<?php
// public/manage_applications.php
// Admin UI to review enrollment applications and Approve / Reject them.
// Updated: added a Delete button in the top-right header area of the detail panel.
// Requires ../includes/db_connect.php and ../includes/auth.php
// Place this file in public/ and link from your admin dashboard.

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

// Ensure session
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf_token = $_SESSION['csrf_token'];

// Flash helpers
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

// Helper
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

// Small helper to map status to label + classes
function status_badge($status) {
    $s = strtolower((string)$status);
    return match($s) {
        'submitted' => ['Submitted', 'bg-yellow-100 text-yellow-800'],
        'approved'  => ['Approved',  'bg-green-100 text-green-800'],
        'rejected'  => ['Rejected',  'bg-red-100 text-red-800'],
        default     => [ucfirst($s), 'bg-gray-100 text-gray-800'],
    };
}

// ---------- Handle POST actions: approve / reject / delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['application_id'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $error_msg = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'];
        $appId = (int)$_POST['application_id'];

        try {
            // load application under transaction
            $stmt = $pdo->prepare("SELECT * FROM enrollment_applications WHERE id = ? LIMIT 1 FOR UPDATE");
            $pdo->beginTransaction();
            $stmt->execute([$appId]);
            $app = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$app) {
                throw new Exception('Application not found.');
            }

            $now = date('Y-m-d H:i:s');
            $adminId = $_SESSION['user_id'] ?? null;

            if ($action === 'approve') {
                if ($app['status'] !== 'submitted') {
                    throw new Exception('Only submitted applications can be approved.');
                }

                // Approve: create enrollment rows for each course and mark application approved
                $courseIds = json_decode($app['course_ids'] ?? '[]', true);
                if (!is_array($courseIds)) $courseIds = [];

                // Prepare once
                $enrollStmt = $pdo->prepare("INSERT INTO enrollments (user_id, course_id, enrolled_at) VALUES (?, ?, ?)");
                $check = $pdo->prepare("SELECT id FROM enrollments WHERE user_id = ? AND course_id = ? LIMIT 1");
                $stmtC = $pdo->prepare("SELECT id FROM courses WHERE id = ? LIMIT 1");

                foreach ($courseIds as $cid) {
                    $cid = (int)$cid;
                    // verify course exists (defensive)
                    $stmtC->execute([$cid]);
                    if (!$stmtC->fetch()) continue;
                    $check->execute([$app['user_id'], $cid]);
                    if ($check->fetch()) continue;
                    $enrollStmt->execute([$app['user_id'], $cid, $now]);
                }

                // update application
                $u = $pdo->prepare("UPDATE enrollment_applications SET status = 'approved', processed_at = ?, processed_by = ? WHERE id = ?");
                $u->execute([$now, $adminId, $appId]);

                // Optional: add audit log
                try {
                    $log = $pdo->prepare("INSERT INTO admin_audit_log (admin_id, action, target_table, target_id, details) VALUES (?, 'approve_application', 'enrollment_applications', ?, ?)");
                    $log->execute([$adminId, $appId, json_encode(['approved_at' => $now])]);
                } catch (Throwable $ignore) {
                    // don't break the flow if audit log table is missing
                }

                $pdo->commit();
                set_flash('Application approved and student enrolled.');
            }

            elseif ($action === 'reject') {
                if ($app['status'] !== 'submitted') {
                    throw new Exception('Only submitted applications can be rejected.');
                }
                $reason = trim((string)($_POST['reject_reason'] ?? ''));
                $u = $pdo->prepare("UPDATE enrollment_applications SET status = 'rejected', processed_at = ?, processed_by = ? WHERE id = ?");
                $u->execute([$now, $adminId, $appId]);

                try {
                    $log = $pdo->prepare("INSERT INTO admin_audit_log (admin_id, action, target_table, target_id, details) VALUES (?, 'reject_application', 'enrollment_applications', ?, ?)");
                    $log->execute([$adminId, $appId, json_encode(['rejected_at' => $now, 'reason' => $reason])]);
                } catch (Throwable $ignore) {}

                $pdo->commit();
                set_flash('Application rejected.');
            }

            elseif ($action === 'delete') {
                // Allow deletion only for non-approved applications (to avoid removing enrollments created by approval).
                if (($app['status'] ?? '') === 'approved') {
                    throw new Exception('Cannot delete an approved application. Remove enrollments first, then delete the application.');
                }

                // Null out payments.application_id that reference this application (defensive)
                try {
                    $pdo->prepare("UPDATE payments SET application_id = NULL WHERE application_id = ?")->execute([$appId]);
                } catch (Throwable $ignore) {
                    // continue even if payments update fails
                }

                // Remove uploaded files referenced in files JSON (best-effort)
                $files = json_decode($app['files'] ?? '[]', true) ?: [];
                $deletedFiles = [];
                foreach ($files as $f) {
                    $stored = $f['stored_name'] ?? '';
                    if ($stored) {
                        // Stored paths in code are relative like "uploads/..." — map to filesystem path
                        $candidate = __DIR__ . '/../' . ltrim($stored, '/');
                        if (file_exists($candidate) && is_file($candidate)) {
                            @unlink($candidate);
                            $deletedFiles[] = $candidate;
                        }
                    }
                }

                // Finally delete application row
                $del = $pdo->prepare("DELETE FROM enrollment_applications WHERE id = ?");
                $del->execute([$appId]);

                // Optional: audit log
                try {
                    $log = $pdo->prepare("INSERT INTO admin_audit_log (admin_id, action, target_table, target_id, details) VALUES (?, 'delete_application', 'enrollment_applications', ?, ?)");
                    $log->execute([$adminId, $appId, json_encode(['deleted_at' => $now, 'deleted_files' => $deletedFiles])]);
                } catch (Throwable $ignore) {}

                $pdo->commit();
                set_flash('Application deleted.');
            }

            else {
                $pdo->rollBack();
                $error_msg = 'Unknown action.';
            }

            header('Location: manage_applications.php');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = 'Action failed: ' . $e->getMessage();
        }
    }
}

// If view_id is provided, load that single application for the detail panel
$view_app = null;
$view_id = isset($_GET['view_id']) ? (int)$_GET['view_id'] : 0;
if ($view_id > 0) {
    try {
        $stmtV = $pdo->prepare("SELECT ea.*, u.username, u.full_name, u.email FROM enrollment_applications ea LEFT JOIN users u ON ea.user_id = u.id WHERE ea.id = ? LIMIT 1");
        $stmtV->execute([$view_id]);
        $view_app = $stmtV->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($view_app) {
            // decode json fields for convenience in the template
            $view_app['student_info'] = json_decode($view_app['student_info'] ?? '{}', true) ?: [];
            $view_app['files'] = json_decode($view_app['files'] ?? '[]', true) ?: [];
            $view_app['course_ids_arr'] = json_decode($view_app['course_ids'] ?? '[]', true) ?: [];

            // fetch processed_by name if present
            $view_app['processed_by_name'] = null;
            if (!empty($view_app['processed_by'])) {
                $stmtPB = $pdo->prepare("SELECT username, full_name FROM users WHERE id = ? LIMIT 1");
                $stmtPB->execute([(int)$view_app['processed_by']]);
                $pb = $stmtPB->fetch(PDO::FETCH_ASSOC);
                if ($pb) $view_app['processed_by_name'] = $pb['full_name'] ?: $pb['username'];
            }
        }
    } catch (Exception $e) {
        $view_app = null;
        error_log('Fetch view application error: ' . $e->getMessage());
        $error_msg = $error_msg ?: 'Could not load application details.';
    }
}

// Support optional filtering of applications by user_id (shows all applications submitted by a student)
$filter_user = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

// Fetch applications (filter by user if requested). Show all statuses when filtered by user.
try {
    if ($filter_user > 0) {
        $stmt = $pdo->prepare("SELECT ea.id, ea.user_id, ea.submitted_at, ea.status, ea.processed_at, ea.processed_by, u.username, u.full_name, ea.student_info
                               FROM enrollment_applications ea
                               LEFT JOIN users u ON ea.user_id = u.id
                               WHERE ea.user_id = ?
                               ORDER BY ea.submitted_at DESC");
        $stmt->execute([$filter_user]);
    } else {
        // Default: show submitted applications first
        $stmt = $pdo->prepare("SELECT ea.id, ea.user_id, ea.submitted_at, ea.status, ea.processed_at, ea.processed_by, u.username, u.full_name, ea.student_info
                               FROM enrollment_applications ea
                               LEFT JOIN users u ON ea.user_id = u.id
                               ORDER BY ea.submitted_at DESC");
        $stmt->execute();
    }
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $applications = [];
    $error_msg = $error_msg ?: 'Could not load applications.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Manage Applications</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    function confirmApprove() {
      return confirm('Approve this application and enroll the student in the selected course(s)?');
    }
    function confirmReject() {
      return confirm('Reject this application?');
    }
    function confirmDelete() {
      return confirm('Delete this application? This cannot be undone.');
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
          <a href="manage_courses.php" class="text-sm text-brand-600">Courses</a>
          <a href="manage_students.php" class="text-sm text-gray-600 hover:text-gray-900">Students</a>
          <a href="manage_payments.php" class="text-sm text-gray-600 hover:text-gray-900">Payments</a>
          <a href="manage_applications.php" class="text-sm text-gray-600 hover:text-gray-900 font-medium">Application</a>
        </div>
        <div class="flex items-center space-x-3">
          <a href="../public/logout.php" class="inline-flex items-center px-3 py-1.5 bg-red-600 text-white text-sm rounded hover:bg-red-700">Logout</a>
        </div>
      </div>
    </div>
  </nav>
  <main class="max-w-6xl mx-auto p-6">
    <header class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-semibold">Enrollment Applications</h1>
        <?php if ($filter_user > 0): ?>
          <?php
            // show student's name if available (take from first application if present)
            $studentName = '';
            foreach ($applications as $a) { if (!empty($a['full_name'])) { $studentName = $a['full_name']; break; } }
          ?>
          <div class="text-sm text-gray-500">Showing all applications for <?= $studentName ? h($studentName) : 'student ID ' . (int)$filter_user ?> — <a href="manage_applications.php" class="text-sky-600 hover:underline">clear filter</a></div>
        <?php else: ?>
          <div class="text-sm text-gray-500">Showing recent applications. Use "View all" to see every application by a student.</div>
        <?php endif; ?>
      </div>
    </header>

    <?php if ($info_msg): ?>
      <div class="mb-4 p-4 bg-green-50 border border-green-100 rounded text-green-800"><?= h($info_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
      <div class="mb-4 p-4 bg-red-50 border border-red-100 rounded text-red-800"><?= h($error_msg) ?></div>
    <?php endif; ?>

    <?php if ($view_app): ?>
      <!-- Detail panel for a single application -->
      <section class="mb-6 bg-white rounded shadow p-4">
        <div class="flex items-start justify-between">
          <div>
            <div class="text-sm text-gray-500">Application #<?= (int)$view_app['id'] ?> · Submitted: <?= h($view_app['submitted_at']) ?></div>
            <h2 class="text-xl font-semibold mt-1"><?= h($view_app['student_info']['first_name'] ?? $view_app['full_name'] ?? $view_app['username'] ?? 'Unknown') ?> <?= h($view_app['student_info']['last_name'] ?? '') ?></h2>
            <div class="text-sm text-gray-600 mt-1"><?= h($view_app['student_info']['email'] ?? $view_app['email'] ?? '') ?></div>
          </div>

          <!-- Top-right controls: Back, (optional) Delete, View all -->
          <div class="text-right space-x-2">
            <a href="manage_applications.php" class="inline-flex items-center px-3 py-1 text-sm rounded bg-gray-100 hover:bg-gray-200">Back to list</a>

            <?php if (($view_app['status'] ?? '') !== 'approved'): ?>
              <form method="post" onsubmit="return confirmDelete();" class="inline-block">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$view_app['id'] ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="inline-flex items-center px-3 py-1 text-sm rounded bg-gray-800 text-white hover:bg-gray-900">Delete</button>
              </form>
            <?php else: ?>
              <span class="inline-flex items-center px-3 py-1 text-sm rounded bg-gray-50 text-gray-500">Approved — cannot delete</span>
            <?php endif; ?>

            <a href="manage_applications.php?user_id=<?= (int)$view_app['user_id'] ?>" class="inline-flex items-center px-3 py-1 text-sm rounded bg-gray-100 hover:bg-gray-200">View all</a>
          </div>
        </div>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-6">
          <div>
            <h4 class="font-medium">Courses</h4>
            <ul class="text-sm text-gray-700 mt-2 space-y-1">
              <?php
                $courseIds = $view_app['course_ids_arr'] ?? [];
                if (empty($courseIds)):
              ?>
                <li>—</li>
              <?php else:
                  // fetch course labels safely
                  $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
                  $courses = [];
                  if (count($courseIds) > 0) {
                      $stmtC = $pdo->prepare("SELECT id, course_code, course_name FROM courses WHERE id IN ($placeholders)");
                      $stmtC->execute($courseIds);
                      $courses = $stmtC->fetchAll(PDO::FETCH_ASSOC);
                  }
                  foreach ($courses as $c):
              ?>
                <li><?= h($c['course_code']) ?> — <?= h($c['course_name']) ?></li>
              <?php endforeach; endif; ?>
            </ul>
          </div>

          <div>
            <h4 class="font-medium">Student details</h4>
            <div class="text-sm text-gray-700 mt-2">
              <div>Birth date: <?= h($view_app['student_info']['birth_date'] ?? '—') ?></div>
              <div>Contact: <?= h($view_app['student_info']['contact'] ?? '—') ?></div>
              <div>Birthplace: <?= h($view_app['student_info']['birthplace'] ?? '—') ?></div>
              <div class="mt-2">Religion: <?= h($view_app['student_info']['religion'] ?? '—') ?></div>
              <div>Age: <?= h($view_app['student_info']['age'] ?? '—') ?></div>
            </div>
          </div>

          <div>
            <h4 class="font-medium">Documents</h4>
            <ul class="text-sm text-gray-700 mt-2 space-y-1">
              <?php if (empty($view_app['files'])): ?>
                <li>—</li>
              <?php else: ?>
                <?php foreach ($view_app['files'] as $f): ?>
                  <li>
                    <?php if (!empty($f['type'])): ?><strong><?= h($f['type']) ?>:</strong> <?php endif; ?>
                    <?php if (!empty($f['stored_name'])): ?>
                      <a href="<?= h($f['stored_name']) ?>" target="_blank" class="text-indigo-600 hover:underline"><?= h($f['original_name'] ?? 'file') ?></a>
                    <?php else: ?>
                      <?= h($f['original_name'] ?? 'file') ?>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              <?php endif; ?>
            </ul>
          </div>
        </div>

        <div class="mt-4 flex gap-3">
          <?php if (($view_app['status'] ?? '') === 'submitted'): ?>
            <form method="post" onsubmit="return confirmApprove();" class="inline">
              <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
              <input type="hidden" name="application_id" value="<?= (int)$view_app['id'] ?>">
              <input type="hidden" name="action" value="approve">
              <button type="submit" class="px-4 py-2 rounded bg-green-600 text-white hover:bg-green-700">Approve & Enroll</button>
            </form>

            <form method="post" onsubmit="return confirmReject();" class="inline">
              <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
              <input type="hidden" name="application_id" value="<?= (int)$view_app['id'] ?>">
              <input type="hidden" name="action" value="reject">
              <button type="submit" class="px-4 py-2 rounded bg-red-600 text-white hover:bg-red-700">Reject</button>
            </form>

            <form method="post" onsubmit="return confirmDelete();" class="inline">
              <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
              <input type="hidden" name="application_id" value="<?= (int)$view_app['id'] ?>">
              <input type="hidden" name="action" value="delete">
              <button type="submit" class="px-4 py-2 rounded bg-gray-800 text-white hover:bg-gray-900">Delete</button>
            </form>
          <?php else: ?>
            <span class="inline-flex items-center px-3 py-1 rounded bg-gray-100 text-sm text-gray-700">No actions (status: <?= h($view_app['status']) ?>)</span>
          <?php endif; ?>

          <!-- View all by student -->
          <a href="manage_applications.php?user_id=<?= (int)$view_app['user_id'] ?>" class="inline-flex items-center px-3 py-2 rounded bg-gray-100 text-sm hover:bg-gray-200">View all applications by student</a>
        </div>

        <?php if (!empty($view_app['processed_at'])): ?>
          <div class="mt-3 text-xs text-gray-500">Processed at: <?= h($view_app['processed_at']) ?><?php if (!empty($view_app['processed_by_name'])) echo ' by ' . h($view_app['processed_by_name']); ?></div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if (empty($applications)): ?>
      <div class="p-6 bg-white rounded shadow">No applications found.</div>
    <?php else: ?>
      <div class="space-y-4">
        <?php foreach ($applications as $app): ?>
          <?php
            $studentInfo = json_decode($app['student_info'] ?? '{}', true) ?: [];
            $statusText = $app['status'] ?? '';
            [$badgeText, $badgeClass] = status_badge($statusText);
          ?>
          <div class="bg-white p-4 rounded shadow flex items-start justify-between">
            <div>
              <div class="text-sm text-gray-500">Application #<?= (int)$app['id'] ?> · Submitted: <?= h($app['submitted_at']) ?></div>
              <div class="text-lg font-medium mt-1"><?= h($studentInfo['first_name'] ?? $app['full_name'] ?? $app['username'] ?? 'Unknown') ?> <?= h($studentInfo['last_name'] ?? '') ?></div>
              <div class="text-sm text-gray-600"><?= h($studentInfo['email'] ?? '') ?></div>
            </div>

            <div class="flex items-center space-x-3">
              <a href="manage_applications.php?view_id=<?= (int)$app['id'] ?>" class="inline-flex items-center px-3 py-1 text-sm rounded bg-gray-100 hover:bg-gray-200">View</a>

              <a href="manage_applications.php?user_id=<?= (int)$app['user_id'] ?>" class="inline-flex items-center px-3 py-1 text-sm rounded bg-blue-50 text-blue-700 hover:bg-blue-100">View all</a>

              <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium <?= h($badgeClass) ?>"><?= h($badgeText) ?></span>

              <?php if (($app['status'] ?? '') === 'submitted'): ?>
                <form method="post" onsubmit="return confirmApprove();" class="inline">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                  <input type="hidden" name="application_id" value="<?= (int)$app['id'] ?>">
                  <input type="hidden" name="action" value="approve">
                  <button type="submit" class="px-3 py-1 rounded bg-green-600 text-white hover:bg-green-700 text-sm">Approve</button>
                </form>

                <form method="post" onsubmit="return confirmReject();" class="inline">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                  <input type="hidden" name="application_id" value="<?= (int)$app['id'] ?>">
                  <input type="hidden" name="action" value="reject">
                  <button type="submit" class="px-3 py-1 rounded bg-red-600 text-white hover:bg-red-700 text-sm">Reject</button>
                </form>
              <?php else: ?>
                <span class="text-xs text-gray-500">Processed</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </main>
</body>
</html>