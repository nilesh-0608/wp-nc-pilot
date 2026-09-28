<?php
/** Elementor page component tools. @package WP_NC_Pilot */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCPilot_REST_Elementor {
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		foreach ( array( 'get', 'add', 'edit', 'remove' ) as $action ) {
			$args = array( 'page_id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) );
			if ( 'get' !== $action ) {
				$args['revision'] = array( 'type' => 'string', 'required' => true );
			}
			if ( in_array( $action, array( 'edit', 'remove' ), true ) ) {
				$args['component_id'] = array( 'type' => 'string', 'required' => true, 'minLength' => 1 );
			}
			if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
				$args['settings'] = array( 'type' => 'object', 'required' => 'edit' === $action );
			}
			if ( 'add' === $action ) {
				$args['el_type'] = array( 'type' => 'string', 'enum' => array( 'container', 'section', 'column', 'widget' ), 'required' => true );
				$args['widget_type'] = array( 'type' => 'string' );
				$args['parent_id'] = array( 'type' => 'string', 'default' => '' );
				$args['position'] = array( 'type' => 'integer', 'minimum' => 0 );
			}
			register_rest_route( NCPILOT_REST_NS, '/elementor/' . $action, array(
				'methods' => 'get' === $action ? 'GET' : 'POST',
				'permission_callback' => array( $this, 'can_edit' ),
				'callback' => function ( $request ) use ( $action ) { return $this->handle( $request, $action ); },
				'args' => $args,
			) );
		}
	}

	public function can_edit( $request ) {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_post', (int) $request['page_id'] );
	}

	private function error( $message, $status = 400 ) {
		return new WP_Error( 'ncpilot_elementor', $message, array( 'status' => $status ) );
	}

	public function handle( $request, $action ) {
		$id = (int) $request['page_id'];
		$post = get_post( $id );
		if ( ! $post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
			return $this->error( 'Choose an existing, non-trashed WordPress page.', 404 );
		}
		if ( ! class_exists( '\Elementor\Plugin' ) || ! did_action( 'elementor/loaded' ) ) {
			return $this->error( 'Elementor must be installed and active.', 409 );
		}
		$document = \Elementor\Plugin::$instance->documents->get( $id );
		if ( ! $document || ! $document->is_built_with_elementor() ) {
			return $this->error( 'Open this page in Elementor and save it first.', 409 );
		}
		if ( ! $document->is_editable_by_current_user() ) {
			return $this->error( 'Your user cannot edit this Elementor document.', 403 );
		}
		$elements = $document->get_elements_data();
		$revision = hash( 'sha256', wp_json_encode( $elements ) );
		if ( 'get' === $action ) {
			return new WP_REST_Response( array( 'page_id' => $id, 'revision' => $revision, 'elements' => $elements ) );
		}
		if ( ! hash_equals( $revision, (string) $request['revision'] ) ) {
			return $this->error( 'The page changed. Read it again before editing.', 409 );
		}
		if ( 'remove' === $action && ! NCPilot_Security::is_enabled( NCPilot_Security::OPT_DELETE ) ) {
			return $this->error( 'Enable the delete-content permission in NC-Pilot to remove Elementor components.', 403 );
		}
		$component_id = (string) $request['component_id'];
		if ( 'add' === $action ) {
			$component_id = \Elementor\Utils::generate_random_string();
			$component = array( 'id' => $component_id, 'elType' => $request['el_type'], 'settings' => (array) $request['settings'], 'elements' => array() );
			if ( 'widget' === $component['elType'] ) {
				$widgets = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
				if ( empty( $request['widget_type'] ) || ! isset( $widgets[ $request['widget_type'] ] ) ) {
					return $this->error( 'Choose a widget type registered by the active Elementor plugins.' );
				}
				$component['widgetType'] = $request['widget_type'];
			}
			$result = $this->insert( $elements, (string) $request['parent_id'], $component, $request['position'] );
		} else {
			$result = $this->modify( $elements, $component_id, $action, (array) $request['settings'] );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return $this->error( 'Component or parent ID was not found.', 404 );
		}
		// Elementor handles sanitization, revisions, CSS invalidation and document caches.
		if ( ! $document->save( array( 'elements' => $elements ) ) ) {
			return $this->error( 'Elementor could not save this page.', 500 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'page_id' => $id, 'component_id' => $component_id, 'action' => $action ) );
	}

	/** Merge settings at control level; preserve other controls, children and IDs. */
	private function modify( &$elements, $id, $action, $settings ) {
		foreach ( $elements as $index => &$element ) {
			if ( $element['id'] === $id ) {
				if ( 'remove' === $action ) {
					array_splice( $elements, $index, 1 );
				} else {
					$element['settings'] = array_replace( (array) $element['settings'], $settings );
				}
				return true;
			}
			if ( ! empty( $element['elements'] ) && $this->modify( $element['elements'], $id, $action, $settings ) ) {
				return true;
			}
		}
		return false;
	}

	private function insert( &$elements, $parent_id, $component, $position, $parent_type = 'root' ) {
		if ( '' === $parent_id ) {
			$allowed = array( 'root' => array( 'container', 'section' ), 'container' => array( 'container', 'widget' ), 'section' => array( 'column' ), 'column' => array( 'widget', 'section' ) );
			if ( ! isset( $allowed[ $parent_type ] ) || ! in_array( $component['elType'], $allowed[ $parent_type ], true ) ) {
				return $this->error( 'This component type cannot be added to the selected parent.' );
			}
			if ( null !== $position && $position > count( $elements ) ) {
				return $this->error( 'Position exceeds the number of children in this parent.' );
			}
			if ( 'section' === $component['elType'] && 'column' === $parent_type ) {
				$component['isInner'] = true;
			}
			array_splice( $elements, null === $position ? count( $elements ) : $position, 0, array( $component ) );
			return true;
		}
		foreach ( $elements as &$element ) {
			if ( $element['id'] === $parent_id ) {
				return $this->insert( $element['elements'], '', $component, $position, $element['elType'] );
			}
			if ( ! empty( $element['elements'] ) ) {
				$result = $this->insert( $element['elements'], $parent_id, $component, $position, $element['elType'] );
				if ( false !== $result ) {
					return $result;
				}
			}
		}
		return false;
	}
}
