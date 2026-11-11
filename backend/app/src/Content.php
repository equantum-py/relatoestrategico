<?php
declare(strict_types=1);

/**
 * Contenidos con persistencia real: artículos del blog y ajustes generales del sitio.
 * Las páginas y bloques se administran en Pages.php.
 */
final class Content
{
    public const STATUSES = ['borrador', 'publicado'];
    private const LIST_COLUMNS = 'id, title, slug, category, excerpt, image_url, status, published_at, updated_at';
    private const POST_COLUMNS = 'id, title, slug, category, excerpt, body, image_url, status, published_at, created_at, updated_at';

    /** Ajustes permitidos (clave => tipo). Cualquier otra clave se rechaza. */
    public const SETTINGS = [
        'siteName' => 'text',
        'email' => 'email',
        'whatsapp' => 'text',
        'calendar' => 'url',
        'linkedin' => 'url',
        'instagram' => 'url',
        'location' => 'text',
        'seoTitle' => 'text',
        'seoDescription' => 'text',
        'ogImage' => 'url',
    ];

    public const ROLES_READ = ['admin', 'editor', 'reader'];
    public const ROLES_WRITE_POSTS = ['admin', 'editor'];
    public const ROLES_WRITE_SETTINGS = ['admin'];

    public static function publicFeatured(): void
    {
        $slug = self::featuredSlug();
        if ($slug !== '') {
            $st = Db::pdo()->prepare("SELECT id FROM posts WHERE slug = ? AND status = 'publicado' LIMIT 1");
            $st->execute([$slug]);
            if ($st->fetchColumn() === false) $slug = '';
        }
        Http::ok(['slug' => $slug]);
    }

    /** Destacado único persistido en site_settings; no requiere migración. */
    private static function featuredSlug(): string
    {
        $st = Db::pdo()->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'featuredPostSlug' LIMIT 1");
        $st->execute();
        return (string) ($st->fetchColumn() ?: '');
    }

    /** Marca `featured` solo si el artículo está publicado y es el destacado actual. */
    private static function markFeatured(array $post, string $featuredSlug): array
    {
        $post['featured'] = $post['status'] === 'publicado' && $post['slug'] === $featuredSlug;
        return $post;
    }

    /** Un único destacado: escribir un slug nuevo reemplaza al anterior; '' lo quita. */
    private static function saveFeatured(string $slug, int $uid): void
    {
        $st = Db::pdo()->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_at, updated_by) VALUES ('featuredPostSlug', ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)");
        $st->execute([$slug, Db::now(), $uid]);
    }

    /**
     * Lee el campo opcional `featured` del cuerpo. Ausente => null (conservar la selección actual).
     * Cualquier valor que no sea booleano se rechaza para evitar cambios accidentales.
     */
    private static function featuredFlag(array $in): ?bool
    {
        if (!array_key_exists('featured', $in)) {
            return null;
        }
        if (!is_bool($in['featured'])) {
            Http::fail(422, 'validation', 'El campo destacado debe ser verdadero o falso.');
        }
        return $in['featured'];
    }

    public static function listPosts(array $query): void
    {
        Session::authorize(self::ROLES_READ);

        $where = [];
        $params = [];

        $status = $query['status'] ?? '';
        if (is_string($status) && $status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                Http::fail(422, 'validation', 'Estado no válido.');
            }
            $where[] = 'status = ?';
            $params[] = $status;
        }

        $search = is_string($query['q'] ?? null) ? trim((string) $query['q']) : '';
        if ($search !== '') {
            $search = mb_substr($search, 0, 100);
            $where[] = 'title LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }

        $sql = 'SELECT ' . self::LIST_COLUMNS . ' FROM posts';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY updated_at DESC, id DESC LIMIT 200';

        $st = Db::pdo()->prepare($sql);
        $st->execute($params);
        $featuredSlug = self::featuredSlug();
        $posts = array_map(
            static fn (array $row): array => self::markFeatured(self::castPost($row), $featuredSlug),
            $st->fetchAll()
        );
        Http::ok(['posts' => $posts]);
    }

    public static function getPost(int $id): void
    {
        Session::authorize(self::ROLES_READ);
        Http::ok(['post' => self::postWithFeatured($id)]);
    }

    public static function createPost(array $in): void
    {
        $session = Session::authorize(self::ROLES_WRITE_POSTS);
        $data = self::cleanPost($in, 0);
        if (self::featuredFlag($in) === true) {
            self::requirePublishedForFeatured($data['status']); // validar antes de escribir
        }
        $now = Db::now();
        $uid = $session['user']['id'];

        $pdo = Db::pdo();
        try {
            $pdo->prepare(
                'INSERT INTO posts (title, slug, category, excerpt, body, image_url, status, published_at,
                                    created_at, updated_at, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $data['title'], $data['slug'], $data['category'], $data['excerpt'], $data['body'],
                $data['image_url'], $data['status'],
                $data['status'] === 'publicado' ? $now : null,
                $now, $now, $uid,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Http::fail(409, 'duplicate_slug', 'Ya existe un artículo con ese slug.');
            }
            throw $e;
        }

        $id = (int) $pdo->lastInsertId();
        if (self::featuredFlag($in) === true) {
            self::saveFeatured($data['slug'], $uid);
        }
        Security::logEvent('post_created', $uid, 'post:' . $id);
        Http::ok(['post' => self::postWithFeatured($id)], 201);
    }

    public static function updatePost(int $id, array $in): void
    {
        $session = Session::authorize(self::ROLES_WRITE_POSTS);
        $current = self::postOrFail($id);
        $data = self::cleanPost($in, $id);
        if (self::featuredFlag($in) === true) {
            self::requirePublishedForFeatured($data['status']); // validar antes de escribir
        }
        $now = Db::now();

        $publishedAt = $current['published_at'];
        if ($data['status'] === 'publicado' && $publishedAt === null) {
            $publishedAt = $now;
        }

        try {
            Db::pdo()->prepare(
                'UPDATE posts SET title = ?, slug = ?, category = ?, excerpt = ?, body = ?, image_url = ?,
                                  status = ?, published_at = ?, updated_at = ?, updated_by = ?
                 WHERE id = ?'
            )->execute([
                $data['title'], $data['slug'], $data['category'], $data['excerpt'], $data['body'],
                $data['image_url'], $data['status'], $publishedAt, $now, $session['user']['id'], $id,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Http::fail(409, 'duplicate_slug', 'Ya existe un artículo con ese slug.');
            }
            throw $e;
        }

        $wasFeatured = $current['slug'] === self::featuredSlug();
        $wantsFeatured = self::featuredFlag($in) ?? $wasFeatured; // sin campo `featured`, se conserva la selección
        if ($wantsFeatured && $data['status'] === 'publicado') {
            self::saveFeatured($data['slug'], $session['user']['id']);
        } elseif ($wasFeatured) {
            // Quitado a propósito, despublicado o eliminado de la selección: se limpia.
            self::saveFeatured('', $session['user']['id']);
        }
        Security::logEvent('post_updated', $session['user']['id'], 'post:' . $id);
        Http::ok(['post' => self::postWithFeatured($id)]);
    }

    public static function deletePost(int $id): void
    {
        $session = Session::authorize(self::ROLES_WRITE_POSTS);
        $post = self::postOrFail($id);
        if ($post['slug'] === self::featuredSlug()) self::saveFeatured('', $session['user']['id']);
        Db::pdo()->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
        Security::logEvent('post_deleted', $session['user']['id'], 'post:' . $id);
        Http::ok();
    }

    public static function getSettings(): void
    {
        Session::authorize(self::ROLES_READ);
        Http::ok(['settings' => self::settingsMap()]);
    }

    public static function updateSettings(array $in): void
    {
        $session = Session::authorize(self::ROLES_WRITE_SETTINGS);
        $clean = self::cleanSettings($in);
        self::storeSettings($clean, $session['user']['id']);
        Security::logEvent('settings_updated', $session['user']['id'], implode(',', array_keys($clean)));
        Http::ok(['settings' => self::settingsMap()]);
    }

    /* ----------------------- uso interno (también respaldos) ----------------------- */

    /** @return array<string,string> todas las claves permitidas, vacías si no hay valor. */
    public static function settingsMap(): array
    {
        $out = array_fill_keys(array_keys(self::SETTINGS), '');
        $rows = Db::pdo()->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
        foreach ($rows as $row) {
            if (array_key_exists($row['setting_key'], $out)) {
                $out[$row['setting_key']] = (string) $row['setting_value'];
            }
        }
        return $out;
    }

    /** Valida ajustes sin tocar la base de datos. Lanza 422 ante cualquier clave o valor no permitido. */
    public static function cleanSettings(array $in): array
    {
        $clean = [];
        foreach ($in as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, self::SETTINGS)) {
                Http::fail(422, 'validation', 'La solicitud contiene campos que no se pueden modificar.');
            }
            $clean[$key] = match (self::SETTINGS[$key]) {
                'email' => self::cleanEmailSetting($value),
                'url' => Validate::url($value, self::label($key)),
                default => Validate::text($value, 0, 300, self::label($key)),
            };
        }
        return $clean;
    }

    /** Guarda ajustes ya validados en una transacción. */
    public static function storeSettings(array $clean, int $uid): void
    {
        $pdo = Db::pdo();
        $now = Db::now();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'INSERT INTO site_settings (setting_key, setting_value, updated_at, updated_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                         updated_at = VALUES(updated_at),
                                         updated_by = VALUES(updated_by)'
            );
            foreach ($clean as $key => $value) {
                $st->execute([$key, $value, $now, $uid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Valida los campos de un artículo sin consultar la base de datos (sirve para restaurar respaldos). */
    public static function validatePostFields(array $in): array
    {
        $title = Validate::text($in['title'] ?? null, 3, 200, 'Título');
        $slugInput = is_string($in['slug'] ?? null) ? strtolower(trim((string) $in['slug'])) : '';
        $slug = $slugInput !== '' ? $slugInput : self::slugify($title);
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) < 3 || strlen($slug) > 120) {
            Http::fail(422, 'validation', 'El slug solo puede tener letras minúsculas, números y guiones (entre 3 y 120 caracteres).');
        }

        $status = $in['status'] ?? 'borrador';
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            Http::fail(422, 'validation', 'Estado no válido.');
        }

        return [
            'title' => $title,
            'slug' => $slug,
            'category' => Validate::text($in['category'] ?? '', 0, 100, 'Categoría'),
            'excerpt' => Validate::text($in['excerpt'] ?? '', 0, 500, 'Extracto'),
            'body' => Validate::text($in['body'] ?? '', 0, 50000, 'Contenido'),
            'image_url' => Validate::url($in['image_url'] ?? '', 'Imagen de portada'),
            'status' => $status,
        ];
    }

    /* ----------------------------- internos ----------------------------- */

    private static function cleanEmailSetting(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $email = Validate::email($value);
        if ($email === null) {
            Http::fail(422, 'validation', 'Correo de contacto: escribe una dirección de correo válida.');
        }
        return $email;
    }

    private static function label(string $key): string
    {
        return [
            'siteName' => 'Nombre del sitio',
            'whatsapp' => 'WhatsApp',
            'calendar' => 'Enlace de agenda',
            'linkedin' => 'Enlace de LinkedIn',
            'instagram' => 'Enlace de Instagram',
            'location' => 'Ubicación',
            'seoTitle' => 'Título SEO',
            'seoDescription' => 'Descripción SEO',
            'ogImage' => 'Imagen para compartir',
        ][$key] ?? $key;
    }

    private static function postOrFail(int $id): array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::POST_COLUMNS . ' FROM posts WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row === false) {
            Http::fail(404, 'not_found', 'El artículo no existe.');
        }
        return self::castPost($row);
    }

    /** Artículo con la propiedad `featured` calculada, para respuestas administrativas. */
    private static function postWithFeatured(int $id): array
    {
        return self::markFeatured(self::postOrFail($id), self::featuredSlug());
    }

    private static function requirePublishedForFeatured(string $status): void
    {
        if ($status !== 'publicado') {
            Http::fail(422, 'validation', 'Solo se pueden destacar artículos publicados.');
        }
    }

    private static function castPost(array $row): array
    {
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /** Valida y normaliza un artículo con comprobación de slug único. $id = 0 para creación. */
    private static function cleanPost(array $in, int $id): array
    {
        $data = self::validatePostFields($in);
        $st = Db::pdo()->prepare('SELECT id FROM posts WHERE slug = ? AND id <> ? LIMIT 1');
        $st->execute([$data['slug'], $id]);
        if ($st->fetch() !== false) {
            Http::fail(409, 'duplicate_slug', 'Ya existe un artículo con ese slug.');
        }
        return $data;
    }

    /** Slug desde el título. Sin guion final tras el recorte (P3-04). */
    public static function slugify(string $text): string
    {
        $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'];
        $slug = strtr(mb_strtolower($text), $map);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        return rtrim(substr($slug, 0, 120), '-');
    }
}
