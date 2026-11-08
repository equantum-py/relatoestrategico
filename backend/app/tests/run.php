<?php
declare(strict_types=1);

/**
 * Pruebas funcionales y de seguridad de la API (HTTP real contra servidor PHP local).
 *
 * Requisitos: PHP 8.2+, MySQL/MariaDB con el esquema 001_schema.sql aplicado en una
 * base de PRUEBAS (nunca la de producción). Credenciales por variables de entorno:
 *   RELATO_TEST_DB_NAME, RELATO_TEST_DB_USER, RELATO_TEST_DB_PASS
 *
 * Uso:  php backend/app/tests/run.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

const TEST_PORT = 8099;
const ORIGIN = 'https://relatoestrategico.cl';
const ADMIN_EMAIL = 'admin.pruebas@relato.test';
define('ADMIN_PASSWORD', 'Adm-' . bin2hex(random_bytes(10)) . '-9z');
const READER_EMAIL = 'lector.pruebas@relato.test';
define('READER_PASSWORD', 'Lec-' . bin2hex(random_bytes(10)) . '-9z');
define('NEW_PASSWORD', 'Nue-' . bin2hex(random_bytes(10)) . '-9z');
define('OTHER_PASSWORD', 'Otr-' . bin2hex(random_bytes(10)) . '-9z');

$appDir = dirname(__DIR__);
$apiDir = dirname($appDir) . '/public-api';

$dbName = getenv('RELATO_TEST_DB_NAME') ?: '';
$dbUser = getenv('RELATO_TEST_DB_USER') ?: '';
$dbPass = getenv('RELATO_TEST_DB_PASS') ?: '';
if ($dbName === '' || $dbUser === '') {
    fwrite(STDERR, "Define RELATO_TEST_DB_NAME y RELATO_TEST_DB_USER (y RELATO_TEST_DB_PASS).\n");
    exit(2);
}

/* ---------------- configuración temporal de pruebas (secretos aleatorios, 0600) ---------------- */

$secret = bin2hex(random_bytes(32));
$cfgPath = tempnam(sys_get_temp_dir(), 'relato-test-');
chmod($cfgPath, 0600);
$cfg = "<?php return " . var_export([
    'db' => ['host' => '127.0.0.1', 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass],
    'app' => [
        'secret' => $secret,
        'allowed_origin' => ORIGIN,
        'site_url' => ORIGIN,
        'mail_from' => 'no-reply@relatoestrategico.cl',
        'session_idle_minutes' => 30,
        'session_max_hours' => 8,
        'max_login_attempts' => 5,
        'lockout_minutes' => 15,
        'ip_attempts_per_15min' => 1000,
        'reset_minutes' => 30,
    ],
], true) . ";\n";
file_put_contents($cfgPath, $cfg);

$env = array_merge(getenv(), [
    'RELATO_APP_DIR' => $appDir,
    'RELATO_CONFIG' => $cfgPath,
]);

$pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

/* ---------------- servidor PHP embebido ---------------- */

$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . TEST_PORT, '-t', $apiDir, $apiDir . '/index.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    null,
    $env
);
$base = 'http://127.0.0.1:' . TEST_PORT;
for ($i = 0; $i < 50; $i++) {
    $h = @file_get_contents($base . '/v1/health');
    if ($h !== false) {
        break;
    }
    usleep(100000);
}

/* ---------------- utilidades HTTP ---------------- */

$results = [];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$name, $ok];
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $name . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
}

/**
 * @param string[] $headers
 * @return array{status:int,headers:array<string,string[]>,raw:string,json:?array}
 */
function req(string $method, string $path, ?array $json = null, array $headers = [], bool $origin = true, ?string $rawBody = null): array
{
    global $base;
    $h = $headers;
    if ($origin) {
        $h[] = 'Origin: ' . ORIGIN;
    }
    $content = '';
    if ($json !== null) {
        $content = json_encode($json, JSON_UNESCAPED_UNICODE);
        $h[] = 'Content-Type: application/json';
    } elseif ($rawBody !== null) {
        $content = $rawBody;
        $h[] = 'Content-Type: application/json';
    }
    $opts = ['http' => [
        'method' => $method,
        'header' => implode("\r\n", $h),
        'content' => $content,
        'ignore_errors' => true,
        'timeout' => 15,
    ]];
    $raw = @file_get_contents($base . $path, false, stream_context_create($opts));
    $headersOut = [];
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
            continue;
        }
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headersOut[strtolower(trim($k))][] = trim($v);
        }
    }
    return [
        'status' => $status,
        'headers' => $headersOut,
        'raw' => (string) $raw,
        'json' => is_string($raw) ? (json_decode((string) $raw, true) ?: null) : null,
    ];
}

function csrf(): string
{
    return (string) req('GET', '/v1/auth/csrf')['json']['csrf'];
}

function login(string $email, string $password): array
{
    return req('POST', '/v1/auth/login', ['email' => $email, 'password' => $password], ['X-CSRF-Token: ' . csrf()]);
}

/** Extrae el token de la cookie de sesión de una respuesta. */
function cookieFrom(array $resp): ?string
{
    foreach ($resp['headers']['set-cookie'] ?? [] as $sc) {
        if (str_starts_with($sc, '__Host-rs_session=')) {
            $value = substr($sc, strlen('__Host-rs_session='));
            return explode(';', $value)[0];
        }
    }
    return null;
}

function authHeaders(string $token, string $csrfToken): array
{
    return ['Cookie: __Host-rs_session=' . $token, 'X-CSRF-Token: ' . $csrfToken];
}

function sessionCount(PDO $pdo, string $email): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM admin_sessions s JOIN admin_users u ON u.id = s.user_id WHERE u.email = ?');
    $st->execute([$email]);
    return (int) $st->fetchColumn();
}

/* ---------------- preparación de la base de pruebas ---------------- */

echo "Preparando base de pruebas…\n";
foreach (['audit_log', 'login_attempts', 'password_resets', 'admin_sessions', 'posts', 'site_settings', 'admin_users'] as $t) {
    $pdo->exec("DELETE FROM {$t}");
}

$cliPassword = ADMIN_PASSWORD . "\n";
$cli = proc_open(
    [PHP_BINARY, $appDir . '/scripts/create_admin.php', ADMIN_EMAIL],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $cliPipes,
    null,
    $env
);
fwrite($cliPipes[0], $cliPassword);
fclose($cliPipes[0]);
$cliOut = stream_get_contents($cliPipes[1]);
$cliErr = stream_get_contents($cliPipes[2]);
$cliCode = proc_close($cli);

echo "\n== Inicialización segura del administrador ==\n";
check('CLI crea el primer administrador', $cliCode === 0 && str_contains($cliOut, ADMIN_EMAIL), trim($cliErr));
$cliAgain = proc_open([PHP_BINARY, $appDir . '/scripts/create_admin.php', ADMIN_EMAIL], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $p2, null, $env);
fwrite($p2[0], ADMIN_PASSWORD . "\n");
fclose($p2[0]);
stream_get_contents($p2[1]);
stream_get_contents($p2[2]);
check('CLI rechaza duplicar un correo existente', proc_close($cliAgain) !== 0);

$readerHash = password_hash(READER_PASSWORD, PASSWORD_DEFAULT);
$now = gmdate('Y-m-d H:i:s');
$pdo->prepare("INSERT INTO admin_users (email, password_hash, role, active, created_at, updated_at) VALUES (?, ?, 'reader', 1, ?, ?)")
    ->execute([READER_EMAIL, $readerHash, $now, $now]);

$pdo->exec("UPDATE admin_users SET role='admin' WHERE email='" . ADMIN_EMAIL . "'");

echo "\n== Seguridad del servidor y CORS ==\n";
$r = req('GET', '/v1/health', null, [], false);
check('Health responde 200', $r['status'] === 200);
check('Cabecera X-Frame-Options DENY', ($r['headers']['x-frame-options'][0] ?? '') === 'DENY');
check('Content-Security-Policy restrictiva', str_contains($r['headers']['content-security-policy'][0] ?? '', "default-src 'none'"));
check('Cache-Control no-store', str_contains($r['headers']['cache-control'][0] ?? '', 'no-store'));
check('Sin cabecera X-Powered-By', !isset($r['headers']['x-powered-by']));

$pre = req('OPTIONS', '/v1/posts', null, ['Access-Control-Request-Method: GET'], true);
check('CORS: origen permitido recibe Allow-Origin exacto', ($pre['headers']['access-control-allow-origin'][0] ?? '') === ORIGIN);
check('CORS: credenciales habilitadas solo para el origen permitido', ($pre['headers']['access-control-allow-credentials'][0] ?? '') === 'true');
$evil = req('OPTIONS', '/v1/posts', null, ['Access-Control-Request-Method: GET'], false);
$evilH = $evil['headers'];
$evilOrigin = req('GET', '/v1/health', null, ['Origin: https://evil.example'], false);
check('CORS: origen ajeno no recibe Allow-Origin', !isset($evilOrigin['headers']['access-control-allow-origin']));
$evilPost = req('POST', '/v1/auth/login', ['email' => ADMIN_EMAIL, 'password' => ADMIN_PASSWORD], ['Origin: https://evil.example', 'X-CSRF-Token: ' . csrf()], false);
check('Escritura desde origen ajeno rechazada (403)', $evilPost['status'] === 403);
$noOrigin = req('POST', '/v1/auth/login', ['email' => ADMIN_EMAIL, 'password' => ADMIN_PASSWORD], ['X-CSRF-Token: ' . csrf()], false);
check('Escritura sin cabecera Origin rechazada (403)', $noOrigin['status'] === 403);
check('Ruta inexistente responde 404', req('GET', '/v1/no-existe')['status'] === 404);
check('Método no permitido responde 405', req('PATCH', '/v1/posts')['status'] === 405);

$raw = req('POST', '/v1/auth/login', null, ['X-CSRF-Token: ' . csrf()], true, 'no es json');
check('Cuerpo no JSON responde 400 o 415', in_array($raw['status'], [400, 415], true));
$big = req('POST', '/v1/auth/login', null, ['X-CSRF-Token: ' . csrf()], true, str_repeat('a', 130000));
check('Cuerpo demasiado grande responde 413', $big['status'] === 413);

echo "\n== Autenticación ==\n";
$csrfResp = req('GET', '/v1/auth/csrf');
check('Token CSRF del formulario emitido', $csrfResp['status'] === 200 && strlen((string) $csrfResp['json']['csrf']) > 40);
$noCsrf = req('POST', '/v1/auth/login', ['email' => ADMIN_EMAIL, 'password' => ADMIN_PASSWORD]);
check('Login sin token CSRF rechazado (403)', $noCsrf['status'] === 403);
$badCsrf = req('POST', '/v1/auth/login', ['email' => ADMIN_EMAIL, 'password' => ADMIN_PASSWORD], ['X-CSRF-Token: 1.2.3']);
check('Login con token CSRF falso rechazado (403)', $badCsrf['status'] === 403);

$wrong = login(ADMIN_EMAIL, 'contraseña-incorrecta-1');
check('Contraseña incorrecta: 401 sin detalles', $wrong['status'] === 401 && ($wrong['json']['error']['code'] ?? '') === 'invalid_credentials');
$unknown = login('nadie@relato.test', 'contraseña-incorrecta-1');
check('Correo inexistente: misma respuesta que contraseña incorrecta', $unknown['status'] === 401 && $unknown['json']['error']['message'] === $wrong['json']['error']['message']);
check('Correo inválido: 422 sin consultar la base', login("' OR '1'='1", 'x')['status'] === 422);
$sqli = login(ADMIN_EMAIL, "' OR '1'='1");
check('Inyección SQL en contraseña no concede acceso', $sqli['status'] === 401);

$ok = login(ADMIN_EMAIL, ADMIN_PASSWORD);
$token = cookieFrom($ok);
$csrfToken = (string) ($ok['json']['csrf'] ?? '');
check('Login válido: 200 y usuario devuelto', $ok['status'] === 200 && ($ok['json']['user']['email'] ?? '') === ADMIN_EMAIL);
check('Login: la respuesta no contiene la contraseña ni su hash', !str_contains($ok['raw'], ADMIN_PASSWORD) && !str_contains($ok['raw'], '$2y$'));
$setCookie = implode(' | ', $ok['headers']['set-cookie'] ?? []);
check('Cookie con prefijo __Host-', str_starts_with($setCookie, '__Host-rs_session='));
// Los atributos de cookie no distinguen mayúsculas (RFC 6265): comparación insensible.
check('Cookie HttpOnly, Secure y SameSite=Strict', stripos($setCookie, 'httponly') !== false && stripos($setCookie, '; secure') !== false && stripos($setCookie, 'samesite=strict') !== false, $setCookie);
check('Cookie sin Domain y con Path=/', stripos($setCookie, 'domain=') === false && stripos($setCookie, 'path=/') !== false, $setCookie);
check('El token de sesión no aparece en el cuerpo JSON', $token !== null && !str_contains($ok['raw'], $token));

$me = req('GET', '/v1/auth/me', null, ['Cookie: __Host-rs_session=' . $token]);
check('Sesión válida: /me devuelve el usuario', $me['status'] === 200 && ($me['json']['user']['role'] ?? '') === 'admin');

echo "\n== Protección de rutas administrativas ==\n";
check('Listado de artículos sin sesión: 401', req('GET', '/v1/posts')['status'] === 401);
check('Ajustes sin sesión: 401', req('GET', '/v1/settings')['status'] === 401);
check('Crear artículo sin sesión: 401', req('POST', '/v1/posts', ['title' => 'Título válido'], ['X-CSRF-Token: x'])['status'] === 401);
check('Cookie manipulada no concede acceso', req('GET', '/v1/posts', null, ['Cookie: __Host-rs_session=' . str_repeat('a', 64)])['status'] === 401);
check('Cookie con formato inválido no concede acceso', req('GET', '/v1/posts', null, ['Cookie: __Host-rs_session=../../etc/passwd'])['status'] === 401);

echo "\n== Contenidos: artículos ==\n";
$H = authHeaders($token, $csrfToken);
$createNoCsrf = req('POST', '/v1/posts', ['title' => 'Artículo sin CSRF', 'body' => 'x'], ['Cookie: __Host-rs_session=' . $token]);
check('Escritura sin CSRF de sesión rechazada (403)', $createNoCsrf['status'] === 403);

$xssTitle = '<img src=x onerror=alert(1)> Artículo de prueba';
$create = req('POST', '/v1/posts', [
    'title' => $xssTitle,
    'category' => 'LinkedIn',
    'excerpt' => 'Extracto de prueba',
    'body' => "Línea 1\nLínea 2 con <script>alert('x')</script>",
    'image_url' => '/rodrigo.webp',
    'status' => 'borrador',
], $H);
check('Crear artículo: 201', $create['status'] === 201, 'status ' . $create['status'] . ' ' . $create['raw']);
$postId = (int) ($create['json']['post']['id'] ?? 0);
check('Título con HTML se guarda como texto literal', ($create['json']['post']['title'] ?? '') === $xssTitle);
check('Respuesta JSON con CSP y Content-Type correcto', str_contains($create['headers']['content-type'][0] ?? '', 'application/json'));
$autoSlug = (string) ($create['json']['post']['slug'] ?? '');
check('Slug generado automáticamente y válido', preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $autoSlug) === 1 && strlen($autoSlug) >= 3, $autoSlug);

$sqlTitle = "x'); DROP TABLE posts; -- prueba";
$create2 = req('POST', '/v1/posts', ['title' => $sqlTitle, 'body' => 'cuerpo', 'slug' => 'inyeccion-prueba'], $H);
check('Título con inyección SQL se guarda literal', $create2['status'] === 201 && ($create2['json']['post']['title'] ?? '') === $sqlTitle);
$tableExists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'posts'")->fetchColumn();
check('La tabla posts sigue existiendo tras el intento de inyección', $tableExists === 1);
$search = req('GET', '/v1/posts?q=' . rawurlencode("%' OR 1=1 --"), null, ['Cookie: __Host-rs_session=' . $token]);
check('Búsqueda con comodines/inyección no devuelve toda la tabla', $search['status'] === 200 && count($search['json']['posts'] ?? []) === 0);

$dup = req('POST', '/v1/posts', ['title' => 'Duplicado', 'slug' => 'inyeccion-prueba', 'body' => ''], $H);
check('Slug duplicado rechazado (409)', $dup['status'] === 409);
$badStatus = req('POST', '/v1/posts', ['title' => 'Estado malo', 'status' => 'publicado-ya; DROP'], $H);
check('Estado fuera de la lista blanca rechazado (422)', $badStatus['status'] === 422);
$badUrl = req('POST', '/v1/posts', ['title' => 'Imagen mala', 'image_url' => 'javascript:alert(1)'], $H);
check('URL javascript: rechazada (422)', $badUrl['status'] === 422);
$shortTitle = req('POST', '/v1/posts', ['title' => 'ab'], $H);
check('Título demasiado corto rechazado (422)', $shortTitle['status'] === 422);
$ctrl = req('POST', '/v1/posts', ['title' => "Título\x07con control"], $H);
check('Caracteres de control rechazados (422)', $ctrl['status'] === 422);

$publish = req('PUT', '/v1/posts/' . $postId, ['title' => 'Artículo publicado de prueba', 'category' => 'LinkedIn', 'excerpt' => 'Extracto', 'body' => 'Cuerpo actualizado', 'image_url' => '', 'status' => 'publicado', 'slug' => 'articulo-publicado-prueba'], $H);
check('Actualizar y publicar artículo: 200', $publish['status'] === 200 && ($publish['json']['post']['status'] ?? '') === 'publicado');
check('Fecha de publicación registrada al publicar', !empty($publish['json']['post']['published_at']));
$get = req('GET', '/v1/posts/' . $postId, null, ['Cookie: __Host-rs_session=' . $token]);
check('Lectura persistida del artículo coincide con lo guardado', ($get['json']['post']['body'] ?? '') === 'Cuerpo actualizado' && ($get['json']['post']['slug'] ?? '') === 'articulo-publicado-prueba');
$dbRow = $pdo->prepare('SELECT status, body FROM posts WHERE id = ?');
$dbRow->execute([$postId]);
$dbVals = $dbRow->fetch();
check('Cambio persistido en MySQL', ($dbVals['status'] ?? '') === 'publicado' && ($dbVals['body'] ?? '') === 'Cuerpo actualizado');
$list = req('GET', '/v1/posts?status=publicado', null, ['Cookie: __Host-rs_session=' . $token]);
check('Filtro por estado devuelve el artículo publicado', $list['status'] === 200 && count(array_filter($list['json']['posts'] ?? [], fn ($p) => $p['id'] === $postId)) === 1);
check('Filtro por estado inválido rechazado (422)', req('GET', '/v1/posts?status=x', null, ['Cookie: __Host-rs_session=' . $token])['status'] === 422);
check('Artículo inexistente responde 404', req('GET', '/v1/posts/999999', null, ['Cookie: __Host-rs_session=' . $token])['status'] === 404);

echo "\n== Control de permisos por rol ==\n";
$readerLogin = login(READER_EMAIL, READER_PASSWORD);
$rToken = cookieFrom($readerLogin);
$rCsrf = (string) ($readerLogin['json']['csrf'] ?? '');
$RH = authHeaders((string) $rToken, $rCsrf);
check('Lector puede listar artículos', req('GET', '/v1/posts', null, ['Cookie: __Host-rs_session=' . $rToken])['status'] === 200);
check('Lector no puede crear artículos (403)', req('POST', '/v1/posts', ['title' => 'Lector intenta crear'], $RH)['status'] === 403);
check('Lector no puede modificar ajustes (403)', req('PUT', '/v1/settings', ['siteName' => 'Hackeado'], $RH)['status'] === 403);

echo "\n== Ajustes del sitio ==\n";
$settingsGet = req('GET', '/v1/settings', null, ['Cookie: __Host-rs_session=' . $token]);
check('Ajustes: lectura con todas las claves permitidas', $settingsGet['status'] === 200 && array_key_exists('siteName', $settingsGet['json']['settings'] ?? []));
$badKey = req('PUT', '/v1/settings', ['admin_flag' => '1'], $H);
check('Clave de ajustes no permitida rechazada (422)', $badKey['status'] === 422);
$badEmail = req('PUT', '/v1/settings', ['email' => 'no-es-correo'], $H);
check('Correo de contacto inválido rechazado (422)', $badEmail['status'] === 422);
$badLink = req('PUT', '/v1/settings', ['linkedin' => 'http://insegura.example'], $H);
check('Enlace no https rechazado (422)', $badLink['status'] === 422);
$settingsPut = req('PUT', '/v1/settings', ['siteName' => 'Relato Estratégico', 'email' => 'contacto@relatoestrategico.cl', 'linkedin' => 'https://www.linkedin.com/in/rodrigobarriareyes/'], $H);
check('Guardar ajustes válidos: 200', $settingsPut['status'] === 200);
$dbSet = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='email'")->fetchColumn();
check('Ajuste persistido en MySQL', $dbSet === 'contacto@relatoestrategico.cl');

echo "\n== Protección contra fuerza bruta ==\n";
$pdo->prepare('UPDATE admin_users SET failed_logins = 0, locked_until = NULL WHERE email = ?')->execute([READER_EMAIL]);
for ($i = 0; $i < 5; $i++) {
    login(READER_EMAIL, 'mala-clave-' . $i . '-xx');
}
$lockedRow = $pdo->prepare('SELECT locked_until FROM admin_users WHERE email = ?');
$lockedRow->execute([READER_EMAIL]);
check('Cuenta bloqueada tras 5 intentos fallidos', $lockedRow->fetchColumn() !== null);
$whileLocked = login(READER_EMAIL, READER_PASSWORD);
check('Contraseña correcta rechazada mientras la cuenta está bloqueada', $whileLocked['status'] === 401);
$pdo->prepare('UPDATE admin_users SET locked_until = ? WHERE email = ?')->execute([gmdate('Y-m-d H:i:s', time() - 60), READER_EMAIL]);
$afterLock = login(READER_EMAIL, READER_PASSWORD);
check('Tras vencer el bloqueo, el acceso válido vuelve a funcionar', $afterLock['status'] === 200);

echo "\n== Sesiones: cierre, inactividad y expiración ==\n";
$logoutNoCsrf = req('POST', '/v1/auth/logout', null, ['Cookie: __Host-rs_session=' . $rToken]);
check('Cierre de sesión sin CSRF rechazado (403)', $logoutNoCsrf['status'] === 403);
$logout = req('POST', '/v1/auth/logout', null, authHeaders((string) $rToken, $rCsrf));
check('Cierre de sesión real: 200', $logout['status'] === 200);
$afterLogout = req('GET', '/v1/auth/me', null, ['Cookie: __Host-rs_session=' . $rToken]);
check('Token anterior inválido tras cerrar sesión (401)', $afterLogout['status'] === 401);
$reuse = req('GET', '/v1/posts', null, ['Cookie: __Host-rs_session=' . $rToken]);
check('Token cerrado no sirve para rutas protegidas', $reuse['status'] === 401);

$pdo->prepare('UPDATE admin_sessions SET last_activity = ? WHERE user_id = (SELECT id FROM admin_users WHERE email = ?)')
    ->execute([gmdate('Y-m-d H:i:s', time() - 31 * 60), ADMIN_EMAIL]);
$idle = req('GET', '/v1/posts', null, ['Cookie: __Host-rs_session=' . $token]);
check('Sesión expirada por inactividad (>30 min): 401', $idle['status'] === 401);

$fresh = login(ADMIN_EMAIL, ADMIN_PASSWORD);
$token2 = cookieFrom($fresh);
$csrf2 = (string) ($fresh['json']['csrf'] ?? '');
$pdo->prepare('UPDATE admin_sessions SET expires_at = ? WHERE user_id = (SELECT id FROM admin_users WHERE email = ?)')
    ->execute([gmdate('Y-m-d H:i:s', time() - 60), ADMIN_EMAIL]);
$absolute = req('GET', '/v1/posts', null, ['Cookie: __Host-rs_session=' . $token2]);
check('Sesión expirada por tiempo máximo: 401', $absolute['status'] === 401);

/* ---------------- recuperación de contraseña ---------------- */

echo "\n== Recuperación de contraseña ==\n";
$reqExisting = req('POST', '/v1/auth/password-reset/request', ['email' => ADMIN_EMAIL], ['X-CSRF-Token: ' . csrf()]);
$reqUnknown = req('POST', '/v1/auth/password-reset/request', ['email' => 'desconocido@relato.test'], ['X-CSRF-Token: ' . csrf()]);
check('Recuperación: respuesta idéntica exista o no la cuenta', $reqExisting['status'] === 200 && $reqExisting['json']['message'] === $reqUnknown['json']['message']);
$tokenRows = (int) $pdo->query("SELECT COUNT(*) FROM password_resets")->fetchColumn();
check('Recuperación: se crea registro con token (solo hash)', $tokenRows >= 1);
$leak = (int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE LENGTH(token_hash) <> 64")->fetchColumn();
check('Recuperación: no se guarda el token en claro', $leak === 0);
$reqNoCsrf = req('POST', '/v1/auth/password-reset/request', ['email' => ADMIN_EMAIL]);
check('Recuperación sin CSRF rechazada (403)', $reqNoCsrf['status'] === 403);

$resetToken = bin2hex(random_bytes(32));
$uidAdmin = (int) $pdo->query("SELECT id FROM admin_users WHERE email='" . ADMIN_EMAIL . "'")->fetchColumn();
$pdo->prepare('INSERT INTO password_resets (user_id, token_hash, created_at, expires_at) VALUES (?, ?, ?, ?)')
    ->execute([$uidAdmin, hash('sha256', $resetToken), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + 1800)]);

$weak = req('POST', '/v1/auth/password-reset/confirm', ['token' => $resetToken, 'password' => 'corta1'], ['X-CSRF-Token: ' . csrf()]);
check('Contraseña nueva débil rechazada (422)', $weak['status'] === 422);
$fake = req('POST', '/v1/auth/password-reset/confirm', ['token' => str_repeat('f', 64), 'password' => NEW_PASSWORD], ['X-CSRF-Token: ' . csrf()]);
check('Token de recuperación falso rechazado (400)', $fake['status'] === 400);
$confirm = req('POST', '/v1/auth/password-reset/confirm', ['token' => $resetToken, 'password' => NEW_PASSWORD], ['X-CSRF-Token: ' . csrf()]);
check('Recuperación con token válido: 200', $confirm['status'] === 200);
$reuseToken = req('POST', '/v1/auth/password-reset/confirm', ['token' => $resetToken, 'password' => OTHER_PASSWORD], ['X-CSRF-Token: ' . csrf()]);
check('Token de recuperación de un solo uso (400 al reutilizar)', $reuseToken['status'] === 400);
check('Sesiones anteriores eliminadas tras recuperar contraseña', sessionCount($pdo, ADMIN_EMAIL) === 0);
check('Contraseña anterior ya no funciona', login(ADMIN_EMAIL, ADMIN_PASSWORD)['status'] === 401);
check('Contraseña nueva funciona', login(ADMIN_EMAIL, NEW_PASSWORD)['status'] === 200);

$expiredToken = bin2hex(random_bytes(32));
$pdo->prepare('INSERT INTO password_resets (user_id, token_hash, created_at, expires_at) VALUES (?, ?, ?, ?)')
    ->execute([$uidAdmin, hash('sha256', $expiredToken), gmdate('Y-m-d H:i:s', time() - 7200), gmdate('Y-m-d H:i:s', time() - 3600)]);
$expired = req('POST', '/v1/auth/password-reset/confirm', ['token' => $expiredToken, 'password' => NEW_PASSWORD], ['X-CSRF-Token: ' . csrf()]);
check('Token de recuperación vencido rechazado (400)', $expired['status'] === 400);

/* ---------------- higiene de secretos ---------------- */

echo "\n== Ausencia de credenciales expuestas ==\n";
$auditDump = implode(' ', array_map('json_encode', $pdo->query('SELECT * FROM audit_log')->fetchAll()));
check('Auditoría no contiene contraseñas en claro', !str_contains($auditDump, NEW_PASSWORD) && !str_contains($auditDump, ADMIN_PASSWORD));
check('Auditoría no contiene el secreto de la aplicación', !str_contains($auditDump, $secret));
$logTable = $pdo->query('SELECT COUNT(*) FROM password_resets WHERE token_hash = ' . $pdo->quote($resetToken))->fetchColumn();
check('Token de recuperación no se guarda en claro', (int) $logTable === 0);
$whole = '';
foreach (['/v1/health', '/v1/auth/csrf'] as $p) {
    $whole .= req('GET', $p)['raw'];
}
check('Respuestas públicas sin secretos', !str_contains($whole, $secret) && !str_contains($whole, 'pass'));

/* ---------------- limpieza ---------------- */

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
@unlink($cfgPath);

$passed = count(array_filter($results, fn ($r) => $r[1]));
$total = count($results);
echo "\nResultado: {$passed}/{$total} pruebas superadas.\n";
exit($passed === $total ? 0 : 1);
