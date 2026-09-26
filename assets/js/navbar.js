(function () {
    const nav = document.querySelector('.navbar-forum');
    const tabbar = document.querySelector('.mobile-tabbar');
    if (!nav) return;

    const TOP_REVEAL = 80; // Always show the navbar above this offset.
    const DELTA = 8;       // Ignore micro scroll jitter (px) to avoid flicker.

    let lastY = window.scrollY;
    let ticking = false;
    let navHidden = false;

    function setHidden(hidden) {
        if (hidden === navHidden) return;
        navHidden = hidden;
        nav.classList.toggle('nav-hidden', hidden);
        if (tabbar) tabbar.classList.toggle('tabbar-top', hidden);
        document.body.classList.toggle('tabbar-pinned', hidden);
    }

    function update() {
        const y = window.scrollY;

        if (window.matchMedia('(max-width: 991.98px)').matches) {
            if (y <= TOP_REVEAL) {
                // Near the top the navbar is always visible.
                setHidden(false);
                lastY = y;
            } else {
                const delta = y - lastY;
                // Only react once the accumulated movement exceeds the dead-zone.
                if (delta > DELTA) {
                    setHidden(true);
                    lastY = y;
                } else if (delta < -DELTA) {
                    setHidden(false);
                    lastY = y;
                }
            }
        } else {
            setHidden(false);
            lastY = y;
        }

        nav.classList.toggle('scrolled', y > 20);
        ticking = false;
    }

    window.addEventListener('scroll', () => {
        if (!ticking) {
            window.requestAnimationFrame(update);
            ticking = true;
        }
    }, { passive: true });
})();
