(function () {
    'use strict';

    function init() {
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
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
