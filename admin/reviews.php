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

$reviewStatusFilters = ['pending', 'approved', 'rejected', 'hidden', 'removed'];

function reviewsReturnUrl($raw) {
    if (is_string($raw) && $raw !== '' && strlen($raw) <= 500 && preg_match('/^[A-Za-z0-9_\-=&%.+\[\]]*$/', $raw)) {
        return 'reviews.php?' . $raw;
    }
    return 'reviews.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = new Validator($_POST);
    $action = $v->choice('action', ['approve', 'reject', 'hide', 'remove', 'restore'], ['message' => MSG_INVALID_OPERATION]);
    $id = $v->id('id');
    $back = reviewsReturnUrl($_POST['return_to'] ?? '');
    if ($action === null || $id === null) {
        abortRequest(400, MSG_INVALID_OPERATION, $back);
    }

    $statusFor = ['approve' => 'approved', 'reject' => 'rejected', 'hide' => 'hidden'];
    try {
        $db->beginTransaction();
        $find = $db->prepare("SELECT r.*, p.name AS product_name, u.full_name AS customer_name FROM product_reviews r INNER JOIN products p ON p.id = r.product_id INNER JOIN users u ON u.id = r.customer_id WHERE r.id = ? FOR UPDATE");
        $find->execute([$id]);
        $review = $find->fetch(PDO::FETCH_ASSOC);

        if (!$review) {
            $db->rollBack();
            abortRequest(404, 'This review no longer exists.', $back);
        }

        $adminId = (int)$_SESSION['user_id'];
        $label = 'review #' . $id . ' by ' . $review['customer_name'] . ' for "' . $review['product_name'] . '"';

        if (isset($statusFor[$action])) {
            $newStatus = $statusFor[$action];
            if (!empty($review['deleted_at'])) {
                $db->rollBack();
                abortRequest(409, 'This review has been removed. Restore it before changing its status.', $back);
            }
            if ($review['status'] === $newStatus) {
                $db->rollBack();
                setFlash('success', 'No changes made. The review is already ' . ucfirst($newStatus) . '.');
                redirectTo($back);
            }
            $db->prepare("UPDATE product_reviews SET status = ?, moderated_by = ?, moderated_at = NOW() WHERE id = ?")->execute([$newStatus, $adminId, $id]);
            $db->commit();
            logActivity($adminId, 'REVIEW_MODERATED', 'Changed ' . $label . ' from ' . $review['status'] . ' to ' . $newStatus);
            $note = $newStatus === 'approved' ? ' It is now visible on the product page.' : ' It is no longer visible on the product page.';
            setFlash('success', 'Review ' . ($newStatus === 'hidden' ? 'hidden' : $newStatus) . ' successfully.' . $note);
        } elseif ($action === 'remove') {
            if (!empty($review['deleted_at'])) {
                $db->rollBack();
                abortRequest(409, 'This review has already been removed.', $back);
            }
            $db->prepare("UPDATE product_reviews SET deleted_at = NOW(), moderated_by = ?, moderated_at = NOW() WHERE id = ?")->execute([$adminId, $id]);
            $db->commit();
            logActivity($adminId, 'REVIEW_REMOVED', 'Removed ' . $label);
            setFlash('success', 'Review removed. It is kept in the records and can be restored from the "Removed" filter.');
        } else {
            if (empty($review['deleted_at'])) {
                $db->rollBack();
                abortRequest(409, 'This review is not removed, so it cannot be restored.', $back);
            }
            $db->prepare("UPDATE product_reviews SET deleted_at = NULL, moderated_by = ?, moderated_at = NOW() WHERE id = ?")->execute([$adminId, $id]);
            $db->commit();
            logActivity($adminId, 'REVIEW_RESTORED', 'Restored ' . $label . ' with status ' . $review['status']);
            setFlash('success', 'Review restored with its previous status (' . ucfirst($review['status']) . ').');
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', 'The review was not updated. ' . safeErrorMessage($e, 'Review moderation failed'));
    }
    redirectTo($back);
}

$filterNotice = '';
$filters = ['q' => '', 'product' => '', 'rating' => '', 'status' => '', 'from' => '', 'to' => ''];

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

$products = $db->query("SELECT id, name FROM products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$productIds = array_map('intval', array_column($products, 'id'));

$rawProduct = $_GET['product'] ?? '';
if (is_string($rawProduct) && $rawProduct !== '') {
    if (ctype_digit($rawProduct) && in_array((int)$rawProduct, $productIds, true)) {
        $filters['product'] = $rawProduct;
    } else {
        $filterNotice = 'The selected product does not exist, so reviews for all products are shown.';
    }
}
$rawRating = $_GET['rating'] ?? '';
if (is_string($rawRating) && $rawRating !== '') {
    if (in_array($rawRating, ['1', '2', '3', '4', '5'], true)) {
        $filters['rating'] = $rawRating;
    } else {
        $filterNotice = MSG_RATING . ' The rating filter has been reset.';
    }
}
$rawStatus = $_GET['status'] ?? '';
if (is_string($rawStatus) && $rawStatus !== '') {
    if (in_array($rawStatus, $reviewStatusFilters, true)) {
        $filters['status'] = $rawStatus;
    } else {
        $filterNotice = 'The selected status is not valid, so all statuses are shown.';
    }
}
foreach (['from', 'to'] as $dateKey) {
    $date = validDateOrNull($_GET[$dateKey] ?? '');
    if ($date === false) {
        $filterNotice = 'Please enter valid dates for the date filter.';
    } elseif ($date !== null) {
        $filters[$dateKey] = $date;
    }
}
if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) {
    $filterNotice = 'The "From" date must be on or before the "To" date, so the date filter has been reset.';
    $filters['from'] = $filters['to'] = '';
}

$where = [];
$params = [];
if ($filters['status'] === 'removed') {
    $where[] = 'r.deleted_at IS NOT NULL';
} else {
    $where[] = 'r.deleted_at IS NULL';
    if ($filters['status'] !== '') {
        $where[] = 'r.status = ?';
        $params[] = $filters['status'];
    }
}
if ($filters['q'] !== '') {
    $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR p.name LIKE ? OR r.review_text LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
if ($filters['product'] !== '') {
    $where[] = 'r.product_id = ?';
    $params[] = (int)$filters['product'];
}
if ($filters['rating'] !== '') {
    $where[] = 'r.rating = ?';
    $params[] = (int)$filters['rating'];
}
if ($filters['from'] !== '') {
    $where[] = 'DATE(r.created_at) >= ?';
    $params[] = $filters['from'];
}
if ($filters['to'] !== '') {
    $where[] = 'DATE(r.created_at) <= ?';
    $params[] = $filters['to'];
}
$whereSql = 'WHERE ' . implode(' AND ', $where);
$fromSql = "FROM product_reviews r INNER JOIN users u ON u.id = r.customer_id INNER JOIN products p ON p.id = r.product_id";

$countStmt = $db->prepare("SELECT COUNT(*) $fromSql $whereSql");
$countStmt->execute($params);
$totalReviews = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalReviews / ADMIN_PAGE_SIZE));
$rawPage = $_GET['page'] ?? '1';
$currentPage = (is_string($rawPage) && ctype_digit($rawPage) && (int)$rawPage >= 1) ? (int)$rawPage : 1;
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * ADMIN_PAGE_SIZE;

$listStmt = $db->prepare("SELECT r.*, u.full_name AS customer_name, u.email AS customer_email, p.name AS product_name $fromSql $whereSql ORDER BY r.created_at DESC, r.id DESC LIMIT " . ADMIN_PAGE_SIZE . " OFFSET " . (int)$offset);
$listStmt->execute($params);
$reviews = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$statusCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'hidden' => 0, 'removed' => 0];
foreach ($db->query("SELECT CASE WHEN deleted_at IS NOT NULL THEN 'removed' ELSE status END AS s, COUNT(*) AS c FROM product_reviews GROUP BY CASE WHEN deleted_at IS NOT NULL THEN 'removed' ELSE status END")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($statusCounts[$row['s']])) {
        $statusCounts[$row['s']] = (int)$row['c'];
    }
}
$anyReviews = array_sum($statusCounts) > 0;
$activeFilters = array_filter($filters, fn($value) => $value !== '');
$returnQuery = http_build_query(array_merge($activeFilters, $currentPage > 1 ? ['page' => $currentPage] : []));

$statusStyles = [
    'pending' => ['rgba(254, 243, 199, 0.9)', '#92400e', 'rgba(251, 191, 36, 0.6)'],
    'approved' => ['rgba(220, 252, 231, 0.9)', '#15803d', 'rgba(74, 222, 128, 0.6)'],
    'rejected' => ['rgba(254, 226, 226, 0.9)', '#991b1b', 'rgba(248, 113, 113, 0.6)'],
    'hidden' => ['rgba(229, 231, 235, 0.9)', '#374151', 'rgba(156, 163, 175, 0.6)'],
    'removed' => ['rgba(254, 202, 202, 0.9)', '#7f1d1d', 'rgba(220, 38, 38, 0.6)'],
];

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
    .filter-label {
        display: block;
        font-size: 0.78rem;
        font-weight: 700;
        margin-bottom: 0.3rem;
        color: #1f2937;
    }
    .review-action {
        padding: 0.35rem 0.6rem;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
        cursor: pointer;
        border: 1px solid;
        white-space: nowrap;
    }
    .review-action-approve { background: rgba(220, 252, 231, 0.9); color: #15803d; border-color: rgba(74, 222, 128, 0.6); }
    .review-action-reject { background: rgba(254, 226, 226, 0.9); color: #991b1b; border-color: rgba(248, 113, 113, 0.6); }
    .review-action-hide { background: rgba(229, 231, 235, 0.9); color: #374151; border-color: rgba(156, 163, 175, 0.6); }
    .review-action-remove { background: rgba(254, 202, 202, 0.9); color: #7f1d1d; border-color: rgba(220, 38, 38, 0.6); }
    .review-action-restore { background: rgba(224, 242, 254, 0.9); color: #0369a1; border-color: rgba(56, 189, 248, 0.6); }
    .review-action:hover { filter: brightness(0.95); }
</style>

<div class="container" style="padding: 2rem 1rem; max-width: 1400px; margin: 0 auto;">

    <div style="margin-bottom: 1.5rem;">
        <h2 class="white-glow-heading" style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.025em; margin-bottom: 0.25rem;">
            <i class="fa-solid fa-star-half-stroke" style="color: #86efac;"></i> Product Review Management
        </h2>
        <p class="white-subtext" style="font-size: 0.9rem;">Moderate customer reviews before they appear on product pages</p>
    </div>

    <?= alertBox('success', $message) ?>
    <?= alertBox('error', $error) ?>
    <?= alertBox('info', $filterNotice) ?>

    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.25rem;">
        <?php foreach ($statusCounts as $statusKey => $count): ?>
            <?php [$bg, $fg, $bd] = $statusStyles[$statusKey]; ?>
            <a href="reviews.php?status=<?= urlencode($statusKey) ?>" class="smiski-glass-card" style="padding: 0.75rem 1.1rem; text-decoration: none; display: flex; align-items: center; gap: 0.6rem; <?= $filters['status'] === $statusKey ? 'border-color: #15803d !important; box-shadow: 0 0 0 2px #15803d !important;' : '' ?>">
                <span class="status-pill" style="background: <?= $bg ?>; color: <?= $fg ?>; border: 1px solid <?= $bd ?>;"><?= ucfirst($statusKey) ?></span>
                <strong style="color: #064e3b; font-size: 1.1rem;"><?= $count ?></strong>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="smiski-glass-card" style="padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;">
        <form method="GET" action="reviews.php" data-validate data-no-lock style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.85rem; align-items: end;">
            <div style="grid-column: span 2; min-width: 0;">
                <label class="filter-label" for="filter_q">Search</label>
                <input type="text" id="filter_q" name="q" maxlength="100" value="<?= htmlspecialchars($filters['q']) ?>" placeholder="Customer, email, product or review text" class="smiski-input">
            </div>
            <div>
                <label class="filter-label" for="filter_product">Product</label>
                <select id="filter_product" name="product" class="smiski-select">
                    <option value="">All products</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $filters['product'] === (string)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="filter-label" for="filter_rating">Rating</label>
                <select id="filter_rating" name="rating" class="smiski-select">
                    <option value="">All ratings</option>
                    <?php for ($r = 5; $r >= 1; $r--): ?>
                        <option value="<?= $r ?>" <?= $filters['rating'] === (string)$r ? 'selected' : '' ?>><?= $r ?> star<?= $r === 1 ? '' : 's' ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div>
                <label class="filter-label" for="filter_status">Status</label>
                <select id="filter_status" name="status" class="smiski-select">
                    <option value="">All (except removed)</option>
                    <?php foreach ($reviewStatusFilters as $statusKey): ?>
                        <option value="<?= $statusKey ?>" <?= $filters['status'] === $statusKey ? 'selected' : '' ?>><?= ucfirst($statusKey) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="filter-label" for="filter_from">From</label>
                <input type="date" id="filter_from" name="from" value="<?= htmlspecialchars($filters['from']) ?>" class="smiski-input">
            </div>
            <div>
                <label class="filter-label" for="filter_to">To</label>
                <input type="date" id="filter_to" name="to" value="<?= htmlspecialchars($filters['to']) ?>" class="smiski-input">
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="btn-green-glow" style="flex: 1; padding: 0.7rem 1rem; border-radius: 12px; font-weight: 700;"><i class="fa-solid fa-filter"></i> Filter</button>
                <a href="reviews.php" class="btn-action-view" style="display: inline-flex; align-items: center; justify-content: center; padding: 0.7rem 0.9rem; text-decoration: none;" title="Clear filters"><i class="fa-solid fa-xmark"></i></a>
            </div>
        </form>
    </div>

    <div class="smiski-glass-card" style="overflow: hidden;">
        <div style="padding: 0.9rem 1.25rem; border-bottom: 1px solid rgba(74, 222, 128, 0.3); font-size: 0.85rem; font-weight: 600; color: #166534;">
            <?= $totalReviews ?> review<?= $totalReviews === 1 ? '' : 's' ?> found<?= $totalPages > 1 ? ' · Page ' . $currentPage . ' of ' . $totalPages : '' ?>
        </div>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; min-width: 960px;">
                <thead>
                    <tr style="background: rgba(220, 252, 231, 0.5); color: #064e3b; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid rgba(74, 222, 128, 0.4);">
                        <th style="padding: 0.9rem 1rem;">ID</th>
                        <th style="padding: 0.9rem 1rem;">Customer</th>
                        <th style="padding: 0.9rem 1rem;">Product</th>
                        <th style="padding: 0.9rem 1rem;">Rating</th>
                        <th style="padding: 0.9rem 1rem;">Review</th>
                        <th style="padding: 0.9rem 1rem;">Submitted</th>
                        <th style="padding: 0.9rem 1rem;">Status</th>
                        <th style="padding: 0.9rem 1rem; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reviews)): ?>
                        <tr><td colspan="8" class="text-muted-green" style="text-align: center; padding: 3rem; font-size: 0.95rem;">
                            <i class="fa-solid fa-comment-slash" style="font-size: 1.6rem; display: block; margin-bottom: 0.5rem;"></i>
                            <?= $anyReviews ? 'No reviews match your filters.' : 'No reviews have been submitted yet. Reviews appear here when customers rate products they have received.' ?>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($reviews as $r): ?>
                            <?php
                                $displayStatus = !empty($r['deleted_at']) ? 'removed' : $r['status'];
                                [$bg, $fg, $bd] = $statusStyles[$displayStatus] ?? $statusStyles['pending'];
                                $excerpt = mb_strlen($r['review_text'], 'UTF-8') > 90 ? mb_substr($r['review_text'], 0, 90, 'UTF-8') . '…' : $r['review_text'];
                                $details = [
                                    'id' => (int)$r['id'],
                                    'customer' => $r['customer_name'],
                                    'email' => $r['customer_email'],
                                    'product' => $r['product_name'],
                                    'rating' => (int)$r['rating'],
                                    'text' => $r['review_text'],
                                    'status' => ucfirst($displayStatus),
                                    'submitted' => date('M j, Y g:i A', strtotime($r['created_at'])),
                                    'moderated' => $r['moderated_at'] ? date('M j, Y g:i A', strtotime($r['moderated_at'])) : 'Not yet moderated',
                                ];
                            ?>
                            <tr style="border-bottom: 1px solid rgba(74, 222, 128, 0.2);" onmouseenter="this.style.background='rgba(220, 252, 231, 0.4)'" onmouseleave="this.style.background='transparent'">
                                <td style="padding: 0.85rem 1rem; font-weight: 700; color: #064e3b; font-size: 0.85rem;"><?= (int)$r['id'] ?></td>
                                <td style="padding: 0.85rem 1rem; font-size: 0.85rem;">
                                    <div style="font-weight: 700; color: #064e3b;"><?= htmlspecialchars($r['customer_name']) ?></div>
                                    <div class="text-muted-green" style="font-size: 0.75rem;"><?= htmlspecialchars($r['customer_email']) ?></div>
                                </td>
                                <td style="padding: 0.85rem 1rem; font-size: 0.85rem; font-weight: 600; color: #1f2937;"><?= htmlspecialchars($r['product_name']) ?></td>
                                <td style="padding: 0.85rem 1rem;"><?= renderStars($r['rating'], '0.85rem') ?></td>
                                <td style="padding: 0.85rem 1rem; font-size: 0.85rem; color: #374151; max-width: 280px;"><?= htmlspecialchars($excerpt) ?></td>
                                <td class="text-muted-green" style="padding: 0.85rem 1rem; font-size: 0.8rem; white-space: nowrap;"><?= htmlspecialchars(date('M j, Y', strtotime($r['created_at']))) ?></td>
                                <td style="padding: 0.85rem 1rem;"><span class="status-pill" style="background: <?= $bg ?>; color: <?= $fg ?>; border: 1px solid <?= $bd ?>;"><?= ucfirst($displayStatus) ?></span></td>
                                <td style="padding: 0.85rem 1rem;">
                                    <div style="display: flex; gap: 0.35rem; flex-wrap: wrap; justify-content: center;">
                                        <button type="button" class="btn-action-view" onclick='openReviewModal(<?= jsonAttr($details) ?>)' style="padding: 0.35rem 0.6rem; font-size: 0.75rem;"><i class="fa-solid fa-eye"></i> View</button>
                                        <?php if ($displayStatus === 'removed'): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Restore this review with its previous status?')">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="restore">
                                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnQuery) ?>">
                                                <button type="submit" class="review-action review-action-restore"><i class="fa-solid fa-rotate-left"></i> Restore</button>
                                            </form>
                                        <?php else: ?>
                                            <?php if ($r['status'] !== 'approved'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnQuery) ?>">
                                                    <button type="submit" class="review-action review-action-approve"><i class="fa-solid fa-check"></i> Approve</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($r['status'] !== 'rejected'): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Reject this review? It will not be shown on the product page.')">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnQuery) ?>">
                                                    <button type="submit" class="review-action review-action-reject"><i class="fa-solid fa-ban"></i> Reject</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($r['status'] !== 'hidden'): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Hide this review from the product page?')">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="hide">
                                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnQuery) ?>">
                                                    <button type="submit" class="review-action review-action-hide"><i class="fa-solid fa-eye-slash"></i> Hide</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this review? It will be kept in the records and can be restored later.')">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="remove">
                                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnQuery) ?>">
                                                <button type="submit" class="review-action review-action-remove"><i class="fa-solid fa-trash"></i> Remove</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= renderPagination($currentPage, $totalPages, $activeFilters, 'reviews.php') ?>
    </div>
</div>

<div id="reviewModal" role="dialog" aria-modal="true" aria-labelledby="reviewModalTitle" onclick="if (event.target === this) closeReviewModal()" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(5, 46, 22, 0.85); backdrop-filter: blur(10px); align-items: center; justify-content: center; z-index: 2000; padding: 1rem;">
    <div class="smiski-glass-card" style="padding: 2rem; width: 100%; max-width: 560px; position: relative; max-height: 90vh; overflow-y: auto; color: #1f2937;">
        <button type="button" onclick="closeReviewModal()" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #15803d;">&times;</button>
        <h3 class="neon-glow-text" id="reviewModalTitle" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem;">Review Details</h3>
        <div style="display: flex; flex-direction: column; gap: 0.6rem; font-size: 0.9rem;">
            <div><strong>Review ID:</strong> <span id="rv_id"></span></div>
            <div><strong>Customer:</strong> <span id="rv_customer"></span> (<span id="rv_email" class="text-muted-green"></span>)</div>
            <div><strong>Product:</strong> <span id="rv_product"></span></div>
            <div><strong>Rating:</strong> <span id="rv_rating" style="color: #f59e0b;"></span></div>
            <div><strong>Status:</strong> <span id="rv_status"></span></div>
            <div><strong>Submitted:</strong> <span id="rv_submitted"></span></div>
            <div><strong>Last moderated:</strong> <span id="rv_moderated"></span></div>
            <div>
                <strong>Review:</strong>
                <p id="rv_text" style="margin-top: 0.4rem; padding: 0.85rem 1rem; background: rgba(255, 255, 255, 0.7); border: 1px solid rgba(74, 222, 128, 0.4); border-radius: 12px; white-space: pre-wrap; line-height: 1.6;"></p>
            </div>
        </div>
    </div>
</div>

<script>
function openReviewModal(r) {
    document.getElementById('rv_id').innerText = '#' + r.id;
    document.getElementById('rv_customer').innerText = r.customer;
    document.getElementById('rv_email').innerText = r.email;
    document.getElementById('rv_product').innerText = r.product;
    document.getElementById('rv_rating').innerText = '★'.repeat(r.rating) + '☆'.repeat(5 - r.rating) + ' (' + r.rating + '/5)';
    document.getElementById('rv_status').innerText = r.status;
    document.getElementById('rv_submitted').innerText = r.submitted;
    document.getElementById('rv_moderated').innerText = r.moderated;
    document.getElementById('rv_text').innerText = r.text;
    document.getElementById('reviewModal').style.display = 'flex';
}
function closeReviewModal() { document.getElementById('reviewModal').style.display = 'none'; }
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeReviewModal(); });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
