<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // errors are logged, never shown (see config/validation.php)

// Session is started securely in config/db.php
require_once __DIR__ . '/../config/db.php';

$error = getFlash('error');   // e.g. expired form
$prompt = $_SESSION['login_prompt'] ?? '';
unset($_SESSION['login_prompt']);
$errors = [];
$old = [];

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
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            regenerateSessionAfterLogin();
            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_phone'] = $user['phone'] ?? '';
            $_SESSION['user_address'] = $user['address'] ?? '';

            if ($user['role'] === 'admin') {
                logActivity($user['id'], 'ADMIN_LOGIN', 'Admin logged in via customer login: ' . $user['email']);
                header("Location: ../admin/index.php");
                exit;
            } else {
                logActivity($user['id'], 'CUSTOMER_LOGIN', 'Customer logged in: ' . $user['email']);

                // Restore the saved cart (and merge any items added before logging in)
                unset($_SESSION['cart_synced_for']);
                syncCustomerCart();

                // Only allow redirects to pages of this store (no open redirects)
                $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
                if (!is_string($redirect) || !preg_match('/^[a-z_]+\.php$/', $redirect)) {
                    $redirect = 'index.php';
                }
                unset($_SESSION['redirect_after_login']);
                setFlash('success', 'Welcome back, ' . $user['full_name'] . '! You are now logged in.');
                header("Location: $redirect");
                exit;
            }
        } else {
            recordFailedLogin($email, 'customer');
            $error = MSG_LOGIN_FAILED;
            http_response_code(401);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In - E-Commerce Store</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/form-feedback.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Outfit', sans-serif; }
        
        body {
            background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            color: #1f2937 !important;
        }

        /* White Frosted Glass Card (Matched with cart, checkout, success, orders) */
        .smiski-card {
            background: rgba(255, 255, 255, 0.78) !important;
            backdrop-filter: blur(30px) saturate(180%);
            -webkit-backdrop-filter: blur(30px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.5) !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35), 0 0 20px rgba(134, 239, 172, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.8) !important;
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s ease, border-color 0.4s ease;
            border-radius: 24px;
        }
        .smiski-card:hover {
            transform: translateY(-2px);
            border-color: #86efac !important;
            background: rgba(255, 255, 255, 0.88) !important;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4), 0 0 30px rgba(134, 239, 172, 0.5), inset 0 0 15px rgba(134, 239, 172, 0.3) !important;
        }

        .text-muted-green {
            color: #166534 !important;
            font-weight: 500;
        }

        /* Neon Glow Text for White Backgrounds */
        .neon-glow-text {
            color: #064e3b !important;
            text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
        }

        .login-card {
            width: 100%;
            max-width: 430px;
            padding: 2.5rem;
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-header h2 {
            font-size: 1.75rem;
            font-weight: 700;
        }
        .login-header p {
            font-size: 0.9rem;
            margin-top: 0.3rem;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.4rem;
        }
        
        /* Input Controls */
        .input-control {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.85) !important;
            border: 1px solid rgba(74, 222, 128, 0.5) !important;
            border-radius: 12px;
            font-size: 0.95rem;
            outline: none;
            transition: all 0.3s ease;
            color: #1f2937;
            backdrop-filter: blur(10px);
        }
        .input-control::placeholder {
            color: #9ca3af;
        }
        .input-control:focus {
            border-color: #15803d !important;
            background: #ffffff !important;
            box-shadow: 0 0 15px rgba(74, 222, 128, 0.4);
        }

        /* Hover Scale Effect */
        .hover-scale {
            transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .hover-scale:hover {
            transform: translateY(-2px);
            border-color: #15803d !important;
        }

        /* Buttons matching the theme */
        .btn-green-primary {
            background: #15803d !important;
            color: #ffffff !important;
            border: 1px solid #4ade80 !important;
            box-shadow: 0 0 12px rgba(74, 222, 128, 0.5);
            padding: 0.85rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            transition: all 0.3s ease;
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
            padding: 0.85rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            transition: all 0.3s ease;
        }
        .btn-green-secondary:hover {
            background: rgba(255, 255, 255, 0.9) !important;
            transform: translateY(-2px);
            border-color: #15803d !important;
            box-shadow: 0 0 15px rgba(74, 222, 128, 0.5);
        }

        .btn-link { 
            color: #15803d; 
            transition: color 0.2s;
        }
        .btn-link:hover { 
            color: #064e3b; 
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="smiski-card login-card">
    <div class="login-header">
        <a href="index.php" title="Back to Home Store" style="display: inline-block; text-decoration: none; margin-bottom: 0.75rem;">
            <img src="../assets/ecommerce-logo.png" alt="E-Commerce Logo" style="width: 64px; height: 64px; object-fit: contain; transition: transform 0.2s;" onmouseenter="this.style.transform='scale(1.08)'" onmouseleave="this.style.transform='none'">
        </a>
        <h2 class="neon-glow-text">Store Log In</h2>
        <p class="text-muted-green">Enter your credentials to access your account</p>
    </div>

    <?php if ($prompt): ?>
        <div style="background: rgba(220, 252, 231, 0.9); color: #064e3b; padding: 0.75rem 1rem; border-radius: 12px; font-size: 0.85rem; margin-bottom: 1.25rem; border: 1px solid rgba(74, 222, 128, 0.5); backdrop-filter: blur(10px);">
            <i class="fa-solid fa-circle-info" style="color: #15803d;"></i> <?= htmlspecialchars($prompt) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div style="background: rgba(254, 226, 226, 0.95); border: 1px solid rgba(239, 68, 68, 0.5); color: #991b1b; padding: 0.75rem 1rem; border-radius: 12px; font-size: 0.85rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem; box-shadow: 0 5px 15px rgba(0,0,0,0.15);">
            <i class="fa-solid fa-triangle-exclamation" style="color: #dc2626;"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <?= csrfField() ?>
        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" class="input-control hover-scale<?= invalidClass($errors, 'email') ?>"<?= invalidAttr($errors, 'email') ?> required maxlength="100" autocomplete="email" placeholder="name@example.com" value="<?= oldValue($old, 'email') ?>">
            <?= fieldError($errors, 'email') ?>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <div style="position: relative;">
                <input type="password" id="password" name="password" class="input-control hover-scale<?= invalidClass($errors, 'password') ?>"<?= invalidAttr($errors, 'password') ?> required autocomplete="current-password" placeholder="••••••••" style="padding-right: 2.5rem;">
                <button type="button" onclick="togglePassword('password', 'toggleIcon')" style="position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #4b5563; font-size: 1rem;" title="Show/Hide Password" onmouseover="this.style.color='#15803d'" onmouseout="this.style.color='#4b5563'">
                    <i id="toggleIcon" class="fa-solid fa-eye"></i>
                </button>
            </div>
            <?= fieldError($errors, 'password') ?>
        </div>

        <div style="display: flex; flex-direction: column; gap: 0.85rem; margin-top: 1.75rem; margin-bottom: 1.25rem;">
            <button type="submit" class="btn-green-primary">
                <i class="fa-solid fa-right-to-bracket"></i> Log In
            </button>

            <a href="register.php" class="btn-green-secondary" style="text-align: center; text-decoration: none;">
                <i class="fa-solid fa-user-plus"></i> Create New Account (Register)
            </a>
        </div>

        <div style="display: flex; justify-content: flex-end; align-items: center; margin-top: 1rem;">
            <a href="../admin/login.php" class="btn-link" style="text-decoration: none; font-weight: 500; font-size: 0.85rem;">
                <i class="fa-solid fa-user-shield"></i> Admin Portal
            </a>
        </div>
    </form>
</div>

<script src="../assets/js/form-validation.js"></script>
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

</body>
</html>