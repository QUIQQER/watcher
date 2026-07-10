<?php

namespace QUI\Watcher\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Watcher;
use Throwable;

class WatcherDbalIntegrationTest extends TestCase
{
    private const TEST_PREFIX = 'phpunit-watcher-dbal-';

    public static function setUpBeforeClass(): void
    {
        self::skipIfDatabaseIsUnavailable();
        self::cleanupFixtures();
    }

    protected function setUp(): void
    {
        self::skipIfDatabaseIsUnavailable();
        self::cleanupFixtures();
    }

    protected function tearDown(): void
    {
        self::cleanupFixtures();
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanupFixtures();
    }

    public function testListSupportsFilteringSortingPaginationAndCount(): void
    {
        $uid = self::TEST_PREFIX . uniqid();
        $this->insertFixture($uid, 'first', '2026-01-01 10:00:00');
        $this->insertFixture($uid, 'second', '2026-01-01 11:00:00');
        $this->insertFixture($uid, 'third', '2026-01-01 12:00:00');

        $result = Watcher::getList(
            ['order' => 'statusTime ASC', 'limit' => false],
            ['uid' => $uid]
        );

        $this->assertSame(['first', 'second', 'third'], array_column($result, 'message'));

        $page = Watcher::getList(
            ['order' => 'statusTime ASC', 'limit' => '1,1'],
            ['uid' => $uid]
        );

        $this->assertCount(1, $page);
        $this->assertSame('second', $page[0]['message']);

        $dateRange = Watcher::getList(
            ['order' => 'statusTime ASC', 'limit' => false],
            [
                'uid' => $uid,
                'from' => '2026-01-01 11:00:00',
                'to' => '2026-01-01 12:00:00'
            ]
        );

        $this->assertSame(['second', 'third'], array_column($dateRange, 'message'));

        $count = Watcher::getList(
            ['count' => true, 'limit' => false],
            ['uid' => $uid]
        );

        $this->assertSame(3, (int)$count[0]['count']);
    }

    public function testGridListReturnsRequestedPageAndTotal(): void
    {
        $uid = self::TEST_PREFIX . uniqid();
        $this->insertFixture($uid, 'first', '2026-01-01 10:00:00');
        $this->insertFixture($uid, 'second', '2026-01-01 11:00:00');
        $this->insertFixture($uid, 'third', '2026-01-01 12:00:00');

        $result = Watcher::getGridList(
            [
                'sortOn' => 'statusTime',
                'sortBy' => 'ASC',
                'page' => 2,
                'perPage' => 1
            ],
            ['uid' => $uid]
        );

        $this->assertSame(2, $result['page']);
        $this->assertSame(3, $result['total']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('second', $result['data'][0]['message']);
        $this->assertSame('unknown', $result['data'][0]['username']);
    }

    private function insertFixture(string $uid, string $message, string $statusTime): void
    {
        self::getConnection()->insert(self::getTable(), [
            'message' => $message,
            'uid' => $uid,
            'statusTime' => $statusTime
        ]);
    }

    private static function skipIfDatabaseIsUnavailable(): void
    {
        try {
            self::getConnection()->createQueryBuilder()
                ->select('1')
                ->from(self::getTable())
                ->setMaxResults(1)
                ->executeQuery()
                ->free();
        } catch (Throwable $Exception) {
            self::markTestSkipped('QUIQQER database is not available: ' . $Exception->getMessage());
        }
    }

    private static function cleanupFixtures(): void
    {
        try {
            $Connection = self::getConnection();
            $uid = $Connection->getDatabasePlatform()->quoteSingleIdentifier('uid');

            $Connection->createQueryBuilder()
                ->delete(self::getTable())
                ->where($uid . ' LIKE :uid')
                ->setParameter('uid', self::TEST_PREFIX . '%')
                ->executeStatement();
        } catch (Throwable) {
            // The availability check reports DB problems. Cleanup should not hide the test result.
        }
    }

    private static function getConnection(): Connection
    {
        return QUI::getDataBaseConnection();
    }

    private static function getTable(): string
    {
        return QUI\Utils\Doctrine::quoteIdentifier(QUI::getDBTableName('watcher'));
    }
}
