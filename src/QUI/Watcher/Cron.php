<?php

namespace QUI\Watcher;

use DateTimeImmutable;
use QUI\Exception;
use QUI\Watcher;

/**
 * Class Cron
 *
 * Cronjob handler for quiqqer/watcher
 */
class Cron
{
    /**
     * Delete all watcher entries older than X days
     *
     * @param array{days?: int|string} $params
     * @throws Exception
     */
    public static function clearWatcherEntries(array $params): void
    {
        $days = $params['days'] ?? 3;

        if (!is_numeric($days)) {
            $days = 3;
        }

        $days = max(0, (int)$days);
        $DeleteOlderThanDate = new DateTimeImmutable('-' . $days . ' days');
        Watcher::clear($DeleteOlderThanDate->format('Y-m-d'));
    }
}
