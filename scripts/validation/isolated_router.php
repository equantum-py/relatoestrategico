<?php
$root = realpath((string) getenv('RELATO_API_ROOT'));
$uri = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath($root . '/' . ltrim($uri, '/'));
if ($file && str_starts_with($file, $root . '/') && is_file($file) && !str_ends_with($file, '.php')) { return false; }
require $root . '/index.php';
