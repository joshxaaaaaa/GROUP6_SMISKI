<?php
require_once __DIR__ . '/../config/db.php';

$confirmed = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm_logout'] ?? '') === '1';

if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit;
}

if (!$confirmed) {
    $cancelUrl = 'index.php';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer !== '' && parse_url($referer, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '') && strpos($referer, 'logout.php') === false) {
        $cancelUrl = $referer;
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Out - Admin Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Outfit', sans-serif; }
        body { background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
        .card { background: rgba(255, 255, 255, 0.85); border-radius: 24px; padding: 2.5rem; max-width: 420px; width: 100%; text-align: center; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35); }
        h2 { color: #064e3b; font-size: 1.35rem; margin-bottom: 1.5rem; }
        .row { display: flex; gap: 0.75rem; }
        .btn { flex: 1; padding: 0.8rem; border-radius: 12px; font-weight: 600; font-size: 0.95rem; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        .btn-confirm { background: #15803d; color: #fff; border: 1px solid #4ade80; }
        .btn-cancel { background: #fff; color: #15803d; border: 1px solid rgba(74, 222, 128, 0.6); }
    </style>
</head>
<body>
    <div class="card">
        <i class="fa-solid fa-right-from-bracket" style="font-size: 2rem; color: #15803d; margin-bottom: 1rem;"></i>
        <h2>Are you sure you want to log out?</h2>
        <form method="POST" action="logout.php" class="row">
            <?= csrfField() ?>
            <input type="hidden" name="confirm_logout" value="1">
            <a href="<?= htmlspecialchars($cancelUrl) ?>" class="btn btn-cancel">Cancel</a>
            <button type="submit" class="btn btn-confirm">Confirm</button>
        </form>
    </div>
</body>
</html>
    <?php
    exit;
}

logActivity($_SESSION['user_id'], 'ADMIN_LOGOUT', 'Admin logged out: ' . $_SESSION['user_email']);

session_unset();
session_destroy();

header("Location: login.php");
exit;
