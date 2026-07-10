<?php

/**
 * This file contains QUI\Watcher
 */

namespace QUI;

use DOMElement;
use DOMXPath;
use PDO;
use PDOException;
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

        QUI::getDataBase()->insert(QUI::getDBTableName('watcher'), [
            'message' => $message,
            'call' => $call,
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

        QUI::getDataBase()->insert(QUI::getDBTableName('watcher'), [
            'localeGroup' => $localeGroup,
            'localeVar' => $localeVar,
            'localeParams' => json_encode($localeParams),
            'call' => $call,
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

        // TODO: turn this into a setting (see quiqqer/package-watcher#8)
        if (QUI::getUsers()->isSystemUser($User)) {
            return false;
        }

        if (isset(self::$checked[$uid])) {
            return self::$checked[$uid];
        }


        if (!is_array(self::$groups) || !is_array(self::$users)) {
            $Config = QUI::getPackage('quiqqer/watcher')->getConfig();

            if ($Config === null) {
                return false;
            }

            $usersAndGroups = $Config->getValue('settings', 'users_and_groups');

            if (!is_string($usersAndGroups)) {
                $usersAndGroups = '';
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
     * @param array $params - database query params (eq: order, limit)
     * @param bool|array $search - search parameter
     *
     * @return array
     */
    public static function getList(array $params = [], bool|array $search = false): array
    {
        $PDO = QUI::getDataBase()->getPDO();

        if (!isset($params['order'])) {
            $params['order'] = 'statusTime';
        }

        if (!isset($params['limit'])) {
            $params['limit'] = 0;
        }

        $query = 'SELECT * ';

        if (isset($params['count'])) {
            $query = 'SELECT COUNT(*) as count ';
        }

        $query .= ' FROM ' . QUI::getDBTableName('watcher');


        // search
        if (is_array($search)) {
            $searchQuery = [];

            if (!empty($search['uid'])) {
                $searchQuery[] = 'uid = :uid';
            }

            if (!empty($search['from'])) {
                $searchQuery[] = 'statusTime >= :from';
            }

            if (!empty($search['to'])) {
                $searchQuery[] = 'statusTime <= :to';
            }

            $query .= ' WHERE ' . implode(' AND ', $searchQuery);
        }


        // order
        switch ($params['order']) {
            case 'id':
            case 'id DESC':
            case 'id ASC':
                $query .= ' ORDER BY ' . $params['order'];
                break;

            case 'uid':
            case 'uid DESC':
            case 'statusTime':
            case 'statusTime DESC':
                $query .= ' ORDER BY ' . $params['order'] . ', id DESC';
                break;

            case 'uid ASC':
            case 'statusTime ASC':
                $query .= ' ORDER BY ' . $params['order'] . ', id ASC';
                break;

            default:
                $query .= ' ORDER BY statusTime ASC';
        }

        // limit
        if ($params['limit'] !== false) {
            $query .= ' LIMIT :limit1, :limit2';
        }


        $Statement = $PDO->prepare($query);

        // prepared statements
        if ($params['limit'] !== false) {
            if (!str_contains($params['limit'], ',')) {
                $limit1 = 0;
                $limit2 = (int)$params['limit'];
            } else {
                $params['limit'] = explode(',', $params['limit']);

                $limit1 = (int)$params['limit'][0];
                $limit2 = (int)$params['limit'][1];
            }

            $Statement->bindValue(':limit1', $limit1, PDO::PARAM_INT);
            $Statement->bindValue(':limit2', $limit2, PDO::PARAM_INT);
        }

        if (is_array($search)) {
            if (!empty($search['uid'])) {
                $Statement->bindValue(':uid', $search['uid']);
            }

            if (!empty($search['from'])) {
                $Statement->bindValue(
                    ':from',
                    $search['from']
                );
            }

            if (!empty($search['to'])) {
                $Statement->bindValue(':to', $search['to']);
            }
        }


        try {
            $Statement->execute();
        } catch (PDOException $Exception) {
            QUI\System\Log::writeException($Exception);

            return [];
        }

        return $Statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return the result list for a Grid control
     *
     * @param array $params - database query params (eq: order, limit)
     * @param bool|array $search - search parameter
     *
     * @return array
     */
    public static function getGridList(array $params = [], bool|array $search = false): array
    {
        $Grid = new QUI\Utils\Grid();
        $dbParams = $Grid->parseDBParams($params);

        if (!isset($params['sortOn'])) {
            $params['sortOn'] = 'statusTime';
        }

        if (!isset($params['sortBy'])) {
            $params['sortBy'] = 'DESC';
        }

        if (isset($params['perPage']) && isset($params['page'])) {
            $params['limit'] = (($params['page'] - 1) * $params['perPage']) . ',' . $params['perPage'];
        }

        $order = $params['sortOn'] . ' ' . $params['sortBy'];

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
            if (!empty($value['localeGroup']) && !empty($value['localeVar'])) {
                $localeParams = json_decode($value['localeParams'], true);

                $result[$key]['message'] = QUI::getLocale()->get(
                    $value['localeGroup'],
                    $value['localeVar'],
                    $localeParams
                );
            }

            try {
                $result[$key]['username'] = QUI::getUsers()
                    ->get($value['uid'])
                    ->getUsername();
            } catch (QUI\Exception) {
                $result[$key]['username'] = 'unknown';
            }
        }

        $dbParams['limit'] = false;
        $dbParams['count'] = true;
        $count = self::getList($dbParams, $search);

        return $Grid->parseResult($result, $count[0]['count']);
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


        QUI::getDataBase()->delete(QUI::getDBTableName('watcher'), [
            'statusTime' => [
                'type' => '<=',
                'value' => date('Y-m-d H:i:s', $date)
            ]
        ]);
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
            $watcherXml = OPT_DIR . $packageName . '/products.xml';

            if (!file_exists($watcherXml)) {
                return;
            }

            $Dom = XML::getDomFromXml($watcherXml);
            $Path = new DOMXPath($Dom);

            $watchList = $Path->query("//quiqqer/watch");
            $table = QUI::getDBTableName('watcherEvents');

            if ($watchList === false) {
                continue;
            }

            // clear watches of package
            QUI::getDataBase()->delete($table, [
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
                    QUI::getDataBase()->insert($table, [
                        'package' => $packageName,
                        'ajax' => $ajax,
                        'exec' => $exec
                    ]);

                    continue;
                }

                QUI::getDataBase()->insert($table, [
                    'package' => $packageName,
                    'event' => $event,
                    'exec' => $exec
                ]);
            }
        }
    }
}
