<?php
require_once __DIR__ . '/../config/db.php';

if (isAdminLoggedIn()) {
    setFlash('error', MSG_UNAUTHORIZED . ' Admin accounts are managed from the admin panel.');
    header("Location: ../admin/index.php", true, 303);
    exit;
}
if (!isCustomerLoggedIn()) {
    $_SESSION['redirect_after_login'] = 'profile.php';
    $_SESSION['login_prompt'] = 'Please log in to view your account.';
    header("Location: login.php", true, 303);
    exit;
}

$db = getDB();
$customerId = (int)$_SESSION['user_id'];

function loadCustomerAccount(PDO $db, $customerId) {
    $stmt = $db->prepare("SELECT id, full_name, email, phone, address, status, created_at, updated_at FROM users WHERE id = ? AND role = 'customer'");
    $stmt->execute([$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$account = loadCustomerAccount($db, $customerId);
if (!$account || $account['status'] !== 'active') {
    $_SESSION = [];
    session_regenerate_id(true);
    setFlash('error', 'Your account is not available. Please log in again or contact support.');
    header("Location: login.php", true, 303);
    exit;
}

$successMessage = getFlash('success');
$formError = getFlash('error');
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = new Validator($_POST);
    $name = $v->personName('full_name');
    $email = $v->email('email');
    $phone = $v->phone('phone');
    $address = $v->text('address', ['required' => true, 'min' => 5, 'max' => 500, 'multiline' => true,
        'minMessage' => 'Please enter your complete delivery address (at least 5 characters).']);
    $old = ['full_name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address];

    $emailChanged = !$v->hasError('email') && $email !== strtolower($account['email']);
    if ($emailChanged) {
        $dup = $db->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
        $dup->execute([$email, $customerId]);
        if ($dup->fetch()) {
            $v->addError('email', MSG_EMAIL_TAKEN);
        }
        $currentPassword = $v->loginPassword('current_password');
        if (!$v->hasError('current_password')) {
            $hashStmt = $db->prepare("SELECT password FROM users WHERE id = ? AND role = 'customer'");
            $hashStmt->execute([$customerId]);
            if (!password_verify($currentPassword, (string)$hashStmt->fetchColumn())) {
                $v->addError('current_password', MSG_CURRENT_PASSWORD);
            }
        }
    }

    $errors = $v->errors();
    if ($errors) {
        $formError = 'Your profile was not updated. ' . MSG_FORM_ERRORS;
        http_response_code(422);
    } elseif ($name === $account['full_name'] && !$emailChanged && $phone === (string)$account['phone'] && $address === (string)$account['address']) {
        setFlash('success', 'No changes were made to your profile.');
        header("Location: profile.php", true, 303);
        exit;
    } else {
        try {
            $db->beginTransaction();
            $update = $db->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, address = ? WHERE id = ? AND role = 'customer'");
            $update->execute([$name, $email, $phone, $address, $customerId]);
            if ($emailChanged) {
                $db->prepare("UPDATE contact_messages SET email = ? WHERE email = ?")->execute([$email, $account['email']]);
            }
            $db->commit();

            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_phone'] = $phone;
            $_SESSION['user_address'] = $address;
            logActivity($customerId, 'PROFILE_UPDATED', 'Customer updated profile' . ($emailChanged ? ' (email changed to ' . $email . ')' : ''));
            setFlash('success', 'Your profile has been updated successfully.');
            header("Location: profile.php", true, 303);
            exit;
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() === '23000') {
                $errors['email'] = MSG_EMAIL_TAKEN;
                $formError = 'Your profile was not updated. ' . MSG_EMAIL_TAKEN;
                http_response_code(409);
            } else {
                $formError = 'Your profile was not updated. ' . safeErrorMessage($e, 'Profile update failed');
                http_response_code(500);
            }
        }
    }
}

$statsStmt = $db->prepare("SELECT COUNT(*) AS total_orders, SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_orders, SUM(CASE WHEN status IN ('pending', 'processing', 'shipped') THEN 1 ELSE 0 END) AS open_orders FROM orders WHERE customer_id = ?");
$statsStmt->execute([$customerId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$reviewCount = 0;
try {
    ensureStoreSchema();
    $reviewStmt = $db->prepare("SELECT COUNT(*) FROM product_reviews WHERE customer_id = ? AND deleted_at IS NULL");
    $reviewStmt->execute([$customerId]);
    $reviewCount = (int)$reviewStmt->fetchColumn();
} catch (Exception $e) {
    logAppError('Loading review count failed', $e);
}

$form = $old ?: ['full_name' => $account['full_name'], 'email' => $account['email'], 'phone' => $account['phone'], 'address' => $account['address']];
$memberSince = date('F j, Y', strtotime($account['created_at']));
$lastUpdated = !empty($account['updated_at']) ? date('M j, Y g:i A', strtotime($account['updated_at'])) : null;

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
        padding: 0.75rem 1rem;
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
    .account-stat {
        background: rgba(255, 255, 255, 0.55);
        border: 1px solid rgba(74, 222, 128, 0.4);
        border-radius: 14px;
        padding: 0.85rem 1rem;
        text-align: center;
    }
    .account-stat strong {
        display: block;
        font-size: 1.4rem;
        color: #064e3b;
    }
    .account-stat span {
        font-size: 0.75rem;
        font-weight: 700;
        color: #166534;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .account-row {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.6rem 0;
        border-bottom: 1px solid rgba(74, 222, 128, 0.25);
        font-size: 0.9rem;
        color: #1f2937;
    }
    .account-row span:first-child {
        color: #166534;
        font-weight: 600;
    }
    .account-row span:last-child {
        text-align: right;
        word-break: break-word;
    }
</style>

<div class="container" style="max-width: 1100px; margin: 0 auto; padding-top: 1.5rem; padding-bottom: 3rem; padding-left: 1rem; padding-right: 1rem;">

    <div class="smiski-card" style="padding: 2rem 2.5rem; margin-bottom: 2rem;">
        <span style="background: rgba(255, 255, 255, 0.6); color: #064e3b; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.8rem; border-radius: 20px; display: inline-block; margin-bottom: 0.75rem; border: 1px solid rgba(74,222,128,0.6); box-shadow: 0 0 10px rgba(74,222,128,0.4);">
            <i class="fa-solid fa-user-gear"></i> MY ACCOUNT
        </span>
        <h1 class="neon-glow-text" style="font-size: 2.25rem; font-weight: 700; margin-bottom: 0.4rem;">My Profile</h1>
        <p class="text-muted-green" style="font-size: 1rem; line-height: 1.5;">View your account details and keep your contact and delivery information up to date.</p>
        <?php require __DIR__ . '/includes/account_nav.php'; ?>
    </div>

    <?= alertBox('success', $successMessage) ?>
    <?= alertBox('error', $formError) ?>

    <div style="display: flex; gap: 2rem; flex-wrap: wrap; align-items: flex-start;">

        <div class="smiski-card" style="flex: 1; min-width: 280px; padding: 1.75rem 2rem;">
            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
                <div style="width: 58px; height: 58px; border-radius: 50%; background: rgba(21, 128, 61, 0.15); border: 1px solid rgba(74, 222, 128, 0.6); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #15803d; flex-shrink: 0;">
                    <i class="fa-solid fa-user"></i>
                </div>
                <div style="min-width: 0;">
                    <h3 class="neon-glow-text" style="font-size: 1.2rem; font-weight: 700; word-break: break-word;"><?= htmlspecialchars($account['full_name']) ?></h3>
                    <div class="text-muted-green" style="font-size: 0.85rem; word-break: break-all;"><?= htmlspecialchars($account['email']) ?></div>
                </div>
            </div>

            <div class="account-row"><span>Member since</span><span><?= htmlspecialchars($memberSince) ?></span></div>
            <div class="account-row"><span>Account status</span><span><span style="background: rgba(220, 252, 231, 0.95); color: #15803d; border: 1px solid rgba(74, 222, 128, 0.7); font-size: 0.78rem; font-weight: 700; padding: 0.2rem 0.7rem; border-radius: 999px;"><?= htmlspecialchars(ucfirst($account['status'])) ?></span></span></div>
            <div class="account-row"><span>Phone</span><span><?= htmlspecialchars($account['phone'] ?? '') ?></span></div>
            <div class="account-row"><span>Delivery address</span><span><?= nl2br(htmlspecialchars($account['address'] ?? '')) ?></span></div>
            <?php if ($lastUpdated): ?>
                <div class="account-row" style="border-bottom: none;"><span>Last updated</span><span><?= htmlspecialchars($lastUpdated) ?></span></div>
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 0.75rem; margin-top: 1.25rem;">
                <div class="account-stat"><strong><?= (int)$stats['total_orders'] ?></strong><span>Orders</span></div>
                <div class="account-stat"><strong><?= (int)$stats['delivered_orders'] ?></strong><span>Completed</span></div>
                <div class="account-stat"><strong><?= (int)$stats['open_orders'] ?></strong><span>In Progress</span></div>
                <div class="account-stat"><strong><?= $reviewCount ?></strong><span>Reviews</span></div>
            </div>

            <a href="change_password.php" class="btn-green-secondary" style="width: 100%; justify-content: center; margin-top: 1.25rem; text-decoration: none;">
                <i class="fa-solid fa-key"></i> Change Password
            </a>
        </div>

        <div class="smiski-card" style="flex: 1.4; min-width: 300px; padding: 1.75rem 2rem;">
            <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem; border-bottom: 1px solid rgba(74, 222, 128, 0.3); padding-bottom: 0.75rem;">
                <i class="fa-solid fa-pen-to-square" style="color: #15803d;"></i> Edit Profile
            </h3>

            <form method="POST" action="profile.php" id="profileForm">
                <?= csrfField() ?>
                <div style="margin-bottom: 1.1rem;">
                    <label class="account-label" for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" required minlength="2" maxlength="100" autocomplete="name" value="<?= oldValue($form, 'full_name') ?>" class="account-input<?= invalidClass($errors, 'full_name') ?>"<?= invalidAttr($errors, 'full_name') ?>>
                    <?= fieldError($errors, 'full_name') ?>
                </div>

                <div style="margin-bottom: 1.1rem;">
                    <label class="account-label" for="email">Email Address</label>
                    <input type="email" id="email" name="email" required maxlength="100" autocomplete="email" data-original="<?= htmlspecialchars(strtolower($account['email'])) ?>" value="<?= oldValue($form, 'email') ?>" class="account-input<?= invalidClass($errors, 'email') ?>"<?= invalidAttr($errors, 'email') ?>>
                    <?= fieldError($errors, 'email') ?>
                    <small class="field-hint">Changing your email also moves your support messages to the new address.</small>
                </div>

                <div style="margin-bottom: 1.1rem;">
                    <label class="account-label" for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" required data-phone-input inputmode="numeric" pattern="[0-9]{7,15}" maxlength="15" autocomplete="tel" placeholder="09123456789" title="Numbers only (7 to 15 digits)" value="<?= oldValue($form, 'phone') ?>" class="account-input<?= invalidClass($errors, 'phone') ?>"<?= invalidAttr($errors, 'phone') ?>>
                    <?= fieldError($errors, 'phone') ?>
                </div>

                <div style="margin-bottom: 1.1rem;">
                    <label class="account-label" for="address">Delivery Address</label>
                    <textarea id="address" name="address" rows="3" required minlength="5" maxlength="500" data-counter class="account-input<?= invalidClass($errors, 'address') ?>"<?= invalidAttr($errors, 'address') ?> style="resize: vertical;"><?= oldValue($form, 'address') ?></textarea>
                    <?= fieldError($errors, 'address') ?>
                </div>

                <div id="currentPasswordGroup" style="margin-bottom: 1.5rem; <?= isset($errors['current_password']) || (($old['email'] ?? strtolower($account['email'])) !== strtolower($account['email'])) ? '' : 'display: none;' ?>">
                    <label class="account-label" for="current_password">Current Password</label>
                    <div style="position: relative;">
                        <input type="password" id="current_password" name="current_password" autocomplete="current-password" placeholder="Enter your current password" class="account-input<?= invalidClass($errors, 'current_password') ?>"<?= invalidAttr($errors, 'current_password') ?> style="padding-right: 2.75rem;">
                        <button type="button" onclick="togglePassword('current_password', 'currentPasswordIcon')" title="Show/Hide Password" style="position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #4b5563; font-size: 1rem;">
                            <i id="currentPasswordIcon" class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                    <?= fieldError($errors, 'current_password') ?>
                    <small class="field-hint">Required to confirm it's you when you change your email address.</small>
                </div>

                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <button type="submit" class="btn-green-primary" style="flex: 1; justify-content: center; min-width: 160px;">
                        <i class="fa-solid fa-floppy-disk"></i> Save Changes
                    </button>
                    <a href="profile.php" class="btn-green-secondary" style="flex: 1; justify-content: center; min-width: 140px; text-decoration: none;">
                        <i class="fa-solid fa-rotate-left"></i> Discard Changes
                    </a>
                </div>
            </form>
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

(function () {
    const emailInput = document.getElementById('email');
    const group = document.getElementById('currentPasswordGroup');
    const passwordInput = document.getElementById('current_password');
    function syncPasswordRequirement() {
        const changed = emailInput.value.trim().toLowerCase() !== emailInput.dataset.original;
        if (!changed && window.FormValidation) {
            FormValidation.clearError(passwordInput);
        }
        group.style.display = changed || passwordInput.classList.contains('is-invalid') ? '' : 'none';
        passwordInput.required = changed;
    }
    emailInput.addEventListener('input', syncPasswordRequirement);
    syncPasswordRequirement();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
