
<?php
/**
 * Template Name: Playlist Template
 * Template Post Type: playlist
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header(); ?>

<div class="bspfy-container">
    <header class="bspfy-header">    
        <?php include_once 'playlist-template-header.php'; ?>
    </header>

    <main class="bspfy-main">
        <?php include_once 'playlist-template-main.php'; ?>
    </main>

    <section class="bspfy-cover-section">
        <?php include_once 'playlist-template-sidebar-cover.php'; ?>
    </section>

<?php
// Hent valgt player fra innstillinger
$player_choice = get_option( 'bspfy_player_theme', 'default' );

// Bestem fil og ekstra klasse
$is_dock      = ( $player_choice === 'dock' );
$player_file  = $is_dock
  ? 'players/playlist-template-player-dock.php'
  : 'players/playlist-template-player1.php';

// Lag absolut sti relativt til denne templaten
$abs_path = trailingslashit( __DIR__ ) . $player_file;
?>
<section class="bspfy-player-section" data-player="<?php echo esc_attr( $player_choice ); ?>">
  <?php
  if ( file_exists( $abs_path ) ) {
    include $abs_path;
  } else {
    // Fallback: alltid last eksisterende spiller
    include trailingslashit( __DIR__ ) . 'players/playlist-template-player1.php';
  }
  ?>
</section>


    <footer class="bspfy-footer">
      <?php include_once 'playlist-template-footer.php'; ?>
    </footer>
</div>

  <?php get_footer(); ?>