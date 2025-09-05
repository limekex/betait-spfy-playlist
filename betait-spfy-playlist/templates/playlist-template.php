
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

    <section class="bspfy-player-section">
        <?php include_once 'playlist-template-player1.php'; ?>
    </section>

    <footer class="bspfy-footer">
      <?php include_once 'playlist-template-footer.php'; ?>
    </footer>
</div>

  <?php get_footer(); ?>