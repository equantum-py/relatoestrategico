<?php
declare(strict_types=1);

/**
 * Sesiones del lado del servidor. La cookie solo contiene un token aleatorio;
 * en base de datos se guarda su SHA-256. Caducan por inactividad y por tiempo máximo.
 */
final class Session
{
    public const COOKIE = '__Host-rs_session';

    /** Crea la sesión y devuelve el token CSRF asociado (se envía al cliente en JSON, nunca en localStorage). */
    public static function create(int $userId): string
    {
        $token = Security::randomToken();
        $sid = hash('sha256', $token);
        $csrf = Security::randomToken();
        $maxHours = (int) Config::app('session_max_hours', 8);
        $now = Db::now();

        Db::pdo()->prepare(
            'INSERT INTO admin_sessions (id, user_id, csrf_token, created_at, last_activity, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$sid, $userId, $csrf, $now, $now, Db::now($maxHours * 3600)]);

        // Cookie de sesión: HttpOnly, Secure, SameSite=Strict, sin Domain (requerido por __Host-).
        setcookie(self::COOKIE, $token, [
            'expires' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        return $csrf;
    }

    /** @return array{sid:string,csrf:string,user:array{id:int,email:string,role:string}}|null */
    public static function current(): ?array
    {
        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (!Security::isHexToken($token)) {
            return null;
        }
        $sid = hash('sha256', $token);
        $st = Db::pdo()->prepare(
            'SELECT s.csrf_token, s.last_activity, s.expires_at, u.id AS user_id, u.email, u.role, u.active
             FROM admin_sessions s
             JOIN admin_users u ON u.id = s.user_id
             WHERE s.id = ? LIMIT 1'
        );
        $st->execute([$sid]);
        $row = $st->fetch();
        if ($row === false) {
            return null;
        }

        $now = time();
        $idleSeconds = (int) Config::app('session_idle_minutes', 30) * 60;
        $expired = strtotime($row['expires_at'] . ' UTC') <= $now
            || strtotime($row['last_activity'] . ' UTC') + $idleSeconds <= $now;

        if ($expired || (int) $row['active'] !== 1) {
            self::deleteRow($sid);
            if ($expired) {
                Security::logEvent('session_expired', (int) $row['user_id']);
            }
            return null;
        }

        Db::pdo()->prepare('UPDATE admin_sessions SET last_activity = ? WHERE id = ?')
            ->execute([Db::now(), $sid]);

        return [
            'sid' => $sid,
            'csrf' => (string) $row['csrf_token'],
            'user' => [
                'id' => (int) $row['user_id'],
                'email' => (string) $row['email'],
                'role' => (string) $row['role'],
            ],
        ];
    }

    /**
     * Exige sesión válida, token CSRF en métodos que modifican datos y rol permitido.
     * @param string[] $roles vacío = cualquier rol autenticado
     */
    public static function authorize(array $roles = []): array
    {
        $session = self::current();
        if ($session === null) {
            Http::fail(401, 'unauthenticated', 'Tu sesión no está activa o expiró. Inicia sesión nuevamente.');
        }

        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            if ($sent === '' || !hash_equals($session['csrf'], $sent)) {
                Security::logEvent('csrf_rejected', $session['user']['id'], 'session');
                Http::fail(403, 'csrf', 'La sesión no es válida para esta acción. Recarga la página e intenta nuevamente.');
            }
        }

        if ($roles !== [] && !in_array($session['user']['role'], $roles, true)) {
            Security::logEvent('forbidden', $session['user']['id'], 'role');
            Http::fail(403, 'forbidden', 'Tu rol no permite realizar esta acción.');
        }

        return $session;
    }

    public static function destroy(string $sid): void
    {
        self::deleteRow($sid);
        self::expireCookie();
    }

    public static function destroyAllForUser(int $userId): void
    {
        Db::pdo()->prepare('DELETE FROM admin_sessions WHERE user_id = ?')->execute([$userId]);
    }

    private static function deleteRow(string $sid): void
    {
        Db::pdo()->prepare('DELETE FROM admin_sessions WHERE id = ?')->execute([$sid]);
    }

    private static function expireCookie(): void
    {
        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
