<script>
document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-copy-command]');
    if (!button) {
        return;
    }
    const command = button.getAttribute('data-copy-command') || '';
    const label = button.querySelector('.copy-label');
    const initialText = label ? label.textContent : '';
    const showCopied = function () {
        if (label) {
            label.textContent = 'Скопировано';
            window.setTimeout(function () {
                label.textContent = initialText;
            }, 1600);
        }
    };
    const fallbackCopy = function () {
        const field = document.createElement('textarea');
        field.value = command;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.top = '-1000px';
        document.body.appendChild(field);
        field.select();
        try {
            document.execCommand('copy');
            showCopied();
        } finally {
            document.body.removeChild(field);
        }
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(command).then(showCopied, fallbackCopy);
    } else {
        fallbackCopy();
    }
});
</script>
