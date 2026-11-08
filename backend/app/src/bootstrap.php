<?php
declare(strict_types=1);

/** Arranque común de la API. No imprime errores al cliente. */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Validate.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Content.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('UTC');

set_exception_handler(static function (Throwable $e): void {
    // Solo tipo y ubicación: nunca el contenido de la solicitud ni credenciales.
    error_log('[relato-api] ' . get_class($e) . ' en ' . basename($e->getFile()) . ':' . $e->getLine());
    Http::fail(500, 'server_error', 'Error interno. Intenta nuevamente más tarde.');
});

Config::load();
