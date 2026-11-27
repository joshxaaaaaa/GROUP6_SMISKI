<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/db.php';

if (isAdminLoggedIn()) {
    header("Location: index.php");
    exit;
}

$error = getFlash('error');
$errors = [];
$old = ['email' => 'admin@admin.com'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = new Validator($_POST);
    $email = $v->email('email');
    $password = $v->loginPassword('password');
    $errors = $v->errors();
    $old = ['email' => $email];

    if (isLoginThrottled()) {
        $error = msgLoginThrottled();
        $errors = [];
        http_response_code(429);
    } elseif ($errors) {
        $error = MSG_FORM_ERRORS;
        http_response_code(422);
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin' AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        $isHashed = $user && password_get_info($user['password'])['algo'] !== null && password_get_info($user['password'])['algo'] !== 0;
        $valid = $user && ($isHashed
            ? password_verify($password, $user['password'])
            : hash_equals((string)$user['password'], $password));

        if ($valid) {
            if (!$isHashed || password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            regenerateSessionAfterLogin();

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];

            logActivity($user['id'], 'ADMIN_LOGIN', 'Admin logged in: ' . $user['email']);

            setFlash('success', 'Welcome back, ' . $user['full_name'] . '!');
            header("Location: index.php", true, 303);
            exit;
        } else {
            recordFailedLogin($email, 'admin');
            $error = MSG_LOGIN_FAILED;
            http_response_code(401);
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    html, body {
        margin: 0 !important;
        padding: 0 !important;
        background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
        min-height: 100vh !important;
        height: 100% !important;
        font-family: 'Outfit', sans-serif;
    }

    .smiski-admin-card {
        background: rgba(255, 255, 255, 0.78) !important;
        backdrop-filter: blur(30px) saturate(180%);
        -webkit-backdrop-filter: blur(30px) saturate(180%);
        border: 1px solid rgba(255, 255, 255, 0.5) !important;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35), 0 0 20px rgba(134, 239, 172, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.8) !important;
        transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s ease, border-color 0.4s ease;
        border-radius: 24px;
    }
    .smiski-admin-card:hover {
        transform: translateY(-2px);
        border-color: #86efac !important;
        background: rgba(255, 255, 255, 0.88) !important;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4), 0 0 30px rgba(134, 239, 172, 0.5), inset 0 0 15px rgba(134, 239, 172, 0.3) !important;
    }

    .neon-glow-text {
        color: #064e3b !important;
        text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
    }

    .input-control-admin {
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
    }
    .input-control-admin::placeholder {
        color: #9ca3af;
    }
    .input-control-admin:focus {
        border-color: #15803d !important;
        background: #ffffff !important;
        box-shadow: 0 0 15px rgba(74, 222, 128, 0.4);
    }

    .btn-green-glow {
        background: #15803d !important;
        color: #ffffff !important;
        border: 1px solid #4ade80 !important;
        box-shadow: 0 0 12px rgba(74, 222, 128, 0.5);
        transition: all 0.3s ease;
    }
    .btn-green-glow:hover {
        background: #166534 !important;
        transform: translateY(-2px);
        border-color: #86efac !important;
        box-shadow: 0 0 20px rgba(74, 222, 128, 0.8), inset 0 0 8px rgba(74, 222, 128, 0.4);
    }
</style>

<div style="position: fixed; top: 0; left: 0; width: 100%; height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%); z-index: 999; overflow-y: auto;">
    
    <div class="smiski-admin-card" style="color: #1f2937; padding: 2.5rem; width: 100%; max-width: 400px; margin: auto;">
        <div style="text-align: center; margin-bottom: 2rem;">
            <div style="width: 60px; height: 60px; background: rgba(220, 252, 231, 0.9); color: #15803d; border: 1px solid rgba(74, 222, 128, 0.6); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem auto; font-size: 1.75rem; box-shadow: 0 0 15px rgba(74, 222, 128, 0.5);">
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <h2 class="neon-glow-text" style="font-size: 1.75rem; font-weight: 700;">Admin Login</h2>
            <p style="font-size: 0.9rem; color: #166534; font-weight: 500; margin-top: 0.3rem;">Access the administrative portal</p>
        </div>

        <?php if ($error): ?>
            <div style="background: rgba(254, 226, 226, 0.95); border: 1px solid rgba(239, 68, 68, 0.5); color: #991b1b; padding: 0.75rem 1rem; border-radius: 12px; font-size: 0.85rem; margin-bottom: 1.25rem; box-shadow: 0 5px 15px rgba(0,0,0,0.15);">
                <i class="fa-solid fa-triangle-exclamation" style="color: #dc2626;"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <?= csrfField() ?>
            <div style="margin-bottom: 1.25rem;">
                <label for="adminEmail" style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem; color: #1f2937;">Admin Email</label>
                <input type="email" id="adminEmail" name="email" required maxlength="100" autocomplete="username" placeholder="admin@admin.com" value="<?= oldValue($old, 'email') ?>"
                       class="input-control-admin<?= invalidClass($errors, 'email') ?>"<?= invalidAttr($errors, 'email') ?>>
                <?= fieldError($errors, 'email') ?>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem; color: #1f2937;">Password</label>
                <div style="position: relative;">
                    <input type="password" id="adminPassword" name="password" required autocomplete="current-password" placeholder="••••••••"
                           class="input-control-admin<?= invalidClass($errors, 'password') ?>"<?= invalidAttr($errors, 'password') ?> style="padding-right: 2.5rem;">
                    <button type="button" onclick="togglePassword('adminPassword', 'adminToggleIcon')" style="position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #4b5563; font-size: 1rem;" title="Show/Hide Password" onmouseover="this.style.color='#15803d'" onmouseout="this.style.color='#4b5563'">
                        <i id="adminToggleIcon" class="fa-solid fa-eye"></i>
                    </button>
                </div>
                <?= fieldError($errors, 'password') ?>
            </div>

            <button type="submit" class="btn-green-glow" style="width: 100%; padding: 0.85rem; font-size: 1rem; border-radius: 14px; font-weight: 600; cursor: pointer;">
                <i class="fa-solid fa-right-to-bracket"></i> Log In to Dashboard
            </button>
        </form>

        <div style="margin-top: 1.5rem; text-align: center; border-top: 1px solid rgba(74, 222, 128, 0.4); padding-top: 1.25rem;">
            <a href="../customer/login.php" style="color: #15803d; font-size: 0.9rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem; transition: color 0.2s;" onmouseover="this.style.color='#064e3b'" onmouseout="this.style.color='#15803d'">
                <i class="fa-solid fa-arrow-left"></i> Back to Store Login
            </a>
            <span style="color: #9ca3af; margin: 0 0.5rem;">|</span>
            <a href="../customer/index.php" style="color: #4b5563; font-size: 0.9rem; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem; transition: color 0.2s;" onmouseover="this.style.color='#15803d'" onmouseout="this.style.color='#4b5563'">
                <i class="fa-solid fa-store"></i> Store Front
            </a>
        </div>
    </div>
</div>

<script>
function togglePassword(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-solid fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-solid fa-eye';
    }
}
</script>