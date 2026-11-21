<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/headers.php';
require_once '../config/theme.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $theme = fitodomikCurrentTheme();
    ?>
    <!DOCTYPE html>
    <html lang="ru" data-theme="<?php echo htmlspecialchars($theme, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Выход - ФитоДомик</title>
        <link rel="stylesheet" href="/static/css/styles.v20261009.css">
    </head>
    <body>
        <main class="auth-container">
            <form method="post" class="auth-form">
                <?php echo fitodomikCsrfInput(); ?>
                <h2>Выйти из аккаунта?</h2>
                <div class="profile-actions">
                    <button type="submit" class="auth-button logout">Выйти</button>
                    <a href="profile.php" class="auth-button secondary">Отмена</a>
                </div>
            </form>
        </main>
    </body>
    </html>
    <?php
    exit;
}
fitodomikRequireCsrf();
fitodomikForgetCurrentRememberToken($pdo);
$_SESSION = array();
if (ini_get("session.use_cookies")) {
    setcookie(session_name(), '', fitodomikCookieOptions(time() - 3600));
}
session_destroy();
header("Location: login.php");
exit();
