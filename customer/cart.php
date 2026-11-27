<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

syncCustomerCart();

$allowedActions = ['add', 'buy_now', 'reduce', 'remove'];
$rawAction = $_GET['action'] ?? '';
$action = is_string($rawAction) ? $rawAction : '';
$rawId = $_GET['id'] ?? '';
$id = (is_string($rawId) && ctype_digit($rawId)) ? (int)$rawId : 0;
$isAjax = isAjaxRequest();

if ($action !== '') {
    if (!in_array($action, $allowedActions, true) || $id < 1) {
        abortRequest(400, MSG_INVALID_OPERATION, 'cart.php');
    }
    if (isAdminLoggedIn()) {
        abortRequest(403, MSG_UNAUTHORIZED . ' Shopping is disabled in Admin mode.', 'index.php');
    }
    requireCsrfFromQuery('cart.php');
}

foreach ($_SESSION['cart'] as $cartKey => $cartQty) {
    if (!is_int($cartKey) && !ctype_digit((string)$cartKey) || (int)$cartQty < 1) {
        unset($_SESSION['cart'][$cartKey]);
    } else {
        $_SESSION['cart'][$cartKey] = (int)$cartQty;
    }
}

$cartError = $_SESSION['cart_error'] ?? '';
unset($_SESSION['cart_error']);

function cartAddCheck($productId) {
    $stmt = getDB()->prepare("SELECT name, stock_quantity FROM products WHERE id = ? AND status = 'active'");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        return [404, 'Sorry, this product could not be found or is no longer available.'];
    }
    $stock = (int)$product['stock_quantity'];
    $inCart = (int)($_SESSION['cart'][$productId] ?? 0);
    if ($stock <= 0) {
        return [409, '"' . $product['name'] . '" is currently out of stock.'];
    }
    if ($inCart + 1 > $stock) {
        return [409, msgInsufficientStock($stock) . ' You already have ' . $inCart . ' in your cart.'];
    }
    return [200, ''];
}

if ($action === 'add') {
    [$status, $addError] = cartAddCheck($id);
    if ($status === 200) {
        $_SESSION['cart'][$id] = ($_SESSION['cart'][$id] ?? 0) + 1;
        persistCart();
    }
    $totalCount = array_sum($_SESSION['cart']);

    if ($isAjax) {
        jsonResponse($status, [
            'success'   => $status === 200,
            'cartCount' => $totalCount,
            'message'   => $status === 200 ? 'Item added to cart!' : $addError,
        ]);
    }

    if ($status !== 200) {
        $_SESSION['cart_error'] = $addError;
    }
    header("Location: " . safeRedirectTarget('cart.php'), true, 303);
    exit;
}

if ($action === 'buy_now') {
    [$status, $addError] = cartAddCheck($id);
    if ($status === 200) {
        $_SESSION['cart'][$id] = ($_SESSION['cart'][$id] ?? 0) + 1;
        persistCart();
    } elseif (empty($_SESSION['cart'][$id])) {
        $_SESSION['cart_error'] = $addError;
        header("Location: cart.php", true, 303);
        exit;
    }
    if (function_exists('isCustomerLoggedIn') && !isCustomerLoggedIn()) {
        $_SESSION['redirect_after_login'] = 'checkout.php';
        $_SESSION['login_prompt'] = 'Please log in or sign up to complete your checkout.';
        header("Location: login.php", true, 303);
    } else {
        header("Location: checkout.php", true, 303);
    }
    exit;
}

if ($action === 'reduce') {
    if (isset($_SESSION['cart'][$id])) {
        $_SESSION['cart'][$id]--;
        if ($_SESSION['cart'][$id] <= 0) {
            unset($_SESSION['cart'][$id]);
        }
        persistCart();
    } else {
        $_SESSION['cart_error'] = 'This item is no longer in your cart.';
    }
    header("Location: cart.php", true, 303);
    exit;
}

if ($action === 'remove') {
    if (isset($_SESSION['cart'][$id])) {
        unset($_SESSION['cart'][$id]);
        persistCart();
        setFlash('success', 'Item removed from your cart.');
    } else {
        $_SESSION['cart_error'] = 'This item is no longer in your cart.';
    }
    header("Location: cart.php", true, 303);
    exit;
}

$cartItems = [];
$subtotal = 0;
$cartHasIssues = false;

if (!empty($_SESSION['cart'])) {
    $db = getDB();
    $productIds = array_keys($_SESSION['cart']);
    $inClause = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $db->prepare("SELECT * FROM products WHERE id IN ($inClause)");
    $stmt->execute($productIds);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $foundIds = array_map('intval', array_column($products, 'id'));
    foreach ($productIds as $cartProductId) {
        if (!in_array((int)$cartProductId, $foundIds, true)) {
            unset($_SESSION['cart'][$cartProductId]);
            persistCart();
        }
    }

    foreach ($products as $p) {
        $qty = $_SESSION['cart'][$p['id']];
        $itemSubtotal = $p['price'] * $qty;
        $subtotal += $itemSubtotal;

        $issue = '';
        if (($p['status'] ?? 'active') !== 'active') {
            $issue = 'This product is no longer available. Please remove it from your cart.';
        } elseif ($qty > (int)$p['stock_quantity']) {
            $issue = msgInsufficientStock($p['stock_quantity']) . ' Please reduce the quantity.';
        }
        if ($issue !== '') {
            $cartHasIssues = true;
        }

        $cartItems[] = [
            'id' => $p['id'],
            'name' => $p['name'],
            'price' => $p['price'],
            'stock' => $p['stock_quantity'] ?? 0,
            'quantity' => $qty,
            'subtotal' => $itemSubtotal,
            'issue' => $issue
        ];
    }
}

if (file_exists('includes/header.php')) {
    include 'includes/header.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Your Shopping Cart</title>
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
    </style>
</head>
<body>

<div class="container" style="max-width: 1200px; margin: 0 auto; padding-top: 1.5rem; padding-bottom: 3rem; padding-left: 1rem; padding-right: 1rem;">

    <div class="smiski-card" style="padding: 2rem 2.5rem; margin-bottom: 2rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1.5rem;">
            <div>
                <span style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); color: #064e3b; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.8rem; border-radius: 20px; display: inline-block; margin-bottom: 0.75rem; border: 1px solid rgba(74,222,128,0.6); box-shadow: 0 0 10px rgba(74,222,128,0.4);">
                    <i class="fa-solid fa-cart-shopping"></i> CHECKOUT READY
                </span>
                <h1 class="neon-glow-text" style="font-size: 2.25rem; font-weight: 700; margin-bottom: 0.4rem;">Your Shopping Cart</h1>
                <p class="text-muted-green" style="font-size: 1rem; line-height: 1.5;">Review your selected Smiski figures and proceed to checkout whenever you're ready.</p>
            </div>
            <div>
                <a href="home.php" class="btn-green-secondary" style="text-decoration: none;">
                    <i class="fa-solid fa-arrow-left"></i> Continue Shopping
                </a>
            </div>
        </div>
    </div>

    <?php if (!empty($cartError)): ?>
        <div class="smiski-card" style="background: rgba(254, 226, 226, 0.95) !important; border: 1px solid rgba(248, 113, 113, 0.8) !important; color: #991b1b; padding: 1rem 1.5rem; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 0.75rem;">
            <i class="fa-solid fa-circle-exclamation" style="font-size: 1.2rem; color: #dc2626;"></i>
            <span><?= htmlspecialchars($cartError) ?></span>
        </div>
    <?php endif; ?>

    <?php if (empty($cartItems)): ?>
        <div class="smiski-card" style="padding: 4rem 2rem; text-align: center;">
            <i class="fa-solid fa-cart-flatbed" style="font-size: 4rem; color: #166534; opacity: 0.8; margin-bottom: 1.25rem; filter: drop-shadow(0 0 10px rgba(74,222,128,0.5));"></i>
            <h3 class="neon-glow-text" style="font-weight: 700; font-size: 1.5rem;">Your Cart is Empty</h3>
            <p class="text-muted-green" style="font-size: 1rem; margin-top: 0.5rem; margin-bottom: 1.75rem;">Looks like you haven't added any products to your cart yet.</p>
            <a href="home.php" class="btn-green-primary" style="text-decoration: none; padding: 0.85rem 1.75rem; border-radius: 14px; font-weight: 600;">
                <i class="fa-solid fa-store"></i> Browse Products
            </a>
        </div>
    <?php else: ?>
        <div style="display: flex; gap: 2rem; flex-wrap: wrap; align-items: flex-start;">

            <div class="smiski-card" style="flex: 2; min-width: 340px; padding: 1.75rem 2rem;">
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; min-width: 500px;">
                        <thead>
                            <tr style="border-bottom: 2px solid rgba(74, 222, 128, 0.4); text-align: left;">
                                <th style="padding: 0.75rem 0.5rem; font-size: 0.85rem; color: #166534; font-weight: 700;">Product</th>
                                <th style="padding: 0.75rem 0.5rem; font-size: 0.85rem; color: #166534; font-weight: 700;">Price</th>
                                <th style="padding: 0.75rem 0.5rem; font-size: 0.85rem; color: #166534; font-weight: 700; text-align: center;">Quantity</th>
                                <th style="padding: 0.75rem 0.5rem; font-size: 0.85rem; color: #166534; font-weight: 700; text-align: right;">Subtotal</th>
                                <th style="padding: 0.75rem 0.5rem; font-size: 0.85rem; color: #166534; font-weight: 700; text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cartItems as $item): ?>
                                <tr style="border-bottom: 1px solid rgba(74, 222, 128, 0.2); transition: background 0.2s;">
                                    <td style="padding: 1rem 0.5rem;">
                                        <div>
                                            <div style="font-weight: 700; color: #064e3b; font-size: 1rem; line-height: 1.3;"><?= htmlspecialchars($item['name']) ?></div>
                                            <div style="font-size: 0.75rem; color: #166534; margin-top: 0.2rem; font-weight: 600;">In Stock: <?= $item['stock'] ?></div>
                                            <?php if (!empty($item['issue'])): ?>
                                                <div class="field-error"><i class="fa-solid fa-circle-exclamation"></i> <span><?= htmlspecialchars($item['issue']) ?></span></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td style="padding: 1rem 0.5rem; color: #1f2937; font-weight: 600;">
                                        ₱<?= number_format($item['price'], 2) ?>
                                    </td>
                                    <td style="padding: 1rem 0.5rem; text-align: center;">
                                        <div class="smiski-subcard" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.3rem 0.5rem;">
                                            <a href="cart.php?action=reduce&id=<?= (int)$item['id'] ?>&<?= csrfQuery() ?>" title="Decrease quantity" style="color: #15803d; text-decoration: none; font-weight: 700; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; background: rgba(255, 255, 255, 0.8); border-radius: 8px; border: 1px solid rgba(74,222,128,0.5); transition: background 0.2s;">-</a>
                                            <span style="font-weight: 700; min-width: 24px; text-align: center; color: #064e3b; font-size: 0.95rem;"><?= $item['quantity'] ?></span>
                                            <a href="cart.php?action=add&id=<?= (int)$item['id'] ?>&<?= csrfQuery() ?>" title="Increase quantity" style="color: #15803d; text-decoration: none; font-weight: 700; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; background: rgba(255, 255, 255, 0.8); border-radius: 8px; border: 1px solid rgba(74,222,128,0.5); transition: background 0.2s;">+</a>
                                        </div>
                                    </td>
                                    <td style="padding: 1rem 0.5rem; font-weight: 700; color: #15803d; text-align: right;">
                                        ₱<?= number_format($item['subtotal'], 2) ?>
                                    </td>
                                    <td style="padding: 1rem 0.5rem; text-align: center;">
                                        <a href="cart.php?action=remove&id=<?= (int)$item['id'] ?>&<?= csrfQuery() ?>" onclick="return confirm('Remove this item from your cart?')" style="color: #dc2626; font-size: 1.1rem; padding: 0.5rem; display: inline-block; transition: transform 0.2s;" title="Remove item">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="smiski-card" style="flex: 1; min-width: 280px; padding: 1.75rem 2rem;">
                <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem; border-bottom: 1px solid rgba(74, 222, 128, 0.4); padding-bottom: 0.75rem;">Order Summary</h3>

                <div style="display: flex; justify-content: space-between; font-size: 1.05rem; font-weight: 600; margin-bottom: 1.25rem; color: #1f2937;">
                    <span>Subtotal</span>
                    <span class="neon-glow-text" style="font-size: 1.35rem; font-weight: 700;">₱<?= number_format($subtotal, 2) ?></span>
                </div>

                <p class="text-muted-green" style="font-size: 0.85rem; margin-bottom: 1.75rem; line-height: 1.5;">
                    Taxes, your Loyal Customer Discount (if eligible), and shipping fees will be finalized during checkout.
                </p>

                <?php if (function_exists('isAdminLoggedIn') && isAdminLoggedIn()): ?>
                    <button disabled class="btn-green-secondary" style="width: 100%; cursor: not-allowed; opacity: 0.6; justify-content: center;" title="Checkout disabled in Admin mode">
                        <i class="fa-solid fa-ban"></i> Checkout Disabled for Admin
                    </button>
                <?php elseif ($cartHasIssues): ?>
                    <button disabled class="btn-green-secondary" style="width: 100%; cursor: not-allowed; opacity: 0.6; justify-content: center;" title="Fix the highlighted items first">
                        <i class="fa-solid fa-triangle-exclamation"></i> Update Your Cart to Continue
                    </button>
                    <div class="field-error" style="justify-content: center; margin-top: 0.75rem;"><i class="fa-solid fa-circle-exclamation"></i> <span>Some items exceed available stock or are no longer available. Please adjust them before checking out.</span></div>
                <?php else: ?>
                    <a href="checkout.php" class="btn-green-primary" style="width: 100%; justify-content: center; text-decoration: none;">
                        <i class="fa-solid fa-circle-check"></i> Proceed to Check Out
                    </a>
                    <?php if (function_exists('isCustomerLoggedIn') && !isCustomerLoggedIn()): ?>
                        <div class="smiski-subcard" style="margin-top: 1rem; padding: 0.85rem; font-size: 0.8rem; color: #166534; text-align: center; line-height: 1.4;">
                            <i class="fa-solid fa-circle-info" style="color: #15803d;"></i> Note: You will be prompted to Log In or Sign Up during checkout to secure your order.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div>
    <?php endif; ?>
</div>

<?php 
if (file_exists('includes/footer.php')) {
    include 'includes/footer.php';
}
?>
</body>
</html>