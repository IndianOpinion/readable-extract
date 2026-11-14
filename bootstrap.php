<?php

/*
 * Use readable-extract without Composer:
 *
 *     require 'path/to/readable-extract/bootstrap.php';
 *
 * Registers a PSR-4 autoloader for IndianOpinion\ReadableExtract. Nothing else.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'IndianOpinion\\ReadableExtract\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
