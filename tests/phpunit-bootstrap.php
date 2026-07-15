<?php

if (!defined('QUIQQER_SYSTEM')) {
    define('QUIQQER_SYSTEM', true);
}

if (!defined('QUIQQER_AJAX')) {
    define('QUIQQER_AJAX', true);
}

require_once __DIR__ . '/../../../../bootstrap.php';

spl_autoload_register(static function (string $class): void {
    if ($class === 'QUI\\Watcher') {
        require_once dirname(__DIR__) . '/src/QUI/Watcher.php';
        return;
    }

    $prefix = 'QUI\\Watcher\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/src/QUI/Watcher/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
