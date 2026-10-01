<?php

declare(strict_types=1);

/**
 * Lightweight PSR-4 autoloader for EidCloud\ApiMock without composer dependency.
 */
spl_autoload_register(function (string $class) {
    $prefix = 'EidCloud\\ApiMock\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
