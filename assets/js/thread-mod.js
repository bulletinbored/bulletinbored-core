(function () {
    'use strict';

    function init() {
        document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.getAttribute('data-modal-open'));
                if (target && typeof target.showModal === 'function') {
                    target.showModal();
                }
            });
        });

        document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.getAttribute('data-modal-close'));
                if (target && typeof target.close === 'function') {
                    target.close();
                }
            });
        });

        // data-confirm is handled by core-helpers.js (loaded in the shared
        // header) to avoid showing the same confirmation twice.

        // Inline composers are native <details> elements, so they open and
        // submit without JavaScript. This only wires the optional Cancel button.
        document.querySelectorAll('[data-reply-cancel]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var details = btn.closest('details');
                if (details) details.open = false;
            });
        });

        // Inline reply editors are upgraded only when the composer is opened,
        // so a thread with dozens of comments does not initialise dozens of
        // editbored instances at page load. The server marks the container with
        // editbored's own "already wrapped" class to keep it untouched until now.
        function ensureNestedEditor(details) {
            var slot = details.querySelector('[data-reply-editor]');
            if (!slot) return;

            var textarea = slot.querySelector('textarea');
            if (!textarea) return;

            if (slot.classList.contains('editbored-wrap')) {
                slot.classList.remove('editbored-wrap');
                if (window.editbored && typeof window.editbored.refresh === 'function') {
                    window.editbored.refresh();
                }
            }

            var target = slot.querySelector('.editbored-editor')
                || slot.querySelector('[contenteditable="true"]')
                || textarea;
            if (target && typeof target.focus === 'function') target.focus();
        }

        var nestedComposers = document.querySelectorAll('details.post-reply');
        nestedComposers.forEach(function (details) {
            details.addEventListener('toggle', function () {
                if (details.open) ensureNestedEditor(details);
            });
        });

        // Collapse a comment branch without hiding the comment itself.
        function applyCollapse(btn, collapsed) {
            var target = document.getElementById(btn.getAttribute('data-collapse-target'));
            if (!target) return;

            target.hidden = collapsed;
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

            var label = collapsed
                ? btn.getAttribute('data-expand-label')
                : btn.getAttribute('data-collapse-label');
            if (label) btn.setAttribute('title', label);

            var icon = btn.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-minus', !collapsed);
                icon.classList.toggle('fa-plus', collapsed);
            }

            var node = btn.closest('.comment-node');
            if (node) node.classList.toggle('comment-collapsed', collapsed);
        }

        document.querySelectorAll('[data-collapse-target]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                applyCollapse(btn, btn.getAttribute('aria-expanded') !== 'false');
            });
        });

        // Deep links (notifications, permalinks) must reveal the whole path to
        // the target even when intermediate branches are collapsed by default.
        function expandPathTo(element) {
            var node = element.closest('.comment-node');
            while (node) {
                if (node.classList.contains('comment-collapsed')) {
                    var btn = null;
                    node.querySelectorAll('[data-collapse-target]').forEach(function (candidate) {
                        if (!btn && candidate.closest('.comment-node') === node) {
                            btn = candidate;
                        }
                    });
                    if (btn) applyCollapse(btn, false);
                }
                var parent = node.parentElement;
                node = parent ? parent.closest('.comment-node') : null;
            }
        }

        var hashMatch = /^#post-(\d+)$/.exec(window.location.hash);
        if (hashMatch) {
            var hashTarget = document.getElementById('post-' + hashMatch[1]);
            if (hashTarget) {
                expandPathTo(hashTarget);
                setTimeout(function () {
                    hashTarget.scrollIntoView({ block: 'center' });
                }, 0);
            }
        }

        var splitForm = document.getElementById('split-form');
        if (splitForm) {
            splitForm.addEventListener('submit', function () {
                var ids = Array.from(document.querySelectorAll('.split-post-check:checked')).map(function (c) {
                    return c.value;
                }).join(',');
                document.getElementById('split-post-ids').value = ids;
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
