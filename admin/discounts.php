<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/db.php';

if (!isAdminLoggedIn()) {
    if (isAjaxRequest()) {
        jsonResponse(401, ['success' => false, 'message' => 'Your session has expired. Please log in again.']);
    }
    header("Location: login.php");
    exit;
}

$db = getDB();
ensureStoreSchema();

$message = getFlash('success');
$error = getFlash('error');
$addErrors = [];  $addOld = null;
$editErrors = []; $editOld = null;

$discountStatusFilters = ['active', 'scheduled', 'expired', 'inactive', 'used_up'];

function discountsReturnUrl($raw) {
    if (is_string($raw) && $raw !== '' && strlen($raw) <= 500 && preg_match('/^[A-Za-z0-9_\-=&%.+\[\]]*$/', $raw)) {
        return 'discounts.php?' . $raw;
    }
    return 'discounts.php';
}

function validateDiscountInput(PDO $db, Validator $v, array $existing = null) {
    $values = [
        'name'                 => $v->text('name', ['required' => true, 'min' => 2, 'max' => 100]),
        'description'          => $v->message('description', ['required' => false, 'max' => 500]),
        'discount_type'        => $v->choice('discount_type', DISCOUNT_TYPES),
        'discount_value'       => null,
        'min_purchase'         => $v->money('min_purchase', ['required' => false, 'max' => 1000000,
                                      'negativeMessage' => 'Minimum purchase cannot be negative.',
                                      'maxMessage' => 'Minimum purchase must not exceed ₱1,000,000.00.']),
        'min_completed_orders' => $v->int('min_completed_orders', ['required' => false, 'min' => 0, 'max' => 1000,
                                      'message' => 'Please enter a whole number of completed orders.',
                                      'minMessage' => 'Completed orders cannot be negative.',
                                      'maxMessage' => 'Completed orders must not exceed 1,000.']),
        'usage_limit'          => $v->int('usage_limit', ['required' => false, 'min' => 1, 'max' => 1000000,
                                      'message' => 'Please enter a whole number for the usage limit.',
                                      'minMessage' => 'Usage limit must be at least 1, or leave it empty for unlimited use.',
                                      'maxMessage' => 'Usage limit must not exceed 1,000,000.']),
        'start_date'           => null,
        'end_date'             => null,
        'is_active'            => $v->choice('is_active', ['1', '0']),
    ];
    $values['min_purchase'] = $values['min_purchase'] ?? 0.0;
    $values['min_completed_orders'] = $values['min_completed_orders'] ?? 0;

    $rawValue = $_POST['discount_value'] ?? '';
    $rawValue = is_string($rawValue) ? trim($rawValue) : null;
    if ($rawValue === '') {
        $v->addError('discount_value', MSG_REQUIRED);
    } elseif ($rawValue === null || !preg_match('/^\d{1,8}(\.\d{1,2})?$/', $rawValue) || (float)$rawValue <= 0) {
        $v->addError('discount_value', MSG_DISCOUNT_VALUE);
    } else {
        $values['discount_value'] = round((float)$rawValue, 2);
        if ($values['discount_type'] === 'percentage' && ($values['discount_value'] < 1 || $values['discount_value'] > 100)) {
            $v->addError('discount_value', MSG_DISCOUNT_PERCENT);
        } elseif ($values['discount_type'] === 'fixed' && $values['discount_value'] > 1000000) {
            $v->addError('discount_value', MSG_DISCOUNT_VALUE);
        } elseif ($values['discount_type'] === 'fixed' && !$v->hasError('min_purchase') && $values['discount_value'] > $values['min_purchase']) {
            $v->addError('min_purchase', 'For a fixed amount discount, the minimum purchase must be at least the discount amount (₱' . number_format($values['discount_value'], 2) . ').');
        }
    }

    $start = validDateOrNull($_POST['start_date'] ?? '');
    $end = validDateOrNull($_POST['end_date'] ?? '');
    if ($start === false) {
        $v->addError('start_date', MSG_DISCOUNT_DATES);
    }
    if ($end === false) {
        $v->addError('end_date', MSG_DISCOUNT_DATES);
    }
    if ($start !== false && $end !== false) {
        $values['start_date'] = $start;
        $values['end_date'] = $end;
        $endChanged = $existing === null || (string)($existing['end_date'] ?? '') !== (string)($end ?? '');
        if ($start !== null && $end !== null && $start > $end) {
            $v->addError('end_date', MSG_DISCOUNT_DATES . ' The end date must be on or after the start date.');
        } elseif ($end !== null && $endChanged && $end < todayDate()) {
            $v->addError('end_date', MSG_DISCOUNT_DATES . ' The end date cannot be in the past.');
        }
    }

    if ($existing !== null && $values['usage_limit'] !== null && $values['usage_limit'] < (int)$existing['times_used']) {
        $v->addError('usage_limit', 'Usage limit cannot be lower than the number of times this discount has already been used (' . (int)$existing['times_used'] . ').');
    }

    if (!$v->hasError('name') && $values['name'] !== '') {
        $dup = $db->prepare("SELECT id FROM discounts WHERE LOWER(name) = LOWER(?) AND id <> ?");
        $dup->execute([$values['name'], $existing['id'] ?? 0]);
        if ($dup->fetch()) {
            $v->addError('name', 'A discount with this name already exists.');
        }
    }

    return [$values, $v->errors()];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = new Validator($_POST);
    $action = $v->choice('action', ['add', 'edit', 'toggle', 'delete'], ['message' => MSG_INVALID_OPERATION]);
    $back = discountsReturnUrl($_POST['return_to'] ?? '');
    if ($action === null) {
        abortRequest(400, MSG_INVALID_OPERATION, $back);
    }
    $adminId = (int)$_SESSION['user_id'];

    if ($action === 'add') {
        [$values, $addErrors] = validateDiscountInput($db, $v);
        if ($addErrors) {
            $addOld = $_POST;
            $error = 'Discount not added. ' . MSG_FORM_ERRORS;
            http_response_code(422);
        } elseif (!consumeFormToken('discount_add', $_POST['form_token'] ?? '')) {
            setFlash('error', MSG_DUPLICATE . ' The discount list below already includes it if it was saved.');
            redirectTo('discounts.php');
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO discounts (name, description, discount_type, discount_value, min_purchase, min_completed_orders, usage_limit, start_date, end_date, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$values['name'], $values['description'] !== '' ? $values['description'] : null, $values['discount_type'], $values['discount_value'], $values['min_purchase'], $values['min_completed_orders'], $values['usage_limit'], $values['start_date'], $values['end_date'], (int)$values['is_active']]);
                logActivity($adminId, 'DISCOUNT_CREATED', 'Created discount "' . $values['name'] . '" (' . discountValueLabel($values) . ')');
                setFlash('success', 'Discount "' . $values['name'] . '" added successfully!');
                redirectTo('discounts.php');
            } catch (Exception $e) {
                $addOld = $_POST;
                $error = 'Discount not added. ' . safeErrorMessage($e, 'Discount insert failed');
                http_response_code(((string)$e->getCode() === '23000') ? 409 : 500);
                if ((string)$e->getCode() === '23000') {
                    $addErrors['name'] = 'A discount with this name already exists.';
                    $error = 'Discount not added. ' . MSG_FORM_ERRORS;
                }
            }
        }
    } else {
        $id = $v->id('id');
        if ($id === null) {
            abortRequest(400, MSG_INVALID_OPERATION, $back);
        }
        $existing = fetchDiscountWithStatus($id);
        if (!$existing) {
            abortRequest(404, 'This discount no longer exists.', $back);
        }

        if ($action === 'edit') {
            [$values, $editErrors] = validateDiscountInput($db, $v, $existing);
            if ($editErrors) {
                $editOld = array_merge($_POST, ['id' => $id, 'times_used' => (int)$existing['times_used']]);
                $error = 'Discount not updated. ' . MSG_FORM_ERRORS;
                http_response_code(422);
            } else {
                try {
                    $stmt = $db->prepare("UPDATE discounts SET name = ?, description = ?, discount_type = ?, discount_value = ?, min_purchase = ?, min_completed_orders = ?, usage_limit = ?, start_date = ?, end_date = ?, is_active = ? WHERE id = ?");
                    $stmt->execute([$values['name'], $values['description'] !== '' ? $values['description'] : null, $values['discount_type'], $values['discount_value'], $values['min_purchase'], $values['min_completed_orders'], $values['usage_limit'], $values['start_date'], $values['end_date'], (int)$values['is_active'], $id]);
                    logActivity($adminId, 'DISCOUNT_UPDATED', 'Updated discount ID ' . $id . ': "' . $values['name'] . '" (' . discountValueLabel($values) . ')');
                    setFlash('success', 'Discount "' . $values['name'] . '" updated successfully! Changes apply to new orders only.');
                    redirectTo($back);
                } catch (Exception $e) {
                    $editOld = array_merge($_POST, ['id' => $id, 'times_used' => (int)$existing['times_used']]);
                    $error = 'Discount not updated. ' . safeErrorMessage($e, 'Discount update failed');
                    http_response_code(500);
                    if ((string)$e->getCode() === '23000') {
                        $editErrors['name'] = 'A discount with this name already exists.';
                        $error = 'Discount not updated. ' . MSG_FORM_ERRORS;
                        http_response_code(409);
                    }
                }
            }
        }

        if ($action === 'toggle') {
            $activate = (int)$existing['is_active'] === 0;
            if ($activate && !empty($existing['end_date']) && $existing['end_date'] < todayDate()) {
                abortRequest(409, MSG_DISCOUNT_EXPIRED . ' Edit its end date to activate it again.', $back);
            }
            try {
                $db->prepare("UPDATE discounts SET is_active = ? WHERE id = ?")->execute([$activate ? 1 : 0, $id]);
                logActivity($adminId, 'DISCOUNT_STATUS_CHANGED', ($activate ? 'Activated' : 'Deactivated') . ' discount ID ' . $id . ': "' . $existing['name'] . '"');
                setFlash('success', 'Discount "' . $existing['name'] . '" ' . ($activate ? 'activated. Eligible customers can now use it at checkout.' : 'deactivated. It will no longer be offered at checkout.'));
            } catch (Exception $e) {
                setFlash('error', 'Discount status not changed. ' . safeErrorMessage($e, 'Discount toggle failed'));
            }
            redirectTo($back);
        }

        if ($action === 'delete') {
            try {
                $used = 0;
                if (ordersHaveDiscountColumn()) {
                    $check = $db->prepare("SELECT COUNT(*) FROM orders WHERE discount_id = ?");
                    $check->execute([$id]);
                    $used = (int)$check->fetchColumn();
                }
                if ($used > 0) {
                    abortRequest(409, 'This discount has been used in ' . $used . ' order' . ($used === 1 ? '' : 's') . ' and cannot be deleted. Deactivate it instead.', $back);
                }
                $db->prepare("DELETE FROM discounts WHERE id = ?")->execute([$id]);
                logActivity($adminId, 'DISCOUNT_DELETED', 'Deleted discount ID ' . $id . ': "' . $existing['name'] . '"');
                setFlash('success', 'Discount "' . $existing['name'] . '" deleted successfully!');
            } catch (Exception $e) {
                setFlash('error', 'Discount not deleted. ' . safeErrorMessage($e, 'Discount delete failed'));
            }
            redirectTo($back);
        }
    }
}

$discountAddToken = generateFormToken('discount_add');

$filterNotice = '';
$filters = ['q' => '', 'status' => '', 'type' => ''];
$rawQ = $_GET['q'] ?? '';
if (is_string($rawQ)) {
    $filters['q'] = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $rawQ));
    if (!mb_check_encoding($filters['q'], 'UTF-8')) {
        $filters['q'] = '';
        $filterNotice = 'The search keyword could not be processed. Please try a different keyword.';
    } elseif (mb_strlen($filters['q'], 'UTF-8') > 100) {
        $filters['q'] = mb_substr($filters['q'], 0, 100, 'UTF-8');
        $filterNotice = 'Search keywords are limited to 100 characters, so your search was shortened.';
    }
}
$rawStatus = $_GET['status'] ?? '';
if (is_string($rawStatus) && $rawStatus !== '') {
    if (in_array($rawStatus, $discountStatusFilters, true)) {
        $filters['status'] = $rawStatus;
    } else {
        $filterNotice = 'The selected status is not valid, so all statuses are shown.';
    }
}
$rawType = $_GET['type'] ?? '';
if (is_string($rawType) && $rawType !== '') {
    if (in_array($rawType, DISCOUNT_TYPES, true)) {
        $filters['type'] = $rawType;
    } else {
        $filterNotice = 'The selected discount type is not valid, so all types are shown.';
    }
}

$baseSql = "SELECT d.*, " . discountStatusSql('d') . " AS computed_status, " . discountUsageSql('d') . " AS times_used, "
    . (ordersHaveDiscountColumn() ? "(SELECT COALESCE(SUM(o.discount_amount), 0) FROM orders o WHERE o.discount_id = d.id AND o.status <> 'cancelled')" : "0")
    . " AS total_discounted FROM discounts d";
$where = [];
$params = [];
if ($filters['q'] !== '') {
    $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
    $where[] = '(x.name LIKE ? OR x.description LIKE ?)';
    array_push($params, $like, $like);
}
if ($filters['status'] !== '') {
    $where[] = 'x.computed_status = ?';
    $params[] = $filters['status'];
}
if ($filters['type'] !== '') {
    $where[] = 'x.discount_type = ?';
    $params[] = $filters['type'];
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM ($baseSql) x $whereSql");
$countStmt->execute($params);
$totalDiscounts = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalDiscounts / ADMIN_PAGE_SIZE));
$rawPage = $_GET['page'] ?? '1';
$currentPage = (is_string($rawPage) && ctype_digit($rawPage) && (int)$rawPage >= 1) ? (int)$rawPage : 1;
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * ADMIN_PAGE_SIZE;

$listStmt = $db->prepare("SELECT * FROM ($baseSql) x $whereSql ORDER BY x.created_at DESC, x.id DESC LIMIT " . ADMIN_PAGE_SIZE . " OFFSET " . (int)$offset);
$listStmt->execute($params);
$discounts = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$statusCounts = array_fill_keys($discountStatusFilters, 0);
foreach ($db->query("SELECT x.computed_status AS s, COUNT(*) AS c FROM ($baseSql) x GROUP BY x.computed_status")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($statusCounts[$row['s']])) {
        $statusCounts[$row['s']] = (int)$row['c'];
    }
}
$anyDiscounts = array_sum($statusCounts) > 0;
$activeFilters = array_filter($filters, fn($value) => $value !== '');
$returnQuery = http_build_query(array_merge($activeFilters, $currentPage > 1 ? ['page' => $currentPage] : []));

$statusStyles = [
    'active' => ['rgba(220, 252, 231, 0.9)', '#15803d', 'rgba(74, 222, 128, 0.6)'],
    'scheduled' => ['rgba(224, 242, 254, 0.9)', '#0369a1', 'rgba(56, 189, 248, 0.6)'],
    'expired' => ['rgba(254, 226, 226, 0.9)', '#991b1b', 'rgba(248, 113, 113, 0.6)'],
    'inactive' => ['rgba(229, 231, 235, 0.9)', '#374151', 'rgba(156, 163, 175, 0.6)'],
    'used_up' => ['rgba(254, 243, 199, 0.9)', '#92400e', 'rgba(251, 191, 36, 0.6)'],
];

function discountFormRow(array $d) {
    foreach ($d as $key => $value) {
        if (!is_scalar($value) && $value !== null) {
            $d[$key] = '';
        }
    }
    return [
        'id' => (int)$d['id'],
        'name' => (string)($d['name'] ?? ''),
        'description' => (string)($d['description'] ?? ''),
        'discount_type' => (string)($d['discount_type'] ?? 'percentage'),
        'discount_value' => (string)($d['discount_value'] ?? ''),
        'min_purchase' => (string)($d['min_purchase'] ?? '0'),
        'min_completed_orders' => (string)($d['min_completed_orders'] ?? '0'),
        'usage_limit' => (string)($d['usage_limit'] ?? ''),
        'start_date' => (string)($d['start_date'] ?? ''),
        'end_date' => (string)($d['end_date'] ?? ''),
        'is_active' => (string)($d['is_active'] ?? '1'),
        'times_used' => (int)($d['times_used'] ?? 0),
    ];
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body {
        background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
        color: #1f2937 !important;
        font-family: 'Outfit', sans-serif;
        min-height: 100vh;
    }

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

    .white-glow-heading {
        color: #ffffff !important;
        text-shadow: 0 0 10px rgba(255, 255, 255, 0.8), 0 0 20px rgba(134, 239, 172, 0.6), 0 2px 4px rgba(0,0,0,0.3);
    }

    .neon-glow-text {
        color: #064e3b !important;
        text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
    }

    .text-muted-green {
        color: #166534 !important;
        font-weight: 500;
    }

    .white-subtext {
        color: rgba(255, 255, 255, 0.9) !important;
        font-weight: 500;
        text-shadow: 0 1px 3px rgba(0,0,0,0.3);
    }

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

    .status-pill {
        display: inline-block;
        padding: 0.25rem 0.6rem;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 700;
        white-space: nowrap;
    }
    .form-label {
        display: block;
        font-size: 0.85rem;
        font-weight: 700;
        margin-bottom: 0.4rem;
        color: #1f2937;
    }
    .filter-label {
        display: block;
        font-size: 0.78rem;
        font-weight: 700;
        margin-bottom: 0.3rem;
        color: #1f2937;
    }
    .form-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    .btn-action-toggle-on { background: rgba(220, 252, 231, 0.9); color: #15803d; border: 1px solid rgba(74, 222, 128, 0.6); }
    .btn-action-toggle-off { background: rgba(229, 231, 235, 0.9); color: #374151; border: 1px solid rgba(156, 163, 175, 0.6); }
    .btn-action-toggle-on, .btn-action-toggle-off {
        padding: 0.35rem 0.6rem;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
    }
    @media (max-width: 560px) {
        .form-grid-2 { grid-template-columns: 1fr; }
    }
</style>

<div class="container" style="padding: 2rem 1rem; max-width: 1400px; margin: 0 auto;">

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2 class="white-glow-heading" style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.025em; margin-bottom: 0.25rem;">
                <i class="fa-solid fa-tags" style="color: #86efac;"></i> Discount Management
            </h2>
            <p class="white-subtext" style="font-size: 0.9rem;">Create and manage the discounts customers can use at checkout</p>
        </div>
        <button type="button" onclick="openAddModal()" class="btn-green-glow" style="padding: 0.75rem 1.5rem; border-radius: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 0.5rem;">
            <i class="fa-solid fa-plus"></i> Add Discount
        </button>
    </div>

    <?= alertBox('success', $message) ?>
    <?= alertBox('error', $error) ?>
    <?= alertBox('info', $filterNotice) ?>

    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.25rem;">
        <?php foreach ($statusCounts as $statusKey => $count): ?>
            <?php [$bg, $fg, $bd] = $statusStyles[$statusKey]; ?>
            <a href="discounts.php?status=<?= urlencode($statusKey) ?>" class="smiski-glass-card" style="padding: 0.75rem 1.1rem; text-decoration: none; display: flex; align-items: center; gap: 0.6rem; <?= $filters['status'] === $statusKey ? 'border-color: #15803d !important; box-shadow: 0 0 0 2px #15803d !important;' : '' ?>">
                <span class="status-pill" style="background: <?= $bg ?>; color: <?= $fg ?>; border: 1px solid <?= $bd ?>;"><?= htmlspecialchars(discountStatusLabel($statusKey)) ?></span>
                <strong style="color: #064e3b; font-size: 1.1rem;"><?= $count ?></strong>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="smiski-glass-card" style="padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;">
        <form method="GET" action="discounts.php" data-validate data-no-lock style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.85rem; align-items: end;">
            <div style="grid-column: span 2; min-width: 0;">
                <label class="filter-label" for="filter_q">Search</label>
                <input type="text" id="filter_q" name="q" maxlength="100" value="<?= htmlspecialchars($filters['q']) ?>" placeholder="Discount name or description" class="smiski-input">
            </div>
            <div>
                <label class="filter-label" for="filter_status">Status</label>
                <select id="filter_status" name="status" class="smiski-select">
                    <option value="">All statuses</option>
                    <?php foreach ($discountStatusFilters as $statusKey): ?>
                        <option value="<?= $statusKey ?>" <?= $filters['status'] === $statusKey ? 'selected' : '' ?>><?= htmlspecialchars(discountStatusLabel($statusKey)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="filter-label" for="filter_type">Type</label>
                <select id="filter_type" name="type" class="smiski-select">
                    <option value="">All types</option>
                    <option value="percentage" <?= $filters['type'] === 'percentage' ? 'selected' : '' ?>>Percentage</option>
                    <option value="fixed" <?= $filters['type'] === 'fixed' ? 'selected' : '' ?>>Fixed amount</option>
                </select>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="btn-green-glow" style="flex: 1; padding: 0.7rem 1rem; border-radius: 12px; font-weight: 700;"><i class="fa-solid fa-filter"></i> Filter</button>
                <a href="discounts.php" class="btn-action-view" style="display: inline-flex; align-items: center; justify-content: center; padding: 0.7rem 0.9rem; text-decoration: none;" title="Clear filters"><i class="fa-solid fa-xmark"></i></a>
            </div>
        </form>
    </div>

    <div class="smiski-glass-card" style="overflow: hidden;">
        <div style="padding: 0.9rem 1.25rem; border-bottom: 1px solid rgba(74, 222, 128, 0.3); font-size: 0.85rem; font-weight: 600; color: #166534;">
            <?= $totalDiscounts ?> discount<?= $totalDiscounts === 1 ? '' : 's' ?> found<?= $totalPages > 1 ? ' · Page ' . $currentPage . ' of ' . $totalPages : '' ?>
        </div>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; min-width: 1000px;">
                <thead>
                    <tr style="background: rgba(220, 252, 231, 0.5); color: #064e3b; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid rgba(74, 222, 128, 0.4);">
                        <th style="padding: 0.9rem 1rem;">Discount</th>
                        <th style="padding: 0.9rem 1rem;">Value</th>
                        <th style="padding: 0.9rem 1rem;">Requirements</th>
                        <th style="padding: 0.9rem 1rem;">Valid Period</th>
                        <th style="padding: 0.9rem 1rem;">Usage</th>
                        <th style="padding: 0.9rem 1rem;">Status</th>
                        <th style="padding: 0.9rem 1rem; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($discounts)): ?>
                        <tr><td colspan="7" class="text-muted-green" style="text-align: center; padding: 3rem; font-size: 0.95rem;">
                            <i class="fa-solid fa-tag" style="font-size: 1.6rem; display: block; margin-bottom: 0.5rem;"></i>
                            <?= $anyDiscounts ? 'No discounts match your filters.' : 'No discounts yet. Click "Add Discount" to create one.' ?>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($discounts as $d): ?>
                            <?php
                                $status = $d['computed_status'];
                                [$bg, $fg, $bd] = $statusStyles[$status] ?? $statusStyles['inactive'];
                                $requirements = [];
                                if ((float)$d['min_purchase'] > 0) {
                                    $requirements[] = 'Min. purchase ₱' . number_format((float)$d['min_purchase'], 2);
                                }
                                if ((int)$d['min_completed_orders'] > 0) {
                                    $requirements[] = (int)$d['min_completed_orders'] . '+ completed order' . ((int)$d['min_completed_orders'] === 1 ? '' : 's');
                                }
                                $period = ($d['start_date'] ? date('M j, Y', strtotime($d['start_date'])) : 'Any time') . ' – ' . ($d['end_date'] ? date('M j, Y', strtotime($d['end_date'])) : 'No end date');
                                $usage = (int)$d['times_used'] . ' / ' . ($d['usage_limit'] !== null ? (int)$d['usage_limit'] : '∞');
                                $view = [
                                    'name' => $d['name'],
                                    'description' => $d['description'] ?: 'No description provided.',
                                    'value' => discountValueLabel($d),
                                    'type' => $d['discount_type'] === 'percentage' ? 'Percentage' : 'Fixed amount',
                                    'requirements' => $requirements ? implode(', ', $requirements) : 'None',
                                    'period' => $period,
                                    'usage' => $usage . ' order' . ((int)$d['times_used'] === 1 ? '' : 's'),
                                    'total' => '₱' . number_format((float)$d['total_discounted'], 2),
                                    'status' => discountStatusLabel($status),
                                    'created' => date('M j, Y g:i A', strtotime($d['created_at'])),
                                    'updated' => date('M j, Y g:i A', strtotime($d['updated_at'] ?: $d['created_at'])),
                                ];
                            ?>
                            <tr style="border-bottom: 1px solid rgba(74, 222, 128, 0.2);" onmouseenter="this.style.background='rgba(220, 252, 231, 0.4)'" onmouseleave="this.style.background='transparent'">
                                <td style="padding: 0.85rem 1rem; max-width: 280px;">
                                    <div style="font-weight: 700; color: #064e3b; font-size: 0.9rem;"><?= htmlspecialchars($d['name']) ?></div>
                                    <?php if (!empty($d['description'])): ?>
                                        <div class="text-muted-green" style="font-size: 0.75rem; margin-top: 0.15rem;"><?= htmlspecialchars(mb_strlen($d['description'], 'UTF-8') > 80 ? mb_substr($d['description'], 0, 80, 'UTF-8') . '…' : $d['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.85rem 1rem; font-weight: 700; color: #15803d; font-size: 0.9rem; white-space: nowrap;"><?= htmlspecialchars(discountValueLabel($d)) ?></td>
                                <td style="padding: 0.85rem 1rem; font-size: 0.8rem; color: #374151;"><?= htmlspecialchars($requirements ? implode(' · ', $requirements) : 'None') ?></td>
                                <td class="text-muted-green" style="padding: 0.85rem 1rem; font-size: 0.8rem; white-space: nowrap;"><?= htmlspecialchars($period) ?></td>
                                <td style="padding: 0.85rem 1rem; font-size: 0.85rem; font-weight: 600; color: #1f2937; white-space: nowrap;"><?= htmlspecialchars($usage) ?></td>
                                <td style="padding: 0.85rem 1rem;"><span class="status-pill" style="background: <?= $bg ?>; color: <?= $fg ?>; border: 1px solid <?= $bd ?>;"><?= htmlspecialchars(discountStatusLabel($status)) ?></span></td>
                                <td style="padding: 0.85rem 1rem;">
                                    <div style="display: flex; gap: 0.35rem; flex-wrap: wrap; justify-content: center;">
                                        <button type="button" class="btn-action-view" onclick='openViewModal(<?= jsonAttr($view) ?>)' title="View discount"><i class="fa-solid fa-eye"></i></button>
                                        <button type="button" class="btn-action-edit" onclick='openEditModal(<?= jsonAttr(discountFormRow($d)) ?>)' title="Edit discount"><i class="fa-solid fa-pen"></i></button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm(<?= htmlspecialchars(json_encode((int)$d['is_active'] === 1 ? 'Deactivate this discount? Customers will no longer be able to use it.' : 'Activate this discount? Eligible customers will be able to use it at checkout.'), ENT_QUOTES) ?>)">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                            <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnQuery) ?>">
                                            <?php if ((int)$d['is_active'] === 1): ?>
                                                <button type="submit" class="btn-action-toggle-off" title="Deactivate discount"><i class="fa-solid fa-toggle-off"></i> Deactivate</button>
                                            <?php else: ?>
                                                <button type="submit" class="btn-action-toggle-on" title="Activate discount"><i class="fa-solid fa-toggle-on"></i> Activate</button>
                                            <?php endif; ?>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this discount permanently? Discounts that were already used in orders cannot be deleted.')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                            <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnQuery) ?>">
                                            <button type="submit" class="btn-action-delete" title="Delete discount"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= renderPagination($currentPage, $totalPages, $activeFilters, 'discounts.php') ?>
    </div>
</div>

<?php
$modalForms = [
    'add' => ['title' => 'Add New Discount', 'button' => 'Add Discount', 'errors' => $addErrors, 'prefix' => 'add'],
    'edit' => ['title' => 'Edit Discount', 'button' => 'Save Changes', 'errors' => $editErrors, 'prefix' => 'edit'],
];
foreach ($modalForms as $mode => $cfg):
    $errs = $cfg['errors'];
    $px = $cfg['prefix'];
    $old = $mode === 'add' ? ($addOld ?? []) : [];
?>
<div id="<?= $mode ?>Modal" role="dialog" aria-modal="true" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(5, 46, 22, 0.85); backdrop-filter: blur(10px); align-items: center; justify-content: center; z-index: 2000; padding: 1rem;">
    <div class="smiski-glass-card" style="padding: 2rem; width: 100%; max-width: 560px; position: relative; max-height: 90vh; overflow-y: auto; color: #1f2937;">
        <button type="button" onclick="closeModal('<?= $mode ?>Modal')" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #15803d;" onmouseover="this.style.color='#064e3b'" onmouseout="this.style.color='#15803d'">&times;</button>
        <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem;"><?= $cfg['title'] ?></h3>

        <form method="POST" id="<?= $mode ?>DiscountForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $mode ?>">
            <?php if ($mode === 'add'): ?>
                <input type="hidden" name="form_token" value="<?= htmlspecialchars($discountAddToken) ?>">
            <?php else: ?>
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnQuery) ?>">
                <p id="edit_usage_note" class="field-hint" style="margin: -0.5rem 0 1rem; font-size: 0.8rem; color: #166534;"></p>
            <?php endif; ?>

            <div style="margin-bottom: 1rem;">
                <label class="form-label" for="<?= $px ?>_name">Discount Name</label>
                <input type="text" name="name" id="<?= $px ?>_name" required minlength="2" maxlength="100" value="<?= oldValue($old, 'name') ?>" class="smiski-input<?= invalidClass($errs, 'name') ?>"<?= invalidAttr($errs, 'name') ?>>
                <?= fieldError($errs, 'name') ?>
            </div>

            <div style="margin-bottom: 1rem;">
                <label class="form-label" for="<?= $px ?>_description">Description <span class="text-muted-green" style="font-weight: 500;">(optional)</span></label>
                <textarea name="description" id="<?= $px ?>_description" rows="2" maxlength="500" data-counter class="smiski-textarea<?= invalidClass($errs, 'description') ?>"<?= invalidAttr($errs, 'description') ?>><?= oldValue($old, 'description') ?></textarea>
                <?= fieldError($errs, 'description') ?>
            </div>

            <div class="form-grid-2">
                <div>
                    <label class="form-label" for="<?= $px ?>_discount_type">Discount Type</label>
                    <select name="discount_type" id="<?= $px ?>_discount_type" required onchange="syncDiscountType('<?= $px ?>')" class="smiski-select<?= invalidClass($errs, 'discount_type') ?>">
                        <option value="percentage" <?= ($old['discount_type'] ?? 'percentage') === 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                        <option value="fixed" <?= ($old['discount_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed amount (₱)</option>
                    </select>
                    <?= fieldError($errs, 'discount_type') ?>
                </div>
                <div>
                    <label class="form-label" for="<?= $px ?>_discount_value" id="<?= $px ?>_value_label">Discount Value</label>
                    <input type="number" step="0.01" name="discount_value" id="<?= $px ?>_discount_value" required min="1" max="100" data-msg-number="<?= htmlspecialchars(MSG_DISCOUNT_VALUE) ?>" data-msg-min="<?= htmlspecialchars(MSG_DISCOUNT_PERCENT) ?>" data-msg-max="<?= htmlspecialchars(MSG_DISCOUNT_PERCENT) ?>" value="<?= oldValue($old, 'discount_value') ?>" class="smiski-input<?= invalidClass($errs, 'discount_value') ?>"<?= invalidAttr($errs, 'discount_value') ?>>
                    <?= fieldError($errs, 'discount_value') ?>
                </div>
            </div>

            <div class="form-grid-2">
                <div>
                    <label class="form-label" for="<?= $px ?>_min_purchase">Minimum Purchase (₱)</label>
                    <input type="number" step="0.01" min="0" max="1000000" name="min_purchase" id="<?= $px ?>_min_purchase" data-msg-min="Minimum purchase cannot be negative." data-msg-max="Minimum purchase must not exceed ₱1,000,000.00." value="<?= oldValue($old, 'min_purchase', '0') ?>" class="smiski-input<?= invalidClass($errs, 'min_purchase') ?>"<?= invalidAttr($errs, 'min_purchase') ?>>
                    <?= fieldError($errs, 'min_purchase') ?>
                </div>
                <div>
                    <label class="form-label" for="<?= $px ?>_min_completed_orders">Min. Completed Orders</label>
                    <input type="number" step="1" min="0" max="1000" name="min_completed_orders" id="<?= $px ?>_min_completed_orders" data-msg-number="Please enter a whole number of completed orders." data-msg-min="Completed orders cannot be negative." data-msg-max="Completed orders must not exceed 1,000." value="<?= oldValue($old, 'min_completed_orders', '0') ?>" class="smiski-input<?= invalidClass($errs, 'min_completed_orders') ?>"<?= invalidAttr($errs, 'min_completed_orders') ?>>
                    <?= fieldError($errs, 'min_completed_orders') ?>
                </div>
            </div>

            <div class="form-grid-2">
                <div>
                    <label class="form-label" for="<?= $px ?>_start_date">Start Date <span class="text-muted-green" style="font-weight: 500;">(optional)</span></label>
                    <input type="date" name="start_date" id="<?= $px ?>_start_date" value="<?= oldValue($old, 'start_date') ?>" class="smiski-input<?= invalidClass($errs, 'start_date') ?>"<?= invalidAttr($errs, 'start_date') ?>>
                    <?= fieldError($errs, 'start_date') ?>
                </div>
                <div>
                    <label class="form-label" for="<?= $px ?>_end_date">End Date <span class="text-muted-green" style="font-weight: 500;">(optional)</span></label>
                    <input type="date" name="end_date" id="<?= $px ?>_end_date" data-after="#<?= $px ?>_start_date" data-msg-after="<?= htmlspecialchars(MSG_DISCOUNT_DATES . ' The end date must be on or after the start date.') ?>" value="<?= oldValue($old, 'end_date') ?>" class="smiski-input<?= invalidClass($errs, 'end_date') ?>"<?= invalidAttr($errs, 'end_date') ?>>
                    <?= fieldError($errs, 'end_date') ?>
                </div>
            </div>

            <div class="form-grid-2" style="margin-bottom: 1.5rem;">
                <div>
                    <label class="form-label" for="<?= $px ?>_usage_limit">Usage Limit <span class="text-muted-green" style="font-weight: 500;">(optional)</span></label>
                    <input type="number" step="1" min="1" max="1000000" name="usage_limit" id="<?= $px ?>_usage_limit" placeholder="Unlimited" data-msg-number="Please enter a whole number for the usage limit." data-msg-min="Usage limit must be at least 1, or leave it empty for unlimited use." data-msg-max="Usage limit must not exceed 1,000,000." value="<?= oldValue($old, 'usage_limit') ?>" class="smiski-input<?= invalidClass($errs, 'usage_limit') ?>"<?= invalidAttr($errs, 'usage_limit') ?>>
                    <?= fieldError($errs, 'usage_limit') ?>
                </div>
                <div>
                    <label class="form-label" for="<?= $px ?>_is_active">Status</label>
                    <select name="is_active" id="<?= $px ?>_is_active" required class="smiski-select<?= invalidClass($errs, 'is_active') ?>">
                        <option value="1" <?= ($old['is_active'] ?? '1') === '1' ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= ($old['is_active'] ?? '') === '0' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <?= fieldError($errs, 'is_active') ?>
                </div>
            </div>

            <p class="text-muted-green" style="font-size: 0.75rem; margin-bottom: 1rem;">Only one discount can be used per order. Changes apply to new orders only; existing orders keep their original discount amounts.</p>

            <button type="submit" class="btn-green-glow" style="width: 100%; padding: 0.75rem; font-size: 0.95rem; font-weight: 700; border-radius: 12px;"><?= $cfg['button'] ?></button>
        </form>
    </div>
</div>
<?php endforeach; ?>

<div id="viewModal" role="dialog" aria-modal="true" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(5, 46, 22, 0.85); backdrop-filter: blur(10px); align-items: center; justify-content: center; z-index: 2000; padding: 1rem;">
    <div class="smiski-glass-card" style="padding: 2rem; width: 100%; max-width: 520px; position: relative; max-height: 90vh; overflow-y: auto; color: #1f2937;">
        <button type="button" onclick="closeModal('viewModal')" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #15803d;" onmouseover="this.style.color='#064e3b'" onmouseout="this.style.color='#15803d'">&times;</button>
        <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem;">Discount Details</h3>
        <div style="display: flex; flex-direction: column; gap: 0.7rem; font-size: 0.9rem; color: #374151;">
            <div><strong style="color: #1f2937;">Name:</strong> <span id="view_name" style="font-weight: 700; color: #064e3b;"></span></div>
            <div><strong style="color: #1f2937;">Type:</strong> <span id="view_type"></span></div>
            <div><strong style="color: #1f2937;">Value:</strong> <span id="view_value" style="font-weight: 700; color: #15803d;"></span></div>
            <div><strong style="color: #1f2937;">Requirements:</strong> <span id="view_requirements"></span></div>
            <div><strong style="color: #1f2937;">Valid Period:</strong> <span id="view_period"></span></div>
            <div><strong style="color: #1f2937;">Times Used:</strong> <span id="view_usage"></span></div>
            <div><strong style="color: #1f2937;">Total Discount Given:</strong> <span id="view_total"></span></div>
            <div><strong style="color: #1f2937;">Status:</strong> <span id="view_status" style="font-weight: 700;"></span></div>
            <div><strong style="color: #1f2937;">Created:</strong> <span id="view_created"></span></div>
            <div><strong style="color: #1f2937;">Last Updated:</strong> <span id="view_updated"></span></div>
            <div><strong style="color: #1f2937;">Description:</strong> <p id="view_description" style="margin-top: 0.4rem; background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(74, 222, 128, 0.4); padding: 0.8rem; border-radius: 12px; color: #1f2937; white-space: pre-wrap;"></p></div>
        </div>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
function openAddModal() { syncDiscountType('add'); openModal('addModal'); }

function syncDiscountType(px) {
    var type = document.getElementById(px + '_discount_type').value;
    var input = document.getElementById(px + '_discount_value');
    var label = document.getElementById(px + '_value_label');
    if (type === 'percentage') {
        input.min = '1';
        input.max = '100';
        input.step = '0.01';
        input.dataset.msgMin = <?= json_encode(MSG_DISCOUNT_PERCENT) ?>;
        input.dataset.msgMax = <?= json_encode(MSG_DISCOUNT_PERCENT) ?>;
        label.innerText = 'Discount Value (%)';
    } else {
        input.min = '0.01';
        input.max = '1000000';
        input.step = '0.01';
        input.dataset.msgMin = <?= json_encode(MSG_DISCOUNT_VALUE) ?>;
        input.dataset.msgMax = <?= json_encode(MSG_DISCOUNT_VALUE) ?>;
        label.innerText = 'Discount Value (₱)';
    }
}

function openEditModal(d, keepErrors) {
    var form = document.getElementById('editDiscountForm');
    if (!keepErrors && window.FormValidation) FormValidation.clearErrors(form);
    document.getElementById('edit_id').value = d.id;
    ['name', 'description', 'discount_type', 'discount_value', 'min_purchase', 'min_completed_orders', 'usage_limit', 'start_date', 'end_date', 'is_active'].forEach(function (key) {
        var el = document.getElementById('edit_' + key);
        if (el) el.value = d[key] == null ? '' : d[key];
    });
    var used = parseInt(d.times_used, 10) || 0;
    document.getElementById('edit_usage_note').innerText = used > 0
        ? 'This discount has been used in ' + used + ' order' + (used === 1 ? '' : 's') + '. Those orders keep their original discount amounts.'
        : 'This discount has not been used yet.';
    document.getElementById('edit_usage_limit').min = Math.max(1, used);
    syncDiscountType('edit');
    openModal('editModal');
}

function openViewModal(d) {
    ['name', 'type', 'value', 'requirements', 'period', 'usage', 'total', 'status', 'created', 'updated', 'description'].forEach(function (key) {
        document.getElementById('view_' + key).innerText = d[key];
    });
    openModal('viewModal');
}

['addModal', 'editModal', 'viewModal'].forEach(function (id) {
    document.getElementById(id).addEventListener('click', function (e) { if (e.target === this) closeModal(id); });
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') ['addModal', 'editModal', 'viewModal'].forEach(closeModal);
});
document.addEventListener('DOMContentLoaded', function () { syncDiscountType('add'); });
<?php if ($addOld !== null): ?>
document.addEventListener('DOMContentLoaded', function () { openModal('addModal'); });
<?php endif; ?>
<?php if ($editOld !== null): ?>
document.addEventListener('DOMContentLoaded', function () {
    openEditModal(<?= json_encode(discountFormRow($editOld), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, true);
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
