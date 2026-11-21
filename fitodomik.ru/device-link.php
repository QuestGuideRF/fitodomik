<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/headers.php';
require_once 'config/site.php';
require_once __DIR__ . '/api/device-pair-common.php';
$code = trim((string)($_GET['code'] ?? $_POST['code'] ?? ''));
if (!isLoggedIn()) {
    $redirect = '/device-link.php';
    if ($code !== '') {
        $redirect .= '?code=' . urlencode($code);
    }
    header('Location: ' . fitodomikUrl('/authentication/login.php') . '?redirect=' . urlencode($redirect));
    exit;
}
$status_message = '';
$error_message = '';
$device_name = '';
$request_status = '';
try {
    devicePairExpireOldRequests($pdo);
    if ($code === '') {
        $error_message = 'Код привязки не передан';
    } else {
        $isApproval = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve';
        if ($isApproval) {
            fitodomikRequireCsrf();
            $pdo->beginTransaction();
        }
        $lockClause = $isApproval ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare("SELECT * FROM device_pair_requests WHERE code = ? LIMIT 1" . $lockClause);
        $stmt->execute([$code]);
        $pair = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pair) {
            $error_message = 'Код привязки не найден';
        } else {
            $device_name = $pair['device_name'];
            $request_status = $pair['status'];
            if ($isApproval) {
                if ($pair['status'] !== 'pending') {
                    $error_message = 'Этот код уже использован или истёк';
                } elseif (strtotime($pair['expires_at']) < time()) {
                    $update = $pdo->prepare("
                        UPDATE device_pair_requests
                        SET status = 'expired'
                        WHERE code = ? AND status = 'pending'
                    ");
                    $update->execute([$code]);
                    $error_message = 'Код привязки истёк';
                    $request_status = 'expired';
                } else {
                    $device_id = fitodomikCreateDeviceId($pdo);
                    $user_id = (int)$_SESSION['user_id'];
                    fitodomikEnsureUserDevice($pdo, $user_id, $device_id, $pair['device_name'], false);
                    $update = $pdo->prepare("
                        UPDATE device_pair_requests
                        SET user_id = ?, status = 'approved', used_at = NOW(), device_id = ?
                        WHERE code = ? AND status = 'pending'
                    ");
                    $update->execute([$user_id, $device_id, $code]);
                    if ($update->rowCount() !== 1) {
                        throw new FitodomikConflictException('Код привязки уже использован');
                    }
                    $_SESSION['selected_device_id'] = $device_id;
                    $status_message = 'Устройство привязано. Можно вернуться в приложение.';
                    $request_status = 'approved';
                }
            } elseif ($pair['status'] === 'approved') {
                $status_message = 'Это устройство уже привязано.';
            } elseif ($pair['status'] === 'expired') {
                $error_message = 'Код привязки истёк. Сгенерируйте новый QR в приложении.';
            }
        }
        if ($isApproval && $pdo->inTransaction()) {
            $pdo->commit();
        }
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('device-link.php: ' . $e->getMessage());
    $error_message = 'Ошибка сервера. Попробуйте ещё раз.';
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(fitodomikCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <title>Привязка устройства - ФитоДомик</title>
    <link rel="stylesheet" href="/static/css/styles.v20261009.css">
    <link rel="stylesheet" href="/static/css/qr-scanner.v5.css">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--background-color);
            color: var(--text-color);
            font-family: Roboto, Arial, sans-serif;
        }
        .device-link-wrap {
            max-width: 560px;
            margin: 0 auto;
            padding: 32px 16px;
        }
        .device-link-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 12px 32px rgba(31, 42, 36, 0.12);
        }
        .device-link-card h1 {
            margin: 0 0 12px;
            font-size: 28px;
            color: var(--primary-color);
        }
        .device-name {
            padding: 14px;
            border-radius: 8px;
            background: var(--background);
            border: 1px solid var(--border-color);
            margin: 18px 0;
            font-weight: 700;
        }
        .message {
            padding: 12px;
            border-radius: 8px;
            margin: 14px 0;
        }
        .success {
            background: #dcfae6;
            color: #067647;
        }
        .error {
            background: #fee4e2;
            color: #b42318;
        }
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }
        .actions button,
        .actions a {
            appearance: none;
            border: 0;
            border-radius: 8px;
            padding: 12px 16px;
            background: var(--accent-surface);
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
        }
        .actions a.secondary {
            background: var(--button-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        [data-theme="dark"] body,
        body[data-theme="dark"] {
            background: var(--background-color);
            color: var(--text-color);
        }
        [data-theme="dark"] .device-link-card {
            background: var(--card-bg);
            border-color: var(--border-color);
        }
        [data-theme="dark"] .device-name {
            background: var(--background);
            border-color: var(--border-color);
        }
    </style>
</head>
<body>
    <main class="device-link-wrap">
        <section class="device-link-card">
            <h1>Привязка устройства</h1>
            <?php if ($device_name) : ?>
                <p>Вы подтверждаете привязку устройства к аккаунту:</p>
                <div class="device-name"><?php echo htmlspecialchars($device_name); ?></div>
            <?php endif; ?>
            <?php if ($status_message) : ?>
                <div class="message success"><?php echo htmlspecialchars($status_message); ?></div>
            <?php endif; ?>
            <?php if ($error_message) : ?>
                <div class="message error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <?php if (!$error_message && $request_status === 'pending') : ?>
                <p>После подтверждения приложение получит токен автоматически. Токен не хранится в QR-коде.</p>
                <form method="POST" class="actions">
                    <?php echo fitodomikCsrfInput(); ?>
                    <input type="hidden" name="code" value="<?php echo htmlspecialchars($code); ?>">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit">Привязать это устройство</button>
                    <a class="secondary" href="/">Отмена</a>
                </form>
            <?php else : ?>
                <div class="actions">
                    <button class="qr-scan-trigger" type="button" data-qr-scanner-open>Сканировать другой QR</button>
                    <a href="/devices.php">Мои устройства</a>
                    <a class="secondary" href="/">На главную</a>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script src="/static/js/theme.v20261009.js" defer></script>
    <script src="/static/js/device-qr-scanner.v5.js" defer></script>
</body>
</html>
