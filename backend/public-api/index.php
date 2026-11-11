<?php
declare(strict_types=1);

/**
 * Controlador frontal de la API administrativa de Relato Estratégico.
 * La ruta de la aplicación se define con RELATO_APP_DIR (fuera del docroot).
 */

// Antes de cargar nada: un fallo de arranque nunca debe mostrar rutas ni errores al cliente.
ini_set('display_errors', '0');

$appDir = rtrim((string) (getenv('RELATO_APP_DIR') ?: '/home/cre116835/relato-app'), '/');
require $appDir . '/src/bootstrap.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$path = '/' . trim($path, '/');

Http::securityHeaders();
Http::cors($method);
if (!in_array($method, ['GET', 'HEAD'], true)) {
    Http::requireTrustedOrigin();
}

const PAGE_KEY = '([a-z][a-z0-9-]{1,39})';
const ID = '(\d{1,10})';

$routes = [
    ['GET',    '#^/v1/public/featured$#',              static fn () => Content::publicFeatured()],
    ['GET',    '#^/v1/health$#',                       static fn () => Http::ok(['status' => 'up'])],
    ['GET',    '#^/v1/auth/csrf$#',                    static fn () => Auth::csrf()],
    ['POST',   '#^/v1/auth/login$#',                   static fn () => Auth::login(Http::body())],
    ['POST',   '#^/v1/auth/logout$#',                  static fn () => Auth::logout()],
    ['GET',    '#^/v1/auth/me$#',                      static fn () => Auth::me()],
    ['POST',   '#^/v1/auth/password-reset/request$#',  static fn () => Auth::resetRequest(Http::body())],
    ['POST',   '#^/v1/auth/password-reset/confirm$#', static fn () => Auth::resetConfirm(Http::body())],

    ['GET',    '#^/v1/posts$#',                        static fn () => Content::listPosts($_GET)],
    ['POST',   '#^/v1/posts$#',                        static fn () => Content::createPost(Http::body())],
    ['GET',    '#^/v1/posts/' . ID . '$#',             static fn (array $m) => Content::getPost((int) $m[1])],
    ['PUT',    '#^/v1/posts/' . ID . '$#',             static fn (array $m) => Content::updatePost((int) $m[1], Http::body())],
    ['DELETE', '#^/v1/posts/' . ID . '$#',             static fn (array $m) => Content::deletePost((int) $m[1])],
    ['GET',    '#^/v1/settings$#',                     static fn () => Content::getSettings()],
    ['PUT',    '#^/v1/settings$#',                     static fn () => Content::updateSettings(Http::body())],

    ['GET',    '#^/v1/pages$#',                        static fn () => Pages::listAll()],
    ['GET',    '#^/v1/pages/' . PAGE_KEY . '$#',       static fn (array $m) => Pages::getOne($m[1])],
    ['POST',   '#^/v1/pages/' . PAGE_KEY . '/blocks$#', static fn (array $m) => Pages::createBlock($m[1], Http::body())],
    ['PUT',    '#^/v1/pages/' . PAGE_KEY . '/blocks/' . ID . '$#', static fn (array $m) => Pages::updateBlock($m[1], (int) $m[2], Http::body())],
    ['DELETE', '#^/v1/pages/' . PAGE_KEY . '/blocks/' . ID . '$#', static fn (array $m) => Pages::deleteBlock($m[1], (int) $m[2])],
    ['PUT',    '#^/v1/pages/' . PAGE_KEY . '/order$#', static fn (array $m) => Pages::reorder($m[1], Http::body())],

    ['GET',    '#^/v1/media$#',                        static fn () => Media::listAll()],
    ['POST',   '#^/v1/media$#',                        static fn () => Media::upload()],
    ['DELETE', '#^/v1/media/' . ID . '$#',             static fn (array $m) => Media::delete((int) $m[1])],

    ['GET',    '#^/v1/users$#',                        static fn () => Users::listAll()],
    ['POST',   '#^/v1/users$#',                        static fn () => Users::create(Http::body())],
    ['PUT',    '#^/v1/users/' . ID . '$#',             static fn (array $m) => Users::update((int) $m[1], Http::body())],
    ['DELETE', '#^/v1/users/' . ID . '$#',             static fn (array $m) => Users::delete((int) $m[1])],
    ['POST',   '#^/v1/users/' . ID . '/reset$#',       static fn (array $m) => Users::sendReset((int) $m[1])],

    ['GET',    '#^/v1/backup$#',                       static fn () => Backup::export()],
    ['POST',   '#^/v1/backup/restore$#',               static fn () => Backup::restore()],
    ['POST',   '#^/v1/backup/reset$#',                 static fn () => Backup::resetDefaults(Http::body())],
];

$pathMatched = false;
foreach ($routes as [$verb, $pattern, $handler]) {
    if (preg_match($pattern, $path, $m) !== 1) {
        continue;
    }
    $pathMatched = true;
    if ($verb !== $method) {
        continue;
    }
    $handler($m);
    exit;
}

if ($pathMatched) {
    Http::fail(405, 'method_not_allowed', 'Método no permitido.');
}
Http::fail(404, 'not_found', 'Ruta no encontrada.');
