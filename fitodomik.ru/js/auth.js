document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        const inputId = button.getAttribute('data-password-toggle');
        const input = inputId ? document.getElementById(inputId) : null;
        if (!(input instanceof HTMLInputElement)) {
            return;
        }
        button.addEventListener('click', () => {
            const shouldShow = input.type === 'password';
            input.type = shouldShow ? 'text' : 'password';
            button.textContent = shouldShow ? 'Скрыть' : 'Показать';
            button.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
            input.focus({ preventScroll: true });
        });
    });
    const password = document.querySelector('[data-password-primary]');
    const confirmation = document.querySelector('[data-password-confirmation]');
    const status = document.querySelector('[data-password-status]');
    if (!(password instanceof HTMLInputElement)
        || !(confirmation instanceof HTMLInputElement)
        || !(status instanceof HTMLElement)) {
        return;
    }
    const updatePasswordStatus = () => {
        if (confirmation.value === '') {
            status.textContent = 'Повторите пароль без изменений.';
            status.removeAttribute('data-state');
            confirmation.setCustomValidity('');
            return;
        }
        const matches = password.value === confirmation.value;
        status.textContent = matches ? 'Пароли совпадают.' : 'Пароли пока не совпадают.';
        status.setAttribute('data-state', matches ? 'success' : 'error');
        confirmation.setCustomValidity(matches ? '' : 'Пароли не совпадают');
    };
    password.addEventListener('input', updatePasswordStatus);
    confirmation.addEventListener('input', updatePasswordStatus);
});
