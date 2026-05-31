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

namespace GlpiPlugin\Unseen;

use CommonDBTM;
use CommonGLPI;
use Config as GlpiConfig;
use Dropdown;
use Entity;
use Html;
use Session;

/**
 * Per-entity configuration of the unseen feature.
 *
 * Stored in its own table, keyed by entities_id, with parent inheritance
 * handled the same way as core entity configuration.
 */
class EntityConfig extends CommonDBTM
{
    /** Inherit the value from the parent entity. */
    public const MODE_INHERIT = Entity::CONFIG_PARENT; // -2

    /** Feature disabled for the entity. */
    public const MODE_DISABLED = 0;

    /**
     * Self-service users (helpdesk interface) have a personal queue limited to
     * their own tickets; technicians (central interface) share a single queue,
     * so marking a ticket as read marks it read for every technician.
     */
    public const MODE_HELPDESK_SHARE_CENTRAL = 1;

    /** Every user (including technicians) has a personal queue. */
    public const MODE_PER_USER = 2;

    /** Sentinel users_id used to store the shared (central) read status. */
    public const SHARED_USER = 0;

    public static $rightname = 'entity';

    public static function getTypeName($nb = 0)
    {
        return __('Unseen messages', 'unseen');
    }

    /**
     * List of selectable modes (excluding the inheritance option).
     *
     * @return array<int, string>
     */
    public static function getModes(): array
    {
        return [
            self::MODE_DISABLED               => __('Disabled', 'unseen'),
            self::MODE_HELPDESK_SHARE_CENTRAL => __('Per user on helpdesk; shared between technicians', 'unseen'),
            self::MODE_PER_USER               => __('Per user', 'unseen'),
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$withtemplate && $item instanceof Entity && self::canView()) {
            return self::getTypeName();
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Entity) {
            (new self())->showForEntity($item);
        }

        return true;
    }

    /**
     * Resolve the effective mode for an entity, walking up the entity tree.
     *
     * @param int $entities_id
     *
     * @return int One of the MODE_* constants (never MODE_INHERIT).
     */
    public static function getMode(int $entities_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        static $cache = [];
        if (isset($cache[$entities_id])) {
            return $cache[$entities_id];
        }

        $ids = array_merge([$entities_id], getAncestorsOf(Entity::getTable(), $entities_id));

        // Parent pointers, to climb the tree in order.
        $parent = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'entities_id'],
            'FROM'   => Entity::getTable(),
            'WHERE'  => ['id' => $ids],
        ]) as $row) {
            $parent[(int) $row['id']] = (int) $row['entities_id'];
        }

        // Stored modes per entity.
        $modes = [];
        foreach ($DB->request([
            'SELECT' => ['entities_id', 'mode'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['entities_id' => $ids],
        ]) as $row) {
            $modes[(int) $row['entities_id']] = (int) $row['mode'];
        }

        // Walk from the requested entity up to the root.
        $current  = $entities_id;
        $resolved = null;
        $guard    = 0;
        while ($guard++ < 1000) {
            if (isset($modes[$current]) && $modes[$current] !== self::MODE_INHERIT) {
                $resolved = $modes[$current];
                break;
            }
            if ($current === 0) {
                break; // root reached, no explicit value
            }
            $current = $parent[$current] ?? 0;
        }

        if ($resolved === null) {
            $resolved = self::getGlobalDefaultMode();
        }

        $cache[$entities_id] = $resolved;
        return $resolved;
    }

    /**
     * Global default mode, used when no entity in the tree defines one.
     */
    public static function getGlobalDefaultMode(): int
    {
        $conf = GlpiConfig::getConfigurationValues('plugin:unseen', ['default_mode']);
        if (isset($conf['default_mode']) && $conf['default_mode'] !== '') {
            return (int) $conf['default_mode'];
        }

        return self::MODE_PER_USER;
    }

    /**
     * Stored (raw) mode for a single entity, or MODE_INHERIT if not set.
     */
    public static function getRawMode(int $entities_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'SELECT' => ['mode'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['entities_id' => $entities_id],
            'LIMIT'  => 1,
        ])->current();

        return $row ? (int) $row['mode'] : self::MODE_INHERIT;
    }

    /**
     * Persist the mode for an entity (upsert).
     */
    public static function setMode(int $entities_id, int $mode): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['entities_id' => $entities_id],
            'LIMIT'  => 1,
        ])->current();

        if ($existing) {
            return (bool) $DB->update(self::getTable(), ['mode' => $mode], ['id' => $existing['id']]);
        }

        return (bool) $DB->insert(self::getTable(), [
            'entities_id' => $entities_id,
            'mode'        => $mode,
        ]);
    }

    /**
     * Render the configuration form inside the entity tab.
     */
    public function showForEntity(Entity $entity): void
    {
        $entities_id = (int) $entity->getID();
        $canedit     = Session::haveRight(self::$rightname, UPDATE);

        $raw_mode      = self::getRawMode($entities_id);
        $effective     = self::getMode($entities_id);
        $modes         = self::getModes();

        // Build the dropdown options. The root entity cannot inherit.
        $options = [];
        if ($entities_id > 0) {
            $options[self::MODE_INHERIT] = __('Inheritance of the parent entity', 'unseen')
                . ' (' . ($modes[$effective] ?? '') . ')';
        }
        $options += $modes;

        global $CFG_GLPI;

        echo "<div class='spaced'>";
        echo "<form name='form' method='post' action='"
            . $CFG_GLPI['root_doc'] . "/plugins/unseen/front/entityconfig.form.php'>";

        echo "<input type='hidden' name='entities_id' value='{$entities_id}'>";

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'>" . self::getTypeName() . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td style='width:40%'>" . __('Mode', 'unseen') . "</td>";
        echo "<td>";
        if ($canedit) {
            Dropdown::showFromArray('mode', $options, [
                'value' => $raw_mode,
            ]);
        } else {
            echo htmlspecialchars($options[$raw_mode] ?? ($modes[$effective] ?? ''));
        }
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Effective mode', 'unseen') . "</td>";
        echo "<td>" . htmlspecialchars($modes[$effective] ?? '') . "</td>";
        echo "</tr>";

        if ($canedit) {
            echo "<tr class='tab_bg_2'><td colspan='2' class='center'>";
            echo "<input type='submit' name='update' class='btn btn-primary' value='"
                . _sx('button', 'Save') . "'>";
            echo "</td></tr>";
        }

        echo "</table>";

        Html::closeForm();
        echo "</div>";
    }
}
