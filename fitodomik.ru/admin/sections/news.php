<?php
const ADMIN_NEWS_DATETIME_FORMAT = 'Y-m-d H:i:s';
$normalizeDateTime = static function ($value): string {
    if (empty($value)) {
        return date(ADMIN_NEWS_DATETIME_FORMAT);
    }
    $timestamp = strtotime((string)$value);
    return $timestamp ? date(ADMIN_NEWS_DATETIME_FORMAT, $timestamp) : date(ADMIN_NEWS_DATETIME_FORMAT);
};
$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'create':
                $created_at = $normalizeDateTime($_POST['created_at'] ?? null);
                $stmt = $pdo->prepare("INSERT INTO posts (title, content, created_at) VALUES (?, ?, ?)");
                $stmt->execute([$_POST['title'], $_POST['content'], $created_at]);
                $success = "Новость успешно добавлена";
                break;
            case 'update':
                $created_at = $normalizeDateTime($_POST['created_at'] ?? null);
                $stmt = $pdo->prepare("UPDATE posts SET title = ?, content = ?, created_at = ? WHERE id = ?");
                $stmt->execute([$_POST['title'], $_POST['content'], $created_at, $_POST['id']]);
                $success = "Новость успешно обновлена";
                break;
            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $success = "Новость успешно удалена";
                break;
            default:
                $error = 'Неизвестное действие';
                break;
        }
    } catch(Throwable $e) {
        error_log('Admin news action failed: ' . $e->getMessage());
        $error = 'Не удалось выполнить действие';
    }
}
try {
    $stmt = $pdo->prepare("SELECT * FROM posts ORDER BY created_at DESC, id DESC");
    $stmt->execute();
    $news = $stmt->fetchAll();
} catch(PDOException $e) {
    error_log('Admin news list failed: ' . $e->getMessage());
    $error = 'Не удалось получить список новостей';
    $news = [];
}
?>
<div class="container">
    <h1 class="mb-4">Управление новостями</h1>
    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title">Добавить новость</h5>
            <form method="POST">
                <?php echo fitodomikCsrfInput(); ?>
                <input type="hidden" name="action" value="create">
                <div class="mb-3">
                    <label for="title" class="form-label">Заголовок</label>
                    <input type="text" class="form-control" id="title" name="title" required>
                </div>
                <div class="mb-3">
                    <label for="content" class="form-label">Содержание</label>
                    <textarea class="form-control" id="content" name="content" rows="5" required></textarea>
                </div>
                <div class="mb-3">
                    <label for="created_at" class="form-label">Дата и время публикации</label>
                    <input type="datetime-local" class="form-control" id="created_at" name="created_at">
                    <div class="form-text">Оставьте пустым, чтобы использовать текущее время</div>
                </div>
                <button type="submit" class="btn btn-primary">Добавить новость</button>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Список новостей</h5>
            <?php if (empty($news)): ?>
                <p class="text-muted">Новостей пока нет</p>
            <?php else: ?>
                <?php foreach ($news as $item): ?>
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="card-title"><?php echo $escape($item['title']); ?></h5>
                                    <small class="text-muted">
                                        Дата: <?php echo date('d.m.Y H:i', strtotime($item['created_at'])); ?>
                                    </small>
                                </div>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $item['id']; ?>">
                                        Редактировать
                                    </button>
                                    <form method="POST" style="display: inline;">
                                        <?php echo fitodomikCsrfInput(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Вы уверены?')">Удалить</button>
                                    </form>
                                </div>
                            </div>
                            <p class="card-text"><?php echo nl2br($escape($item['content'])); ?></p>
                        </div>
                    </div>
                    <div class="modal fade" id="editModal<?php echo $item['id']; ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Редактирование новости</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST">
                                    <?php echo fitodomikCsrfInput(); ?>
                                    <div class="modal-body">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <div class="mb-3">
                                            <label for="edit_title<?php echo $item['id']; ?>" class="form-label">Заголовок</label>
                                            <input type="text" class="form-control" id="edit_title<?php echo $item['id']; ?>" name="title" value="<?php echo $escape($item['title']); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="edit_content<?php echo $item['id']; ?>" class="form-label">Содержание</label>
                                            <textarea class="form-control" id="edit_content<?php echo $item['id']; ?>" name="content" rows="5" required><?php echo $escape($item['content']); ?></textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label for="edit_created_at<?php echo $item['id']; ?>" class="form-label">Дата и время публикации</label>
                                            <input type="datetime-local" class="form-control" id="edit_created_at<?php echo $item['id']; ?>" name="created_at" value="<?php echo date('Y-m-d\TH:i', strtotime($item['created_at'])); ?>">
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                                        <button type="submit" class="btn btn-primary">Сохранить</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
