<?php
// admin/manage_courses.php
// Admin page to list, add, edit, and delete courses with a Tailwind frontend.
//
// Usage:
// - Place this file in the admin/ directory of the starter project.
// - Requires ../includes/db_connect.php and ../includes/auth.php
// - Only accessible to users with role = 'admin'

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

// Ensure session available for flash / CSRF
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf_token = $_SESSION['csrf_token'];

// Flash helpers
$info_msg = '';
$error_msg = '';
if (!empty($_SESSION['flash'])) {
    $info_msg = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// Handle form submissions (add / update / delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Basic CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $error_msg = 'Invalid request (CSRF). Please reload the page and try again.';
    } else {
        $action = $_POST['action'];

        if ($action === 'add') {
            $course_code = trim($_POST['course_code'] ?? '');
            $course_name = trim($_POST['course_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $credits = intval($_POST['credits'] ?? 0);

            if ($course_code === '' || $course_name === '' || $credits <= 0) {
                $error_msg = 'Please provide course code, name and a positive credit value.';
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO courses (course_code, course_name, description, credits, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $stmt->execute([$course_code, $course_name, $description, $credits]);
                    $_SESSION['flash'] = 'Course added successfully.';
                    header('Location: manage_courses.php');
                    exit;
                } catch (PDOException $e) {
                    // unique constraint or other DB error
                    error_log('Add course error: ' . $e->getMessage());
                    $error_msg = 'Failed to add course: ' . htmlspecialchars($e->getMessage());
                }
            }
        }

        elseif ($action === 'update') {
            $id = intval($_POST['id'] ?? 0);
            $course_code = trim($_POST['course_code'] ?? '');
            $course_name = trim($_POST['course_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $credits = intval($_POST['credits'] ?? 0);

            if ($id <= 0 || $course_code === '' || $course_name === '' || $credits <= 0) {
                $error_msg = 'Invalid input for update.';
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE courses SET course_code = ?, course_name = ?, description = ?, credits = ? WHERE id = ?");
                    $stmt->execute([$course_code, $course_name, $description, $credits, $id]);
                    $_SESSION['flash'] = 'Course updated successfully.';
                    header('Location: manage_courses.php');
                    exit;
                } catch (PDOException $e) {
                    error_log('Update course error: ' . $e->getMessage());
                    $error_msg = 'Failed to update course: ' . htmlspecialchars($e->getMessage());
                }
            }
        }

        elseif ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error_msg = 'Invalid course id.';
            } else {
                try {
                    // Optional: prevent delete if enrollments exist
                    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM enrollments WHERE course_id = ?");
                    $stmt->execute([$id]);
                    $count = (int)$stmt->fetchColumn();
                    if ($count > 0) {
                        $error_msg = 'Cannot delete course: there are enrolled students. Remove enrollments first.';
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
                        $stmt->execute([$id]);
                        $_SESSION['flash'] = 'Course deleted successfully.';
                        header('Location: manage_courses.php');
                        exit;
                    }
                } catch (PDOException $e) {
                    error_log('Delete course error: ' . $e->getMessage());
                    $error_msg = 'Failed to delete course: ' . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
    // fall through to display possible $error_msg
}

// Show message passed via query (backwards compatible)
if (isset($_GET['msg']) && $_GET['msg'] !== '') {
    $info_msg = htmlspecialchars($_GET['msg']);
}

// Fetch all courses for display
try {
    $stmt = $pdo->query("SELECT * FROM courses ORDER BY course_name ASC");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $courses = [];
    error_log('Fetch courses error: ' . $e->getMessage());
    $error_msg = 'Failed to retrieve courses.';
}

// If editing, load course data
$edit_course = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_course = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Manage Courses - Admin</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">

    <!-- Tailwind CDN for quick prototyping; use a compiled build in production -->
    <script src="https://cdn.tailwindcss.com"></script>
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
          <a href="manage_courses.php" class="text-sm text-brand-600 font-medium">Courses</a>
          <a href="manage_students.php" class="text-sm text-gray-600 hover:text-gray-900">Students</a>
          <a href="manage_payments.php" class="text-sm text-gray-600 hover:text-gray-900">Payments</a>
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
      <h1 class="text-2xl font-semibold text-gray-900">Manage Courses</h1>
      <p class="text-sm text-gray-500">Add, edit or remove course offerings.</p>
    </div>

    <?php if ($info_msg): ?>
      <div class="mb-4">
        <div class="rounded-md bg-green-50 p-4 border border-green-100">
          <p class="text-sm text-green-700"><?= $info_msg ?></p>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
      <div class="mb-4">
        <div class="rounded-md bg-red-50 p-4 border border-red-100">
          <p class="text-sm text-red-700"><?= htmlspecialchars($error_msg) ?></p>
        </div>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-1 bg-white shadow rounded-lg p-6">
        <?php if ($edit_course): ?>
          <h2 class="text-lg font-medium text-gray-900 mb-4">Edit Course</h2>
          <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$edit_course['id'] ?>">

            <div>
              <label class="block text-sm font-medium text-gray-700">Course Code</label>
              <input name="course_code" type="text" required value="<?= htmlspecialchars($edit_course['course_code']) ?>" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Course Name</label>
              <input name="course_name" type="text" required value="<?= htmlspecialchars($edit_course['course_name']) ?>" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Credits</label>
              <input name="credits" type="number" min="1" required value="<?= (int)$edit_course['credits'] ?>" class="mt-1 block w-24 rounded-md border-gray-300 px-3 py-2" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Description</label>
              <textarea name="description" rows="4" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2"><?= htmlspecialchars($edit_course['description']) ?></textarea>
            </div>

            <div class="flex items-center gap-2">
              <button type="submit" class="inline-flex items-center px-4 py-2 bg-sky-600 text-white rounded-md text-sm hover:bg-sky-700">Save changes</button>
              <a href="manage_courses.php" class="text-sm text-gray-600 hover:underline">Cancel</a>
            </div>
          </form>
        <?php else: ?>
          <h2 class="text-lg font-medium text-gray-900 mb-4">Add Course</h2>
          <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="add">

            <div>
              <label class="block text-sm font-medium text-gray-700">Course Code</label>
              <input name="course_code" type="text" required class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Course Name</label>
              <input name="course_name" type="text" required class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Credits</label>
              <input name="credits" type="number" min="1" required value="3" class="mt-1 block w-24 rounded-md border-gray-300 px-3 py-2" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Description</label>
              <textarea name="description" rows="4" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2"></textarea>
            </div>

            <div>
              <button type="submit" class="w-full inline-flex justify-center items-center px-4 py-2 bg-sky-600 text-white rounded-md text-sm hover:bg-sky-700">Add Course</button>
            </div>
          </form>
        <?php endif; ?>
      </div>

      <div class="lg:col-span-2 bg-white shadow rounded-lg p-6 overflow-x-auto">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-lg font-medium text-gray-900">Existing Courses <span class="text-sm text-gray-500">(<?= count($courses) ?>)</span></h2>
          <div class="text-sm text-gray-500">Tip: click Edit to modify a course, or Delete to remove it.</div>
        </div>

        <?php if (count($courses) === 0): ?>
          <div class="rounded bg-gray-50 p-6">
            <p class="text-sm text-gray-600">No courses found. Add a course using the form on the left.</p>
          </div>
        <?php else: ?>
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Credits</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created At</th>
                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach ($courses as $c): ?>
                <tr>
                  <td class="px-4 py-3 text-sm text-gray-700"><?= (int)$c['id'] ?></td>
                  <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($c['course_code']) ?></td>
                  <td class="px-4 py-3 text-sm text-gray-900"><?= htmlspecialchars($c['course_name']) ?></td>
                  <td class="px-4 py-3 text-sm text-gray-700"><?= (int)$c['credits'] ?></td>
                  <td class="px-4 py-3 text-sm text-gray-500"><?= htmlspecialchars($c['created_at']) ?></td>
                  <td class="px-4 py-3 text-sm text-right">
                    <a href="manage_courses.php?edit_id=<?= (int)$c['id'] ?>" class="inline-flex items-center px-3 py-1 text-sm rounded bg-gray-100 hover:bg-gray-200">Edit</a>

                    <form method="post" class="inline-block ml-2" onsubmit="return confirm('Delete this course? This cannot be undone.');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                      <button type="submit" class="inline-flex items-center px-3 py-1 text-sm rounded bg-red-600 text-white hover:bg-red-700">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </main>

  <footer class="bg-white border-t mt-12">
    <div class="max-w-7xl mx-auto px-4 text-center sm:px-6 lg:px-8 py-6 text-sm text-gray-500">
      © <?= date('Y') ?> Your Institution — Admin Panel
    </div>
  </footer>
</body>
</html>