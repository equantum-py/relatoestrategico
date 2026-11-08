<?php
declare(strict_types=1);

/** Inicio y cierre de sesión, bloqueo por intentos fallidos y recuperación de contraseña. */
final class Auth
{
    private const GENERIC_FAILURE = 'Correo o contraseña incorrectos. Si olvidaste tu contraseña, usa “Recuperar contraseña”.';

    private static ?string $dummyHash = null;

    public static function csrf(): void
    {
        Http::ok(['csrf' => Security::csrfIssue()]);
    }

    private static function requireFormCsrf(): void
    {
        $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!Security::csrfCheckStateless($token)) {
            Security::logEvent('csrf_rejected', null, 'form');
            Http::fail(403, 'csrf', 'El formulario expiró. Recarga la página e intenta nuevamente.');
        }
    }

    /** Hash de referencia para igualar el tiempo de respuesta cuando el correo no existe. */
    private static function dummyHash(): string
    {
        if (self::$dummyHash === null) {
            self::$dummyHash = password_hash(Security::randomToken(), PASSWORD_DEFAULT);
        }
        return self::$dummyHash;
    }

    public static function login(array $in): void
    {
        self::requireFormCsrf();

        $email = Validate::email($in['email'] ?? null);
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        if ($email === null || $password === '' || strlen($password) > 200) {
            Http::fail(422, 'validation', 'Ingresa un correo válido y tu contraseña.');
        }

        $pdo = Db::pdo();
        $maxAttempts = (int) Config::app('max_login_attempts', 5);
        $lockMinutes = (int) Config::app('lockout_minutes', 15);
        $ipLimit = (int) Config::app('ip_attempts_per_15min', 30);
        $ipHash = Security::ipHash();
        $now = Db::now();

        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND success = 0 AND attempted_at >= ?'
        );
        $st->execute([$ipHash, Db::now(-900)]);
        if ((int) $st->fetchColumn() >= $ipLimit) {
            Security::logEvent('login_ip_throttled', null);
            Http::fail(429, 'rate_limited', 'Demasiados intentos desde esta conexión. Espera unos minutos e intenta nuevamente.');
        }

        $st = $pdo->prepare(
            'SELECT id, password_hash, failed_logins, locked_until, active, role
             FROM admin_users WHERE email = ? LIMIT 1'
        );
        $st->execute([$email]);
        $user = $st->fetch() ?: null;

        $locked = $user !== null && $user['locked_until'] !== null && $user['locked_until'] > $now;
        $hash = $user !== null ? (string) $user['password_hash'] : self::dummyHash();
        $passwordOk = password_verify($password, $hash);
        $active = $user !== null && (int) $user['active'] === 1;

        if ($user === null || !$passwordOk || !$active || $locked) {
            $pdo->prepare(
                'INSERT INTO login_attempts (ip_hash, email_hash, success, attempted_at) VALUES (?, ?, 0, ?)'
            )->execute([$ipHash, Security::hmac($email), $now]);

            if ($user !== null && !$locked) {
                // Si el bloqueo anterior ya venció, el contador vuelve a empezar.
                $base = ($user['locked_until'] !== null && $user['locked_until'] <= $now) ? 0 : (int) $user['failed_logins'];
                $fails = $base + 1;
                $lockUntil = $fails >= $maxAttempts ? Db::now($lockMinutes * 60) : null;
                $pdo->prepare('UPDATE admin_users SET failed_logins = ?, locked_until = ?, updated_at = ? WHERE id = ?')
                    ->execute([$fails, $lockUntil, $now, (int) $user['id']]);
            }

            Security::logEvent($locked ? 'login_while_locked' : 'login_failed', $user !== null ? (int) $user['id'] : null);
            Http::fail(401, 'invalid_credentials', self::GENERIC_FAILURE);
        }

        $uid = (int) $user['id'];
        $pdo->prepare('UPDATE admin_users SET failed_logins = 0, locked_until = NULL, last_login_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$now, $now, $uid]);

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
        }

        $pdo->prepare('INSERT INTO login_attempts (ip_hash, email_hash, success, attempted_at) VALUES (?, ?, 1, ?)')
            ->execute([$ipHash, Security::hmac($email), $now]);
        Security::logEvent('login_success', $uid);

        $csrf = Session::create($uid);
        Http::ok([
            'user' => ['id' => $uid, 'email' => $email, 'role' => (string) $user['role']],
            'csrf' => $csrf,
        ]);
    }

    public static function logout(): void
    {
        $session = Session::authorize();
        Session::destroy($session['sid']);
        Security::logEvent('logout', $session['user']['id']);
        Http::ok();
    }

    public static function me(): void
    {
        $session = Session::current();
        if ($session === null) {
            Http::fail(401, 'unauthenticated', 'Tu sesión no está activa o expiró. Inicia sesión nuevamente.');
        }
        Http::ok(['user' => $session['user'], 'csrf' => $session['csrf']]);
    }

    /** Siempre responde igual, exista o no la cuenta (evita enumerar correos). */
    public static function resetRequest(array $in): void
    {
        self::requireFormCsrf();
        $message = 'Si el correo corresponde a una cuenta autorizada, recibirás un enlace para crear una nueva contraseña en unos minutos.';

        $email = Validate::email($in['email'] ?? null);
        if ($email === null) {
            Http::ok(['message' => $message]);
        }

        $pdo = Db::pdo();
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE event = 'reset_requested' AND ip_hash = ? AND created_at >= ?"
        );
        $st->execute([Security::ipHash(), Db::now(-3600)]);
        if ((int) $st->fetchColumn() >= 5) {
            Security::logEvent('reset_throttled', null);
            Http::ok(['message' => $message]);
        }

        $st = $pdo->prepare('SELECT id FROM admin_users WHERE email = ? AND active = 1 LIMIT 1');
        $st->execute([$email]);
        $user = $st->fetch() ?: null;
        if ($user !== null) {
            $uid = (int) $user['id'];
            $token = Security::randomToken();
            $minutes = (int) Config::app('reset_minutes', 30);
            $now = Db::now();
            $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
                ->execute([$now, $uid]);
            $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, created_at, expires_at) VALUES (?, ?, ?, ?)')
                ->execute([$uid, hash('sha256', $token), $now, Db::now($minutes * 60)]);

            $link = rtrim((string) Config::app('site_url'), '/') . '/admin/?reset=' . $token;
            $body = "Recibimos una solicitud para restablecer tu contraseña del panel de Relato Estratégico.\n\n"
                . "Abre este enlace para crear una nueva contraseña (vence en {$minutes} minutos y solo puede usarse una vez):\n"
                . $link . "\n\n"
                . "Si no fuiste tú, ignora este mensaje: tu contraseña no cambiará.\n";
            $headers = 'From: ' . (string) Config::app('mail_from') . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n";
            $subject = mb_encode_mimeheader('Recuperar contraseña · Panel Relato Estratégico', 'UTF-8');
            if (!@mail($email, $subject, $body, $headers)) {
                error_log('[relato-api] Falló el envío del correo de recuperación.');
            }
            Security::logEvent('reset_requested', $uid);
        } else {
            Security::logEvent('reset_requested', null, 'unknown_account');
        }

        Http::ok(['message' => $message]);
    }

    public static function resetConfirm(array $in): void
    {
        self::requireFormCsrf();
        $token = is_string($in['token'] ?? null) ? $in['token'] : '';
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';

        if (!Security::isHexToken($token)) {
            Http::fail(400, 'invalid_token', 'El enlace no es válido o ya venció. Solicita uno nuevo.');
        }
        $policyError = Security::passwordError($password);
        if ($policyError !== null) {
            Http::fail(422, 'validation', $policyError);
        }

        $pdo = Db::pdo();
        $st = $pdo->prepare(
            'SELECT pr.id, pr.user_id FROM password_resets pr
             JOIN admin_users u ON u.id = pr.user_id
             WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > ? AND u.active = 1
             LIMIT 1'
        );
        $st->execute([hash('sha256', $token), Db::now()]);
        $reset = $st->fetch() ?: null;
        if ($reset === null) {
            Security::logEvent('reset_invalid_token', null);
            Http::fail(400, 'invalid_token', 'El enlace no es válido o ya venció. Solicita uno nuevo.');
        }

        $uid = (int) $reset['user_id'];
        $now = Db::now();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE admin_users SET password_hash = ?, failed_logins = 0, locked_until = NULL, updated_at = ? WHERE id = ?'
            )->execute([password_hash($password, PASSWORD_DEFAULT), $now, $uid]);
            $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
                ->execute([$now, $uid]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Session::destroyAllForUser($uid);
        Security::logEvent('password_reset_completed', $uid);
        Http::ok(['message' => 'Contraseña actualizada. Ya puedes iniciar sesión con la nueva contraseña.']);
    }
}
