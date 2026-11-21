(function () {
    'use strict';
    const MAX_UPLOAD_BYTES = 6 * 1024 * 1024;
    const MAX_CAPTURE_SIDE = 1800;
    const ALLOWED_IMAGE_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);
    const PAIR_CODE_PATTERN = /^[a-f0-9]{48}$/i;
    let qrStream = null;
    let activeTrigger = null;
    let requestController = null;
    let previewUrl = '';
    let cameraRequestId = 0;
    let previousBodyOverflow = '';
    function createModal() {
        const modal = document.createElement('div');
        modal.className = 'qr-scanner-modal';
        modal.hidden = true;
        modal.innerHTML = `
            <section class="qr-scanner-dialog" role="dialog" aria-modal="true" aria-labelledby="qrScannerTitle" tabindex="-1">
                <div class="qr-scanner-head">
                    <h2 id="qrScannerTitle">Сканирование QR-кода</h2>
                    <button class="qr-scanner-close" type="button" aria-label="Закрыть сканирование">&times;</button>
                </div>
                <div class="qr-scanner-body">
                    <p id="qrScannerHelp" class="qr-scanner-help">Наведите камеру на QR-код устройства и сделайте фотографию.</p>
                    <div class="qr-scanner-preview" data-qr-preview>
                        <video playsinline autoplay muted aria-describedby="qrScannerHelp"></video>
                        <img data-qr-photo alt="Предпросмотр фотографии QR-кода" hidden>
                        <canvas aria-hidden="true" hidden></canvas>
                        <div class="qr-scanner-frame" aria-hidden="true"></div>
                        <div class="qr-scanner-loading" role="status" hidden>Открываем камеру...</div>
                    </div>
                    <output class="qr-scanner-status" aria-live="polite" aria-atomic="true">Открываем камеру...</output>
                    <div class="qr-scanner-actions">
                        <button class="qr-scanner-button primary" type="button" data-qr-capture disabled>Сделать фото</button>
                        <button class="qr-scanner-button" type="button" data-qr-retake hidden>Переснять</button>
                        <button class="qr-scanner-button" type="button" data-qr-restart hidden>Включить камеру</button>
                        <label class="qr-scanner-button qr-file-button">
                            Выбрать фотографию
                            <input class="qr-file-input" type="file" accept="image/png,image/jpeg,image/webp" capture="environment" data-qr-file>
                        </label>
                        <button class="qr-scanner-button" type="button" data-qr-close>Закрыть</button>
                    </div>
                </div>
            </section>
        `;
        document.body.appendChild(modal);
        return modal;
    }
    const modal = createModal();
    const dialog = modal.querySelector('.qr-scanner-dialog');
    const video = modal.querySelector('video');
    const canvas = modal.querySelector('canvas');
    const photo = modal.querySelector('[data-qr-photo]');
    const frame = modal.querySelector('.qr-scanner-frame');
    const loading = modal.querySelector('.qr-scanner-loading');
    const status = modal.querySelector('.qr-scanner-status');
    const captureButton = modal.querySelector('[data-qr-capture]');
    const retakeButton = modal.querySelector('[data-qr-retake]');
    const restartButton = modal.querySelector('[data-qr-restart]');
    const fileInput = modal.querySelector('[data-qr-file]');
    function setStatus(message, state) {
        status.textContent = message;
        status.dataset.state = state || '';
    }
    function setLoading(isLoading) {
        loading.hidden = !isLoading;
        captureButton.disabled = isLoading || !qrStream;
        fileInput.disabled = isLoading;
    }
    function stopQrCamera(updateStatus) {
        cameraRequestId += 1;
        if (qrStream) {
            qrStream.getTracks().forEach((track) => track.stop());
            qrStream = null;
        }
        video.pause();
        video.srcObject = null;
        captureButton.disabled = true;
        if (updateStatus) {
            setStatus('Камера остановлена. Можно выбрать фотографию с устройства.');
        }
    }
    function clearPreview() {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = '';
        }
        photo.removeAttribute('src');
        photo.hidden = true;
        video.hidden = false;
        frame.hidden = false;
        retakeButton.hidden = true;
        canvas.width = 0;
        canvas.height = 0;
    }
    function showPhotoPreview(blob) {
        clearPreview();
        previewUrl = URL.createObjectURL(blob);
        photo.src = previewUrl;
        photo.hidden = false;
        video.hidden = true;
        frame.hidden = true;
        retakeButton.hidden = false;
    }
    function cameraErrorMessage(error) {
        if (!window.isSecureContext) {
            return 'Камера доступна только через защищённое HTTPS-соединение.';
        }
        if (error && error.name === 'NotAllowedError') {
            return 'Доступ к камере запрещён. Разрешите использование камеры в настройках браузера или выберите фотографию QR-кода.';
        }
        if (error && (error.name === 'NotFoundError' || error.name === 'OverconstrainedError')) {
            return 'Камера не найдена. Выберите фотографию QR-кода с устройства.';
        }
        if (error && (error.name === 'NotReadableError' || error.name === 'AbortError')) {
            return 'Не удалось открыть камеру. Возможно, её использует другое приложение.';
        }
        return 'Не удалось открыть камеру. Выберите фотографию QR-кода с устройства.';
    }
    async function startQrCamera() {
        stopQrCamera(false);
        clearPreview();
        restartButton.hidden = true;
        fileInput.disabled = false;
        if (!window.isSecureContext) {
            setStatus(cameraErrorMessage(null), 'error');
            return;
        }
        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
            setStatus('Камера не найдена. Выберите фотографию QR-кода с устройства.', 'error');
            return;
        }
        const requestId = cameraRequestId;
        setLoading(true);
        setStatus('Запрашиваем доступ к камере...');
        try {
            const newStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                },
                audio: false
            });
            if (modal.hidden || requestId !== cameraRequestId) {
                newStream.getTracks().forEach((track) => track.stop());
                return;
            }
            qrStream = newStream;
            video.srcObject = qrStream;
            await video.play();
            if (modal.hidden || requestId !== cameraRequestId) {
                stopQrCamera(false);
                return;
            }
            captureButton.disabled = video.videoWidth < 1 || video.videoHeight < 1;
            setStatus('Наведите камеру на QR-код и нажмите «Сделать фото».');
        } catch (error) {
            stopQrCamera(false);
            restartButton.hidden = false;
            setStatus(cameraErrorMessage(error), 'error');
        } finally {
            loading.hidden = true;
            fileInput.disabled = false;
        }
    }
    function canvasToBlob() {
        return new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                if (blob) {
                    resolve(blob);
                    return;
                }
                reject(new Error('CAPTURE_FAILED'));
            }, 'image/jpeg', 0.9);
        });
    }
    async function captureQrPhoto() {
        const sourceWidth = video.videoWidth;
        const sourceHeight = video.videoHeight;
        if (!qrStream || sourceWidth < 1 || sourceHeight < 1) {
            setStatus('Камера ещё не готова. Подождите и повторите снимок.', 'error');
            return;
        }
        captureButton.disabled = true;
        const scale = Math.min(1, MAX_CAPTURE_SIDE / Math.max(sourceWidth, sourceHeight));
        canvas.width = Math.max(1, Math.round(sourceWidth * scale));
        canvas.height = Math.max(1, Math.round(sourceHeight * scale));
        const context = canvas.getContext('2d', { alpha: false });
        if (!context) {
            setStatus('Не удалось сделать фотографию. Повторите попытку.', 'error');
            captureButton.disabled = false;
            return;
        }
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        try {
            const blob = await canvasToBlob();
            stopQrCamera(false);
            showPhotoPreview(blob);
            await recognizeQrImage(blob, 'qr-scan.jpg');
        } catch (error) {
            setStatus('Не удалось сделать фотографию. Повторите попытку.', 'error');
            retakeButton.hidden = false;
        }
    }
    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) {
            return meta.content;
        }
        const input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }
    function safePairRedirect(value) {
        try {
            const url = new URL(String(value || ''), window.location.origin);
            const code = url.searchParams.get('code') || '';
            if (url.origin !== window.location.origin || url.pathname !== '/device-link.php'
                || !PAIR_CODE_PATTERN.test(code)) {
                return '';
            }
            return url.pathname + '?code=' + encodeURIComponent(code);
        } catch (error) {
            return '';
        }
    }
    async function recognizeQrImage(blob, filename) {
        if (!csrfToken()) {
            setStatus('Сессия устарела. Обновите страницу и повторите попытку.', 'error');
            return;
        }
        if (requestController) {
            requestController.abort();
        }
        requestController = new AbortController();
        captureButton.disabled = true;
        retakeButton.disabled = true;
        fileInput.disabled = true;
        setStatus('Распознаём QR-код...');
        const formData = new FormData();
        formData.append('qr_image', blob, filename);
        formData.append('csrf_token', csrfToken());
        try {
            const response = await fetch('/authentication/api/qr-recognize.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: requestController.signal
            });
            const payload = await response.json().catch(() => null);
            if (!response.ok || !payload || payload.success !== true) {
                const fallback = response.status >= 500
                    ? 'Не удалось обработать фотографию. Повторите попытку позже.'
                    : 'QR-код на фотографии не найден. Наведите камеру ближе и повторите снимок.';
                setStatus(payload && payload.message ? payload.message : fallback, 'error');
                return;
            }
            const redirect = safePairRedirect(payload.result && payload.result.redirect);
            if (!redirect) {
                setStatus('Не удалось продолжить привязку. Повторите попытку позже.', 'error');
                return;
            }
            setStatus(payload.message || 'QR-код успешно распознан.', 'success');
            stopQrCamera(false);
            window.setTimeout(() => window.location.assign(redirect), 600);
        } catch (error) {
            if (error.name !== 'AbortError') {
                setStatus('Не удалось обработать фотографию. Проверьте соединение и повторите попытку.', 'error');
            }
        } finally {
            requestController = null;
            retakeButton.disabled = false;
            fileInput.disabled = false;
        }
    }
    async function handleSelectedFile() {
        const file = fileInput.files && fileInput.files[0];
        fileInput.value = '';
        if (!file) {
            return;
        }
        if (!ALLOWED_IMAGE_TYPES.has(file.type)) {
            setStatus('Разрешены только фотографии JPEG, PNG и WebP.', 'error');
            return;
        }
        if (file.size < 1 || file.size > MAX_UPLOAD_BYTES) {
            setStatus('Размер фотографии не должен превышать 6 МБ.', 'error');
            return;
        }
        stopQrCamera(false);
        showPhotoPreview(file);
        await recognizeQrImage(file, file.type === 'image/png' ? 'qr-scan.png' : 'qr-scan.jpg');
    }
    function openModal(trigger) {
        activeTrigger = trigger;
        previousBodyOverflow = document.body.style.overflow;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        clearPreview();
        setStatus('Открываем камеру...');
        dialog.focus();
        void startQrCamera();
    }
    function closeModal() {
        if (requestController) {
            requestController.abort();
            requestController = null;
        }
        stopQrCamera(false);
        clearPreview();
        setLoading(false);
        restartButton.hidden = true;
        modal.hidden = true;
        document.body.style.overflow = previousBodyOverflow;
        if (activeTrigger) {
            activeTrigger.focus();
            activeTrigger = null;
        }
    }
    function trapFocus(event) {
        if (event.key !== 'Tab' || modal.hidden) {
            return;
        }
        const focusable = Array.from(modal.querySelectorAll(
            'button:not([disabled]):not([hidden]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter((element) => element.offsetParent !== null);
        if (focusable.length === 0) {
            event.preventDefault();
            dialog.focus();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
    document.querySelectorAll('[data-qr-scanner-open]').forEach((button) => {
        button.addEventListener('click', () => openModal(button));
    });
    captureButton.addEventListener('click', () => void captureQrPhoto());
    retakeButton.addEventListener('click', () => void startQrCamera());
    restartButton.addEventListener('click', () => void startQrCamera());
    fileInput.addEventListener('change', () => void handleSelectedFile());
    modal.querySelectorAll('.qr-scanner-close, [data-qr-close]').forEach((button) => {
        button.addEventListener('click', closeModal);
    });
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });
    modal.addEventListener('keydown', trapFocus);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });
    video.addEventListener('loadeddata', () => {
        if (qrStream && video.videoWidth > 0 && video.videoHeight > 0) {
            captureButton.disabled = false;
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden' && !modal.hidden) {
            stopQrCamera(true);
            restartButton.hidden = false;
        }
    });
    window.addEventListener('pagehide', () => stopQrCamera(false));
}());
