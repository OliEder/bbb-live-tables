<?php
/**
 * BBB Goodlayers Page Builder Elements (Standalone)
 *
 * Registriert Goodlayers/BigSlam Page Builder Elemente:
 *   - BBB Liga-Tabelle (Live)
 *   - BBB Turnier-Bracket
 *
 * ★ Liga-Dropdown: Nutzt Filter bbb_table_liga_options.
 *   Ohne Sync-Plugin: Nur manuelle Liga-ID Eingabe.
 *   Mit Sync-Plugin: sp_league-basierte Dropdown-Liste.
 *
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

class BBB_Goodlayers_Elements {

    public function __construct() {
        // Filter + Shortcodes immer registrieren.
        // Wenn gdlr_core nicht aktiv ist, wird der Filter nie aufgerufen → kein Overhead.
        add_filter( 'gdlr_core_page_builder_module', [ $this, 'register_bracket_element' ] );
        add_filter( 'gdlr_core_page_builder_module', [ $this, 'register_table_element' ] );
        add_action( 'init', [ $this, 'register_shortcodes' ] );
    }

    // ═════════════════════════════════════════
    // BRACKET ELEMENT
    // ═════════════════════════════════════════

    public function register_bracket_element( array $modules ): array {
        $modules['bbb-bracket'] = [
            'name'     => esc_html__( 'BBB Turnier-Bracket', 'bbb-live-tables' ),
            'category' => esc_html__( 'Sport', 'bbb-live-tables' ),
            'icon'     => 'fa-trophy',
            'options'  => [
                'liga-id' => [
                    'title'       => esc_html__( 'Liga-ID', 'bbb-live-tables' ),
                    'type'        => 'text',
                    'default'     => '',
                    'description' => esc_html__( 'BBB Liga-ID des Turniers (z.B. 47976). Findest du in der URL auf basketball-bund.net.', 'bbb-live-tables' ),
                ],
                'title' => [
                    'title'   => esc_html__( 'Titel', 'bbb-live-tables' ),
                    'type'    => 'text',
                    'default' => '',
                    'description' => esc_html__( 'Leer = Liga-Name aus API.', 'bbb-live-tables' ),
                ],
                'mode' => [
                    'title'   => esc_html__( 'Turnier-Modus', 'bbb-live-tables' ),
                    'type'    => 'combobox',
                    'options' => [
                        'ko'      => esc_html__( 'KO (Single Elimination)', 'bbb-live-tables' ),
                        'playoff' => esc_html__( 'Playoff (Best-of-N)', 'bbb-live-tables' ),
                    ],
                    'default' => 'ko',
                ],
                'best-of' => [
                    'title'     => esc_html__( 'Best of', 'bbb-live-tables' ),
                    'type'      => 'combobox',
                    'options'   => [ '3' => 'Best of 3', '5' => 'Best of 5', '7' => 'Best of 7' ],
                    'default'   => '5',
                    'condition' => [ 'mode' => 'playoff' ],
                ],
                'show-dates' => [
                    'title'   => esc_html__( 'Spieldaten anzeigen', 'bbb-live-tables' ),
                    'type'    => 'checkbox',
                    'default' => 'enable',
                ],
                'show-logos' => [
                    'title'   => esc_html__( 'Team-Logos anzeigen', 'bbb-live-tables' ),
                    'type'    => 'checkbox',
                    'default' => 'enable',
                ],
                'highlight-own' => [
                    'title'   => esc_html__( 'Eigenes Team hervorheben', 'bbb-live-tables' ),
                    'type'    => 'checkbox',
                    'default' => 'enable',
                ],
                'cache' => [
                    'title'   => esc_html__( 'Cache-Dauer (Sekunden)', 'bbb-live-tables' ),
                    'type'    => 'text',
                    'default' => '3600',
                ],
            ],
        ];

        return $modules;
    }

    // ═════════════════════════════════════════
    // TABLE ELEMENT
    // ═════════════════════════════════════════

    public function register_table_element( array $modules ): array {
        $modules['bbb-table'] = [
            'name'     => esc_html__( 'BBB Liga-Tabelle (Live)', 'bbb-live-tables' ),
            'category' => esc_html__( 'Sport', 'bbb-live-tables' ),
            'icon'     => 'fa-table',
            'options'  => [
                'liga-id' => [
                    'title'       => esc_html__( 'Liga-ID', 'bbb-live-tables' ),
                    'type'        => 'text',
                    'default'     => '',
                    'description' => esc_html__( 'BBB Liga-ID (z.B. 47976).', 'bbb-live-tables' ),
                ],
                'title' => [
                    'title'   => esc_html__( 'Titel', 'bbb-live-tables' ),
                    'type'    => 'text',
                    'default' => '',
                    'description' => esc_html__( 'Leer = Liga-Name aus API.', 'bbb-live-tables' ),
                ],
                'show-logos' => [
                    'title'   => esc_html__( 'Team-Logos anzeigen', 'bbb-live-tables' ),
                    'type'    => 'checkbox',
                    'default' => 'enable',
                ],
                'columns-desktop' => [
                    'title'   => esc_html__( 'Spalten Desktop', 'bbb-live-tables' ),
                    'type'    => 'text',
                    'default' => '',
                    'description' => esc_html__( 'Komma-separiert. Leer = Standard.', 'bbb-live-tables' ),
                ],
                'columns-mobile' => [
                    'title'   => esc_html__( 'Spalten Mobil', 'bbb-live-tables' ),
                    'type'    => 'text',
                    'default' => '',
                ],
                'team-display-desktop' => [
                    'title'   => esc_html__( 'Team-Anzeige Desktop', 'bbb-live-tables' ),
                    'type'    => 'combobox',
                    'options' => [ 'full' => 'Logo + Name', 'short' => 'Logo + Kurzname', 'logo' => 'Nur Logo', 'nameShort' => 'Nur Kurzname' ],
                    'default' => 'full',
                ],
                'team-display-mobile' => [
                    'title'   => esc_html__( 'Team-Anzeige Mobil', 'bbb-live-tables' ),
                    'type'    => 'combobox',
                    'options' => [ 'full' => 'Logo + Name', 'short' => 'Logo + Kurzname', 'logo' => 'Nur Logo', 'nameShort' => 'Nur Kurzname' ],
                    'default' => 'short',
                ],
                'highlight-own' => [
                    'title'   => esc_html__( 'Eigenes Team hervorheben', 'bbb-live-tables' ),
                    'type'    => 'checkbox',
                    'default' => 'enable',
                ],
                'show-gb' => [
                    'title'       => esc_html__( 'Games Behind (GB) anzeigen', 'bbb-live-tables' ),
                    'type'        => 'checkbox',
                    'default'     => 'disable',
                    'description' => esc_html__( 'Zeigt den Rückstand zum Tabellenersten.', 'bbb-live-tables' ),
                ],
                'cache' => [
                    'title'   => esc_html__( 'Cache-Dauer (Sekunden)', 'bbb-live-tables' ),
                    'type'    => 'text',
                    'default' => '900',
                ],
            ],
        ];

        return $modules;
    }

    // ═════════════════════════════════════════
    // SHORTCODES
    // ═════════════════════════════════════════

    public function register_shortcodes(): void {
        add_shortcode( 'gdlr_core_bbb_bracket', [ $this, 'render_bracket_shortcode' ] );
        add_shortcode( 'gdlr_core_bbb_table', [ $this, 'render_table_shortcode' ] );
    }

    public function render_bracket_shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'liga-id'        => '',
            'title'          => '',
            'mode'           => 'ko',
            'best-of'        => '5',
            'show-dates'     => 'enable',
            'show-logos'     => 'enable',
            'highlight-own'  => 'enable',
            'cache'          => '3600',
        ], $atts, 'gdlr_core_bbb_bracket' );

        $liga_id = (int) $atts['liga-id'];
        if ( ! $liga_id ) {
            return '<p class="bbb-bracket-error" role="alert">Bitte eine Liga-ID eingeben.</p>';
        }

        $bracket_atts = [
            'liga_id'        => $liga_id,
            'title'          => $atts['title'],
            'highlight_club' => $atts['highlight-own'] === 'enable' ? (int) get_option( 'bbb_tables_club_id', 0 ) : 0,
            'cache'          => (int) $atts['cache'],
            'show_dates'     => $atts['show-dates'] === 'enable' ? 'true' : 'false',
            'show_logos'     => $atts['show-logos'] === 'enable' ? 'true' : 'false',
            'mode'           => $atts['mode'],
            'best_of'        => (int) $atts['best-of'],
        ];

        $bracket = new BBB_Tournament_Bracket();
        $output  = $bracket->render_shortcode( $bracket_atts );
        return '<div class="gdlr-core-bbb-bracket-item gdlr-core-item-pdlr gdlr-core-item-pdb">' . $output . '</div>';
    }

    public function render_table_shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'liga-id'               => '',
            'title'                 => '',
            'show-logos'            => 'enable',
            'columns-desktop'       => '',
            'columns-mobile'        => '',
            'team-display-desktop'  => 'full',
            'team-display-mobile'   => 'short',
            'highlight-own'         => 'enable',
            'show-gb'               => 'disable',
            'cache'                 => '900',
        ], $atts, 'gdlr_core_bbb_table' );

        $liga_id = (int) $atts['liga-id'];
        if ( ! $liga_id ) {
            return '<p class="bbb-table-error" role="alert">Bitte eine Liga-ID eingeben.</p>';
        }

        $table_atts = [
            'liga_id'              => $liga_id,
            'title'                => $atts['title'],
            'highlight_club'       => $atts['highlight-own'] === 'enable' ? (int) get_option( 'bbb_tables_club_id', 0 ) : 0,
            'cache'                => (int) $atts['cache'],
            'show_logos'           => $atts['show-logos'] === 'enable' ? 'true' : 'false',
            'columns_desktop'      => $atts['columns-desktop'],
            'columns_mobile'       => $atts['columns-mobile'],
            'team_display_desktop' => $atts['team-display-desktop'],
            'team_display_mobile'  => $atts['team-display-mobile'],
            'show_gb'              => $atts['show-gb'] === 'enable' ? 'true' : 'false',
        ];

        $table  = new BBB_Live_Table();
        $output = $table->render_shortcode( $table_atts );
        return '<div class="gdlr-core-bbb-table-item gdlr-core-item-pdlr gdlr-core-item-pdb">' . $output . '</div>';
    }
}
