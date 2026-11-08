<?php
declare(strict_types=1);

/**
 * Páginas y bloques editables del sitio: contenido, orden y visibilidad.
 * La semilla reproduce el catálogo del panel demostrativo anterior.
 */
final class Pages
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9-]{1,39}$/';
    private const MAX_BLOCKS_PER_PAGE = 200;
    private const CALENDAR_URL = 'https://calendar.app.google/K8EH7bzSrJ4ApRGr5';

    /** Catálogo inicial: [clave, etiqueta, descripción, bloques]. */
    private const CATALOG = [
        ['home', 'Inicio', 'Portada y secciones actuales', ['Hero: Tu experiencia tiene valor', 'Soluciones según objetivo', 'Cuatro servicios', 'Resultados destacados', 'Rodrigo Barría', 'Noticias, tendencias y tips de LinkedIn', 'Contacto y agenda']],
        ['soluciones', 'Soluciones', 'Tres objetivos profesionales', ['Mejorar oportunidades laborales', 'Conseguir oportunidades de negocio', 'Posicionarme como referente']],
        ['servicios', 'Servicios', 'Cuatro soluciones comerciales', ['Perfil Estratégico', 'Presencia Constante', 'LinkedIn Elite', 'Taller Embajadores LinkedIn']],
        ['resultados', 'Resultados', 'Indicadores publicados y evidencias', ['Interacciones', '+2.135 % impresiones', '+24,1 % seguidores', '+57,4 % impresiones anuales', 'Gráficos y evidencias']],
        ['nosotros', 'Sobre nosotros', 'Biografía, fotografía y Método RUTA', ['Rodrigo Barría, periodista senior y director editorial', 'Fotografía: /rodrigo.webp', 'Método RUTA: Revisar', 'Método RUTA: Unificar', 'Método RUTA: Traducir', 'Método RUTA: Activar']],
        ['preguntas', 'Preguntas frecuentes', 'Ocho preguntas actuales', ['¿Para quién es la asesoría de marca personal en LinkedIn?', '¿Puedo construir mi marca personal en LinkedIn por mi cuenta?', '¿Cuánto cuestan los servicios?', '¿Cuánto tiempo debo dedicar si trabajan conmigo?', '¿Los contenidos serán genéricos? ¿Sonarán como yo?', '¿En cuánto tiempo se ven resultados?', '¿Garantizan más seguidores en LinkedIn?', '¿Puedo conseguir empleo o negocios gracias a LinkedIn?']],
        ['contacto', 'Contacto', 'Datos y canales actuales', ['WhatsApp: +56 9 5085 9889', 'Correo: contacto@relatoestrategico.cl', 'Agendamiento: Google Calendar', 'LinkedIn: Rodrigo Barría Reyes', 'Instagram: @relatoestrategico', 'Santiago, Chile · Atención remota y presencial']],
    ];

    private const DESCRIPTIONS = [
        'Perfil Estratégico' => 'Diagnóstico e informe; optimización completa del perfil con SEO; pilares de contenido y guía de uso de LinkedIn. Pago único.',
        'Presencia Constante' => 'Ocho contenidos mensuales, networking estratégico, gestión de interacciones y métricas. Ciclos renovables de tres meses.',
        'LinkedIn Elite' => 'Ghostwriting ejecutivo (12–16 contenidos), liderazgo de opinión, gestión reputacional, artículos, newsletter y medios. Cupos limitados.',
        'Taller Embajadores LinkedIn' => 'Capacitación para equipos: marca personal, optimización de perfiles, técnicas de crecimiento y ejercicios prácticos.',
        'Rodrigo Barría, periodista senior y director editorial' => 'Investiga, escribe y transforma trayectorias profesionales en relatos estratégicos para LinkedIn.',
        'Fotografía: /rodrigo.webp' => 'Fotografía existente en el repositorio y utilizada en Home y Sobre nosotros.',
        'Método RUTA: Revisar' => 'Diagnóstico de perfil, trayectoria, industria y objetivos.',
        'Método RUTA: Unificar' => 'Ordenar el relato y optimizar el perfil.',
        'Método RUTA: Traducir' => 'Convertir la experiencia en contenidos relevantes.',
        'Método RUTA: Activar' => 'Publicar, medir y ajustar la estrategia.',
        '+57,4 % impresiones anuales' => 'Indicador mostrado en la web. Validar evidencia y período antes de usarlo comercialmente.',
    ];

    /** Semilla completa: páginas con sus bloques. */
    public static function defaults(): array
    {
        $pages = [];
        foreach (self::CATALOG as [$key, $label, $description, $titles]) {
            $blocks = [];
            foreach ($titles as $title) {
                $blocks[] = [
                    'title' => $title,
                    'subtitle' => '',
                    'description' => self::DESCRIPTIONS[$title] ?? '',
                    'button' => '',
                    'url' => str_starts_with($title, 'Agendamiento:') ? self::CALENDAR_URL : '',
                    'image_url' => str_starts_with($title, 'Fotografía:') ? '/rodrigo.webp' : '',
                    'visible' => true,
                ];
            }
            $pages[] = ['key' => $key, 'label' => $label, 'description' => $description, 'blocks' => $blocks];
        }
        return $pages;
    }

    /** Inserta la semilla. Debe llamarse dentro de una transacción o sobre tablas vacías. */
    public static function insertDefaults(PDO $pdo, ?int $uid): void
    {
        $now = Db::now();
        $pageSt = $pdo->prepare(
            'INSERT INTO pages (page_key, label, description, sort_order, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $blockSt = $pdo->prepare(
            'INSERT INTO page_blocks (page_key, sort_order, title, subtitle, description, button, url, image_url, visible,
                                      created_at, updated_at, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (self::defaults() as $i => $page) {
            $pageSt->execute([$page['key'], $page['label'], $page['description'], $i + 1, $now, $uid]);
            foreach ($page['blocks'] as $j => $b) {
                $blockSt->execute([
                    $page['key'], $j + 1, $b['title'], $b['subtitle'], $b['description'], $b['button'],
                    $b['url'], $b['image_url'], $b['visible'] ? 1 : 0, $now, $now, $uid,
                ]);
            }
        }
    }

    /** Si no hay páginas, siembra la semilla. Idempotente. */
    public static function ensureSeeded(): void
    {
        $pdo = Db::pdo();
        if ((int) $pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn() > 0) {
            return;
        }
        $pdo->beginTransaction();
        try {
            if ((int) $pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn() === 0) {
                self::insertDefaults($pdo, null);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function listAll(): void
    {
        Session::authorize(Content::ROLES_READ);
        self::ensureSeeded();
        Http::ok(['pages' => self::exportAll()]);
    }

    public static function getOne(string $key): void
    {
        Session::authorize(Content::ROLES_READ);
        self::ensureSeeded();
        Http::ok(['page' => self::pageOrFail($key)]);
    }

    public static function createBlock(string $key, array $in): void
    {
        $session = Session::authorize(Content::ROLES_WRITE_POSTS);
        self::ensureSeeded();
        $page = self::pageOrFail($key);
        if (count($page['blocks']) >= self::MAX_BLOCKS_PER_PAGE) {
            Http::fail(422, 'validation', 'La página alcanzó el máximo de bloques permitido.');
        }
        $data = self::cleanBlock($in);
        $now = Db::now();
        $uid = $session['user']['id'];
        $st = Db::pdo()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM page_blocks WHERE page_key = ?');
        $st->execute([$key]);
        $next = (int) $st->fetchColumn();

        Db::pdo()->prepare(
            'INSERT INTO page_blocks (page_key, sort_order, title, subtitle, description, button, url, image_url, visible,
                                      created_at, updated_at, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $key, $next, $data['title'], $data['subtitle'], $data['description'], $data['button'],
            $data['url'], $data['image_url'], $data['visible'] ? 1 : 0, $now, $now, $uid,
        ]);
        Security::logEvent('page_block_created', $uid, $key);
        Http::ok(['page' => self::pageOrFail($key)], 201);
    }

    public static function updateBlock(string $key, int $id, array $in): void
    {
        $session = Session::authorize(Content::ROLES_WRITE_POSTS);
        self::ensureSeeded();
        self::blockOrFail($key, $id);
        $data = self::cleanBlock($in);
        Db::pdo()->prepare(
            'UPDATE page_blocks SET title = ?, subtitle = ?, description = ?, button = ?, url = ?, image_url = ?,
                                    visible = ?, updated_at = ?, updated_by = ?
             WHERE id = ? AND page_key = ?'
        )->execute([
            $data['title'], $data['subtitle'], $data['description'], $data['button'], $data['url'],
            $data['image_url'], $data['visible'] ? 1 : 0, Db::now(), $session['user']['id'], $id, $key,
        ]);
        Security::logEvent('page_block_updated', $session['user']['id'], $key . ':' . $id);
        Http::ok(['page' => self::pageOrFail($key)]);
    }

    public static function deleteBlock(string $key, int $id): void
    {
        $session = Session::authorize(Content::ROLES_WRITE_POSTS);
        self::ensureSeeded();
        self::blockOrFail($key, $id);
        Db::pdo()->prepare('DELETE FROM page_blocks WHERE id = ? AND page_key = ?')->execute([$id, $key]);
        Security::logEvent('page_block_deleted', $session['user']['id'], $key . ':' . $id);
        Http::ok(['page' => self::pageOrFail($key)]);
    }

    /** Reordena los bloques. Debe recibir exactamente los ids de la página, una sola vez cada uno. */
    public static function reorder(string $key, array $in): void
    {
        $session = Session::authorize(Content::ROLES_WRITE_POSTS);
        self::ensureSeeded();
        self::pageOrFail($key);

        $ids = $in['ids'] ?? null;
        if (!is_array($ids) || array_values($ids) !== $ids) {
            Http::fail(422, 'validation', 'El orden debe enviarse como una lista de bloques.');
        }
        $ids = array_map(static fn ($v) => is_int($v) ? $v : (is_string($v) && ctype_digit($v) ? (int) $v : -1), $ids);

        $st = Db::pdo()->prepare('SELECT id FROM page_blocks WHERE page_key = ? ORDER BY sort_order, id');
        $st->execute([$key]);
        $current = array_map('intval', array_column($st->fetchAll(), 'id'));
        $a = $current;
        $b = $ids;
        sort($a);
        sort($b);
        if ($a !== $b) {
            Http::fail(422, 'validation', 'La lista de bloques no coincide con los bloques de la página.');
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare('UPDATE page_blocks SET sort_order = ?, updated_at = ?, updated_by = ? WHERE id = ? AND page_key = ?');
            $now = Db::now();
            foreach ($ids as $pos => $id) {
                $upd->execute([$pos + 1, $now, $session['user']['id'], $id, $key]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Security::logEvent('page_blocks_reordered', $session['user']['id'], $key);
        Http::ok(['page' => self::pageOrFail($key)]);
    }

    /** @return array<int,array> todas las páginas con bloques, en orden. Sin autorización: lo llaman rutas ya protegidas. */
    public static function exportAll(): array
    {
        $pdo = Db::pdo();
        $pages = $pdo->query('SELECT page_key, label, description, sort_order FROM pages ORDER BY sort_order, page_key')->fetchAll();
        $blocks = $pdo->query(
            'SELECT id, page_key, sort_order, title, subtitle, description, button, url, image_url, visible
             FROM page_blocks ORDER BY page_key, sort_order, id'
        )->fetchAll();
        $byPage = [];
        foreach ($blocks as $row) {
            $byPage[$row['page_key']][] = self::castBlock($row);
        }
        $out = [];
        foreach ($pages as $p) {
            $out[] = [
                'key' => $p['page_key'],
                'label' => $p['label'],
                'description' => $p['description'],
                'sort_order' => (int) $p['sort_order'],
                'blocks' => $byPage[$p['page_key']] ?? [],
            ];
        }
        return $out;
    }

    /* ----------------------------- internos ----------------------------- */

    private static function validKey(string $key): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            Http::fail(404, 'not_found', 'La página no existe.');
        }
    }

    private static function pageOrFail(string $key): array
    {
        self::validKey($key);
        $st = Db::pdo()->prepare('SELECT page_key, label, description, sort_order FROM pages WHERE page_key = ? LIMIT 1');
        $st->execute([$key]);
        $page = $st->fetch();
        if ($page === false) {
            Http::fail(404, 'not_found', 'La página no existe.');
        }
        $st = Db::pdo()->prepare(
            'SELECT id, page_key, sort_order, title, subtitle, description, button, url, image_url, visible
             FROM page_blocks WHERE page_key = ? ORDER BY sort_order, id'
        );
        $st->execute([$key]);
        $blocks = array_map([self::class, 'castBlock'], $st->fetchAll());
        return [
            'key' => $page['page_key'],
            'label' => $page['label'],
            'description' => $page['description'],
            'sort_order' => (int) $page['sort_order'],
            'blocks' => $blocks,
        ];
    }

    private static function blockOrFail(string $key, int $id): void
    {
        self::validKey($key);
        $st = Db::pdo()->prepare('SELECT id FROM page_blocks WHERE id = ? AND page_key = ? LIMIT 1');
        $st->execute([$id, $key]);
        if ($st->fetch() === false) {
            Http::fail(404, 'not_found', 'El bloque no existe.');
        }
    }

    private static function castBlock(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'page_key' => $row['page_key'],
            'sort_order' => (int) $row['sort_order'],
            'title' => $row['title'],
            'subtitle' => $row['subtitle'],
            'description' => $row['description'],
            'button' => $row['button'],
            'url' => $row['url'],
            'image_url' => $row['image_url'],
            'visible' => (int) $row['visible'] === 1,
        ];
    }

    /** Valida un bloque completo. Usado por la API y por la restauración de respaldos. */
    public static function cleanBlock(array $in): array
    {
        return [
            'title' => Validate::text($in['title'] ?? null, 2, 200, 'Título'),
            'subtitle' => Validate::text($in['subtitle'] ?? '', 0, 300, 'Subtítulo'),
            'description' => Validate::text($in['description'] ?? '', 0, 2000, 'Texto explicativo'),
            'button' => Validate::text($in['button'] ?? '', 0, 100, 'Texto del botón'),
            'url' => Validate::url($in['url'] ?? '', 'Enlace del botón'),
            'image_url' => Validate::url($in['image_url'] ?? '', 'Imagen'),
            'visible' => Validate::bool($in['visible'] ?? true, 'Visibilidad'),
        ];
    }

    /** Valida una página completa del respaldo. */
    public static function cleanPage(array $in): array
    {
        $key = $in['key'] ?? null;
        if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            Http::fail(422, 'validation', 'Clave de página no válida en el respaldo.');
        }
        $blocks = $in['blocks'] ?? [];
        if (!is_array($blocks) || count($blocks) > self::MAX_BLOCKS_PER_PAGE) {
            Http::fail(422, 'validation', 'Bloques no válidos en el respaldo.');
        }
        return [
            'key' => $key,
            'label' => Validate::text($in['label'] ?? null, 1, 100, 'Nombre de página'),
            'description' => Validate::text($in['description'] ?? '', 0, 255, 'Descripción de página'),
            'blocks' => array_map(static fn ($b) => self::cleanBlock(is_array($b) ? $b : []), array_values($blocks)),
        ];
    }
}
