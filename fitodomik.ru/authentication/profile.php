<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/headers.php';
require_once '../config/api_exceptions.php';
require_once '../config/file_security.php';
require_once '../config/theme.php';
header('Cache-Control: private, no-cache');
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
$success_message = '';
$error_message = '';
$theme = fitodomikCurrentTheme();
$upload_dir = dirname(__DIR__) . '/uploads/avatars';
function validateProfileAvatar(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new FitodomikUploadException('Ошибка при загрузке файла');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > 5 * 1024 * 1024) {
        throw new FitodomikUploadException('Размер файла не должен превышать 5 МБ');
    }
    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new FitodomikUploadException('Файл не прошёл проверку загрузки');
    }
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        throw new FitodomikUploadException('Разрешены только изображения JPEG, PNG, GIF и WebP');
    }
    $mimeType = (string)$imageInfo['mime'];
    if (class_exists('finfo')) {
        $detectedType = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
        if (is_string($detectedType) && $detectedType !== '') {
            $mimeType = $detectedType;
        }
    }
    if (!isset($allowedTypes[$mimeType])) {
        throw new FitodomikUploadException('Разрешены только изображения JPEG, PNG, GIF и WebP');
    }
    $width = (int)$imageInfo[0];
    $height = (int)$imageInfo[1];
    if ($width < 1 || $height < 1 || $width * $height > 30000000) {
        throw new FitodomikUploadException('Недопустимые размеры изображения');
    }
    return ['tmp_name' => $tmpName, 'extension' => $allowedTypes[$mimeType]];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['avatar'])) {
    fitodomikRequireCsrf();
    try {
        $avatar = validateProfileAvatar($_FILES['avatar']);
        if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
            throw new FitodomikStorageException('Не удалось подготовить папку для аватаров');
        }
        if (!is_writable($upload_dir)) {
            throw new FitodomikStorageException('Папка для аватаров недоступна для записи');
        }
        $filename = 'avatar_' . (int)$user['id'] . '_' . bin2hex(random_bytes(12)) . '.' . $avatar['extension'];
        $upload_path = $upload_dir . '/' . $filename;
        if (!move_uploaded_file($avatar['tmp_name'], $upload_path)) {
            throw new FitodomikUploadException('Ошибка при сохранении файла');
        }
        $oldAvatar = basename((string)($user['avatar'] ?? ''));
        $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
        if (!$stmt->execute([$filename, $user['id']])) {
            fitodomikSafeUnlinkAvatar($filename, $upload_dir, (int)$user['id']);
            throw new FitodomikStorageException('Ошибка при обновлении фотографии профиля');
        }
        if ($oldAvatar !== '' && $oldAvatar !== $filename) {
            fitodomikSafeUnlinkAvatar($oldAvatar, $upload_dir, (int)$user['id']);
        }
        $success_message = 'Фотография профиля успешно обновлена';
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $user = $stmt->fetch();
    } catch (FitodomikUploadException | FitodomikStorageException $e) {
        $error_message = $e->getMessage();
    } catch (Throwable $e) {
        error_log('profile avatar upload failed: ' . $e->getMessage());
        $error_message = 'Не удалось обновить фотографию профиля';
    }
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?php echo htmlspecialchars($theme, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Профиль - ФитоДомик</title>
    <link rel="stylesheet" href="/static/css/styles.v20261009.css">
    <link rel="apple-touch-icon" sizes="180x180" href="/static/icon/apple-touch-icon.v10.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/static/icon/favicon-32x32.v10.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/static/icon/favicon-16x16.v10.png">
    <link rel="manifest" href="/site.v10.webmanifest">
    <link rel="shortcut icon" href="/static/icon/favicon.v10.ico">
</head>
<body>
    <div class="auth-container">
        <button id="theme-toggle" class="theme-toggle auth-theme-toggle" type="button" data-theme-toggle
                aria-label="Переключить цветовую тему" title="Переключить цветовую тему">
            <span class="theme-icon" aria-hidden="true">◐</span>
        </button>
        <div class="auth-form">
            <h2>Профиль пользователя</h2>
            <?php if ($error_message) : ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($success_message) : ?>
                <div class="success-message"><?php echo htmlspecialchars($success_message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            <?php endif; ?>
            <div class="profile-content">
                <div class="profile-info">
                    <div class="profile-field">
                        <span class="profile-label">Имя:</span>
                        <span><?php echo htmlspecialchars($user['first_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                    </div>
                    <div class="profile-field">
                        <span class="profile-label">Фамилия:</span>
                        <span><?php echo htmlspecialchars($user['last_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                    </div>
                    <div class="profile-field">
                        <span class="profile-label">Никнейм:</span>
                        <span><?php echo htmlspecialchars($user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                    </div>
                    <div class="profile-field">
                        <span class="profile-label">Telegram:</span>
                        <span>@<?php echo htmlspecialchars($user['telegram_username'] ?? 'Не указан', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                    </div>
                </div>
                <div class="profile-avatar">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar'])) : ?>
                            <img src="/media/avatars/<?php echo rawurlencode(basename((string)$user['avatar'])); ?>" alt="Фото профиля" loading="lazy" width="100" height="100">
                        <?php else : ?>
                            <div class="avatar-placeholder">
                                <span><?php echo htmlspecialchars(strtoupper(substr($user['first_name'], 0, 1)) . strtoupper(substr($user['last_name'], 0, 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <form method="POST" enctype="multipart/form-data" class="avatar-form">
                        <?php echo fitodomikCsrfInput(); ?>
                        <label for="avatar" class="avatar-upload-label">
                            <span class="upload-icon">📷</span>
                            Изменить фото
                        </label>
                        <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" class="avatar-input">
                    </form>
                </div>
            </div>
            <div class="profile-actions">
                <a href="settings.php" class="auth-button">Настройки</a>
                <a href="logout.php" class="auth-button logout">Выйти</a>
                <a href="../index.php" class="auth-button secondary">Вернуться на главную</a>
            </div>
        </div>
    </div>
    <script>
        document.querySelector('.avatar-input').addEventListener('change', function() {
            if (this.files && this.files[0]) {
                this.closest('form').submit();
            }
        });
    </script>
    <script src="/static/js/theme.v20261009.js" defer></script>
</body>
</html>
