<?php
/**
 * PLANTILLA de configuración. NO contiene secretos.
 *
 * En el servidor, copiar a:  /home/cre116835/relato-config/config.php
 * (carpeta FUERA de public_html), con permisos 600, y completar los valores
 * reales directamente en el servidor. Nunca subir el archivo real al repositorio.
 */
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'cre116835_relato_admin',
        'user' => 'cre116835_relato_app',
        'pass' => 'CAMBIAR_EN_SERVIDOR',
    ],
    'app' => [
        // Clave HMAC: 48+ caracteres aleatorios. Generar con: php -r "echo bin2hex(random_bytes(32));"
        'secret' => 'CAMBIAR_EN_SERVIDOR_CLAVE_ALEATORIA_DE_64_HEX_CARACTERES_MINIMO_48',
        // Único origen con permiso CORS y para métodos que modifican datos.
        'allowed_origin' => 'https://relatoestrategico.cl',
        'site_url' => 'https://relatoestrategico.cl',
        'mail_from' => 'no-reply@relatoestrategico.cl',
        // production: entorno real. mail_transport "file" solo se acepta con environment = test (pruebas).
        'environment' => 'production',
        'mail_transport' => 'mail',
        'session_idle_minutes' => 30,
        'session_max_hours' => 8,
        // Intentos fallidos por par IP+cuenta en 15 min (bloqueo acotado a esa conexión).
        'max_login_attempts' => 5,
        // Intentos fallidos por IP en 15 min, y por cuenta en 1 hora (umbral alto, frena ataques distribuidos).
        'ip_attempts_per_15min' => 30,
        'account_attempts_per_hour' => 50,
        'reset_minutes' => 30,
        // Enlaces de recuperación o invitación por cuenta y hora.
        'reset_per_account_per_hour' => 3,
        'reset_per_ip_per_hour' => 5,
        // Proxies de confianza (IP). Vacío = usar REMOTE_ADDR. Solo listar proxies controlados por el hosting.
        'trusted_proxies' => [],
        // Biblioteca multimedia: carpeta pública de imágenes y su URL base.
        'media_dir' => '/home/cre116835/public_html/relato-api/media',
        'media_base_url' => 'https://api.relatoestrategico.cl/media',
        'media_max_bytes' => 5242880,
    ],
];
