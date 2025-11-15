<?php
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';
require_admin();

// Helper: detect admin display name from session
$adminName = "";
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
if (!empty($_SESSION['user']['name'])) {
    $adminName = htmlspecialchars($_SESSION['user']['name']);
} elseif (!empty($_SESSION['username'])) {
    $adminName = htmlspecialchars($_SESSION['username']);
}

// Helper function to attempt multiple count queries and return first successful integer or null
function try_count(array $queries) {
    // Use globals that db_connect.php may provide
    global $pdo, $conn;

    foreach ($queries as $sql) {
        try {
            if (!empty($pdo) && $pdo instanceof PDO) {
                $stmt = $pdo->query($sql);
                if ($stmt !== false) {
                    $val = $stmt->fetchColumn();
                    if ($val !== false && $val !== null) {
                        return (int)$val;
                    }
                }
            } elseif (!empty($conn)) { // mysqli
                if ($result = $conn->query($sql)) {
                    $row = $result->fetch_row();
                    if ($row !== null && isset($row[0])) {
                        return (int)$row[0];
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore and try next query
        }
    }
    return null;
}

// Prepare plausible queries for each metric.
$activeCoursesQueries = [
    "SELECT COUNT(*) FROM courses WHERE active=1",
    "SELECT COUNT(*) FROM courses WHERE status='active'",
    "SELECT COUNT(*) FROM courses"
];

$enrolledStudentsQueries = [
    "SELECT COUNT(DISTINCT student_id) FROM enrollments WHERE status IN ('enrolled','active')",
    "SELECT COUNT(DISTINCT student_id) FROM enrollments",
    "SELECT COUNT(*) FROM students",
    "SELECT COUNT(*) FROM users WHERE role='student'"
];

$pendingPaymentsQueries = [
  "SELECT COUNT(*) FROM payments WHERE payment_status = 'pending'",
  // fallback if your schema uses a different column name:
  "SELECT COUNT(*) FROM payments WHERE status = 'pending'",
  // last-resort: return total payments (should not be used normally)
  "SELECT COUNT(*) FROM payments"
];

$completedPaymentsQueries = [
  "SELECT COUNT(*) FROM payments WHERE payment_status = 'completed'",
  // fallback if your schema uses a different column name:
  "SELECT COUNT(*) FROM payments WHERE status = 'completed'",
  // last-resort: return total payments (should not be used normally)
  "SELECT COUNT(*) FROM payments"
];

$activeCourses = try_count($activeCoursesQueries);
$enrolledStudents = try_count($enrolledStudentsQueries);
$pendingPayments = try_count($pendingPaymentsQueries);
$completedPayments = try_count($completedPaymentsQueries);

// Recent logs: try several likely table names and return rows
function fetch_logs($limit = 10) {
    global $pdo, $conn;
    $tables = ['system_logs', 'logs', 'audit_logs', 'admin_logs'];
    foreach ($tables as $table) {
        $sql = "SELECT id, created_at, level, message FROM `$table` ORDER BY created_at DESC LIMIT " . (int)$limit;
        try {
            if (!empty($pdo) && $pdo instanceof PDO) {
                $stmt = $pdo->query($sql);
                if ($stmt !== false) {
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($rows)) return [$table, $rows];
                }
            } elseif (!empty($conn)) {
                if ($result = $conn->query($sql)) {
                    $rows = [];
                    while ($r = $result->fetch_assoc()) $rows[] = $r;
                    if (!empty($rows)) return [$table, $rows];
                }
            }
        } catch (Throwable $e) {
            // try next table
        }
    }
    return [null, []];
}

list($logsTable, $recentLogs) = fetch_logs(10);

// Helper to render a safe integer or placeholder
function render_count($v) {
    return ($v === null) ? '—' : number_format((int)$v);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Admin Dashboard</title>

  <!-- Tailwind CDN for quick prototyping. Replace with a compiled build for production -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brand: {
              DEFAULT: '#1f2937', /* gray-800 */
              accent: '#0ea5a4'   /* teal-500 */
            }
          }
        }
      }
    }
  </script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-800">
  <header class="bg-white shadow">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex justify-between items-center py-6">
        <div class="flex items-center space-x-3">
          <div class="h-10 w-10 rounded-md bg-brand p-2 text-white flex items-center justify-center font-bold">AD</div>
          <div>
            <h1 class="text-xl font-semibold">Admin Dashboard</h1>
            <p class="text-sm text-gray-500">Manage courses, students and payments</p>
          </div>
        </div>

        <div class="flex items-center space-x-4">
          <?php if ($adminName): ?>
            <div class="text-right">
              <p class="text-sm text-gray-600">Signed in as</p>
              <p class="font-medium"><?php echo $adminName; ?></p>
            </div>
          <?php endif; ?>

          <a href="../public/logout.php"
             class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
             aria-label="Logout">Logout</a>
        </div>
      </div>
    </div>
  </header>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <section class="mb-6">
      <div class="rounded-lg bg-white shadow p-6">
        <h2 class="text-2xl font-semibold mb-2">Welcome, Administrator</h2>
        <p class="text-gray-600">Use the controls below to manage the system.</p>
      </div>
    </section>

    <section aria-label="Admin actions">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <a href="manage_courses.php" class="group block rounded-lg bg-white p-6 shadow hover:shadow-md transition">
          <div class="flex items-start justify-between">
            <div>
              <h3 class="text-lg font-medium text-gray-900">Manage Courses</h3>
              <p class="mt-2 text-sm text-gray-500">Create, edit or remove course offerings.</p>
            </div>
            <div class="ml-4 flex-shrink-0">
              <svg class="h-10 w-10 text-indigo-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"></path>
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.4 18.4A9 9 0 1112 3v3"></path>
              </svg>
            </div>
          </div>
        </a>

        <a href="manage_students.php" class="group block rounded-lg bg-white p-6 shadow hover:shadow-md transition">
          <div class="flex items-start justify-between">
            <div>
              <h3 class="text-lg font-medium text-gray-900">Manage Students</h3>
              <p class="mt-2 text-sm text-gray-500">View and update student records and enrollments.</p>
            </div>
            <div class="ml-4 flex-shrink-0">
              <svg class="h-10 w-10 text-green-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-4-4h-1"></path>
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 20H4v-2a4 4 0 014-4h1"></path>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 7a4 4 0 100-8 4 4 0 000 8z"></path>
              </svg>
            </div>
          </div>
        </a>

        <a href="manage_payments.php" class="group block rounded-lg bg-white p-6 shadow hover:shadow-md transition">
          <div class="flex items-start justify-between">
            <div>
              <h3 class="text-lg font-medium text-gray-900">Manage Payments</h3>
              <p class="mt-2 text-sm text-gray-500">Approve refunds, view transactions and resolve issues.</p>
            </div>
            <div class="ml-4 flex-shrink-0">
              <svg class="h-10 w-10 text-yellow-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-3.866 0-7 1.79-7 4v2a2 2 0 002 2h10a2 2 0 002-2v-2c0-2.21-3.134-4-7-4z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8V6m0 0a4 4 0 110 8"></path>
              </svg>
            </div>
          </div>
        </a>

        <a href="manage_applications.php" class="group block rounded-lg bg-white p-6 shadow hover:shadow-md transition">
          <div class="flex items-start justify-between">
            <div>
              <h3 class="text-lg font-medium text-gray-900">Manage Applications</h3>
              <p class="mt-2 text-sm text-gray-500">Review, accept, or decline user applications.</p>
            </div>
            <div class="ml-4 flex-shrink-0">
              <svg class="h-10 w-10 text-blue-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"></path>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 11a4 4 0 100-8 4 4 0 000 8z"></path>
              </svg>
            </div>
          </div>
        </a>
      </div>
    </section>

    <section class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="rounded-lg bg-white p-6 shadow col-span-2">
        <h3 class="text-lg font-medium mb-4">Overview</h3>
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
          <div class="p-4 bg-gray-50 rounded">
            <p class="text-sm text-gray-500">Active Courses</p>
            <p class="mt-2 text-2xl font-semibold text-gray-900"><?php echo render_count($activeCourses); ?></p>
          </div>
          <div class="p-4 bg-gray-50 rounded">
            <p class="text-sm text-gray-500">Enrolled Students</p>
            <p class="mt-2 text-2xl font-semibold text-gray-900"><?php echo render_count($enrolledStudents); ?></p>
          </div>
          <div class="p-4 bg-gray-50 rounded">
            <p class="text-sm text-gray-500">Pending Payments</p>
            <p class="mt-2 text-2xl font-semibold text-gray-900"><?php echo render_count($pendingPayments); ?></p>
          </div>
          <div class="p-4 bg-gray-50 rounded">
            <p class="text-sm text-gray-500">Completed Payments</p>
            <p class="mt-2 text-2xl font-semibold text-gray-900"><?php echo render_count($completedPayments); ?></p>
          </div>
        </div>


        <?php if (!empty($recentLogs)): ?>
          <div class="mt-6">
            <h4 class="text-md font-medium mb-2">Recent logs (<?php echo htmlspecialchars($logsTable); ?>)</h4>
            <div class="max-h-48 overflow-auto bg-gray-50 rounded p-3 text-sm">
              <ul class="space-y-2">
                <?php foreach ($recentLogs as $row): ?>
                  <li class="border-l-4 border-gray-200 pl-3">
                    <div class="flex justify-between items-start">
                      <div class="pr-4">
                        <div class="text-xs text-gray-500"><?php echo isset($row['created_at']) ? htmlspecialchars($row['created_at']) : ''; ?> <?php echo isset($row['level']) ? ' · ' . htmlspecialchars($row['level']) : ''; ?></div>
                        <div class="text-sm text-gray-800"><?php echo isset($row['message']) ? htmlspecialchars(mb_strimwidth($row['message'], 0, 200, '...')) : '[no message]'; ?></div>
                      </div>
                      <div class="text-xs text-gray-400"><?php echo isset($row['id']) ? '#'.htmlspecialchars($row['id']) : ''; ?></div>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
        <?php endif; ?>

      </div>

      <div class="rounded-lg bg-white p-6 shadow">
        <h3 class="text-lg font-medium mb-4">Quick Actions</h3>
        <div class="flex flex-col gap-3">
          <a href="manage_courses.php" class="w-full inline-flex items-center justify-between px-4 py-2 border rounded-md bg-brand text-white hover:opacity-95">Add / Edit Courses <span class="text-sm opacity-80">→</span></a>
          <a href="manage_students.php" class="w-full inline-flex items-center justify-between px-4 py-2 border rounded-md bg-gray-100 text-gray-800 hover:bg-gray-200">Search Students <span class="text-sm opacity-80">→</span></a>
          <a href="manage_payments.php" class="w-full inline-flex items-center justify-between px-4 py-2 border rounded-md bg-gray-100 text-gray-800 hover:bg-gray-200">Payment Queue <span class="text-sm opacity-80">→</span></a>
        </div>
      </div>
    </section>
  </main>

  <footer class="bg-white border-t">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 text-sm text-gray-500">
      © <?php echo date('Y'); ?> Your Institution — Admin Panel
    </div>
  </footer>

</body>
</html>