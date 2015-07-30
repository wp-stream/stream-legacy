<?php

class WP_Stream_Notifications {

	/**
	 * Holds plugin minimum version
	 *
	 * @const string
	 */
	const STREAM_MIN_VERSION = '1.4.5';

	/**
	 * Holds this plugin version
	 * Used in assets cache
	 *
	 * @const string
	 */
	const VERSION = '0.1.2';

	/**
	 * Hold Stream instance
	 *
	 * @var string
	 */
	public static $instance;

	/**
	 * Screen ID for my admin page
	 * @var string
	 */
	public static $screen_id;

	/**
	 * List table object
	 * @var WP_Stream_Notifications_List_Table
	 */
	public static $list_table = null;

	/**
	 * @var WP_Stream_Notifications_Network
	 */
	public $network = null;

	/**
	 * Page slug for notifications list table screen
	 *
	 * @const string
	 */
	const NOTIFICATIONS_PAGE_SLUG = 'wp_stream_notifications';
	// Todo: We should probably check whether the current user has caps to
	// view and edit the notifications as this can differ from caps to Stream.

	/**
	 * Holds admin notices messages
	 *
	 * @var array
	 */
	public static $messages = array();

	/*
	 * List of registered adapters
	 * @var array
	 */
	public static $adapters = array();

	/**
	 * Matcher object
	 * @var  WP_Stream_Notification_Rule_Matcher
	 */
	public $matcher;

	/**
	 * Form Class Object
	 * @var WP_Stream_Notifications_Form
	 */
	public $form;

	/**
	 * Capability for the Notifications to be viewed
	 *
	 * @const string
	 */
	const VIEW_CAP = 'view_stream_notifications';

	/**
	 * Class constructor
	 */
	private function __construct() {
		define( 'WP_STREAM_NOTIFICATIONS_PLUGIN', plugin_basename( __FILE__ ) );
		define( 'WP_STREAM_NOTIFICATIONS_DIR', plugin_dir_path( __FILE__ ) ); // Has trailing slash
		define( 'WP_STREAM_NOTIFICATIONS_URL', plugin_dir_url( __FILE__ ) ); // Has trailing slash
		define( 'WP_STREAM_NOTIFICATIONS_INC_DIR', WP_STREAM_NOTIFICATIONS_DIR . 'includes/' ); // Has trailing slash
		define( 'WP_STREAM_NOTIFICATIONS_CLASS_DIR', WP_STREAM_NOTIFICATIONS_DIR . 'classes/' ); // Has trailing slash

		add_action( 'plugins_loaded', array( $this, 'load' ) );
	}

	/**
	 * Load our classes, actions/filters, only if our big brother is activated.
	 * GO GO GO!
	 *
	 * @return void
	 */
	public function load() {

		// Admin notices
		add_action( 'all_admin_notices', array( $this, 'admin_notices' ) );

		if ( ! $this->is_dependency_satisfied() ) {
			return;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// Load all classes in /classes folder
		foreach ( glob( WP_STREAM_NOTIFICATIONS_DIR . 'classes/*.php' ) as $class ) {
			include $class;
		}

		// Include all adapters
		foreach ( glob( WP_STREAM_NOTIFICATIONS_DIR . 'classes/adapters/*.php' ) as $class ) {
			include $class;
		}

		if ( is_multisite() ) {
			add_filter( 'wp_stream_notifications_disallow_site_access', array( 'WP_Stream_Network', 'disable_admin_access' ) );
		}

		// Load settings, enabling extensions to hook in
		require_once WP_STREAM_NOTIFICATIONS_INC_DIR . 'settings.php';
		add_action( 'init', array( 'WP_Stream_Notification_Settings', 'load' ), 9 );

		// Load network class
		if ( is_multisite() ) {
			require_once WP_STREAM_NOTIFICATIONS_INC_DIR . 'network.php';
			$this->network = new WP_Stream_Notifications_Network;
		}

		// Register export page
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
		}
		if ( is_plugin_active_for_network( WP_STREAM_NOTIFICATIONS_PLUGIN ) ) {
			add_action( 'network_admin_menu', array( $this, 'register_menu' ), 11 );
		}
		if ( ! apply_filters( 'wp_stream_notifications_disallow_site_access', false ) ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		}

		if ( ! apply_filters( 'wp_stream_notifications_disallow_site_access', false ) ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'register_scripts' ) );

		// Default list actions handlers
		add_action( 'wp_stream_notifications_handle_deactivate', array( $this, 'handle_rule_activation_status_change' ), 10, 3 );
		add_action( 'wp_stream_notifications_handle_activate', array( $this, 'handle_rule_activation_status_change' ), 10, 3 );
		add_action( 'wp_stream_notifications_handle_delete', array( $this, 'handle_rule_deletion' ), 10, 3 );

		// Load Matcher
		$this->matcher = new WP_Stream_Notification_Rule_Matcher();

		// Load form class

		if ( is_admin() ) {
			include WP_STREAM_NOTIFICATIONS_INC_DIR . 'form.php';
			$this->form = new WP_Stream_Notifications_Form;

			include WP_STREAM_NOTIFICATIONS_INC_DIR . 'export.php';
		}

		// Register to Stream updates
		if ( class_exists( 'WP_Stream_Updater' ) ) {
			WP_Stream_Updater::instance()->register( plugin_basename( __FILE__ ) );
		}
	}

	/**
	 * Register Notification menu under Stream's main one
	 *
	 * @action admin_menu
	 * @return void
	 */
	public function register_menu() {
		self::$screen_id = add_submenu_page(
			'wp_stream',
			__( 'Notifications', 'stream-notifications' ),
			__( 'Notifications', 'stream-notifications' ),
			self::VIEW_CAP,
			self::NOTIFICATIONS_PAGE_SLUG,
			array( $this, 'page' )
		);

		add_action( 'load-' . self::$screen_id, array( $this, 'page_form_save' ) );
		add_action( 'load-' . self::$screen_id, array( $this->form, 'load' ) );
	}

	public static function register_adapter( $adapter, $name, $title ) {
		self::$adapters[ $name ] = array(
			'title' => $title,
			'class' => $adapter,
		);
	}

	/**
	 * @action admin_enqueue_scripts
	 */
	public static function register_scripts( $hook ) {
		if ( sprintf( 'stream_page_%s', self::NOTIFICATIONS_PAGE_SLUG ) === $hook ) {
			wp_enqueue_script( 'stream-notifications-actions', WP_STREAM_NOTIFICATIONS_URL . 'ui/js/actions.js', array( 'jquery' ) );
			wp_localize_script( 'stream-notifications-actions', 'stream_notifications_actions', array(
				'messages' => array(
					'deletePermanently' => __( 'Are you sure you want to delete this rule? This cannot be undone.', 'stream-notifications' ),
				),
			) );
		}
	}

	/**
	 * Admin page callback function, redirects to each respective method based
	 * on $_GET['view']
	 *
	 * @return void
	 */
	public function page() {
		$view = wp_stream_filter_input( INPUT_GET, 'view', FILTER_DEFAULT, array( 'options' => array( 'default' => 'list' ) ) );
		$id   = wp_stream_filter_input( INPUT_GET, 'id' );

		switch ( $view ) {
			case 'rule':
				$this->page_form( $id );
				break;

			default:
				$this->page_list();
		}
	}

	/**
	 * Admin page callback for form actions
	 *
	 * @param null $id
	 *
	 * @return void
	 */
	public function page_form( $id = null ) {
		$rule = new WP_Stream_Notification_Rule( $id );
		include WP_STREAM_NOTIFICATIONS_DIR . 'views/rule-form.php';
	}

	public function page_form_save() {
		require_once WP_STREAM_NOTIFICATIONS_INC_DIR . 'list-table.php';
		self::$list_table = new WP_Stream_Notifications_List_Table( array( 'screen' => self::$screen_id ) );

		// TODO check author/user permission to update record

		$view     = wp_stream_filter_input( INPUT_GET, 'view', FILTER_DEFAULT, array( 'options' => array( 'default' => 'list' ) ) );
		$action   = wp_stream_filter_input( INPUT_GET, 'action', FILTER_DEFAULT );
		$id       = wp_stream_filter_input( INPUT_GET, 'id' );
		$bulk_ids = wp_stream_filter_input( INPUT_GET, 'wp_stream_notifications_checkbox', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
		$search   = wp_stream_filter_input( INPUT_GET, 'search' );

		// There is a chance we go from the bottom bulk actions select box
		if ( ! $action || '-1' === $action ) {
			$action = wp_stream_filter_input( INPUT_GET, 'action2', FILTER_DEFAULT, array( 'options' => array( 'default' => 'render' ) ) );
		}

		if ( $_POST && 'rule' === $view ) {
			$data = $_POST;
			$rule = new WP_Stream_Notification_Rule( $id );

			if ( ! wp_verify_nonce( wp_stream_filter_input( INPUT_POST, '_wpnonce' ), 'stream-notifications-form' ) ) {
				wp_die( __( 'Invalid form parameters.', 'stream-notifications' ) );
			}

			if ( empty( $data['triggers'] ) ) {
				wp_die( __( 'Rules cannot be saved without triggers!', 'stream-notifications' ) );
			}

			if ( ! isset( $data['visibility'] ) ) {
				$data['visibility'] = 'inactive'; // Checkbox woraround
			}

			$data['summary'] = trim( $data['summary'] );

			$result = $rule->load_from_array( $data )->save();

			if ( $result ) {
				// Should not follow the WP naming convention, to avoid conflicts
				// if/when Stream migrates to using WP tables
				do_action( 'saved_stream_notification_rule', $rule );
			}

			if ( $result && 'edit' !== $action ) {
				wp_redirect(
					add_query_arg(
						array(
							'action' => 'edit',
							'id'     => $rule->ID,
						)
					)
				);
			}
		}

		if ( 'list' === $view && 'render' !== $action ) {
			if ( has_action( 'wp_stream_notifications_handle_' . $action ) ) {
				if ( $bulk_ids ) {
					foreach ( $bulk_ids as $id ) {
						do_action( 'wp_stream_notifications_handle_' . $action, $id, $action, true );
					}
				} else {
					do_action( 'wp_stream_notifications_handle_' . $action, $id, $action, false );
				}
			} elseif ( null === $search ) {
				wp_redirect(
					add_query_arg(
						array(
							'page' => self::NOTIFICATIONS_PAGE_SLUG,
						),
						is_network_admin() ? network_admin_url( WP_Stream_Admin::ADMIN_PARENT_PAGE ) : admin_url( WP_Stream_Admin::ADMIN_PARENT_PAGE )
					)
				);
			}
		}

	}

	/**
	 * Admin page callback for list view
	 *
	 * @return void
	 */
	public function page_list() {
		self::$list_table->prepare_items();

		echo '<div class="wrap">';
		echo sprintf(
			'<h2>%s <a href="%s" class="add-new-h2">%s</a></h2>',
			__( 'Stream Notifications', 'stream-notifications' ),
			add_query_arg(
				array(
					'page' => self::NOTIFICATIONS_PAGE_SLUG,
					'view' => 'rule',
				),
				is_network_admin() ? network_admin_url( WP_Stream_Admin::ADMIN_PARENT_PAGE ) : admin_url( WP_Stream_Admin::ADMIN_PARENT_PAGE )
			),
			__( 'Add New' )
		); // xss okay

		self::$list_table->display();
		echo '</div>';
	}

	/*
	 * Handle the rule activation & deactivation action
	 */
	public function handle_rule_activation_status_change( $id, $action, $is_bulk = false ) {
		$data             = $_GET;
		$nonce            = wp_stream_filter_input( INPUT_GET, 'wp_stream_nonce' );
		$nonce_identifier = $is_bulk ? 'wp_stream_notifications_bulk_actions' : "activate-record_$id";
		$visibility       = ( 'activate' === $action ) ? 'active' : 'inactive';

		if ( ! wp_verify_nonce( $nonce, $nonce_identifier ) ) {
			return;
		}

		$activate_rule = apply_filters( 'wp_stream_notifications_before_rule_' . $action, true, $id );
		if ( false === $activate_rule ) {
			return;
		}

		$this->update_record(
			$id,
			array( 'visibility' => $visibility ),
			array( '%s' )
		);

		wp_redirect(
			add_query_arg(
				array(
					'wp_stream_nonce' => false,
					'action'          => false,
					'id'              => false,
					'visibility'      => $visibility,
				)
			)
		);
	}

	/*
	 * Handle the rule deletion
	 */
	public function handle_rule_deletion( $id, $action, $is_bulk = false ) {
		$data             = $_GET;
		$nonce            = wp_stream_filter_input( INPUT_GET, 'wp_stream_nonce' );
		$nonce_identifier = $is_bulk ? 'wp_stream_notifications_bulk_actions' : "delete-record_$id";
		$visibility       = wp_stream_filter_input( INPUT_GET, 'visibility', FILTER_DEFAULT );

		if ( ! wp_verify_nonce( $nonce, $nonce_identifier ) ) {
			return;
		}

		$activate_rule = apply_filters( 'wp_stream_notifications_before_rule_' . $action, true, $id );
		if ( false === $activate_rule ) {
			return;
		}

		$this->delete_record( $id );

		wp_redirect(
			add_query_arg(
				array(
					'wp_stream_nonce' => false,
					'action'          => false,
					'id'              => false,
					'visibility'      => $visibility,
				)
			)
		);
	}

	public function update_record( $id, $fields, $formats ) {
		global $wpdb;

		$wpdb->update(
			WP_Stream_DB::$table,
			$fields,
			array( 'ID' => $id, 'type' => 'notification_rule' ),
			$formats,
			array( '%d', '%s' )
		); // db call ok, cache ok

		// Refresh rule cache
		$this->matcher->refresh();
	}

	public function delete_record( $id ) {
		global $wpdb;

		$wpdb->delete(
			WP_Stream_DB::$table,
			array(
				'ID' => $id,
			)
		); // db call ok, cache ok

		// Refresh rule cache
		$this->matcher->refresh();
	}

	/**
	 * Check if plugin dependencies are satisfied and add an admin notice if not
	 *
	 * @return bool
	 */
	public function is_dependency_satisfied() {
		$message = '';

		if ( ! class_exists( 'WP_Stream' ) ) {
			$message .= sprintf( '<p>%s</p>', __( 'Stream Notifications requires Stream plugin to be present and activated.', 'stream-notifications' ) );
		} else if ( version_compare( WP_Stream::VERSION, self::STREAM_MIN_VERSION, '<' ) ) {
			$message .= sprintf( '<p>%s</p>', sprintf( __( 'Stream Notifications requires Stream version %s or higher', 'stream-notifications' ), self::STREAM_MIN_VERSION ) );
		}

		if ( ! empty( $message ) ) {
			self::$messages['wp_stream_db_error'] = sprintf(
				'<div class="error">%s<p>%s</p></div>',
				$message,
				sprintf(
					__( 'Please <a href="%s" target="_blank">install</a> Stream plugin version %s or higher for Stream Notifications to work properly.', 'stream-notifications' ),
					esc_url( 'http://wordpress.org/plugins/stream/' ),
					self::STREAM_MIN_VERSION
				)
			); // xss okay

			return false;
		}

		return true;
	}

	/**
	 * Display all messages on admin board
	 *
	 * @return void
	 */
	public static function admin_notices() {
		foreach ( self::$messages as $message ) {
			echo wp_kses_post( $message );
		}
	}

	/**
	 * Plugin activation routine
	 * @return void
	 */
	public function on_activation() {
		// Add sample rule
		if ( function_exists( 'wp_stream_query' ) && ! wp_stream_query( 'type=notification_rule&ignore_context=1' ) ) {
			$this->load();
			$this->add_sample_rule();
		}
	}

	/**
	 * Add a sample rule, used upon activation
	 *
	 */
	public function add_sample_rule() {
		$rule = new WP_Stream_Notification_Rule();
		$details = array(
			'author'     => 0,
			'summary'    => __( 'Sample Rule', 'stream-notifications' ),
			'visibility' => 'inactive',
			'type'       => 'notification_rule',
			'triggers'   => array(
				array(
					'group'    => 0,
					'relation' => 'and',
					'type'     => 'author_role',
					'operator' => '!=',
					'value'    => 'administrator',
				),
				array(
					'group'    => 0,
					'relation' => 'and',
					'type'     => 'action',
					'operator' => '=',
					'value'    => 'updated',
				),
				array(
					'group'    => 1,
					'relation' => 'and',
					'type'     => 'author_role',
					'operator' => '=',
					'value'    => 'administrator',
				),
				array(
					'group'    => 1,
					'relation' => 'and',
					'type'     => 'connector',
					'operator' => '=',
					'value'    => 'widgets',
				),
				array(
					'group'    => 1,
					'relation' => 'and',
					'type'     => 'action',
					'operator' => '=',
					'value'    => 'sorted',
				),
			),
			'groups' => array(
				1 => array(
					'group'    => 0,
					'relation' => 'or',
				),
			),
			'alerts' => array(
				array(
					'type'    => 'email',
					'users'   => '1',
					'emails'  => '',
					'subject' => sprintf( __( '[Site Activity Alert] %s', 'stream-notifications' ), get_bloginfo( 'name' ) ),
					'message' => __( "The following just happened on your site:\r\n\r\n{summary} by {author.display_name}\r\n\r\nDate of action: {created}", 'stream-notifications' )
				),
			),
		);
		$rule->load_from_array( $details );
		$rule->save();
	}

	/**
	 * Return active instance of WP_Stream, create one if it doesn't exist
	 *
	 * @return WP_Stream
	 */
	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			$class = __CLASS__;
			self::$instance = new $class;
		}
		return self::$instance;
	}

}

$GLOBALS['wp_stream_notifications'] = WP_Stream_Notifications::get_instance();
register_activation_hook( __FILE__, array( $GLOBALS['wp_stream_notifications'], 'on_activation' ) );
