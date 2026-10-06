<?php
/**
 * Frontend script & style loader for HR Nomination Form.
 *
 * @package HR_Nomination_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HR_Nomination_Frontend
 */
class HR_Nomination_Frontend {

	/**
	 * Register actions.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue frontend CSS and JavaScript.
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style(
			'hr-nomination-frontend',
			HR_NOMINATION_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			HR_NOMINATION_VERSION
		);

		wp_enqueue_script(
			'hr-nomination-frontend',
			HR_NOMINATION_PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			HR_NOMINATION_VERSION,
			true // in footer
		);

		wp_localize_script(
			'hr-nomination-frontend',
			'hrNominationData',
			array(
				'postUrl'       => esc_url( admin_url( 'admin-post.php' ) ),
				'ajaxUrl'       => esc_url( admin_url( 'admin-ajax.php' ) ),
				'action'        => HR_Nomination_Form_Handler::ACTION_NAME,
				'nonce'         => wp_create_nonce( HR_Nomination_Form_Handler::NONCE_ACTION ),
				'honeypotField' => HR_Nomination_Form_Handler::HONEYPOT_FIELD,
				'i18n'          => array(
					'submitting'        => __( 'Submitting Nomination...', 'hr-nomination-form' ),
					'successTitle'      => __( 'Nomination Submitted Successfully!', 'hr-nomination-form' ),
					'successMessage'    => __( 'Thank you for your submission. Our team has received your nomination.', 'hr-nomination-form' ),
					'errorTitle'        => __( 'Submission Error', 'hr-nomination-form' ),
					'defaultError'      => __( 'There was a problem submitting your nomination. Please review the form and try again.', 'hr-nomination-form' ),
					'rateLimitError'    => __( 'Too many submissions. Please wait 60 seconds before submitting again.', 'hr-nomination-form' ),
					'validationError'   => __( 'Please complete all required fields correctly before submitting.', 'hr-nomination-form' ),
					'invalidNonce'      => __( 'Session expired. Please refresh the page and try again.', 'hr-nomination-form' ),
					'consentRequired'   => __( 'You must agree to the terms / consent before submitting.', 'hr-nomination-form' ),
					'validEmail'        => __( 'Please enter a valid email address.', 'hr-nomination-form' ),
					'validUrl'          => __( 'Please enter a valid website URL (e.g. https://example.com).', 'hr-nomination-form' ),
					'requiredField'     => __( 'This field is required.', 'hr-nomination-form' ),
				),
			)
		);
	}
}
