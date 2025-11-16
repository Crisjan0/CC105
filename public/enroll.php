<?php
// public/enroll.php
// Enrollment application form with embedded "Pay Fees" form and server-side handling.
// If student provides an amount it will create a pending payment (optionally linked to an application).
// The enroll flow remains the same; a separate POST action 'record_payment' is added to handle payments.
//
// Requires: ../includes/db_connect.php (provides $pdo) and ../includes/auth.php
// Place this file in public/ directory.

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

// Ensure session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header('Location: login.php');
    exit;
}

// Try to fetch current user's basic info to prefill email/name if available
$current_user = ['full_name' => '', 'first_name' => '', 'middle_name' => '', 'last_name' => '', 'email' => ''];
try {
    $stmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        $current_user['full_name'] = $u['full_name'] ?? '';
        $current_user['email'] = $u['email'] ?? '';
        // split full_name into first/middle/last safely
        $parts = preg_split('/\s+/', trim($current_user['full_name']));
        $current_user['first_name'] = $parts[0] ?? '';
        if (count($parts) === 1) {
            $current_user['middle_name'] = '';
            $current_user['last_name'] = '';
        } elseif (count($parts) === 2) {
            $current_user['middle_name'] = '';
            $current_user['last_name'] = $parts[1];
        } else {
            // first = first part, last = last part, middle = the rest
            $current_user['last_name'] = array_pop($parts);
            array_shift($parts); // remove first
            $current_user['middle_name'] = implode(' ', $parts);
        }
    }
} catch (Exception $e) {
    // ignore; prefill will be empty
}

// Config
$UPLOAD_BASE = __DIR__ . '/../uploads/enrollment_applications'; // ensure writable
$PAY_UPLOAD_BASE = __DIR__ . '/../uploads/payments'; // payment proofs
$MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB per file for documents
$MAX_PROOF_SIZE = 8 * 1024 * 1024; // 8 MB for payment proof
$ALLOWED_MIME = [
    'application/pdf',
    'image/jpeg',
    'image/png',
];

// Payment config: fee per credit (used only when student doesn't provide an amount)
if (!defined('FEE_PER_CREDIT')) {
    define('FEE_PER_CREDIT', 500.00); // change to your institution's per-credit rate
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

// Helper sanitize small text
function clean_text($s) {
    return trim(mb_substr((string)$s, 0, 2000));
}

// Simple date validation (YYYY-MM-DD)
function valid_date($d) {
    $t = strtotime($d);
    if ($t === false) return false;
    return date('Y-m-d', $t) === $d || date('Y-m-d', $t) === $d;
}

// Helper to compute age from birth date (YYYY-MM-DD)
function compute_age($birth_date) {
    try {
        $b = new DateTime($birth_date);
        $now = new DateTime('now');
        $diff = $now->diff($b);
        return (int)$diff->y;
    } catch (Throwable $e) {
        return null;
    }
}

// ---------- Payment recording handler (embedded) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    // CSRF
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $error_msg = 'Invalid CSRF token for payment.';
    } else {
        // Validate amount
        $amount_raw = str_replace(',', '', trim((string)($_POST['amount'] ?? '')));
        if ($amount_raw === '' || !is_numeric($amount_raw) || (float)$amount_raw <= 0) {
            $error_msg = 'Please enter a valid payment amount greater than zero.';
        } else {
            $amount = round((float)$amount_raw, 2);
        }

        // application link (optional)
        $application_id = null;
        if (!empty($_POST['application_id'])) {
            $application_id = (int)$_POST['application_id'];
        }

        // Handle proof file (optional)
        $proof_path = null;
        if ($error_msg === '' && !empty($_FILES['proof']) && is_array($_FILES['proof'])) {
            $file = $_FILES['proof'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                if ($file['size'] > $MAX_PROOF_SIZE) {
                    $error_msg = 'Proof file exceeds maximum size of ' . ($MAX_PROOF_SIZE / 1024 / 1024) . ' MB.';
                } else {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);
                    if (!in_array($mime, $ALLOWED_MIME, true)) {
                        $error_msg = 'Unsupported proof file type. Accepts PDF, JPG, PNG.';
                    } else {
                        $userDir = $PAY_UPLOAD_BASE . '/user_' . (int)$user_id;
                        if (!is_dir($userDir)) @mkdir($userDir, 0755, true);
                        $orig = basename($file['name'] ?? 'proof');
                        $ext = pathinfo($orig, PATHINFO_EXTENSION) ?: 'bin';
                        $stored = sprintf('%s_%s_%s.%s', $user_id, time(), bin2hex(random_bytes(6)), $ext);
                        $dest = $userDir . '/' . $stored;
                        if (!move_uploaded_file($file['tmp_name'], $dest)) {
                            $error_msg = 'Failed to save uploaded proof file.';
                        } else {
                            @chmod($dest, 0644);
                            $proof_path = 'uploads/payments/user_' . (int)$user_id . '/' . $stored;
                        }
                    }
                }
            } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
                $error_msg = 'Error uploading proof file.';
            }
        }

        // Insert payment when no errors
        if ($error_msg === '') {
            try {
                // Try insert with application_id if provided; fallback if schema differs
                if ($application_id) {
                    try {
                        $pstmt = $pdo->prepare("INSERT INTO payments (application_id, user_id, amount, payment_date, payment_status) VALUES (?, ?, ?, NOW(), 'pending')");
                        $pstmt->execute([$application_id, $user_id, $amount]);
                    } catch (PDOException $ex) {
                        // fallback without application_id
                        error_log('payments insert with application_id failed: ' . $ex->getMessage());
                        $pstmt = $pdo->prepare("INSERT INTO payments (user_id, amount, payment_date, payment_status) VALUES (?, ?, NOW(), 'pending')");
                        $pstmt->execute([$user_id, $amount]);
                    }
                } else {
                    $pstmt = $pdo->prepare("INSERT INTO payments (user_id, amount, payment_date, payment_status) VALUES (?, ?, NOW(), 'pending')");
                    $pstmt->execute([$user_id, $amount]);
                }
                $payment_id = (int)$pdo->lastInsertId();

                // Save proof path if possible (try couple of column names)
                if ($proof_path !== null) {
                    try {
                        $pdo->prepare("UPDATE payments SET proof = ? WHERE id = ?")->execute([$proof_path, $payment_id]);
                    } catch (PDOException $ex) {
                        try {
                            $pdo->prepare("UPDATE payments SET proof_path = ? WHERE id = ?")->execute([$proof_path, $payment_id]);
                        } catch (PDOException $ex2) {
                            error_log('Could not save proof path to payments table: ' . $ex2->getMessage());
                        }
                    }
                }

                set_flash('Payment recorded. Admin will verify and update status.');
                $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
                header('Location: enroll.php#payments');
                exit;
            } catch (PDOException $e) {
                error_log('Payment insert error: ' . $e->getMessage());
                $error_msg = 'Failed to record payment. Please try again later.';
            }
        }
    }
}
// ---------- End payment handler ----------

// Handle application submission (existing)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_application') {
    // Basic CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $error_msg = 'Invalid form submission (CSRF check failed).';
    } else {
        // --- Collect selected course: single radio "course_id" ---
        $course_id = isset($_POST['course_id']) ? (int)$_POST['course_id'] : null;
        if (!$course_id) {
            $error_msg = 'Please select one course to apply for.';
        } else {
            // keep consistent shape: store as array of ints
            $course_ids = [$course_id];

            // optional cover letter / notes
            $notes = clean_text($_POST['notes'] ?? '');

            // Student information (now includes middle_name and birthplace)
            $student_first = clean_text($_POST['student_first_name'] ?? '');
            $student_middle = clean_text($_POST['student_middle_name'] ?? '');
            $student_last = clean_text($_POST['student_last_name'] ?? '');
            $student_birth = trim($_POST['student_birth_date'] ?? '');
            $student_birthplace = clean_text($_POST['student_birthplace'] ?? '');
            $student_gender = in_array($_POST['student_gender'] ?? '', ['male','female','other'], true) ? $_POST['student_gender'] : '';

            // Structured address fields (added inputs in the form)
            $addr_house = clean_text($_POST['student_addr_house'] ?? '');
            $addr_street = clean_text($_POST['student_addr_street'] ?? '');
            $addr_barangay = clean_text($_POST['student_addr_barangay'] ?? '');
            $addr_city = clean_text($_POST['student_addr_city'] ?? '');
            $addr_province = clean_text($_POST['student_addr_province'] ?? '');
            $addr_country = clean_text($_POST['student_addr_country'] ?? '');
            $addr_zip = clean_text($_POST['student_addr_zip'] ?? '');

            $student_contact = clean_text($_POST['student_contact'] ?? '');
            $student_email = trim($_POST['student_email'] ?? '');

            // New fields: religion and age (age can be provided or computed from birth date)
            $student_religion = clean_text($_POST['student_religion'] ?? '');
            $student_age_input = trim($_POST['student_age'] ?? '');
            $student_age = null;
            if ($student_age_input !== '') {
                // accept numeric age
                $student_age = is_numeric($student_age_input) ? (int)$student_age_input : null;
            } elseif ($student_birth !== '' && valid_date($student_birth)) {
                $student_age = compute_age($student_birth);
            }

            // Indigenous peoples question
            $indigenous_belongs = null;
            if (isset($_POST['indigenous_belongs'])) {
                $val = $_POST['indigenous_belongs'];
                if ($val === 'yes') $indigenous_belongs = true;
                elseif ($val === 'no') $indigenous_belongs = false;
            }
            $indigenous_spec = clean_text($_POST['indigenous_spec'] ?? '');

            // Accept an optional manual amount from the student (matches the UI in your screenshot)
            $manual_amount_raw = trim((string)($_POST['amount'] ?? ''));
            $manual_amount = null;
            if ($manual_amount_raw !== '') {
                // Normalize commas and whitespace
                $manual_amount_norm = str_replace(',', '', $manual_amount_raw);
                if (is_numeric($manual_amount_norm)) {
                    $manual_amount = round((float)$manual_amount_norm, 2);
                    if ($manual_amount <= 0) {
                        $error_msg = 'If you enter an amount it must be greater than zero.';
                    }
                } else {
                    $error_msg = 'Please enter a valid numeric amount.';
                }
            }

            // Basic validation for student info
            if ($error_msg === '' && ($student_first === '' || $student_last === '')) {
                $error_msg = 'Please provide student first and last name.';
            } elseif ($error_msg === '' && ($student_email === '' || !filter_var($student_email, FILTER_VALIDATE_EMAIL))) {
                $error_msg = 'Please provide a valid student email address.';
            } elseif ($error_msg === '' && ($student_birth !== '' && !valid_date($student_birth))) {
                $error_msg = 'Birth date must be in YYYY-MM-DD format.';
            } elseif ($error_msg === '' && ($student_age !== null && ($student_age < 0 || $student_age > 200))) {
                $error_msg = 'Please enter a valid age.';
            }

            // Parent / guardian fields (extended to include father/mother/legal guardian/contact)
            $parent_name = clean_text($_POST['parent_name'] ?? '');
            $parent_relation = clean_text($_POST['parent_relation'] ?? '');
            $parent_contact = clean_text($_POST['parent_contact'] ?? '');
            $parent_consent = isset($_POST['parent_consent']) && $_POST['parent_consent'] === '1' ? true : false;
            $parent_lives_with = isset($_POST['parent_lives_with']) && $_POST['parent_lives_with'] === '1' ? true : false;

            // New explicit fields from the image
            $father_name = clean_text($_POST['father_name'] ?? '');
            $mother_maiden_name = clean_text($_POST['mother_maiden_name'] ?? '');
            $legal_guardian_name = clean_text($_POST['legal_guardian_name'] ?? '');
            $guardian_contact = clean_text($_POST['guardian_contact'] ?? '');

            $parent_info = [
                'name' => $parent_name,
                'relation' => $parent_relation,
                'contact' => $parent_contact,
                'consent' => $parent_consent,
                'lives_with' => $parent_lives_with,
                // added structured fields
                'father_name' => $father_name ?: null,
                'mother_maiden_name' => $mother_maiden_name ?: null,
                'legal_guardian_name' => $legal_guardian_name ?: null,
                'guardian_contact' => $guardian_contact ?: null,
            ];

            // Student info array (address as structured object) includes middle_name and birthplace
            $student_info = [
                'first_name' => $student_first,
                'middle_name' => $student_middle ?: null,
                'last_name' => $student_last,
                'birth_date' => $student_birth ?: null,
                'birthplace' => $student_birthplace ?: null,
                'gender' => $student_gender ?: null,
                'religion' => $student_religion ?: null,
                'age' => $student_age !== null ? (int)$student_age : null,
                'address' => [
                    'house_no' => $addr_house,
                    'street' => $addr_street,
                    'barangay' => $addr_barangay,
                    'city' => $addr_city,
                    'province' => $addr_province,
                    'country' => $addr_country,
                    'zip' => $addr_zip,
                ],
                'contact' => $student_contact,
                'email' => $student_email,
                'indigenous' => [
                    'belongs' => $indigenous_belongs,
                    'specify' => $indigenous_spec,
                ],
            ];

            // Files handling
            $uploadedFiles = []; // will store info arrays: original_name, stored_name, mime, size, type
            // Ensure user upload dir exists
            $userDir = $UPLOAD_BASE . '/user_' . (int)$user_id;
            if (!is_dir($userDir)) {
                @mkdir($userDir, 0755, true);
            }

            // Helper to process a single-file input; throws RuntimeException on error
            $process_single_file = function($fieldName, $label) use (&$uploadedFiles, $userDir, $MAX_FILE_SIZE, $ALLOWED_MIME, $user_id) {
                if (empty($_FILES[$fieldName])) return;
                $file = $_FILES[$fieldName];
                if (!is_array($file) || !isset($file['error'])) return;

                if ($file['error'] === UPLOAD_ERR_NO_FILE) return;
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException("Upload error for {$label}.");
                }

                $tmp = $file['tmp_name'];
                $orig = basename($file['name'] ?? '');
                $size = (int)($file['size'] ?? 0);

                if ($size > $MAX_FILE_SIZE) {
                    throw new RuntimeException("File {$orig} for {$label} exceeds the 5 MB limit.");
                }

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $tmp);
                finfo_close($finfo);
                if (!in_array($mime, $ALLOWED_MIME, true)) {
                    throw new RuntimeException("File {$orig} for {$label} has unsupported file type.");
                }

                $ext = pathinfo($orig, PATHINFO_EXTENSION);
                $timestamp = time();
                $stored = sprintf('%s_%s_%s.%s', $user_id, $timestamp, bin2hex(random_bytes(6)), $ext);
                $dest = $userDir . '/' . $stored;

                if (!move_uploaded_file($tmp, $dest)) {
                    throw new RuntimeException("Failed to move uploaded file {$orig} for {$label}.");
                }

                @chmod($dest, 0644);

                // stored_name is web-accessible relative path (adjust if your uploads are served differently)
                $uploadedFiles[] = [
                    'original_name' => $orig,
                    'stored_name' => 'uploads/enrollment_applications/user_' . (int)$user_id . '/' . $stored,
                    'mime' => $mime,
                    'size' => $size,
                    'type' => $label,
                ];
            };

            // Process required/important individual file inputs
            try {
                // These field names match the new inputs in the form below
                $process_single_file('psa', 'PSA');
                $process_single_file('report_card', 'Report Card');
                $process_single_file('form_138', 'Form 138');
                $process_single_file('good_moral', 'Good Moral');

                // Process additional multiple files in documents[] (optional)
                if (!empty($_FILES['documents']) && is_array($_FILES['documents']['name'])) {
                    for ($i = 0; $i < count($_FILES['documents']['name']); $i++) {
                        $err = $_FILES['documents']['error'][$i];
                        if ($err === UPLOAD_ERR_NO_FILE) continue;
                        if ($err !== UPLOAD_ERR_OK) {
                            $error_msg = 'One or more additional documents failed to upload.';
                            break;
                        }

                        $tmp = $_FILES['documents']['tmp_name'][$i];
                        $orig = basename($_FILES['documents']['name'][$i]);
                        $size = (int)$_FILES['documents']['size'][$i];

                        if ($size > $MAX_FILE_SIZE) {
                            $error_msg = "File {$orig} exceeds the 5 MB limit.";
                            break;
                        }

                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = finfo_file($finfo, $tmp);
                        finfo_close($finfo);
                        if (!in_array($mime, $ALLOWED_MIME, true)) {
                            $error_msg = "File {$orig} has unsupported file type.";
                            break;
                        }

                        $ext = pathinfo($orig, PATHINFO_EXTENSION);
                        $timestamp = time();
                        $stored = sprintf('%s_%s_%s.%s', $user_id, $timestamp, bin2hex(random_bytes(6)), $ext);
                        $dest = $userDir . '/' . $stored;

                        if (!move_uploaded_file($tmp, $dest)) {
                            $error_msg = "Failed to move uploaded file {$orig}.";
                            break;
                        }

                        $uploadedFiles[] = [
                            'original_name' => $orig,
                            'stored_name' => 'uploads/enrollment_applications/user_' . (int)$user_id . '/' . $stored,
                            'mime' => $mime,
                            'size' => $size,
                            'type' => 'Additional',
                        ];

                        @chmod($dest, 0644);
                    }
                }
            } catch (RuntimeException $fe) {
                if ($error_msg === '') $error_msg = $fe->getMessage();
            }

            // If no error so far, persist application and optionally create payment
            if ($error_msg === '') {
                try {
                    $pdo->beginTransaction();
                    $sql = "INSERT INTO enrollment_applications
                        (user_id, course_ids, notes, files, parent_info, student_info, status, submitted_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'submitted', NOW())";
                    $stmt = $pdo->prepare($sql);
                    $course_json = json_encode(array_values($course_ids));
                    $files_json = json_encode($uploadedFiles);
                    $parent_json = json_encode($parent_info);
                    $student_json = json_encode($student_info);
                    $stmt->execute([$user_id, $course_json, $notes, $files_json, $parent_json, $student_json]);

                    // get application id
                    $appId = (int)$pdo->lastInsertId();

                    // Determine payment amount:
                    // - if student supplied a manual amount use that
                    // - otherwise compute based on credits * FEE_PER_CREDIT
                    $fee_amount = null;
                    if ($manual_amount !== null) {
                        $fee_amount = $manual_amount;
                    } else {
                        // compute from course credits
                        $fee_amount = 0.00;
                        if (!empty($course_ids) && is_array($course_ids)) {
                            $cid = (int)$course_ids[0];
                            $stmtC = $pdo->prepare("SELECT credits FROM courses WHERE id = ? LIMIT 1");
                            $stmtC->execute([$cid]);
                            $rowC = $stmtC->fetch(PDO::FETCH_ASSOC);
                            $credits = $rowC ? (int)$rowC['credits'] : 0;
                            $fee_amount = round($credits * FEE_PER_CREDIT, 2);
                        }
                    }

                    // create payment record if fee > 0
                    if (is_numeric($fee_amount) && $fee_amount > 0) {
                        try {
                            // Try to insert with application_id (preferred)
                            $pstmt = $pdo->prepare("INSERT INTO payments (application_id, user_id, amount, payment_date, payment_status) VALUES (?, ?, ?, NOW(), 'pending')");
                            $pstmt->execute([$appId, $user_id, $fee_amount]);
                        } catch (PDOException $payEx) {
                            // If payments.application_id doesn't exist or other schema mismatch, try fallback without application_id
                            error_log('payments insert with application_id failed: ' . $payEx->getMessage());
                            try {
                                $pstmt2 = $pdo->prepare("INSERT INTO payments (user_id, amount, payment_date, payment_status) VALUES (?, ?, NOW(), 'pending')");
                                $pstmt2->execute([$user_id, $fee_amount]);
                            } catch (PDOException $payEx2) {
                                // log and continue (do NOT break the user submission for a payments schema problem)
                                error_log('payments insert fallback failed: ' . $payEx2->getMessage());
                                $info_msg = 'Application saved but payment creation failed; contact support.';
                                set_flash($info_msg);
                            }
                        }
                    }

                    $pdo->commit();
                    set_flash('Application submitted successfully. Your application status is "submitted". A payment was created if fees are due.');

                    // rotate CSRF & redirect to payment section so student can pay/view
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
                    header('Location: enroll.php#applications');
                    exit;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    // remove files we moved
                    foreach ($uploadedFiles as $f) {
                        $p = __DIR__ . '/../' . ltrim($f['stored_name'], '/');
                        if (file_exists($p)) @unlink($p);
                    }
                    error_log('Enrollment application or payment insert error: ' . $e->getMessage());
                    $error_msg = 'Failed to submit application. Please try again later.';
                }
            }
        }
    }
}

// Fetch available courses
try {
    $stmt = $pdo->query("SELECT id, course_code, course_name, credits FROM courses ORDER BY course_name ASC");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $courses = [];
    error_log('Fetch courses error: ' . $e->getMessage());
    $error_msg = $error_msg ?: 'Could not load courses.';
}

// Fetch user's previous applications (include parent_info and student_info)
try {
    $stmt = $pdo->prepare("SELECT id, course_ids, notes, files, parent_info, student_info, status, submitted_at, processed_at, processed_by FROM enrollment_applications WHERE user_id = ? ORDER BY submitted_at DESC");
    $stmt->execute([$user_id]);
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $applications = [];
    error_log('Fetch applications error: ' . $e->getMessage());
}

// Fetch user's payments for the payments form/listing
$payments = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE user_id = ? ORDER BY payment_date DESC, id DESC");
    $stmt->execute([$user_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $payments = [];
    error_log('Fetch payments error: ' . $e->getMessage());
}

// Helper
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Enrollment Application</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      function confirmApplication() {
        // Check that a single course radio is selected AND required name/email are filled.
        const selectedCourse = document.querySelector('input[name="course_id"]:checked');
        if (!selectedCourse) {
          alert('Please select one course to apply for.');
          return false;
        }
        // basic check for required student name/email fields
        const first = document.querySelector('input[name="student_first_name"]').value.trim();
        const last = document.querySelector('input[name="student_last_name"]').value.trim();
        const email = document.querySelector('input[name="student_email"]').value.trim();
        if (!first || !last) {
          alert('Please provide student first and last name.');
          return false;
        }
        if (!email) {
          alert('Please provide student email address.');
          return false;
        }
        // optional amount validation client-side
        const amt = document.querySelector('input[name="amount"]').value.trim();
        if (amt !== '') {
          const normalized = amt.replace(/,/g, '');
          if (isNaN(normalized) || Number(normalized) <= 0) {
            alert('Please enter a valid amount greater than zero or leave it blank.');
            return false;
          }
        }
        return confirm('Submit enrollment application? You will be able to view status on this page.');
      }

      function validatePaymentForm() {
        const amt = document.querySelector('input[name="pay_amount"]').value.trim();
        if (amt === '' || isNaN(amt.replace(/,/g,'')) || Number(amt.replace(/,/g,'')) <= 0) {
          alert('Please enter a valid amount greater than zero.');
          return false;
        }
        return confirm('Record this payment? Admin will verify.');
      }

      // Compute age client-side when birth date changes
      function updateAgeFromDob() {
        const dob = document.querySelector('input[name="student_birth_date"]').value;
        const ageField = document.querySelector('input[name="student_age"]');
        if (!dob) { return; }
        const birth = new Date(dob);
        const now = new Date();
        let age = now.getFullYear() - birth.getFullYear();
        const m = now.getMonth() - birth.getMonth();
        if (m < 0 || (m === 0 && now.getDate() < birth.getDate())) age--;
        if (!isNaN(age) && age >= 0) ageField.value = age;
      }

      // Toggle indigenous specify box
      function toggleIndigenousSpecify() {
        const yes = document.querySelector('input[name="indigenous_belongs"][value="yes"]').checked;
        document.getElementById('indigenous_spec_div').style.display = yes ? 'block' : 'none';
      }
    </script>
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
          <h1 class="text-lg font-semibold text-gray-900">Enrollment Application</h1>
          <p class="text-sm text-gray-500">Submit an application, upload documents and record payments. Provide student and parent/guardian details below.</p>
        </div>
        <div class="space-x-3">
          <a href="dashboard.php" class="text-sm text-gray-600 hover:underline font-medium">←Back to Dashboard</a>
          <a href="logout.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">Logout</a>
        </div>
      </div>
    </div>
  </header>

  <main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <?php if ($info_msg): ?>
      <div class="mb-4 rounded-md bg-green-50 border border-green-100 p-4">
        <p class="text-sm text-green-800"><?= h($info_msg) ?></p>
      </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
      <div class="mb-4 rounded-md bg-red-50 border border-red-100 p-4">
        <p class="text-sm text-red-800"><?= h($error_msg) ?></p>
      </div>
    <?php endif; ?>

    <section class="bg-white shadow rounded-lg p-6 mb-6">
      <h2 class="text-lg font-medium text-gray-900 mb-3">Application Details & Student Information</h2>

      <!-- Single form that contains course selection + student info -->
      <form method="post" enctype="multipart/form-data" onsubmit="return confirmApplication();">
        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
        <input type="hidden" name="action" value="submit_application">

        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-2">Select a course to apply for</label>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-64 overflow-auto border rounded p-2">
            <?php foreach ($courses as $c): ?>
              <label class="inline-flex items-center space-x-2 p-2 hover:bg-gray-50 rounded cursor-pointer">
                <input type="radio" name="course_id" value="<?= (int)$c['id'] ?>" class="h-4 w-4 text-sky-600 border-gray-300 rounded"
                  <?= ((int)($_POST['course_id'] ?? 0) === (int)$c['id']) ? 'checked' : '' ?> aria-label="<?= h($c['course_code'] . ' - ' . $c['course_name']) ?>">
                <span class="text-sm">
                  <strong><?= h($c['course_code']) ?></strong> — <?= h($c['course_name']) ?> <span class="text-xs text-gray-500">(<?= (int)$c['credits'] ?> cr)</span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="text-xs text-gray-500 mt-1">You may apply for only one course per application. To apply for additional courses submit separate applications.</p>
        </div>

        <!-- Student name / basic info grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">First Name</label>
            <input type="text" name="student_first_name" value="<?= h($_POST['student_first_name'] ?? $current_user['first_name']) ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" required placeholder="Your First Name"  />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">Middle Name</label>
            <input type="text" name="student_middle_name" value="<?= h($_POST['student_middle_name'] ?? $current_user['middle_name']) ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" placeholder="Middle Name (optional)" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">Last Name</label>
            <input type="text" name="student_last_name" value="<?= h($_POST['student_last_name'] ?? $current_user['last_name']) ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" required placeholder="Your Last Name" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">Birth Date</label>
            <input onchange="updateAgeFromDob()" type="date" name="student_birth_date" value="<?= h($_POST['student_birth_date'] ?? '') ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">Age</label>
            <input type="number" min="0" name="student_age" value="<?= h($_POST['student_age'] ?? '') ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" placeholder="Age (years)" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">Gender</label>
            <div class="mt-1 flex items-center space-x-4">
              <label class="inline-flex items-center"><input type="radio" name="student_gender" value="male" <?= (($_POST['student_gender'] ?? '') === 'male') ? 'checked' : '' ?> class="h-4 w-4" /> <span class="ml-2 text-sm">Male</span></label>
              <label class="inline-flex items-center"><input type="radio" name="student_gender" value="female" <?= (($_POST['student_gender'] ?? '') === 'female') ? 'checked' : '' ?> class="h-4 w-4" /> <span class="ml-2 text-sm">Female</span></label>
              <label class="inline-flex items-center"><input type="radio" name="student_gender" value="other" <?= (($_POST['student_gender'] ?? '') === 'other') ? 'checked' : '' ?> class="h-4 w-4" /> <span class="ml-2 text-sm">Other</span></label>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">Religion</label>
            <input type="text" name="student_religion" value="<?= h($_POST['student_religion'] ?? '') ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" placeholder="e.g. Christianity, Islam, Indigenous" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">Birthplace</label>
            <input type="text" name="student_birthplace" value="<?= h($_POST['student_birthplace'] ?? '') ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" placeholder="Place of birth (city/province/country)" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">Contact Number</label>
            <input type="text" name="student_contact" value="<?= h($_POST['student_contact'] ?? '') ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" placeholder="(000) 000-0000" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">Email Address</label>
            <input type="email" name="student_email" value="<?= h($_POST['student_email'] ?? $current_user['email']) ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" required placeholder="name@example.com"  />
          </div>
        </div>

        <!-- Current Address fields (added to match image) -->
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-2">Student Address</label>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <input type="text" name="student_addr_house" value="<?= h($_POST['student_addr_house'] ?? '') ?>" placeholder="House No." class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" />
            <input type="text" name="student_addr_street" value="<?= h($_POST['student_addr_street'] ?? '') ?>" placeholder="Sitio / Street" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" />
            <input type="text" name="student_addr_barangay" value="<?= h($_POST['student_addr_barangay'] ?? '') ?>" placeholder="Barangay" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" />
            <input type="text" name="student_addr_city" value="<?= h($_POST['student_addr_city'] ?? '') ?>" placeholder="Municipality / City" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" />
            <input type="text" name="student_addr_province" value="<?= h($_POST['student_addr_province'] ?? '') ?>" placeholder="Province" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" />
            <input type="text" name="student_addr_country" value="<?= h($_POST['student_addr_country'] ?? '') ?>" placeholder="Country" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" />
            <input type="text" name="student_addr_zip" value="<?= h($_POST['student_addr_zip'] ?? '') ?>" placeholder="ZIP / Postal Code" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" />
          </div>
          <p class="text-xs text-gray-500 mt-1">Fill out your current address. Use the fields provided above (House No., Sitio/Street, Barangay, Municipality/City, Province).</p>
        </div>

        <!-- Indigenous Peoples question -->
        <div class="md:col-span-2 mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-2">Belonging to any Indigenous Peoples (IP) Community / Indigenous Cultural Community?</label>
          <div class="flex items-center space-x-4">
            <label class="inline-flex items-center"><input type="radio" name="indigenous_belongs" value="yes" onclick="toggleIndigenousSpecify()" <?= (($_POST['indigenous_belongs'] ?? '') === 'yes') ? 'checked' : '' ?> /> <span class="ml-2 text-sm">Yes</span></label>
            <label class="inline-flex items-center"><input type="radio" name="indigenous_belongs" value="no" onclick="toggleIndigenousSpecify()" <?= (($_POST['indigenous_belongs'] ?? '') === 'no') ? 'checked' : '' ?> /> <span class="ml-2 text-sm">No</span></label>
            <div id="indigenous_spec_div" class="ml-4" style="display:<?= (($_POST['indigenous_belongs'] ?? '') === 'yes') ? 'block' : 'none' ?>;">
              <input type="text" name="indigenous_spec" value="<?= h($_POST['indigenous_spec'] ?? '') ?>" placeholder="If Yes, please specify" class="block w-80 rounded border-gray-300 px-3 py-2 text-sm" />
            </div>
          </div>
        </div>

        <hr class="my-4" />

        <!-- Parent / Guardian details (added to match image) -->
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-2">Parent / Guardian Details</label>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Father's Name</label>
              <input type="text" name="father_name" value="<?= h($_POST['father_name'] ?? '') ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" placeholder="Father's Full Name (Last, Given, Middle)" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Mother's Maiden Name</label>
              <input type="text" name="mother_maiden_name" value="<?= h($_POST['mother_maiden_name'] ?? '') ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" placeholder="Mother's Maiden Name (Last, Given, Middle)" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Legal Guardian's Name</label>
              <input type="text" name="legal_guardian_name" value="<?= h($_POST['legal_guardian_name'] ?? '') ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" placeholder="Legal Guardian (if applicable)" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Contact Number</label>
              <input type="text" name="guardian_contact" value="<?= h($_POST['guardian_contact'] ?? '') ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" placeholder="Contact Number for Parent/Guardian" />
            </div>

            <!-- existing generic parent fields (kept for backward compatibility) -->
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Primary Parent / Guardian Name</label>
              <input type="text" name="parent_name" value="<?= h($_POST['parent_name'] ?? '') ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" placeholder="Name (Primary Contact)" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Relation to Student</label>
              <input type="text" name="parent_relation" value="<?= h($_POST['parent_relation'] ?? '') ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" placeholder="e.g. Mother, Father, Guardian" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Primary Parent Contact</label>
              <input type="text" name="parent_contact" value="<?= h($_POST['parent_contact'] ?? '') ?>" class="mt-1 block w-full rounded border-gray-300 px-3 py-2 text-sm" placeholder="(000) 000-0000" />
            </div>
            <div class="flex items-center space-x-3">
              <label class="inline-flex items-center"><input type="checkbox" name="parent_consent" value="1" <?= (isset($_POST['parent_consent']) ? 'checked' : '') ?> /> <span class="ml-2 text-sm">Consent given</span></label>
              <label class="inline-flex items-center"><input type="checkbox" name="parent_lives_with" value="1" <?= (isset($_POST['parent_lives_with']) ? 'checked' : '') ?> /> <span class="ml-2 text-sm">Lives with student</span></label>
            </div>
          </div>
        </div>

        <hr class="my-4" />

        <!-- Payment amount input (styled like your screenshot: boxed full width helper) -->
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-2">Amount</label>
          <div class="border rounded bg-white p-4">
            <input
              type="text"
              name="amount"
              id="amount"
              placeholder="e.g. 1500.00"
              value="<?= isset($_POST['amount']) ? h($_POST['amount']) : '' ?>"
              class="block w-full text-gray-700 placeholder-gray-400 border-0 focus:outline-none focus:ring-0 text-sm"
              aria-label="Amount"
            />
            <p class="mt-2 text-xs text-gray-400">Enter the amount for down payment ₱1000. Admin will verify and mark completed.</p>
          </div>
        </div>

        <!-- file uploads and submit button -->
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-2">Upload required documents</label>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">PSA (Birth Certificate)</label>
              <input type="file" name="psa" accept=".pdf,image/jpeg,image/png" class="block w-full" />
              <p class="text-xs text-gray-400 mt-1">Accepted: PDF, JPG, PNG. Max 5 MB.</p>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Report Card</label>
              <input type="file" name="report_card" accept=".pdf,image/jpeg,image/png" class="block w-full" />
              <p class="text-xs text-gray-400 mt-1">Accepted: PDF, JPG, PNG. Max 5 MB.</p>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Form 138</label>
              <input type="file" name="form_138" accept=".pdf,image/jpeg,image/png" class="block w-full" />
              <p class="text-xs text-gray-400 mt-1">Accepted: PDF, JPG, PNG. Max 5 MB.</p>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Good Moral Certificate</label>
              <input type="file" name="good_moral" accept=".pdf,image/jpeg,image/png" class="block w-full" />
              <p class="text-xs text-gray-400 mt-1">Accepted: PDF, JPG, PNG. Max 5 MB.</p>
            </div>
          </div>

          <div class="mt-3">
            <label class="block text-sm font-medium text-gray-700 mb-1">Additional documents (optional)</label>
            <input type="file" name="documents[]" multiple accept=".pdf,image/jpeg,image/png" class="block" />
            <p class="text-xs text-gray-500 mt-1">Use this for any extra attachments. Accepted: PDF, JPG, PNG. Max 5 MB per file.</p>
          </div>
        </div>

        <div class="flex items-center space-x-3">
          <button type="submit" class="inline-flex items-center px-4 py-2 bg-sky-600 text-white rounded-md hover:bg-sky-700">Submit Application</button>
          <a href="dashboard.php" class="text-sm text-gray-600 hover:underline">Cancel</a>
        </div>
      </form>
    </section>

    <!-- PAYMENTS SECTION (embedded) -->
   
    <!-- applications listing unchanged -->
    <section id="applications" class="bg-white shadow rounded-lg p-6">
      <h3 class="text-lg font-medium text-gray-900 mb-3">Your Applications</h3>

      <?php if (empty($applications)): ?>
        <div class="text-sm text-gray-600">You have not submitted any enrollment applications yet.</div>
      <?php else: ?>
        <div class="space-y-4">
          <?php foreach ($applications as $app): ?>
            <?php
              $files = json_decode($app['files'] ?: '[]', true);
              $courses_applied = json_decode($app['course_ids'] ?: '[]', true);
              $parent_info = json_decode($app['parent_info'] ?: 'null', true);
              $student_info = json_decode($app['student_info'] ?: 'null', true);
            ?>
            <div class="border rounded p-4">
              <div class="flex items-start justify-between">
                <div>
                  <div class="text-sm text-gray-900 font-medium">Application #<?= (int)$app['id'] ?></div>
                  <div class="text-xs text-gray-500">Submitted: <?= h($app['submitted_at']) ?></div>
                </div>
                <div class="text-sm">
                  <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium <?= $app['status'] === 'approved' ? 'bg-green-100 text-green-800' : ($app['status'] === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') ?>">
                    <?= h(ucfirst($app['status'])) ?>
                  </span>
                </div>
              </div>

              <div class="mt-3 text-sm text-gray-700">
                <div><strong>Student:</strong>
                  <?php if (!empty($student_info) && is_array($student_info)): ?>
                    <?= h($student_info['first_name'] ?? ''); ?> <?= h($student_info['middle_name'] ?? '') ?> <?= h($student_info['last_name'] ?? ''); ?> — <?= h($student_info['email'] ?? '') ?>
                    <div class="text-xs text-gray-500">DOB: <?= h($student_info['birth_date'] ?? '—') ?> · Birthplace: <?= h($student_info['birthplace'] ?? '—') ?> · Gender: <?= h($student_info['gender'] ?? '—') ?> · Age: <?= h($student_info['age'] ?? '—') ?></div>
                    <?php
                      $a = $student_info['address'] ?? null;
                      if (!empty($a) && is_array($a)):
                    ?>
                      <div class="text-xs text-gray-500 mt-1">
                        <?= h($a['house_no'] ?? '') ?> <?= h($a['street'] ?? '') ?> <?= h($a['barangay'] ?? '') ?>,
                        <?= h($a['city'] ?? '') ?>, <?= h($a['province'] ?? '') ?> <?= h($a['country'] ?? '') ?> <?= h($a['zip'] ?? '') ?>
                      </div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-gray-500">Not provided</span>
                  <?php endif; ?>
                </div>

                <div class="mt-2"><strong>Courses:</strong>
                  <?php if (empty($courses_applied)): ?>
                    <span class="text-gray-500">None</span>
                  <?php else: ?>
                    <?php
                      $placeholders = implode(',', array_fill(0, count($courses_applied), '?'));
                      $stmt2 = $pdo->prepare("SELECT course_code, course_name FROM courses WHERE id IN ($placeholders)");
                      $stmt2->execute($courses_applied);
                      $crs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                      $labels = array_map(fn($r)=> h($r['course_code']) . ' — ' . h($r['course_name']), $crs);
                    ?>
                    <?= implode(', ', $labels) ?>
                  <?php endif; ?>
                </div>

                <?php if (!empty($app['notes'])): ?>
                  <div class="mt-2"><strong>Notes:</strong> <?= h($app['notes']) ?></div>
                <?php endif; ?>

                <?php if (!empty($parent_info) && is_array($parent_info)): ?>
                  <div class="mt-2">
                    <strong>Parent / Guardian:</strong>
                    <div class="text-sm text-gray-700">
                      <?php if (!empty($parent_info['father_name'])): ?><div><strong>Father:</strong> <?= h($parent_info['father_name']) ?></div><?php endif; ?>
                      <?php if (!empty($parent_info['mother_maiden_name'])): ?><div><strong>Mother (maiden):</strong> <?= h($parent_info['mother_maiden_name']) ?></div><?php endif; ?>
                      <?php if (!empty($parent_info['legal_guardian_name'])): ?><div><strong>Legal Guardian:</strong> <?= h($parent_info['legal_guardian_name']) ?></div><?php endif; ?>

                      <?php if (!empty($parent_info['name'])): ?>
                        <div class="mt-1"><?= h($parent_info['name']) ?> <?php if (!empty($parent_info['relation'])): ?>(<?= h($parent_info['relation']) ?>)<?php endif; ?></div>
                      <?php endif; ?>

                      <?php if (!empty($parent_info['guardian_contact'])): ?>
                        <div class="text-xs text-gray-500">Contact: <?= h($parent_info['guardian_contact']) ?></div>
                      <?php elseif (!empty($parent_info['contact'])): ?>
                        <div class="text-xs text-gray-500">Contact: <?= h($parent_info['contact']) ?></div>
                      <?php endif; ?>

                      <div class="text-xs text-gray-500 mt-1">
                        Consent: <?= (!empty($parent_info['consent']) ? 'Yes' : 'No') ?> · Lives with student: <?= (!empty($parent_info['lives_with']) ? 'Yes' : 'No') ?>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>

                <?php if (!empty($files)): ?>
                  <div class="mt-2"><strong>Documents:</strong>
                    <ul class="list-disc list-inside">
                      <?php foreach ($files as $f): ?>
                        <li>
                          <?php if (!empty($f['type'])): ?><strong><?= h($f['type']) ?>:</strong> <?php endif; ?>
                          <a href="<?= h($f['stored_name']) ?>" target="_blank" rel="noopener" class="text-sky-600 hover:underline"><?= h($f['original_name']) ?></a>
                          <span class="text-xs text-gray-400"> (<?= round($f['size']/1024) ?> KB)</span>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                <?php endif; ?>
              </div>

              <?php if (!empty($app['processed_at'])): ?>
                <div class="mt-3 text-xs text-gray-500">Processed at: <?= h($app['processed_at']) ?> by <?= h($app['processed_by'] ?? 'system') ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>

  <footer class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pb-8 text-sm text-gray-500">
    © <?= date('Y') ?> Your Institution
  </footer>
</body>
</html>