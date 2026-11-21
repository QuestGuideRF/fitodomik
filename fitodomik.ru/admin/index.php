<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session.php';
if (!isLoggedIn()) {
    header('Location: /authentication/login.php?redirect=' . urlencode('/admin/'));
    exit;
}
try {
    $adminStatus = $pdo->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
    $adminStatus->execute([(int)$_SESSION['user_id']]);
    $isCurrentUserAdmin = (bool)$adminStatus->fetchColumn();
    $_SESSION['is_admin'] = $isCurrentUserAdmin;
} catch (PDOException $e) {
    error_log('Admin permission check failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Сервис временно недоступен');
}
if (!$isCurrentUserAdmin) {
    http_response_code(403);
    header('Cache-Control: private, no-store');
    $pageTitle = 'Доступ запрещён — ФитоДомик';
    $pageDescription = 'У текущего аккаунта нет прав для просмотра административной панели ФитоДомика.';
    $pageRobots = 'noindex, nofollow';
    ob_start();
    ?>
    <section class="admin-access-denied" aria-labelledby="admin-access-denied-title">
        <div class="admin-access-denied__content">
            <div class="admin-access-denied__code" aria-hidden="true">403</div>
            <h1 id="admin-access-denied-title">У вас нет прав для этой страницы</h1>
            <p>Административная панель доступна только аккаунтам с правами администратора.</p>
            <div class="admin-access-denied__actions">
                <a class="btn btn-success" href="/">На главную</a>
                <a class="btn btn-outline-success" href="/farm.php">В личный кабинет</a>
            </div>
        </div>
    </section>
    <style>
        .admin-access-denied {
            min-height: min(70vh, 720px);
            display: grid;
            place-items: center;
            padding: 3rem 1rem;
        }
        .admin-access-denied__content {
            width: min(100%, 680px);
            text-align: center;
        }
        .admin-access-denied__code {
            color: var(--bs-success);
            font-size: clamp(4rem, 16vw, 8rem);
            font-weight: 800;
            line-height: 1;
        }
        .admin-access-denied h1 {
            margin: 1rem 0;
            font-size: clamp(1.75rem, 5vw, 2.6rem);
        }
        .admin-access-denied p {
            margin: 0 auto 2rem;
            color: var(--bs-secondary-color);
            font-size: 1.1rem;
        }
        .admin-access-denied__actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.75rem;
        }
        .admin-access-denied__actions .btn {
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        @media (max-width: 420px) {
            .admin-access-denied__actions {
                display: grid;
            }
            .admin-access-denied__actions .btn {
                width: 100%;
            }
        }
    </style>
    <?php
    $content = ob_get_clean();
    require_once __DIR__ . '/../layout.php';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fitodomikRequireCsrf();
}
$theme = ($_SESSION['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
if (isset($_POST['theme'])) {
    $theme = $_POST['theme'] === 'dark' ? 'dark' : 'light';
    $_SESSION['theme'] = $theme;
}
$section = $_GET['section'] ?? 'news';
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?php echo htmlspecialchars($theme, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель</title>
    <link rel="apple-touch-icon" sizes="180x180" href="/icon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/icon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/icon/favicon-16x16.png">
    <link rel="icon" type="image/x-icon" href="/icon/favicon.ico">
    <link rel="manifest" href="/icon/site.webmanifest">
    <link href="/assets/vendor/bootstrap/5.3.0/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap-icons.css?v=5" rel="stylesheet">
    <style>
        @font-face {
            font-family: "Manrope";
            src: url("/assets/fonts/site/manrope-cyrillic.woff2") format("woff2");
            font-style: normal;
            font-weight: 400 800;
            font-display: swap;
            unicode-range: U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116;
        }
        :root[data-theme="light"] {
            --bg-color: #f6f8ef;
            --text-color: #13251a;
            --sidebar-bg: #1f7a3b;
            --card-bg: rgba(255, 255, 255, 0.92);
            --border-color: rgba(47, 143, 70, 0.16);
            --hover-bg: rgba(47, 143, 70, 0.1);
            --muted-text: #667568;
            --admin-primary: #2f8f46;
            --admin-primary-dark: #166534;
            --admin-soft: #dff4d7;
            --admin-shadow: 0 18px 55px rgba(38, 82, 48, 0.12);
        }
        :root[data-theme="dark"] {
            --bg-color: #0d1711;
            --text-color: #edf8ee;
            --sidebar-bg: #102d1c;
            --card-bg: rgba(20, 33, 24, 0.94);
            --border-color: rgba(164, 230, 173, 0.18);
            --hover-bg: rgba(123, 216, 143, 0.12);
            --muted-text: #a9bcae;
            --admin-primary: #7bd88f;
            --admin-primary-dark: #a7f3b8;
            --admin-soft: rgba(123, 216, 143, 0.16);
            --admin-shadow: 0 22px 65px rgba(0, 0, 0, 0.32);
        }
        body {
            background:
                radial-gradient(circle at 8% 8%, rgba(183, 216, 121, 0.24), transparent 28rem),
                radial-gradient(circle at 92% 18%, rgba(47, 143, 70, 0.16), transparent 30rem),
                var(--bg-color);
            color: var(--text-color);
            font-family: "Manrope", "Segoe UI", sans-serif;
            min-height: 100vh;
        }
        .sidebar {
            position: sticky;
            top: 0;
            background:
                radial-gradient(circle at 20% 0%, rgba(183, 216, 121, 0.26), transparent 14rem),
                linear-gradient(180deg, rgba(22, 101, 52, 0.98), rgba(31, 122, 59, 0.94));
            min-height: 100vh;
            padding: 24px 18px;
            border-right: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow: 16px 0 42px rgba(22, 101, 52, 0.18);
        }
        .sidebar h4 {
            color: #fff;
            font-weight: 800;
            letter-spacing: -0.03em;
        }
        .card {
            background-color: var(--card-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
            border-radius: 22px;
            box-shadow: var(--admin-shadow);
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.86);
            border: 1px solid transparent;
            border-radius: 16px;
            padding: 0.78rem 1rem;
            margin-bottom: 0.35rem;
            font-weight: 700;
            transition: all 0.2s ease;
        }
        .sidebar .nav-link:hover {
            color: #fff;
            background-color: rgba(255, 255, 255, 0.13);
            border-color: rgba(255, 255, 255, 0.18);
            transform: translateX(4px);
        }
        .sidebar .nav-link.active {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.24), rgba(255, 255, 255, 0.12));
            border-color: rgba(255, 255, 255, 0.18);
            color: #fff;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.16);
        }
        .sidebar .form-check-label {
            color: rgba(255, 255, 255, 0.86);
            font-weight: 600;
        }
        .form-check-input:checked {
            background-color: var(--admin-primary);
            border-color: var(--admin-primary);
        }
        .home-button {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            color: #fff;
            text-decoration: none;
            border-radius: 16px;
            transition: all 0.2s ease;
            background-color: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        .home-button:hover {
            background-color: rgba(255, 255, 255, 0.18);
            color: #fff;
            transform: translateY(-2px);
        }
        .home-button i {
            margin-right: 0.5rem;
        }
        .form-control {
            background-color: var(--card-bg);
            border-color: var(--border-color);
            color: var(--text-color);
            border-radius: 14px;
        }
        .form-control:focus {
            background-color: var(--card-bg);
            border-color: var(--admin-primary);
            color: var(--text-color);
            box-shadow: 0 0 0 0.25rem rgba(47, 143, 70, 0.18);
        }
        pre, code {
            background-color: var(--card-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 1rem;
        }
        .table {
            color: var(--text-color);
        }
        .table td, .table th {
            border-color: var(--border-color);
        }
        .text-muted {
            color: var(--muted-text) !important;
        }
        .btn-primary,
        .btn-success {
            background: linear-gradient(135deg, var(--admin-primary-dark), var(--admin-primary)) !important;
            border-color: transparent !important;
            color: #fff;
            border-radius: 999px;
            font-weight: 700;
        }
        .btn-primary:hover,
        .btn-success:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 28px rgba(47, 143, 70, 0.22);
        }
        .btn-secondary {
            border-radius: 999px;
        }
        [data-theme="dark"] .form-select,
        [data-theme="dark"] .form-control {
            background-color: var(--card-bg) !important;
            border-color: var(--border-color) !important;
            color: var(--text-color) !important;
        }
        [data-theme="dark"] .form-select option {
            background-color: var(--card-bg) !important;
            color: var(--text-color) !important;
        }
        [data-theme="dark"] .modal-content {
            background-color: var(--card-bg) !important;
            border-color: var(--border-color) !important;
        }
        [data-theme="dark"] .modal-header,
        [data-theme="dark"] .modal-footer {
            border-color: var(--border-color) !important;
            background-color: var(--sidebar-bg) !important;
        }
        [data-theme="dark"] .modal-body {
            background-color: var(--card-bg) !important;
        }
        [data-theme="dark"] .form-label {
            color: var(--text-color) !important;
        }
        [data-theme="dark"] .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }
        [data-theme="dark"] .form-select {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23ffffff' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") !important;
        }
        [data-theme="dark"] .form-control:disabled,
        [data-theme="dark"] .form-control[readonly],
        [data-theme="dark"] .form-select:disabled {
            background-color: var(--sidebar-bg) !important;
            color: var(--muted-text) !important;
        }
        [data-theme="dark"] .form-control:focus,
        [data-theme="dark"] .form-select:focus {
            background-color: var(--card-bg) !important;
            border-color: var(--admin-primary) !important;
            color: var(--text-color) !important;
            box-shadow: 0 0 0 0.25rem rgba(47, 143, 70, 0.22) !important;
        }
        [data-theme="dark"] .table {
            color: var(--text-color) !important;
            border-color: var(--border-color) !important;
        }
        [data-theme="dark"] .table td,
        [data-theme="dark"] .table th {
            border-color: var(--border-color) !important;
            color: var(--text-color) !important;
            background-color: var(--card-bg) !important;
        }
        [data-theme="dark"] .table thead th {
            background-color: var(--sidebar-bg) !important;
            border-bottom: 2px solid var(--border-color) !important;
        }
        [data-theme="dark"] .table tbody tr {
            background-color: var(--card-bg) !important;
        }
        [data-theme="dark"] .table tbody tr:hover {
            background-color: var(--hover-bg) !important;
        }
        [data-theme="dark"] .table-responsive {
            background-color: var(--card-bg) !important;
        }
        [data-theme="dark"] .table .btn-primary {
            background-color: var(--admin-primary) !important;
            border-color: var(--admin-primary) !important;
            color: #fff !important;
        }
        [data-theme="dark"] .table .btn-danger {
            background-color: #b42318 !important;
            border-color: #b42318 !important;
            color: #fff !important;
        }
        [data-theme="dark"] .table .btn-primary:hover {
            background-color: var(--admin-primary-dark) !important;
            border-color: var(--admin-primary-dark) !important;
        }
        [data-theme="dark"] .table .btn-danger:hover {
            background-color: #8f1c13 !important;
            border-color: #8f1c13 !important;
        }
        [data-theme="dark"] .modal-content {
            background-color: var(--card-bg) !important;
            border: 1px solid var(--border-color) !important;
        }
        [data-theme="dark"] .modal-header,
        [data-theme="dark"] .modal-footer {
            border-color: var(--border-color) !important;
            background-color: var(--sidebar-bg) !important;
        }
        [data-theme="dark"] .modal-body {
            background-color: var(--card-bg) !important;
            color: var(--text-color) !important;
        }
        [data-theme="dark"] .modal-title {
            color: var(--text-color) !important;
        }
        [data-theme="dark"] .form-control,
        [data-theme="dark"] .form-select {
            background-color: var(--sidebar-bg) !important;
            border-color: var(--border-color) !important;
            color: var(--text-color) !important;
        }
        [data-theme="dark"] .form-control:focus,
        [data-theme="dark"] .form-select:focus {
            background-color: var(--sidebar-bg) !important;
            border-color: var(--admin-primary) !important;
            color: var(--text-color) !important;
            box-shadow: 0 0 0 0.25rem rgba(47, 143, 70, 0.22) !important;
        }
        [data-theme="dark"] .form-select {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23ffffff' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") !important;
        }
        [data-theme="dark"] .form-select option {
            background-color: var(--sidebar-bg) !important;
            color: var(--text-color) !important;
        }
        [data-theme="dark"] .form-label {
            color: var(--text-color) !important;
        }
        [data-theme="dark"] .btn-secondary {
            background-color: var(--sidebar-bg) !important;
            border-color: var(--border-color) !important;
            color: var(--text-color) !important;
        }
        [data-theme="dark"] .btn-secondary:hover {
            background-color: var(--hover-bg) !important;
            border-color: var(--border-color) !important;
        }
        [data-theme="dark"] .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%) !important;
        }
        @media (max-width: 767.98px) {
            .sidebar {
                position: static;
                min-height: auto;
                border-radius: 0 0 24px 24px;
            }
            .nav.flex-column {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0.4rem;
            }
            .sidebar .nav-link {
                margin-bottom: 0;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
                <div class="col-md-3 col-lg-2 sidebar">
                <h4 class="mb-4">Админ-панель</h4>
                <a href="/" class="home-button">
                    <i class="bi bi-house-door"></i>
                    На главную
                </a>
                <form method="POST" class="mb-4">
                    <?php echo fitodomikCsrfInput(); ?>
                    <div class="form-check">
                        <input type="radio" class="form-check-input" name="theme" value="light" id="lightTheme" <?php echo $theme === 'light' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="lightTheme">Светлая тема</label>
                    </div>
                    <div class="form-check">
                        <input type="radio" class="form-check-input" name="theme" value="dark" id="darkTheme" <?php echo $theme === 'dark' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="darkTheme">Темная тема</label>
                    </div>
                </form>
                <div class="nav flex-column nav-pills">
                    <a class="nav-link <?php echo $section === 'news' ? 'active' : ''; ?>" href="?section=news">Новости</a>
                    <a class="nav-link <?php echo $section === 'info' ? 'active' : ''; ?>" href="?section=info">О проекте</a>
                    <a class="nav-link <?php echo $section === 'gallery' ? 'active' : ''; ?>" href="?section=gallery">Галерея</a>
                    <a class="nav-link <?php echo $section === 'code' ? 'active' : ''; ?>" href="?section=code">Код</a>
                    <a class="nav-link <?php echo $section === 'components' ? 'active' : ''; ?>" href="?section=components">Компоненты</a>
                    <a class="nav-link <?php echo $section === 'feedback' ? 'active' : ''; ?>" href="?section=feedback">Обратная связь</a>
                    <a class="nav-link <?php echo $section === 'users' ? 'active' : ''; ?>" href="?section=users">Пользователи</a>
                </div>
            </div>
            <div class="col-md-9 col-lg-10 p-4">
                <?php
                switch ($section) {
                    case 'news':
                        include_once __DIR__ . '/sections/news.php';
                        break;
                    case 'info':
                        include_once __DIR__ . '/sections/info.php';
                        break;
                    case 'gallery':
                        include_once __DIR__ . '/sections/gallery.php';
                        break;
                    case 'code':
                        include_once __DIR__ . '/sections/code.php';
                        break;
                    case 'components':
                        include_once __DIR__ . '/sections/components.php';
                        break;
                    case 'feedback':
                        include_once __DIR__ . '/sections/feedback.php';
                        break;
                    case 'users':
                        include_once __DIR__ . '/sections/users.php';
                        break;
                    default:
                        include_once __DIR__ . '/sections/dashboard.php';
                        break;
                }
                ?>
            </div>
        </div>
    </div>
    <script src="/assets/vendor/bootstrap/5.3.0/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('input[name="theme"]').forEach(input => {
            input.addEventListener('change', () => input.form.submit());
        });
    </script>
</body>
</html>
