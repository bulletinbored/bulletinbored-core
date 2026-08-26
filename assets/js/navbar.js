(function () {
    const nav = document.querySelector('.navbar-forum');
    const tabbar = document.querySelector('.mobile-tabbar');
    if (!nav) return;

    let lastY = window.scrollY;
    let ticking = false;

    function update() {
        const y = window.scrollY;

        if (window.matchMedia('(max-width: 991.98px)').matches) {
            // Hide the navbar when scrolling down; show it again ONLY when back
            // at the very top of the page (not on any upward scroll).
            if (y > 80 && y > lastY) {
                nav.classList.add('nav-hidden');
                if (tabbar) tabbar.classList.add('tabbar-top');
                document.body.classList.add('tabbar-pinned');
            } else if (y <= 2) {
                nav.classList.remove('nav-hidden');
                if (tabbar) tabbar.classList.remove('tabbar-top');
                document.body.classList.remove('tabbar-pinned');
            }
        } else {
            nav.classList.remove('nav-hidden');
            if (tabbar) tabbar.classList.remove('tabbar-top');
            document.body.classList.remove('tabbar-pinned');
        }

        nav.classList.toggle('scrolled', y > 20);
        lastY = y;
        ticking = false;
    }

    window.addEventListener('scroll', () => {
        if (!ticking) {
            window.requestAnimationFrame(update);
            ticking = true;
        }
    }, { passive: true });
})();
