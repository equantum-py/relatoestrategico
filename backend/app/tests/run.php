<?php
declare(strict_types=1);

/**
 * Pruebas funcionales y de seguridad de la API (HTTP real contra servidores PHP locales).
 *
 * Requisitos: PHP 8.2+ con fileinfo y gd; MySQL/MariaDB con el esquema 001_schema.sql aplicado
 * en una base de PRUEBAS (el nombre debe contener "test"). Credenciales por variables de entorno:
 *   RELATO_TEST_DB_NAME, RELATO_TEST_DB_USER, RELATO_TEST_DB_PASS
 *
 * Uso:  php backend/app/tests/run.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

const PORT_MAIN = 8099;
const PORT_LIMITS = 8100;
const ORIGIN = 'https://relatoestrategico.cl';
const ADMIN_EMAIL = 'admin.pruebas@relato.test';
const READER_EMAIL = 'lector.pruebas@relato.test';
const INVITED_EMAIL = 'invitado.pruebas@relato.test';
define('ADMIN_PASSWORD', 'Adm-' . bin2hex(random_bytes(10)) . '-9z');
define('READER_PASSWORD', 'Lec-' . bin2hex(random_bytes(10)) . '-9z');
define('NEW_PASSWORD', 'Nue-' . bin2hex(random_bytes(10)) . '-9z');
define('OTHER_PASSWORD', 'Otr-' . bin2hex(random_bytes(10)) . '-9z');

$appDir = dirname(__DIR__);
$apiDir = dirname($appDir) . '/public-api';
$adminHtml = dirname($appDir, 2) . '/public/admin';

$dbName = getenv('RELATO_TEST_DB_NAME') ?: '';
$dbUser = getenv('RELATO_TEST_DB_USER') ?: '';
$dbPass = getenv('RELATO_TEST_DB_PASS') ?: '';
if ($dbName === '' || $dbUser === '') {
    fwrite(STDERR, "Define RELATO_TEST_DB_NAME y RELATO_TEST_DB_USER (y RELATO_TEST_DB_PASS).\n");
    exit(2);
}
if (stripos($dbName, 'test') === false) {
    fwrite(STDERR, "Negado: el nombre de la base debe contener 'test'. Nunca ejecutes estas pruebas contra producción.\n");
    exit(2);
}

/* ---------------- configuración temporal (secretos aleatorios, 0600) ---------------- */

$secret = bin2hex(random_bytes(32));
$mediaDir = sys_get_temp_dir() . '/relato-media-test-' . bin2hex(random_bytes(4));
mkdir($mediaDir, 0700, true);
$mailDir = sys_get_temp_dir() . '/relato-mail-test-' . bin2hex(random_bytes(4));
mkdir($mailDir, 0700, true);
$logDir = sys_get_temp_dir() . '/relato-srvlog-' . bin2hex(random_bytes(4));
mkdir($logDir, 0700, true);
$serverLogFile = $logDir . '/server.log';
touch($serverLogFile);
chmod($serverLogFile, 0600);
register_shutdown_function(static function () use ($mailDir, $logDir): void {
    foreach (array_merge(glob($mailDir . '/*') ?: [], glob($logDir . '/*') ?: []) as $f) {
        @unlink($f);
    }
    @rmdir($mailDir);
    @rmdir($logDir);
});

function writeConfig(array $app): string
{
    $path = tempnam(sys_get_temp_dir(), 'relato-test-');
    chmod($path, 0600);
    file_put_contents($path, '<?php return ' . var_export($app, true) . ";\n");
    return $path;
}

global $dbHost;
$dbHost = ['host' => '127.0.0.1', 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass];
$appBase = [
    'secret' => $secret,
    'allowed_origin' => ORIGIN,
    'site_url' => ORIGIN,
    'mail_from' => 'no-reply@relatoestrategico.cl',
    'session_idle_minutes' => 30,
    'session_max_hours' => 8,
    'max_login_attempts' => 5,
    'lockout_minutes' => 15,
    'ip_attempts_per_15min' => 1000,
    'account_attempts_per_hour' => 50,
    'reset_minutes' => 30,
    'reset_per_account_per_hour' => 3,
    'reset_per_ip_per_hour' => 1000,
    'trusted_proxies' => [],
    'media_dir' => $mediaDir,
    'environment' => 'test',
    'mail_transport' => 'file',
    'mail_test_dir' => $mailDir,
    'media_base_url' => 'https://api.relatoestrategico.cl/media',
    'media_max_bytes' => 5242880,
];
$cfgMain = writeConfig(['db' => $dbHost, 'app' => $appBase]);
// Servidor de límites: IP 3/15 min, cuenta 4/h, proxy de confianza para X-Forwarded-For, media máx. 2000 bytes.
$cfgLimits = writeConfig(['db' => $dbHost, 'app' => array_merge($appBase, [
    'ip_attempts_per_15min' => 3,
    'account_attempts_per_hour' => 4,
    'trusted_proxies' => ['127.0.0.1'],
    'media_max_bytes' => 2000,
])]);

$env = array_merge(getenv(), ['RELATO_APP_DIR' => $appDir]);

$pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

/* ---------------- servidores PHP embebidos ---------------- */

function startServer(int $port, string $cfg, string $apiDir, array $env): array
{
    global $serverLogFile;
    // Un puerto ocupado significa un servidor huérfano: no probar contra él.
    $busy = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
    if ($busy !== false) {
        fclose($busy);
        fwrite(STDERR, "El puerto {$port} ya está en uso. Cierra el servidor anterior antes de ejecutar las pruebas.\n");
        exit(2);
    }
    $proc = proc_open(
        [PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log=' . $serverLogFile, '-S', '127.0.0.1:' . $port, '-t', $apiDir, $apiDir . '/index.php'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', $serverLogFile, 'a']],
        $pipes,
        null,
        array_merge($env, ['RELATO_CONFIG' => $cfg])
    );
    $url = 'http://127.0.0.1:' . $port;
    for ($i = 0; $i < 50; $i++) {
        if (@file_get_contents($url . '/v1/health') !== false) {
            break;
        }
        usleep(100000);
    }
    return [$proc, $url];
}

[$serverMain, $base] = startServer(PORT_MAIN, $cfgMain, $apiDir, $env);
[$serverLimits, $baseLimits] = startServer(PORT_LIMITS, $cfgLimits, $apiDir, $env);

// Pase lo que pase (incluso un error fatal), los servidores y la configuración temporal se cierran.
register_shutdown_function(static function () use ($serverMain, $serverLimits, $cfgMain, $cfgLimits, $mediaDir): void {
    foreach ([$serverMain, $serverLimits] as $proc) {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
    }
    @unlink($cfgMain);
    @unlink($cfgLimits);
    foreach (glob($mediaDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($mediaDir);
});

/* ---------------- utilidades ---------------- */

$results = [];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$name, $ok];
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $name . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
}

/**
 * Interpreta una respuesta de file_get_contents. $lines es $http_response_header del ámbito que hizo la petición
 * (PHP lo define en ese ámbito local, no en el global).
 */
function parseResponse(string|false $raw, array $lines): array
{
    $headersOut = [];
    $status = 0;
    foreach ($lines as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
            continue;
        }
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headersOut[strtolower(trim($k))][] = trim($v);
        }
    }
    $text = (string) $raw;
    return [
        'status' => $status,
        'headers' => $headersOut,
        'raw' => $text,
        'json' => $text !== '' ? (json_decode($text, true) ?: null) : null,
    ];
}

/**
 * @param string[] $headers
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
    return parseResponse($raw, $http_response_header ?? []);
}

/** Subida multipart de un archivo al campo "file". */
function upload(string $filename, string $content, array $headers = []): array
{
    global $base;
    $boundary = 'b' . bin2hex(random_bytes(8));
    $body = "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
        . "Content-Type: application/octet-stream\r\n\r\n"
        . $content . "\r\n--{$boundary}--\r\n";
    $h = array_merge($headers, ['Origin: ' . ORIGIN, 'Content-Type: multipart/form-data; boundary=' . $boundary]);
    $opts = ['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $h),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 15,
    ]];
    $raw = @file_get_contents($base . '/v1/media', false, stream_context_create($opts));
    return parseResponse($raw, $http_response_header ?? []);
}

function csrf(): string
{
    return (string) req('GET', '/v1/auth/csrf')['json']['csrf'];
}

function login(string $email, string $password, array $extraHeaders = []): array
{
    return req('POST', '/v1/auth/login', ['email' => $email, 'password' => $password], array_merge(['X-CSRF-Token: ' . csrf()], $extraHeaders));
}

/** Extrae el token de la cookie de sesión de una respuesta. */
function cookieFrom(array $resp): ?string
{
    foreach ($resp['headers']['set-cookie'] ?? [] as $sc) {
        if (str_starts_with($sc, '__Host-rs_session=')) {
            return explode(';', substr($sc, strlen('__Host-rs_session=')))[0];
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

function makePng(int $w, int $h, bool $noise = false): string
{
    $im = imagecreatetruecolor($w, $h);
    if ($noise) {
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                imagesetpixel($im, $x, $y, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
            }
        }
    } else {
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 30, 90, 160));
    }
    ob_start();
    imagepng($im);
    $bin = (string) ob_get_clean();
    imagedestroy($im);
    return $bin;
}

/* ---------------- preparación de la base de pruebas ---------------- */

echo "Preparando base de pruebas…\n";
foreach (['page_blocks', 'pages', 'media', 'audit_log', 'login_attempts', 'password_resets', 'admin_sessions', 'posts', 'site_settings', 'admin_users'] as $t) {
    $pdo->exec("DELETE FROM {$t}");
}

$cliPassword = ADMIN_PASSWORD . "\n";
$cli = proc_open(
    [PHP_BINARY, $appDir . '/scripts/create_admin.php', ADMIN_EMAIL],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $cliPipes,
    null,
    array_merge($env, ['RELATO_CONFIG' => $cfgMain])
);
fwrite($cliPipes[0], $cliPassword);
fclose($cliPipes[0]);
$cliOut = stream_get_contents($cliPipes[1]);
$cliErr = stream_get_contents($cliPipes[2]);
$cliCode = proc_close($cli);

echo "\n== Inicialización segura del administrador ==\n";
check('CLI crea el primer administrador', $cliCode === 0 && str_contains($cliOut, ADMIN_EMAIL), trim($cliErr));
$cliAgain = proc_open([PHP_BINARY, $appDir . '/scripts/create_admin.php', ADMIN_EMAIL], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $p2, null, array_merge($env, ['RELATO_CONFIG' => $cfgMain]));
fwrite($p2[0], ADMIN_PASSWORD . "\n");
fclose($p2[0]);
stream_get_contents($p2[1]);
stream_get_contents($p2[2]);
check('CLI rechaza duplicar un correo existente', proc_close($cliAgain) !== 0);

$now = gmdate('Y-m-d H:i:s');
$pdo->prepare("INSERT INTO admin_users (email, password_hash, role, active, created_at, updated_at) VALUES (?, ?, 'reader', 1, ?, ?)")
    ->execute([READER_EMAIL, password_hash(READER_PASSWORD, PASSWORD_DEFAULT), $now, $now]);
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
check('Cookie HttpOnly, Secure y SameSite=Strict', stripos($setCookie, 'httponly') !== false && stripos($setCookie, '; secure') !== false && stripos($setCookie, 'samesite=strict') !== false, $setCookie);
check('Cookie sin Domain y con Path=/', stripos($setCookie, 'domain=') === false && stripos($setCookie, 'path=/') !== false, $setCookie);
check('El token de sesión no aparece en el cuerpo JSON', $token !== null && !str_contains($ok['raw'], $token));

$me = req('GET', '/v1/auth/me', null, ['Cookie: __Host-rs_session=' . $token]);
check('Sesión válida: /me devuelve el usuario', $me['status'] === 200 && ($me['json']['user']['role'] ?? '') === 'admin');

echo "\n== Protección de rutas administrativas ==\n";
check('Listado de artículos sin sesión: 401', req('GET', '/v1/posts')['status'] === 401);
check('Ajustes sin sesión: 401', req('GET', '/v1/settings')['status'] === 401);
check('Páginas sin sesión: 401', req('GET', '/v1/pages')['status'] === 401);
check('Multimedia sin sesión: 401', req('GET', '/v1/media')['status'] === 401);
check('Usuarios sin sesión: 401', req('GET', '/v1/users')['status'] === 401);
check('Respaldo sin sesión: 401', req('GET', '/v1/backup')['status'] === 401);
check('Crear artículo sin sesión: 401', req('POST', '/v1/posts', ['title' => 'Título válido'], ['X-CSRF-Token: x'])['status'] === 401);
check('Cookie manipulada no concede acceso', req('GET', '/v1/posts', null, ['Cookie: __Host-rs_session=' . str_repeat('a', 64)])['status'] === 401);
check('Cookie con formato inválido no concede acceso', req('GET', '/v1/posts', null, ['Cookie: __Host-rs_session=../../etc/passwd'])['status'] === 401);

echo "\n== Contenidos: artículos ==\n";
$H = authHeaders($token, $csrfToken);
$createNoCsrf = req('POST', '/v1/posts', ['title' => 'Artículo sin CSRF', 'body' => 'x'], ['Cookie: __Host-rs_session=' . $token]);
check('Escritura sin CSRF de sesión rechazada (403)', $createNoCsrf['status'] === 403);

$markupTitle = '<img src=x onerror=alert(1)> Artículo de prueba';
$markup = req('POST', '/v1/posts', ['title' => $markupTitle, 'body' => 'x'], $H);
check('Título con marcado HTML rechazado (422)', $markup['status'] === 422);

$create = req('POST', '/v1/posts', [
    'title' => 'Artículo de prueba automático',
    'category' => 'LinkedIn',
    'excerpt' => 'Extracto de prueba',
    'body' => "Línea 1\nLínea 2 con alert('x')",
    'image_url' => '/rodrigo.webp',
    'status' => 'borrador',
], $H);
check('Crear artículo: 201', $create['status'] === 201, 'status ' . $create['status'] . ' ' . $create['raw']);
$postId = (int) ($create['json']['post']['id'] ?? 0);
check('Respuesta JSON con Content-Type correcto', str_contains($create['headers']['content-type'][0] ?? '', 'application/json'));
$autoSlug = (string) ($create['json']['post']['slug'] ?? '');
check('Slug generado automáticamente y válido', preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $autoSlug) === 1 && strlen($autoSlug) >= 3, $autoSlug);

$longTitle = str_repeat('a', 119) . ' bbb';
$longPost = req('POST', '/v1/posts', ['title' => $longTitle, 'body' => 'x'], $H);
$longSlug = (string) ($longPost['json']['post']['slug'] ?? '');
check('Slug largo se trunca sin guión final', $longPost['status'] === 201 && $longSlug === str_repeat('a', 119), $longSlug);

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
$protoRel = req('POST', '/v1/posts', ['title' => 'Imagen relativa', 'image_url' => '//evil.example/x.png'], $H);
check('URL //host rechazada (422)', $protoRel['status'] === 422);
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
check('Lector no puede modificar páginas (403)', req('POST', '/v1/pages/inicio/blocks', ['title' => 'Intruso'], $RH)['status'] === 403);
check('Lector no puede subir imágenes (403)', upload('x.png', makePng(8, 8), ['Cookie: __Host-rs_session=' . $rToken, 'X-CSRF-Token: ' . $rCsrf])['status'] === 403);
check('Lector no puede gestionar usuarios (403)', req('GET', '/v1/users', null, ['Cookie: __Host-rs_session=' . $rToken])['status'] === 403);
check('Lector no puede descargar el respaldo (403)', req('GET', '/v1/backup', null, ['Cookie: __Host-rs_session=' . $rToken])['status'] === 403);

echo "\n== Ajustes del sitio ==\n";
$settingsGet = req('GET', '/v1/settings', null, ['Cookie: __Host-rs_session=' . $token]);
check('Ajustes: lectura con todas las claves permitidas', $settingsGet['status'] === 200 && array_key_exists('siteName', $settingsGet['json']['settings'] ?? []));
$badKey = req('PUT', '/v1/settings', ['admin_flag' => '1'], $H);
check('Clave de ajustes no permitida rechazada (422)', $badKey['status'] === 422);
$badEmail = req('PUT', '/v1/settings', ['email' => 'no-es-correo'], $H);
check('Correo de contacto inválido rechazado (422)', $badEmail['status'] === 422);
$badLink = req('PUT', '/v1/settings', ['linkedin' => 'http://insegura.example'], $H);
check('Enlace no https rechazado (422)', $badLink['status'] === 422);
$protoSet = req('PUT', '/v1/settings', ['calendar' => '//evil.example/agenda'], $H);
check('Ajuste con URL //host rechazado (422)', $protoSet['status'] === 422);
$settingsPut = req('PUT', '/v1/settings', ['siteName' => 'Relato Estratégico', 'email' => 'contacto@relatoestrategico.cl', 'linkedin' => 'https://www.linkedin.com/in/rodrigobarriareyes/'], $H);
check('Guardar ajustes válidos: 200', $settingsPut['status'] === 200);
$dbSet = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='email'")->fetchColumn();
check('Ajuste persistido en MySQL', $dbSet === 'contacto@relatoestrategico.cl');

echo "\n== Páginas y bloques ==\n";
$pagesList = req('GET', '/v1/pages', null, ['Cookie: __Host-rs_session=' . $token]);
$pages = $pagesList['json']['pages'] ?? [];
check('Listado de páginas con bloques iniciales', $pagesList['status'] === 200 && count($pages) >= 1 && count($pages[0]['blocks'] ?? []) >= 1);
$pageKey = (string) ($pages[0]['key'] ?? '');
$pageGet = req('GET', '/v1/pages/' . $pageKey, null, ['Cookie: __Host-rs_session=' . $token]);
check('Lectura de una página por clave', $pageGet['status'] === 200 && ($pageGet['json']['page']['key'] ?? '') === $pageKey);
check('Página inexistente responde 404', req('GET', '/v1/pages/no-existe', null, ['Cookie: __Host-rs_session=' . $token])['status'] === 404);
check('Clave de página con formato inválido responde 404', req('GET', '/v1/pages/A_B!', null, ['Cookie: __Host-rs_session=' . $token])['status'] === 404);

$blockCount = count($pages[0]['blocks']);
$newBlock = req('POST', '/v1/pages/' . $pageKey . '/blocks', [
    'title' => 'Bloque de prueba', 'subtitle' => 'Subtítulo', 'description' => 'Texto explicativo',
    'button' => 'Ver más', 'url' => '/contacto', 'image_url' => '/rodrigo.webp', 'visible' => true,
], $H);
check('Crear bloque: 201 y se añade al final', $newBlock['status'] === 201 && count($newBlock['json']['page']['blocks'] ?? []) === $blockCount + 1);
$blocks = $newBlock['json']['page']['blocks'] ?? [];
$testBlockId = (int) (end($blocks)['id'] ?? 0);

$markupBlock = req('POST', '/v1/pages/' . $pageKey . '/blocks', ['title' => '<script>x</script>'], $H);
check('Bloque con marcado HTML en el título rechazado (422)', $markupBlock['status'] === 422);
$protoBlock = req('POST', '/v1/pages/' . $pageKey . '/blocks', ['title' => 'Enlace relativo', 'url' => '//evil.example'], $H);
check('Bloque con enlace //host rechazado (422)', $protoBlock['status'] === 422);
$jsBlock = req('POST', '/v1/pages/' . $pageKey . '/blocks', ['title' => 'Enlace js', 'url' => 'javascript:alert(1)'], $H);
check('Bloque con enlace javascript: rechazado (422)', $jsBlock['status'] === 422);

$hide = req('PUT', '/v1/pages/' . $pageKey . '/blocks/' . $testBlockId, [
    'title' => 'Bloque de prueba', 'subtitle' => 'Subtítulo', 'description' => 'Texto explicativo',
    'button' => 'Ver más', 'url' => '/contacto', 'image_url' => '/rodrigo.webp', 'visible' => false,
], $H);
check('Ocultar bloque sin borrarlo', $hide['status'] === 200);
$hidden = array_values(array_filter($hide['json']['page']['blocks'] ?? [], fn ($b) => $b['id'] === $testBlockId));
check('Bloque oculto persistido como no visible', count($hidden) === 1 && $hidden[0]['visible'] === false);

$current = array_map(fn ($b) => $b['id'], $hide['json']['page']['blocks']);
$reversed = array_reverse($current);
$reorder = req('PUT', '/v1/pages/' . $pageKey . '/order', ['ids' => $reversed], $H);
check('Reordenar bloques: 200', $reorder['status'] === 200);
$afterOrder = array_map(fn ($b) => $b['id'], $reorder['json']['page']['blocks'] ?? []);
check('El nuevo orden se guarda en la base de datos', $afterOrder === $reversed);
$partial = req('PUT', '/v1/pages/' . $pageKey . '/order', ['ids' => array_slice($current, 1)], $H);
check('Orden incompleto rechazado (422)', $partial['status'] === 422);
$notList = req('PUT', '/v1/pages/' . $pageKey . '/order', ['ids' => ['a' => 1]], $H);
check('Orden que no es una lista rechazado (422)', $notList['status'] === 422);
req('PUT', '/v1/pages/' . $pageKey . '/order', ['ids' => $current], $H);

$readerEdit = req('PUT', '/v1/pages/' . $pageKey . '/blocks/' . $testBlockId, ['title' => 'Lector intenta editar'], $RH);
check('Lector no puede editar bloques (403)', $readerEdit['status'] === 403);
check('Lector puede ver bloques', req('GET', '/v1/pages/' . $pageKey, null, ['Cookie: __Host-rs_session=' . $rToken])['status'] === 200);

$del = req('DELETE', '/v1/pages/' . $pageKey . '/blocks/' . $testBlockId, null, $H);
check('Eliminar bloque: 200', $del['status'] === 200);
check('Eliminar bloque inexistente responde 404', req('DELETE', '/v1/pages/' . $pageKey . '/blocks/' . $testBlockId, null, $H)['status'] === 404);

echo "\n== Multimedia ==\n";
$cookieAdmin = ['Cookie: __Host-rs_session=' . $token, 'X-CSRF-Token: ' . $csrfToken];
$png = makePng(64, 48);
$up = upload('foto de prueba.png', $png, $cookieAdmin);
check('Subir PNG válido: 201', $up['status'] === 201, 'status ' . $up['status'] . ' ' . substr($up['raw'], 0, 200));
$media = $up['json']['media'] ?? [];
$storedName = (string) ($media['stored_name'] ?? '');
check('Nombre almacenado aleatorio (32 hex + extensión)', preg_match('/^[a-f0-9]{32}\.png$/', $storedName) === 1, $storedName);
check('Imagen guardada con dimensiones y URL pública', ($media['width'] ?? 0) === 64 && str_starts_with((string) ($media['url'] ?? ''), 'https://api.relatoestrategico.cl/media/'));
check('Archivo existe en media_dir', is_file($mediaDir . '/' . $storedName));

$payload = makePng(32, 32) . 'PAYLOAD-OCULTO-PHP<?php system($_GET["c"]); ?>';
$withTail = upload('con-cola.png', $payload, $cookieAdmin);
$tailName = (string) ($withTail['json']['media']['stored_name'] ?? '');
check('PNG con datos añadidos se re-codifica', $withTail['status'] === 201 && $tailName !== '');
check('Los datos añadidos no sobreviven a la re-codificación', $tailName !== '' && !str_contains((string) file_get_contents($mediaDir . '/' . $tailName), 'PAYLOAD-OCULTO'));

$fakePhp = upload('shell.png', '<?php system($_GET["c"]); ?>', $cookieAdmin);
check('PHP disfrazado de PNG rechazado (422)', $fakePhp['status'] === 422);
$fakeJpg = upload('x.jpg', 'GIF89a' . str_repeat("\0", 20), $cookieAdmin);
check('Contenido GIF con extensión JPG rechazado (422)', $fakeJpg['status'] === 422);
$empty = upload('vacio.png', '', $cookieAdmin);
check('Archivo vacío rechazado (422)', $empty['status'] === 422);

$listMedia = req('GET', '/v1/media', null, ['Cookie: __Host-rs_session=' . $rToken]);
check('Lector puede listar la biblioteca', $listMedia['status'] === 200 && count($listMedia['json']['media'] ?? []) >= 1);

$pageImg = req('PUT', '/v1/settings', ['ogImage' => 'https://api.relatoestrategico.cl/media/' . $storedName], $H);
check('Imagen usada en ajustes: 200', $pageImg['status'] === 200);
$inUse = req('DELETE', '/v1/media/' . (int) $media['id'], null, $H);
check('Eliminar imagen en uso: 409', $inUse['status'] === 409);
req('PUT', '/v1/settings', ['ogImage' => ''], $H);
$delMedia = req('DELETE', '/v1/media/' . (int) $media['id'], null, $H);
clearstatcache(true); // el servidor borró el archivo: evitar la caché de stat de PHP
check('Eliminar imagen sin uso: 200 y archivo borrado', $delMedia['status'] === 200 && !is_file($mediaDir . '/' . $storedName), 'status ' . $delMedia['status'] . ' ' . $delMedia['raw']);
check('Eliminar imagen inexistente responde 404', req('DELETE', '/v1/media/999999', null, $H)['status'] === 404);

$htaccess = $apiDir . '/media/.htaccess';
check('Carpeta de medios con .htaccess que impide ejecutar scripts', is_file($htaccess) && stripos((string) file_get_contents($htaccess), 'Require all denied') !== false);

echo "\n== Administración de usuarios y roles ==\n";
$usersReader = req('GET', '/v1/users', null, ['Cookie: __Host-rs_session=' . $token]);
check('Listado de usuarios para el administrador', $usersReader['status'] === 200 && count($usersReader['json']['users'] ?? []) >= 2);
$readerRow = null;
foreach ($usersReader['json']['users'] ?? [] as $u) {
    if ($u['email'] === READER_EMAIL) {
        $readerRow = $u;
    }
}
$readerId = (int) ($readerRow['id'] ?? 0);

$inv = req('POST', '/v1/users', ['email' => INVITED_EMAIL, 'role' => 'editor'], $H);
check('Invitar usuario: 201 con rol editor', $inv['status'] === 201 && ($inv['json']['user']['role'] ?? '') === 'editor');
$invitedId = (int) ($inv['json']['user']['id'] ?? 0);
$invRows = (int) $pdo->query('SELECT COUNT(*) FROM password_resets WHERE user_id = ' . $invitedId)->fetchColumn();
check('La invitación crea un enlace de un solo uso (registro de recuperación)', $invRows === 1);
$dupUser = req('POST', '/v1/users', ['email' => INVITED_EMAIL, 'role' => 'reader'], $H);
check('Correo duplicado al invitar: 409', $dupUser['status'] === 409);
$badRole = req('POST', '/v1/users', ['email' => 'otro.pruebas@relato.test', 'role' => 'superadmin'], $H);
check('Rol inexistente rechazado (422)', $badRole['status'] === 422);
$badMail = req('POST', '/v1/users', ['email' => 'no-es-correo', 'role' => 'reader'], $H);
check('Correo inválido al invitar rechazado (422)', $badMail['status'] === 422);

$selfRole = req('PUT', '/v1/users/' . (int) $me['json']['user']['id'], ['role' => 'reader'], $H);
check('El administrador no puede cambiar su propio rol (422)', $selfRole['status'] === 422);
$selfDelete = req('DELETE', '/v1/users/' . (int) $me['json']['user']['id'], null, $H);
check('El administrador no puede eliminarse a sí mismo (422)', $selfDelete['status'] === 422);

$readerReset = [];
for ($i = 0; $i < 4; $i++) {
    $readerReset[] = req('POST', '/v1/users/' . $readerId . '/reset', null, $H)['status'];
}
check('Enlace de recuperación por cuenta: 3 por hora, la cuarta responde 429', $readerReset === [200, 200, 200, 429], implode(',', $readerReset));
$resetNoCsrf = req('POST', '/v1/users/' . $readerId . '/reset', null, ['Cookie: __Host-rs_session=' . $token]);
check('Enviar enlace sin CSRF rechazado (403)', $resetNoCsrf['status'] === 403);

$promote = req('PUT', '/v1/users/' . $readerId, ['role' => 'editor'], $H);
check('Cambiar rol de otro usuario: 200', $promote['status'] === 200 && ($promote['json']['user']['role'] ?? '') === 'editor');
$demote = req('PUT', '/v1/users/' . $readerId, ['role' => 'reader'], $H);
check('Devolver rol a lector: 200', $demote['status'] === 200);

$readerSessionBefore = login(READER_EMAIL, READER_PASSWORD);
$rsToken = cookieFrom($readerSessionBefore);
check('El lector tiene sesión abierta antes de desactivarlo', $rsToken !== null);
$deactivate = req('PUT', '/v1/users/' . $readerId, ['active' => false], $H);
check('Desactivar cuenta: 200', $deactivate['status'] === 200 && ($deactivate['json']['user']['active'] ?? true) === false);
check('Desactivar cuenta cierra sus sesiones', req('GET', '/v1/auth/me', null, ['Cookie: __Host-rs_session=' . $rsToken])['status'] === 401);
check('Cuenta desactivada no puede iniciar sesión (401)', login(READER_EMAIL, READER_PASSWORD)['status'] === 401);
check('Enlace a cuenta desactivada rechazado (422)', req('POST', '/v1/users/' . $readerId . '/reset', null, $H)['status'] === 422);
$activate = req('PUT', '/v1/users/' . $readerId, ['active' => true], $H);
check('Reactivar cuenta: 200', $activate['status'] === 200);
check('Cuenta reactivada vuelve a iniciar sesión', login(READER_EMAIL, READER_PASSWORD)['status'] === 200);

$readerAgain = login(READER_EMAIL, READER_PASSWORD);
$rToken = cookieFrom($readerAgain);
$rCsrf = (string) ($readerAgain['json']['csrf'] ?? '');
$RH = authHeaders((string) $rToken, $rCsrf);
$delInvited = req('DELETE', '/v1/users/' . $invitedId, null, $H);
check('Eliminar usuario: 200', $delInvited['status'] === 200);
check('Usuario eliminado no aparece en el listado', !str_contains(req('GET', '/v1/users', null, ['Cookie: __Host-rs_session=' . $token])['raw'], INVITED_EMAIL));
check('Usuarios: solo el administrador (lector 403)', req('GET', '/v1/users', null, ['Cookie: __Host-rs_session=' . $rToken])['status'] === 403);

echo "\n== Respaldo y restauración ==\n";
$exp = req('GET', '/v1/backup', null, ['Cookie: __Host-rs_session=' . $token]);
check('Descargar respaldo: 200 con formato relato-backup', $exp['status'] === 200 && ($exp['json']['format'] ?? '') === 'relato-backup');
check('El respaldo no contiene hashes de contraseña', !str_contains($exp['raw'], '$2y$') && !str_contains($exp['raw'], 'password_hash'));
check('El respaldo no contiene usuarios ni correos de administración', !str_contains($exp['raw'], ADMIN_EMAIL) && !str_contains($exp['raw'], READER_EMAIL));
check('El respaldo no contiene el secreto de la aplicación', !str_contains($exp['raw'], $secret));
check('El respaldo incluye páginas, ajustes y artículos', count($exp['json']['pages'] ?? []) >= 1 && isset($exp['json']['settings']['siteName']) && count($exp['json']['posts'] ?? []) >= 1);
$backupPosts = count($exp['json']['posts']);

$restoreNoConfirm = req('POST', '/v1/backup/restore', ['backup' => $exp['json']], $H);
check('Restaurar sin confirmación RESTAURAR rechazado (422)', $restoreNoConfirm['status'] === 422);
$restoreBadFormat = req('POST', '/v1/backup/restore', ['confirm' => 'RESTAURAR', 'backup' => ['format' => 'otro', 'version' => 1]], $H);
check('Respaldo con formato desconocido rechazado (422)', $restoreBadFormat['status'] === 422);
$restoreBadPage = req('POST', '/v1/backup/restore', ['confirm' => 'RESTAURAR', 'backup' => array_merge($exp['json'], ['pages' => [['key' => '../x', 'label' => 'x', 'blocks' => []]]])], $H);
check('Respaldo con clave de página maliciosa rechazado (422)', $restoreBadPage['status'] === 422);
$restoreReader = req('POST', '/v1/backup/restore', ['confirm' => 'RESTAURAR', 'backup' => $exp['json']], $RH);
check('Lector no puede restaurar (403)', $restoreReader['status'] === 403);

$restore = req('POST', '/v1/backup/restore', ['confirm' => 'RESTAURAR', 'backup' => $exp['json']], $H);
check('Restaurar respaldo válido: 200', $restore['status'] === 200, substr($restore['raw'], 0, 200));
check('Artículos restaurados con el mismo número', (int) $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn() === $backupPosts);
$pageAfter = req('GET', '/v1/pages/' . $pageKey, null, ['Cookie: __Host-rs_session=' . $token]);
check('Páginas restauradas tras el respaldo', $pageAfter['status'] === 200 && count($pageAfter['json']['page']['blocks'] ?? []) >= 1);

$reset = req('POST', '/v1/backup/reset', ['confirm' => 'SI'], $H);
check('Restablecer sin confirmación RESTABLECER rechazado (422)', $reset['status'] === 422);
$resetReader = req('POST', '/v1/backup/reset', ['confirm' => 'RESTABLECER'], $RH);
check('Lector no puede restablecer (403)', $resetReader['status'] === 403);
$doReset = req('POST', '/v1/backup/reset', ['confirm' => 'RESTABLECER'], $H);
check('Restablecer valores iniciales: 200', $doReset['status'] === 200);
$settingsAfterReset = req('GET', '/v1/settings', null, ['Cookie: __Host-rs_session=' . $token]);
check('Restablecer deja los ajustes iniciales', ($settingsAfterReset['json']['settings']['siteName'] ?? '') === 'Relato Estratégico');
check('Restablecer no borra los artículos', (int) $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn() === $backupPosts);

echo "\n== Protección contra fuerza bruta (límites por par, IP y cuenta) ==\n";
// Par IP + cuenta: 5 fallos bloquean esa combinación, incluso con la contraseña correcta.
$pdo->prepare('DELETE FROM login_attempts')->execute();
for ($i = 0; $i < 5; $i++) {
    login(READER_EMAIL, 'mala-clave-' . $i . '-xx');
}
$pairBlocked = login(READER_EMAIL, READER_PASSWORD);
check('Límite por par IP + cuenta: 429 con la contraseña correcta', $pairBlocked['status'] === 429);
$pdo->prepare('UPDATE login_attempts SET attempted_at = ? WHERE success = 0')->execute([gmdate('Y-m-d H:i:s', time() - 1000)]);
check('Tras vencer la ventana de 15 minutos, el acceso vuelve a funcionar', login(READER_EMAIL, READER_PASSWORD)['status'] === 200);

echo "\n== Sesiones: cierre, inactividad y expiración ==\n";
$readerLogin2 = login(READER_EMAIL, READER_PASSWORD);
$rToken2 = cookieFrom($readerLogin2);
$rCsrf2 = (string) ($readerLogin2['json']['csrf'] ?? '');
$logoutNoCsrf = req('POST', '/v1/auth/logout', null, ['Cookie: __Host-rs_session=' . $rToken2]);
check('Cierre de sesión sin CSRF rechazado (403)', $logoutNoCsrf['status'] === 403);
$logout = req('POST', '/v1/auth/logout', null, authHeaders((string) $rToken2, $rCsrf2));
check('Cierre de sesión real: 200', $logout['status'] === 200);
check('Token anterior inválido tras cerrar sesión (401)', req('GET', '/v1/auth/me', null, ['Cookie: __Host-rs_session=' . $rToken2])['status'] === 401);

$pdo->prepare('UPDATE admin_sessions SET last_activity = ? WHERE user_id = (SELECT id FROM admin_users WHERE email = ?)')
    ->execute([gmdate('Y-m-d H:i:s', time() - 31 * 60), ADMIN_EMAIL]);
check('Sesión expirada por inactividad (>30 min): 401', req('GET', '/v1/posts', null, ['Cookie: __Host-rs_session=' . $token])['status'] === 401);

$fresh = login(ADMIN_EMAIL, ADMIN_PASSWORD);
$token2 = cookieFrom($fresh);
$pdo->prepare('UPDATE admin_sessions SET expires_at = ? WHERE user_id = (SELECT id FROM admin_users WHERE email = ?)')
    ->execute([gmdate('Y-m-d H:i:s', time() - 60), ADMIN_EMAIL]);
check('Sesión expirada por tiempo máximo: 401', req('GET', '/v1/posts', null, ['Cookie: __Host-rs_session=' . $token2])['status'] === 401);

echo "\n== Límites con proxy de confianza (servidor de límites) ==\n";
$pdo->exec('DELETE FROM login_attempts WHERE success = 0');
$baseMain = $base;
$base = $baseLimits;
$xff = static fn (string $ip): array => ['X-Forwarded-For: ' . $ip];
$ipResults = [];
for ($i = 0; $i < 3; $i++) {
    $ipResults[] = login('fantasma' . $i . '@relato.test', 'x-' . $i . '-clave', $xff('203.0.113.7'))['status'];
}
$ipResults[] = login('fantasma9@relato.test', 'x-9-clave', $xff('203.0.113.7'))['status'];
check('Límite por IP (3/15 min) bloquea la cuarta prueba desde esa IP', $ipResults === [401, 401, 401, 429], implode(',', $ipResults));
check('Otra IP no queda bloqueada por el límite de la primera', login(READER_EMAIL, READER_PASSWORD, $xff('203.0.113.8'))['status'] === 200);

$acct = [];
foreach (['203.0.113.9', '203.0.113.10', '203.0.113.11', '203.0.113.12'] as $ip) {
    $acct[] = login(READER_EMAIL, 'mala-' . $ip . '-clave', $xff($ip))['status'];
}
$acct[] = login(READER_EMAIL, READER_PASSWORD, $xff('203.0.113.13'))['status'];
check('Límite por cuenta (4/h) bloquea también desde IP nuevas', $acct === [401, 401, 401, 401, 429], implode(',', $acct));

$adminNow = login(ADMIN_EMAIL, ADMIN_PASSWORD);
$uploadLimit = upload('grande.png', makePng(200, 200, true), ['Cookie: __Host-rs_session=' . cookieFrom($adminNow), 'X-CSRF-Token: ' . (string) ($adminNow['json']['csrf'] ?? '')]);
check('Imagen mayor que media_max_bytes responde 413', $uploadLimit['status'] === 413, 'status ' . $uploadLimit['status']);
$base = $baseMain;

echo "\n== Recuperación de contraseña ==\n";
$reqExisting = req('POST', '/v1/auth/password-reset/request', ['email' => ADMIN_EMAIL], ['X-CSRF-Token: ' . csrf()]);
$reqUnknown = req('POST', '/v1/auth/password-reset/request', ['email' => 'desconocido@relato.test'], ['X-CSRF-Token: ' . csrf()]);
check('Recuperación: respuesta idéntica exista o no la cuenta', $reqExisting['status'] === 200 && $reqExisting['json']['message'] === $reqUnknown['json']['message']);
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

echo "\n== Panel administrativo: CSP y archivos estáticos ==\n";
$indexHtml = (string) @file_get_contents($adminHtml . '/index.html');
check('Panel: script externo app.js', str_contains($indexHtml, 'src="app.js"'));
check('Panel: CSP sin unsafe-inline en script-src', preg_match("/script-src[^;]*'self'/", $indexHtml) === 1 && !preg_match("/script-src[^;]*unsafe-inline/", $indexHtml));
check('Panel: sin scripts inline', preg_match('#<script(?![^>]*\ssrc=)[^>]*>#i', $indexHtml) === 0);
check('Panel: app.js existe', is_file($adminHtml . '/app.js'));
$vercel = (string) @file_get_contents(dirname($adminHtml, 2) . '/vercel.json');
check('vercel.json envía CSP al panel', str_contains($vercel, "/admin/") && str_contains($vercel, 'Content-Security-Policy'));

echo "\n== Correo: invitaciones y recuperación con transporte de prueba ==\n";
$plainTokens = [];
$mailMessages = static function () use ($mailDir): array {
    $out = [];
    foreach (glob($mailDir . '/*.eml') ?: [] as $f) {
        $out[] = (string) file_get_contents($f);
    }
    return $out;
};
$findMail = static function (string $to, array $msgs): ?string {
    $found = null;
    foreach ($msgs as $m) {
        if (str_starts_with($m, 'To: ' . $to . "\r\n")) {
            $found = $m; // el más reciente
        }
    }
    return $found;
};
$tokenIn = static function (?string $mail): ?string {
    return ($mail !== null && preg_match('#\?reset=([a-f0-9]{64})#', $mail, $mm) === 1) ? $mm[1] : null;
};
$rowFor = static function (string $tok) use ($pdo): array {
    $st = $pdo->prepare('SELECT user_id, used_at, expires_at, created_at FROM password_resets WHERE token_hash = ?');
    $st->execute([hash('sha256', $tok)]);
    return $st->fetch() ?: [];
};
$csrfHdr = static fn (): array => ['X-CSRF-Token: ' . csrf()];
$confirm = static function (string $tok, string $pw) use ($csrfHdr): array {
    return req('POST', '/v1/auth/password-reset/confirm', ['token' => $tok, 'password' => $pw], $csrfHdr());
};
$requestReset = static function (string $email) use ($csrfHdr): array {
    return req('POST', '/v1/auth/password-reset/request', ['email' => $email], $csrfHdr());
};

// La recuperación del administrador revoca sus sesiones: se inicia sesión de nuevo con la contraseña nueva.
$adminAgain = login(ADMIN_EMAIL, NEW_PASSWORD);
$HM = authHeaders((string) cookieFrom($adminAgain), (string) ($adminAgain['json']['csrf'] ?? ''));
$mailUser = 'correo.pruebas@relato.test';
$created = req('POST', '/v1/users', ['email' => $mailUser, 'role' => 'reader'], $HM);
check('Usuario de prueba creado para flujos por correo (201)', $created['status'] === 201);
$mailUserId = (int) ($created['json']['user']['id'] ?? 0);

$invMsg = $findMail($mailUser, $mailMessages());
$invTok = $tokenIn($invMsg);
check('Invitación: correo generado en el transporte de prueba', $invMsg !== null && str_contains($invMsg, 'Te invitaron'));
check('Invitación: enlace con token de 64 hex', $invTok !== null);
if ($invTok !== null) {
    $plainTokens[] = $invTok;
    $ir = $rowFor($invTok);
    check('Invitación: token guardado solo como hash, sin usar, del usuario correcto',
        $ir !== [] && $ir['used_at'] === null && (int) $ir['user_id'] === $mailUserId);
    $ttl = strtotime((string) $ir['expires_at'] . ' UTC') - time();
    check('Invitación: vigencia de 30 minutos', $ttl > 29 * 60 && $ttl <= 30 * 60 + 5, (string) $ttl);
}
$ACCEPT_PW = 'Acc-' . bin2hex(random_bytes(10)) . '-9z';
$RESET_PW = 'Res-' . bin2hex(random_bytes(10)) . '-9z';
$acc = $invTok !== null ? $confirm($invTok, $ACCEPT_PW) : ['status' => 0];
check('Invitación: el enlace crea la contraseña (200)', $acc['status'] === 200);
$accAgain = $invTok !== null ? $confirm($invTok, OTHER_PASSWORD) : ['status' => 0];
check('Invitación: el enlace no se puede reutilizar (400)', $accAgain['status'] === 400);
check('Invitación: inicio de sesión con la contraseña creada', login($mailUser, $ACCEPT_PW)['status'] === 200);

echo "\n== Recuperación de contraseña por correo ==\n";
$before = count($mailMessages());
$r1 = $requestReset($mailUser);
check('Recuperación: respuesta 200', $r1['status'] === 200);
$recMsg = $findMail($mailUser, $mailMessages());
$recTok = $tokenIn($recMsg);
check('Recuperación: correo nuevo con enlace distinto al de invitación',
    $recTok !== null && $recTok !== $invTok && count($mailMessages()) === $before + 1);
if ($recTok !== null) {
    $plainTokens[] = $recTok;
}
$rc = $recTok !== null ? $confirm($recTok, $RESET_PW) : ['status' => 0];
check('Recuperación: el enlace cambia la contraseña (200)', $rc['status'] === 200);
check('Recuperación: la contraseña anterior deja de funcionar', login($mailUser, $ACCEPT_PW)['status'] === 401);
check('Recuperación: la contraseña nueva funciona', login($mailUser, $RESET_PW)['status'] === 200);
$rcAgain = $recTok !== null ? $confirm($recTok, OTHER_PASSWORD) : ['status' => 0];
check('Recuperación: el enlace es de un solo uso (400)', $rcAgain['status'] === 400);

$beforeUnknown = count($mailMessages());
$unk = $requestReset('desconocido.pruebas@relato.test');
check('Correo desconocido: misma respuesta que una cuenta existente',
    $unk['status'] === 200 && ($unk['json']['message'] ?? null) === ($r1['json']['message'] ?? '#'));
check('Correo desconocido: no se envía ningún mensaje', count($mailMessages()) === $beforeUnknown);

// Segundo enlace: dentro del límite (invitación + recuperación = 2 de 3 por hora).
$r2 = $requestReset($mailUser);
$expTok = $tokenIn($findMail($mailUser, $mailMessages()));
check('Segundo enlace dentro del límite por cuenta (3/h)', $r2['status'] === 200 && $expTok !== null && $expTok !== $recTok);
if ($expTok !== null) {
    $plainTokens[] = $expTok;
    $er = $rowFor($expTok);
    $ttl2 = strtotime((string) $er['expires_at'] . ' UTC') - time();
    check('Segundo enlace: vigencia de 30 minutos', $ttl2 > 29 * 60 && $ttl2 <= 30 * 60 + 5, (string) $ttl2);
    $pdo->prepare('UPDATE password_resets SET expires_at = ? WHERE token_hash = ?')
        ->execute([gmdate('Y-m-d H:i:s', time() - 60), hash('sha256', $expTok)]);
    $vencido = $confirm($expTok, OTHER_PASSWORD);
    check('Enlace vencido no cambia la contraseña (400)', $vencido['status'] === 400);
    check('Enlace vencido: la contraseña sigue siendo la de recuperación', login($mailUser, $RESET_PW)['status'] === 200);
}

// Tercer intento en la misma hora: límite por cuenta, sin correo y misma respuesta.
$beforeLimit = count($mailMessages());
$r3 = $requestReset($mailUser);
check('Límite por cuenta: sin correo adicional y respuesta genérica',
    count($mailMessages()) === $beforeLimit && $r3['status'] === 200 && ($r3['json']['message'] ?? null) === ($r1['json']['message'] ?? '#'));

check('Permisos de los mensajes de prueba: 0600',
    ($files = glob($mailDir . '/*.eml')) !== [] && array_unique(array_map(fn ($f) => fileperms($f) & 0777, $files)) === [0600]);
check('Directorio de correos de prueba: 0700', (fileperms($mailDir) & 0777) === 0700);

echo "\n== Guardas del transporte de prueba (subprocesos) ==\n";
$guardCode = "require '{$appDir}/src/Config.php'; require '{$appDir}/src/Mailer.php'; Config::load(); "
    . "exit(Mailer::send('x@ejemplo.test', 'Prueba', 'cuerpo', 'From: no-reply@relatoestrategico.cl') ? 0 : 3);";
$worldDir = sys_get_temp_dir() . '/relato-mail-open-' . bin2hex(random_bytes(4));
mkdir($worldDir, 0755, true);
$base = ['db' => $dbHost, 'app' => $appBase];
$guards = [
    'Producción con transporte file: rechazado al cargar la configuración' => [
        array_merge($base, ['app' => array_merge($appBase, ['environment' => 'production', 'mail_transport' => 'file'])]), false],
    'Transporte desconocido: rechazado' => [
        array_merge($base, ['app' => array_merge($appBase, ['mail_transport' => 'smtp'])]), false],
    'Directorio dentro de public-api: rechazado' => [
        array_merge($base, ['app' => array_merge($appBase, ['mail_test_dir' => $apiDir])]), false],
    'Directorio con permisos 0755: rechazado' => [
        array_merge($base, ['app' => array_merge($appBase, ['mail_test_dir' => $worldDir])]), false],
    'Configuración de prueba válida: se escribe el mensaje' => [
        $base, true],
];
foreach ($guards as $label => [$cfgArr, $shouldWork]) {
    $cfgPath = writeConfig($cfgArr);
    $apiBefore = count(glob($apiDir . '/*.eml') ?: []);
    $p = proc_open([PHP_BINARY, '-r', $guardCode], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, null, array_merge(getenv(), ['RELATO_CONFIG' => $cfgPath]));
    $code = proc_close($p);
    @unlink($cfgPath);
    $ok = $shouldWork ? $code === 0 : $code !== 0;
    check($label, $ok, "salida {$code}");
    check($label . ' (sin archivos en el directorio público)', count(glob($apiDir . '/*.eml') ?: []) === $apiBefore);
}
rmdir($worldDir);

echo "\n== Ausencia de tokens y contraseñas en registros ==\n";
$secretsToCheck = array_merge($plainTokens, [$ACCEPT_PW, $RESET_PW, NEW_PASSWORD, ADMIN_PASSWORD, OTHER_PASSWORD, $secret]);
$serverLog = (string) @file_get_contents($serverLogFile);
check('Registro del servidor disponible y con actividad (control de la revisión)', is_file($serverLogFile) && filesize($serverLogFile) > 0);
$auditAll = implode(' ', array_map('json_encode', $pdo->query('SELECT * FROM audit_log')->fetchAll()));
$loginAll = implode(' ', array_map('json_encode', $pdo->query('SELECT * FROM login_attempts')->fetchAll()));
$leak = false;
foreach ($secretsToCheck as $sec) {
    if ($sec !== '' && (str_contains($serverLog, $sec) || str_contains($auditAll, $sec) || str_contains($loginAll, $sec))) {
        $leak = true;
    }
}
check('Ni tokens ni contraseñas aparecen en registro del servidor, auditoría ni intentos', !$leak);
check('Las pruebas de correo sí dejaron mensajes en el transporte de prueba', count($mailMessages()) >= 5);

/* ---------------- ausencia de credenciales expuestas ---------------- */

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
check('Respuestas públicas sin secretos', !str_contains($whole, $secret));

/* ---------------- limpieza ---------------- */

$passed = count(array_filter($results, fn ($r) => $r[1]));
$total = count($results);
echo "\nResultado: {$passed}/{$total} pruebas superadas.\n";
exit($passed === $total ? 0 : 1);
