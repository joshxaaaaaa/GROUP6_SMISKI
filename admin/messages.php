<?php
require_once __DIR__ . '/../config/db.php';

if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit;
}

$db = getDB();
// Results of the last action (Post/Redirect/Get, so refreshing never repeats it)
$message = getFlash('success');
$error = getFlash('error');
$replyErrors = [];
$replyOld = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = new Validator($_POST);
    $action = $v->choice('action', ['reply', 'delete'], ['message' => MSG_INVALID_OPERATION]);
    $id = $v->id('id');
    if ($action === null || $id === null) {
        abortRequest(400, MSG_INVALID_OPERATION, 'messages.php');
    }
    $find = $db->prepare("SELECT * FROM contact_messages WHERE id = ?");
    $find->execute([$id]);
    $ticket = $find->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        abortRequest(404, 'This message no longer exists. It may have been deleted.', 'messages.php');
    }
    $userId = $_SESSION['user_id'] ?? null;

    if ($action === 'reply') {
        $replyText = $v->message('admin_reply', ['required' => true, 'min' => 2, 'max' => 2000,
            'minMessage' => 'Please write a reply of at least 2 characters.']);
        $replyErrors = $v->errors();

        if ($replyErrors) {
            $replyOld = $ticket;
            $replyOld['admin_reply'] = $replyText;
            $error = 'Reply not sent. ' . MSG_FORM_ERRORS;
            http_response_code(422);
        } else {
            try {
                $stmt = $db->prepare("UPDATE contact_messages SET admin_reply = ?, is_read = 1, replied_at = NOW() WHERE id = ?");
                $stmt->execute([$replyText, $id]);
                if (function_exists('logActivity') && $userId) {
                    logActivity($userId, 'MESSAGE_REPLIED', "Replied to customer message ID $id");
                }
                setFlash('success', 'Reply saved successfully! The customer can now see it under My Messages.');
                redirectTo('messages.php');
            } catch (Exception $e) {
                $replyOld = $ticket;
                $replyOld['admin_reply'] = $replyText;
                $error = 'Reply not sent. ' . safeErrorMessage($e, 'Message reply failed');
                http_response_code(500);
            }
        }
    }

    if ($action === 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM contact_messages WHERE id = ?");
            $stmt->execute([$id]);
            if (function_exists('logActivity') && $userId) {
                logActivity($userId, 'MESSAGE_DELETED', "Deleted message ID $id");
            }
            setFlash('success', 'Message deleted successfully!');
        } catch (Exception $e) {
            setFlash('error', 'Message not deleted. ' . safeErrorMessage($e, 'Message delete failed'));
        }
        redirectTo('messages.php');
    }
}

$messagesList = $db->query("SELECT * FROM contact_messages ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/header.php';
?>

<!-- Custom White Frosted Glassmorphism Messages Management Styles -->
<style>
    body {
        background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
        color: #1f2937 !important;
        font-family: 'Outfit', sans-serif;
        min-height: 100vh;
    }

    /* White Frosted Glass Card Effect */
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

    /* Pure White Glow Text for Headings on Dark Background */
    .white-glow-heading {
        color: #ffffff !important;
        text-shadow: 0 0 10px rgba(255, 255, 255, 0.8), 0 0 20px rgba(134, 239, 172, 0.6), 0 2px 4px rgba(0,0,0,0.3);
    }

    /* Green Glow-in-the-Dark Text Effect for White Backgrounds */
    .neon-glow-text {
        color: #064e3b !important;
        text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
    }

    .text-muted-green {
        color: #166534 !important;
        font-weight: 500;
    }

    /* Pure White Subtext for Dark Background */
    .white-subtext {
        color: rgba(255, 255, 255, 0.9) !important;
        font-weight: 500;
        text-shadow: 0 1px 3px rgba(0,0,0,0.3);
    }

    /* Form Inputs & Textarea */
    .smiski-input, .smiski-textarea {
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
        font-family: inherit;
    }
    .smiski-input::placeholder, .smiski-textarea::placeholder {
        color: #9ca3af;
    }
    .smiski-input:focus, .smiski-textarea:focus {
        border-color: #15803d !important;
        background: #ffffff !important;
        box-shadow: 0 0 15px rgba(74, 222, 128, 0.4);
    }

    /* Green Primary Button */
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

    /* Action Buttons Glow */
    .btn-action-view {
        background: rgba(255, 255, 255, 0.9);
        color: #15803d;
        border: 1px solid rgba(74, 222, 128, 0.6);
        padding: 0.4rem 0.8rem;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.8rem;
        font-weight: 600;
        transition: all 0.2s ease;
        width: 100%;
        text-align: left;
    }
    .btn-action-view:hover {
        background: #ffffff;
        border-color: #15803d;
        box-shadow: 0 0 12px rgba(74, 222, 128, 0.5);
    }

    .btn-action-reply {
        background: rgba(238, 242, 255, 0.9);
        color: #3730a3;
        border: 1px solid rgba(129, 140, 248, 0.6);
        padding: 0.4rem 0.8rem;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.8rem;
        font-weight: 600;
        transition: all 0.2s ease;
        width: 100%;
        text-align: left;
    }
    .btn-action-reply:hover {
        background: #ffffff;
        border-color: #6366f1;
        box-shadow: 0 0 12px rgba(129,140,248,0.6);
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
        width: 100%;
        text-align: left;
    }
    .btn-action-delete:hover {
        background: #ffffff;
        border-color: #dc2626;
        box-shadow: 0 0 12px rgba(248,113,113,0.6);
    }

    .custom-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
    }
    .custom-table th {
        background: rgba(220, 252, 231, 0.5);
        color: #064e3b;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 2px solid rgba(74, 222, 128, 0.4);
        padding: 1rem;
    }
    .custom-table td {
        padding: 0.9rem 1rem;
        border-bottom: 1px solid rgba(74, 222, 128, 0.2);
        font-size: 0.9rem;
        color: #1f2937;
    }
    .custom-table tr:hover {
        background: rgba(220, 252, 231, 0.4);
    }
</style>

<div class="container" style="padding: 2rem 1rem; max-width: 1300px; margin: 0 auto;">

    <!-- Page Header -->
    <div style="margin-bottom: 1.5rem;">
        <h2 class="white-glow-heading" style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.025em; margin-bottom: 0.25rem;">
            <i class="fa-solid fa-comments" style="color: #86efac;"></i> Customer Messages
        </h2>
        <p class="white-subtext" style="font-size: 0.9rem;">View and manage inquiries sent through the Contact Us form</p>
    </div>

    <!-- Alert Messages -->
    <?php if ($message): ?>
        <div style="background: rgba(220, 252, 231, 0.95); border: 1px solid rgba(74, 222, 128, 0.8); color: #15803d; padding: 0.85rem 1rem; border-radius: 12px; font-size: 0.9rem; margin-bottom: 1.5rem; font-weight: 600; backdrop-filter: blur(10px); box-shadow: 0 0 15px rgba(74, 222, 128, 0.3);">
            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div style="background: rgba(254, 226, 226, 0.95); border: 1px solid rgba(248, 113, 113, 0.8); color: #dc2626; padding: 0.85rem 1rem; border-radius: 12px; font-size: 0.9rem; margin-bottom: 1.5rem; font-weight: 600; backdrop-filter: blur(10px); box-shadow: 0 0 15px rgba(248, 113, 113, 0.3);">
            <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Main Table Container -->
    <div class="smiski-glass-card" style="overflow: hidden;">
        <div style="overflow-x: auto;">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Sender</th>
                        <th>Subject & Message</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($messagesList)): ?>
                        <tr><td colspan="6" class="text-muted-green" style="text-align: center; padding: 3rem; font-size: 0.95rem;">No customer messages found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($messagesList as $index => $m): ?>
                            <tr>
                                <td style="font-weight: 700; color: #064e3b; vertical-align: top;"><?= $m['id'] ?></td>
                                <td style="vertical-align: top;">
                                    <div style="font-weight: 700; color: #064e3b; font-size: 0.9rem;"><?= htmlspecialchars($m['name']) ?></div>
                                    <div class="text-muted-green" style="font-size: 0.78rem; margin-top: 0.15rem;"><?= htmlspecialchars($m['email']) ?></div>
                                </td>
                                <td style="vertical-align: top; max-width: 350px;">
                                    <div style="font-weight: 700; color: #15803d; font-size: 0.92rem; margin-bottom: 0.25rem;">
                                        <?= htmlspecialchars($m['subject']) ?>
                                    </div>
                                    <div style="color: #374151; font-size: 0.88rem; line-height: 1.4; word-break: break-word;">
                                        <?= nl2br(htmlspecialchars($m['message'])) ?>
                                    </div>
                                </td>
                                <td class="text-muted-green" style="font-size: 0.82rem; white-space: nowrap; vertical-align: top;"><?= date('M d, Y h:i A', strtotime($m['created_at'])) ?></td>
                                <td style="vertical-align: top;">
                                    <?php if (!empty($m['admin_reply'])): ?>
                                        <span style="background: rgba(220, 252, 231, 0.9); color: #15803d; border: 1px solid rgba(74, 222, 128, 0.6); padding: 0.25rem 0.6rem; border-radius: 8px; font-size: 0.75rem; font-weight: 700; display: inline-block;">
                                            <i class="fa-solid fa-check-double"></i> Replied
                                        </span>
                                    <?php elseif ($m['is_read']): ?>
                                        <span style="background: rgba(224, 242, 254, 0.9); color: #0369a1; border: 1px solid rgba(56, 189, 248, 0.6); padding: 0.25rem 0.6rem; border-radius: 8px; font-size: 0.75rem; font-weight: 700; display: inline-block;">Read</span>
                                    <?php else: ?>
                                        <span style="background: rgba(254, 243, 199, 0.9); color: #92400e; border: 1px solid rgba(251, 191, 36, 0.6); padding: 0.25rem 0.6rem; border-radius: 8px; font-size: 0.75rem; font-weight: 700; display: inline-block;">New</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center; white-space: nowrap; vertical-align: top;">
                                    <div style="display: inline-flex; flex-direction: column; gap: 0.4rem; min-width: 110px;">
                                        <button type="button" onclick='openViewModal(<?= json_encode($m, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="btn-action-view" title="View Full Message">
                                            <i class="fa-solid fa-eye"></i> View Full
                                        </button>
                                        <button type="button" onclick='openReplyModal(<?= json_encode($m, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="btn-action-reply" title="Reply to Message">
                                            <i class="fa-solid fa-reply"></i> Reply
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this message?')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                            <button type="submit" class="btn-action-delete" title="Delete Message">
                                                <i class="fa-solid fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- View Modal -->
<div id="viewModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(5, 46, 22, 0.85); backdrop-filter: blur(10px); align-items: center; justify-content: center; z-index: 2000;">
    <div class="smiski-glass-card" style="padding: 2rem; width: 100%; max-width: 550px; position: relative; max-height: 90vh; overflow-y: auto; color: #1f2937;">
        <button type="button" onclick="closeViewModal()" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #15803d;" onmouseover="this.style.color='#064e3b'" onmouseout="this.style.color='#15803d'">&times;</button>
        <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem;">View Customer Message</h3>

        <div style="display: flex; flex-direction: column; gap: 0.85rem; font-size: 0.9rem; color: #374151;">
            <div><strong style="color: #1f2937;">From:</strong> <span id="view_name" style="font-weight: 600; color: #15803d;"></span> (<span class="text-muted-green" id="view_email"></span>)</div>
            <div><strong style="color: #1f2937;">Subject:</strong> <span id="view_subject" style="font-weight: 700; color: #064e3b;"></span></div>
            <div><strong style="color: #1f2937;">Date Sent:</strong> <span class="text-muted-green" id="view_date"></span></div>
            <div>
                <strong style="color: #1f2937;">Message:</strong>
                <p id="view_msg" style="margin-top: 0.4rem; background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(74, 222, 128, 0.4); padding: 0.9rem; border-radius: 12px; line-height: 1.5; color: #1f2937; white-space: pre-line;"></p>
            </div>
            <div id="replyContainer" style="display: none; margin-top: 0.5rem;">
                <strong style="color: #15803d;">Admin Reply:</strong>
                <p id="view_reply" style="margin-top: 0.4rem; background: rgba(220, 252, 231, 0.9); border: 1px solid rgba(74, 222, 128, 0.6); padding: 0.9rem; border-radius: 12px; line-height: 1.5; color: #064e3b; white-space: pre-line;"></p>
                <div class="text-muted-green" style="font-size: 0.75rem; margin-top: 0.3rem;">Replied on: <span id="view_replied_at"></span></div>
            </div>
        </div>
    </div>
</div>

<!-- Reply Modal -->
<div id="replyModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(5, 46, 22, 0.85); backdrop-filter: blur(10px); align-items: center; justify-content: center; z-index: 2000;">
    <div class="smiski-glass-card" style="padding: 2rem; width: 100%; max-width: 500px; position: relative; color: #1f2937;">
        <button type="button" onclick="closeReplyModal()" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #15803d;" onmouseover="this.style.color='#064e3b'" onmouseout="this.style.color='#15803d'">&times;</button>
        <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem;">Reply to Message</h3>

        <form method="POST" id="replyForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="id" id="reply_id">

            <div style="margin-bottom: 0.75rem; font-size: 0.9rem;" class="text-muted-green">
                <strong style="color: #1f2937;">To:</strong> <span id="reply_to" style="color: #15803d; font-weight: 600;"></span>
            </div>
            <div style="margin-bottom: 1rem; font-size: 0.9rem;" class="text-muted-green">
                <strong style="color: #1f2937;">Subject:</strong> <span id="reply_subj" style="color: #064e3b; font-weight: 600;"></span>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Admin Reply Message</label>
                <textarea name="admin_reply" id="reply_text" rows="5" required minlength="2" maxlength="2000" data-counter<?= invalidAttr($replyErrors, 'admin_reply') ?> placeholder="Type your response here..." class="smiski-textarea<?= invalidClass($replyErrors, 'admin_reply') ?>"></textarea>
                <?= fieldError($replyErrors, 'admin_reply') ?>
            </div>

            <button type="submit" class="btn-green-glow" style="width: 100%; padding: 0.75rem; font-size: 0.95rem; font-weight: 700; border-radius: 12px;">
                <i class="fa-solid fa-paper-plane"></i> Send Reply
            </button>
        </form>
    </div>
</div>

<script>
function openViewModal(m) {
    document.getElementById('view_name').innerText = m.name;
    document.getElementById('view_email').innerText = m.email;
    document.getElementById('view_subject').innerText = m.subject;
    document.getElementById('view_date').innerText = m.created_at;
    document.getElementById('view_msg').innerText = m.message;

    if (m.admin_reply) {
        document.getElementById('replyContainer').style.display = 'block';
        document.getElementById('view_reply').innerText = m.admin_reply;
        document.getElementById('view_replied_at').innerText = m.replied_at || 'N/A';
    } else {
        document.getElementById('replyContainer').style.display = 'none';
    }

    document.getElementById('viewModal').style.display = 'flex';
}
function closeViewModal() { document.getElementById('viewModal').style.display = 'none'; }

function openReplyModal(m, keepErrors) {
    if (!keepErrors && window.FormValidation) FormValidation.clearErrors(document.getElementById('replyForm'));
    document.getElementById('reply_id').value = m.id;
    document.getElementById('reply_to').innerText = m.name + ' (' + m.email + ')';
    document.getElementById('reply_subj').innerText = m.subject;
    document.getElementById('reply_text').value = m.admin_reply || '';
    document.getElementById('replyModal').style.display = 'flex';
}
function closeReplyModal() { document.getElementById('replyModal').style.display = 'none'; }
<?php if ($replyOld !== null): ?>
// The last reply failed validation: show the form again with the typed reply and the error
document.addEventListener('DOMContentLoaded', function () {
    openReplyModal(<?= json_encode($replyOld, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, true);
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>