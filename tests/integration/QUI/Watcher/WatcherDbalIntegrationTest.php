<?php

namespace QUI\Watcher\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\StringType;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Interfaces\Users\User as UserInterface;
use QUI\System\Console\Tools\MigrationV2;
use QUI\Watcher;
use QUI\Watcher\EventsReact;
use ReflectionProperty;
use Throwable;

class WatcherDbalIntegrationTest extends TestCase
{
    private const TEST_PREFIX = 'phpunit-watcher-dbal-';

    public static function setUpBeforeClass(): void
    {
        self::skipIfDatabaseIsUnavailable();
        EventsReact::onQuiqqerMigrationV2(new MigrationV2());
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

    public function testGridListSortsUuidValuesAsStrings(): void
    {
        $firstUid = self::TEST_PREFIX . 'a-' . uniqid();
        $secondUid = self::TEST_PREFIX . 'b-' . uniqid();
        $this->insertFixture($secondUid, 'second uid', '2026-01-01 10:00:00');
        $this->insertFixture($firstUid, 'first uid', '2026-01-01 11:00:00');

        $result = Watcher::getGridList(
            [
                'sortOn' => 'uid',
                'sortBy' => 'ASC'
            ]
        );

        $uids = array_column($result['data'], 'uid');
        $firstPosition = array_search($firstUid, $uids, true);
        $secondPosition = array_search($secondUid, $uids, true);

        $this->assertIsInt($firstPosition);
        $this->assertIsInt($secondPosition);
        $this->assertLessThan($secondPosition, $firstPosition);
    }

    public function testSystemUserLoggingCanBeEnabledExplicitly(): void
    {
        $Config = QUI::getPackage('quiqqer/watcher')->getConfig();

        if ($Config === null) {
            $this->markTestSkipped('Watcher configuration is not available.');
        }

        $hadLogSystemUser = $Config->existValue('settings', 'logSystemUser');
        $originalLogSystemUser = $Config->getValue('settings', 'logSystemUser');
        $hadUsersAndGroups = $Config->existValue('settings', 'users_and_groups');
        $originalUsersAndGroups = $Config->getValue('settings', 'users_and_groups');
        $PreviousUser = self::replaceSessionUser(QUI::getUsers()->getSystemUser());
        $message = self::TEST_PREFIX . uniqid();

        try {
            $Config->setValue('settings', 'users_and_groups', '');
            $Config->del('settings', 'logSystemUser');
            self::resetWatcherState();

            Watcher::addString($message, 'phpunit');
            $this->assertSame(0, self::countMessages($message));

            $Config->setValue('settings', 'logSystemUser', 0);
            self::resetWatcherState();

            Watcher::addString($message, 'phpunit');
            $this->assertSame(0, self::countMessages($message));

            $Config->setValue('settings', 'logSystemUser', 1);
            self::resetWatcherState();

            Watcher::addString($message, 'phpunit');
            $this->assertSame(1, self::countMessages($message));
        } finally {
            self::restoreConfigValue($Config, 'logSystemUser', $hadLogSystemUser, $originalLogSystemUser);
            self::restoreConfigValue($Config, 'users_and_groups', $hadUsersAndGroups, $originalUsersAndGroups);
            self::replaceSessionUser($PreviousUser);
            self::resetWatcherState();
        }
    }

    public function testMigrationConvertsLegacyUserIdsToUuids(): void
    {
        $LegacyUser = null;

        foreach (QUI::getUsers()->getUserIds() as $userData) {
            $userId = $userData['id'] ?? null;
            $userUuid = $userData['uuid'] ?? null;

            if (!is_numeric($userId) || !is_string($userUuid) || $userUuid === '' || is_numeric($userUuid)) {
                continue;
            }

            $LegacyUser = QUI::getUsers()->get((string)$userId);
            break;
        }

        if ($LegacyUser === null) {
            $this->markTestSkipped('No user with a legacy ID and UUID is available.');
        }

        $legacyUserId = $LegacyUser->getId();

        if ($legacyUserId === false) {
            $this->markTestSkipped('The migration user has no legacy ID.');
        }

        $message = self::TEST_PREFIX . uniqid();
        $this->insertFixture((string)$legacyUserId, $message, '2026-01-01 10:00:00');

        EventsReact::onQuiqqerMigrationV2(new MigrationV2());

        $Connection = self::getConnection();
        $messageColumn = $Connection->getDatabasePlatform()->quoteSingleIdentifier('message');
        $uid = $Connection->createQueryBuilder()
            ->select('uid')
            ->from(self::getTable())
            ->where($messageColumn . ' = :message')
            ->setParameter('message', $message)
            ->executeQuery()
            ->fetchOne();

        $this->assertSame($LegacyUser->getUUID(), $uid);

        $UidColumn = QUI::getSchemaManager()
            ->introspectTable(QUI::getDBTableName('watcher'))
            ->getColumn('uid');

        $this->assertInstanceOf(StringType::class, $UidColumn->getType());
        $this->assertSame(50, $UidColumn->getLength());
        $this->assertTrue($UidColumn->getNotnull());
    }

    public function testSetupRegistersPackageWatchFiles(): void
    {
        Watcher::onSetupAllEnd();

        $QueryBuilder = self::getConnection()->createQueryBuilder();
        $events = $QueryBuilder
            ->select('event')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(QUI::getDBTableName('watcherEvents')))
            ->where($QueryBuilder->expr()->eq('package', ':package'))
            ->setParameter('package', 'quiqqer/core')
            ->executeQuery()
            ->fetchFirstColumn();

        $this->assertContains('onUserLoginError', $events);
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
            $message = $Connection->getDatabasePlatform()->quoteSingleIdentifier('message');

            $Connection->createQueryBuilder()
                ->delete(self::getTable())
                ->where($uid . ' LIKE :uid')
                ->orWhere($message . ' LIKE :message')
                ->setParameter('uid', self::TEST_PREFIX . '%')
                ->setParameter('message', self::TEST_PREFIX . '%')
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

    private static function countMessages(string $message): int
    {
        $QueryBuilder = self::getConnection()->createQueryBuilder();
        $count = $QueryBuilder
            ->select('COUNT(*)')
            ->from(self::getTable())
            ->where($QueryBuilder->expr()->eq('message', ':message'))
            ->setParameter('message', $message)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int)$count : 0;
    }

    private static function replaceSessionUser(UserInterface $User): UserInterface
    {
        $Users = QUI::getUsers();
        $Property = new ReflectionProperty($Users, 'Session');
        $Property->setAccessible(true);

        $PreviousUser = $Property->getValue($Users);
        $Property->setValue($Users, $User);

        return $PreviousUser instanceof UserInterface ? $PreviousUser : QUI::getUsers()->getNobody();
    }

    private static function resetWatcherState(): void
    {
        foreach (['groups' => null, 'users' => null, 'checked' => []] as $property => $value) {
            $Property = new ReflectionProperty(Watcher::class, $property);
            $Property->setValue(null, $value);
        }
    }

    private static function restoreConfigValue(
        QUI\Config $Config,
        string $key,
        bool $existed,
        mixed $value
    ): void {
        if (!$existed) {
            $Config->del('settings', $key);
            return;
        }

        if (is_bool($value)) {
            $Config->setValue('settings', $key, (int)$value);
            return;
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            $Config->setValue('settings', $key, $value);
        }
    }
}
