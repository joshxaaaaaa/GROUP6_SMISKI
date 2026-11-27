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
}

$shippingCost = 50.00;
$taxRate = 12.0; 

$settingsStmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $settingsStmt->fetch()) {
    if ($row['setting_key'] === 'shipping_cost') $shippingCost = floatval($row['setting_value']);
    if ($row['setting_key'] === 'tax_rate') $taxRate = floatval($row['setting_value']);
}

$productIds = array_keys($_SESSION['cart']);
$inClause = implode(',', array_fill(0, count($productIds), '?'));
$stmt = $db->prepare("SELECT * FROM products WHERE id IN ($inClause)");
$stmt->execute($productIds);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cartItems = [];
$subtotal = 0;
$cartProblems = [];

foreach ($products as $p) {
    $qty = (int)$_SESSION['cart'][$p['id']];
    if ($qty < 1) {
        continue;
    }
    $itemSubtotal = round((float)$p['price'] * $qty, 2);
    $subtotal += $itemSubtotal;

    if (($p['status'] ?? 'active') !== 'active') {
        $cartProblems[] = '"' . $p['name'] . '" is no longer available.';
    } elseif ($qty > (int)$p['stock_quantity']) {
        $cartProblems[] = '"' . $p['name'] . '": ' . msgInsufficientStock($p['stock_quantity']);
    }

    $cartItems[] = [
        'id' => $p['id'],
        'name' => $p['name'],
        'price' => $p['price'],
        'quantity' => $qty,
        'subtotal' => $itemSubtotal
    ];
}
$subtotal = round($subtotal, 2);

if (empty($cartItems)) {
    setFlash('error', 'Your cart is empty or its items are no longer available.');
    header("Location: cart.php", true, 303);
    exit;
}
if ($cartProblems) {
    $_SESSION['cart_error'] = 'Please update your cart before checking out. ' . implode(' ', $cartProblems);
    header("Location: cart.php", true, 303);
    exit;
}

$accountStmt = $db->prepare("SELECT id, status FROM users WHERE id = ? AND role = 'customer'");
$accountStmt->execute([(int)$_SESSION['user_id']]);
$account = $accountStmt->fetch();
if (!$account || $account['status'] !== 'active') {
    $_SESSION = [];
    session_regenerate_id(true);
    setFlash('error', 'Your account is not available. Please log in again or contact support.');
    header("Location: login.php", true, 303);
    exit;
}

$checkoutDiscounts = getCheckoutDiscounts((int)$account['id'], $subtotal);

$error = '';
$errors = [];
$old = [];

$allowedPaymentMethods = ['Cash on Delivery (COD)', 'E-Wallet / GCash', 'Credit / Debit Card'];

function isValidCardNumber($digits) {
    if (!preg_match('/^\d{13,19}$/', $digits)) {
        return false;
    }
    $sum = 0;
    $double = false;
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        $n = (int)$digits[$i];
        if ($double) {
            $n *= 2;
            if ($n > 9) $n -= 9;
        }
        $sum += $n;
        $double = !$double;
    }
    return $sum % 10 === 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = new Validator($_POST);
    $customerName = $v->personName('customer_name');
    $phone = $v->phone('phone');
    $address = $v->text('delivery_address', ['required' => true, 'min' => 5, 'max' => 500, 'multiline' => true,
        'minMessage' => 'Please enter your complete delivery address (at least 5 characters).']);
    $paymentChoice = $v->choice('payment_method', $allowedPaymentMethods, ['requiredMessage' => 'Please choose a payment method.']);
    $rawDiscount = $_POST['discount_id'] ?? null;
    $discountChoice = null;
    if ($rawDiscount !== null) {
        if (!is_string($rawDiscount) || ($rawDiscount !== 'none' && !ctype_digit($rawDiscount))) {
            $v->addError('discount_id', MSG_INVALID_OPERATION);
        } else {
            $discountChoice = $rawDiscount === 'none' ? 'none' : (int)$rawDiscount;
        }
    }
    $old = ['customer_name' => $customerName, 'phone' => $phone, 'delivery_address' => $address, 'payment_method' => $paymentChoice, 'discount_id' => is_string($rawDiscount) ? $rawDiscount : null];

    $selectedDiscount = null;
    if ($discountChoice === null && !$v->hasError('discount_id')) {
        $selectedDiscount = $checkoutDiscounts['available'][0] ?? null;
    } elseif (is_int($discountChoice)) {
        foreach ($checkoutDiscounts['available'] as $candidate) {
            if ((int)$candidate['id'] === $discountChoice) {
                $selectedDiscount = $candidate;
                break;
            }
        }
        if ($selectedDiscount === null) {
            $chosen = fetchDiscountWithStatus($discountChoice);
            $v->addError('discount_id', $chosen ? (discountEligibilityError($chosen, $checkoutDiscounts['completed'], $subtotal) ?: MSG_DISCOUNT_UNAVAILABLE) : MSG_DISCOUNT_UNAVAILABLE);
        }
    }

    $paymentMethod = (string)$paymentChoice;
    $paymentReceipt = null;
    $paymentStatus = 'unpaid';
    $isGcash = $paymentChoice === 'E-Wallet / GCash';
    $isCard = $paymentChoice === 'Credit / Debit Card';
    $receiptCheck = null;

    if ($isGcash) {
        $paymentStatus = 'pending_verification';
        $receiptCheck = checkImageUpload('payment_receipt', allowedImageTypes(false), UPLOAD_MAX_BYTES, true);
        if ($receiptCheck['error'] !== null) {
            $v->addError('payment_receipt', $receiptCheck['error'] === MSG_REQUIRED
                ? 'Please upload a screenshot of your GCash payment receipt.'
                : $receiptCheck['error']);
        }
    } elseif ($isCard) {
        $cardName = $v->personName('card_name', ['patternMessage' => 'Please enter the cardholder name as shown on the card.']);
        $cardNumber = preg_replace('/[\s-]+/', '', $v->text('card_number', ['required' => true, 'max' => 23]));
        if (!$v->hasError('card_number') && !isValidCardNumber($cardNumber)) {
            $v->addError('card_number', 'Please enter a valid card number (13 to 19 digits).');
        }
        $cardExpiry = $v->text('card_expiry', ['required' => true, 'max' => 5]);
        if (!$v->hasError('card_expiry')) {
            if (!preg_match('/^(0[1-9]|1[0-2])\/(\d{2})$/', $cardExpiry, $m)) {
                $v->addError('card_expiry', 'Please enter a valid expiry date in MM/YY format.');
            } elseif (mktime(0, 0, 0, (int)$m[1] + 1, 1, 2000 + (int)$m[2]) <= time()) {
                $v->addError('card_expiry', 'This card has expired. Please use a different card.');
            }
        }
        $cardCvv = $v->text('card_cvv', ['required' => true, 'max' => 4]);
        if (!$v->hasError('card_cvv') && !preg_match('/^\d{3,4}$/', $cardCvv)) {
            $v->addError('card_cvv', 'Please enter the 3- or 4-digit security code (CVV).');
        }
        $old['card_name'] = $cardName;
        if (!$v->fails()) {
            $paymentStatus = 'paid';
            $paymentMethod = 'Card (ending in ' . substr($cardNumber, -4) . ')';
        }
    }

    $errors = $v->errors();

    if ($errors) {
        $error = MSG_FORM_ERRORS;
        http_response_code(422);
    } elseif (!consumeFormToken('checkout', $_POST['form_token'] ?? '')) {
        if (!empty($_SESSION['latest_order'])) {
            header("Location: success.php", true, 303);
            exit;
        }
        setFlash('error', MSG_DUPLICATE . ' Please check Track Orders before trying again.');
        header("Location: orders.php", true, 303);
        exit;
    }

    if (empty($errors)) {
        $discountAmount = 0;
        $discountType = 'None';
        $discountId = null;

        try {
            if ($isGcash) {
                $paymentReceipt = storeCheckedUpload($receiptCheck, 'receipts', 'gcash');
            }

            $db->beginTransaction();
            $customerId = (int)$account['id'];

            if ($selectedDiscount !== null) {
                $lockedDiscount = fetchDiscountWithStatus($selectedDiscount['id'], true);
                $discountError = $lockedDiscount ? discountEligibilityError($lockedDiscount, countCompletedOrders($customerId), $subtotal) : MSG_DISCOUNT_UNAVAILABLE;
                if ($discountError !== '') {
                    throw new UserFacingException($discountError . ' Please review your discount and try again.', 409);
                }
                $discountAmount = calculateDiscountAmount($lockedDiscount, $subtotal);
                $discountType = discountOrderLabel($lockedDiscount);
                $discountId = (int)$lockedDiscount['id'];
            }
            $discountedSubtotal = $subtotal - $discountAmount;
            $taxAmount = round($discountedSubtotal * ($taxRate / 100), 2);
            $totalAmount = round($discountedSubtotal + $taxAmount + $shippingCost, 2);

            $orderColumns = "customer_id, customer_name, phone, delivery_address, payment_method, payment_receipt, payment_status, subtotal, discount_type, discount_amount, shipping_cost, tax_amount, total_amount, status";
            $orderValues = [
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
                $totalAmount,
                'pending'
            ];
            if (ordersHaveDiscountColumn()) {
                $orderColumns .= ", discount_id";
                $orderValues[] = $discountId;
            }
            $orderStmt = $db->prepare("INSERT INTO orders ($orderColumns) VALUES (" . implode(', ', array_fill(0, count($orderValues), '?')) . ")");
            $orderStmt->execute($orderValues);

            $orderId = $db->lastInsertId();

            $noStmt = $db->prepare("SELECT order_number FROM orders WHERE id = ?");
            $noStmt->execute([$orderId]);
            $orderNum = $noStmt->fetchColumn();

            $itemStmt = $db->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
            $stockStmt = $db->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND status = 'active' AND stock_quantity >= ?");
            $stockLeftStmt = $db->prepare("SELECT stock_quantity FROM products WHERE id = ?");

            foreach ($cartItems as $item) {
                $stockStmt->execute([$item['quantity'], $item['id'], $item['quantity']]);
                if ($stockStmt->rowCount() === 0) {
                    $stockLeftStmt->execute([$item['id']]);
                    $left = max(0, (int)$stockLeftStmt->fetchColumn());
                    throw new UserFacingException('"' . $item['name'] . '": ' . msgInsufficientStock($left) . ' Please update the quantity in your cart.', 409);
                }
                $itemStmt->execute([$orderId, $item['id'], $item['name'], $item['quantity'], $item['price'], $item['subtotal']]);
            }

            $db->commit();

            if (function_exists('logActivity')) {
                $discountNote = $discountId !== null ? " with " . $discountType . " (-₱" . number_format($discountAmount, 2) . ")" : "";
                logActivity($_SESSION['user_id'], 'ORDER_PLACED', "Order $orderNum placed for total amount ₱" . number_format($totalAmount, 2) . $discountNote);
            }

            $_SESSION['cart'] = [];
            persistCart();
            $_SESSION['latest_order'] = [
                'order_number' => $orderNum,
                'total_amount' => $totalAmount,
                'payment_method' => $paymentMethod
            ];

            header("Location: success.php", true, 303);
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            deleteStoredUpload($paymentReceipt);
            $error = 'Your order was not placed. ' . safeErrorMessage($e, 'Order placement failed');
            http_response_code($e instanceof UserFacingException ? $e->getHttpStatus() : 500);
        }
    }
}

$selectedPayment = in_array($old['payment_method'] ?? '', $allowedPaymentMethods, true) ? $old['payment_method'] : 'Cash on Delivery (COD)';

$checkoutToken = generateFormToken('checkout');

$checkoutDiscounts = getCheckoutDiscounts((int)$account['id'], $subtotal);
$availableDiscounts = $checkoutDiscounts['available'];
$defaultDiscountId = $availableDiscounts ? (string)$availableDiscounts[0]['id'] : 'none';
$selectedDiscountId = isset($old['discount_id']) && is_string($old['discount_id']) ? $old['discount_id'] : $defaultDiscountId;
$previewDiscount = 0;
$previewLabel = '';
$previewName = '';
$selectionFound = $selectedDiscountId === 'none';
foreach ($availableDiscounts as $d) {
    if ((string)$d['id'] === $selectedDiscountId) {
        $previewDiscount = $d['amount'];
        $previewLabel = $d['label'];
        $previewName = $d['name'];
        $selectionFound = true;
    }
}
if (!$selectionFound) {
    $selectedDiscountId = $defaultDiscountId;
    if ($availableDiscounts) {
        $previewDiscount = $availableDiscounts[0]['amount'];
        $previewLabel = $availableDiscounts[0]['label'];
        $previewName = $availableDiscounts[0]['name'];
    }
}
$loyalDiscountAvailable = false;
foreach ($availableDiscounts as $d) {
    if ((int)$d['min_completed_orders'] > 0) {
        $loyalDiscountAvailable = true;
    }
}
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

        .neon-glow-text {
            color: #064e3b !important;
            text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
        }

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
        <?= csrfField() ?>
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($checkoutToken) ?>">
        <div style="display: flex; gap: 2rem; flex-wrap: wrap; align-items: flex-start;">

            <div style="flex: 2; min-width: 320px; display: flex; flex-direction: column; gap: 1.75rem;">

                <div class="smiski-card" style="padding: 1.75rem 2rem;">
                    <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.6rem; border-bottom: 1px solid rgba(74, 222, 128, 0.3); padding-bottom: 0.75rem;">
                        <i class="fa-solid fa-user-gear" style="color: #15803d;"></i> Customer & Delivery Details
                    </h3>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
                        <div>
                            <label class="text-muted-green" style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem;">Full Name</label>
                            <input type="text" name="customer_name" required minlength="2" maxlength="100" autocomplete="name" value="<?= oldValue($old, 'customer_name', $_SESSION['user_name'] ?? '') ?>" class="smiski-input<?= invalidClass($errors, 'customer_name') ?>"<?= invalidAttr($errors, 'customer_name') ?>>
                            <?= fieldError($errors, 'customer_name') ?>
                        </div>
                        <div>
                            <label class="text-muted-green" style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem;">Phone Number</label>
                            <input type="tel" name="phone" required data-phone-input inputmode="numeric" pattern="[0-9]{7,15}" maxlength="15" placeholder="09123456789" title="Numbers only (7 to 15 digits)" value="<?= oldValue($old, 'phone', $_SESSION['user_phone'] ?? '') ?>" autocomplete="tel" class="smiski-input<?= invalidClass($errors, 'phone') ?>"<?= invalidAttr($errors, 'phone') ?>>
                            <?= fieldError($errors, 'phone') ?>
                        </div>
                    </div>

                    <div>
                        <label class="text-muted-green" style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem;">Delivery Address</label>
                        <textarea name="delivery_address" rows="3" required minlength="5" maxlength="500" data-counter class="smiski-input<?= invalidClass($errors, 'delivery_address') ?>"<?= invalidAttr($errors, 'delivery_address') ?> style="font-family:inherit; resize: vertical;"><?= oldValue($old, 'delivery_address', $_SESSION['user_address'] ?? '') ?></textarea>
                        <?= fieldError($errors, 'delivery_address') ?>
                    </div>
                </div>

                <div class="smiski-card" style="padding: 1.75rem 2rem;">
                    <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.6rem; border-bottom: 1px solid rgba(74, 222, 128, 0.3); padding-bottom: 0.75rem;">
                        <i class="fa-solid fa-wallet" style="color: #15803d;"></i> Payment Method
                    </h3>

                    <div style="display: flex; flex-direction: column; gap: 0.85rem;">
                        <label class="smiski-subcard active-payment" style="display: flex; align-items: center; gap: 0.85rem; padding: 1rem 1.25rem; cursor: pointer;">
                            <input type="radio" name="payment_method" value="Cash on Delivery (COD)" required <?= $selectedPayment === 'Cash on Delivery (COD)' ? 'checked' : '' ?> style="accent-color: #15803d; width:18px; height:18px;">
                            <div>
                                <div style="font-weight: 700; font-size: 0.95rem; color: #064e3b;">Cash on Delivery (COD)</div>
                                <div class="text-muted-green" style="font-size: 0.8rem;">Pay in cash upon doorstep delivery</div>
                            </div>
                        </label>

                        <label class="smiski-subcard" style="display: flex; align-items: center; gap: 0.85rem; padding: 1rem 1.25rem; cursor: pointer;">
                            <input type="radio" name="payment_method" value="E-Wallet / GCash" <?= $selectedPayment === 'E-Wallet / GCash' ? 'checked' : '' ?> style="accent-color: #15803d; width:18px; height:18px;">
                            <div>
                                <div style="font-weight: 700; font-size: 0.95rem; color: #064e3b;">E-Wallet / GCash / Maya</div>
                                <div class="text-muted-green" style="font-size: 0.8rem;">Pay via digital wallet & upload receipt screenshot</div>
                            </div>
                        </label>

                        <label class="smiski-subcard" style="display: flex; align-items: center; gap: 0.85rem; padding: 1rem 1.25rem; cursor: pointer;">
                            <input type="radio" name="payment_method" value="Credit / Debit Card" <?= $selectedPayment === 'Credit / Debit Card' ? 'checked' : '' ?> style="accent-color: #15803d; width:18px; height:18px;">
                            <div>
                                <div style="font-weight: 700; font-size: 0.95rem; color: #064e3b;">Credit / Debit Card</div>
                                <div class="text-muted-green" style="font-size: 0.8rem;">Pay securely with Visa or Mastercard</div>
                            </div>
                        </label>
                    </div>

                    <?= fieldError($errors, 'payment_method') ?>

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
                        <input type="file" name="payment_receipt" id="payment_receipt_input" data-required-when-shown accept="image/jpeg,image/png,image/webp,image/heic,image/heif,image/avif,image/bmp,image/tiff,.heic,.heif" data-max-size="5242880" class="smiski-input<?= invalidClass($errors, 'payment_receipt') ?>"<?= invalidAttr($errors, 'payment_receipt') ?> style="padding: 0.5rem;">
                        <?= fieldError($errors, 'payment_receipt') ?>
                        <small class="field-hint">Accepted: JPG, PNG, WebP, HEIC, AVIF, BMP, TIFF. Maximum 5 MB.</small>
                    </div>

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
                                <input type="text" name="card_name" data-required-when-shown minlength="2" maxlength="100" autocomplete="cc-name" placeholder="e.g. Juan Dela Cruz" value="<?= oldValue($old, 'card_name') ?>" class="smiski-input<?= invalidClass($errors, 'card_name') ?>"<?= invalidAttr($errors, 'card_name') ?>>
                                <?= fieldError($errors, 'card_name') ?>
                            </div>
                            <div>
                                <label class="text-muted-green" style="display: block; font-size: 0.82rem; font-weight: 600; margin-bottom: 0.3rem;">Card Number</label>
                                <input type="text" name="card_number" id="card_number_input" data-required-when-shown inputmode="numeric" autocomplete="cc-number" maxlength="19" pattern="(?:[0-9] ?){12,18}[0-9]" data-msg-pattern="Please enter a valid card number (13 to 19 digits)." placeholder="4532 •••• •••• 8901" class="smiski-input<?= invalidClass($errors, 'card_number') ?>"<?= invalidAttr($errors, 'card_number') ?> style="letter-spacing:1px;">
                                <?= fieldError($errors, 'card_number') ?>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                                <div>
                                    <label class="text-muted-green" style="display: block; font-size: 0.82rem; font-weight: 600; margin-bottom: 0.3rem;">Expiry Date</label>
                                    <input type="text" name="card_expiry" id="card_expiry_input" data-required-when-shown inputmode="numeric" autocomplete="cc-exp" maxlength="5" pattern="(0[1-9]|1[0-2])/[0-9]{2}" data-msg-pattern="Please enter a valid expiry date in MM/YY format." placeholder="MM/YY" class="smiski-input<?= invalidClass($errors, 'card_expiry') ?>"<?= invalidAttr($errors, 'card_expiry') ?>>
                                    <?= fieldError($errors, 'card_expiry') ?>
                                </div>
                                <div>
                                    <label class="text-muted-green" style="display: block; font-size: 0.82rem; font-weight: 600; margin-bottom: 0.3rem;">CVV / CVC</label>
                                    <input type="password" name="card_cvv" data-required-when-shown inputmode="numeric" autocomplete="cc-csc" maxlength="4" pattern="[0-9]{3,4}" data-msg-pattern="Please enter the 3- or 4-digit security code (CVV)." placeholder="123" class="smiski-input<?= invalidClass($errors, 'card_cvv') ?>"<?= invalidAttr($errors, 'card_cvv') ?>>
                                    <?= fieldError($errors, 'card_cvv') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

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

            <div style="flex: 1; min-width: 280px; display: flex; flex-direction: column; gap: 1.75rem; height: fit-content;">

                <div class="smiski-card" style="padding: 1.75rem 2rem;">
                    <h4 class="neon-glow-text" style="font-size: 1.1rem; font-weight: 700; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-tags" style="color: #15803d;"></i> Discounts
                    </h4>

                    <?php if ($loyalDiscountAvailable): ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.85rem; flex-wrap: wrap;">
                            <span style="background: rgba(220, 252, 231, 0.95); color: #15803d; border: 1px solid rgba(74, 222, 128, 0.7); font-size: 0.8rem; font-weight: 700; padding: 0.3rem 0.75rem; border-radius: 999px; display: inline-flex; align-items: center; gap: 0.35rem;">
                                <i class="fa-solid fa-circle-check"></i> Eligible - Loyal Customer
                            </span>
                            <span class="text-muted-green" style="font-size: 0.8rem;"><?= (int)$checkoutDiscounts['completed'] ?> completed purchase<?= (int)$checkoutDiscounts['completed'] === 1 ? '' : 's' ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($availableDiscounts)): ?>
                        <div id="discountOptions" role="radiogroup" aria-label="Choose a discount" style="display: flex; flex-direction: column; gap: 0.6rem; margin-bottom: 0.85rem;">
                            <?php foreach ($availableDiscounts as $d): ?>
                                <label class="smiski-subcard" style="display: flex; gap: 0.75rem; align-items: flex-start; padding: 0.85rem 1rem; cursor: pointer;">
                                    <input type="radio" name="discount_id" value="<?= (int)$d['id'] ?>" data-amount="<?= htmlspecialchars(number_format($d['amount'], 2, '.', '')) ?>" data-label="<?= htmlspecialchars($d['label']) ?>" data-name="<?= htmlspecialchars($d['name']) ?>" <?= $selectedDiscountId === (string)$d['id'] ? 'checked' : '' ?> style="accent-color: #15803d; width: 18px; height: 18px; margin-top: 0.15rem; flex-shrink: 0;">
                                    <span style="display: block;">
                                        <span style="display: block; font-weight: 700; color: #064e3b; font-size: 0.92rem;"><?= htmlspecialchars($d['name']) ?> <span style="font-size: 0.75rem; font-weight: 700; color: #15803d; background: rgba(220, 252, 231, 0.95); border: 1px solid rgba(74, 222, 128, 0.6); padding: 0.1rem 0.5rem; border-radius: 999px;"><?= htmlspecialchars($d['value_label']) ?></span></span>
                                        <?php if (!empty($d['description'])): ?>
                                            <span class="text-muted-green" style="display: block; font-size: 0.8rem; line-height: 1.4; margin-top: 0.2rem;"><?= htmlspecialchars($d['description']) ?></span>
                                        <?php endif; ?>
                                        <span style="display: block; font-size: 0.82rem; font-weight: 700; color: #15803d; margin-top: 0.25rem;">You save ₱<?= number_format($d['amount'], 2) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                            <label class="smiski-subcard" style="display: flex; gap: 0.75rem; align-items: center; padding: 0.85rem 1rem; cursor: pointer;">
                                <input type="radio" name="discount_id" value="none" data-amount="0" data-label="" data-name="" <?= $selectedDiscountId === 'none' ? 'checked' : '' ?> style="accent-color: #15803d; width: 18px; height: 18px; flex-shrink: 0;">
                                <span style="font-weight: 700; color: #064e3b; font-size: 0.9rem;">Don't use a discount</span>
                            </label>
                        </div>
                        <?= fieldError($errors, 'discount_id') ?>

                        <div id="discountAppliedBox" class="smiski-subcard" style="padding: 0.9rem 1rem; margin-bottom: 0.85rem; background: rgba(240, 253, 244, 0.9) !important; <?= $previewDiscount > 0 ? '' : 'display: none;' ?>">
                            <div style="font-weight: 700; color: #064e3b; font-size: 0.9rem; margin-bottom: 0.25rem;">
                                <i class="fa-solid fa-tag" style="color: #15803d;"></i> <span id="discountAppliedName"><?= htmlspecialchars($previewName) ?></span> applied!
                            </div>
                            <div class="text-muted-green" style="font-size: 0.82rem; line-height: 1.5;">
                                <strong style="color: #15803d;">-₱<span id="discountAppliedAmount"><?= number_format($previewDiscount, 2) ?></span></strong> off your ₱<?= number_format($subtotal, 2) ?> subtotal. You save <strong style="color: #15803d;">₱<span id="discountAppliedSavings"><?= number_format($previewDiscount, 2) ?></span></strong> on this order.
                            </div>
                        </div>
                        <div id="discountRemovedBox" class="smiski-subcard" style="padding: 0.9rem 1rem; margin-bottom: 0.85rem; <?= $previewDiscount > 0 ? 'display: none;' : '' ?>">
                            <div style="font-weight: 700; color: #92400e; font-size: 0.9rem; margin-bottom: 0.25rem;">
                                <i class="fa-solid fa-circle-info"></i> Discount not applied to this order
                            </div>
                            <div class="text-muted-green" style="font-size: 0.82rem; line-height: 1.5;">
                                Your eligibility is not affected. You can choose a discount above or use it on future orders.
                            </div>
                        </div>

                        <p class="text-muted-green" style="font-size: 0.75rem; line-height: 1.4;">
                            The best available discount is applied automatically. Only one discount can be used per order.
                        </p>
                    <?php else: ?>
                        <p class="text-muted-green" style="font-size: 0.85rem; margin-bottom: 0.75rem; line-height: 1.5;">No discounts are available for this order.</p>
                        <?= fieldError($errors, 'discount_id') ?>
                    <?php endif; ?>

                    <?php foreach ($checkoutDiscounts['locked'] as $locked): ?>
                        <div style="border-top: 1px dashed rgba(74, 222, 128, 0.4); padding-top: 0.85rem; margin-top: 0.85rem;">
                            <div style="margin-bottom: 0.6rem;">
                                <span style="background: rgba(254, 243, 199, 0.95); color: #92400e; border: 1px solid rgba(251, 191, 36, 0.6); font-size: 0.8rem; font-weight: 700; padding: 0.3rem 0.75rem; border-radius: 999px; display: inline-flex; align-items: center; gap: 0.35rem;">
                                    <i class="fa-solid fa-hourglass-half"></i> Not yet eligible
                                </span>
                            </div>
                            <?php if (isset($locked['remaining_orders'])): ?>
                                <p class="text-muted-green" style="font-size: 0.85rem; margin-bottom: 0.6rem; line-height: 1.5;">
                                    You have <strong style="color: #064e3b;"><?= (int)$checkoutDiscounts['completed'] ?> of <?= (int)$locked['min_completed_orders'] ?></strong> completed purchases.
                                    Complete <strong style="color: #064e3b;"><?= (int)$locked['remaining_orders'] ?> more</strong> to unlock <?= htmlspecialchars($locked['name']) ?> (<?= htmlspecialchars($locked['value_label']) ?>).
                                </p>
                                <div style="height: 8px; background: rgba(74, 222, 128, 0.2); border-radius: 999px; overflow: hidden; margin-bottom: 0.5rem;">
                                    <div style="height: 100%; width: <?= min(100, round($checkoutDiscounts['completed'] / max(1, (int)$locked['min_completed_orders']) * 100)) ?>%; background: #15803d; border-radius: 999px;"></div>
                                </div>
                                <p class="text-muted-green" style="font-size: 0.75rem; line-height: 1.4;">A purchase counts once the order has been delivered.</p>
                            <?php else: ?>
                                <p class="text-muted-green" style="font-size: 0.85rem; line-height: 1.5;">
                                    Spend <strong style="color: #064e3b;">₱<?= number_format($locked['remaining_amount'], 2) ?> more</strong> to unlock <?= htmlspecialchars($locked['name']) ?> (<?= htmlspecialchars($locked['value_label']) ?>).
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="smiski-card" style="padding: 1.75rem 2rem;">
                    <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem; border-bottom: 1px solid rgba(74, 222, 128, 0.4); padding-bottom: 0.75rem;">Payment Summary</h3>

                    <div style="display: flex; flex-direction: column; gap: 0.85rem; font-size: 0.95rem; margin-bottom: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; color: #1f2937;">
                            <span>Product Subtotal</span>
                            <span style="font-weight: 600; color: #064e3b;">₱<?= number_format($subtotal, 2) ?></span>
                        </div>

                        <div id="discountRow" style="display: <?= $previewDiscount > 0 ? 'flex' : 'none' ?>; justify-content: space-between; color: #dc2626;">
                            <span id="discountLabel"><?= htmlspecialchars($previewLabel) ?></span>
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

function formatPeso(value) {
    return value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function selectedDiscount() {
    const radio = document.querySelector('input[name="discount_id"]:checked');
    if (!radio) return { amount: 0, label: '', name: '' };
    return { amount: parseFloat(radio.dataset.amount) || 0, label: radio.dataset.label || '', name: radio.dataset.name || '' };
}

function calculateTotal() {
    const selected = selectedDiscount();
    const discount = Math.min(selected.amount, baseSubtotal);
    const discountedSub = baseSubtotal - discount;
    const tax = discountedSub * (taxRate / 100);
    const grandTotal = discountedSub + tax + shippingCost;

    document.getElementById('displayDiscount').innerText = formatPeso(discount);
    document.getElementById('displayTax').innerText = formatPeso(tax);
    document.getElementById('displayGrandTotal').innerText = formatPeso(grandTotal);
    document.getElementById('displaySavings').innerText = formatPeso(discount);
    document.getElementById('discountLabel').innerText = selected.label;
    document.getElementById('discountRow').style.display = discount > 0 ? 'flex' : 'none';
    document.getElementById('savingsNote').style.display = discount > 0 ? 'block' : 'none';

    const applied = document.getElementById('discountAppliedBox');
    const removed = document.getElementById('discountRemovedBox');
    if (applied && removed) {
        applied.style.display = discount > 0 ? 'block' : 'none';
        removed.style.display = discount > 0 ? 'none' : 'block';
        document.getElementById('discountAppliedName').innerText = selected.name;
        document.getElementById('discountAppliedAmount').innerText = formatPeso(discount);
        document.getElementById('discountAppliedSavings').innerText = formatPeso(discount);
    }
}

document.querySelectorAll('input[name="discount_id"]').forEach(radio => {
    radio.addEventListener('change', calculateTotal);
});

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
            gcashSection.querySelectorAll('[data-required-when-shown]').forEach(el => { el.required = gcashSection.style.display === 'block'; });
            cardSection.querySelectorAll('[data-required-when-shown]').forEach(el => { el.required = cardSection.style.display === 'block'; });
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