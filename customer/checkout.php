<?php
require_once __DIR__ . '/../config/db.php';

if (function_exists('isAdminLoggedIn') && isAdminLoggedIn()) {
    header("Location: index.php");
    exit;
}

if (function_exists('isCustomerLoggedIn') && !isCustomerLoggedIn()) {
    $_SESSION['redirect_after_login'] = 'checkout.php';
    $_SESSION['login_prompt'] = 'Please log in or sign up to complete your order checkout.';
    header("Location: login.php");
    exit;
}

// Load the customer's saved cart so checkout matches what the cart shows
syncCustomerCart();

if (empty($_SESSION['cart'])) {
    header("Location: cart.php");
    exit;
}

$db = getDB();

try {
    $db->exec("ALTER TABLE orders ADD COLUMN payment_receipt VARCHAR(255) NULL AFTER payment_method");
    $db->exec("ALTER TABLE orders ADD COLUMN payment_status VARCHAR(50) DEFAULT 'unpaid' AFTER payment_receipt");
} catch (Exception $e) {
    // Ignore if columns already exist
}

$shippingCost = 50.00;
$taxRate = 12.0; 
$loyaltyDiscountRate = 5.0;   // Loyal Customer Discount (% off product subtotal)
$loyaltyMinPurchases = 5;     // Completed (delivered) purchases needed to qualify

$settingsStmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $settingsStmt->fetch()) {
    if ($row['setting_key'] === 'shipping_cost') $shippingCost = floatval($row['setting_value']);
    if ($row['setting_key'] === 'tax_rate') $taxRate = floatval($row['setting_value']);
    if ($row['setting_key'] === 'loyalty_discount_rate') $loyaltyDiscountRate = floatval($row['setting_value']);
    if ($row['setting_key'] === 'loyalty_min_purchases') $loyaltyMinPurchases = max(1, intval($row['setting_value']));
}

/**
 * Loyalty status from the customer's purchase history.
 * Only successfully completed purchases (orders marked Delivered) count,
 * so the status is re-assessed whenever an order is delivered.
 */
function getLoyaltyStatus(PDO $db, $customerId, $minPurchases) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND status = 'delivered'");
    $stmt->execute([$customerId]);
    $completed = (int)$stmt->fetchColumn();
    return [
        'completed' => $completed,
        'required'  => $minPurchases,
        'remaining' => max(0, $minPurchases - $completed),
        'eligible'  => $completed >= $minPurchases,
    ];
}

$loyalty = getLoyaltyStatus($db, (int)($_SESSION['user_id'] ?? 0), $loyaltyMinPurchases);
$loyaltyRateLabel = rtrim(rtrim(number_format($loyaltyDiscountRate, 2, '.', ''), '0'), '.') . '%';

// Applied automatically for eligible customers; they can choose "Remove Discount".
// Removing it only affects this order — loyalty status is based on purchase history.
$applyLoyaltyDiscount = $loyalty['eligible']
    && (($_SERVER['REQUEST_METHOD'] === 'POST') ? (($_POST['apply_loyalty_discount'] ?? '1') === '1') : true);

$productIds = array_keys($_SESSION['cart']);
$inClause = implode(',', array_fill(0, count($productIds), '?'));
$stmt = $db->prepare("SELECT * FROM products WHERE id IN ($inClause)");
$stmt->execute($productIds);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cartItems = [];
$subtotal = 0;

foreach ($products as $p) {
    $qty = $_SESSION['cart'][$p['id']];
    $itemSubtotal = $p['price'] * $qty;
    $subtotal += $itemSubtotal;

    $cartItems[] = [
        'id' => $p['id'],
        'name' => $p['name'],
        'price' => $p['price'],
        'quantity' => $qty,
        'subtotal' => $itemSubtotal
    ];
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerName = sanitize($_POST['customer_name'] ?? ($_SESSION['user_name'] ?? ''));
    $phone = sanitize($_POST['phone'] ?? ($_SESSION['user_phone'] ?? ''));
    $address = sanitize($_POST['delivery_address'] ?? ($_SESSION['user_address'] ?? ''));
    $paymentMethod = sanitize($_POST['payment_method'] ?? 'COD');

    $paymentReceipt = null;
    $paymentStatus = 'unpaid';

    $isGcash = (strpos(strtolower($paymentMethod), 'gcash') !== false || strpos(strtolower($paymentMethod), 'wallet') !== false || strpos(strtolower($paymentMethod), 'e-wallet') !== false);
    $isCard = (strpos(strtolower($paymentMethod), 'card') !== false || strpos(strtolower($paymentMethod), 'credit') !== false);

    if ($isGcash) {
        $paymentStatus = 'pending_verification';
        if (isset($_FILES['payment_receipt']) && $_FILES['payment_receipt']['error'] !== UPLOAD_ERR_NO_FILE) {
            $receiptErr = $_FILES['payment_receipt']['error'];
            if ($receiptErr === UPLOAD_ERR_INI_SIZE || $receiptErr === UPLOAD_ERR_FORM_SIZE) {
                $error = 'The payment receipt image is too large. Please select a screenshot under 5MB or compress it first.';
            } else if ($receiptErr !== UPLOAD_ERR_OK) {
                $error = 'Failed to upload GCash receipt screenshot (Error code: ' . $receiptErr . ').';
            } else {
                $uploadDir = __DIR__ . '/../assets/uploads/receipts/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0777, true);
                }
                $ext = strtolower(pathinfo($_FILES['payment_receipt']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'avif', 'bmp', 'tiff'];
                if (!in_array($ext, $allowed)) {
                    $error = 'Invalid image format (.' . htmlspecialchars($ext) . ') for GCash receipt. Accepted formats: PNG, JPG, WebP, HEIC.';
                } else {
                    $filename = 'gcash_' . time() . '_' . uniqid() . '.' . $ext;
                    $targetFile = $uploadDir . $filename;
                    if (move_uploaded_file($_FILES['payment_receipt']['tmp_name'], $targetFile)) {
                        @chmod($targetFile, 0666);
                        $paymentReceipt = 'assets/uploads/receipts/' . $filename;
                    } else {
                        $error = 'Failed to save GCash receipt screenshot. Please check write permissions for assets/uploads/receipts/';
                    }
                }
            }
        } else {
            $error = 'Please upload a screenshot of your GCash payment receipt.';
        }
    } elseif ($isCard) {
        $cardName = sanitize($_POST['card_name'] ?? '');
        $cardNumber = preg_replace('/\s+/', '', sanitize($_POST['card_number'] ?? ''));
        $cardExpiry = sanitize($_POST['card_expiry'] ?? '');
        $cardCvv = sanitize($_POST['card_cvv'] ?? '');

        if (empty($cardName) || strlen($cardNumber) < 13 || empty($cardExpiry) || strlen($cardCvv) < 3) {
            $error = 'Please enter valid Credit/Debit Card details (Cardholder Name, 16-digit Card Number, Expiry MM/YY, and CVV).';
        } else {
            $paymentStatus = 'paid';
            $last4 = substr($cardNumber, -4);
            $paymentMethod = "Card (ending in $last4)";
        }
    }

    if (empty($customerName) || empty($phone) || empty($address)) {
        $error = 'Please complete your order details (Name, Phone, and Delivery Address).';
    }

    if (empty($error)) {
        // Re-check eligibility against the latest purchase history before placing the order
        $loyalty = getLoyaltyStatus($db, (int)($_SESSION['user_id'] ?? 0), $loyaltyMinPurchases);
        $applyLoyaltyDiscount = $loyalty['eligible'] && (($_POST['apply_loyalty_discount'] ?? '1') === '1');

        // Only one discount per order: the Loyal Customer Discount (no other promotions are combined)
        $discountAmount = $applyLoyaltyDiscount ? round($subtotal * ($loyaltyDiscountRate / 100), 2) : 0;
        $discountedSubtotal = $subtotal - $discountAmount;
        $taxAmount = $discountedSubtotal * ($taxRate / 100);
        $totalAmount = $discountedSubtotal + $taxAmount + $shippingCost;

        try {
            $db->beginTransaction();

            $userCheck = $db->prepare("SELECT id FROM users WHERE id = ?");
            $userCheck->execute([$_SESSION['user_id'] ?? 0]);
            $customerId = $userCheck->fetchColumn();

            if (!$customerId) {
                $email = $_SESSION['user_email'] ?? ('customer_' . time() . '_' . rand(100, 999) . '@store.com');
                $insUser = $db->prepare("INSERT INTO users (full_name, email, password, phone, address, role, status) VALUES (?, ?, 'customer123', ?, ?, 'customer', 'active')");
                $insUser->execute([$customerName, $email, $phone, $address]);
                $customerId = $db->lastInsertId();
                $_SESSION['user_id'] = $customerId;
                $_SESSION['user_name'] = $customerName;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_role'] = 'customer';
            }

            $orderStmt = $db->prepare("
                INSERT INTO orders (customer_id, customer_name, phone, delivery_address, payment_method, payment_receipt, payment_status, subtotal, discount_type, discount_amount, shipping_cost, tax_amount, total_amount, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");

            // discount_type column is VARCHAR(20)
            $discountType = $applyLoyaltyDiscount ? substr('Loyal Customer (' . $loyaltyRateLabel . ')', 0, 20) : 'None';

            $orderStmt->execute([
                $customerId,
                $customerName,
                $phone,
                $address,
                $paymentMethod,
                $paymentReceipt,
                $paymentStatus,
                $subtotal,
                $discountType,
                $discountAmount,
                $shippingCost,
                $taxAmount,
                $totalAmount
            ]);

            $orderId = $db->lastInsertId();

            $noStmt = $db->prepare("SELECT order_number FROM orders WHERE id = ?");
            $noStmt->execute([$orderId]);
            $orderNum = $noStmt->fetchColumn();

            $itemStmt = $db->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
            // Only deduct stock when enough units are available (prevents negative stock)
            $stockStmt = $db->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?");
            $stockLeftStmt = $db->prepare("SELECT stock_quantity FROM products WHERE id = ?");

            foreach ($cartItems as $item) {
                $stockStmt->execute([$item['quantity'], $item['id'], $item['quantity']]);
                if ($stockStmt->rowCount() === 0) {
                    $stockLeftStmt->execute([$item['id']]);
                    $left = max(0, (int)$stockLeftStmt->fetchColumn());
                    throw new Exception('Sorry, only ' . $left . ' unit(s) of "' . $item['name'] . '" are left in stock. Please update the quantity in your cart.');
                }
                $itemStmt->execute([$orderId, $item['id'], $item['name'], $item['quantity'], $item['price'], $item['subtotal']]);
            }

            $db->commit();

            if (function_exists('logActivity')) {
                $discountNote = $applyLoyaltyDiscount ? " with Loyal Customer Discount (-₱" . number_format($discountAmount, 2) . ")" : "";
                logActivity($_SESSION['user_id'], 'ORDER_PLACED', "Order $orderNum placed for total amount ₱" . number_format($totalAmount, 2) . $discountNote);
            }

            $_SESSION['cart'] = [];
            persistCart(); // clear the saved cart as well
            $_SESSION['latest_order'] = [
                'order_number' => $orderNum,
                'total_amount' => $totalAmount,
                'payment_method' => $paymentMethod
            ];

            header("Location: success.php");
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Failed to place order: ' . $e->getMessage();
        }
    }
}

// Values for the initial order summary (JavaScript recalculates when the discount is removed/applied)
$loyaltyDiscountValue = $loyalty['eligible'] ? round($subtotal * ($loyaltyDiscountRate / 100), 2) : 0;
$previewDiscount = $applyLoyaltyDiscount ? $loyaltyDiscountValue : 0;
$previewTax = ($subtotal - $previewDiscount) * ($taxRate / 100);
$previewTotal = ($subtotal - $previewDiscount) + $previewTax + $shippingCost;

if (file_exists('includes/header.php')) {
    include 'includes/header.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order Checkout</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
            color: #f0fdf4 !important;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* Main Glass Card with Smiski Glow (Matched with cart.php) */
        .smiski-card {
            background: rgba(255, 255, 255, 0.78) !important;
            backdrop-filter: blur(30px) saturate(180%);
            -webkit-backdrop-filter: blur(30px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.5) !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35), 0 0 20px rgba(134, 239, 172, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.8) !important;
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s ease, border-color 0.4s ease;
            border-radius: 24px;
        }
        .smiski-card:hover {
            transform: translateY(-2px);
            border-color: #86efac !important;
            background: rgba(255, 255, 255, 0.88) !important;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4), 0 0 30px rgba(134, 239, 172, 0.5), inset 0 0 15px rgba(134, 239, 172, 0.3) !important;
        }

        /* Subcards inside Details (Matched with cart.php) */
        .smiski-subcard {
            background: rgba(255, 255, 255, 0.55) !important;
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border: 1px solid rgba(74, 222, 128, 0.4) !important;
            border-radius: 16px;
            box-shadow: inset 0 2px 5px rgba(255, 255, 255, 0.9), 0 0 12px rgba(74, 222, 128, 0.2);
            transition: all 0.3s ease;
        }
        .smiski-subcard.active-payment {
            border-color: #15803d !important;
            background: rgba(220, 252, 231, 0.85) !important;
            box-shadow: 0 0 15px rgba(74, 222, 128, 0.5);
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
            padding: 0.75rem 1.25rem;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
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
            padding: 0.75rem 1.25rem;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }
        .btn-green-secondary:hover {
            background: rgba(255, 255, 255, 0.9) !important;
            transform: translateY(-2px);
            border-color: #15803d !important;
            box-shadow: 0 0 15px rgba(74, 222, 128, 0.5);
        }

        /* Neon Glow-in-the-Dark Text Effect for Glass White Backgrounds */
        .neon-glow-text {
            color: #064e3b !important;
            text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
        }

        /* Form Inputs */
        .smiski-input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(74, 222, 128, 0.5);
            border-radius: 12px;
            color: #1f2937;
            outline: none;
            font-size: 0.95rem;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }
        .smiski-input:focus {
            border-color: #86efac;
            box-shadow: 0 0 15px rgba(74, 222, 128, 0.4);
            background: #ffffff;
        }
    </style>
</head>
<body>

<div class="container" style="max-width: 1200px; margin: 0 auto; padding-top: 1.5rem; padding-bottom: 3rem; padding-left: 1rem; padding-right: 1rem;">

    <!-- Header Section -->
    <div class="smiski-card" style="padding: 2rem 2.5rem; margin-bottom: 2rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1.5rem;">
            <div>
                <span style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); color: #064e3b; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.8rem; border-radius: 20px; display: inline-block; margin-bottom: 0.75rem; border: 1px solid rgba(74,222,128,0.6); box-shadow: 0 0 10px rgba(74,222,128,0.4);">
                    <i class="fa-solid fa-credit-card"></i> SECURE CHECKOUT
                </span>
                <h1 class="neon-glow-text" style="font-size: 2.25rem; font-weight: 700; margin-bottom: 0.4rem;">Order Check Out</h1>
                <p class="text-muted-green" style="font-size: 1rem; line-height: 1.5;">Review your items, complete delivery info, and place your order.</p>
            </div>
            <div>
                <a href="cart.php" class="btn-green-secondary" style="text-decoration: none;">
                    <i class="fa-solid fa-arrow-left"></i> Back to Cart View
                </a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="smiski-card" style="background: rgba(254, 226, 226, 0.95) !important; border: 1px solid rgba(248, 113, 113, 0.8); color: #991b1b; padding: 1.25rem 1.5rem; border-radius: 16px; margin-bottom: 2rem; font-weight: 600; display: flex; align-items: center; gap: 0.75rem;">
            <i class="fa-solid fa-circle-exclamation" style="font-size: 1.4rem; color: #dc2626;"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" action="checkout.php" id="checkoutForm" enctype="multipart/form-data">
        <div style="display: flex; gap: 2rem; flex-wrap: wrap; align-items: flex-start;">

            <!-- Left Column: Details & Products -->
            <div style="flex: 2; min-width: 320px; display: flex; flex-direction: column; gap: 1.75rem;">

                <!-- Customer Details -->
                <div class="smiski-card" style="padding: 1.75rem 2rem;">
                    <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.6rem; border-bottom: 1px solid rgba(74, 222, 128, 0.3); padding-bottom: 0.75rem;">
                        <i class="fa-solid fa-user-gear" style="color: #15803d;"></i> Customer & Delivery Details
                    </h3>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
                        <div>
                            <label class="text-muted-green" style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem;">Full Name</label>
                            <input type="text" name="customer_name" required value="<?= htmlspecialchars($_POST['customer_name'] ?? ($_SESSION['user_name'] ?? '')) ?>" class="smiski-input">
                        </div>
                        <div>
                            <label class="text-muted-green" style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem;">Phone Number</label>
                            <input type="text" name="phone" required value="<?= htmlspecialchars($_POST['phone'] ?? ($_SESSION['user_phone'] ?? '')) ?>" class="smiski-input">
                        </div>
                    </div>

                    <div>
                        <label class="text-muted-green" style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem;">Delivery Address</label>
                        <textarea name="delivery_address" rows="3" required class="smiski-input" style="font-family:inherit; resize: vertical;"><?= htmlspecialchars($_POST['delivery_address'] ?? ($_SESSION['user_address'] ?? '')) ?></textarea>
                    </div>
                </div>

                <!-- Payment Method -->
                <div class="smiski-card" style="padding: 1.75rem 2rem;">
                    <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.6rem; border-bottom: 1px solid rgba(74, 222, 128, 0.3); padding-bottom: 0.75rem;">
                        <i class="fa-solid fa-wallet" style="color: #15803d;"></i> Payment Method
                    </h3>

                    <div style="display: flex; flex-direction: column; gap: 0.85rem;">
                        <label class="smiski-subcard active-payment" style="display: flex; align-items: center; gap: 0.85rem; padding: 1rem 1.25rem; cursor: pointer;">
                            <input type="radio" name="payment_method" value="Cash on Delivery (COD)" checked style="accent-color: #15803d; width:18px; height:18px;">
                            <div>
                                <div style="font-weight: 700; font-size: 0.95rem; color: #064e3b;">Cash on Delivery (COD)</div>
                                <div class="text-muted-green" style="font-size: 0.8rem;">Pay in cash upon doorstep delivery</div>
                            </div>
                        </label>

                        <label class="smiski-subcard" style="display: flex; align-items: center; gap: 0.85rem; padding: 1rem 1.25rem; cursor: pointer;">
                            <input type="radio" name="payment_method" value="E-Wallet / GCash" style="accent-color: #15803d; width:18px; height:18px;">
                            <div>
                                <div style="font-weight: 700; font-size: 0.95rem; color: #064e3b;">E-Wallet / GCash / Maya</div>
                                <div class="text-muted-green" style="font-size: 0.8rem;">Pay via digital wallet & upload receipt screenshot</div>
                            </div>
                        </label>

                        <label class="smiski-subcard" style="display: flex; align-items: center; gap: 0.85rem; padding: 1rem 1.25rem; cursor: pointer;">
                            <input type="radio" name="payment_method" value="Credit / Debit Card" style="accent-color: #15803d; width:18px; height:18px;">
                            <div>
                                <div style="font-weight: 700; font-size: 0.95rem; color: #064e3b;">Credit / Debit Card</div>
                                <div class="text-muted-green" style="font-size: 0.8rem;">Pay securely with Visa or Mastercard</div>
                            </div>
                        </label>
                    </div>

                    <!-- GCash Upload Section -->
                    <div id="gcashUploadSection" class="smiski-subcard" style="display: none; padding: 1.25rem; margin-top: 1.25rem; background: rgba(240, 253, 244, 0.85) !important;">
                        <div class="neon-glow-text" style="font-weight: 700; font-size: 0.95rem; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fa-solid fa-mobile-screen-button" style="color: #15803d;"></i> GCash Payment Info & Receipt Upload
                        </div>
                        <div class="text-muted-green" style="font-size: 0.88rem; margin-bottom: 0.85rem; line-height: 1.5;">
                            Please send GCash payment to <strong style="color: #064e3b;">0917-123-4567 (Official Store Account)</strong>, then attach a screenshot of your transaction receipt below for Admin verification.
                        </div>
                        <label class="text-muted-green" style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                            Upload GCash Receipt Screenshot <span style="color:#dc2626;">*</span>
                        </label>
                        <input type="file" name="payment_receipt" id="payment_receipt_input" accept="image/*" class="smiski-input" style="padding: 0.5rem;">
                    </div>

                    <!-- Card Payment Section -->
                    <div id="cardPaymentSection" class="smiski-subcard" style="display: none; padding: 1.25rem; margin-top: 1.25rem; background: rgba(240, 253, 244, 0.85) !important;">
                        <div style="font-weight: 700; color: #064e3b; font-size: 0.95rem; margin-bottom: 0.85rem; display: flex; align-items: center; justify-content: space-between;">
                            <span><i class="fa-solid fa-credit-card" style="color: #15803d;"></i> Card Details</span>
                            <div style="display: flex; gap: 0.4rem; font-size: 1.2rem; color: #166534;">
                                <i class="fa-brands fa-cc-visa"></i>
                                <i class="fa-brands fa-cc-mastercard"></i>
                                <i class="fa-brands fa-cc-jcb"></i>
                            </div>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 0.85rem;">
                            <div>
                                <label class="text-muted-green" style="display: block; font-size: 0.82rem; font-weight: 600; margin-bottom: 0.3rem;">Cardholder Name</label>
                                <input type="text" name="card_name" placeholder="e.g. Juan Dela Cruz" class="smiski-input">
                            </div>
                            <div>
                                <label class="text-muted-green" style="display: block; font-size: 0.82rem; font-weight: 600; margin-bottom: 0.3rem;">Card Number</label>
                                <input type="text" name="card_number" id="card_number_input" maxlength="19" placeholder="4532 •••• •••• 8901" class="smiski-input" style="letter-spacing:1px;">
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                                <div>
                                    <label class="text-muted-green" style="display: block; font-size: 0.82rem; font-weight: 600; margin-bottom: 0.3rem;">Expiry Date</label>
                                    <input type="text" name="card_expiry" id="card_expiry_input" maxlength="5" placeholder="MM/YY" class="smiski-input">
                                </div>
                                <div>
                                    <label class="text-muted-green" style="display: block; font-size: 0.82rem; font-weight: 600; margin-bottom: 0.3rem;">CVV / CVC</label>
                                    <input type="password" name="card_cvv" maxlength="4" placeholder="123" class="smiski-input">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Products Ordered -->
                <div class="smiski-card" style="padding: 1.75rem 2rem;">
                    <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.6rem; border-bottom: 1px solid rgba(74, 222, 128, 0.3); padding-bottom: 0.75rem;">
                        <i class="fa-solid fa-boxes-packing" style="color: #15803d;"></i> Products Ordered
                    </h3>

                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; min-width: 450px;">
                            <thead>
                                <tr style="border-bottom: 2px solid rgba(74, 222, 128, 0.4); text-align: left;">
                                    <th style="padding: 0.75rem 0.5rem; font-size: 0.85rem; color: #166534; font-weight: 700;">Product</th>
                                    <th style="padding: 0.75rem 0.5rem; font-size: 0.85rem; color: #166534; font-weight: 700; text-align: center;">Qty</th>
                                    <th style="padding: 0.75rem 0.5rem; font-size: 0.85rem; color: #166534; font-weight: 700; text-align: right;">Price</th>
                                    <th style="padding: 0.75rem 0.5rem; font-size: 0.85rem; color: #166534; font-weight: 700; text-align: right;">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cartItems as $item): ?>
                                    <tr style="border-bottom: 1px solid rgba(74, 222, 128, 0.2); transition: background 0.2s;">
                                        <td style="padding: 0.85rem 0.5rem; font-weight: 700; color: #064e3b; font-size: 0.95rem;"><?= htmlspecialchars($item['name']) ?></td>
                                        <td style="padding: 0.85rem 0.5rem; text-align: center; color: #166534; font-weight: 600;"><?= $item['quantity'] ?></td>
                                        <td style="padding: 0.85rem 0.5rem; text-align: right; color: #1f2937; font-weight: 600;">₱<?= number_format($item['price'], 2) ?></td>
                                        <td style="padding: 0.85rem 0.5rem; text-align: right; font-weight: 700; color: #15803d;">₱<?= number_format($item['subtotal'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- Right Column: Summary & Checkout Actions -->
            <div style="flex: 1; min-width: 280px; display: flex; flex-direction: column; gap: 1.75rem; height: fit-content;">

                <!-- Loyal Customer Discount -->
                <div class="smiski-card" style="padding: 1.75rem 2rem;">
                    <h4 class="neon-glow-text" style="font-size: 1.1rem; font-weight: 700; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-award" style="color: #15803d;"></i> Loyal Customer Discount
                    </h4>

                    <?php if ($loyalty['eligible']): ?>
                        <input type="hidden" name="apply_loyalty_discount" id="applyLoyaltyInput" value="<?= $applyLoyaltyDiscount ? '1' : '0' ?>">

                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.85rem; flex-wrap: wrap;">
                            <span style="background: rgba(220, 252, 231, 0.95); color: #15803d; border: 1px solid rgba(74, 222, 128, 0.7); font-size: 0.8rem; font-weight: 700; padding: 0.3rem 0.75rem; border-radius: 999px; display: inline-flex; align-items: center; gap: 0.35rem;">
                                <i class="fa-solid fa-circle-check"></i> Eligible - Loyal Customer
                            </span>
                            <span class="text-muted-green" style="font-size: 0.8rem;"><?= $loyalty['completed'] ?> completed purchase<?= $loyalty['completed'] === 1 ? '' : 's' ?></span>
                        </div>

                        <!-- Shown while the discount is applied -->
                        <div id="loyaltyAppliedBox" class="smiski-subcard" style="padding: 0.9rem 1rem; margin-bottom: 0.85rem; background: rgba(240, 253, 244, 0.9) !important; <?= $applyLoyaltyDiscount ? '' : 'display: none;' ?>">
                            <div style="font-weight: 700; color: #064e3b; font-size: 0.9rem; margin-bottom: 0.25rem;">
                                <i class="fa-solid fa-tag" style="color: #15803d;"></i> Loyal Customer Discount applied!
                            </div>
                            <div class="text-muted-green" style="font-size: 0.82rem; line-height: 1.5;">
                                <?= $loyaltyRateLabel ?> off your ₱<?= number_format($subtotal, 2) ?> subtotal: <strong style="color: #15803d;">-₱<?= number_format($loyaltyDiscountValue, 2) ?></strong>. You save <strong style="color: #15803d;">₱<?= number_format($loyaltyDiscountValue, 2) ?></strong> on this order.
                            </div>
                        </div>

                        <!-- Shown after the customer removes the discount -->
                        <div id="loyaltyRemovedBox" class="smiski-subcard" style="padding: 0.9rem 1rem; margin-bottom: 0.85rem; <?= $applyLoyaltyDiscount ? 'display: none;' : '' ?>">
                            <div style="font-weight: 700; color: #92400e; font-size: 0.9rem; margin-bottom: 0.25rem;">
                                <i class="fa-solid fa-circle-info"></i> Discount not applied to this order
                            </div>
                            <div class="text-muted-green" style="font-size: 0.82rem; line-height: 1.5;">
                                You are still a Loyal Customer. You can re-apply it now or use it on future orders.
                            </div>
                        </div>

                        <button type="button" id="removeLoyaltyBtn" class="btn-green-secondary" onclick="setLoyaltyDiscount(false)" style="width: 100%; justify-content: center; padding: 0.6rem; <?= $applyLoyaltyDiscount ? '' : 'display: none;' ?>">
                            <i class="fa-solid fa-xmark"></i> Remove Discount
                        </button>
                        <button type="button" id="applyLoyaltyBtn" class="btn-green-primary" onclick="setLoyaltyDiscount(true)" style="width: 100%; justify-content: center; padding: 0.6rem; <?= $applyLoyaltyDiscount ? 'display: none;' : '' ?>">
                            <i class="fa-solid fa-tag"></i> Apply <?= $loyaltyRateLabel ?> Discount
                        </button>

                        <p class="text-muted-green" style="font-size: 0.75rem; margin-top: 0.75rem; line-height: 1.4;">
                            Applied automatically, once per order. Cannot be combined with other promotional discounts.
                        </p>
                    <?php else: ?>
                        <div style="margin-bottom: 0.85rem;">
                            <span style="background: rgba(254, 243, 199, 0.95); color: #92400e; border: 1px solid rgba(251, 191, 36, 0.6); font-size: 0.8rem; font-weight: 700; padding: 0.3rem 0.75rem; border-radius: 999px; display: inline-flex; align-items: center; gap: 0.35rem;">
                                <i class="fa-solid fa-hourglass-half"></i> Not yet eligible
                            </span>
                        </div>
                        <p class="text-muted-green" style="font-size: 0.85rem; margin-bottom: 0.75rem; line-height: 1.5;">
                            You have <strong style="color: #064e3b;"><?= $loyalty['completed'] ?> of <?= $loyalty['required'] ?></strong> completed purchases.
                            Complete <strong style="color: #064e3b;"><?= $loyalty['remaining'] ?> more</strong> to unlock <?= $loyaltyRateLabel ?> off every order.
                        </p>
                        <div style="height: 8px; background: rgba(74, 222, 128, 0.2); border-radius: 999px; overflow: hidden; margin-bottom: 0.6rem;">
                            <div style="height: 100%; width: <?= min(100, round($loyalty['completed'] / max(1, $loyalty['required']) * 100)) ?>%; background: #15803d; border-radius: 999px;"></div>
                        </div>
                        <p class="text-muted-green" style="font-size: 0.75rem; line-height: 1.4;">A purchase counts once the order has been delivered.</p>
                    <?php endif; ?>
                </div>

                <!-- Payment Summary Box -->
                <div class="smiski-card" style="padding: 1.75rem 2rem;">
                    <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem; border-bottom: 1px solid rgba(74, 222, 128, 0.4); padding-bottom: 0.75rem;">Payment Summary</h3>

                    <div style="display: flex; flex-direction: column; gap: 0.85rem; font-size: 0.95rem; margin-bottom: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; color: #1f2937;">
                            <span>Product Subtotal</span>
                            <span style="font-weight: 600; color: #064e3b;">₱<?= number_format($subtotal, 2) ?></span>
                        </div>

                        <div id="discountRow" style="display: <?= $previewDiscount > 0 ? 'flex' : 'none' ?>; justify-content: space-between; color: #dc2626;">
                            <span>Loyal Customer Discount (<?= $loyaltyRateLabel ?>)</span>
                            <span style="font-weight: 600;">-₱<span id="displayDiscount"><?= number_format($previewDiscount, 2) ?></span></span>
                        </div>

                        <div style="display: flex; justify-content: space-between; color: #1f2937;">
                            <span>Shipping Fee</span>
                            <span style="font-weight: 600; color: #064e3b;">₱<?= number_format($shippingCost, 2) ?></span>
                        </div>

                        <div style="display: flex; justify-content: space-between; color: #1f2937;">
                            <span>VAT Tax (12%)</span>
                            <span style="font-weight: 600; color: #064e3b;">₱<span id="displayTax"><?= number_format($previewTax, 2) ?></span></span>
                        </div>

                        <hr style="border: 0; border-top: 1px dashed rgba(74, 222, 128, 0.4); margin: 0.5rem 0;">

                        <div style="display: flex; justify-content: space-between; font-size: 1.25rem; font-weight: 700; color: #15803d;">
                            <span>Total Amount</span>
                            <span class="neon-glow-text" style="font-size: 1.35rem; font-weight: 700;">₱<span id="displayGrandTotal"><?= number_format($previewTotal, 2) ?></span></span>
                        </div>

                        <div id="savingsNote" style="display: <?= $previewDiscount > 0 ? 'block' : 'none' ?>; text-align: right; font-size: 0.82rem; font-weight: 700; color: #15803d;">
                            <i class="fa-solid fa-piggy-bank"></i> You save ₱<span id="displaySavings"><?= number_format($previewDiscount, 2) ?></span>
                        </div>
                    </div>

                    <button type="submit" class="btn-green-primary" style="width: 100%; justify-content: center; padding: 0.9rem; font-size: 1.05rem; margin-bottom: 0.85rem; border-radius: 14px;">
                        <i class="fa-solid fa-bag-shopping"></i> Confirm Order / Place Order
                    </button>

                    <a href="cart.php" class="btn-green-secondary" style="width: 100%; justify-content: center; text-decoration: none; border-radius: 14px;">
                        <i class="fa-solid fa-arrow-left"></i> Back to Cart
                    </a>
                </div>

            </div>

        </div>
    </form>
</div>

<script>
const baseSubtotal = <?= json_encode($subtotal) ?>;
const shippingCost = <?= json_encode($shippingCost) ?>;
const taxRate = <?= json_encode($taxRate) ?>;
const loyaltyDiscountRate = <?= json_encode($loyaltyDiscountRate) ?>;
const isLoyalCustomer = <?= json_encode($loyalty['eligible']) ?>;

function formatPeso(value) {
    return value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function isLoyaltyApplied() {
    const input = document.getElementById('applyLoyaltyInput');
    return isLoyalCustomer && input !== null && input.value === '1';
}

function calculateTotal() {
    let discount = 0;
    if (isLoyaltyApplied()) {
        discount = Math.round(baseSubtotal * (loyaltyDiscountRate / 100) * 100) / 100;
    }
    const discountedSub = baseSubtotal - discount;
    const tax = discountedSub * (taxRate / 100);
    const grandTotal = discountedSub + tax + shippingCost;

    document.getElementById('displayDiscount').innerText = formatPeso(discount);
    document.getElementById('displayTax').innerText = formatPeso(tax);
    document.getElementById('displayGrandTotal').innerText = formatPeso(grandTotal);
    document.getElementById('displaySavings').innerText = formatPeso(discount);
    document.getElementById('discountRow').style.display = discount > 0 ? 'flex' : 'none';
    document.getElementById('savingsNote').style.display = discount > 0 ? 'block' : 'none';
}

// Apply / remove the Loyal Customer Discount for this order only (loyalty status is unaffected)
function setLoyaltyDiscount(apply) {
    const input = document.getElementById('applyLoyaltyInput');
    if (!isLoyalCustomer || !input) return;
    input.value = apply ? '1' : '0';
    document.getElementById('loyaltyAppliedBox').style.display = apply ? 'block' : 'none';
    document.getElementById('loyaltyRemovedBox').style.display = apply ? 'none' : 'block';
    document.getElementById('removeLoyaltyBtn').style.display = apply ? 'flex' : 'none';
    document.getElementById('applyLoyaltyBtn').style.display = apply ? 'none' : 'flex';
    calculateTotal();
}

function togglePaymentSections() {
    const paymentRadios = document.querySelectorAll('input[name="payment_method"]');
    const gcashSection = document.getElementById('gcashUploadSection');
    const cardSection = document.getElementById('cardPaymentSection');

    paymentRadios.forEach(radio => {
        const parentLabel = radio.closest('.smiski-subcard');
        
        if (radio.checked) {
            parentLabel.classList.add('active-payment');
            const val = radio.value.toLowerCase();

            if (val.includes('gcash') || val.includes('wallet')) {
                gcashSection.style.display = 'block';
                cardSection.style.display = 'none';
            } else if (val.includes('card') || val.includes('credit')) {
                gcashSection.style.display = 'none';
                cardSection.style.display = 'block';
            } else {
                gcashSection.style.display = 'none';
                cardSection.style.display = 'none';
            }
        } else {
            parentLabel.classList.remove('active-payment');
        }
    });
}

document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
    radio.addEventListener('change', togglePaymentSections);
});

const cardNumInput = document.getElementById('card_number_input');
if (cardNumInput) {
    cardNumInput.addEventListener('input', function (e) {
        let val = e.target.value.replace(/\D/g, '');
        val = val.substring(0, 16);
        e.target.value = val.replace(/(.{4})/g, '$1 ').trim();
    });
}

const cardExpInput = document.getElementById('card_expiry_input');
if (cardExpInput) {
    cardExpInput.addEventListener('input', function (e) {
        let val = e.target.value.replace(/\D/g, '');
        if (val.length >= 2) {
            e.target.value = val.substring(0, 2) + '/' + val.substring(2, 4);
        } else {
            e.target.value = val;
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    togglePaymentSections();
    calculateTotal();
});
</script>

<?php 
if (file_exists('includes/footer.php')) {
    include 'includes/footer.php';
}
?>
</body>
</html>