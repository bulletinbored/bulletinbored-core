(function () {
    'use strict';

    var stack = document.getElementById('mobileStack');
    if (!stack) return;

    var backBtn = document.getElementById('mobileStackBack');
    var tabs = stack.querySelectorAll('.mobile-stack-tab');
    var panes = stack.querySelectorAll('.mobile-stack-pane');
    var titleEl = document.getElementById('mobileStackTitle');

    var titles = { messages: 'Messages', notifications: 'Notifications', search: 'Search', user: 'Account', login: 'Login' };
    var loaded = { messages: false, notifications: false, user: false, login: false };

    var isMobile = function () {
        return window.matchMedia('(max-width: 991.98px)').matches;
    };

    // On mobile, stop the plugins from turning the navbar icons into Bootstrap
    // dropdowns – the mobile stack handles them instead.
    function neutralizeDropdownToggles() {
        if (!isMobile()) return;
        document.querySelectorAll('.topbar-user a[data-bs-toggle="dropdown"]').forEach(function (a) {
            a.removeAttribute('data-bs-toggle');
            a.classList.remove('dropdown-toggle');
        });
    }

    var scrollY = 0;

    function openStack(tab) {
        stack.classList.add('open');
        stack.setAttribute('aria-hidden', 'false');
        if (document.body.style.overflow !== 'hidden') {
            scrollY = window.scrollY;
            document.body.style.overflow = 'hidden';
            document.body.style.position = 'fixed';
            document.body.style.top = '-' + scrollY + 'px';
            document.body.style.left = '0';
            document.body.style.right = '0';
        }
        if (tab && stack.querySelector('.mobile-stack-tab[data-tab="' + tab + '"]')) {
            selectTab(tab);
        } else {
            var first = stack.querySelector('.mobile-stack-tab');
            selectTab(first ? first.dataset.tab : 'search');
        }
    }

    function closeStack() {
        stack.classList.remove('open');
        stack.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.left = '';
        document.body.style.right = '';
        window.scrollTo(0, scrollY);
    }

    function selectTab(tab) {
        tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.tab === tab); });
        panes.forEach(function (p) { p.classList.toggle('active', p.dataset.pane === tab); });
        titleEl.textContent = titles[tab] || '';
        if (tab === 'user' || tab === 'search' || tab === 'login') { return; }
        if (!loaded[tab]) {
            loadPane(tab);
        }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function loadPane(tab) {
        if (tab === 'messages') return loadMessages();
        if (tab === 'notifications') return loadNotifications();
    }

    function loadMessages() {
        var pane = document.getElementById('paneMessages');
        pane.innerHTML = '<div class="mobile-stack-loading">Loading…</div>';
        fetch((window.textmebored ? window.textmebored.apiUrl : '') + '/conversations', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                loaded.messages = true;
                if (!data.success || !data.conversations || !data.conversations.length) {
                    pane.innerHTML = '<div class="mobile-stack-empty"><i class="fas fa-envelope-open-text"></i><p>No messages yet</p></div>';
                    return;
                }
                var html = '';
                data.conversations.forEach(function (c) {
                    html += '<a class="mobile-stack-row" href="' + (window.textmebored ? window.textmebored.baseUrl : '') + '/messages">' +
                        '<div class="mobile-stack-row-main"><div class="mobile-stack-row-title">' + escapeHtml(c.other_username) + '</div>' +
                        '<div class="mobile-stack-row-sub">' + escapeHtml(c.last_message) + '</div></div>' +
                        (c.unread_count > 0 ? '<span class="mobile-stack-count">' + (c.unread_count > 99 ? '99+' : c.unread_count) + '</span>' : '') +
                        '</a>';
                });
                pane.innerHTML = html;
            })
            .catch(function () {
                pane.innerHTML = '<div class="mobile-stack-empty">Could not load messages</div>';
            });
    }

    function loadNotifications() {
        var pane = document.getElementById('paneNotifications');
        pane.innerHTML = '<div class="mobile-stack-loading">Loading…</div>';
        fetch((window.bellbored ? window.bellbored.apiUrl : '') + '', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                loaded.notifications = true;
                var items = data.items || [];
                if (!items.length) {
                    pane.innerHTML = '<div class="mobile-stack-empty"><i class="fas fa-bell-slash"></i><p>No notifications</p></div>';
                    return;
                }
                var html = '';
                items.forEach(function (it) {
                    var label = it.title ? it.title : escapeHtml(it.message);
                    var href = it.link || ((window.bellbored ? window.bellbored.baseUrl : '') + '/notifications');
                    html += '<a class="mobile-stack-row" href="' + escapeHtml(href) + '">' +
                        '<div class="mobile-stack-row-main"><div class="mobile-stack-row-title' + (it.is_read ? '' : ' fw-semibold') + '">' + escapeHtml(label) + '</div></div>' +
                        (it.is_read ? '' : '<span class="mobile-stack-dot"></span>') +
                        '</a>';
                });
                pane.innerHTML = html;
            })
            .catch(function () {
                pane.innerHTML = '<div class="mobile-stack-empty">Could not load notifications</div>';
            });
    }

    // Wire the navbar icons to open the stack instead of dropdowns (mobile only).
    function bindIcons() {
        document.querySelectorAll('[data-mobile-tab]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                if (!isMobile()) return;
                e.preventDefault();
                e.stopPropagation();
                neutralizeDropdownToggles();
                openStack(el.getAttribute('data-mobile-tab'));
            });
        });
        var userToggle = document.querySelector('.user-menu-dropdown > a');
        if (userToggle) {
            userToggle.addEventListener('click', function (e) {
                if (!isMobile()) return;
                e.preventDefault();
                e.stopPropagation();
                neutralizeDropdownToggles();
                openStack('user');
            });
        }
        // Mobile-only "menu" icon in the tab bar toggles the sidebar collapse
        // (the Browse button inside the sidebar is hidden on mobile).
        var menuToggle = document.getElementById('mobileMenuToggle');
        if (menuToggle) {
            menuToggle.addEventListener('click', function (e) {
                if (!isMobile()) return;
                e.preventDefault();
                var body = document.getElementById('sidebarBody');
                if (body && window.bootstrap) {
                    var inst = window.bootstrap.Collapse.getOrCreateInstance(body);
                    // Opening the menu: scroll to top so the navbar reappears and
                    // the interaction feels natural (it was hidden on scroll-down).
                    if (!body.classList.contains('show')) {
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }
                    inst.toggle();
                }
            });
        }
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () { selectTab(t.dataset.tab); });
    });
    backBtn.addEventListener('click', closeStack);

    bindIcons();
    neutralizeDropdownToggles();
    // Plugins (bellbored/textmebored) may add data-bs-toggle on DOMContentLoaded;
    // re-neutralize after that so the stack handles the icons, not Bootstrap.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', neutralizeDropdownToggles);
    } else {
        window.addEventListener('load', neutralizeDropdownToggles);
    }

    // Re-evaluate toggle neutralization when crossing the breakpoint.
    var wasMobile = isMobile();
    window.addEventListener('resize', function () {
        var now = isMobile();
        if (now !== wasMobile) {
            wasMobile = now;
            if (now) neutralizeDropdownToggles();
        }
    });
})();
