<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/site.php';
require_once 'config/preset_import.php';
const FITODOMIK_IMPORT_MODE_PATH = '/import-mode.php';
const FITODOMIK_IMPORT_MODE_APPLE_ICON_PATH = '/security/image.php?file=icon/apple-touch-icon.png';
$error = null;
$success = false;
$confirmationRequired = false;
$mode = null;
$code = trim((string)($_POST['code'] ?? $_GET['code'] ?? ''));
if (!isset($_SESSION['user_id'])) {
    header('Location: /authentication/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
if (!empty($code)) {
    try {
        if (strlen($code) !== 8) {
            $error = 'Неверный формат кода режима';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM preset_modes WHERE share_code = ? LIMIT 1");
            $stmt->execute([$code]);
            $mode = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$mode) {
                $error = 'Режим с указанным кодом не найден';
            } elseif ((int)$mode['user_id'] === (int)$_SESSION['user_id']) {
                $error = 'Этот режим уже принадлежит вам';
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                fitodomikRequireCsrf();
                fitodomikImportPreset($pdo, (int)$_SESSION['user_id'], $mode);
                $success = true;
            } else {
                $confirmationRequired = true;
            }
        }
    } catch (PDOException $e) {
        error_log('Ошибка импорта режима: ' . $e->getMessage());
        $error = 'Произошла ошибка при импорте режима';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Импорт режима - ФитоДомик</title>
    <meta name="description" content="Импортируйте готовые режимы выращивания в вашу умную ферму ФитоДомик. Оптимизируйте параметры роста для различных типов растений с помощью проверенных шаблонов.">
    <meta name="keywords" content="ФитоДомик, умная ферма, импорт режима, шаблоны выращивания, параметры растений, оптимизация выращивания, обмен режимами">
    <meta name="robots" content="noindex, follow">
    <link rel="canonical" href="<?php echo htmlspecialchars(fitodomikUrl(FITODOMIK_IMPORT_MODE_PATH), ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:title" content="Импорт режима - ФитоДомик">
    <meta property="og:description" content="Импортируйте проверенные режимы выращивания растений в вашу умную ферму ФитоДомик">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars(fitodomikUrl(FITODOMIK_IMPORT_MODE_PATH), ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars(fitodomikUrl(FITODOMIK_IMPORT_MODE_APPLE_ICON_PATH), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet"
          href="/assets/vendor/bootstrap/5.3.0/bootstrap.min.css"
          >
    <link rel="stylesheet" href="/static/css/styles.v20261009.css">
    <link rel="apple-touch-icon" sizes="180x180" href="security/image.php?file=icon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="security/image.php?file=icon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="security/image.php?file=icon/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="shortcut icon" href="security/image.php?file=icon/favicon.ico">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebPage",
        "name": "Импорт режима - ФитоДомик",
        "description": "Страница для импорта пользовательских режимов выращивания растений в системе ФитоДомик",
        "publisher": {
            "@type": "Organization",
            "name": "ФитоДомик",
            "logo": {
                "@type": "ImageObject",
                "url": <?php echo json_encode(fitodomikUrl(FITODOMIK_IMPORT_MODE_APPLE_ICON_PATH), JSON_UNESCAPED_SLASHES); ?>
            }
        },
        "breadcrumb": {
            "@type": "BreadcrumbList",
            "itemListElement": [
                {
                    "@type": "ListItem",
                    "position": 1,
                    "name": "Главная",
                    "item": <?php echo json_encode(fitodomikUrl('/'), JSON_UNESCAPED_SLASHES); ?>
                },
                {
                    "@type": "ListItem",
                    "position": 2,
                    "name": "Импорт режима",
                    "item": <?php echo json_encode(fitodomikUrl(FITODOMIK_IMPORT_MODE_PATH), JSON_UNESCAPED_SLASHES); ?>
                }
            ]
        },
        "mainEntity": {
            "@type": "SoftwareApplication",
            "name": "ФитоДомик - Импорт режима",
            "applicationCategory": "Utility",
            "operatingSystem": "All"
        }
    }
    </script>
    <style>
        .import-container {
            max-width: 600px;
            margin: 50px auto;
            padding: 30px;
            border-radius: 10px;
            background-color: var(--card-bg);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .import-title {
            margin-bottom: 20px;
            color: var(--primary-color);
            text-align: center;
        }
        .import-icon {
            font-size: 48px;
            display: block;
            text-align: center;
            margin-bottom: 20px;
        }
        .import-message {
            margin-bottom: 30px;
            font-size: 18px;
            text-align: center;
        }
        .button-container {
            display: flex;
            justify-content: center;
            gap: 15px;
        }
    </style>
</head>
<body itemscope itemtype="https://schema.org/WebPage">
    <div class="container" itemprop="mainContentOfPage">
        <div class="import-container" itemscope itemtype="https://schema.org/SoftwareApplication">
            <?php if ($success) : ?>
                <span class="import-icon">✅</span>
                <h2 class="import-title" itemprop="name">Режим успешно импортирован</h2>
                <p class="import-message" itemprop="description">Режим был успешно добавлен в ваши пресеты.</p>
                <div class="button-container">
                    <a href="/farm.php" class="btn btn-primary">В кабинет</a>
                    <a href="/farm.php" class="btn btn-success">Перейти к режимам</a>
                </div>
            <?php elseif ($error) :
                ?>
                <span class="import-icon">❌</span>
                <h2 class="import-title" itemprop="name">Ошибка импорта</h2>
                <p class="import-message" itemprop="description"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
                <div class="button-container">
                    <a href="/farm.php" class="btn btn-primary">В кабинет</a>
                    <a href="/farm.php" class="btn btn-outline-secondary">Перейти к режимам</a>
                </div>
            <?php elseif ($confirmationRequired && is_array($mode)) : ?>
                <span class="import-icon">🌱</span>
                <h2 class="import-title" itemprop="name">Подтвердите импорт</h2>
                <p class="import-message" itemprop="description">
                    Добавить режим «<?php echo htmlspecialchars((string)$mode['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>» в ваш аккаунт?
                </p>
                <form method="post" action="import-mode.php" class="text-center">
                    <?php echo fitodomikCsrfInput(); ?>
                    <input type="hidden" name="code" value="<?php echo htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    <button class="btn btn-primary" type="submit">Импортировать режим</button>
                    <a href="/farm.php" class="btn btn-outline-secondary">Отмена</a>
                </form>
            <?php else : ?>
                <span class="import-icon">🔍</span>
                <h2 class="import-title" itemprop="name">Импорт режима</h2>
                <p class="import-message" itemprop="description">Для импорта режима введите код:</p>
                <form method="get" action="import-mode.php" class="mb-4" itemprop="potentialAction" itemscope itemtype="https://schema.org/SearchAction">
                    <meta itemprop="target" content="<?php echo htmlspecialchars(fitodomikUrl('/import-mode.php?code={code}'), ENT_QUOTES, 'UTF-8'); ?>"/>
                    <div class="input-group mb-3">
                        <label class="visually-hidden" for="import-mode-code">Код режима</label>
                        <input type="text" id="import-mode-code" class="form-control" name="code" placeholder="Введите 8-символьный код" maxlength="8" required itemprop="query-input">
                        <button class="btn btn-primary" type="submit">Импортировать</button>
                    </div>
                </form>
                <div class="text-center">
                    <a href="/farm.php" class="btn btn-outline-secondary">Вернуться назад</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script src="/assets/vendor/bootstrap/5.3.0/bootstrap.bundle.min.js"
            ></script>
</body>
</html>
