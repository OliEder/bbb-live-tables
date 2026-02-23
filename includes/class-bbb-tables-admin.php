<?php
/**
 * BBB Live Tables – Admin Settings
 *
 * Einstellungsseite unter Einstellungen → BBB Live Tables.
 *
 * Tabs:
 *   - Verein: Club-ID, Override-PIDs, Auto-Discovery Teams
 *   - Ligen & Turniere: Erkannte Ligen mit Shortcodes
 *   - Darstellung: Farben mit Erklärungen
 *   - Referenz: Shortcode-Doku + Filter-Hooks
 *   - Support: Donation + Links
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
            delete_transient( "bbb_club_raw_{$club_id}" );
            delete_transient( "bbb_club_teams_{$club_id}" );
            delete_transient( "bbb_club_leagues_{$club_id}" );
        }

        $tab = sanitize_key( $_POST['_bbb_redirect_tab'] ?? 'club' );
        wp_redirect( admin_url( "options-general.php?page=bbb-live-tables&tab={$tab}&cache_cleared=1" ) );
        exit;
    }

    // ═════════════════════════════════════════
    // PAGE RENDER
    // ═════════════════════════════════════════

    public function render_page(): void {
        $tab = $_GET['tab'] ?? 'club';
        ?>
        <div class="wrap">
            <h1>BBB Live Tables</h1>

            <?php if ( ! empty( $_GET['cache_cleared'] ) ): ?>
                <div class="notice notice-success is-dismissible"><p>Alle Caches (Tabellen, Brackets, Discovery) wurden geleert.</p></div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper">
                <?php foreach ( [
                    'club'       => 'Verein',
                    'leagues'    => 'Ligen & Turniere',
                    'appearance' => 'Darstellung',
                    'reference'  => 'Referenz',
                    'support'    => '❤️ Support',
                ] as $slug => $label ) : ?>
                    <a href="?page=bbb-live-tables&tab=<?php echo $slug; ?>"
                       class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div style="margin-top:20px;">
                <?php match ($tab) {
                    'leagues'    => $this->render_leagues_tab(),
                    'appearance' => $this->render_appearance_tab(),
                    'reference'  => $this->render_reference_tab(),
                    'support'    => $this->render_support_tab(),
                    default      => $this->render_club_tab(),
                }; ?>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────
    // TAB: VEREIN
    // ─────────────────────────────────────────

    private function render_club_tab(): void {
        $sync_active = class_exists( 'BBB_Sync_Engine' ) || is_plugin_active( 'bbb-sportspress-sync/bbb-sportspress-sync.php' );
        $club_id     = (int) get_option( 'bbb_tables_club_id', 0 );
        ?>

        <?php if ( $sync_active ): ?>
            <div class="notice notice-info inline">
                <p><strong>BBB SportsPress Sync ist aktiv.</strong> Die Team-Erkennung erfolgt primär über das Sync-Plugin. Die hier gesetzte Club-ID dient als Fallback für Highlighting.</p>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width:700px; padding:15px;">
            <h2>Vereins-Einstellungen</h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'bbb_tables_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="bbb_tables_club_id">BBB Club-ID</label></th>
                        <td>
                            <input type="number" id="bbb_tables_club_id" name="bbb_tables_club_id"
                                   value="<?php echo esc_attr( get_option( 'bbb_tables_club_id', '' ) ); ?>" class="regular-text" min="1">
                            <p class="description">
                                Deine Vereins-ID bei basketball-bund.net (z.B. <code>4468</code>).<br>
                                Wird für die automatische Erkennung deiner Teams und für die farbliche Hervorhebung in Tabellen verwendet.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="bbb_tables_own_team_pids">Team-PIDs (Override)</label></th>
                        <td>
                            <input type="text" id="bbb_tables_own_team_pids" name="bbb_tables_own_team_pids"
                                   value="<?php echo esc_attr( get_option( 'bbb_tables_own_team_pids', '' ) ); ?>" class="large-text">
                            <p class="description">
                                Komma-separierte Liste von Team Permanent-IDs (z.B. <code>12345,12346,12347</code>).<br>
                                <strong>Normalerweise nicht nötig:</strong> Bei gesetzter Club-ID werden die Teams automatisch
                                über die BBB-API erkannt (siehe unten). Diese Liste dient nur als manueller Override,
                                falls die Auto-Discovery ein Team nicht findet (z.B. Freundschaftsspiele ohne gemeldete Liga).
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Speichern' ); ?>
            </form>
        </div>

        <?php if ( $club_id ): ?>
        <?php
            $api = new BBB_Api_Client();
            $has_discovery = method_exists( $api, 'get_club_teams_detailed' );
        ?>

        <?php if ( ! $has_discovery ): ?>
            <div class="card" style="max-width:700px; padding:15px; margin-top:15px;">
                <h2>Auto-Discovery</h2>
                <p class="description">
                    Auto-Discovery steht nicht zur Verfügung, da BBB SportsPress Sync seinen eigenen API-Client lädt.
                    Die Team-/Liga-Erkennung erfolgt über <strong>SportsPress → BBB Sync → Team-Discovery</strong>.
                </p>
            </div>
        <?php else: ?>
            <?php $teams = $api->get_club_teams_detailed( $club_id ); ?>

            <div class="card" style="max-width:700px; padding:15px; margin-top:15px;">
                <h2>Erkannte Teams <span style="font-size:.6em; font-weight:400; color:#888;">(<?php echo count( $teams ); ?> Teams · Club-ID: <?php echo $club_id; ?>)</span></h2>
                <p class="description" style="margin-bottom:12px;">
                    Automatisch erkannte Teams deines Vereins aus der BBB-API. Daten werden 24h gecached.
                </p>

                <?php if ( ! empty( $teams ) ): ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th style="width:32px;"></th>
                                <th>Teamname</th>
                                <th>Altersklasse</th>
                                <th>Liga(en)</th>
                                <th>Permanent-ID</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $teams as $pid => $t ):
                            $ak = $t['akName'] ?? '';
                            $g  = match ($t['geschlecht'] ?? '') {
                                'mix' => '⚥', 'maennlich', 'm' => '♂', 'weiblich', 'w' => '♀', default => ''
                            };
                        ?>
                            <tr>
                                <td>
                                    <img src="https://www.basketball-bund.net/media/team/<?php echo (int) $pid; ?>/logo"
                                         width="24" height="24" style="vertical-align:middle;" loading="lazy" alt="Logo">
                                </td>
                                <td><strong><?php echo esc_html( $t['teamname'] ); ?></strong></td>
                                <td>
                                    <?php if ( $ak ): ?>
                                        <span style="background:#e8f0fe; padding:2px 8px; border-radius:3px; font-size:12px;">
                                            <?php echo esc_html( "{$ak} {$g}" ); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#999;">–</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:12px;">
                                    <?php foreach ( $t['ligen'] as $liga ): ?>
                                        <div><?php echo esc_html( $liga ); ?></div>
                                    <?php endforeach; ?>
                                </td>
                                <td><code style="font-size:11px;"><?php echo esc_html( $pid ); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><em>Keine Teams gefunden. Eventuell hat der Verein aktuell keine Spiele gemeldet.</em></p>
                <?php endif; ?>

                <div style="margin-top:12px;">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="bbb_tables_clear_cache">
                        <input type="hidden" name="_bbb_redirect_tab" value="club">
                        <?php wp_nonce_field( 'bbb_tables_clear_cache' ); ?>
                        <?php submit_button( 'Cache leeren & neu erkennen', 'secondary', 'submit', false ); ?>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        <?php else: ?>
            <div class="notice notice-warning inline" style="margin-top:15px;">
                <p>Gib oben eine Club-ID ein, um die automatische Team-Erkennung zu aktivieren.</p>
            </div>
        <?php endif; ?>
        <?php
    }

    // ─────────────────────────────────────────
    // TAB: LIGEN & TURNIERE
    // ─────────────────────────────────────────

    private function render_leagues_tab(): void {
        $club_id = (int) get_option( 'bbb_tables_club_id', 0 );

        if ( ! $club_id ): ?>
            <div class="notice notice-warning inline">
                <p>Zuerst eine <a href="?page=bbb-live-tables&tab=club">Club-ID</a> eingeben, dann werden hier die Ligen automatisch erkannt.</p>
            </div>
            <?php return;
        endif;

        $api = new BBB_Api_Client();
        $has_discovery = method_exists( $api, 'get_club_leagues' );

        if ( ! $has_discovery ): ?>
            <div class="card" style="max-width:900px; padding:15px;">
                <h2>Ligen & Turniere</h2>
                <p class="description">
                    Auto-Discovery steht nicht zur Verfügung, da BBB SportsPress Sync seinen eigenen API-Client lädt.
                    Shortcodes findest du unter <strong>SportsPress → BBB Sync → Dashboard</strong>.
                </p>
            </div>
            <?php return;
        endif;

        $leagues = $api->get_club_leagues( $club_id );
        ?>

        <div class="card" style="max-width:950px; padding:15px;">
            <h2>Ligen & Turniere <span style="font-size:.6em; font-weight:400; color:#888;">(<?php echo count( $leagues ); ?> · Club-ID: <?php echo $club_id; ?>)</span></h2>
            <p class="description" style="margin-bottom:12px;">
                Automatisch erkannte Ligen und Turniere deines Vereins. Kopiere den passenden Shortcode in deine Seiten oder verwende die Gutenberg-Blöcke / Goodlayers-Elemente.
            </p>

            <?php if ( ! empty( $leagues ) ): ?>
                <table class="widefat striped" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>Liga-ID</th>
                            <th>Name</th>
                            <th>Typ</th>
                            <th>Shortcode</th>
                            <th>Goodlayers / Page Builder</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $leagues as $league ): ?>
                        <tr>
                            <td><code><?php echo esc_html( $league['liga_id'] ); ?></code></td>
                            <td><?php echo esc_html( $league['label'] ); ?></td>
                            <td>
                                <?php if ( $league['type'] === 'league' ): ?>
                                    <span style="color:#27ae60;">●</span> Tabelle
                                <?php else: ?>
                                    <span style="color:#e67e22;">●</span> Turnier
                                <?php endif; ?>
                            </td>
                            <td>
                                <code style="user-select:all; font-size:12px;"><?php
                                    echo $league['type'] === 'league'
                                        ? '[bbb_table liga_id="' . esc_attr( $league['liga_id'] ) . '"]'
                                        : '[bbb_bracket liga_id="' . esc_attr( $league['liga_id'] ) . '"]';
                                ?></code>
                            </td>
                            <td>
                                <code style="user-select:all; font-size:12px;"><?php
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
                    („BBB Liga-Tabelle" und „BBB Turnier-Bracket"). In Gutenberg sind sie als Blöcke verfügbar.
                </p>
            <?php else: ?>
                <p><em>Keine Ligen gefunden. Eventuell hat der Verein aktuell keine Spiele gemeldet.</em></p>
            <?php endif; ?>

            <div style="margin-top:12px;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                    <input type="hidden" name="action" value="bbb_tables_clear_cache">
                    <input type="hidden" name="_bbb_redirect_tab" value="leagues">
                    <?php wp_nonce_field( 'bbb_tables_clear_cache' ); ?>
                    <?php submit_button( 'Cache leeren & neu erkennen', 'secondary', 'submit', false ); ?>
                </form>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────
    // TAB: DARSTELLUNG
    // ─────────────────────────────────────────

    private function render_appearance_tab(): void {
        $sync_active = class_exists( 'BBB_Sync_Engine' ) || is_plugin_active( 'bbb-sportspress-sync/bbb-sportspress-sync.php' );
        ?>

        <div class="card" style="max-width:700px; padding:15px;">
            <h2>Farben</h2>
            <p class="description" style="margin-bottom:15px;">
                Die Tabellen und Brackets werden mit diesen Farben gestaltet. Damit kannst du das Erscheinungsbild
                an dein WordPress-Theme und die Vereinsfarben anpassen, ohne CSS schreiben zu müssen.
            </p>

            <?php if ( $sync_active ): ?>
                <div class="notice notice-info inline" style="margin-bottom:15px;">
                    <p><strong>BBB SportsPress Sync ist aktiv.</strong> Farben werden bevorzugt aus SportsPress übernommen. Die Werte hier dienen als Fallback.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'bbb_tables_settings' ); ?>
                <!-- Hidden fields to preserve club settings when saving only colors -->
                <input type="hidden" name="bbb_tables_club_id" value="<?php echo esc_attr( get_option( 'bbb_tables_club_id', '' ) ); ?>">
                <input type="hidden" name="bbb_tables_own_team_pids" value="<?php echo esc_attr( get_option( 'bbb_tables_own_team_pids', '' ) ); ?>">

                <table class="form-table">
                    <tr>
                        <th><label for="bbb_tables_color_primary">Primärfarbe</label></th>
                        <td>
                            <input type="text" id="bbb_tables_color_primary" name="bbb_tables_color_primary"
                                   value="<?php echo esc_attr( get_option( 'bbb_tables_color_primary', '' ) ); ?>" class="small-text" placeholder="#2b353e">
                            <p class="description">
                                Hintergrundfarbe der Tabellen-Kopfzeile (Spaltenüberschriften wie „Platz", „Team", „Sp", „S" usw.)
                                und Akzent-Elemente in Brackets. Typischerweise eine dunkle Farbe, die gut mit weißem Text lesbar ist.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="bbb_tables_color_link">Akzentfarbe</label></th>
                        <td>
                            <input type="text" id="bbb_tables_color_link" name="bbb_tables_color_link"
                                   value="<?php echo esc_attr( get_option( 'bbb_tables_color_link', '' ) ); ?>" class="small-text" placeholder="#00a69c">
                            <p class="description">
                                Farbe für die Hervorhebung der eigenen Vereinsteams in der Tabelle (Highlight-Zeile),
                                Links zu Spielberichten und die Kennzeichnung von Gewinnern im Turnier-Bracket.
                                Am besten eine kräftige, auffällige Vereinsfarbe.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="bbb_tables_color_heading">Header-Textfarbe</label></th>
                        <td>
                            <input type="text" id="bbb_tables_color_heading" name="bbb_tables_color_heading"
                                   value="<?php echo esc_attr( get_option( 'bbb_tables_color_heading', '' ) ); ?>" class="small-text" placeholder="#ffffff">
                            <p class="description">
                                Textfarbe der Spaltenüberschriften in der Tabellen-Kopfzeile. Muss gut lesbar auf der Primärfarbe sein –
                                bei einer dunklen Primärfarbe typischerweise <code>#ffffff</code> (weiß), bei heller Primärfarbe entsprechend dunkel.
                            </p>
                        </td>
                    </tr>
                </table>

                <div style="margin:15px 0; padding:12px; background:#f9f9f9; border:1px solid #ddd; border-radius:4px;">
                    <strong>Reihenfolge der Farb-Ermittlung:</strong>
                    <ol style="margin:8px 0 0 20px; font-size:13px; color:#555;">
                        <li>SportsPress-Farben (wenn Sync-Plugin aktiv)</li>
                        <li>Diese Plugin-Einstellungen (Fallback)</li>
                        <li>Goodlayers-Theme-Farben (automatische Erkennung)</li>
                        <li>Standard-Werte (dunkelgrau / teal / weiß)</li>
                    </ol>
                    <p class="description" style="margin-top:8px;">
                        Leer lassen = Farben werden automatisch aus der nächsthöheren Quelle übernommen.
                        Nur setzen, wenn du die automatisch erkannten Farben überschreiben möchtest.
                    </p>
                </div>

                <?php submit_button( 'Farben speichern' ); ?>
            </form>
        </div>
        <?php
    }

    // ─────────────────────────────────────────
    // TAB: REFERENZ
    // ─────────────────────────────────────────

    private function render_reference_tab(): void {
        ?>
        <div class="card" style="max-width:750px; padding:15px;">
            <h2>Shortcode-Referenz</h2>
            <table class="widefat striped">
                <thead><tr><th>Shortcode</th><th>Beschreibung</th></tr></thead>
                <tbody>
                    <tr><td><code>[bbb_table liga_id="..."]</code></td><td>Liga-Tabelle (live aus API)</td></tr>
                    <tr><td><code>[bbb_bracket liga_id="..."]</code></td><td>Turnier-Bracket (KO-System)</td></tr>
                    <tr><td><code>[bbb_bracket liga_id="..." mode="playoff" best_of="5"]</code></td><td>Playoff-Bracket (Best-of-Serie)</td></tr>
                </tbody>
            </table>
            <p class="description" style="margin-top:8px;">
                Die konkreten Liga-IDs für deinen Verein findest du im Tab
                <a href="?page=bbb-live-tables&tab=leagues">Ligen & Turniere</a>.
            </p>
        </div>

        <div class="card" style="max-width:750px; padding:15px; margin-top:15px;">
            <h2>Filter-Hooks (für Entwickler)</h2>
            <p class="description" style="margin-bottom:10px;">
                Das Plugin definiert Filter-Hooks, über die ein Sync-Plugin oder Custom Code das Verhalten anpassen kann:
            </p>
            <table class="widefat striped">
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

    // ─────────────────────────────────────────
    // TAB: SUPPORT
    // ─────────────────────────────────────────

    private function render_support_tab(): void {
        ?>
        <div class="card" style="max-width:700px; padding:20px;">
            <h2>🏀 BBB Live Tables unterstützen</h2>
            <p>
                Dieses Plugin wird ehrenamtlich von <strong>Oliver-Marcus Eder</strong> entwickelt und gepflegt.
                Es ist kostenlos und Open Source – wenn es dir weiterhilft, freue ich mich über einen kleinen Beitrag:
            </p>

            <div style="display:flex; gap:16px; margin:20px 0;">
                <a href="https://buymeacoffee.com/olivermarcus.eder" target="_blank"
                   style="display:inline-flex; align-items:center; gap:8px; padding:10px 20px; background:#FFDD00; color:#000; border-radius:8px; text-decoration:none; font-weight:600; font-size:15px;">
                    ☕ Buy Me a Coffee
                </a>
                <a href="https://ko-fi.com/olieder" target="_blank"
                   style="display:inline-flex; align-items:center; gap:8px; padding:10px 20px; background:#13C3FF; color:#fff; border-radius:8px; text-decoration:none; font-weight:600; font-size:15px;">
                    🎁 Ko-fi
                </a>
            </div>

            <hr style="margin:20px 0;">

            <h3>🐛 Fehler melden &amp; Feature-Wünsche</h3>
            <p>
                Hast du einen Bug gefunden oder eine Idee für ein neues Feature?
                Erstelle ein Issue auf GitHub:
            </p>
            <p>
                <a href="https://github.com/OliEder/bbb-live-tables/issues" target="_blank" class="button">
                    GitHub Issues → bbb-live-tables
                </a>
            </p>

            <hr style="margin:20px 0;">

            <h3>📦 Weitere Plugins</h3>
            <table class="widefat striped" style="max-width:500px;">
                <tr>
                    <td><strong>BBB SportsPress Sync</strong></td>
                    <td>Synchronisiert BBB-Daten in SportsPress (Teams, Spielplan, Spieler)</td>
                    <td><a href="https://github.com/OliEder/bbb-sportspress-sync" target="_blank">GitHub</a></td>
                </tr>
            </table>

            <hr style="margin:20px 0;">

            <p style="color:#666; font-size:13px;">
                Entwickelt mit ❤️ in Bayern · <a href="https://github.com/OliEder" target="_blank">github.com/OliEder</a>
            </p>
        </div>
        <?php
    }
}
