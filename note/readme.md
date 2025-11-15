value="<?= h($current_user['full_name'] ? (explode(' ', $current_user['full_name'])[1] ?? '') : '') ?>"
value="<?= h($current_user['email']) ?>"
<a href="course_selection.php" class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-800 rounded hover:bg-gray-200 text-sm">View Courses</a>
              <a href="contact_support.php" class="inline-flex items-center px-4 py-2 bg-green-100 text-green-800 rounded hover:bg-green-200 text-sm">Contact Support</a>

              <!-- hypothetical checklist download (add real file on server if available) -->
              <a href="/downloads/enrollment_checklist.pdf" class="inline-flex items-center px-3 py-2 bg-gray-50 text-gray-700 rounded border text-sm hover:bg-gray-100" target="_blank" rel="noopener">Download checklist (PDF)</a>