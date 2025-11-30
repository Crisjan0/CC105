<?php
// public/profile.php
// Student profile page: view and edit profile, change password, see enrollments & payments.
// Requires ../includes/db_connect.php and ../includes/auth.php
// Place in public/ directory. Only accessible to logged-in users.

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_login(); // ensures session and user are available

// Ensure session (auth.php typically starts it, defensive)
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header('Location: login.php');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf_token = $_SESSION['csrf_token'];

// Flash helpers
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

$info_msg = get_flash();
$error_msg = '';

// Fetch current user record
try {
    $stmt = $pdo->prepare("SELECT id, username, first_name, middle_name, last_name, email, created_at FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        // If user not found, force logout
        header('Location: logout.php');
        exit;
    }
} catch (PDOException $e) {
    error_log('Fetch user error: ' . $e->getMessage());
    $error_msg = 'Could not load profile.';
    $user = ['username' => '', 'first_name' => '', 'middle_name' => '', 'last_name' => '', 'email' => '', 'created_at' => ''];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Basic CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $error_msg = 'Invalid request (CSRF). Please reload the page and try again.';
    } else {
        $action = $_POST['action'];

        if ($action === 'update_profile') {
            $first_name = trim($_POST['first_name'] ?? '');
            $middle_name = trim($_POST['middle_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if ($first_name === '' || $last_name === '' || $email === '') {
                $error_msg = 'First name, last name, and email are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error_msg = 'Please enter a valid email address.';
            } else {
                // Ensure email uniqueness excluding current user
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                $stmt->execute([$email, $user_id]);
                if ($stmt->fetch()) {
                    $error_msg = 'Email is already used by another account.';
                } else {
                    try {
                        $stmt = $pdo->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ? WHERE id = ?");
                        $stmt->execute([$first_name, $middle_name, $last_name, $email, $user_id]);
                        set_flash('Profile updated successfully.');
                        // refresh user data and token
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
                        header('Location: profile.php');
                        exit;
                    } catch (PDOException $e) {
                        error_log('Update profile error: ' . $e->getMessage());
                        $error_msg = 'Failed to update profile. Please try again later.';
                    }
                }
            }
        }

        elseif ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if ($current === '' || $new === '' || $confirm === '') {
                $error_msg = 'Please fill all password fields.';
            } elseif ($new !== $confirm) {
                $error_msg = 'New password and confirmation do not match.';
            } elseif (strlen($new) < 8) {
                $error_msg = 'New password must be at least 8 characters.';
            } else {
                try {
                    // Fetch hashed password
                    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
                    $stmt->execute([$user_id]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$row || !password_verify($current, $row['password'])) {
                        $error_msg = 'Current password is incorrect.';
                    } else {
                        $hash = password_hash($new, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmt->execute([$hash, $user_id]);
                        set_flash('Password changed successfully.');
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
                        header('Location: profile.php');
                        exit;
                    }
                } catch (PDOException $e) {
                    error_log('Change password error: ' . $e->getMessage());
                    $error_msg = 'Failed to change password. Please try again later.';
                }
            }
        }
    }
}

// Fetch a small list of enrollments and payments for display
try {
    $stmt = $pdo->prepare("SELECT c.course_code, c.course_name, e.enrolled_at
                           FROM enrollments e
                           JOIN courses c ON e.course_id = c.id
                           WHERE e.user_id = ?
                           ORDER BY e.enrolled_at DESC
                           LIMIT 10");
    $stmt->execute([$user_id]);
    $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $enrollments = [];
    error_log('Fetch enrollments error: ' . $e->getMessage());
}

try {
    $stmt = $pdo->prepare("SELECT id, amount, payment_status, payment_date
                           FROM payments
                           WHERE user_id = ?
                           ORDER BY payment_date DESC
                           LIMIT 10");
    $stmt->execute([$user_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $payments = [];
    error_log('Fetch payments error: ' . $e->getMessage());
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Your Profile</title>

  <!-- Tailwind CDN for quick prototyping. Replace with compiled CSS in production. -->
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body::-webkit-scrollbar{
      display:none;
    }
  </style>
</head>
<body class="min-h-screen bg-gray-50 text-gray-800">
  <header class="bg-white shadow">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex justify-between items-center py-6">
        <div>
          <h1 class="text-xl font-semibold">Profile</h1>
          <p class="text-sm text-gray-500">Manage your account and view recent activity.</p>
        </div>
        <div class="flex items-center space-x-3">
          <a href="dashboard.php" class="text-sm text-gray-600 hover:underline font-medium">←Back to Dashboard</a>
          <a href="logout.php"
             class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
             aria-label="Logout">Logout</a>
        </div>
      </div>
    </div>
  </header>

  <main class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <?php if ($info_msg): ?>
      <div class="mb-4 rounded-md bg-green-50 border border-green-100 p-4">
        <p class="text-sm text-green-800"><?= htmlspecialchars($info_msg) ?></p>
      </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
      <div class="mb-4 rounded-md bg-red-50 border border-red-100 p-4">
        <p class="text-sm text-red-800"><?= htmlspecialchars($error_msg) ?></p>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <section class="lg:col-span-2 bg-white shadow rounded-lg p-6">
        <h2 class="text-lg font-medium text-gray-900 mb-4">Account</h2>

        <form method="post" class="space-y-4">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          <input type="hidden" name="action" value="update_profile">

          <div>
            <label class="block text-sm font-medium text-gray-700">Username</label>
            <div class="mt-1 text-sm text-gray-900"><?= htmlspecialchars($user['username']) ?></div>
            <p class="text-xs text-gray-400 mt-1">Your username cannot be changed here. Contact admin if you need to change it.</p>
          </div>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <label for="first_name" class="block text-sm font-medium text-gray-700">First Name</label>
              <input id="first_name" name="first_name" type="text" required value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
            </div>
            <div>
              <label for="middle_name" class="block text-sm font-medium text-gray-700">Middle Name</label>
              <input id="middle_name" name="middle_name" type="text" value="<?= htmlspecialchars($user['middle_name'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
            </div>
            <div>
              <label for="last_name" class="block text-sm font-medium text-gray-700">Last Name</label>
              <input id="last_name" name="last_name" type="text" required value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
            </div>
          </div>

          <div>
            <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
            <input id="email" name="email" type="email" required value="<?= htmlspecialchars($user['email']) ?>" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
          </div>

          <div class="flex items-center space-x-3">
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-sky-600 text-white rounded-md text-sm hover:bg-sky-700">Save profile</button>
            <a href="profile.php" class="text-sm text-gray-600 hover:underline">Reset</a>
          </div>
        </form>

        <hr class="my-6" />

        <h3 class="text-lg font-medium text-gray-900 mb-3">Change Password</h3>
        <form method="post" class="space-y-4">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          <input type="hidden" name="action" value="change_password">

          <div>
            <label for="current_password" class="block text-sm font-medium text-gray-700">Current password</label>
            <input id="current_password" name="current_password" type="password" required class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
          </div>

          <div>
            <label for="new_password" class="block text-sm font-medium text-gray-700">New password</label>
            <input id="new_password" name="new_password" type="password" required class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
            <p class="text-xs text-gray-400 mt-1">At least 8 characters recommended.</p>
          </div>

          <div>
            <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm new password</label>
            <input id="confirm_password" name="confirm_password" type="password" required class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
          </div>

          <div>
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-md text-sm hover:bg-amber-700">Change password</button>
          </div>
        </form>
      </section>

      <aside class="bg-white shadow rounded-lg p-6">
        <h2 class="text-lg font-medium text-gray-900 mb-3">Activity</h2>

        <div class="mb-4">
          <h4 class="text-sm text-gray-500">Recent enrollments</h4>
          <?php if (empty($enrollments)): ?>
            <div class="text-sm text-gray-600 mt-2">No recent enrollments.</div>
          <?php else: ?>
            <ul class="mt-2 space-y-2">
              <?php foreach ($enrollments as $e): ?>
                <li class="text-sm">
                  <div class="font-medium text-gray-900"><?= htmlspecialchars($e['course_code']) ?> — <?= htmlspecialchars($e['course_name']) ?></div>
                  <div class="text-xs text-gray-500"><?= htmlspecialchars($e['enrolled_at']) ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div>
          <h4 class="text-sm text-gray-500">Recent payments</h4>
          <?php if (empty($payments)): ?>
            <div class="text-sm text-gray-600 mt-2">No recent payments.</div>
          <?php else: ?>
            <ul class="mt-2 space-y-2">
              <?php foreach ($payments as $p): ?>
                <li class="flex items-center justify-between text-sm">
                  <div>
                    <div class="text-gray-900"><?= number_format((float)$p['amount'], 2) ?> USD
                      <?php if (!empty($p['transaction_id'])): ?>
                        <span class="text-xs text-gray-500">· Txn: <?= htmlspecialchars($p['transaction_id']) ?></span>
                      <?php endif; ?>
                    </div>
                    <div class="text-xs text-gray-500"><?= htmlspecialchars($p['payment_date']) ?></div>
                  </div>
                  <div>
                    <span class="inline-flex items-center px-2.5 py-1 rounded text-xs font-medium <?= ($p['payment_status']=='pending' ? 'bg-yellow-100 text-yellow-800' : ($p['payment_status']=='completed' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800')) ?>">
                      <?= htmlspecialchars(ucfirst($p['payment_status'])) ?>
                    </span>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="mt-6 text-xs text-gray-500">
          Member since <?= htmlspecialchars(date('F j, Y', strtotime($user['created_at'] ?? 'now'))) ?>.
        </div>
      </aside>
    </div>
  </main>

  <footer class="bg-white border-t mt-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 text-center lg:px-8 py-6 text-sm text-gray-500">
      © <?= date('Y') ?> Your Institution.
    </div>
  </footer>
</body>
</html>