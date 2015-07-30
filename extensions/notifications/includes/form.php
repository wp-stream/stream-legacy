<?php

class WP_Stream_Notifications_Form {

	function __construct() {
		// AJAX end point for form auto completion
		add_action( 'wp_ajax_stream_notification_endpoint', array( $this, 'form_ajax_ep' ) );
		add_action( 'wp_ajax_stream-notifications-reset-occ', array( $this, 'ajax_reset_occ' ) );

		// Enqueue our form scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ), 11 );

		// define `search_in` arg for WP_User_Query
		add_filter( 'user_search_columns', array( $this, 'define_search_in_arg' ), 10, 3 );
	}

	public function load() {
		$view = wp_stream_filter_input( INPUT_GET, 'view' );

		// Control screen layout
		if ( 'rule' === $view ) {
			add_screen_option( 'layout_columns', array( 'max' => 2, 'default' => 2 ) );

			// Register metaboxes
			add_meta_box(
				'triggers',
				esc_html__( 'Triggers', 'stream-notifications' ),
				array( $this, 'metabox_triggers' ),
				WP_Stream_Notifications::$screen_id,
				'normal'
			);
			add_meta_box(
				'alerts',
				esc_html__( 'Alerts', 'stream-notifications' ),
				array( $this, 'metabox_alerts' ),
				WP_Stream_Notifications::$screen_id,
				'normal'
			);
			add_meta_box(
				'submitdiv',
				esc_html__( 'Save', 'stream-notifications' ),
				array( $this, 'metabox_save' ),
				WP_Stream_Notifications::$screen_id,
				'side'
			);
			add_meta_box(
				'data-tags',
				esc_html__( 'Data Tags', 'stream-notifications' ),
				array( $this, 'metabox_data_tags' ),
				WP_Stream_Notifications::$screen_id,
				'side'
			);
		}
	}

	/**
	 * Enqueue our scripts, in our own page only
	 *
	 * @action admin_enqueue_scripts
	 * @param  string $hook Current admin page slug
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		if ( WP_Stream_Notifications::$screen_id !== $hook || 'rule' !== wp_stream_filter_input( INPUT_GET, 'view' ) ) {
			return;
		}

		$view = wp_stream_filter_input( INPUT_GET, 'view', FILTER_DEFAULT, array( 'options' => array( 'default' => 'list' ) ) );

		if ( 'rule' === $view ) {
			wp_enqueue_script( 'dashboard' );
			wp_enqueue_style( 'select2' );
			wp_enqueue_script( 'select2' );
			wp_enqueue_script( 'underscore' );
			wp_enqueue_style( 'jquery-ui' );
			wp_enqueue_script( 'jquery-ui-datepicker' );
			wp_enqueue_style( 'wp-stream-datepicker' );
			wp_enqueue_script( 'jquery-ui-accordion' );
			wp_enqueue_script( 'accordion' );
			wp_enqueue_style( 'stream-notifications-form', WP_STREAM_NOTIFICATIONS_URL . '/ui/css/form.css' );
			wp_enqueue_script( 'stream-notifications-form', WP_STREAM_NOTIFICATIONS_URL . '/ui/js/form.js', array( 'underscore', 'select2' ) );
			wp_localize_script( 'stream-notifications-form', 'stream_notifications', $this->get_js_options() );
		}
	}


	/**
	 * Callback for form AJAX operations
	 *
	 * @action wp_ajax_stream_notifications_endpoint
	 * @return void
	 */
	public function form_ajax_ep() {
		$type      = wp_stream_filter_input( INPUT_POST, 'type' );
		$is_single = wp_stream_filter_input( INPUT_POST, 'single' );
		$query     = wp_stream_filter_input( INPUT_POST, 'q' );
		$args      = json_decode( wp_stream_filter_input( INPUT_POST, 'args' ), true );

		if ( ! is_array( $args ) ) {
			$args = array();
		}

		if ( $is_single ) {
			switch ( $type ) {
				case 'author':
				case 'post_author':
				case 'user':
					$user_ids   = explode( ',', $query );
					$user_query = new WP_User_Query(
						array(
							'include' => $user_ids,
							'fields'  => array( 'ID', 'user_email', 'display_name' ),
						)
					);
					if ( $user_query->results ) {
						$data = $this->format_json_for_select2(
							$user_query->results,
							'ID',
							'display_name'
						);
					} else {
						$data = array();
					}
					break;
				case 'post':
				case 'post_parent':
					$args  = array(
						'post_type' => 'any',
						'post_status' => 'any',
						'posts_per_page' => -1,
						'post__in' => explode( ',', $query ),
					);
					$posts = get_posts( $args );
					$items = array_combine( wp_list_pluck( $posts, 'ID' ), wp_list_pluck( $posts, 'post_title' ) );
					$data  = $this->format_json_for_select2( $items );
					break;
				case 'tax':
					$items  = get_taxonomies( null, 'objects' );
					$items  = wp_list_pluck( $items, 'labels' );
					$items  = wp_list_pluck( $items, 'name' );
					$query  = explode( ',', $query );
					$chosen = array_intersect_key( $items, array_flip( $query ) );
					$data   = $this->format_json_for_select2( $chosen );
					break;
				case 'term':
				case 'term_parent':
					$tax   = isset( $args['tax'] ) ? $args['tax'] : null;
					$query = explode( ',', $query );
					$terms = $this->get_terms( $query, $tax );
					$data  = $this->format_json_for_select2( $terms );
					break;
			}
		} else {
			switch ( $type ) {
				case 'author':
				case 'post_author':
				case 'user':
					$users = get_users(
						array(
							'search'    => '*' . $query . '*',
							'search_in' => array(
								'user_login',
								'display_name',
								'user_email',
								'user_nicename',
							),
							'meta_key'  => ( isset( $args['push'] ) && $args['push'] ) ? 'ckpn_user_key' : null,
						)
					);
					$data = $this->format_json_for_select2( $users, 'ID', 'display_name' );
					break;
				case 'action':
				case 'context':
					$items = WP_Stream_Connectors::$term_labels['stream_' . $type];
					$items = preg_grep( sprintf( '/%s/i', $query ), $items );
					$data  = $this->format_json_for_select2( $items );
					break;
				case 'post':
				case 'post_parent':
					$posts = get_posts( 'post_type=any&post_status=any&posts_per_page=-1&s=' . $query );
					$items = array_combine( wp_list_pluck( $posts, 'ID' ), wp_list_pluck( $posts, 'post_title' ) );
					$data  = $this->format_json_for_select2( $items );
					break;
				case 'tax':
					$items = get_taxonomies( null, 'objects' );
					$items = wp_list_pluck( $items, 'labels' );
					$items = wp_list_pluck( $items, 'name' );
					$items = preg_grep( sprintf( '/%s/i', $query ), $items );
					$data  = $this->format_json_for_select2( $items );
					break;
				case 'term':
				case 'term_parent':
					$tax   = isset( $args['tax'] ) ? $args['tax'] : null;
					$terms = $this->get_terms( $query, $tax );
					$data  = $this->format_json_for_select2( $terms );
					break;
			}
		}

		// Add gravatar for authors
		if ( 'author' === $type && get_option( 'show_avatars' ) ) {
			foreach ( $data as $i => $item ) {
				if ( $avatar = get_avatar( $item['id'], 20 ) ) {
					$item['avatar'] = $avatar;
				}
				$data[$i] = $item;
			}
		}

		if ( ! empty( $data )  ) {
			wp_send_json_success( $data );
		} else {
			wp_send_json_error();
		}
	}

	/**
	 * Take an (associative) array and format it for select2 AJAX result parser
	 * @param  array  $data (associative) Data array
	 * @param  string $key  Key of the ID column, null if associative array
	 * @param  string $val  Key of the Title column, null if associative array
	 * @return array        Formatted array, [ { id: %, title: % }, .. ]
	 */
	public function format_json_for_select2( $data, $key = null, $val = null ) {
		$return = array();
		if ( is_null( $key ) && is_null( $val ) ) { // for flat associative array
			$keys = array_keys( $data );
			$vals = array_values( $data );
		} else {
			$keys = wp_list_pluck( $data, $key );
			$vals = wp_list_pluck( $data, $val );
		}
		foreach ( $keys as $idx => $key ) {
			$return[] = array(
				'id'   => $key,
				'text' => $vals[$idx],
			);
		}
		return $return;
	}

	public function ajax_reset_occ() {
		$id    = wp_stream_filter_input( INPUT_GET, 'id' );
		$nonce = wp_stream_filter_input( INPUT_GET, 'wp_stream_nonce' );

		if ( ! wp_verify_nonce( $nonce, 'reset-occ_' . $id ) ) {
			wp_send_json_error( esc_html__( 'Invalid nonce', 'stream-notifications' ) );
		}

		if ( empty( $id ) || (int) $id !== $id ) {
			wp_send_json_error( esc_html__( 'Invalid record ID', 'stream-notifications' ) );
		}

		wp_stream_update_meta( $id, 'occurrences', 0 );
		wp_send_json_success();
	}

	/**
	 * Format JS options for the form, to be used with wp_localize_script
	 *
	 * @return array  Options for our form JS handling
	 */
	public function get_js_options() {
		global $wp_roles;
		$args = array();

		$connectors = WP_Stream_Connectors::$term_labels['stream_connector'];
		asort( $connectors );

		$roles     = $wp_roles->roles;
		$roles_arr = array_combine( array_keys( $roles ), wp_list_pluck( $roles, 'name' ) );

		$default_operators = array(
			'='   => esc_html__( 'is', 'stream-notifications' ),
			'!='  => esc_html__( 'is not', 'stream-notifications' ),
			'in'  => esc_html__( 'is in', 'stream-notifications' ),
			'!in' => esc_html__( 'is not in', 'stream-notifications' ),
		);

		$text_operator = array(
			'='         => esc_html__( 'is', 'stream-notifications' ),
			'!='        => esc_html__( 'is not', 'stream-notifications' ),
			'contains'  => esc_html__( 'contains', 'stream-notifications' ),
			'!contains' => esc_html__( 'does not contain', 'stream-notifications' ),
			'starts'    => esc_html__( 'starts with', 'stream-notifications' ),
			'ends'      => esc_html__( 'ends with', 'stream-notifications' ),
			'regex'     => esc_html__( 'regex', 'stream-notifications' ),
		);

		$numeric_operators = array(
			'='  => esc_html__( 'equals', 'stream-notifications' ),
			'!=' => esc_html__( 'not equal', 'stream-notifications' ),
			'<'  => esc_html__( 'less than', 'stream-notifications' ),
			'<=' => esc_html__( 'equal or less than', 'stream-notifications' ),
			'>'  => esc_html__( 'greater than', 'stream-notifications' ),
			'>=' => esc_html__( 'equal or greater than', 'stream-notifications' ),
		);

		$args['types'] = array(
			'search' => array(
				'title'     => esc_html__( 'Summary', 'stream-notifications' ),
				'type'      => 'text',
				'operators' => $text_operator,
			),
			'object_id' => array(
				'title'     => esc_html__( 'Object ID', 'stream-notifications' ),
				'type'      => 'text',
				'tags'      => true,
				'operators' => $default_operators,
			),

			'author_role' => array(
				'title'     => esc_html__( 'Author Role', 'stream-notifications' ),
				'type'      => 'select',
				'multiple'  => true,
				'operators' => $default_operators,
				'options' => $roles_arr,
			),

			'author' => array(
				'title'     => esc_html__( 'Author', 'stream-notifications' ),
				'type'      => 'text',
				'ajax'      => true,
				'operators' => $default_operators,
			),

			'ip' => array(
				'title'     => esc_html__( 'IP', 'stream-notifications' ),
				'type'      => 'text',
				'subtype'   => 'ip',
				'tags'      => true,
				'operators' => $default_operators,
			),

			'date' => array(
				'title'     => esc_html__( 'Date', 'stream-notifications' ),
				'type'      => 'date',
				'operators' => array(
					'='  => esc_html__( 'is on', 'stream-notifications' ),
					'!=' => esc_html__( 'is not on', 'stream-notifications' ),
					'<'  => esc_html__( 'is before', 'stream-notifications' ),
					'<=' => esc_html__( 'is on or before', 'stream-notifications' ),
					'>'  => esc_html__( 'is after', 'stream-notifications' ),
					'>=' => esc_html__( 'is on or after', 'stream-notifications' ),
				),
			),

			'weekday' => array(
				'title'     => esc_html__( 'Day of Week', 'stream-notifications' ),
				'type'      => 'select',
				'multiple'  => true,
				'operators' => $default_operators,
				'options'   => array_combine(
					array_map(
						function( $weekday_index ) {
							return sprintf( 'weekday_%d', $weekday_index % 7 );
						},
						range( get_option( 'start_of_week' ), get_option( 'start_of_week' ) + 6 )
					),
					array_map(
						function( $weekday_index ) {
							global $wp_locale;

							return $wp_locale->get_weekday ( $weekday_index % 7 );
						},
						range( get_option( 'start_of_week' ), get_option( 'start_of_week' ) + 6 )
					)
				),
			),

			// TODO: find a way to introduce meta to the rules, problem: not translatable since it is
			// generated on run time with no prior definition
			// 'meta_query'            => array(),

			'connector' => array(
				'title'     => esc_html__( 'Connector', 'stream-notifications' ),
				'type'      => 'select',
				'multiple'  => true,
				'operators' => $default_operators,
				'options'   => $connectors,
			),
			'context' => array(
				'title'     => esc_html__( 'Context', 'stream-notifications' ),
				'type'      => 'select',
				'multiple'  => true,
				'operators' => $default_operators,
				'options'   => WP_Stream_Connectors::$term_labels['stream_context'],
			),
			'action' => array(
				'title'     => esc_html__( 'Action', 'stream-notifications' ),
				'type'      => 'select',
				'multiple'  => true,
				'operators' => $default_operators,
				'options'   => WP_Stream_Connectors::$term_labels['stream_action'],
			),
		);

		// Connector-based triggers
		$args['special_types'] = array(
			'post' => array(
				'title'     => esc_html__( '- Post', 'stream-notifications' ),
				'type'      => 'text',
				'ajax'      => true,
				'connector' => 'posts',
				'operators' => $default_operators,
			),
			'post_title' => array(
				'title'     => esc_html__( '- Post: Title', 'stream-notifications' ),
				'type'      => 'text',
				'connector' => 'posts',
				'operators' => $text_operator,
			),
			'post_slug' => array(
				'title'     => esc_html__( '- Post: Slug', 'stream-notifications' ),
				'type'      => 'text',
				'connector' => 'posts',
				'operators' => $text_operator,
			),
			'post_content' => array(
				'title'     => esc_html__( '- Post: Content', 'stream-notifications' ),
				'type'      => 'text',
				'connector' => 'posts',
				'operators' => $text_operator,
			),
			'post_excerpt' => array(
				'title'     => esc_html__( '- Post: Excerpt', 'stream-notifications' ),
				'type'      => 'text',
				'connector' => 'posts',
				'operators' => $text_operator,
			),
			'post_author' => array(
				'title'     => esc_html__( '- Post: Author', 'stream-notifications' ),
				'type'      => 'text',
				'ajax'      => true,
				'connector' => 'posts',
				'operators' => $default_operators,
			),
			'post_status' => array(
				'title'     => esc_html__( '- Post: Status', 'stream-notifications' ),
				'type'      => 'select',
				'connector' => 'posts',
				'options'   => wp_list_pluck( $GLOBALS['wp_post_statuses'], 'label' ),
				'operators' => $default_operators,
			),
			'post_format' => array(
				'title'     => esc_html__( '- Post: Format', 'stream-notifications' ),
				'type'      => 'select',
				'connector' => 'posts',
				'options'   => get_post_format_strings(),
				'operators' => $default_operators,
			),
			'post_parent' => array(
				'title'     => esc_html__( '- Post: Parent', 'stream-notifications' ),
				'type'      => 'text',
				'ajax'      => true,
				'connector' => 'posts',
				'operators' => $default_operators,
			),
			'post_thumbnail' => array(
				'title'     => esc_html__( '- Post: Featured Image', 'stream-notifications' ),
				'type'      => 'select',
				'connector' => 'posts',
				'options'   => array(
					'0' => esc_html__( 'None', 'stream-notifications' ),
					'1' => esc_html__( 'Has one', 'stream-notifications' )
				),
				'operators' => $default_operators,
			),
			'post_comment_status' => array(
				'title'     => esc_html__( '- Post: Comment Status', 'stream-notifications' ),
				'type'      => 'select',
				'connector' => 'posts',
				'options'   => array(
					'open'   => esc_html__( 'Open', 'stream-notifications' ),
					'closed' => esc_html__( 'Closed', 'stream-notifications' )
				),
				'operators' => $default_operators,
			),
			'post_comment_count' => array(
				'title'     => esc_html__( '- Post: Comment Count', 'stream-notifications' ),
				'type'      => 'text',
				'connector' => 'posts',
				'operators' => $numeric_operators,
			),
			'user' => array(
				'title'     => esc_html__( '- User', 'stream-notifications' ),
				'type'      => 'text',
				'ajax'      => true,
				'connector' => 'users',
				'operators' => $default_operators,
			),
			'user_role' => array(
				'title'     => esc_html__( '- User: Role', 'stream-notifications' ),
				'type'      => 'select',
				'connector' => 'users',
				'options'   => $roles_arr,
				'operators' => $default_operators,
			),
			'tax' => array(
				'title'     => esc_html__( '- Taxonomy', 'stream-notifications' ),
				'type'      => 'text',
				'ajax'      => true,
				'connector' => 'taxonomies',
				'operators' => $default_operators,
			),
			'term' => array(
				'title'     => esc_html__( '- Term', 'stream-notifications' ),
				'type'      => 'text',
				'ajax'      => true,
				'connector' => 'taxonomies',
				'operators' => $default_operators,
			),
			'term_parent' => array(
				'title'     => esc_html__( '- Term: Parent', 'stream-notifications' ),
				'type'      => 'text',
				'ajax'      => true,
				'connector' => 'taxonomies',
				'operators' => $default_operators,
			),
		);

		$args['adapters'] = array();

		foreach ( WP_Stream_Notifications::$adapters as $name => $options ) {
			$args['adapters'][$name] = array(
				'title'  => $options['title'],
				'fields' => $options['class']::fields(),
				'hints'  => $options['class']::hints(),
			);
		}

		// Localization
		$args['i18n'] = array(
			'empty_triggers'        => esc_html__( 'You cannot save a rule without any triggers.', 'stream-notifications' ),
			'invalid_first_trigger' => esc_html__( 'You cannot save a rule with an empty first trigger.', 'stream-notifications' ),
			'ajax_error'            => esc_html__( 'There was an error submitting your request, please try again.', 'stream-notifications' ),
			'confirm_reset'         => esc_html__( 'Are you sure you want to reset occurrences for this rule? This cannot be undone.', 'stream-notifications' ),
		);

		return apply_filters( 'stream_notification_js_args', $args );
	}

	/**
	 * @filter user_search_columns
	 */
	public function define_search_in_arg( $search_columns, $search, $query ) {
		$search_in      = $query->get( 'search_in' );
		$search_columns = ! is_null( $search_in ) ? (array) $search_in : $search_columns;

		return $search_columns;
	}

	public function get_terms( $search, $taxonomies = array() ) {
		global $wpdb;
		$taxonomies = (array) $taxonomies;

		$sql = "SELECT tt.term_taxonomy_id id, t.name, t.slug, tt.taxonomy, tt.description
			FROM $wpdb->terms t
			JOIN $wpdb->term_taxonomy tt USING ( term_id )
			WHERE
			";

		if ( is_array( $search ) ) {
			$search = array_map( 'intval', $search );
			$where = sprintf( 'tt.term_taxonomy_id IN ( %s )', implode( ', ', $search ) );
		} else {
			$where = '
				t.name LIKE %s
				OR
				t.slug LIKE %s
				OR
				tt.taxonomy LIKE %s
				OR
				tt.description LIKE %s
			';
			$where = $wpdb->prepare( $where, "%$search%", "%$search%", "%$search%", "%$search%" );
		}

		$sql .= $where;
		$results = $wpdb->get_results( $sql );

		$return  = array();
		foreach ( $results as $result ) {
			$return[ $result->id ] = sprintf( '%s - %s', $result->name, $result->taxonomy );
		}
		return $return;
	}

	public function metabox_triggers() {
		?>
		<a class="add-trigger button button-secondary" href="#add-trigger" data-group="0"><?php esc_html_e( '+ Add Trigger', 'stream-notifications' ) ?></a>
		<a class="add-trigger-group button button-primary" href="#add-trigger-group" data-group="0"><?php esc_html_e( '+ Add Group', 'stream-notifications' ) ?></a>
		<div class="group" rel="0"></div>
		<?php
	}

	public function metabox_alerts() {
		?>
		<a class="add-alert button button-secondary" href="#add-alert"><?php esc_html_e( '+ Add Alert', 'stream-notifications' ) ?></a>
		<?php
	}

	public function metabox_save( $rule ) {
		$reset_link = add_query_arg(
			array(
				'action'          => 'stream-notifications-reset-occ',
				'id'              => absint( $rule->ID ),
				'wp_stream_nonce' => wp_create_nonce( 'reset-occ_' . absint( $rule->ID ) ),
			),
			admin_url( 'admin-ajax.php' )
		);
		?>
		<div class="submitbox" id="submitpost">
			<div id="minor-publishing">
				<div id="misc-publishing-actions">
					<div class="misc-pub-section misc-pub-post-status">
						<label for="notification_visibility">
							<input type="checkbox" name="visibility" id="notification_visibility" value="active" <?php checked( ! $rule->exists() || 'active' === $rule->visibility ) ?>>
							<?php esc_html_e( 'Active', 'stream-notifications' ) ?>
						</label>
					</div>
					<?php if ( $rule->exists() ) : ?>
					<div class="misc-pub-section">
						<?php $occ = absint( wp_stream_get_meta( $rule->ID, 'occurrences', true ) ) ?>
						<div class="occurrences">
							<p>
								<?php
								echo sprintf(
									_n(
										'This rule has occurred %1$s time.',
										'This rule has occurred %1$s times.',
										$occ,
										'stream-notifications'
									),
									sprintf( '<strong>%d</strong>', $occ ? $occ : 0 )
								) // xss okay
								?>
							</p>
							<?php if ( 0 !== $occ ) : ?>
								<p>
									<a href="<?php echo esc_url( $reset_link ) ?>" class="button button-secondary reset-occ">
										<?php esc_html_e( 'Reset Count', 'stream-notifications' ) ?>
									</a>
								</p>
							<?php endif; ?>
						</div>
					</div>
					<?php endif; ?>
				</div>
			</div>

			<div id="major-publishing-actions">
				<?php if ( $rule->exists() ) : ?>
					<div id="delete-action">
						<?php
						$delete_link = add_query_arg(
							array(
								'page'            => WP_Stream_Notifications::NOTIFICATIONS_PAGE_SLUG,
								'action'          => 'delete',
								'id'              => absint( $rule->ID ),
								'wp_stream_nonce' => wp_create_nonce( 'delete-record_' . absint( $rule->ID ) ),
							),
							is_network_admin() ? network_admin_url( WP_Stream_Admin::ADMIN_PARENT_PAGE ) : admin_url( WP_Stream_Admin::ADMIN_PARENT_PAGE )
						);
						?>
						<a class="submitdelete deletion" href="<?php echo esc_url( $delete_link ) ?>">
							<?php esc_html_e( 'Delete Permanently', 'stream-notifications' ) ?>
						</a>
					</div>
				<?php endif; ?>

				<div id="publishing-action">
					<span class="spinner"></span>
					<input type="submit" name="publish" id="publish" class="button button-primary button-large" value="<?php $rule->exists() ? esc_attr_e( 'Update', 'stream-notifications' ) : esc_attr_e( 'Save', 'stream-notifications' ) ?>" accesskey="s">
				</div>
				<div class="clear"></div>
			</div>
		</div>
		<?php
	}

	public function metabox_data_tags() {
		$data_tags = array(
			__( 'Basic', 'stream-notifications' ) => array(
				'summary'   => __( 'Summary message of the triggered record.', 'stream-notifications' ),
				'author'    => __( 'User ID of the triggered record author.', 'stream-notifications' ),
				'connector' => __( 'Connector of the triggered record.', 'stream-notifications' ),
				'context'   => __( 'Context of the triggered record.', 'stream-notifications' ),
				'action'    => __( 'Action of the triggered record.', 'stream-notifications' ),
				'created'   => __( 'Timestamp of triggered record.', 'stream-notifications' ),
				'ip'        => __( 'IP of the triggered record author.', 'stream-notifications' ),
				'object_id' => __( 'Object ID of the triggered record.', 'stream-notifications' ),
			),
			__( 'Advanced', 'stream-notifications' ) => array(
				'object.' => __( 'Specific object data of the record, relative to what the object type is:
					<br /><br />
					<strong>{object.post_title}</strong>
					<br />
					<strong>{object.post_excerpt}</strong>
					<br />
					<strong>{object.post_status}</strong>
					<br />
					<a href="http://codex.wordpress.org/Function_Reference/get_userdata#Notes" target="_blank">See Codex for more Post values</a>
					<br /><br />
					<strong>{object.name}</strong>
					<br />
					<strong>{object.taxonomy}</strong>
					<br />
					<strong>{object.description}</strong>
					<br />
					<a href="http://codex.wordpress.org/Function_Reference/get_userdata#Notes" target="_blank">See Codex for more Term values</a>', 'stream-notifications' ),

				'author.' => __( 'Specific user data of the record author:
					<br /><br />
					<strong>{author.display_name}</strong>
					<br />
					<strong>{author.user_email}</strong>
					<br />
					<strong>{author.user_login}</strong>
					<br />
					<a href="http://codex.wordpress.org/Function_Reference/get_userdata#Notes" target="_blank">See Codex for more User values</a>', 'stream-notifications' ),
				'meta.' => __( 'Specific meta data of the record, used to display specific meta values created by Connectors.
					<br /><br />
					Example: <strong>{meta.old_theme}</strong> to display the old theme name when a new theme is activated.', 'stream-notifications' ),
			),
		);
		$allowed_html = array(
			'a'      => array(
				'href'   => array(),
				'target' => array(),
			),
			'code'   => array(),
			'strong' => array(),
			'br'     => array(),
		);
		?>
		<div id="data-tag-glossary" class="accordion-container">
			<ul class="outer-border">
				<?php foreach ( $data_tags as $section => $tags ) : ?>
				<li class="control-section accordion-section">
					<h3 class="accordion-section-title hndle" title="<?php echo esc_attr( $section ) ?>"><?php echo esc_html( $section ) ?></h3>
					<div class="accordion-section-content">
						<div class="inside">
							<dl>
								<?php foreach ( $tags as $tag => $desc ) : ?>
									<dt><code>{<?php echo esc_html( $tag ) ?>}</code></dt>
									<dd><?php echo wp_kses( $desc, $allowed_html ) ?></dd>
								<?php endforeach; ?>
							</dl>
						</div>
					</div>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
