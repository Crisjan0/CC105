<?php
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';
require_admin();
echo "<h2>Admin Dashboard</h2>";
echo "<ul>
    <li><a href='manage_courses.php'>Manage Courses</a></li>
    <li><a href='manage_students.php'>Manage Students</a></li>
    <li><a href='manage_payments.php'>Manage Payments</a></li>
    <li><a href='../public/logout.php'>Logout</a></li>
</ul>";
?>