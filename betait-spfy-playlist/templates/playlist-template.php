<?php
/**
 * Template Name: BSPFY – Playlist (Plugin)
 * Template Post Type: playlist
 *
 * Main template for the "playlist" CPT rendered by the plugin.
 *
 * ⚠️ Overriding templates in a theme
 * ----------------------------------
 * You can override any of the partials below by copying them into your theme:
 *
 *   Child theme (preferred):
 *     /wp-content/themes/your-child-theme/betait-spfy-playlist/playlist-template-header.php
 *     /wp-content/themes/your-child-theme/betait-spfy-playlist/playlist-template-main.php
 *     /wp-content/themes/your-child-theme/betait-spfy-playlist/playlist-template-sidebar-cover.php
 *     /wp-content/themes/your-child-theme/betait-spfy-playlist/playlist-template-footer.php
 *     /wp-content/themes/your-child-theme/betait-spfy-playlist/players/playlist-template-player-dock.php
 *     /wp-content/themes/your-child-theme/betait-spfy-playlist/players/playlist-template-player1.php
 *
 *   Parent theme (fallback if no child):
 *     /wp-content/themes/your-parent-theme/betait-spfy-playlist/… (same paths as above)
 *
 * The loader will search child theme → parent theme → plugin defaults.
 *
 * 💡 Full template override
 * -------------------------
 * If you want to completely control single views for this CPT from your theme,
 * create:  /wp-content/themes/your-theme/single-playlist.php
 * That file will take precedence if your plugin stops forcing its template.
 *
 * @package   Betait_Spfy_Playlist
 * @subpackage Betait_Spfy_Playlist/templates
 * @author    Bjorn-Tore <bt@betait.no>
 * @link      https://betait.no
 * @since     2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locate and include a template partial with theme overrides.
 *
 * Search order:
 *   1) Child theme:   /betait-spfy-playlist/{relative}
 *   2) Parent theme:  /betait-spfy-playlist/{relative}
 *   3) Plugin:        /public/templates/{relative}
 *
 * @param string $relative Relative path under the templates root (e.g. 'playlist-template-header.php' or 'players/…').
 * @param bool   $require  Whether to include the file when found (true) or just return the path (false).
 * @return string          The resolved absolute path or empty string if none found.
 */
if ( ! function_exists( 'bspfy_locate_part' ) ) {
	function bspfy_locate_part( $relative, $require = true ) {
		$relative = ltrim( (string) $relative, '/\\' );

		$child  = trailingslashit( get_stylesheet_directory() ) . 'betait-spfy-playlist/' . $relative;
		$parent = trailingslashit( get_template_directory() )   . 'betait-spfy-playlist/' . $relative;
		$plugin = trailingslashit( __DIR__ ) . $relative; // __DIR__ points to plugin's /public/templates/

		$candidates = apply_filters( 'bspfy_template_part_candidates', array( $child, $parent, $plugin ), $relative );

		$found = '';
		foreach ( $candidates as $file ) {
			if ( $file && file_exists( $file ) ) {
				$found = $file;
				break;
			}
		}

		if ( $found && $require ) {
			/** @noinspection PhpIncludeInspection */
			include $found;
		}

		return $found;
	}
}

get_header();
?>

<div class="bspfy-container">
	<header class="bspfy-header">
		<?php
		// Header partial (title, meta, etc.)
		bspfy_locate_part( 'playlist-template-header.php', true );
		?>
	</header>

	<main class="bspfy-main">
		<?php
		// Main content partial (track list / body)
		bspfy_locate_part( 'playlist-template-main.php', true );
		?>
	</main>

	<section class="bspfy-cover-section">
		<?php
		// Sidebar cover partial (artwork / playlist info)
		bspfy_locate_part( 'playlist-template-sidebar-cover.php', true );
		?>
	</section>

	<?php
	/**
	 * Choose player variant from settings (or via filter).
	 * Accepts: 'dock' or 'default' (maps to playlist-template-player-dock.php / playlist-template-player1.php).
	 */
	$player_choice = get_option( 'bspfy_player_theme', 'default' );
	$player_choice = apply_filters( 'bspfy_player_template_choice', $player_choice );

	$player_relative = ( 'dock' === $player_choice )
		? 'players/playlist-template-player-dock.php'
		: 'players/playlist-template-player1.php';

	// Resolve player file (allow override in theme).
	$player_path = bspfy_locate_part( $player_relative, false );
	if ( ! $player_path ) {
		// Final fallback to the default player shipped with the plugin.
		$player_path = trailingslashit( __DIR__ ) . 'players/playlist-template-player1.php';
	}
	?>

	<section class="bspfy-player-section" data-player="<?php echo esc_attr( $player_choice ); ?>">
		<?php /** @noinspection PhpIncludeInspection */ include $player_path; ?>
	</section>

	<footer class="bspfy-footer">
		<?php
		// Footer partial (attribution, actions)
		bspfy_locate_part( 'playlist-template-footer.php', true );
		?>
	</footer>
</div>

<?php get_footer();
