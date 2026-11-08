<?php
declare(strict_types=1);

/**
 * Carga la configuración desde un archivo FUERA del directorio público.
 * Ruta por defecto: /home/cre116835/relato-config/config.php
 * Puede sobrescribirse con la variable de entorno RELATO_CONFIG (usada en pruebas).
 */
final class Config
{
    private static ?array $data = null;

    public static function load(): void
    {
        if (self::$data !== null) {
            return;
        }
        $path = (string) (getenv('RELATO_CONFIG') ?: '/home/cre116835/relato-config/config.php');
        if (!is_readable($path)) {
            throw new RuntimeException('Archivo de configuración no legible.');
        }
        $cfg = require $path;
        if (!is_array($cfg)) {
            throw new RuntimeException('La configuración debe devolver un arreglo.');
        }
        foreach (['db', 'app'] as $section) {
            if (!isset($cfg[$section]) || !is_array($cfg[$section])) {
                throw new RuntimeException('Falta la sección de configuración: ' . $section);
            }
        }
        $db = $cfg['db'];
        foreach (['host', 'name', 'user', 'pass'] as $key) {
            if (!array_key_exists($key, $db) || !is_string($db[$key]) || $db[$key] === '') {
                throw new RuntimeException('Configuración de base de datos incompleta: ' . $key);
            }
        }
        $secret = (string) ($cfg['app']['secret'] ?? '');
        if (strlen($secret) < 48 || str_contains($secret, 'CAMBIAR')) {
            throw new RuntimeException('app.secret no es válido (mínimo 48 caracteres aleatorios).');
        }
        if (!preg_match('#^https://[a-z0-9.-]+$#i', (string) ($cfg['app']['allowed_origin'] ?? ''))) {
            throw new RuntimeException('app.allowed_origin debe ser un origen https exacto.');
        }
        self::$data = $cfg;
    }

    public static function section(string $name): array
    {
        self::load();
        if (!isset(self::$data[$name]) || !is_array(self::$data[$name])) {
            throw new RuntimeException('Sección de configuración inexistente: ' . $name);
        }
        return self::$data[$name];
    }

    public static function app(string $key, mixed $default = null): mixed
    {
        $app = self::section('app');
        return array_key_exists($key, $app) ? $app[$key] : $default;
    }
}
