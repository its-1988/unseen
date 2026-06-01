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
use CommonITILActor;
use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QuerySubQuery;
use Session;
use Ticket;

/**
 * Per-user (or shared) read status of ITIL objects.
 *
 * A row stores the timestamp up to which a given user has read a given item.
 * An item is "unseen" when it has timeline activity from somebody else that is
 * more recent than that timestamp.
 *
 * The {@see self::SHARED_USER} (0) pseudo-user is used to store the shared
 * read status used by technicians in the "share central" mode.
 */
class ReadStatus extends CommonDBTM
{
    /** Itemtypes whose timeline activity is tracked. */
    public const TRACKED_ITEMTYPES = ['Ticket'];

    /**
     * Current time as a SQL datetime string.
     */
    public static function now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    /**
     * Resolve which users_id the read status should be stored/read under for
     * the current user, given the entity of the item.
     *
     * @return int|null The users_id to use, or null if the feature is disabled.
     */
    public static function getStorageUser(int $entities_id, ?int $uid = null): ?int
    {
        $uid ??= (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return null;
        }

        $mode = EntityConfig::getMode($entities_id);
        if ($mode === EntityConfig::MODE_DISABLED) {
            return null;
        }

        if (
            $mode === EntityConfig::MODE_HELPDESK_SHARE_CENTRAL
            && Session::getCurrentInterface() === 'central'
        ) {
            return EntityConfig::SHARED_USER;
        }

        return $uid;
    }

    /**
     * Mark an ITIL object as read (up to "now") for the current user.
     */
    public static function markAsRead(string $itemtype, int $items_id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!in_array($itemtype, self::TRACKED_ITEMTYPES, true)) {
            return false;
        }

        $item = getItemForItemtype($itemtype);
        if (!$item || !$item->getFromDB($items_id)) {
            return false;
        }

        $entities_id = (int) ($item->fields['entities_id'] ?? 0);
        $storage     = self::getStorageUser($entities_id);
        if ($storage === null) {
            return false;
        }

        $now = self::now();

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'users_id' => $storage,
            ],
            'LIMIT'  => 1,
        ])->current();

        if ($existing) {
            return (bool) $DB->update(self::getTable(), ['last_read' => $now], ['id' => $existing['id']]);
        }

        return (bool) $DB->insert(self::getTable(), [
            'itemtype'  => $itemtype,
            'items_id'  => $items_id,
            'users_id'  => $storage,
            'last_read' => $now,
        ]);
    }

    /**
     * Last read timestamp for the current user on a given item.
     *
     * @return string|null SQL datetime, or null if never read.
     */
    public static function getLastRead(string $itemtype, int $items_id, ?int $entities_id = null): ?string
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($entities_id === null) {
            $item = getItemForItemtype($itemtype);
            if (!$item || !$item->getFromDB($items_id)) {
                return null;
            }
            $entities_id = (int) ($item->fields['entities_id'] ?? 0);
        }

        $storage = self::getStorageUser($entities_id);
        if ($storage === null) {
            return null;
        }

        $row = $DB->request([
            'SELECT' => ['last_read'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'users_id' => $storage,
            ],
            'LIMIT'  => 1,
        ])->current();

        return $row ? ($row['last_read'] ?? null) : null;
    }

    /**
     * Build the map "ticket id => latest foreign timeline activity date".
     *
     * "Foreign" means authored by someone other than the current user.
     *
     * @param int[] $ids
     *
     * @return array<int, string> id => SQL datetime
     */
    private static function getActivityMap(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (empty($ids)) {
            return [];
        }

        $uid       = (int) Session::getLoginUserID();
        $interface = Session::getCurrentInterface();
        $activity  = [];

        $merge = static function (array $rows, string $datefield) use (&$activity) {
            foreach ($rows as $row) {
                $id   = (int) $row['items_id'];
                $date = $row[$datefield];
                if ($date === null) {
                    continue;
                }
                if (!isset($activity[$id]) || strcmp($date, $activity[$id]) > 0) {
                    $activity[$id] = $date;
                }
            }
        };

        // Followups.
        $where = [
            'itemtype' => 'Ticket',
            'items_id' => $ids,
            'users_id' => ['<>', $uid],
        ];
        if ($interface !== 'central') {
            $where['is_private'] = 0;
        }
        $merge(iterator_to_array($DB->request([
            'SELECT'  => ['items_id', new QueryExpression('MAX(' . $DB->quoteName('date') . ') AS ' . $DB->quoteName('maxdate'))],
            'FROM'    => 'glpi_itilfollowups',
            'WHERE'   => $where,
            'GROUPBY' => 'items_id',
        ])), 'maxdate');

        // Tasks.
        $where = [
            'tickets_id' => $ids,
            'users_id'   => ['<>', $uid],
        ];
        if ($interface !== 'central') {
            $where['is_private'] = 0;
        }
        $merge(iterator_to_array($DB->request([
            'SELECT'  => [new QueryExpression($DB->quoteName('tickets_id') . ' AS ' . $DB->quoteName('items_id')), new QueryExpression('MAX(' . $DB->quoteName('date') . ') AS ' . $DB->quoteName('maxdate'))],
            'FROM'    => 'glpi_tickettasks',
            'WHERE'   => $where,
            'GROUPBY' => 'tickets_id',
        ])), 'maxdate');

        // Solutions.
        $merge(iterator_to_array($DB->request([
            'SELECT'  => ['items_id', new QueryExpression('MAX(' . $DB->quoteName('date_creation') . ') AS ' . $DB->quoteName('maxdate'))],
            'FROM'    => 'glpi_itilsolutions',
            'WHERE'   => [
                'itemtype' => 'Ticket',
                'items_id' => $ids,
                'users_id' => ['<>', $uid],
            ],
            'GROUPBY' => 'items_id',
        ])), 'maxdate');

        // Ticket creation (covers brand-new tickets opened by somebody else).
        $merge(iterator_to_array($DB->request([
            'SELECT' => [new QueryExpression($DB->quoteName('id') . ' AS ' . $DB->quoteName('items_id')), new QueryExpression($DB->quoteName('date') . ' AS ' . $DB->quoteName('maxdate'))],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => [
                'id'                => $ids,
                'users_id_recipient' => ['<>', $uid],
            ],
        ])), 'maxdate');

        return $activity;
    }

    /**
     * Build the map "ticket id => last_read" for the relevant storage users.
     *
     * @param int[] $ids
     *
     * @return array<int, array<int, string>> id => (users_id => last_read)
     */
    private static function getReadMap(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (empty($ids)) {
            return [];
        }

        $uid = (int) Session::getLoginUserID();
        $map = [];
        foreach ($DB->request([
            'SELECT' => ['items_id', 'users_id', 'last_read'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'itemtype' => 'Ticket',
                'items_id' => $ids,
                'users_id' => [$uid, EntityConfig::SHARED_USER],
            ],
        ]) as $row) {
            $map[(int) $row['items_id']][(int) $row['users_id']] = $row['last_read'];
        }

        return $map;
    }

    /**
     * Compute the unseen state for a set of tickets.
     *
     * @param array<int, int> $tickets id => entities_id
     *
     * @return array<int, array{unseen: bool, date: ?string}>
     */
    private static function computeUnseen(array $tickets): array
    {
        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0 || empty($tickets)) {
            return [];
        }

        $ids       = array_keys($tickets);
        $interface = Session::getCurrentInterface();
        $activity  = self::getActivityMap($ids);
        $readmap   = self::getReadMap($ids);

        $result = [];
        foreach ($tickets as $id => $entities_id) {
            $mode = EntityConfig::getMode((int) $entities_id);
            if ($mode === EntityConfig::MODE_DISABLED) {
                $result[$id] = ['unseen' => false, 'date' => null];
                continue;
            }

            $storage = ($mode === EntityConfig::MODE_HELPDESK_SHARE_CENTRAL && $interface === 'central')
                ? EntityConfig::SHARED_USER
                : $uid;

            $last = $readmap[$id][$storage] ?? null;
            $act  = $activity[$id] ?? null;

            $unseen = $act !== null && ($last === null || strcmp($act, $last) > 0);
            $result[$id] = ['unseen' => $unseen, 'date' => $act];
        }

        return $result;
    }

    /**
     * Tickets the current user should be notified about (optionally restricted
     * to a given set of ids).
     *
     * Always includes the tickets the user is *involved* in — requester,
     * assignee or observer, directly or through one of their groups. For
     * technicians (central interface) it ALSO includes the open *unassigned*
     * tickets sitting in the intake queue of their entities (no assigned
     * technician, group or supplier), so a brand-new ticket dropped in the
     * shared queue lights the bell even before anybody picks it up. Self-service
     * (helpdesk) users only ever see their own tickets.
     *
     * Only open (not closed), non-deleted tickets within the user's active
     * entities are considered. Single source of truth shared by the bell and the
     * list highlight, so both always agree on which tickets count.
     *
     * @param int[]|null $restrict_ids Limit to these ids, or null for all.
     *
     * @return array<int, array{entities_id:int, name:string}>
     */
    private static function getWatchedCandidates(?array $restrict_ids, int $limit): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return [];
        }
        if ($restrict_ids !== null && empty($restrict_ids)) {
            return [];
        }

        $types  = [CommonITILActor::REQUESTER, CommonITILActor::ASSIGN, CommonITILActor::OBSERVER];
        $groups = $_SESSION['glpigroups'] ?? [];

        // Tickets the user is personally an actor of.
        $user_sub = new QuerySubQuery([
            'SELECT' => 'tickets_id',
            'FROM'   => 'glpi_tickets_users',
            'WHERE'  => ['users_id' => $uid, 'type' => $types],
        ]);
        $watched = [['glpi_tickets.id' => $user_sub]];

        // Tickets one of the user's groups is an actor of.
        if (!empty($groups)) {
            $group_sub = new QuerySubQuery([
                'SELECT' => 'tickets_id',
                'FROM'   => 'glpi_groups_tickets',
                'WHERE'  => ['groups_id' => $groups, 'type' => $types],
            ]);
            $watched[] = ['glpi_tickets.id' => $group_sub];
        }

        // Technicians also watch the unassigned intake queue of their entities:
        // tickets with no assigned technician, group or supplier. Helpdesk users
        // only see their own tickets, so this branch is central-only.
        if (Session::getCurrentInterface() === 'central') {
            $assign = CommonITILActor::ASSIGN;
            $assigned_user_sub = new QuerySubQuery([
                'SELECT' => 'tickets_id',
                'FROM'   => 'glpi_tickets_users',
                'WHERE'  => ['type' => $assign],
            ]);
            $assigned_group_sub = new QuerySubQuery([
                'SELECT' => 'tickets_id',
                'FROM'   => 'glpi_groups_tickets',
                'WHERE'  => ['type' => $assign],
            ]);
            $assigned_supplier_sub = new QuerySubQuery([
                'SELECT' => 'tickets_id',
                'FROM'   => 'glpi_suppliers_tickets',
                'WHERE'  => ['type' => $assign],
            ]);
            $watched[] = [
                'NOT' => [
                    'OR' => [
                        ['glpi_tickets.id' => $assigned_user_sub],
                        ['glpi_tickets.id' => $assigned_group_sub],
                        ['glpi_tickets.id' => $assigned_supplier_sub],
                    ],
                ],
            ];
        }

        $where = [
            'glpi_tickets.is_deleted' => 0,
            'glpi_tickets.status'     => ['<>', Ticket::CLOSED],
            'OR'                      => $watched,
        ];

        // Restrict to the user's active entities. Pushed as a nested AND group
        // (numeric key) so its own internal OR (recursive entities) survives — a
        // plain `+=` would silently drop it, because $where already owns 'OR'.
        $entity_crit = getEntitiesRestrictCriteria('glpi_tickets', '', '', true);
        if (!empty($entity_crit)) {
            $where[] = $entity_crit;
        }

        if ($restrict_ids !== null) {
            $where['glpi_tickets.id'] = array_values(array_unique(array_map('intval', $restrict_ids)));
        }

        $out = [];
        foreach ($DB->request([
            'SELECT'  => ['id', 'name', 'entities_id'],
            'FROM'    => 'glpi_tickets',
            'WHERE'   => $where,
            'ORDERBY' => 'date_mod DESC',
            'LIMIT'   => $limit,
        ]) as $row) {
            $out[(int) $row['id']] = [
                'entities_id' => (int) $row['entities_id'],
                'name'        => (string) $row['name'],
            ];
        }

        return $out;
    }

    /**
     * Unseen state for an explicit list of ticket ids (used to highlight lists).
     * Only the user's involved + open tickets are considered, exactly like the
     * bell — so that "mark all as read" clears the list highlight too.
     *
     * @param int[] $ids
     *
     * @return array<int, bool> id => is_unseen
     */
    public static function getUnseenStatusForIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        $candidates = self::getWatchedCandidates($ids, count($ids));

        $tickets = [];
        foreach ($candidates as $id => $info) {
            $tickets[$id] = $info['entities_id'];
        }
        $computed = self::computeUnseen($tickets);

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = isset($computed[$id]) && $computed[$id]['unseen'];
        }

        return $out;
    }

    /**
     * List of unseen tickets for the current user, most recent activity first.
     *
     * @return array<int, array{id:int, name:string, entities_id:int, date:?string}>
     */
    public static function getUnseenTickets(int $limit = 50): array
    {
        $candidates = self::getWatchedCandidates(null, 500);
        if (empty($candidates)) {
            return [];
        }

        $tickets = [];
        foreach ($candidates as $id => $info) {
            $tickets[$id] = $info['entities_id'];
        }
        $computed = self::computeUnseen($tickets);

        $list = [];
        foreach ($computed as $id => $data) {
            if (!$data['unseen']) {
                continue;
            }
            $list[] = [
                'id'          => $id,
                'name'        => $candidates[$id]['name'] ?? '',
                'entities_id' => $tickets[$id],
                'date'        => $data['date'],
            ];
        }

        // Most recent activity first.
        usort($list, static fn($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

        return array_slice($list, 0, $limit);
    }

    /**
     * Number of unseen tickets for the current user.
     */
    public static function getUnseenCount(): int
    {
        return count(self::getUnseenTickets(500));
    }

    /**
     * Mark every currently unseen ticket of the current user as read.
     *
     * @return int Number of tickets marked as read.
     */
    public static function markAllAsRead(): int
    {
        $count = 0;
        foreach (self::getUnseenTickets(500) as $ticket) {
            if (self::markAsRead('Ticket', (int) $ticket['id'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Remove every read status row attached to a given item.
     */
    public static function deleteForItem(string $itemtype, int $items_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(self::getTable(), [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
        ]);
    }
}
