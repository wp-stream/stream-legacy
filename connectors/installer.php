<?php

class WP_Stream_Connector_Installer extends WP_Stream_Connector {

	/**
	 * Context name
	 * @var string
	 */
	public static $name = 'installer';

	/**
	 * Actions registered for this context
	 * @var array
	 */
	public static $actions = array(
		'upgrader_process_complete', // plugins::installed | themes::installed
		'activate_plugin', // plugins::activated
		'deactivate_plugin', // plugins::deactivated
		'switch_theme', // themes::activated
		'delete_site_transient_update_themes', // themes::deleted
		'pre_option_uninstall_plugins', // plugins::deleted
		'pre_set_site_transient_update_plugins',
	);

	/**
	 * Return translated context label
	 *
	 * @return string Translated context label
	 */
	public static function get_label() {
		return __( 'Installer', 'stream' );
	}

	/**
	 * Return translated action labels
	 *
	 * @return array Action label translations
	 */
	public static function get_action_labels() {
		return array(
			'installed' => __( 'Installed', 'stream' ),
			'activated' => __( 'Activated', 'stream' ),
			'deactivated' => __( 'Deactivated', 'stream' ),
			'deleted' => __( 'Deleted', 'stream' ),
		);
	}

	/**
	 * Return translated context labels
	 *
	 * @return array Context label translations
	 */
	public static function get_context_labels() {
		return array(
			'plugins' => __( 'Plugins', 'stream' ),
			'themes' => __( 'Themes', 'stream' ),
		);
	}

	/**
	 * Add action links to Stream drop row in admin list screen
	 *
	 * @filter wp_stream_action_links_posts
	 * @param  array $links      Previous links registered
	 * @param  int   $stream_id  Stream drop id
	 * @param  int   $object_id  Object ( post ) id
	 * @return array             Action links
	 */
	public static function action_links( $links, $stream_id, $object_id ) {
		
		return $links;
	}

	/**
	 * Log plugin installations
	 *
	 * @action transition_post_status
	 */
	public static function callback_upgrader_process_complete( $upgrader, $extra ) {
		$type    = $extra['type'];
		$name    = $upgrader->skin->api->name;
		$slug    = $upgrader->skin->api->slug;
		$success = ! is_a( $upgrader->skin->result, 'WP_Error' );
		$error   = $success ? null : reset( $upgrader->skin->result->errors )[0];
		$from    = $upgrader->skin->options['type'];

		if ( ! in_array( $type, array( 'plugin', 'theme' ) ) ) {
			return;
		}
		
		$action  = 'installed';
		$context = $type . 's';
		$message = __( 'Installed %s: %s', 'stream' );

		self::log(
			$message,
			compact( 'type', 'name', 'slug', 'success', 'error', 'from' ),
			null,
			array(
				$context => $action,
				)
		);
	}

	public static function callback_activate_plugin( $slug, $network_wide ) {
		$plugins = get_plugins();
		$name  = $plugins[$slug]['Name'];
		$network_wide = $network_wide ? 'network wide' : ''; 
		self::log(
			__( 'Activated plugin: %s %s', 'stream' ),
			compact( 'name', 'network_wide', 'slug' ),
			null,
			array( 'plugins' => 'activated' )
			);
	}

	public static function callback_deactivate_plugin( $slug, $network_wide ) {
		$plugins = get_plugins();
		$name  = $plugins[$slug]['Name'];
		$network_wide = $network_wide ? 'network wide' : ''; 
		self::log(
			__( 'Deactivated plugin: %s %s', 'stream' ),
			compact( 'name', 'network_wide', 'slug' ),
			null,
			array( 'plugins' => 'deactivated' )
			);
	}

	public static function callback_switch_theme( $name, $theme ) {
		self::log(
			__( 'Activated theme: %s', 'stream' ),
			compact( 'name', 'theme' ),
			null,
			array( 'themes' => 'activated' )
			);
	}

	public static function callback_delete_site_transient_update_themes() {
		$stylesheet = filter_input( INPUT_GET, 'stylesheet' );
		if ( filter_input( INPUT_GET, 'action' ) != 'delete' || ! $stylesheet ) {
			return;
		}
		$theme = $GLOBALS['theme'];
		$name  = $theme['Name'];
		self::log(
			__( 'Deleted theme: %s', 'stream' ),
			compact( 'name', 'stylesheet' ),
			null,
			array( 'themes' => 'deleted' )
			);
	}

	public static function callback_pre_option_uninstall_plugins() {
		global $plugins;
		if ( filter_input( INPUT_GET, 'action' ) != 'delete-selected' ) {
			return;
		}
		$_plugins = get_plugins();
		foreach ( $plugins as $plugin ) {
			$plugins_to_delete[$plugin] = $_plugins[$plugin];
		}
		
		update_option( 'wp_stream_plugins_to_delete', $plugins_to_delete );
		return false;
	}

	public static function callback_pre_set_site_transient_update_plugins( $current ) {
		if ( ! filter_input( INPUT_POST, 'verify-delete' ) ) {
			return false;
		}
		$plugins_to_delete = get_option( 'wp_stream_plugins_to_delete' );
		if ( empty( $plugins_to_delete ) ) {
			return false;
		}
		foreach ( $plugins_to_delete as $plugin => $data ) {
			$name = $data['Name'];
			$network_wide = $data['Network'] ? 'network wide' : null;
			self::log(
				__( 'Deleted plugin: %s', 'stream' ),
				compact( 'name', 'plugin', 'network_wide' ),
				null,
				array( 'plugins' => 'deleted' )
				);
		}
		delete_option( 'wp_stream_plugins_to_delete' );
		return false;
	}

	

}