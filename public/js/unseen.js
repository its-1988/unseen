/*
 * -------------------------------------------------------------------------
 * Unseen plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Unseen plugin team.
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 */

/* global $, CFG_GLPI */

(function () {
    "use strict";

    if (typeof $ === "undefined" || typeof CFG_GLPI === "undefined") {
        return;
    }

    var ROOT = CFG_GLPI.root_doc + "/plugins/unseen";
    var POLL_INTERVAL = 60000; // 60s
    var labels = {
        title: "Unseen messages",
        empty: "No unseen messages",
        aria: "Unseen messages",
        and_more: "and %d more",
        mark_all: "Mark all as read"
    };

    function getCsrfToken() {
        var meta = document.querySelector('meta[property="glpi:csrf_token"]');
        return meta ? meta.getAttribute("content") : "";
    }

    /* --------------------------------------------------------------------- */
    /* Helpers                                                               */
    /* --------------------------------------------------------------------- */
    function escapeHtml(str) {
        return $("<div>").text(str == null ? "" : str).html();
    }

    /* --------------------------------------------------------------------- */
    /* Header bell                                                           */
    /* --------------------------------------------------------------------- */
    function buildBell() {
        if (document.getElementById("plugin-unseen-bell")) {
            return document.getElementById("plugin-unseen-bell");
        }

        var header = document.querySelector('header[data-testid="main-header"] .header-container')
            || document.querySelector("header.navbar .header-container")
            || document.querySelector("header.navbar");
        if (!header) {
            return null;
        }

        var bell = document.createElement("div");
        bell.className = "nav-item dropdown plugin-unseen-nav d-flex align-items-center";
        bell.id = "plugin-unseen-bell";
        bell.innerHTML =
            '<a href="#" class="nav-link" data-bs-toggle="dropdown" data-bs-auto-close="outside" '
            + 'aria-label="' + escapeHtml(labels.aria) + '" role="button" aria-haspopup="true" aria-expanded="false">'
            + '<span class="plugin-unseen-icon">'
            + '<i class="ti ti-bell"></i>'
            + '<span class="badge bg-red plugin-unseen-badge" style="display:none">0</span>'
            + '</span>'
            + '</a>'
            + '<div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow plugin-unseen-menu">'
            + '  <div class="card">'
            + '    <div class="card-header py-2"><h3 class="card-title">' + escapeHtml(labels.title) + '</h3></div>'
            + '    <div class="list-group list-group-flush plugin-unseen-list"></div>'
            + '    <div class="card-body plugin-unseen-empty" style="display:none">' + escapeHtml(labels.empty) + '</div>'
            + '    <div class="card-footer p-0 plugin-unseen-footer" style="display:none">'
            + '      <button type="button" class="btn btn-sm btn-link w-100 plugin-unseen-markall">'
            + escapeHtml(labels.mark_all) + '</button>'
            + '    </div>'
            + '  </div>'
            + '</div>';

        // Insert the bell just before the *desktop* user-menu wrapper. There is
        // also a hidden mobile copy (.d-lg-none) we must skip.
        var target = null;
        Array.prototype.forEach.call(header.children, function (child) {
            if (child.querySelector && child.querySelector(".user-menu") && !child.classList.contains("d-lg-none")) {
                target = child;
            }
        });

        if (target) {
            header.insertBefore(bell, target);
        } else {
            header.appendChild(bell);
        }

        return bell;
    }

    function renderBell(data) {
        var bell = document.getElementById("plugin-unseen-bell");
        if (!bell) {
            return;
        }

        if (data.labels) {
            labels = $.extend(labels, data.labels);
        }

        var count = parseInt(data.count, 10) || 0;
        var $badge = $(bell).find(".plugin-unseen-badge");
        var $footer = $(bell).find(".plugin-unseen-footer");
        if (count > 0) {
            $badge.text(count > 99 ? "99+" : count).show();
            $(bell).addClass("has-unseen");
            $footer.find(".plugin-unseen-markall").text(labels.mark_all);
            $footer.show();
        } else {
            $badge.hide();
            $(bell).removeClass("has-unseen");
            $footer.hide();
        }

        var $list = $(bell).find(".plugin-unseen-list");
        var $empty = $(bell).find(".plugin-unseen-empty");
        $list.empty();

        var items = data.items || [];
        if (!items.length) {
            $empty.text(labels.empty).show();
        } else {
            $empty.hide();
            items.forEach(function (it) {
                var $a = $("<a>")
                    .attr("href", it.url)
                    .addClass("list-group-item list-group-item-action plugin-unseen-item");
                $("<div>").addClass("plugin-unseen-item-title").text(it.name).appendTo($a);
                if (it.date) {
                    $("<div>").addClass("plugin-unseen-item-meta text-muted").text(it.date).appendTo($a);
                }
                $list.append($a);
            });

            var remaining = count - items.length;
            if (remaining > 0) {
                $("<div>")
                    .addClass("list-group-item text-muted text-center plugin-unseen-item-meta")
                    .text(labels.and_more.replace("%d", remaining))
                    .appendTo($list);
            }
        }
    }

    function refreshBell() {
        if (!document.getElementById("plugin-unseen-bell")) {
            return;
        }
        $.ajax({
            url: ROOT + "/ajax/menu.php",
            dataType: "json",
            cache: false
        }).done(renderBell);
    }

    /* --------------------------------------------------------------------- */
    /* Unseen highlight in lists                                             */
    /* --------------------------------------------------------------------- */
    var highlightScheduled = false;

    function highlightLists() {
        highlightScheduled = false;

        var anchors = document.querySelectorAll(
            '#page table a[href*="ticket.form.php?id="]'
        );
        if (!anchors.length) {
            return;
        }

        var map = {};
        anchors.forEach(function (a) {
            if (a.getAttribute("data-unseen-checked")) {
                return; // already evaluated — never re-query the same row
            }
            a.setAttribute("data-unseen-checked", "1");
            var m = (a.getAttribute("href") || "").match(/ticket\.form\.php\?id=(\d+)/);
            if (!m) {
                return;
            }
            var id = parseInt(m[1], 10);
            var tr = a.closest("tr");
            if (!tr) {
                return;
            }
            (map[id] = map[id] || []).push(tr);
        });

        var ids = Object.keys(map);
        if (!ids.length) {
            return; // nothing new to check → no request
        }

        $.ajax({
            url: ROOT + "/ajax/status.php",
            data: { ids: ids.join(",") },
            dataType: "json",
            cache: false
        }).done(function (data) {
            (data.unseen || []).forEach(function (id) {
                (map[id] || []).forEach(function (tr) {
                    tr.classList.add("plugin-unseen-row");
                });
            });
        });
    }

    function scheduleHighlight() {
        if (highlightScheduled) {
            return;
        }
        highlightScheduled = true;
        setTimeout(highlightLists, 300);
    }

    /* --------------------------------------------------------------------- */
    /* Read tracking                                                         */
    /* --------------------------------------------------------------------- */

    // The ITIL object is marked read server-side when its form is rendered
    // (see Timeline::preItemForm). On a ticket page we just refresh the bell a
    // moment later so the counter reflects that.
    function scheduleTicketRefresh() {
        if (!/ticket\.form\.php/.test(window.location.pathname)) {
            return;
        }
        setTimeout(refreshBell, 2500);
    }

    // "Mark all as read" — clears every unseen ticket for the current user.
    function markAllAsRead() {
        $.ajax({
            url: ROOT + "/ajax/markallread.php",
            method: "POST",
            dataType: "json",
            headers: { "X-Glpi-Csrf-Token": getCsrfToken() }
        }).done(function () {
            document.querySelectorAll("tr.plugin-unseen-row").forEach(function (tr) {
                tr.classList.remove("plugin-unseen-row");
            });
            refreshBell();
        });
    }

    /* --------------------------------------------------------------------- */
    /* Boot                                                                  */
    /* --------------------------------------------------------------------- */
    $(function () {
        buildBell();
        refreshBell();
        scheduleHighlight();
        scheduleTicketRefresh();

        // Refresh the dropdown content when it is opened.
        $(document).on("show.bs.dropdown", "#plugin-unseen-bell", refreshBell);

        // "Mark all as read" button.
        $(document).on("click", "#plugin-unseen-bell .plugin-unseen-markall", function (e) {
            e.preventDefault();
            markAllAsRead();
        });

        // Periodic refresh.
        setInterval(refreshBell, POLL_INTERVAL);

        // Re-scan lists when GLPI injects or replaces rows (search results,
        // pagination, tab content). This used to hook $(document).ajaxComplete,
        // but that fired on EVERY request — including GLPI's own debug.php
        // capture when debug mode is on — so status.php and debug.php triggered
        // each other in an endless loop ("constant" requests). A debounced
        // MutationObserver watches the DOM instead, and the data-unseen-checked
        // guard means a re-scan only calls status.php when genuinely new ticket
        // rows appear — adding our highlight class changes no nodes, so it never
        // re-triggers itself.
        if (window.MutationObserver) {
            var observer = new MutationObserver(scheduleHighlight);
            observer.observe(document.body, { childList: true, subtree: true });
        }
    });
})();
