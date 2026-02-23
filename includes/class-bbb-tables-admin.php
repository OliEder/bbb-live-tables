<?php
/**
 * BBB Live Tables – Admin Settings
 *
 * Einstellungsseite unter Einstellungen → BBB Live Tables.
 * Nur die Settings die das Standalone-Plugin braucht:
 *   - Club-ID (für Highlighting)
 *   - Eigene Team-PIDs (für Highlighting ohne SP)
 *   - Farben (wenn kein Theme-Sync)
 *   - Cache leeren
 *
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

class BBB_Tables_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_post_bbb_tables_clear_cache', [ $this, 'handle_clear_cache' ] );
    }

    public function add_menu(): void {
        add_options_page(
            'BBB Live Tables',
            'BBB Live Tables',
            'manage_options',
            'bbb-live-tables',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        register_setting( 'bbb_tables_settings', 'bbb_tables_club_id', [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
        register_setting( 'bbb_tables_settings', 'bbb_tables_own_team_pids', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'bbb_tables_settings', 'bbb_tables_color_primary', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_hex_color' ] );
        register_setting( 'bbb_tables_settings', 'bbb_tables_color_link', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_hex_color' ] );
        register_setting( 'bbb_tables_settings', 'bbb_tables_color_heading', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_hex_color' ] );
    }

    public function handle_clear_cache(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Keine Berechtigung.' );
        check_admin_referer( 'bbb_tables_clear_cache' );

        BBB_Live_Table::invalidate_all_caches();
        BBB_Tournament_Bracket::invalidate_all_caches();

        // Discovery-Caches leeren
        $club_id = (int) get_option( 'bbb_tables_club_id', 0 );
        if ( $club_id ) {
            delete_transient( "bbb_club_teams_{$club_id}" );
            delete_transient( "bbb_club_leagues_{$club_id}" );
        }

        wp_redirect( admin_url( 'options-general.php?page=bbb-live-tables&cache_cleared=1' ) );
        exit;
    }

    public function render_page(): void {
        $sync_active = class_exists( 'BBB_Sync_Engine' ) || is_plugin_active( 'bbb-sportspress-sync/bbb-sportspress-sync.php' );
        $club_id     = (int) get_option( 'bbb_tables_club_id', 0 );
        ?>
        <div class="wrap">
            <h1>BBB Live Tables</h1>

            <?php if ( ! empty( $_GET['cache_cleared'] ) ): ?>
                <div class="notice notice-success is-dismissible"><p>Alle Caches (Tabellen, Brackets, Discovery) wurden geleert.</p></div>
            <?php endif; ?>

            <?php if ( $sync_active ): ?>
                <div class="notice notice-info">
                    <p><strong>BBB SportsPress Sync ist aktiv.</strong> Logos, Team-Links und Farben werden automatisch aus SportsPress übernommen. Du kannst hier trotzdem Fallback-Werte setzen.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'bbb_tables_settings' ); ?>

                <h2>Verein</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="bbb_tables_club_id">BBB Club-ID</label></th>
                        <td>
                            <input type="number" id="bbb_tables_club_id" name="bbb_tables_club_id"
                                   value="<?php echo esc_attr( get_option( 'bbb_tables_club_id', '' ) ); ?>" class="regular-text">
                            <p class="description">Deine Vereins-ID bei basketball-bund.net (z.B. 4468 für Fibalon Baskets). Wird für Auto-Discovery und Hervorhebung verwendet.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="bbb_tables_own_team_pids">Eigene Team-PIDs (Override)</label></th>
                        <td>
                            <input type="text" id="bbb_tables_own_team_pids" name="bbb_tables_own_team_pids"
                                   value="<?php echo esc_attr( get_option( 'bbb_tables_own_team_pids', '' ) ); ?>" class="large-text">
                            <p class="description">
                                Komma-separierte Liste von Team Permanent-IDs (z.B. <code>12345,12346,12347</code>).<br>
                                <strong>Normalerweise nicht nötig:</strong> Bei gesetzter Club-ID werden die Teams automatisch
                                über die BBB-API erkannt (24h gecached). Diese Liste dient nur als manueller Override.
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>Farben</h2>
                <p class="description">Leer lassen = Goodlayers/Theme-Farben werden automatisch verwendet. Mit Sync-Plugin werden SportsPress-Farben bevorzugt.</p>
                <table class="form-table">
                    <tr>
                        <th><label for="bbb_tables_color_primary">Primärfarbe</label></th>
                        <td>
                            <input type="text" id="bbb_tables_color_primary" name="bbb_tables_color_primary"
                                   value="<?php echo esc_attr( get_option( 'bbb_tables_color_primary', '' ) ); ?>" class="small-text" placeholder="#2b353e">
                            <p class="description">Header-Hintergrund, Platzziffer, Überschriften.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="bbb_tables_color_link">Akzentfarbe / Link</label></th>
                        <td>
                            <input type="text" id="bbb_tables_color_link" name="bbb_tables_color_link"
                                   value="<?php echo esc_attr( get_option( 'bbb_tables_color_link', '' ) ); ?>" class="small-text" placeholder="#00a69c">
                            <p class="description">Highlighting eigener Verein, Links, Gewinner-Farbe.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="bbb_tables_color_heading">Header-Text</label></th>
                        <td>
                            <input type="text" id="bbb_tables_color_heading" name="bbb_tables_color_heading"
                                   value="<?php echo esc_attr( get_option( 'bbb_tables_color_heading', '' ) ); ?>" class="small-text" placeholder="#ffffff">
                            <p class="description">Textfarbe in Tabellen-Kopfzeile.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Einstellungen speichern' ); ?>
            </form>

            <?php if ( $club_id ): ?>
            <hr>
            <h2>Auto-Discovery <span style="font-size:.6em;font-weight:400;color:#888">(Club-ID: <?php echo $club_id; ?>)</span></h2>
            <p class="description">Automatisch erkannte Teams und Ligen aus der BBB-API. Daten werden 24h gecached. Über „Caches leeren“ aktualisierbar.</p>

            <?php
            $api = new BBB_Api_Client();

            // Teams
            $teams = $api->get_club_team_ids( $club_id );
            ?>
            <h3>Eigene Teams (<?php echo count( $teams ); ?>)</h3>
            <?php if ( ! empty( $teams ) ): ?>
                <table class="widefat striped" style="max-width:500px">
                    <thead><tr><th>Team Permanent-ID</th><th>Logo</th></tr></thead>
                    <tbody>
                    <?php foreach ( $teams as $pid ): ?>
                        <tr>
                            <td><code><?php echo esc_html( $pid ); ?></code></td>
                            <td><img src="https://www.basketball-bund.net/media/team/<?php echo (int) $pid; ?>/logo" width="24" height="24" style="vertical-align:middle" loading="lazy" alt="Logo"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><em>Keine Teams gefunden. Eventuell hat der Verein aktuell keine Spiele gemeldet.</em></p>
            <?php endif; ?>

            <?php
            // Ligen
            $leagues = $api->get_club_leagues( $club_id );
            ?>
            <h3>Ligen &amp; Turniere (<?php echo count( $leagues ); ?>)</h3>
            <?php if ( ! empty( $leagues ) ): ?>
                <table class="widefat striped" style="max-width:900px">
                    <thead><tr><th>Liga-ID</th><th>Name</th><th>Typ</th><th>Shortcode</th><th>Goodlayers / Page Builder</th></tr></thead>
                    <tbody>
                    <?php foreach ( $leagues as $league ): ?>
                        <tr>
                            <td><code><?php echo esc_html( $league['liga_id'] ); ?></code></td>
                            <td><?php echo esc_html( $league['label'] ); ?></td>
                            <td>
                                <?php if ( $league['type'] === 'league' ): ?>
                                    <span style="color:#27ae60">●</span> Tabelle
                                <?php else: ?>
                                    <span style="color:#e67e22">●</span> Turnier
                                <?php endif; ?>
                            </td>
                            <td>
                                <code style="user-select:all"><?php
                                    echo $league['type'] === 'league'
                                        ? '[bbb_table liga_id="' . esc_attr( $league['liga_id'] ) . '"]'
                                        : '[bbb_bracket liga_id="' . esc_attr( $league['liga_id'] ) . '"]';
                                ?></code>
                            </td>
                            <td>
                                <code style="user-select:all"><?php
                                    echo $league['type'] === 'league'
                                        ? '[gdlr_core_bbb_table liga-id="' . esc_attr( $league['liga_id'] ) . '"]'
                                        : '[gdlr_core_bbb_bracket liga-id="' . esc_attr( $league['liga_id'] ) . '"]';
                                ?></code>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description" style="margin-top:8px;">
                    <strong>Tipp:</strong> Im Goodlayers Page Builder findest du die Elemente unter der Kategorie <em>Sport</em>
                    („BBB Liga-Tabelle“ und „BBB Turnier-Bracket“).
                </p>
            <?php else: ?>
                <p><em>Keine Ligen gefunden.</em></p>
            <?php endif; ?>

            <?php endif; // $club_id ?>

            <hr>
            <h2>Cache</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="bbb_tables_clear_cache">
                <?php wp_nonce_field( 'bbb_tables_clear_cache' ); ?>
                <p>Leert alle Caches: Tabellen, Brackets und Auto-Discovery. Die Daten werden beim nächsten Aufruf neu von basketball-bund.net geladen.</p>
                <?php submit_button( 'Alle Caches leeren', 'secondary' ); ?>
            </form>

            <hr>
            <h2>Shortcode-Referenz</h2>
            <table class="widefat striped" style="max-width:700px">
                <thead><tr><th>Shortcode</th><th>Beschreibung</th></tr></thead>
                <tbody>
                    <tr><td><code>[bbb_table liga_id="..."]</code></td><td>Liga-Tabelle (live aus API)</td></tr>
                    <tr><td><code>[bbb_bracket liga_id="..."]</code></td><td>Turnier-Bracket (KO)</td></tr>
                    <tr><td><code>[bbb_bracket liga_id="..." mode="playoff" best_of="5"]</code></td><td>Playoff-Bracket (Best-of-5)</td></tr>
                </tbody>
            </table>

            <h2>Filter-Hooks (für Entwickler)</h2>
            <p>Das Plugin definiert Filter-Hooks die ein Sync-Plugin nutzen kann:</p>
            <table class="widefat striped" style="max-width:700px">
                <thead><tr><th>Filter</th><th>Standalone-Default</th></tr></thead>
                <tbody>
                    <tr><td><code>bbb_table_team_logo_url</code></td><td>BBB Media URL</td></tr>
                    <tr><td><code>bbb_table_team_url</code></td><td>Kein Link</td></tr>
                    <tr><td><code>bbb_table_event_url</code></td><td>Kein Link</td></tr>
                    <tr><td><code>bbb_table_theme_colors</code></td><td>Plugin-Settings / Goodlayers</td></tr>
                    <tr><td><code>bbb_table_own_team_ids</code></td><td>API Auto-Discovery (24h) → Manuelle Liste</td></tr>
                    <tr><td><code>bbb_table_liga_options</code></td><td>API Auto-Discovery (24h) → Leeres Array</td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
