<?php
declare(strict_types=1);

/** Utilidades criptográficas, registro de auditoría y políticas. Nunca registra secretos. */
final class Security
{
    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** HMAC con la clave de la aplicación: permite comparar sin guardar valores en claro. */
    public static function hmac(string $value): string
    {
        return hash_hmac('sha256', strtolower(trim($value)), (string) Config::app('secret'));
    }

    public static function ipHash(): string
    {
        return self::hmac(self::clientIp());
    }

    /** Registro de auditoría y seguridad (sin contraseñas, tokens ni contenido sensible). */
    public static function logEvent(string $event, ?int $userId, string $detail = ''): void
    {
        $st = Db::pdo()->prepare(
            'INSERT INTO audit_log (event, user_id, ip_hash, detail, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([
            substr($event, 0, 60),
            $userId,
            self::ipHash(),
            mb_substr($detail, 0, 250),
            Db::now(),
        ]);
    }

    /** Token de 256 bits en hexadecimal (64 caracteres). */
    public static function randomToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isHexToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
    }

    public static function passwordError(string $password): ?string
    {
        $len = mb_strlen($password);
        if ($len < 12) {
            return 'La contraseña debe tener al menos 12 caracteres.';
        }
        if ($len > 200) {
            return 'La contraseña es demasiado larga (máximo 200 caracteres).';
        }
        if (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            return 'La contraseña debe incluir al menos una letra y un número.';
        }
        return null;
    }

    /**
     * Token CSRF sin estado para formularios anónimos (login y recuperación).
     * Formato: vencimiento.nonce.firma_hmac. Caduca en 1 hora.
     */
    public static function csrfIssue(): string
    {
        $payload = (time() + 3600) . '.' . bin2hex(random_bytes(16));
        return $payload . '.' . hash_hmac('sha256', $payload, (string) Config::app('secret'));
    }

    public static function csrfCheckStateless(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || (int) $parts[0] < time()) {
            return false;
        }
        $expected = hash_hmac('sha256', $parts[0] . '.' . $parts[1], (string) Config::app('secret'));
        return hash_equals($expected, $parts[2]);
    }
}
