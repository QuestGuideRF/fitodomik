(function () {
    const script = document.currentScript;
    const href = script && script.dataset ? script.dataset.stylesheet : '';
    if (!href) {
        return;
    }
    let loaded = false;
    function loadIconStyles() {
        if (loaded) {
            return;
        }
        loaded = true;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
    }
    const firstIconSection = document.querySelector('.value-grid, .feature-grid, .steps-grid, .audience-grid');
    if ('IntersectionObserver' in window && firstIconSection) {
        const observer = new IntersectionObserver(function (entries) {
            if (entries.some(function (entry) { return entry.isIntersecting; })) {
                observer.disconnect();
                loadIconStyles();
            }
        }, { rootMargin: '240px 0px' });
        observer.observe(firstIconSection);
    }
    ['pointerdown', 'keydown', 'scroll'].forEach(function (eventName) {
        window.addEventListener(eventName, loadIconStyles, { once: true, passive: true });
    });
}());
