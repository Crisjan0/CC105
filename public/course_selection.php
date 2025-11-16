<?php
// public/enroll.php
// Student enroll page with Tailwind frontend, CSRF protection, flash messages,
// safe DB handling and avoidance of form resubmission.
//
// Requires: ../includes/db_connect.php (provides $pdo) and ../includes/auth.php
// Place in public/ directory. Only accessible to logged-in users.

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_login(); // ensures session + user are available

// Ensure session (auth.php usually starts session, but be defensive)
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header('Location: login.php');
    exit;
}

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

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf_token = $_SESSION['csrf_token'];

// Handle POST: accept either single course_id (select) or multiple course_ids[] (checkboxes)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic CSRF protection
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $error_msg = 'Invalid form submission (CSRF check failed).';
    } else {
        // collect selected courses
        $selected = [];
        if (!empty($_POST['course_ids']) && is_array($_POST['course_ids'])) {
            $selected = array_map('intval', $_POST['course_ids']);
        } elseif (!empty($_POST['course_id'])) {
            $selected = [intval($_POST['course_id'])];
        }

        // sanitize and remove invalid ids
        $selected = array_values(array_unique(array_filter($selected, function($v){ return $v > 0; })));

        if (empty($selected)) {
            $error_msg = 'Please select at least one course to enroll.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt_check = $pdo->prepare("SELECT 1 FROM enrollments WHERE user_id = ? AND course_id = ? LIMIT 1");
                $stmt_insert = $pdo->prepare("INSERT INTO enrollments (user_id, course_id, enrolled_at) VALUES (?, ?, NOW())");
                $stmt_course = $pdo->prepare("SELECT id FROM courses WHERE id = ? LIMIT 1");

                $inserted = 0;
                $skipped = 0;
                foreach ($selected as $cid) {
                    // verify course exists
                    $stmt_course->execute([$cid]);
                    if (!$stmt_course->fetch()) {
                        $skipped++;
                        continue;
                    }

                    $stmt_check->execute([$user_id, $cid]);
                    if ($stmt_check->fetch()) {
                        $skipped++;
                        continue;
                    }

                    $stmt_insert->execute([$user_id, $cid]);
                    $inserted++;
                }

                $pdo->commit();

                $parts = [];
                if ($inserted > 0) $parts[] = "$inserted enrolled";
                if ($skipped > 0) $parts[] = "$skipped skipped";
                set_flash(implode('; ', $parts) ?: 'No changes made.');

                // rotate CSRF token and redirect to avoid form resubmission
                $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
                header('Location: enroll.php');
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Enroll error: ' . $e->getMessage());
                $error_msg = 'Failed to enroll. Please try again later.';
            }
        }
    }
}

// Fetch available courses
try {
    $stmt = $pdo->query("SELECT id, course_code, course_name, credits FROM courses ORDER BY course_name ASC");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $courses = [];
    error_log('Fetch courses error: ' . $e->getMessage());
    $error_msg = $error_msg ?: 'Could not load courses.';
}

// Fetch current enrollments for the user
try {
    $stmt = $pdo->prepare("SELECT c.id, c.course_code, c.course_name, c.credits, e.enrolled_at
                           FROM enrollments e
                           JOIN courses c ON e.course_id = c.id
                           WHERE e.user_id = ?
                           ORDER BY c.course_name ASC");
    $stmt->execute([$user_id]);
    $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $enrollments = [];
    error_log('Fetch enrollments error: ' . $e->getMessage());
    $error_msg = $error_msg ?: 'Could not load your enrollments.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Enroll in Courses</title>
  <!-- Tailwind CDN for quick prototyping; use a compiled build in production -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    function selectAllCourses(state) {
      document.querySelectorAll('input[name="course_ids[]"]').forEach(cb => cb.checked = state);
    }
    function ensureSelectionBeforeSubmit() {
      const any = Array.from(document.querySelectorAll('input[name="course_ids[]"]')).some(c => c.checked);
      if (!any) {
        const single = document.querySelector('select[name="course_id"]');
        if (single && single.value) {
          return confirm('Enroll in the selected course?');
        }
        alert('Please select at least one course to enroll.');
        return false;
      }
      return confirm('Confirm enrollment in the selected course(s)?');
    }
    function copyEnrollments() {
      const rows = Array.from(document.querySelectorAll('#enrollmentsTable tbody tr')).map(tr => tr.dataset.name);
      if (!rows.length) return alert('No enrollments to copy.');
      navigator.clipboard?.writeText(rows.join('\n')).then(() => alert('Copied to clipboard.'));
    }
  </script>
  <style>
    body::-webkit-scrollbar{
      display:none;
    }
  </style>
</head>
<body class="min-h-screen bg-gray-50 text-gray-800">
  <header class="bg-white shadow">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between py-6">
        <div>
          <h1 class="text-lg font-semibold text-gray-900">Course Enrollment</h1>
          <p class="text-sm text-gray-500">Select courses to enroll. Your current enrollments are shown on the right.</p>
        </div>
        <div class="flex items-center space-x-4">
          <a href="dashboard.php" class="text-sm text-gray-600 hover:underline font-medium">←Back to Dashboard</a>
          <a href="logout.php"
             class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
             aria-label="Logout">Logout</a>
        </div>
      </div>
    </div>
  </header>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <?php if ($info_msg): ?>
      <div class="mb-4 rounded-md bg-green-50 border border-green-100 p-4">
        <p class="text-sm text-green-800"><?= htmlspecialchars($info_msg) ?></p>
      </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
      <div class="mb-4 rounded-md bg-red-50 border border-red-100 p-4">
        <p class="text-sm text-red-800"><?= htmlspecialchars($error_msg) ?></p>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Course selection -->
      <section class="lg:col-span-2 bg-white shadow rounded-lg p-6">
        <form method="post" onsubmit="return ensureSelectionBeforeSubmit();" class="space-y-4">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

          <div class="flex items-center justify-between">
            <h2 class="text-xl font-medium text-gray-900">Available Courses <span class="text-sm text-gray-500"> (<?= count($courses) ?>)</span></h2>
            <div class="flex items-center space-x-2">
              <button type="button" onclick="selectAllCourses(true)" class="inline-flex items-center px-3 py-1.5 bg-gray-100 text-sm rounded hover:bg-gray-200">Select all</button>
              <button type="button" onclick="selectAllCourses(false)" class="inline-flex items-center px-3 py-1.5 bg-gray-100 text-sm rounded hover:bg-gray-200">Clear</button>
            </div>
          </div>

          <?php if (count($courses) === 0): ?>
            <p class="text-sm text-gray-600">No courses available. Contact an administrator.</p>
          <?php else: ?>
            <div class="mt-3 border border-gray-100 rounded divide-y divide-gray-100 max-h-96 overflow-auto">
              <?php foreach ($courses as $c): ?>
                <label class="flex items-center justify-between p-4 hover:bg-gray-50 cursor-pointer">
                  <div class="flex items-start space-x-3">
                    <input type="checkbox" name="course_ids[]" value="<?= (int)$c['id'] ?>" class="mt-1 h-4 w-4 text-sky-600 border-gray-300 rounded" aria-label="<?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']) ?>">
                    <div>
                      <div class="text-sm font-medium text-gray-900">
                        <?= htmlspecialchars($c['course_code']) ?> — <?= htmlspecialchars($c['course_name']) ?>
                      </div>
                      <div class="text-xs text-gray-500 mt-0.5">
                        <?= (int)$c['credits'] ?> credit<?= (int)$c['credits'] !== 1 ? 's' : '' ?>
                      </div>
                    </div>
                  </div>
                  <svg class="h-5 w-5 text-gray-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6l4 2"></path>
                  </svg>
                </label>
              <?php endforeach; ?>
            </div>

            <div class="mt-4 flex items-center space-x-3">
              <button type="submit" class="inline-flex items-center px-4 py-2 bg-sky-600 text-white rounded-md text-sm hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-sky-500">Enroll</button>

              <!-- Optional single-select fallback for quick enroll -->
              <div class="ml-4">
                <label class="sr-only">Quick enroll</label>
                <select name="course_id" class="rounded border-gray-300 px-2 py-1 text-sm">
                  <option value="">Quick enroll...</option>
                  <?php foreach ($courses as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['course_code'] . ' — ' . $c['course_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          <?php endif; ?>
        </form>
      </section>

      <!-- Current enrollments -->
      <aside class="bg-white shadow rounded-lg p-6">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-medium text-gray-900">Your Enrollments <span class="text-sm text-gray-500"> (<?= count($enrollments) ?>)</span></h3>
          <button type="button" onclick="copyEnrollments()" class="text-sm text-gray-600 hover:underline">Copy</button>
        </div>

        <?php if (count($enrollments) === 0): ?>
          <div class="mt-4 text-sm text-gray-600">You are not enrolled in any courses yet.</div>
        <?php else: ?>
          <div class="mt-4 overflow-auto">
            <table id="enrollmentsTable" class="min-w-full text-sm divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                  <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Course</th>
                  <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Enrolled At</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-100">
                <?php foreach ($enrollments as $e): ?>
                  <tr data-name="<?= htmlspecialchars($e['course_code'] . ' - ' . $e['course_name']) ?>">
                    <td class="px-3 py-2 text-gray-700"><?= htmlspecialchars($e['course_code']) ?></td>
                    <td class="px-3 py-2 text-gray-900"><?= htmlspecialchars($e['course_name']) ?></td>
                    <td class="px-3 py-2 text-gray-500"><?= htmlspecialchars($e['enrolled_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <div class="mt-4 text-xs text-gray-500">
          Need to drop a course? Contact an administrator or use the "My Courses" page once drop functionality is available.
        </div>
      </aside>
    </div>
  </main>
</body>
</html>