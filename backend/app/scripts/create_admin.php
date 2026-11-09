<?php
declare(strict_types=1);

/**
 * Crea el PRIMER administrador (o uno adicional) desde la línea de comandos.
 * No acepta contraseñas como argumento (quedarían en el historial del shell).
 *
 * Uso en el servidor (desde SSH o Terminal de cPanel):
 *   php /home/cre116835/relato-app/scripts/create_admin.php correo@dominio.cl
 * La contraseña se pide por teclado sin mostrarse.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$appDir = dirname(__DIR__);
require $appDir . '/src/bootstrap.php';

$email = Validate::email($argv[1] ?? null);
if ($email === null) {
    fwrite(STDERR, "Uso: php create_admin.php correo@dominio.cl\n");
    exit(1);
}

$pdo = Db::pdo();
$st = $pdo->prepare('SELECT id FROM admin_users WHERE email = ? LIMIT 1');
$st->execute([$email]);
if ($st->fetch() !== false) {
    fwrite(STDERR, "Ya existe una cuenta con ese correo. Usa el procedimiento de recuperación de contraseña.\n");
    exit(1);
}

$isTty = function_exists('posix_isatty') ? posix_isatty(STDIN) : false;
if ($isTty) {
    fwrite(STDOUT, 'Contraseña (mínimo 12 caracteres, con letras y números): ');
    shell_exec('stty -echo');
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($isTty) {
    shell_exec('stty echo');
    fwrite(STDOUT, "\n");
}

$policyError = Security::passwordError($password);
if ($policyError !== null) {
    fwrite(STDERR, $policyError . "\n");
    exit(1);
}

$now = Db::now();
$pdo->prepare(
    'INSERT INTO admin_users (email, password_hash, role, active, created_at, updated_at)
     VALUES (?, ?, ?, 1, ?, ?)'
)->execute([$email, password_hash($password, PASSWORD_DEFAULT), 'admin', $now, $now]);

Security::logEvent('admin_created_cli', (int) $pdo->lastInsertId());
fwrite(STDOUT, "Administrador creado: {$email}\n");
