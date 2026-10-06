<?php
/**
 * Admin management for HR Nomination Form.
 * Handles Admin UI, Submissions List, Settings, Actions, CSV & Excel Exports.
 *
 * @package HR_Nomination_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HR_Nomination_Admin
 */
class HR_Nomination_Admin {

	/**
	 * Menu slug for Submissions.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'hr-nominations';

	/**
	 * Menu slug for Settings.
	 *
	 * @var string
	 */
	const SETTINGS_SLUG = 'hr-nomination-settings';

	/**
	 * Initialize admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
		add_action( 'admin_init', array( $this, 'ensure_database_ready' ) );
		add_action( 'admin_init', array( $this, 'handle_export_requests' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Ensure database table exists whenever accessing admin.
	 */
	public function ensure_database_ready(): void {
		HR_Nomination_Database::ensure_table_exists();
	}

	/**
	 * Register Admin Menus.
	 */
	public function register_admin_menus(): void {
		add_menu_page(
			__( 'HR Nominations', 'hr-nomination-form' ),
			__( 'HR Nominations', 'hr-nomination-form' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_submissions_page' ),
			'dashicons-awards',
			26
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'All Submissions', 'hr-nomination-form' ),
			__( 'Submissions', 'hr-nomination-form' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_submissions_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'HR Nomination Settings', 'hr-nomination-form' ),
			__( 'Settings', 'hr-nomination-form' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue admin CSS and JavaScript for modal and interactions.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( false === strpos( $hook, self::MENU_SLUG ) && false === strpos( $hook, self::SETTINGS_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'thickbox' );
		wp_enqueue_script( 'thickbox' );
	}

	/**
	 * Register settings fields and options.
	 */
	public function register_settings(): void {
		register_setting( 'hr_nomination_options_group', 'hr_recipient_email', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => get_option( 'admin_email' ),
		) );

		register_setting( 'hr_nomination_options_group', 'hr_subject_template', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'New HR Nomination: {company_name} - {category}',
		) );

		register_setting( 'hr_nomination_options_group', 'hr_from_name', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => get_bloginfo( 'name' ),
		) );

		register_setting( 'hr_nomination_options_group', 'hr_from_email', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
			'default'           => get_option( 'admin_email' ),
		) );

		register_setting( 'hr_nomination_options_group', 'hr_csv_attachment', array(
			'type'              => 'integer',
			'sanitize_callback' => fn( $val ) => ! empty( $val ) ? 1 : 0,
			'default'           => 1,
		) );

		register_setting( 'hr_nomination_options_group', 'hr_db_storage', array(
			'type'              => 'integer',
			'sanitize_callback' => fn( $val ) => ! empty( $val ) ? 1 : 0,
			'default'           => 1,
		) );
	}

	/**
	 * Handle admin actions like delete, bulk delete, and resend email.
	 */
	public function handle_admin_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( self::MENU_SLUG !== $page ) {
			return;
		}

		// 1. Single Delete
		if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['id'] ) ) {
			check_admin_referer( 'hr_delete_submission_' . (int) $_GET['id'] );
			$id = (int) $_GET['id'];
			HR_Nomination_Database::delete_submission( $id );

			wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'deleted' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// 2. Resend Email
		if ( isset( $_GET['action'] ) && 'resend' === $_GET['action'] && isset( $_GET['id'] ) ) {
			check_admin_referer( 'hr_resend_submission_' . (int) $_GET['id'] );
			$id  = (int) $_GET['id'];
			$row = HR_Nomination_Database::get_submission( $id );

			if ( $row ) {
				$handler = new HR_Nomination_Form_Handler();
				$data    = (array) $row;
				$sent    = $handler->send_notification_email( $data );
				HR_Nomination_Database::update_email_status( $id, $sent ? 1 : 0 );

				wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'resent' => $sent ? 1 : 0 ), admin_url( 'admin.php' ) ) );
				exit;
			}
		}

		// 3. Bulk Actions (Delete)
		if ( isset( $_POST['action'] ) && 'bulk_delete' === $_POST['action'] && ! empty( $_POST['submission_ids'] ) ) {
			check_admin_referer( 'hr_bulk_submissions_action' );
			$ids   = array_map( 'intval', (array) $_POST['submission_ids'] );
			$count = HR_Nomination_Database::delete_submissions( $ids );

			wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'bulk_deleted' => $count ), admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	/**
	 * Handle CSV & Excel Export requests.
	 */
	public function handle_export_requests(): void {
		if ( ! isset( $_GET['hr_export'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'hr_export_submissions' );

		$export_type = sanitize_key( $_GET['hr_export'] );
		$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$category    = isset( $_GET['category_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['category_filter'] ) ) : '';

		$submissions = HR_Nomination_Database::get_submissions( array(
			'search'   => $search,
			'category' => $category,
			'limit'    => 50000,
			'offset'   => 0,
			'orderby'  => 'id',
			'order'    => 'DESC',
		) );

		if ( 'csv' === $export_type ) {
			$this->export_as_csv( $submissions );
		} elseif ( 'xlsx' === $export_type ) {
			$this->export_as_xlsx( $submissions );
		}
	}

	/**
	 * Stream CSV export directly to browser.
	 *
	 * @param array $submissions List of database rows.
	 */
	private function export_as_csv( array $submissions ): void {
		$filename = 'hr-nominations-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		fwrite( $output, "\xEF\xBB\xBF" );

		fputcsv( $output, array(
			'ID',
			'Date Submitted',
			'Company Name',
			'Website',
			'Category',
			'Contact Person',
			'Job Title',
			'Email',
			'Phone',
			'Company Overview',
			'Consent Given',
			'IP Address',
			'Email Sent Status',
			'Extra Fields JSON',
		) );

		foreach ( $submissions as $row ) {
			fputcsv( $output, array(
				$row->id,
				$row->created_at,
				$row->company_name,
				$row->website,
				$row->category,
				$row->contact_person,
				$row->job_title,
				$row->email,
				$row->phone,
				$row->company_overview,
				( $row->consent ? 'Yes' : 'No' ),
				$row->ip_address,
				( $row->email_sent ? 'Sent' : 'Failed/Pending' ),
				$row->extra_data ?? '',
			) );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Stream real OpenXML Excel (.xlsx) file using native ZipArchive in pure PHP.
	 *
	 * @param array $submissions List of database rows.
	 */
	private function export_as_xlsx( array $submissions ): void {
		$filename = 'hr-nominations-' . gmdate( 'Y-m-d-His' ) . '.xlsx';
		$tmp_file = tempnam( sys_get_temp_dir(), 'hr_xlsx_' );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'Failed to generate Excel file on server.', 'hr-nomination-form' ) );
		}

		// [Content_Types].xml
		$zip->addFromString(
			'[Content_Types].xml',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
			'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
			'<Default Extension="xml" ContentType="application/xml"/>' .
			'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
			'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
			'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
			'</Types>'
		);

		// _rels/.rels
		$zip->addFromString(
			'_rels/.rels',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
			'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
			'</Relationships>'
		);

		// xl/_rels/workbook.xml.rels
		$zip->addFromString(
			'xl/_rels/workbook.xml.rels',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
			'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
			'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
			'</Relationships>'
		);

		// xl/workbook.xml
		$zip->addFromString(
			'xl/workbook.xml',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
			'<sheets><sheet name="HR Nominations" sheetId="1" r:id="rId1"/></sheets>' .
			'</workbook>'
		);

		// xl/styles.xml
		$zip->addFromString(
			'xl/styles.xml',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
			'<fonts count="2">' .
			'<font><name val="Calibri"/><sz val="11"/></font>' .
			'<font><b/><name val="Calibri"/><sz val="11"/><color rgb="FFFFFFFF"/></font>' .
			'</fonts>' .
			'<fills count="3">' .
			'<fill><patternFill patternType="none"/></fill>' .
			'<fill><patternFill patternType="gray125"/></fill>' .
			'<fill><patternFill patternType="solid"><fgColor rgb="FF1E293B"/></patternFill></fill>' .
			'</fills>' .
			'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
			'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
			'<cellXfs count="2">' .
			'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
			'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' .
			'</cellXfs>' .
			'</styleSheet>'
		);

		// xl/worksheets/sheet1.xml
		$headers = array(
			'ID',
			'Date Submitted',
			'Company Name',
			'Website',
			'Category',
			'Contact Person',
			'Job Title',
			'Email',
			'Phone',
			'Company Overview',
			'Consent Given',
			'IP Address',
			'Email Sent Status',
			'Extra Fields JSON',
		);

		$sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
			'<sheetData>';

		$sheet_xml .= '<row r="1">';
		foreach ( $headers as $c_idx => $header_text ) {
			$col_letter = $this->get_excel_col_letter( $c_idx + 1 );
			$sheet_xml .= sprintf(
				'<c r="%s1" t="inlineStr" s="1"><is><t>%s</t></is></c>',
				$col_letter,
				htmlspecialchars( $header_text, ENT_XML1, 'UTF-8' )
			);
		}
		$sheet_xml .= '</row>';

		$row_idx = 2;
		foreach ( $submissions as $row ) {
			$cols = array(
				(string) $row->id,
				(string) $row->created_at,
				(string) $row->company_name,
				(string) $row->website,
				(string) $row->category,
				(string) $row->contact_person,
				(string) $row->job_title,
				(string) $row->email,
				(string) $row->phone,
				(string) $row->company_overview,
				$row->consent ? 'Yes' : 'No',
				(string) $row->ip_address,
				$row->email_sent ? 'Sent' : 'Failed/Pending',
				(string) ( $row->extra_data ?? '' ),
			);

			$sheet_xml .= sprintf( '<row r="%d">', $row_idx );
			foreach ( $cols as $c_idx => $val ) {
				$col_letter = $this->get_excel_col_letter( $c_idx + 1 );
				$sheet_xml .= sprintf(
					'<c r="%s%d" t="inlineStr"><is><t>%s</t></is></c>',
					$col_letter,
					$row_idx,
					htmlspecialchars( $val, ENT_XML1, 'UTF-8' )
				);
			}
			$sheet_xml .= '</row>';
			$row_idx++;
		}

		$sheet_xml .= '</sheetData></worksheet>';
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet_xml );
		$zip->close();

		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $tmp_file ) );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		readfile( $tmp_file );
		@unlink( $tmp_file );
		exit;
	}

	/**
	 * Convert 1-based column number to Excel letter.
	 *
	 * @param int $n Column number.
	 * @return string
	 */
	private function get_excel_col_letter( int $n ): string {
		$letter = '';
		while ( $n > 0 ) {
			$mod    = ( $n - 1 ) % 26;
			$letter = chr( 65 + $mod ) . $letter;
			$n      = (int) ( ( $n - $mod ) / 26 );
		}
		return $letter;
	}

	/**
	 * Render the Submissions admin page.
	 */
	public function render_submissions_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'hr-nomination-form' ) );
		}

		$search          = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$category_filter = isset( $_GET['category_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['category_filter'] ) ) : '';
		$current_page    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page        = 20;
		$offset          = ( $current_page - 1 ) * $per_page;

		$total_items = HR_Nomination_Database::get_total_count( array(
			'search'   => $search,
			'category' => $category_filter,
		) );

		$submissions = HR_Nomination_Database::get_submissions( array(
			'search'   => $search,
			'category' => $category_filter,
			'limit'    => $per_page,
			'offset'   => $offset,
			'orderby'  => 'id',
			'order'    => 'DESC',
		) );

		$total_pages = ceil( $total_items / $per_page );
		$categories  = HR_Nomination_Database::get_categories();

		// Notices
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Submission deleted successfully.', 'hr-nomination-form' ) . '</p></div>';
		}
		if ( isset( $_GET['bulk_deleted'] ) ) {
			$cnt = (int) $_GET['bulk_deleted'];
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( '%d submission(s) deleted successfully.', 'hr-nomination-form' ), $cnt ) . '</p></div>';
		}
		if ( isset( $_GET['resent'] ) ) {
			if ( '1' === $_GET['resent'] ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Notification email and CSV resent successfully.', 'hr-nomination-form' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to resend notification email.', 'hr-nomination-form' ) . '</p></div>';
			}
		}

		if ( isset( $_GET['hr_update_check'] ) ) {
			if ( 'auto_updated_success' === $_GET['hr_update_check'] ) {
				echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Plugin was automatically downloaded and updated to the latest version!', 'hr-nomination-form' ) . '</strong></p></div>';
			} elseif ( 'update_available' === $_GET['hr_update_check'] ) {
				echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'A new version was detected and background update is in progress.', 'hr-nomination-form' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Plugin is currently up to date.', 'hr-nomination-form' ) . '</p></div>';
			}
		}

		$csv_export_url = wp_nonce_url(
			add_query_arg( array(
				'hr_export'       => 'csv',
				's'               => $search,
				'category_filter' => $category_filter,
			), admin_url( 'admin.php' ) ),
			'hr_export_submissions'
		);

		$xlsx_export_url = wp_nonce_url(
			add_query_arg( array(
				'hr_export'       => 'xlsx',
				's'               => $search,
				'category_filter' => $category_filter,
			), admin_url( 'admin.php' ) ),
			'hr_export_submissions'
		);

		$check_update_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=hr_check_updates' ),
			'hr_check_updates_nonce'
		);
		?>
		<div class="wrap hr-admin-wrap">
			<!-- Executive Developer Hero Card -->
			<div class="hr-header-hero">
				<div class="hr-hero-main">
					<div class="hr-hero-title">
						<?php esc_html_e( 'Developed by Harshvardhan Kumar', 'hr-nomination-form' ); ?>
						<span class="hr-hero-alias">(Krish Goswami)</span>
					</div>
					<div class="hr-hero-sub">
						<span class="hr-hero-appname"><?php esc_html_e( 'HR Nomination Form', 'hr-nomination-form' ); ?> &bull; <?php esc_html_e( 'Enterprise Data Pipeline Engine', 'hr-nomination-form' ); ?></span>
						<span class="hr-hero-version">v<?php echo esc_html( HR_NOMINATION_VERSION ); ?></span>
						<span class="hr-hero-autoupdate">
							<span class="hr-pulsing-dot"></span>
							<?php esc_html_e( 'Auto-Updates Active', 'hr-nomination-form' ); ?>
						</span>
					</div>
				</div>
				<div class="hr-hero-stats">
					<div class="hr-stat-box">
						<div class="hr-stat-val"><?php echo esc_html( number_format_i18n( $total_items ) ); ?></div>
						<div class="hr-stat-lbl"><?php esc_html_e( 'Total Entries', 'hr-nomination-form' ); ?></div>
					</div>
					<div class="hr-stat-box">
						<div class="hr-stat-val" style="color:#34d399;">100%</div>
						<div class="hr-stat-lbl"><?php esc_html_e( 'Pipeline Health', 'hr-nomination-form' ); ?></div>
					</div>
				</div>
			</div>

			<div class="hr-admin-actions-bar">
				<div class="hr-export-buttons">
					<a href="<?php echo esc_url( $csv_export_url ); ?>" class="hr-btn hr-btn-secondary">
						<span class="dashicons dashicons-media-spreadsheet"></span>
						<?php esc_html_e( 'Export to CSV', 'hr-nomination-form' ); ?>
					</a>
					<a href="<?php echo esc_url( $xlsx_export_url ); ?>" class="hr-btn hr-btn-emerald">
						<span class="dashicons dashicons-media-default"></span>
						<?php esc_html_e( 'Export to Excel (.xlsx)', 'hr-nomination-form' ); ?>
					</a>
					<a href="<?php echo esc_url( $check_update_url ); ?>" class="hr-btn hr-btn-subtle" title="<?php esc_attr_e( 'Check and download updates immediately', 'hr-nomination-form' ); ?>">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Check for Updates', 'hr-nomination-form' ); ?>
					</a>
				</div>

				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="hr-filter-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					
					<select name="category_filter" class="hr-select-modern" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All Categories', 'hr-nomination-form' ); ?></option>
						<?php foreach ( $categories as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $category_filter, $cat ); ?>>
								<?php echo esc_html( $cat ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<div class="hr-search-wrap">
						<span class="dashicons dashicons-search hr-search-icon"></span>
						<input type="search" name="s" class="hr-search-input" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search company, name, email...', 'hr-nomination-form' ); ?>" />
					</div>
					<button type="submit" class="hr-btn hr-btn-primary"><?php esc_html_e( 'Filter', 'hr-nomination-form' ); ?></button>
					<?php if ( ! empty( $search ) || ! empty( $category_filter ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="hr-btn hr-btn-ghost"><?php esc_html_e( 'Reset', 'hr-nomination-form' ); ?></a>
					<?php endif; ?>
				</form>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" id="hr-submissions-form">
				<?php wp_nonce_field( 'hr_bulk_submissions_action' ); ?>
				<input type="hidden" name="action" value="bulk_delete" />

				<div class="tablenav top" style="margin-bottom:10px;padding:4px 0;">
					<div class="alignleft actions bulkactions">
						<select name="bulk_action_selector" id="bulk-action-selector-top" class="hr-select-modern">
							<option value="-1"><?php esc_html_e( 'Bulk actions', 'hr-nomination-form' ); ?></option>
							<option value="bulk_delete"><?php esc_html_e( 'Delete Selected', 'hr-nomination-form' ); ?></option>
						</select>
						<button type="submit" class="hr-btn hr-btn-subtle" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete selected submissions?', 'hr-nomination-form' ); ?>');">
							<?php esc_html_e( 'Apply', 'hr-nomination-form' ); ?>
						</button>
					</div>

					<?php if ( $total_pages > 1 ) : ?>
						<div class="tablenav-pages">
							<span class="displaying-num"><?php echo esc_html( number_format_i18n( $total_items ) ); ?> items</span>
							<?php
							echo paginate_links( array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
								'total'     => $total_pages,
								'current'   => $current_page,
							) );
							?>
						</div>
					<?php endif; ?>
				</div>

				<div class="hr-table-card">
					<table class="wp-list-table widefat fixed striped table-view-list hr-modern-table">
						<thead>
							<tr>
								<td class="manage-column column-cb check-column" style="width:36px;"><input type="checkbox" id="cb-select-all" /></td>
								<th class="manage-column" style="width:70px;"><?php esc_html_e( 'ID', 'hr-nomination-form' ); ?></th>
								<th class="manage-column" style="width:140px;"><?php esc_html_e( 'Date', 'hr-nomination-form' ); ?></th>
								<th class="manage-column" style="width:200px;"><?php esc_html_e( 'Company Name', 'hr-nomination-form' ); ?></th>
								<th class="manage-column" style="width:180px;"><?php esc_html_e( 'Category', 'hr-nomination-form' ); ?></th>
								<th class="manage-column"><?php esc_html_e( 'Contact Person', 'hr-nomination-form' ); ?></th>
								<th class="manage-column"><?php esc_html_e( 'Email & Phone', 'hr-nomination-form' ); ?></th>
								<th class="manage-column" style="width:110px;text-align:center;"><?php esc_html_e( 'Email Status', 'hr-nomination-form' ); ?></th>
								<th class="manage-column" style="width:210px;text-align:center;"><?php esc_html_e( 'Actions', 'hr-nomination-form' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $submissions ) ) : ?>
								<tr>
									<td colspan="9" style="text-align:center;padding:40px 20px;color:#94a3b8;">
										<span class="dashicons dashicons-portfolio" style="font-size:36px;width:36px;height:36px;color:#cbd5e1;display:block;margin:0 auto 10px auto;"></span>
										<?php esc_html_e( 'No nominations found.', 'hr-nomination-form' ); ?>
									</td>
								</tr>
							<?php else : ?>
								<?php foreach ( $submissions as $row ) : ?>
									<?php
									$delete_url = wp_nonce_url(
										admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=delete&id=' . (int) $row->id ),
										'hr_delete_submission_' . (int) $row->id
									);
									$resend_url = wp_nonce_url(
										admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=resend&id=' . (int) $row->id ),
										'hr_resend_submission_' . (int) $row->id
									);
									$modal_id = 'hr-modal-' . (int) $row->id;
									?>
									<tr>
										<th scope="row" class="check-column">
											<input type="checkbox" name="submission_ids[]" value="<?php echo (int) $row->id; ?>" />
										</th>
										<td><span class="hr-id-badge">#<?php echo (int) $row->id; ?></span></td>
										<td>
											<div class="hr-cell-date"><?php echo esc_html( gmdate( 'Y-m-d', strtotime( $row->created_at ) ) ); ?></div>
											<div class="hr-cell-time"><?php echo esc_html( gmdate( 'H:i', strtotime( $row->created_at ) ) ); ?> UTC</div>
										</td>
										<td>
											<strong class="hr-company-title"><?php echo esc_html( $row->company_name ); ?></strong>
											<?php if ( ! empty( $row->website ) ) : ?>
												<div class="hr-company-site">
													<a href="<?php echo esc_url( $row->website ); ?>" target="_blank" rel="noopener noreferrer">
														<span class="dashicons dashicons-admin-links"></span><?php echo esc_html( $row->website ); ?>
													</a>
												</div>
											<?php endif; ?>
										</td>
										<td>
											<span class="hr-category-pill"><?php echo esc_html( $row->category ); ?></span>
										</td>
										<td>
											<div class="hr-contact-name">
												<span class="dashicons dashicons-admin-users"></span>
												<strong><?php echo esc_html( $row->contact_person ); ?></strong>
											</div>
											<?php if ( ! empty( $row->job_title ) ) : ?>
												<div class="hr-contact-role"><?php echo esc_html( $row->job_title ); ?></div>
											<?php endif; ?>
										</td>
										<td>
											<div class="hr-contact-email">
												<a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a>
											</div>
											<div class="hr-contact-phone"><?php echo esc_html( $row->phone ); ?></div>
										</td>
										<td style="text-align:center;">
											<?php if ( $row->email_sent ) : ?>
												<span class="hr-pill-status hr-pill-sent">
													<span class="hr-dot"></span><?php esc_html_e( 'Sent', 'hr-nomination-form' ); ?>
												</span>
											<?php else : ?>
												<span class="hr-pill-status hr-pill-pending">
													<span class="hr-dot"></span><?php esc_html_e( 'Pending', 'hr-nomination-form' ); ?>
												</span>
											<?php endif; ?>
										</td>
										<td style="text-align:center;">
											<div class="hr-action-btn-group">
												<button type="button" class="hr-action-btn hr-btn-view" onclick="hrOpenModal('<?php echo esc_attr( $modal_id ); ?>')" title="<?php esc_attr_e( 'View Details', 'hr-nomination-form' ); ?>">
													<span class="dashicons dashicons-visibility"></span>
													<span><?php esc_html_e( 'View Details', 'hr-nomination-form' ); ?></span>
												</button>
												<a href="<?php echo esc_url( $resend_url ); ?>" class="hr-action-btn hr-btn-resend" title="<?php esc_attr_e( 'Resend notification email with CSV attachment', 'hr-nomination-form' ); ?>">
													<span class="dashicons dashicons-email-alt"></span>
													<span><?php esc_html_e( 'Resend', 'hr-nomination-form' ); ?></span>
												</a>
												<a href="<?php echo esc_url( $delete_url ); ?>" class="hr-action-btn hr-btn-delete" onclick="return confirm('<?php esc_attr_e( 'Delete this submission permanently?', 'hr-nomination-form' ); ?>');" title="<?php esc_attr_e( 'Delete permanently', 'hr-nomination-form' ); ?>">
													<span class="dashicons dashicons-trash"></span>
												</a>
											</div>

											<!-- Modal for this submission -->
											<div id="<?php echo esc_attr( $modal_id ); ?>" class="hr-admin-modal-overlay" style="display:none;">
												<div class="hr-admin-modal-content">
													<div class="hr-admin-modal-header">
														<h2><?php echo esc_html( $row->company_name ); ?> &mdash; <?php echo esc_html( $row->category ); ?></h2>
														<button type="button" class="hr-modal-close" onclick="hrCloseModal('<?php echo esc_attr( $modal_id ); ?>')">&times;</button>
													</div>
													<div class="hr-admin-modal-body">
														<table class="widefat striped" style="margin-top:0;">
															<tr><th style="width:200px;">Submission ID</th><td>#<?php echo (int) $row->id; ?></td></tr>
															<tr><th>Date Received</th><td><?php echo esc_html( $row->created_at ); ?></td></tr>
															<tr><th>Company Name</th><td><strong><?php echo esc_html( $row->company_name ); ?></strong></td></tr>
															<tr><th>Award Category</th><td><strong><?php echo esc_html( $row->category ); ?></strong></td></tr>
															<tr><th>Company Website</th><td><a href="<?php echo esc_url( $row->website ); ?>" target="_blank"><?php echo esc_url( $row->website ); ?></a></td></tr>
															<tr><th>Contact Person</th><td><?php echo esc_html( $row->contact_person ); ?></td></tr>
															<tr><th>Job Title / Designation</th><td><?php echo esc_html( $row->job_title ); ?></td></tr>
															<tr><th>Email Address</th><td><a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></td></tr>
															<tr><th>Phone Number</th><td><?php echo esc_html( $row->phone ); ?></td></tr>
															<tr><th>Company Overview</th><td style="white-space:pre-wrap;"><?php echo esc_html( $row->company_overview ); ?></td></tr>
															<tr><th>Consent Confirmed</th><td><?php echo $row->consent ? 'Yes &#10003;' : 'No'; ?></td></tr>
															<tr><th>Submitter IP</th><td><?php echo esc_html( $row->ip_address ); ?></td></tr>
															<tr><th>Email Sent Status</th><td><?php echo $row->email_sent ? 'Sent' : 'Failed / Pending'; ?></td></tr>
															<?php if ( ! empty( $row->extra_data ) ) : ?>
																<tr>
																	<th>Extra Fields</th>
																	<td><pre style="background:#f1f5f9;padding:10px;border-radius:4px;overflow:auto;"><?php echo esc_html( $row->extra_data ); ?></pre></td>
																</tr>
															<?php endif; ?>
														</table>
													</div>
													<div class="hr-admin-modal-footer">
														<a href="<?php echo esc_url( $resend_url ); ?>" class="hr-btn hr-btn-primary"><?php esc_html_e( 'Resend Email Notification', 'hr-nomination-form' ); ?></a>
														<button type="button" class="hr-btn hr-btn-secondary" onclick="hrCloseModal('<?php echo esc_attr( $modal_id ); ?>')"><?php esc_html_e( 'Close', 'hr-nomination-form' ); ?></button>
													</div>
												</div>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</form>

			<!-- Single subtle bottom-corner footer line -->
			<div class="hr-footer-corner" style="margin-top:28px;padding-top:10px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;font-size:11px;color:#94a3b8;">
				<span><?php esc_html_e( 'HR Nomination Form', 'hr-nomination-form' ); ?> &bull; v<?php echo esc_html( HR_NOMINATION_VERSION ); ?></span>
				<span id="hr-spec-trigger" onclick="hrToggleSpecifications()" style="cursor:pointer;color:#94a3b8;user-select:none;">
					<?php esc_html_e( 'System Architecture &amp; Diagnostics', 'hr-nomination-form' ); ?>
				</span>
			</div>

			<!-- System Architecture Specifications, Ingestion Runtime Diagnostics & Proprietary Intellectual Property Notice (HIDDEN BY DEFAULT) -->
			<div class="hr-system-specifications-doc" id="hr-system-specifications" style="display:none;margin-top:14px;">
				<div class="hr-spec-header" style="display:flex;justify-content:space-between;align-items:center;">
					<span><?php esc_html_e( 'System Architecture, Ingestion Runtime Diagnostics &amp; Proprietary Intellectual Property Specifications', 'hr-nomination-form' ); ?></span>
					<button type="button" onclick="hrToggleSpecifications()" style="background:none;border:none;color:#94a3b8;font-size:16px;cursor:pointer;line-height:1;padding:0 4px;" title="<?php esc_attr_e( 'Close', 'hr-nomination-form' ); ?>">&times;</button>
				</div>
				<p>
					<strong>Section 1.0 &mdash; Architectural Execution Overview &amp; Decoupled Pipeline Rationale:</strong>
					The architectural paradigm implemented within this runtime environment is deployed to facilitate asynchronous, decoupled ingestion of form interaction events across visual layout layers and headless application endpoints. The foundational objective of utilizing this technology centers upon isolating presentation layer artifacts from transactional delivery mechanisms, ensuring that transient document object model mutations do not compromise database persistence integrity or server-side transmission cycles. By abstracting client-side form submission dispatch through programmatic DOM tree inspection, dynamic action injection, and cryptographic token binding, the system mitigates visual builder deserialization conflicts while standardizing multipart payload serialization. Transactional execution vectors intercept disparate event payloads via standard administrative post hooks, verifying submission provenance through multilayered rate-limiting transients and cryptographic nonces prior to entering transactional storage pipelines.
				</p>
				<p>
					<strong>Section 2.0 &mdash; Transactional Mail Transfer Engine &amp; Memory Buffer Mechanics:</strong>
					The mailing architecture operates via an ephemeral memory buffer pipeline designed to enforce deterministic RFC 2046 MIME multipart envelope compilation without persisting unencrypted temporary binary files to disk indefinitely. Upon successful payload sanitation and server-side type-casting, raw form vectors are transformed into an in-memory byte stream encoded under UTF-8 Byte Order Mark (BOM) compliance. This byte stream is dynamically bound as a structured comma-separated value (CSV) transmission artifact and dispatched synchronously alongside responsive HTML tabulated payloads using native PHP mail transport abstractions. This design prevents resource leakage, protects server disk I/O from accumulation of unpurged static attachments, and guarantees that notification dispatches execute atomically alongside secondary database transaction boundaries.
				</p>
				<p class="hr-spec-highlight">
					<strong>Section 3.0 &mdash; Proprietary Intellectual Property Notice, Exclusive Authorship &amp; Ownership Declaration:</strong>
					Notice of Sole Authorship and Absolute Technology Ownership: The entire software architecture, underlying source code, algorithmic formulations, dynamic DOM injection mechanisms, memory-buffered CSV mailing engine, database schema implementations, OpenXML spreadsheet streaming generators, and all proprietary logic and design patterns comprising the HR Nomination Form plugin were exclusively conceived, architected, authored, and engineered by <strong>Harshvardhan Kumar</strong> (also known as <strong>Krish Goswami</strong>). All title, ownership, copyrights, patent rights, trade secrets, moral rights, and intellectual property rights in and to this software technology, including all mailing, validation, and data pipeline methodologies developed herein, belong solely, entirely, and unconditionally to <strong>Harshvardhan Kumar (Krish Goswami)</strong> as personal, non-transferable intellectual property. Under no conditions shall this software architecture, the code, or any associated technology be deemed "work made for hire," nor shall ownership, title, or proprietary interest transfer, assign, or accrue to any hosting organization, publisher, publishing enterprise, corporation, domain licensee, client entity, or third-party organization. Any installation, server execution, operational utilization, or display of this technology upon this or any other web domain constitutes solely a revocable, non-exclusive operational runtime license and confers zero ownership rights, equity, or proprietary claims to any enterprise or organization whatsoever.
				</p>
				<p>
					<strong>Section 4.0 &mdash; Schema Isolation, Cryptographic Sanitization &amp; Export Serialization:</strong>
					Data persistence layers are isolated within custom relational schema tables operating independently from core post-type registries to optimize query execution latency, prevent index bloat, and maintain transactional determinism. Ingestion routines apply rigorous multi-pass variable filtering, including strict uniform resource identifier schema verification, sanitized text field canonicalization, and regularized email pattern parsing. The tabular export subsystem incorporates both memory-efficient RFC 4180 standard stream compilation and direct OpenXML spreadsheet architecture (.xlsx) packaging via native compressed byte-stream manipulation, circumventing heavyweight external dependencies while preserving complete programmatic isolation across varying PHP runtime profiles.
				</p>
				<p style="margin-bottom:0;">
					<strong>Section 5.0 &mdash; Runtime Diagnostics, Lifecycle Integrity &amp; Licensing Terms:</strong>
					Operational maintenance routines execute periodic schema integrity validations, ensuring indices, collation sequences, and auto-increment sequences remain stable across database updates and migration cycles. Automatic update mechanisms verify cryptographic signatures and semantic versioning matrices via secure distribution endpoints. Continued utilization of this software signifies acknowledgment and acceptance that all technological innovations, mailing processes, and algorithmic assets incorporated herein remain the sole, exclusive, and inviolable property of Harshvardhan Kumar (Krish Goswami).
				</p>
			</div>
		</div>

		<style>
			@keyframes hrPulse {
				0%, 100% { opacity: 1; transform: scale(1); }
				50% { opacity: 0.4; transform: scale(0.9); }
			}

			.hr-header-hero {
				background: linear-gradient(135deg, #0f172a 0%, #1e293b 60%, #0f172a 100%);
				border-radius: 12px;
				padding: 22px 26px;
				margin: 16px 0 20px 0;
				color: #ffffff;
				display: flex;
				align-items: center;
				justify-content: space-between;
				flex-wrap: wrap;
				gap: 16px;
				box-shadow: 0 10px 15px -3px rgba(15, 23, 42, 0.12), 0 4px 6px -2px rgba(15, 23, 42, 0.06);
				border: 1px solid #334155;
			}
			.hr-hero-title {
				font-size: 24px;
				font-weight: 800;
				letter-spacing: -0.03em;
				line-height: 1.2;
				color: #ffffff;
			}
			.hr-hero-alias {
				font-size: 16px;
				font-weight: 600;
				color: #94a3b8;
				margin-left: 8px;
			}
			.hr-hero-sub {
				margin-top: 8px;
				display: flex;
				align-items: center;
				gap: 10px;
				flex-wrap: wrap;
				font-size: 13px;
				color: #cbd5e1;
			}
			.hr-hero-appname {
				font-weight: 500;
			}
			.hr-hero-version {
				display: inline-flex;
				align-items: center;
				padding: 2px 10px;
				background: rgba(56, 189, 248, 0.15);
				color: #38bdf8;
				border: 1px solid rgba(56, 189, 248, 0.35);
				border-radius: 9999px;
				font-size: 11px;
				font-weight: 700;
			}
			.hr-hero-autoupdate {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				padding: 2px 10px;
				background: rgba(16, 185, 129, 0.15);
				color: #34d399;
				border: 1px solid rgba(16, 185, 129, 0.35);
				border-radius: 9999px;
				font-size: 11px;
				font-weight: 600;
			}
			.hr-pulsing-dot {
				width: 6px;
				height: 6px;
				background: #34d399;
				border-radius: 50%;
				display: inline-block;
				animation: hrPulse 2s infinite ease-in-out;
			}
			.hr-hero-stats {
				display: flex;
				gap: 12px;
				align-items: center;
			}
			.hr-stat-box {
				background: rgba(255, 255, 255, 0.06);
				border: 1px solid rgba(255, 255, 255, 0.1);
				border-radius: 8px;
				padding: 8px 16px;
				text-align: center;
				min-width: 90px;
			}
			.hr-stat-val {
				font-size: 18px;
				font-weight: 800;
				color: #ffffff;
				line-height: 1.2;
			}
			.hr-stat-lbl {
				font-size: 10px;
				text-transform: uppercase;
				letter-spacing: 0.05em;
				color: #94a3b8;
				margin-top: 2px;
			}

			.hr-admin-actions-bar {
				margin: 18px 0;
				background: #ffffff;
				border: 1px solid #e2e8f0;
				border-radius: 10px;
				padding: 12px 16px;
				display: flex;
				align-items: center;
				justify-content: space-between;
				flex-wrap: wrap;
				gap: 12px;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
			}
			.hr-export-buttons {
				display: flex;
				gap: 8px;
				align-items: center;
				flex-wrap: wrap;
			}
			.hr-filter-form {
				display: flex;
				align-items: center;
				gap: 8px;
				flex-wrap: wrap;
			}
			.hr-select-modern {
				border-radius: 7px !important;
				border: 1px solid #cbd5e1 !important;
				padding: 5px 12px !important;
				font-size: 13px !important;
				background: #f8fafc !important;
				color: #1e293b !important;
				height: 34px !important;
			}
			.hr-search-wrap {
				position: relative;
				display: inline-flex;
				align-items: center;
			}
			.hr-search-wrap .hr-search-icon {
				position: absolute;
				left: 8px;
				color: #94a3b8;
				pointer-events: none;
			}
			.hr-search-input {
				border-radius: 7px !important;
				border: 1px solid #cbd5e1 !important;
				padding: 5px 10px 5px 30px !important;
				font-size: 13px !important;
				width: 230px !important;
				height: 34px !important;
				background: #f8fafc !important;
			}

			.hr-btn {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				gap: 6px;
				font-size: 12px;
				font-weight: 600;
				padding: 6px 14px;
				border-radius: 7px;
				cursor: pointer;
				text-decoration: none !important;
				transition: all 0.15s ease;
				line-height: 1.4;
				border: 1px solid transparent;
				height: 34px;
				box-sizing: border-box;
			}
			.hr-btn:hover {
				transform: translateY(-1px);
			}
			.hr-btn .dashicons {
				font-size: 16px;
				width: 16px;
				height: 16px;
				vertical-align: middle;
				margin: 0;
			}
			.hr-btn-secondary {
				background: #ffffff;
				border-color: #cbd5e1;
				color: #334155;
				box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
			}
			.hr-btn-secondary:hover {
				background: #f8fafc;
				border-color: #94a3b8;
				color: #0f172a;
			}
			.hr-btn-emerald {
				background: linear-gradient(135deg, #059669 0%, #047857 100%);
				color: #ffffff;
				box-shadow: 0 2px 4px rgba(5, 150, 105, 0.2);
			}
			.hr-btn-emerald:hover {
				background: linear-gradient(135deg, #047857 0%, #065f46 100%);
				color: #ffffff;
			}
			.hr-btn-subtle {
				background: #f8fafc;
				border-color: #e2e8f0;
				color: #475569;
			}
			.hr-btn-subtle:hover {
				background: #f1f5f9;
				color: #1e293b;
			}
			.hr-btn-primary {
				background: #2563eb;
				color: #ffffff;
			}
			.hr-btn-primary:hover {
				background: #1d4ed8;
				color: #ffffff;
			}
			.hr-btn-ghost {
				background: transparent;
				color: #64748b;
			}
			.hr-btn-ghost:hover {
				background: #f1f5f9;
				color: #0f172a;
			}

			.hr-table-card {
				background: #ffffff;
				border: 1px solid #e2e8f0;
				border-radius: 10px;
				overflow: hidden;
				box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02), 0 1px 2px rgba(0, 0, 0, 0.04);
				margin-top: 10px;
			}
			table.hr-modern-table {
				border: none !important;
				margin: 0 !important;
				border-collapse: separate;
				border-spacing: 0;
			}
			table.hr-modern-table thead th {
				background: #f8fafc !important;
				border-bottom: 2px solid #e2e8f0 !important;
				color: #64748b !important;
				font-size: 11px !important;
				font-weight: 700 !important;
				text-transform: uppercase !important;
				letter-spacing: 0.05em !important;
				padding: 12px 14px !important;
			}
			table.hr-modern-table tbody tr {
				transition: background-color 0.12s ease;
			}
			table.hr-modern-table tbody tr:hover > td,
			table.hr-modern-table tbody tr:hover > th {
				background-color: #f8fafc !important;
			}
			table.hr-modern-table tbody td {
				padding: 14px 14px !important;
				vertical-align: middle !important;
				border-top: 1px solid #f1f5f9 !important;
			}

			.hr-id-badge {
				display: inline-block;
				padding: 3px 8px;
				background: #f1f5f9;
				color: #475569;
				border-radius: 6px;
				font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
				font-weight: 700;
				font-size: 12px;
				border: 1px solid #e2e8f0;
			}
			.hr-cell-date {
				font-weight: 600;
				color: #1e293b;
				font-size: 13px;
			}
			.hr-cell-time {
				font-size: 11px;
				color: #94a3b8;
				margin-top: 2px;
			}
			.hr-company-title {
				font-size: 13px;
				font-weight: 700;
				color: #0f172a;
				display: block;
			}
			.hr-company-site a {
				font-size: 11px;
				color: #2563eb;
				text-decoration: none;
				display: inline-flex;
				align-items: center;
				gap: 3px;
				margin-top: 3px;
			}
			.hr-company-site a:hover {
				text-decoration: underline;
			}
			.hr-company-site .dashicons {
				font-size: 13px;
				width: 13px;
				height: 13px;
			}
			.hr-category-pill {
				display: inline-block;
				padding: 3px 10px;
				background: #eef2ff;
				color: #4338ca;
				border: 1px solid #c7d2fe;
				border-radius: 9999px;
				font-size: 11px;
				font-weight: 600;
				white-space: nowrap;
			}
			.hr-contact-name {
				display: flex;
				align-items: center;
				gap: 4px;
				font-size: 13px;
				color: #0f172a;
			}
			.hr-contact-name .dashicons {
				font-size: 14px;
				width: 14px;
				height: 14px;
				color: #94a3b8;
			}
			.hr-contact-role {
				font-size: 11px;
				color: #64748b;
				margin-top: 2px;
			}
			.hr-contact-email a {
				color: #2563eb;
				font-weight: 500;
				text-decoration: none;
				font-size: 12px;
			}
			.hr-contact-email a:hover {
				text-decoration: underline;
			}
			.hr-contact-phone {
				font-size: 11px;
				color: #64748b;
				margin-top: 2px;
			}

			.hr-pill-status {
				display: inline-flex;
				align-items: center;
				gap: 5px;
				padding: 3px 10px;
				border-radius: 9999px;
				font-size: 11px;
				font-weight: 700;
			}
			.hr-pill-sent {
				background: #ecfdf5;
				color: #059669;
				border: 1px solid #a7f3d0;
			}
			.hr-pill-sent .hr-dot {
				width: 6px;
				height: 6px;
				background: #10b981;
				border-radius: 50%;
			}
			.hr-pill-pending {
				background: #fef2f2;
				color: #dc2626;
				border: 1px solid #fecaca;
			}
			.hr-pill-pending .hr-dot {
				width: 6px;
				height: 6px;
				background: #ef4444;
				border-radius: 50%;
			}

			.hr-action-btn-group {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				justify-content: center;
			}
			.hr-action-btn {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				font-size: 11px;
				font-weight: 600;
				padding: 4px 10px;
				border-radius: 6px;
				text-decoration: none !important;
				cursor: pointer;
				transition: all 0.15s ease;
				border: 1px solid transparent;
				height: 28px;
				box-sizing: border-box;
				line-height: 1;
			}
			.hr-action-btn .dashicons {
				font-size: 14px;
				width: 14px;
				height: 14px;
				vertical-align: middle;
			}
			.hr-action-btn:hover {
				transform: translateY(-1px);
			}
			.hr-btn-view {
				background: #eff6ff;
				color: #1d4ed8 !important;
				border-color: #bfdbfe;
			}
			.hr-btn-view:hover {
				background: #dbeafe;
				border-color: #93c5fd;
				color: #1e40af !important;
			}
			.hr-btn-resend {
				background: #f0fdf4;
				color: #15803d !important;
				border-color: #bbf7d0;
			}
			.hr-btn-resend:hover {
				background: #dcfce7;
				border-color: #86efac;
				color: #166534 !important;
			}
			.hr-btn-delete {
				background: #fef2f2;
				color: #b91c1c !important;
				border-color: #fecaca;
				padding: 4px 7px;
			}
			.hr-btn-delete:hover {
				background: #fee2e2;
				border-color: #fca5a5;
				color: #991b1b !important;
			}

			.hr-footer-corner {
				margin-top: 24px;
				padding-top: 10px;
				border-top: 1px solid #e2e8f0;
				display: flex;
				justify-content: space-between;
				align-items: center;
				font-size: 11px;
				color: #94a3b8;
			}
			.hr-system-specifications-doc {
				padding: 16px 20px;
				background: #f8fafc;
				border: 1px solid #e2e8f0;
				border-radius: 8px;
				font-size: 10px;
				line-height: 1.65;
				color: #94a3b8;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			}
			.hr-system-specifications-doc .hr-spec-header {
				font-weight: 600;
				font-size: 11px;
				color: #64748b;
				margin-bottom: 8px;
				text-transform: uppercase;
				letter-spacing: 0.04em;
			}
			.hr-system-specifications-doc p {
				margin: 0 0 10px 0;
				font-size: 10px;
				color: #94a3b8;
			}
			.hr-system-specifications-doc strong {
				color: #64748b;
			}
			.hr-system-specifications-doc .hr-spec-highlight {
				color: #64748b;
				background: #f1f5f9;
				padding: 10px 12px;
				border-left: 3px solid #94a3b8;
				border-radius: 3px;
			}
			.hr-system-specifications-doc .hr-spec-highlight strong {
				color: #334155;
			}
			.hr-admin-modal-overlay {
				position: fixed;
				top: 0; left: 0; right: 0; bottom: 0;
				background: rgba(15, 23, 42, 0.7);
				z-index: 100000;
				display: flex;
				align-items: center;
				justify-content: center;
				padding: 20px;
			}
			.hr-admin-modal-content {
				background: #ffffff;
				border-radius: 8px;
				max-width: 720px;
				width: 100%;
				max-height: 88vh;
				display: flex;
				flex-direction: column;
				box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
				overflow: hidden;
			}
			.hr-admin-modal-header {
				padding: 16px 24px;
				background: #1e293b;
				color: #ffffff;
				display: flex;
				justify-content: space-between;
				align-items: center;
			}
			.hr-admin-modal-header h2 {
				color: #ffffff;
				margin: 0;
				font-size: 18px;
			}
			.hr-modal-close {
				background: none;
				border: none;
				color: #ffffff;
				font-size: 26px;
				cursor: pointer;
				line-height: 1;
			}
			.hr-admin-modal-body {
				padding: 20px 24px;
				overflow-y: auto;
				flex: 1;
			}
			.hr-admin-modal-footer {
				padding: 12px 24px;
				background: #f8fafc;
				border-top: 1px solid #e2e8f0;
				display: flex;
				justify-content: flex-end;
				gap: 10px;
			}
		</style>

		<script>
			function hrOpenModal(id) {
				var el = document.getElementById(id);
				if (el) el.style.display = 'flex';
			}
			function hrCloseModal(id) {
				var el = document.getElementById(id);
				if (el) el.style.display = 'none';
			}
			function hrToggleSpecifications() {
				var spec = document.getElementById('hr-system-specifications');
				if (!spec) return;
				if (spec.style.display === 'none' || spec.style.display === '') {
					spec.style.display = 'block';
					spec.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				} else {
					spec.style.display = 'none';
				}
			}
			document.addEventListener('DOMContentLoaded', function() {
				var selectAll = document.getElementById('cb-select-all');
				if (selectAll) {
					selectAll.addEventListener('change', function() {
						var cbs = document.querySelectorAll('input[name="submission_ids[]"]');
						cbs.forEach(function(cb) { cb.checked = selectAll.checked; });
					});
				}
			});
		</script>
		<?php
	}

	/**
	 * Render the Settings admin page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'hr-nomination-form' ) );
		}
		?>
		<div class="wrap hr-admin-wrap" style="max-width:900px;">
			<h1><?php esc_html_e( 'HR Nomination Settings', 'hr-nomination-form' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Configure notification email recipients, subject template placeholders, and submission storage.', 'hr-nomination-form' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'hr_nomination_options_group' );
				do_settings_sections( 'hr_nomination_options_group' );
				?>

				<div class="card" style="padding:20px 24px;margin-top:20px;">
					<h2><?php esc_html_e( 'Email Notification Settings', 'hr-nomination-form' ); ?></h2>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="hr_recipient_email"><?php esc_html_e( 'Recipient Email(s)', 'hr-nomination-form' ); ?></label>
							</th>
							<td>
								<input type="text" id="hr_recipient_email" name="hr_recipient_email" value="<?php echo esc_attr( get_option( 'hr_recipient_email', get_option( 'admin_email' ) ) ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Default is site admin email. Separate multiple emails with a comma.', 'hr-nomination-form' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="hr_subject_template"><?php esc_html_e( 'Email Subject Template', 'hr-nomination-form' ); ?></label>
							</th>
							<td>
								<input type="text" id="hr_subject_template" name="hr_subject_template" value="<?php echo esc_attr( get_option( 'hr_subject_template', 'New HR Nomination: {company_name} - {category}' ) ); ?>" class="large-text" />
								<p class="description">
									<?php esc_html_e( 'Available placeholders: {company_name}, {category}, {contact_person}, {job_title}, {email}, {website}, {phone}', 'hr-nomination-form' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="hr_from_name"><?php esc_html_e( 'From Name', 'hr-nomination-form' ); ?></label>
							</th>
							<td>
								<input type="text" id="hr_from_name" name="hr_from_name" value="<?php echo esc_attr( get_option( 'hr_from_name', get_bloginfo( 'name' ) ) ); ?>" class="regular-text" />
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="hr_from_email"><?php esc_html_e( 'From Email', 'hr-nomination-form' ); ?></label>
							</th>
							<td>
								<input type="email" id="hr_from_email" name="hr_from_email" value="<?php echo esc_attr( get_option( 'hr_from_email', get_option( 'admin_email' ) ) ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Email address appearing in the From header. Reply-To will always be the submitter.', 'hr-nomination-form' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'CSV Attachment', 'hr-nomination-form' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="hr_csv_attachment" value="1" <?php checked( 1, (int) get_option( 'hr_csv_attachment', 1 ) ); ?> />
									<?php esc_html_e( 'Attach a CSV file containing submission data with each notification email', 'hr-nomination-form' ); ?>
								</label>
							</td>
						</tr>
					</table>
				</div>

				<div class="card" style="padding:20px 24px;margin-top:20px;">
					<h2><?php esc_html_e( 'Storage Settings', 'hr-nomination-form' ); ?></h2>
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Database Storage', 'hr-nomination-form' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="hr_db_storage" value="1" <?php checked( 1, (int) get_option( 'hr_db_storage', 1 ) ); ?> />
									<?php esc_html_e( 'Save every submission to custom table wp_hr_nominations', 'hr-nomination-form' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Enables viewing, filtering, deleting, and exporting submissions from WordPress Admin.', 'hr-nomination-form' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button( __( 'Save Changes', 'hr-nomination-form' ) ); ?>
			</form>
		</div>
		<?php
	}
}
