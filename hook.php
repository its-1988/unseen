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

use GlpiPlugin\Unseen\EntityConfig;
use GlpiPlugin\Unseen\ReadStatus;

/**
 * Plugin install process.
 *
 * @return boolean
 */
function plugin_unseen_install()
{
    /** @var DBmysql $DB */
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    $readstatus_table = ReadStatus::getTable();
    if (!$DB->tableExists($readstatus_table)) {
        $query = "CREATE TABLE `{$readstatus_table}` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(100) NOT NULL,
            `items_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `users_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `last_read` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`itemtype`,`items_id`,`users_id`),
            KEY `item` (`itemtype`,`items_id`),
            KEY `users_id` (`users_id`),
            KEY `last_read` (`last_read`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($query);
    }

    $config_table = EntityConfig::getTable();
    if (!$DB->tableExists($config_table)) {
        $query = "CREATE TABLE `{$config_table}` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `mode` int NOT NULL DEFAULT '-2',
            PRIMARY KEY (`id`),
            UNIQUE KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($query);

        // Seed the root entity with an explicit, working default so the
        // feature is active out-of-the-box (children inherit from it).
        $DB->insert($config_table, [
            'entities_id' => 0,
            'mode'        => EntityConfig::MODE_PER_USER,
        ]);
    }

    // Global default used when no entity in the tree defines a mode.
    Config::setConfigurationValues('plugin:unseen', [
        'default_mode' => EntityConfig::MODE_PER_USER,
    ]);

    return true;
}

/**
 * Plugin uninstall process.
 *
 * @return boolean
 */
function plugin_unseen_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;

    foreach ([ReadStatus::getTable(), EntityConfig::getTable()] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    $config = new Config();
    $config->deleteConfigurationValues('plugin:unseen', ['default_mode']);

    return true;
}
