<?php

/**
 * WP Simple Pay Session Class
 *
 * Handles the storage of sessions within WP Simple Pay.
 * This is a wrapper class WP Session Manager
 * https://github.com/ericmann/wp-session-manager/
 * Using version 2.0.1
 *
 * Currently not offering option for using native PHP $_SESSION due to issues.
 * Taking session logic from EDD, Give & Ninja Forms.
 */

namespace SimplePay\Core;

use WP_Session;
use WP_Session_Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Session {

	/**
	 * Holds our session data
	 *
	 * @since  3.0
	 * @access private
	 *
	 * @var    WP_Session/array
	 */
	private $session;

	/**
	 * Class Constructor
	 *
	 * Defines our session constants, includes the necessary libraries and retrieves the session instance.
	 *
	 * @since  3.0
	 * @access public
	 */
	public function __construct() {

		// Use WP_Session.
		// Let users change the session cookie name.
		if ( ! defined( 'WP_SESSION_COOKIE' ) ) {
			define( 'WP_SESSION_COOKIE', 'simpay_wp_session' );
		}

		if ( ! class_exists( 'Recursive_ArrayAccess' ) ) {
			require_once( SIMPLE_PAY_INC . 'core/libraries/wp-session/class-recursive-arrayaccess.php' );
		}

		// Include utilities class.
		// Skip including WP_CLI class.
		if ( ! class_exists( 'WP_Session_Utils' ) ) {
			require_once SIMPLE_PAY_INC . 'core/libraries/wp-session/class-wp-session-utils.php';
		}

		// Only include the functionality if it's not pre-defined.
		if ( ! class_exists( 'WP_Session' ) ) {
			require_once( SIMPLE_PAY_INC . 'core/libraries/wp-session/class-wp-session.php' );
			require_once( SIMPLE_PAY_INC . 'core/libraries/wp-session/wp-session.php' );
		}

		// TODO Init WP_Session immediately instead of on init hook (Give uses hook).
		self::init();
		//add_action( 'init', array( $this, 'init' ), - 1 );

		add_filter( 'wp_session_expiration_variant', array( $this, 'set_expiration_variant_time' ), 99999 );
		add_filter( 'wp_session_expiration', array( $this, 'set_expiration_time' ), 99999 );

		add_action( 'admin_init', array( $this, 'create_sm_sessions_table' ) );
		add_action( 'wp_session_init', array( $this, 'create_sm_sessions_table' ) );
	}

	/**
	 * Session Init
	 *
	 * Setup the Session instance.
	 *
	 * @since  3.0
	 * @access public
	 *
	 * @return WP_Session/array instance
	 */
	public function init() {

		$this->session = WP_Session::get_instance();

		return $this->session;
	}

	/**
	 * Get Session ID
	 *
	 * Retrieve session ID.
	 *
	 * @since  3.0
	 * @access public
	 *
	 * @return string Session ID.
	 */
	public function get_id() {

		return $this->session->session_id;
	}

	/**
	 * Get Session
	 *
	 * Retrieve session variable for a given session key.
	 *
	 * @since  3.0
	 * @access public
	 *
	 * @param  string $key Session key
	 *
	 * @return string|array      Session variable
	 */
	public function get( $key ) {

		$key    = sanitize_key( $key );
		$return = false;

		if ( isset( $this->session[ $key ] ) && ! empty( $this->session[ $key ] ) ) {

			// TODO EDD & Give use regext matching & JSON decoding when retrieving session values.
			$return = maybe_unserialize( $this->session[ $key ] );
		}

		return $return;
	}

	/**
	 * Set Session
	 *
	 * Create a new session.
	 *
	 * @since  3.0
	 * @access public
	 *
	 * @param  string $key   Session key
	 * @param  mixed  $value Session variable
	 *
	 * @return string        Session variable
	 */
	public function set( $key, $value ) {

		// TODO Manually set cookie (like Give & NF)?
		//$this->session->set_cookie();

		$key = sanitize_key( $key );

		// TODO Set & retrieve JSON instead of full objects (like Give)?
		// Currently we're passing various value types including strings, arrays & objects.
		// i.e. The Payment Form object ('simpay_form') is sent here.
		$this->session[ $key ] = $value;

		return $this->session[ $key ];
	}

	/**
	 * Set Cookie Variant Time
	 *
	 * Force the cookie expiration variant time to 23 minutes.
	 *
	 * @since  3.0
	 * @access public
	 *
	 * @return int
	 */
	public function set_expiration_variant_time() {

		return ( 60 * 23 );
	}

	/**
	 * Set Cookie Expiration
	 *
	 * Force the cookie expiration time to 24 minutes.
	 *
	 * @since  3.0
	 * @access public
	 *
	 * @return int
	 */
	public function set_expiration_time() {

		return ( 60 * 24 );
	}

	/**
	 * Create table to hold session data.
	 *
	 * Create the new table for housing session data if we're not still using
	 * the legacy options mechanism. This code should be invoked before
	 * instantiating the singleton session manager to ensure the table exists
	 * before trying to use it.
	 *
	 * @see    https://github.com/ericmann/wp-session-manager/issues/55
	 *
	 * @since  3.0
	 * @access private
	 */
	public function create_sm_sessions_table() {

		if ( defined( 'WP_SESSION_USE_OPTIONS' ) && WP_SESSION_USE_OPTIONS ) {
			return;
		}

		$current_db_version = '0.1';
		$created_db_version = get_option( 'sm_session_db_version', '0.0' );

		if ( version_compare( $created_db_version, $current_db_version, '<' ) ) {
			global $wpdb;

			$collate = '';
			if ( $wpdb->has_cap( 'collation' ) ) {
				$collate = $wpdb->get_charset_collate();
			}

			$table = "CREATE TABLE {$wpdb->prefix}sm_sessions (
		  session_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		  session_key char(32) NOT NULL,
		  session_value LONGTEXT NOT NULL,
		  session_expiry BIGINT(20) UNSIGNED NOT NULL,
		  PRIMARY KEY  (session_key),
		  UNIQUE KEY session_id (session_id)
		) $collate;";

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			dbDelta( $table );

			add_option( 'sm_session_db_version', '0.1', '', 'no' );

			WP_Session_Utils::delete_all_sessions_from_options();
		}
	}
}
