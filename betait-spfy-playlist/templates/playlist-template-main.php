<?php
/**
 * Template Part: Playlist Main (tracks grid/list)
 * Context:      Used by the BSPFY playlist template to render the track list.
 *
 * ❖ Overriding in a theme
 * Copy this file to:
 *   /wp-content/themes/your-child-theme/betait-spfy-playlist/playlist-template-main.php
 * (Parent theme also supported; plugin fallback used if none found.)
 *
 * @package   Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/templates
 * @since     2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = get_the_ID();

/**
 * Retrieve saved tracks.
 * Stored as JSON (string) but may already be an array in some installs;
 * handle both, and ensure a clean array in $tracks.
 */
$tracks_meta = get_post_meta( $post_id, '_playlist_tracks', true );
if ( is_string( $tracks_meta ) ) {
	$tracks = json_decode( $tracks_meta, true );
} elseif ( is_array( $tracks_meta ) ) {
	$tracks = $tracks_meta;
} else {
	$tracks = array();
}

if ( ! is_array( $tracks ) ) {
	$tracks = array();
}

/**
 * Theme selection (card | list), filterable for customization.
 */
$theme   = get_option( 'bspfy_playlist_theme', 'card' );
$theme   = apply_filters( 'bspfy_playlist_theme', $theme, $post_id );
$is_list = ( 'list' === $theme );

/**
 * Allow last-minute mutation (e.g., sorting or slicing) before render.
 *
 * @param array $tracks  Track objects from Spotify (as saved).
 * @param int   $post_id Current post ID.
 */
$tracks = apply_filters( 'bspfy_playlist_tracks_before_render', $tracks, $post_id );

if ( ! empty( $tracks ) ) :

	do_action( 'bspfy_before_tracks', $post_id, $tracks );

	echo '<div class="bspfy-playlist-container">';

	// Outer wrapper: always has .bspfy-playlist-grid for JS hooks,
	// plus a modifier class for visual theme.
	printf(
		'<div class="bspfy-playlist-grid %s" data-theme="%s">',
		$is_list ? 'is-list' : 'is-card',
		esc_attr( $theme )
	);

	foreach ( $tracks as $track ) :
		// Safe accessors with defaults
		$track_name      = isset( $track['name'] ) ? (string) $track['name'] : __( 'Unknown Track', 'betait-spfy-playlist' );
		$album_name      = isset( $track['album']['name'] ) ? (string) $track['album']['name'] : __( 'Unknown Album', 'betait-spfy-playlist' );
		$artist_name     = isset( $track['artists'][0]['name'] ) ? (string) $track['artists'][0]['name'] : __( 'Unknown Artist', 'betait-spfy-playlist' );
		$track_url_raw   = isset( $track['external_urls']['spotify'] ) ? (string) $track['external_urls']['spotify'] : '#';
		$album_url_raw   = isset( $track['album']['external_urls']['spotify'] ) ? (string) $track['album']['external_urls']['spotify'] : '#';
		$artist_url_raw  = isset( $track['artists'][0]['external_urls']['spotify'] ) ? (string) $track['artists'][0]['external_urls']['spotify'] : '#';
		$track_uri_raw   = isset( $track['uri'] ) ? (string) $track['uri'] : '';
		$cover_url_raw   = isset( $track['album']['images'][0]['url'] ) ? (string) $track['album']['images'][0]['url'] : '';

		// Escaped values
		$track_name_e    = esc_html( $track_name );
		$album_name_e    = esc_html( $album_name );
		$artist_name_e   = esc_html( $artist_name );
		$track_url       = esc_url( $track_url_raw );
		$album_url       = esc_url( $album_url_raw );
		$artist_url      = esc_url( $artist_url_raw );
		$track_uri       = esc_attr( $track_uri_raw );
		$track_cover_url = esc_url( $cover_url_raw );

		if ( $is_list ) : ?>
			<!-- LIST THEME: row layout with small thumb and kebab menu -->
			<div class="bspfy-track-item bspfy-list-item"
				 data-uri="<?php echo $track_uri; ?>"
				 data-album-cover="<?php echo $track_cover_url; ?>">

				<div class="bspfy-list-left">
					<button type="button"
							class="bspfy-play-icon"
							data-uri="<?php echo $track_uri; ?>"
							aria-label="<?php esc_attr_e( 'Play', 'betait-spfy-playlist' ); ?>">
						<i class="fas fa-play" aria-hidden="true"></i>
					</button>

					<?php if ( $track_cover_url ) : ?>
						<img class="bspfy-list-thumb"
							 src="<?php echo $track_cover_url; ?>"
							 loading="lazy"
							 decoding="async"
							 alt="">
					<?php endif; ?>
				</div>

				<div class="bspfy-list-center">
					<div class="bspfy-list-title bspfy-one-line bspfy-scroll"><?php echo $track_name_e; ?></div>
					<div class="bspfy-list-meta bspfy-one-line bspfy-scroll">
						<a href="<?php echo $artist_url; ?>" target="_blank" rel="noopener noreferrer"><?php echo $artist_name_e; ?></a>
					</div>
				</div>

				<div class="bspfy-list-right">
					<button type="button"
							class="bspfy-more"
							aria-haspopup="menu"
							aria-expanded="false"
							aria-label="<?php esc_attr_e( 'More actions', 'betait-spfy-playlist' ); ?>">
						<i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
					</button>
					<div class="bspfy-more-menu" role="menu" hidden>
						<a role="menuitem" class="bspfy-action-link" href="<?php echo $artist_url; ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View artist', 'betait-spfy-playlist' ); ?></a>
						<a role="menuitem" class="bspfy-action-link" href="<?php echo $album_url; ?>"  target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View album', 'betait-spfy-playlist' ); ?></a>
						<a role="menuitem" class="bspfy-action-link" href="<?php echo $track_url; ?>"  target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open in Spotify', 'betait-spfy-playlist' ); ?></a>
					</div>
				</div>
			</div>
		<?php else : ?>
			<!-- CARD THEME: background cover with controls -->
			<div class="bspfy-track-item bspfy-track-griditem"
				 data-uri="<?php echo $track_uri; ?>"
				 data-album-cover="<?php echo $track_cover_url; ?>"
				 <?php if ( $track_cover_url ) : ?>
					style="background-image:url('<?php echo $track_cover_url; ?>');background-size:cover;background-blend-mode:color-burn;"
				 <?php endif; ?>
			>
				<div class="track-info">
					<strong><span class="bspfy-track-title bspfy-one-line bspfy-scroll"><?php echo $track_name_e; ?></span></strong>
					<p>
						<span class="bspfy-track-album bspfy-one-line bspfy-scroll"><?php echo $album_name_e; ?></span>
						<span class="bspfy-track-artist bspfy-one-line bspfy-scroll"> - <?php echo $artist_name_e; ?></span>
					</p>
				</div>

				<div class="track-actions" aria-label="<?php esc_attr_e( 'Track actions', 'betait-spfy-playlist' ); ?>">
					<a href="<?php echo $artist_url; ?>" target="_blank" class="bspfy-action-icon" rel="noopener noreferrer">
						<i class="fas fa-user" title="<?php esc_attr_e( 'View Artist', 'betait-spfy-playlist' ); ?>"></i>
						<span class="screen-reader-text"><?php esc_html_e( 'View Artist', 'betait-spfy-playlist' ); ?></span>
					</a>
					<a href="<?php echo $album_url; ?>" target="_blank" class="bspfy-action-icon" rel="noopener noreferrer">
						<i class="fas fa-compact-disc" title="<?php esc_attr_e( 'View Album', 'betait-spfy-playlist' ); ?>"></i>
						<span class="screen-reader-text"><?php esc_html_e( 'View Album', 'betait-spfy-playlist' ); ?></span>
					</a>
					<a href="<?php echo $track_url; ?>" target="_blank" class="bspfy-action-icon" rel="noopener noreferrer">
						<i class="fas fa-music" title="<?php esc_attr_e( 'View Track', 'betait-spfy-playlist' ); ?>"></i>
						<span class="screen-reader-text"><?php esc_html_e( 'View Track', 'betait-spfy-playlist' ); ?></span>
					</a>
					<button type="button"
							class="bspfy-play-icon"
							data-uri="<?php echo $track_uri; ?>"
							aria-label="<?php esc_attr_e( 'Play', 'betait-spfy-playlist' ); ?>">
						<i class="fas fa-play" aria-hidden="true"></i>
					</button>
				</div>
			</div>
		<?php
		endif;
	endforeach;

	echo '</div>'; // /.bspfy-playlist-grid
	echo '</div>'; // /.bspfy-playlist-container

	do_action( 'bspfy_after_tracks', $post_id, $tracks );

else :
	// No tracks
	echo '<p>' . esc_html__( 'No tracks found in this playlist.', 'betait-spfy-playlist' ) . '</p>';
endif;
