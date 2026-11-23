<?php
require_once __DIR__ . '/../config/db.php';

if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['user_role'] ?? 'customer';
    $action = ($role === 'admin') ? 'ADMIN_LOGOUT' : 'CUSTOMER_LOGOUT';
    logActivity($_SESSION['user_id'], $action, ucfirst($role) . ' logged out: ' . ($_SESSION['user_email'] ?? ''));
}

// Burahin ang lahat kasama ang cart para pag naging guest, walang makikitang cart
session_unset();
session_destroy();

header("Location: index.php");
exit;