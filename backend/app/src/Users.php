<?php
declare(strict_types=1);

/**
 * Administración de usuarios y roles. Solo administradores; los permisos se validan aquí, no en el panel.
 * Las cuentas nuevas no reciben contraseña elegida por el administrador: reciben un enlace de invitación.
 */
final class Users
{
    private const ROLES = ['admin', 'editor', 'reader'];
    private const ADMIN_ONLY = ['admin'];

    public static function listAll(): void
    {
        Session::authorize(self::ADMIN_ONLY);
        $rows = Db::pdo()->query(
            'SELECT id, email, role, active, last_login_at, created_at FROM admin_users ORDER BY email'
        )->fetchAll();
        Http::ok(['users' => array_map([self::class, 'present'], $rows)]);
    }

    public static function create(array $in): void
    {
        $session = Session::authorize(self::ADMIN_ONLY);
        $email = Validate::email($in['email'] ?? null);
        if ($email === null) {
            Http::fail(422, 'validation', 'Escribe un correo electrónico válido.');
        }
        $role = self::role($in['role'] ?? 'reader');

        $pdo = Db::pdo();
        $st = $pdo->prepare('SELECT id FROM admin_users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        if ($st->fetch() !== false) {
            Http::fail(409, 'duplicate_email', 'Ya existe un usuario con ese correo.');
        }

        $now = Db::now();
        // Contraseña aleatoria e inutilizable: el usuario la define con el enlace de invitación.
        $pdo->prepare(
            'INSERT INTO admin_users (email, password_hash, role, active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)'
        )->execute([$email, password_hash(Security::randomToken(), PASSWORD_DEFAULT), $role, $now, $now]);
        $uid = (int) $pdo->lastInsertId();

        $sent = Auth::issueReset($uid, $email, true);
        Security::logEvent('user_created', $session['user']['id'], 'user:' . $uid . ' role:' . $role);
        Http::ok(['user' => self::find($uid), 'invitation_sent' => $sent], 201);
    }

    public static function update(int $id, array $in): void
    {
        $session = Session::authorize(self::ADMIN_ONLY);
        $actor = $session['user']['id'];
        $target = self::findRaw($id);

        $role = array_key_exists('role', $in) ? self::role($in['role']) : (string) $target['role'];
        $active = array_key_exists('active', $in)
            ? Validate::bool($in['active'], 'Estado')
            : (int) $target['active'] === 1;

        $demotesOrDeactivates = $role !== (string) $target['role'] || !$active;
        if ($id === $actor && $demotesOrDeactivates) {
            Http::fail(422, 'self_change', 'No puedes cambiar tu propio rol ni desactivar tu cuenta.');
        }
        if ((string) $target['role'] === 'admin' && $demotesOrDeactivates && (int) $target['active'] === 1) {
            self::ensureAnotherActiveAdmin($id);
        }

        Db::pdo()->prepare('UPDATE admin_users SET role = ?, active = ?, updated_at = ? WHERE id = ?')
            ->execute([$role, $active ? 1 : 0, Db::now(), $id]);

        // Un cambio de rol o una desactivación invalida todas las sesiones abiertas de esa cuenta.
        if ($demotesOrDeactivates) {
            Session::destroyAllForUser($id);
        }
        Security::logEvent('user_updated', $actor, 'user:' . $id . ' role:' . $role . ' active:' . ($active ? 1 : 0));
        Http::ok(['user' => self::find($id)]);
    }

    public static function delete(int $id): void
    {
        $session = Session::authorize(self::ADMIN_ONLY);
        if ($id === $session['user']['id']) {
            Http::fail(422, 'self_change', 'No puedes eliminar tu propia cuenta.');
        }
        $target = self::findRaw($id);
        if ((string) $target['role'] === 'admin' && (int) $target['active'] === 1) {
            self::ensureAnotherActiveAdmin($id);
        }
        Db::pdo()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        Security::logEvent('user_deleted', $session['user']['id'], 'user:' . $id);
        Http::ok();
    }

    public static function sendReset(int $id): void
    {
        $session = Session::authorize(self::ADMIN_ONLY);
        $target = self::findRaw($id);
        if ((int) $target['active'] !== 1) {
            Http::fail(422, 'inactive', 'La cuenta está desactivada. Actívala antes de enviar un enlace.');
        }
        if (!Auth::issueReset($id, (string) $target['email'])) {
            Http::fail(429, 'rate_limited', 'Esta cuenta ya recibió el máximo de enlaces en la última hora. Intenta más tarde.');
        }
        Security::logEvent('user_reset_sent', $session['user']['id'], 'user:' . $id);
        Http::ok(['message' => 'Enlace enviado al correo de la cuenta.']);
    }

    /* ----------------------------- internos ----------------------------- */

    private static function role(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ROLES, true)) {
            Http::fail(422, 'validation', 'Rol no válido. Usa Administrador, Editor o Lector.');
        }
        return $value;
    }

    private static function ensureAnotherActiveAdmin(int $excludeId): void
    {
        $st = Db::pdo()->prepare("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND active = 1 AND id <> ?");
        $st->execute([$excludeId]);
        if ((int) $st->fetchColumn() === 0) {
            Http::fail(409, 'last_admin', 'Debe quedar al menos un administrador activo.');
        }
    }

    private static function findRaw(int $id): array
    {
        $st = Db::pdo()->prepare('SELECT id, email, role, active FROM admin_users WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row === false) {
            Http::fail(404, 'not_found', 'El usuario no existe.');
        }
        return $row;
    }

    private static function find(int $id): array
    {
        $st = Db::pdo()->prepare('SELECT id, email, role, active, last_login_at, created_at FROM admin_users WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        return self::present($st->fetch());
    }

    private static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'role' => (string) $row['role'],
            'active' => (int) $row['active'] === 1,
            'last_login_at' => $row['last_login_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }
}
