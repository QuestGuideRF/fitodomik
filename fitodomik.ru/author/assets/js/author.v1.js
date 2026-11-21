(() => {
    const page = document.querySelector('.author-page');
    if (!(page instanceof HTMLElement)) {
        return;
    }
    const items = Array.from(page.querySelectorAll('[data-author-reveal]'));
    if (!items.length || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        items.forEach((item) => item.classList.add('is-visible'));
        return;
    }
    page.classList.add('author-motion-ready');
    if (!('IntersectionObserver' in window)) {
        items.forEach((item) => item.classList.add('is-visible'));
        return;
    }
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
    requestAnimationFrame(() => items.forEach((item) => observer.observe(item)));
})();
