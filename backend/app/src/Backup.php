<?php
declare(strict_types=1);

/**
 * Respaldo y restauración de contenidos. Solo administradores.
 * El respaldo NO incluye usuarios, contraseñas, sesiones ni auditoría.
 * Los archivos multimedia no viajan en el JSON: se restauran solo si siguen en el servidor y su SHA-256 coincide.
 */
final class Backup
{
    private const FORMAT = 'relato-backup';
    private const VERSION = 1;
    private const ADMIN_ONLY = ['admin'];
    private const MAX_POSTS = 5000;
    private const MAX_MEDIA = 5000;
    private const MAX_PAGES = 50;

    /** Valores iniciales de ajustes (los mismos del panel demostrativo). */
    private const DEFAULT_SETTINGS = [
        'siteName' => 'Relato Estratégico',
        'email' => 'contacto@relatoestrategico.cl',
        'whatsapp' => '+56 9 5085 9889',
        'calendar' => 'https://calendar.app.google/K8EH7bzSrJ4ApRGr5',
        'linkedin' => 'https://www.linkedin.com/in/rodrigobarriareyes/',
        'instagram' => 'https://www.instagram.com/relatoestrategico/',
        'location' => 'Santiago, Chile',
        'seoTitle' => 'Relato Estratégico | Posicionamiento y estrategia en LinkedIn',
        'seoDescription' => 'Estrategia editorial y posicionamiento en LinkedIn para profesionales y ejecutivos.',
        'ogImage' => '',
    ];

    public static function export(): void
    {
        $session = Session::authorize(self::ADMIN_ONLY);
        Pages::ensureSeeded();

        $posts = Db::pdo()->query(
            'SELECT id, title, slug, category, excerpt, body, image_url, status, published_at, created_at, updated_at
             FROM posts ORDER BY id'
        )->fetchAll();
        $media = Db::pdo()->query(
            'SELECT original_name, stored_name, mime, width, height, size_bytes, sha256, created_at FROM media ORDER BY id'
        )->fetchAll();

        $payload = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'created_at' => Db::now(),
            'pages' => Pages::exportAll(),
            'settings' => Content::settingsMap(),
            'posts' => array_map(static fn (array $p): array => $p + ['id' => (int) $p['id']], $posts),
            'media' => $media,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        Security::logEvent('backup_exported', $session['user']['id']);
        Http::download($json, 'relato-respaldo-' . gmdate('Ymd-His') . '.json');
    }

    public static function restore(): void
    {
        $session = Session::authorize(self::ADMIN_ONLY);
        $data = Http::jsonBody(Http::MAX_BACKUP_BYTES);
        if (($data['confirm'] ?? null) !== 'RESTAURAR') {
            Http::fail(422, 'confirmation', 'Confirma la restauración escribiendo RESTAURAR.');
        }
        $backup = $data['backup'] ?? null;
        if (!is_array($backup)) {
            Http::fail(422, 'validation', 'El archivo de respaldo no es válido.');
        }

        $clean = self::validate($backup);
        $counts = self::apply($clean, $session['user']['id']);
        Security::logEvent('backup_restored', $session['user']['id'], json_encode($counts, JSON_THROW_ON_ERROR));
        Http::ok(['restored' => $counts]);
    }

    /** Restablece páginas y ajustes a los valores iniciales. No borra artículos ni multimedia. */
    public static function resetDefaults(array $in): void
    {
        $session = Session::authorize(self::ADMIN_ONLY);
        if (($in['confirm'] ?? null) !== 'RESTABLECER') {
            Http::fail(422, 'confirmation', 'Confirma el restablecimiento escribiendo RESTABLECER.');
        }
        $uid = $session['user']['id'];
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM page_blocks');
            $pdo->exec('DELETE FROM pages');
            Pages::insertDefaults($pdo, $uid);
            $pdo->exec('DELETE FROM site_settings');
            self::insertSettings($pdo, self::DEFAULT_SETTINGS, $uid);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Security::logEvent('backup_reset', $uid);
        Http::ok(['pages' => count(Pages::defaults())]);
    }

    /* ----------------------------- validación ----------------------------- */

    /** Valida el respaldo completo antes de tocar la base de datos. */
    private static function validate(array $b): array
    {
        if (($b['format'] ?? null) !== self::FORMAT || ($b['version'] ?? null) !== self::VERSION) {
            Http::fail(422, 'validation', 'El archivo no es un respaldo válido de este panel.');
        }

        $pages = $b['pages'] ?? null;
        if (!is_array($pages) || $pages === [] || count($pages) > self::MAX_PAGES) {
            Http::fail(422, 'validation', 'El respaldo no contiene páginas válidas.');
        }
        $cleanPages = [];
        $keys = [];
        foreach (array_values($pages) as $p) {
            $page = Pages::cleanPage(is_array($p) ? $p : []);
            if (isset($keys[$page['key']])) {
                Http::fail(422, 'validation', 'El respaldo repite una página.');
            }
            $keys[$page['key']] = true;
            $cleanPages[] = $page;
        }

        $settingsIn = $b['settings'] ?? [];
        if (!is_array($settingsIn)) {
            Http::fail(422, 'validation', 'Los ajustes del respaldo no son válidos.');
        }
        $settings = self::DEFAULT_SETTINGS;
        foreach (Content::cleanSettings($settingsIn) as $k => $v) {
            $settings[$k] = $v;
        }

        $posts = $b['posts'] ?? [];
        if (!is_array($posts) || count($posts) > self::MAX_POSTS) {
            Http::fail(422, 'validation', 'Los artículos del respaldo no son válidos.');
        }
        $cleanPosts = [];
        $ids = [];
        $slugs = [];
        foreach (array_values($posts) as $p) {
            $p = is_array($p) ? $p : [];
            $id = $p['id'] ?? null;
            if (!is_int($id) || $id < 1 || isset($ids[$id])) {
                Http::fail(422, 'validation', 'Un artículo del respaldo tiene un identificador no válido o repetido.');
            }
            $fields = Content::validatePostFields($p);
            if (isset($slugs[$fields['slug']])) {
                Http::fail(422, 'validation', 'El respaldo contiene dos artículos con el mismo slug.');
            }
            $ids[$id] = true;
            $slugs[$fields['slug']] = true;
            $cleanPosts[] = $fields + [
                'id' => $id,
                'published_at' => self::timestamp($p['published_at'] ?? null),
                'created_at' => self::timestamp($p['created_at'] ?? null) ?? Db::now(),
                'updated_at' => self::timestamp($p['updated_at'] ?? null) ?? Db::now(),
            ];
        }

        $media = $b['media'] ?? [];
        if (!is_array($media) || count($media) > self::MAX_MEDIA) {
            Http::fail(422, 'validation', 'La lista de multimedia del respaldo no es válida.');
        }
        $restorableMedia = [];
        $skippedMedia = 0;
        foreach (array_values($media) as $m) {
            $row = self::validateMedia(is_array($m) ? $m : []);
            if ($row === null) {
                $skippedMedia++;
                continue;
            }
            $restorableMedia[] = $row;
        }

        return [
            'pages' => $cleanPages,
            'settings' => $settings,
            'posts' => $cleanPosts,
            'media' => $restorableMedia,
            'media_skipped' => $skippedMedia,
        ];
    }

    /** Solo acepta una imagen si el archivo sigue en disco y su hash coincide. */
    private static function validateMedia(array $m): ?array
    {
        $stored = $m['stored_name'] ?? null;
        $mime = $m['mime'] ?? null;
        $sha = $m['sha256'] ?? null;
        if (!is_string($stored) || preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $stored) !== 1
            || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || !is_string($sha) || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            Http::fail(422, 'validation', 'Una imagen del respaldo tiene datos no válidos.');
        }
        $path = Media::dir() . '/' . $stored;
        if (!is_file($path) || !hash_equals($sha, (string) hash_file('sha256', $path))) {
            return null;
        }
        return [
            'original_name' => mb_substr((string) ($m['original_name'] ?? 'imagen'), 0, 200),
            'stored_name' => $stored,
            'mime' => (string) $mime,
            'width' => max(0, (int) ($m['width'] ?? 0)),
            'height' => max(0, (int) ($m['height'] ?? 0)),
            'size_bytes' => max(0, (int) ($m['size_bytes'] ?? filesize($path))),
            'sha256' => $sha,
            'created_at' => self::timestamp($m['created_at'] ?? null) ?? Db::now(),
        ];
    }

    private static function timestamp(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1 ? $value : null;
    }

    /* ----------------------------- aplicación ----------------------------- */

    /** Reemplaza páginas, ajustes y artículos, y añade las imágenes verificadas. Todo o nada. */
    private static function apply(array $c, int $uid): array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM page_blocks');
            $pdo->exec('DELETE FROM pages');
            $pageSt = $pdo->prepare(
                'INSERT INTO pages (page_key, label, description, sort_order, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $blockSt = $pdo->prepare(
                'INSERT INTO page_blocks (page_key, sort_order, title, subtitle, description, button, url, image_url, visible,
                                          created_at, updated_at, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $now = Db::now();
            $blockCount = 0;
            foreach ($c['pages'] as $i => $page) {
                $pageSt->execute([$page['key'], $page['label'], $page['description'], $i + 1, $now, $uid]);
                foreach ($page['blocks'] as $j => $b) {
                    $blockSt->execute([
                        $page['key'], $j + 1, $b['title'], $b['subtitle'], $b['description'], $b['button'],
                        $b['url'], $b['image_url'], $b['visible'] ? 1 : 0, $now, $now, $uid,
                    ]);
                    $blockCount++;
                }
            }

            $pdo->exec('DELETE FROM site_settings');
            self::insertSettings($pdo, $c['settings'], $uid);

            $pdo->exec('DELETE FROM posts');
            $postSt = $pdo->prepare(
                'INSERT INTO posts (id, title, slug, category, excerpt, body, image_url, status, published_at,
                                    created_at, updated_at, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($c['posts'] as $p) {
                $postSt->execute([
                    $p['id'], $p['title'], $p['slug'], $p['category'], $p['excerpt'], $p['body'], $p['image_url'],
                    $p['status'], $p['published_at'], $p['created_at'], $p['updated_at'], $uid,
                ]);
            }

            $mediaSt = $pdo->prepare(
                'INSERT INTO media (original_name, stored_name, mime, width, height, size_bytes, sha256, created_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE original_name = VALUES(original_name)'
            );
            foreach ($c['media'] as $m) {
                $mediaSt->execute([
                    $m['original_name'], $m['stored_name'], $m['mime'], $m['width'], $m['height'],
                    $m['size_bytes'], $m['sha256'], $m['created_at'], $uid,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'pages' => count($c['pages']),
            'blocks' => $blockCount,
            'settings' => count($c['settings']),
            'posts' => count($c['posts']),
            'media_restored' => count($c['media']),
            'media_skipped' => $c['media_skipped'],
        ];
    }

    private static function insertSettings(PDO $pdo, array $settings, int $uid): void
    {
        $now = Db::now();
        $st = $pdo->prepare(
            'INSERT INTO site_settings (setting_key, setting_value, updated_at, updated_by) VALUES (?, ?, ?, ?)'
        );
        foreach ($settings as $key => $value) {
            $st->execute([$key, (string) $value, $now, $uid]);
        }
    }
}
