<?php
declare(strict_types=1);

/** Respuestas JSON, cabeceras de seguridad, CORS y lectura segura del cuerpo. */
final class Http
{
    /** Límite general para JSON de formularios. */
    public const MAX_BODY_BYTES = 120000;
    /** Límite para restaurar respaldos (JSON completo). */
    public const MAX_BACKUP_BYTES = 10485760;

    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header('Cache-Control: no-store, max-age=0');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header_remove('X-Powered-By');
    }

    /** Solo el origen exacto de la web pública recibe cabeceras CORS. Otros orígenes no leen respuestas. */
    public static function cors(string $method): void
    {
        $allowed = (string) Config::app('allowed_origin');
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && hash_equals($allowed, $origin)) {
            header('Access-Control-Allow-Origin: ' . $allowed);
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
            header('Access-Control-Max-Age: 600');
        }
        header('Vary: Origin');
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /** Métodos que modifican datos exigen origen permitido (defensa adicional a CSRF). */
    public static function requireTrustedOrigin(): void
    {
        $allowed = (string) Config::app('allowed_origin');
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '' || !hash_equals($allowed, $origin)) {
            Security::logEvent('origin_rejected', null, 'origin');
            self::fail(403, 'forbidden_origin', 'Origen no permitido.');
        }
    }

    /** Cuerpo JSON de hasta MAX_BODY_BYTES. */
    public static function body(): array
    {
        return self::jsonBody(self::MAX_BODY_BYTES);
    }

    /** Cuerpo JSON con un límite explícito (por ejemplo, restauración de respaldos). */
    public static function jsonBody(int $maxBytes): array
    {
        $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_starts_with($ct, 'application/json')) {
            self::fail(415, 'unsupported_media', 'Envía los datos en formato JSON.');
        }
        $raw = self::readRaw($maxBytes);
        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            self::fail(400, 'bad_json', 'Los datos enviados no son válidos.');
        }
        if (!is_array($data)) {
            self::fail(400, 'bad_json', 'Los datos enviados no son válidos.');
        }
        return $data;
    }

    private static function readRaw(int $maxBytes): string
    {
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($raw === false || strlen($raw) > $maxBytes) {
            self::fail(413, 'too_large', 'La solicitud es demasiado grande.');
        }
        return $raw;
    }

    public static function json(int $status, array $data): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    /** Descarga de archivo (respaldo). No añade envoltorio "ok". */
    public static function download(string $body, string $filename, string $mime = 'application/json'): never
    {
        http_response_code(200);
        header('Content-Type: ' . $mime . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $body;
        exit;
    }

    public static function ok(array $data = [], int $status = 200): never
    {
        self::json($status, ['ok' => true] + $data);
    }

    public static function fail(int $status, string $code, string $message): never
    {
        self::json($status, ['ok' => false, 'error' => ['code' => $code, 'message' => $message]]);
    }
}
