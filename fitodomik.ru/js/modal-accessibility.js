(() => {
    if (window.fitodomikModalAccessibility) return;
    window.fitodomikModalAccessibility = true;
    const previousFocus = new WeakMap();
    const open = modal => getComputedStyle(modal).display !== 'none';
    const focusable = modal => [...modal.querySelectorAll('button,input,select,textarea,a[href],[tabindex]')]
        .filter(element => !element.disabled && element.tabIndex >= 0 && element.getClientRects().length);
    document.querySelectorAll('.modal').forEach(modal => {
        modal.setAttribute('role', 'dialog'); modal.setAttribute('aria-modal', 'true'); modal.tabIndex = -1;
        const heading = modal.querySelector('h2,h3');
        if (heading) { heading.id ||= (modal.id || 'modal') + '-heading'; modal.setAttribute('aria-labelledby', heading.id); }
        let wasOpen = false;
        new MutationObserver(() => {
            const isOpen = open(modal);
            if (isOpen && !wasOpen) {
                previousFocus.set(modal, document.activeElement);
                (focusable(modal)[0] || modal).focus();
            } else if (!isOpen && wasOpen) {
                const previous = previousFocus.get(modal);
                if (previous?.isConnected) previous.focus();
            }
            wasOpen = isOpen;
        }).observe(modal, {attributes: true, attributeFilter: ['style', 'class', 'hidden']});
        modal.addEventListener('keydown', event => {
            if (!open(modal)) return;
            if (event.key === 'Escape') {
                event.preventDefault(); event.stopPropagation(); modal.style.display = 'none';
            } else if (event.key === 'Tab') {
                const items = focusable(modal), first = items[0], last = items[items.length-1];
                if (!items.length) { event.preventDefault(); modal.focus(); }
                else if (event.shiftKey && (document.activeElement === first || document.activeElement === modal)) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        });
    });
})();
