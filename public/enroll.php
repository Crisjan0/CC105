<?php
// public/enroll.php
// Student enrollment form: select one or more courses and enroll.
// Requires: ../includes/db_connect.php and ../includes/auth.php
// Place in public/ directory. Only accessible to logged-in users.

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$user_id = $_SESSION['user_id'];
$info_msg = '';
$error_msg = '';

// --- CSRF token helper (simple) ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf_token = $_SESSION['csrf_token'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_msg = 'Invalid form submission (CSRF check failed).';
    } else {
        // collect selected courses (checkboxes named course_ids[])
        $selected = $_POST['course_ids'] ?? [];
        if (!is_array($selected) || count($selected) === 0) {
            $error_msg = 'Please select at least one course to enroll.';
        } else {
            // sanitize and unique course IDs
            $course_ids = array_values(array_unique(array_map('intval', $selected)));
            try {
                // We'll insert enrollments one-by-one, but wrap in transaction
                $pdo->beginTransaction();

                $inserted = 0;
                $skipped = 0;
                $stmt_check = $pdo->prepare("SELECT id FROM enrollments WHERE user_id = ? AND course_id = ?");
                $stmt_insert = $pdo->prepare("INSERT INTO enrollments (user_id, course_id) VALUES (?, ?)");

                foreach ($course_ids as $cid) {
                    if ($cid <= 0) continue;
                    $stmt_check->execute([$user_id, $cid]);
                    if ($stmt_check->fetch()) {
                        $skipped++;
                        continue; // already enrolled
                    }
                    $stmt_insert->execute([$user_id, $cid]);
                    $inserted++;
                }

                $pdo->commit();

                $parts = [];
                if ($inserted > 0) $parts[] = "$inserted enrolled";
                if ($skipped > 0) $parts[] = "$skipped skipped (already enrolled)";
                $info_msg = implode('; ', $parts) ?: 'No changes made.';
                // Refresh CSRF token after successful POST
                $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
                $csrf_token = $_SESSION['csrf_token'];
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_msg = 'Failed to enroll: ' . $e->getMessage();
            }
        }
    }
}

// Fetch available courses (all)
$stmt = $pdo->query("SELECT id, course_code, course_name, credits FROM courses ORDER BY course_name ASC");
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch current enrollments for the user
$stmt = $pdo->prepare("SELECT c.id, c.course_code, c.course_name, c.credits, e.enrolled_at
                       FROM enrollments e
                       JOIN courses c ON e.course_id = c.id
                       WHERE e.user_id = ?
                       ORDER BY c.course_name ASC");
$stmt->execute([$user_id]);
$enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Enroll in Courses</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <!-- Tailwind CDN for quick prototyping. Use a compiled build in production. -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      // small client-side helpers
      function selectAll(state) {
        document.querySelectorAll('input[name="course_ids[]"]').forEach(function(chk) {
          chk.checked = state;
        });
      }

      function confirmEnroll() {
        const anyChecked = Array.from(document.querySelectorAll('input[name="course_ids[]"]')).some(c => c.checked);
        if (!anyChecked) {
          alert('Please select at least one course to enroll.');
          return false;
        }
        return confirm('Confirm enrollment in the selected course(s)?');
      }
    </script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-800">
  <header class="bg-white shadow">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div>
          <h1 class="text-lg font-semibold text-gray-900">Course Enrollment</h1>
          <p class="text-sm text-gray-500">Select one or more courses and click "Enroll". Your current enrollments are shown at the right.</p>
        </div>
        <div class="space-x-3">
          <a href="dashboard.php" class="text-sm text-gray-600 hover:underline">← Back to Dashboard</a>
          <a href="logout.php" class="text-sm text-red-600 hover:underline ml-4">Logout</a>
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
      <!-- Left: Course selection -->
      <section class="lg:col-span-2 bg-white shadow rounded-lg p-6">
        <form method="post" onsubmit="return confirmEnroll();" class="space-y-4">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

          <div class="flex items-start justify-between">
            <h2 class="text-xl font-medium text-gray-900">Available Courses <span class="text-sm text-gray-500"> (<?= count($courses) ?>)</span></h2>
            <div class="flex items-center space-x-2">
              <button type="button" onclick="selectAll(true)" class="inline-flex items-center px-3 py-1.5 bg-gray-100 text-sm rounded hover:bg-gray-200">Select all</button>
              <button type="button" onclick="selectAll(false)" class="inline-flex items-center px-3 py-1.5 bg-gray-100 text-sm rounded hover:bg-gray-200">Clear</button>
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
              <button type="button" onclick="selectAll(true)" class="inline-flex items-center px-3 py-2 bg-gray-100 rounded text-sm hover:bg-gray-200">Select all</button>
              <button type="button" onclick="selectAll(false)" class="inline-flex items-center px-3 py-2 bg-gray-100 rounded text-sm hover:bg-gray-200">Clear</button>
            </div>
          <?php endif; ?>
        </form>
      </section>

      <!-- Right: Current enrollments -->
      <aside class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900">Your Enrollments <span class="text-sm text-gray-500"> (<?= count($enrollments) ?>)</span></h3>

        <?php if (count($enrollments) === 0): ?>
          <div class="mt-4 text-sm text-gray-600">You are not enrolled in any courses yet.</div>
        <?php else: ?>
          <div class="mt-4 overflow-auto">
            <table class="min-w-full text-sm divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                  <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Course</th>
                  <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Enrolled At</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-100">
                <?php foreach ($enrollments as $e): ?>
                  <tr>
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

  <footer class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-8 text-sm text-gray-500">
    © <?= date('Y') ?> Your Institution
  </footer>
</body>
</html>