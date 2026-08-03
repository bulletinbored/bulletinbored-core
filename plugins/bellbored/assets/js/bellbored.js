(function () {
    'use strict';

    var currentPage = 1;
    var isLoading = false;
    var navItem = null;
    var toggle = null;
    var unreadBadge = null;
    var dropdown = null;
    var bellItem = null;
    var actionsContainer = null;
    var emptyMsg = null;

    function escapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatTime(dateStr) {
        var date = new Date(dateStr);
        var now = new Date();
        var diff = Math.floor((now - date) / 1000);

        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    function closeDropdown() {
        if (dropdown) {
            dropdown.style.display = 'none';
        }
        document.removeEventListener('click', onDocClick);
    }

    function closeOtherDropdowns() {
        var otherDropdown = document.querySelector('.textmebored-dropdown');
        if (otherDropdown && otherDropdown.style.display !== 'none') {
            otherDropdown.style.display = 'none';
        }
    }

    function onDocClick(e) {
        if (navItem && !navItem.contains(e.target)) {
            closeDropdown();
        }
    }

    function renderNotifications(notifications, unreadCount) {
        if (!bellItem) return;

        if (unreadCount > 0) {
            unreadBadge.textContent = unreadCount > 99 ? '99+' : unreadCount;
            unreadBadge.style.display = 'inline';
        } else {
            unreadBadge.style.display = 'none';
        }

        if (!notifications || notifications.length === 0) {
            bellItem.innerHTML = '';
            bellItem.appendChild(emptyMsg);
            bellItem.appendChild(actionsContainer);
            return;
        }

        bellItem.innerHTML = '';

        notifications.forEach(function (n) {
            var item = document.createElement('li');
            item.className = 'bellbored-item' + (n.read == 0 ? ' unread' : '');
            item.setAttribute('data-id', n.id);

            var title = document.createElement('div');
            title.className = 'bellbored-item-title';
            title.textContent = n.title;

            var message = document.createElement('div');
            message.className = 'bellbored-item-message';
            message.textContent = n.message || '';

            var time = document.createElement('div');
            time.className = 'bellbored-item-time';
            time.textContent = formatTime(n.created_at);

            item.appendChild(title);
            item.appendChild(message);
            item.appendChild(time);

            if (n.link) {
                item.style.cursor = 'pointer';
                item.addEventListener('click', function (e) {
                    e.preventDefault();
                    closeDropdown();
                    if (n.read == 0) {
                        markRead(n.id);
                    }
                    window.location.href = n.link;
                });
            } else {
                item.style.cursor = 'default';
                if (n.read == 0) {
                    item.addEventListener('click', function () {
                        markRead(n.id);
                    });
                }
            }

            bellItem.appendChild(item);
        });

        bellItem.appendChild(actionsContainer);
    }

    function fetchNotifications() {
        if (isLoading) return;
        isLoading = true;

        var xhr = new XMLHttpRequest();
        xhr.open('GET', window.bellbored.apiUrl + '/list?page=' + currentPage, true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            isLoading = false;

            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        renderNotifications(data.notifications, data.unread_count);
                    }
                } catch (e) {
                    console.error('bellbored: failed to parse notifications', e);
                }
            }
        };
        xhr.send();
    }

    function markRead(id) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.bellbored.apiUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.status === 200) {
                fetchNotifications();
            }
        };
        xhr.send('action=mark_read&id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(window.bellbored.csrfToken || ''));
    }

    function markAllRead() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.bellbored.apiUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.status === 200) {
                fetchNotifications();
            }
        };
        xhr.send('action=mark_all_read&csrf_token=' + encodeURIComponent(window.bellbored.csrfToken || ''));
    }

    function createUI() {
        var userMenu = document.querySelector('.navbar-nav:last-child');
        if (!userMenu) return;

        navItem = document.createElement('li');
        navItem.className = 'nav-item bellbored-nav-item';

        toggle = document.createElement('a');
        toggle.className = 'nav-link';
        toggle.href = window.bellbored.baseUrl + '/notifications';
        toggle.innerHTML = '<i class="fas fa-bell me-1"></i><span class="badge bg-danger rounded-pill bellbored-unread-count" style="display:none;">0</span>';
        toggle.addEventListener('click', function(e) {
            if (dropdown && dropdown.style.display !== 'none') {
                closeDropdown();
                e.preventDefault();
            }
        });

        dropdown = document.createElement('ul');
        dropdown.className = 'dropdown-menu dropdown-menu-end bellbored-dropdown';
        dropdown.style.minWidth = '320px';
        dropdown.style.maxHeight = '400px';
        dropdown.style.overflowY = 'auto';
        dropdown.style.display = 'none';
        dropdown.innerHTML = '<li class="dropdown-header"><i class="fas fa-bell me-1"></i>Notifications</li><li><hr class="dropdown-divider"></li><li class="bellbored-empty-msg text-center text-muted py-3">No notifications yet</li>';

        navItem.appendChild(toggle);
        navItem.appendChild(dropdown);
        userMenu.appendChild(navItem);

        unreadBadge = toggle.querySelector('.bellbored-unread-count');
        bellItem = dropdown;
        emptyMsg = dropdown.querySelector('.bellbored-empty-msg');

        actionsContainer = document.createElement('li');
        actionsContainer.className = 'bellbored-actions';

        var viewAllBtn = document.createElement('a');
        viewAllBtn.className = 'btn btn-sm btn-outline-secondary';
        viewAllBtn.href = window.bellbored.baseUrl + '/notifications';
        viewAllBtn.textContent = 'View all';

        var markAllBtn = document.createElement('button');
        markAllBtn.className = 'btn btn-sm btn-outline-secondary';
        markAllBtn.textContent = 'Mark all read';
        markAllBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            markAllRead();
        });

        actionsContainer.appendChild(viewAllBtn);
        actionsContainer.appendChild(markAllBtn);

        dropdown.appendChild(actionsContainer);

        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (dropdown.style.display === 'none') {
                closeOtherDropdowns();
                dropdown.style.display = 'block';
                document.addEventListener('click', onDocClick);
                if (!bellItem.querySelector('.bellbored-item')) {
                    fetchNotifications();
                }
            } else {
                closeDropdown();
            }
        });

        fetchNotifications();
    }

    function init() {
        if (!document.getElementById('bellbored-nav-item')) {
            createUI();
        } else {
            navItem = document.getElementById('bellbored-nav-item');
            toggle = navItem.querySelector('.nav-link');
            dropdown = navItem.querySelector('.bellbored-dropdown');
            unreadBadge = navItem.querySelector('.bellbored-unread-count');
            bellItem = dropdown;
            emptyMsg = dropdown.querySelector('.bellbored-empty-msg');

            actionsContainer = document.createElement('li');
            actionsContainer.className = 'bellbored-actions';

            var viewAllBtn = document.createElement('a');
            viewAllBtn.className = 'btn btn-sm btn-outline-secondary';
            viewAllBtn.href = window.bellbored.baseUrl + '/notifications';
            viewAllBtn.textContent = 'View all';

            var markAllBtn = document.createElement('button');
            markAllBtn.className = 'btn btn-sm btn-outline-secondary';
            markAllBtn.textContent = 'Mark all read';
            markAllBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                markAllRead();
            });

            actionsContainer.appendChild(viewAllBtn);
            actionsContainer.appendChild(markAllBtn);

            dropdown.appendChild(actionsContainer);

            fetchNotifications();
        }
    }

    window.bellbored = window.bellbored || {};
    window.bellbored.init = init;
})();