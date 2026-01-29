<?php
/**
 * Spotify Playlist Widget for displaying playlists in sidebars and widget areas.
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/includes
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Widget class for displaying Spotify playlists with mini-player.
 */
class Betait_Spfy_Playlist_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'bspfy_playlist_widget',
			__( 'Spotify Playlist', 'betait-spfy-playlist' ),
			array(
				'description'                 => __( 'Display a Spotify playlist with mini-player', 'betait-spfy-playlist' ),
				'classname'                   => 'bspfy-playlist-widget',
				'show_instance_in_rest'       => true,
				'customize_selective_refresh' => true,
			)
		);
	}

	/**
	 * Front-end display of widget.
	 *
	 * @param array $args     Widget arguments.
	 * @param array $instance Saved values from database.
	 */
	public function widget( $args, $instance ) {
		$playlist_id = ! empty( $instance['playlist_id'] ) ? absint( $instance['playlist_id'] ) : 0;

		if ( ! $playlist_id || 'playlist' !== get_post_type( $playlist_id ) || 'publish' !== get_post_status( $playlist_id ) ) {
			return;
		}

		// Enqueue widget assets directly when widget is rendered (most reliable method).
		$this->enqueue_widget_assets();

		// Parse display options.
		$options = array(
			'show_title'  => isset( $instance['show_title'] ) ? (bool) $instance['show_title'] : true,
			'show_player' => isset( $instance['show_player'] ) ? (bool) $instance['show_player'] : true,
			'show_tracks' => isset( $instance['show_tracks'] ) ? (bool) $instance['show_tracks'] : true,
			'show_footer' => isset( $instance['show_footer'] ) ? (bool) $instance['show_footer'] : true,
			'limit'       => ! empty( $instance['limit'] ) ? absint( $instance['limit'] ) : 0,
		);

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Use shared template.
		$template = plugin_dir_path( dirname( __FILE__ ) ) . 'templates/widget-playlist-template.php';
		if ( file_exists( $template ) ) {
			include $template;
		}

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Enqueue widget-specific assets.
	 * Called when widget is actually rendered to ensure assets load.
	 *
	 * @return void
	 */
	private function enqueue_widget_assets() {
		$plugin_version = defined( 'BETAIT_SPFY_PLAYLIST_VERSION' ) ? BETAIT_SPFY_PLAYLIST_VERSION : '1.0.0';
		$ver = ( defined( 'BSPFY_DEBUG' ) && BSPFY_DEBUG ) ? time() : $plugin_version;

		// Enqueue widget CSS.
		wp_enqueue_style(
			'bspfy-widget',
			plugin_dir_url( dirname( __FILE__ ) ) . 'public/css/betait-spfy-playlist-widget.css',
			array(),
			$ver,
			'all'
		);

		// Enqueue widget JS.
		wp_enqueue_script(
			'bspfy-widget',
			plugin_dir_url( dirname( __FILE__ ) ) . 'public/js/betait-spfy-playlist-widget.js',
			array( 'jquery', 'betait-spfy-playlist' ),
			$ver,
			true
		);
	}

	/**
	 * Back-end widget form.
	 *
	 * @param array $instance Previously saved values from database.
	 * @return string
	 */
	public function form( $instance ) {
		$playlist_id = ! empty( $instance['playlist_id'] ) ? absint( $instance['playlist_id'] ) : 0;
		$show_title  = isset( $instance['show_title'] ) ? (bool) $instance['show_title'] : true;
		$show_player = isset( $instance['show_player'] ) ? (bool) $instance['show_player'] : true;
		$show_tracks = isset( $instance['show_tracks'] ) ? (bool) $instance['show_tracks'] : true;
		$show_footer = isset( $instance['show_footer'] ) ? (bool) $instance['show_footer'] : true;
		$limit       = ! empty( $instance['limit'] ) ? absint( $instance['limit'] ) : 0;

		// Get all published playlists.
		$playlists = get_posts(
			array(
				'post_type'      => 'playlist',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'playlist_id' ) ); ?>">
				<?php esc_html_e( 'Select Playlist:', 'betait-spfy-playlist' ); ?>
			</label>
			<select 
				class="widefat" 
				id="<?php echo esc_attr( $this->get_field_id( 'playlist_id' ) ); ?>" 
				name="<?php echo esc_attr( $this->get_field_name( 'playlist_id' ) ); ?>">
				<option value=""><?php esc_html_e( '-- Select Playlist --', 'betait-spfy-playlist' ); ?></option>
				<?php foreach ( $playlists as $playlist ) : ?>
					<option value="<?php echo esc_attr( $playlist->ID ); ?>" <?php selected( $playlist_id, $playlist->ID ); ?>>
						<?php echo esc_html( $playlist->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<input 
				class="checkbox" 
				type="checkbox" 
				<?php checked( $show_title ); ?> 
				id="<?php echo esc_attr( $this->get_field_id( 'show_title' ) ); ?>" 
				name="<?php echo esc_attr( $this->get_field_name( 'show_title' ) ); ?>" />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_title' ) ); ?>">
				<?php esc_html_e( 'Show playlist title', 'betait-spfy-playlist' ); ?>
			</label>
		</p>

		<p>
			<input 
				class="checkbox" 
				type="checkbox" 
				<?php checked( $show_player ); ?> 
				id="<?php echo esc_attr( $this->get_field_id( 'show_player' ) ); ?>" 
				name="<?php echo esc_attr( $this->get_field_name( 'show_player' ) ); ?>" />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_player' ) ); ?>">
				<?php esc_html_e( 'Show mini-player', 'betait-spfy-playlist' ); ?>
			</label>
		</p>

		<p>
			<input 
				class="checkbox" 
				type="checkbox" 
				<?php checked( $show_tracks ); ?> 
				id="<?php echo esc_attr( $this->get_field_id( 'show_tracks' ) ); ?>" 
				name="<?php echo esc_attr( $this->get_field_name( 'show_tracks' ) ); ?>" />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_tracks' ) ); ?>">
				<?php esc_html_e( 'Show track list', 'betait-spfy-playlist' ); ?>
			</label>
		</p>

		<p>
			<input 
				class="checkbox" 
				type="checkbox" 
				<?php checked( $show_footer ); ?> 
				id="<?php echo esc_attr( $this->get_field_id( 'show_footer' ) ); ?>" 
				name="<?php echo esc_attr( $this->get_field_name( 'show_footer' ) ); ?>" />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_footer' ) ); ?>">
				<?php esc_html_e( 'Show "See Playlist" footer link', 'betait-spfy-playlist' ); ?>
			</label>
		</p>

		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>">
				<?php esc_html_e( 'Track limit (0 = all):', 'betait-spfy-playlist' ); ?>
			</label>
			<input 
				class="widefat" 
				type="number" 
				min="0" 
				step="1" 
				id="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>" 
				name="<?php echo esc_attr( $this->get_field_name( 'limit' ) ); ?>" 
				value="<?php echo esc_attr( $limit ); ?>" />
		</p>
		<?php
	}

	/**
	 * Sanitize widget form values as they are saved.
	 *
	 * @param array $new_instance Values just sent to be saved.
	 * @param array $old_instance Previously saved values from database.
	 * @return array Updated safe values to be saved.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array();

		$instance['playlist_id'] = ! empty( $new_instance['playlist_id'] ) ? absint( $new_instance['playlist_id'] ) : 0;
		$instance['show_title']  = ! empty( $new_instance['show_title'] );
		$instance['show_player'] = ! empty( $new_instance['show_player'] );
		$instance['show_tracks'] = ! empty( $new_instance['show_tracks'] );
		$instance['show_footer'] = ! empty( $new_instance['show_footer'] );
		$instance['limit']       = ! empty( $new_instance['limit'] ) ? absint( $new_instance['limit'] ) : 0;

		return $instance;
	}
}
