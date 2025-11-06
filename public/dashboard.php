<?php
require_once '../includes/auth.php';
require_login();
echo "<h2>Welcome to your student dashboard!</h2>";
echo "<p><a href='enroll.php'>Enroll</a>|<a href='course_selection.php'>Select Courses</a> | <a href='payment.php'>Pay Fees</a> | <a href='logout.php'>Logout</a></p>";
?>