<?php

// Unit tests cover the framework-free Conformance code only, so they run
// without installing SeAT or Laravel.
spl_autoload_register(static function (string $class): void {
    $map = [
        'Goemktg\\Seat\\SeatCustomPlugin\\Tests\\' => __DIR__ . '/',
        'Goemktg\\Seat\\SeatCustomPlugin\\Conformance\\' => __DIR__ . '/../src/Conformance/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
