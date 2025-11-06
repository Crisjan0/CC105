<?php
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';
require_login();
$user_id = $_SESSION['user_id'];

// Mock payment: lets students "record" payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = floatval($_POST['amount']);
    $stmt = $pdo->prepare("INSERT INTO payments (user_id, amount, payment_status) VALUES (?, ?, 'pending')");
    $stmt->execute([$user_id, $amount]);
    echo "Payment recorded. (Admin will verify and mark as completed)";
}

// Show payment history
$stmt = $pdo->prepare("SELECT * FROM payments WHERE user_id=? ORDER BY payment_date DESC");
$stmt->execute([$user_id]);
$payments = $stmt->fetchAll();
?>
<h2>Pay School Fees</h2>
<form method="post">
    Amount: <input type="number" name="amount" step="0.01" min="0" required>
    <button type="submit">Record Payment</button>
</form>

<h3>Your Payment Records</h3>
<ul>
<?php foreach ($payments as $p): ?>
    <li><?= htmlspecialchars($p['payment_date']) ?> - <?= htmlspecialchars($p['amount']) ?>
        - Status: <?= htmlspecialchars($p['payment_status']) ?></li>
<?php endforeach; ?>
</ul>