<?php
// admin/manage_payments.php
// Admin page to view and manage payments: list, filter, search, update status, delete.
//
// Requires: ../includes/db_connect.php and ../includes/auth.php
// Place this file in the admin/ directory. Only accessible to admin users.

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin(); // starts session via auth.php

$info_msg = '';
$error_msg = '';

// Show flash messages (set by actions before redirect)
if (isset($_SESSION['flash'])) {
    $info_msg = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// Allowed statuses
$allowed_statuses = ['pending', 'completed', 'failed'];

// Handle form submissions: update_status, delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_status') {
        $id = intval($_POST['id'] ?? 0);
        $new_status = $_POST['status'] ?? '';
        if ($id <= 0 || !in_array($new_status, $allowed_statuses, true)) {
            $error_msg = 'Invalid payment id or status.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE payments SET payment_status = ? WHERE id = ?");
                $stmt->execute([$new_status, $id]);
                $_SESSION['flash'] = "Payment #{$id} set to '{$new_status}'.";
                header('Location: manage_payments.php');
                exit;
            } catch (PDOException $e) {
                $error_msg = 'Failed to update payment status: ' . $e->getMessage();
            }
        }
    }

    elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $error_msg = 'Invalid payment id.';
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM payments WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['flash'] = "Payment #{$id} deleted.";
                header('Location: manage_payments.php');
                exit;
            } catch (PDOException $e) {
                $error_msg = 'Failed to delete payment: ' . $e->getMessage();
            }
        }
    }
    // fall through to display errors if any
}

// Filters & search (GET)
$status_filter = $_GET['status'] ?? ''; // pending|completed|failed|empty
$search = trim($_GET['search'] ?? '');

// Build query with joins and optional filters
$sql = "SELECT p.*, u.username, u.full_name, u.email
        FROM payments p
        JOIN users u ON p.user_id = u.id";
$where = [];
$params = [];

if ($status_filter !== '' && in_array($status_filter, $allowed_statuses, true)) {
    $where[] = "p.payment_status = ?";
    $params[] = $status_filter;
}

if ($search !== '') {
    // search by username, full_name, or email
    $where[] = "(u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY p.payment_date DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Manage Payments - Admin</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .controls { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; vertical-align: middle; }
        th { background: #f0f0f0; }
        form.inline { display: inline; margin:0; }
        .msg { padding: 10px; margin-bottom: 10px; }
        .info { background: #e6ffed; border: 1px solid #b7f0c4; }
        .error { background: #ffe6e6; border: 1px solid #f0b7b7; }
        .small { font-size: 0.9em; color: #555; }
        .badge { display:inline-block; padding:4px 8px; border-radius:4px; font-size:0.9em; color:#fff; }
        .pending { background:#f0ad4e; }
        .completed { background:#5cb85c; }
        .failed { background:#d9534f; }
        .actions button { margin-right:6px; }
        .search { margin-left:auto; }
    </style>
</head>
<body>
    <h1>Manage Payments</h1>
    <p><a href="dashboard.php">← Admin Dashboard</a> | <a href="../public/logout.php">Logout</a></p>

    <?php if ($info_msg): ?>
        <div class="msg info"><?= htmlspecialchars($info_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="msg error"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <div class="controls">
        <form method="get" style="display:flex; gap:8px; align-items:center;">
            <label for="status">Status:</label>
            <select id="status" name="status">
                <option value="">All</option>
                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                <option value="failed" <?= $status_filter === 'failed' ? 'selected' : '' ?>>Failed</option>
            </select>
            <button type="submit">Filter</button>
        </form>

        <form method="get" class="search" style="margin-left:auto;">
            <input type="text" name="search" placeholder="Search username, name, email" value="<?= htmlspecialchars($search) ?>">
            <?php if ($status_filter !== ''): ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
            <?php endif; ?>
            <button type="submit">Search</button>
            <a href="manage_payments.php" style="margin-left:8px;">Reset</a>
        </form>
    </div>

    <h2>Payments <span class="small">(<?= count($payments) ?>)</span></h2>

    <?php if (count($payments) === 0): ?>
        <p>No payments found for the selected filters.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Email</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th class="small">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?= (int)$p['id'] ?></td>
                    <td>
                        <?= htmlspecialchars($p['username']) ?><br>
                        <span class="small"><?= htmlspecialchars($p['full_name']) ?></span>
                    </td>
                    <td><?= htmlspecialchars($p['email']) ?></td>
                    <td><?= number_format((float)$p['amount'], 2) ?></td>
                    <td><?= htmlspecialchars($p['payment_date']) ?></td>
                    <td>
                        <span class="badge <?= htmlspecialchars($p['payment_status']) ?>">
                            <?= htmlspecialchars(ucfirst($p['payment_status'])) ?>
                        </span>
                    </td>
                    <td>
                        <!-- Quick status change form -->
                        <form method="post" class="inline" style="margin-right:6px;">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <select name="status" onchange="this.form.submit()">
                                <option value="pending" <?= $p['payment_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="completed" <?= $p['payment_status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="failed" <?= $p['payment_status'] === 'failed' ? 'selected' : '' ?>>Failed</option>
                            </select>
                            <noscript><button type="submit">Set</button></noscript>
                        </form>

                        <!-- View details / delete -->
                        <form method="post" class="inline" onsubmit="return confirm('Delete this payment? This cannot be undone.');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button type="submit">Delete</button>
                        </form>
                        <a href="manage_payments.php?view_id=<?= (int)$p['id'] ?>" style="margin-left:8px;">Details</a>
                    </td>
                </tr>

                <?php
                // If details view requested for this payment, render a details row right after it
                if (isset($_GET['view_id']) && intval($_GET['view_id']) === (int)$p['id']): 
                    // fetch full payment row (including any fields like notes if available)
                    $stmtD = $pdo->prepare("SELECT p.*, u.username, u.full_name, u.email FROM payments p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
                    $stmtD->execute([(int)$p['id']]);
                    $detail = $stmtD->fetch();
                ?>
                <tr>
                    <td colspan="7" style="background:#fafafa;">
                        <strong>Payment Details (ID <?= (int)$p['id'] ?>)</strong><br>
                        User: <?= htmlspecialchars($detail['username']) ?> (<?= htmlspecialchars($detail['full_name']) ?>)<br>
                        Email: <?= htmlspecialchars($detail['email']) ?><br>
                        Amount: <?= number_format((float)$detail['amount'], 2) ?><br>
                        Date: <?= htmlspecialchars($detail['payment_date']) ?><br>
                        Status: <?= htmlspecialchars($detail['payment_status']) ?><br>
                        <!-- If you later add more columns like transaction_id, gateway, or notes, show them here -->
                    </td>
                </tr>
                <?php endif; ?>

                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p class="small">Tip: Integrate your payment gateway to automatically create and update payment records. For now, admins can manually set statuses.</p>

</body>
</html>