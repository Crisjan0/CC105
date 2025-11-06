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
$courses = $stmt->fetchAll();

// Fetch current enrollments for the user
$stmt = $pdo->prepare("SELECT c.id, c.course_code, c.course_name, c.credits, e.enrolled_at
                       FROM enrollments e
                       JOIN courses c ON e.course_id = c.id
                       WHERE e.user_id = ?
                       ORDER BY c.course_name ASC");
$stmt->execute([$user_id]);
$enrollments = $stmt->fetchAll();

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Enroll in Courses</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 20px auto; padding: 0 12px; }
        h1 { margin-bottom: 6px; }
        form { margin-top: 12px; }
        .grid { display:grid; grid-template-columns: 1fr 320px; gap:20px; align-items:start; }
        .card { border:1px solid #ddd; padding:12px; border-radius:6px; background:#fff; }
        .courses-list { max-height: 400px; overflow:auto; padding:6px; }
        label.course { display:block; padding:6px; border-bottom:1px solid #f0f0f0; }
        .msg { padding:10px; margin-bottom:12px; border-radius:6px; }
        .info { background:#e6ffed; border:1px solid #b7f0c4; }
        .error { background:#ffe6e6; border:1px solid #f0b7b7; }
        .small { font-size:0.9em; color:#555; }
        .actions { margin-top:10px; }
        table { width:100%; border-collapse:collapse; margin-top:8px; }
        th, td { border:1px solid #eee; padding:8px; text-align:left; }
    </style>
</head>
<body>
    <h1>Course Enrollment</h1>
    <p class="small">Select one or more courses below and click "Enroll". You can see your current enrollments on the right.</p>
    <p><a href="dashboard.php">← Back to Dashboard</a> | <a href="logout.php">Logout</a></p>

    <?php if ($info_msg): ?>
        <div class="msg info"><?= htmlspecialchars($info_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="msg error"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <div class="grid">
        <div class="card">
            <form method="post" onsubmit="return confirmEnroll();">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <h2>Available Courses (<?= count($courses) ?>)</h2>
                <?php if (count($courses) === 0): ?>
                    <p>No courses available. Contact admin.</p>
                <?php else: ?>
                    <div class="courses-list" role="list">
                        <?php foreach ($courses as $c): ?>
                            <label class="course" title="<?= htmlspecialchars($c['course_name']) ?>">
                                <input type="checkbox" name="course_ids[]" value="<?= (int)$c['id'] ?>">
                                <strong><?= htmlspecialchars($c['course_code']) ?></strong>
                                &nbsp;—&nbsp;<?= htmlspecialchars($c['course_name']) ?>
                                <span class="small"> (<?= (int)$c['credits'] ?> cr)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="actions">
                        <button type="submit">Enroll</button>
                        <button type="button" onclick="selectAll(true)">Select all</button>
                        <button type="button" onclick="selectAll(false)">Clear</button>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <aside class="card">
            <h3>Your Enrollments (<?= count($enrollments) ?>)</h3>
            <?php if (count($enrollments) === 0): ?>
                <p>You are not enrolled in any courses yet.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Code</th><th>Name</th><th>Enrolled At</th></tr></thead>
                    <tbody>
                        <?php foreach ($enrollments as $e): ?>
                            <tr>
                                <td><?= htmlspecialchars($e['course_code']) ?></td>
                                <td><?= htmlspecialchars($e['course_name']) ?></td>
                                <td><?= htmlspecialchars($e['enrolled_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="small">If you need to drop a course, contact an administrator (or we can add a drop feature later).</p>
        </aside>
    </div>

    <script>
        function selectAll(state) {
            document.querySelectorAll('input[name="course_ids[]"]').forEach(function(chk) {
                chk.checked = state;
            });
        }
        function confirmEnroll() {
            // Ensure at least one is checked before submitting
            const anyChecked = Array.from(document.querySelectorAll('input[name="course_ids[]"]')).some(c => c.checked);
            if (!anyChecked) {
                alert('Please select at least one course to enroll.');
                return false;
            }
            return confirm('Confirm enrollment in the selected course(s)?');
        }
    </script>
</body>
</html>