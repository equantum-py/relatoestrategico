<?php
declare(strict_types=1);

/** Conexión PDO única. Consultas preparadas, sin emulación, errores como excepciones. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::section('db');
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['name']);
            self::$pdo = new PDO($dsn, (string) $c['user'], (string) $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'",
            ]);
        }
        return self::$pdo;
    }

    /** Fecha y hora UTC en formato DATETIME, con desplazamiento opcional en segundos. */
    public static function now(int $offsetSeconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
    }
}
