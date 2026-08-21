<?php

namespace QUI\Watcher\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Cache\Manager as CacheManager;
use QUI\Interfaces\Users\User as UserInterface;
use QUI\Permissions\Permission;
use QUI\Update;
use QUI\Watcher;
use QUI\Watcher\EventsReact;
use QUI\Watcher\Tests\DatabaseEnvironment;
use ReflectionProperty;

abstract class WatcherSqliteTestCase extends TestCase
{
    protected Connection $connection;

    private Connection $originalConnection;
    private bool $ownsTestConnection = false;
    private mixed $originalSessionUser;
    private mixed $originalPermissionUser;
    private bool $hadWatcherEventsCache = false;
    private mixed $originalWatcherEventsCache = null;

    /** @var array<string, mixed> */
    private array $originalWatcherState = [];

    /** @var array<string, array{existed: bool, value: mixed}> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = QUI::getDataBaseConnection();
        if (DatabaseEnvironment::usesCiDatabase()) {
            $this->connection = $this->originalConnection;
        } else {
            $this->connection = DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'memory' => true
            ]);
            $this->ownsTestConnection = true;
        }

        $Users = QUI::getUsers();
        $Session = new ReflectionProperty($Users, 'Session');
        $this->originalSessionUser = $Session->getValue($Users);
        $Session->setValue($Users, $Users->getSystemUser());

        $PermissionUser = new ReflectionProperty(Permission::class, 'User');
        $this->originalPermissionUser = $PermissionUser->getValue();
        Permission::setUser($Users->getSystemUser());

        foreach (['groups', 'users', 'checked', 'globalWatcherDisable'] as $property) {
            $this->originalWatcherState[$property] = (new ReflectionProperty(Watcher::class, $property))->getValue();
        }

        foreach (['logSystemUser', 'users_and_groups', 'logEvents', 'logAjax'] as $key) {
            $Config = $this->getWatcherConfig();
            $this->originalConfig[$key] = [
                'existed' => $Config->existValue('settings', $key),
                'value' => $Config->getValue('settings', $key)
            ];
        }

        try {
            $this->originalWatcherEventsCache = CacheManager::get($this->getWatcherEventsCacheKey());
            $this->hadWatcherEventsCache = true;
        } catch (\Exception) {
            $this->hadWatcherEventsCache = false;
        }

        CacheManager::clear($this->getWatcherEventsCacheKey());
        (new ReflectionProperty(EventsReact::class, 'watcherEvents'))->setValue(null, null);
        if ($this->ownsTestConnection) {
            $this->setConnection($this->connection);
            Update::importDatabase(dirname(__DIR__, 5) . '/database.xml');
        }

        $this->restoreDatabaseSchema();
        $this->clearDatabaseFixtures();
        $this->resetWatcherState();
    }

    protected function tearDown(): void
    {
        $this->restoreDatabaseSchema();
        $this->clearDatabaseFixtures();

        $this->restoreWatcherConfig();

        foreach ($this->originalWatcherState as $property => $value) {
            (new ReflectionProperty(Watcher::class, $property))->setValue(null, $value);
        }

        (new ReflectionProperty(EventsReact::class, 'watcherEvents'))->setValue(null, null);
        CacheManager::clear($this->getWatcherEventsCacheKey());

        if ($this->hadWatcherEventsCache) {
            CacheManager::set($this->getWatcherEventsCacheKey(), $this->originalWatcherEventsCache);
        }

        $this->setConnection($this->originalConnection);
        (new ReflectionProperty(QUI::getUsers(), 'Session'))->setValue(
            QUI::getUsers(),
            $this->originalSessionUser
        );
        (new ReflectionProperty(Permission::class, 'User'))->setValue(null, $this->originalPermissionUser);
        if ($this->ownsTestConnection) {
            $this->connection->close();
        }

        parent::tearDown();
    }

    protected function enableWatcherLogging(): void
    {
        $Config = $this->getWatcherConfig();
        $Config->setValue('settings', 'logSystemUser', 1);
        $Config->setValue('settings', 'users_and_groups', '');
        $this->resetWatcherState();
    }

    protected function getWatcherConfig(): QUI\Config
    {
        $Config = QUI::getPackage('quiqqer/watcher')->getConfig();

        if ($Config === null) {
            self::fail('The watcher configuration must be available.');
        }

        return $Config;
    }

    protected function setWatcherEventsCache(mixed $events): void
    {
        CacheManager::set($this->getWatcherEventsCacheKey(), $events);
        (new ReflectionProperty(EventsReact::class, 'watcherEvents'))->setValue(null, null);
    }

    protected function clearWatcherEventsCache(): void
    {
        CacheManager::clear($this->getWatcherEventsCacheKey());
        (new ReflectionProperty(EventsReact::class, 'watcherEvents'))->setValue(null, null);
    }

    protected function resetWatcherState(): void
    {
        foreach (['groups' => null, 'users' => null, 'checked' => []] as $property => $value) {
            (new ReflectionProperty(Watcher::class, $property))->setValue(null, $value);
        }

        Watcher::$globalWatcherDisable = false;
    }

    protected function watcherTable(): string
    {
        return QUI\Utils\Doctrine::quoteIdentifier(QUI::getDBTableName('watcher'));
    }

    protected function watcherCallColumn(): string
    {
        return $this->connection->getDatabasePlatform()->quoteSingleIdentifier('call');
    }

    private function setConnection(Connection $Connection): void
    {
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $Connection);
    }

    private function restoreDatabaseSchema(): void
    {
        if (!$this->ownsTestConnection) {
            Update::importDatabase(dirname(__DIR__, 5) . '/database.xml');
        }
    }

    private function clearDatabaseFixtures(): void
    {
        $SchemaManager = $this->connection->createSchemaManager();
        $watcherTable = QUI::getDBTableName('watcher');

        if ($SchemaManager->tablesExist([$watcherTable])) {
            $this->connection->executeStatement(
                'DELETE FROM ' . QUI\Utils\Doctrine::quoteIdentifier($watcherTable)
            );
        }

        $eventsTable = QUI::getDBTableName('watcherEvents');

        if ($SchemaManager->tablesExist([$eventsTable])) {
            $QueryBuilder = $this->connection->createQueryBuilder();
            $QueryBuilder
                ->delete(QUI\Utils\Doctrine::quoteIdentifier($eventsTable))
                ->where($QueryBuilder->expr()->like('package', ':package'))
                ->setParameter('package', 'phpunit/%')
                ->executeStatement();
        }
    }

    private function restoreWatcherConfig(): void
    {
        $Config = $this->getWatcherConfig();

        foreach ($this->originalConfig as $key => $original) {
            if (!$original['existed']) {
                $Config->del('settings', $key);
                continue;
            }

            $value = $original['value'];

            if (is_bool($value)) {
                $Config->setValue('settings', $key, (int)$value);
                continue;
            }

            if (is_int($value) || is_float($value) || is_string($value)) {
                $Config->setValue('settings', $key, $value);
            }
        }
    }

    private function getWatcherEventsCacheKey(): string
    {
        return 'quiqqer/watcher/events';
    }
}
