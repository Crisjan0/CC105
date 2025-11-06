<?php
session_start();
require_once '../includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username=?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        if ($user['role'] === 'admin') {
            header('Location: ../admin/dashboard.php');
        } else {
            header('Location: dashboard.php');
        }
        exit;
    } else {
        echo "Invalid credentials!";
    }
}
?>

<!-- Login Form -->
<form method="post">
    Username: <input type="text" name="username" required/><br>
    Password: <input type="password" name="password" required/><br>
    <button type="submit">Login</button>
</form>