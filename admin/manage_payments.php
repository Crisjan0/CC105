<?php
// admin/manage_payments.php
// Admin page to view and manage payments: list, filter, search, update status, delete.
// Tailwind UI with added "Payment ID" (transaction_id) functionality:
// - Search by transaction_id
// - Edit / save transaction_id from the details panel
// - Copy transaction_id to clipboard
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

// Handle form submissions: update_status, delete, update_txn
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_txn') {
        // Update transaction_id for a payment
        $id = intval($_POST['id'] ?? 0);
        $txn = trim($_POST['id'] ?? '');
        if ($id <= 0) {
            $error_msg = 'Invalid payment id.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE payments SET id = ? WHERE id = ?");
                $stmt->execute([$txn !== '' ? $txn : null, $id]);
                $_SESSION['flash'] = "Payment #{$id} transaction id updated.";
                header('Location: manage_payments.php?view_id=' . $id);
                exit;
            } catch (PDOException $e) {
                $error_msg = 'Failed to update transaction id: ' . $e->getMessage();
            }
        }
    }

    elseif ($action === 'update_status') {
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
$view_id = isset($_GET['view_id']) ? intval($_GET['view_id']) : 0;

// Build query with joins and optional filters
$sql = "SELECT p.*, u.username, u.first_name, u.middle_name, u.last_name, u.email
        FROM payments p
        JOIN users u ON p.user_id = u.id";
$where = [];
$params = [];

if ($status_filter !== '' && in_array($status_filter, $allowed_statuses, true)) {
    $where[] = "p.payment_status = ?";
    $params[] = $status_filter;
}

if ($search !== '') {
    // search by username, first_name, last_name, email or transaction_id
    $where[] = "(u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR p.transaction_id LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
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
    <!-- Tailwind CDN for quick prototyping; use a compiled build in production -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      // Small helper to copy text and show a transient tooltip
      function copyToClipboard(text, btn) {
        if (!navigator.clipboard) {
          // Fallback
          const ta = document.createElement('textarea');
          ta.value = text;
          document.body.appendChild(ta);
          ta.select();
          try { document.execCommand('copy'); } catch(e) {}
          document.body.removeChild(ta);
        } else {
          navigator.clipboard.writeText(text).catch(()=>{});
        }
        // show a temporary indicator on the button
        const orig = btn.innerHTML;
        btn.innerHTML = 'Copied';
        setTimeout(()=> btn.innerHTML = orig, 1200);
      }
    </script>
    <style>
    body::-webkit-scrollbar{
      display:none;
    }
  </style>
</head>
<body class="min-h-screen bg-gray-50 text-gray-800">
  <nav class="bg-white border-b">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div class="flex items-center space-x-4">
          <a href="dashboard.php" class="text-lg font-semibold text-gray-900">Admin Panel</a>
          <a href="dashboard.php" class="text-sm text-gray-600 hover:text-gray-900">Dashboard</a>
          <a href="manage_courses.php" class="text-sm text-gray-600 hover:text-gray-900">Courses</a>
          <a href="manage_students.php" class="text-sm text-gray-600 hover:text-gray-900">Students</a>
          <a href="manage_payments.php" class="text-sm text-brand-600 font-medium">Payments</a>
          <a href="manage_applications.php" class="text-sm text-gray-600 hover:text-gray-900">Application</a>
        </div>
        <div class="flex items-center space-x-3">
          <a href="../public/logout.php" class="inline-flex items-center px-3 py-1.5 bg-red-600 text-white text-sm rounded hover:bg-red-700">Logout</a>
        </div>
      </div>
    </div>
  </nav>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-6">
      <h1 class="text-2xl font-semibold text-gray-900">Manage Payments</h1>
      <p class="text-sm text-gray-500">Review, filter and update payment records. You can now search by and edit the Payment ID (transaction_id).</p>
    </div>

    <?php if ($info_msg): ?>
      <div class="mb-4">
        <div class="rounded-md bg-green-50 p-4 border border-green-100">
          <div class="flex">
            <div class="ml-3">
              <p class="text-sm text-green-700"><?= htmlspecialchars($info_msg) ?></p>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
      <div class="mb-4">
        <div class="rounded-md bg-red-50 p-4 border border-red-100">
          <div class="flex">
            <div class="ml-3">
              <p class="text-sm text-red-700"><?= htmlspecialchars($error_msg) ?></p>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="bg-white shadow rounded-lg p-4 mb-6">
      <div class="flex flex-col sm:flex-row sm:items-center sm:space-x-4 gap-4">
        <form method="get" class="flex items-center gap-2">
          <label for="status" class="sr-only">Status</label>
          <select id="status" name="status" class="rounded border-gray-300 text-sm px-3 py-2">
            <option value="">All statuses</option>
            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
            <option value="failed" <?= $status_filter === 'failed' ? 'selected' : '' ?>>Failed</option>
          </select>
          <button type="submit" class="ml-1 inline-flex items-center px-3 py-2 bg-sky-600 text-white text-sm rounded hover:bg-sky-700">Filter</button>
        </form>

        <form method="get" class="flex items-center gap-2 ml-auto">
          <input name="search" type="text" placeholder="Search username, name, email, or Payment ID" value="<?= htmlspecialchars($search) ?>" class="w-72 rounded border-gray-300 px-3 py-2 text-sm" />
          <?php if ($status_filter !== ''): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
          <?php endif; ?>
          <button type="submit" class="inline-flex items-center px-3 py-2 bg-gray-700 text-white text-sm rounded hover:bg-gray-800">Search</button>
          <a href="manage_payments.php" class="ml-2 text-sm text-gray-500 hover:underline">Reset</a>
        </form>
      </div>
    </div>

    <div class="mb-4">
      <h2 class="text-lg font-medium text-gray-900">Payments <span class="text-sm text-gray-500">(<?= count($payments) ?>)</span></h2>
      <p class="text-sm text-gray-500">Showing <?= count($payments) ?> result<?= count($payments) !== 1 ? 's' : '' ?>.</p>
    </div>

    <?php if (count($payments) === 0): ?>
      <div class="rounded bg-white p-6 shadow">
        <p class="text-sm text-gray-600">No payments found for the selected filters.</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto bg-white rounded-lg shadow">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
              <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
              <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
              <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
              <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
              <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
              <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <?php foreach ($payments as $p): ?>
            <tr>
              <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700"><?= (int)$p['id'] ?></td>
              <td class="px-4 py-4 whitespace-nowrap">
                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($p['username']) ?></div>
                <div class="text-sm text-gray-500"><?= htmlspecialchars(trim($p['first_name'] . ' ' . ($p['middle_name'] ? $p['middle_name'] . ' ' : '') . $p['last_name'])) ?></div>
              </td>
              <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars($p['email']) ?></td>
              <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-right"><?= number_format((float)$p['amount'], 2) ?></td>
              <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars($p['payment_date']) ?></td>
              <td class="px-4 py-4 whitespace-nowrap">
                <?php
                  $status = $p['payment_status'];
                  $badgeClass = 'bg-gray-200 text-gray-800';
                  if ($status === 'pending') { $badgeClass = 'bg-yellow-100 text-yellow-800'; }
                  if ($status === 'completed') { $badgeClass = 'bg-green-100 text-green-800'; }
                  if ($status === 'failed') { $badgeClass = 'bg-red-100 text-red-800'; }
                ?>
                <span class="inline-flex items-center px-2.5 py-1 rounded text-xs font-medium <?= $badgeClass ?>">
                  <?= htmlspecialchars(ucfirst($status)) ?>
                </span>
              </td>
              <td class="px-4 py-4 whitespace-nowrap text-sm text-right space-x-2">
                <!-- Quick status change form -->
                <form method="post" class="inline-block">
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <label class="sr-only">Change status</label>
                  <select name="status" onchange="this.form.submit()" class="rounded border-gray-300 text-sm px-2 py-1">
                    <option value="pending" <?= $p['payment_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="completed" <?= $p['payment_status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="failed" <?= $p['payment_status'] === 'failed' ? 'selected' : '' ?>>Failed</option>
                  </select>
                  <noscript><button type="submit" class="ml-2 inline-flex items-center px-2 py-1 bg-sky-600 text-white text-xs rounded">Set</button></noscript>
                </form>

                <!-- Delete -->
                <form method="post" class="inline-block" onsubmit="return confirm('Delete this payment? This cannot be undone.');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button type="submit" class="inline-flex items-center px-2 py-1 bg-red-600 text-white text-xs rounded hover:bg-red-700">Delete</button>
                </form>

                <a href="manage_payments.php?<?= ($status_filter !== '' ? 'status=' . urlencode($status_filter) . '&' : '') ?><?= ($search !== '' ? 'search=' . urlencode($search) . '&' : '') ?>view_id=<?= (int)$p['id'] ?>" class="inline-block ml-2 text-sm text-sky-600 hover:underline">Details</a>
              </td>
            </tr>

            <?php if ($view_id === (int)$p['id']): 
                // fetch full payment row (including any fields like notes if available)
                $stmtD = $pdo->prepare("SELECT p.*, u.username, u.first_name, u.middle_name, u.last_name, u.email FROM payments p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
                $stmtD->execute([(int)$p['id']]);
                $detail = $stmtD->fetch();
            ?>
            <tr class="bg-gray-50">
              <td colspan="7" class="px-4 py-4">
                <div class="space-y-4">
                  <div class="flex items-center justify-between">
                    <div>
                      <div class="text-sm text-gray-700"><strong>Payment Details (ID <?= (int)$p['id'] ?>)</strong></div>
                      <div class="text-sm text-gray-600">User: <?= htmlspecialchars($detail['username']) ?> (<?= htmlspecialchars(trim($detail['first_name'] . ' ' . ($detail['middle_name'] ? $detail['middle_name'] . ' ' : '') . $detail['last_name'])) ?>) — <?= htmlspecialchars($detail['email']) ?></div>
                    </div>
                    <div class="text-sm text-gray-500">Recorded: <?= htmlspecialchars($detail['payment_date']) ?></div>
                  </div>

                  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                      <div class="text-xs text-gray-500">Amount</div>
                      <div class="text-sm font-medium text-gray-900"><?= number_format((float)$detail['amount'], 2) ?></div>
                    </div>
                    <div>
                      <div class="text-xs text-gray-500">Status</div>
                      <div class="text-sm text-gray-900"><?= htmlspecialchars($detail['payment_status']) ?></div>
                    </div>
                    <div>
                      <div class="text-xs text-gray-500">Payment ID</div>
                      <div class="flex items-center gap-2">
                        <div id="txn-<?= (int)$detail['id'] ?>" class="text-sm text-gray-900 font-mono"><?= $detail['id'] !== null ? htmlspecialchars($detail['id']) : '—' ?></div>
                        <?php if (!empty($detail['id'])): ?>
                          <button type="button" onclick="copyToClipboard('<?= htmlspecialchars(addslashes($detail['id'])) ?>', this)" class="inline-flex items-center px-2 py-1 bg-gray-100 text-sm rounded hover:bg-gray-200">Copy</button>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>

                  <?php if (!empty($detail['notes'])): ?>
                    <div>
                      <div class="text-xs text-gray-500">Notes</div>
                      <div class="text-sm text-gray-800 whitespace-pre-wrap"><?= htmlspecialchars($detail['notes']) ?></div>
                    </div>
                  <?php endif; ?>

                  <!-- Transaction ID edit form -->
                  <form method="post" class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                    <input type="hidden" name="action" value="update_txn">
                    <input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
                    <div class="sm:col-span-2">
                      <label for="transaction_id" class="block text-xs font-medium text-gray-700">Edit Payment ID (transaction_id)</label>
                      <input id="transaction_id" name="transaction_id" type="text" value="<?= htmlspecialchars($detail['id'] ?? '') ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                      <button type="submit" class="inline-flex items-center px-3 py-2 bg-sky-600 text-white text-sm rounded hover:bg-sky-700">Save</button>
                    </div>
                  </form>

                </div>
              </td>
            </tr>
            <?php endif; ?>

            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="mt-6 text-sm text-gray-500">
      Tip: Integrate your payment gateway to automatically create and update payment records. Admins can now edit and copy the Payment ID for reconciliation with gateway logs.
    </div>

  </main>

  <footer class="bg-white border-t mt-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 text-center lg:px-8 py-6 text-sm text-gray-500">
      © <?= date('Y') ?> Your Institution — Admin Panel
    </div>
  </footer>
</body>
</html>