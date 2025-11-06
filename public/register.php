<?php
require_once '../includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $role = 'student';

    // Check if username or email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username=? OR email=?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        echo "Username or email already exists!";
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)");
        if ($stmt->execute([$username, $password, $full_name, $email, $role])) {
            echo "Registration successful! <a href='login.php'>Login here</a>";
        } else {
            echo "Registration failed!";
        }
    }
}
?>

<!-- Registration Form -->
<form method="post">
    Username: <input type="text" name="username" required/><br>
    Full Name: <input type="text" name="full_name" required/><br>
    Email: <input type="email" name="email" required/><br>
    Password: <input type="password" name="password" required/><br>
    <button type="submit">Register</button>
</form>