<?php

/**
 * This file contains package_quiqqer_watch_ajax_list
 */

/**
 * @param string $date
 *
 * @return array
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_watcher_ajax_clear',
    function ($date) {
        QUI\Watcher::clear($date);
    },
    ['date'],
    ['Permission::checkAdminUser', 'quiqqer.watcher.clearlog']
);
