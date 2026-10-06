# HR Nomination Form WordPress Plugin

A robust WordPress plugin designed to fix and activate broken HTML nomination forms (such as raw HTML blocks in WPBakery Page Builder or Elementor) without modifying theme files. It provides native form processing, email notifications with attached CSV files, database storage, admin management with CSV/Excel exports, and seamless GitHub auto-updates.

---

## 🚀 Key Features

* **Zero WPBakery Edits Needed**: JavaScript dynamically locates `.hr-form` on page load, injecting `action="admin-post.php"`, `name="action" value="hr_nomination_form"`, WordPress security nonces, return redirect URLs, and invisible honeypots.
* **Dual Request Handlers**: Hooks `admin_post_hr_nomination_form` and `admin_post_nopriv_hr_nomination_form` to accept submissions from both logged-in and guest visitors.
* **Rigorous Field Sanitization & Validation**:
  * Fields validated: `company_name`, `website`, `contact_person`, `job_title`, `email`, `phone`, `category`, `company_overview`, and `consent`.
  * Sanitized using standard WordPress sanitizers (`sanitize_text_field`, `esc_url_raw`, `sanitize_email`, `sanitize_textarea_field`).
  * Captures extra fields dynamically in JSON format so no submitted data is lost.
* **Spam & Abuse Protection**:
  * Invisible honeypot field traps automated bots.
  * Transient-based IP rate limiting (1 submission per IP per 60 seconds).
* **HTML Email Notifications with CSV Attachment**:
  * Formatted HTML email table sent via `wp_mail()`.
  * Customizable subject template with `{company_name}`, `{category}`, `{contact_person}`, etc.
  * Direct submitter `Reply-To`.
  * Automatically generates and attaches a `.csv` file containing the submission data.
* **Persistent Database Storage**:
  * Stores submissions in a dedicated table `wp_hr_nominations`.
  * Track email delivery status, submitter IP address, and submission timestamp.
* **Comprehensive Admin UI**:
  * Top-level menu: **HR Nominations**.
  * Submissions table with search, category filtering, and pagination.
  * Detailed modal preview for viewing full nomination details.
  * Resend email notification action.
  * Single and bulk deletion with security nonces.
  * **Export to CSV** (with UTF-8 BOM for Excel).
  * **Export to Excel (.xlsx)** generated in pure PHP via `ZipArchive` (zero external Composer packages).
* **Front-end Feedback & UX**:
  * Success and error banners automatically rendered based on `?nomination=success` or `?nomination=error`.
  * Prevents double submissions: disables submit button and displays animated loading spinner.
  * Auto-cleans query parameters from browser URL using `window.history.replaceState`.
* **GitHub Auto-Update**:
  * Self-contained updater class interacting with GitHub Releases API.
  * Hooks into WordPress core update transient (`pre_set_site_transient_update_plugins`) and plugin info modal (`plugins_api`).
  * 6-hour transient caching to respect GitHub API rate limits.
  * Supports Personal Access Tokens (PAT) for private repositories.
  * Automatically handles folder renaming so updates install cleanly into `hr-nomination-form/`.

---

## 📁 Plugin File Structure

```
hr-nomination-form/
├── hr-nomination-form.php       # Main plugin loader, constants & bootstrap
├── includes/
│   ├── class-database.php       # Custom table schema, CRUD operations & filters
│   ├── class-form-handler.php   # Nonces, validation, sanitization, email & CSV
│   ├── class-frontend.php       # Frontend asset enqueueing & localization
│   ├── class-admin.php          # Admin menu, submissions table, settings & exports
│   └── class-updater.php        # Native GitHub Releases auto-updater
├── assets/
│   ├── js/
│   │   └── frontend.js          # DOM injection, client validation & UX banners
│   └── css/
│       └── frontend.css         # Styling for alerts, spinners & error states
├── readme.txt                   # Standard WordPress repository metadata
└── README.md                    # Documentation & GitHub release guide
```

---

## 🛠️ Installation & Setup

1. **Upload Plugin**:
   * Copy the `hr-nomination-form/` folder into your site's `/wp-content/plugins/` directory, **OR**
   * In WP Admin, go to **Plugins > Add New > Upload Plugin** and upload `hr-nomination-form.zip`.
2. **Activate**:
   * Activate **HR Nomination Form** from the Plugins screen.
3. **Configure Settings**:
   * Go to **HR Nominations > Settings**:
     * **Recipient Email(s)**: Enter recipient email(s) (comma-separated if multiple).
     * **Email Subject Template**: e.g., `New HR Nomination: {company_name} - {category}`.
     * **From Name & Email**: Set sender details.
     * **CSV Attachment**: Toggle attaching `.csv` to notification emails.
     * **Database Storage**: Toggle logging submissions to `wp_hr_nominations`.
     * **GitHub Repository Slug**: Enter `your-username/hr-nomination-form` to enable updates.
     * **Personal Access Token (PAT)**: Optional, for private repositories.
4. **Form Integration**:
   * Ensure your existing nomination form element or its container has the class `hr-form`:
     ```html
     <form class="hr-form">
       <!-- your existing inputs -->
     </form>
     ```
   * The plugin automatically sets the form action, nonces, and submission handler.

---

## 📦 GitHub Release & Auto-Update Workflow

Follow these steps whenever releasing an update to GitHub:

### Step 1: Bump Version Numbers
Update the version string in both files:
1. **`hr-nomination-form.php`**:
   ```php
   * Version: 1.0.1
   ...
   define( 'HR_NOMINATION_VERSION', '1.0.1' );
   ```
2. **`readme.txt`**:
   ```txt
   Stable tag: 1.0.1
   ```

### Step 2: Commit & Push Changes
```bash
git add .
git commit -m "Release version 1.0.1: [describe your changes]"
git push origin main
```

### Step 3: Create GitHub Release
1. In your GitHub repository, go to **Releases > Draft a new release**.
2. **Choose a tag**: Type `v1.0.1` (or `1.0.1`) and select "Create new tag on publish".
3. **Release title**: `HR Nomination Form 1.0.1`.
4. **Description**: Detail changes, fixes, and improvements (this is shown in the WordPress update changelog).

### Step 4: Attach Plugin Zip Asset
1. Build the zip file:
   ```bash
   zip -r hr-nomination-form.zip hr-nomination-form/ -x "*.git*"
   ```
2. Drag and drop `hr-nomination-form.zip` into the GitHub release asset dropzone.
3. Click **Publish release**.

Within 6 hours (or immediately upon checking for updates in WordPress), sites running the plugin will display **"There is a new version of HR Nomination Form available"** with full one-click update functionality!

---

## 🧪 Comprehensive Verification Checklist

Use this checklist to confirm all requirements are met:

- [x] **Form Injection**:
  - Open page with `.hr-form`.
  - Inspect DOM: verify `action` is set to `.../wp-admin/admin-post.php`, `method="POST"`, hidden `action="hr_nomination_form"`, `hr_nomination_nonce`, and hidden honeypot wrapper exist.
- [x] **Submission Flow**:
  - Fill in all required fields and click submit.
  - Verify submit button becomes disabled and displays loading spinner.
  - Submitter is redirected back to the page with `?nomination=success`.
  - Clean green success banner appears above the form and URL query params are smoothly cleared.
- [x] **Email Notification**:
  - Admin inbox receives formatted HTML email with submission details.
  - Submitter email is set in `Reply-To`.
  - `.csv` file is attached containing the nomination data.
- [x] **Database Row Created**:
  - Check WP Admin > **HR Nominations > Submissions**.
  - Submission appears with correct Company Name, Contact Person, Category, and date.
  - Click "View Details" to inspect the modal with full information.
- [x] **Rate Limiting & Spam Protection**:
  - Attempting a second submission from the same IP within 60s triggers the rate limit error banner.
  - Filling the invisible honeypot field prevents delivery without disruption.
- [x] **Admin Actions & Exports**:
  - Click **Export to CSV**: downloads valid `.csv` with UTF-8 BOM.
  - Click **Export to Excel (.xlsx)**: downloads native OpenXML `.xlsx` spreadsheet that opens in Excel and Google Sheets without warnings.
  - Click "Resend" to verify email re-dispatch.
  - Delete single entry and test bulk delete.
- [x] **GitHub Auto-Update**:
  - Configure GitHub repository slug in Settings.
  - Publish release tag higher than current header version.
  - Go to **Dashboard > Updates** in WordPress; verify update notice and "View version details" changelog appear.
