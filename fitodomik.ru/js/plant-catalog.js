(function () {
    'use strict';
    const root = document.querySelector('[data-catalog-root]');
    if (!root) return;
    const form = root.querySelector('[data-catalog-form]');
    const grid = root.querySelector('[data-catalog-grid]');
    const resultCount = root.querySelector('[data-result-count]');
    const loadingStatus = root.querySelector('[data-loading-status]');
    const pagination = root.querySelector('[data-pagination]');
    const emptyState = root.querySelector('[data-empty-state]');
    const dialog = root.querySelector('[data-mode-dialog]');
    const dialogContent = root.querySelector('[data-dialog-content]');
    const listApi = root.dataset.listApi || '/api/plant-catalog-list.php';
    const addApi = root.dataset.addApi || '/api/plant-catalog-add-mode.php';
    const csrfToken = root.dataset.csrfToken || '';
    const deviceId = root.dataset.deviceId || '';
    let requestController = null;
    let searchTimer = null;
    root.addEventListener('error', function (event) {
        const image = event.target;
        if (image instanceof HTMLImageElement && !image.dataset.fallbackApplied) {
            image.dataset.fallbackApplied = '1';
            image.src = '/static/icon/android-chrome-512x512.v15.png';
        }
    }, true);
    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }
    function detailUrl(slug) {
        const params = new URLSearchParams({ plant: slug });
        if (deviceId) params.set('device_id', deviceId);
        return '/pages/plant-catalog/?' + params.toString();
    }
    function difficultyLabel(value) {
        return { easy: 'Просто', medium: 'Средне', hard: 'Сложно' }[value] || 'Средне';
    }
    function cleanNumber(value) {
        const number = Number(value);
        return Number.isInteger(number) ? String(number) : number.toFixed(1);
    }
    function range(min, max, suffix) {
        if (min === null || max === null) return 'Многолетний цикл';
        return cleanNumber(min) + '–' + cleanNumber(max) + (suffix || '');
    }
    function createButton(label, className, handler) {
        const button = element('button', 'plant-button ' + className, label);
        button.type = 'button';
        if (handler) button.addEventListener('click', handler);
        return button;
    }
    function createCard(item) {
        const card = element('article', 'plant-card');
        const imageLink = element('a', 'plant-card__image-link');
        imageLink.href = detailUrl(item.slug);
        imageLink.setAttribute('aria-label', 'Подробнее: ' + item.name_ru);
        const image = document.createElement('img');
        image.src = item.image_path;
        image.alt = item.name_ru + ' — ' + item.name_latin;
        image.width = 640;
        image.height = 420;
        image.loading = 'lazy';
        image.decoding = 'async';
        imageLink.appendChild(image);
        card.appendChild(imageLink);
        const body = element('div', 'plant-card__body');
        const badges = element('div', 'plant-card__badges');
        badges.append(element('span', '', item.category_name), element('span', '', difficultyLabel(item.difficulty)));
        body.appendChild(badges);
        const heading = element('h2');
        const headingLink = element('a', '', item.name_ru);
        headingLink.href = detailUrl(item.slug);
        heading.appendChild(headingLink);
        body.appendChild(heading);
        body.appendChild(element('p', 'plant-latin', item.name_latin));
        body.appendChild(element('p', '', item.short_description));
        const specs = element('dl', 'plant-card__specs');
        [
            ['Температура', range(item.temperature_min, item.temperature_max, ' °C')],
            ['Свет', range(item.light_hours_min, item.light_hours_max, ' ч')],
            ['До зрелости', item.harvest_days_min === null ? 'Многолетний цикл' : range(item.harvest_days_min, item.harvest_days_max, ' дней')],
        ].forEach(function (spec) {
            const wrapper = element('div');
            wrapper.append(element('dt', '', spec[0]), element('dd', '', spec[1]));
            specs.appendChild(wrapper);
        });
        body.appendChild(specs);
        card.appendChild(body);
        const actions = element('div', 'plant-card__actions');
        const details = element('a', 'plant-button plant-button--secondary', 'Подробнее');
        details.href = detailUrl(item.slug);
        actions.appendChild(details);
        if (csrfToken) {
            const add = createButton('Добавить режим', 'plant-button--primary');
            add.dataset.addMode = '';
            add.dataset.plant = item.slug;
            actions.appendChild(add);
        } else {
            const login = element('a', 'plant-button plant-button--primary', 'Добавить режим');
            login.href = '/authentication/login.php?redirect=' + encodeURIComponent(detailUrl(item.slug));
            actions.appendChild(login);
        }
        card.appendChild(actions);
        return card;
    }
    function showSkeletons() {
        if (!grid) return;
        grid.replaceChildren();
        for (let index = 0; index < 6; index += 1) {
            grid.appendChild(element('div', 'plant-card plant-card--skeleton'));
        }
    }
    function formParameters() {
        const params = new URLSearchParams(new FormData(form));
        params.delete('page');
        Array.from(params.entries()).forEach(function (entry) {
            if (entry[1] === '' || entry[1] === '0') params.delete(entry[0]);
        });
        return params;
    }
    function updatePagination(data, params) {
        if (!pagination) return;
        pagination.replaceChildren();
        if (data.pages <= 1) return;
        if (data.page > 1) {
            pagination.appendChild(createButton('Назад', 'plant-button--secondary', function () {
                loadCatalog(data.page - 1);
            }));
        }
        pagination.appendChild(element('span', '', 'Страница ' + data.page + ' из ' + data.pages));
        if (data.page < data.pages) {
            pagination.appendChild(createButton('Показать ещё', 'plant-button--primary', function () {
                loadCatalog(data.page + 1);
            }));
        }
        params.set('page', String(data.page));
    }
    async function loadCatalog(page) {
        if (!form || !grid) return;
        if (requestController) requestController.abort();
        requestController = new AbortController();
        const params = formParameters();
        params.set('page', String(page || 1));
        params.set('per_page', '12');
        if (loadingStatus) loadingStatus.textContent = 'Загрузка...';
        showSkeletons();
        try {
            const response = await fetch(listApi + '?' + params.toString(), {
                headers: { Accept: 'application/json' },
                signal: requestController.signal,
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Не удалось загрузить каталог');
            grid.replaceChildren();
            data.items.forEach(function (item) { grid.appendChild(createCard(item)); });
            if (emptyState) emptyState.hidden = data.pagination.total > 0;
            if (resultCount) {
                resultCount.replaceChildren('Найдено растений: ');
                resultCount.appendChild(element('strong', '', String(data.pagination.total)));
            }
            updatePagination(data.pagination, params);
            params.delete('per_page');
            window.history.replaceState({}, '', '/pages/plant-catalog/' + (params.toString() ? '?' + params.toString() : ''));
            if (loadingStatus) loadingStatus.textContent = '';
        } catch (error) {
            if (error.name === 'AbortError') return;
            grid.replaceChildren();
            if (emptyState) {
                emptyState.hidden = false;
                const heading = emptyState.querySelector('h2');
                const text = emptyState.querySelector('p');
                if (heading) heading.textContent = 'Каталог временно недоступен';
                if (text) text.textContent = error.message;
            }
            if (loadingStatus) loadingStatus.textContent = '';
        }
    }
    function openDialog(title, message, actions) {
        if (!dialog || !dialogContent) return;
        dialogContent.className = 'plant-dialog-content';
        dialogContent.replaceChildren(element('h2', '', title), element('p', '', message));
        const actionRow = element('div', 'plant-dialog-actions');
        actions.forEach(function (action) {
            if (action.href) {
                const link = element('a', 'plant-button ' + action.className, action.label);
                link.href = action.href;
                actionRow.appendChild(link);
            } else {
                actionRow.appendChild(createButton(action.label, action.className, action.handler));
            }
        });
        dialogContent.appendChild(actionRow);
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
    }
    async function addMode(slug, duplicateAction) {
        if (!csrfToken) {
            window.location.href = '/authentication/login.php?redirect=' + encodeURIComponent(detailUrl(slug));
            return;
        }
        const button = root.querySelector('[data-add-mode][data-plant="' + CSS.escape(slug) + '"]');
        if (button) button.disabled = true;
        try {
            const response = await fetch(addApi, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({ plant: slug, device_id: deviceId, duplicate_action: duplicateAction || '' }),
            });
            const data = await response.json();
            if (response.status === 409 && data.duplicate) {
                openDialog('Этот режим уже добавлен', data.message, [
                    { label: 'Открыть существующий', className: 'plant-button--secondary', handler: function () { window.location.href = data.farm_url; } },
                    { label: 'Добавить копию', className: 'plant-button--primary', handler: function () { dialog.close(); addMode(slug, 'copy'); } },
                    { label: 'Отмена', className: 'plant-button--ghost', handler: function () { dialog.close(); } },
                ]);
                return;
            }
            if (!response.ok || !data.success) throw new Error(data.message || 'Не удалось добавить режим');
            openDialog('Режим добавлен', data.message, [
                { label: 'Перейти к моим режимам', className: 'plant-button--primary', href: data.farm_url },
                { label: 'Продолжить поиск', className: 'plant-button--secondary', handler: function () { dialog.close(); } },
            ]);
        } catch (error) {
            openDialog('Не удалось добавить режим', error.message, [
                { label: 'Закрыть', className: 'plant-button--secondary', handler: function () { dialog.close(); } },
            ]);
        } finally {
            if (button) button.disabled = false;
        }
    }
    root.addEventListener('click', function (event) {
        const button = event.target.closest('[data-add-mode]');
        if (button && root.contains(button)) {
            event.preventDefault();
            addMode(button.dataset.plant || '', '');
            return;
        }
    });
    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            loadCatalog(1);
        });
        form.addEventListener('change', function (event) {
            if (event.target.matches('select, input[type="checkbox"]')) loadCatalog(1);
        });
        const search = form.querySelector('input[type="search"]');
        if (search) {
            search.addEventListener('input', function () {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(function () { loadCatalog(1); }, 350);
            });
        }
    }
}());
