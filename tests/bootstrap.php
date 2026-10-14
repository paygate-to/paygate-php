<?php

declare(strict_types=1);

// Composer's autoloader when installed, otherwise a minimal PSR-4 loader so the tests also run
// from a plain checkout with a global PHPUnit.
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;

    return;
}

spl_autoload_register(static function (string $class): void {
    $map = ['PayGate\\Tests\\' => __DIR__ . '/', 'PayGate\\' => __DIR__ . '/../src/'];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
