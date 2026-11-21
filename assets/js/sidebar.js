document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('sidebarToggleTop');
    var closeBtn = document.querySelector('.sidebar-close');
    var overlay = document.querySelector('.sidebar-overlay');
    var sidebar = document.querySelector('.sidebar');

    function isMobile() {
        return window.matchMedia('(max-width: 767.98px)').matches;
    }

    function syncExpanded() {
        if (toggle) {
            toggle.setAttribute('aria-expanded', document.body.classList.contains('sidebar-toggled') ? 'true' : 'false');
        }
    }

    function openSidebar() {
        document.body.classList.add('sidebar-toggled');
        syncExpanded();
    }

    function closeSidebar() {
        document.body.classList.remove('sidebar-toggled');
        syncExpanded();
    }

    if (toggle) {
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            if (document.body.classList.contains('sidebar-toggled')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', function (e) {
            e.preventDefault();
            closeSidebar();
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    if (sidebar) {
        sidebar.querySelectorAll('.sidebar-nav a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (isMobile()) {
                    closeSidebar();
                }
            });
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.body.classList.contains('sidebar-toggled')) {
            closeSidebar();
        }
    });

    window.addEventListener('resize', function () {
        if (!isMobile()) {
            closeSidebar();
        }
    });
});
