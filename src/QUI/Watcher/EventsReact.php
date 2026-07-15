<?php

/**
 * This file contains QUI\Watcher\EventsReact
 */

namespace QUI\Watcher;

use QUI;
use QUI\Cache\Manager as CacheManager;
use QUI\Exception;
use QUI\System\Console\Tools\MigrationV2;

use function date;
use function is_array;
use function is_numeric;
use function is_string;
use function json_encode;

/**
 * Class EventsReact
 *
 * @package quiqqer/watcher
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 */
class EventsReact
{
    /**
     * @var array<string, array<string, list<array<string, mixed>>>>|null
     */
    protected static ?array $watcherEvents = null;

    /**
     *
     * @param string $event
     * @param array<array-key, mixed> $arguments
     * @throws Exception
     */
    public static function trigger(string $event, array $arguments = []): void
    {
        // admin events
        if (
            $event == 'headerLoaded'
            || $event == 'adminLoad'
            || $event == 'adminLoadFooter'
        ) {
            return;
        }

        // users events
        if ($event == 'userLoad') {
            return;
        }

        // site events
        if (
            $event == 'siteInit'
            || $event == 'siteLoad'
            || $event == 'siteCheckActivate'
            || $event == 'siteCheckDeactivate'
        ) {
            return;
        }

        // smarty events
        if ($event == 'smartyInit') {
            return;
        }

        if (!QUI::getUserBySession()->canUseBackend()) {
            return;
        }

        if (!QUI::getPackage('quiqqer/watcher')->getConfig()?->getValue('settings', 'logEvents')) {
            return;
        }

        switch ($event) {
            case 'userLogin':
            case 'userSave':
            case 'userSetPassword':
            case 'userDisable':
            case 'userActivate':
            case 'userDeactivate':
            case 'userDelete':
            case 'projectConfigSave':
            case 'createProject':
            case 'packageSetup':
            case 'packageInstall':
            case 'packageUninstall':
            case 'siteActivate':
            case 'siteDeactivate':
            case 'siteSave':
            case 'siteDelete':
            case 'siteDestroy':
            case 'siteCreateChild':
            case 'siteMove':
            case 'mediaActivate':
            case 'mediaDeactivate':
            case 'mediaSaveBegin':
            case 'mediaSave':
            case 'mediaDelete':
            case 'mediaDeleteBegin':
            case 'mediaDestroy':
            case 'mediaRename':
                QUI\Watcher::add(
                    'quiqqer/watcher',
                    'watcher.message.' . $event,
                    $event,
                    $arguments,
                    $arguments
                );

                return;
        }


        $events = self::getWatchEvents();

        if (!isset($events['event'][$event])) {
            return;
        }

        $data = $events['event'][$event];

        foreach ($data as $entry) {
            $exec = $entry['exec'];

            if (is_callable($exec)) {
                try {
                    $str = call_user_func_array($exec, [
                        $event,
                        $arguments
                    ]);

                    QUI\Watcher::addString($str, $event, $arguments);
                } catch (\Exception $Exception) {
                    QUI\System\Log::writeException($Exception);
                }
            }
        }
    }

    /**
     * event on ajax call - React at ajax events
     *
     * @param string|array<array-key, mixed> $function
     * @param string|array<array-key, mixed> $result
     * @param array<array-key, mixed> $params
     * @throws Exception
     */
    public static function onAjaxCall(string|array $function, string|array $result, array $params): void
    {
        if (!QUI::getPackage('quiqqer/watcher')->getConfig()?->getValue('settings', 'logAjax')) {
            return;
        }

        if (is_array($function)) {
            foreach ($function as $func) {
                if (is_string($func)) {
                    self::onAjaxCall($func, $result, $params);
                }
            }

            return;
        }

        $events = self::getWatchEvents();

        if (!isset($events['ajax'][$function])) {
            return;
        }

        $data = $events['ajax'][$function];

        foreach ($data as $entry) {
            $exec = $entry['exec'];

            if (is_callable($exec)) {
                try {
                    $str = call_user_func_array($exec, [
                        $function,
                        $params,
                        $result
                    ]);

                    QUI\Watcher::addString($str, $function, $params);
                } catch (\Exception $Exception) {
                    QUI\System\Log::writeException($Exception);
                }
            }
        }
    }

    /**
     * Register watch events
     */
    public static function onHeaderLoaded(): void
    {
        if (!QUI::getPackage('quiqqer/watcher')->getConfig()?->getValue('settings', 'logEvents')) {
            return;
        }

        $events = self::getWatchEvents();

        if (empty($events['event'])) {
            return;
        }

        $Events = QUI::getEvents();

        foreach ($events['event'] as $event => $data) {
            foreach ($data as $eventData) {
                $Events->addEvent($event, function () use ($eventData) {
                    $exec = $eventData['exec'];

                    if (!is_callable($exec)) {
                        return;
                    }

                    try {
                        $str = call_user_func_array($exec, [
                            $eventData['event'],
                            func_get_args()
                        ]);

                        QUI\Watcher::addString($str, $eventData['event']);
                    } catch (\Exception $Exception) {
                        QUI\System\Log::writeException($Exception);
                    }
                });
            }
        }
    }

    /**
     * event onUserSave
     *
     * @param QUI\Interfaces\Users\User $User
     * @throws Exception
     */
    public static function onUserSave(QUI\Interfaces\Users\User $User): void
    {
        self::trigger('userSave', [
            'uid' => $User->getUUID()
        ]);
    }

    /**
     * event onUserSetPassword
     *
     * @param QUI\Interfaces\Users\User $User
     * @throws Exception
     */
    public static function onUserSetPassword(QUI\Interfaces\Users\User $User): void
    {
        self::trigger('userSetPassword', [
            'uid' => $User->getUUID()
        ]);
    }

    /**
     * event onUserDisable
     *
     * @param QUI\Interfaces\Users\User $User
     * @throws Exception
     */
    public static function onUserDisable(QUI\Interfaces\Users\User $User): void
    {
        self::trigger('userDisable', [
            'uid' => $User->getUUID()
        ]);
    }

    /**
     * event onUserActivate
     *
     * @param QUI\Users\User $User
     * @throws Exception
     */
    public static function onUserActivate(QUI\Interfaces\Users\User $User): void
    {
        self::trigger('userActivate', [
            'uid' => $User->getUUID()
        ]);
    }

    /**
     * event onUserDeactivate
     *
     * @param QUI\Users\User $User
     * @throws Exception
     */
    public static function onUserDeactivate(QUI\Interfaces\Users\User $User): void
    {
        self::trigger('userDeactivate', [
            'uid' => $User->getUUID()
        ]);
    }

    /**
     * event onUserDelete
     *
     * @param QUI\Users\User $User
     * @throws Exception
     */
    public static function onUserDelete(QUI\Interfaces\Users\User $User): void
    {
        self::trigger('userDelete', [
            'uid' => $User->getUUID()
        ]);
    }

    /**
     * event onProjectConfigSave
     *
     * @param string $project
     * @param array<string, mixed> $config
     * @throws Exception
     */
    public static function onProjectConfigSave(string $project, array $config): void
    {
        self::trigger('projectConfigSave', [
            'project' => $project,
            'config' => $config
        ]);
    }

    /**
     * event onCreateProject
     *
     * @param QUI\Projects\Project $Project
     * @throws Exception
     */
    public static function onCreateProject(QUI\Projects\Project $Project): void
    {
        self::trigger('createProject', [
            'project' => $Project->getName(),
            'lang' => $Project->getLang()
        ]);
    }

    /**
     * event onPackageSetup
     *
     * @param QUI\Package\Package $Package
     * @throws Exception
     */
    public static function onPackageSetup(QUI\Package\Package $Package): void
    {
        self::trigger('packageSetup', [
            'package' => $Package->getName()
        ]);
    }

    /**
     * event onPackageInstall
     *
     * @param QUI\Package\Package $Package
     * @throws Exception
     */
    public static function onPackageInstall(QUI\Package\Package $Package): void
    {
        self::trigger('packageInstall', [
            'package' => $Package->getName()
        ]);
    }

    /**
     * event onPackageUninstall
     *
     * @param string $packageName
     * @throws Exception
     */
    public static function onPackageUninstall(string $packageName): void
    {
        self::trigger('packageUninstall', [
            'package' => $packageName
        ]);
    }

    /**
     * event onSiteActivate
     *
     * @param QUI\Interfaces\Projects\Site $Site
     * @throws Exception
     */
    public static function onSiteActivate(QUI\Interfaces\Projects\Site $Site): void
    {
        self::trigger('siteActivate', [
            'id' => $Site->getId(),
            'project' => $Site->getProject()->getName(),
            'lang' => $Site->getProject()->getLang()
        ]);
    }

    /**
     * event onSiteDeactivate
     *
     * @param QUI\Projects\Site $Site
     * @throws Exception
     */
    public static function onSiteDeactivate(QUI\Interfaces\Projects\Site $Site): void
    {
        self::trigger('siteDeactivate', [
            'id' => $Site->getId(),
            'project' => $Site->getProject()->getName(),
            'lang' => $Site->getProject()->getLang()
        ]);
    }

    /**
     * event onSiteSave
     *
     * @param QUI\Projects\Site $Site
     * @throws Exception
     */
    public static function onSiteSave(QUI\Interfaces\Projects\Site $Site): void
    {
        self::trigger('siteSave', [
            'id' => $Site->getId(),
            'project' => $Site->getProject()->getName(),
            'lang' => $Site->getProject()->getLang()
        ]);
    }

    /**
     * event onSiteDelete
     *
     * @param integer $siteId
     * @param QUI\Projects\Project $Project
     * @throws Exception
     */
    public static function onSiteDelete(int $siteId, QUI\Projects\Project $Project): void
    {
        self::trigger('siteDelete', [
            'id' => $siteId,
            'project' => $Project->getName(),
            'lang' => $Project->getLang()
        ]);
    }

    /**
     * event onSiteDestroy
     *
     * @param QUI\Projects\Site $Site
     * @throws Exception
     */
    public static function onSiteDestroy(QUI\Interfaces\Projects\Site $Site): void
    {
        self::trigger('siteDestroy', [
            'id' => $Site->getId(),
            'project' => $Site->getProject()->getName(),
            'lang' => $Site->getProject()->getLang()
        ]);
    }

    /**
     * event onSiteCreateChild
     *
     * @param integer $newId
     * @param QUI\Projects\Site $Parent
     * @throws Exception
     */
    public static function onSiteCreateChild(int $newId, QUI\Projects\Site $Parent): void
    {
        self::trigger('siteCreateChild', [
            'newid' => $newId,
            'id' => $Parent->getId(),
            'project' => $Parent->getProject()->getName(),
            'lang' => $Parent->getProject()->getLang()
        ]);
    }

    /**
     * event onSiteMove
     *
     * @param QUI\Projects\Site $Site
     * @param integer $parentId
     * @throws Exception
     */
    public static function onSiteMove(QUI\Interfaces\Projects\Site $Site, int $parentId): void
    {
        self::trigger('siteMove', [
            'parentId' => $parentId,
            'id' => $Site->getId(),
            'project' => $Site->getProject()->getName(),
            'lang' => $Site->getProject()->getLang()
        ]);
    }

    /**
     * event onMediaActivate
     *
     * @param QUI\Projects\Media\Item $Item
     * @throws Exception
     */
    public static function onMediaActivate(QUI\Projects\Media\Item $Item): void
    {
        self::trigger('mediaActivate', [
            'id' => $Item->getId(),
            'project' => $Item->getProject()->getName(),
            'lang' => $Item->getProject()->getLang()
        ]);
    }

    /**
     * event onMediaDeactivate
     *
     * @param QUI\Projects\Media\Item $Item
     * @throws Exception
     */
    public static function onMediaDeactivate(QUI\Projects\Media\Item $Item): void
    {
        self::trigger('mediaDeactivate', [
            'id' => $Item->getId(),
            'project' => $Item->getProject()->getName(),
            'lang' => $Item->getProject()->getLang()
        ]);
    }

    /**
     * event onMediaSaveBegin
     *
     * @param QUI\Projects\Media\Item $Item
     * @throws Exception
     */
    public static function onMediaSaveBegin(QUI\Projects\Media\Item $Item): void
    {
        self::trigger('mediaSaveBegin', [
            'id' => $Item->getId(),
            'project' => $Item->getProject()->getName(),
            'lang' => $Item->getProject()->getLang()
        ]);
    }

    /**
     * event onMediaSave
     *
     * @param QUI\Projects\Media\Item $Item
     * @throws Exception
     */
    public static function onMediaSave(QUI\Projects\Media\Item $Item): void
    {
        self::trigger('mediaSave', [
            'id' => $Item->getId(),
            'project' => $Item->getProject()->getName(),
            'lang' => $Item->getProject()->getLang()
        ]);
    }

    /**
     * event onMediaDelete
     *
     * @param QUI\Projects\Media\Item $Item
     * @throws Exception
     */
    public static function onMediaDelete(QUI\Projects\Media\Item $Item): void
    {
        self::trigger('mediaDelete', [
            'id' => $Item->getId(),
            'project' => $Item->getProject()->getName(),
            'lang' => $Item->getProject()->getLang()
        ]);
    }

    /**
     * event onMediaDeleteBegin
     *
     * @param QUI\Projects\Media\Item $Item
     * @throws Exception
     */
    public static function onMediaDeleteBegin(QUI\Projects\Media\Item $Item): void
    {
        self::trigger('mediaDeleteBegin', [
            'id' => $Item->getId(),
            'project' => $Item->getProject()->getName(),
            'lang' => $Item->getProject()->getLang()
        ]);
    }

    /**
     * event onMediaDestroy
     *
     * @param QUI\Projects\Media\Item $Item
     * @throws Exception
     */
    public static function onMediaDestroy(QUI\Projects\Media\Item $Item): void
    {
        self::trigger('mediaDestroy', [
            'id' => $Item->getId(),
            'project' => $Item->getProject()->getName(),
            'lang' => $Item->getProject()->getLang()
        ]);
    }

    /**
     * event onMediaRename
     *
     * @param QUI\Projects\Media\Item $Item
     * @throws Exception
     */
    public static function onMediaRename(QUI\Projects\Media\Item $Item): void
    {
        self::trigger('mediaRename', [
            'id' => $Item->getId(),
            'project' => $Item->getProject()->getName(),
            'lang' => $Item->getProject()->getLang()
        ]);
    }

    /**
     * Return the global watch events -> from watch.xml's
     *
     * @return array<string, array<string, list<array<string, mixed>>>>|null
     */
    protected static function getWatchEvents(): ?array
    {
        $cacheName = 'quiqqer/watcher/events';

        try {
            return CacheManager::get($cacheName);
        } catch (\Exception) {
            // re-fetch from database
        }

        if (!self::$watcherEvents) {
            try {
                $result = QUI::getQueryBuilder()
                    ->select('*')
                    ->from(QUI\Utils\Doctrine::quoteIdentifier(QUI::getDBTableName('watcherEvents')))
                    ->executeQuery()
                    ->fetchAllAssociative();
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
                $result = [];
            }

            foreach ($result as $entry) {
                $ajax = $entry['ajax'] ?? null;
                $event = $entry['event'] ?? null;

                if (is_string($ajax) && $ajax !== '') {
                    self::$watcherEvents['ajax'][$ajax][] = $entry;
                }

                if (is_string($event) && $event !== '') {
                    self::$watcherEvents['event'][$event][] = $entry;
                }
            }
        }

        CacheManager::set($cacheName, self::$watcherEvents);

        return self::$watcherEvents;
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public static function onQuiqqerMigrationV2(MigrationV2 $Console): void
    {
        $Console->writeLn('- Migrate watcher');

        $tableName = QUI::getDBTableName('watcher');
        $SchemaManager = QUI::getSchemaManager();

        if (!$SchemaManager->tablesExist([$tableName])) {
            return;
        }

        $Table = $SchemaManager->introspectTable($tableName);
        $UidColumn = new \Doctrine\DBAL\Schema\Column(
            'uid',
            \Doctrine\DBAL\Types\Type::getType('string'),
            ['length' => 50, 'notnull' => true]
        );

        if (!$Table->hasColumn('uid')) {
            $SchemaManager->alterTable(new \Doctrine\DBAL\Schema\TableDiff(
                $Table,
                addedColumns: [$UidColumn]
            ));
        } else {
            $CurrentUidColumn = $Table->getColumn('uid');

            if (
                !$CurrentUidColumn->getType() instanceof \Doctrine\DBAL\Types\StringType
                || $CurrentUidColumn->getLength() !== 50
                || !$CurrentUidColumn->getNotnull()
            ) {
                $SchemaManager->alterTable(new \Doctrine\DBAL\Schema\TableDiff(
                    $Table,
                    changedColumns: [
                        'uid' => new \Doctrine\DBAL\Schema\ColumnDiff(
                            $CurrentUidColumn,
                            $UidColumn
                        )
                    ]
                ));
            }
        }

        $table = QUI\Utils\Doctrine::quoteIdentifier($tableName);
        $uids = QUI::getQueryBuilder()
            ->select('uid')
            ->distinct()
            ->from($table)
            ->executeQuery()
            ->fetchFirstColumn();

        foreach ($uids as $uid) {
            if ((!is_int($uid) && !is_string($uid)) || !is_numeric($uid)) {
                continue;
            }

            try {
                QUI::getDataBaseConnection()->update(
                    $table,
                    ['uid' => QUI::getUsers()->get($uid)->getUUID()],
                    ['uid' => $uid]
                );
            } catch (QUI\Exception) {
            }
        }
    }
}
