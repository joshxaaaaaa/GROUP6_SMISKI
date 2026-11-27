<?php
require_once __DIR__ . '/../config/db.php';

$db = getDB();

// Flash messages from the Contact Us form (Post/Redirect/Get)
$contactSuccess = $_SESSION['contact_flash_success'] ?? '';
$contactError = $_SESSION['contact_flash_error'] ?? '';
unset($_SESSION['contact_flash_success'], $_SESSION['contact_flash_error']);

// Field errors + entered values of a failed Contact Us submission (shown by includes/footer.php)
$contactFormState = takeFormState('contact');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_contact') {
    if (isAdminLoggedIn()) {
        $_SESSION['contact_flash_error'] = MSG_UNAUTHORIZED . ' The contact form is disabled in Admin mode.';
    } else {
        $accountEmail = isCustomerLoggedIn() ? ($_SESSION['user_email'] ?? null) : null;
        [$values, $errors] = validateSupportMessage($_POST, $accountEmail);

        if ($errors) {
            setFormState('contact', $errors, $values);
            $_SESSION['contact_flash_error'] = 'Your message was not sent. ' . MSG_FORM_ERRORS;
        } elseif (isMessageRateLimited($values['email'])) {
            setFormState('contact', [], $values);
            $_SESSION['contact_flash_error'] = MSG_RATE_LIMITED;
        } elseif (!consumeFormToken('contact_form', $_POST['form_token'] ?? '')) {
            // Same form submitted again (refresh / double click): don't save a duplicate
            $_SESSION['contact_flash_error'] = MSG_DUPLICATE;
        } else {
            try {
                saveSupportMessage($values);
                $_SESSION['contact_flash_success'] = 'Thank you! Your message has been sent successfully. <a href="messages.php" style="color:#064e3b; font-weight:700; text-decoration:underline; text-shadow: 0 0 6px rgba(74,222,128,0.5);">Click here to track support replies</a>.';
            } catch (Exception $e) {
                setFormState('contact', [], $values);
                $_SESSION['contact_flash_error'] = safeErrorMessage($e, 'Contact form save failed');
            }
        }
    }

    // Redirect so refreshing the page does not resubmit the form
    header("Location: index.php", true, 303);
    exit;
}

// Categories sorted strictly A-Z (Case-insensitive using LOWER)
$catStmt = $db->query("SELECT * FROM categories WHERE status = 'active' ORDER BY LOWER(name) ASC");
$categories = $catStmt->fetchAll();

/* ---- Search & filter parameters (invalid values are ignored and the customer is told) ---- */
$filterNotice = '';
$rawSearch = $_GET['search'] ?? '';
$search = is_string($rawSearch) ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $rawSearch)) : '';
if (!is_string($rawSearch) || !mb_check_encoding($search, 'UTF-8')) {
    $search = '';
    $filterNotice = 'Your search could not be processed. Please try a different keyword.';
} elseif (mb_strlen($search, 'UTF-8') > 100) {
    $search = mb_substr($search, 0, 100, 'UTF-8');
    $filterNotice = 'Search keywords are limited to 100 characters, so your search was shortened.';
}

$validCategoryIds = array_map('intval', array_column($categories, 'id'));
$rawCategory = $_GET['category'] ?? '';
$selectedCategory = 0;
if (is_string($rawCategory) && $rawCategory !== '' && $rawCategory !== '0') {
    if (ctype_digit($rawCategory) && in_array((int)$rawCategory, $validCategoryIds, true)) {
        $selectedCategory = (int)$rawCategory;
    } else {
        $filterNotice = 'The selected category does not exist, so all categories are shown.';
    }
}

$rawPriceSort = $_GET['price_sort'] ?? '';
$priceSort = (is_string($rawPriceSort) && in_array($rawPriceSort, ['', 'low_high', 'high_low'], true)) ? $rawPriceSort : '';
$rawStockStatus = $_GET['stock_status'] ?? '';
$stockStatus = (is_string($rawStockStatus) && in_array($rawStockStatus, ['', 'in_stock', 'out_of_stock'], true)) ? $rawStockStatus : '';
if ($priceSort !== $rawPriceSort || $stockStatus !== $rawStockStatus) {
    $filterNotice = 'Some filter options were invalid and have been reset.';
}

$sql = "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.status = 'active'";
$params = [];

if (!empty($search)) {
    // Treat % and _ typed by the customer as normal characters
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $sql .= " AND (p.name LIKE ? OR p.description LIKE ?)";
    $params[] = $like;
    $params[] = $like;
}

if ($selectedCategory > 0) {
    $sql .= " AND p.category_id = ?";
    $params[] = $selectedCategory;
}

if ($stockStatus === 'in_stock') {
    $sql .= " AND p.stock_quantity > 0";
} elseif ($stockStatus === 'out_of_stock') {
    $sql .= " AND p.stock_quantity <= 0";
}

// Price Sorting (Lowest to Highest / Highest to Lowest)
if ($priceSort === 'low_high') {
    $sql .= " ORDER BY p.price ASC";
} elseif ($priceSort === 'high_low') {
    $sql .= " ORDER BY p.price DESC";
} else {
    $sql .= " ORDER BY p.created_at DESC"; // Default fallback sorting
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body {
        background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
        min-height: 100vh;
    }

    /* iOS Frosted Glass White Card Effect with Darker, Prominent Borders */
    .smiski-card {
        background: rgba(255, 255, 255, 0.82) !important;
        backdrop-filter: blur(30px) saturate(180%);
        -webkit-backdrop-filter: blur(30px) saturate(180%);
        border: 2px solid rgba(22, 101, 52, 0.55) !important;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4), 0 0 15px rgba(22, 101, 52, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.9) !important;
        transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s ease, border-color 0.4s ease;
    }
    .smiski-card:hover {
        transform: translateY(-6px) scale(1.01);
        border-color: #15803d !important;
        background: rgba(255, 255, 255, 0.92) !important;
        box-shadow: 0 30px 60px rgba(0, 0, 0, 0.5), 0 0 25px rgba(34, 197, 94, 0.6), inset 0 0 15px rgba(74, 222, 128, 0.3) !important;
    }

    .hover-scale {
        transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
    }
    .hover-scale:hover {
        transform: translateY(-3px);
        border-color: #15803d !important;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2), 0 0 15px rgba(22, 101, 52, 0.4) !important;
    }

    /* Green Glow-in-the-Dark Text Effect for Glass White Backgrounds */
    .neon-glow-text {
        color: #064e3b !important;
        text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
    }

    /* Placeholder Styling for Inputs */
    .search-input::placeholder {
        color: rgba(6, 78, 59, 0.6) !important;
        opacity: 1;
    }
</style>

<div class="container" style="padding-top: 1.5rem; padding-bottom: 3rem;">

    <?php if ($contactSuccess): ?>
        <div class="smiski-card" style="color: #064e3b; margin-bottom: 1.5rem; border-radius: 18px; padding: 1.1rem 1.4rem; font-weight: 600; display: flex; align-items: center; gap: 0.75rem;">
            <i class="fa-solid fa-circle-check" style="color: #16a34a; font-size: 1.2rem; filter: drop-shadow(0 0 6px rgba(74,222,128,0.8));"></i> 
            <div><?= $contactSuccess ?></div>
        </div>
    <?php endif; ?>

    <?php if ($contactError): ?>
        <div class="smiski-card" style="color: #991b1b; background: rgba(254, 226, 226, 0.95) !important; margin-bottom: 1.5rem; border-radius: 18px; padding: 1.1rem 1.4rem; font-weight: 600; display: flex; align-items: center; gap: 0.75rem;">
            <i class="fa-solid fa-circle-exclamation" style="color: #dc2626; font-size: 1.2rem;"></i>
            <div><?= htmlspecialchars($contactError) ?></div>
        </div>
    <?php endif; ?>

    <?= alertBox('info', $filterNotice) ?>

    <!-- TOP HEADER & FILTER BAR -->
    <div class="smiski-card" style="border-radius: 24px; padding: 1.75rem 2.2rem; margin-bottom: 1.75rem;">
        
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
            <div>
                <h2 class="neon-glow-text" style="font-size: 1.6rem; font-weight: 700;">Welcome to PROJECT SMISKI</h2>
                <p style="font-size: 0.85rem; color: #166534; margin-top: 0.1rem; font-weight: 600;">Explore our curated selection of quality products (Showing <?= count($products) ?> items)</p>
            </div>
        </div>

        <!-- Horizontal Filter Form -->
        <form method="GET" action="index.php" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
            
            <!-- Search Input -->
            <div style="flex: 1; min-width: 200px; position: relative;">
                <input type="text" name="search" maxlength="100" aria-label="Search products" value="<?= htmlspecialchars($search) ?>" placeholder="Search product name..." class="search-input hover-scale"
                       style="width: 100%; padding: 0.65rem 0.9rem 0.65rem 2.3rem; border: 2px solid rgba(22, 101, 52, 0.5); border-radius: 14px; font-size: 0.85rem; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(15px); color: #064e3b !important; outline: none; box-shadow: inset 0 2px 4px rgba(255,255,255,0.8); font-weight: 600;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 0.85rem; top: 50%; transform: translateY(-50%); color: #15803d; font-size: 0.85rem;"></i>
            </div>

            <!-- Categories A-Z Select -->
            <div style="min-width: 160px;">
                <select name="category" class="hover-scale" style="width: 100%; padding: 0.65rem 0.75rem; border: 2px solid rgba(22, 101, 52, 0.5); border-radius: 14px; font-size: 0.85rem; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(15px); color: #064e3b; outline: none; font-weight: 600;">
                    <option value="" style="background: #ffffff; color: #064e3b;">All Categories (A-Z)</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $selectedCategory == $cat['id'] ? 'selected' : '' ?> style="background: #ffffff; color: #064e3b;">
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Price Sorting Dropdown -->
            <div style="min-width: 160px;">
                <select name="price_sort" class="hover-scale" style="width: 100%; padding: 0.65rem 0.75rem; border: 2px solid rgba(22, 101, 52, 0.5); border-radius: 14px; font-size: 0.85rem; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(15px); color: #064e3b; outline: none; font-weight: 600;">
                    <option value="" style="background: #ffffff; color: #064e3b;">Sort by Price</option>
                    <option value="low_high" <?= $priceSort === 'low_high' ? 'selected' : '' ?> style="background: #ffffff; color: #064e3b;">Price: Lowest to Highest</option>
                    <option value="high_low" <?= $priceSort === 'high_low' ? 'selected' : '' ?> style="background: #ffffff; color: #064e3b;">Price: Highest to Lowest</option>
                </select>
            </div>

            <!-- Stock Availability -->
            <div style="min-width: 150px;">
                <select name="stock_status" class="hover-scale" style="width: 100%; padding: 0.65rem 0.75rem; border: 2px solid rgba(22, 101, 52, 0.5); border-radius: 14px; font-size: 0.85rem; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(15px); color: #064e3b; outline: none; font-weight: 600;">
                    <option value="" style="background: #ffffff; color: #064e3b;">All Stock Status</option>
                    <option value="in_stock" <?= $stockStatus === 'in_stock' ? 'selected' : '' ?> style="background: #ffffff; color: #064e3b;">In Stock Only</option>
                    <option value="out_of_stock" <?= $stockStatus === 'out_of_stock' ? 'selected' : '' ?> style="background: #ffffff; color: #064e3b;">Out of Stock</option>
                </select>
            </div>

            <!-- Buttons -->
            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="hover-scale btn" style="background: #16a34a; backdrop-filter: blur(15px); border: 2px solid rgba(22, 101, 52, 0.8); color: #fff; padding: 0.65rem 1.1rem; font-size: 0.85rem; border-radius: 14px; font-weight: 600; box-shadow: 0 4px 15px rgba(22, 163, 74, 0.4), 0 0 10px rgba(74, 222, 128, 0.5);">
                    <i class="fa-solid fa-filter"></i> Apply
                </button>
                <?php if (!empty($search) || $selectedCategory > 0 || !empty($priceSort) || !empty($stockStatus)): ?>
                    <a href="index.php" class="hover-scale btn" style="background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(15px); color: #064e3b; border: 2px solid rgba(22, 101, 52, 0.5); padding: 0.65rem 0.9rem; font-size: 0.85rem; text-decoration: none; border-radius: 14px; font-weight: 600; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">
                        <i class="fa-solid fa-rotate-left"></i> Reset
                    </a>
                <?php endif; ?>
            </div>

        </form>
    </div>

    <!-- PRODUCTS GRID -->
    <?php if (empty($products)): ?>
        <div class="smiski-card" style="border-radius: 24px; padding: 3rem; text-align: center;">
            <i class="fa-solid fa-box-open" style="font-size: 3rem; color: #16a34a; margin-bottom: 1rem; filter: drop-shadow(0 0 8px rgba(74,222,128,0.6));"></i>
            <h3 class="neon-glow-text" style="font-size: 1.5rem; font-weight: 700;">No Products Found</h3>
            <p style="color: #1f2937; font-size: 0.9rem; margin-top: 0.3rem; font-weight: 500;">Try changing your search terms or filter options.</p>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1.5rem;">
            <?php foreach ($products as $product): ?>
                <?php 
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
                ?>
                <!-- iOS Frosted Glass White Card with Prominent Dark Border -->
                <div class="smiski-card" style="border-radius: 22px; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden;">
                    
                    <a href="product.php?id=<?= $product['id'] ?>" style="display: flex; align-items: center; justify-content: center; width: 100%; height: 180px; background: rgba(255, 255, 255, 0.5); backdrop-filter: blur(10px); border-bottom: 2px solid rgba(22, 101, 52, 0.3); padding: 1rem;">
                        <div style="width: 130px; height: 130px; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); <?= $imgContainerShape ?> display: flex; align-items: center; justify-content: center; overflow: hidden; border: 2px solid rgba(22, 101, 52, 0.5); box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08), inset 0 2px 5px rgba(255, 255, 255, 0.9), 0 0 12px rgba(74, 222, 128, 0.25); padding: 0.75rem;">
                            <?php if (!empty($product['image'])): ?>
                                <img src="../<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="product-img" style="width: 100%; height: 100%; object-fit: contain; transition: transform 0.35s ease;" onmouseenter="this.style.transform='scale(1.1)'" onmouseleave="this.style.transform='none'">
                            <?php else: ?>
                                <i class="fa-solid fa-box-open" style="font-size: 2.5rem; color: #16a34a;"></i>
                            <?php endif; ?>
                        </div>
                    </a>

                    <div style="padding: 1.35rem; flex: 1; display: flex; flex-direction: column;">
                        <span style="background: rgba(22, 163, 74, 0.15); backdrop-filter: blur(10px); color: #15803d; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.7rem; border-radius: 10px; display: inline-block; margin-bottom: 0.6rem; width: fit-content; border: 1.5px solid rgba(22, 101, 52, 0.4); box-shadow: 0 0 6px rgba(74, 222, 128, 0.3);">
                            <?= htmlspecialchars($product['category_name'] ?? 'General') ?>
                        </span>

                        <h4 class="neon-glow-text" style="font-size: 1.1rem; font-weight: 700; margin-bottom: 0.4rem; line-height: 1.3;">
                            <?= htmlspecialchars($product['name']) ?>
                        </h4>

                        <p style="font-size: 0.85rem; color: #1f2937; margin-bottom: 1.1rem; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; flex: 1; font-weight: 500;">
                            <?= htmlspecialchars($product['description'] ?? '') ?>
                        </p>

                        <div style="margin-top: auto;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.85rem;">
                                <span style="font-size: 1.25rem; font-weight: 700; color: #15803d; text-shadow: 0 0 8px rgba(74, 222, 128, 0.6);">
                                    ₱<?= number_format($product['price'], 2) ?>
                                </span>
                                <span style="font-size: 0.8rem; font-weight: 700; color: <?= $product['stock_quantity'] > 0 ? '#15803d' : '#dc2626' ?>;">
                                    <?= $product['stock_quantity'] > 0 ? $product['stock_quantity'] . ' in stock' : 'Out of Stock' ?>
                                </span>
                            </div>

                            <div style="display: flex; gap: 0.5rem;">
                                <a href="product.php?id=<?= $product['id'] ?>" class="hover-scale btn" style="flex:1; padding:0.55rem; font-size:0.85rem; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(12px); color: #064e3b; border: 2px solid rgba(22, 101, 52, 0.5); text-align:center; text-decoration:none; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">
                                    Details
                                </a>
                                <?php if (function_exists('isAdminLoggedIn') && isAdminLoggedIn()): ?>
                                    <button disabled class="btn" style="flex:1; padding:0.55rem; font-size:0.85rem; opacity:0.6; cursor:not-allowed; background: rgba(100, 116, 139, 0.15); color: #334155; border: 2px solid rgba(100, 116, 139, 0.3); border-radius: 12px;" title="Cart disabled in Admin mode">
                                        <i class="fa-solid fa-ban"></i> + Cart
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="hover-scale btn" onclick="addToCart(<?= $product['id'] ?>, '<?= htmlspecialchars(addslashes($product['name'])) ?>')" style="flex:1; padding:0.55rem; font-size:0.85rem; background: #16a34a; backdrop-filter: blur(12px); border: 2px solid rgba(22, 101, 52, 0.8); color: #fff; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3), 0 0 10px rgba(74, 222, 128, 0.4);">
                                        + Cart
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>