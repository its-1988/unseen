<?php

/**
 * -------------------------------------------------------------------------
 * Unseen plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Unseen plugin team.
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Unseen\EntityConfig;

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

Plugin::load('unseen', true);

Session::checkRight('entity', UPDATE);

if (isset($_POST['update'])) {
    // CSRF is validated globally by the kernel for POST requests.
    $entities_id = (int) ($_POST['entities_id'] ?? -1);
    $mode        = (int) ($_POST['mode'] ?? EntityConfig::MODE_INHERIT);

    $valid_modes = array_merge([EntityConfig::MODE_INHERIT], array_keys(EntityConfig::getModes()));
    if ($entities_id >= 0 && in_array($mode, $valid_modes, true)) {
        EntityConfig::setMode($entities_id, $mode);
        Session::addMessageAfterRedirect(__s('Setting saved', 'unseen'));
    }
}

Html::back();
