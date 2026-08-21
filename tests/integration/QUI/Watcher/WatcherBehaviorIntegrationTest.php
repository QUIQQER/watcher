<?php

namespace QUI\Watcher\Tests;

use DateTimeImmutable;
use QUI;
use QUI\Watcher;
use QUI\Watcher\Cron;
use QUI\Watcher\Tests\Fixtures\WatcherSqliteTestCase;

require_once __DIR__ . '/Fixtures/WatcherSqliteTestCase.php';

class WatcherBehaviorIntegrationTest extends WatcherSqliteTestCase
{
    public function testAddsStringAndLocaleEntriesWithSerializedContext(): void
    {
        $this->enableWatcherLogging();

        Watcher::addString('', 'ignored', ['reason' => 'empty']);
        Watcher::addString('plain message', 'unit-call', ['id' => 17]);
        Watcher::add(
            'quiqqer/watcher',
            'watcher.message.userSave',
            'userSave',
            ['uid' => 'test-user'],
            ['uid' => 'test-user']
        );

        $rows = $this->connection->fetchAllAssociative(
            'SELECT message, localeGroup, localeVar, localeParams, ' . $this->watcherCallColumn() . ', callParams, uid '
            . 'FROM ' . $this->watcherTable() . ' ORDER BY id ASC'
        );

        self::assertCount(2, $rows);
        self::assertSame('plain message', $rows[0]['message']);
        self::assertSame('unit-call', $rows[0]['call']);
        self::assertSame(['id' => 17], json_decode((string)$rows[0]['callParams'], true));
        self::assertSame(QUI::getUsers()->getSystemUser()->getUUID(), $rows[0]['uid']);
        self::assertSame('quiqqer/watcher', $rows[1]['localeGroup']);
        self::assertSame('watcher.message.userSave', $rows[1]['localeVar']);
        self::assertSame(['uid' => 'test-user'], json_decode((string)$rows[1]['localeParams'], true));
    }

    public function testGlobalDisablePreventsBothLogEntryTypes(): void
    {
        $this->enableWatcherLogging();
        Watcher::$globalWatcherDisable = true;

        Watcher::addString('must not be logged');
        Watcher::add('quiqqer/watcher', 'watcher.message.userSave');

        self::assertSame(0, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM ' . $this->watcherTable()));
    }

    public function testListHandlesAllSupportedOrdersAndSanitizesLimits(): void
    {
        $this->insertEntry('user-b', 'middle', '2026-01-02 10:00:00');
        $this->insertEntry('user-a', 'oldest', '2026-01-01 10:00:00');
        $this->insertEntry('user-a', 'newest', '2026-01-03 10:00:00');

        $cases = [
            [['order' => 'id', 'limit' => false], ['middle', 'oldest', 'newest']],
            [['order' => 'id DESC', 'limit' => false], ['newest', 'oldest', 'middle']],
            [['order' => 'uid', 'limit' => false], ['newest', 'oldest', 'middle']],
            [['order' => 'uid DESC', 'limit' => false], ['middle', 'newest', 'oldest']],
            [['order' => 'statusTime', 'limit' => false], ['oldest', 'middle', 'newest']],
            [['order' => 'statusTime DESC', 'limit' => false], ['newest', 'middle', 'oldest']],
            [['order' => 'invalid', 'limit' => false], ['oldest', 'middle', 'newest']]
        ];

        foreach ($cases as [$params, $expectedMessages]) {
            self::assertSame($expectedMessages, array_column(Watcher::getList($params), 'message'));
        }

        self::assertSame([], Watcher::getList(['limit' => new \stdClass()]));
        self::assertSame([], Watcher::getList(['order' => 'id', 'limit' => -2]));
        self::assertSame(['middle'], array_column(Watcher::getList(['order' => 'id', 'limit' => '-3,1']), 'message'));
    }

    public function testGridRendersLocaleEntryAndUsesSafeFallbacks(): void
    {
        $systemUuid = (string)QUI::getUsers()->getSystemUser()->getUUID();
        $this->connection->insert($this->watcherTable(), [
            'localeGroup' => 'quiqqer/watcher',
            'localeVar' => 'watcher.message.userSave',
            'localeParams' => '{invalid-json',
            'uid' => $systemUuid,
            'statusTime' => '2026-01-01 10:00:00'
        ]);

        $result = Watcher::getGridList([
            'sortOn' => ['invalid'],
            'sortBy' => ['invalid'],
            'page' => 0,
            'perPage' => 10
        ]);

        self::assertSame(1, $result['page']);
        self::assertSame(1, $result['total']);
        self::assertSame(
            QUI::getLocale()->get('quiqqer/watcher', 'watcher.message.userSave', []),
            $result['data'][0]['message']
        );
        self::assertSame(QUI::getUsers()->getSystemUser()->getUsername(), $result['data'][0]['username']);
    }

    public function testClearRejectsInvalidDateAndDeletesOnlyOlderEntries(): void
    {
        $this->insertEntry('user-a', 'older', '2026-01-01 00:00:00');
        $this->insertEntry('user-a', 'boundary', '2026-01-02 00:00:00');
        $this->insertEntry('user-a', 'newer', '2026-01-03 00:00:00');

        try {
            Watcher::clear('not-a-date');
            self::fail('An invalid date must be rejected.');
        } catch (QUI\Exception $Exception) {
            self::assertSame(
                QUI::getLocale()->get(
                    'quiqqer/watcher',
                    'exception.quiqqer.watcher.clearlog.error.wrongDateFormat'
                ),
                $Exception->getMessage()
            );
        }

        Watcher::clear('2026-01-02');

        self::assertSame(
            ['newer'],
            $this->connection->fetchFirstColumn('SELECT message FROM ' . $this->watcherTable() . ' ORDER BY id')
        );
    }

    public function testCronUsesDefaultAndSanitizedRetentionDays(): void
    {
        $today = new DateTimeImmutable('today');
        $this->insertEntry('user-a', 'old-default', $today->modify('-4 days')->format('Y-m-d H:i:s'));
        $this->insertEntry('user-a', 'within-default', $today->modify('-2 days')->format('Y-m-d H:i:s'));

        Cron::clearWatcherEntries(['days' => 'invalid']);

        self::assertSame(
            ['within-default'],
            $this->connection->fetchFirstColumn('SELECT message FROM ' . $this->watcherTable() . ' ORDER BY id')
        );

        $this->insertEntry('user-a', 'today', $today->modify('+1 hour')->format('Y-m-d H:i:s'));
        Cron::clearWatcherEntries(['days' => -10]);

        self::assertSame(
            ['today'],
            $this->connection->fetchFirstColumn('SELECT message FROM ' . $this->watcherTable() . ' ORDER BY id')
        );
    }

    private function insertEntry(string $uid, string $message, string $statusTime): void
    {
        $this->connection->insert($this->watcherTable(), [
            'uid' => $uid,
            'message' => $message,
            'statusTime' => $statusTime
        ]);
    }
}
