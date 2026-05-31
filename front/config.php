<?php

/**
 * -------------------------------------------------------------------------
 * Unseen plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Unseen plugin team.
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Unseen\Config as UnseenConfig;
use GlpiPlugin\Unseen\EntityConfig;

if (!defined('GLPI_ROOT')) {
    require_once __DIR__ . '/../../../inc/includes.php';
}

Plugin::load('unseen', true);

Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
    // CSRF is validated globally by the kernel for POST requests.
    $mode  = (int) ($_POST['default_mode'] ?? EntityConfig::MODE_PER_USER);
    $valid = array_keys(EntityConfig::getModes());
    if (in_array($mode, $valid, true)) {
        Config::setConfigurationValues('plugin:unseen', ['default_mode' => $mode]);
        Session::addMessageAfterRedirect(__s('Setting saved', 'unseen'));
    }
    Html::back();
}

Html::header(
    UnseenConfig::getTypeName(),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

(new UnseenConfig())->showGlobalForm();

Html::footer();
