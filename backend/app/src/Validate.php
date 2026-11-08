<?php
declare(strict_types=1);

/** Validación de entradas. Mensajes en español, comprensibles para el administrador. */
final class Validate
{
    /** Etiquetas HTML o de script: los textos se guardan como texto plano (P2-07). */
    private const MARKUP = '/<\/?[A-Za-z!?][^<>]*>/';

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

    /**
     * Texto plano con longitud acotada. Permite saltos de línea; rechaza caracteres de control
     * y cualquier etiqueta HTML. El escape final corresponde a quien renderiza (panel y sitio público).
     */
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
        if (preg_match(self::MARKUP, $value) === 1) {
            Http::fail(422, 'validation', $label . ': no se permite código HTML. Escribe solo texto.');
        }
        return $value;
    }

    /**
     * URL https completa o ruta interna que empieza con un único "/".
     * Rechaza "//host" y "/\host", que el navegador interpreta como otro sitio (P2-03).
     */
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
            || preg_match('#^/(?![/\\\\])[A-Za-z0-9/_.\-]{0,400}$#', $value) === 1) {
            return $value;
        }
        Http::fail(422, 'validation', $label . ': usa una dirección https:// completa o una ruta que empiece con / (sin // inicial).');
    }

    /** Booleano estricto: solo true/false, 1/0 o "1"/"0". */
    public static function bool(mixed $value, string $label): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return false;
        }
        Http::fail(422, 'validation', $label . ': valor no válido.');
    }
}
