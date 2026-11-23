<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit;
}

$db = getDB();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $name = sanitize($_POST['full_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $status = sanitize($_POST['status'] ?? 'active');

        if ($id > 0 && !empty($name) && !empty($email)) {
            $stmt = $db->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, address = ?, status = ? WHERE id = ? AND role = 'customer'");
            $stmt->execute([$name, $email, $phone, $address, $status, $id]);
            logActivity($_SESSION['user_id'], 'CUSTOMER_UPDATED', "Updated customer record ID $id");
            $message = 'Customer record updated successfully!';
        }
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role = 'customer'");
            $stmt->execute([$id]);
            logActivity($_SESSION['user_id'], 'CUSTOMER_DELETED', "Deleted customer ID $id");
            $message = 'Customer record deleted successfully!';
        }
    }
}

$customers = $db->query("SELECT * FROM users WHERE role = 'customer' ORDER BY id DESC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Custom White Frosted Glassmorphism Customers Management Styles -->
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

    /* Input & Select Controls for White Cards */
    .smiski-input, .smiski-select, .smiski-textarea {
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
    .smiski-input:focus, .smiski-select:focus, .smiski-textarea:focus {
        border-color: #15803d !important;
        background: #ffffff !important;
        box-shadow: 0 0 15px rgba(74, 222, 128, 0.4);
    }
    .smiski-select option {
        background: #ffffff;
        color: #1f2937;
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

    /* Action Buttons */
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
</style>

<div class="container" style="padding: 2rem 1rem; max-width: 1300px; margin: 0 auto;">

    <!-- Page Header -->
    <div style="margin-bottom: 1.5rem;">
        <h2 class="white-glow-heading" style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.025em; margin-bottom: 0.25rem;">
            <i class="fa-solid fa-users" style="color: #86efac;"></i> Customer Records Management
        </h2>
        <p class="white-subtext" style="font-size: 0.9rem;">View and manage registered customer user accounts</p>
    </div>

    <!-- Alert Message -->
    <?php if ($message): ?>
        <div style="background: rgba(220, 252, 231, 0.95); border: 1px solid rgba(74, 222, 128, 0.6); color: #064e3b; padding: 0.85rem 1rem; border-radius: 12px; font-size: 0.9rem; margin-bottom: 1.5rem; font-weight: 600; box-shadow: 0 5px 15px rgba(0,0,0,0.15);">
            <i class="fa-solid fa-circle-check" style="color: #15803d;"></i> <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Main Table Container -->
    <div class="smiski-glass-card" style="overflow: hidden;">
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="background: rgba(220, 252, 231, 0.5); color: #064e3b; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid rgba(74, 222, 128, 0.4);">
                        <th style="padding: 1rem;">ID</th>
                        <th style="padding: 1rem;">Name</th>
                        <th style="padding: 1rem;">Email</th>
                        <th style="padding: 1rem;">Phone</th>
                        <th style="padding: 1rem;">Address</th>
                        <th style="padding: 1rem;">Status</th>
                        <th style="padding: 1rem; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                        <tr><td colspan="7" class="text-muted-green" style="text-align: center; padding: 3rem; font-size: 0.95rem;">No customer records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($customers as $index => $c): ?>
                            <tr style="border-bottom: 1px solid rgba(74, 222, 128, 0.2); transition: background 0.2s;" onmouseenter="this.style.background='rgba(220, 252, 231, 0.4)'" onmouseleave="this.style.background='transparent'">
                                <td style="padding: 0.9rem 1rem; font-size: 0.9rem; font-weight: 700; color: #064e3b;"><?= $c['id'] ?></td>
                                <td style="padding: 0.9rem 1rem; font-weight: 700; color: #064e3b; font-size: 0.9rem;"><?= htmlspecialchars($c['full_name']) ?></td>
                                <td class="text-muted-green" style="padding: 0.9rem 1rem; font-size: 0.9rem;"><?= htmlspecialchars($c['email']) ?></td>
                                <td class="text-muted-green" style="padding: 0.9rem 1rem; font-size: 0.85rem;"><?= htmlspecialchars($c['phone'] ?? 'N/A') ?></td>
                                <td class="text-muted-green" style="padding: 0.9rem 1rem; font-size: 0.85rem; max-width: 200px;"><?= htmlspecialchars($c['address'] ?? 'N/A') ?></td>
                                <td style="padding: 0.9rem 1rem;">
                                    <span style="background: <?= $c['status'] === 'active' ? 'rgba(220, 252, 231, 0.9)' : 'rgba(254, 243, 199, 0.9)' ?>; color: <?= $c['status'] === 'active' ? '#15803d' : '#92400e' ?>; border: 1px solid <?= $c['status'] === 'active' ? 'rgba(74, 222, 128, 0.6)' : 'rgba(251, 191, 36, 0.6)' ?>; padding: 0.25rem 0.6rem; border-radius: 8px; font-size: 0.75rem; font-weight: 700; display: inline-block;">
                                        <?= ucfirst($c['status']) ?>
                                    </span>
                                </td>
                                <td style="padding: 0.9rem 1rem; text-align: center;">
                                    <div style="display: inline-flex; gap: 0.4rem;">
                                        <button onclick='openViewModal(<?= json_encode($c) ?>)' class="btn-action-view" title="View Customer Details">
                                            <i class="fa-solid fa-eye"></i> View
                                        </button>
                                        <button onclick='openEditModal(<?= json_encode($c) ?>)' class="btn-action-edit" title="Edit Customer">
                                            <i class="fa-solid fa-pen-to-square"></i> Edit
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this customer record?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                            <button type="submit" class="btn-action-delete" title="Delete Customer">
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
    <div class="smiski-glass-card" style="padding: 2rem; width: 100%; max-width: 500px; position: relative; color: #1f2937;">
        <button onclick="closeViewModal()" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #15803d;" onmouseover="this.style.color='#064e3b'" onmouseout="this.style.color='#15803d'">&times;</button>
        <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem;">Customer Details</h3>

        <div style="display: flex; flex-direction: column; gap: 0.85rem; font-size: 0.9rem; color: #374151;">
            <div><strong style="color: #1f2937;">ID:</strong> <span id="view_id" style="color: #15803d; font-weight: 600;"></span></div>
            <div><strong style="color: #1f2937;">Full Name:</strong> <span id="view_name" style="font-weight: 600; color: #064e3b;"></span></div>
            <div><strong style="color: #1f2937;">Email Address:</strong> <span id="view_email"></span></div>
            <div><strong style="color: #1f2937;">Phone Number:</strong> <span id="view_phone"></span></div>
            <div><strong style="color: #1f2937;">Address:</strong> <span id="view_address"></span></div>
            <div><strong style="color: #1f2937;">Account Status:</strong> <span id="view_status" style="font-weight: 700;"></span></div>
            <div><strong style="color: #1f2937;">Created At:</strong> <span id="view_created"></span></div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(5, 46, 22, 0.85); backdrop-filter: blur(10px); align-items: center; justify-content: center; z-index: 2000;">
    <div class="smiski-glass-card" style="padding: 2rem; width: 100%; max-width: 500px; position: relative; max-height: 90vh; overflow-y: auto; color: #1f2937;">
        <button onclick="closeEditModal()" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #15803d;" onmouseover="this.style.color='#064e3b'" onmouseout="this.style.color='#15803d'">&times;</button>
        <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem;">Edit Customer</h3>

        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Full Name</label>
                <input type="text" name="full_name" id="edit_name" required class="smiski-input">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Email</label>
                <input type="email" name="email" id="edit_email" required class="smiski-input">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Phone</label>
                <input type="text" name="phone" id="edit_phone" required class="smiski-input">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Account Status</label>
                <select name="status" id="edit_status" class="smiski-select">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Address</label>
                <textarea name="address" id="edit_address" rows="3" required class="smiski-textarea"></textarea>
            </div>

            <button type="submit" class="btn-green-glow" style="width: 100%; padding: 0.75rem; font-size: 0.95rem; font-weight: 700; border-radius: 12px;">Save Customer Changes</button>
        </form>
    </div>
</div>

<script>
function openViewModal(c) {
    document.getElementById('view_id').innerText = c.id;
    document.getElementById('view_name').innerText = c.full_name;
    document.getElementById('view_email').innerText = c.email;
    document.getElementById('view_phone').innerText = c.phone || 'N/A';
    document.getElementById('view_address').innerText = c.address || 'N/A';
    document.getElementById('view_status').innerText = c.status;
    document.getElementById('view_created').innerText = c.created_at;
    document.getElementById('viewModal').style.display = 'flex';
}
function closeViewModal() { document.getElementById('viewModal').style.display = 'none'; }

function openEditModal(c) {
    document.getElementById('edit_id').value = c.id;
    document.getElementById('edit_name').value = c.full_name;
    document.getElementById('edit_email').value = c.email;
    document.getElementById('edit_phone').value = c.phone || '';
    document.getElementById('edit_status').value = c.status;
    document.getElementById('edit_address').value = c.address || '';
    document.getElementById('editModal').style.display = 'flex';
}
function closeEditModal() { document.getElementById('editModal').style.display = 'none'; }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>