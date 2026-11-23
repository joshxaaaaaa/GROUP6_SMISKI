<?php
require_once __DIR__ . '/../../config/db.php';

$cartCount = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $qty) {
        $cartCount += $qty;
    }
}

// Kunin ang pangalan ng kasalukuyang PHP file para sa active state
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Commerce Store</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #22c55e;          /* Vibrant Green */
            --primary-hover: #16a34a;    /* Darker Green */
            --secondary: #14532d;
            --dark: #052e16;
            --light: #14532d;            /* Match Admin Background */
            --gray-100: rgba(255, 255, 255, 0.1);
            --gray-200: rgba(187, 247, 208, 0.3);
            --gray-600: #dcfce7;
            --gray-800: #ffffff;
            --success: #22c55e;
            --danger: #f87171;
            --warning: #fbbf24;
            --radius: 12px;
            --shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
            color: var(--gray-800);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* iOS Glassmorphism Header Style */
        .navbar {
            background: rgba(20, 83, 45, 0.45);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            position: sticky;
            top: 0;
            z-index: 1000;
            padding: 0.9rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2);
        }

        .logo {
            font-size: 1.4rem;
            font-weight: 700;
            color: #ffffff;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .nav-link {
            text-decoration: none;
            color: rgba(255, 255, 255, 0.85);
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
        }

        .nav-link:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.1);
        }

        /* Active Glow-in-the-Dark Link State */
        .nav-link.active {
            color: #86efac !important;
            background: rgba(134, 239, 172, 0.15) !important;
            border: 1px solid rgba(134, 239, 172, 0.4);
            box-shadow: 0 0 12px rgba(134, 239, 172, 0.35);
            text-shadow: 0 0 8px rgba(134, 239, 172, 0.6);
            font-weight: 600;
        }

        .cart-btn-link {
            position: relative;
            background: rgba(34, 197, 94, 0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .cart-btn-link:hover {
            background: rgba(34, 197, 94, 1);
            transform: translateY(-1px);
        }

        /* Active state para sa Cart button kapag nasa cart.php */
        .cart-btn-link.active {
            background: rgba(34, 197, 94, 1) !important;
            border-color: #86efac !important;
            box-shadow: 0 0 20px rgba(134, 239, 172, 0.6) !important;
        }

        .cart-badge {
            background: rgba(0, 0, 0, 0.3);
            color: #ffffff;
            font-size: 0.75rem;
            padding: 0.12rem 0.45rem;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .user-badge {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            padding: 0.45rem 0.9rem;
            border-radius: 12px;
            font-size: 0.85rem;
            color: #ffffff;
            font-weight: 500;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.65rem 1.3rem;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            border: 1px solid #16a34a;
        }
        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            border: 1px solid rgba(187, 247, 208, 0.3);
        }
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .btn-outline {
            border: 1px solid rgba(187, 247, 208, 0.4);
            color: #ffffff;
            background: transparent;
        }
        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }
        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-block {
            width: 100%;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
            width: 100%;
            flex: 1;
        }

        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
            font-weight: 500;
        }
        .alert-success {
            background: rgba(20, 83, 45, 0.9);
            color: #86efac;
            border: 1px solid rgba(134, 239, 172, 0.4);
        }
        .alert-danger {
            background: rgba(127, 29, 29, 0.9);
            color: #fca5a5;
            border: 1px solid rgba(252, 165, 165, 0.4);
        }

        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(5, 23, 10, 0.7);
            backdrop-filter: blur(6px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        .modal-overlay.active {
            display: flex;
        }

        .modal-card {
            background: rgba(20, 83, 45, 0.95);
            backdrop-filter: blur(12px);
            border-radius: var(--radius);
            width: 100%;
            max-width: 500px;
            padding: 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(187, 247, 208, 0.3);
            position: relative;
        }

        .modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            font-size: 1.2rem;
            color: #dcfce7;
            cursor: pointer;
        }
    </style>
</head>
<body>

<header class="navbar">
    <a href="home.php" class="logo">
        <img src="../assets/ecommerce-logo.png" alt="E-Commerce Logo" style="height: 38px; width: 38px; object-fit: contain; vertical-align: middle;">
        <span>PROJECT SMISKI</span>
    </a>

    <div class="nav-links">
        <a href="home.php" class="nav-link <?= ($current_page == 'home.php') ? 'active' : '' ?>"><i class="fa-solid fa-house"></i> Home</a>
        <a href="index.php" class="nav-link <?= ($current_page == 'index.php') ? 'active' : '' ?>"><i class="fa-solid fa-store"></i> Products</a>
        <a href="about.php" class="nav-link <?= ($current_page == 'about.php') ? 'active' : '' ?>"><i class="fa-solid fa-circle-info"></i> About Us</a>
        <a href="orders.php" class="nav-link <?= ($current_page == 'orders.php') ? 'active' : '' ?>"><i class="fa-solid fa-receipt"></i> Track Orders</a>
        <a href="messages.php" class="nav-link <?= ($current_page == 'messages.php') ? 'active' : '' ?>"><i class="fa-solid fa-comments"></i> My Messages</a>
        
        <?php if (isAdminLoggedIn()): ?>
            <button type="button" class="nav-link" disabled style="opacity:0.5; cursor:not-allowed; background:none; border:none; font-size:1rem;" title="Contact form disabled for Admin Mode"><i class="fa-solid fa-envelope"></i> Contact Us</button>

            <a href="#" class="cart-btn-link" style="opacity:0.5; cursor:not-allowed; pointer-events:none;" title="Cart disabled for Admin Mode">
                <i class="fa-solid fa-cart-shopping"></i>
                <span>Cart</span>
                <span class="cart-badge">0</span>
            </a>
        <?php else: ?>
            <button type="button" class="nav-link" style="background:none;border:none;cursor:pointer;font-size:1rem; color:rgba(255,255,255,0.85);" onclick="openContactPane()"><i class="fa-solid fa-envelope"></i> Contact Us</button>

            <a href="cart.php" class="cart-btn-link <?= ($current_page == 'cart.php') ? 'active' : '' ?>">
                <i class="fa-solid fa-cart-shopping"></i>
                <span>Cart</span>
                <span class="cart-badge"><?= $cartCount ?></span>
            </a>
        <?php endif; ?>

        <?php if (isset($_SESSION['user_id'])): ?>
            <span class="user-badge" style="<?= isAdminLoggedIn() ? 'background: rgba(20, 83, 45, 0.9); color: #86efac; border-color: rgba(134, 239, 172, 0.4);' : '' ?>">
                <i class="fa-solid <?= isAdminLoggedIn() ? 'fa-user-shield' : 'fa-user' ?>" style="color: #86efac;"></i> 
                <?= htmlspecialchars($_SESSION['user_name']) ?>
            </span>

            <?php if (isAdminLoggedIn()): ?>
                <a href="../admin/index.php" class="btn btn-warning" style="padding:0.4rem 0.9rem; font-size:0.85rem; background: #d97706; color: white;">
                    <i class="fa-solid fa-gauge"></i> Admin Dashboard
                </a>
            <?php endif; ?>

            <a href="logout.php" class="btn btn-secondary" style="padding:0.4rem 0.9rem; font-size:0.85rem;"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        <?php else: ?>
            <a href="login.php" class="btn btn-outline <?= ($current_page == 'login.php') ? 'active' : '' ?>" style="padding:0.4rem 0.9rem; font-size:0.85rem;">Log In</a>
            <a href="register.php" class="btn btn-outline <?= ($current_page == 'register.php') ? 'active' : '' ?>" style="padding:0.4rem 0.9rem; font-size:0.85rem;">Sign Up</a>
        <?php endif; ?>
    </div>
</header>


<div id="cartToast" style="position: fixed; bottom: 2rem; right: 2rem; z-index: 3000; background: rgba(10, 40, 20, 0.95); backdrop-filter: blur(10px); color: white; padding: 0.85rem 1.3rem; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.4); border: 1px solid rgba(187, 247, 208, 0.3); display: flex; align-items: center; gap: 0.85rem; font-size: 0.95rem; font-weight: 500; transform: translateY(100px); opacity: 0; transition: all 0.35s cubic-bezier(0.68, -0.55, 0.265, 1.55); pointer-events: none;">
    <div style="width: 34px; height: 34px; background: var(--success); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1rem; color: white; flex-shrink: 0;">
        <i class="fa-solid fa-check"></i>
    </div>
    <div>
        <div id="cartToastTitle" style="font-weight: 700; color: white;">Added to Cart!</div>
        <div id="cartToastSub" style="font-size: 0.8rem; color: #dcfce7;">Item successfully added to your shopping cart</div>
    </div>
</div>

<script>
function addToCart(productId, productName) {
    fetch('cart.php?action=add&id=' + productId + '&ajax=1')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const badges = document.querySelectorAll('.cart-badge');
                badges.forEach(b => b.textContent = data.cartCount);
                showToast(productName);
            }
        })
        .catch(err => {
            console.error('Add to cart error:', err);
        });
}

function showToast(productName) {
    const toast = document.getElementById('cartToast');
    const title = document.getElementById('cartToastTitle');
    const sub = document.getElementById('cartToastSub');
    if (toast && title) {
        title.textContent = productName ? '"' + productName + '" added to cart!' : 'Item added to cart!';
        if (sub) sub.textContent = 'Shopping cart updated successfully';
        
        toast.style.transform = 'translateY(0)';
        toast.style.opacity = '1';
        
        clearTimeout(window.cartToastTimer);
        window.cartToastTimer = setTimeout(() => {
            toast.style.transform = 'translateY(100px)';
            toast.style.opacity = '0';
        }, 3000);
    }
}
</script>
</body>
</html>