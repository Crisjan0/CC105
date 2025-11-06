<?php
// admin/manage_students.php
// Admin page to list, add, edit, promote, reset password, and delete student accounts.
//
// Requires: ../includes/db_connect.php and ../includes/auth.php
// Place this file in the admin/ directory. Only accessible to admin users.

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin(); // starts session via auth.php

$info_msg = '';
$error_msg = '';

// flash message support (stored in session by actions then shown after redirect)
if (isset($_SESSION['flash'])) {
    $info_msg = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// Helper: generate a random temporary password
function generate_temp_password($len = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+';
    $max = strlen($chars) - 1;
    $pw = '';
    for ($i = 0; $i < $len; $i++) {
        $pw .= $chars[random_int(0, $max)];
    }
    return $pw;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        // Create a new student account
        $username = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password_plain = trim($_POST['password'] ?? '');

        if ($username === '' || $full_name === '' || $email === '') {
            $error_msg = 'Please provide username, full name and email.';
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
                $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)");
                if ($stmt->execute([$username, $hash, $full_name, $email, $role])) {
                    // Use flash to display temporary password securely after redirect
                    $_SESSION['flash'] = "Student created. Temporary password: " . $password_plain;
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
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($id <= 0 || $username === '' || $full_name === '' || $email === '') {
            $error_msg = 'Invalid input for update.';
        } else {
            // Ensure username/email uniqueness (excluding current user)
            $stmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
            $stmt->execute([$username, $email, $id]);
            if ($stmt->fetch()) {
                $error_msg = 'Another account with that username or email already exists.';
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, full_name = ?, email = ? WHERE id = ?");
                if ($stmt->execute([$username, $full_name, $email, $id])) {
                    $_SESSION['flash'] = 'Student updated successfully.';
                    header('Location: manage_students.php');
                    exit;
                } else {
                    $error_msg = 'Failed to update student.';
                }
            }
        }
    }

    elseif ($action === 'promote') {
        // Promote user to admin
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $error_msg = 'Invalid user id.';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
            if ($stmt->execute([$id])) {
                $_SESSION['flash'] = 'User promoted to admin.';
                header('Location: manage_students.php');
                exit;
            } else {
                $error_msg = 'Failed to promote user.';
            }
        }
    }

    elseif ($action === 'reset_password') {
        // Generate a temporary password and update the user's hash
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $error_msg = 'Invalid user id.';
        } else {
            $temp_pw = generate_temp_password(10);
            $hash = password_hash($temp_pw, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stmt->execute([$hash, $id])) {
                // pass the temp password back via flash (visible only to admin)
                $_SESSION['flash'] = "Password reset. Temporary password: " . $temp_pw;
                header('Location: manage_students.php');
                exit;
            } else {
                $error_msg = 'Failed to reset password.';
            }
        }
    }

    elseif ($action === 'delete') {
        // Delete user only if no enrollments exist
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $error_msg = 'Invalid user id.';
        } else {
            // Prevent deleting other admins by accident
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                $error_msg = 'User not found.';
            } elseif ($row['role'] === 'admin') {
                $error_msg = 'Cannot delete an admin account.';
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE user_id = ?");
                $stmt->execute([$id]);
                $count = (int)$stmt->fetchColumn();
                if ($count > 0) {
                    $error_msg = 'Cannot delete user: there are enrollments. Remove enrollments first.';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    if ($stmt->execute([$id])) {
                        $_SESSION['flash'] = 'User deleted successfully.';
                        header('Location: manage_students.php');
                        exit;
                    } else {
                        $error_msg = 'Failed to delete user.';
                    }
                }
            }
        }
    }

    // If we get here with an error, show it without redirect so admin can correct
}

// Fetch user list (students and optionally admins)
$filter = $_GET['filter'] ?? 'students'; // 'students' or 'all'
if ($filter === 'all') {
    $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
} else {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'student' ORDER BY created_at DESC");
    $stmt->execute();
}
$users = $stmt->fetchAll();

// If editing, load the user data
$edit_user = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_user = $stmt->fetch() ?: null;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Manage Students - Admin</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
        form.inline { display: inline; }
        .msg { padding: 10px; margin-bottom: 10px; }
        .info { background: #e6ffed; border: 1px solid #b7f0c4; }
        .error { background: #ffe6e6; border: 1px solid #f0b7b7; }
        .small { font-size: 0.9em; color: #555; }
        .actions button { margin-right: 6px; }
    </style>
</head>
<body>
    <h1>Manage Students</h1>
    <p><a href="dashboard.php">← Admin Dashboard</a> | <a href="../public/logout.php">Logout</a></p>

    <?php if ($info_msg): ?>
        <div class="msg info"><?= htmlspecialchars($info_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="msg error"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <p class="small">Showing: 
        <?php if ($filter === 'all'): ?>
            All users (<a href="manage_students.php?filter=students">show only students</a>)
        <?php else: ?>
            Students only (<a href="manage_students.php?filter=all">show all users</a>)
        <?php endif; ?>
    </p>

    <!-- Add / Edit form -->
    <?php if ($edit_user): ?>
        <h2>Edit User</h2>
        <form method="post">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$edit_user['id'] ?>">
            <label>Username: <input type="text" name="username" required value="<?= htmlspecialchars($edit_user['username']) ?>"></label><br><br>
            <label>Full Name: <input type="text" name="full_name" required value="<?= htmlspecialchars($edit_user['full_name']) ?>"></label><br><br>
            <label>Email: <input type="email" name="email" required value="<?= htmlspecialchars($edit_user['email']) ?>"></label><br><br>
            <p class="small">Note: To change password, use the "Reset password" action from the list below.</p>
            <button type="submit">Update User</button>
            <a href="manage_students.php" style="margin-left:10px;">Cancel</a>
        </form>
    <?php else: ?>
        <h2>Add Student</h2>
        <form method="post">
            <input type="hidden" name="action" value="add">
            <label>Username: <input type="text" name="username" required></label><br><br>
            <label>Full Name: <input type="text" name="full_name" required></label><br><br>
            <label>Email: <input type="email" name="email" required></label><br><br>
            <label>Password (optional): <input type="text" name="password" placeholder="leave blank to auto-generate"></label><br><br>
            <button type="submit">Create Student</button>
        </form>
    <?php endif; ?>

    <!-- Users list -->
    <h2>Users <span class="small">(<?= count($users) ?>)</span></h2>
    <?php if (count($users) === 0): ?>
        <p>No users found.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Full name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Created At</th>
                    <th class="small">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= (int)$u['id'] ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['full_name']) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><?= htmlspecialchars($u['role']) ?></td>
                        <td><?= htmlspecialchars($u['created_at']) ?></td>
                        <td class="actions">
                            <a href="manage_students.php?edit_id=<?= (int)$u['id'] ?>">Edit</a>
                            &nbsp;|&nbsp;
                            <?php if ($u['role'] !== 'admin'): ?>
                                <form method="post" class="inline" onsubmit="return confirm('Promote this user to admin?');">
                                    <input type="hidden" name="action" value="promote">
                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit">Promote</button>
                                </form>
                                &nbsp;|&nbsp;
                                <form method="post" class="inline" onsubmit="return confirm('Reset password for this user? A temporary password will be generated.');">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit">Reset password</button>
                                </form>
                                &nbsp;|&nbsp;
                                <form method="post" class="inline" onsubmit="return confirm('Delete this user? This cannot be undone.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit">Delete</button>
                                </form>
                            <?php else: ?>
                                <span class="small">admin</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

</body>
</html>