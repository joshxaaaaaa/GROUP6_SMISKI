<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $address = sanitize($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (empty($name) || empty($email) || empty($password) || empty($address) || $phone === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!isValidPhone($phone)) {
        $error = 'Phone number must contain numbers only (7 to 15 digits, no letters, spaces or special characters).';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'Email address is already registered.';
        } else {
            // I-hash ang password para secure bago i-save sa database
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $db->prepare("INSERT INTO users (full_name, email, password, address, phone, role, status) VALUES (?, ?, ?, ?, ?, 'customer', 'active')");
            if ($stmt->execute([$name, $email, $hashedPassword, $address, $phone])) {
                $newUserId = $db->lastInsertId();
                if (function_exists('logActivity')) {
                    logActivity($newUserId, 'CUSTOMER_REGISTER', 'New customer registered: ' . $email);
                }

                $_SESSION['user_id'] = $newUserId;
                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_role'] = 'customer';
                $_SESSION['user_phone'] = $phone;
                $_SESSION['user_address'] = $address;

                // Save any items added to the cart before signing up
                unset($_SESSION['cart_synced_for']);
                syncCustomerCart();

                $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
                unset($_SESSION['redirect_after_login']);
                header("Location: $redirect");
                exit;
            } else {
                $error = 'Failed to create account. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register / Sign Up - E-Commerce Store</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

        /* White Frosted Glass Card (Matched with other pages) */
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

        /* Neon Glow Text for White Cards */
        .neon-glow-text {
            color: #064e3b !important;
            text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
        }

        .register-card {
            width: 100%;
            max-width: 490px;
            padding: 2.5rem;
        }

        .register-header {
            text-align: center;
            margin-bottom: 1.75rem;
        }
        .register-header h2 {
            font-size: 1.75rem;
            font-weight: 700;
        }
        .register-header p {
            font-size: 0.9rem;
            margin-top: 0.3rem;
        }
        .form-group {
            margin-bottom: 1.1rem;
        }
        label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.35rem;
        }

        /* Input Controls */
        .input-control {
            width: 100%;
            padding: 0.7rem 0.9rem;
            background: rgba(255, 255, 255, 0.85) !important;
            border: 1px solid rgba(74, 222, 128, 0.5) !important;
            border-radius: 12px;
            font-size: 0.95rem;
            outline: none;
            color: #1f2937;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }
        .input-control::placeholder {
            color: #9ca3af;
        }
        .input-control:focus {
            border-color: #15803d !important;
            background: #ffffff !important;
            box-shadow: 0 0 15px rgba(74, 222, 128, 0.4);
        }
        textarea.input-control {
            resize: vertical;
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
            padding: 0.75rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            flex: 1;
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
            padding: 0.75rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            flex: 1;
            transition: all 0.3s ease;
        }
        .btn-green-secondary:hover {
            background: rgba(255, 255, 255, 0.9) !important;
            transform: translateY(-2px);
            border-color: #15803d !important;
            box-shadow: 0 0 15px rgba(74, 222, 128, 0.5);
        }

        .btn-row {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }
        .footer-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.9rem;
            color: #4b5563;
        }
        .footer-link a {
            color: #15803d;
            font-weight: 600;
            text-decoration: underline;
            transition: color 0.2s;
        }
        .footer-link a:hover {
            color: #064e3b;
        }
    </style>
</head>
<body>

<div class="smiski-card register-card">
    <div class="register-header">
        <a href="index.php" title="Back to Home Store" style="display: inline-block; text-decoration: none; margin-bottom: 0.75rem;">
            <img src="../assets/ecommerce-logo.png" alt="E-Commerce Logo" style="width: 64px; height: 64px; object-fit: contain; transition: transform 0.2s;" onmouseenter="this.style.transform='scale(1.08)'" onmouseleave="this.style.transform='none'">
        </a>
        <h2 class="neon-glow-text">Create Account</h2>
        <p class="text-muted-green">Sign up to start purchasing products</p>
    </div>

    <?php if ($error): ?>
        <div style="background: rgba(254, 226, 226, 0.95); border: 1px solid rgba(239, 68, 68, 0.5); color: #991b1b; padding: 0.75rem 1rem; border-radius: 12px; font-size: 0.85rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem; box-shadow: 0 5px 15px rgba(0,0,0,0.15);">
            <i class="fa-solid fa-triangle-exclamation" style="color: #dc2626;"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div style="background: rgba(220, 252, 231, 0.95); border: 1px solid rgba(74, 222, 128, 0.5); color: #064e3b; padding: 0.75rem 1rem; border-radius: 12px; font-size: 0.85rem; margin-bottom: 1.25rem; box-shadow: 0 5px 15px rgba(0,0,0,0.15);">
            <i class="fa-solid fa-circle-check" style="color: #15803d;"></i> <?= htmlspecialchars($success) ?>
            <div style="margin-top: 0.5rem;">
                <a href="login.php" style="color:#15803d; font-weight:700; text-decoration:underline;">Click here to Log In</a>
            </div>
        </div>
    <?php endif; ?>

    <form id="registerForm" method="POST" action="register.php">
        <div class="form-group">
            <label for="name">Full Name</label>
            <input type="text" id="name" name="name" class="input-control hover-scale" required placeholder="John Doe" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" class="input-control hover-scale" required placeholder="john@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <div style="position: relative;">
                <input type="password" id="password" name="password" class="input-control hover-scale" required placeholder="••••••••" style="padding-right: 2.5rem;">
                <button type="button" onclick="togglePassword('password', 'toggleIcon')" style="position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #4b5563; font-size: 1rem;" title="Show/Hide Password" onmouseover="this.style.color='#15803d'" onmouseout="this.style.color='#4b5563'">
                    <i id="toggleIcon" class="fa-solid fa-eye"></i>
                </button>
            </div>
        </div>

        <div class="form-group">
            <label for="phone">Phone Number</label>
            <input type="tel" id="phone" name="phone" class="input-control hover-scale" required placeholder="09123456789" inputmode="numeric" pattern="[0-9]{7,15}" minlength="7" maxlength="15" autocomplete="tel" title="Numbers only (7 to 15 digits)" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
            <small id="phoneError" style="display: none; color: #b91c1c; font-size: 0.78rem; font-weight: 600; margin-top: 0.35rem;">
                <i class="fa-solid fa-circle-exclamation"></i> <span></span>
            </small>
        </div>

        <div class="form-group">
            <label for="address">Delivery Address</label>
            <textarea id="address" name="address" class="input-control hover-scale" rows="3" required placeholder="Complete delivery address..."><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
        </div>

        <div class="btn-row">
            <button type="submit" class="btn-green-primary">
                <i class="fa-solid fa-paper-plane"></i> Enter / Submit
            </button>
            <button type="button" class="btn-green-secondary" onclick="clearForm()">
                <i class="fa-solid fa-rotate-left"></i> Clear
            </button>
        </div>
    </form>

    <div class="footer-link">
        Already have an account? <a href="login.php">Log In here</a>
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

(function () {
    const phoneInput = document.getElementById('phone');
    const phoneError = document.getElementById('phoneError');
    const form = document.getElementById('registerForm');

    function showPhoneError(msg) {
        if (!phoneError) return;
        phoneError.querySelector('span').textContent = msg;
        phoneError.style.display = msg ? 'block' : 'none';
    }

    function validatePhone() {
        const value = phoneInput.value;
        if (value === '') {
            phoneInput.setCustomValidity('Please enter your phone number.');
            return false;
        }
        if (!/^[0-9]+$/.test(value)) {
            phoneInput.setCustomValidity('Phone number must contain numbers only.');
            return false;
        }
        if (value.length < 7 || value.length > 15) {
            phoneInput.setCustomValidity('Phone number must be 7 to 15 digits long.');
            return false;
        }
        phoneInput.setCustomValidity('');
        return true;
    }

    // Block letters and special characters as they are typed
    phoneInput.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return; // allow shortcuts, arrows, backspace...
        if (!/[0-9]/.test(e.key)) {
            e.preventDefault();
            showPhoneError('Only numbers (0-9) are allowed.');
        }
    });

    // Strip anything that is not a digit (paste, autofill, mobile keyboards)
    phoneInput.addEventListener('input', function () {
        const digitsOnly = phoneInput.value.replace(/[^0-9]/g, '').slice(0, 15);
        if (digitsOnly !== phoneInput.value) {
            phoneInput.value = digitsOnly;
            showPhoneError('Only numbers (0-9) are allowed.');
        } else {
            showPhoneError('');
        }
        validatePhone();
    });

    form.addEventListener('submit', function (e) {
        if (!validatePhone()) {
            e.preventDefault();
            showPhoneError(phoneInput.validationMessage);
            phoneInput.reportValidity();
            phoneInput.focus();
        }
    });
})();

function clearForm() {
    document.getElementById('registerForm').reset();
    const pwdInput = document.getElementById('password');
    const icon = document.getElementById('toggleIcon');
    if (pwdInput && icon) {
        pwdInput.type = 'password';
        icon.className = 'fa-solid fa-eye';
    }
}
</script>
</body>
</html>