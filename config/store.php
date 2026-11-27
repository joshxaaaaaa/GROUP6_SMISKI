<?php
const STORE_SCHEMA_VERSION = 1;

const REVIEW_STATUSES = ['pending', 'approved', 'rejected', 'hidden'];
const REVIEW_MIN_LENGTH = 10;
const REVIEW_MAX_LENGTH = 1000;
const DISCOUNT_TYPES = ['percentage', 'fixed'];
const ADMIN_PAGE_SIZE = 10;

const MSG_PHONE_SHORT = 'Please enter a valid phone number.';
const MSG_CURRENT_PASSWORD = 'Your current password is incorrect.';
const MSG_PASSWORD_UNCHANGED = 'Your new password must be different from your current password.';
const MSG_RATING = 'Please select a valid rating.';
const MSG_REVIEW_EMPTY = 'Please enter your review before submitting.';
const MSG_DISCOUNT_VALUE = 'Please enter a valid discount amount.';
const MSG_DISCOUNT_PERCENT = 'Discount percentage must be between 1% and 100%.';
const MSG_DISCOUNT_DATES = 'Please enter a valid discount date range.';
const MSG_DISCOUNT_EXPIRED = 'This discount has expired and can no longer be used.';
const MSG_DISCOUNT_UNAVAILABLE = 'This discount is not available for your order.';

function getSetting($key, $default = null) {
    $stmt = getDB()->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : $value;
}

function setSetting($key, $value) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    if ($stmt->fetchColumn() !== false) {
        $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?")->execute([(string)$value, $key]);
    } else {
        $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, (string)$value]);
    }
}

function ordersHaveDiscountColumn($refresh = false) {
    static $has = null;
    if ($has === null || $refresh) {
        try {
            getDB()->query("SELECT discount_id FROM orders LIMIT 0");
            $has = true;
        } catch (Exception $e) {
            $has = false;
        }
    }
    return $has;
}

function ensureStoreSchema() {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $db = getDB();

    try {
        if ((int)getSetting('store_schema_version', 0) >= STORE_SCHEMA_VERSION) {
            return;
        }
    } catch (Exception $e) {
        logAppError('Reading store schema version failed', $e);
        return;
    }

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS discounts (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                description VARCHAR(500) NULL,
                discount_type VARCHAR(20) NOT NULL,
                discount_value DECIMAL(10,2) NOT NULL,
                min_purchase DECIMAL(10,2) NOT NULL DEFAULT 0,
                min_completed_orders INT NOT NULL DEFAULT 0,
                usage_limit INT NULL,
                start_date DATE NULL,
                end_date DATE NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_discount_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS product_reviews (
                id INT PRIMARY KEY AUTO_INCREMENT,
                product_id INT NOT NULL,
                customer_id INT NOT NULL,
                rating TINYINT NOT NULL,
                review_text TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                moderated_by INT NULL,
                moderated_at DATETIME NULL,
                deleted_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_review_product_customer (product_id, customer_id),
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (moderated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        logAppError('Creating store tables failed', $e);
        return;
    }

    if (!ordersHaveDiscountColumn(true)) {
        try {
            $db->exec("ALTER TABLE orders ADD COLUMN discount_id INT NULL");
            ordersHaveDiscountColumn(true);
        } catch (Exception $e) {
            logAppError('Adding orders.discount_id failed', $e);
        }
        try {
            $db->exec("ALTER TABLE orders ADD CONSTRAINT fk_orders_discount FOREIGN KEY (discount_id) REFERENCES discounts(id) ON DELETE SET NULL");
        } catch (Exception $e) {
        }
    }
    try {
        $db->exec("ALTER TABLE orders MODIFY discount_type VARCHAR(100) DEFAULT NULL");
    } catch (Exception $e) {
    }

    try {
        if ((int)$db->query("SELECT COUNT(*) FROM discounts")->fetchColumn() === 0) {
            $rate = (float)getSetting('loyalty_discount_rate', 5);
            $minOrders = max(1, (int)getSetting('loyalty_min_purchases', 5));
            $stmt = $db->prepare("INSERT INTO discounts (name, description, discount_type, discount_value, min_purchase, min_completed_orders, usage_limit, start_date, end_date, is_active) VALUES (?, ?, 'percentage', ?, 0, ?, NULL, NULL, NULL, 1)");
            $stmt->execute(['Loyal Customer Discount', 'Automatic reward for customers with at least ' . $minOrders . ' completed (delivered) orders.', $rate > 0 && $rate <= 100 ? $rate : 5, $minOrders]);
        }
        setSetting('store_schema_version', STORE_SCHEMA_VERSION);
    } catch (Exception $e) {
        logAppError('Seeding discounts failed', $e);
    }
}

function discountUsageSql($alias = 'd') {
    return ordersHaveDiscountColumn()
        ? "(SELECT COUNT(*) FROM orders o WHERE o.discount_id = $alias.id AND o.status <> 'cancelled')"
        : "0";
}

function discountStatusSql($alias = 'd') {
    $used = discountUsageSql($alias);
    return "CASE
        WHEN $alias.is_active = 0 THEN 'inactive'
        WHEN $alias.end_date IS NOT NULL AND $alias.end_date < CURDATE() THEN 'expired'
        WHEN $alias.start_date IS NOT NULL AND $alias.start_date > CURDATE() THEN 'scheduled'
        WHEN $alias.usage_limit IS NOT NULL AND $used >= $alias.usage_limit THEN 'used_up'
        ELSE 'active' END";
}

function discountStatusLabel($status) {
    $labels = ['active' => 'Active', 'scheduled' => 'Scheduled', 'expired' => 'Expired', 'inactive' => 'Inactive', 'used_up' => 'Usage Limit Reached'];
    return $labels[$status] ?? ucfirst((string)$status);
}

function formatPercent($value) {
    return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.') . '%';
}

function discountValueLabel(array $d) {
    return $d['discount_type'] === 'percentage'
        ? formatPercent($d['discount_value']) . ' off'
        : '₱' . number_format((float)$d['discount_value'], 2) . ' off';
}

function discountOrderLabel(array $d) {
    $suffix = $d['discount_type'] === 'percentage'
        ? ' (' . formatPercent($d['discount_value']) . ')'
        : ' (₱' . number_format((float)$d['discount_value'], 2) . ' off)';
    return mb_substr($d['name'] . $suffix, 0, 100, 'UTF-8');
}

function calculateDiscountAmount(array $d, $subtotal) {
    $subtotal = max(0, (float)$subtotal);
    $amount = $d['discount_type'] === 'percentage'
        ? $subtotal * ((float)$d['discount_value'] / 100)
        : min((float)$d['discount_value'], $subtotal);
    return round(max(0, $amount), 2);
}

function countCompletedOrders($customerId) {
    $stmt = getDB()->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND status = 'delivered'");
    $stmt->execute([(int)$customerId]);
    return (int)$stmt->fetchColumn();
}

function fetchDiscountWithStatus($discountId, $forUpdate = false) {
    $sql = "SELECT d.*, " . discountStatusSql('d') . " AS computed_status, " . discountUsageSql('d') . " AS times_used FROM discounts d WHERE d.id = ?";
    if ($forUpdate) {
        $sql .= " FOR UPDATE";
    }
    $stmt = getDB()->prepare($sql);
    $stmt->execute([(int)$discountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function discountEligibilityError(array $d, $completedOrders, $subtotal) {
    switch ($d['computed_status']) {
        case 'expired':
            return MSG_DISCOUNT_EXPIRED;
        case 'inactive':
        case 'scheduled':
        case 'used_up':
            return MSG_DISCOUNT_UNAVAILABLE;
    }
    if ((float)$subtotal < (float)$d['min_purchase']) {
        return 'This discount requires a minimum purchase of ₱' . number_format((float)$d['min_purchase'], 2) . '.';
    }
    if ((int)$completedOrders < (int)$d['min_completed_orders']) {
        return 'This discount is for customers with at least ' . (int)$d['min_completed_orders'] . ' completed orders.';
    }
    if (calculateDiscountAmount($d, $subtotal) <= 0) {
        return MSG_DISCOUNT_UNAVAILABLE;
    }
    return '';
}

function getCheckoutDiscounts($customerId, $subtotal) {
    ensureStoreSchema();
    $completed = countCompletedOrders($customerId);
    $result = ['completed' => $completed, 'available' => [], 'locked' => []];
    try {
        $rows = getDB()->query("SELECT d.*, " . discountStatusSql('d') . " AS computed_status, " . discountUsageSql('d') . " AS times_used FROM discounts d ORDER BY d.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        logAppError('Loading discounts failed', $e);
        return $result;
    }
    foreach ($rows as $d) {
        if ($d['computed_status'] !== 'active') {
            continue;
        }
        $d['amount'] = calculateDiscountAmount($d, $subtotal);
        $d['label'] = discountOrderLabel($d);
        $d['value_label'] = discountValueLabel($d);
        $error = discountEligibilityError($d, $completed, $subtotal);
        if ($error === '') {
            $result['available'][] = $d;
            continue;
        }
        if ($completed < (int)$d['min_completed_orders']) {
            $d['remaining_orders'] = (int)$d['min_completed_orders'] - $completed;
            $result['locked'][] = $d;
        } elseif ((float)$subtotal < (float)$d['min_purchase']) {
            $d['remaining_amount'] = round((float)$d['min_purchase'] - (float)$subtotal, 2);
            $result['locked'][] = $d;
        }
    }
    usort($result['available'], function ($a, $b) {
        return $b['amount'] <=> $a['amount'] ?: $a['id'] <=> $b['id'];
    });
    return $result;
}

function renderPagination($page, $totalPages, array $params, $script) {
    if ($totalPages <= 1) {
        return '';
    }
    $link = function ($p, $label, $disabled = false, $current = false) use ($params, $script) {
        $style = 'display:inline-flex;align-items:center;justify-content:center;min-width:2.2rem;padding:0.45rem 0.7rem;border-radius:10px;font-size:0.85rem;font-weight:700;text-decoration:none;border:1px solid rgba(74,222,128,0.6);';
        if ($current) {
            return '<span aria-current="page" style="' . $style . 'background:#15803d;color:#fff;">' . $label . '</span>';
        }
        if ($disabled) {
            return '<span style="' . $style . 'background:rgba(255,255,255,0.5);color:#9ca3af;">' . $label . '</span>';
        }
        $query = http_build_query(array_merge($params, ['page' => $p]));
        return '<a href="' . htmlspecialchars($script . '?' . $query, ENT_QUOTES, 'UTF-8') . '" style="' . $style . 'background:rgba(255,255,255,0.85);color:#15803d;">' . $label . '</a>';
    };
    $html = '<nav aria-label="Pagination" style="display:flex;gap:0.4rem;flex-wrap:wrap;justify-content:center;padding:1.25rem;">';
    $html .= $link($page - 1, '&laquo; Prev', $page <= 1);
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    for ($p = $start; $p <= $end; $p++) {
        $html .= $link($p, (string)$p, false, $p === $page);
    }
    $html .= $link($page + 1, 'Next &raquo;', $page >= $totalPages);
    return $html . '</nav>';
}

function validDateOrNull($value) {
    if (!is_string($value)) {
        return false;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        return false;
    }
    return $value;
}

function todayDate() {
    try {
        return (string)getDB()->query("SELECT CURDATE()")->fetchColumn();
    } catch (Exception $e) {
        return date('Y-m-d');
    }
}

function getProductReviewStats($productId) {
    ensureStoreSchema();
    try {
        $stmt = getDB()->prepare("SELECT COUNT(*) AS review_count, AVG(rating) AS avg_rating FROM product_reviews WHERE product_id = ? AND status = 'approved' AND deleted_at IS NULL");
        $stmt->execute([(int)$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['count' => (int)$row['review_count'], 'average' => $row['review_count'] ? round((float)$row['avg_rating'], 1) : 0.0];
    } catch (Exception $e) {
        logAppError('Loading review stats failed', $e);
        return ['count' => 0, 'average' => 0.0];
    }
}

function getApprovedReviews($productId, $limit = 20) {
    ensureStoreSchema();
    try {
        $stmt = getDB()->prepare("SELECT r.rating, r.review_text, r.created_at, u.full_name FROM product_reviews r INNER JOIN users u ON u.id = r.customer_id WHERE r.product_id = ? AND r.status = 'approved' AND r.deleted_at IS NULL ORDER BY r.created_at DESC, r.id DESC LIMIT " . (int)$limit);
        $stmt->execute([(int)$productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        logAppError('Loading reviews failed', $e);
        return [];
    }
}

function getCustomerReview($customerId, $productId) {
    ensureStoreSchema();
    $stmt = getDB()->prepare("SELECT * FROM product_reviews WHERE customer_id = ? AND product_id = ?");
    $stmt->execute([(int)$customerId, (int)$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function customerReceivedProduct($customerId, $productId) {
    $stmt = getDB()->prepare("SELECT COUNT(*) FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id WHERE o.customer_id = ? AND o.status = 'delivered' AND oi.product_id = ?");
    $stmt->execute([(int)$customerId, (int)$productId]);
    return (int)$stmt->fetchColumn() > 0;
}

function reviewStatusLabel(array $review) {
    if (!empty($review['deleted_at'])) {
        return 'Removed';
    }
    return ucfirst($review['status']);
}

function renderStars($rating, $size = '1rem') {
    $rating = max(0, min(5, (float)$rating));
    $html = '<span aria-label="' . htmlspecialchars(number_format($rating, 1), ENT_QUOTES, 'UTF-8') . ' out of 5 stars" style="color:#f59e0b;font-size:' . htmlspecialchars($size, ENT_QUOTES, 'UTF-8') . ';white-space:nowrap;">';
    for ($i = 1; $i <= 5; $i++) {
        if ($rating >= $i) {
            $html .= '<i class="fa-solid fa-star"></i>';
        } elseif ($rating >= $i - 0.5) {
            $html .= '<i class="fa-solid fa-star-half-stroke"></i>';
        } else {
            $html .= '<i class="fa-regular fa-star"></i>';
        }
    }
    return $html . '</span>';
}
