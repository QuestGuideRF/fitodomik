<?php
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: /admin/index.php');
    exit;
}
class AdminUserException extends RuntimeException
{
}
function adminUserIdFromPost(string $field = 'id'): int
{
    $userId = filter_var($_POST[$field] ?? null, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        throw new AdminUserException('Некорректный ID пользователя');
    }
    return $userId;
}
function adminEnsureUserCanLoseAdminRole(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $isAdmin = $stmt->fetchColumn();
    if ($isAdmin === false) {
        throw new AdminUserException('Пользователь не найден');
    }
    if ((int)$isAdmin !== 1) {
        return;
    }
    $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
    if ($adminCount <= 1) {
        throw new AdminUserException('Нельзя удалить или понизить последнего администратора');
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'create':
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $stmt->execute([$_POST['username']]);
                if ($stmt->fetchColumn() > 0) {
                    throw new AdminUserException("Имя пользователя уже используется");
                }
                $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, username, password, is_admin) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['first_name'],
                    $_POST['last_name'],
                    $_POST['username'],
                    $password_hash,
                    isset($_POST['is_admin']) ? 1 : 0
                ]);
                $success = "Пользователь успешно создан";
                break;
            case 'update':
                $userId = adminUserIdFromPost();
                $updates = [];
                $params = [];
                if ($_POST['username'] !== $_POST['old_username']) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
                    $stmt->execute([$_POST['username'], $userId]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new AdminUserException("Имя пользователя уже используется");
                    }
                }
                $updates[] = "first_name = ?";
                $updates[] = "last_name = ?";
                $updates[] = "username = ?";
                $updates[] = "is_admin = ?";
                $params = [
                    $_POST['first_name'],
                    $_POST['last_name'],
                    $_POST['username'],
                    isset($_POST['is_admin']) ? 1 : 0
                ];
                if (!isset($_POST['is_admin'])) {
                    adminEnsureUserCanLoseAdminRole($pdo, $userId);
                }
                if (!empty($_POST['password'])) {
                    $updates[] = "password = ?";
                    $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                }
                $params[] = $userId;
                $stmt = $pdo->prepare("UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?");
                $stmt->execute($params);
                $success = "Данные пользователя обновлены";
                break;
            case 'delete':
                $userId = adminUserIdFromPost();
                adminEnsureUserCanLoseAdminRole($pdo, $userId);
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $success = "Пользователь успешно удален";
                break;
            case 'toggle_lock':
                $userId = adminUserIdFromPost();
                $isLocked = isset($_POST['is_locked']) && (int)$_POST['is_locked'] === 1 ? 1 : 0;
                $stmt = $pdo->prepare("UPDATE users SET is_locked = ?, login_attempts = 0 WHERE id = ?");
                $stmt->execute([$isLocked, $userId]);
                $success = "Статус блокировки пользователя обновлен";
                break;
            case 'toggle_admin':
                $user_id = adminUserIdFromPost('user_id');
                $is_admin = isset($_POST['is_admin']) && (int)$_POST['is_admin'] === 1 ? 1 : 0;
                if ($user_id !== $_SESSION['user_id']) {
                    try {
                        if ($is_admin === 0) {
                            adminEnsureUserCanLoseAdminRole($pdo, $user_id);
                        }
                        $stmt = $pdo->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
                        $stmt->execute([$is_admin, $user_id]);
                        $success = $is_admin ? "Права администратора успешно предоставлены" : "Права администратора успешно сняты";
                    } catch(PDOException $e) {
                        $error = "Ошибка при изменении прав администратора";
                    }
                } else {
                    $error = "Вы не можете изменить свои собственные права администратора";
                }
                break;
            default:
                $error = 'Неизвестное действие';
                break;
        }
    } catch(AdminUserException $e) {
        $error = $e->getMessage();
    } catch(Throwable $e) {
        error_log('Admin user action failed: ' . $e->getMessage());
        $error = 'Не удалось выполнить действие';
    }
}
try {
    $stats = [
        'total' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'admins' => $pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn(),
        'locked' => $pdo->query("SELECT COUNT(*) FROM users WHERE is_locked = 1")->fetchColumn(),
        'active' => $pdo->query("SELECT COUNT(*) FROM users WHERE last_login > DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn()
    ];
} catch(PDOException $e) {
    $error = "Ошибка при получении статистики";
}
try {
    $stmt = $pdo->query("
        SELECT u.id, u.first_name, u.last_name, u.username,
               u.telegram_username, u.telegram_user_id, u.is_admin, u.is_locked,
               u.created_at,
               (SELECT COUNT(*) FROM user_devices ud
                WHERE ud.user_id = u.id AND ud.status = 'active') AS device_count
        FROM users u
        ORDER BY u.created_at DESC
    ");
    $users = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Ошибка при получении списка пользователей";
}
?>
<div class="container">
    <h1 class="mb-4">Управление пользователями</h1>
    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Всего пользователей</h5>
                    <h3><?php echo $stats['total']; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Администраторы</h5>
                    <h3><?php echo $stats['admins']; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Заблокированные</h5>
                    <h3><?php echo $stats['locked']; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Активные (30 дней)</h5>
                    <h3><?php echo $stats['active']; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title">Создать пользователя</h5>
            <form method="POST">
                <?php echo fitodomikCsrfInput(); ?>
                <input type="hidden" name="action" value="create">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="first_name" class="form-label">Имя</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="last_name" class="form-label">Фамилия</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" required>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="username" class="form-label">Имя пользователя</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="password" class="form-label">Пароль</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <div class="form-check mt-4">
                                <input type="checkbox" class="form-check-input" id="is_admin" name="is_admin">
                                <label class="form-check-label" for="is_admin">Администратор</label>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Создать пользователя</button>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Список пользователей</h5>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Имя</th>
                            <th>Telegram username</th>
                            <th>Telegram ID</th>
                            <th>Статус</th>
                            <th>Устройства</th>
                            <th>Дата регистрации</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td>
                                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                    <?php if ($user['is_admin']): ?>
                                        <span class="badge bg-success">Админ</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $user['telegram_username'] ? '@' . htmlspecialchars($user['telegram_username']) : 'Не подключён'; ?></td>
                                <td><?php echo $user['telegram_user_id'] ? htmlspecialchars((string)$user['telegram_user_id']) : '—'; ?></td>
                                <td>
                                    <?php if ($user['is_locked']): ?>
                                        <span class="badge bg-danger">Заблокирован</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Активен</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$user['device_count']; ?></td>
                                <td><?php echo date('d.m.Y H:i', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $user['id']; ?>">
                                            Редактировать
                                        </button>
                                        <form method="POST" style="display: inline;">
                                            <?php echo fitodomikCsrfInput(); ?>
                                            <input type="hidden" name="action" value="toggle_lock">
                                            <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                            <input type="hidden" name="is_locked" value="<?php echo $user['is_locked'] ? '0' : '1'; ?>">
                                            <button type="submit" class="btn btn-warning btn-sm">
                                                <?php echo $user['is_locked'] ? 'Разблокировать' : 'Заблокировать'; ?>
                                            </button>
                                        </form>
                                        <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                                            <form method="POST" style="display: inline;">
                                                <?php echo fitodomikCsrfInput(); ?>
                                                <input type="hidden" name="action" value="toggle_admin">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <input type="hidden" name="is_admin" value="<?php echo $user['is_admin'] ? '0' : '1'; ?>">
                                                <div class="form-check form-switch">
                                                    <?php $adminToggleId = 'user-admin-toggle-' . (int)$user['id']; ?>
                                                    <input type="checkbox"
                                                           id="<?php echo $adminToggleId; ?>"
                                                           class="form-check-input"
                                                           name="is_admin"
                                                           value="1"
                                                           <?php echo $user['is_admin'] ? 'checked' : ''; ?>
                                                           onchange="this.form.submit()">
                                                    <label class="visually-hidden" for="<?php echo $adminToggleId; ?>">
                                                        Права администратора для пользователя <?php echo htmlspecialchars((string)$user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                    </label>
                                                </div>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                                            <form method="POST" style="display: inline;">
                                                <?php echo fitodomikCsrfInput(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                                <input type="hidden" name="is_admin" value="<?php echo $user['is_admin']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Вы уверены?')">Удалить</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <div class="modal fade" id="editModal<?php echo $user['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Редактирование пользователя</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <?php echo fitodomikCsrfInput(); ?>
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="update">
                                                <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                                <input type="hidden" name="old_username" value="<?php echo $user['username']; ?>">
                                                <div class="mb-3">
                                                    <label for="edit_first_name<?php echo $user['id']; ?>" class="form-label">Имя</label>
                                                    <input type="text" class="form-control" id="edit_first_name<?php echo $user['id']; ?>" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="edit_last_name<?php echo $user['id']; ?>" class="form-label">Фамилия</label>
                                                    <input type="text" class="form-control" id="edit_last_name<?php echo $user['id']; ?>" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="edit_username<?php echo $user['id']; ?>" class="form-label">Имя пользователя</label>
                                                    <input type="text" class="form-control" id="edit_username<?php echo $user['id']; ?>" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="edit_password<?php echo $user['id']; ?>" class="form-label">Новый пароль (оставьте пустым, чтобы не менять)</label>
                                                    <input type="password" class="form-control" id="edit_password<?php echo $user['id']; ?>" name="password">
                                                </div>
                                                <div class="mb-3">
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input" id="edit_is_admin<?php echo $user['id']; ?>" name="is_admin" <?php echo $user['is_admin'] ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="edit_is_admin<?php echo $user['id']; ?>">Администратор</label>
                                                    </div>
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
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<style>
.admin-toggle-form {
    margin: 0;
    padding: 0;
}
.form-switch {
    padding-left: 2.5em;
}
.form-check-input {
    cursor: pointer;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.form-check-input').forEach(function(checkbox) {
        checkbox.addEventListener('change', function(e) {
            if (!confirm('Вы уверены, что хотите ' + (this.checked ? 'предоставить' : 'снять') + ' права администратора?')) {
                e.preventDefault();
                this.checked = !this.checked;
                return false;
            }
        });
    });
});
</script>
