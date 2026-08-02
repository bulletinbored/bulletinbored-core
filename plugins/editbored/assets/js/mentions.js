(function() {
    'use strict';

    const USERS = (window.editbored && window.editbored.users) ? window.editbored.users : [];
    let dropdown = null;
    let activeIndex = -1;
    let currentMatches = [];
    let currentTextarea = null;
    let mentionStart = -1;

    function createDropdown() {
        if (dropdown) return dropdown;
        dropdown = document.createElement('div');
        dropdown.className = 'editbored-mentions-dropdown';
        document.body.appendChild(dropdown);
        return dropdown;
    }

    function showDropdown(ta, rect) {
        const dd = createDropdown();
        dd.innerHTML = '';
        currentMatches = [];
        activeIndex = -1;
        if (!USERS.length) {
            dd.style.display = 'none';
            return;
        }
        const query = ta.value.substring(mentionStart + 1).toLowerCase().split(/\s/)[0];
        const filtered = USERS.filter(function(u) {
            return u.username.toLowerCase().indexOf(query) !== -1;
        }).slice(0, 10);

        if (!filtered.length) {
            dd.style.display = 'none';
            currentMatches = [];
            return;
        }

        currentMatches = filtered;
        filtered.forEach(function(user, i) {
            const item = document.createElement('div');
            item.className = 'editbored-mention-item';
            item.textContent = user.username;
            item.addEventListener('mousedown', function(e) {
                e.preventDefault();
                insertMention(ta, user.username);
            });
            dd.appendChild(item);
        });

        dd.style.display = 'block';
        dd.style.left = rect.left + 'px';
        dd.style.top = (rect.bottom + 4) + 'px';
        dd.style.minWidth = rect.width + 'px';
        activeIndex = -1;
    }

    function hideDropdown() {
        if (dropdown) {
            dropdown.style.display = 'none';
            currentMatches = [];
            activeIndex = -1;
        }
    }

    function insertMention(ta, username) {
        hideDropdown();
        const before = ta.value.substring(0, mentionStart);
        const after = ta.value.substring(ta.selectionEnd);
        ta.value = before + '@' + username + ' ' + after;
        const pos = before.length + username.length + 2;
        ta.selectionStart = pos;
        ta.selectionEnd = pos;
        ta.focus();
        ta.dispatchEvent(new Event('input'));
    }

    function highlightActive() {
        if (!dropdown || !currentMatches.length) return;
        const items = dropdown.querySelectorAll('.editbored-mention-item');
        items.forEach(function(el, i) {
            if (i === activeIndex) {
                el.classList.add('active');
            } else {
                el.classList.remove('active');
            }
        });
    }

    document.addEventListener('input', function(e) {
        const ta = e.target;
        if (!ta || ta.tagName !== 'TEXTAREA') return;
        if (!ta.closest('.editbored-wrap')) return;

        const pos = ta.selectionStart;
        const textBefore = ta.value.substring(0, pos);
        const atPos = textBefore.lastIndexOf('@');

        if (atPos !== -1 && (atPos === 0 || /[\s\n]/.test(textBefore.charAt(atPos - 1)))) {
            mentionStart = atPos;
            const rect = ta.getBoundingClientRect();
            showDropdown(ta, rect);
        } else {
            hideDropdown();
        }
    }, true);

    document.addEventListener('keydown', function(e) {
        if (!dropdown || dropdown.style.display === 'none') return;
        if (!currentMatches.length) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, currentMatches.length - 1);
            highlightActive();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            highlightActive();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIndex >= 0 && activeIndex < currentMatches.length) {
                const ta = document.activeElement;
                if (ta && ta.tagName === 'TEXTAREA' && ta.closest('.editbored-wrap')) {
                    insertMention(ta, currentMatches[activeIndex].username);
                }
            }
        } else if (e.key === 'Escape') {
            hideDropdown();
        }
    });

    document.addEventListener('scroll', function() {
        if (dropdown && dropdown.style.display !== 'none') {
            dropdown.style.display = 'none';
        }
    }, true);

    document.addEventListener('click', function(e) {
        if (dropdown && !dropdown.contains(e.target)) {
            hideDropdown();
        }
    });

})();
