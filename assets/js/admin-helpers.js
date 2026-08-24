(function () {
    'use strict';

    function init() {
        document.querySelectorAll('[data-toggle-target]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.getAttribute('data-toggle-target'));
                if (target) {
                    target.classList.toggle('d-none');
                }
            });
        });

        document.querySelectorAll('[data-close-target]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.getAttribute('data-close-target'));
                if (target) {
                    target.classList.add('d-none');
                }
            });
        });

        document.querySelectorAll('[data-confirm]').forEach(function (el) {
            var handler = function (e) {
                var msg = el.getAttribute('data-confirm');
                if (!confirm(msg)) {
                    e.preventDefault();
                }
            };
            if (el.tagName === 'FORM') {
                el.addEventListener('submit', handler);
            } else {
                el.addEventListener('click', handler);
            }
        });

        document.querySelectorAll('[data-suspend-form]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                var days = prompt(form.getAttribute('data-suspend-prompt'));
                if (days) {
                    form.querySelector('input[name=days]').value = days;
                } else {
                    e.preventDefault();
                }
            });
        });
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.getAttribute('data-warning-toggle'));
                if (target) {
                    var open = target.classList.toggle('d-none');
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
