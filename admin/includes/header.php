<?php
require_once __DIR__ . '/../../config/db.php';

if (!isAdminLoggedIn() && basename($_SERVER['PHP_SELF']) !== 'login.php') {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal - E-Commerce Store</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/form-feedback.css">
    <script src="../assets/js/form-validation.js" defer></script>
    <style>
        :root {
            --primary: #22c55e;          /* Vibrant Green */
            --primary-hover: #16a34a;    /* Darker Green */
            --dark-sidebar: #0f2e1b;     /* Deep Forest Matcha Header/Sidebar */
            --sidebar-hover: #14532d;    /* Sidebar Hover State */
            --sidebar-active: #22c55e;   /* Active Menu Green */
            --light-bg: #14532d;         /* Rich Dark Green Background */
            --gray-100: rgba(255, 255, 255, 0.05);
            --gray-200: rgba(187, 247, 208, 0.3);
            --gray-600: #dcfce7;
            --gray-800: #ffffff;
            --success: #22c55e;
            --danger: #f87171;
            --warning: #fbbf24;
            --radius: 12px;
            --shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Outfit', sans-serif; }

        body {
            background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
            color: #ffffff;
            min-height: 100vh;
        }

        .admin-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .admin-sidebar {
            width: 260px;
            background: rgba(15, 46, 27, 0.95);
            backdrop-filter: blur(12px);
            color: white;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 1000;
            transition: all 0.3s ease;
            box-shadow: 4px 0 25px rgba(0,0,0,0.3);
            border-right: 1px solid rgba(187, 247, 208, 0.2);
        }

        .admin-sidebar.collapsed {
            transform: translateX(-260px);
        }

        .sidebar-header {
            padding: 0 1.25rem;
            border-bottom: 1px solid rgba(187, 247, 208, 0.3);
            height: 73px;
            display: flex;
            align-items: center;
        }

        .admin-brand {
            font-size: 1.2rem;
            font-weight: 700;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .brand-text {
            display: flex;
            flex-direction: column;
        }

        .brand-title {
            font-size: 1.1rem;
            font-weight: 700;
            line-height: 1.2;
            color: white;
        }

        .brand-sub {
            font-size: 0.75rem;
            color: #86efac;
            font-weight: 400;
        }

        .sidebar-nav {
            flex: 1;
            padding: 1.25rem 0.85rem;
            overflow-y: auto;
        }

        .nav-section-title {
            font-size: 0.7rem;
            font-weight: 700;
            color: #86efac;
            letter-spacing: 0.05em;
            padding: 0 0.75rem;
            margin-bottom: 0.6rem;
            opacity: 0.8;
        }

        .sidebar-nav-item {
            color: #dcfce7;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.92rem;
            padding: 0.7rem 0.85rem;
            border-radius: 10px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.25rem;
        }

        .sidebar-nav-item i {
            font-size: 1.1rem;
            width: 22px;
            text-align: center;
            color: #86efac;
        }

        .sidebar-nav-item:hover {
            color: white;
            background: rgba(255, 255, 255, 0.1);
        }

        .sidebar-nav-item.active {
            color: white;
            background: var(--primary);
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        .sidebar-nav-item.active i {
            color: white;
        }

        .sidebar-footer {
            padding: 1rem;
            border-top: 1px solid rgba(187, 247, 208, 0.2);
            background: rgba(0,0,0,0.2);
        }

        .sidebar-store-link {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            color: #86efac;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.65rem 0.85rem;
            border-radius: 8px;
            background: rgba(20, 83, 45, 0.8);
            border: 1px solid rgba(134, 239, 172, 0.3);
            margin-bottom: 0.85rem;
            transition: background 0.2s;
        }
        .sidebar-store-link:hover {
            background: rgba(20, 83, 45, 1);
        }

        .user-info-box {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            margin-bottom: 0.85rem;
            padding: 0.4rem;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #86efac;
            font-size: 1.1rem;
        }

        .user-details {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .user-name {
            font-size: 0.85rem;
            font-weight: 600;
            color: white;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 0.75rem;
            color: #dcfce7;
            opacity: 0.8;
        }

        .btn-sidebar-logout {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            padding: 0.6rem;
            border-radius: 8px;
            background: rgba(248, 113, 113, 0.15);
            color: #fca5a5;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            border: 1px solid rgba(248, 113, 113, 0.3);
            transition: all 0.2s;
        }
        .btn-sidebar-logout:hover {
            background: #f87171;
            color: white;
        }

        /* Logout confirmation dialog */
        .logout-confirm-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(5, 46, 22, 0.85);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 5000;
            padding: 1rem;
        }
        .logout-confirm-overlay.active {
            display: flex;
        }
        .logout-confirm-card {
            background: rgba(255, 255, 255, 0.92);
            border-radius: 20px;
            padding: 2rem;
            max-width: 420px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
        }
        .logout-confirm-card h3 {
            color: #064e3b;
            font-size: 1.2rem;
            font-weight: 700;
            margin: 0 0 1.5rem;
        }
        .logout-confirm-actions {
            display: flex;
            gap: 0.75rem;
        }
        .logout-confirm-actions button {
            flex: 1;
            padding: 0.75rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            font-family: inherit;
        }
        .logout-confirm-cancel {
            background: #ffffff;
            color: #15803d;
            border: 1px solid rgba(74, 222, 128, 0.6);
        }
        .logout-confirm-ok {
            background: #15803d;
            color: #ffffff;
            border: 1px solid #4ade80;
        }

        /* Main Content Layout */
        .admin-main {
            margin-left: 260px;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        .admin-sidebar.collapsed + .admin-main {
            margin-left: 0;
        }

        /* Topbar Styles */
        .admin-topbar {
            background: rgba(20, 83, 45, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(187, 247, 208, 0.3);
            padding: 0 2rem;
            height: 73px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 900;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .sidebar-toggle-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(187, 247, 208, 0.3);
            border-radius: 8px;
            padding: 0.45rem 0.75rem;
            font-size: 1rem;
            color: #dcfce7;
            cursor: pointer;
            transition: all 0.2s;
        }
        .sidebar-toggle-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
        }

        .topbar-page-title h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }

        .topbar-store-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: #ffffff;
            background: rgba(34, 197, 94, 0.2);
            border: 1px solid rgba(134, 239, 172, 0.4);
            padding: 0.45rem 0.9rem;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .topbar-store-btn:hover {
            background: rgba(34, 197, 94, 0.4);
        }

        .topbar-user {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: #dcfce7;
        }

        .admin-content {
            padding: 2rem;
            flex: 1;
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
        }

        .container {
            width: 100%;
            margin: 0 auto;
        }

        /* Cards & Tables */
        .card {
            background: rgba(20, 83, 45, 0.95);
            backdrop-filter: blur(12px);
            border-radius: var(--radius);
            border: 1px solid rgba(187, 247, 208, 0.3);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            color: #ffffff;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        table.admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        table.admin-table th {
            background: rgba(255, 255, 255, 0.05);
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            color: #86efac;
            border-bottom: 2px solid rgba(187, 247, 208, 0.3);
        }

        table.admin-table td {
            padding: 0.9rem 1rem;
            border-bottom: 1px solid rgba(187, 247, 208, 0.2);
            color: #ffffff;
        }

        /* Buttons & Badges */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-primary { background: var(--primary); color: white; border: 1px solid #16a34a; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-secondary { background: rgba(255, 255, 255, 0.1); color: #ffffff; border: 1px solid rgba(187, 247, 208, 0.3); }
        .btn-secondary:hover { background: rgba(255, 255, 255, 0.2); }
        .btn-danger { background: #ef4444; color: white; border: 1px solid #dc2626; }
        .btn-danger:hover { background: #dc2626; }
        .btn-success { background: var(--success); color: white; border: 1px solid #16a34a; }
        .btn-success:hover { background: #16a34a; }
        .btn-warning { background: #f59e0b; color: white; border: 1px solid #d97706; }
        .btn-warning:hover { background: #d97706; }

        .badge {
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        .badge-success { background: rgba(34, 197, 94, 0.2); color: #86efac; border: 1px solid rgba(134, 239, 172, 0.4); }
        .badge-danger { background: rgba(248, 113, 113, 0.2); color: #fca5a5; border: 1px solid rgba(248, 113, 113, 0.4); }
        .badge-warning { background: rgba(251, 191, 36, 0.2); color: #fde68a; border: 1px solid rgba(251, 191, 36, 0.4); }
        .badge-info { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.4); }

        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(5, 46, 22, 0.8);
            backdrop-filter: blur(6px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        .modal-overlay.active { display: flex; }
        .modal-card {
            background: rgba(20, 83, 45, 0.95);
            backdrop-filter: blur(12px);
            border-radius: var(--radius);
            border: 1px solid rgba(187, 247, 208, 0.3);
            width: 100%;
            max-width: 550px;
            padding: 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
            color: #ffffff;
        }
        .modal-close {
            position: absolute;
            top: 1rem; right: 1rem;
            background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #dcfce7;
        }

        .alert {
            padding: 0.9rem 1.25rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .alert-success { background: rgba(34, 197, 94, 0.2); color: #86efac; border: 1px solid rgba(134, 239, 172, 0.4); }
        .alert-danger { background: rgba(248, 113, 113, 0.2); color: #fca5a5; border: 1px solid rgba(248, 113, 113, 0.4); }

        @media (max-width: 992px) {
            .admin-sidebar {
                transform: translateX(-260px);
            }
            .admin-sidebar.active {
                transform: translateX(0);
            }
            .admin-main {
                margin-left: 0 !important;
            }
        }
    </style>
</head>
<body>

<?php if (isAdminLoggedIn()): ?>
<div class="admin-wrapper">
    
    <aside class="admin-sidebar" id="adminSidebar">
        <div class="sidebar-header">
            <a href="index.php" class="admin-brand">
                <img src="../assets/ecommerce-logo.png" alt="Logo" style="height: 36px; width: 36px; object-fit: contain;">
                <div class="brand-text">
                    <span class="brand-title">Admin Portal</span>
                    <span class="brand-sub">Management System</span>
                </div>
            </a>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-title">MAIN MENU</div>
            
            <a href="index.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-gauge"></i> <span>Dashboard</span>
            </a>
            <a href="inventory.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) === 'inventory.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-boxes-stacked"></i> <span>Inventory</span>
            </a>
            <a href="customers.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) === 'customers.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-users"></i> <span>Customers</span>
            </a>
            <a href="orders.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-receipt"></i> <span>Orders</span>
            </a>
            
            <div class="nav-section-title" style="margin-top: 1.5rem;">MONITORING</div>
            
            <a href="reports.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) === 'reports.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-chart-line"></i> <span>Reports & Analytics</span>
            </a>
            <a href="audit.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) === 'audit.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-list-check"></i> <span>Audit Logs</span>
            </a>
            <a href="messages.php" class="sidebar-nav-item <?= basename($_SERVER['PHP_SELF']) === 'messages.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-comments"></i> <span>Messages</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="../customer/index.php" target="_blank" class="sidebar-store-link">
                <i class="fa-solid fa-store"></i> <span>View Customer Store</span>
            </a>
            <div class="user-info-box">
                <div class="user-avatar"><i class="fa-solid fa-user-gear"></i></div>
                <div class="user-details">
                    <span class="user-name"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></span>
                    <span class="user-role">Administrator</span>
                </div>
            </div>
            <a href="logout.php" class="btn-sidebar-logout" onclick="return openLogoutConfirm(event)">
                <i class="fa-solid fa-right-from-bracket"></i> <span>Logout Account</span>
            </a>
        </div>
    </aside>

    <!-- Logout Confirmation Dialog -->
    <div class="logout-confirm-overlay" id="logoutConfirmModal" role="dialog" aria-modal="true" aria-labelledby="logoutConfirmTitle" onclick="if (event.target === this) closeLogoutConfirm()">
        <div class="logout-confirm-card">
            <i class="fa-solid fa-right-from-bracket" style="font-size: 1.8rem; color: #15803d; margin-bottom: 0.85rem;"></i>
            <h3 id="logoutConfirmTitle">Are you sure you want to log out?</h3>
            <form method="POST" action="logout.php" class="logout-confirm-actions">
                <?= csrfField() ?>
                <input type="hidden" name="confirm_logout" value="1">
                <button type="button" class="logout-confirm-cancel" onclick="closeLogoutConfirm()">Cancel</button>
                <button type="submit" class="logout-confirm-ok">Confirm</button>
            </form>
        </div>
    </div>
    <script>
    function openLogoutConfirm(e) {
        const modal = document.getElementById('logoutConfirmModal');
        if (!modal) return true;
        if (e) e.preventDefault();
        modal.classList.add('active');
        return false;
    }
    function closeLogoutConfirm() {
        const modal = document.getElementById('logoutConfirmModal');
        if (modal) modal.classList.remove('active');
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeLogoutConfirm();
    });
    </script>

    
    <div class="admin-main">
        <header class="admin-topbar">
            <div class="topbar-left">
                <button type="button" class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle Sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-page-title">
                    <h2>
                        <?php
                        $page = basename($_SERVER['PHP_SELF']);
                        switch ($page) {
                            case 'reports.php': echo '<i class="fa-solid fa-chart-line" style="color: #86efac;"></i> Reports & Analytics'; break;
                            case 'inventory.php': echo '<i class="fa-solid fa-boxes-stacked" style="color: #86efac;"></i> Inventory Management'; break;
                            case 'customers.php': echo '<i class="fa-solid fa-users" style="color: #86efac;"></i> Customer Accounts'; break;
                            case 'orders.php': echo '<i class="fa-solid fa-receipt" style="color: #86efac;"></i> Order Records'; break;
                            case 'audit.php': echo '<i class="fa-solid fa-list-check" style="color: #86efac;"></i> System Audit Logs'; break;
                            case 'messages.php': echo '<i class="fa-solid fa-comments" style="color: #86efac;"></i> Customer Inquiries'; break;
                            default: echo '<i class="fa-solid fa-gauge" style="color: #86efac;"></i> Admin Dashboard'; break;
                        }
                        ?>
                    </h2>
                </div>
            </div>

            <div class="topbar-actions">
                <a href="../customer/index.php" class="topbar-store-btn" target="_blank">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Store Front
                </a>
                <div class="topbar-user">
                    <i class="fa-solid fa-user-circle" style="font-size: 1.4rem; color: #86efac;"></i>
                    <span><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></span>
                </div>
            </div>
        </header>

        <div class="admin-content">
<?php
    // Notices set with setFlash() that the page itself did not display
    $adminFlashSuccess = getFlash('success');
    $adminFlashError = getFlash('error');
    if ($adminFlashSuccess !== '' || $adminFlashError !== ''):
?>
            <div style="max-width: 1400px; margin: 1.5rem auto 0; padding: 0 1rem;">
                <?= alertBox('success', $adminFlashSuccess) ?>
                <?= alertBox('error', $adminFlashError) ?>
            </div>
<?php endif; ?>
<?php else: ?>
<div class="admin-main-login">
<?php endif; ?>
