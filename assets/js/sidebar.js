document.addEventListener('DOMContentLoaded', function() {
    var toggle = document.getElementById('sidebarToggleTop');
    var closeBtn = document.querySelector('.sidebar-close');
    var overlay = document.querySelector('.sidebar-overlay');

    function closeSidebar() {
        document.body.classList.remove('sidebar-toggled');
    }

    if (toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            document.body.classList.toggle('sidebar-toggled');
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            closeSidebar();
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }
});
