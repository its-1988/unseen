<?php

/**
 * -------------------------------------------------------------------------
 * Unseen plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Unseen.
 *
 * Unseen is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * Unseen is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Unseen. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Unseen plugin team.
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Unseen\Config;
use GlpiPlugin\Unseen\EntityConfig;
use GlpiPlugin\Unseen\Timeline;

define('PLUGIN_UNSEEN_VERSION', '1.0.1');

// Minimal GLPI version, inclusive.
define('PLUGIN_UNSEEN_MIN_GLPI', '11.0.0');
// Maximum GLPI version, exclusive.
define('PLUGIN_UNSEEN_MAX_GLPI', '11.0.99');

/**
 * Init hooks of the plugin.
 * REQUIRED
 *
 * @return void
 */
function plugin_init_unseen()
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    // CSRF compliance is mandatory for GLPI >= 0.84.
    $PLUGIN_HOOKS['csrf_compliant']['unseen'] = true;

    // Per-entity configuration tab (Administration > Entities > Unseen messages).
    Plugin::registerClass(EntityConfig::class, ['addtabon' => 'Entity']);

    // Global configuration tab (Setup > General > Unseen messages).
    Plugin::registerClass(Config::class, ['addtabon' => 'Config']);

    // Front config page (linked from the plugins list).
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['unseen'] = 'front/config.php';
    }

    // Assets injected in the head of every authenticated page.
    // They power the header bell, the unseen highlight in lists and the
    // "New messages" separator in the timeline.
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['unseen']        = 'css/unseen.css';
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['unseen'] = 'js/unseen.js';

    // Timeline "New messages" separator: this hook is called for each
    // sub-item (followup, task, solution, ...) shown in an ITIL timeline.
    $PLUGIN_HOOKS[Hooks::PRE_SHOW_ITEM]['unseen'] = [Timeline::class, 'preShowItem'];

    // Mark the ITIL object as read when its form is displayed. This hook fires
    // for the object form even when the timeline has no entries, so opening a
    // ticket always clears it (server-side, no JavaScript needed).
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_FORM]['unseen'] = [Timeline::class, 'preItemForm'];

    // Drop read status rows when a parent ITIL object is purged.
    $cleanup_types = ['Ticket', 'Change', 'Problem'];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['unseen'] = array_fill_keys($cleanup_types, [Timeline::class, 'cleanupParent']);
}

/**
 * Get the name and the version of the plugin.
 * REQUIRED
 *
 * @return array
 */
function plugin_version_unseen()
{
    return [
        'name'         => __('Unseen messages', 'unseen'),
        'version'      => PLUGIN_UNSEEN_VERSION,
        'author'       => 'by Claude',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_UNSEEN_MIN_GLPI,
                'max' => PLUGIN_UNSEEN_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.1',
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install.
 * OPTIONAL, but recommended.
 *
 * @return boolean
 */
function plugin_unseen_check_prerequisites()
{
    // Version compatibility is already checked by GLPI through the
    // 'requirements' key of plugin_version_unseen(). Nothing more to do.
    return true;
}

/**
 * Check configuration process.
 *
 * @param boolean $verbose Whether to display message on failure. Defaults to false
 *
 * @return boolean
 */
function plugin_unseen_check_config($verbose = false)
{
    return true;
}
