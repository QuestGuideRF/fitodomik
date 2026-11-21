<?php
// Legacy URL: use the validated feedback handler and canonical page.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_once __DIR__ . '/pages/feedback/submit_feedback.php';
    exit;
}
header('Location: /pages/feedback/', true, 301);
exit;
