<?php
/** Standalone behavior checks: php tests/elementor.php (no WordPress required). */
namespace Elementor {
	class Plugin { public static $instance; }
	class Utils { public static function generate_random_string() { return 'new1234'; } }
}
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'NCPILOT_REST_NS', 'ncpilot/v1' );
	class WP_Error {
		public $message;
		public function __construct( $code, $message, $data ) { $this->message = $message; }
	}
	class WP_REST_Response {
		public $data;
		public function __construct( $data ) { $this->data = $data; }
	}
	class NCPilot_Security {
		const OPT_DELETE = 'delete';
		public static $enabled = false;
		public static function is_enabled( $key ) { return self::$enabled; }
	}
	function current_user_can( $cap, $id = null ) { return $GLOBALS['allowed']; }
	function get_post( $id ) { return (object) array( 'post_type' => 'page', 'post_status' => 'publish' ); }
	function did_action( $name ) { return true; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][ $route ] = $args; }
	class TestDocument {
		public $elements;
		public $saves = 0;
		public function get( $id ) { return $this; }
		public function is_built_with_elementor() { return true; }
		public function is_editable_by_current_user() { return true; }
		public function get_elements_data() { return $this->elements; }
		public function get_widget_types() { return array( 'heading' => true ); }
		public function save( $data ) { $this->elements = $data['elements']; ++$this->saves; return true; }
	}
	function check( $condition, $message ) {
		if ( ! $condition ) { throw new \Exception( $message ); }
		echo "PASS: $message\n";
	}
	require __DIR__ . '/../nc-pilot/includes/class-rest-elementor.php';
	require __DIR__ . '/../nc-pilot/includes/class-rest-mcp.php';
	$GLOBALS['allowed'] = true;
	$document = new TestDocument();
	$document->elements = array( array( 'id' => 'root', 'elType' => 'container', 'settings' => array(), 'elements' => array(
		array( 'id' => 'heading', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Old', 'align' => 'center' ), 'elements' => array() ),
	) ) );
	\Elementor\Plugin::$instance = (object) array( 'documents' => $document, 'widgets_manager' => $document );
	$api = new NCPilot_REST_Elementor();
	$api->register_routes();
	check( count( $GLOBALS['routes'] ) === 4, 'All four REST routes registered' );
	$specs = new \ReflectionMethod( 'NCPilot_REST_MCP', 'tool_specs' );
	$specs->setAccessible( true );
	$names = array_column( $specs->invoke( new NCPilot_REST_MCP() ), 'name' );
	check( count( array_intersect( $names, array( 'get_elementor_page', 'add_elementor_component', 'edit_elementor_component', 'remove_elementor_component' ) ) ) === 4, 'All four tools advertised' );
	$base = array( 'page_id' => 1, 'component_id' => 'heading', 'settings' => array(), 'parent_id' => '', 'position' => null );
	$read = $api->handle( $base, 'get' );
	$base['revision'] = $read->data['revision'];
	$result = $api->handle( array_replace( $base, array( 'settings' => array( 'title' => 'New' ) ) ), 'edit' );
	check( ! is_wp_error( $result ) && $document->elements[0]['elements'][0]['settings'] === array( 'title' => 'New', 'align' => 'center' ), 'Nested edit preserves unrelated controls' );
	check( is_wp_error( $api->handle( $base, 'edit' ) ) && 1 === $document->saves, 'Stale revision cannot save' );
	$base['revision'] = $api->handle( $base, 'get' )->data['revision'];
	check( is_wp_error( $api->handle( $base, 'remove' ) ) && 1 === $document->saves, 'Removal toggle enforced' );
	$add = array_replace( $base, array( 'el_type' => 'widget', 'widget_type' => 'heading', 'parent_id' => 'root', 'position' => 0 ) );
	check( ! is_wp_error( $api->handle( $add, 'add' ) ) && 'new1234' === $document->elements[0]['elements'][0]['id'], 'Add at requested nested position' );
	$add['revision'] = $api->handle( $base, 'get' )->data['revision'];
	$add['parent_id'] = '';
	check( is_wp_error( $api->handle( $add, 'add' ) ) && 2 === $document->saves, 'Invalid root widget rejected' );
	$add['parent_id'] = 'missing';
	check( is_wp_error( $api->handle( $add, 'add' ) ), 'Missing parent rejected' );
	$add['widget_type'] = 'missing-widget';
	check( is_wp_error( $api->handle( $add, 'add' ) ), 'Unknown widget rejected' );
	$base['revision'] = $add['revision'];
	$base['component_id'] = 'missing';
	check( is_wp_error( $api->handle( $base, 'edit' ) ), 'Missing edit target rejected' );
	NCPilot_Security::$enabled = true;
	$base['component_id'] = 'root';
	check( ! is_wp_error( $api->handle( $base, 'remove' ) ) && array() === $document->elements, 'Container removal includes descendants and saves empty page' );
	$GLOBALS['allowed'] = false;
	check( false === $api->can_edit( $base ), 'Unauthorized user denied' );
}
