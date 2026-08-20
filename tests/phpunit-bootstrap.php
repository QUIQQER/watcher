<?php

if (!defined('QUIQQER_SYSTEM')) {
    define('QUIQQER_SYSTEM', true);
}

if (!defined('QUIQQER_AJAX')) {
    define('QUIQQER_AJAX', true);
}

require_once __DIR__ . '/DatabaseEnvironment.php';
require_once __DIR__ . '/../../../../bootstrap.php';

if (QUI\Watcher\Tests\DatabaseEnvironment::usesCiDatabase()) {
    $databasePlatform = QUI::getDataBaseConnection()->getDatabasePlatform();
    $databasePlatformClass = $databasePlatform::class;
    $databaseVendor = QUI\Watcher\Tests\DatabaseEnvironment::getCiVendor();

    if (!$databasePlatform instanceof Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
        throw new RuntimeException(
            'GitLab watcher tests expected a MySQL-compatible DBAL platform, got ' . $databasePlatformClass . '.'
        );
    }

    $isMariaDbPlatform = str_contains(strtolower($databasePlatformClass), 'maria');

    if (
        ($databaseVendor === 'mariadb' && !$isMariaDbPlatform)
        || ($databaseVendor === 'mysql' && $isMariaDbPlatform)
    ) {
        throw new RuntimeException(
            'GitLab DB_VENDOR=' . $databaseVendor . ' does not match DBAL platform ' . $databasePlatformClass . '.'
        );
    }
}

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
