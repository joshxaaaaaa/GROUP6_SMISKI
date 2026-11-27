<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/db.php';

$orderInfo = $_SESSION['latest_order'] ?? null;
unset($_SESSION['latest_order']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Placed Successfully - Smiski Store</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Outfit', sans-serif; }
        body {
            background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
            color: #1f2937 !important;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .smiski-card {
            background: rgba(255, 255, 255, 0.78) !important;
            backdrop-filter: blur(30px) saturate(180%);
            -webkit-backdrop-filter: blur(30px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.5) !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35), 0 0 20px rgba(134, 239, 172, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.8) !important;
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s ease, border-color 0.4s ease;
            border-radius: 24px;
            width: 100%;
            max-width: 500px;
            padding: 3rem 2.5rem;
            text-align: center;
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
            padding: 1.25rem;
            margin-bottom: 2rem;
            text-align: left;
        }

        .check-icon {
            width: 80px;
            height: 80px;
            background: rgba(220, 252, 231, 0.9);
            color: #15803d;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem auto;
            font-size: 2.5rem;
            border: 1px solid rgba(74, 222, 128, 0.6);
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
            padding: 0.85rem 1.25rem;
            border-radius: 14px;
            font-weight: 600;
            font-size: 1rem;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            width: 100%;
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
            padding: 0.85rem 1.25rem;
            border-radius: 14px;
            font-weight: 600;
            font-size: 1rem;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            width: 100%;
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

        .order-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            margin-bottom: 0.65rem;
            color: #1f2937;
        }
    </style>
</head>
<body>

<div class="smiski-card">
    <div class="check-icon">
        <i class="fa-solid fa-circle-check"></i>
    </div>

    <h1 class="neon-glow-text" style="font-size: 1.75rem; font-weight: 700; margin-bottom: 0.5rem;">Order Placed Successfully!</h1>
    <p class="text-muted-green" style="font-size: 0.95rem; margin-bottom: 2rem; line-height: 1.5;">Thank you for your purchase. Your order has been securely placed and is now being processed.</p>

    <?php if ($orderInfo): ?>
        <div class="smiski-subcard">
            <div class="order-row">
                <span class="text-muted-green">Order Number:</span>
                <strong style="color: #064e3b;"><?= htmlspecialchars($orderInfo['order_number']) ?></strong>
            </div>
            <div class="order-row">
                <span class="text-muted-green">Payment Method:</span>
                <strong style="color: #1f2937;"><?= htmlspecialchars($orderInfo['payment_method']) ?></strong>
            </div>
            <div class="order-row" style="margin-bottom: 0;">
                <?php
                $pMethod = strtolower($orderInfo['payment_method'] ?? '');
                $isGcash = strpos($pMethod, 'gcash') !== false || strpos($pMethod, 'wallet') !== false;
                $isCod = strpos($pMethod, 'cod') !== false;
                ?>
                <span class="text-muted-green"><?= $isGcash ? 'Amount (Pending Verification):' : ($isCod ? 'Amount Due on Delivery:' : 'Total Amount Paid:') ?></span>
                <strong class="neon-glow-text" style="font-size: 1.05rem;">₱<?= number_format($orderInfo['total_amount'], 2) ?></strong>
            </div>
        </div>
    <?php endif; ?>

    <div style="display: flex; flex-direction: column; gap: 0.85rem;">
        <a href="orders.php" class="btn-green-primary" style="text-decoration: none;">
            <i class="fa-solid fa-truck-fast"></i> Track My Order Progress
        </a>
        <a href="home.php" class="btn-green-secondary" style="text-decoration: none;">
            <i class="fa-solid fa-house"></i> Return to Store Catalog
        </a>
    </div>
</div>

</body>
</html>