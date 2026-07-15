<?php

/**
 * This file contains package_quiqqer_watch_ajax_list
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_watcher_ajax_list',
    function (mixed $params, mixed $search): array {
        $params = is_string($params) ? json_decode($params, true) : [];

        if (!is_array($params)) {
            $params = [];
        }

        if (is_string($search) && $search !== '') {
            $search = json_decode($search, true);
        }

        if (!is_array($search)) {
            $search = false;
        }

        return QUI\Watcher::getGridList($params, $search);
    },
    ['params', 'search'],
    ['Permission::checkAdminUser', 'quiqqer.watcher.readlog']
);
