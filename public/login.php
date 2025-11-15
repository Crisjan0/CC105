<?php
// public/login.php
// Login page with Tailwind frontend.
// Requires: ../includes/db_connect.php (provides $pdo)

session_start();
require_once __DIR__ . '/../includes/db_connect.php';

// Simple flash helpers
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

// CSRF token helper
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf_token = $_SESSION['csrf_token'];

$errors = [];
$old_username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $errors[] = "Invalid request (CSRF token mismatch). Please reload the page and try again.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $old_username = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($username === '' || $password === '') {
            $errors[] = "Please enter both username and password.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['password'])) {
                    // Successful login
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = $user['role'];
                    set_flash("Welcome, " . htmlspecialchars($user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "!");

                    if ($user['role'] === 'admin') {
                        header('Location: ../admin/dashboard.php');
                    } else {
                        header('Location: dashboard.php');
                    }
                    exit;
                } else {
                    // Generic message to avoid user enumeration
                    $errors[] = "Invalid username or password.";
                }
            } catch (PDOException $e) {
                // Don't leak DB error details in production
                $errors[] = "An error occurred while checking credentials.";
                error_log("Login error: " . $e->getMessage());
            }
        }
    }
}

// Show any flash message set by previous actions
$info_msg = get_flash();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Login</title>

  <!-- Tailwind CDN for quick prototyping. Replace with compiled CSS in production. -->
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50 flex items-center justify-center">
  <div class="max-w-md w-full px-6">
    <div class="bg-white shadow-lg rounded-lg overflow-hidden">
      <div class="p-6">
        <h1 class="text-2xl font-semibold text-gray-900 mb-2">Sign in to your account</h1>
        <p class="text-sm text-gray-500 mb-4">Enter your credentials to continue.</p>

        <?php if ($info_msg): ?>
          <div class="mb-4 rounded-md bg-green-50 border border-green-100 p-3 text-green-800 text-sm">
            <?= htmlspecialchars($info_msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
          <div class="mb-4 rounded-md bg-red-50 border border-red-100 p-3 text-red-800 text-sm">
            <ul class="list-disc pl-5">
              <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="post" class="space-y-4" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

          <div>
            <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
            <div class="mt-1">
              <input id="username" name="username" type="text" required
                     value="<?= $old_username ?>"
                     class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-sky-500 focus:border-sky-500 sm:text-sm px-3 py-2" />
            </div>
          </div>

          <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
            <div class="mt-1">
              <input id="password" name="password" type="password" required
                     class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-sky-500 focus:border-sky-500 sm:text-sm px-3 py-2" />
            </div>
          </div>

          <div class="flex items-center justify-between">
            <div class="flex items-center">
              <input id="remember" name="remember" type="checkbox" class="h-4 w-4 text-sky-600 border-gray-300 rounded" />
              <label for="remember" class="ml-2 block text-sm text-gray-600">Remember me</label>
            </div>
            <div class="text-sm">
              <a href="#" class="font-medium text-sky-600 hover:text-sky-700">Forgot password?</a>
            </div>
          </div>

          <div>
            <button type="submit" class="w-full inline-flex justify-center items-center px-4 py-2 bg-sky-600 text-white rounded-md shadow-sm hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-sky-500">
              Sign in
            </button>
          </div>
        </form>
      </div>

      <div class="bg-gray-50 px-6 py-4 text-center text-sm text-gray-600">
        Don't have an account? <a href="register.php" class="text-sky-600 hover:underline">Register</a>
      </div>
    </div>

    
  </div>
</body>
</html>