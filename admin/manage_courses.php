<?php
// admin/manage_courses.php
// Admin page to list, add, edit, and delete courses.
//
// Usage:
// - Place this file in the admin/ directory of the starter project.
// - Requires ../includes/db_connect.php and ../includes/auth.php
// - Only accessible to users with role = 'admin'

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$info_msg = '';
$error_msg = '';

// Handle form submissions (add / update / delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
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
                $stmt = $pdo->prepare("INSERT INTO courses (course_code, course_name, description, credits) VALUES (?, ?, ?, ?)");
                $stmt->execute([$course_code, $course_name, $description, $credits]);
                $info_msg = 'Course added successfully.';
            } catch (PDOException $e) {
                // unique constraint or other DB error
                $error_msg = 'Failed to add course: ' . $e->getMessage();
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
                $info_msg = 'Course updated successfully.';
            } catch (PDOException $e) {
                $error_msg = 'Failed to update course: ' . $e->getMessage();
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
                    $info_msg = 'Course deleted successfully.';
                }
            } catch (PDOException $e) {
                $error_msg = 'Failed to delete course: ' . $e->getMessage();
            }
        }
    }

    // Redirect to avoid form resubmission and show fresh list
    if ($error_msg === '') {
        header('Location: manage_courses.php?msg=' . urlencode($info_msg));
        exit;
    } else {
        // Fall through to display the error message below
    }
}

// Show message passed via redirect if any
if (isset($_GET['msg']) && $_GET['msg'] !== '') {
    $info_msg = htmlspecialchars($_GET['msg']);
}

// Fetch all courses for display
$stmt = $pdo->query("SELECT * FROM courses ORDER BY course_name ASC");
$courses = $stmt->fetchAll();

// If editing, load course data
$edit_course = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_course = $stmt->fetch() ?: null;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Manage Courses - Admin</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        /* Minimal styling for clarity */
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
        form.inline { display: inline; }
        .msg { padding: 10px; margin-bottom: 10px; }
        .info { background: #e6ffed; border: 1px solid #b7f0c4; }
        .error { background: #ffe6e6; border: 1px solid #f0b7b7; }
        .small { font-size: 0.9em; color: #555; }
    </style>
</head>
<body>
    <h1>Manage Courses</h1>
    <p><a href="dashboard.php">← Admin Dashboard</a> | <a href="../public/logout.php">Logout</a></p>

    <?php if ($info_msg): ?>
        <div class="msg info"><?= htmlspecialchars($info_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="msg error"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- Add / Edit form -->
    <?php if ($edit_course): ?>
        <h2>Edit Course</h2>
        <form method="post">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$edit_course['id'] ?>">
            <label>Course Code: <input type="text" name="course_code" required value="<?= htmlspecialchars($edit_course['course_code']) ?>"></label><br><br>
            <label>Course Name: <input type="text" name="course_name" required value="<?= htmlspecialchars($edit_course['course_name']) ?>"></label><br><br>
            <label>Credits: <input type="number" name="credits" required min="1" value="<?= (int)$edit_course['credits'] ?>"></label><br><br>
            <label>Description:<br>
                <textarea name="description" rows="4" cols="50"><?= htmlspecialchars($edit_course['description']) ?></textarea>
            </label><br><br>
            <button type="submit">Update Course</button>
            <a href="manage_courses.php" style="margin-left:10px;">Cancel</a>
        </form>
    <?php else: ?>
        <h2>Add Course</h2>
        <form method="post">
            <input type="hidden" name="action" value="add">
            <label>Course Code: <input type="text" name="course_code" required></label><br><br>
            <label>Course Name: <input type="text" name="course_name" required></label><br><br>
            <label>Credits: <input type="number" name="credits" required min="1" value="3"></label><br><br>
            <label>Description:<br>
                <textarea name="description" rows="4" cols="50"></textarea>
            </label><br><br>
            <button type="submit">Add Course</button>
        </form>
    <?php endif; ?>

    <!-- Courses list -->
    <h2>Existing Courses <span class="small">(<?= count($courses) ?>)</span></h2>
    <?php if (count($courses) === 0): ?>
        <p>No courses found. Add a course using the form above.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Credits</th>
                    <th>Created At</th>
                    <th class="small">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($courses as $c): ?>
                    <tr>
                        <td><?= (int)$c['id'] ?></td>
                        <td><?= htmlspecialchars($c['course_code']) ?></td>
                        <td><?= htmlspecialchars($c['course_name']) ?></td>
                        <td><?= (int)$c['credits'] ?></td>
                        <td><?= htmlspecialchars($c['created_at']) ?></td>
                        <td>
                            <a href="manage_courses.php?edit_id=<?= (int)$c['id'] ?>">Edit</a>
                            &nbsp;|&nbsp;
                            <form method="post" class="inline" onsubmit="return confirm('Delete this course? This cannot be undone.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

</body>
</html>