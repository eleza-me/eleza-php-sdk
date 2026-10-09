<?php

/**
 * Autoloader for projects that do not use Composer:
 *
 *     require __DIR__ . '/bot-sdk-php/autoload.php';
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Eleza\\Bot\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
