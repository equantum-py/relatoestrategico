<?php
declare(strict_types=1);

/** Validación de entradas. Mensajes en español, comprensibles para el administrador. */
final class Validate
{
    public static function email(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $email = strtolower(trim($value));
        if ($email === '' || strlen($email) > 254) {
            return null;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /** Texto con longitud acotada. Permite saltos de línea; rechaza caracteres de control. */
    public static function text(mixed $value, int $min, int $max, string $label): string
    {
        if ($value === null) {
            $value = '';
        }
        if (!is_string($value)) {
            Http::fail(422, 'validation', $label . ': formato no válido.');
        }
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            Http::fail(422, 'validation', sprintf('%s: debe tener entre %d y %d caracteres.', $label, $min, $max));
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            Http::fail(422, 'validation', $label . ': contiene caracteres no permitidos.');
        }
        return $value;
    }

    /** URL https o ruta interna que empieza con "/". Vacío permitido. */
    public static function url(mixed $value, string $label): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value) || mb_strlen($value) > 500) {
            Http::fail(422, 'validation', $label . ': la dirección es demasiado larga o no es válida.');
        }
        $value = trim($value);
        if (preg_match('#^https://[^\s<>"\'\\\\]+$#i', $value) === 1
            || preg_match('#^/[A-Za-z0-9/_.\-]{0,400}$#', $value) === 1) {
            return $value;
        }
        Http::fail(422, 'validation', $label . ': usa una dirección https:// completa o una ruta que empiece con /.');
    }
}
