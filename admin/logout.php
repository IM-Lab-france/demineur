<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_admin(false);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hash_equals(csrf_token(), (string) ($_POST['csrf_token'] ?? ''))) {
    $_SESSION = [];
    session_destroy();
}
header('Location: /admin/login.php');
