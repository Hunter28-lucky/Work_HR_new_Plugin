<?php
/**
 * Form submission handler for HR Nomination Form.
 *
 * @package HR_Nomination_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HR_Nomination_Form_Handler
 */
class HR_Nomination_Form_Handler {

	/**
	 * Action hook name for form submission.
	 *
	 * @var string
	 */
	const ACTION_NAME = 'hr_nomination_form';

	/**
	 * Nonce action name.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'hr_nomination_form_nonce';

	/**
	 * Honeypot input name.
	 *
	 * @var string
	 */
	const HONEYPOT_FIELD = 'hr_nomination_hp';

	/**
	 * Initialize form hooks.
	 */
	public function __construct() {
		// Native admin-post handlers (logged in and logged out).
		add_action( 'admin_post_' . self::ACTION_NAME, array( $this, 'handle_submission' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_NAME, array( $this, 'handle_submission' ) );

		// AJAX handlers (optional fallback).
		add_action( 'wp_ajax_' . self::ACTION_NAME, array( $this, 'handle_ajax_submission' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION_NAME, array( $this, 'handle_ajax_submission' ) );
	}

	/**
	 * Handle standard POST submission via admin-post.php.
	 */
	public function handle_submission(): void {
		$return_url = $this->get_return_url();

		// 1. Verify Nonce.
		$nonce = isset( $_POST['hr_nomination_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hr_nomination_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->redirect_with_status( $return_url, 'error', 'invalid_nonce' );
		}

		// 2. Honeypot check for bots.
		$honeypot = isset( $_POST[ self::HONEYPOT_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::HONEYPOT_FIELD ] ) ) : '';
		if ( ! empty( $honeypot ) ) {
			// Silently redirect bot as success to avoid triggering retry loops.
			$this->redirect_with_status( $return_url, 'success' );
		}

		// 3. Rate limiting (1 submission per IP per 60 seconds).
		$ip       = $this->get_client_ip();
		$rate_key = 'hr_nom_rl_' . md5( $ip );
		if ( false !== get_transient( $rate_key ) ) {
			$this->redirect_with_status( $return_url, 'error', 'rate_limit' );
		}

		// Set rate limit transient for 60 seconds.
		set_transient( $rate_key, 1, 60 );

		// 4. Extract and sanitize fields.
		$data = $this->extract_and_sanitize_data();

		// 5. Validate required fields.
		$validation_errors = $this->validate_submission( $data );
		if ( ! empty( $validation_errors ) ) {
			$this->redirect_with_status( $return_url, 'error', 'validation_failed' );
		}

		// 6. Save to Database (if enabled in settings).
		$db_enabled    = (int) get_option( 'hr_db_storage', 1 );
		$submission_id = 0;

		if ( $db_enabled ) {
			$submission_id = HR_Nomination_Database::insert_submission( $data );
			if ( ! $submission_id ) {
				$this->redirect_with_status( $return_url, 'error', 'database_error' );
			}
			$data['id'] = $submission_id;
		}

		// 7. Send Email notification with CSV attachment.
		$email_sent = $this->send_notification_email( $data );

		if ( $db_enabled && $submission_id && $email_sent ) {
			HR_Nomination_Database::update_email_status( $submission_id, 1 );
		}

		// 8. Redirect back with success status.
		$this->redirect_with_status( $return_url, 'success' );
	}

	/**
	 * Handle AJAX form submission.
	 */
	public function handle_ajax_submission(): void {
		// Nonce check.
		$nonce = isset( $_POST['hr_nomination_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hr_nomination_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security verification failed. Please refresh and try again.', 'hr-nomination-form' ) ), 403 );
		}

		// Honeypot.
		$honeypot = isset( $_POST[ self::HONEYPOT_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::HONEYPOT_FIELD ] ) ) : '';
		if ( ! empty( $honeypot ) ) {
			wp_send_json_success( array( 'message' => __( 'Nomination received.', 'hr-nomination-form' ) ) );
		}

		// Rate limit.
		$ip       = $this->get_client_ip();
		$rate_key = 'hr_nom_rl_' . md5( $ip );
		if ( false !== get_transient( $rate_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many submissions. Please wait 60 seconds before submitting again.', 'hr-nomination-form' ) ), 429 );
		}
		set_transient( $rate_key, 1, 60 );

		// Extract & validate.
		$data   = $this->extract_and_sanitize_data();
		$errors = $this->validate_submission( $data );
		if ( ! empty( $errors ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please complete all required fields.', 'hr-nomination-form' ),
					'errors'  => $errors,
				),
				400
			);
		}

		// Save DB.
		$db_enabled    = (int) get_option( 'hr_db_storage', 1 );
		$submission_id = 0;
		if ( $db_enabled ) {
			$submission_id = HR_Nomination_Database::insert_submission( $data );
			$data['id']    = $submission_id;
		}

		// Email.
		$email_sent = $this->send_notification_email( $data );
		if ( $db_enabled && $submission_id && $email_sent ) {
			HR_Nomination_Database::update_email_status( $submission_id, 1 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Thank you! Your nomination has been submitted successfully.', 'hr-nomination-form' ),
			)
		);
	}

	/**
	 * Extract and sanitize incoming $_POST data.
	 * Supports canonical field names as well as common variations.
	 *
	 * @return array
	 */
	public function extract_and_sanitize_data(): array {
		$post = wp_unslash( $_POST );

		// Helper to look up field across alias keys.
		$get_field = function ( array $keys ) use ( $post ): string {
			foreach ( $keys as $k ) {
				if ( isset( $post[ $k ] ) && '' !== trim( (string) $post[ $k ] ) ) {
					return trim( (string) $post[ $k ] );
				}
			}
			return '';
		};

		$company_name     = sanitize_text_field( $get_field( array( 'company_name', 'company', 'company-name', 'companyName' ) ) );
		$website          = esc_url_raw( $get_field( array( 'website', 'company_website', 'company-website', 'url', 'site_url' ) ) );
		$contact_person   = sanitize_text_field( $get_field( array( 'contact_person', 'contact_name', 'name', 'full_name', 'contactPerson' ) ) );
		$job_title        = sanitize_text_field( $get_field( array( 'job_title', 'title', 'designation', 'position', 'jobTitle' ) ) );
		$email            = sanitize_email( $get_field( array( 'email', 'contact_email', 'work_email', 'email_address' ) ) );
		$phone            = sanitize_text_field( $get_field( array( 'phone', 'phone_number', 'telephone', 'mobile', 'tel' ) ) );
		$category         = sanitize_text_field( $get_field( array( 'category', 'award_category', 'nomination_category', 'awards_category' ) ) );
		$company_overview = sanitize_textarea_field( $get_field( array( 'company_overview', 'overview', 'description', 'nomination_reason', 'about_company', 'why_nominate' ) ) );

		// Consent check (checkbox or radio).
		$consent_val = $get_field( array( 'consent', 'agree', 'terms', 'privacy_consent', 'agreement' ) );
		$consent     = ! empty( $consent_val ) && ( '1' === $consent_val || 'on' === strtolower( $consent_val ) || 'yes' === strtolower( $consent_val ) || 'true' === strtolower( $consent_val ) ) ? 1 : 0;

		// Capture any remaining extra fields so no information is lost.
		$standard_keys = array(
			'action',
			'hr_nomination_nonce',
			'return_url',
			self::HONEYPOT_FIELD,
			'company_name',
			'company',
			'company-name',
			'companyName',
			'website',
			'company_website',
			'company-website',
			'url',
			'site_url',
			'contact_person',
			'contact_name',
			'name',
			'full_name',
			'contactPerson',
			'job_title',
			'title',
			'designation',
			'position',
			'jobTitle',
			'email',
			'contact_email',
			'work_email',
			'email_address',
			'phone',
			'phone_number',
			'telephone',
			'mobile',
			'tel',
			'category',
			'award_category',
			'nomination_category',
			'awards_category',
			'company_overview',
			'overview',
			'description',
			'nomination_reason',
			'about_company',
			'why_nominate',
			'consent',
			'agree',
			'terms',
			'privacy_consent',
			'agreement',
		);

		$extra_data = array();
		foreach ( $post as $key => $val ) {
			if ( ! in_array( $key, $standard_keys, true ) && ! empty( $val ) ) {
				$clean_key = sanitize_key( $key );
				if ( is_array( $val ) ) {
					$extra_data[ $clean_key ] = array_map( 'sanitize_text_field', $val );
				} else {
					$extra_data[ $clean_key ] = sanitize_textarea_field( $val );
				}
			}
		}

		return array(
			'company_name'     => $company_name,
			'website'          => $website,
			'contact_person'   => $contact_person,
			'job_title'        => $job_title,
			'email'            => $email,
			'phone'            => $phone,
			'category'         => $category,
			'company_overview' => $company_overview,
			'consent'          => $consent,
			'extra_data'       => ! empty( $extra_data ) ? $extra_data : null,
			'ip_address'       => $this->get_client_ip(),
			'created_at'       => current_time( 'mysql' ),
		);
	}

	/**
	 * Validate required fields.
	 *
	 * @param array $data Submission data.
	 * @return array Array of errors, empty if valid.
	 */
	public function validate_submission( array $data ): array {
		$errors = array();

		if ( empty( $data['company_name'] ) ) {
			$errors['company_name'] = __( 'Company name is required.', 'hr-nomination-form' );
		}

		if ( empty( $data['website'] ) || ! filter_var( $data['website'], FILTER_VALIDATE_URL ) ) {
			$errors['website'] = __( 'A valid website URL is required.', 'hr-nomination-form' );
		}

		if ( empty( $data['contact_person'] ) ) {
			$errors['contact_person'] = __( 'Contact person is required.', 'hr-nomination-form' );
		}

		if ( empty( $data['job_title'] ) ) {
			$errors['job_title'] = __( 'Job title is required.', 'hr-nomination-form' );
		}

		if ( empty( $data['email'] ) || ! is_email( $data['email'] ) ) {
			$errors['email'] = __( 'A valid email address is required.', 'hr-nomination-form' );
		}

		if ( empty( $data['phone'] ) ) {
			$errors['phone'] = __( 'Phone number is required.', 'hr-nomination-form' );
		}

		if ( empty( $data['company_overview'] ) ) {
			$errors['company_overview'] = __( 'Company overview is required.', 'hr-nomination-form' );
		}

		if ( empty( $data['category'] ) ) {
			$errors['category'] = __( 'Category is required.', 'hr-nomination-form' );
		}

		if ( empty( $data['consent'] ) ) {
			$errors['consent'] = __( 'Consent is required.', 'hr-nomination-form' );
		}

		return $errors;
	}

	/**
	 * Send notification email with HTML table and attached CSV.
	 *
	 * @param array $data Submission data.
	 * @return bool True if sent, false otherwise.
	 */
	public function send_notification_email( array $data ): bool {
		// 1. Recipient email.
		$recipient = get_option( 'hr_recipient_email', get_option( 'admin_email' ) );
		if ( empty( $recipient ) ) {
			$recipient = get_option( 'admin_email' );
		}

		// Allow comma-separated multiple recipients.
		$recipients = array_map( 'trim', explode( ',', $recipient ) );
		$recipients = array_filter( $recipients, 'is_email' );
		if ( empty( $recipients ) ) {
			$recipients = array( get_option( 'admin_email' ) );
		}

		// 2. Email Subject with placeholders.
		$subject_template = get_option( 'hr_subject_template', 'New HR Nomination: {company_name} - {category}' );
		$placeholders     = array(
			'{company_name}'   => $data['company_name'],
			'{category}'       => $data['category'],
			'{contact_person}' => $data['contact_person'],
			'{job_title}'      => $data['job_title'],
			'{email}'          => $data['email'],
			'{phone}'          => $data['phone'],
			'{website}'        => $data['website'],
		);
		$subject = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $subject_template );

		// 3. Sender headers.
		$from_name  = get_option( 'hr_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'hr_from_email', get_option( 'admin_email' ) );
		if ( empty( $from_email ) || ! is_email( $from_email ) ) {
			$from_email = get_option( 'admin_email' );
		}

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', wp_strip_all_tags( $from_name ), sanitize_email( $from_email ) ),
			sprintf( 'Reply-To: %s <%s>', wp_strip_all_tags( $data['contact_person'] ), sanitize_email( $data['email'] ) ),
		);

		// 4. Build HTML email body.
		$body = $this->build_html_email_body( $data );

		// 5. Build CSV attachment (if enabled).
		$attachments    = array();
		$attach_csv_opt = (int) get_option( 'hr_csv_attachment', 1 );
		$temp_csv_file  = '';

		if ( $attach_csv_opt ) {
			$temp_csv_file = $this->generate_submission_csv( $data );
			if ( ! empty( $temp_csv_file ) && file_exists( $temp_csv_file ) ) {
				$attachments[] = $temp_csv_file;
			}
		}

		// 6. Send email.
		$mail_sent = wp_mail( $recipients, $subject, $body, $headers, $attachments );

		// 7. Clean up temporary CSV file.
		if ( ! empty( $temp_csv_file ) && file_exists( $temp_csv_file ) ) {
			@unlink( $temp_csv_file );
		}

		return $mail_sent;
	}

	/**
	 * Build clean and responsive HTML table for email.
	 *
	 * @param array $data Submission data.
	 * @return string
	 */
	public function build_html_email_body( array $data ): string {
		$company_name     = esc_html( $data['company_name'] );
		$website          = esc_url( $data['website'] );
		$contact_person   = esc_html( $data['contact_person'] );
		$job_title        = esc_html( $data['job_title'] );
		$email            = esc_html( $data['email'] );
		$phone            = esc_html( $data['phone'] );
		$category         = esc_html( $data['category'] );
		$company_overview = nl2br( esc_html( $data['company_overview'] ) );
		$ip_address       = esc_html( $data['ip_address'] ?? 'N/A' );
		$date             = esc_html( $data['created_at'] ?? current_time( 'mysql' ) );
		$submission_id    = ! empty( $data['id'] ) ? '#' . (int) $data['id'] : 'New';

		$extra_rows = '';
		if ( ! empty( $data['extra_data'] ) ) {
			$extras = is_array( $data['extra_data'] ) ? $data['extra_data'] : json_decode( $data['extra_data'], true );
			if ( is_array( $extras ) ) {
				foreach ( $extras as $k => $v ) {
					$label = esc_html( ucwords( str_replace( array( '_', '-' ), ' ', $k ) ) );
					$val   = is_array( $v ) ? esc_html( implode( ', ', $v ) ) : nl2br( esc_html( (string) $v ) );
					$extra_rows .= "<tr>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;font-weight:600;color:#374151;width:220px;vertical-align:top;\">{$label}</td>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;color:#111827;\">{$val}</td>
					</tr>";
				}
			}
		}

		return "<!DOCTYPE html>
<html>
<head>
	<meta charset=\"utf-8\">
	<title>New HR Nomination Submission</title>
</head>
<body style=\"margin:0;padding:24px;background-color:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;\">
	<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"max-width:680px;margin:0 auto;background:#ffffff;border-radius:8px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);overflow:hidden;border:1px solid #e5e7eb;\">
		<tr>
			<td style=\"padding:24px 32px;background:linear-gradient(135deg, #1e293b 0%, #0f172a 100%);color:#ffffff;\">
				<h1 style=\"margin:0 0 6px 0;font-size:22px;font-weight:700;color:#ffffff;\">New HR Nomination Submission</h1>
				<p style=\"margin:0;font-size:14px;color:#94a3b8;\">Submission {$submission_id} &bull; Received on {$date}</p>
			</td>
		</tr>
		<tr>
			<td style=\"padding:28px 32px;\">
				<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"border-collapse:collapse;font-size:14px;line-height:1.6;\">
					<tr>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;font-weight:600;color:#374151;width:220px;background:#f9fafb;\">Company Name</td>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;color:#111827;font-weight:600;\">{$company_name}</td>
					</tr>
					<tr>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;font-weight:600;color:#374151;background:#f9fafb;\">Award Category</td>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;color:#2563eb;font-weight:600;\">{$category}</td>
					</tr>
					<tr>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;font-weight:600;color:#374151;background:#f9fafb;\">Company Website</td>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;color:#111827;\"><a href=\"{$website}\" target=\"_blank\" style=\"color:#2563eb;text-decoration:underline;\">{$website}</a></td>
					</tr>
					<tr>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;font-weight:600;color:#374151;background:#f9fafb;\">Contact Person</td>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;color:#111827;\">{$contact_person}</td>
					</tr>
					<tr>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;font-weight:600;color:#374151;background:#f9fafb;\">Job Title</td>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;color:#111827;\">{$job_title}</td>
					</tr>
					<tr>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;font-weight:600;color:#374151;background:#f9fafb;\">Email Address</td>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;color:#111827;\"><a href=\"mailto:{$email}\" style=\"color:#2563eb;text-decoration:underline;\">{$email}</a></td>
					</tr>
					<tr>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;font-weight:600;color:#374151;background:#f9fafb;\">Phone Number</td>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;color:#111827;\">{$phone}</td>
					</tr>
					<tr>
						<td style=\"padding:12px 14px;border-bottom:1px solid #e5e7eb;font-weight:600;color:#374151;background:#f9fafb;vertical-align:top;\">Company Overview</td>
						<td style=\"padding:12px 14px;border-bottom:1px solid #e5e7eb;color:#111827;background:#ffffff;\">{$company_overview}</td>
					</tr>
					<tr>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;font-weight:600;color:#374151;background:#f9fafb;\">Consent Confirmed</td>
						<td style=\"padding:10px 14px;border-bottom:1px solid #e5e7eb;color:#059669;font-weight:600;\">Yes &check;</td>
					</tr>
					{$extra_rows}
					<tr>
						<td style=\"padding:10px 14px;font-weight:600;color:#6b7280;background:#f9fafb;\">Submitter IP</td>
						<td style=\"padding:10px 14px;color:#6b7280;\">{$ip_address}</td>
					</tr>
				</table>
			</td>
		</tr>
		<tr>
			<td style=\"padding:18px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;text-align:center;\">
				This nomination was submitted via " . esc_html( get_bloginfo( 'name' ) ) . ". Reply directly to this email to reach {$contact_person}.
			</td>
		</tr>
	</table>
</body>
</html>";
	}

	/**
	 * Generate a single-submission CSV file in upload/temp dir.
	 *
	 * @param array $data Submission data.
	 * @return string Path to generated CSV file.
	 */
	public function generate_submission_csv( array $data ): string {
		$upload_dir = wp_upload_dir();
		$target_dir = $upload_dir['basedir'] . '/hr-nominations-temp';

		if ( ! file_exists( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}

		$filename = sprintf( 'nomination-%s-%s.csv', sanitize_file_name( $data['company_name'] ), time() );
		$filepath = $target_dir . '/' . $filename;

		$handle = fopen( $filepath, 'w' );
		if ( ! $handle ) {
			return '';
		}

		// Write UTF-8 BOM for Excel compatibility.
		fwrite( $handle, "\xEF\xBB\xBF" );

		// CSV headers.
		fputcsv(
			$handle,
			array(
				'Submission ID',
				'Company Name',
				'Category',
				'Website',
				'Contact Person',
				'Job Title',
				'Email',
				'Phone',
				'Company Overview',
				'Consent',
				'IP Address',
				'Date Submitted',
			)
		);

		// CSV row.
		fputcsv(
			$handle,
			array(
				$data['id'] ?? 'N/A',
				$data['company_name'] ?? '',
				$data['category'] ?? '',
				$data['website'] ?? '',
				$data['contact_person'] ?? '',
				$data['job_title'] ?? '',
				$data['email'] ?? '',
				$data['phone'] ?? '',
				$data['company_overview'] ?? '',
				! empty( $data['consent'] ) ? 'Yes' : 'No',
				$data['ip_address'] ?? '',
				$data['created_at'] ?? current_time( 'mysql' ),
			)
		);

		fclose( $handle );

		return $filepath;
	}

	/**
	 * Get reliable client IP address.
	 *
	 * @return string
	 */
	public function get_client_ip(): string {
		$headers = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_CLIENT_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip_list = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				$ip      = trim( $ip_list[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '127.0.0.1';
	}

	/**
	 * Get redirection return URL.
	 *
	 * @return string
	 */
	private function get_return_url(): string {
		if ( ! empty( $_POST['return_url'] ) ) {
			return esc_url_raw( wp_unslash( $_POST['return_url'] ) );
		}

		$referer = wp_get_referer();
		if ( $referer ) {
			return $referer;
		}

		return home_url();
	}

	/**
	 * Redirect back with status and reason query arguments.
	 *
	 * @param string      $url Return URL.
	 * @param string      $status 'success' or 'error'.
	 * @param string|null $reason Reason slug.
	 */
	private function redirect_with_status( string $url, string $status, ?string $reason = null ): void {
		// Clean out existing nomination query parameters.
		$cleaned_url = remove_query_arg( array( 'nomination', 'reason' ), $url );

		$args = array( 'nomination' => $status );
		if ( ! empty( $reason ) ) {
			$args['reason'] = $reason;
		}

		$redirect_to = add_query_arg( $args, $cleaned_url );
		wp_safe_redirect( $redirect_to );
		exit;
	}
}
