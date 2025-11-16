<?php
// public/payment.php
// Student-facing "record a payment" page with added Exam / Semester payment panel.
// Requires: ../includes/db_connect.php (provides $pdo) and ../includes/auth.php

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_login(); // ensures session + user info

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header('Location: login.php');
    exit;
}

// Flash helpers
function set_flash($msg) { $_SESSION['flash'] = $msg; }
function get_flash() {
    if (!empty($_SESSION['flash'])) { $m = $_SESSION['flash']; unset($_SESSION['flash']); return $m; }
    return '';
}

// CSRF token
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf_token = $_SESSION['csrf_token'];

$info_msg = get_flash();
$error_msg = '';

// Handle POST: generic payment (existing behavior)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $error_msg = 'Invalid request (CSRF check failed). Please reload the page and try again.';
    } else {
        $amount_raw = $_POST['amount'] ?? '';
        $amount_raw = str_replace(',', '', trim($amount_raw));
        if ($amount_raw === '' || !is_numeric($amount_raw)) {
            $error_msg = 'Please enter a valid amount.';
        } else {
            $amount = (float)$amount_raw;
            if ($amount <= 0) {
                $error_msg = 'Amount must be greater than zero.';
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO payments (user_id, amount, payment_status, payment_date) VALUES (?, ?, 'pending', NOW())");
                    $stmt->execute([$user_id, $amount]);
                    set_flash('Payment recorded successfully. Admin will verify and mark it as completed.');
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

// Handle POST: record an exam payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_exam_payment') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $error_msg = 'Invalid request (CSRF check failed). Please reload the page and try again.';
    } else {
        $exam_fee_id = isset($_POST['exam_fee_id']) ? (int)$_POST['exam_fee_id'] : 0;
        $semester_id = isset($_POST['semester_id']) ? (int)$_POST['semester_id'] : 0;
        $exam_number = isset($_POST['exam_number']) ? (int)$_POST['exam_number'] : 0;

        // Determine amount: prefer linked exam_fee_id amount if provided, else fall back to manual amount input
        $amount = null;
        if ($exam_fee_id > 0) {
            $stmt = $pdo->prepare("SELECT amount FROM exam_fees WHERE id = ? LIMIT 1");
            $stmt->execute([$exam_fee_id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) $amount = (float)$r['amount'];
        } elseif ($semester_id > 0 && $exam_number >= 1 && $exam_number <= 4) {
            // Try to find fee for semester + exam_number
            $stmt = $pdo->prepare("SELECT id, amount FROM exam_fees WHERE semester_id = ? AND exam_number = ? LIMIT 1");
            $stmt->execute([$semester_id, $exam_number]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $exam_fee_id = (int)$r['id'];
                $amount = (float)$r['amount'];
            }
        }

        // If amount still null, accept manual amount input
        if ($amount === null) {
            $manual_raw = $_POST['exam_amount'] ?? '';
            $manual_raw = str_replace(',', '', trim($manual_raw));
            if ($manual_raw === '' || !is_numeric($manual_raw) || (float)$manual_raw <= 0) {
                $error_msg = 'Please provide a valid exam payment amount or select a valid fee.';
            } else {
                $amount = (float)$manual_raw;
            }
        }

        if ($error_msg === '') {
            try {
                $stmt = $pdo->prepare("INSERT INTO exam_payments (exam_fee_id, user_id, amount, payment_status, payment_date) VALUES (?, ?, ?, 'pending', NOW())");
                $stmt->execute([$exam_fee_id ?: null, $user_id, $amount]);
                set_flash('Exam payment recorded. Admin will verify and mark it as completed.');
                $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
                header('Location: payment.php#exam_payments');
                exit;
            } catch (PDOException $e) {
                error_log('Exam payment insert error: ' . $e->getMessage());
                $error_msg = 'Failed to record exam payment. Please try again later.';
            }
        }
    }
}

// Fetch user's regular payments
try {
    $stmt = $pdo->prepare("SELECT id, amount, payment_status, payment_date FROM payments WHERE user_id = ? ORDER BY payment_date DESC");
    $stmt->execute([$user_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $payments = [];
    error_log('Fetch payments error: ' . $e->getMessage());
    $error_msg = $error_msg ?: 'Could not load your payment records.';
}

// Fetch exam payments for this user
try {
    $stmt = $pdo->prepare("SELECT ep.*, ef.semester_id, ef.exam_number, s.name AS semester_name
                           FROM exam_payments ep
                           LEFT JOIN exam_fees ef ON ep.exam_fee_id = ef.id
                           LEFT JOIN semesters s ON ef.semester_id = s.id
                           WHERE ep.user_id = ? ORDER BY ep.payment_date DESC");
    $stmt->execute([$user_id]);
    $exam_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $exam_payments = [];
    error_log('Fetch exam payments error: ' . $e->getMessage());
    $error_msg = $error_msg ?: 'Could not load your exam payment records.';
}

// Fetch semesters and fees for the exam payment UI
$semesters = [];
$exam_fees_map = []; // semester_id => [exam_number => fee_row]
try {
    $stmt = $pdo->query("SELECT id, name, start_date, end_date, active FROM semesters ORDER BY start_date DESC");
    $semesters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT id, semester_id, exam_number, amount FROM exam_fees ORDER BY semester_id, exam_number");
    $fees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fees as $f) {
        $sid = (int)$f['semester_id'];
        $n = (int)$f['exam_number'];
        $exam_fees_map[$sid][$n] = $f;
    }
} catch (PDOException $e) {
    // ignore — UI will fallback
    error_log('Fetch semesters/fees error: ' . $e->getMessage());
}

// Helper for badge class (existing)
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
  <script src="https://cdn.tailwindcss.com"></script>
  <style>body::-webkit-scrollbar{display:none;}</style>
</head>
<body class="min-h-screen bg-gray-50 text-gray-800">
  <header class="bg-white shadow">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between py-6">
        <div>
          <h1 class="text-lg font-semibold text-gray-900">Pay School Fees</h1>
          <p class="text-sm text-gray-500">Record general payments or exam payments for a semester.</p>
        </div>
        <div class="flex items-center space-x-3">
          <a href="dashboard.php" class="text-sm text-gray-600 hover:underline font-medium">←Back to Dashboard</a>
          <a href="logout.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">Logout</a>
        </div>
      </div>
    </div>
  </header>

  <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <?php if ($info_msg): ?>
      <div class="mb-4 rounded-md bg-green-50 border border-green-100 p-4"><p class="text-sm text-green-800"><?= htmlspecialchars($info_msg) ?></p></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
      <div class="mb-4 rounded-md bg-red-50 border border-red-100 p-4"><p class="text-sm text-red-800"><?= htmlspecialchars($error_msg) ?></p></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <!-- Existing generic payment -->
      <section class="bg-white shadow rounded-lg p-6">
        <h2 class="text-lg font-medium text-gray-900 mb-3">Record a Payment</h2>
        <form method="post" class="space-y-4" onsubmit="return validateAndConfirm();">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          <input type="hidden" name="action" value="record_payment">

          <div>
            <label for="amount" class="block text-sm font-medium text-gray-700">Amount</label>
            <div class="mt-1">
              <input id="amount" name="amount" type="number" step="0.01" min="0.01" required placeholder="e.g. 1500.00" class="block w-full rounded-md border-gray-300 px-3 py-2" />
            </div>
            <p class="mt-1 text-xs text-gray-400">Enter the amount you paid (general payments).</p>
          </div>

          <div class="flex items-center space-x-3">
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-sky-600 text-white rounded-md text-sm hover:bg-sky-700">Record Payment</button>
            <button type="button" onclick="document.getElementById('amount').value='';" class="inline-flex items-center px-3 py-2 bg-gray-100 text-sm rounded hover:bg-gray-200">Clear</button>
          </div>
        </form>
      </section>

      <!-- Exam payment panel -->
      <section id="exam_payments" class="bg-white shadow rounded-lg p-6">
        <h2 class="text-lg font-medium text-gray-900 mb-3">Exam / Semester Payment (4 exams / semester)</h2>

        <form method="post" class="space-y-4" id="examForm" onsubmit="return confirm('Record exam payment? Admin will verify.');">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          <input type="hidden" name="action" value="record_exam_payment">
          <input type="hidden" name="exam_fee_id" id="exam_fee_id" value="">

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700">Semester</label>
              <select id="semester_select" name="semester_id" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" onchange="onSemesterChange()">
                <option value="">Select semester...</option>
                <?php foreach ($semesters as $s): ?>
                  <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?> <?= $s['active'] ? ' (active)' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Exam #</label>
              <select id="exam_number" name="exam_number" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" onchange="onExamNumberChange()">
                <option value="">Select exam (1–4)</option>
                <option value="1">Exam 1</option>
                <option value="2">Exam 2</option>
                <option value="3">Exam 3</option>
                <option value="4">Exam 4</option>
              </select>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">Fee amount</label>
            <div class="mt-1 flex items-center gap-3">
              <div id="exam_amount_display" class="text-lg font-medium text-gray-900">—</div>
              <input type="hidden" id="exam_amount" name="exam_amount" value="">
            </div>
            <p class="mt-1 text-xs text-gray-400">If no preset fee exists for the selected semester/exam you may enter an amount manually below.</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">Manual amount (optional)</label>
            <input id="manual_exam_amount" name="exam_amount_manual" type="number" step="0.01" min="0.01" placeholder="e.g. 500.00" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2" />
            <p class="mt-1 text-xs text-gray-400">Use only when there is no preset exam fee.</p>
          </div>

          <div class="flex items-center space-x-3">
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-sky-600 text-white rounded-md text-sm hover:bg-sky-700">Record Exam Payment</button>
            <button type="button" onclick="resetExamForm()" class="inline-flex items-center px-3 py-2 bg-gray-100 text-sm rounded hover:bg-gray-200">Reset</button>
          </div>
        </form>
      </section>
    </div>

    <!-- Lists: regular payments and exam payments -->
    <div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-6">
      <aside class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-3">Your Payment Records <span class="text-sm text-gray-500">(<?= count($payments) ?>)</span></h3>
        <?php if (empty($payments)): ?>
          <div class="text-sm text-gray-600">You have no payment records yet.</div>
        <?php else: ?>
          <div class="space-y-3">
            <?php foreach ($payments as $p): ?>
              <div class="border border-gray-100 rounded-md p-3 flex items-start justify-between">
                <div>
                  <div class="text-sm text-gray-900 font-medium"><?= number_format((float)$p['amount'], 2) ?> USD <?php if (!empty($p['id'])): ?><span class="text-xs text-gray-500 ml-2">Txn: <?= htmlspecialchars($p['id']) ?></span><?php endif; ?></div>
                  <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($p['payment_date']) ?></div>
                </div>
                <div class="text-right">
                  <span class="inline-flex items-center px-2.5 py-1 rounded text-xs font-medium <?= status_badge_class($p['payment_status']) ?>"><?= htmlspecialchars(ucfirst($p['payment_status'])) ?></span>
                  <div class="mt-2 text-xs text-gray-400">#<?= (int)$p['id'] ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </aside>

      <aside class="bg-white shadow rounded-lg p-6" id="exam_list_panel">
        <h3 class="text-lg font-medium text-gray-900 mb-3">Your Exam Payments <span class="text-sm text-gray-500">(<?= count($exam_payments) ?>)</span></h3>
        <?php if (empty($exam_payments)): ?>
          <div class="text-sm text-gray-600">You have no exam payment records yet.</div>
        <?php else: ?>
          <div class="space-y-3">
            <?php foreach ($exam_payments as $ep): ?>
              <div class="border border-gray-100 rounded-md p-3 flex items-start justify-between">
                <div>
                  <div class="text-sm text-gray-900 font-medium">
                    <?= number_format((float)$ep['amount'], 2) ?> USD
                    <?php if (!empty($ep['semester_name']) || !empty($ep['exam_number'])): ?>
                      <span class="text-xs text-gray-500 ml-2"><?= htmlspecialchars($ep['semester_name'] ?? 'Semester') ?> · Exam <?= (int)$ep['exam_number'] ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($ep['payment_date']) ?></div>
                </div>
                <div class="text-right">
                  <span class="inline-flex items-center px-2.5 py-1 rounded text-xs font-medium <?= status_badge_class($ep['payment_status']) ?>"><?= htmlspecialchars(ucfirst($ep['payment_status'])) ?></span>
                  <div class="mt-2 text-xs text-gray-400">#<?= (int)$ep['id'] ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </aside>
    </div>

  </main>

  <footer class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pb-8 text-sm text-gray-500">© <?= date('Y') ?> Your Institution</footer>

  <script>
    // Prepare JS data maps for fees (server-embedded)
    const examFees = <?= json_encode($exam_fees_map, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    function onSemesterChange() {
      // clear exam fee id and display
      document.getElementById('exam_fee_id').value = '';
      document.getElementById('exam_amount_display').textContent = '—';
      document.getElementById('exam_amount').value = '';
      document.getElementById('manual_exam_amount').value = '';
      // optionally preselect exam 1
    }
    function onExamNumberChange() {
      const sid = parseInt(document.getElementById('semester_select').value || '0', 10);
      const exn = parseInt(document.getElementById('exam_number').value || '0', 10);
      let display = document.getElementById('exam_amount_display');
      let hidden = document.getElementById('exam_amount');
      let feeIdInput = document.getElementById('exam_fee_id');
      document.getElementById('manual_exam_amount').value = '';
      if (sid && exn && examFees[sid] && examFees[sid][exn]) {
        const fee = examFees[sid][exn];
        display.textContent = Number(fee.amount).toFixed(2) + ' USD';
        hidden.value = fee.amount;
        feeIdInput.value = fee.id;
      } else {
        display.textContent = 'No preset fee';
        hidden.value = '';
        feeIdInput.value = '';
      }
    }
    function resetExamForm() {
      document.getElementById('semester_select').value = '';
      document.getElementById('exam_number').value = '';
      document.getElementById('exam_amount_display').textContent = '—';
      document.getElementById('exam_amount').value = '';
      document.getElementById('manual_exam_amount').value = '';
      document.getElementById('exam_fee_id').value = '';
    }
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