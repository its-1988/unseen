<?php

/**
 * -------------------------------------------------------------------------
 * Unseen plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Unseen plugin team.
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 *
 * Returns which of the given ticket ids have unseen activity, so lists can be
 * highlighted client-side.
 */

use GlpiPlugin\Unseen\ReadStatus;

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

header('Content-Type: application/json; charset=UTF-8');

if (!Session::getLoginUserID()) {
    echo json_encode(['unseen' => []]);
    exit;
}

$raw = $_GET['ids'] ?? $_POST['ids'] ?? [];
if (!is_array($raw)) {
    $raw = explode(',', (string) $raw);
}
$ids = array_values(array_filter(array_map('intval', $raw), static fn($v) => $v > 0));

// Bound the payload to avoid abuse.
$ids = array_slice(array_unique($ids), 0, 500);

$status = ReadStatus::getUnseenStatusForIds($ids);

$unseen = [];
foreach ($status as $id => $is_unseen) {
    if ($is_unseen) {
        $unseen[] = (int) $id;
    }
}

echo json_encode(['unseen' => $unseen]);
