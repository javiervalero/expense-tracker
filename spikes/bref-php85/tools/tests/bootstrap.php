<?php

declare(strict_types=1);

// E_ALL, and every PHP-level diagnostic (including deprecations) becomes an exception.
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__ . '/../vendor/autoload.php';
