document.addEventListener('DOMContentLoaded', () => {
    const dataNode = document.getElementById('verification-data');
    const status = document.getElementById('verification-status');
    const statusText = status?.querySelector('[data-status-text]');
    const copyButton = document.querySelector('[data-copy-target]');
    const copyResult = document.querySelector('[data-copy-result]');
    const telegramButton = document.querySelector('[data-telegram-open]');
    if (!(dataNode instanceof HTMLElement)
        || !(status instanceof HTMLElement)
        || !(statusText instanceof HTMLElement)) {
        return;
    }
    const checkUrl = dataNode.dataset.checkUrl || '/api/check_verification.php';
    const redirectUrl = dataNode.dataset.redirect || '/farm.php';
    let stopped = false;
    let requestInProgress = false;
    let checkTimer = null;
    let consecutiveFailures = 0;
    const setStatus = (message, state = 'waiting') => {
        status.dataset.state = state;
        statusText.textContent = message;
    };
    const scheduleCheck = (delay = 4000) => {
        if (checkTimer !== null) {
            window.clearTimeout(checkTimer);
        }
        if (!stopped) {
            checkTimer = window.setTimeout(checkVerification, delay);
        }
    };
    const checkVerification = async () => {
        if (stopped || requestInProgress) {
            return;
        }
        requestInProgress = true;
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(checkUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            const data = await response.json().catch(() => ({}));
            if (data.verified === true) {
                stopped = true;
                setStatus('Telegram подтверждён. Открываем кабинет...', 'success');
                window.setTimeout(() => {
                    window.location.assign(redirectUrl);
                }, 900);
                return;
            }
            const message = typeof data.message === 'string'
                ? data.message
                : 'Подтверждение пока не получено';
            if (data.terminal === true) {
                stopped = true;
                setStatus(message, 'error');
                if (data.can_continue_without_telegram === true) {
                    document.querySelector('.telegram-skip-button')?.focus();
                }
                return;
            }
            if (!response.ok) {
                consecutiveFailures += 1;
                setStatus('Не удалось проверить статус. Повторяем...', 'error');
                scheduleCheck(Math.min(30000, 4000 * (2 ** consecutiveFailures)));
                return;
            }
            consecutiveFailures = 0;
            setStatus(message, 'waiting');
            scheduleCheck();
        } catch {
            consecutiveFailures += 1;
            setStatus('Нет связи с сайтом. Повторяем проверку...', 'error');
            scheduleCheck(Math.min(30000, 4000 * (2 ** consecutiveFailures)));
        } finally {
            window.clearTimeout(timeout);
            requestInProgress = false;
        }
    };
    const copyText = async (text) => {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return;
        }
        const input = document.createElement('textarea');
        input.value = text;
        input.setAttribute('readonly', '');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        const copied = document.execCommand('copy');
        input.remove();
        if (!copied) {
            throw new Error('copy failed');
        }
    };
    const selectElementText = (element) => {
        const selection = window.getSelection();
        if (!selection) {
            return false;
        }
        const range = document.createRange();
        range.selectNodeContents(element);
        selection.removeAllRanges();
        selection.addRange(range);
        return true;
    };
    copyButton?.addEventListener('click', async () => {
        const targetId = copyButton.getAttribute('data-copy-target');
        const target = targetId ? document.getElementById(targetId) : null;
        if (!(target instanceof HTMLElement)) {
            return;
        }
        try {
            await copyText(target.textContent?.trim() || '');
            copyButton.textContent = 'Скопировано';
            if (copyResult instanceof HTMLElement) {
                copyResult.textContent = 'Команда готова. Вставьте её в сообщение боту.';
            }
        } catch {
            const selected = selectElementText(target);
            copyButton.textContent = selected ? 'Выделено' : 'Скопировать';
            if (copyResult instanceof HTMLElement) {
                copyResult.textContent = selected
                    ? 'Команда выделена. Нажмите Ctrl+C или выберите «Копировать».'
                    : 'Не удалось скопировать автоматически. Выделите команду вручную.';
            }
        }
    });
    telegramButton?.addEventListener('click', () => {
        setStatus('Telegram открыт. Подтвердите запуск бота.', 'active');
    });
    checkVerification();
});
