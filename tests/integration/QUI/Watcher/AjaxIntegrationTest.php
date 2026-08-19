<?php

namespace QUI\Watcher\Tests;

use QUI;
use QUI\Ajax;
use QUI\Watcher\Tests\Fixtures\WatcherSqliteTestCase;
use ReflectionProperty;

require_once __DIR__ . '/Fixtures/WatcherSqliteTestCase.php';

class AjaxIntegrationTest extends WatcherSqliteTestCase
{
    public function testEndpointsRegisterPermissionsAndExecuteValidatedBehavior(): void
    {
        $Callables = new ReflectionProperty(Ajax::class, 'callables');
        $Permissions = new ReflectionProperty(Ajax::class, 'permissions');
        $originalCallables = $Callables->getValue();
        $originalPermissions = $Permissions->getValue();

        try {
            require dirname(__DIR__, 4) . '/ajax/list.php';
            require dirname(__DIR__, 4) . '/ajax/clear.php';

            $callables = Ajax::getRegisteredCallables();
            $permissions = $Permissions->getValue();
            $listName = 'package_quiqqer_watcher_ajax_list';
            $clearName = 'package_quiqqer_watcher_ajax_clear';

            self::assertSame(['params', 'search'], $callables[$listName]['params']);
            self::assertSame(['date'], $callables[$clearName]['params']);
            self::assertSame(
                ['Permission::checkAdminUser', 'quiqqer.watcher.readlog'],
                $permissions[$listName]
            );
            self::assertSame(
                ['Permission::checkAdminUser', 'quiqqer.watcher.clearlog'],
                $permissions[$clearName]
            );

            $this->connection->insert($this->watcherTable(), [
                'uid' => 'ajax-user',
                'message' => 'ajax entry',
                'statusTime' => '2026-01-01 10:00:00'
            ]);

            $list = $callables[$listName]['callable'];
            $result = $list(
                json_encode(['sortOn' => 'id', 'sortBy' => 'ASC', 'page' => 1, 'perPage' => 10]),
                json_encode(['uid' => 'ajax-user'])
            );

            self::assertSame(1, $result['total']);
            self::assertSame('ajax entry', $result['data'][0]['message']);
            $fallbackResult = $list('{invalid-json', '{invalid-json');
            self::assertSame(1, $fallbackResult['total']);
            self::assertSame('ajax entry', $fallbackResult['data'][0]['message']);

            $clear = $callables[$clearName]['callable'];
            $clear('2026-01-02');

            self::assertSame(0, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM ' . $this->watcherTable()));
        } finally {
            $Callables->setValue(null, $originalCallables);
            $Permissions->setValue(null, $originalPermissions);
        }
    }
}
