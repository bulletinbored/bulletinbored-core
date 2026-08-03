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
        var otherDropdown = document.querySelector('.bellbored-dropdown');
        if (otherDropdown && otherDropdown.style.display !== 'none') {
            otherDropdown.style.display = 'none';
        }
    }

    function onDocClick(e) {
        if (navItem && !navItem.contains(e.target)) {
            closeDropdown();
        }
    }

    function renderConversations(conversations) {
        if (!bellItem) return;

        if (!conversations || conversations.length === 0) {
            bellItem.innerHTML = '';
            bellItem.appendChild(emptyMsg);
            bellItem.appendChild(actionsContainer);
            unreadBadge.style.display = 'none';
            return;
        }

        var unreadCount = 0;
        conversations.forEach(function (c) {
            unreadCount += parseInt(c.unread_count || 0, 10);
        });

        if (unreadCount > 0) {
            unreadBadge.textContent = unreadCount > 99 ? '99+' : unreadCount;
            unreadBadge.style.display = 'inline';
        } else {
            unreadBadge.style.display = 'none';
        }

        bellItem.innerHTML = '';

        conversations.forEach(function (c) {
            var item = document.createElement('li');
            item.className = 'textmebored-item' + (c.unread_count > 0 ? ' unread' : '');
            item.setAttribute('data-user-id', c.other_user_id);

            var title = document.createElement('div');
            title.className = 'textmebored-item-title';
            title.textContent = c.other_username || 'Unknown';

            var message = document.createElement('div');
            message.className = 'textmebored-item-message';
            message.textContent = c.last_message || '';

            var time = document.createElement('div');
            time.className = 'textmebored-item-time';
            time.textContent = formatTime(c.last_message_at);

            item.appendChild(title);
            item.appendChild(message);
            item.appendChild(time);

            item.style.cursor = 'pointer';
            item.addEventListener('click', function () {
                closeDropdown();
                openConversation(c.other_user_id, c.other_username);
            });

            bellItem.appendChild(item);
        });

        bellItem.appendChild(actionsContainer);
    }

    function fetchConversations() {
        if (isLoading) return;
        isLoading = true;

        var xhr = new XMLHttpRequest();
        xhr.open('GET', window.textmebored.apiUrl + '?action=conversations', true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            isLoading = false;

            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        renderConversations(data.conversations);
                    }
                } catch (e) {
                    console.error('textmebored: failed to parse conversations', e);
                }
            }
        };
        xhr.send();
    }

    function fetchMessages(userId) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', window.textmebored.apiUrl + '?action=messages&user_id=' + encodeURIComponent(userId), true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;

            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        renderConversationModal(data.messages, userId);
                    }
                } catch (e) {
                    console.error('textmebored: failed to parse messages', e);
                }
            }
        };
        xhr.send();
    }

    function renderConversationModal(messages, otherUserId) {
        var existing = document.getElementById('textmebored-conversation-modal');
        if (existing) {
            existing.remove();
        }

        var modal = document.createElement('div');
        modal.id = 'textmebored-conversation-modal';
        modal.className = 'textmebored-modal-overlay';

        var modalHtml = '<div class="textmebored-modal">';
        modalHtml += '<div class="textmebored-modal-header">';
        modalHtml += '<span class="textmebored-modal-title">Conversation</span>';
        modalHtml += '<button class="textmebored-modal-close">&times;</button>';
        modalHtml += '</div>';
        modalHtml += '<div class="textmebored-modal-messages">';

        if (!messages || messages.length === 0) {
            modalHtml += '<div class="text-center text-muted py-4">No messages yet. Start the conversation!</div>';
        } else {
            messages.forEach(function (m) {
                var isMe = m.sender_id == window.textmebored.currentUserId;
                modalHtml += '<div class="textmebored-message ' + (isMe ? 'textmebored-message--me' : 'textmebored-message--other') + '">';
                modalHtml += '<div class="textmebored-message-content">' + escapeHtml(m.content) + '</div>';
                modalHtml += '<div class="textmebored-message-time">' + formatTime(m.created_at) + '</div>';
                modalHtml += '</div>';
            });
        }

        modalHtml += '</div>';
        modalHtml += '<div class="textmebored-modal-input">';
        modalHtml += '<form class="textmebored-reply-form">';
        modalHtml += '<input type="hidden" name="csrf_token" value="' + escapeHtml(window.textmebored.csrfToken || '') + '">';
        modalHtml += '<input type="hidden" name="action" value="send">';
        modalHtml += '<input type="hidden" name="recipient_id" value="' + otherUserId + '">';
        modalHtml += '<input type="text" name="content" class="textmebored-reply-input" placeholder="Type a message..." required autocomplete="off">';
        modalHtml += '<button type="submit" class="textmebored-reply-btn"><i class="fas fa-paper-plane"></i></button>';
        modalHtml += '</form>';
        modalHtml += '</div>';
        modalHtml += '</div>';

        modal.innerHTML = modalHtml;
        document.body.appendChild(modal);

        var closeBtn = modal.querySelector('.textmebored-modal-close');
        closeBtn.addEventListener('click', function () {
            modal.remove();
            fetchConversations();
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                modal.remove();
                fetchConversations();
            }
        });

        var replyForm = modal.querySelector('.textmebored-reply-form');
        replyForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var input = replyForm.querySelector('input[name="content"]');
            var content = input.value.trim();
            if (!content) return;

            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.textmebored.apiUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    input.value = '';
                    fetchMessages(otherUserId);
                }
            };
            xhr.send('action=send&recipient_id=' + encodeURIComponent(otherUserId) + '&content=' + encodeURIComponent(content) + '&csrf_token=' + encodeURIComponent(window.textmebored.csrfToken || ''));
        });

        var messagesContainer = modal.querySelector('.textmebored-modal-messages');
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function newConversation() {
        var existing = document.getElementById('textmebored-compose-modal');
        if (existing) {
            existing.remove();
        }

        var modal = document.createElement('div');
        modal.id = 'textmebored-compose-modal';
        modal.className = 'textmebored-modal-overlay';

        var modalHtml = '<div class="textmebored-modal">';
        modalHtml += '<div class="textmebored-modal-header">';
        modalHtml += '<span class="textmebored-modal-title">New Message</span>';
        modalHtml += '<button class="textmebored-modal-close">&times;</button>';
        modalHtml += '</div>';
        modalHtml += '<div class="textmebored-modal-input" style="padding:16px;">';
        modalHtml += '<form class="textmebored-compose-form">';
        modalHtml += '<input type="hidden" name="csrf_token" value="' + escapeHtml(window.textmebored.csrfToken || '') + '">';
        modalHtml += '<input type="hidden" name="action" value="send">';
        modalHtml += '<div class="mb-3"><label class="form-label">To (username)</label><input type="text" name="to_username" class="form-control" placeholder="Enter username" required autocomplete="off"></div>';
        modalHtml += '<div class="mb-3"><label class="form-label">Message</label><input type="text" name="content" class="form-control" placeholder="Type a message..." required autocomplete="off"></div>';
        modalHtml += '<button type="submit" class="btn btn-forum btn-sm"><i class="fas fa-paper-plane me-1"></i>Send</button>';
        modalHtml += '</form>';
        modalHtml += '</div>';
        modalHtml += '</div>';

        modal.innerHTML = modalHtml;
        document.body.appendChild(modal);

        var closeBtn = modal.querySelector('.textmebored-modal-close');
        closeBtn.addEventListener('click', function () {
            modal.remove();
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                modal.remove();
            }
        });

        var composeForm = modal.querySelector('.textmebored-compose-form');
        composeForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var usernameInput = composeForm.querySelector('input[name="to_username"]');
            var contentInput = composeForm.querySelector('input[name="content"]');
            var username = usernameInput.value.trim();
            var content = contentInput.value.trim();
            if (!username || !content) return;

            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.textmebored.apiUrl + '?action=resolve_user', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            modal.remove();
                            openConversation(data.user_id, username);
                        } else {
                            alert(data.error || 'User not found');
                        }
                    } catch (err) {
                        alert('Error resolving user');
                    }
                }
            };
            xhr.send('username=' + encodeURIComponent(username) + '&csrf_token=' + encodeURIComponent(window.textmebored.csrfToken || ''));
        });
    }

    function openConversation(userId, username) {
        fetchMessages(userId);
    }

    function createUI() {
        var userMenu = document.querySelector('.navbar-nav:last-child');
        if (!userMenu) return;

        navItem = document.createElement('li');
        navItem.className = 'nav-item textmebored-nav-item';

        toggle = document.createElement('a');
        toggle.className = 'nav-link';
        toggle.href = '?action=messages';
        toggle.innerHTML = '<i class="fas fa-envelope me-1"></i><span class="badge bg-danger rounded-pill textmebored-unread-count" style="display:none;">0</span>';
        toggle.addEventListener('click', function(e) {
            if (dropdown && dropdown.style.display !== 'none') {
                closeDropdown();
                e.preventDefault();
            }
        });

        dropdown = document.createElement('ul');
        dropdown.className = 'dropdown-menu dropdown-menu-end textmebored-dropdown show';
        dropdown.style.minWidth = '320px';
        dropdown.style.maxHeight = '400px';
        dropdown.style.overflowY = 'auto';
        dropdown.style.display = 'none';
        dropdown.innerHTML = '<li class="dropdown-header"><i class="fas fa-envelope me-1"></i>Messages</li><li><hr class="dropdown-divider"></li><li class="textmebored-empty-msg text-center text-muted py-3">No messages yet</li>';

        navItem.appendChild(toggle);
        navItem.appendChild(dropdown);
        userMenu.appendChild(navItem);

        unreadBadge = toggle.querySelector('.textmebored-unread-count');
        bellItem = dropdown;
        emptyMsg = dropdown.querySelector('.textmebored-empty-msg');

        actionsContainer = document.createElement('li');
        actionsContainer.className = 'textmebored-actions';

        var viewMessagesBtn = document.createElement('a');
        viewMessagesBtn.className = 'btn btn-sm btn-outline-secondary';
        viewMessagesBtn.href = '?action=messages';
        viewMessagesBtn.textContent = 'View messages';

        actionsContainer.appendChild(viewMessagesBtn);

        dropdown.appendChild(actionsContainer);

        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (dropdown.style.display === 'none') {
                closeOtherDropdowns();
                dropdown.style.display = 'block';
                document.addEventListener('click', onDocClick);
                if (!bellItem.querySelector('.textmebored-item')) {
                    fetchConversations();
                }
            } else {
                closeDropdown();
            }
        });

        fetchConversations();
    }

    function init() {
        if (!document.getElementById('textmebored-nav-item')) {
            createUI();
        } else {
            navItem = document.getElementById('textmebored-nav-item');
            toggle = navItem.querySelector('.nav-link');
            dropdown = navItem.querySelector('.textmebored-dropdown');
            unreadBadge = navItem.querySelector('.textmebored-unread-count');
            bellItem = dropdown;
            emptyMsg = dropdown.querySelector('.textmebored-empty-msg');

            actionsContainer = document.createElement('li');
            actionsContainer.className = 'textmebored-actions';

            var viewMessagesBtn = document.createElement('a');
            viewMessagesBtn.className = 'btn btn-sm btn-outline-secondary';
            viewMessagesBtn.href = '?action=messages';
            viewMessagesBtn.textContent = 'View messages';

            actionsContainer.appendChild(viewMessagesBtn);

            dropdown.appendChild(actionsContainer);

            fetchConversations();
        }

        var newMsgLink = document.getElementById('textmebored-new-msg');
        if (newMsgLink) {
            newMsgLink.addEventListener('click', function (e) {
                e.preventDefault();
                newConversation();
            });
        }
    }

    window.textmebored = window.textmebored || {};
    window.textmebored.newConversation = newConversation;
    window.textmebored.openConversation = openConversation;
    window.textmebored.init = init;
})();