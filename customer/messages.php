<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/db.php';

$db = getDB();

$isLoggedIn = !empty($_SESSION['user_id']) || !empty($_SESSION['user_email']);
$userEmail = $_SESSION['user_email'] ?? '';

$messageSuccess = $_SESSION['support_flash_success'] ?? '';
$messageError = $_SESSION['support_flash_error'] ?? '';
unset($_SESSION['support_flash_success'], $_SESSION['support_flash_error']);
$formSubject = '';
$formMessage = '';
$reopenForm = false;

$msgErrors = [];
$msgOld = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    if (!$isLoggedIn) {
        abortRequest(401, 'Please log in to send a support inquiry.', 'login.php');
    }
    if (isAdminLoggedIn()) {
        abortRequest(403, MSG_UNAUTHORIZED . ' Support inquiries can only be sent from customer accounts.', 'messages.php');
    }

    [$values, $msgErrors] = validateSupportMessage($_POST, $userEmail);
    $msgOld = $values;
    $formSubject = $values['subject'];
    $formMessage = $values['message'];

    if ($msgErrors) {
        $messageError = 'Your message was not sent. ' . MSG_FORM_ERRORS;
        $reopenForm = true;
        http_response_code(422);
    } elseif (isMessageRateLimited($values['email'])) {
        $messageError = MSG_RATE_LIMITED;
        $reopenForm = true;
        http_response_code(429);
    } elseif (!consumeFormToken('support_message', $_POST['form_token'] ?? '')) {
        $_SESSION['support_flash_error'] = MSG_DUPLICATE . ' Your ticket is listed below.';
        header("Location: messages.php", true, 303);
        exit;
    } else {
        try {
            saveSupportMessage($values);
            $_SESSION['support_flash_success'] = 'Your message has been sent successfully! Our support team will reply here.';
            header("Location: messages.php", true, 303);
            exit;
        } catch (Exception $e) {
            $messageError = 'Your message was not sent. ' . safeErrorMessage($e, 'Support message save failed');
            $reopenForm = true;
            http_response_code(500);
        }
    }
}

$supportFormToken = $isLoggedIn ? generateFormToken('support_message') : '';

$myMessages = [];
if ($isLoggedIn && !empty($userEmail)) {
    try {
        $stmt = $db->prepare("SELECT * FROM contact_messages WHERE email = ? ORDER BY id DESC");
        $stmt->execute([$userEmail]);
        $myMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $myMessages = [];
    }
}

if (file_exists('includes/header.php')) {
    include 'includes/header.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Support & Contact Messages</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
    </style>
</head>
<body>

<div class="container" style="max-width: 1100px; margin: 0 auto; padding-top: 1.5rem; padding-bottom: 3rem; padding-left: 1rem; padding-right: 1rem;">
    
    <div class="smiski-card" style="padding: 2rem 2.5rem; margin-bottom: 2rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1.5rem;">
            <div>
                <span style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); color: #064e3b; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.8rem; border-radius: 20px; display: inline-block; margin-bottom: 0.75rem; border: 1px solid rgba(74,222,128,0.6); box-shadow: 0 0 10px rgba(74,222,128,0.4);">
                    <i class="fa-solid fa-headset"></i> CUSTOMER SUPPORT
                </span>
                <h1 class="neon-glow-text" style="font-size: 2.25rem; font-weight: 700; margin-bottom: 0.4rem;">Support & Messages</h1>
                <p class="text-muted-green" style="font-size: 1rem; line-height: 1.5;">View your past inquiries and official responses from our support team.</p>
            </div>
            <div>
                <?php if ($isLoggedIn): ?>
                    <button type="button" onclick="openContactPane()" class="btn-green-primary">
                        <i class="fa-solid fa-paper-plane"></i> Send Message
                    </button>
                <?php else: ?>
                    <a href="login.php" class="btn-green-primary" style="text-decoration: none;">
                        <i class="fa-solid fa-right-to-bracket"></i> Login to Inquire
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($messageSuccess): ?>
        <div class="smiski-card" style="background: rgba(220, 252, 231, 0.95) !important; border: 1px solid rgba(74, 222, 128, 0.8); color: #166534; padding: 1.25rem 1.5rem; border-radius: 16px; margin-bottom: 2rem; font-weight: 600; display: flex; align-items: center; gap: 0.75rem;">
            <i class="fa-solid fa-circle-check" style="font-size: 1.4rem; color: #15803d;"></i>
            <span><?= htmlspecialchars($messageSuccess) ?></span>
        </div>
    <?php endif; ?>
    
    <?php if ($messageError): ?>
        <div class="smiski-card" style="background: rgba(254, 226, 226, 0.95) !important; border: 1px solid rgba(248, 113, 113, 0.8); color: #991b1b; padding: 1.25rem 1.5rem; border-radius: 16px; margin-bottom: 2rem; font-weight: 600; display: flex; align-items: center; gap: 0.75rem;">
            <i class="fa-solid fa-circle-exclamation" style="font-size: 1.4rem; color: #dc2626;"></i>
            <span><?= htmlspecialchars($messageError) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!$isLoggedIn): ?>
        <div class="smiski-card" style="padding: 4rem 2rem; text-align: center;">
            <i class="fa-solid fa-lock" style="font-size: 4rem; color: #166534; opacity: 0.8; margin-bottom: 1.25rem; filter: drop-shadow(0 0 10px rgba(74,222,128,0.5));"></i>
            <h3 class="neon-glow-text" style="font-weight: 700; font-size: 1.5rem;">Authentication Required</h3>
            <p class="text-muted-green" style="font-size: 1rem; margin-top: 0.5rem; margin-bottom: 1.75rem;">Please log in to your account to view your messages and send inquiries.</p>
            <a href="login.php" class="btn-green-primary" style="text-decoration: none; padding: 0.85rem 1.75rem; border-radius: 14px;">Login Now</a>
        </div>
    <?php elseif (empty($myMessages)): ?>
        <div class="smiski-card" style="padding: 4rem 2rem; text-align: center;">
            <i class="fa-solid fa-comments" style="font-size: 4rem; color: #166534; opacity: 0.8; margin-bottom: 1.25rem; filter: drop-shadow(0 0 10px rgba(74,222,128,0.5));"></i>
            <h3 class="neon-glow-text" style="font-weight: 700; font-size: 1.5rem;">No Messages Found</h3>
            <p class="text-muted-green" style="font-size: 1rem; margin-top: 0.5rem; margin-bottom: 1.75rem;">You haven't submitted any support inquiries yet.</p>
            <button type="button" onclick="openContactPane()" class="btn-green-primary" style="padding: 0.85rem 1.75rem; border-radius: 14px;">Send First Message</button>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <?php foreach ($myMessages as $m): ?>
                <?php 
                    $hasReply = !empty($m['admin_reply'] ?? null); 
                    $rawReplyTime = $m['replied_at'] ?? ($m['updated_at'] ?? $m['created_at']);
                    $replyTime = date('M d, Y - h:i A', strtotime($rawReplyTime));
                ?>
                <div class="smiski-card" style="padding: 1.75rem 2rem;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.25rem; border-bottom: 1px solid rgba(74, 222, 128, 0.3); padding-bottom: 1rem;">
                        <div>
                            <span style="font-size: 0.78rem; color: #166534; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">SUPPORT TICKET</span>
                            <div class="text-muted-green" style="font-size: 0.85rem; font-weight: 600; margin-top: 0.2rem;">Ticket #<?= $m['id'] ?> &bull; Date: <?= date('M d, Y h:i A', strtotime($m['created_at'])) ?></div>
                        </div>
                        <div>
                            <?php if ($hasReply): ?>
                                <span style="background: rgba(22, 101, 52, 0.15); color: #166534; border: 1px solid rgba(74, 222, 128, 0.6); font-size: 0.85rem; font-weight: 700; padding: 0.35rem 0.9rem; border-radius: 999px; display: inline-flex; align-items: center; gap: 0.35rem;">
                                    <i class="fa-solid fa-circle-check"></i> Replied
                                </span>
                            <?php else: ?>
                                <span style="background: rgba(202, 138, 4, 0.15); color: #854d0e; border: 1px solid rgba(202, 138, 4, 0.4); font-size: 0.85rem; font-weight: 700; padding: 0.35rem 0.9rem; border-radius: 999px; display: inline-flex; align-items: center; gap: 0.35rem;">
                                    <i class="fa-solid fa-clock"></i> Pending
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="margin-bottom: 1.25rem;">
                        <span class="text-muted-green" style="font-size: 0.8rem; font-weight: 700; display: block; margin-bottom: 0.3rem; text-transform: uppercase; letter-spacing: 0.05em;">Subject</span>
                        <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin: 0;"><?= htmlspecialchars($m['subject']) ?></h3>
                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <div class="text-muted-green" style="font-size: 0.8rem; font-weight: 700; margin-bottom: 0.4rem; text-transform: uppercase; letter-spacing: 0.05em;">Your Message</div>
                        <div class="smiski-subcard" style="padding: 1.25rem; color: #1f2937; font-size: 0.98rem; line-height: 1.6;"><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                    </div>

                    <?php if ($hasReply): ?>
                        <div class="smiski-subcard" style="background: rgba(240, 253, 244, 0.85) !important; border: 1px solid rgba(74, 222, 128, 0.6) !important; padding: 1.5rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
                                <span class="neon-glow-text" style="font-weight: 700; font-size: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                                    <i class="fa-solid fa-headset" style="color: #15803d;"></i> Smiski Support Team
                                </span>
                                <span class="text-muted-green" style="font-size: 0.8rem;">
                                    <i class="fa-regular fa-calendar-days"></i> <?= $replyTime ?>
                                </span>
                            </div>
                            <div style="font-size: 0.98rem; color: #1f2937; line-height: 1.6; white-space: pre-line;"><?= nl2br(htmlspecialchars($m['admin_reply'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($isLoggedIn): ?>
<div id="contactPane" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.65); backdrop-filter: blur(8px); z-index: 1000; align-items: center; justify-content: center; padding: 1rem;">
    <div class="smiski-card" style="width: 100%; max-width: 540px; padding: 2.5rem; position: relative;">
        <button type="button" onclick="closeContactPane()" style="position: absolute; top: 1.25rem; right: 1.5rem; background: none; border: none; font-size: 1.75rem; cursor: pointer; color: #166534; transition: color 0.2s;" onmouseover="this.style.color='#064e3b'" onmouseout="this.style.color='#166534'">&times;</button>
        <span style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); color: #064e3b; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.8rem; border-radius: 20px; display: inline-block; margin-bottom: 0.75rem; border: 1px solid rgba(74,222,128,0.6);">NEW MESSAGE</span>
        <h3 class="neon-glow-text" style="margin-bottom: 1.5rem; font-size: 1.5rem; font-weight: 700;">Send Support Inquiry</h3>
        <form method="POST" action="messages.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="send_message">
            <input type="hidden" name="form_token" value="<?= htmlspecialchars($supportFormToken) ?>">
            <div style="margin-bottom: 1.15rem;">
                <label class="text-muted-green" style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 0.35rem;">Name</label>
                <input type="text" name="name" required minlength="2" maxlength="100" value="<?= oldValue($msgOld, 'name', $_SESSION['user_name'] ?? '') ?>"<?= invalidAttr($msgErrors, 'name') ?> class="hover-scale<?= invalidClass($msgErrors, 'name') ?>" style="width: 100%; padding: 0.75rem 1rem; background: rgba(255, 255, 255, 0.8); border: 1px solid rgba(74, 222, 128, 0.5); border-radius: 12px; color: #1f2937; outline: none; backdrop-filter: blur(10px);">
                <?= fieldError($msgErrors, 'name') ?>
            </div>
            <div style="margin-bottom: 1.15rem;">
                <label class="text-muted-green" style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 0.35rem;">Email</label>
                <input type="email" name="email" required readonly value="<?= htmlspecialchars($userEmail) ?>" style="width: 100%; padding: 0.75rem 1rem; background: rgba(255, 255, 255, 0.4); border: 1px solid rgba(74, 222, 128, 0.3); border-radius: 12px; color: #4b5563; outline: none; cursor: not-allowed;">
            </div>
            <div style="margin-bottom: 1.15rem;">
                <label class="text-muted-green" style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 0.35rem;">Subject</label>
                <input type="text" name="subject" required minlength="3" maxlength="200" value="<?= htmlspecialchars($formSubject) ?>"<?= invalidAttr($msgErrors, 'subject') ?> class="hover-scale<?= invalidClass($msgErrors, 'subject') ?>" style="width: 100%; padding: 0.75rem 1rem; background: rgba(255, 255, 255, 0.8); border: 1px solid rgba(74, 222, 128, 0.5); border-radius: 12px; color: #1f2937; outline: none; backdrop-filter: blur(10px);">
                <?= fieldError($msgErrors, 'subject') ?>
            </div>
            <div style="margin-bottom: 1.75rem;">
                <label class="text-muted-green" style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 0.35rem;">Message</label>
                <textarea name="message" rows="4" required minlength="10" maxlength="2000" data-counter<?= invalidAttr($msgErrors, 'message') ?> class="hover-scale<?= invalidClass($msgErrors, 'message') ?>" style="width: 100%; padding: 0.75rem 1rem; background: rgba(255, 255, 255, 0.8); border: 1px solid rgba(74, 222, 128, 0.5); border-radius: 12px; color: #1f2937; outline: none; resize: vertical; backdrop-filter: blur(10px);"><?= htmlspecialchars($formMessage) ?></textarea>
                <?= fieldError($msgErrors, 'message') ?>
            </div>
            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                <button type="button" onclick="closeContactPane()" class="btn-green-secondary">Cancel</button>
                <button type="submit" class="btn-green-primary">Submit Inquiry</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openContactPane() { document.getElementById('contactPane').style.display = 'flex'; }
function closeContactPane() { document.getElementById('contactPane').style.display = 'none'; }

<?php if ($reopenForm): ?>
    window.addEventListener('DOMContentLoaded', () => {
        openContactPane();
    });
<?php endif; ?>
</script>

</body>
</html>