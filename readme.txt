=== HR Nomination Form ===
Contributors: hrtechinsight
Tags: nomination, hr, form, awards, submissions
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fixes and handles existing HR nomination HTML forms, emails submission details with CSV attachments, logs submissions with CSV/Excel exports, and supports GitHub auto-updates.

== Description ==

HR Nomination Form seamlessly activates broken or unhandled HTML nomination forms on your WordPress site. Designed specifically for sites built with WPBakery Page Builder, Elementor, or custom raw HTML blocks, this plugin requires **zero manual editing of page builder templates**.

=== Key Features ===
* **Automated Form Hooking**: JavaScript automatically finds `.hr-form` on your site and injects the proper WordPress `admin-post.php` action, security nonces, and spam honeypots.
* **Email Notifications with CSV Attachment**: Automatically sends structured HTML emails with styled tables and attaches a freshly generated `.csv` of the nomination data.
* **Database Logging**: Captures every submission in a dedicated table `wp_hr_nominations` with timestamps, submitter IP, and extra fields.
* **Submissions Admin Dashboard**: View paginated submissions, search by company or contact person, filter by category, view detailed modal previews, resend emails, and delete records.
* **Export to CSV & Excel (.xlsx)**: One-click export to CSV or native OpenXML Excel `.xlsx` spreadsheets with bold header formatting. No external Composer packages needed.
* **Spam & Abuse Protection**: Features invisible honeypots and IP-based rate limiting (1 submission per IP per 60 seconds).
* **Frontend Feedback**: Injects accessible success/error banners and disables the submit button with an animated spinner while submitting.
* **GitHub Auto-Update**: Integrated updater connects to your GitHub repository's latest release, allowing one-click updates directly inside the WordPress Plugins screen.

== Installation ==

1. Upload the `hr-nomination-form` folder to the `/wp-content/plugins/` directory, or upload the `hr-nomination-form.zip` file via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **HR Nominations > Settings** to configure recipient emails, email subject templates, and optional GitHub repository details.
4. Ensure your form container or form element has the class `.hr-form`. The plugin handles the rest automatically!

== Frequently Asked Questions ==

= Does this require editing my WPBakery page builder blocks? =
No. The plugin automatically detects any form with the class `.hr-form` and dynamically injects the form action, method, nonces, and honeypot on page load.

= Which fields are validated? =
Required fields include: Company Name, Website, Contact Person, Job Title, Email, Phone, Award Category, Company Overview, and Consent.

= Can I export submissions to Excel? =
Yes! In **HR Nominations > Submissions**, click the "Export to Excel (.xlsx)" button to download a genuine `.xlsx` spreadsheet.

= How does GitHub Auto-Update work? =
Under **HR Nominations > Settings**, set your GitHub repository slug (e.g. `owner/repo`). When you publish a new release tag on GitHub, WordPress will notify you and allow a one-click update.

== Changelog ==

= 1.0.0 =
* Initial release.
* Automated form injection for `.hr-form`.
* HTML email notifications with single-submission CSV attachment.
* Custom database table `wp_hr_nominations`.
* Admin Submissions table with search, category filtering, and modal preview.
* Pure-PHP CSV and Excel (.xlsx) export.
* Native GitHub Releases updater with 6-hour caching.
