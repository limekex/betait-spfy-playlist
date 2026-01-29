<?php
/**
 * Template for displaying Spotify playlist in widgets and shortcodes.
 *
 * Available variables:
 * - $playlist_id : int - The playlist post ID
 * - $options : array - Display options (show_title, show_player, show_tracks, show_footer, limit)
 *
 * @package    Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/templates
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get playlist data.
$playlist_title = get_the_title( $playlist_id );
$tracks_json    = get_post_meta( $playlist_id, '_playlist_tracks', true );
$tracks         = json_decode( $tracks_json, true );

if ( ! is_array( $tracks ) ) {
	$tracks = array();
}

// Apply track limit if set.
if ( $options['limit'] > 0 && count( $tracks ) > $options['limit'] ) {
	$tracks = array_slice( $tracks, 0, $options['limit'] );
}

$playlist_url = get_permalink( $playlist_id );
$widget_id    = 'bspfy-widget-' . uniqid();
?>

<div class="bspfy-widget-playlist" data-playlist-id="<?php echo esc_attr( $playlist_id ); ?>" data-widget-id="<?php echo esc_attr( $widget_id ); ?>">
	
	<?php if ( $options['show_title'] ) : ?>
		<div class="bspfy-widget-title">
			<h3><?php echo esc_html( $playlist_title ); ?></h3>
		</div>
	<?php endif; ?>

	<?php if ( $options['show_player'] && ! empty( $tracks ) ) : ?>
		<div class="bspfy-widget-player" id="<?php echo esc_attr( $widget_id ); ?>-player">
			<div class="bspfy-player-artwork">
				<?php
				$first_track = $tracks[0];
				$album_image = '';
				if ( ! empty( $first_track['album']['images'] ) && is_array( $first_track['album']['images'] ) ) {
					$album_image = $first_track['album']['images'][0]['url'] ?? '';
				}
				?>
				<?php if ( $album_image ) : ?>
					<img 
						src="<?php echo esc_url( $album_image ); ?>" 
						alt="<?php echo esc_attr( $first_track['name'] ?? '' ); ?>"
						class="bspfy-player-thumb"
						id="<?php echo esc_attr( $widget_id ); ?>-artwork" />
				<?php endif; ?>
			</div>
			
			<div class="bspfy-player-info" aria-live="polite" aria-atomic="true">
				<div class="bspfy-player-track-name" id="<?php echo esc_attr( $widget_id ); ?>-track-name">
					<?php echo esc_html( $first_track['name'] ?? '' ); ?>
				</div>
				<div class="bspfy-player-artist-name" id="<?php echo esc_attr( $widget_id ); ?>-artist-name">
					<?php
					if ( ! empty( $first_track['artists'] ) && is_array( $first_track['artists'] ) ) {
						$artist_names = array_map(
							function( $artist ) {
								return $artist['name'];
							},
							$first_track['artists']
						);
						echo esc_html( implode( ', ', $artist_names ) );
					}
					?>
				</div>
			</div>
			
			<div class="bspfy-player-controls">
				<button 
					class="bspfy-player-btn bspfy-btn-prev" 
					aria-label="<?php esc_attr_e( 'Previous track', 'betait-spfy-playlist' ); ?>"
					data-action="prev">
					<i class="fas fa-step-backward"></i>
				</button>
				<button 
					class="bspfy-player-btn bspfy-btn-play" 
					aria-label="<?php esc_attr_e( 'Play', 'betait-spfy-playlist' ); ?>"
					data-action="play">
					<i class="fas fa-play"></i>
				</button>
				<button 
					class="bspfy-player-btn bspfy-btn-next" 
					aria-label="<?php esc_attr_e( 'Next track', 'betait-spfy-playlist' ); ?>"
					data-action="next">
					<i class="fas fa-step-forward"></i>
				</button>
			</div>
			
			<div class="bspfy-player-volume">
				<button class="bspfy-volume-btn" aria-label="<?php esc_attr_e( 'Volume', 'betait-spfy-playlist' ); ?>">
					<i class="fas fa-volume-up"></i>
				</button>
				<input 
					type="range" 
					class="bspfy-volume-slider" 
					min="0" 
					max="100" 
					value="50" 
					aria-label="<?php esc_attr_e( 'Volume control', 'betait-spfy-playlist' ); ?>" />
			</div>
		</div>
	<?php endif; ?>

	<?php if ( $options['show_tracks'] && ! empty( $tracks ) ) : ?>
		<div class="bspfy-widget-tracks">
			<ul class="bspfy-track-list">
				<?php foreach ( $tracks as $index => $track ) : ?>
					<?php
					$track_number   = $index + 1;
					$track_name     = $track['name'] ?? '';
					$track_uri      = $track['uri'] ?? '';
					$track_id       = $track['id'] ?? '';
					$duration_ms    = $track['duration_ms'] ?? 0;
					$duration_min   = floor( $duration_ms / 60000 );
					$duration_sec   = floor( ( $duration_ms % 60000 ) / 1000 );
					$duration_text  = sprintf( '%d:%02d', $duration_min, $duration_sec );
					$album_image    = '';
					
					if ( ! empty( $track['album']['images'] ) && is_array( $track['album']['images'] ) ) {
						// Try smallest image first (index 2), fallback to largest.
						if ( ! empty( $track['album']['images'][2]['url'] ) ) {
							$album_image = $track['album']['images'][2]['url'];
						} elseif ( ! empty( $track['album']['images'][0]['url'] ) ) {
							$album_image = $track['album']['images'][0]['url'];
						}
					}
					
					$artist_names   = array();
					$artist_ids     = array();
					
					if ( ! empty( $track['artists'] ) && is_array( $track['artists'] ) ) {
						$artist_names = array_map(
							function( $artist ) {
								return $artist['name'];
							},
							$track['artists']
						);
						$artist_ids = array_map(
							function( $artist ) {
								return $artist['id'] ?? '';
							},
							$track['artists']
						);
					}
					
					// Build Spotify URLs
					$track_url  = $track_id ? 'https://open.spotify.com/track/' . $track_id : '';
					$artist_url = ! empty( $artist_ids[0] ) ? 'https://open.spotify.com/artist/' . $artist_ids[0] : '';
					$album_id   = $track['album']['id'] ?? '';
					$album_url  = $album_id ? 'https://open.spotify.com/album/' . $album_id : '';
					?>
					<li class="bspfy-track-item" data-track-uri="<?php echo esc_attr( $track_uri ); ?>" data-track-index="<?php echo esc_attr( $index ); ?>">
						<?php if ( $album_image ) : ?>
						<img 
							src="<?php echo esc_url( $album_image ); ?>" 
							alt="<?php echo esc_attr( $track_name ); ?>"
							class="bspfy-track-thumb" />
						<?php endif; ?>
						
						<div class="bspfy-track-info">
							<div class="bspfy-track-name"><?php echo esc_html( $track_name ); ?></div>
							<div class="bspfy-track-artist"><?php echo esc_html( implode( ', ', $artist_names ) ); ?></div>
						</div>
						
						<button 
							class="bspfy-track-play-btn" 
							aria-label="<?php echo esc_attr( sprintf( __( 'Play %s', 'betait-spfy-playlist' ), $track_name ) ); ?>"
							data-track-uri="<?php echo esc_attr( $track_uri ); ?>"
							data-track-index="<?php echo esc_attr( $index ); ?>">
							<i class="fas fa-play"></i>
						</button>
						
						<button type="button"
								class="bspfy-track-more"
								aria-haspopup="menu"
								aria-expanded="false"
								aria-label="<?php esc_attr_e( 'More actions', 'betait-spfy-playlist' ); ?>">
							<i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
						</button>
						<div class="bspfy-track-more-menu" role="menu" hidden>
							<?php if ( $artist_url ) : ?>
								<a role="menuitem" class="bspfy-action-link" href="<?php echo esc_url( $artist_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View artist', 'betait-spfy-playlist' ); ?></a>
							<?php endif; ?>
							<?php if ( $album_url ) : ?>
								<a role="menuitem" class="bspfy-action-link" href="<?php echo esc_url( $album_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View album', 'betait-spfy-playlist' ); ?></a>
							<?php endif; ?>
							<?php if ( $track_url ) : ?>
								<a role="menuitem" class="bspfy-action-link" href="<?php echo esc_url( $track_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open in Spotify', 'betait-spfy-playlist' ); ?></a>
							<?php endif; ?>
						</div>
						
						<span class="bspfy-track-duration"><?php echo esc_html( $duration_text ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( $options['show_footer'] ) : ?>
		<div class="bspfy-widget-footer">
			<a href="<?php echo esc_url( $playlist_url ); ?>" class="bspfy-playlist-link">
				<?php esc_html_e( 'See Full Playlist', 'betait-spfy-playlist' ); ?> &rarr;
			</a>
		</div>
	<?php endif; ?>

	<?php
	// Store track data for JavaScript (JSON-encoded, escaped for HTML attribute).
	$tracks_data = array();
	foreach ( $tracks as $track ) {
		$album_image = '';
		if ( ! empty( $track['album']['images'] ) && is_array( $track['album']['images'] ) ) {
			$album_image = $track['album']['images'][0]['url'] ?? '';
		}
		
		$tracks_data[] = array(
			'uri'         => $track['uri'] ?? '',
			'name'        => $track['name'] ?? '',
			'artists'     => $track['artists'] ?? array(),
			'album_image' => $album_image,
		);
	}
	?>
	<script type="application/json" class="bspfy-widget-tracks-data" data-widget-id="<?php echo esc_attr( $widget_id ); ?>">
		<?php echo wp_json_encode( $tracks_data ); ?>
	</script>
</div>
