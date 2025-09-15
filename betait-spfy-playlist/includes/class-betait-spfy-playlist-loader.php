<?php
/**
 * Loader for registering actions and filters in BeTA iT – Spotify Playlist.
 *
 * This class collects hooks (actions and filters) across the plugin and
 * registers them with WordPress in a single place. It keeps the core class
 * and feature classes tidy by delegating to this loader.
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Betait_Spfy_Playlist_Loader
 *
 * Usage:
 *   $loader = new Betait_Spfy_Playlist_Loader();
 *   $loader->add_action( 'init', $obj, 'method_name' );
 *   $loader->add_filter( 'the_title', $obj, 'filter_method', 10, 1 );
 *   $loader->run();
 */
class Betait_Spfy_Playlist_Loader {

	/**
	 * Actions queued for registration.
	 *
	 * @var array<int, array{hook:string, component:object, callback:string, priority:int, accepted_args:int}>
	 */
	protected $actions = array();

	/**
	 * Filters queued for registration.
	 *
	 * @var array<int, array{hook:string, component:object, callback:string, priority:int, accepted_args:int}>
	 */
	protected $filters = array();

	/**
	 * Constructor.
	 *
	 * Initializes internal storage.
	 */
	public function __construct() {
		$this->actions = array();
		$this->filters = array();
	}

	/**
	 * Queue an action to be registered with WordPress.
	 *
	 * @param string $hook          WordPress action hook name.
	 * @param object $component     Instance that defines the method.
	 * @param string $callback      Method name on the $component.
	 * @param int    $priority      Hook priority (default 10).
	 * @param int    $accepted_args Number of args passed to the callback (default 1).
	 * @return void
	 */
	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Queue a filter to be registered with WordPress.
	 *
	 * @param string $hook          WordPress filter hook name.
	 * @param object $component     Instance that defines the method.
	 * @param string $callback      Method name on the $component.
	 * @param int    $priority      Hook priority (default 10).
	 * @param int    $accepted_args Number of args passed to the callback (default 1).
	 * @return void
	 */
	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Internal helper to collect hooks in a unified format.
	 *
	 * @param array  $hooks         Current collection (actions or filters).
	 * @param string $hook          WordPress hook name.
	 * @param object $component     Instance with the callback method.
	 * @param string $callback      Method name on the instance.
	 * @param int    $priority      Hook priority.
	 * @param int    $accepted_args Number of args passed to callback.
	 * @return array Updated collection.
	 */
	private function add( $hooks, $hook, $component, $callback, $priority, $accepted_args ) {
		// Basic sanity checks; we still collect the hook to avoid surprises,
		// but these guard against obviously broken inputs.
		$hook     = (string) $hook;
		$callback = (string) $callback;

		$hooks[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => (int) $priority,
			'accepted_args' => (int) $accepted_args,
		);

		return $hooks;
	}

	/**
	 * Register all queued filters and actions with WordPress.
	 *
	 * @return void
	 */
	public function run() {
		foreach ( $this->filters as $hook ) {
			// Warn early if a callback is not callable, but don't fatal.
			if ( function_exists( '_doing_it_wrong' ) && ! is_callable( array( $hook['component'], $hook['callback'] ) ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: hook name, 2: class::method */
						esc_html__( 'Filter "%1$s" callback "%2$s" is not callable.', 'betait-spfy-playlist' ),
						$hook['hook'],
						is_object( $hook['component'] ) ? get_class( $hook['component'] ) . '::' . $hook['callback'] : $hook['callback']
					),
					'1.0.0'
				);
			}

			add_filter(
				$hook['hook'],
				array( $hook['component'], $hook['callback'] ),
				$hook['priority'],
				$hook['accepted_args']
			);
		}

		foreach ( $this->actions as $hook ) {
			// Warn early if a callback is not callable, but don't fatal.
			if ( function_exists( '_doing_it_wrong' ) && ! is_callable( array( $hook['component'], $hook['callback'] ) ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: hook name, 2: class::method */
						esc_html__( 'Action "%1$s" callback "%2$s" is not callable.', 'betait-spfy-playlist' ),
						$hook['hook'],
						is_object( $hook['component'] ) ? get_class( $hook['component'] ) . '::' . $hook['callback'] : $hook['callback']
					),
					'1.0.0'
				);
			}

			add_action(
				$hook['hook'],
				array( $hook['component'], $hook['callback'] ),
				$hook['priority'],
				$hook['accepted_args']
			);
		}
	}
}
