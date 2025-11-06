<?php
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';
require_login();
$user_id = $_SESSION['user_id'];

// Enroll in course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_id'])) {
    $course_id = intval($_POST['course_id']);
    $stmt = $pdo->prepare("SELECT * FROM enrollments WHERE user_id=? AND course_id=?");
    $stmt->execute([$user_id, $course_id]);
    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO enrollments (user_id, course_id) VALUES (?, ?)");
        $stmt->execute([$user_id, $course_id]);
        echo "Successfully enrolled!";
    } else {
        echo "Already enrolled in this course.";
    }
}

// Show available courses
$stmt = $pdo->query("SELECT * FROM courses ORDER BY course_name");
$courses = $stmt->fetchAll();
?>

<h2>Available Courses</h2>
<form method="post">
    <select name="course_id">
        <?php foreach ($courses as $course): ?>
            <option value="<?= $course['id'] ?>"><?= htmlspecialchars($course['course_code'] . ': ' . $course['course_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Enroll</button>
</form>

<h3>Your Enrollments</h3>
<ul>
<?php
$stmt = $pdo->prepare("SELECT c.course_code, c.course_name FROM enrollments e
    JOIN courses c ON e.course_id = c.id WHERE e.user_id=?");
$stmt->execute([$user_id]);
foreach ($stmt->fetchAll() as $course) {
    echo "<li>" . htmlspecialchars($course['course_code']) . " - " . htmlspecialchars($course['course_name']) . "</li>";
}
?>
</ul>