<?php
// public/payment.php
// Student-facing "record a payment" page with Tailwind frontend, CSRF, flash messages,
// server-side validation and safe DB usage.
//
// Requires: ../includes/db_connect.php (provides $pdo) and ../includes/auth.php
// Place this file in public/ and ensure your payments table has columns like:
// id, user_id, amount, payment_status, payment_date, transaction_id (optional)

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_login(); // ensures session + user info

// Ensure session available for flash/CSRF
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header('Location: login.php');
    exit;
}

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

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf_token = $_SESSION['csrf_token'];

$info_msg = get_flash();
$error_msg = '';

// Handle POST: record a mock payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $error_msg = 'Invalid request (CSRF check failed). Please reload the page and try again.';
    } else {
        // Validate amount
        $amount_raw = $_POST['amount'] ?? '';
        // remove commas, whitespace
        $amount_raw = str_replace(',', '', trim($amount_raw));
        if ($amount_raw === '' || !is_numeric($amount_raw)) {
            $error_msg = 'Please enter a valid amount.';
        } else {
            $amount = (float)$amount_raw;
            if ($amount <= 0) {
                $error_msg = 'Amount must be greater than zero.';
            } else {
                try {
                    // Insert: mark as pending for admin verification
                    $stmt = $pdo->prepare("INSERT INTO payments (user_id, amount, payment_status, payment_date) VALUES (?, ?, 'pending', NOW())");
                    $stmt->execute([$user_id, $amount]);

                    set_flash('Payment recorded successfully. Admin will verify and mark it as completed.');
                    // rotate token and redirect to avoid resubmission
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
                    header('Location: payment.php');
                    exit;
                } catch (PDOException $e) {
                    error_log('Payment insert error: ' . $e->getMessage());
                    $error_msg = 'Failed to record payment. Please try again later.';
                }
            }
        }
    }
}

// Fetch user's payments (most recent first)
try {
    $stmt = $pdo->prepare("SELECT id, amount, payment_status, payment_date FROM payments WHERE user_id = ? ORDER BY payment_date DESC");
    $stmt->execute([$user_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $payments = [];
    error_log('Fetch payments error: ' . $e->getMessage());
    $error_msg = $error_msg ?: 'Could not load your payment records.';
}

// Helper for badge class
function status_badge_class($status) {
    $s = strtolower((string)$status);
    return match($s) {
        'pending' => 'bg-yellow-100 text-yellow-800',
        'completed' => 'bg-green-100 text-green-800',
        'failed' => 'bg-red-100 text-red-800',
        default => 'bg-gray-100 text-gray-800',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Pay Fees</title>

  <!-- Tailwind CDN for quick prototyping; compile for production -->
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
      <div class="flex items-center justify-between py-6">
        <div>
          <h1 class="text-lg font-semibold text-gray-900">Pay School Fees</h1>
          <p class="text-sm text-gray-500">Record a payment. An administrator will verify and update the status.</p>
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

  <main class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
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

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <section class="bg-white shadow rounded-lg p-6">
        <h2 class="text-lg font-medium text-gray-900 mb-3">Record a Payment</h2>
        <form method="post" class="space-y-4" onsubmit="return validateAndConfirm();">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

          <div>
            <label for="amount" class="block text-sm font-medium text-gray-700">Amount</label>
            <div class="mt-1">
              <input id="amount" name="amount" type="number" step="0.01" min="0.01" required
                     placeholder="e.g. 1500.00"
                     class="block w-full rounded-md border-gray-300 px-3 py-2 focus:ring-sky-500 focus:border-sky-500" />
            </div>
            <p class="mt-1 text-xs text-gray-400">Enter the amount you paid. Admin will verify and mark completed.</p>
          </div>

          <div class="flex items-center space-x-3">
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-sky-600 text-white rounded-md text-sm hover:bg-sky-700">Record Payment</button>
            <button type="button" onclick="document.getElementById('amount').value='';" class="inline-flex items-center px-3 py-2 bg-gray-100 text-sm rounded hover:bg-gray-200">Clear</button>
          </div>
        </form>
      </section>

      <aside class="bg-white shadow rounded-lg p-6">
        <h2 class="text-lg font-medium text-gray-900 mb-3">Your Payment Records <span class="text-sm text-gray-500"> (<?= count($payments) ?>)</span></h2>

        <?php if (empty($payments)): ?>
          <div class="text-sm text-gray-600">You have no payment records yet.</div>
        <?php else: ?>
          <div class="space-y-3">
            <?php foreach ($payments as $p): ?>
              <div class="border border-gray-100 rounded-md p-3 flex items-start justify-between">
                <div>
                  <div class="text-sm text-gray-900 font-medium">
                    <?= number_format((float)$p['amount'], 2) ?> PHP
                    <?php if (!empty($p['id'])): ?>
                      <span class="text-xs text-gray-500 ml-2">Txn: <?= htmlspecialchars($p['id']) ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($p['payment_date']) ?></div>
                </div>

                <div class="text-right">
                  <span class="inline-flex items-center px-2.5 py-1 rounded text-xs font-medium <?= status_badge_class($p['payment_status']) ?>">
                    <?= htmlspecialchars(ucfirst($p['payment_status'])) ?>
                  </span>
                  <div class="mt-2 text-xs text-gray-400">#<?= (int)$p['id'] ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <p class="mt-4 text-xs text-gray-500">Note: This form only records payments in the system for admin verification. For real gateway integration, replace this with your payment provider flow and webhook handling.</p>
      </aside>
    </div>
  </main>

  <footer class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 pb-8 text-sm text-gray-500">
    © <?= date('Y') ?> Your Institution
  </footer>

  <script>
    function validateAndConfirm() {
      const input = document.getElementById('amount');
      const v = input.value.trim();
      if (v === '' || isNaN(v) || Number(v) <= 0) {
        alert('Please enter a valid amount greater than zero.');
        input.focus();
        return false;
      }
      return confirm('Record this payment of ' + Number(v).toFixed(2) + ' USD? This will notify admin for verification.');
    }
  </script>
</body>
</html>