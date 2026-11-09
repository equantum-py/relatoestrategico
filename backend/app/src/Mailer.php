<?php
declare(strict_types=1);

/**
 * Envío de correos de acceso (recuperación e invitaciones).
 *
 * Producción: transporte "mail" (función mail() de PHP).
 * Pruebas: transporte "file". Cada mensaje se guarda como archivo .eml con permisos 0600
 * dentro de app.mail_test_dir y nunca se envía. Solo se permite con app.environment = test.
 * Config::load() valida la combinación y este archivo la vuelve a comprobar antes de escribir.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $body, string $headers): bool
    {
        $transport = (string) Config::app('mail_transport', 'mail');
        if ($transport === 'mail') {
            return @mail($to, $subject, $body, $headers);
        }
        if ($transport === 'file') {
            return self::writeTestMessage($to, $subject, $body, $headers);
        }
        throw new RuntimeException('Transporte de correo no válido.');
    }

    private static function writeTestMessage(string $to, string $subject, string $body, string $headers): bool
    {
        $dir = self::testDirectory();
        // Prefijo con marca de tiempo: el orden alfabético de los nombres es cronológico.
        $path = $dir . '/' . sprintf('%017.6F', microtime(true)) . '-' . bin2hex(random_bytes(6)) . '.eml';
        $message = 'To: ' . $to . "\r\nSubject: " . $subject . "\r\n" . $headers . "\r\n" . $body;
        if (file_put_contents($path, $message, LOCK_EX) === false) {
            return false;
        }
        chmod($path, 0600);
        return true;
    }

    private static function testDirectory(): string
    {
        if ((string) Config::app('environment', 'production') !== 'test') {
            throw new RuntimeException('Transporte de prueba desactivado: app.environment no es test.');
        }
        $configured = (string) Config::app('mail_test_dir', '');
        if ($configured === '' || $configured[0] !== '/') {
            throw new RuntimeException('app.mail_test_dir debe ser una ruta absoluta.');
        }
        $real = realpath($configured);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('app.mail_test_dir no existe.');
        }
        $publicRoots = array_filter([realpath(__DIR__ . '/../../public-api') ?: '', '/home/cre116835/public_html']);
        foreach ($publicRoots as $root) {
            if (self::isInside($real, $root)) {
                throw new RuntimeException('app.mail_test_dir está dentro de un directorio público.');
            }
        }
        if ((fileperms($real) & 0077) !== 0) {
            throw new RuntimeException('app.mail_test_dir debe tener permisos 0700.');
        }
        return $real;
    }

    private static function isInside(string $path, string $base): bool
    {
        $base = rtrim($base, '/');
        return $base !== '' && ($path === $base || str_starts_with($path, $base . '/'));
    }
}
