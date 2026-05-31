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

use CommonGLPI;
use Config as GlpiConfig;
use Dropdown;
use Html;
use Session;

/**
 * Global configuration of the plugin (default mode used as a fallback when no
 * entity in the tree defines an explicit mode).
 */
class Config extends \CommonDBTM
{
    protected static $notable = true;

    public static $rightname = 'config';

    public static function getTypeName($nb = 0)
    {
        return __('Unseen messages', 'unseen');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$withtemplate && $item instanceof GlpiConfig && Session::haveRight(self::$rightname, READ)) {
            return self::getTypeName();
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof GlpiConfig) {
            (new self())->showGlobalForm();
        }

        return true;
    }

    /**
     * Render the global configuration form.
     */
    public function showGlobalForm(): void
    {
        global $CFG_GLPI;

        $canedit = Session::haveRight(self::$rightname, UPDATE);
        $default = EntityConfig::getGlobalDefaultMode();

        echo "<div class='spaced'>";
        echo "<form name='form' method='post' action='"
            . $CFG_GLPI['root_doc'] . "/plugins/unseen/front/config.php'>";

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'>" . self::getTypeName() . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td style='width:40%'>" . __('Default mode (used when no entity defines one)', 'unseen') . "</td>";
        echo "<td>";
        if ($canedit) {
            Dropdown::showFromArray('default_mode', EntityConfig::getModes(), ['value' => $default]);
        } else {
            $modes = EntityConfig::getModes();
            echo htmlspecialchars($modes[$default] ?? '');
        }
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td colspan='2'>";
        echo "<em>" . __('Per-entity overrides are configured in Administration > Entities, "Unseen messages" tab.', 'unseen') . "</em>";
        echo "</td></tr>";

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
