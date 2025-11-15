<?php
require_once '../includes/auth.php';
require_login();

// Try to detect a display name from common session keys, fallback to empty string.
$username = "";
if (isset($_SESSION)) {
    if (!empty($_SESSION['user']['name'])) {
        $username = htmlspecialchars($_SESSION['user']['name']);
    } elseif (!empty($_SESSION['username'])) {
        $username = htmlspecialchars($_SESSION['username']);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Student Dashboard</title>

  <!-- Tailwind Play CDN for quick prototyping. Swap to a compiled build for production. -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Optional: customize Tailwind defaults for this page -->
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brand: {
              DEFAULT: '#0f766e', // teal-700
              light: '#10b981'
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
          <div class="h-10 w-10 rounded-md bg-brand p-2 text-white flex items-center justify-center font-bold">SD</div>
          <div>
            <h1 class="text-xl font-semibold">Student Dashboard</h1>
            <p class="text-sm text-gray-500">Your academic hub</p>
          </div>
        </div>

        <div class="flex items-center space-x-4">
          <?php if ($username): ?>
            <div class="text-right">
              <p class="text-sm text-gray-600">Welcome back,</p>
              <p class="font-medium"><?php echo $username; ?></p>
            </div>
          <?php endif; ?>

          <a href="logout.php"
             class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
             aria-label="Logout">Logout</a>
        </div>
      </div>
    </div>
  </header>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <section class="mb-6">
      <div class="rounded-lg bg-white shadow p-6">
        <h2 class="text-2xl font-semibold mb-2">Welcome to your student dashboard!</h2>
        <p class="text-gray-600">Use the actions below to manage enrollment, choose courses and pay fees.</p>
      </div>
    </section>

    <section aria-label="Primary actions">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <a href="enroll.php" class="group block rounded-lg bg-white p-6 shadow hover:shadow-md transition">
          <div class="flex items-start justify-between">
            <div>
              <h3 class="text-lg font-medium text-gray-900">Enroll</h3>
              <p class="mt-2 text-sm text-gray-500">Start a new enrollment or view pending applications.</p>
            </div>
            <div class="ml-4 flex-shrink-0">
              <!-- Simple icon -->
              <svg class="h-10 w-10 text-brand" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422A12.083 12.083 0 0118 20.5H6a12.083 12.083 0 00-.16-9.922L12 14z"></path>
              </svg>
            </div>
          </div>
        </a>

        <a href="payment.php" class="group block rounded-lg bg-white p-6 shadow hover:shadow-md transition">
          <div class="flex items-start justify-between">
            <div>
              <h3 class="text-lg font-medium text-gray-900">Pay Fees</h3>
              <p class="mt-2 text-sm text-gray-500">View outstanding fees and make secure payments.</p>
            </div>
            <div class="ml-4 flex-shrink-0">
              <svg class="h-10 w-10 text-yellow-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-3.866 0-7 1.79-7 4v2a2 2 0 002 2h10a2 2 0 002-2v-2c0-2.21-3.134-4-7-4z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8V6m0 0a4 4 0 110 8m0-8c1.657 0 3 1.567 3 3.5S13.657 13 12 13"></path>
              </svg>
            </div>
          </div>
        </a>

        <a href="profile.php" class="group block rounded-lg bg-white p-6 shadow hover:shadow-md transition">
          <div class="flex items-start justify-between">
            <div>
              <h3 class="text-lg font-medium text-gray-900">Profile</h3>
              <p class="mt-2 text-sm text-gray-500">Update contact info, view enrollment history and documents.</p>
            </div>
            <div class="ml-4 flex-shrink-0">
              <svg class="h-10 w-10 text-purple-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5.121 17.804A13.937 13.937 0 0112 15c2.485 0 4.83.64 6.879 1.804M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.341A8 8 0 006.572 15.34"></path>
              </svg>
            </div>
          </div>
        </a>
      </div>
    </section>

    <!-- New: Enrollment Application Submission Requirements -->
    <section aria-label="Enrollment application requirements" class="mt-8">
      <div class="rounded-lg bg-white p-6 shadow">
        <div class="flex items-start justify-between">
          <div>
            <h3 class="text-lg font-medium mb-2">Enrollment Application — Requirements & Steps</h3>
            <p class="text-sm text-gray-500 mb-4">Before you submit an enrollment application, please ensure you meet the eligibility criteria and prepare the required documents listed below.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="bg-gray-50 p-4 rounded">
                <h4 class="text-sm font-semibold text-gray-800 mb-2">Eligibility</h4>
                <ul class="list-disc list-inside text-sm text-gray-600 space-y-1">
                  <li>Be a registered user of this portal</li>
                  <li>Meet program-specific entry requirements (check course catalog)</li>
                  <li>Fees must be paid or a payment plan arranged</li>
                </ul>
              </div>

              <div class="bg-gray-50 p-4 rounded">
                <h4 class="text-sm font-semibold text-gray-800 mb-2">Required Documents</h4>
                <ul class="list-disc list-inside text-sm text-gray-600 space-y-1">
                  <li>Original SHS Report Card/Form 138</li>
                  <li>Original Certificate of Good Moral Character</li>
                  <li>Original PSA Birth Certificate </li>
                  <li>Proof of payment or payment receipt (PDF/JPG)</li>
                </ul>
              </div>

              <div class="bg-gray-50 p-4 rounded">
                <h4 class="text-sm font-semibold text-gray-800 mb-2">File requirements</h4>
                <p class="text-sm text-gray-600">PDF preferred for documents, each file ≤ 5 MB. Use clear scans/photos; filenames should include your username.</p>
              </div>

              <div class="bg-gray-50 p-4 rounded">
                <h4 class="text-sm font-semibold text-gray-800 mb-2">Deadlines & Notes</h4>
                <ul class="list-disc list-inside text-sm text-gray-600 space-y-1">
                  <li>Regular intake deadline: see course selection for term-specific dates</li>
                  <li>Late submissions may be considered with approval</li>
                  <li>Incomplete applications will not be processed</li>
                </ul>
              </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-3 items-center">
              <a href="enroll.php" class="inline-flex items-center px-4 py-2 bg-brand text-white rounded hover:bg-brand-light text-sm">Start Application</a>
              
            </div>

            <p class="mt-3 text-xs text-gray-400">Tip: Gather your documents first, then start the application — you'll be able to upload files during the process.</p>
          </div>

          <div class="hidden md:block md:ml-6">
            <!-- simple illustrative icon -->
            <svg class="h-24 w-24 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3"></path>
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 12A9 9 0 1112 3v0"></path>
            </svg>
          </div>
        </div>
      </div>
    </section>
 
  </main>

  <footer class="bg-white border-t">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 text-sm text-gray-500">
      © <?php echo date('Y'); ?> Your Institution. All rights reserved.
    </div>
  </footer>

</body>
</html>