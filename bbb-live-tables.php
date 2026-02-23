<?php
/**
 * Plugin Name:       BBB Live Tables
 * Plugin URI:        https://github.com/OliEder/bbb-live-tables
 * Description:       Liga-Tabellen und Turnier-Brackets live aus der Basketball-Bund.net (BBB) API. Standalone – kein SportsPress nötig. Optional erweiterbar durch bbb-sportspress-sync.
 * Version:           1.5.1
 * Author:            Oliver-Marcus Eder
 * Author URI:        https://github.com/OliEder
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bbb-live-tables
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.1
 */

defined( 'ABSPATH' ) || exit;

define( 'BBB_TABLES_VERSION', '1.5.1' );
define( 'BBB_TABLES_DIR', plugin_dir_path( __FILE__ ) );
define( 'BBB_TABLES_URL', plugin_dir_url( __FILE__ ) );
define( 'BBB_TABLES_BASENAME', plugin_basename( __FILE__ ) );

// Nur definieren wenn nicht bereits durch bbb-sportspress-sync gesetzt
if ( ! defined( 'BBB_API_BASE_URL' ) ) {
    define( 'BBB_API_BASE_URL', 'https://www.basketball-bund.net/rest' );
}

/**
 * Init: Load classes.
 */
add_action( 'plugins_loaded', function() {

    // API Client (nur laden wenn nicht bereits durch Sync-Plugin vorhanden)
    if ( ! class_exists( 'BBB_Api_Client' ) ) {
        require_once BBB_TABLES_DIR . 'includes/class-bbb-api-client.php';
    }

    require_once BBB_TABLES_DIR . 'includes/class-bbb-live-table.php';
    require_once BBB_TABLES_DIR . 'includes/class-bbb-tournament-bracket.php';
    require_once BBB_TABLES_DIR . 'includes/class-bbb-goodlayers-elements.php';

    // Shortcodes + Gutenberg Blöcke
    new BBB_Live_Table();
    new BBB_Tournament_Bracket();

    // Goodlayers Page Builder Elemente
    // Wenn Sync-Plugin aktiv ist, übernimmt es die Registrierung
    // (mit SP-angereicherten Liga-Dropdowns etc.)
    if ( ! defined( 'BBB_SYNC_VERSION' ) ) {
        new BBB_Goodlayers_Elements();
    }

    // Admin Settings + GitHub Update Checker
    if ( is_admin() ) {
        require_once BBB_TABLES_DIR . 'includes/class-bbb-tables-admin.php';
        new BBB_Tables_Admin();

        require_once BBB_TABLES_DIR . 'includes/class-bbb-github-updater.php';
        new BBB_GitHub_Updater(
            BBB_TABLES_BASENAME,
            'OliEder',
            'bbb-live-tables',
            BBB_TABLES_VERSION
        );
    }
}, 20 ); // Priorität 20: Nach bbb-sportspress-sync (falls aktiv)

/**
 * Settings link on plugin page.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
    array_unshift( $links,
        '<a href="' . admin_url( 'options-general.php?page=bbb-live-tables' ) . '">Einstellungen</a>'
    );
    return $links;
});
