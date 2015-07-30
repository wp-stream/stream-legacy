<?php

class WP_Stream_Admin {

	/**
	 * Menu page screen id
	 *
	 * @var string
	 */
	public static $screen_id = array();

	/**
	 * List table object
	 *
	 * @var WP_Stream_List_Table
	 */
	public static $list_table = null;

	/**
	 * Option to disable access to Stream
	 *
	 * @var bool
	 */
	public static $disable_access = false;

	const ADMIN_BODY_CLASS     = 'wp_stream_screen';
	const RECORDS_PAGE_SLUG    = 'wp_stream';
	const SETTINGS_PAGE_SLUG   = 'wp_stream_settings';
	const ADMIN_PARENT_PAGE    = 'admin.php';
	const VIEW_CAP             = 'view_stream';
	const SETTINGS_CAP         = 'manage_options';
	const PRELOAD_AUTHORS_MAX  = 50;

	public static function load() {
		// User and role caps
		add_filter( 'user_has_cap', array( __CLASS__, '_filter_user_caps' ), 10, 4 );
		add_filter( 'role_has_cap', array( __CLASS__, '_filter_role_caps' ), 10, 3 );

		self::$disable_access = apply_filters( 'wp_stream_disable_admin_access', false );

		// Register settings page
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );

		// Admin notices
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );

		// Add admin body class
		add_filter( 'admin_body_class', array( __CLASS__, 'admin_body_class' ) );

		// Plugin action links
		add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_action_links' ), 10, 2 );
		add_filter( 'network_admin_plugin_action_links', array( __CLASS__, 'plugin_action_links' ), 10, 2 );

		// Load admin scripts and styles
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_menu_css' ) );

		// Reset Streams database
		add_action( 'wp_ajax_wp_stream_reset', array( __CLASS__, 'wp_ajax_reset' ) );

		// Reset Streams settings
		add_action( 'wp_ajax_wp_stream_defaults', array( __CLASS__, 'wp_ajax_defaults' ) );

		// Uninstall Streams and Deactivate plugin
		add_action( 'wp_ajax_wp_stream_uninstall', array( __CLASS__, 'uninstall_plugin' ) );

		// Auto purge setup
		add_action( 'wp_loaded', array( __CLASS__, 'purge_schedule_setup' ) );
		add_action( 'wp_stream_auto_purge', array( __CLASS__, 'purge_scheduled_action' ) );

		// Admin notices
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );

		// Ajax authors list
		add_action( 'wp_ajax_wp_stream_filters', array( __CLASS__, 'ajax_filters' ) );

		// Ajax author's name by ID
		add_action( 'wp_ajax_wp_stream_get_filter_value_by_id', array( __CLASS__, 'get_filter_value_by_id' ) );
	}

	/**
	 * Output specific update
	 *
	 * @action admin_notices
	 * @return string
	 */
	public static function admin_notices() {
		$message = wp_stream_filter_input( INPUT_GET, 'message' );

		switch ( $message ) {
			case 'data_erased':
				printf( '<div class="updated"><p>%s</p></div>', __( 'All records have been successfully erased.', 'stream' ) );
				break;
			case 'settings_reset':
				printf( '<div class="updated"><p>%s</p></div>', __( 'All site settings have been successfully reset.', 'stream' ) );
				break;
		}
	}

	/**
	 * Register menu page
	 *
	 * @action admin_menu
	 * @return bool|void
	 */
	public static function register_menu() {
		if ( is_network_admin() && ! is_plugin_active_for_network( WP_STREAM_PLUGIN ) ) {
			return false;
		}

		if ( self::$disable_access ) {
			return false;
		}

		self::$screen_id['main'] = add_menu_page(
			__( 'Stream', 'stream' ),
			__( 'Stream', 'stream' ),
			self::VIEW_CAP,
			self::RECORDS_PAGE_SLUG,
			array( __CLASS__, 'stream_page' ),
			'div',
			apply_filters( 'wp_stream_menu_position', '2.999999' ) // Using longtail decimal string to reduce the chance of position conflicts, see Codex
		);

		self::$screen_id['settings'] = add_submenu_page(
			self::RECORDS_PAGE_SLUG,
			__( 'Stream Settings', 'stream' ),
			__( 'Settings', 'default' ),
			self::SETTINGS_CAP,
			self::SETTINGS_PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);

		// Register the list table early, so it associates the column headers with 'Screen settings'
		add_action( 'load-' . self::$screen_id['main'], array( __CLASS__, 'register_list_table' ) );
		do_action( 'wp_stream_admin_menu_screens' );

		// Register the list table early, so it associates the column headers with 'Screen settings'
		add_action( 'load-' . self::$screen_id['main'], array( __CLASS__, 'register_list_table' ) );
	}

	/**
	 * Enqueue scripts/styles for admin screen
	 *
	 * @action admin_enqueue_scripts
	 *
	 * @param $hook
	 *
	 * @return void
	 */
	public static function admin_enqueue_scripts( $hook ) {
		wp_register_script( 'select2', WP_STREAM_URL . 'ui/select2/select2.min.js', array( 'jquery' ), '3.4.5', true );
		wp_register_style( 'select2', WP_STREAM_URL . 'ui/select2/select2.css', array(), '3.4.5' );
		wp_register_script( 'timeago', WP_STREAM_URL . 'ui/timeago/timeago.js', array(), '0.2.0', true );

		$locale    = substr( get_locale(), 0, 2 );
		$file_tmpl = 'ui/timeago/locale/jquery.timeago.%s.js';

		if ( file_exists( WP_STREAM_DIR . sprintf( $file_tmpl, $locale ) ) ) {
			wp_register_script( 'timeago-locale', WP_STREAM_URL . sprintf( $file_tmpl, $locale ), array( 'timeago' ), '1' );
		} else {
			wp_register_script( 'timeago-locale', WP_STREAM_URL . sprintf( $file_tmpl, 'en' ), array( 'timeago' ), '1' );
		}

		wp_enqueue_style( 'wp-stream-admin', WP_STREAM_URL . 'ui/admin.css', array(), WP_Stream::VERSION );

		$script_screens = array( 'plugins.php', 'user-edit.php', 'user-new.php', 'profile.php' );

		if ( 'index.php' === $hook ) {
			wp_enqueue_script( 'wp-stream-admin-dashboard', WP_STREAM_URL . 'ui/dashboard.js', array( 'jquery', 'heartbeat' ), WP_Stream::VERSION );
		} elseif ( in_array( $hook, self::$screen_id ) || in_array( $hook, $script_screens ) ) {
			wp_enqueue_script( 'select2' );
			wp_enqueue_style( 'select2' );

			wp_enqueue_script( 'timeago' );
			wp_enqueue_script( 'timeago-locale' );

			wp_enqueue_script( 'wp-stream-admin', WP_STREAM_URL . 'ui/admin.js', array( 'jquery', 'select2', 'heartbeat' ), WP_Stream::VERSION );
			wp_localize_script(
				'wp-stream-admin',
				'wp_stream',
				array(
					'i18n'            => array(
						'confirm_purge'     => __( 'Are you sure you want to delete all Stream activity records from the database? This cannot be undone.', 'stream' ),
						'confirm_defaults'  => __( 'Are you sure you want to reset all site settings to default? This cannot be undone.', 'stream' ),
						'confirm_uninstall' => __( 'Are you sure you want to uninstall and deactivate Stream? This will delete all Stream tables from the database and cannot be undone.', 'stream' ),
					),
					'gmt_offset'     => get_option( 'gmt_offset' ),
					'current_screen' => $hook,
					'current_page'   => isset( $_GET['paged'] ) ? esc_js( $_GET['paged'] ) : '1',
					'current_order'  => isset( $_GET['order'] ) ? esc_js( $_GET['order'] ) : 'desc',
					'current_query'  => json_encode( $_GET ),
					'filters'        => self::$list_table ? self::$list_table->get_filters() : false,
				)
			);
		}
	}

	/**
	 * Add a specific body class to all Stream admin screens
	 *
	 * @filter admin_body_class
	 *
	 * @param  string $classes
	 *
	 * @return string $classes
	 */
	public static function admin_body_class( $classes ) {
		if ( isset( $_GET['page'] ) && false !== strpos( $_GET['page'], self::RECORDS_PAGE_SLUG ) ) {
			$classes .= sprintf( ' %s ', self::ADMIN_BODY_CLASS );
		}

		return $classes;
	}

	/**
	 * Add menu styles for various WP Admin skins
	 *
	 * @uses wp_add_inline_style()
	 * @action admin_enqueue_scripts
	 * @return bool true on success false on failure
	 */
	public static function admin_menu_css() {
		wp_register_style( 'jquery-ui', '//ajax.googleapis.com/ajax/libs/jqueryui/1.10.1/themes/base/jquery-ui.css', array(), '1.10.1' );
		wp_register_style( 'wp-stream-datepicker', WP_STREAM_URL . 'ui/datepicker.css', array( 'jquery-ui' ), WP_Stream::VERSION );
		wp_register_style( 'wp-stream-icons', WP_STREAM_URL . 'ui/stream-icons/style.css', array(), WP_Stream::VERSION );

		// Make sure we're working off a clean version
		include( ABSPATH . WPINC . '/version.php' );

		$body_class      = self::ADMIN_BODY_CLASS;
		$records_page    = self::RECORDS_PAGE_SLUG;
		$stream_url      = WP_STREAM_URL;

		if ( version_compare( $wp_version, '3.8-alpha', '>=' ) ) {
			wp_enqueue_style( 'wp-stream-icons' );
			$css = "
				#toplevel_page_{$records_page} .wp-menu-image:before {
					font-family: 'WP Stream' !important;
					content: '\\73' !important;
				}
				#toplevel_page_{$records_page} .wp-menu-image {
					background-repeat: no-repeat;
				}
				#menu-posts-feedback .wp-menu-image:before {
					font-family: dashicons !important;
					content: '\\f175';
				}
				#adminmenu #menu-posts-feedback div.wp-menu-image {
					background: none !important;
					background-repeat: no-repeat;
				}
				body.{$body_class} #wpbody-content .wrap h2:nth-child(1):before {
					font-family: 'WP Stream' !important;
					content: '\\73';
					padding: 0 8px 0 0;
				}
			";
		} else {
			$css = "
				#toplevel_page_{$records_page} .wp-menu-image {
					background: url( {$stream_url}ui/stream-icons/menuicon-sprite.png ) 0 90% no-repeat;
				}
				/* Retina Stream Menu Icon */
				@media  only screen and (-moz-min-device-pixel-ratio: 1.5),
						only screen and (-o-min-device-pixel-ratio: 3/2),
						only screen and (-webkit-min-device-pixel-ratio: 1.5),
						only screen and (min-device-pixel-ratio: 1.5) {
					#toplevel_page_{$records_page} .wp-menu-image {
						background: url( {$stream_url}ui/stream-icons/menuicon-sprite-2x.png ) 0 90% no-repeat;
						background-size:30px 64px;
					}
				}
				#toplevel_page_{$records_page}.current .wp-menu-image,
				#toplevel_page_{$records_page}.wp-has-current-submenu .wp-menu-image,
				#toplevel_page_{$records_page}:hover .wp-menu-image {
					background-position: top left;
				}
			";
		}

		wp_add_inline_style( 'wp-admin', $css );
	}

	/**
	 * @filter plugin_action_links
	 */
	public static function plugin_action_links( $links, $file ) {
		if ( plugin_basename( WP_STREAM_DIR . 'stream.php' ) === $file ) {

			// Don't show links in Network Admin if Stream isn't network enabled
			if ( is_network_admin() && is_multisite() && ! is_plugin_active_for_network( WP_STREAM_PLUGIN ) ) {
				return $links;
			}

			if ( is_network_admin() ) {
				$admin_page_url = add_query_arg( array( 'page' => WP_Stream_Network::NETWORK_SETTINGS_PAGE_SLUG ), network_admin_url( self::ADMIN_PARENT_PAGE ) );
			} else {
				$admin_page_url = add_query_arg( array( 'page' => self::SETTINGS_PAGE_SLUG ), admin_url( self::ADMIN_PARENT_PAGE ) );
			}
			$links[] = sprintf( '<a href="%s">%s</a>', esc_url( $admin_page_url ), esc_html__( 'Settings', 'default' ) );

			$url = add_query_arg(
				array(
					'action'          => 'wp_stream_uninstall',
					'wp_stream_nonce' => wp_create_nonce( 'stream_nonce' ),
				),
				admin_url( 'admin-ajax.php' )
			);
			$links[] = sprintf( '<span id="wp_stream_uninstall" class="delete"><a href="%s">%s</a></span>', esc_url( $url ), esc_html__( 'Uninstall', 'stream' ) );
		}

		return $links;
	}

	/**
	 * Register a routine to be called when stream or a stream connector has been updated
	 * It works by comparing the current version with the version previously stored in the database.
	 *
	 * @param string $file A reference to the main plugin file
	 * @param string $callback The function to run when the hook is called.
	 * @param string $version The version to which the plugin is updating.
	 * @return void
	 */
	public static function register_update_hook( $file, $callback, $version ) {
		if ( ! is_admin() ) {
			return;
		}

		$plugin = plugin_basename( $file );

		if ( is_plugin_active_for_network( $plugin ) ) {
			$current_versions = get_site_option( WP_Stream_Install::KEY . '_connectors', array() );
			$network          = true;
		} elseif ( is_plugin_active( $plugin ) ) {
			$current_versions = get_option( WP_Stream_Install::KEY . '_connectors', array() );
			$network          = false;
		} else {
			return;
		}

		if ( version_compare( $version, $current_versions[ $plugin ], '>' ) ) {
			call_user_func( $callback, $current_versions[ $plugin ], $network );
			$current_versions[ $plugin ] = $version;
		}

		if ( $network ) {
			update_site_option( WP_Stream_Install::KEY . '_registered_connectors', $current_versions );
		} else {
			update_option( WP_Stream_Install::KEY . '_registered_connectors', $current_versions );
		}

		return;
	}

	/**
	 * Render settings page
	 *
	 * @return void
	 */
	public static function render_page() {

		$option_key  = WP_Stream_Settings::$option_key;
		$form_action = apply_filters( 'wp_stream_settings_form_action', admin_url( 'options.php' ) );

		$page_title       = apply_filters( 'wp_stream_settings_form_title', get_admin_page_title() );
		$page_description = apply_filters( 'wp_stream_settings_form_description', '' );

		$sections   = WP_Stream_Settings::get_fields();
		$active_tab = wp_stream_filter_input( INPUT_GET, 'tab' );

		?>
		<div class="wrap">

			<h2><?php echo esc_html( $page_title ); ?></h2>

			<?php if ( ! empty( $page_description ) ) : ?>
				<p><?php echo esc_html( $page_description ); ?></p>
			<?php endif; ?>

			<?php settings_errors() ?>

			<?php if ( count( $sections ) > 1 ) : ?>
				<h2 class="nav-tab-wrapper">
					<?php $i = 0 ?>
					<?php foreach ( $sections as $section => $data ) : ?>
						<?php $i ++ ?>
						<?php $is_active = ( ( 1 === $i && ! $active_tab ) || $active_tab === $section ) ?>
						<a href="<?php echo esc_url( add_query_arg( 'tab', $section ) ) ?>" class="nav-tab<?php if ( $is_active ) { echo esc_attr( ' nav-tab-active' ); } ?>">
							<?php echo esc_html( $data['title'] ) ?>
						</a>
					<?php endforeach; ?>
				</h2>
			<?php endif; ?>

			<div class="nav-tab-content" id="tab-content-settings">

				<form method="post" action="<?php echo esc_attr( $form_action ) ?>" enctype="multipart/form-data">
		<?php
		$i = 0;
		foreach ( $sections as $section => $data ) {
			$i++;
			$is_active = ( ( 1 === $i && ! $active_tab ) || $active_tab === $section );
			if ( $is_active ) {
				settings_fields( $option_key );
				do_settings_sections( $option_key );
			}
		}
		submit_button();
		?>
				</form>

			</div>

		</div>
	<?php
	}

	public static function register_list_table() {
		require_once WP_STREAM_INC_DIR . 'list-table.php';
		self::$list_table = new WP_Stream_List_Table( array( 'screen' => self::$screen_id['main'] ) );
	}

	public static function stream_page() {
		$page_title = __( 'Stream Records', 'stream' );

		echo '<div class="wrap">';

		if ( is_network_admin() ) {
			$site_count = sprintf( _n( '1 site', '%d sites', get_blog_count(), 'stream' ), get_blog_count() );
			printf( '<h2>%s (%s)</h2>', __( 'Stream Records', 'stream' ), $site_count ); // xss ok
		} else {
			printf( '<h2>%s</h2>', __( 'Stream Records', 'stream' ) ); // xss ok
		}

		self::$list_table->prepare_items();
		self::$list_table->display();
		echo '</div>';
	}

	public static function wp_ajax_reset() {
		check_ajax_referer( 'stream_nonce', 'wp_stream_nonce' );

		if ( current_user_can( self::SETTINGS_CAP ) ) {
			self::erase_stream_records();
			wp_redirect(
				add_query_arg(
					array(
						'page'    => is_network_admin() ? 'wp_stream_network_settings' : 'wp_stream_settings',
						'message' => 'data_erased',
					),
					is_plugin_active_for_network( WP_STREAM_PLUGIN ) ? network_admin_url( self::ADMIN_PARENT_PAGE ) : admin_url( self::ADMIN_PARENT_PAGE )
				)
			);
			exit;
		} else {
			wp_die( "You don't have sufficient privileges to do this action." );
		}
	}

	private static function erase_stream_records() {
		global $wpdb;

		$where = '';
		if ( is_multisite() && ! is_plugin_active_for_network( WP_STREAM_PLUGIN ) ) {
			$where .= $wpdb->prepare( ' AND `blog_id` = %d', get_current_blog_id() );
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE `stream`, `context`, `meta`
				FROM {$wpdb->stream} AS `stream`
				LEFT JOIN {$wpdb->streamcontext} AS `context`
				ON `context`.`record_id` = `stream`.`ID`
				LEFT JOIN {$wpdb->streammeta} AS `meta`
				ON `meta`.`record_id` = `stream`.`ID`
				WHERE `stream`.`type` = %s
				$where;",
				'stream'
			)
		);
	}

	public static function wp_ajax_defaults() {
		check_ajax_referer( 'stream_nonce', 'wp_stream_nonce' );

		if ( ! is_plugin_active_for_network( WP_STREAM_PLUGIN ) ) {
			wp_die( "You don't have sufficient privileges to do this action." );
		}

		if ( current_user_can( self::SETTINGS_CAP ) ) {
			self::reset_stream_settings();
			wp_redirect(
				add_query_arg(
					array(
						'page'    => is_network_admin() ? 'wp_stream_network_settings' : 'wp_stream_settings',
						'message' => 'settings_reset',
					),
					is_plugin_active_for_network( WP_STREAM_PLUGIN ) ? network_admin_url( self::ADMIN_PARENT_PAGE ) : admin_url( self::ADMIN_PARENT_PAGE )
				)
			);
			exit;
		} else {
			wp_die( "You don't have sufficient privileges to do this action." );
		}
	}

	private static function reset_stream_settings() {
		global $wpdb;

		$blogs = wp_get_sites();

		if ( $blogs ) {
			foreach ( $blogs as $blog ) {
				switch_to_blog( $blog['blog_id'] );
				delete_option( WP_Stream_Settings::KEY );
			}
			restore_current_blog();
		}
	}

	/**
	 * This function is used to uninstall all custom tables and uninstall the plugin
	 * It will also uninstall custom actions
	 */
	public static function uninstall_plugin() {
		global $wpdb;

		check_ajax_referer( 'stream_nonce', 'wp_stream_nonce' );

		if ( current_user_can( self::SETTINGS_CAP ) ) {
			// Prevent stream action from being fired on plugin
			remove_action( 'deactivate_plugin', array( 'WP_Stream_Connector_Installer', 'callback' ), null );

			// Plugin is being uninstalled from only one of the multisite blogs
			if ( is_multisite() && ! is_plugin_active_for_network( WP_STREAM_PLUGIN ) ) {
				$blog_id = get_current_blog_id();

				$wpdb->query( "DELETE FROM {$wpdb->base_prefix}stream WHERE blog_id = $blog_id" );

				delete_option( plugin_basename( WP_STREAM_DIR ) . '_db' );
				delete_option( WP_Stream_Install::KEY );
				delete_option( WP_Stream_Settings::KEY );
			} else {
				// Delete all tables
				foreach ( WP_Stream_DB::get_instance()->get_table_names() as $table ) {
					$wpdb->query( "DROP TABLE $table" );
				}

				// Delete database options
				if ( is_multisite() ) {
					$blogs = wp_get_sites();
					foreach ( $blogs as $blog ) {
						switch_to_blog( $blog['blog_id'] );
						delete_option( plugin_basename( WP_STREAM_DIR ) . '_db' );
						delete_option( WP_Stream_Install::KEY );
						delete_option( WP_Stream_Settings::KEY );
					}
					restore_current_blog();
				}

				// Delete database option
				delete_site_option( plugin_basename( WP_STREAM_DIR ) . '_db' );
				delete_site_option( WP_Stream_Install::KEY );
				delete_site_option( WP_Stream_Settings::KEY );
				delete_site_option( WP_Stream_Settings::DEFAULTS_KEY );
				delete_site_option( WP_Stream_Settings::NETWORK_KEY );
				delete_site_option( 'dashboard_stream_activity_options' );
			}

			// Delete scheduled cron event hooks
			wp_clear_scheduled_hook( 'stream_auto_purge' ); // Deprecated hook
			wp_clear_scheduled_hook( 'wp_stream_auto_purge' );

			// Deactivate the plugin
			deactivate_plugins( plugin_basename( WP_STREAM_DIR ) . '/stream.php' );

			// Redirect to plugin page
			wp_redirect( add_query_arg( array( 'deactivate' => true ), self_admin_url( 'plugins.php' ) ) );
			exit;
		} else {
			wp_die( "You don't have sufficient privileges to do this action." );
		}
	}

	public static function purge_schedule_setup() {
		if ( ! wp_next_scheduled( 'wp_stream_auto_purge' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'wp_stream_auto_purge' );
		}
	}

	public static function purge_scheduled_action() {
		global $wpdb;

		// Don't purge if in Network Admin if Stream isn't network enabled
		if ( is_network_admin() && is_multisite() && ! is_plugin_active_for_network( WP_STREAM_PLUGIN ) ) {
			return;
		}

		if ( is_multisite() && is_plugin_active_for_network( WP_STREAM_PLUGIN ) ) {
			$options = (array) get_site_option( WP_Stream_Settings::NETWORK_KEY, array() );
		} else {
			$options = WP_Stream_Settings::get_options();
		}

		$days = $options['general_records_ttl'];
		$date = new DateTime( 'now', $timezone = new DateTimeZone( 'UTC' ) );

		$date->sub( DateInterval::createFromDateString( "$days days" ) );

		$where = $wpdb->prepare( ' AND `stream`.`created` < %s', $date->format( 'Y-m-d H:i:s' ) );

		if ( is_multisite() && ! is_plugin_active_for_network( WP_STREAM_PLUGIN ) ) {
			$where .= $wpdb->prepare( ' AND `blog_id` = %d', get_current_blog_id() );
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE `stream`, `context`, `meta`
				FROM {$wpdb->stream} AS `stream`
				LEFT JOIN {$wpdb->streamcontext} AS `context`
				ON `context`.`record_id` = `stream`.`ID`
				LEFT JOIN {$wpdb->streammeta} AS `meta`
				ON `meta`.`record_id` = `stream`.`ID`
				WHERE `stream`.`type` = %s
				$where;",
				'stream',
				$date->format( 'Y-m-d H:i:s' )
			)
		);
	}

	private static function _role_can_view_stream( $role ) {
		if ( in_array( $role, WP_Stream_Settings::$options['general_role_access'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Filter user caps to dynamically grant our view cap based on allowed roles
	 *
	 * @filter user_has_cap
	 *
	 * @param $allcaps
	 * @param $caps
	 * @param $args
	 * @param $user
	 *
	 * @return array
	 */
	public static function _filter_user_caps( $allcaps, $caps, $args, $user = null ) {
		global $wp_roles;

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new WP_Roles();
		}

		$user = is_a( $user, 'WP_User' ) ? $user : wp_get_current_user();

		// @see
		// https://github.com/WordPress/WordPress/blob/c67c9565f1495255807069fdb39dac914046b1a0/wp-includes/capabilities.php#L758
		$roles = array_unique(
			array_merge(
				$user->roles,
				array_filter(
					array_keys( $user->caps ),
					array( $wp_roles, 'is_role' )
				)
			)
		);

		foreach ( $caps as $cap ) {
			if ( self::VIEW_CAP === $cap ) {
				foreach ( $roles as $role ) {
					if ( self::_role_can_view_stream( $role ) ) {
						$allcaps[ $cap ] = true;
						break 2;
					}
				}
			}
		}

		return $allcaps;
	}

	/**
	 * Filter role caps to dynamically grant our view cap based on allowed roles
	 *
	 * @filter role_has_cap
	 *
	 * @param $allcaps
	 * @param $cap
	 * @param $role
	 *
	 * @return array
	 */
	public static function _filter_role_caps( $allcaps, $cap, $role ) {
		if ( self::VIEW_CAP === $cap && self::_role_can_view_stream( $role ) ) {
			$allcaps[ $cap ] = true;
		}

		return $allcaps;
	}

	/**
	 * @action wp_ajax_wp_stream_filters
	 */
	public static function ajax_filters() {
		switch ( wp_stream_filter_input( INPUT_GET, 'filter' ) ) {
			case 'author':
				$users = array_merge(
					array( 0 => (object) array( 'display_name' => 'WP-CLI' ) ),
					get_users()
				);

				// `search` arg for get_users() is not enough
				$users = array_filter(
					$users,
					function ( $user ) {
						return false !== mb_strpos( mb_strtolower( $user->display_name ), mb_strtolower( wp_stream_filter_input( INPUT_GET, 'q' ) ) );
					}
				);

				if ( count( $users ) > self::PRELOAD_AUTHORS_MAX ) {
					$users = array_slice( $users, 0, self::PRELOAD_AUTHORS_MAX );
					// @todo $extra is not used
					$extra = array(
						'id'       => 0,
						'disabled' => true,
						'text'     => sprintf( _n( 'One more result...', '%d more results...', $results_count - self::PRELOAD_AUTHORS_MAX, 'stream' ), $results_count - self::PRELOAD_AUTHORS_MAX ),
					);
				}

				// Get gravatar / roles for final result set
				$results = self::get_authors_record_meta( $users );

				break;
		}
		if ( isset( $results ) ) {
			echo json_encode( array_values( $results ) );
		}
		die();
	}

	/**
	 * @action wp_ajax_wp_stream_get_filter_value_by_id
	 */
	public static function get_filter_value_by_id() {
		$filter = wp_stream_filter_input( INPUT_POST, 'filter' );
		switch ( $filter ) {
			case 'author':
				$id = wp_stream_filter_input( INPUT_POST, 'id' );
				if ( $id === '0' ) {
					$value = 'WP-CLI';
					break;
				}
				$user = get_userdata( $id );
				if ( ! $user || is_wp_error( $user ) ) {
					$value = '';
				} else {
					$value = $user->display_name;
				}
				break;
			default:
				$value = '';
				break;
		}
		echo json_encode( $value );
		wp_die();
	}

	public static function get_authors_record_meta( $authors ) {
		require_once WP_STREAM_INC_DIR . 'class-wp-stream-author.php';

		$authors_records = array();

		foreach ( $authors as $user_id => $args ) {
			$author   = new WP_Stream_Author( $user_id );
			$disabled = isset( $args['disabled'] ) ? $args['disabled'] : null;

			$authors_records[ $user_id ] = array(
				'text'     => $author->get_display_name(),
				'id'       => $user_id,
				'label'    => $author->get_display_name(),
				'icon'     => $author->get_avatar_src( 32 ),
				'title'    => '',
				'disabled' => $disabled,
			);
		}

		return $authors_records;
	}
}
