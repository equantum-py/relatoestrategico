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
        'session_idle_minutes' => 30,
        'session_max_hours' => 8,
        'max_login_attempts' => 5,
        'lockout_minutes' => 15,
        'ip_attempts_per_15min' => 30,
        'reset_minutes' => 30,
    ],
];
