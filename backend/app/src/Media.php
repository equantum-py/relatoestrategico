<?php
declare(strict_types=1);

/**
 * Biblioteca multimedia. Solo se aceptan JPG, PNG y WebP verificados por contenido.
 * Cada imagen se re-codifica con GD: se eliminan metadatos y cualquier dato añadido tras la imagen.
 * Los archivos tienen nombre aleatorio y se guardan en app.media_dir (ver backend/public-api/media/.htaccess).
 */
final class Media
{
    private const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const MAX_SIDE = 6000;
    private const MAX_PIXELS = 25000000;
    private const DEFAULT_DIR = '/home/cre116835/public_html/relato-api/media';
    private const DEFAULT_BASE = 'https://api.relatoestrategico.cl/media';
    private const DEFAULT_MAX_BYTES = 5242880;

    public static function listAll(): void
    {
        Session::authorize(Content::ROLES_READ);
        $rows = Db::pdo()->query(
            'SELECT id, original_name, stored_name, mime, width, height, size_bytes, created_at
             FROM media ORDER BY created_at DESC, id DESC LIMIT 500'
        )->fetchAll();
        Http::ok(['media' => array_map([self::class, 'present'], $rows)]);
    }

    public static function upload(): void
    {
        $session = Session::authorize(Content::ROLES_WRITE_POSTS);
        $uid = $session['user']['id'];

        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            // Un archivo superior a post_max_size deja $_FILES vacío.
            Http::fail(413, 'too_large', 'La imagen supera el tamaño permitido.');
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            Http::fail(413, 'too_large', 'La imagen supera el tamaño permitido.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            Http::fail(422, 'validation', 'No se pudo recibir la imagen. Intenta nuevamente.');
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            Http::fail(422, 'validation', 'No se pudo recibir la imagen. Intenta nuevamente.');
        }

        $size = filesize($tmp);
        if ($size === false || $size === 0) {
            Http::fail(422, 'validation', 'El archivo está vacío.');
        }
        $max = self::maxBytes();
        if ($size > $max) {
            Http::fail(413, 'too_large', sprintf('La imagen supera el tamaño permitido (máximo %d MB).', (int) ($max / 1048576)));
        }

        $raw = (string) file_get_contents($tmp);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        if (!is_string($mime) || !isset(self::ALLOWED[$mime])) {
            Http::fail(422, 'validation', 'Formato no permitido. Usa JPG, PNG o WebP.');
        }
        if (!function_exists('imagecreatefromstring')) {
            Http::fail(503, 'unavailable', 'El procesamiento de imágenes no está disponible en el servidor.');
        }

        $info = @getimagesizefromstring($raw);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            Http::fail(422, 'validation', 'El archivo no es una imagen válida.');
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            Http::fail(422, 'validation', 'Las dimensiones de la imagen son demasiado grandes.');
        }

        $img = @imagecreatefromstring($raw);
        if ($img === false) {
            Http::fail(422, 'validation', 'No se pudo leer la imagen.');
        }
        ob_start();
        $encoded = match ($mime) {
            'image/jpeg' => imagejpeg($img, null, 88),
            'image/png' => imagepng($img, null, 6),
            default => imagewebp($img, null, 86),
        };
        $bin = (string) ob_get_clean();
        imagedestroy($img);
        if ($encoded === false || $bin === '') {
            Http::fail(422, 'validation', 'No se pudo procesar la imagen. Prueba con otro archivo.');
        }
        if ((new finfo(FILEINFO_MIME_TYPE))->buffer($bin) !== $mime) {
            Http::fail(422, 'validation', 'No se pudo procesar la imagen. Prueba con otro archivo.');
        }

        $stored = bin2hex(random_bytes(16)) . '.' . self::ALLOWED[$mime];
        $path = self::dir() . '/' . $stored;
        if (file_put_contents($path, $bin, LOCK_EX) === false) {
            error_log('[relato-api] No se pudo escribir en media_dir.');
            Http::fail(500, 'server_error', 'No se pudo guardar la imagen. Intenta nuevamente más tarde.');
        }
        @chmod($path, 0644);

        $now = Db::now();
        try {
            Db::pdo()->prepare(
                'INSERT INTO media (original_name, stored_name, mime, width, height, size_bytes, sha256, created_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                self::cleanName((string) ($file['name'] ?? 'imagen')), $stored, $mime, $width, $height,
                strlen($bin), hash('sha256', $bin), $now, $uid,
            ]);
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }
        $id = (int) Db::pdo()->lastInsertId();
        Security::logEvent('media_uploaded', $uid, $stored);

        $row = Db::pdo()->prepare(
            'SELECT id, original_name, stored_name, mime, width, height, size_bytes, created_at FROM media WHERE id = ?'
        );
        $row->execute([$id]);
        Http::ok(['media' => self::present($row->fetch())], 201);
    }

    public static function delete(int $id): void
    {
        $session = Session::authorize(Content::ROLES_WRITE_POSTS);
        $st = Db::pdo()->prepare('SELECT id, stored_name FROM media WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row === false) {
            Http::fail(404, 'not_found', 'La imagen no existe.');
        }
        $stored = (string) $row['stored_name'];
        if (self::usage($stored) > 0) {
            Http::fail(409, 'in_use', 'La imagen está en uso en una página, un artículo o un ajuste. Quítala de ahí antes de eliminarla.');
        }

        Db::pdo()->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
        $path = self::dir() . '/' . $stored;
        if (is_file($path)) {
            @unlink($path);
        }
        Security::logEvent('media_deleted', $session['user']['id'], $stored);
        Http::ok();
    }

    /** Número de contenidos que referencian el archivo. */
    public static function usage(string $stored): int
    {
        $like = '%' . $stored . '%';
        $pdo = Db::pdo();
        $count = 0;
        $st = $pdo->prepare('SELECT COUNT(*) FROM page_blocks WHERE image_url LIKE ? OR url LIKE ?');
        $st->execute([$like, $like]);
        $count += (int) $st->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE image_url LIKE ?');
        $st->execute([$like]);
        $count += (int) $st->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM site_settings WHERE setting_value LIKE ?');
        $st->execute([$like]);
        $count += (int) $st->fetchColumn();
        return $count;
    }

    public static function dir(): string
    {
        $dir = rtrim((string) Config::app('media_dir', self::DEFAULT_DIR), '/');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[relato-api] No se pudo crear media_dir.');
            Http::fail(500, 'server_error', 'Almacenamiento de imágenes no disponible.');
        }
        return $dir;
    }

    public static function url(string $stored): string
    {
        return rtrim((string) Config::app('media_base_url', self::DEFAULT_BASE), '/') . '/' . $stored;
    }

    public static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'original_name' => (string) $row['original_name'],
            'stored_name' => (string) $row['stored_name'],
            'mime' => (string) $row['mime'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'size_bytes' => (int) $row['size_bytes'],
            'created_at' => (string) $row['created_at'],
            'url' => self::url((string) $row['stored_name']),
        ];
    }

    private static function maxBytes(): int
    {
        return (int) Config::app('media_max_bytes', self::DEFAULT_MAX_BYTES);
    }

    private static function cleanName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = (string) preg_replace('/[^\p{L}\p{N} ._-]+/u', '_', $base);
        $base = trim($base);
        return mb_substr($base === '' ? 'imagen' : $base, 0, 200);
    }
}
