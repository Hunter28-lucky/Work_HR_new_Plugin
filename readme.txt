=== HR Nomination Form ===
Contributors: hrtechinsight
Tags: nomination, hr, form, awards, submissions
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fixes and handles existing HR nomination HTML forms, emails submission details with CSV attachments, logs submissions with CSV/Excel exports, and supports automated updates.

== Description ==

HR Nomination Form seamlessly activates broken or unhandled HTML nomination forms on your WordPress site. Designed specifically for sites built with WPBakery Page Builder, Elementor, or custom raw HTML blocks, this plugin requires **zero manual editing of page builder templates**.

=== Key Features ===
* **Automated Form Hooking**: JavaScript automatically finds `.hr-form` on your site and injects the proper WordPress `admin-post.php` action, security nonces, and spam honeypots.
* **Email Notifications with CSV Attachment**: Automatically sends structured HTML emails with styled tables and attaches a freshly generated `.csv` of the nomination data.
* **Database Logging**: Captures every submission in a dedicated table `wp_hr_nominations` with timestamps, submitter IP, and extra fields.
* **Submissions Admin Dashboard**: View paginated submissions, search by company or contact person, filter by category, view detailed modal previews, resend emails, and delete records.
* **Export to CSV & Excel (.xlsx)**: One-click export to CSV or native OpenXML Excel `.xlsx` spreadsheets with bold header formatting. No external Composer packages needed.
* **Spam & Abuse Protection**: Features intelligent bot verification and IP-based rate limiting (1 submission per IP per 60 seconds).
* **Frontend Feedback**: Injects accessible success/error banners and disables the submit button with an animated spinner while submitting.
* **Automated Updates**: Integrated updater connects to distribution endpoints, allowing one-click updates directly inside the WordPress Plugins screen.

== Installation ==

1. Upload the `hr-nomination-form` folder to the `/wp-content/plugins/` directory, or upload the `hr-nomination-form.zip` file via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **HR Nominations > Settings** to configure recipient emails and email subject templates.
4. Ensure your form container or form element has the class `.hr-form`. The plugin handles the rest automatically!

== Changelog ==

= 1.0.5 =
* Implemented automatic background downloading and upgrading via WP-Cron and throttled admin checks.
* Redesigned Submissions dashboard with modern Executive SaaS layout, hero metrics banner, and custom-styled action pills.
* Preserved seamless form processing, data preservation, and complete origin privacy.

= 1.0.4 =
* Made system architecture and proprietary IP specifications hidden by default, accessible only via a subtle, single-line footer toggle.
* Refined admin dashboard aesthetics to preserve a completely clean interface.

= 1.0.3 =
* Hid repository hosting details and external links from all admin screens, plugin headers, and update information modals.
* Enhanced privacy for source code distribution endpoints.

= 1.0.2 =
* Added prominent developer attribution for Harshvardhan Kumar (Krish Goswami).
* Added comprehensive system architecture, runtime diagnostics, and proprietary intellectual property documentation in admin footer.
* Polished Submissions admin UI table layout and controls while ensuring 100% preservation of all database and form handling functions.

= 1.0.1 =
* Fixed honeypot false-positive with browser autofill by adding client-side JS verification tokens.
* Added automatic database table initialization on plugin boot, guaranteeing wp_hr_nominations is created even on zip upgrade.
* Cleaned up Settings UI by hiding internal distribution parameters.
* Added one-click "Check for Updates" button to Submissions dashboard.

= 1.0.0 =
* Initial release.
