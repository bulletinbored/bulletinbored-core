(function () {
    const nav = document.querySelector('.navbar-forum');
    const tabbar = document.querySelector('.mobile-tabbar');
    if (!nav) return;

    let lastY = window.scrollY;
    let ticking = false;
    let scrollingDown = false;

    function update() {
        const y = window.scrollY;

        if (window.matchMedia('(max-width: 991.98px)').matches) {
            // Hide navbar when scrolling down (past 80px), show on scroll up.
            if (y > 80 && y > lastY) {
                if (!scrollingDown) {
                    nav.classList.add('nav-hidden');
                    if (tabbar) tabbar.classList.add('tabbar-top');
                    document.body.classList.add('tabbar-pinned');
                    scrollingDown = true;
                }
            } else if (y < lastY) {
                if (scrollingDown) {
                    nav.classList.remove('nav-hidden');
                    if (tabbar) tabbar.classList.remove('tabbar-top');
                    document.body.classList.remove('tabbar-pinned');
                    scrollingDown = false;
                }
            }
        } else {
            nav.classList.remove('nav-hidden');
            if (tabbar) tabbar.classList.remove('tabbar-top');
            document.body.classList.remove('tabbar-pinned');
            scrollingDown = false;
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
