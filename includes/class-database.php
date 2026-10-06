<?php
/**
 * Database management for HR Nomination Form.
 *
 * @package HR_Nomination_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HR_Nomination_Database
 */
class HR_Nomination_Database {

	/**
	 * Table name without WP prefix.
	 *
	 * @var string
	 */
	const TABLE_NAME = 'hr_nominations';

	/**
	 * Get the full prefixed table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create database table on plugin activation.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			company_name VARCHAR(255) NOT NULL,
			website VARCHAR(255) NOT NULL,
			contact_person VARCHAR(255) NOT NULL,
			job_title VARCHAR(255) NOT NULL,
			email VARCHAR(255) NOT NULL,
			phone VARCHAR(100) NOT NULL,
			category VARCHAR(255) NOT NULL,
			company_overview LONGTEXT NOT NULL,
			consent TINYINT(1) NOT NULL DEFAULT 1,
			extra_data LONGTEXT NULL,
			ip_address VARCHAR(45) NOT NULL,
			email_sent TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY email (email(191)),
			KEY category (category(191)),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a nomination submission.
	 *
	 * @param array $data Submission data.
	 * @return int|false Inserted ID on success, false on failure.
	 */
	public static function insert_submission( array $data ) {
		global $wpdb;

		$table = self::get_table_name();

		$row = array(
			'company_name'     => sanitize_text_field( $data['company_name'] ?? '' ),
			'website'          => esc_url_raw( $data['website'] ?? '' ),
			'contact_person'   => sanitize_text_field( $data['contact_person'] ?? '' ),
			'job_title'        => sanitize_text_field( $data['job_title'] ?? '' ),
			'email'            => sanitize_email( $data['email'] ?? '' ),
			'phone'            => sanitize_text_field( $data['phone'] ?? '' ),
			'category'         => sanitize_text_field( $data['category'] ?? '' ),
			'company_overview' => sanitize_textarea_field( $data['company_overview'] ?? '' ),
			'consent'          => ! empty( $data['consent'] ) ? 1 : 0,
			'extra_data'       => ! empty( $data['extra_data'] ) ? ( is_string( $data['extra_data'] ) ? $data['extra_data'] : wp_json_encode( $data['extra_data'] ) ) : null,
			'ip_address'       => sanitize_text_field( $data['ip_address'] ?? '' ),
			'email_sent'       => ! empty( $data['email_sent'] ) ? 1 : 0,
			'created_at'       => current_time( 'mysql' ),
		);

		$formats = array(
			'%s', // company_name
			'%s', // website
			'%s', // contact_person
			'%s', // job_title
			'%s', // email
			'%s', // phone
			'%s', // category
			'%s', // company_overview
			'%d', // consent
			'%s', // extra_data
			'%s', // ip_address
			'%d', // email_sent
			'%s', // created_at
		);

		$result = $wpdb->insert( $table, $row, $formats );

		if ( false === $result ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get nominations with filtering and pagination.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function get_submissions( array $args = array() ): array {
		global $wpdb;

		$table = self::get_table_name();

		$defaults = array(
			'search'   => '',
			'category' => '',
			'orderby'  => 'id',
			'order'    => 'DESC',
			'limit'    => 20,
			'offset'   => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$where_clauses = array( '1=1' );
		$params        = array();

		// Search filter.
		if ( ! empty( $args['search'] ) ) {
			$search_term     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where_clauses[] = '(company_name LIKE %s OR contact_person LIKE %s OR email LIKE %s)';
			$params[]        = $search_term;
			$params[]        = $search_term;
			$params[]        = $search_term;
		}

		// Category filter.
		if ( ! empty( $args['category'] ) ) {
			$where_clauses[] = 'category = %s';
			$params[]        = $args['category'];
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// Validate orderby and order.
		$allowed_orderby = array( 'id', 'company_name', 'category', 'created_at', 'contact_person' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$limit  = max( 1, (int) $args['limit'] );
		$offset = max( 0, (int) $args['offset'] );

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$prepared = $wpdb->prepare( $sql, $params );
		$results  = $wpdb->get_results( $prepared );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get total count of submissions matching criteria.
	 *
	 * @param array $args Filter arguments.
	 * @return int
	 */
	public static function get_total_count( array $args = array() ): int {
		global $wpdb;

		$table = self::get_table_name();

		$where_clauses = array( '1=1' );
		$params        = array();

		if ( ! empty( $args['search'] ) ) {
			$search_term     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where_clauses[] = '(company_name LIKE %s OR contact_person LIKE %s OR email LIKE %s)';
			$params[]        = $search_term;
			$params[]        = $search_term;
			$params[]        = $search_term;
		}

		if ( ! empty( $args['category'] ) ) {
			$where_clauses[] = 'category = %s';
			$params[]        = $args['category'];
		}

		$where_sql = implode( ' AND ', $where_clauses );
		$sql       = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Get a single submission by ID.
	 *
	 * @param int $id Submission ID.
	 * @return object|null
	 */
	public static function get_submission( int $id ) {
		global $wpdb;

		$table = self::get_table_name();
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );

		return $wpdb->get_row( $sql );
	}

	/**
	 * Delete a single submission.
	 *
	 * @param int $id Submission ID.
	 * @return bool
	 */
	public static function delete_submission( int $id ): bool {
		global $wpdb;

		$table  = self::get_table_name();
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Delete multiple submissions by ID.
	 *
	 * @param array $ids List of submission IDs.
	 * @return int Number of deleted rows.
	 */
	public static function delete_submissions( array $ids ): int {
		global $wpdb;

		$ids = array_map( 'intval', $ids );
		$ids = array_filter( $ids, fn( $id ) => $id > 0 );

		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = self::get_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids );

		$result = $wpdb->query( $sql );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Update email delivery status.
	 *
	 * @param int $id Submission ID.
	 * @param int $status 1 for sent, 0 for failed.
	 * @return bool
	 */
	public static function update_email_status( int $id, int $status ): bool {
		global $wpdb;

		$table  = self::get_table_name();
		$result = $wpdb->update(
			$table,
			array( 'email_sent' => $status ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get list of distinct categories for filtering.
	 *
	 * @return array
	 */
	public static function get_categories(): array {
		global $wpdb;

		$table = self::get_table_name();
		$sql   = "SELECT DISTINCT category FROM {$table} WHERE category != '' ORDER BY category ASC";

		$results = $wpdb->get_col( $sql );

		return is_array( $results ) ? $results : array();
	}
}
