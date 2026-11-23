<?php
require_once __DIR__ . '/../config/db.php';

if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit;
}

$db = getDB();

$actionsList = [
    'ADMIN_LOGIN',
    'CUSTOMER_DELETED',
    'CUSTOMER_LOGIN',
    'CUSTOMER_LOGOUT',
    'CUSTOMER_REGISTER',
    'CUSTOMER_UPDATED',
    'MESSAGE_DELETED',
    'MESSAGE_REPLIED',
    'ORDER_DELETED',
    'ORDER_PLACED',
    'ORDER_STATUS_UPDATED',
    'PRODUCT_ADDED',
    'PRODUCT_DEACTIVATED',
    'PRODUCT_DELETED',
    'PRODUCT_UPDATED'
];

$selectedActions = $_GET['actions'] ?? [];
if (!is_array($selectedActions)) {
    $selectedActions = [$selectedActions];
}
$selectedActions = array_filter($selectedActions);

$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT a.*, u.full_name, u.email, u.role
    FROM activity_logs a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $sql .= " AND (a.action LIKE ? OR a.description LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($selectedActions)) {
    $inClause = implode(',', array_fill(0, count($selectedActions), '?'));
    $sql .= " AND a.action IN ($inClause)";
    foreach ($selectedActions as $act) {
        $params[] = $act;
    }
}

$sql .= " ORDER BY a.id DESC LIMIT 200";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Custom White Frosted Glassmorphism Audit Logs Styles -->
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

    /* Input Controls for White Cards */
    .smiski-input {
        width: 100%;
        padding: 0.75rem 1rem 0.75rem 2.5rem;
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
    .smiski-input::placeholder {
        color: #9ca3af;
    }
    .smiski-input:focus {
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

    /* Secondary Action Button */
    .btn-secondary-glow {
        background: rgba(255, 255, 255, 0.9) !important;
        color: #15803d !important;
        border: 1px solid rgba(74, 222, 128, 0.6) !important;
        box-shadow: 0 0 10px rgba(74, 222, 128, 0.3);
        transition: all 0.3s ease;
    }
    .btn-secondary-glow:hover {
        background: #ffffff !important;
        border-color: #15803d !important;
        box-shadow: 0 0 15px rgba(74, 222, 128, 0.5);
        transform: translateY(-2px);
    }
</style>

<div class="container" style="padding: 2rem 1rem; max-width: 1300px; margin: 0 auto;">

    <!-- Page Header -->
    <div style="margin-bottom: 1.5rem;">
        <h2 class="white-glow-heading" style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.025em; margin-bottom: 0.25rem;">
            <i class="fa-solid fa-list-check" style="color: #86efac;"></i> Audit Logs
        </h2>
        <p class="white-subtext" style="font-size: 0.9rem;">Track and monitor all user and system activity history</p>
    </div>

    <!-- Layout Container (Filter Sidebar + Main Table) -->
    <div style="display: grid; grid-template-columns: 280px 1fr; gap: 1.5rem; align-items: start;">

        <!-- Filter Sidebar Card -->
        <div class="smiski-glass-card" style="padding: 1.5rem;">
            <h3 class="neon-glow-text" style="font-size: 1.1rem; font-weight: 700; margin-bottom: 1.25rem;">
                <i class="fa-solid fa-filter" style="color: #15803d;"></i> Log Action Checklist
            </h3>

            <form method="GET" action="audit.php">
                
                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: #1f2937;">Search Logs</label>
                    <div style="position: relative;">
                        <span style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #9ca3af;"><i class="fa-solid fa-search"></i></span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search keyword..." class="smiski-input">
                    </div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.5rem; color: #1f2937;">Action Type Filter</label>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem; max-height: 260px; overflow-y: auto; padding-right: 0.5rem;">
                        <?php foreach ($actionsList as $act): ?>
                            <label style="display: flex; align-items: center; gap: 0.6rem; font-size: 0.85rem; cursor: pointer; color: #374151; font-weight: 500;">
                                <input type="checkbox" name="actions[]" value="<?= $act ?>" <?= in_array($act, $selectedActions) ? 'checked' : '' ?> style="accent-color: #15803d; width: 16px; height: 16px; border-radius: 4px;">
                                <?= htmlspecialchars($act) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="submit" class="btn-green-glow" style="width: 100%; padding: 0.75rem; font-size: 0.9rem; font-weight: 700; border-radius: 12px;">
                    <i class="fa-solid fa-filter"></i> Filter Logs
                </button>
                <?php if (!empty($search) || !empty($selectedActions)): ?>
                    <a href="audit.php" class="btn-secondary-glow" style="display: block; text-align: center; margin-top: 0.75rem; padding: 0.75rem; font-size: 0.9rem; font-weight: 600; border-radius: 12px; text-decoration: none;">
                        <i class="fa-solid fa-rotate-left"></i> Reset Filter
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Main Logs Table Container -->
        <div class="smiski-glass-card" style="overflow: hidden;">
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid rgba(74, 222, 128, 0.4); background: rgba(220, 252, 231, 0.5); display: flex; justify-content: space-between; align-items: center;">
                <h3 class="neon-glow-text" style="font-size: 1.15rem; font-weight: 700; margin: 0;">
                    <i class="fa-solid fa-list" style="color: #15803d;"></i> Activity Records <span class="text-muted-green" style="font-size: 0.85rem; font-weight: 500;">(Showing <?= count($logs) ?> entries)</span>
                </h3>
            </div>

            <?php if (empty($logs)): ?>
                <p class="text-muted-green" style="text-align: center; padding: 3rem; font-weight: 500;">No audit logs matched your filter criteria.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
                        <thead>
                            <tr style="background: rgba(220, 252, 231, 0.5); color: #064e3b; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid rgba(74, 222, 128, 0.4);">
                                <th style="padding: 1rem;">ID</th>
                                <th style="padding: 1rem;">User</th>
                                <th style="padding: 1rem;">Action</th>
                                <th style="padding: 1rem;">Description</th>
                                <th style="padding: 1rem;">IP Address</th>
                                <th style="padding: 1rem;">Timestamp</th>
                                <th style="padding: 1rem; text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr style="border-bottom: 1px solid rgba(74, 222, 128, 0.2); transition: background 0.2s;" onmouseenter="this.style.background='rgba(220, 252, 231, 0.4)'" onmouseleave="this.style.background='transparent'">
                                    <td style="padding: 0.9rem 1rem; font-weight: 700; color: #064e3b;"><?= $log['id'] ?></td>
                                    <td style="padding: 0.9rem 1rem;">
                                        <div style="font-weight: 700; color: #064e3b; font-size: 0.88rem;"><?= htmlspecialchars($log['full_name'] ?? 'System / Guest') ?></div>
                                        <div class="text-muted-green" style="font-size: 0.72rem;"><?= htmlspecialchars($log['email'] ?? '') ?></div>
                                    </td>
                                    <td style="padding: 0.9rem 1rem;">
                                        <span style="background: rgba(238, 242, 255, 0.9); color: #3730a3; border: 1px solid rgba(129, 140, 248, 0.6); padding: 0.25rem 0.6rem; border-radius: 8px; font-size: 0.75rem; font-weight: 700; display: inline-block;">
                                            <?= htmlspecialchars($log['action']) ?>
                                        </span>
                                    </td>
                                    <td class="text-muted-green" style="padding: 0.9rem 1rem; max-width: 240px; font-size: 0.85rem;"><?= htmlspecialchars($log['description']) ?></td>
                                    <td class="text-muted-green" style="padding: 0.9rem 1rem; font-size: 0.82rem;"><?= htmlspecialchars($log['ip_address'] ?? '127.0.0.1') ?></td>
                                    <td class="text-muted-green" style="padding: 0.9rem 1rem; font-size: 0.82rem; white-space: nowrap;"><?= date('M d, Y h:i:s A', strtotime($log['created_at'])) ?></td>
                                    <td style="padding: 0.9rem 1rem; text-align: center;">
                                        <button onclick='openLogModal(<?= htmlspecialchars(json_encode($log), ENT_QUOTES, "UTF-8") ?>)' class="btn-secondary-glow" style="padding: 0.35rem 0.65rem; border-radius: 8px; font-size: 0.78rem; font-weight: 600; cursor: pointer;" title="View Details">
                                            <i class="fa-solid fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

</div>

<!-- Modal Dialog -->
<div class="modal-overlay" id="logModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(5, 46, 22, 0.85); backdrop-filter: blur(10px); align-items: center; justify-content: center; z-index: 2000;">
    <div class="smiski-glass-card" style="padding: 2rem; width: 100%; max-width: 500px; position: relative; color: #1f2937;">
        <button onclick="closeLogModal()" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #15803d;" onmouseover="this.style.color='#064e3b'" onmouseout="this.style.color='#15803d'">&times;</button>
        <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.25rem;">Audit Log Details</h3>

        <div style="display: flex; flex-direction: column; gap: 0.85rem; font-size: 0.9rem; color: #374151;">
            <div><strong style="color: #1f2937;">Log ID:</strong> <span id="log_id" style="color: #15803d; font-weight: 600;"></span></div>
            <div><strong style="color: #1f2937;">Action:</strong> <span id="log_action" style="font-weight: 700; color: #3730a3; background: rgba(238, 242, 255, 0.9); border: 1px solid rgba(129, 140, 248, 0.6); padding: 0.15rem 0.5rem; border-radius: 6px;"></span></div>
            <div><strong style="color: #1f2937;">User:</strong> <span id="log_user"></span></div>
            <div><strong style="color: #1f2937;">IP Address:</strong> <span id="log_ip"></span></div>
            <div><strong style="color: #1f2937;">Timestamp:</strong> <span id="log_time"></span></div>
            <div><strong style="color: #1f2937;">Description:</strong> <p id="log_desc" style="margin-top: 0.4rem; background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(74, 222, 128, 0.4); padding: 0.8rem; border-radius: 12px; color: #1f2937;"></p></div>
        </div>
    </div>
</div>

<script>
function openLogModal(l) {
    document.getElementById('log_id').innerText = l.id;
    document.getElementById('log_action').innerText = l.action;
    document.getElementById('log_user').innerText = (l.full_name ? l.full_name + ' (' + (l.email || '') + ')' : 'System / Guest');
    document.getElementById('log_ip').innerText = l.ip_address || '127.0.0.1';
    document.getElementById('log_time').innerText = l.created_at;
    document.getElementById('log_desc').innerText = l.description || 'No description';
    document.getElementById('logModal').style.display = 'flex';
}
function closeLogModal() { 
    document.getElementById('logModal').style.display = 'none'; 
}
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>