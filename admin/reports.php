<?php
require_once __DIR__ . '/../config/db.php';

if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit;
}

$db = getDB();

$totalRevenue = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status != 'cancelled'")->fetchColumn();
$totalOrders = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$deliveredOrders = $db->query("SELECT COUNT(*) FROM orders WHERE status = 'delivered'")->fetchColumn();
$cancelledOrders = $db->query("SELECT COUNT(*) FROM orders WHERE status = 'cancelled'")->fetchColumn();
$pendingOrders = $db->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$processingOrders = $db->query("SELECT COUNT(*) FROM orders WHERE status = 'processing'")->fetchColumn();
$shippedOrders = $db->query("SELECT COUNT(*) FROM orders WHERE status = 'shipped'")->fetchColumn();
$totalCustomers = $db->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
$totalProducts = $db->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();
$lowStockCount = $db->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= 10 AND status = 'active'")->fetchColumn();
$outOfStockCount = $db->query("SELECT COUNT(*) FROM products WHERE stock_quantity = 0 AND status = 'active'")->fetchColumn();
$avgOrderValue = $totalOrders > 0 ? $totalRevenue / ($totalOrders - $cancelledOrders ?: 1) : 0;

$todayRevenue = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE DATE(created_at) = CURDATE() AND status != 'cancelled'")->fetchColumn();
$todayOrders = $db->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$monthRevenue = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status != 'cancelled'")->fetchColumn();
$monthOrders = $db->query("SELECT COUNT(*) FROM orders WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetchColumn();

$paidRevenue = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE payment_status = 'paid' AND status != 'cancelled'")->fetchColumn();
$unpaidRevenue = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE payment_status != 'paid' AND status != 'cancelled'")->fetchColumn();

$topProducts = $db->query("
    SELECT oi.product_name, SUM(oi.quantity) AS total_qty, SUM(oi.subtotal) AS total_sales
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    WHERE o.status != 'cancelled'
    GROUP BY oi.product_name
    ORDER BY total_sales DESC
    LIMIT 8
")->fetchAll();

$salesByCategory = $db->query("
    SELECT c.name AS category_name, SUM(oi.subtotal) AS total_sales, SUM(oi.quantity) AS total_qty
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN categories c ON p.category_id = c.id
    JOIN orders o ON oi.order_id = o.id
    WHERE o.status != 'cancelled'
    GROUP BY c.name
    ORDER BY total_sales DESC
")->fetchAll();

$paymentMethods = $db->query("
    SELECT payment_method, COUNT(*) AS cnt, SUM(total_amount) AS total
    FROM orders WHERE status != 'cancelled'
    GROUP BY payment_method
    ORDER BY total DESC
")->fetchAll();

$dailySales = $db->query("
    SELECT DATE(created_at) AS sale_date, SUM(total_amount) AS daily_total, COUNT(*) AS daily_count
    FROM orders
    WHERE status != 'cancelled' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY sale_date ASC
")->fetchAll();

$topCustomers = $db->query("
    SELECT o.customer_name, COUNT(*) AS order_count, SUM(o.total_amount) AS total_spent
    FROM orders o
    WHERE o.status != 'cancelled'
    GROUP BY o.customer_name
    ORDER BY total_spent DESC
    LIMIT 5
")->fetchAll();

$recentOrders = $db->query("
    SELECT order_number, customer_name, total_amount, status, payment_method, created_at
    FROM orders ORDER BY id DESC LIMIT 8
")->fetchAll();

$lowStockProducts = $db->query("
    SELECT name, stock_quantity, price FROM products
    WHERE stock_quantity <= 10 AND status = 'active'
    ORDER BY stock_quantity ASC LIMIT 8
")->fetchAll();

$catColors = ['#15803d', '#166534', '#22c55e', '#4ade80', '#86efac', '#0284c7', '#6366f1', '#f59e0b'];

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body {
        background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
        color: #1f2937 !important;
        min-height: 100vh;
        font-family: 'Outfit', sans-serif;
    }

    /* White Frosted Glass Card Effect */
    .smiski-card {
        background: rgba(255, 255, 255, 0.78) !important;
        backdrop-filter: blur(30px) saturate(180%);
        -webkit-backdrop-filter: blur(30px) saturate(180%);
        border: 1px solid rgba(255, 255, 255, 0.5) !important;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35), 0 0 20px rgba(134, 239, 172, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.8) !important;
        transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s ease, border-color 0.4s ease;
        border-radius: 24px;
        padding: 2rem;
        margin-bottom: 2rem;
    }
    .smiski-card:hover {
        transform: translateY(-2px);
        border-color: #86efac !important;
        background: rgba(255, 255, 255, 0.88) !important;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4), 0 0 30px rgba(134, 239, 172, 0.5), inset 0 0 15px rgba(134, 239, 172, 0.3) !important;
    }

    /* Subcards inside sections */
    .smiski-subcard {
        background: rgba(255, 255, 255, 0.85) !important;
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
        border: 1px solid rgba(74, 222, 128, 0.5) !important;
        border-radius: 16px;
        box-shadow: inset 0 1px 3px rgba(255, 255, 255, 0.9), 0 5px 15px rgba(0, 0, 0, 0.1);
        padding: 1.25rem;
    }

    /* Pure White Glow Text for Headings on Dark Background */
    .white-glow-heading {
        color: #ffffff !important;
        text-shadow: 0 0 10px rgba(255, 255, 255, 0.8), 0 0 20px rgba(134, 239, 172, 0.6), 0 2px 4px rgba(0,0,0,0.3);
    }

    /* Green Glow-in-the-Dark Text Effect for White Backgrounds */
    .neon-glow-text {
        color: #064e3b !important;
        text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
    }

    .text-muted-green {
        color: #166534 !important;
        font-weight: 500;
    }

    /* Pure White Subtext for Dark Background */
    .white-subtext {
        color: rgba(255, 255, 255, 0.9) !important;
        font-weight: 500;
        text-shadow: 0 1px 3px rgba(0,0,0,0.3);
    }

    .custom-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
    }
    .custom-table th {
        background: rgba(220, 252, 231, 0.5);
        color: #064e3b;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 2px solid rgba(74, 222, 128, 0.4);
        padding: 1rem;
    }
    .custom-table td {
        padding: 0.9rem 1rem;
        border-bottom: 1px solid rgba(74, 222, 128, 0.2);
        font-size: 0.9rem;
        color: #1f2937;
    }
    .custom-table tr:hover {
        background: rgba(220, 252, 231, 0.4);
    }
</style>

<div class="container" style="padding: 2rem 1rem; max-width: 1400px; margin: 0 auto;">

    <!-- Page Header -->
    <div style="margin-bottom: 1.5rem;">
        <h2 class="white-glow-heading" style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.025em; margin: 0 0 0.25rem 0;">
            <i class="fa-solid fa-chart-line" style="color: #86efac;"></i> Analytics & Reports
        </h2>
        <p class="white-subtext" style="font-size: 0.9rem;">Comprehensive overview of sales performance, inventory health, and customer activity</p>
    </div>

    <!-- Top KPI Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 2rem;">
        
        <div class="smiski-card" style="padding: 1.5rem; margin-bottom: 0;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                <span class="text-muted-green" style="font-size: 0.85rem; font-weight: 700;"><i class="fa-solid fa-peso-sign"></i> Total Revenue</span>
                <div style="width: 38px; height: 38px; background: rgba(220, 252, 231, 0.9); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #15803d; border: 1px solid rgba(74, 222, 128, 0.6);"><i class="fa-solid fa-wallet"></i></div>
            </div>
            <div class="neon-glow-text" style="font-size: 1.8rem; font-weight: 800;">₱<?= number_format($totalRevenue, 2) ?></div>
            <div class="text-muted-green" style="font-size: 0.78rem; font-weight: 600; margin-top: 0.4rem;"><?= $totalOrders - $cancelledOrders ?> completed orders</div>
        </div>

        <div class="smiski-card" style="padding: 1.5rem; margin-bottom: 0;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                <span class="text-muted-green" style="font-size: 0.85rem; font-weight: 700;"><i class="fa-solid fa-calendar-day"></i> Today's Sales</span>
                <div style="width: 38px; height: 38px; background: rgba(220, 252, 231, 0.9); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #15803d; border: 1px solid rgba(74, 222, 128, 0.6);"><i class="fa-solid fa-chart-line"></i></div>
            </div>
            <div class="neon-glow-text" style="font-size: 1.8rem; font-weight: 800;">₱<?= number_format($todayRevenue, 2) ?></div>
            <div class="text-muted-green" style="font-size: 0.78rem; font-weight: 600; margin-top: 0.4rem;"><?= $todayOrders ?> orders today</div>
        </div>

        <div class="smiski-card" style="padding: 1.5rem; margin-bottom: 0;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                <span class="text-muted-green" style="font-size: 0.85rem; font-weight: 700;"><i class="fa-solid fa-calendar"></i> This Month</span>
                <div style="width: 38px; height: 38px; background: rgba(220, 252, 231, 0.9); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #15803d; border: 1px solid rgba(74, 222, 128, 0.6);"><i class="fa-solid fa-calendar-days"></i></div>
            </div>
            <div class="neon-glow-text" style="font-size: 1.8rem; font-weight: 800;">₱<?= number_format($monthRevenue, 2) ?></div>
            <div class="text-muted-green" style="font-size: 0.78rem; font-weight: 600; margin-top: 0.4rem;"><?= $monthOrders ?> orders this month</div>
        </div>

        <div class="smiski-card" style="padding: 1.5rem; margin-bottom: 0;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                <span class="text-muted-green" style="font-size: 0.85rem; font-weight: 700;"><i class="fa-solid fa-scale-balanced"></i> Avg. Order Value</span>
                <div style="width: 38px; height: 38px; background: rgba(220, 252, 231, 0.9); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #15803d; border: 1px solid rgba(74, 222, 128, 0.6);"><i class="fa-solid fa-chart-pie"></i></div>
            </div>
            <div class="neon-glow-text" style="font-size: 1.8rem; font-weight: 800;">₱<?= number_format($avgOrderValue, 2) ?></div>
            <div class="text-muted-green" style="font-size: 0.78rem; font-weight: 600; margin-top: 0.4rem;"><?= $totalCustomers ?> total customers</div>
        </div>
    </div>

    <!-- Charts Row 1 -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
        <div class="smiski-card" style="margin-bottom: 0;">
            <h3 class="neon-glow-text" style="font-size: 1.15rem; font-weight: 800; margin-bottom: 1.25rem;">
                <i class="fa-solid fa-chart-area" style="color: #15803d;"></i> Sales Trend (Last 30 Days)
            </h3>
            <div style="position: relative; height: 260px;">
                <canvas id="salesChart"></canvas>
            </div>
        </div>

        <div class="smiski-card" style="margin-bottom: 0;">
            <h3 class="neon-glow-text" style="font-size: 1.15rem; font-weight: 800; margin-bottom: 1.25rem;">
                <i class="fa-solid fa-circle-check" style="color: #15803d;"></i> Order Status Breakdown
            </h3>
            <div style="position: relative; height: 190px;">
                <canvas id="statusChart"></canvas>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 1rem; justify-content: center;">
                <span style="font-size: 0.75rem; padding: 0.25rem 0.6rem; background: rgba(254, 243, 199, 0.9); color: #92400e; border: 1px solid rgba(251, 191, 36, 0.6); border-radius: 12px; font-weight: 700;">Pending: <?= $pendingOrders ?></span>
                <span style="font-size: 0.75rem; padding: 0.25rem 0.6rem; background: rgba(238, 242, 255, 0.9); color: #3730a3; border: 1px solid rgba(129, 140, 248, 0.6); border-radius: 12px; font-weight: 700;">Processing: <?= $processingOrders ?></span>
                <span style="font-size: 0.75rem; padding: 0.25rem 0.6rem; background: rgba(224, 242, 254, 0.9); color: #0369a1; border: 1px solid rgba(56, 189, 248, 0.6); border-radius: 12px; font-weight: 700;">Shipped: <?= $shippedOrders ?></span>
                <span style="font-size: 0.75rem; padding: 0.25rem 0.6rem; background: rgba(220, 252, 231, 0.9); color: #15803d; border: 1px solid rgba(74, 222, 128, 0.6); border-radius: 12px; font-weight: 700;">Delivered: <?= $deliveredOrders ?></span>
                <span style="font-size: 0.75rem; padding: 0.25rem 0.6rem; background: rgba(254, 226, 226, 0.9); color: #991b1b; border: 1px solid rgba(248, 113, 113, 0.6); border-radius: 12px; font-weight: 700;">Cancelled: <?= $cancelledOrders ?></span>
            </div>
        </div>
    </div>

    <!-- Products & Categories Row -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
        <div class="smiski-card" style="margin-bottom: 0;">
            <h3 class="neon-glow-text" style="font-size: 1.15rem; font-weight: 800; margin-bottom: 1.25rem;">
                <i class="fa-solid fa-trophy" style="color: #15803d;"></i> Top Selling Products
            </h3>
            <?php if (empty($topProducts)): ?>
                <p class="text-muted-green" style="text-align: center; padding: 1.5rem;">No sales data available yet.</p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.85rem;">
                    <?php $maxSales = $topProducts[0]['total_sales'] ?? 1; ?>
                    <?php foreach ($topProducts as $i => $tp): ?>
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.3rem;">
                                <span style="font-size: 0.88rem; font-weight: 700; color: #064e3b;">
                                    <span style="display: inline-block; width: 22px; height: 22px; background: rgba(220, 252, 231, 0.9); color: #15803d; border: 1px solid rgba(74, 222, 128, 0.6); border-radius: 50%; text-align: center; line-height: 20px; font-size: 0.75rem; font-weight: 800; margin-right: 0.4rem;"><?= $i + 1 ?></span>
                                    <?= htmlspecialchars($tp['product_name']) ?>
                                </span>
                                <span style="font-size: 0.88rem; font-weight: 800; color: #15803d;">₱<?= number_format($tp['total_sales'], 2) ?></span>
                            </div>
                            <div class="smiski-subcard" style="padding: 0; height: 8px; overflow: hidden;">
                                <div style="height: 100%; background: linear-gradient(90deg, #166534, #15803d); border-radius: 10px; width: <?= round(($tp['total_sales'] / $maxSales) * 100) ?>%;"></div>
                            </div>
                            <div class="text-muted-green" style="font-size: 0.75rem; margin-top: 0.2rem; font-weight: 600;"><?= $tp['total_qty'] ?> units sold</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="smiski-card" style="margin-bottom: 0;">
            <h3 class="neon-glow-text" style="font-size: 1.15rem; font-weight: 800; margin-bottom: 1.25rem;">
                <i class="fa-solid fa-tags" style="color: #15803d;"></i> Sales by Category
            </h3>
            <?php if (empty($salesByCategory)): ?>
                <p class="text-muted-green" style="text-align: center; padding: 1.5rem;">No category data available yet.</p>
            <?php else: ?>
                <div style="position: relative; height: 170px; margin-bottom: 1rem;">
                    <canvas id="categoryChart"></canvas>
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.5rem; max-height: 140px; overflow-y: auto;">
                    <?php foreach ($salesByCategory as $i => $sc): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; padding: 0.3rem 0; border-bottom: 1px dashed rgba(74, 222, 128, 0.4);">
                            <span style="display: flex; align-items: center; gap: 0.5rem;">
                                <span style="width: 10px; height: 10px; background: <?= $catColors[$i % 8] ?>; border-radius: 50%; display: inline-block;"></span>
                                <span style="font-weight: 700; color: #064e3b;"><?= htmlspecialchars($sc['category_name']) ?></span>
                            </span>
                            <span style="font-weight: 800; color: #15803d;">₱<?= number_format($sc['total_sales'], 2) ?> <span class="text-muted-green" style="font-weight: 600;">(<?= $sc['total_qty'] ?> pcs)</span></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payments & Customers Row -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
        <div class="smiski-card" style="margin-bottom: 0;">
            <h3 class="neon-glow-text" style="font-size: 1.15rem; font-weight: 800; margin-bottom: 1.25rem;">
                <i class="fa-solid fa-credit-card" style="color: #15803d;"></i> Payment Methods
            </h3>
            <?php if (empty($paymentMethods)): ?>
                <p class="text-muted-green" style="text-align: center; padding: 1.5rem;">No payment data available yet.</p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <?php foreach ($paymentMethods as $pm): ?>
                        <div class="smiski-subcard" style="display: flex; justify-content: space-between; align-items: center; padding: 0.85rem 1.1rem;">
                            <div>
                                <div style="font-weight: 800; color: #064e3b; font-size: 0.92rem;"><?= htmlspecialchars($pm['payment_method']) ?></div>
                                <div class="text-muted-green" style="font-size: 0.78rem; font-weight: 600;"><?= $pm['cnt'] ?> transactions</div>
                            </div>
                            <div style="font-weight: 800; color: #15803d; font-size: 1rem;">₱<?= number_format($pm['total'], 2) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="smiski-subcard" style="margin-top: 1.25rem; display: flex; justify-content: space-between; background: rgba(220, 252, 231, 0.95) !important;">
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 700; color: #15803d;">Collected (Paid)</div>
                        <div style="font-size: 1.2rem; font-weight: 800; color: #064e3b;">₱<?= number_format($paidRevenue, 2) ?></div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 0.8rem; font-weight: 700; color: #92400e;">Pending Collection</div>
                        <div style="font-size: 1.2rem; font-weight: 800; color: #92400e;">₱<?= number_format($unpaidRevenue, 2) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="smiski-card" style="margin-bottom: 0;">
            <h3 class="neon-glow-text" style="font-size: 1.15rem; font-weight: 800; margin-bottom: 1.25rem;">
                <i class="fa-solid fa-users" style="color: #15803d;"></i> Top Customers
            </h3>
            <?php if (empty($topCustomers)): ?>
                <p class="text-muted-green" style="text-align: center; padding: 1.5rem;">No customer data available yet.</p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.65rem;">
                    <?php foreach ($topCustomers as $i => $tc): ?>
                        <div class="smiski-subcard" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem;">
                            <div style="width: 36px; height: 36px; background: rgba(220, 252, 231, 0.9); color: #15803d; border: 1px solid rgba(74, 222, 128, 0.6); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.85rem; flex-shrink: 0;">
                                #<?= $i + 1 ?>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-weight: 800; color: #064e3b; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($tc['customer_name']) ?></div>
                                <div class="text-muted-green" style="font-size: 0.78rem; font-weight: 600;"><?= $tc['order_count'] ?> orders</div>
                            </div>
                            <div style="font-weight: 800; color: #15803d; font-size: 0.95rem; white-space: nowrap;">₱<?= number_format($tc['total_spent'], 2) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Orders & Low Stock Alerts Row -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
        <div class="smiski-card" style="padding: 0; overflow: hidden; margin-bottom: 0;">
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid rgba(74, 222, 128, 0.4); background: rgba(220, 252, 231, 0.5);">
                <h3 class="neon-glow-text" style="font-size: 1.15rem; font-weight: 800; margin: 0;">
                    <i class="fa-solid fa-clock-rotate-left" style="color: #15803d;"></i> Recent Orders
                </h3>
            </div>
            <div style="overflow-x: auto;">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th style="padding: 0.75rem 1rem;">Order #</th>
                            <th style="padding: 0.75rem 1rem;">Customer</th>
                            <th style="padding: 0.75rem 1rem; text-align: right;">Amount</th>
                            <th style="padding: 0.75rem 1rem; text-align: center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentOrders as $ro): ?>
                            <?php
                            $bgCol = 'rgba(254, 243, 199, 0.9)'; $txtCol = '#92400e'; $borderCol = 'rgba(251, 191, 36, 0.6)';
                            if ($ro['status'] === 'delivered') { $bgCol = 'rgba(220, 252, 231, 0.9)'; $txtCol = '#15803d'; $borderCol = 'rgba(74, 222, 128, 0.6)'; }
                            elseif ($ro['status'] === 'shipped') { $bgCol = 'rgba(224, 242, 254, 0.9)'; $txtCol = '#0369a1'; $borderCol = 'rgba(56, 189, 248, 0.6)'; }
                            elseif ($ro['status'] === 'cancelled') { $bgCol = 'rgba(254, 226, 226, 0.9)'; $txtCol = '#991b1b'; $borderCol = 'rgba(248, 113, 113, 0.6)'; }
                            elseif ($ro['status'] === 'processing') { $bgCol = 'rgba(238, 242, 255, 0.9)'; $txtCol = '#3730a3'; $borderCol = 'rgba(129, 140, 248, 0.6)'; }
                            ?>
                            <tr>
                                <td style="font-weight: 800; color: #064e3b;"><?= htmlspecialchars($ro['order_number']) ?></td>
                                <td style="color: #064e3b; font-weight: 600;"><?= htmlspecialchars($ro['customer_name']) ?></td>
                                <td style="text-align: right; font-weight: 800; color: #15803d;">₱<?= number_format($ro['total_amount'], 2) ?></td>
                                <td style="text-align: center;">
                                    <span style="background: <?= $bgCol ?>; color: <?= $txtCol ?>; border: 1px solid <?= $borderCol ?>; padding: 0.2rem 0.6rem; border-radius: 8px; font-size: 0.72rem; font-weight: 700; display: inline-block;">
                                        <?= ucfirst($ro['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="smiski-card" style="margin-bottom: 0;">
            <h3 class="neon-glow-text" style="font-size: 1.15rem; font-weight: 800; margin-bottom: 1.25rem;">
                <i class="fa-solid fa-triangle-exclamation" style="color: #dc2626;"></i> Low Stock Alerts
                <?php if ($lowStockCount > 0): ?>
                    <span style="background: rgba(254, 226, 226, 0.9); color: #dc2626; border: 1px solid rgba(248, 113, 113, 0.6); font-size: 0.75rem; padding: 0.15rem 0.5rem; border-radius: 8px; font-weight: 800; margin-left: 0.4rem;"><?= $lowStockCount ?> items</span>
                <?php endif; ?>
            </h3>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1.25rem;">
                <div class="smiski-subcard" style="background: rgba(254, 226, 226, 0.9) !important; border-color: rgba(248, 113, 113, 0.6) !important; text-align: center; padding: 0.85rem;">
                    <div style="font-size: 1.5rem; font-weight: 800; color: #dc2626;"><?= $outOfStockCount ?></div>
                    <div style="font-size: 0.78rem; font-weight: 700; color: #991b1b;">Out of Stock</div>
                </div>
                <div class="smiski-subcard" style="background: rgba(254, 243, 199, 0.9) !important; border-color: rgba(251, 191, 36, 0.6) !important; text-align: center; padding: 0.85rem;">
                    <div style="font-size: 1.5rem; font-weight: 800; color: #d97706;"><?= $lowStockCount ?></div>
                    <div style="font-size: 0.78rem; font-weight: 700; color: #92400e;">Low Stock (≤10)</div>
                </div>
            </div>

            <?php if (empty($lowStockProducts)): ?>
                <p class="text-muted-green" style="text-align: center; padding: 1rem; font-weight: 700;"><i class="fa-solid fa-check-circle" style="color: #15803d;"></i> All products are well-stocked!</p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.5rem; max-height: 220px; overflow-y: auto;">
                    <?php foreach ($lowStockProducts as $lsp): ?>
                        <div class="smiski-subcard" style="display: flex; justify-content: space-between; align-items: center; padding: 0.65rem 0.85rem; background: <?= $lsp['stock_quantity'] == 0 ? 'rgba(254, 226, 226, 0.9)' : 'rgba(254, 243, 199, 0.9)' ?> !important;">
                            <div>
                                <div style="font-weight: 800; font-size: 0.85rem; color: #064e3b;"><?= htmlspecialchars($lsp['name']) ?></div>
                                <div class="text-muted-green" style="font-size: 0.75rem; font-weight: 600;">₱<?= number_format($lsp['price'], 2) ?></div>
                            </div>
                            <span style="font-weight: 800; font-size: 0.85rem; color: <?= $lsp['stock_quantity'] == 0 ? '#dc2626' : '#d97706' ?>;">
                                <?= $lsp['stock_quantity'] == 0 ? 'OUT OF STOCK' : $lsp['stock_quantity'] . ' left' ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Summary Stats -->
    <div class="smiski-card">
        <h3 class="neon-glow-text" style="font-size: 1.15rem; font-weight: 800; margin-bottom: 1.25rem;">
            <i class="fa-solid fa-clipboard-list" style="color: #15803d;"></i> Quick Summary Stats
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem;">
            <div class="smiski-subcard" style="text-align: center; padding: 1.1rem;">
                <div style="font-size: 1.8rem; font-weight: 800; color: #15803d;"><?= $totalOrders ?></div>
                <div class="text-muted-green" style="font-size: 0.82rem; font-weight: 700;">Total Orders</div>
            </div>
            <div class="smiski-subcard" style="text-align: center; padding: 1.1rem;">
                <div style="font-size: 1.8rem; font-weight: 800; color: #16a34a;"><?= $deliveredOrders ?></div>
                <div class="text-muted-green" style="font-size: 0.82rem; font-weight: 700;">Delivered</div>
            </div>
            <div class="smiski-subcard" style="text-align: center; padding: 1.1rem;">
                <div style="font-size: 1.8rem; font-weight: 800; color: #dc2626;"><?= $cancelledOrders ?></div>
                <div class="text-muted-green" style="font-size: 0.82rem; font-weight: 700;">Cancelled</div>
            </div>
            <div class="smiski-subcard" style="text-align: center; padding: 1.1rem;">
                <div style="font-size: 1.8rem; font-weight: 800; color: #3730a3;"><?= $totalCustomers ?></div>
                <div class="text-muted-green" style="font-size: 0.82rem; font-weight: 700;">Customers</div>
            </div>
            <div class="smiski-subcard" style="text-align: center; padding: 1.1rem;">
                <div style="font-size: 1.8rem; font-weight: 800; color: #0369a1;"><?= $totalProducts ?></div>
                <div class="text-muted-green" style="font-size: 0.82rem; font-weight: 700;">Active Products</div>
            </div>
            <div class="smiski-subcard" style="text-align: center; padding: 1.1rem;">
                <div style="font-size: 1.8rem; font-weight: 800; color: #d97706;"><?= $lowStockCount ?></div>
                <div class="text-muted-green" style="font-size: 0.82rem; font-weight: 700;">Low Stock Items</div>
            </div>
        </div>
    </div>

</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Sales Trend Chart (Last 30 Days)
    const dailySalesData = <?= json_encode($dailySales) ?>;
    const salesCtx = document.getElementById('salesChart').getContext('2d');
    new Chart(salesCtx, {
        type: 'line',
        data: {
            labels: dailySalesData.map(item => item.sale_date),
            datasets: [{
                label: 'Daily Sales (₱)',
                data: dailySalesData.map(item => item.daily_total),
                borderColor: '#15803d',
                backgroundColor: 'rgba(74, 222, 128, 0.2)',
                borderWidth: 3,
                fill: true,
                tension: 0.35,
                pointBackgroundColor: '#15803d',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(74, 222, 128, 0.2)' },
                    ticks: { color: '#064e3b', font: { size: 10 } }
                },
                y: {
                    grid: { color: 'rgba(74, 222, 128, 0.2)' },
                    ticks: { color: '#064e3b', font: { size: 10 } }
                }
            }
        }
    });

    // Order Status Doughnut Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'],
            datasets: [{
                data: [
                    <?= $pendingOrders ?>, 
                    <?= $processingOrders ?>, 
                    <?= $shippedOrders ?>, 
                    <?= $deliveredOrders ?>, 
                    <?= $cancelledOrders ?>
                ],
                backgroundColor: ['#fde68a', '#a5b4fc', '#38bdf8', '#4ade80', '#fca5a5'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            cutout: '70%'
        }
    });

    // Category Sales Pie/Doughnut Chart
    const categoryData = <?= json_encode($salesByCategory) ?>;
    const catColors = <?= json_encode($catColors) ?>;
    if (categoryData.length > 0) {
        const catCtx = document.getElementById('categoryChart').getContext('2d');
        new Chart(catCtx, {
            type: 'doughnut',
            data: {
                labels: categoryData.map(item => item.category_name),
                datasets: [{
                    data: categoryData.map(item => item.total_sales),
                    backgroundColor: catColors,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                cutout: '65%'
            }
        });
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>