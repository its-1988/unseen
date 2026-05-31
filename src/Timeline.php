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
use CommonITILObject;

/**
 * Two responsibilities, both driven by the ITIL object form/timeline render:
 *
 *  1. Mark the object as read as soon as its form is displayed. This is done
 *     server-side (at request shutdown) so it does not depend on JavaScript:
 *     opening a ticket always clears it. The previous read position is captured
 *     *before* the marking so the separator can still be drawn.
 *
 *  2. Draw a single "New messages" separator in the timeline at the boundary of
 *     that previously captured read position.
 */
class Timeline
{
    /**
     * Per-request state, keyed by "<Itemtype>_<id>".
     *
     * @var array<string, array{last: ?string, drawn: bool, sawNew: bool, reversed: bool}>
     */
    private static array $state = [];

    /**
     * Capture the pre-visit read position for an ITIL object (once per request)
     * and schedule it to be marked as read at the end of the request.
     */
    private static function capture(CommonITILObject $parent): void
    {
        $type = $parent->getType();
        $id   = (int) $parent->getID();

        if ($id <= 0 || !in_array($type, ReadStatus::TRACKED_ITEMTYPES, true)) {
            return;
        }

        $key = $type . '_' . $id;
        if (isset(self::$state[$key])) {
            return;
        }

        $entities_id = (int) ($parent->fields['entities_id'] ?? 0);

        self::$state[$key] = [
            'last'     => ReadStatus::getLastRead($type, $id, $entities_id),
            'drawn'    => false,
            'sawNew'   => false,
            'reversed' => (($_SESSION['glpitimeline_order'] ?? '') === CommonITILObject::TIMELINE_ORDER_REVERSE),
        ];

        // Mark as read at the very end of the request, so the read position
        // used above (and the separator below) reflect the state *before* this
        // visit. No JavaScript required.
        register_shutdown_function(static function () use ($type, $id) {
            ReadStatus::markAsRead($type, $id);
        });
    }

    /**
     * Hook: Glpi\Plugin\Hooks::PRE_ITEM_FORM.
     *
     * Fires for the ITIL object form (even when the timeline has no entries),
     * so it reliably captures the read position and schedules marking.
     *
     * @param array{item?: mixed, options?: array} $params
     */
    public static function preItemForm($params): void
    {
        $item = $params['item'] ?? null;
        if ($item instanceof CommonITILObject) {
            self::capture($item);
        }
    }

    /**
     * Hook: Glpi\Plugin\Hooks::PRE_SHOW_ITEM.
     *
     * Called once per timeline sub-item; draws the separator at the boundary.
     *
     * @param array{item: mixed, options?: array} $params
     */
    public static function preShowItem($params): void
    {
        $item   = $params['item'] ?? null;
        $parent = $params['options']['parent'] ?? null;

        if (!($parent instanceof CommonITILObject)) {
            return;
        }

        self::capture($parent);

        $key = $parent->getType() . '_' . (int) $parent->getID();
        if (!isset(self::$state[$key])) {
            return; // not a tracked itemtype
        }

        $st = &self::$state[$key];

        // No previous read position (first visit) or separator already drawn.
        if ($st['last'] === null || $st['drawn']) {
            return;
        }

        $date = self::extractDate($item);
        if ($date === null) {
            return;
        }

        $is_new = strcmp($date, $st['last']) > 0;

        if (!$st['reversed']) {
            // Natural order (oldest first): the line goes before the first new item.
            if ($is_new) {
                self::renderSeparator();
                $st['drawn'] = true;
            }
        } else {
            // Reverse order (newest first): the line goes after the new block,
            // i.e. before the first already-read item once a new one was seen.
            if ($is_new) {
                $st['sawNew'] = true;
            } elseif ($st['sawNew']) {
                self::renderSeparator();
                $st['drawn'] = true;
            }
        }
    }

    /**
     * Extract the timeline date of a sub-item (object or raw array).
     */
    private static function extractDate($item): ?string
    {
        if (is_array($item)) {
            $fields = $item;
        } elseif (is_object($item) && isset($item->fields) && is_array($item->fields)) {
            $fields = $item->fields;
        } else {
            return null;
        }

        $date = $fields['date'] ?? $fields['date_creation'] ?? null;
        if ($date === null || $date === '' || str_starts_with((string) $date, '0000')) {
            return null;
        }

        return (string) $date;
    }

    /**
     * Output the visual separator.
     */
    private static function renderSeparator(): void
    {
        echo '<div class="plugin-unseen-separator" data-testid="unseen-separator">'
            . '<span class="plugin-unseen-separator-line"></span>'
            . '<span class="plugin-unseen-separator-label">'
            . htmlspecialchars(__('New messages', 'unseen'))
            . '</span>'
            . '<span class="plugin-unseen-separator-line"></span>'
            . '</div>';
    }

    /**
     * Hook: Glpi\Plugin\Hooks::ITEM_PURGE.
     *
     * Remove read status rows attached to a purged ITIL object.
     *
     * @param CommonDBTM $item
     */
    public static function cleanupParent($item): void
    {
        if ($item instanceof CommonDBTM && $item->getID()) {
            ReadStatus::deleteForItem($item->getType(), (int) $item->getID());
        }
    }
}
