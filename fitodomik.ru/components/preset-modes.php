<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/device_context.php';
$user_id = $_SESSION['user_id'] ?? null;
$presetModes = [];
$presetDeviceId = '';
if ($user_id) {
    try {
        $presetDevice = fitodomikGetSelectedDevice($pdo, (int)$user_id);
        $presetDeviceId = (string)($presetDevice['device_id'] ?? '');
        $stmt = $pdo->prepare("SELECT * FROM preset_modes WHERE user_id = ? ORDER BY name");
        $stmt->execute([$user_id]);
        $presetModes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('preset-modes.php: ' . $e->getMessage());
        $presetModes = [];
        $presetModesError = true;
    }
}
?>
<style>
#presetModesContent .visually-hidden {
    position: absolute !important;
    width: 1px !important;
    height: 1px !important;
    padding: 0 !important;
    margin: -1px !important;
    overflow: hidden !important;
    clip: rect(0, 0, 0, 0) !important;
    white-space: nowrap !important;
    border: 0 !important;
}
#addPresetModal.modal {
    position: fixed;
    inset: 0;
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: clamp(10px, 2vw, 24px);
    background: rgba(3, 10, 6, 0.72);
    -webkit-backdrop-filter: blur(12px);
    backdrop-filter: blur(12px);
    overflow: hidden;
}
#addPresetModal .farm-style-modal {
    position: relative;
    width: min(940px, calc(100vw - 28px));
    max-height: min(92dvh, 820px);
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    color: #eef8f0;
    background:
        radial-gradient(circle at 18% 0%, rgba(51, 179, 89, 0.16), transparent 32%),
        linear-gradient(180deg, #263028 0%, #1f2721 58%, #1a211c 100%);
    border: 1px solid rgba(150, 235, 166, 0.18);
    border-radius: 24px;
    box-shadow: 0 28px 80px rgba(0, 0, 0, 0.46), 0 0 0 1px rgba(255, 255, 255, 0.03) inset;
}
#addPresetModal .modal-title {
    margin: 0;
    padding: 24px 72px 14px 28px;
    color: #a9f3b2;
    font-size: clamp(22px, 2.1vw, 30px);
    line-height: 1.16;
    font-weight: 800;
    letter-spacing: -0.02em;
}
#addPresetModal .close-button {
    position: absolute;
    top: 16px;
    right: 18px;
    z-index: 3;
    width: 42px;
    height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    color: #dbe9df;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.08);
    font-size: 30px;
    line-height: 1;
    cursor: pointer;
    transition: background 0.16s ease, transform 0.16s ease, color 0.16s ease;
}
#addPresetModal .close-button:hover,
#addPresetModal .close-button:focus-visible {
    color: #ffffff;
    background: rgba(255, 255, 255, 0.12);
    transform: scale(1.03);
    outline: none;
}
#addPresetModal .compact-form {
    min-height: 0;
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    overflow: hidden;
}
#addPresetModal .compact-form > .form-group {
    margin: 0;
    padding: 0 28px 16px;
}
#addPresetModal .form-group label,
#addPresetModal .input-group label {
    margin-bottom: 7px;
    color: #f2f7f3;
    font-size: 14px;
    font-weight: 750;
}
#addPresetModal input[type="text"],
#addPresetModal input[type="number"] {
    width: 100%;
    min-height: 48px;
    padding: 10px 14px;
    color: #ffffff;
    background: rgba(12, 17, 14, 0.58);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    box-shadow: 0 1px 0 rgba(255, 255, 255, 0.04) inset;
    font-size: 16px;
    outline: none;
    transition: border-color 0.16s ease, box-shadow 0.16s ease, background 0.16s ease;
}
#addPresetModal input[type="text"]:focus,
#addPresetModal input[type="number"]:focus {
    border-color: rgba(112, 230, 136, 0.78);
    background: rgba(9, 14, 11, 0.78);
    box-shadow: 0 0 0 4px rgba(46, 204, 91, 0.13);
}
#addPresetModal input::placeholder {
    color: rgba(238, 248, 240, 0.42);
}
#addPresetModal .preset-form-grid {
    min-height: 0;
    flex: 1 1 auto;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    padding: 2px 28px 22px;
    overflow-y: auto;
    overflow-x: hidden;
}
#addPresetModal .preset-form-grid fieldset {
    min-width: 0;
    margin: 0;
    padding: 20px 16px 16px;
    border: 1px solid rgba(166, 235, 178, 0.14);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.045);
    box-shadow: 0 1px 0 rgba(255, 255, 255, 0.035) inset;
}
#addPresetModal .preset-form-grid legend {
    float: none;
    width: auto;
    margin: 0;
    padding: 0 8px;
    color: #93efa3;
    font-size: 16px;
    font-weight: 800;
}
#addPresetModal .input-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    align-items: end;
}
#addPresetModal .input-group {
    min-width: 0;
    width: 100%;
    display: flex !important;
    flex-direction: column;
    gap: 0;
}
#addPresetModal .light-settings,
#addPresetModal .curtains-settings {
    grid-column: 1 / -1;
}
#addPresetModal .preset-field-hint {
    margin: 0 0 14px;
    color: rgba(238, 248, 240, 0.72);
    font-size: 15px;
    line-height: 1.42;
}
#addPresetModal .preset-hours-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 9px;
}
#addPresetModal .preset-hour-button {
    min-width: 0;
    min-height: 48px;
    padding: 8px 6px;
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    color: #edf5ef;
    background: rgba(255, 255, 255, 0.075);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 13px;
    font-size: 17px;
    font-weight: 850;
    line-height: 1;
    cursor: pointer;
    -webkit-user-select: none;
    user-select: none;
    transition: transform 0.12s ease, background 0.16s ease, border-color 0.16s ease, box-shadow 0.16s ease;
}
#addPresetModal .preset-hour-button:hover,
#addPresetModal .preset-hour-button:focus-visible {
    transform: translateY(-1px);
    border-color: rgba(176, 239, 188, 0.26);
    background: rgba(255, 255, 255, 0.115);
    outline: none;
}
#addPresetModal .preset-hour-button.is-active {
    color: #ffffff;
    background: linear-gradient(180deg, #229649 0%, #187d3c 100%);
    border-color: rgba(129, 245, 155, 0.35);
    box-shadow: 0 10px 24px rgba(20, 133, 61, 0.22), 0 1px 0 rgba(255, 255, 255, 0.12) inset;
}
#addPresetModal .curtain-hour small {
    color: rgba(255, 255, 255, 0.82);
    font-size: 18px;
    font-weight: 800;
    line-height: 1;
}
#addPresetModal .curtain-hour.is-up {
    background: linear-gradient(180deg, #2c9c59 0%, #1c7543 100%);
    border-color: rgba(129, 245, 155, 0.35);
}
#addPresetModal .curtain-hour.is-down {
    background: linear-gradient(180deg, #385d86 0%, #284664 100%);
    border-color: rgba(157, 204, 255, 0.28);
}
#addPresetModal .hours-display {
    margin-top: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: rgba(238, 248, 240, 0.82);
    font-weight: 750;
}
#addPresetModal .hours-value-display {
    min-width: 54px;
    min-height: 40px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #a9f3b2;
    background: rgba(12, 17, 14, 0.58);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    font-size: 18px;
    font-weight: 900;
}
#addPresetModal .form-actions {
    flex: 0 0 auto;
    margin: 0;
    padding: 14px 28px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    background: rgba(24, 31, 26, 0.92);
}
#addPresetModal .preset-save-error {
    flex: 1 1 auto;
    min-width: 0;
    padding: 12px 14px;
    color: #ffe1e1;
    background: rgba(190, 54, 54, 0.16);
    border: 1px solid rgba(255, 120, 120, 0.22);
    border-radius: 12px;
    font-size: 14px;
    line-height: 1.35;
}
#addPresetModal .form-buttons-container {
    flex: 0 0 auto;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}
#addPresetModal .btn-cancel,
#addPresetModal .btn-save {
    min-width: 170px;
    min-height: 52px;
    padding: 12px 18px;
    border: 0;
    border-radius: 13px;
    color: #ffffff;
    font-size: 16px;
    font-weight: 800;
    cursor: pointer;
    transition: transform 0.14s ease, box-shadow 0.16s ease, background 0.16s ease, opacity 0.16s ease;
}
#addPresetModal .btn-cancel {
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.1);
}
#addPresetModal .btn-save {
    background: linear-gradient(180deg, #20a24e 0%, #16833d 100%);
    box-shadow: 0 12px 28px rgba(22, 131, 61, 0.24);
}
#addPresetModal .btn-cancel:hover,
#addPresetModal .btn-save:hover,
#addPresetModal .btn-cancel:focus-visible,
#addPresetModal .btn-save:focus-visible {
    transform: translateY(-1px);
    outline: none;
}
#addPresetModal .btn-save:disabled {
    cursor: wait;
    opacity: 0.68;
    transform: none;
}
@media (max-width: 760px) {
    #addPresetModal.modal {
        align-items: stretch;
        justify-content: stretch;
        padding: 0;
    }
    #addPresetModal .farm-style-modal {
        width: 100%;
        max-height: 100dvh;
        min-height: 100dvh;
        border-radius: 0;
        border-left: 0;
        border-right: 0;
    }
    #addPresetModal .modal-title {
        padding: 18px 64px 12px 18px;
        font-size: 22px;
    }
    #addPresetModal .close-button {
        top: 10px;
        right: 12px;
    }
    #addPresetModal .compact-form > .form-group,
    #addPresetModal .preset-form-grid,
    #addPresetModal .form-actions {
        padding-left: 16px;
        padding-right: 16px;
    }
    #addPresetModal .preset-form-grid {
        grid-template-columns: 1fr;
        gap: 14px;
        padding-bottom: 16px;
    }
    #addPresetModal .input-row {
        grid-template-columns: 1fr;
    }
    #addPresetModal .preset-hours-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
    }
    #addPresetModal .preset-hour-button {
        min-height: 46px;
        font-size: 16px;
        border-radius: 12px;
    }
    #addPresetModal .form-actions {
        flex-direction: column;
        align-items: stretch;
        padding-top: 12px;
        padding-bottom: calc(14px + env(safe-area-inset-bottom));
    }
    #addPresetModal .form-buttons-container {
        width: 100%;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    #addPresetModal .btn-cancel,
    #addPresetModal .btn-save {
        min-width: 0;
        width: 100%;
    }
}
@media (max-width: 390px) {
    #addPresetModal .preset-hours-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
</style>
<div class="graphs-header" id="presetModesHeader">
    <h2>Предустановленные режимы 🌱</h2>
    <div class="header-right-content">
        <span class="accordion-icon" id="presetModesIcon">▼</span>
    </div>
</div>
<div class="graphs-content" id="presetModesContent">
    <div class="preset-header">
        <div class="import-code-container">
            <h4>Импортировать режим</h4>
            <div class="input-group">
                <label class="visually-hidden" for="importCodeInput">Код режима для импорта</label>
                <input type="text" id="importCodeInput" class="import-code-input" placeholder="Введите код режима" maxlength="8">
                <button class="import-btn" id="importCodeBtn">Загрузить код</button>
            </div>
        </div>
    </div>
    <div class="preset-modes-container">
        <?php if (!empty($presetModesError)) : ?>
            <p class="preset-empty-state">Режимы временно недоступны. Попробуйте обновить страницу.</p>
        <?php elseif (!empty($presetModes)) : ?>
            <?php foreach ($presetModes as $mode) : ?>
            <div class="preset-mode" data-id="<?php echo (int)$mode['id']; ?>">
                <h3><?php echo htmlspecialchars((string)$mode['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h3>
                <div class="preset-params">
                    <div class="preset-param">
                        <span class="param-icon">🌡️</span>
                        <span class="param-label">Темп.:</span>
                        <span class="param-value"><?php echo number_format((float)$mode['temperature'], 1, '.', ''); ?>°C (±<?php echo number_format((float)$mode['tolerance'], 1, '.', ''); ?>°C)</span>
                    </div>
                    <div class="preset-param">
                        <span class="param-icon">💧</span>
                        <span class="param-label">Влаж.:</span>
                        <span class="param-value"><?php echo (int)$mode['humidity']; ?>% (±<?php echo number_format((float)$mode['humidity_tolerance'], 1, '.', ''); ?>%)</span>
                    </div>
                    <?php if ($mode['soil_moisture'] !== null) : ?>
                    <div class="preset-param">
                        <span class="param-icon">◉</span>
                        <span class="param-label">Почва:</span>
                        <span class="param-value"><?php echo (int)$mode['soil_moisture']; ?>% (±<?php echo number_format((float)$mode['soil_moisture_tolerance'], 1, '.', ''); ?>%)</span>
                    </div>
                    <?php endif; ?>
                    <div class="preset-param">
                        <span class="param-icon">☀️</span>
                        <span class="param-label">Свет:</span>
                        <span class="param-value"><?php echo number_format((float)$mode['light_hours'], 1, '.', ''); ?> ч (<?php echo htmlspecialchars(substr((string)$mode['light_start'], 0, 5), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> - <?php echo htmlspecialchars(substr((string)$mode['light_end'], 0, 5), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>)</span>
                    </div>
                </div>
                <div class="preset-actions">
                    <button class="activate-button" data-mode='<?php echo htmlspecialchars(json_encode($mode), ENT_QUOTES, 'UTF-8'); ?>'>Активировать</button>
                    <button class="edit-button" type="button" data-mode='<?php echo htmlspecialchars(json_encode($mode), ENT_QUOTES, 'UTF-8'); ?>'>Изменить</button>
                    <button class="share-button" type="button" data-id="<?php echo (int)$mode['id']; ?>" data-name="<?php echo htmlspecialchars((string)$mode['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">🔗</button>
                    <button class="delete-button" type="button" data-id="<?php echo (int)$mode['id']; ?>" data-name="<?php echo htmlspecialchars((string)$mode['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">🗑️</button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else : ?>
             <p class="preset-empty-state">У вас пока нет сохраненных режимов.</p>
        <?php endif; ?>
        <div class="preset-create-actions">
            <a
                class="find-preset-button"
                href="/pages/plant-catalog/<?php echo $presetDeviceId !== '' ? '?device_id=' . rawurlencode($presetDeviceId) : ''; ?>">
                <span class="find-preset-button__icon" aria-hidden="true"></span>
                Найти режим
            </a>
            <button class="add-preset-button" id="addPresetButton">+ Добавить новый режим</button>
        </div>
    </div>
</div>
<div id="addPresetModal" class="modal">
    <?php require_once __DIR__ . '/../includes/modal_accessibility_script.php'; ?>
    <div class="modal-content farm-style-modal">
        <button type="button" class="close-button" id="closeModalButton" aria-label="Закрыть">&times;</button>
        <h2 class="modal-title" id="presetModalTitle">Добавить новый режим</h2>
        <form id="addPresetForm" class="compact-form">
            <input type="hidden" id="presetModeId" name="mode_id" value="">
            <div class="form-group">
                <label for="presetName">Название режима</label>
                <input type="text" id="presetName" name="name" autocomplete="off" required placeholder="Название режима">
            </div>
            <div class="preset-form-grid">
                <fieldset>
                    <legend>Температура</legend>
                    <div class="input-row">
                        <div class="input-group">
                            <label for="presetTemp">Температура, °C</label>
                            <input type="number" id="presetTemp" name="temperature" step="0.1" min="5" max="50" required placeholder="24">
                        </div>
                        <div class="input-group">
                            <label for="presetTempTolerance">Допуск, °C</label>
                            <input type="number" id="presetTempTolerance" name="tolerance" step="0.1" value="2" min="0" max="10" required>
                        </div>
                    </div>
                </fieldset>
                <fieldset>
                    <legend>Влажность почвы</legend>
                    <div class="input-row">
                        <div class="input-group">
                            <label for="presetSoilMoisture">Целевое значение, %</label>
                            <input type="number" id="presetSoilMoisture" name="soil_moisture" step="1" min="0" max="100" value="50" required>
                        </div>
                        <div class="input-group">
                            <label for="presetSoilTolerance">Допуск, %</label>
                            <input type="number" id="presetSoilTolerance" name="soil_moisture_tolerance" step="0.1" min="0" max="30" value="15" required>
                        </div>
                    </div>
                </fieldset>
                <fieldset>
                    <legend>Влажность</legend>
                    <div class="input-row">
                        <div class="input-group">
                            <label for="presetHumidity">Влажность, %</label>
                            <input type="number" id="presetHumidity" name="humidity" step="1" min="30" max="99" required placeholder="65">
                        </div>
                        <div class="input-group">
                            <label for="presetHumidityTolerance">Допуск, %</label>
                            <input type="number" id="presetHumidityTolerance" name="humidity_tolerance" step="0.1" value="10" min="0" max="20" required>
                        </div>
                    </div>
                </fieldset>
                <fieldset class="light-settings">
                    <legend>Лампа</legend>
                    <p class="preset-field-hint">Выберите часы работы лампы. Несколько диапазонов можно включать отдельно.</p>
                    <div id="presetLampHoursGrid" class="preset-hours-grid" aria-label="Часы работы лампы"></div>
                    <div class="hours-display">
                        <span>Часов в день:</span>
                        <div id="presetLightHours" class="hours-value-display">0</div>
                        <input type="hidden" name="light_hours" id="hiddenLightHours" value="0">
                        <input type="hidden" name="lamp_hours" id="presetLampHours" value="[]">
                        <input type="hidden" name="light_start" id="presetLightStart" value="08:00">
                        <input type="hidden" name="light_end" id="presetLightEnd" value="20:00">
                    </div>
                </fieldset>
                <fieldset class="curtains-settings">
                    <legend>Шторы</legend>
                    <p class="preset-field-hint">Нажатие переключает час: не менять → подняты → опущены.</p>
                    <div id="presetCurtainHoursGrid" class="preset-hours-grid curtain-hours-grid" aria-label="Состояние штор по часам"></div>
                    <input type="hidden" name="curtain_schedule" id="presetCurtainSchedule" value="{}">
                </fieldset>
            </div>
            <div class="form-actions">
                <div id="presetSaveError" class="preset-save-error" role="alert" hidden></div>
                <div class="form-buttons-container">
                    <button type="button" class="btn-cancel" id="cancelPresetBtn">Отмена</button>
                    <button type="submit" class="btn-save" id="savePresetBtn">Сохранить режим</button>
                </div>
            </div>
        </form>
    </div>
</div>
<div class="modal" id="sharePresetModal">
    <div class="modal-content">
        <button type="button" class="close-button" id="closeShareModalButton" aria-label="Закрыть">&times;</button>
        <h2>Поделиться режимом</h2>
        <p>Выберите способ, которым вы хотите поделиться режимом: <span id="shareModeName" class="fw-bold"></span></p>
        <div class="share-options">
            <button class="btn btn-primary mb-2 w-100" id="shareCodeBtn">
                <i class="bi bi-key me-2"></i> Поделиться кодом
            </button>
            <button class="btn btn-info mb-2 w-100" id="shareLinkBtn">
                <i class="bi bi-link-45deg me-2"></i> Поделиться ссылкой
            </button>
        </div>
        <div id="shareCodeSection" class="d-none share-result-section">
            <label for="shareCodeDisplay" class="form-label">Код для импорта режима:</label>
            <div class="input-group">
                <input type="text"
                       id="shareCodeDisplay"
                       class="form-control share-input"
                       readonly
                       aria-label="Код для импорта режима"
                       title="Код для импорта режима в другую систему"
                       placeholder="Код будет сгенерирован...">
                <button class="btn btn-outline-secondary copy-btn"
                        type="button"
                        id="copyCodeBtn"
                        title="Копировать код в буфер обмена">
                    📋 Копировать
                </button>
            </div>
            <p class="text-muted small mt-2">Передайте этот код другому пользователю для импорта режима</p>
        </div>
        <div id="shareLinkSection" class="d-none share-result-section">
            <label for="shareLinkDisplay" class="form-label">Ссылка для импорта режима:</label>
            <div class="input-group">
                <input type="text"
                       id="shareLinkDisplay"
                       class="form-control share-input"
                       readonly
                       aria-label="Ссылка для импорта режима"
                       title="Ссылка для импорта режима через браузер"
                       placeholder="Ссылка будет сгенерирована...">
                <button class="btn btn-outline-secondary copy-btn"
                        type="button"
                        id="copyLinkBtn"
                        title="Копировать ссылку в буфер обмена">
                    📋 Копировать
                </button>
            </div>
            <p class="text-muted small mt-2">Отправьте эту ссылку другому пользователю для прямого импорта режима</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="closeShareModalFooterBtn">Закрыть</button>
        </div>
    </div>
</div>
<script>
function presetDebugLog(...args) {
    if (window.FITODOMIK_DEBUG) {
        console.log(...args);
    }
}
function showPresetSaveError(message) {
    const el = document.getElementById('presetSaveError');
    if (!el) {
        return;
    }
    if (message) {
        el.textContent = message;
        el.hidden = false;
    } else {
        el.textContent = '';
        el.hidden = true;
    }
}
function timeToMinutes(timeStr) {
    if (!timeStr || !timeStr.includes(':')) return 0;
    const [hours, minutes] = timeStr.split(':').map(Number);
    return hours * 60 + minutes;
}
function isTimeInPeriod(time, start, end) {
    const timeMin = timeToMinutes(time);
    const startMin = timeToMinutes(start);
    const endMin = timeToMinutes(end);
    if (startMin <= endMin) {
        return timeMin >= startMin && timeMin <= endMin;
    } else {
        return timeMin >= startMin || timeMin <= endMin;
    }
}
function calculateTotalHours(startTime, endTime) {
    const start = timeToMinutes(startTime);
    const end = timeToMinutes(endTime);
    let totalMinutes = end - start;
    if (totalMinutes < 0) {
        totalMinutes += 24 * 60;
    }
    return totalMinutes / 60;
}
function togglePresetModes() {
    const content = document.getElementById('presetModesContent');
    const icon = document.getElementById('presetModesIcon');
    content.classList.toggle('active');
    icon.classList.toggle('rotate');
}
async function activatePresetMode(modeData) {
    presetDebugLog('Активация режима:', modeData);
    try {
        const deviceId = <?php echo json_encode($presetDeviceId); ?>;
        if (!deviceId) {
            throw new Error('Сначала подключите и выберите устройство в личном кабинете, затем обновите страницу.');
        }
        const response = await fetch('/api/activate-preset-mode.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({
                mode_id: modeData.id,
                device_id: deviceId
            })
        });
        const result = await response.json();
        if (!response.ok) {
            throw new Error(result.message || `Ошибка сервера: ${response.status}`);
        }
        if (result.success) {
            const farmSettingsContent = document.getElementById('farmSettingsContent');
            const farmSettingsHeader = document.querySelector('.farm-settings-header');
            if (farmSettingsContent && !farmSettingsContent.classList.contains('active') && farmSettingsHeader) {
                farmSettingsHeader.click();
            }
            const schedulesContent = document.getElementById('schedulesContent');
            if (schedulesContent && !schedulesContent.classList.contains('active')) {
                const schedulesHeader = document.querySelector('.schedules-header');
                if (schedulesHeader) {
                    schedulesHeader.click();
                }
            }
            const lightingHoursDisplay = document.getElementById('lightingHours');
            if (lightingHoursDisplay) {
                lightingHoursDisplay.textContent = modeData.light_hours;
            }
            const lampHours = parsePresetLampHours(modeData);
            if (lampHours.length > 0) {
                updateHoursGridFromPresetHours(lampHours, 'lightingGrid');
            } else {
                updateHoursGridFromTime(modeData.light_start, modeData.light_end, 'lightingGrid');
            }
            if (farmSettingsHeader) {
                farmSettingsHeader.scrollIntoView({ behavior: 'smooth' });
            }
            alert(`Режим "${modeData.name}" активирован успешно!`);
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            throw new Error(result.message || 'Неизвестная ошибка при активации режима');
        }
    } catch (error) {
        console.error('Ошибка при активации режима:', error);
        alert('Не удалось активировать режим: ' + error.message);
    }
}
function updateHoursGridFromTime(startTime, endTime, gridId) {
    const grid = document.getElementById(gridId);
    if (!grid) return;
    const startMin = timeToMinutes(startTime);
    let endMin = timeToMinutes(endTime);
    if (endTime.endsWith(':00')) {
        endMin -= 1;
    }
    const cells = grid.querySelectorAll('.hour-cell');
    cells.forEach(cell => {
        const cellTime = cell.dataset.time;
        const cellHour = parseInt(cellTime.split(':')[0]);
        const cellStartMin = cellHour * 60;
        const cellEndMin = cellStartMin + 59;
        let isActive = false;
        if (startMin <= endMin) {
            isActive = (cellStartMin >= startMin && cellStartMin <= endMin);
        } else {
            isActive = cellStartMin >= startMin || cellStartMin <= endMin;
        }
        if (isActive) {
            cell.classList.add('active');
            cell.classList.remove('inactive');
        } else {
            cell.classList.remove('active');
            cell.classList.add('inactive');
        }
    });
    const typeId = gridId === 'lightingGrid' ? 'lightingHours' : 'curtainsHours';
    updateHoursCount(typeId === 'lightingHours' ? 'lighting' : 'curtains');
}
function parsePresetLampHours(modeData) {
    if (!modeData || !modeData.lamp_hours_json) {
        return [];
    }
    try {
        const hours = JSON.parse(modeData.lamp_hours_json);
        return Array.isArray(hours)
            ? hours.map(Number).filter(hour => Number.isInteger(hour) && hour >= 0 && hour <= 23)
            : [];
    } catch (error) {
        return [];
    }
}
function updateHoursGridFromPresetHours(hours, gridId) {
    const grid = document.getElementById(gridId);
    if (!grid) return;
    const selected = new Set(hours);
    grid.querySelectorAll('.hour-cell').forEach(cell => {
        const cellHour = parseInt((cell.dataset.time || '0:00').split(':')[0], 10);
        const isActive = selected.has(cellHour);
        cell.classList.toggle('active', isActive);
        cell.classList.toggle('inactive', !isActive);
    });
    updateHoursCount(gridId === 'lightingGrid' ? 'lighting' : 'curtains');
}
const presetLampHours = new Set();
const presetCurtainSchedule = {};
const CURTAIN_STATES = ['none', 'up', 'down'];
const CURTAIN_LABELS = { none: '—', up: '↑', down: '↓' };
const CURTAIN_TITLES = { none: 'не менять', up: 'подняты', down: 'опущены' };
function formatPresetHour(hour) {
    return String(hour).padStart(2, '0');
}
function selectedPresetHours() {
    return Array.from(presetLampHours).sort((a, b) => a - b);
}
function updatePresetLampHiddenFields() {
    const hours = selectedPresetHours();
    const hoursDisplay = document.getElementById('presetLightHours');
    const hiddenHours = document.getElementById('hiddenLightHours');
    const hiddenLampHours = document.getElementById('presetLampHours');
    const startField = document.getElementById('presetLightStart');
    const endField = document.getElementById('presetLightEnd');
    if (hoursDisplay) {
        hoursDisplay.textContent = String(hours.length);
    }
    if (hiddenHours) {
        hiddenHours.value = String(hours.length);
    }
    if (hiddenLampHours) {
        hiddenLampHours.value = JSON.stringify(hours);
    }
    if (startField && endField) {
        const first = hours[0] ?? 8;
        const last = hours.length ? hours[hours.length - 1] : 20;
        startField.value = `${formatPresetHour(first)}:00`;
        endField.value = `${formatPresetHour((last + 1) % 24)}:00`;
    }
}
function updatePresetCurtainHiddenField() {
    const field = document.getElementById('presetCurtainSchedule');
    if (field) {
        field.value = JSON.stringify(presetCurtainSchedule);
    }
}
function setPresetLampHour(hour, active) {
    if (active) {
        presetLampHours.add(hour);
    } else {
        presetLampHours.delete(hour);
    }
    const button = document.querySelector(`#presetLampHoursGrid [data-hour="${hour}"]`);
    if (button) {
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    }
    updatePresetLampHiddenFields();
}
function cyclePresetCurtainHour(hour) {
    const current = presetCurtainSchedule[hour] || 'none';
    const next = CURTAIN_STATES[(CURTAIN_STATES.indexOf(current) + 1) % CURTAIN_STATES.length];
    if (next === 'none') {
        delete presetCurtainSchedule[hour];
    } else {
        presetCurtainSchedule[hour] = next;
    }
    const button = document.querySelector(`#presetCurtainHoursGrid [data-hour="${hour}"]`);
    if (button) {
        button.dataset.state = next;
        button.classList.remove('is-up', 'is-down');
        button.classList.toggle('is-up', next === 'up');
        button.classList.toggle('is-down', next === 'down');
        button.innerHTML = `<span>${formatPresetHour(hour)}</span><small>${CURTAIN_LABELS[next]}</small>`;
        button.title = `Шторы: ${CURTAIN_TITLES[next]}`;
    }
    updatePresetCurtainHiddenField();
}
function buildPresetHourGrid(gridId, mode) {
    const grid = document.getElementById(gridId);
    if (!grid || grid.dataset.ready === '1') {
        return;
    }
    for (let hour = 0; hour < 24; hour += 1) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `preset-hour-button ${mode === 'curtains' ? 'curtain-hour' : ''}`;
        button.dataset.hour = String(hour);
        button.setAttribute('aria-pressed', 'false');
        if (mode === 'lamp') {
            button.textContent = formatPresetHour(hour);
            button.addEventListener('click', () => setPresetLampHour(hour, !presetLampHours.has(hour)));
        } else {
            button.dataset.state = 'none';
            button.innerHTML = `<span>${formatPresetHour(hour)}</span><small>${CURTAIN_LABELS.none}</small>`;
            button.title = `Шторы: ${CURTAIN_TITLES.none}`;
            button.addEventListener('click', () => cyclePresetCurtainHour(hour));
        }
        grid.appendChild(button);
    }
    grid.dataset.ready = '1';
}
function resetPresetScheduleControls() {
    presetLampHours.clear();
    Object.keys(presetCurtainSchedule).forEach(hour => delete presetCurtainSchedule[hour]);
    buildPresetHourGrid('presetLampHoursGrid', 'lamp');
    buildPresetHourGrid('presetCurtainHoursGrid', 'curtains');
    document.querySelectorAll('#presetLampHoursGrid .preset-hour-button').forEach(button => {
        button.classList.remove('is-active');
        button.setAttribute('aria-pressed', 'false');
    });
    document.querySelectorAll('#presetCurtainHoursGrid .preset-hour-button').forEach(button => {
        button.dataset.state = 'none';
        button.classList.remove('is-up', 'is-down');
        const hour = Number(button.dataset.hour);
        button.innerHTML = `<span>${formatPresetHour(hour)}</span><small>${CURTAIN_LABELS.none}</small>`;
        button.title = `Шторы: ${CURTAIN_TITLES.none}`;
    });
    for (let hour = 8; hour < 20; hour += 1) {
        setPresetLampHour(hour, true);
    }
    updatePresetCurtainHiddenField();
}
function setPresetModalMode(modeData) {
    const form = document.getElementById('addPresetForm');
    const title = document.getElementById('presetModalTitle');
    const saveButton = document.getElementById('savePresetBtn');
    if (!form) return;
    form.reset();
    resetPresetScheduleControls();
    const modeId = document.getElementById('presetModeId');
    if (!modeData) {
        if (modeId) modeId.value = '';
        if (title) title.textContent = 'Добавить новый режим';
        if (saveButton) saveButton.textContent = 'Сохранить режим';
        return;
    }
    if (modeId) modeId.value = String(modeData.id || '');
    if (title) title.textContent = 'Изменить режим';
    if (saveButton) saveButton.textContent = 'Сохранить изменения';
    const values = {
        presetName: modeData.name,
        presetTemp: modeData.temperature,
        presetTempTolerance: modeData.tolerance,
        presetHumidity: modeData.humidity,
        presetHumidityTolerance: modeData.humidity_tolerance,
        presetSoilMoisture: modeData.soil_moisture ?? 50,
        presetSoilTolerance: modeData.soil_moisture_tolerance ?? 15
    };
    Object.entries(values).forEach(([id, value]) => {
        const field = document.getElementById(id);
        if (field) field.value = String(value ?? '');
    });
    presetLampHours.clear();
    document.querySelectorAll('#presetLampHoursGrid .preset-hour-button').forEach(button => {
        button.classList.remove('is-active');
        button.setAttribute('aria-pressed', 'false');
    });
    const storedHours = parsePresetLampHours(modeData);
    if (storedHours.length > 0) {
        storedHours.forEach(hour => setPresetLampHour(hour, true));
    } else {
        const start = timeToMinutes(String(modeData.light_start || '08:00')) / 60;
        const count = Math.max(1, Math.min(24, Math.round(Number(modeData.light_hours) || 12)));
        for (let index = 0; index < count; index += 1) {
            setPresetLampHour((Math.floor(start) + index) % 24, true);
        }
    }
    Object.keys(presetCurtainSchedule).forEach(hour => delete presetCurtainSchedule[hour]);
    try {
        const schedule = typeof modeData.curtain_schedule === 'string'
            ? JSON.parse(modeData.curtain_schedule || '{}')
            : (modeData.curtain_schedule || {});
        Object.entries(schedule).forEach(([hour, state]) => {
            if ((state === 'up' || state === 'down') && Number(hour) >= 0 && Number(hour) <= 23) {
                presetCurtainSchedule[hour] = state;
                const button = document.querySelector(`#presetCurtainHoursGrid [data-hour="${Number(hour)}"]`);
                if (button) {
                    button.dataset.state = state;
                    button.classList.toggle('is-up', state === 'up');
                    button.classList.toggle('is-down', state === 'down');
                    button.innerHTML = `<span>${formatPresetHour(Number(hour))}</span><small>${CURTAIN_LABELS[state]}</small>`;
                    button.title = `Шторы: ${CURTAIN_TITLES[state]}`;
                }
            }
        });
    } catch (error) {
        presetDebugLog('Не удалось прочитать расписание штор режима', error);
    }
    updatePresetCurtainHiddenField();
}
async function saveNewPresetMode(formData) {
    const submitButton = document.querySelector('#addPresetForm button[type="submit"]');
    if (!submitButton) {
        console.error('Не найдена кнопка отправки формы');
        showPresetSaveError('Внутренняя ошибка интерфейса. Обновите страницу.');
        return;
    }
    const originalButtonText = submitButton.innerHTML;
    submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Сохранение...';
    submitButton.disabled = true;
    showPresetSaveError('');
    try {
        presetDebugLog('Отправляем данные режима на сервер:', formData);
        const response = await fetch('/api/save-preset-mode.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify(formData)
        });
        const responseText = await response.text();
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            console.error('Ошибка парсинга JSON ответа:', parseError, responseText);
            showPresetSaveError('Получен некорректный ответ от сервера.');
            return;
        }
        if (result.success) {
            presetDebugLog('Режим успешно сохранен, ответ сервера:', result);
            const modalElement = document.getElementById('addPresetModal');
            if (modalElement) {
                modalElement.style.display = 'none';
            }
            const form = document.getElementById('addPresetForm');
            if (form) {
                form.reset();
            }
            resetPresetScheduleControls();
            alert(result.message || 'Режим сохранён');
            location.reload();
            return;
        }
        showPresetSaveError(result.message || 'Не удалось сохранить режим');
    } catch (error) {
        console.error('Ошибка при сохранении режима:', error);
        showPresetSaveError(error.message || 'Не удалось сохранить режим');
    } finally {
        if (submitButton) {
            submitButton.innerHTML = originalButtonText || 'Сохранить режим';
            submitButton.disabled = false;
        }
    }
}
function checkUrlForImportCode() {
    const urlParams = new URLSearchParams(window.location.search);
    const code = urlParams.get('code');
    if (code) {
        presetDebugLog('Обнаружен код импорта в URL:', code);
        if (history.pushState) {
            const newUrl = window.location.pathname;
            window.history.pushState({path: newUrl}, '', newUrl);
        }
        const importInput = document.getElementById('importCodeInput');
        if (importInput) {
            importInput.value = code;
            const presetModesHeader = document.getElementById('presetModesHeader');
            if (presetModesHeader) {
                presetModesHeader.scrollIntoView({ behavior: 'smooth' });
                const presetModesContent = document.getElementById('presetModesContent');
                if (presetModesContent && !presetModesContent.classList.contains('active')) {
                    togglePresetModes();
                }
                setTimeout(() => {
                    const importBtn = document.getElementById('importCodeBtn');
                    if (importBtn) {
                        importBtn.click();
                    }
                }, 500);
            }
        }
    }
}
document.addEventListener('DOMContentLoaded', function() {
    presetDebugLog('DOM загружен, инициализация обработчиков событий');
    initActivateButtons();
    initEditButtons();
    initDeleteButtons();
    initShareButtons();
    setupImportByCode();
    checkUrlForImportCode();
    const presetModesHeader = document.getElementById('presetModesHeader');
    if(presetModesHeader) {
        presetModesHeader.addEventListener('click', togglePresetModes);
    }
    const importCodeBtn = document.getElementById('importCodeBtn');
    if (importCodeBtn) {
        importCodeBtn.textContent = 'Загрузить код';
    }
    const addPresetButton = document.getElementById('addPresetButton');
    const closeModalButton = document.getElementById('closeModalButton');
    const cancelPresetBtn = document.getElementById('cancelPresetBtn');
    const addPresetForm = document.getElementById('addPresetForm');
    const modal = document.getElementById('addPresetModal');
    function closePresetModal() {
        if(modal) {
            modal.style.display = 'none';
            setPresetModalMode(null);
        }
    }
    if(addPresetButton) {
        addPresetButton.addEventListener('click', function() {
            setPresetModalMode(null);
            showPresetSaveError('');
            if(modal) modal.style.display = 'flex';
        });
    }
    if(closeModalButton) {
        closeModalButton.addEventListener('click', closePresetModal);
    }
    if(cancelPresetBtn) {
        cancelPresetBtn.addEventListener('click', closePresetModal);
    }
    window.addEventListener('click', function(event) {
        if (event.target == modal) {
            closePresetModal();
        }
    });
    if(addPresetForm) {
        addPresetForm.addEventListener('submit', function(event) {
            event.preventDefault();
            const saveButton = document.getElementById('savePresetBtn');
            if(saveButton && saveButton.disabled) {
                presetDebugLog('Кнопка сохранения уже отключена, предотвращаем повторную отправку');
                return;
            }
            const formData = new FormData(event.target);
            const data = Object.fromEntries(formData.entries());
            if (validatePresetFormData(data)) {
                saveNewPresetMode(data);
            }
        });
    }
    resetPresetScheduleControls();
});
function validatePresetFormData(data) {
    if (!data.name || data.name.trim() === '') {
        alert('Ошибка: Необходимо указать название режима.');
        return false;
    }
    const temp = parseFloat(data.temperature);
    if (isNaN(temp) || temp < 5 || temp > 50) {
        alert('Ошибка: Температура должна быть числом от 5 до 50°C.');
        return false;
    }
    const humidity = parseInt(data.humidity, 10);
    if (isNaN(humidity) || humidity < 30 || humidity > 99) {
        alert('Ошибка: Влажность должна быть целым числом от 30 до 99%.');
        return false;
    }
    const tolerance = parseFloat(data.tolerance);
    if (isNaN(tolerance) || tolerance < 0 || tolerance > 10) {
        alert('Ошибка: Допуск температуры должен быть числом от 0 до 10°C.');
        return false;
    }
    const humidityTolerance = parseFloat(data.humidity_tolerance);
    if (isNaN(humidityTolerance) || humidityTolerance < 0 || humidityTolerance > 20) {
        alert('Ошибка: Допуск влажности должен быть числом от 0 до 20%.');
        return false;
    }
    const soilMoisture = parseInt(data.soil_moisture, 10);
    if (isNaN(soilMoisture) || soilMoisture < 0 || soilMoisture > 100) {
        alert('Ошибка: Влажность почвы должна быть целым числом от 0 до 100%.');
        return false;
    }
    const soilTolerance = parseFloat(data.soil_moisture_tolerance);
    if (isNaN(soilTolerance) || soilTolerance < 0 || soilTolerance > 30) {
        alert('Ошибка: Допуск влажности почвы должен быть числом от 0 до 30%.');
        return false;
    }
    let lampHours = [];
    try {
        lampHours = JSON.parse(data.lamp_hours || '[]');
    } catch (error) {
        lampHours = [];
    }
    if (!Array.isArray(lampHours) || lampHours.some(hour => !Number.isInteger(hour) || hour < 0 || hour > 23)) {
        alert('Ошибка: Некорректный список часов лампы.');
        return false;
    }
    if (lampHours.length <= 0) {
        alert('Выберите хотя бы один час работы лампы.');
        return false;
    }
    const startTimeInput = data.light_start;
    const endTimeInput = data.light_end;
    if (!startTimeInput || !endTimeInput) {
        alert('Не удалось определить время освещения. Выберите часы лампы ещё раз.');
        return false;
    }
    data.temperature = temp;
    data.tolerance = tolerance;
    data.humidity = humidity;
    data.humidity_tolerance = humidityTolerance;
    data.soil_moisture = soilMoisture;
    data.soil_moisture_tolerance = soilTolerance;
    data.light_hours = lampHours.length;
    data.lamp_hours = lampHours;
    try {
        data.curtain_schedule = JSON.parse(data.curtain_schedule || '{}');
    } catch (error) {
        data.curtain_schedule = {};
    }
    presetDebugLog('Данные формы прошли валидацию:', data);
    return true;
}
function initActivateButtons() {
    presetDebugLog('Инициализация кнопок активации');
    document.querySelectorAll('.activate-button').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            presetDebugLog('Клик по кнопке активации');
            try {
                const modeData = JSON.parse(this.dataset.mode);
                activatePresetMode(modeData);
            } catch(e) {
                console.error("Ошибка парсинга данных режима:", e);
                alert("Не удалось загрузить данные режима.");
            }
        });
    });
}
function initEditButtons() {
    document.querySelectorAll('.edit-button').forEach(button => {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            try {
                const modeData = JSON.parse(this.dataset.mode);
                setPresetModalMode(modeData);
                showPresetSaveError('');
                const modal = document.getElementById('addPresetModal');
                if (modal) modal.style.display = 'flex';
            } catch (error) {
                console.error('Не удалось открыть режим для редактирования:', error);
                alert('Не удалось загрузить параметры режима.');
            }
        });
    });
}
function initDeleteButtons() {
    presetDebugLog('Инициализация кнопок удаления');
    document.querySelectorAll('.delete-button').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            presetDebugLog('Клик по кнопке удаления');
            const modeId = this.dataset.id;
            const modeName = this.dataset.name || 'Режим';
            presetDebugLog('Нажата кнопка удаления для режима:', modeId, modeName);
            deletePresetMode(modeId, modeName);
        });
    });
}
function initShareButtons() {
    presetDebugLog('Инициализация кнопок шаринга');
    const shareModalElement = document.getElementById('sharePresetModal');
    if (!shareModalElement) {
        console.error('Модальное окно шаринга не найдено');
        return;
    }
    const closeShareModalButton = document.getElementById('closeShareModalButton');
    const closeShareModalFooterBtn = document.getElementById('closeShareModalFooterBtn');
    function closeShareModal() {
        shareModalElement.style.display = 'none';
    }
    if (closeShareModalButton) {
        closeShareModalButton.addEventListener('click', closeShareModal);
    }
    if (closeShareModalFooterBtn) {
        closeShareModalFooterBtn.addEventListener('click', closeShareModal);
    }
    window.addEventListener('click', function(event) {
        if (event.target === shareModalElement) {
            closeShareModal();
        }
    });
    document.querySelectorAll('.share-button').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            presetDebugLog('Клик по кнопке шаринга');
            const modeId = this.dataset.id;
            const modeName = this.dataset.name || 'Режим';
            presetDebugLog('Данные кнопки шаринга:', {
                modeId: modeId,
                modeName: modeName,
                button: this
            });
            const shareModeName = document.getElementById('shareModeName');
            if (shareModeName) {
                shareModeName.textContent = modeName;
            }
            const shareCodeSection = document.getElementById('shareCodeSection');
            const shareLinkSection = document.getElementById('shareLinkSection');
            if (shareCodeSection) shareCodeSection.classList.add('d-none');
            if (shareLinkSection) shareLinkSection.classList.add('d-none');
            window.currentShareCode = null;
            window.currentShareModeId = modeId;
            shareModalElement.style.display = 'block';
            presetDebugLog('Модальное окно шаринга открыто');
        });
    });
    initShareModalButtons();
}
function initShareModalButtons() {
    const shareCodeBtn = document.getElementById('shareCodeBtn');
    const shareLinkBtn = document.getElementById('shareLinkBtn');
    const copyCodeBtn = document.getElementById('copyCodeBtn');
    const copyLinkBtn = document.getElementById('copyLinkBtn');
    function setupInputField(field, value) {
        if (!field) return;
        field.value = value;
        field.addEventListener('focus', function() {
            this.select();
        });
        field.style.resize = 'none';
        field.readOnly = true;
        if (value.length > 40) {
            field.style.fontSize = '13px';
        }
        const container = field.closest('#shareCodeSection') || field.closest('#shareLinkSection');
        if (container) {
            container.classList.remove('d-none');
        }
    }
    if (shareCodeBtn) {
        shareCodeBtn.addEventListener('click', async function() {
            presetDebugLog('Клик по кнопке "Поделиться кодом"', { currentModeId: window.currentShareModeId });
            if (!window.currentShareModeId) {
                console.error('ID режима не определен');
                return;
            }
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Получение кода...';
            this.disabled = true;
            try {
                const requestedModeId = window.currentShareModeId;
                const shareCode = await generateShareCode(requestedModeId);
                if (window.currentShareModeId !== requestedModeId) return;
                presetDebugLog('Получен код шаринга:', shareCode);
                if (shareCode) {
                    const shareCodeDisplay = document.getElementById('shareCodeDisplay');
                    const shareCodeSection = document.getElementById('shareCodeSection');
                    const shareLinkSection = document.getElementById('shareLinkSection');
                    setupInputField(shareCodeDisplay, shareCode);
                    if (shareCodeSection) shareCodeSection.classList.remove('d-none');
                    if (shareLinkSection) shareLinkSection.classList.add('d-none');
                    window.currentShareCode = shareCode;
                }
            } catch (error) {
                console.error('Ошибка при получении кода шаринга:', error);
                alert('Не удалось получить код для шаринга: ' + error.message);
            } finally {
                this.innerHTML = '<i class="bi bi-key me-2"></i> Поделиться кодом';
                this.disabled = false;
            }
        });
    }
    if (shareLinkBtn) {
        shareLinkBtn.addEventListener('click', async function() {
            presetDebugLog('Клик по кнопке "Поделиться ссылкой"', { currentModeId: window.currentShareModeId });
            if (!window.currentShareModeId) {
                console.error('ID режима не определен');
                return;
            }
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Создание ссылки...';
            this.disabled = true;
            try {
                const requestedModeId = window.currentShareModeId;
                let shareCode = window.currentShareCode;
                if (!shareCode) {
                    shareCode = await generateShareCode(requestedModeId);
                    if (window.currentShareModeId !== requestedModeId) return;
                    presetDebugLog('Получен новый код шаринга для ссылки:', shareCode);
                    window.currentShareCode = shareCode;
                }
                if (shareCode) {
                    const shareLinkDisplay = document.getElementById('shareLinkDisplay');
                    const shareCodeSection = document.getElementById('shareCodeSection');
                    const shareLinkSection = document.getElementById('shareLinkSection');
                    const currentUrl = window.location.href;
                    const urlParts = currentUrl.split('?')[0];
                    const baseUrl = urlParts;
                    const shareLink = `${baseUrl}?code=${shareCode}`;
                    presetDebugLog('Сгенерирована ссылка:', shareLink);
                    setupInputField(shareLinkDisplay, shareLink);
                    if (shareLinkSection) shareLinkSection.classList.remove('d-none');
                    if (shareCodeSection) shareCodeSection.classList.add('d-none');
                }
            } catch (error) {
                console.error('Ошибка при создании ссылки для шаринга:', error);
                alert('Не удалось создать ссылку для шаринга: ' + error.message);
            } finally {
                this.innerHTML = '<i class="bi bi-link-45deg me-2"></i> Поделиться ссылкой';
                this.disabled = false;
            }
        });
    }
    if (copyCodeBtn) {
        copyCodeBtn.addEventListener('click', function() {
            const shareCodeDisplay = document.getElementById('shareCodeDisplay');
            if (shareCodeDisplay) {
                shareCodeDisplay.select();
                document.execCommand('copy');
                const originalText = this.innerHTML;
                this.innerHTML = '✅ Скопировано!';
                setTimeout(() => {
                    this.innerHTML = originalText;
                }, 2000);
            }
        });
    }
    if (copyLinkBtn) {
        copyLinkBtn.addEventListener('click', function() {
            const shareLinkDisplay = document.getElementById('shareLinkDisplay');
            if (shareLinkDisplay) {
                shareLinkDisplay.select();
                document.execCommand('copy');
                const originalText = this.innerHTML;
                this.innerHTML = '✅ Скопировано!';
                setTimeout(() => {
                    this.innerHTML = originalText;
                }, 2000);
            }
        });
    }
}
async function deletePresetMode(modeId, modeName) {
    if (!confirm(`Вы уверены, что хотите удалить режим "${modeName}"?`)) {
        return;
    }
    presetDebugLog("Попытка удалить режим ID:", modeId);
    try {
        const response = await fetch('/api/delete-preset-mode.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({ mode_id: parseInt(modeId) })
        });
        if (!response.ok) {
            const errorText = await response.text();
            console.error('Ошибка сервера при удалении:', response.status, response.statusText, errorText);
            throw new Error(`Ошибка сервера: ${response.status}. Убедитесь, что файл /api/delete-preset-mode.php существует.`);
        }
        const result = await response.json();
        if (result.success) {
            alert('Режим "' + modeName + '" успешно удален.');
            const modeCard = document.querySelector(`.preset-mode[data-id="${modeId}"]`);
            if (modeCard) {
                modeCard.remove();
            }
        } else {
            throw new Error(result.message || 'Неизвестная ошибка API при удалении');
        }
    } catch (error) {
        console.error('Ошибка при удалении режима:', error);
        alert('Не удалось удалить режим: ' + error.message);
    }
}
async function generateShareCode(modeId) {
    try {
        const response = await fetch('/api/share-preset-mode.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({ mode_id: parseInt(modeId) })
        });
        if (!response.ok) {
            const errorText = await response.text();
            console.error('Ошибка сервера при генерации кода:', response.status, response.statusText, errorText);
            throw new Error(`Ошибка сервера: ${response.status}. Убедитесь, что файл api/share-preset-mode.php существует.`);
        }
        const result = await response.json();
        if (result.success) {
            return result.share_code;
        } else {
            throw new Error(result.message || 'Неизвестная ошибка API');
        }
    } catch (error) {
        console.error('Ошибка при генерации кода:', error);
        alert('Не удалось создать код для шаринга: ' + error.message);
        return null;
    }
}
function setupImportByCode() {
    const importBtn = document.getElementById('importCodeBtn');
    const importInput = document.getElementById('importCodeInput');
    if (!importBtn || !importInput) {
        return;
    }
    importBtn.addEventListener('click', async function() {
        const code = importInput.value.trim();
        if (!code) {
            alert('Пожалуйста, введите код режима');
            return;
        }
        if (!/^[A-Z0-9]{8}$/.test(code)) {
            alert('Код должен состоять из 8 символов (буквы и цифры)');
            return;
        }
        importBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        importBtn.disabled = true;
        try {
            const response = await fetch('/api/import-preset-mode.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({ code: code })
            });
            if (!response.ok) {
                const errorText = await response.text();
                console.error('Ошибка сервера при импорте:', response.status, response.statusText, errorText);
                throw new Error(`Ошибка сервера: ${response.status}. Проверьте API импорта режима.`);
            }
            const result = await response.json();
            if (result.success) {
                alert('Режим успешно импортирован! Страница будет перезагружена для отображения нового режима.');
                importInput.value = '';
                window.location.reload();
            } else {
                alert('Ошибка: ' + (result.message || 'Не удалось импортировать режим'));
            }
        } catch (error) {
            alert('Ошибка: ' + error.message);
            console.error('Ошибка импорта режима:', error);
        } finally {
            importBtn.innerHTML = 'Импортировать';
            importBtn.disabled = false;
        }
    });
    importInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            importBtn.click();
        }
    });
}
</script>
