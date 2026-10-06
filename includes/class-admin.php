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
			if ( 'update_available' === $_GET['hr_update_check'] ) {
				echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'A new version is available on GitHub!', 'hr-nomination-form' ) . '</strong> <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'View and update on Plugins page &rarr;', 'hr-nomination-form' ) . '</a></p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Plugin is currently up to date with the latest GitHub release.', 'hr-nomination-form' ) . '</p></div>';
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
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'HR Nominations', 'hr-nomination-form' ); ?>
				<span class="count" style="font-size:14px;color:#6b7280;font-weight:400;margin-left:8px;">(<?php echo esc_html( number_format_i18n( $total_items ) ); ?> <?php esc_html_e( 'total', 'hr-nomination-form' ); ?>)</span>
			</h1>

			<div class="hr-admin-actions-bar" style="margin:16px 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
				<div class="hr-export-buttons" style="display:flex;gap:8px;align-items:center;">
					<a href="<?php echo esc_url( $csv_export_url ); ?>" class="button button-secondary">
						<span class="dashicons dashicons-media-spreadsheet" style="vertical-align:middle;margin-right:3px;"></span>
						<?php esc_html_e( 'Export to CSV', 'hr-nomination-form' ); ?>
					</a>
					<a href="<?php echo esc_url( $xlsx_export_url ); ?>" class="button button-primary" style="background:#0f766e;border-color:#0d5c56;">
						<span class="dashicons dashicons-media-default" style="vertical-align:middle;margin-right:3px;"></span>
						<?php esc_html_e( 'Export to Excel (.xlsx)', 'hr-nomination-form' ); ?>
					</a>
					<a href="<?php echo esc_url( $check_update_url ); ?>" class="button" title="<?php esc_attr_e( 'Check GitHub for plugin updates immediately', 'hr-nomination-form' ); ?>">
						<span class="dashicons dashicons-update" style="vertical-align:middle;margin-right:3px;"></span>
						<?php esc_html_e( 'Check for Updates', 'hr-nomination-form' ); ?>
					</a>
				</div>

				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;gap:8px;">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					
					<select name="category_filter" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All Categories', 'hr-nomination-form' ); ?></option>
						<?php foreach ( $categories as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $category_filter, $cat ); ?>>
								<?php echo esc_html( $cat ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search company, name, email...', 'hr-nomination-form' ); ?>" style="width:240px;" />
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'hr-nomination-form' ); ?></button>
					<?php if ( ! empty( $search ) || ! empty( $category_filter ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'hr-nomination-form' ); ?></a>
					<?php endif; ?>
				</form>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" id="hr-submissions-form">
				<?php wp_nonce_field( 'hr_bulk_submissions_action' ); ?>
				<input type="hidden" name="action" value="bulk_delete" />

				<div class="tablenav top" style="margin-bottom:8px;">
					<div class="alignleft actions bulkactions">
						<select name="bulk_action_selector" id="bulk-action-selector-top">
							<option value="-1"><?php esc_html_e( 'Bulk actions', 'hr-nomination-form' ); ?></option>
							<option value="bulk_delete"><?php esc_html_e( 'Delete Selected', 'hr-nomination-form' ); ?></option>
						</select>
						<button type="submit" class="button action" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete selected submissions?', 'hr-nomination-form' ); ?>');">
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

				<table class="wp-list-table widefat fixed striped table-view-list">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column" style="width:30px;"><input type="checkbox" id="cb-select-all" /></td>
							<th class="manage-column" style="width:60px;"><?php esc_html_e( 'ID', 'hr-nomination-form' ); ?></th>
							<th class="manage-column" style="width:130px;"><?php esc_html_e( 'Date', 'hr-nomination-form' ); ?></th>
							<th class="manage-column" style="width:180px;"><?php esc_html_e( 'Company Name', 'hr-nomination-form' ); ?></th>
							<th class="manage-column" style="width:160px;"><?php esc_html_e( 'Category', 'hr-nomination-form' ); ?></th>
							<th class="manage-column"><?php esc_html_e( 'Contact Person', 'hr-nomination-form' ); ?></th>
							<th class="manage-column"><?php esc_html_e( 'Email & Phone', 'hr-nomination-form' ); ?></th>
							<th class="manage-column" style="width:100px;"><?php esc_html_e( 'Email Status', 'hr-nomination-form' ); ?></th>
							<th class="manage-column" style="width:190px;"><?php esc_html_e( 'Actions', 'hr-nomination-form' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $submissions ) ) : ?>
							<tr>
								<td colspan="9" style="text-align:center;padding:24px;color:#6b7280;">
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
									<td><strong>#<?php echo (int) $row->id; ?></strong></td>
									<td><?php echo esc_html( gmdate( 'Y-m-d H:i', strtotime( $row->created_at ) ) ); ?></td>
									<td>
										<strong><?php echo esc_html( $row->company_name ); ?></strong>
										<?php if ( ! empty( $row->website ) ) : ?>
											<br><a href="<?php echo esc_url( $row->website ); ?>" target="_blank" style="font-size:12px;color:#2563eb;"><?php echo esc_html( $row->website ); ?></a>
										<?php endif; ?>
									</td>
									<td><span class="badge" style="display:inline-block;padding:2px 8px;background:#e0e7ff;color:#3730a3;border-radius:4px;font-size:12px;font-weight:600;"><?php echo esc_html( $row->category ); ?></span></td>
									<td>
										<strong><?php echo esc_html( $row->contact_person ); ?></strong>
										<?php if ( ! empty( $row->job_title ) ) : ?>
											<br><span style="font-size:12px;color:#6b7280;"><?php echo esc_html( $row->job_title ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a>
										<br><span style="font-size:12px;color:#6b7280;"><?php echo esc_html( $row->phone ); ?></span>
									</td>
									<td>
										<?php if ( $row->email_sent ) : ?>
											<span style="color:#16a34a;font-weight:600;">&#10003; Sent</span>
										<?php else : ?>
											<span style="color:#dc2626;font-weight:600;">&#10007; Pending</span>
										<?php endif; ?>
									</td>
									<td>
										<button type="button" class="button button-small" onclick="hrOpenModal('<?php echo esc_attr( $modal_id ); ?>')">
											<?php esc_html_e( 'View Details', 'hr-nomination-form' ); ?>
										</button>
										<a href="<?php echo esc_url( $resend_url ); ?>" class="button button-small" title="<?php esc_attr_e( 'Resend notification email with CSV attachment', 'hr-nomination-form' ); ?>">
											<?php esc_html_e( 'Resend', 'hr-nomination-form' ); ?>
										</a>
										<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Delete this submission permanently?', 'hr-nomination-form' ); ?>');">
											<?php esc_html_e( 'Delete', 'hr-nomination-form' ); ?>
										</a>

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
													<a href="<?php echo esc_url( $resend_url ); ?>" class="button button-primary"><?php esc_html_e( 'Resend Email Notification', 'hr-nomination-form' ); ?></a>
													<button type="button" class="button" onclick="hrCloseModal('<?php echo esc_attr( $modal_id ); ?>')"><?php esc_html_e( 'Close', 'hr-nomination-form' ); ?></button>
												</div>
											</div>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</form>
		</div>

		<style>
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
