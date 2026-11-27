<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/db.php';

if (!isAdminLoggedIn()) {
    if (isset($_GET['ajax_details']) || isAjaxRequest()) {
        jsonResponse(401, ['success' => false, 'message' => 'Your session has expired. Please log in again.']);
    }
    header("Location: login.php");
    exit;
}

$db = getDB();
$message = getFlash('success');
$error = getFlash('error');

$validOrderStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
$lockedOrderStatuses = ['delivered', 'cancelled'];

function orderStatusChangeError($fromStatus, $toStatus, array $validStatuses, array $lockedStatuses) {
    if (!in_array($toStatus, $validStatuses, true)) {
        return 'Invalid order status selected.';
    }
    if ($fromStatus !== $toStatus && in_array($fromStatus, $lockedStatuses, true)) {
        if ($fromStatus === 'delivered') {
            return 'This order has already been Delivered. Its status can no longer be changed.';
        }
        return 'This order has already been Cancelled. Its status can no longer be changed.';
    }
    return '';
}

function restoreOrderStock(PDO $db, $orderId) {
    $items = $db->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
    $items->execute([$orderId]);
    $stockStmt = $db->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
    foreach ($items->fetchAll() as $it) {
        $stockStmt->execute([$it['quantity'], $it['product_id']]);
    }
}

try {
    $db->exec("ALTER TABLE orders ADD COLUMN payment_receipt VARCHAR(255) NULL AFTER payment_method");
    $db->exec("ALTER TABLE orders ADD COLUMN payment_status VARCHAR(50) DEFAULT 'unpaid' AFTER payment_receipt");
} catch (Exception $e) {
    
}

if (isset($_GET['ajax_details'])) {
    $rawOrderId = $_GET['ajax_details'];
    if (!is_string($rawOrderId) || !ctype_digit($rawOrderId) || (int)$rawOrderId < 1) {
        jsonResponse(400, ['success' => false, 'message' => MSG_INVALID_OPERATION]);
    }
    $orderId = (int)$rawOrderId;
    $oStmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
    $oStmt->execute([$orderId]);
    $order = $oStmt->fetch();
    if (!$order) {
        jsonResponse(404, ['success' => false, 'message' => 'This order no longer exists.']);
    }
    header('Content-Type: text/html; charset=UTF-8');

    if ($order) {
        $iStmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $iStmt->execute([$orderId]);
        $items = $iStmt->fetchAll();

        echo "<div style='display:flex; flex-direction:column; gap:0.5rem; margin-bottom:1rem; color: #1f2937;'>";
        echo "<div><strong style='color:#064e3b;'>Order #:</strong> " . htmlspecialchars($order['order_number']) . "</div>";
        echo "<div><strong style='color:#064e3b;'>Customer Name:</strong> " . htmlspecialchars($order['customer_name']) . "</div>";
        echo "<div><strong style='color:#064e3b;'>Phone:</strong> " . htmlspecialchars($order['phone']) . "</div>";
        echo "<div><strong style='color:#064e3b;'>Delivery Address:</strong> " . htmlspecialchars($order['delivery_address']) . "</div>";
        echo "<div><strong style='color:#064e3b;'>Payment Method:</strong> " . htmlspecialchars($order['payment_method']) . " (" . ucfirst($order['payment_status'] ?? 'unpaid') . ")</div>";
        if (!empty($order['payment_receipt'])) {
            echo "<div><strong style='color:#064e3b;'>GCash Receipt:</strong> <a href='../" . htmlspecialchars($order['payment_receipt']) . "' target='_blank' style='color:#15803d; font-weight:600;'>View Full Image</a></div>";
        }
        echo "<div><strong style='color:#064e3b;'>Discount:</strong> " . htmlspecialchars($order['discount_type'] ?? 'None') . " (-₱" . number_format($order['discount_amount'], 2) . ")</div>";
        echo "<div><strong style='color:#064e3b;'>Shipping Fee:</strong> ₱" . number_format($order['shipping_cost'], 2) . "</div>";
        echo "<div><strong style='color:#064e3b;'>Tax Amount:</strong> ₱" . number_format($order['tax_amount'], 2) . "</div>";
        echo "<div style='font-size:1.1rem; font-weight:700; color:#15803d; margin-top:0.3rem;'><strong>Grand Total:</strong> ₱" . number_format($order['total_amount'], 2) . "</div>";
        echo "</div>";

        echo "<h4 style='font-weight:700; margin-bottom:0.5rem; color:#064e3b;'>Ordered Items:</h4>";
        echo "<table class='custom-table'>";
        echo "<thead><tr><th style='padding:0.4rem;'>Item</th><th style='padding:0.4rem; text-align:center;'>Qty</th><th style='padding:0.4rem; text-align:right;'>Price</th><th style='padding:0.4rem; text-align:right;'>Subtotal</th></tr></thead><tbody>";
        foreach ($items as $it) {
            echo "<tr>";
            echo "<td style='padding:0.4rem; color: #1f2937;'>" . htmlspecialchars($it['product_name']) . "</td>";
            echo "<td style='padding:0.4rem; text-align:center;'>" . (int)$it['quantity'] . "</td>";
            echo "<td style='padding:0.4rem; text-align:right;'>₱" . number_format($it['price'], 2) . "</td>";
            echo "<td style='padding:0.4rem; text-align:right; font-weight:700; color:#15803d;'>₱" . number_format($it['subtotal'], 2) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = new Validator($_POST);
    $action = $v->choice('action', ['approve_payment', 'reject_payment', 'update_status', 'delete'], ['message' => MSG_INVALID_OPERATION]);
    $id = $v->id('id');
    if ($action === null || $id === null) {
        abortRequest(400, MSG_INVALID_OPERATION, 'orders.php');
    }

    if ($action === 'approve_payment') {
        if ($id > 0) {
            try {
                $db->beginTransaction();
                $cur = $db->prepare("SELECT status, payment_method, payment_status FROM orders WHERE id = ? FOR UPDATE");
                $cur->execute([$id]);
                $orderRow = $cur->fetch();
                $prevStatus = $orderRow ? $orderRow['status'] : false;
                $isWalletPayment = $orderRow && preg_match('/gcash|wallet/i', (string)$orderRow['payment_method']);

                if ($prevStatus === false) {
                    $db->rollBack();
                    $error = 'This order no longer exists.';
                } elseif (!$isWalletPayment) {
                    $db->rollBack();
                    $error = MSG_INVALID_OPERATION . ' Only GCash / e-wallet payments need manual approval.';
                } elseif (($orderRow['payment_status'] ?? '') === 'paid') {
                    $db->rollBack();
                    $error = 'This payment has already been approved.';
                } elseif (in_array($prevStatus, $lockedOrderStatuses, true)) {
                    $db->rollBack();
                    $error = 'Payment cannot be approved because this order is already ' . ucfirst($prevStatus) . '.';
                } else {
                    $newStatus = ($prevStatus === 'pending') ? 'processing' : $prevStatus;
                    $stmt = $db->prepare("UPDATE orders SET payment_status = 'paid', status = ? WHERE id = ?");
                    $stmt->execute([$newStatus, $id]);
                    $db->commit();
                    logActivity($_SESSION['user_id'], 'PAYMENT_APPROVED', "Approved GCash payment for Order ID $id");
                    $message = 'GCash payment approved! Order status is now ' . ucfirst($newStatus) . '.';
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Payment not approved. ' . safeErrorMessage($e, 'Payment approval failed');
            }
        }
    }

    if ($action === 'reject_payment') {
        if ($id > 0) {
            try {
                $db->beginTransaction();
                $cur = $db->prepare("SELECT status FROM orders WHERE id = ? FOR UPDATE");
                $cur->execute([$id]);
                $prevStatus = $cur->fetchColumn();

                if ($prevStatus === false) {
                    $db->rollBack();
                    $error = 'This order no longer exists.';
                } elseif ($prevStatus === 'delivered') {
                    $db->rollBack();
                    $error = 'Payment cannot be rejected because this order has already been Delivered.';
                } else {
                    if ($prevStatus !== 'cancelled') {
                        restoreOrderStock($db, $id);
                    }
                    $stmt = $db->prepare("UPDATE orders SET payment_status = 'rejected', status = 'cancelled' WHERE id = ?");
                    $stmt->execute([$id]);
                    $db->commit();
                    logActivity($_SESSION['user_id'], 'PAYMENT_REJECTED', "Rejected payment for Order ID $id and cancelled order.");
                    $message = 'GCash payment rejected. Order has been cancelled and stock restored.';
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Payment not rejected. ' . safeErrorMessage($e, 'Payment rejection failed');
            }
        }
    }

    if ($action === 'update_status') {
        $status = is_string($_POST['status'] ?? null) ? strtolower(trim($_POST['status'])) : '';

        if ($id > 0) {
            try {
                $db->beginTransaction();
                $cur = $db->prepare("SELECT status FROM orders WHERE id = ? FOR UPDATE");
                $cur->execute([$id]);
                $prevStatus = $cur->fetchColumn();

                $changeError = ($prevStatus === false)
                    ? 'This order no longer exists.'
                    : orderStatusChangeError($prevStatus, $status, $validOrderStatuses, $lockedOrderStatuses);

                if ($changeError !== '') {
                    $db->rollBack();
                    $error = $changeError;
                } elseif ($prevStatus === $status) {
                    $db->rollBack();
                    $message = 'No changes made. The order is already ' . ucfirst($status) . '.';
                } else {
                    if ($status === 'cancelled') {
                        restoreOrderStock($db, $id);
                    }

                    $stmt = $db->prepare("UPDATE orders SET status = ? WHERE id = ?");
                    $stmt->execute([$status, $id]);

                    if ($status === 'delivered') {
                        $db->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = ?")->execute([$id]);
                    }

                    $db->commit();
                    logActivity($_SESSION['user_id'], 'ORDER_STATUS_UPDATED', "Updated order ID $id status from $prevStatus to: $status");
                    $message = 'Order status updated successfully!';
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Order status not updated. ' . safeErrorMessage($e, 'Order status update failed');
            }
        }
    }

    if ($action === 'delete') {
        if ($id > 0) {
            try {
                $db->beginTransaction();
                $cur = $db->prepare("SELECT status FROM orders WHERE id = ? FOR UPDATE");
                $cur->execute([$id]);
                $prevStatus = $cur->fetchColumn();

                if ($prevStatus === false) {
                    $db->rollBack();
                    $error = 'This order no longer exists.';
                } else {
                    if (!in_array($prevStatus, ['cancelled', 'delivered'], true)) {
                        restoreOrderStock($db, $id);
                    }

                    $stmt = $db->prepare("DELETE FROM orders WHERE id = ?");
                    $stmt->execute([$id]);
                    $db->commit();
                    logActivity($_SESSION['user_id'], 'ORDER_DELETED', "Deleted order ID $id");
                    $message = 'Order deleted successfully!';
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Order not deleted. ' . safeErrorMessage($e, 'Order delete failed');
            }
        }
    }

    if ($message !== '') setFlash('success', $message);
    if ($error !== '') setFlash('error', $error);
    redirectTo('orders.php');
}

$orders = $db->query("
    SELECT o.*, u.email AS customer_email
    FROM orders o
    LEFT JOIN users u ON o.customer_id = u.id
    ORDER BY o.id DESC
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body {
        background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
        color: #1f2937 !important;
        min-height: 100vh;
        font-family: 'Outfit', sans-serif;
    }

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

    .smiski-subcard {
        background: rgba(255, 255, 255, 0.85) !important;
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
        border: 1px solid rgba(74, 222, 128, 0.5) !important;
        border-radius: 16px;
        box-shadow: inset 0 1px 3px rgba(255, 255, 255, 0.9), 0 5px 15px rgba(0, 0, 0, 0.1);
        padding: 1.25rem;
    }

    .white-glow-heading {
        color: #ffffff !important;
        text-shadow: 0 0 10px rgba(255, 255, 255, 0.8), 0 0 20px rgba(134, 239, 172, 0.6), 0 2px 4px rgba(0,0,0,0.3);
    }

    .neon-glow-text {
        color: #064e3b !important;
        text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
    }

    .text-muted-green {
        color: #166534 !important;
        font-weight: 500;
    }

    .white-subtext {
        color: rgba(255, 255, 255, 0.9) !important;
        font-weight: 500;
        text-shadow: 0 1px 3px rgba(0,0,0,0.3);
    }

    .btn-green-primary {
        background: #15803d !important;
        color: #ffffff !important;
        border: 1px solid #4ade80 !important;
        box-shadow: 0 0 12px rgba(74, 222, 128, 0.5);
        padding: 0.65rem 1.1rem;
        border-radius: 12px;
        font-weight: 600;
        font-size: 0.88rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        transition: all 0.3s ease;
        text-decoration: none;
    }
    .btn-green-primary:hover {
        background: #166534 !important;
        transform: translateY(-2px);
        border-color: #86efac !important;
        box-shadow: 0 0 20px rgba(74, 222, 128, 0.8), inset 0 0 8px rgba(74, 222, 128, 0.4);
    }

    .btn-danger-glow {
        background: rgba(254, 226, 226, 0.9) !important;
        color: #dc2626 !important;
        border: 1px solid rgba(248, 113, 113, 0.6) !important;
        box-shadow: 0 0 10px rgba(248, 113, 113, 0.3);
        padding: 0.65rem 1.1rem;
        border-radius: 12px;
        font-weight: 600;
        font-size: 0.88rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        transition: all 0.3s ease;
    }
    .btn-danger-glow:hover {
        background: rgba(254, 202, 202, 1) !important;
        transform: translateY(-2px);
        border-color: #dc2626 !important;
        box-shadow: 0 0 15px rgba(248, 113, 113, 0.5);
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

    .smiski-modal-backdrop {
        display: none;
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(5, 46, 22, 0.85);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        align-items: center;
        justify-content: center;
        z-index: 2000;
        padding: 1rem;
    }
</style>

<div class="container" style="padding: 2rem 1rem; max-width: 1400px; margin: 0 auto;">

    <div style="margin-bottom: 1.5rem;">
        <h2 class="white-glow-heading" style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.025em; margin-bottom: 0.25rem;">
            <i class="fa-solid fa-receipt" style="color: #86efac;"></i> Order Management & Payment Verification
        </h2>
        <p class="white-subtext" style="font-size: 0.9rem;">Review orders, verify GCash receipt screenshots, approve payments, and update delivery status</p>
    </div>

    <?php if ($message): ?>
        <div style="background: rgba(220, 252, 231, 0.95); border: 1px solid rgba(74, 222, 128, 0.6); color: #064e3b; padding: 0.85rem 1rem; border-radius: 12px; font-size: 0.9rem; margin-bottom: 1.5rem; font-weight: 600; box-shadow: 0 5px 15px rgba(0,0,0,0.15);">
            <i class="fa-solid fa-circle-check" style="color: #15803d;"></i> <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div style="background: rgba(254, 226, 226, 0.95); border: 1px solid rgba(248, 113, 113, 0.6); color: #991b1b; padding: 0.85rem 1rem; border-radius: 12px; font-size: 0.9rem; margin-bottom: 1.5rem; font-weight: 600; box-shadow: 0 5px 15px rgba(0,0,0,0.15);">
            <i class="fa-solid fa-circle-exclamation" style="color: #dc2626;"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="smiski-card" style="padding: 0; overflow: hidden;">
        <div style="overflow-x: auto;">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Payment Method</th>
                        <th>GCash Receipt</th>
                        <th>Total Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="9" class="text-muted-green" style="text-align: center; padding: 3rem; font-size: 0.95rem;">No orders found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $index => $o): ?>
                            <?php
                            $isGcash = strpos(strtolower($o['payment_method']), 'gcash') !== false || strpos(strtolower($o['payment_method']), 'wallet') !== false;
                            ?>
                            <tr>
                                <td style="font-weight: 700; color: #064e3b;"><?= htmlspecialchars($o['order_number']) ?></td>
                                <td>
                                    <div style="font-weight: 700; color: #064e3b;"><?= htmlspecialchars($o['customer_name']) ?></div>
                                    <div class="text-muted-green" style="font-size: 0.75rem;"><?= htmlspecialchars($o['customer_email'] ?? '') ?></div>
                                </td>
                                <td class="text-muted-green" style="font-size: 0.85rem;"><?= htmlspecialchars($o['phone']) ?></td>
                                <td>
                                    <div style="font-weight: 600; font-size: 0.85rem; color: #064e3b;"><?= htmlspecialchars($o['payment_method']) ?></div>
                                    <?php if ($o['payment_status'] === 'paid'): ?>
                                        <span style="font-size: 0.72rem; color: #15803d; font-weight: 700;"><i class="fa-solid fa-circle-check"></i> Paid / Approved</span>
                                    <?php elseif ($o['payment_status'] === 'pending_verification'): ?>
                                        <span style="font-size: 0.72rem; color: #b45309; font-weight: 700;"><i class="fa-solid fa-clock"></i> Verification Pending</span>
                                    <?php elseif ($o['payment_status'] === 'rejected'): ?>
                                        <span style="font-size: 0.72rem; color: #991b1b; font-weight: 700;"><i class="fa-solid fa-ban"></i> Payment Rejected</span>
                                    <?php else: ?>
                                        <span class="text-muted-green" style="font-size: 0.72rem;"><i class="fa-solid fa-hand-holding-dollar"></i> COD (Unpaid)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isGcash && !empty($o['payment_receipt'])): ?>
                                        <button type="button" onclick='openReceiptModal(<?= htmlspecialchars(json_encode($o), ENT_QUOTES, "UTF-8") ?>)' style="padding: 0.35rem 0.65rem; font-size: 0.78rem; background: rgba(220, 252, 231, 0.9); color: #15803d; border: 1px solid rgba(74, 222, 128, 0.6); border-radius: 8px; cursor: pointer; font-weight: 600;">
                                            <i class="fa-solid fa-image"></i> View Receipt
                                        </button>
                                    <?php elseif ($isGcash && ($o['payment_status'] ?? '') !== 'paid'): ?>
                                        <button type="button" onclick='openReceiptModal(<?= htmlspecialchars(json_encode($o), ENT_QUOTES, "UTF-8") ?>)' style="padding: 0.35rem 0.65rem; font-size: 0.78rem; background: rgba(254, 243, 199, 0.9); color: #92400e; border: 1px solid rgba(251, 191, 36, 0.6); border-radius: 8px; cursor: pointer; font-weight: 600;">
                                            <i class="fa-solid fa-receipt"></i> Verify Payment
                                        </button>
                                    <?php elseif ($isGcash): ?>
                                        <span style="font-size: 0.78rem; color: #15803d; font-weight: 600;"><i class="fa-solid fa-circle-check"></i> Approved</span>
                                    <?php elseif (strpos(strtolower($o['payment_method']), 'card') !== false): ?>
                                        <span style="font-size: 0.78rem; color: #15803d; font-weight: 600;"><i class="fa-solid fa-shield-halved"></i> Auto-Approved</span>
                                    <?php else: ?>
                                        <span class="text-muted-green" style="font-size: 0.78rem;">N/A (COD)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight: 700; color: #15803d;">₱<?= number_format($o['total_amount'], 2) ?></td>
                                <td>
                                    <?php
                                    $bgCol = 'rgba(254, 243, 199, 0.9)'; $txtCol = '#92400e'; $borderCol = 'rgba(251, 191, 36, 0.6)';
                                    if ($o['status'] === 'delivered') { $bgCol = 'rgba(220, 252, 231, 0.9)'; $txtCol = '#15803d'; $borderCol = 'rgba(74, 222, 128, 0.6)'; }
                                    elseif ($o['status'] === 'shipped') { $bgCol = 'rgba(224, 242, 254, 0.9)'; $txtCol = '#0369a1'; $borderCol = 'rgba(56, 189, 248, 0.6)'; }
                                    elseif ($o['status'] === 'cancelled') { $bgCol = 'rgba(254, 226, 226, 0.9)'; $txtCol = '#991b1b'; $borderCol = 'rgba(248, 113, 113, 0.6)'; }
                                    elseif ($o['status'] === 'processing') { $bgCol = 'rgba(238, 242, 255, 0.9)'; $txtCol = '#3730a3'; $borderCol = 'rgba(129, 140, 248, 0.6)'; }
                                    ?>
                                    <span style="background: <?= $bgCol ?>; color: <?= $txtCol ?>; border: 1px solid <?= $borderCol ?>; padding: 0.25rem 0.6rem; border-radius: 8px; font-size: 0.75rem; font-weight: 700; display: inline-block;">
                                        <?= ucfirst($o['status']) ?>
                                    </span>
                                </td>
                                <td class="text-muted-green" style="font-size: 0.85rem; white-space: nowrap;"><?= date('M d, Y h:i A', strtotime($o['created_at'])) ?></td>
                                <td style="text-align: center; white-space: nowrap;">
                                    <div style="display: inline-flex; gap: 0.4rem; align-items: center; justify-content: center;">
                                        <button onclick="viewOrderDetails(<?= $o['id'] ?>)" title="View Details" style="background: rgba(255, 255, 255, 0.9); color: #15803d; border: 1px solid rgba(74, 222, 128, 0.6); padding: 0.35rem 0.65rem; border-radius: 8px; cursor: pointer; font-size: 0.78rem; font-weight: 600;">
                                            <i class="fa-solid fa-eye"></i> Details
                                        </button>
                                        <button onclick='openStatusModal(<?= htmlspecialchars(json_encode($o), ENT_QUOTES, "UTF-8") ?>)' title="Update Status" class="btn-green-primary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem;">
                                            <i class="fa-solid fa-arrows-rotate"></i> Status
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this order record?')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $o['id'] ?>">
                                            <button type="submit" class="btn-danger-glow" style="padding: 0.35rem 0.65rem; font-size: 0.78rem;" title="Delete Order">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="receiptModal" class="smiski-modal-backdrop">
    <div class="smiski-card" style="max-width: 550px; width: 100%; position: relative; text-align: center; margin: 0; color: #1f2937;">
        <button onclick="closeReceiptModal()" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #15803d;">&times;</button>
        <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem;">
            <i class="fa-solid fa-receipt" style="color: #15803d;"></i> GCash Payment Receipt
        </h3>
        <p class="text-muted-green" style="font-size: 0.85rem; margin-bottom: 1.25rem;">
            Order #: <strong id="receipt_order_num" style="color: #064e3b;"></strong> | Total: <strong id="receipt_total_amount" style="color: #15803d;"></strong>
        </p>

        <div class="smiski-subcard" style="margin-bottom: 1.5rem; max-height: 380px; overflow: auto; padding: 0.75rem;">
            <img id="receipt_image_preview" src="" alt="GCash Receipt Screenshot" style="max-width: 100%; height: auto; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.2);">
            <div id="no_receipt_notice" style="display: none; padding: 2rem; color: #4b5563; font-weight: 600;">
                <i class="fa-solid fa-file-image" style="font-size: 2.5rem; margin-bottom: 0.5rem; display: block; color: #15803d;"></i>
                No receipt image screenshot uploaded by customer.
            </div>
        </div>

        <div id="receipt_locked_notice" style="display: none; background: rgba(254, 243, 199, 0.95); border: 1px solid rgba(251, 191, 36, 0.6); color: #92400e; padding: 0.75rem 1rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600; margin-bottom: 1rem;"></div>

        <div style="display: flex; gap: 0.75rem; justify-content: center;">
            <form method="POST" style="flex: 1;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="approve_payment">
                <input type="hidden" name="id" id="receipt_approve_id">
                <button type="submit" id="receipt_approve_btn" class="btn-green-primary" style="width: 100%; padding: 0.75rem; justify-content: center;">
                    <i class="fa-solid fa-circle-check"></i> Approve Payment
                </button>
            </form>

            <form method="POST" style="flex: 1;" onsubmit="return confirm('Are you sure you want to reject this payment and cancel the order?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reject_payment">
                <input type="hidden" name="id" id="receipt_reject_id">
                <button type="submit" id="receipt_reject_btn" class="btn-danger-glow" style="width: 100%; padding: 0.75rem; justify-content: center;">
                    <i class="fa-solid fa-ban"></i> Reject & Cancel
                </button>
            </form>
        </div>
    </div>
</div>

<div id="statusModal" class="smiski-modal-backdrop">
    <div class="smiski-card" style="max-width: 500px; width: 100%; position: relative; text-align: left; margin: 0; color: #1f2937;">
        <button onclick="closeStatusModal()" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #15803d;">&times;</button>
        <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem;">Update Order Status</h3>

        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" id="status_order_id">

            <div style="margin-bottom: 1rem; color: #1f2937; font-size: 0.9rem;">
                <strong>Order Number:</strong> <span id="status_order_num" style="color: #15803d; font-weight: 700;"></span>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Select New Status</label>
                <select name="status" id="status_select" style="width:100%; padding:0.75rem 1rem; border:1px solid rgba(74, 222, 128, 0.5); border-radius:12px; background: rgba(255, 255, 255, 0.85); color: #1f2937; outline: none; font-size: 0.95rem;">
                    <option value="pending">Pending</option>
                    <option value="processing">Processing</option>
                    <option value="shipped">Shipped (Out for Delivery)</option>
                    <option value="delivered">Delivered (Completed)</option>
                    <option value="cancelled">Cancelled (Finalized / Rejected)</option>
                </select>
                <p class="text-muted-green" style="font-size: 0.78rem; margin-top: 0.4rem;">
                    Note: Setting status to <strong>Cancelled</strong> automatically restores reserved inventory stock. Setting to <strong>Delivered</strong> marks COD payment as completed. Delivered and Cancelled orders are final and can no longer be changed.
                </p>
                <div id="status_locked_notice" style="display: none; background: rgba(254, 243, 199, 0.95); border: 1px solid rgba(251, 191, 36, 0.6); color: #92400e; padding: 0.75rem 1rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600; margin-top: 0.75rem;"></div>
            </div>

            <button type="submit" id="status_submit_btn" class="btn-green-primary" style="width: 100%; padding: 0.75rem; justify-content: center; font-size: 0.95rem;">
                <i class="fa-solid fa-check"></i> Save Status Change
            </button>
        </form>
    </div>
</div>

<div id="viewModal" class="smiski-modal-backdrop">
    <div class="smiski-card" style="max-width: 650px; width: 100%; position: relative; text-align: left; max-height: 90vh; overflow-y: auto; margin: 0; color: #1f2937;">
        <button onclick="closeViewModal()" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #15803d;">&times;</button>
        <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem;">Order Details</h3>

        <div id="orderDetailsContent" style="font-size: 0.9rem; color: #374151;">
            Loading order details...
        </div>
    </div>
</div>

<script>
function openReceiptModal(o) {
    document.getElementById('receipt_approve_id').value = o.id;
    document.getElementById('receipt_reject_id').value = o.id;
    document.getElementById('receipt_order_num').innerText = o.order_number;
    document.getElementById('receipt_total_amount').innerText = '₱' + parseFloat(o.total_amount).toFixed(2);
    
    const img = document.getElementById('receipt_image_preview');
    const notice = document.getElementById('no_receipt_notice');

    if (o.payment_receipt && o.payment_receipt.trim() !== '') {
        img.src = '../' + o.payment_receipt;
        img.style.display = 'block';
        if (notice) notice.style.display = 'none';
    } else {
        img.style.display = 'none';
        if (notice) notice.style.display = 'block';
    }

    const status = (o.status || '').toLowerCase();
    const approveBtn = document.getElementById('receipt_approve_btn');
    const rejectBtn = document.getElementById('receipt_reject_btn');
    const lockNotice = document.getElementById('receipt_locked_notice');
    const approveLocked = (status === 'delivered' || status === 'cancelled');
    const rejectLocked = (status === 'delivered');
    setButtonLocked(approveBtn, approveLocked);
    setButtonLocked(rejectBtn, rejectLocked);
    if (approveLocked) {
        lockNotice.innerHTML = '<i class="fa-solid fa-lock"></i> This order is already ' + status.charAt(0).toUpperCase() + status.slice(1) + '. Payment actions are no longer available.';
        lockNotice.style.display = 'block';
    } else {
        lockNotice.style.display = 'none';
    }
    document.getElementById('receiptModal').style.display = 'flex';
}

function setButtonLocked(btn, locked) {
    if (!btn) return;
    btn.disabled = locked;
    btn.style.opacity = locked ? '0.5' : '';
    btn.style.cursor = locked ? 'not-allowed' : '';
}
function closeReceiptModal() { document.getElementById('receiptModal').style.display = 'none'; }

function openStatusModal(o) {
    document.getElementById('status_order_id').value = o.id;
    document.getElementById('status_order_num').innerText = o.order_number;
    const select = document.getElementById('status_select');
    const notice = document.getElementById('status_locked_notice');
    const submitBtn = document.getElementById('status_submit_btn');
    const current = (o.status || '').toLowerCase();
    const locked = (current === 'delivered' || current === 'cancelled');

    select.value = current;
    Array.from(select.options).forEach(opt => {
        opt.disabled = locked && opt.value !== current;
    });
    select.disabled = locked;
    setButtonLocked(submitBtn, locked);

    if (locked) {
        notice.innerHTML = '<i class="fa-solid fa-lock"></i> This order is already ' + current.charAt(0).toUpperCase() + current.slice(1) + '. Its status can no longer be changed.';
        notice.style.display = 'block';
    } else {
        notice.style.display = 'none';
    }
    document.getElementById('statusModal').style.display = 'flex';
}
function closeStatusModal() { document.getElementById('statusModal').style.display = 'none'; }

function viewOrderDetails(orderId) {
    document.getElementById('viewModal').style.display = 'flex';
    document.getElementById('orderDetailsContent').innerHTML = 'Loading...';

    const box = document.getElementById('orderDetailsContent');
    const showProblem = (msg) => {
        box.innerHTML = '';
        const div = document.createElement('div');
        div.className = 'form-alert form-alert-error';
        div.textContent = msg;
        box.appendChild(div);
    };
    fetch('orders.php?ajax_details=' + encodeURIComponent(orderId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    })
        .then(res => {
            if (res.ok) return res.text().then(html => { box.innerHTML = html; });
            return res.json()
                .then(data => showProblem(data.message || 'Something went wrong. Please try again later.'))
                .catch(() => showProblem('Something went wrong. Please try again later.'));
        })
        .catch(() => showProblem('Could not load the order details. Please check your connection and try again.'));
}
function closeViewModal() { document.getElementById('viewModal').style.display = 'none'; }
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>