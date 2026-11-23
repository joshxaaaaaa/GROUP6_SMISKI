<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

$db = getDB();
$searchOrderNum = sanitize($_GET['order_number'] ?? '');

$orders = [];
$trackedOrder = null;
$searched = false;

if (!empty($searchOrderNum)) {
    $searched = true;
    $stmt = $db->prepare("SELECT * FROM orders WHERE order_number = ? OR id = ?");
    $stmt->execute([$searchOrderNum, intval($searchOrderNum)]);
    $trackedOrder = $stmt->fetch();
}

if (isset($_SESSION['user_id']) && !isAdminLoggedIn()) {
    $stmt = $db->prepare("SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $orders = $stmt->fetchAll();
} elseif ($trackedOrder) {
    $orders = [$trackedOrder];
}

if ($trackedOrder) {
    $found = false;
    foreach ($orders as $o) {
        if ($o['id'] == $trackedOrder['id']) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        array_unshift($orders, $trackedOrder);
    }
}

function getOrderItems($orderId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

$selectedOrderId = 0;
if ($trackedOrder) {
    $selectedOrderId = $trackedOrder['id'];
} elseif (!empty($orders)) {
    $selectedOrderId = $orders[0]['id'];
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body {
        background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
        min-height: 100vh;
    }
    
    /* iOS Frosted Glass White Card Effect */
    .smiski-card {
        background: rgba(255, 255, 255, 0.78) !important;
        backdrop-filter: blur(30px) saturate(180%);
        -webkit-backdrop-filter: blur(30px) saturate(180%);
        border: 1px solid rgba(255, 255, 255, 0.5) !important;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35), 0 0 20px rgba(134, 239, 172, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.8) !important;
        transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s ease, border-color 0.4s ease;
    }
    .smiski-card:hover {
        transform: translateY(-8px) scale(1.01);
        border-color: #86efac !important;
        background: rgba(255, 255, 255, 0.88) !important;
        box-shadow: 0 30px 60px rgba(0, 0, 0, 0.45), 0 0 35px rgba(134, 239, 172, 0.6), inset 0 0 15px rgba(134, 239, 172, 0.3) !important;
    }

    /* Subcards inside Details */
    .smiski-subcard {
        background: rgba(255, 255, 255, 0.55) !important;
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
        border: 1px solid rgba(74, 222, 128, 0.4) !important;
        border-radius: 16px;
        box-shadow: inset 0 2px 5px rgba(255, 255, 255, 0.9), 0 0 12px rgba(74, 222, 128, 0.2);
    }

    .text-muted-green {
        color: #166534 !important;
        font-weight: 500;
    }

    /* Primary & Secondary Buttons aligned with Theme */
    .btn-green-primary {
        background: #15803d !important;
        color: #ffffff !important;
        border: 1px solid #4ade80 !important;
        box-shadow: 0 0 12px rgba(74, 222, 128, 0.5);
        transition: all 0.3s ease;
    }
    .btn-green-primary:hover {
        background: #166534 !important;
        transform: translateY(-2px);
        border-color: #86efac !important;
        box-shadow: 0 0 20px rgba(74, 222, 128, 0.8), inset 0 0 8px rgba(74, 222, 128, 0.4);
    }

    .btn-green-secondary {
        background: rgba(255, 255, 255, 0.7) !important;
        backdrop-filter: blur(10px);
        color: #15803d !important;
        border: 1px solid rgba(74, 222, 128, 0.6) !important;
        transition: all 0.3s ease;
    }
    .btn-green-secondary:hover {
        background: rgba(255, 255, 255, 0.9) !important;
        transform: translateY(-2px);
        border-color: #15803d !important;
        box-shadow: 0 0 15px rgba(74, 222, 128, 0.5);
    }

    /* Hover Scale Effect */
    .hover-scale {
        transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
    }
    .hover-scale:hover {
        transform: translateY(-3px);
        border-color: #86efac !important;
        box-shadow: 0 15px 35px rgba(0,0,0,0.3), 0 0 20px rgba(74, 222, 128, 0.4);
    }

    /* Order Item List Card styling */
    .order-list-card {
        background: rgba(255, 255, 255, 0.65) !important;
        backdrop-filter: blur(15px);
        border: 1px solid rgba(255, 255, 255, 0.8);
        border-radius: 16px;
        transition: all 0.3s ease;
    }
    .order-list-card:hover {
        border-color: #4ade80 !important;
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2), 0 0 15px rgba(74, 222, 128, 0.4);
    }
    .order-list-card.active {
        border-color: #15803d !important;
        box-shadow: 0 0 20px rgba(74, 222, 128, 0.6), inset 0 0 10px rgba(74, 222, 128, 0.3);
        background: rgba(255, 255, 255, 0.9) !important;
    }

    /* Green Glow-in-the-Dark Text Effect for Glass White Backgrounds */
    .neon-glow-text {
        color: #064e3b !important;
        text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
    }
</style>

<div class="container" style="max-width: 1200px; margin: 0 auto; padding-top: 1.5rem; padding-bottom: 3rem;">

    <!-- Top Search & Header Card -->
    <div class="smiski-card" style="padding: 2rem 2.5rem; margin-bottom: 2.5rem; border-radius: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem;">
            <div>
                <span style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); color: #064e3b; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.8rem; border-radius: 20px; display: inline-block; margin-bottom: 0.75rem; border: 1px solid rgba(74,222,128,0.6); box-shadow: 0 0 10px rgba(74,222,128,0.4);">
                    <i class="fa-solid fa-truck-fast"></i> LIVE TRACKING
                </span>
                <h1 class="neon-glow-text" style="font-size: 2.25rem; font-weight: 700; margin-bottom: 0.4rem;">Order Tracking & History</h1>
                <p class="text-muted-green" style="font-size: 1rem; line-height: 1.5;">Select an order below to view its live status and package progress.</p>
            </div>

            <form method="GET" action="orders.php" style="display: flex; gap: 0.5rem; flex: 1; max-width: 420px; min-width: 260px;">
                <div style="position: relative; flex: 1;">
                    <input type="text" name="order_number" value="<?= htmlspecialchars($searchOrderNum) ?>" placeholder="Search Order # (e.g. ORD-...)" class="hover-scale"
                           style="width: 100%; padding: 0.75rem 1rem 0.75rem 2.5rem; background: rgba(255, 255, 255, 0.8); border: 1px solid rgba(74, 222, 128, 0.5); border-radius: 12px; font-size: 0.9rem; outline: none; color: #1f2937; backdrop-filter: blur(10px);">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 0.95rem; top: 50%; transform: translateY(-50%); color: #15803d; font-size: 0.9rem;"></i>
                </div>
                <button type="submit" class="btn btn-green-primary" style="padding: 0.75rem 1.25rem; border-radius: 12px; font-weight: 600; cursor: pointer;">
                    Track
                </button>
                <?php if ($searched): ?>
                    <a href="orders.php" class="btn btn-green-secondary" title="Clear Search" style="padding: 0.75rem 1rem; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none;">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($searched && !$trackedOrder): ?>
        <div class="smiski-card" style="background: rgba(254, 226, 226, 0.85) !important; border: 1px solid rgba(239, 68, 68, 0.5); color: #991b1b; padding: 1.25rem 1.5rem; border-radius: 16px; font-weight: 600; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
            <i class="fa-solid fa-circle-exclamation" style="font-size: 1.4rem; color: #dc2626;"></i>
            <span>No order found matching <strong>"<?= htmlspecialchars($searchOrderNum) ?>"</strong>. Please check your order number.</span>
        </div>
    <?php endif; ?>

    <?php if (empty($orders)): ?>
        <div class="smiski-card" style="padding: 4rem 2rem; text-align: center; border-radius: 22px;">
            <i class="fa-solid fa-receipt" style="font-size: 4rem; color: #15803d; margin-bottom: 1.25rem; filter: drop-shadow(0 0 8px rgba(74,222,128,0.6));"></i>
            <h3 class="neon-glow-text" style="font-weight: 700; font-size: 1.5rem;">No Orders Found</h3>
            <p class="text-muted-green" style="font-size: 1rem; margin-top: 0.5rem; margin-bottom: 1.75rem;">
                <?= isset($_SESSION['user_id']) ? "You haven't placed any orders yet." : "Please log in to view your orders, or enter an Order Number above to track a package." ?>
            </p>
            <a href="index.php" class="btn btn-green-primary" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.85rem 1.75rem; border-radius: 14px; text-decoration: none; font-weight: 600;">
                <i class="fa-solid fa-store"></i> Browse Catalog & Shop Now
            </a>
        </div>
    <?php else: ?>

        <!-- Master-Detail Split Layout -->
        <div style="display: flex; gap: 2rem; align-items: flex-start; flex-wrap: wrap;">

            <!-- Left: Order Selection List (Sidebar) -->
            <div style="flex: 1; min-width: 300px; max-width: 360px; display: flex; flex-direction: column; gap: 0.75rem;">
                <div style="font-size: 0.85rem; font-weight: 700; color: #ffffff; text-transform: uppercase; letter-spacing: 0.05em; padding-left: 0.25rem; margin-bottom: 0.25rem; text-shadow: 0 0 5px rgba(255, 255, 255, 0.5);">
                    Select Order to View (<?= count($orders) ?>)
                </div>

                <div style="display: flex; flex-direction: column; gap: 0.85rem; max-height: 700px; overflow-y: auto; padding-right: 0.25rem;">
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $status = strtolower($order['status']);
                        $isSelected = ($order['id'] == $selectedOrderId);
                        ?>
                        <div class="order-list-card <?= $isSelected ? 'active' : '' ?>" id="order-card-<?= $order['id'] ?>" onclick="selectOrder(<?= $order['id'] ?>)"
                             style="padding: 1.25rem; cursor: pointer; position: relative;">
                            
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                                <div class="neon-glow-text" style="font-weight: 700; font-size: 0.98rem; font-family: monospace;">
                                    <?= htmlspecialchars($order['order_number']) ?>
                                </div>
                                <div>
                                    <?php if ($status === 'delivered'): ?>
                                        <span style="background: rgba(34, 197, 94, 0.2); color: #15803d; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 999px; border: 1px solid rgba(74, 222, 128, 0.6);">Delivered</span>
                                    <?php elseif ($status === 'shipped'): ?>
                                        <span style="background: rgba(168, 85, 247, 0.15); color: #7e22ce; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 999px; border: 1px solid rgba(216, 180, 254, 0.6);">On Way</span>
                                    <?php elseif ($status === 'processing'): ?>
                                        <span style="background: rgba(56, 189, 248, 0.15); color: #0369a1; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 999px; border: 1px solid rgba(125, 211, 252, 0.6);">Processing</span>
                                    <?php elseif ($status === 'cancelled'): ?>
                                        <span style="background: rgba(239, 68, 68, 0.15); color: #b91c1c; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 999px; border: 1px solid rgba(252, 165, 165, 0.6);">Cancelled</span>
                                    <?php else: ?>
                                        <span style="background: rgba(234, 179, 8, 0.15); color: #a16207; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 999px; border: 1px solid rgba(253, 230, 138, 0.6);">Pending</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; color: #4b5563;">
                                <span><?= date('M d, Y', strtotime($order['created_at'])) ?></span>
                                <span style="font-weight: 700; color: #15803d; font-size: 0.98rem;">₱<?= number_format($order['total_amount'], 2) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Right: Active Order Details & Tracking Progress -->
            <div style="flex: 2; min-width: 320px;">
                <?php foreach ($orders as $order): ?>
                    <?php
                    $items = getOrderItems($order['id']);
                    $status = strtolower($order['status']);
                    
                    $step1 = true;
                    $step2 = in_array($status, ['processing', 'shipped', 'delivered']);
                    $step3 = in_array($status, ['shipped', 'delivered']);
                    $step4 = ($status === 'delivered');
                    $isCancelled = ($status === 'cancelled');
                    $isSelected = ($order['id'] == $selectedOrderId);
                    ?>

                    <div class="order-detail-pane smiski-card" id="order-pane-<?= $order['id'] ?>" style="display: <?= $isSelected ? 'block' : 'none' ?>; padding: 2rem; border-radius: 22px;">
                        
                        <!-- Header Bar -->
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; padding-bottom: 1.25rem; border-bottom: 1px solid rgba(74, 222, 128, 0.3); margin-bottom: 1.75rem;">
                            <div>
                                <span style="font-size: 0.78rem; color: #15803d; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">ACTIVE ORDER</span>
                                <h3 class="neon-glow-text" style="font-size: 1.45rem; font-weight: 700; font-family: monospace; margin-top: 0.2rem;">
                                    <?= htmlspecialchars($order['order_number']) ?>
                                </h3>
                                <div style="font-size: 0.88rem; color: #4b5563; margin-top: 0.25rem; font-weight: 500;">
                                    Placed on <?= date('F j, Y \a\t g:i A', strtotime($order['created_at'])) ?>
                                </div>
                            </div>

                            <div style="text-align: right;">
                                <span style="font-size: 0.78rem; color: #15803d; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">CURRENT STATUS</span>
                                <div style="margin-top: 0.3rem;">
                                    <?php if ($status === 'delivered'): ?>
                                        <span style="background: rgba(34, 197, 94, 0.2); color: #15803d; font-size: 0.9rem; font-weight: 700; padding: 0.45rem 1.1rem; border-radius: 999px; display: inline-flex; align-items: center; gap: 0.4rem; border: 1px solid rgba(74, 222, 128, 0.6); box-shadow: 0 0 8px rgba(74,222,128,0.3);">
                                            <i class="fa-solid fa-circle-check"></i> Delivered
                                        </span>
                                    <?php elseif ($status === 'shipped'): ?>
                                        <span style="background: rgba(168, 85, 247, 0.15); color: #7e22ce; font-size: 0.9rem; font-weight: 700; padding: 0.45rem 1.1rem; border-radius: 999px; display: inline-flex; align-items: center; gap: 0.4rem; border: 1px solid rgba(216, 180, 254, 0.6);">
                                            <i class="fa-solid fa-truck-fast"></i> Out for Delivery
                                        </span>
                                    <?php elseif ($status === 'processing'): ?>
                                        <span style="background: rgba(56, 189, 248, 0.15); color: #0369a1; font-size: 0.9rem; font-weight: 700; padding: 0.45rem 1.1rem; border-radius: 999px; display: inline-flex; align-items: center; gap: 0.4rem; border: 1px solid rgba(125, 211, 252, 0.6);">
                                            <i class="fa-solid fa-arrows-rotate fa-spin"></i> Processing
                                        </span>
                                    <?php elseif ($status === 'cancelled'): ?>
                                        <span style="background: rgba(239, 68, 68, 0.15); color: #b91c1c; font-size: 0.9rem; font-weight: 700; padding: 0.45rem 1.1rem; border-radius: 999px; display: inline-flex; align-items: center; gap: 0.4rem; border: 1px solid rgba(252, 165, 165, 0.6);">
                                            <i class="fa-solid fa-ban"></i> Cancelled
                                        </span>
                                    <?php else: ?>
                                        <span style="background: rgba(234, 179, 8, 0.15); color: #a16207; font-size: 0.9rem; font-weight: 700; padding: 0.45rem 1.1rem; border-radius: 999px; display: inline-flex; align-items: center; gap: 0.4rem; border: 1px solid rgba(253, 230, 138, 0.6);">
                                            <i class="fa-solid fa-clock"></i> Order Pending
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- 4-Step Tracker Visual -->
                        <?php if (!$isCancelled): ?>
                            <div class="smiski-subcard" style="margin-bottom: 2rem; padding: 1.75rem 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; position: relative; max-width: 650px; margin: 0 auto;">
                                    
                                    <div style="position: absolute; top: 21px; left: 10%; right: 10%; height: 4px; background: rgba(0, 0, 0, 0.1); z-index: 1;"></div>
                                    <div style="position: absolute; top: 21px; left: 10%; width: <?= $step4 ? '80%' : ($step3 ? '53%' : ($step2 ? '27%' : '0%')) ?>; height: 4px; background: #15803d; z-index: 2; transition: width 0.4s ease; box-shadow: 0 0 10px rgba(74, 222, 128, 0.8);"></div>

                                    <div style="position: relative; z-index: 3; text-align: center; flex: 1;">
                                        <div style="width: 44px; height: 44px; border-radius: 50%; background: <?= $step1 ? '#15803d' : '#ffffff' ?>; color: <?= $step1 ? '#ffffff' : '#15803d' ?>; border: 2px solid #15803d; display: flex; align-items: center; justify-content: center; margin: 0 auto 0.5rem auto; font-size: 1.1rem; box-shadow: 0 4px 10px rgba(0,0,0,0.15);">
                                            <i class="fa-solid fa-file-invoice"></i>
                                        </div>
                                        <span style="font-size: 0.85rem; font-weight: 700; color: #064e3b;">Placed</span>
                                    </div>

                                    <div style="position: relative; z-index: 3; text-align: center; flex: 1;">
                                        <div style="width: 44px; height: 44px; border-radius: 50%; background: <?= $step2 ? '#15803d' : '#ffffff' ?>; color: <?= $step2 ? '#ffffff' : '#15803d' ?>; border: 2px solid #15803d; display: flex; align-items: center; justify-content: center; margin: 0 auto 0.5rem auto; font-size: 1.1rem; box-shadow: 0 4px 10px rgba(0,0,0,0.15);">
                                            <i class="fa-solid fa-box-open"></i>
                                        </div>
                                        <span style="font-size: 0.85rem; font-weight: 700; color: #064e3b;">Processing</span>
                                    </div>

                                    <div style="position: relative; z-index: 3; text-align: center; flex: 1;">
                                        <div style="width: 44px; height: 44px; border-radius: 50%; background: <?= $step3 ? '#15803d' : '#ffffff' ?>; color: <?= $step3 ? '#ffffff' : '#15803d' ?>; border: 2px solid #15803d; display: flex; align-items: center; justify-content: center; margin: 0 auto 0.5rem auto; font-size: 1.1rem; box-shadow: 0 4px 10px rgba(0,0,0,0.15);">
                                            <i class="fa-solid fa-truck-fast"></i>
                                        </div>
                                        <span style="font-size: 0.85rem; font-weight: 700; color: #064e3b;">On The Way</span>
                                    </div>

                                    <div style="position: relative; z-index: 3; text-align: center; flex: 1;">
                                        <div style="width: 44px; height: 44px; border-radius: 50%; background: <?= $step4 ? '#15803d' : '#ffffff' ?>; color: <?= $step4 ? '#ffffff' : '#15803d' ?>; border: 2px solid #15803d; display: flex; align-items: center; justify-content: center; margin: 0 auto 0.5rem auto; font-size: 1.1rem; box-shadow: 0 4px 10px rgba(0,0,0,0.15);">
                                            <i class="fa-solid fa-house-circle-check"></i>
                                        </div>
                                        <span style="font-size: 0.85rem; font-weight: 700; color: #064e3b;">Delivered</span>
                                    </div>

                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Items & Delivery Split -->
                        <div style="display: flex; gap: 1.75rem; flex-wrap: wrap;">

                            <!-- Left: Items -->
                            <div style="flex: 2; min-width: 280px;">
                                <h4 class="neon-glow-text" style="font-size: 1rem; font-weight: 700; margin-bottom: 0.85rem;">
                                    <i class="fa-solid fa-boxes-packing" style="color: #15803d;"></i> Ordered Items (<?= count($items) ?>)
                                </h4>
                                <div class="smiski-subcard" style="padding: 1rem 1.25rem;">
                                    <?php foreach ($items as $item): ?>
                                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 0; border-bottom: 1px solid rgba(74, 222, 128, 0.25);">
                                            <div style="display: flex; align-items: center; gap: 0.85rem;">
                                                <div style="width: 38px; height: 38px; background: rgba(255, 255, 255, 0.8); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #15803d; font-size: 1rem; border: 1px solid rgba(74, 222, 128, 0.5);">
                                                    <i class="fa-solid fa-box"></i>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 600; font-size: 0.92rem; color: #1f2937;">
                                                        <?= htmlspecialchars($item['product_name']) ?>
                                                    </div>
                                                    <div style="font-size: 0.82rem; color: #4b5563; font-weight: 500;">
                                                        Qty: <?= $item['quantity'] ?> × ₱<?= number_format($item['price'], 2) ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div style="font-weight: 700; font-size: 0.98rem; color: #15803d;">
                                                ₱<?= number_format($item['subtotal'], 2) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Right: Delivery & Payment Details -->
                            <div class="smiski-subcard" style="flex: 1; min-width: 250px; padding: 1.5rem; display: flex; flex-direction: column; justify-content: space-between;">
                                <div>
                                    <h4 class="neon-glow-text" style="font-size: 1rem; font-weight: 700; margin-bottom: 0.85rem;">
                                        <i class="fa-solid fa-location-dot" style="color: #15803d;"></i> Delivery & Payment
                                    </h4>
                                    <div style="font-size: 0.88rem; color: #374151; margin-bottom: 0.5rem; font-weight: 500;">
                                        <strong style="color: #1f2937;">Recipient:</strong> <?= htmlspecialchars($order['customer_name']) ?>
                                    </div>
                                    <div style="font-size: 0.88rem; color: #374151; margin-bottom: 0.5rem; font-weight: 500;">
                                        <strong style="color: #1f2937;">Phone:</strong> <?= htmlspecialchars($order['phone']) ?>
                                    </div>
                                    <div style="font-size: 0.88rem; color: #374151; margin-bottom: 1rem; line-height: 1.5; font-weight: 500;">
                                        <strong style="color: #1f2937;">Address:</strong> <?= htmlspecialchars($order['delivery_address']) ?>
                                    </div>

                                    <?php
                                    $pMethodLower = strtolower($order['payment_method']);
                                    $isGcashOrder = strpos($pMethodLower, 'gcash') !== false || strpos($pMethodLower, 'wallet') !== false;
                                    $isCodOrder = strpos($pMethodLower, 'cod') !== false;
                                    $payStatus = $order['payment_status'] ?? 'unpaid';
                                    ?>
                                    <div style="font-size: 0.88rem; color: #374151; margin-bottom: 0.6rem; font-weight: 500;">
                                        <strong style="color: #1f2937;">Payment Method:</strong> <?= htmlspecialchars($order['payment_method']) ?>
                                    </div>

                                    <?php if ($isGcashOrder): ?>
                                        <div style="margin-bottom: 1rem;">
                                            <?php if ($payStatus === 'paid'): ?>
                                                <span style="font-size: 0.8rem; color: #15803d; font-weight: 700; background: rgba(34, 197, 94, 0.2); padding: 0.3rem 0.75rem; border-radius: 8px; display: inline-block; border: 1px solid rgba(74, 222, 128, 0.6);">
                                                    <i class="fa-solid fa-circle-check"></i> GCash Verified & Approved
                                                </span>
                                            <?php elseif ($payStatus === 'rejected'): ?>
                                                <span style="font-size: 0.8rem; color: #b91c1c; font-weight: 700; background: rgba(239, 68, 68, 0.2); padding: 0.3rem 0.75rem; border-radius: 8px; display: inline-block; border: 1px solid rgba(252, 165, 165, 0.6);">
                                                    <i class="fa-solid fa-circle-xmark"></i> Payment Receipt Rejected
                                                </span>
                                            <?php else: ?>
                                                <span style="font-size: 0.8rem; color: #b45309; font-weight: 700; background: rgba(234, 179, 8, 0.2); padding: 0.3rem 0.75rem; border-radius: 8px; display: inline-block; border: 1px solid rgba(253, 230, 138, 0.6);">
                                                    <i class="fa-solid fa-clock"></i> Awaiting Admin Verification
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div style="border-top: 1px solid rgba(74, 222, 128, 0.4); padding-top: 1rem;">
                                    <div style="display: flex; justify-content: space-between; font-weight: 700; font-size: 1.1rem; color: #1f2937;">
                                        <?php if ($isGcashOrder): ?>
                                            <span><?= $payStatus === 'paid' ? 'Total Paid:' : 'Amount (Pending):' ?></span>
                                        <?php elseif ($isCodOrder && strtolower($order['status']) !== 'delivered'): ?>
                                            <span>Due on Delivery:</span>
                                        <?php else: ?>
                                            <span>Total Paid:</span>
                                        <?php endif; ?>
                                        <span class="neon-glow-text" style="font-size: 1.25rem;">₱<?= number_format($order['total_amount'], 2) ?></span>
                                    </div>
                                </div>

                            </div>

                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

        </div>

    <?php endif; ?>

</div>

<script>
function selectOrder(orderId) {
    document.querySelectorAll('.order-list-card').forEach(card => card.classList.remove('active'));
    document.querySelectorAll('.order-detail-pane').forEach(pane => pane.style.display = 'none');

    const selectedCard = document.getElementById('order-card-' + orderId);
    const selectedPane = document.getElementById('order-pane-' + orderId);

    if (selectedCard) selectedCard.classList.add('active');
    if (selectedPane) selectedPane.style.display = 'block';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>