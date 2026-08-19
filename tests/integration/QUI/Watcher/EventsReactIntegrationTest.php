<?php

namespace QUI\Watcher\Tests;

use QUI;
use QUI\Interfaces\Projects\Site as SiteInterface;
use QUI\Interfaces\Users\User as UserInterface;
use QUI\Watcher\EventsReact;
use QUI\Watcher\Tests\Fixtures\WatcherSqliteTestCase;
use ReflectionProperty;

require_once __DIR__ . '/Fixtures/WatcherSqliteTestCase.php';

class EventsReactIntegrationTest extends WatcherSqliteTestCase
{
    public function testIgnoredAndDisabledEventsDoNotCreateEntries(): void
    {
        $this->enableWatcherLogging();
        $Config = $this->getWatcherConfig();
        $Config->setValue('settings', 'logEvents', 1);

        foreach (
            [
                'headerLoaded',
                'adminLoad',
                'adminLoadFooter',
                'userLoad',
                'siteInit',
                'siteLoad',
                'siteCheckActivate',
                'siteCheckDeactivate',
                'smartyInit'
            ] as $event
        ) {
            EventsReact::trigger($event, ['should' => 'be ignored']);
        }

        $Config->setValue('settings', 'logEvents', 0);
        EventsReact::trigger('userSave', ['uid' => 'disabled']);
        $Config->setValue('settings', 'logAjax', 0);
        EventsReact::onAjaxCall('disabled.ajax', [], ['disabled' => true]);

        self::assertSame(0, $this->countEntries());
    }

    public function testBackendRequirementPreventsFrontendUserEventLogging(): void
    {
        $this->enableWatcherLogging();
        $this->getWatcherConfig()->setValue('settings', 'logEvents', 1);
        $Users = QUI::getUsers();
        (new ReflectionProperty($Users, 'Session'))->setValue($Users, $Users->getNobody());

        EventsReact::trigger('userSave', ['uid' => 'frontend-user']);

        self::assertSame(0, $this->countEntries());
    }

    public function testCoreEventAdaptersPersistExactDomainContext(): void
    {
        $this->enableWatcherLogging();
        $this->getWatcherConfig()->setValue('settings', 'logEvents', 1);
        $this->useBackendTestUser();

        $User = $this->createMock(UserInterface::class);
        $User->method('getUUID')->willReturn('adapter-user');

        $Project = $this->createMock(QUI\Projects\Project::class);
        $Project->method('getName')->willReturn('adapter-project');
        $Project->method('getLang')->willReturn('de');

        $Package = $this->createMock(QUI\Package\Package::class);
        $Package->method('getName')->willReturn('vendor/adapter');

        $Site = $this->createMock(QUI\Projects\Site::class);
        $Site->method('getId')->willReturn(71);
        $Site->method('getProject')->willReturn($Project);

        $MediaItem = $this->createMock(QUI\Projects\Media\Item::class);
        $MediaItem->method('getId')->willReturn(81);
        $MediaItem->method('getProject')->willReturn($Project);

        EventsReact::onUserSave($User);
        EventsReact::onUserSetPassword($User);
        EventsReact::onUserDisable($User);
        EventsReact::onUserActivate($User);
        EventsReact::onUserDeactivate($User);
        EventsReact::onUserDelete($User);
        EventsReact::onProjectConfigSave('adapter-project', ['theme' => 'dark']);
        EventsReact::onCreateProject($Project);
        EventsReact::onPackageSetup($Package);
        EventsReact::onPackageInstall($Package);
        EventsReact::onPackageUninstall('vendor/removed');
        EventsReact::onSiteActivate($Site);
        EventsReact::onSiteDeactivate($Site);
        EventsReact::onSiteSave($Site);
        EventsReact::onSiteDelete(72, $Project);
        EventsReact::onSiteDestroy($Site);
        EventsReact::onSiteCreateChild(73, $Site);
        EventsReact::onSiteMove($Site, 70);
        EventsReact::onMediaActivate($MediaItem);
        EventsReact::onMediaDeactivate($MediaItem);
        EventsReact::onMediaSaveBegin($MediaItem);
        EventsReact::onMediaSave($MediaItem);
        EventsReact::onMediaDelete($MediaItem);
        EventsReact::onMediaDeleteBegin($MediaItem);
        EventsReact::onMediaDestroy($MediaItem);
        EventsReact::onMediaRename($MediaItem);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT localeVar, "call", callParams, localeParams FROM ' . $this->watcherTable() . ' ORDER BY id'
        );
        $expectedCalls = [
            'userSave', 'userSetPassword', 'userDisable', 'userActivate', 'userDeactivate', 'userDelete',
            'projectConfigSave', 'createProject', 'packageSetup', 'packageInstall', 'packageUninstall',
            'siteActivate', 'siteDeactivate', 'siteSave', 'siteDelete', 'siteDestroy', 'siteCreateChild', 'siteMove',
            'mediaActivate', 'mediaDeactivate', 'mediaSaveBegin', 'mediaSave', 'mediaDelete', 'mediaDeleteBegin',
            'mediaDestroy', 'mediaRename'
        ];

        self::assertSame($expectedCalls, array_column($rows, 'call'));

        foreach ($rows as $index => $row) {
            self::assertSame('watcher.message.' . $expectedCalls[$index], $row['localeVar']);
            self::assertSame(
                json_decode((string)$row['callParams'], true),
                json_decode((string)$row['localeParams'], true)
            );
        }

        self::assertSame(['uid' => 'adapter-user'], json_decode((string)$rows[0]['callParams'], true));
        self::assertSame(
            ['project' => 'adapter-project', 'config' => ['theme' => 'dark']],
            json_decode((string)$rows[6]['callParams'], true)
        );
        self::assertSame(
            ['newid' => 73, 'id' => 71, 'project' => 'adapter-project', 'lang' => 'de'],
            json_decode((string)$rows[16]['callParams'], true)
        );
        self::assertSame(
            ['id' => 81, 'project' => 'adapter-project', 'lang' => 'de'],
            json_decode((string)$rows[25]['callParams'], true)
        );
    }

    public function testConfiguredCustomEventAndAjaxCallbacksPersistTheirResults(): void
    {
        $this->enableWatcherLogging();
        $Config = $this->getWatcherConfig();
        $Config->setValue('settings', 'logEvents', 1);
        $Config->setValue('settings', 'logAjax', 1);
        $this->useBackendTestUser();

        $eventsTable = QUI\Utils\Doctrine::quoteIdentifier(QUI::getDBTableName('watcherEvents'));
        $this->connection->insert($eventsTable, [
            'package' => 'phpunit/custom',
            'event' => 'customEvent',
            'exec' => self::class . '::customEventCallback'
        ]);
        $this->connection->insert($eventsTable, [
            'package' => 'phpunit/custom',
            'ajax' => 'custom.ajax',
            'exec' => self::class . '::customAjaxCallback'
        ]);
        $this->clearWatcherEventsCache();

        EventsReact::trigger('missingEvent', ['ignored' => true]);
        EventsReact::trigger('customEvent', ['id' => 42]);
        EventsReact::onAjaxCall(['custom.ajax', 42, 'missing.ajax'], ['ok' => true], ['id' => 43]);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT message, "call", callParams FROM ' . $this->watcherTable() . ' ORDER BY id'
        );

        self::assertCount(2, $rows);
        self::assertSame('event customEvent:42', $rows[0]['message']);
        self::assertSame('customEvent', $rows[0]['call']);
        self::assertSame(['id' => 42], json_decode((string)$rows[0]['callParams'], true));
        self::assertSame('ajax custom.ajax:43:1', $rows[1]['message']);
        self::assertSame('custom.ajax', $rows[1]['call']);
        self::assertSame(['id' => 43], json_decode((string)$rows[1]['callParams'], true));
    }

    public function testHeaderRegistrationExecutesConfiguredRuntimeCallback(): void
    {
        $this->enableWatcherLogging();
        $this->getWatcherConfig()->setValue('settings', 'logEvents', 1);
        $eventsTable = QUI\Utils\Doctrine::quoteIdentifier(QUI::getDBTableName('watcherEvents'));
        $this->connection->insert($eventsTable, [
            'package' => 'phpunit/header',
            'event' => 'onPhpunitHeaderEvent',
            'exec' => self::class . '::customEventCallback'
        ]);
        $this->clearWatcherEventsCache();

        $EventsManager = QUI::getEvents();
        $EventsProperty = new ReflectionProperty($EventsManager, 'Events');
        $EventDispatcher = $EventsProperty->getValue($EventsManager);
        $registeredProperty = new ReflectionProperty($EventDispatcher, 'events');
        $originalEvents = $registeredProperty->getValue($EventDispatcher);

        try {
            EventsReact::onHeaderLoaded();
            $EventsManager->fireEvent('onPhpunitHeaderEvent', [['id' => 99]]);

            $row = $this->connection->fetchAssociative(
                'SELECT message, "call" FROM ' . $this->watcherTable()
            );

            self::assertIsArray($row);
            self::assertSame('event onPhpunitHeaderEvent:99', $row['message']);
            self::assertSame('onPhpunitHeaderEvent', $row['call']);
        } finally {
            $registeredProperty->setValue($EventDispatcher, $originalEvents);
        }
    }

    /** @param array<array-key, mixed> $arguments */
    public static function customEventCallback(string $event, array $arguments): string
    {
        $id = $arguments['id'] ?? $arguments[0]['id'] ?? null;

        return 'event ' . $event . ':' . $id;
    }

    /**
     * @param array<string, int> $params
     * @param array<string, bool> $result
     */
    public static function customAjaxCallback(string $function, array $params, array $result): string
    {
        return 'ajax ' . $function . ':' . $params['id'] . ':' . (int)$result['ok'];
    }

    private function countEntries(): int
    {
        return (int)$this->connection->fetchOne('SELECT COUNT(*) FROM ' . $this->watcherTable());
    }

    private function useBackendTestUser(): void
    {
        $User = $this->createMock(UserInterface::class);
        $User->method('getUUID')->willReturn('adapter-session-user');
        $User->method('canUseBackend')->willReturn(true);
        $User->method('getGroups')->willReturn([]);

        $Users = QUI::getUsers();
        (new ReflectionProperty($Users, 'Session'))->setValue($Users, $User);
        $this->getWatcherConfig()->setValue('settings', 'users_and_groups', 'uadapter-session-user');
        $this->resetWatcherState();
    }
}
