<?php

/**
 * -------------------------------------------------------------------------
 * Unseen plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Unseen plugin team.
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 *
 * Returns the data used to build the header bell dropdown.
 */

use GlpiPlugin\Unseen\ReadStatus;

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

header('Content-Type: application/json; charset=UTF-8');

if (!Session::getLoginUserID()) {
    echo json_encode(['count' => 0, 'items' => []]);
    exit;
}

$max_display = 15;

$all   = ReadStatus::getUnseenTickets(500);
$count = count($all);

$items = [];
foreach (array_slice($all, 0, $max_display) as $t) {
    $name = trim((string) $t['name']);
    if ($name === '') {
        $name = '#' . $t['id'];
    }

    $items[] = [
        'id'    => $t['id'],
        'name'  => $name,
        'url'   => Ticket::getFormURLWithID($t['id']),
        'date'  => $t['date'] ? Html::convDateTime($t['date']) : '',
    ];
}

echo json_encode([
    'count'  => $count,
    'items'  => $items,
    'labels' => [
        'title'    => __('Unseen messages', 'unseen'),
        'empty'    => __('No unseen messages', 'unseen'),
        'aria'     => __('Unseen messages', 'unseen'),
        'and_more' => __('and %d more', 'unseen'),
        'mark_all' => __('Mark all as read', 'unseen'),
    ],
]);
