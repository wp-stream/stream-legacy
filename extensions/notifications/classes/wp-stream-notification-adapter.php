<?php

abstract class WP_Stream_Notification_Adapter {

	public $params = array();

	public static function register( $title ) {
		$class = get_called_class();
		$name  = strtolower( str_replace( 'WP_Stream_Notification_Adapter_', '', $class ) );
		WP_Stream_Notifications::register_adapter( $class, $name, $title );
	}

	public static function fields() {
		return array();
	}

	public static function hints() {
		return '';
	}

	/**
	 * Replace placeholders in alert[field]s with proper info from the log
	 * @param  string $haystack Text to replace in
	 * @param  array  $log      Log array
	 * @return string
	 */
	public static function replace( $haystack, $log ) {
		if ( preg_match_all( '#{([^}]+)}#', $haystack, $placeholders ) ) {

			foreach ( $placeholders[1] as $placeholder ) {
				$value = false;
				switch ( $placeholder ) {
					case 'summary':
					case 'object_id':
					case 'author':
					case 'ip':
					case 'created':
						$value = $log[ $placeholder ];
						break;
					case 'connector':
						$value = WP_Stream_Connectors::$term_labels['stream_connector'][ $log[ $placeholder ] ];
						break;
					case 'context':
						$value = WP_Stream_Connectors::$term_labels['stream_context'][ key( $log['contexts'] ) ];
						break;
					case 'action':
						$value = WP_Stream_Connectors::$term_labels['stream_action'][ reset( $log['contexts'] ) ];
						break;
					case ( false !== strpos( $placeholder, 'meta.' ) ):
						$meta_key = substr( $placeholder, 5 );
						if ( isset( $log['meta'][ $meta_key ] ) ) {
							$value = $log['meta'][ $meta_key ];
						}
						break;
					case ( false !== strpos( $placeholder, 'author.' ) ):
						$meta_key = substr( $placeholder, 7 );
						$author = get_userdata( $log['author'] );
						if ( $author && isset( $author->{$meta_key} ) ) {
							$value = $author->{$meta_key};
						}
						break;
					// TODO Move this part to Stream base, and abstract it
					case ( false !== strpos( $placeholder, 'object.' ) ):
						$meta_key = substr( $placeholder, 7 );
						$context = key( $log['contexts'] );
						// can only guess the object type, since there is no
						// actual reference here
						switch ( $context ) {
							case 'post':
							case 'page':
							case 'media':
								$object = get_post( $log['object_id'] );
								break;
							case 'users':
								$object = get_userdata( $log['object_id'] );
								break;
							case 'comment':
								$object = get_comment( $log['object_id'] );
								break;
							case 'term':
							case 'category':
							case 'post_tag':
							case 'link_category':
								$object = get_term( $log['object_id'], $log['meta']['taxonomy'] );
								break;
							default:
								$object = apply_filters( 'stream_notifications_record_object', $log['object_id'], $log );
								break;
						}
						if ( is_object( $object ) && isset( $object->{$meta_key} ) ) {
							$value = $object->{$meta_key};
						}
						break;
				}
				if ( $value ) {
					$haystack = str_replace( "{{$placeholder}}", $value, $haystack );
				}
			}
		}
		return $haystack;
	}

	function load( $alert ) {
		$params = array();
		$fields = $this::fields();
		foreach ( $fields as $field => $options ) {
			$params[ $field ] = isset( $alert[ $field ] )
				? $alert[ $field ]
				: null;
		}
		$this->params = $params;
		return $this;
	}

	abstract function send( $log );

}
