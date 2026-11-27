<?php


if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/store.php';

define('DB_HOST', 'localhost');
define('DB_PORT', '3307');
define('DB_USER', 'root');
define('DB_PASS', 'mysqlforuniv@24');
define('DB_NAME', 'ecommerce_activity_db');
define('DB_CHARSET', 'utf8mb4');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            logAppError('Database connection failed', $e);
            if (isAjaxRequest()) {
                jsonResponse(503, ['success' => false, 'message' => MSG_SERVER_ERROR]);
            }
            renderErrorPage(503, MSG_SERVER_ERROR);
            exit;
        }
    }
    return $pdo;
}


function logActivity($userId, $action, $description) {
    try {
        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $db->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $description, $ip]);
    } catch (Exception $e) {
        logAppError('Activity log write failed', $e);
    }
}


function isCustomerLoggedIn() {
    return isset($_SESSION['user_id']);
}


function isAdminLoggedIn() {
    return isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'admin';
}


function sanitize($data) {
    return htmlspecialchars(trim($data ?? ''), ENT_QUOTES, 'UTF-8');
}


function isValidPhone($phone) {
    return is_string($phone) && preg_match('/^[0-9]{7,15}$/', $phone) === 1;
}


function generateFormToken($form) {
    $token = bin2hex(random_bytes(16));
    if (!isset($_SESSION['form_tokens'][$form]) || !is_array($_SESSION['form_tokens'][$form])) {
        $_SESSION['form_tokens'][$form] = [];
    }
    $_SESSION['form_tokens'][$form][] = $token;
    $_SESSION['form_tokens'][$form] = array_slice($_SESSION['form_tokens'][$form], -10);
    return $token;
}

function consumeFormToken($form, $token) {
    $token = (string)$token;
    $tokens = $_SESSION['form_tokens'][$form] ?? [];
    if ($token === '' || !is_array($tokens)) {
        return false;
    }
    $index = array_search($token, $tokens, true);
    if ($index === false) {
        return false;
    }
    unset($tokens[$index]);
    $_SESSION['form_tokens'][$form] = array_values($tokens);
    return true;
}


function ensureCartTable() {
    static $ready = false;
    if ($ready) {
        return;
    }
    getDB()->exec("
        CREATE TABLE IF NOT EXISTS cart_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cart_user_product (user_id, product_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $ready = true;
}

function isCartCustomer() {
    return isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'customer';
}

function loadSavedCart($userId) {
    $stmt = getDB()->prepare("
        SELECT ci.product_id, ci.quantity
        FROM cart_items ci
        INNER JOIN products p ON p.id = ci.product_id
        WHERE ci.user_id = ? AND ci.quantity > 0
        ORDER BY ci.id ASC
    ");
    $stmt->execute([$userId]);
    $cart = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cart[(int)$row['product_id']] = (int)$row['quantity'];
    }
    return $cart;
}

function saveCart($userId, array $cart) {
    $db = getDB();
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        $db->prepare("DELETE FROM cart_items WHERE user_id = ?")->execute([$userId]);
        $ins = $db->prepare("INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)");
        foreach ($cart as $productId => $qty) {
            $productId = (int)$productId;
            $qty = (int)$qty;
            if ($productId > 0 && $qty > 0) {
                $ins->execute([$userId, $productId, $qty]);
            }
        }
        if ($ownTransaction) {
            $db->commit();
        }
    } catch (Exception $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function syncCustomerCart() {
    static $syncedThisRequest = false;
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    if ($syncedThisRequest || !isCartCustomer()) {
        return;
    }
    $userId = (int)$_SESSION['user_id'];
    try {
        ensureCartTable();
        $saved = loadSavedCart($userId);

        if (($_SESSION['cart_synced_for'] ?? null) !== $userId) {
            $guestCart = $_SESSION['cart'];
            foreach ($guestCart as $productId => $qty) {
                $productId = (int)$productId;
                $qty = (int)$qty;
                if ($productId > 0 && $qty > 0) {
                    $saved[$productId] = ($saved[$productId] ?? 0) + $qty;
                }
            }
            if (!empty($guestCart)) {
                saveCart($userId, $saved);
                $saved = loadSavedCart($userId);
            }
            $_SESSION['cart_synced_for'] = $userId;
        }

        $_SESSION['cart'] = $saved;
        $syncedThisRequest = true;
    } catch (Exception $e) {
    }
}

function persistCart() {
    if (!isCartCustomer()) {
        return;
    }
    try {
        ensureCartTable();
        saveCart((int)$_SESSION['user_id'], $_SESSION['cart'] ?? []);
    } catch (Exception $e) {
    }
}

enforceCsrfOnPost();
