<?php
// public/register.php
// Registration page with Tailwind frontend and server-side validation.
//
// Requires: ../includes/db_connect.php which must provide a $pdo PDO instance.
// Place this file in the public/ directory.

session_start();
require_once __DIR__ . '/../includes/db_connect.php';

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf_token = $_SESSION['csrf_token'];

$errors = [];
$old = [
    'username' => '',
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'email' => ''
];
$info_msg = '';

// If redirected from a successful action, show flash
if (!empty($_SESSION['flash'])) {
    $info_msg = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $errors[] = "Invalid request. Please reload the page and try again.";
    } else {
        // Collect and trim inputs
        $username   = trim($_POST['username'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $password   = $_POST['password'] ?? '';
        $password2  = $_POST['password_confirm'] ?? '';

        $old['username'] = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $old['first_name'] = htmlspecialchars($first_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $old['middle_name'] = htmlspecialchars($middle_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $old['last_name'] = htmlspecialchars($last_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $old['email'] = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Validation
        if ($username === '' || !preg_match('/^[A-Za-z0-9._-]{3,30}$/', $username)) {
            $errors[] = "Username is required and must be 3-30 characters (letters, numbers, dot, underscore, hyphen).";
        }
        if ($first_name === '' || mb_strlen($first_name) < 2) {
            $errors[] = "Please enter your first name.";
        }
        if ($last_name === '' || mb_strlen($last_name) < 2) {
            $errors[] = "Please enter your last name.";
        }

        // Construct full name
        $name_parts = array_filter([$first_name, $middle_name, $last_name], fn($v) => $v !== '');
        $full_name = implode(' ', $name_parts);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address.";
        }
        if ($password === '' || strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters.";
        }
        if ($password !== $password2) {
            $errors[] = "Password confirmation does not match.";
        }

        // If no validation errors, check duplicates and insert
        if (empty($errors)) {
            try {
                // Check username or email exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
                $stmt->execute([$username, $email]);
                if ($stmt->fetch()) {
                    $errors[] = "Username or email already exists.";
                } else {
                    // Hash password and insert
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $role = 'student';
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, first_name, middle_name, last_name, full_name, email, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $ok = $stmt->execute([$username, $hash, $first_name, $middle_name, $last_name, $full_name, $email, $role]);
                    if ($ok) {
                        $_SESSION['flash'] = "Registration successful! Please sign in.";
                        header('Location: login.php');
                        exit;
                    } else {
                        $errors[] = "Registration failed. Please try again later.";
                    }
                }
            } catch (PDOException $e) {
                // Log the error for admins, but show a generic error to the user
                error_log("Registration error: " . $e->getMessage());
                $errors[] = "An error occurred while creating your account.";
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Register</title>

  <!-- Tailwind CDN for quick prototyping. Replace with compiled CSS in production -->
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    /* small helper to visually indicate password strength */
    .pw-weak { background: linear-gradient(90deg,#fca5a5,#fca5a5); }
    .pw-medium { background: linear-gradient(90deg,#fbbf24,#fbbf24); }
    .pw-strong { background: linear-gradient(90deg,#34d399,#34d399); }
  </style>
  <script>
    // Simple client-side password strength indicator & confirm match
    function checkPasswordStrength() {
      const pw = document.getElementById('password').value;
      const bar = document.getElementById('pw-strength');
      const msg = document.getElementById('pw-strength-text');
      if (!pw) { bar.className = ''; msg.textContent = ''; return; }
      let score = 0;
      if (pw.length >= 8) score++;
      if (/[A-Z]/.test(pw)) score++;
      if (/[0-9]/.test(pw)) score++;
      if (/[^A-Za-z0-9]/.test(pw)) score++;
      if (score <= 1) { bar.className = 'pw-weak h-2 rounded'; msg.textContent = 'Weak'; }
      else if (score === 2 || score === 3) { bar.className = 'pw-medium h-2 rounded'; msg.textContent = 'Medium'; }
      else { bar.className = 'pw-strong h-2 rounded'; msg.textContent = 'Strong'; }
    }

    function toggleShowPassword(id, btn) {
      const el = document.getElementById(id);
      if (!el) return;
      if (el.type === 'password') {
        el.type = 'text';
        btn.textContent = 'Hide';
      } else {
        el.type = 'password';
        btn.textContent = 'Show';
      }
    }
  </script>
</head>
<body class="min-h-screen bg-gray-50 flex items-center justify-center py-12 px-4">
  <div class="max-w-xl w-full">
    <div class="bg-white shadow-lg rounded-lg overflow-hidden">
      <div class="p-6">
        <h1 class="text-2xl font-semibold text-gray-900 mb-1">Create your account</h1>
        <p class="text-sm text-gray-500 mb-4">Register as a student to access the portal.</p>

        <?php if (!empty($info_msg)): ?>
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
                     value="<?= $old['username'] ?>"
                     class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-sky-500 focus:border-sky-500 sm:text-sm px-3 py-2" />
            </div>
            <p class="mt-1 text-xs text-gray-400">3–30 characters: letters, numbers, dots, underscores or hyphens.</p>
          </div>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <label for="first_name" class="block text-sm font-medium text-gray-700">First Name</label>
              <div class="mt-1">
                <input id="first_name" name="first_name" type="text" required
                       value="<?= $old['first_name'] ?>"
                       class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-sky-500 focus:border-sky-500 sm:text-sm px-3 py-2" />
              </div>
            </div>
            <div>
              <label for="middle_name" class="block text-sm font-medium text-gray-700">Middle Name</label>
              <div class="mt-1">
                <input id="middle_name" name="middle_name" type="text"
                       value="<?= $old['middle_name'] ?>"
                       class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-sky-500 focus:border-sky-500 sm:text-sm px-3 py-2" />
              </div>
            </div>
            <div>
              <label for="last_name" class="block text-sm font-medium text-gray-700">Last Name</label>
              <div class="mt-1">
                <input id="last_name" name="last_name" type="text" required
                       value="<?= $old['last_name'] ?>"
                       class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-sky-500 focus:border-sky-500 sm:text-sm px-3 py-2" />
              </div>
            </div>
          </div>

          <div>
            <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
            <div class="mt-1">
              <input id="email" name="email" type="email" required
                     value="<?= $old['email'] ?>"
                     class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-sky-500 focus:border-sky-500 sm:text-sm px-3 py-2" />
            </div>
          </div>

          <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
            <div class="mt-1 relative">
              <input id="password" name="password" type="password" required oninput="checkPasswordStrength()"
                     class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-sky-500 focus:border-sky-500 sm:text-sm px-3 py-2" />
              <button type="button" onclick="toggleShowPassword('password', this)" class="absolute right-2 top-2 text-sm px-2 py-1 bg-gray-100 rounded">Show</button>
            </div>
            <div class="mt-2 flex items-center gap-3">
              <div id="pw-strength" class="flex-1 h-2 rounded"></div>
              <div id="pw-strength-text" class="text-xs text-gray-500 w-14 text-right"></div>
            </div>
            <p class="mt-1 text-xs text-gray-400">At least 8 characters. Use a mix of letters, numbers and symbols.</p>
          </div>

          <div>
            <label for="password_confirm" class="block text-sm font-medium text-gray-700">Confirm Password</label>
            <div class="mt-1 relative">
              <input id="password_confirm" name="password_confirm" type="password" required
                     class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-sky-500 focus:border-sky-500 sm:text-sm px-3 py-2" />
              <button type="button" onclick="toggleShowPassword('password_confirm', this)" class="absolute right-2 top-2 text-sm px-2 py-1 bg-gray-100 rounded">Show</button>
            </div>
          </div>

          <div class="pt-2">
            <button type="submit" class="w-full inline-flex justify-center items-center px-4 py-2 bg-sky-600 text-white rounded-md shadow-sm hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-sky-500">
              Create account
            </button>
          </div>
        </form>

        <div class="mt-4 text-sm text-gray-600">
          Already registered? <a href="login.php" class="text-sky-600 hover:underline">Sign in</a>
        </div>
      </div>
    </div>

  </div>
</body>
</html>