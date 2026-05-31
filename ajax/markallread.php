<?php

/**
 * -------------------------------------------------------------------------
 * Unseen plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Unseen plugin team.
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 *
 * Marks every currently unseen ticket of the current user as read.
 * POST only; CSRF is validated globally by the kernel.
 */

use GlpiPlugin\Unseen\ReadStatus;

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

header('Content-Type: application/json; charset=UTF-8');

if (!Session::getLoginUserID()) {
    echo json_encode(['success' => false]);
    exit;
}

$count = ReadStatus::markAllAsRead();

echo json_encode(['success' => true, 'count' => $count]);
