<?php
// admin/manage_students.php
// Admin page to list, add, edit, promote, reset password, and delete student accounts.
// Tailwind frontend added; CSRF protection and flash handling included.
//
// Requires: ../includes/db_connect.php and ../includes/auth.php
// Place this file in the admin/ directory. Only accessible to admin users.

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin(); // starts session via auth.php

// Ensure session available for CSRF / flash
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
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

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf_token = $_SESSION['csrf_token'];

$info_msg = '';
$error_msg = '';

// Show flash if present (e.g. temp password info)
$info_msg = get_flash();

// Handle form submissions (with CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $error_msg = 'Invalid request (CSRF). Please reload and try again.';
    } else {
        $action = $_POST['action'];

        if ($action === 'add') {
            // Create a new student account
            $username = trim($_POST['username'] ?? '');
            $first_name = trim($_POST['first_name'] ?? '');
            $middle_name = trim($_POST['middle_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password_plain = trim($_POST['password'] ?? '');

            if ($username === '' || $first_name === '' || $last_name === '' || $email === '') {
                $error_msg = 'Please provide username, first name, last name and email.';
            } else {
                // Check uniqueness
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $stmt->execute([$username, $email]);
                if ($stmt->fetch()) {
                    $error_msg = 'Username or email already exists.';
                } else {
                    if ($password_plain === '') {
                        $password_plain = generate_temp_password(10);
                    }
                    $hash = password_hash($password_plain, PASSWORD_DEFAULT);
                    $role = 'student';
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, first_name, middle_name, last_name, email, role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                    if ($stmt->execute([$username, $hash, $first_name, $middle_name, $last_name, $email, $role])) {
                        // Use flash to display temporary password securely after redirect
                        set_flash("Student created. Temporary password: " . $password_plain);
                        header('Location: manage_students.php');
                        exit;
                    } else {
                        $error_msg = 'Failed to create student.';
                    }
                }
            }
        }

        elseif ($action === 'update') {
            // Update student details (not password here)
            $id = intval($_POST['id'] ?? 0);
            $username = trim($_POST['username'] ?? '');
            $first_name = trim($_POST['first_name'] ?? '');
            $middle_name = trim($_POST['middle_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if ($id <= 0 || $username === '' || $first_name === '' || $last_name === '' || $email === '') {
                $error_msg = 'Invalid input for update.';
            } else {
                // Ensure username/email uniqueness (excluding current user)
                $stmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
                $stmt->execute([$username, $email, $id]);
                if ($stmt->fetch()) {
                    $error_msg = 'Another account with that username or email already exists.';
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, first_name = ?, middle_name = ?, last_name = ?, email = ? WHERE id = ?");
                    if ($stmt->execute([$username, $first_name, $middle_name, $last_name, $email, $id])) {
                        set_flash('Student updated successfully.');
                        header('Location: manage_students.php');
                        exit;
                    } else {
                        $error_msg = 'Failed to update student.';
                    }
                }
            }
        }

        elseif ($action === 'delete') {
            // Delete user
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                $raw_id = $_POST['id'] ?? 'NULL';
                $error_msg = "Invalid user id (received: " . htmlspecialchars($raw_id) . ").";
            } else {
                // Prevent deleting other admins by accident
                $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $error_msg = 'User not found.';
                } elseif ($row['role'] === 'admin') {
                    $error_msg = 'Cannot delete an admin account.';
                } else {
                    // Database is configured with ON DELETE CASCADE for enrollments, payments, etc.
                    // So we can just delete the user.
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    if ($stmt->execute([$id])) {
                        set_flash('User deleted successfully.');
                        header('Location: manage_students.php');
                        exit;
                    } else {
                        $error_msg = 'Failed to delete user.';
                    }
                }
            }
        }
    }
    // fall through to display possible $error_msg
}

// Fetch user list (students and optionally admins)
$filter = $_GET['filter'] ?? 'students'; // 'students' or 'all'
if ($filter === 'all') {
    $stmt = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM enrollment_applications ea WHERE ea.user_id = u.id AND ea.status = 'approved') as is_enrolled FROM users u ORDER BY u.created_at DESC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT u.*, (SELECT COUNT(*) FROM enrollment_applications ea WHERE ea.user_id = u.id AND ea.status = 'approved') as is_enrolled FROM users u WHERE u.role = 'student' ORDER BY u.created_at DESC");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// If editing, load the user data
$edit_user = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Manage Students - Admin</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <!-- Tailwind CDN for quick prototyping; use compiled build in production -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      // copy text helper
      function copyText(text, btn) {
        if (!navigator.clipboard) {
          const ta = document.createElement('textarea');
          ta.value = text;
          document.body.appendChild(ta);
          ta.select();
          try { document.execCommand('copy'); } catch(e) {}
          document.body.removeChild(ta);
        } else {
          navigator.clipboard.writeText(text).catch(()=>{});
        }
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
          <a href="manage_students.php" class="text-sm text-brand-600 font-medium">Students</a>
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
      <h1 class="text-2xl font-semibold text-gray-900">Manage Students</h1>
      <p class="text-sm text-gray-500">Create and manage student accounts, promote, reset passwords, or remove users.</p>
    </div>

    <?php if ($info_msg): ?>
      <div class="mb-4">
        <div class="rounded-md bg-green-50 p-4 border border-green-100">
          <p class="text-sm text-green-700"><?= htmlspecialchars($info_msg) ?></p>
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

    <div class="flex items-center justify-between mb-4">
      <p class="text-sm text-gray-600">Showing:
        <?php if ($filter === 'all'): ?>
          All users (<a href="manage_students.php?filter=students" class="text-sky-600 hover:underline">show only students</a>)
        <?php else: ?>
          Students only (<a href="manage_students.php?filter=all" class="text-sky-600 hover:underline">show all users</a>)
        <?php endif; ?>
      </p>
    </div>

    <div class="grid grid-cols-1 <?= $edit_user ? 'lg:grid-cols-3' : '' ?> gap-6">
      <?php if ($edit_user): ?>
      <div class="lg:col-span-1 bg-white shadow rounded-lg p-6">
          <h2 class="text-lg font-medium text-gray-900 mb-4">Edit User</h2>
          <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$edit_user['id'] ?>">

            <div>
              <label class="block text-sm font-medium text-gray-700">Username</label>
              <input name="username" type="text" required value="<?= htmlspecialchars($edit_user['username']) ?>" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">First Name</label>
              <input name="first_name" type="text" required value="<?= htmlspecialchars($edit_user['first_name']) ?>" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Middle Name</label>
              <input name="middle_name" type="text" value="<?= htmlspecialchars($edit_user['middle_name'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Last Name</label>
              <input name="last_name" type="text" required value="<?= htmlspecialchars($edit_user['last_name']) ?>" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Email</label>
              <input name="email" type="email" required value="<?= htmlspecialchars($edit_user['email']) ?>" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
            </div>

            <div class="flex items-center gap-2">
              <button type="submit" class="inline-flex items-center px-4 py-2 bg-sky-600 text-white rounded-md text-sm hover:bg-sky-700">Update</button>
              <a href="manage_students.php" class="text-sm text-gray-600 hover:underline">Cancel</a>
            </div>
          </form>
      </div>
      <?php endif; ?>

      <div class="<?= $edit_user ? 'lg:col-span-2' : 'w-full' ?> bg-white shadow rounded-lg p-6 overflow-x-auto">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-lg font-medium text-gray-900">Users <span class="text-sm text-gray-500">(<?= count($users) ?>)</span></h2>
          <div class="text-sm text-gray-500">Tip: Use the actions to manage accounts.</div>
        </div>

        <?php if (count($users) === 0): ?>
          <div class="rounded bg-gray-50 p-6">
            <p class="text-sm text-gray-600">No users found.</p>
          </div>
        <?php else: ?>
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Full name</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created At</th>
                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach ($users as $u): ?>
                <tr>
                  <td class="px-4 py-3 text-sm text-gray-700"><?= (int)$u['id'] ?></td>
                  <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($u['username']) ?></td>
                  <td class="px-4 py-3 text-sm text-gray-900"><?= htmlspecialchars(trim($u['first_name'] . ' ' . ($u['middle_name'] ? $u['middle_name'] . ' ' : '') . $u['last_name'])) ?></td>
                  <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($u['email']) ?></td>
                  <td class="px-4 py-3 text-sm">
                    <?php if (!empty($u['is_enrolled']) && $u['is_enrolled'] > 0): ?>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Enrolled</span>
                    <?php else: ?>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Not Enrolled</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($u['role']) ?></td>
                  <td class="px-4 py-3 text-sm text-gray-500"><?= htmlspecialchars($u['created_at']) ?></td>
                  <td class="px-4 py-3 text-sm text-right space-x-2">
                    <a href="manage_students.php?edit_id=<?= (int)$u['id'] ?>" class="inline-flex items-center px-3 py-1 text-sm rounded bg-gray-100 hover:bg-gray-200">Edit</a>

                    <?php if ($u['role'] !== 'admin'): ?>
                      <form method="post" class="inline-block" onsubmit="return confirm('Delete this user? This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <button type="submit" class="inline-flex px-3 py-1 text-sm rounded bg-red-600 text-white hover:bg-red-700">Delete</button>
                      </form>
                    <?php else: ?>
                      <span class="inline-block px-3 py-1 text-sm rounded bg-gray-100 text-gray-600">admin</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="mt-6 text-sm text-gray-500">
      Tip: Use the actions to manage accounts.
    </div>
  </main>

  <footer class="bg-white border-t mt-12">
    <div class="max-w-7xl mx-auto px-4 text-center sm:px-6 lg:px-8 py-6 text-sm text-gray-500">
      © <?= date('Y') ?> Your Institution — Admin Panel
    </div>
  </footer>
</body>
</html>