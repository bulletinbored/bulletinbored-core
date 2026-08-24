document.getElementById('sidebarToggleTop').addEventListener('click', function() {
    document.body.classList.toggle('sidebar-toggled');
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        sidebar.classList.toggle('toggled');
    }
});
