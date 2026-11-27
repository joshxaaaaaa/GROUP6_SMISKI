<?php
require_once __DIR__ . '/../config/db.php';

// Product ID must be a positive whole number
$rawId = $_GET['id'] ?? '';
if (!is_string($rawId) || !ctype_digit($rawId) || (int)$rawId < 1) {
    setFlash('error', 'Invalid product link. ' . MSG_INVALID_OPERATION);
    header("Location: index.php", true, 303);
    exit;
}
$id = (int)$rawId;
$db = getDB();

$stmt = $db->prepare("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ? AND p.status = 'active'");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    setFlash('error', 'Sorry, this product could not be found or is no longer available.');
    header("Location: index.php", true, 303);
    exit;
}

// Check kung Bundle Series para maging box ang shape
$isBundle = (
    isset($product['category_name']) && (
        stripos($product['category_name'], 'Bundle') !== false || 
        stripos($product['category_name'], 'Set') !== false
    )
) || (
    isset($product['name']) && (
        stripos($product['name'], 'Bundle') !== false || 
        stripos($product['name'], 'Set') !== false
    )
);

$imgContainerShape = $isBundle ? 'border-radius: 16px;' : 'border-radius: 50%;';

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
        transform: translateY(-4px);
        border-color: #86efac !important;
        background: rgba(255, 255, 255, 0.88) !important;
        box-shadow: 0 30px 60px rgba(0, 0, 0, 0.45), 0 0 35px rgba(134, 239, 172, 0.6), inset 0 0 15px rgba(134, 239, 172, 0.3) !important;
    }

    .hover-scale {
        transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
    }
    .hover-scale:hover {
        transform: translateY(-3px);
        border-color: #86efac !important;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2), 0 0 20px rgba(74, 222, 128, 0.5) !important;
    }

    /* Green Glow-in-the-Dark Text Effect for Glass White Backgrounds */
    .neon-glow-text {
        color: #064e3b !important;
        text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
    }
</style>

<div class="container" style="padding-top: 1.5rem; padding-bottom: 3rem;">
    
    <!-- Back Button -->
    <div style="margin-bottom: 1.5rem;">
        <a href="index.php" class="hover-scale" style="background: rgba(255, 255, 255, 0.65); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); color: #064e3b; border: 1px solid rgba(74, 222, 128, 0.6); font-size: 0.85rem; padding: 0.55rem 1.1rem; border-radius: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600; box-shadow: 0 4px 15px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.8);">
            <i class="fa-solid fa-arrow-left"></i> Back to Products
        </a>
    </div>

    <!-- Main Realistic Glass Card Container -->
    <div class="smiski-card" style="border-radius: 24px; padding: 2.5rem; display: flex; gap: 3rem; flex-wrap: wrap;">

        <!-- Product Image Container -->
        <div style="flex: 1; min-width: 280px; display: flex; align-items: center; justify-content: center; min-height: 320px;">
            <div style="width: 280px; height: 280px; background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); <?= $imgContainerShape ?> display: flex; align-items: center; justify-content: center; overflow: hidden; border: 1px solid rgba(74, 222, 128, 0.5); box-shadow: inset 0 2px 5px rgba(255, 255, 255, 0.9), 0 10px 25px rgba(0, 0, 0, 0.1), 0 0 15px rgba(74, 222, 128, 0.3); padding: 1rem;">
                <?php if (!empty($product['image'])): ?>
                    <img src="../<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" style="width: 100%; height: 100%; object-fit: contain; transition: transform 0.35s ease;" onmouseenter="this.style.transform='scale(1.08)'" onmouseleave="this.style.transform='scale(1)'">
                <?php else: ?>
                    <i class="fa-solid fa-box-open" style="font-size: 5rem; color: #16a34a; opacity: 0.6;"></i>
                <?php endif; ?>
            </div>
        </div>

        <!-- Product Details Container -->
        <div style="flex: 2; min-width: 300px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <!-- Category Badge -->
                <span style="background: rgba(22, 163, 74, 0.15); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); color: #15803d; font-size: 0.85rem; font-weight: 700; padding: 0.35rem 0.9rem; border-radius: 999px; display: inline-block; margin-bottom: 0.75rem; border: 1px solid rgba(74, 222, 128, 0.5); box-shadow: 0 0 8px rgba(74, 222, 128, 0.3);">
                    <?= htmlspecialchars($product['category_name'] ?? 'General') ?>
                </span>

                <!-- Product Name -->
                <h1 class="neon-glow-text" style="font-size: 2.1rem; font-weight: 700; margin-bottom: 0.75rem; line-height: 1.2;">
                    <?= htmlspecialchars($product['name']) ?>
                </h1>

                <!-- Price -->
                <div style="font-size: 1.85rem; font-weight: 700; color: #15803d; margin-bottom: 1.25rem; text-shadow: 0 0 8px rgba(74, 222, 128, 0.6);">
                    ₱<?= number_format($product['price'], 2) ?>
                </div>

                <!-- Description -->
                <div style="margin-bottom: 1.5rem;">
                    <h4 class="neon-glow-text" style="font-size: 0.95rem; font-weight: 700; margin-bottom: 0.4rem;">Description</h4>
                    <p style="color: #1f2937; line-height: 1.6; font-size: 0.95rem; font-weight: 500;">
                        <?= nl2br(htmlspecialchars($product['description'] ?? '')) ?>
                    </p>
                </div>

                <!-- Stock Availability -->
                <div style="margin-bottom: 2rem;">
                    <h4 class="neon-glow-text" style="font-size: 0.95rem; font-weight: 700; margin-bottom: 0.4rem;">Stock Availability</h4>
                    <span style="font-weight: 700; font-size: 1.05rem; color: <?= $product['stock_quantity'] > 0 ? '#15803d' : '#dc2626' ?>; text-shadow: <?= $product['stock_quantity'] > 0 ? '0 0 6px rgba(74, 222, 128, 0.6)' : 'none' ?>;">
                        <?= $product['stock_quantity'] > 0 ? $product['stock_quantity'] . ' units available' : 'Out of Stock' ?>
                    </span>
                </div>
            </div>

            <!-- Actions -->
            <?php if (function_exists('isAdminLoggedIn') && isAdminLoggedIn()): ?>
                <button disabled class="btn" style="background: rgba(100, 116, 139, 0.15); backdrop-filter: blur(12px); color: #334155; border: 1px solid rgba(100, 116, 139, 0.3); cursor: not-allowed; padding: 0.85rem; width: 100%; border-radius: 14px; font-weight: 600;" title="Purchasing is disabled in Admin mode">
                    <i class="fa-solid fa-eye"></i> Admin Store Preview Mode (Purchasing Disabled)
                </button>
            <?php elseif ($product['stock_quantity'] > 0): ?>
                <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                    <button type="button" onclick="addToCart(<?= $product['id'] ?>, '<?= htmlspecialchars(addslashes($product['name'])) ?>')" class="hover-scale btn" style="flex: 1; min-width: 160px; padding: 0.85rem; background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); color: #064e3b; border: 1px solid rgba(74, 222, 128, 0.7); border-radius: 14px; font-weight: 600; cursor: pointer; box-shadow: 0 6px 15px rgba(0,0,0,0.1), 0 0 10px rgba(74, 222, 128, 0.4);">
                        <i class="fa-solid fa-cart-plus"></i> Add To Cart
                    </button>
                    <a href="cart.php?action=buy_now&id=<?= (int)$product['id'] ?>&<?= csrfQuery() ?>" class="hover-scale btn" style="flex: 1; min-width: 160px; padding: 0.85rem; background: #16a34a; backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); color: white; border: 1px solid rgba(74, 222, 128, 0.8); border-radius: 14px; font-weight: 600; text-align: center; text-decoration: none; box-shadow: 0 6px 20px rgba(22, 163, 74, 0.4), 0 0 15px rgba(74, 222, 128, 0.5);">
                        <i class="fa-solid fa-bolt"></i> BUY NOW
                    </a>
                </div>
            <?php else: ?>
                <button disabled class="btn" style="background: rgba(239, 68, 68, 0.15); backdrop-filter: blur(12px); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.3); cursor: not-allowed; width: 100%; padding: 0.85rem; border-radius: 14px; font-weight: 600;">
                    Currently Out of Stock
                </button>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>