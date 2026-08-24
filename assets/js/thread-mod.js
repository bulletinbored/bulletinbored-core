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

        document.querySelectorAll('[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                var msg = form.getAttribute('data-confirm');
                if (!confirm(msg)) {
                    e.preventDefault();
                }
            });
        });

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
