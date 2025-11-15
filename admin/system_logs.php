<?php
// admin/system_logs.php
// Admin interface to view, search, filter, export and manage system logs.
//
// Requires: ../includes/db_connect.php and ../includes/auth.php
// Place this file in admin/ directory. Only accessible to admin users.

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

// Defensive session start for CSRF and flash
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf_token = $_SESSION['csrf_token'];

// Config
$logs_table = 'system_logs'; // change if your logs table has a different name
$perPageOptions = [10, 25, 50, 100];

// Flash/message helpers
function set_flash($msg) {
    $_SESSION['flash'] = $msg;
}
function get_flash() {
    if (!empty($_SESSION['flash'])) {
        $m = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $m;
    }
    return '';
}

$info_msg = get_flash();
$error_msg = '';

// Handle POST actions: delete single entry, clear logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $error_msg = 'Invalid request (CSRF token mismatch).';
    } else {
        $action = $_POST['action'];

        if ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error_msg = 'Invalid log id.';
            } else {
                try {
                    $stmt = $pdo->prepare("DELETE FROM `$logs_table` WHERE id = ?");
                    $stmt->execute([$id]);
                    set_flash("Log #{$id} deleted.");
                    header('Location: system_logs.php');
                    exit;
                } catch (PDOException $e) {
                    error_log('Delete log error: ' . $e->getMessage());
                    $error_msg = 'Failed to delete log entry.';
                }
            }
        } elseif ($action === 'clear') {
            // clear all logs (dangerous) - require explicit confirmation input
            $confirm = $_POST['confirm_clear'] ?? '';
            if ($confirm !== 'CLEAR') {
                $error_msg = 'To clear logs, type CLEAR in the confirmation field.';
            } else {
                try {
                    $pdo->exec("TRUNCATE TABLE `$logs_table`");
                    set_flash('All logs cleared.');
                    header('Location: system_logs.php');
                    exit;
                } catch (PDOException $e) {
                    error_log('Clear logs error: ' . $e->getMessage());
                    $error_msg = 'Failed to clear logs.';
                }
            }
        } elseif ($action === 'export') {
            // Export current filtered results as CSV
            // We'll reuse GET filters below to build WHERE and params
            // After building rows, output CSV and exit
            // Mark export request and fall through to export code after query building
            $_SESSION['export_csv'] = true;
            // carry over to GET processing by redirecting with same GET params
            $qs = $_GET ? ('?' . http_build_query($_GET)) : '';
            // perform redirect to same page so that export uses same query building logic below
            header('Location: system_logs.php' . $qs);
            exit;
        }
    }
}

// Read filters from GET
$level = trim($_GET['level'] ?? '');
$q = trim($_GET['q'] ?? ''); // search query for message/context
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = intval($_GET['per_page'] ?? 25);
if (!in_array($per_page, $perPageOptions, true)) {
    $per_page = 25;
}

// Build WHERE clause safely
$where = [];
$params = [];

if ($level !== '') {
    $where[] = "level = ?";
    $params[] = $level;
}

if ($q !== '') {
    // search in message and context (if present)
    $where[] = "(message LIKE ? OR IFNULL(context, '') LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}

if ($date_from !== '') {
    // expect YYYY-MM-DD optionally with time
    $where[] = "created_at >= ?";
    $params[] = $date_from . (strlen($date_from) === 10 ? ' 00:00:00' : '');
}
if ($date_to !== '') {
    $where[] = "created_at <= ?";
    $params[] = $date_to . (strlen($date_to) === 10 ? ' 23:59:59' : '');
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Count total matching
try {
    $countSql = "SELECT COUNT(*) FROM `$logs_table` $where_sql";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Count logs error: ' . $e->getMessage());
    $total = 0;
    $error_msg = $error_msg ?: 'Failed to load logs.';
}

// Pagination
$totalPages = max(1, (int)ceil($total / $per_page));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $per_page;

// Fetch actual rows
$rows = [];
try {
    $sql = "SELECT * FROM `$logs_table` $where_sql ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($sql);
    // merge params with limit/offset
    $execParams = array_merge($params, [$per_page, $offset]);
    $stmt->execute($execParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Fetch logs error: ' . $e->getMessage());
    $error_msg = $error_msg ?: 'Failed to fetch logs.';
}

// Handle CSV export if requested (session flag set earlier via POST export)
if (!empty($_SESSION['export_csv'])) {
    unset($_SESSION['export_csv']);
    // Re-run a full query (without LIMIT) to export all filtered rows
    try {
        $exportSql = "SELECT * FROM `$logs_table` $where_sql ORDER BY created_at DESC";
        $exportStmt = $pdo->prepare($exportSql);
        $exportStmt->execute($params);
        $exportRows = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

        // Output CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=system_logs_export_' . date('Ymd_His') . '.csv');

        $out = fopen('php://output', 'w');
        // Write BOM for Excel
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        if (!empty($exportRows)) {
            // header columns based on keys of first row
            fputcsv($out, array_keys($exportRows[0]));
            foreach ($exportRows as $r) {
                // ensure no binary issues; convert nulls to empty strings
                $row = array_map(function($v){ return $v === null ? '' : $v; }, $r);
                fputcsv($out, $row);
            }
        }
        fclose($out);
        exit;
    } catch (PDOException $e) {
        error_log('Export logs error: ' . $e->getMessage());
        set_flash('Failed to export logs.');
        header('Location: system_logs.php');
        exit;
    }
}

// Helper to build query string preserving filters
function qs(array $overrides = []) {
    $base = array_merge($_GET, $overrides);
    return http_build_query($base);
}

// Small helper to escape output
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>System Logs - Admin</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-800">
  <nav class="bg-white border-b">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div class="flex items-center space-x-4">
          <a href="dashboard.php" class="text-lg font-semibold text-gray-900">Admin Panel</a>
          <a href="manage_courses.php" class="text-sm text-gray-600 hover:text-gray-900">Courses</a>
          <a href="manage_students.php" class="text-sm text-gray-600 hover:text-gray-900">Students</a>
          <a href="manage_payments.php" class="text-sm text-gray-600 hover:text-gray-900">Payments</a>
          <a href="system_logs.php" class="text-sm text-brand-600 font-medium">System Logs</a>
        </div>
        <div class="flex items-center space-x-3">
          <a href="../public/logout.php" class="inline-flex items-center px-3 py-1.5 bg-red-600 text-white text-sm rounded hover:bg-red-700">Logout</a>
        </div>
      </div>
    </div>
  </nav>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <?php if ($info_msg): ?>
      <div class="mb-4 rounded-md bg-green-50 border border-green-100 p-4">
        <p class="text-sm text-green-800"><?= h($info_msg) ?></p>
      </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
      <div class="mb-4 rounded-md bg-red-50 border border-red-100 p-4">
        <p class="text-sm text-red-800"><?= h($error_msg) ?></p>
      </div>
    <?php endif; ?>

    <div class="bg-white shadow rounded-lg p-6 mb-6">
      <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
        <div>
          <label class="block text-xs font-medium text-gray-600">Level</label>
          <select name="level" class="mt-1 block w-full rounded border-gray-300 px-2 py-1 text-sm">
            <option value="">Any</option>
            <option value="debug" <?= $level === 'debug' ? 'selected' : '' ?>>debug</option>
            <option value="info" <?= $level === 'info' ? 'selected' : '' ?>>info</option>
            <option value="notice" <?= $level === 'notice' ? 'selected' : '' ?>>notice</option>
            <option value="warning" <?= $level === 'warning' ? 'selected' : '' ?>>warning</option>
            <option value="error" <?= $level === 'error' ? 'selected' : '' ?>>error</option>
            <option value="critical" <?= $level === 'critical' ? 'selected' : '' ?>>critical</option>
            <option value="alert" <?= $level === 'alert' ? 'selected' : '' ?>>alert</option>
            <option value="emergency" <?= $level === 'emergency' ? 'selected' : '' ?>>emergency</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-medium text-gray-600">Search</label>
          <input name="q" value="<?= h($q) ?>" placeholder="message or context" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" />
        </div>

        <div>
          <label class="block text-xs font-medium text-gray-600">Date from</label>
          <input type="date" name="date_from" value="<?= h($date_from) ?>" class="mt-1 block w-full rounded border-gray-300 px-2 py-1 text-sm" />
        </div>

        <div>
          <label class="block text-xs font-medium text-gray-600">Date to</label>
          <input type="date" name="date_to" value="<?= h($date_to) ?>" class="mt-1 block w-full rounded border-gray-300 px-2 py-1 text-sm" />
        </div>

        <div class="md:col-span-4 flex items-center justify-between mt-2">
          <div class="flex items-center space-x-2">
            <button type="submit" class="inline-flex items-center px-3 py-2 bg-sky-600 text-white text-sm rounded hover:bg-sky-700">Filter</button>
            <a href="system_logs.php" class="inline-flex items-center px-3 py-2 bg-gray-100 text-sm rounded hover:bg-gray-200">Reset</a>
            <form method="post" class="inline-block" onsubmit="return confirm('Export visible logs as CSV?');" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
              <input type="hidden" name="action" value="export">
              <button type="submit" class="inline-flex items-center px-3 py-2 bg-gray-700 text-white text-sm rounded hover:bg-gray-800">Export CSV</button>
            </form>
          </div>

          <div class="flex items-center space-x-2">
            <label class="text-sm text-gray-600">Per page</label>
            <select onchange="location = '?<?= qs(['per_page' => '']).'per_page='?>' + this.value + '&<?= h(qs(['page'=>1])) ?>'" class="rounded border-gray-300 px-2 py-1 text-sm">
              <?php foreach ($perPageOptions as $opt): ?>
                <option value="<?= $opt ?>" <?= $per_page === $opt ? 'selected' : '' ?>><?= $opt ?></option>
              <?php endforeach; ?>
            </select>
            <button onclick="document.getElementById('clear-form').scrollIntoView()" type="button" class="inline-flex items-center px-3 py-2 bg-red-100 text-red-700 text-sm rounded hover:bg-red-200">Dangerous actions</button>
          </div>
        </div>
      </form>
    </div>

    <div class="bg-white shadow rounded-lg p-4 overflow-auto">
      <table class="min-w-full text-sm divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">When</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Level</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Message</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Context</th>
            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-100">
          <?php if (empty($rows)): ?>
            <tr><td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">No log entries found.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="px-3 py-3 text-sm text-gray-700"><?= h($r['id'] ?? '') ?></td>
                <td class="px-3 py-3 text-sm text-gray-700"><?= h($r['created_at'] ?? '') ?></td>
                <td class="px-3 py-3 text-sm">
                  <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= ($r['level'] === 'error' ? 'bg-red-100 text-red-800' : ($r['level'] === 'warning' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800')) ?>">
                    <?= h($r['level'] ?? '') ?>
                  </span>
                </td>
                <td class="px-3 py-3 text-sm text-gray-900"><?= h(mb_strimwidth($r['message'] ?? '', 0, 200, '...')) ?></td>
                <td class="px-3 py-3 text-sm text-gray-700"><?= h(mb_strimwidth($r['context'] ?? '', 0, 120, '...')) ?></td>
                <td class="px-3 py-3 text-sm text-right">
                  <form method="post" onsubmit="return confirm('Delete this log entry?');" class="inline-block">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="inline-flex items-center px-3 py-1 text-xs rounded bg-red-600 text-white hover:bg-red-700">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="mt-4 flex items-center justify-between">
      <div class="text-sm text-gray-600">Showing page <?= $page ?> of <?= $totalPages ?> — <?= $total ?> total</div>
      <div class="space-x-2">
        <?php if ($page > 1): ?>
          <a href="?<?= qs(['page' => $page - 1]) ?>" class="inline-flex items-center px-3 py-1 bg-gray-100 rounded text-sm hover:bg-gray-200">Previous</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?<?= qs(['page' => $page + 1]) ?>" class="inline-flex items-center px-3 py-1 bg-gray-100 rounded text-sm hover:bg-gray-200">Next</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Clear logs section (dangerous) -->
    <div id="clear-form" class="mt-8 bg-white shadow rounded-lg p-6">
      <h3 class="text-lg font-medium text-gray-900 mb-2">Clear all logs</h3>
      <p class="text-sm text-gray-500 mb-4">This will remove all rows from the <?= h($logs_table) ?> table. This action is irreversible.</p>
      <form method="post" onsubmit="return confirm('Are you sure you want to clear all logs? This cannot be undone.');" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
        <input type="hidden" name="action" value="clear">
        <div>
          <label class="block text-sm text-gray-600">Type <strong>CLEAR</strong> to confirm</label>
          <input name="confirm_clear" type="text" class="mt-1 block w-56 rounded border-gray-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded text-sm hover:bg-red-700">Clear all logs</button>
        </div>
      </form>
    </div>

  </main>

  <footer class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-8 text-sm text-gray-500">
    © <?= date('Y') ?> Your Institution — Admin Panel
  </footer>
</body>
</html>