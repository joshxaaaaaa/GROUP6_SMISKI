<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/db.php';

if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit;
}

$db = getDB();

$productCount = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$customerCount = $db->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
$orderCount = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$auditCount = $db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
$messageCount = $db->query("SELECT COUNT(*) FROM contact_messages")->fetchColumn();

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body {
        background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
        color: #1f2937 !important;
        font-family: 'Outfit', sans-serif;
        min-height: 100vh;
    }

    .smiski-glass-card {
        background: rgba(255, 255, 255, 0.78) !important;
        backdrop-filter: blur(30px) saturate(180%);
        -webkit-backdrop-filter: blur(30px) saturate(180%);
        border: 1px solid rgba(255, 255, 255, 0.5) !important;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35), 0 0 20px rgba(134, 239, 172, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.8) !important;
        transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s ease, border-color 0.4s ease;
        border-radius: 24px;
    }
    
    .smiski-glass-card:hover {
        transform: translateY(-4px);
        border-color: #86efac !important;
        background: rgba(255, 255, 255, 0.88) !important;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4), 0 0 30px rgba(134, 239, 172, 0.5), inset 0 0 15px rgba(134, 239, 172, 0.3) !important;
    }

    .neon-glow-text {
        color: #064e3b !important;
        text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
    }

    .white-glow-heading {
        color: #ffffff !important;
        text-shadow: 0 0 10px rgba(255, 255, 255, 0.8), 0 0 20px rgba(134, 239, 172, 0.6), 0 2px 4px rgba(0,0,0,0.3);
    }

    .text-muted-green {
        color: #166534 !important;
        font-weight: 500;
    }

    .smiski-icon-box {
        width: 50px;
        height: 50px;
        background: rgba(220, 252, 231, 0.9);
        color: #15803d;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        margin-bottom: 1rem;
        border: 1px solid rgba(74, 222, 128, 0.6);
        box-shadow: 0 0 12px rgba(74, 222, 128, 0.4);
    }
</style>

<div class="container" style="padding: 2rem 1rem; max-width: 1200px; margin: 0 auto;">

    <div class="smiski-glass-card" style="padding: 2.5rem 2rem; margin-bottom: 2rem;">
        <h1 class="neon-glow-text" style="font-size: 2rem; font-weight: 800; margin-bottom: 0.5rem; letter-spacing: -0.025em;">
            Welcome back, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>!
        </h1>
        <p class="text-muted-green" style="font-size: 0.95rem;">
            Select any management section below to manage your store system.
        </p>
    </div>

    <h2 class="white-glow-heading" style="font-size: 1.35rem; font-weight: 700; margin-bottom: 1.25rem;">
        Dashboard Navigation Menu
    </h2>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem;">

        <a href="inventory.php" style="text-decoration: none; color: inherit;">
            <div class="smiski-glass-card" style="padding: 1.75rem;">
                <div class="smiski-icon-box">
                    <i class="fa-solid fa-boxes-stacked"></i>
                </div>
                <h3 style="font-size: 1.2rem; font-weight: 700; color: #064e3b; margin-bottom: 0.3rem;">Inventory</h3>
                <p class="text-muted-green" style="font-size: 0.85rem; margin-bottom: 1.25rem;">Manage products, prices, and stock counts</p>
                <div style="font-size: 1.25rem; font-weight: 700; color: #15803d; display: flex; align-items: center; justify-content: space-between;">
                    <span><?= $productCount ?> items</span>
                    <i class="fa-solid fa-arrow-right" style="font-size: 1rem;"></i>
                </div>
            </div>
        </a>

        <a href="customers.php" style="text-decoration: none; color: inherit;">
            <div class="smiski-glass-card" style="padding: 1.75rem;">
                <div class="smiski-icon-box">
                    <i class="fa-solid fa-users"></i>
                </div>
                <h3 style="font-size: 1.2rem; font-weight: 700; color: #064e3b; margin-bottom: 0.3rem;">Customers</h3>
                <p class="text-muted-green" style="font-size: 0.85rem; margin-bottom: 1.25rem;">View and manage registered user accounts</p>
                <div style="font-size: 1.25rem; font-weight: 700; color: #15803d; display: flex; align-items: center; justify-content: space-between;">
                    <span><?= $customerCount ?> records</span>
                    <i class="fa-solid fa-arrow-right" style="font-size: 1rem;"></i>
                </div>
            </div>
        </a>

        <a href="orders.php" style="text-decoration: none; color: inherit;">
            <div class="smiski-glass-card" style="padding: 1.75rem;">
                <div class="smiski-icon-box">
                    <i class="fa-solid fa-receipt"></i>
                </div>
                <h3 style="font-size: 1.2rem; font-weight: 700; color: #064e3b; margin-bottom: 0.3rem;">Orders</h3>
                <p class="text-muted-green" style="font-size: 0.85rem; margin-bottom: 1.25rem;">Process orders and update delivery status</p>
                <div style="font-size: 1.25rem; font-weight: 700; color: #15803d; display: flex; align-items: center; justify-content: space-between;">
                    <span><?= $orderCount ?> orders</span>
                    <i class="fa-solid fa-arrow-right" style="font-size: 1rem;"></i>
                </div>
            </div>
        </a>

        <a href="reports.php" style="text-decoration: none; color: inherit;">
            <div class="smiski-glass-card" style="padding: 1.75rem;">
                <div class="smiski-icon-box">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <h3 style="font-size: 1.2rem; font-weight: 700; color: #064e3b; margin-bottom: 0.3rem;">Reports & Analytics</h3>
                <p class="text-muted-green" style="font-size: 0.85rem; margin-bottom: 1.25rem;">View revenue trends, best sellers, and sales performance</p>
                <div style="font-size: 1.25rem; font-weight: 700; color: #15803d; display: flex; align-items: center; justify-content: space-between;">
                    <span>Analytics</span>
                    <i class="fa-solid fa-arrow-right" style="font-size: 1rem;"></i>
                </div>
            </div>
        </a>

        <a href="audit.php" style="text-decoration: none; color: inherit;">
            <div class="smiski-glass-card" style="padding: 1.75rem;">
                <div class="smiski-icon-box">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <h3 style="font-size: 1.2rem; font-weight: 700; color: #064e3b; margin-bottom: 0.3rem;">Audit Logs</h3>
                <p class="text-muted-green" style="font-size: 0.85rem; margin-bottom: 1.25rem;">Monitor system actions and user activities</p>
                <div style="font-size: 1.25rem; font-weight: 700; color: #15803d; display: flex; align-items: center; justify-content: space-between;">
                    <span><?= $auditCount ?> logs</span>
                    <i class="fa-solid fa-arrow-right" style="font-size: 1rem;"></i>
                </div>
            </div>
        </a>

        <a href="messages.php" style="text-decoration: none; color: inherit;">
            <div class="smiski-glass-card" style="padding: 1.75rem;">
                <div class="smiski-icon-box">
                    <i class="fa-solid fa-comments"></i>
                </div>
                <h3 style="font-size: 1.2rem; font-weight: 700; color: #064e3b; margin-bottom: 0.3rem;">Customer Messages</h3>
                <p class="text-muted-green" style="font-size: 0.85rem; margin-bottom: 1.25rem;">View and respond to Contact Us inquiries</p>
                <div style="font-size: 1.25rem; font-weight: 700; color: #15803d; display: flex; align-items: center; justify-content: space-between;">
                    <span><?= $messageCount ?> messages</span>
                    <i class="fa-solid fa-arrow-right" style="font-size: 1rem;"></i>
                </div>
            </div>
        </a>

    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>