<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/feedback_service.php';
require_once __DIR__ . '/../config/file_security.php';
// Run once per minute. One worker, at most 20 jobs and five delivery attempts.
$lock = $pdo->query("SELECT GET_LOCK(CONCAT(DATABASE(), ':feedback_outbox'),0)");
if ((int)$lock->fetchColumn() !== 1) { exit; }
try {
    for ($i=0; $i<20; $i++) {
        $pdo->beginTransaction();
        $row = $pdo->query("SELECT * FROM feedback_notification_outbox WHERE delivered_at IS NULL
            AND attempts<5 AND available_at<=NOW() ORDER BY available_at,id LIMIT 1 FOR UPDATE")->fetch();
        if (!$row) { $pdo->commit(); break; }
        $pdo->prepare('UPDATE feedback_notification_outbox SET attempts=attempts+1,available_at=DATE_ADD(NOW(),INTERVAL 2 MINUTE) WHERE id=?')->execute([$row['id']]);
        $pdo->commit();
        try {
            $stmt=$pdo->prepare('SELECT * FROM feedback_messages WHERE id=?'); $stmt->execute([$row['message_id']]); $message=$stmt->fetch();
            if ($message) {
                $message['ip']=$message['ip_address'];
                $image=null;
                if (!empty($message['image_path'])) {
                    $path=fitodomikPathInsideBase(basename($message['image_path']),dirname(__DIR__).'/uploads/feedback');
                    if ($path !== null) $image=['absolute_path'=>$path];
                }
                fitodomikFeedbackNotify($message,$image);
            }
            $pdo->prepare('UPDATE feedback_notification_outbox SET delivered_at=NOW() WHERE id=?')->execute([$row['id']]);
        } catch (Throwable $e) {
            $seconds=min(3600,60*(2**(int)$row['attempts']));
            $pdo->prepare('UPDATE feedback_notification_outbox SET available_at=DATE_ADD(NOW(),INTERVAL ? SECOND) WHERE id=?')->execute([$seconds,$row['id']]);
            error_log('Feedback job '.$row['id'].' delivery failed');
        }
    }
    $pdo->exec('DELETE FROM feedback_notification_outbox WHERE delivered_at < DATE_SUB(NOW(),INTERVAL 30 DAY)');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':feedback_outbox'))");
}
