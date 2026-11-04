<?php
declare(strict_types=1);

// Autoloader for vendored third-party PHP (no Composer). See manifest.json.
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Masterminds\\' => __DIR__ . '/masterminds-html5/src/',
    ];
    foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
