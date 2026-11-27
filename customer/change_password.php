<?php
require_once __DIR__ . '/../config/db.php';

if (isAdminLoggedIn()) {
    setFlash('error', MSG_UNAUTHORIZED . ' Admin accounts are managed from the admin panel.');
    header("Location: ../admin/index.php", true, 303);
    exit;
}
if (!isCustomerLoggedIn()) {
    $_SESSION['redirect_after_login'] = 'change_password.php';
    $_SESSION['login_prompt'] = 'Please log in to change your password.';
    header("Location: login.php", true, 303);
    exit;
}

$db = getDB();
$customerId = (int)$_SESSION['user_id'];

$accountStmt = $db->prepare("SELECT id, status FROM users WHERE id = ? AND role = 'customer'");
$accountStmt->execute([$customerId]);
$account = $accountStmt->fetch(PDO::FETCH_ASSOC);
if (!$account || $account['status'] !== 'active') {
    $_SESSION = [];
    session_regenerate_id(true);
    setFlash('error', 'Your account is not available. Please log in again or contact support.');
    header("Location: login.php", true, 303);
    exit;
}

function passwordChangeThrottled($customerId) {
    try {
        $stmt = getDB()->prepare("SELECT COUNT(*) FROM activity_logs WHERE action = 'PASSWORD_CHANGE_FAILED' AND user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt->execute([(int)$customerId]);
        return (int)$stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
    } catch (Exception $e) {
        logAppError('Password change throttle check failed', $e);
        return false;
    }
}

$successMessage = getFlash('success');
$formError = getFlash('error');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (passwordChangeThrottled($customerId)) {
        $formError = 'Too many incorrect password attempts. Please wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.';
        http_response_code(429);
    } else {
        $v = new Validator($_POST);
        $currentPassword = $v->loginPassword('current_password');
        $newPassword = $v->newPassword('new_password');
        $v->confirmation('confirm_password', $newPassword);

        $hashStmt = $db->prepare("SELECT password FROM users WHERE id = ? AND role = 'customer'");
        $hashStmt->execute([$customerId]);
        $storedHash = (string)$hashStmt->fetchColumn();

        if (!$v->hasError('current_password')) {
            if (!password_verify($currentPassword, $storedHash)) {
                $v->addError('current_password', MSG_CURRENT_PASSWORD);
                logActivity($customerId, 'PASSWORD_CHANGE_FAILED', 'Incorrect current password entered while changing password');
            } elseif (!$v->hasError('new_password') && ($newPassword === $currentPassword || password_verify($newPassword, $storedHash))) {
                $v->addError('new_password', MSG_PASSWORD_UNCHANGED);
            }
        }

        $errors = $v->errors();
        if ($errors) {
            $formError = 'Your password was not changed. ' . (isset($errors['current_password']) && $errors['current_password'] === MSG_CURRENT_PASSWORD ? MSG_CURRENT_PASSWORD : MSG_FORM_ERRORS);
            http_response_code(isset($errors['current_password']) && $errors['current_password'] === MSG_CURRENT_PASSWORD ? 401 : 422);
        } else {
            try {
                $update = $db->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'customer'");
                $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), $customerId]);
                regenerateSessionAfterLogin();
                logActivity($customerId, 'PASSWORD_CHANGED', 'Customer changed account password');
                setFlash('success', 'Your password has been changed successfully. Use your new password the next time you log in.');
                header("Location: change_password.php", true, 303);
                exit;
            } catch (Exception $e) {
                $formError = 'Your password was not changed. ' . safeErrorMessage($e, 'Password change failed');
                http_response_code(500);
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
        body {
            background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
            min-height: 100vh;
        }

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
            transform: translateY(-4px);
            border-color: #86efac !important;
            background: rgba(255, 255, 255, 0.88) !important;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4), 0 0 30px rgba(134, 239, 172, 0.5), inset 0 0 15px rgba(134, 239, 172, 0.3) !important;
        }

        .smiski-subcard {
            background: rgba(255, 255, 255, 0.55) !important;
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border: 1px solid rgba(74, 222, 128, 0.4) !important;
            border-radius: 16px;
            box-shadow: inset 0 2px 5px rgba(255, 255, 255, 0.9), 0 0 12px rgba(74, 222, 128, 0.2);
        }

        .text-muted-green {
            color: #166534 !important;
            font-weight: 500;
        }

        .btn-green-primary {
            background: #15803d !important;
            color: #ffffff !important;
            border: 1px solid #4ade80 !important;
            box-shadow: 0 0 12px rgba(74, 222, 128, 0.5);
            padding: 0.75rem 1.25rem;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
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
            padding: 0.75rem 1.25rem;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }
        .btn-green-secondary:hover {
            background: rgba(255, 255, 255, 0.9) !important;
            transform: translateY(-2px);
            border-color: #15803d !important;
            box-shadow: 0 0 15px rgba(74, 222, 128, 0.5);
        }

        .hover-scale {
            transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .hover-scale:hover {
            transform: translateY(-2px);
            border-color: #86efac !important;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1), 0 0 15px rgba(74, 222, 128, 0.4);
        }

        .neon-glow-text {
            color: #064e3b !important;
            text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
        }
    

    .account-label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        margin-bottom: 0.35rem;
        color: #166534;
    }
    .account-input {
        width: 100%;
        padding: 0.75rem 2.75rem 0.75rem 1rem;
        background: rgba(255, 255, 255, 0.8);
        border: 1px solid rgba(74, 222, 128, 0.5);
        border-radius: 12px;
        color: #1f2937;
        font-size: 0.95rem;
        font-family: inherit;
        outline: none;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .account-input:focus {
        border-color: #15803d;
        background: #ffffff;
        box-shadow: 0 0 15px rgba(74, 222, 128, 0.4);
    }
    .password-toggle {
        position: absolute;
        right: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        cursor: pointer;
        color: #4b5563;
        font-size: 1rem;
    }
    .password-toggle:hover {
        color: #15803d;
    }
    .password-rules {
        list-style: none;
        margin: 0.6rem 0 0;
        padding: 0.75rem 1rem;
        background: rgba(255, 255, 255, 0.55);
        border: 1px solid rgba(74, 222, 128, 0.4);
        border-radius: 12px;
        font-size: 0.82rem;
        color: #4b5563;
        display: grid;
        gap: 0.3rem;
    }
    .password-rules li {
        display: flex;
        align-items: center;
        gap: 0.45rem;
    }
    .password-rules li.met {
        color: #15803d;
        font-weight: 600;
    }
</style>

<div class="container" style="max-width: 1100px; margin: 0 auto; padding-top: 1.5rem; padding-bottom: 3rem; padding-left: 1rem; padding-right: 1rem;">

    <div class="smiski-card" style="padding: 2rem 2.5rem; margin-bottom: 2rem;">
        <span style="background: rgba(255, 255, 255, 0.6); color: #064e3b; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.8rem; border-radius: 20px; display: inline-block; margin-bottom: 0.75rem; border: 1px solid rgba(74,222,128,0.6); box-shadow: 0 0 10px rgba(74,222,128,0.4);">
            <i class="fa-solid fa-shield-halved"></i> ACCOUNT SECURITY
        </span>
        <h1 class="neon-glow-text" style="font-size: 2.25rem; font-weight: 700; margin-bottom: 0.4rem;">Change Password</h1>
        <p class="text-muted-green" style="font-size: 1rem; line-height: 1.5;">Choose a strong password that you don't use for other websites.</p>
        <?php require __DIR__ . '/includes/account_nav.php'; ?>
    </div>

    <?= alertBox('success', $successMessage) ?>
    <?= alertBox('error', $formError) ?>

    <div class="smiski-card" style="max-width: 560px; margin: 0 auto; padding: 2rem;">
        <form method="POST" action="change_password.php" id="changePasswordForm" autocomplete="off">
            <?= csrfField() ?>

            <div style="margin-bottom: 1.15rem;">
                <label class="account-label" for="current_password">Current Password</label>
                <div style="position: relative;">
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password" class="account-input<?= invalidClass($errors, 'current_password') ?>"<?= invalidAttr($errors, 'current_password') ?>>
                    <button type="button" class="password-toggle" onclick="togglePassword('current_password', 'currentIcon')" title="Show/Hide Password"><i id="currentIcon" class="fa-solid fa-eye"></i></button>
                </div>
                <?= fieldError($errors, 'current_password') ?>
            </div>

            <div style="margin-bottom: 1.15rem;">
                <label class="account-label" for="new_password">New Password</label>
                <div style="position: relative;">
                    <input type="password" id="new_password" name="new_password" required minlength="8" maxlength="72" data-password-strength data-not-match="#current_password" autocomplete="new-password" aria-describedby="passwordRules" class="account-input<?= invalidClass($errors, 'new_password') ?>"<?= invalidAttr($errors, 'new_password') ?>>
                    <button type="button" class="password-toggle" onclick="togglePassword('new_password', 'newIcon')" title="Show/Hide Password"><i id="newIcon" class="fa-solid fa-eye"></i></button>
                </div>
                <?= fieldError($errors, 'new_password') ?>
                <ul class="password-rules" id="passwordRules">
                    <li data-rule="length"><i class="fa-regular fa-circle"></i> At least 8 characters</li>
                    <li data-rule="letter"><i class="fa-regular fa-circle"></i> At least one letter</li>
                    <li data-rule="number"><i class="fa-regular fa-circle"></i> At least one number</li>
                    <li data-rule="different"><i class="fa-regular fa-circle"></i> Different from your current password</li>
                </ul>
            </div>

            <div style="margin-bottom: 1.6rem;">
                <label class="account-label" for="confirm_password">Confirm New Password</label>
                <div style="position: relative;">
                    <input type="password" id="confirm_password" name="confirm_password" required maxlength="72" data-match="#new_password" autocomplete="new-password" class="account-input<?= invalidClass($errors, 'confirm_password') ?>"<?= invalidAttr($errors, 'confirm_password') ?>>
                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', 'confirmIcon')" title="Show/Hide Password"><i id="confirmIcon" class="fa-solid fa-eye"></i></button>
                </div>
                <?= fieldError($errors, 'confirm_password') ?>
            </div>

            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <button type="submit" class="btn-green-primary" style="flex: 1; justify-content: center; min-width: 170px;">
                    <i class="fa-solid fa-key"></i> Update Password
                </button>
                <a href="profile.php" class="btn-green-secondary" style="flex: 1; justify-content: center; min-width: 140px; text-decoration: none;">
                    <i class="fa-solid fa-arrow-left"></i> Back to Profile
                </a>
            </div>
        </form>
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
    const current = document.getElementById('current_password');
    const next = document.getElementById('new_password');
    const rules = {
        length: value => value.length >= 8,
        letter: value => /[A-Za-z]/.test(value),
        number: value => /[0-9]/.test(value),
        different: value => value !== '' && value !== current.value
    };
    function updateRules() {
        document.querySelectorAll('#passwordRules li').forEach(item => {
            const met = rules[item.dataset.rule](next.value);
            item.classList.toggle('met', met);
            item.querySelector('i').className = met ? 'fa-solid fa-circle-check' : 'fa-regular fa-circle';
        });
    }
    next.addEventListener('input', updateRules);
    current.addEventListener('input', updateRules);
    updateRules();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
