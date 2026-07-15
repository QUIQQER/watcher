<?php

/**
 * This file contains QUI\Watcher
 */

namespace QUI;

use DOMElement;
use DOMXPath;
use QUI;
use QUI\Database\Exception;
use QUI\Groups\Group;
use QUI\Utils\Text\XML;

/**
 * Class Watcher
 *
 * @package quiqqer/watcher
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 */
class Watcher
{
    /**
     * This can be changed to true if the Watcher should be globally disabled for a
     * PHP process.
     *
     * @var bool
     */
    public static bool $globalWatcherDisable = false;

    /**
     * list of group ids
     *
     * @var array<int|string, true>|null
     */
    protected static ?array $groups = null;

    /**
     * list of group ids
     *
     * @var array<int|string, true>|null
     */
    protected static ?array $users = null;

    /**
     * list of checked users
     *
     * @var array<int|string, bool>
     */
    protected static array $checked = [];

    /**
     * Add a simple string to the watch-log
     *
     * @param string $message - Message
     * @param string $call - php call, eq: ajax function or event name
     * @param array<array-key, mixed> $callParams - optional, call parameter
     * @throws Exception|QUI\Exception
     */
    public static function addString(string $message = '', string $call = '', array $callParams = []): void
    {
        if (empty($message)) {
            return;
        }

        if (!self::insertCheck()) {
            return;
        }

        QUI::getDataBaseConnection()->insert(QUI\Utils\Doctrine::quoteIdentifier(QUI::getDBTableName('watcher')), [
            'message' => $message,
            QUI\Utils\Doctrine::quoteIdentifier('call') => $call,
            'callParams' => json_encode($callParams),
            'uid' => QUI::getUserBySession()->getUUID(),
            'statusTime' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Add locale data to the watch-log
     *
     * @param string $localeGroup - locale group
     * @param string $localeVar - locale variable
     * @param string $call - php call, eq: ajax function or event name
     * @param array<array-key, mixed> $callParams - optional, call parameter
     * @param array<array-key, mixed> $localeParams - optional, locale parameter
     * @throws QUI\Exception
     */
    public static function add(
        string $localeGroup,
        string $localeVar,
        string $call = '',
        array $callParams = [],
        array $localeParams = []
    ): void {
        if (!self::insertCheck()) {
            return;
        }

        QUI::getDataBaseConnection()->insert(QUI\Utils\Doctrine::quoteIdentifier(QUI::getDBTableName('watcher')), [
            'localeGroup' => $localeGroup,
            'localeVar' => $localeVar,
            'localeParams' => json_encode($localeParams),
            QUI\Utils\Doctrine::quoteIdentifier('call') => $call,
            'callParams' => json_encode($callParams),
            'uid' => QUI::getUserBySession()->getUUID(),
            'statusTime' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Should be logged for the group or user?
     *
     * @return bool
     * @throws Exception|\QUI\Exception
     */
    protected static function insertCheck(): bool
    {
        if (self::$globalWatcherDisable) {
            return false;
        }

        $User = QUI::getUserBySession();
        $uid = $User->getUUID();

        if (
            QUI::getUsers()->isSystemUser($User)
            && !QUI::getPackage('quiqqer/watcher')->getConfig()?->getValue('settings', 'logSystemUser')
        ) {
            return false;
        }

        if (isset(self::$checked[$uid])) {
            return self::$checked[$uid];
        }


        if (!is_array(self::$groups) || !is_array(self::$users)) {
            $usersAndGroups = QUI::getPackage('quiqqer/watcher')
                ->getConfig()
                ?->getValue('settings', 'users_and_groups');

            if (!is_string($usersAndGroups)) {
                return false;
            }

            $ugs = QUI\Utils\UserGroups::parseUsersGroupsString($usersAndGroups);

            foreach ($ugs['groups'] as $_gid) {
                self::$groups[$_gid] = true;
            }

            foreach ($ugs['users'] as $_uid) {
                self::$users[$_uid] = true;
            }

            if (empty($ugs['groups']) && empty($ugs['users'])) {
                self::$groups[QUI\Groups\Manager::EVERYONE_ID] = true;
            }
        }


        $User = QUI::getUserBySession();

        if (isset(self::$users[$User->getUUID()])) {
            self::$checked[$uid] = true;

            return true;
        }

        $groups = $User->getGroups();

        /* @var $Group Group */
        foreach ($groups as $Group) {
            if (isset(self::$groups[$Group->getUUID()])) {
                self::$checked[$uid] = true;

                return true;
            }
        }

        self::$checked[$uid] = false;

        return false;
    }

    /**
     * Return the watcher-log list
     *
     * @param array<string, mixed> $params - database query params (eq: order, limit)
     * @param bool|array<string, mixed> $search - search parameter
     *
     * @return list<array<string, mixed>>
     */
    public static function getList(array $params = [], bool|array $search = false): array
    {
        $QueryBuilder = QUI::getQueryBuilder();
        $table = QUI\Utils\Doctrine::quoteIdentifier(QUI::getDBTableName('watcher'));
        $id = QUI\Utils\Doctrine::quoteIdentifier('id');
        $statusTime = QUI\Utils\Doctrine::quoteIdentifier('statusTime');
        $uid = QUI\Utils\Doctrine::quoteIdentifier('uid');
        $order = is_string($params['order'] ?? null) ? $params['order'] : 'statusTime';
        $limit = $params['limit'] ?? 0;

        if (!is_int($limit) && !is_string($limit) && $limit !== false) {
            $limit = 0;
        }

        $QueryBuilder
            ->select(!empty($params['count']) ? 'COUNT(*) AS count' : '*')
            ->from($table);

        if (is_array($search)) {
            $searchUid = $search['uid'] ?? null;
            $from = $search['from'] ?? null;
            $to = $search['to'] ?? null;

            if (is_int($searchUid) || is_string($searchUid)) {
                $QueryBuilder
                    ->andWhere($QueryBuilder->expr()->eq($uid, ':uid'))
                    ->setParameter('uid', $searchUid);
            }

            if (is_string($from) && $from !== '') {
                $QueryBuilder
                    ->andWhere($QueryBuilder->expr()->gte($statusTime, ':from'))
                    ->setParameter('from', $from);
            }

            if (is_string($to) && $to !== '') {
                $QueryBuilder
                    ->andWhere($QueryBuilder->expr()->lte($statusTime, ':to'))
                    ->setParameter('to', $to);
            }
        }

        switch ($order) {
            case 'id':
                $QueryBuilder->orderBy($id, 'ASC');
                break;

            case 'id DESC':
                $QueryBuilder->orderBy($id, 'DESC');
                break;

            case 'id ASC':
                $QueryBuilder->orderBy($id, 'ASC');
                break;

            case 'uid':
                $QueryBuilder->orderBy($uid, 'ASC')->addOrderBy($id, 'DESC');
                break;

            case 'uid DESC':
                $QueryBuilder->orderBy($uid, 'DESC')->addOrderBy($id, 'DESC');
                break;

            case 'statusTime':
                $QueryBuilder->orderBy($statusTime, 'ASC')->addOrderBy($id, 'DESC');
                break;

            case 'statusTime DESC':
                $QueryBuilder->orderBy($statusTime, 'DESC')->addOrderBy($id, 'DESC');
                break;

            case 'uid ASC':
                $QueryBuilder->orderBy($uid, 'ASC')->addOrderBy($id, 'ASC');
                break;

            case 'statusTime ASC':
                $QueryBuilder->orderBy($statusTime, 'ASC')->addOrderBy($id, 'ASC');
                break;

            default:
                $QueryBuilder->orderBy($statusTime, 'ASC');
        }

        if ($limit !== false) {
            if (!str_contains((string)$limit, ',')) {
                $offset = 0;
                $maximum = max(0, (int)$limit);
            } else {
                $limitParts = explode(',', (string)$limit, 2);

                $offset = max(0, (int)$limitParts[0]);
                $maximum = max(0, (int)$limitParts[1]);
            }

            $QueryBuilder->setFirstResult($offset)->setMaxResults($maximum);
        }

        try {
            return $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return [];
        }
    }

    /**
     * Return the result list for a Grid control
     *
     * @param array<string, mixed> $params - database query params (eq: order, limit)
     * @param bool|array<string, mixed> $search - search parameter
     *
     * @return array<string, mixed>
     */
    public static function getGridList(array $params = [], bool|array $search = false): array
    {
        $Grid = new QUI\Utils\Grid();
        $dbParams = $Grid->parseDBParams($params);
        $sortOn = $params['sortOn'] ?? 'statusTime';
        $sortBy = $params['sortBy'] ?? 'DESC';

        if (!is_string($sortOn)) {
            $sortOn = 'statusTime';
        }

        if (!is_string($sortBy)) {
            $sortBy = 'DESC';
        }

        $page = $params['page'] ?? null;
        $perPage = $params['perPage'] ?? null;

        if (is_numeric($page) && is_numeric($perPage)) {
            $page = max(1, (int)$page);
            $perPage = max(0, (int)$perPage);
            $dbParams['limit'] = (($page - 1) * $perPage) . ',' . $perPage;
        }

        $order = $sortOn . ' ' . strtoupper($sortBy);

        switch ($order) {
            case 'id':
            case 'id DESC':
            case 'id ASC':
            case 'statusTime':
            case 'statusTime DESC':
            case 'statusTime ASC':
                break;

            default:
                $order = 'statusTime DESC';
        }

        $dbParams['order'] = $order;
        $result = self::getList($dbParams, $search);

        foreach ($result as $key => $value) {
            $localeGroup = $value['localeGroup'] ?? null;
            $localeVar = $value['localeVar'] ?? null;
            $localeParamsJson = $value['localeParams'] ?? null;

            if (is_string($localeGroup) && $localeGroup !== '' && is_string($localeVar) && $localeVar !== '') {
                $localeParams = is_string($localeParamsJson) ? json_decode($localeParamsJson, true) : [];

                if (!is_array($localeParams)) {
                    $localeParams = [];
                }

                $result[$key]['message'] = QUI::getLocale()->get(
                    $localeGroup,
                    $localeVar,
                    $localeParams
                );
            }

            $userId = $value['uid'] ?? null;

            if (!is_int($userId) && !is_string($userId)) {
                $result[$key]['username'] = 'unknown';
                continue;
            }

            try {
                $result[$key]['username'] = QUI::getUsers()
                    ->get($userId)
                    ->getUsername();
            } catch (QUI\Exception) {
                $result[$key]['username'] = 'unknown';
            }
        }

        $dbParams['limit'] = false;
        $dbParams['count'] = true;
        $count = self::getList($dbParams, $search);
        $countValue = $count[0]['count'] ?? 0;

        return $Grid->parseResult($result, is_numeric($countValue) ? (int)$countValue : 0);
    }

    /**
     * Clear the Watcher-Log
     *
     * @param string $date - date
     *
     * @throws QUI\Exception
     */
    public static function clear(string $date): void
    {
        QUI\Permissions\Permission::checkPermission('quiqqer.watcher.clearlog');

        $date = strtotime($date);

        if (!$date) {
            throw new QUI\Exception([
                'quiqqer/watcher',
                'exception.quiqqer.watcher.clearlog.error.wrongDateFormat'
            ]);
        }


        $QueryBuilder = QUI::getQueryBuilder();
        $statusTime = QUI\Utils\Doctrine::quoteIdentifier('statusTime');

        $QueryBuilder
            ->delete(QUI\Utils\Doctrine::quoteIdentifier(QUI::getDBTableName('watcher')))
            ->where($QueryBuilder->expr()->lte($statusTime, ':statusTime'))
            ->setParameter('statusTime', date('Y-m-d H:i:s', $date))
            ->executeStatement();
    }

    /**
     * After all packages have been set up, add all their watches to the watch list.
     *
     * @throws Exception
     */
    public static function onSetupAllEnd(): void
    {
        foreach (QUI::getPackageManager()->getInstalled() as $plugin) {
            $packageName = $plugin['name'];
            $watcherXml = OPT_DIR . $packageName . '/watch.xml';

            if (!file_exists($watcherXml)) {
                continue;
            }

            $Dom = XML::getDomFromXml($watcherXml);
            $Path = new DOMXPath($Dom);

            $watchList = $Path->query("//quiqqer/watch");
            $table = QUI\Utils\Doctrine::quoteIdentifier(QUI::getDBTableName('watcherEvents'));

            if ($watchList === false) {
                continue;
            }

            // clear watches of package
            QUI::getDataBaseConnection()->delete($table, [
                'package' => $packageName
            ]);

            // insert watches
            foreach ($watchList as $Watch) {
                if (!$Watch instanceof DOMElement) {
                    continue;
                }

                $ajax = $Watch->getAttribute('ajax');
                $exec = $Watch->getAttribute('exec');
                $event = $Watch->getAttribute('event');

                if (!$exec || !is_callable($exec)) {
                    continue;
                }

                if ($ajax) {
                    QUI::getDataBaseConnection()->insert($table, [
                        'package' => $packageName,
                        'ajax' => $ajax,
                        'exec' => $exec
                    ]);

                    continue;
                }

                QUI::getDataBaseConnection()->insert($table, [
                    'package' => $packageName,
                    'event' => $event,
                    'exec' => $exec
                ]);
            }
        }

        QUI\Watcher\EventsReact::clearWatchEventsCache();
    }
}
