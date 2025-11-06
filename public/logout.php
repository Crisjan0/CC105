<?php
// public/logout.php
// Destroys the user's session and redirects to the login page.

session_start();

// Clear all session variables
$_SESSION = [];

// If session cookie exists, invalidate it on the client
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session data on the server
session_destroy();

// Redirect back to the login page (relative to this file: public/login.php)
header('Location: login.php');
exit;
?>