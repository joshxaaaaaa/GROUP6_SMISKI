<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // errors are logged, never shown (see config/validation.php)

// Session is started securely in config/db.php
require_once __DIR__ . '/../config/db.php';

if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit;
}

$db = getDB();
// Results of the last action (Post/Redirect/Get, so refreshing never repeats it)
$message = getFlash('success');
$error = getFlash('error');
$addErrors = [];  $addOld = null;    // Add Product form
$editErrors = []; $editOld = null;   // Edit Product form

/**
 * Validates the product fields shared by Add and Edit.
 * Returns [values, errors, imageCheck].
 */
function validateProductInput(PDO $db, Validator $v, $productId = null) {
    $values = [
        'name'           => $v->text('name', ['required' => true, 'min' => 2, 'max' => 200]),
        'category_id'    => $v->int('category_id', ['min' => 1, 'message' => MSG_INVALID_CHOICE, 'minMessage' => MSG_INVALID_CHOICE]),
        'price'          => $v->money('price', ['min' => 0.01, 'max' => 1000000,
                                'minMessage' => 'Price must be greater than zero.',
                                'negativeMessage' => 'Price cannot be negative.',
                                'maxMessage' => 'Price must not exceed ₱1,000,000.00.']),
        'stock_quantity' => $v->int('stock_quantity', ['min' => 0, 'max' => 100000,
                                'message' => 'Please enter a whole number for the stock quantity.',
                                'minMessage' => 'Stock quantity cannot be negative.',
                                'maxMessage' => 'Stock quantity must not exceed 100,000.']),
        'description'    => $v->message('description', ['required' => false, 'max' => 2000]),
    ];

    if ($values['category_id'] !== null) {
        $cat = $db->prepare("SELECT id FROM categories WHERE id = ?");
        $cat->execute([$values['category_id']]);
        if (!$cat->fetch()) {
            $v->addError('category_id', MSG_INVALID_CHOICE);
        }
    }

    // Same product name twice in one category is almost always a duplicate entry
    if (!$v->hasError('name') && $values['category_id'] !== null) {
        $dup = $db->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?) AND category_id = ? AND id <> ?");
        $dup->execute([$values['name'], $values['category_id'], $productId ?? 0]);
        if ($dup->fetch()) {
            $v->addError('name', 'A product with this name already exists in this category.');
        }
    }

    $image = checkImageUpload('product_image', allowedImageTypes(true));
    if ($image['error'] !== null) {
        $v->addError('product_image', $image['error']);
    }
    return [$values, $v->errors(), $image];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = new Validator($_POST);
    $action = $v->choice('action', ['add', 'edit', 'delete'], ['message' => MSG_INVALID_OPERATION]);
    if ($action === null) {
        abortRequest(400, MSG_INVALID_OPERATION, 'inventory.php');
    }

    if ($action === 'add') {
        [$values, $addErrors, $image] = validateProductInput($db, $v);
        if ($addErrors) {
            $addOld = $values;
            $error = 'Product not added. ' . MSG_FORM_ERRORS;
            http_response_code(422);
        } elseif (!consumeFormToken('product_add', $_POST['form_token'] ?? '')) {
            setFlash('error', MSG_DUPLICATE . ' The product list below already includes it if it was saved.');
            redirectTo('inventory.php');
        } else {
            $imagePath = null;
            try {
                if ($image['present']) {
                    $imagePath = storeCheckedUpload($image, 'products', 'prod');
                }
                $stmt = $db->prepare("INSERT INTO products (name, description, category_id, price, stock_quantity, image, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                $stmt->execute([$values['name'], $values['description'], $values['category_id'], $values['price'], $values['stock_quantity'], $imagePath]);
                logActivity($_SESSION['user_id'], 'PRODUCT_ADDED', "Added new product: " . $values['name']);
                setFlash('success', 'Product "' . $values['name'] . '" added successfully!');
                redirectTo('inventory.php');
            } catch (Exception $e) {
                deleteStoredUpload($imagePath);
                $addOld = $values;
                $error = 'Product not added. ' . safeErrorMessage($e, 'Product insert failed');
                http_response_code(500);
            }
        }
    }

    if ($action === 'edit' || $action === 'delete') {
        $id = $v->id('id');
        if ($id === null) {
            abortRequest(400, MSG_INVALID_OPERATION, 'inventory.php');
        }
        $find = $db->prepare("SELECT id, name, image FROM products WHERE id = ?");
        $find->execute([$id]);
        $existing = $find->fetch();
        if (!$existing) {
            abortRequest(404, 'This product no longer exists.', 'inventory.php');
        }
    }

    if ($action === 'edit') {
        [$values, $editErrors, $image] = validateProductInput($db, $v, $id);
        $values['status'] = $v->choice('status', ['active', 'inactive']);
        $editErrors = $v->errors();
        if ($editErrors) {
            $editOld = $values + ['id' => $id];
            $error = 'Product not updated. ' . MSG_FORM_ERRORS;
            http_response_code(422);
        } else {
            $imagePath = null;
            try {
                if ($image['present']) {
                    $imagePath = storeCheckedUpload($image, 'products', 'prod');
                    $stmt = $db->prepare("UPDATE products SET name = ?, description = ?, category_id = ?, price = ?, stock_quantity = ?, status = ?, image = ? WHERE id = ?");
                    $stmt->execute([$values['name'], $values['description'], $values['category_id'], $values['price'], $values['stock_quantity'], $values['status'], $imagePath, $id]);
                } else {
                    $stmt = $db->prepare("UPDATE products SET name = ?, description = ?, category_id = ?, price = ?, stock_quantity = ?, status = ? WHERE id = ?");
                    $stmt->execute([$values['name'], $values['description'], $values['category_id'], $values['price'], $values['stock_quantity'], $values['status'], $id]);
                }
                logActivity($_SESSION['user_id'], 'PRODUCT_UPDATED', "Updated product ID $id: " . $values['name']);
                setFlash('success', 'Product "' . $values['name'] . '" updated successfully!');
                redirectTo('inventory.php');
            } catch (Exception $e) {
                deleteStoredUpload($imagePath);
                $editOld = $values + ['id' => $id];
                $error = 'Product not updated. ' . safeErrorMessage($e, 'Product update failed');
                http_response_code(500);
            }
        }
    }

    if ($action === 'delete') {
        try {
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM order_items WHERE product_id = ?");
            $checkStmt->execute([$id]);
            if ($checkStmt->fetchColumn() > 0) {
                $stmt = $db->prepare("UPDATE products SET status = 'inactive' WHERE id = ?");
                $stmt->execute([$id]);
                logActivity($_SESSION['user_id'], 'PRODUCT_DEACTIVATED', "Deactivated product ID $id (linked to orders)");
                setFlash('success', 'Product marked as inactive because it is linked to existing order records.');
            } else {
                $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
                $stmt->execute([$id]);
                logActivity($_SESSION['user_id'], 'PRODUCT_DELETED', "Deleted product ID $id");
                setFlash('success', 'Product deleted successfully!');
            }
        } catch (Exception $e) {
            setFlash('error', 'Product not deleted. ' . safeErrorMessage($e, 'Product delete failed'));
        }
        redirectTo('inventory.php');
    }
}

$productAddToken = generateFormToken('product_add');

$categories = $db->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

$products = $db->query("
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    ORDER BY p.id DESC
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body {
        background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
        color: #1f2937 !important;
        font-family: 'Outfit', sans-serif;
        min-height: 100vh;
    }

    /* White Frosted Glass Card Effect */
    .smiski-glass-card {
        background: rgba(255, 255, 255, 0.78) !important;
        backdrop-filter: blur(30px) saturate(180%);
        -webkit-backdrop-filter: blur(30px) saturate(180%);
        border: 1px solid rgba(255, 255, 255, 0.5) !important;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35), 0 0 20px rgba(134, 239, 172, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.8) !important;
        transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s ease, border-color 0.4s ease;
        border-radius: 24px;
    }
    
    .smiski-glass-card:hover {
        transform: translateY(-2px);
        border-color: #86efac !important;
        background: rgba(255, 255, 255, 0.88) !important;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4), 0 0 30px rgba(134, 239, 172, 0.5), inset 0 0 15px rgba(134, 239, 172, 0.3) !important;
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

    /* Form Inputs & Controls for White Cards */
    .smiski-input, .smiski-select, .smiski-textarea, .smiski-file {
        width: 100%;
        padding: 0.75rem 1rem;
        background: rgba(255, 255, 255, 0.85) !important;
        border: 1px solid rgba(74, 222, 128, 0.5) !important;
        border-radius: 12px;
        font-size: 0.95rem;
        outline: none;
        color: #1f2937;
        backdrop-filter: blur(10px);
        transition: all 0.3s ease;
        font-family: inherit;
    }
    .smiski-input::placeholder, .smiski-textarea::placeholder {
        color: #9ca3af;
    }
    .smiski-input:focus, .smiski-select:focus, .smiski-textarea:focus, .smiski-file:focus {
        border-color: #15803d !important;
        background: #ffffff !important;
        box-shadow: 0 0 15px rgba(74, 222, 128, 0.4);
    }
    .smiski-select option {
        background: #ffffff;
        color: #1f2937;
    }

    /* Green Primary Button */
    .btn-green-glow {
        background: #15803d !important;
        color: #ffffff !important;
        border: 1px solid #4ade80 !important;
        box-shadow: 0 0 12px rgba(74, 222, 128, 0.5);
        transition: all 0.3s ease;
        cursor: pointer;
    }
    .btn-green-glow:hover {
        background: #166534 !important;
        transform: translateY(-2px);
        border-color: #86efac !important;
        box-shadow: 0 0 20px rgba(74, 222, 128, 0.8), inset 0 0 8px rgba(74, 222, 128, 0.4);
    }

    /* Action Buttons */
    .btn-action-view {
        background: rgba(255, 255, 255, 0.7);
        color: #15803d;
        border: 1px solid rgba(74, 222, 128, 0.6);
        padding: 0.4rem 0.8rem;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.8rem;
        font-weight: 600;
        transition: all 0.2s ease;
    }
    .btn-action-view:hover {
        background: rgba(255, 255, 255, 0.9);
        border-color: #15803d;
        box-shadow: 0 0 10px rgba(74, 222, 128, 0.5);
    }

    .btn-action-edit {
        background: rgba(220, 252, 231, 0.9);
        color: #15803d;
        border: 1px solid rgba(74, 222, 128, 0.6);
        padding: 0.4rem 0.8rem;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.8rem;
        font-weight: 600;
        transition: all 0.2s ease;
    }
    .btn-action-edit:hover {
        background: rgba(187, 247, 208, 1);
        border-color: #15803d;
        box-shadow: 0 0 12px rgba(74, 222, 128, 0.6);
    }

    .btn-action-delete {
        background: rgba(254, 226, 226, 0.9);
        color: #dc2626;
        border: 1px solid rgba(248, 113, 113, 0.6);
        padding: 0.4rem 0.8rem;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.8rem;
        font-weight: 600;
        transition: all 0.2s ease;
    }
    .btn-action-delete:hover {
        background: rgba(254, 202, 202, 1);
        border-color: #dc2626;
        box-shadow: 0 0 12px rgba(248, 113, 113, 0.5);
    }
</style>

<div class="container" style="padding: 2rem 1rem; max-width: 1300px; margin: 0 auto;">

    <div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2 class="white-glow-heading" style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.025em; margin-bottom: 0.25rem;">
                <i class="fa-solid fa-boxes-stacked" style="color: #86efac;"></i> Inventory Management
            </h2>
            <p class="white-subtext" style="font-size: 0.9rem;">Manage product catalog, prices, and stock levels</p>
        </div>

        <button onclick="openAddModal()" class="btn-green-glow" style="padding: 0.75rem 1.25rem; font-size: 0.95rem; font-weight: 700; border-radius: 12px;">
            <i class="fa-solid fa-plus"></i> Add New Product
        </button>
    </div>

    <?php if ($message): ?>
        <div style="background: rgba(220, 252, 231, 0.95); border: 1px solid rgba(74, 222, 128, 0.6); color: #064e3b; padding: 0.85rem 1rem; border-radius: 12px; font-size: 0.9rem; margin-bottom: 1.5rem; font-weight: 600; box-shadow: 0 5px 15px rgba(0,0,0,0.15);">
            <i class="fa-solid fa-circle-check" style="color: #15803d;"></i> <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div style="background: rgba(254, 226, 226, 0.95); border: 1px solid rgba(239, 68, 68, 0.5); color: #991b1b; padding: 0.85rem 1rem; border-radius: 12px; font-size: 0.9rem; margin-bottom: 1.5rem; font-weight: 600; box-shadow: 0 5px 15px rgba(0,0,0,0.15);">
            <i class="fa-solid fa-triangle-exclamation" style="color: #dc2626;"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="smiski-glass-card" style="overflow: hidden;">
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="background: rgba(220, 252, 231, 0.5); color: #064e3b; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid rgba(74, 222, 128, 0.4);">
                        <th style="padding: 1rem;">ID</th>
                        <th style="padding: 1rem;">Image</th>
                        <th style="padding: 1rem;">Product Name</th>
                        <th style="padding: 1rem;">Category</th>
                        <th style="padding: 1rem;">Price</th>
                        <th style="padding: 1rem;">Stock</th>
                        <th style="padding: 1rem;">Status</th>
                        <th style="padding: 1rem; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr><td colspan="8" class="text-muted-green" style="text-align: center; padding: 3rem; font-size: 0.95rem;">No products found in inventory.</td></tr>
                    <?php else: ?>
                        <?php foreach ($products as $index => $p): ?>
                            <tr style="border-bottom: 1px solid rgba(74, 222, 128, 0.2); transition: background 0.2s;" onmouseenter="this.style.background='rgba(220, 252, 231, 0.4)'" onmouseleave="this.style.background='transparent'">
                                <td style="padding: 0.9rem 1rem; font-size: 0.9rem; font-weight: 700; color: #064e3b;"><?= $p['id'] ?></td>
                                <td style="padding: 0.9rem 1rem;">
                                    <?php if (!empty($p['image'])): ?>
                                        <img src="../<?= htmlspecialchars($p['image']) ?>" alt="<?= htmlspecialchars($p['name']) ?>" style="width: 48px; height: 48px; object-fit: cover; border-radius: 8px; border: 1px solid rgba(74, 222, 128, 0.5); box-shadow: 0 0 10px rgba(74,222,128,0.3);">
                                    <?php else: ?>
                                        <div style="width: 48px; height: 48px; background: rgba(255, 255, 255, 0.8); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #15803d; font-size: 1.1rem; border: 1px solid rgba(74, 222, 128, 0.5);">
                                            <i class="fa-solid fa-image"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.9rem 1rem; font-weight: 700; color: #064e3b; font-size: 0.9rem;"><?= htmlspecialchars($p['name']) ?></td>
                                <td class="text-muted-green" style="padding: 0.9rem 1rem; font-size: 0.85rem;"><?= htmlspecialchars($p['category_name'] ?? 'General') ?></td>
                                <td style="padding: 0.9rem 1rem; font-weight: 700; color: #15803d; font-size: 0.9rem;">₱<?= number_format($p['price'], 2) ?></td>
                                <td style="padding: 0.9rem 1rem;">
                                    <span style="background: <?= $p['stock_quantity'] > 0 ? 'rgba(220, 252, 231, 0.9)' : 'rgba(254, 226, 226, 0.9)' ?>; color: <?= $p['stock_quantity'] > 0 ? '#15803d' : '#991b1b' ?>; border: 1px solid <?= $p['stock_quantity'] > 0 ? 'rgba(74, 222, 128, 0.6)' : 'rgba(248, 113, 113, 0.6)' ?>; padding: 0.25rem 0.6rem; border-radius: 8px; font-size: 0.75rem; font-weight: 700; display: inline-block;">
                                        <?= $p['stock_quantity'] ?> units
                                    </span>
                                </td>
                                <td style="padding: 0.9rem 1rem;">
                                    <span style="background: <?= $p['status'] === 'active' ? 'rgba(220, 252, 231, 0.9)' : 'rgba(254, 243, 199, 0.9)' ?>; color: <?= $p['status'] === 'active' ? '#15803d' : '#92400e' ?>; border: 1px solid <?= $p['status'] === 'active' ? 'rgba(74, 222, 128, 0.6)' : 'rgba(251, 191, 36, 0.6)' ?>; padding: 0.25rem 0.6rem; border-radius: 8px; font-size: 0.75rem; font-weight: 700; display: inline-block;">
                                        <?= ucfirst($p['status']) ?>
                                    </span>
                                </td>
                                <td style="padding: 0.9rem 1rem; text-align: center; white-space: nowrap;">
                                    <div style="display: inline-flex; gap: 0.4rem;">
                                        <button type="button" onclick='openViewModal(<?= jsonAttr($p) ?>)' class="btn-action-view" title="View product details">
                                            <i class="fa-solid fa-eye"></i> View
                                        </button>
                                        <button type="button" onclick='openEditModal(<?= jsonAttr($p) ?>)' class="btn-action-edit" title="Edit product">
                                            <i class="fa-solid fa-pen-to-square"></i> Edit
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete or deactivate this product?')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                            <button type="submit" class="btn-action-delete" title="Delete product">
                                                <i class="fa-solid fa-trash"></i> Delete
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

<div id="addModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(5, 46, 22, 0.85); backdrop-filter: blur(10px); align-items: center; justify-content: center; z-index: 2000;">
    <div class="smiski-glass-card" style="padding: 2rem; width: 100%; max-width: 500px; position: relative; max-height: 90vh; overflow-y: auto; color: #1f2937;">
        <button onclick="closeAddModal()" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #15803d;" onmouseover="this.style.color='#064e3b'" onmouseout="this.style.color='#15803d'">&times;</button>
        <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem;">Add New Product</h3>

        <form method="POST" enctype="multipart/form-data" id="addProductForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="form_token" value="<?= htmlspecialchars($productAddToken) ?>">

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Product Name</label>
                <input type="text" name="name" required minlength="2" maxlength="200" value="<?= oldValue($addOld ?? [], 'name') ?>" class="smiski-input<?= invalidClass($addErrors, 'name') ?>"<?= invalidAttr($addErrors, 'name') ?>>
                <?= fieldError($addErrors, 'name') ?>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Category</label>
                <select name="category_id" required class="smiski-select<?= invalidClass($addErrors, 'category_id') ?>">
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)($addOld['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= fieldError($addErrors, 'category_id') ?>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Product Image</label>
                <input type="file" name="product_image" accept="image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif,image/avif,image/bmp,image/tiff,.heic,.heif" data-max-size="5242880" class="smiski-file<?= invalidClass($addErrors, 'product_image') ?>">
                <?= fieldError($addErrors, 'product_image') ?>
                <p class="text-muted-green" style="font-size: 0.75rem; margin-top: 0.25rem;">Accepted: JPG, PNG, GIF, WebP, HEIC, AVIF, BMP, TIFF. Maximum 5 MB.</p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Price (₱)</label>
                    <input type="number" step="0.01" min="0.01" max="1000000" name="price" required data-msg-min="Price must be greater than zero." data-msg-max="Price must not exceed ₱1,000,000.00." value="<?= oldValue($addOld ?? [], 'price') ?>" class="smiski-input<?= invalidClass($addErrors, 'price') ?>"<?= invalidAttr($addErrors, 'price') ?>>
                    <?= fieldError($addErrors, 'price') ?>
                </div>
                <div>
                    <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Stock Quantity</label>
                    <input type="number" step="1" min="0" max="100000" name="stock_quantity" required data-msg-min="Stock quantity cannot be negative." data-msg-number="Please enter a whole number for the stock quantity." data-msg-max="Stock quantity must not exceed 100,000." value="<?= oldValue($addOld ?? [], 'stock_quantity', '10') ?>" class="smiski-input<?= invalidClass($addErrors, 'stock_quantity') ?>"<?= invalidAttr($addErrors, 'stock_quantity') ?>>
                    <?= fieldError($addErrors, 'stock_quantity') ?>
                </div>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Description</label>
                <textarea name="description" rows="3" maxlength="2000" data-counter class="smiski-textarea<?= invalidClass($addErrors, 'description') ?>"<?= invalidAttr($addErrors, 'description') ?>><?= oldValue($addOld ?? [], 'description') ?></textarea>
                <?= fieldError($addErrors, 'description') ?>
            </div>

            <button type="submit" class="btn-green-glow" style="width: 100%; padding: 0.75rem; font-size: 0.95rem; font-weight: 700; border-radius: 12px;">Add Product</button>
        </form>
    </div>
</div>

<div id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(5, 46, 22, 0.85); backdrop-filter: blur(10px); align-items: center; justify-content: center; z-index: 2000;">
    <div class="smiski-glass-card" style="padding: 2rem; width: 100%; max-width: 500px; position: relative; max-height: 90vh; overflow-y: auto; color: #1f2937;">
        <button onclick="closeEditModal()" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #15803d;" onmouseover="this.style.color='#064e3b'" onmouseout="this.style.color='#15803d'">&times;</button>
        <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem;">Edit Product</h3>

        <form method="POST" enctype="multipart/form-data" id="editProductForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Product Name</label>
                <input type="text" name="name" id="edit_name" required minlength="2" maxlength="200" class="smiski-input<?= invalidClass($editErrors, 'name') ?>"<?= invalidAttr($editErrors, 'name') ?>>
                <?= fieldError($editErrors, 'name') ?>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Category</label>
                <select name="category_id" id="edit_category_id" required class="smiski-select<?= invalidClass($editErrors, 'category_id') ?>">
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= fieldError($editErrors, 'category_id') ?>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Product Image</label>
                <div id="edit_image_preview" style="margin-bottom: 0.5rem;"></div>
                <input type="file" name="product_image" accept="image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif,image/avif,image/bmp,image/tiff,.heic,.heif" data-max-size="5242880" class="smiski-file<?= invalidClass($editErrors, 'product_image') ?>">
                <?= fieldError($editErrors, 'product_image') ?>
                <p class="text-muted-green" style="font-size: 0.75rem; margin-top: 0.25rem;">Leave empty to keep current image. Upload new to replace (JPG, PNG, GIF, WebP, HEIC, AVIF, BMP, TIFF; max 5 MB).</p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Price (₱)</label>
                    <input type="number" step="0.01" min="0.01" max="1000000" name="price" id="edit_price" required data-msg-min="Price must be greater than zero." data-msg-max="Price must not exceed ₱1,000,000.00." class="smiski-input<?= invalidClass($editErrors, 'price') ?>"<?= invalidAttr($editErrors, 'price') ?>>
                    <?= fieldError($editErrors, 'price') ?>
                </div>
                <div>
                    <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Stock Quantity</label>
                    <input type="number" step="1" min="0" max="100000" name="stock_quantity" id="edit_stock" required data-msg-min="Stock quantity cannot be negative." data-msg-number="Please enter a whole number for the stock quantity." data-msg-max="Stock quantity must not exceed 100,000." class="smiski-input<?= invalidClass($editErrors, 'stock_quantity') ?>"<?= invalidAttr($editErrors, 'stock_quantity') ?>>
                    <?= fieldError($editErrors, 'stock_quantity') ?>
                </div>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Status</label>
                <select name="status" id="edit_status" required class="smiski-select<?= invalidClass($editErrors, 'status') ?>">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Description</label>
                <textarea name="description" id="edit_description" rows="3" maxlength="2000" data-counter class="smiski-textarea<?= invalidClass($editErrors, 'description') ?>"<?= invalidAttr($editErrors, 'description') ?>></textarea>
                <?= fieldError($editErrors, 'description') ?>
            </div>

            <button type="submit" class="btn-green-glow" style="width: 100%; padding: 0.75rem; font-size: 0.95rem; font-weight: 700; border-radius: 12px;">Save Changes</button>
        </form>
    </div>
</div>

<div id="viewModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(5, 46, 22, 0.85); backdrop-filter: blur(10px); align-items: center; justify-content: center; z-index: 2000;">
    <div class="smiski-glass-card" style="padding: 2rem; width: 100%; max-width: 500px; position: relative; color: #1f2937;">
        <button onclick="closeViewModal()" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #15803d;" onmouseover="this.style.color='#064e3b'" onmouseout="this.style.color='#15803d'">&times;</button>
        <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem;">Product Details</h3>

        <div style="display: flex; flex-direction: column; gap: 0.85rem; font-size: 0.9rem; color: #374151;">
            <div id="view_image_container" style="text-align: center; margin-bottom: 0.5rem;"></div>
            <div><strong style="color: #1f2937;">ID:</strong> <span id="view_id" style="color: #15803d; font-weight: 600;"></span></div>
            <div><strong style="color: #1f2937;">Name:</strong> <span id="view_name" style="font-weight: 600; color: #064e3b;"></span></div>
            <div><strong style="color: #1f2937;">Category:</strong> <span id="view_category"></span></div>
            <div><strong style="color: #1f2937;">Price:</strong> ₱<span id="view_price" style="font-weight: 700; color: #15803d;"></span></div>
            <div><strong style="color: #1f2937;">Stock Available:</strong> <span id="view_stock"></span> units</div>
            <div><strong style="color: #1f2937;">Status:</strong> <span id="view_status" style="font-weight: 700;"></span></div>
            <div><strong style="color: #1f2937;">Description:</strong> <p id="view_description" style="margin-top: 0.4rem; background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(74, 222, 128, 0.4); padding: 0.8rem; border-radius: 12px; color: #1f2937;"></p></div>
        </div>
    </div>
</div>

<script>
function openAddModal() { document.getElementById('addModal').style.display = 'flex'; }
function closeAddModal() { document.getElementById('addModal').style.display = 'none'; }

function openEditModal(p, keepErrors) {
    // Opening the form for a (different) product clears old validation messages
    if (!keepErrors && window.FormValidation) FormValidation.clearErrors(document.getElementById('editProductForm'));
    document.getElementById('edit_id').value = p.id;
    document.getElementById('edit_name').value = p.name;
    document.getElementById('edit_category_id').value = p.category_id;
    document.getElementById('edit_price').value = p.price;
    document.getElementById('edit_stock').value = p.stock_quantity;
    document.getElementById('edit_status').value = p.status;
    document.getElementById('edit_description').value = p.description || '';

    var preview = document.getElementById('edit_image_preview');
    if (p.image) {
        preview.innerHTML = '<img src="../' + p.image + '" style="max-width: 120px; max-height: 90px; object-fit: cover; border-radius: 8px; border: 1px solid rgba(74, 222, 128, 0.5); box-shadow: 0 0 10px rgba(74,222,128,0.3);">';
    } else {
        preview.innerHTML = '<span style="font-size: 0.8rem; color: #4b5563;">No image currently set</span>';
    }
    document.getElementById('editModal').style.display = 'flex';
}
function closeEditModal() { document.getElementById('editModal').style.display = 'none'; }

function openViewModal(p) {
    var imgContainer = document.getElementById('view_image_container');
    if (p.image) {
        imgContainer.innerHTML = '<img src="../' + p.image + '" style="max-width: 100%; max-height: 220px; object-fit: contain; border-radius: 12px; border: 1px solid rgba(74, 222, 128, 0.5); box-shadow: 0 0 15px rgba(74,222,128,0.4);">';
    } else {
        imgContainer.innerHTML = '<div style="width: 100%; height: 150px; background: rgba(255, 255, 255, 0.6); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #4b5563; font-size: 2.5rem; border: 1px solid rgba(74, 222, 128, 0.4);"><i class="fa-solid fa-image"></i></div>';
    }
    document.getElementById('view_id').innerText = p.id;
    document.getElementById('view_name').innerText = p.name;
    document.getElementById('view_category').innerText = p.category_name || 'General';
    document.getElementById('view_price').innerText = parseFloat(p.price).toFixed(2);
    document.getElementById('view_stock').innerText = p.stock_quantity;
    document.getElementById('view_status').innerText = p.status;
    document.getElementById('view_description').innerText = p.description || 'No description provided.';
    document.getElementById('viewModal').style.display = 'flex';
}
function closeViewModal() { document.getElementById('viewModal').style.display = 'none'; }
<?php if ($addOld !== null): ?>
// The last "Add Product" failed validation: show the form again with the entered values and errors
document.addEventListener('DOMContentLoaded', openAddModal);
<?php endif; ?>
<?php if ($editOld !== null): ?>
// The last edit failed validation: show the form again with the entered values and errors
document.addEventListener('DOMContentLoaded', function () {
    openEditModal(<?= json_encode($editOld + ['image' => $existing['image'] ?? null], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, true);
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>