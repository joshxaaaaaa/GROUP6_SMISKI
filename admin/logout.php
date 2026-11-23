<?php
require_once __DIR__ . '/../config/db.php';

if (isAdminLoggedIn()) {
    logActivity($_SESSION['user_id'], 'ADMIN_LOGOUT', 'Admin logged out: ' . $_SESSION['user_email']);
}

session_unset();
session_destroy();

header("Location: login.php");
exit;