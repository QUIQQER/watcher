<?php

/**
 * This file contains package_quiqqer_watch_ajax_list
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_watcher_ajax_clear',
    function (string $date): void {
        QUI\Watcher::clear($date);
    },
    ['date'],
    ['Permission::checkAdminUser', 'quiqqer.watcher.clearlog']
);
