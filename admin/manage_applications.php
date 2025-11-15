<?php
// public/manage_applications.php
// Admin UI to review enrollment applications and Approve / Reject them.
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

// Handle POST actions: approve / reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['application_id'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $error_msg = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'];
        $appId = (int)$_POST['application_id'];

        try {
            // load application
            $stmt = $pdo->prepare("SELECT * FROM enrollment_applications WHERE id = ? LIMIT 1 FOR UPDATE");
            $pdo->beginTransaction();
            $stmt->execute([$appId]);
            $app = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$app) {
                throw new Exception('Application not found.');
            }
            if ($app['status'] !== 'submitted') {
                throw new Exception('Only submitted applications can be processed.');
            }

            $now = date('Y-m-d H:i:s');
            $adminId = $_SESSION['user_id'] ?? null;

            if ($action === 'approve') {
                // Approve: create enrollment rows for each course and mark application approved
                $courseIds = json_decode($app['course_ids'] ?? '[]', true);
                if (!is_array($courseIds)) $courseIds = [];

                // Insert into enrollments table for each course (if you want to prevent duplicates, add checks)
                $enrollStmt = $pdo->prepare("INSERT INTO enrollments (user_id, course_id, enrolled_at) VALUES (?, ?, ?)");
                foreach ($courseIds as $cid) {
                    $cid = (int)$cid;
                    // Optionally check for existing enrollment to avoid duplicates
                    $check = $pdo->prepare("SELECT id FROM enrollments WHERE user_id = ? AND course_id = ? LIMIT 1");
                    $check->execute([$app['user_id'], $cid]);
                    if ($check->fetch()) {
                        // already enrolled, skip
                        continue;
                    }
                    $enrollStmt->execute([$app['user_id'], $cid, $now]);
                }

                // update application
                $u = $pdo->prepare("UPDATE enrollment_applications SET status = 'approved', processed_at = ?, processed_by = ? WHERE id = ?");
                $u->execute([$now, $adminId, $appId]);

                $pdo->commit();
                set_flash('Application approved and student enrolled.');
            } elseif ($action === 'reject') {
                // Optional: accept a rejection reason
                $reason = trim((string)($_POST['reject_reason'] ?? ''));
                $u = $pdo->prepare("UPDATE enrollment_applications SET status = 'rejected', processed_at = ?, processed_by = ? WHERE id = ?");
                $u->execute([$now, $adminId, $appId]);

                // You might want to save rejection reason into a separate column or a log table.
                $pdo->commit();
                set_flash('Application rejected.');
            } else {
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

// Fetch submitted applications
try {
    $stmt = $pdo->prepare("SELECT ea.*, u.username, u.full_name FROM enrollment_applications ea LEFT JOIN users u ON ea.user_id = u.id WHERE ea.status = 'submitted' ORDER BY ea.submitted_at ASC");
    $stmt->execute();
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
  </script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-800">
<nav class="bg-white border-b">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div class="flex items-center space-x-4">
          <a href="dashboard.php" class="text-lg font-semibold text-gray-900">Admin Panel</a>
          <a href="dashboard.php" class="text-sm text-gray-600 hover:text-gray-900">Dashboard</a>
          <a href="manage_courses.php" class="text-sm text-brand-600 font-medium">Courses</a>
          <a href="manage_students.php" class="text-sm text-gray-600 hover:text-gray-900">Students</a>
          <a href="manage_payments.php" class="text-sm text-gray-600 hover:text-gray-900">Payments</a>
        </div>
        <div class="flex items-center space-x-3">
          <a href="../public/logout.php" class="inline-flex items-center px-3 py-1.5 bg-red-600 text-white text-sm rounded hover:bg-red-700">Logout</a>
        </div>
      </div>
    </div>
  </nav>
  <main class="max-w-6xl mx-auto p-6">
    <header class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-semibold">Pending Enrollment Applications</h1>
      <a href="dashboard.php" class="text-sm text-gray-600 hover:underline">← Admin Dashboard</a>
    </header>

    <?php if ($info_msg): ?>
      <div class="mb-4 p-4 bg-green-50 border border-green-100 rounded text-green-800"><?= h($info_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
      <div class="mb-4 p-4 bg-red-50 border border-red-100 rounded text-red-800"><?= h($error_msg) ?></div>
    <?php endif; ?>

    <?php if (empty($applications)): ?>
      <div class="p-6 bg-white rounded shadow">No submitted applications at the moment.</div>
    <?php else: ?>
      <div class="space-y-4">
        <?php foreach ($applications as $app): ?>
          <?php
            $studentInfo = json_decode($app['student_info'] ?? '{}', true) ?: [];
            $files = json_decode($app['files'] ?? '[]', true) ?: [];
            $courseIds = json_decode($app['course_ids'] ?? '[]', true) ?: [];
          ?>
          <div class="bg-white p-4 rounded shadow">
            <div class="flex justify-between items-start">
              <div>
                <div class="text-sm text-gray-500">Application #<?= (int)$app['id'] ?> · Submitted: <?= h($app['submitted_at']) ?></div>
                <div class="text-lg font-medium mt-1"><?= h($studentInfo['first_name'] ?? $app['full_name'] ?? $app['username'] ?? 'Unknown') ?> <?= h($studentInfo['last_name'] ?? '') ?></div>
                <div class="text-sm text-gray-600"><?= h($studentInfo['email'] ?? '') ?></div>
              </div>

              <div class="text-right">
                <div class="text-sm text-gray-500">User ID: <?= (int)$app['user_id'] ?></div>
                <div class="text-sm text-gray-500">Status: <?= h($app['status']) ?></div>
              </div>
            </div>

            <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <h4 class="font-medium">Courses</h4>
                <ul class="text-sm text-gray-700 mt-2 space-y-1">
                  <?php if (empty($courseIds)): ?>
                    <li>—</li>
                  <?php else: ?>
                    <?php
                      // load course labels
                      $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
                      $courses = [];
                      if (count($courseIds) > 0) {
                        $stmtC = $pdo->prepare("SELECT id, course_code, course_name FROM courses WHERE id IN ($placeholders)");
                        $stmtC->execute($courseIds);
                        $courses = $stmtC->fetchAll(PDO::FETCH_ASSOC);
                      }
                    ?>
                    <?php foreach ($courses as $c): ?>
                      <li><?= h($c['course_code']) ?> — <?= h($c['course_name']) ?></li>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </ul>
              </div>

              <div>
                <h4 class="font-medium">Student details</h4>
                <div class="text-sm text-gray-700 mt-2">
                  <div>Birth date: <?= h($studentInfo['birth_date'] ?? '—') ?></div>
                  <div>Contact: <?= h($studentInfo['contact'] ?? '—') ?></div>
                  <div>Birthplace: <?= h($studentInfo['birthplace'] ?? '—') ?></div>
                </div>
              </div>

              <div>
                <h4 class="font-medium">Documents</h4>
                <ul class="text-sm text-gray-700 mt-2 space-y-1">
                  <?php if (empty($files)): ?>
                    <li>—</li>
                  <?php else: ?>
                    <?php foreach ($files as $f): ?>
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
              <form method="post" onsubmit="return confirmApprove();" class="inline">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$app['id'] ?>">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="px-4 py-2 rounded bg-green-600 text-white hover:bg-green-700">Approve & Enroll</button>
              </form>

              <form method="post" onsubmit="return confirmReject();" class="inline">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$app['id'] ?>">
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="px-4 py-2 rounded bg-red-600 text-white hover:bg-red-700">Reject</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </main>
</body>
</html>