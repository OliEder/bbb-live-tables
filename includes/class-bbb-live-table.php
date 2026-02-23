<?php
/**
 * BBB Live Table v1.3 (Standalone)
 *
 * Rendert Liga-Tabellen LIVE aus der BBB-API, ohne lokale Datenspeicherung.
 *
 * DSGVO-Vorteil:
 *   - Keine Spieler-/Team-Daten fremder Vereine werden lokal gespeichert
 *   - Nur Caching (Transient) für Performance, keine persistente Speicherung
 *
 * Standalone: Keine SportsPress-Abhängigkeit. Logos direkt von BBB-API.
 * Erweiterbar: bbb-sportspress-sync kann via Filter SP-Logos/Links/Farben liefern.
 *
 * Filter-Hooks (für optionale SP-Integration):
 *   bbb_table_team_logo_url   ( $url, $team_permanent_id )      → Logo-URL
 *   bbb_table_team_url        ( $url, $team_permanent_id )      → Team-Link
 *   bbb_table_theme_colors    ( $defaults )                      → Theme-Farben
 *   bbb_table_own_team_ids    ( $ids, $club_id )                → Eigene Team-PIDs
 *
 * Shortcode:  [bbb_table liga_id="47976"]
 * Block:      bbb/league-table (Gutenberg)
 * Goodlayers: [gdlr_core_bbb_table] (Page Builder Element)
 *
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

class BBB_Live_Table {

    private BBB_Api_Client $api;
    private static bool $css_injected = false;
    private static bool $gb_js_injected = false;

    private const COLUMN_MAP = [
        'platz'              => [ 'label' => '#',      'title' => 'Tabellenplatz',               'align' => 'center', 'priority' => 1 ],
        'teamname'           => [ 'label' => 'Team',   'title' => 'Mannschaft',                  'align' => 'left',   'priority' => 2 ],
        'anzSpiele'          => [ 'label' => 'Sp',     'title' => 'Anzahl Spiele',               'align' => 'center', 'priority' => 10 ],
        'anzGewinnpunkte'    => [ 'label' => 'GP',     'title' => 'Gewinnpunkte (Tabelle)',       'align' => 'center', 'priority' => 11 ],
        'anzVerlustpunkte'   => [ 'label' => 'VP',     'title' => 'Verlustpunkte (Tabelle)',      'align' => 'center', 'priority' => 12 ],
        's'                  => [ 'label' => 'S',      'title' => 'Siege',                       'align' => 'center', 'priority' => 20 ],
        'n'                  => [ 'label' => 'N',      'title' => 'Niederlagen',                 'align' => 'center', 'priority' => 21 ],
        'gb'                 => [ 'label' => 'GB',     'title' => 'Games Behind (Rückstand zum Tabellenführer)', 'align' => 'center', 'priority' => 22 ],
        'koerbe'             => [ 'label' => 'Körbe',  'title' => 'Erzielte Körbe',              'align' => 'right',  'priority' => 30 ],
        'gegenKoerbe'        => [ 'label' => 'Geg.',   'title' => 'Erhaltene Körbe (Gegner)',     'align' => 'right',  'priority' => 31 ],
        'korbdiff'           => [ 'label' => '+/−',    'title' => 'Korbdifferenz',               'align' => 'right',  'priority' => 32 ],
        'korbRatio'          => [ 'label' => 'K:G',    'title' => 'Körbe : Gegenkörbe',            'align' => 'center', 'priority' => 33 ],
        'ppg'                => [ 'label' => 'ØK',     'title' => 'Punkte pro Spiel',            'align' => 'right',  'priority' => 34 ],
        'oppg'               => [ 'label' => 'ØG',     'title' => 'Gegenkörbe pro Spiel',        'align' => 'right',  'priority' => 35 ],
        'punkte'             => [ 'label' => 'Pkt',    'title' => 'Tabellenpunkte',              'align' => 'center', 'priority' => 11 ],
        'quotient'           => [ 'label' => 'Quo.',   'title' => 'Siegquotient',                'align' => 'right',  'priority' => 36 ],
    ];

    private const HIDDEN_COLUMNS = [
        'team', 'teamPermanentId', 'clubId', 'teamnameSmall',
        'teamId', 'ligaId', 'id', 'seasonId', 'seasonTeamId', 'teamCompetitionId',
        'rang', 'anzspiele', 'verzicht', 'rpiRating', 'sosRating',
    ];

    private const DIFF_COLUMNS = [ 'korbdiff' ];

    private const DEFAULT_DESKTOP = 'platz,teamname,anzSpiele,anzGewinnpunkte,s,n,korbRatio,ppg,oppg,korbdiff';
    private const DEFAULT_MOBILE  = 'platz,teamname,s,n,korbdiff';

    private const TEAM_DISPLAY_MODES = [ 'full', 'short', 'logo', 'nameShort' ];

    public function __construct() {
        $this->api = new BBB_Api_Client();
        add_shortcode( 'bbb_table', [ $this, 'render_shortcode' ] );
        add_action( 'init', [ $this, 'register_block' ] );
    }

    // ═════════════════════════════════════════
    // GUTENBERG BLOCK
    // ═════════════════════════════════════════

    public function register_block(): void {
        $block_dir = BBB_TABLES_DIR . 'blocks/table';
        if ( ! file_exists( $block_dir . '/block.json' ) ) return;
        register_block_type( $block_dir, [ 'render_callback' => [ $this, 'render_block' ] ] );
    }

    public function render_block( array $attributes, string $content, WP_Block $block ): string {
        $shortcode_atts = [
            'liga_id'              => (int) ( $attributes['ligaId'] ?? 0 ),
            'title'                => $attributes['title'] ?? '',
            'highlight_club'       => ! empty( $attributes['highlightOwn'] ) ? (int) get_option( 'bbb_tables_club_id', 0 ) : 0,
            'cache'                => (int) ( $attributes['cache'] ?? 900 ),
            'show_logos'           => ! empty( $attributes['showLogos'] ) ? 'true' : 'false',
            'columns_desktop'      => $attributes['columnsDesktop'] ?? '',
            'columns_mobile'       => $attributes['columnsMobile'] ?? '',
            'team_display_desktop' => $attributes['teamDisplayDesktop'] ?? 'full',
            'team_display_mobile'  => $attributes['teamDisplayMobile'] ?? 'short',
            'show_gb'              => ! empty( $attributes['showGb'] ) ? 'true' : 'false',
        ];

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            $shortcode_atts['cache'] = min( $shortcode_atts['cache'], 60 );
        }

        $output = $this->render_shortcode( $shortcode_atts );

        if ( empty( $output ) ) {
            $output = '<p class="bbb-table-error" role="alert">Kein Tabelleninhalt verfügbar.</p>';
        }

        return '<div ' . get_block_wrapper_attributes() . '>' . $output . '</div>';
    }

    // ═════════════════════════════════════════
    // SHORTCODE
    // ═════════════════════════════════════════

    public function render_shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'liga_id'              => 0,
            'title'                => '',
            'highlight_club'       => (int) get_option( 'bbb_tables_club_id', 0 ),
            'cache'                => 900,
            'show_logos'           => 'true',
            'columns_desktop'      => '',
            'columns_mobile'       => '',
            'columns'              => '',
            'team_display_desktop' => 'full',
            'team_display_mobile'  => 'short',
            'show_gb'              => 'false',
        ], $atts, 'bbb_table' );

        $liga_id = (int) $atts['liga_id'];
        if ( ! $liga_id ) {
            return '<p class="bbb-table-error" role="alert">Fehler: liga_id fehlt im Shortcode.</p>';
        }

        $table_data = $this->get_table_data( $liga_id, (int) $atts['cache'] );
        if ( is_wp_error( $table_data ) ) {
            return '<p class="bbb-table-error" role="alert">Tabelle konnte nicht geladen werden: '
                   . esc_html( $table_data->get_error_message() ) . '</p>';
        }

        $title = $atts['title'] ?: ( $table_data['liga_data']['liganame'] ?? "Tabelle #{$liga_id}" );

        $css = '';
        if ( ! self::$css_injected ) {
            $css = '<style id="bbb-table-css">' . $this->get_table_css() . '</style>';
            self::$css_injected = true;
        }

        $show_gb = ( $atts['show_gb'] === 'true' || $atts['show_gb'] === true );

        $cols_desktop = $this->parse_columns_param( $atts['columns_desktop'] ?: $atts['columns'], self::DEFAULT_DESKTOP );
        $cols_mobile  = $this->parse_columns_param( $atts['columns_mobile']  ?: $atts['columns'], self::DEFAULT_MOBILE );

        if ( $show_gb ) {
            $cols_desktop = $this->inject_gb_column( $cols_desktop );
            $cols_mobile  = $this->inject_gb_column( $cols_mobile );
        }

        $td_desktop = in_array( $atts['team_display_desktop'], self::TEAM_DISPLAY_MODES, true ) ? $atts['team_display_desktop'] : 'full';
        $td_mobile  = in_array( $atts['team_display_mobile'],  self::TEAM_DISPLAY_MODES, true ) ? $atts['team_display_mobile']  : 'short';

        return $css . $this->render_table_html(
            $table_data,
            esc_html( $title ),
            (int) $atts['highlight_club'],
            $atts['show_logos'] === 'true',
            $cols_desktop,
            $cols_mobile,
            $td_desktop,
            $td_mobile,
            $show_gb
        );
    }

    private function parse_columns_param( string $param, string $default ): array {
        $str = ! empty( $param ) ? $param : $default;
        return array_filter( array_map( 'trim', explode( ',', $str ) ) );
    }

    private function inject_gb_column( array $columns ): array {
        if ( in_array( 'gb', $columns, true ) ) {
            return $columns;
        }
        $pos = array_search( 'n', $columns, true );
        if ( $pos !== false ) {
            array_splice( $columns, $pos + 1, 0, [ 'gb' ] );
        } else {
            $columns[] = 'gb';
        }
        return $columns;
    }

    // ═════════════════════════════════════════
    // DATA LOADING + PREPROCESSING
    // ═════════════════════════════════════════

    private function get_table_data( int $liga_id, int $cache_ttl ): array|WP_Error {
        $transient_key = "bbb_live_table_{$liga_id}";

        if ( $cache_ttl > 0 ) {
            $cached = get_transient( $transient_key );
            if ( $cached !== false ) return $cached;
        }

        $result = $this->api->get_tabelle( $liga_id );
        if ( is_wp_error( $result ) ) return $result;

        $data = [
            'liga_id'    => $liga_id,
            'liga_data'  => $result['liga_data'] ?? [],
            'entries'    => $this->preprocess_entries( $result['entries'] ?? [] ),
            'fetched_at' => current_time( 'mysql' ),
        ];

        if ( $cache_ttl > 0 ) {
            set_transient( $transient_key, $data, $cache_ttl );
        }

        return $data;
    }

    private function preprocess_entries( array $entries ): array {
        $processed = [];

        foreach ( $entries as $entry ) {
            $row = [];

            $row['platz'] = (int) ( $entry['rang'] ?? 0 );

            $team = $entry['team'] ?? [];
            if ( is_array( $team ) ) {
                $row['teamname']        = $team['teamname'] ?? $team['name'] ?? $team['teamName'] ?? 'Unbekannt';
                $row['teamnameSmall']   = $team['teamnameSmall'] ?? $team['teamname_small'] ?? '';
                $row['teamPermanentId'] = (int) ( $team['teamPermanentId'] ?? $team['permanentId'] ?? 0 );

                $club = $team['club'] ?? [];
                $row['clubId'] = (int) (
                    $team['clubId'] ?? $team['club_id']
                    ?? ( is_array( $club ) ? ( $club['id'] ?? $club['clubId'] ?? 0 ) : 0 )
                );
            } else {
                $row['teamname']        = (string) $team;
                $row['teamnameSmall']   = '';
                $row['teamPermanentId'] = 0;
                $row['clubId']          = 0;
            }

            foreach ( $entry as $key => $value ) {
                if ( $key === 'team' || $key === 'rang' ) continue;
                if ( is_array( $value ) || is_object( $value ) ) continue;
                $row[ $key ] = $value;
            }

            if ( isset( $row['anzspiele'] ) && ! isset( $row['anzSpiele'] ) ) {
                $row['anzSpiele'] = $row['anzspiele'];
            }

            if ( empty( $row['clubId'] ) && ! empty( $entry['clubId'] ) ) {
                $row['clubId'] = (int) $entry['clubId'];
            }

            if ( $row['platz'] === 0 ) {
                $row['platz'] = count( $processed ) + 1;
            }

            // Berechnete Spalten: PPG, OPPG, K:G
            $spiele = (int) ( $row['anzSpiele'] ?? $row['anzspiele'] ?? 0 );
            $koerbe = (int) ( $row['koerbe'] ?? 0 );
            $gegen  = (int) ( $row['gegenKoerbe'] ?? 0 );

            if ( $spiele > 0 ) {
                $row['ppg']  = number_format( $koerbe / $spiele, 1, ',', '' );
                $row['oppg'] = number_format( $gegen / $spiele, 1, ',', '' );
            } else {
                $row['ppg']  = '–';
                $row['oppg'] = '–';
            }
            $row['korbRatio'] = $koerbe . ':' . $gegen;

            $processed[] = $row;
        }

        $this->compute_games_behind( $processed );

        return $processed;
    }

    private function compute_games_behind( array &$entries ): void {
        if ( empty( $entries ) ) return;

        $leader_w = (int) ( $entries[0]['s'] ?? 0 );
        $leader_l = (int) ( $entries[0]['n'] ?? 0 );

        foreach ( $entries as &$row ) {
            $w  = (int) ( $row['s'] ?? 0 );
            $l  = (int) ( $row['n'] ?? 0 );
            $gb = ( ( $leader_w - $w ) + ( $l - $leader_l ) ) / 2;

            if ( $gb == 0 ) {
                $row['gb'] = '–';
            } elseif ( $gb == (int) $gb ) {
                $row['gb'] = (string) (int) $gb;
            } else {
                $row['gb'] = number_format( $gb, 1, '.', '' );
            }
        }
        unset( $row );
    }

    // ═════════════════════════════════════════
    // HTML RENDERING
    // ═════════════════════════════════════════

    private function render_table_html(
        array $table_data,
        string $title,
        int $highlight_club,
        bool $show_logos,
        array $cols_desktop,
        array $cols_mobile,
        string $td_desktop,
        string $td_mobile,
        bool $show_gb = false
    ): string {
        $entries = $table_data['entries'] ?? [];

        if ( empty( $entries ) ) {
            return '<p class="bbb-table-error" role="alert">Keine Tabelleneinträge vorhanden.</p>';
        }

        $all_col_keys = array_values( array_unique( array_merge( $cols_desktop, $cols_mobile ) ) );
        $columns = $this->detect_columns( $entries, $all_col_keys );

        $col_visibility = [];
        foreach ( array_keys( $columns ) as $key ) {
            $in_d = in_array( $key, $cols_desktop, true );
            $in_m = in_array( $key, $cols_mobile, true );
            if ( $in_d && $in_m )     $col_visibility[ $key ] = 'both';
            elseif ( $in_d )          $col_visibility[ $key ] = 'desktop-only';
            elseif ( $in_m )          $col_visibility[ $key ] = 'mobile-only';
            else                      $col_visibility[ $key ] = 'both';
        }

        $table_id = 'bbb-table-' . substr( md5( $title . ( $table_data['liga_id'] ?? '' ) ), 0, 6 );

        $heading_level = max( 2, min( 6, (int) apply_filters( 'bbb_table_heading_level', 3 ) ) );

        $team_class = 'bbb-td-d-' . esc_attr( $td_desktop ) . ' bbb-td-m-' . esc_attr( $td_mobile );

        $scoped_css = $this->get_responsive_column_css( $table_id, $columns, $col_visibility );
        $scoped_css .= $this->get_team_display_css( $table_id, $td_desktop, $td_mobile );

        $html = '<style>' . $scoped_css . '</style>';

        $gb_hidden_class = $show_gb ? ' bbb-gb-hidden' : '';
        $html .= '<section class="bbb-table-wrapper ' . $team_class . $gb_hidden_class . '" id="' . esc_attr( $table_id ) . '" aria-label="' . esc_attr( $title ) . '">';
        $html .= '<h' . $heading_level . ' class="bbb-table-title">' . $title . '</h' . $heading_level . '>';

        if ( $show_gb ) {
            $html .= '<div class="bbb-table-toggle" role="tablist" aria-label="Tabellen-Sortierung">';
            $html .= '<button class="bbb-toggle-btn bbb-toggle-active" role="tab" aria-selected="true" data-sort="dbb"'
                   . ' data-table="' . esc_attr( $table_id ) . '">Offizielle Tabelle</button>';
            $html .= '<button class="bbb-toggle-btn" role="tab" aria-selected="false" data-sort="gb"'
                   . ' data-table="' . esc_attr( $table_id ) . '">Ranking nach GB</button>';
            $html .= '</div>';
        }

        $html .= '<div class="bbb-table-scroll">';
        $html .= '<table class="bbb-table">';
        $html .= '<caption class="bbb-sr-only">' . esc_html( $title ) . '</caption>';

        // <thead>
        $html .= '<thead><tr>';
        foreach ( $columns as $key => $col ) {
            $sort_attr = $key === 'platz' ? ' aria-sort="ascending"' : '';
            $html .= '<th scope="col"'
                   . ' class="bbb-table-col-' . esc_attr( $key ) . '"'
                   . ' style="text-align:' . esc_attr( $col['align'] ) . '"'
                   . ' title="' . esc_attr( $col['title'] ) . '"'
                   . $sort_attr
                   . '><abbr title="' . esc_attr( $col['title'] ) . '">'
                   . esc_html( $col['label'] )
                   . '</abbr></th>';
        }
        $html .= '</tr></thead>';

        // ★ FILTER: Eigene Team-IDs für Highlighting
        $own_permanent_ids = $this->get_own_team_permanent_ids( $highlight_club );

        // <tbody>
        $html .= '<tbody>';
        foreach ( $entries as $entry ) {
            $club_id  = (int) ( $entry['clubId'] ?? 0 );
            $team_pid = (int) ( $entry['teamPermanentId'] ?? 0 );

            $is_own = false;
            if ( $highlight_club > 0 ) {
                $is_own = ( $club_id === $highlight_club )
                       || in_array( $team_pid, $own_permanent_ids, true );
            }

            $row_class = $is_own ? 'bbb-table-row bbb-table-row-own' : 'bbb-table-row';

            $data_attrs = '';
            if ( $show_gb ) {
                $orig_rank = $entry['platz'] ?? '';
                $orig_gb   = $entry['gb'] ?? '–';
                $wins      = (int) ( $entry['s'] ?? 0 );
                $losses    = (int) ( $entry['n'] ?? 0 );
                $data_attrs = ' data-orig-rank="' . esc_attr( $orig_rank ) . '"'
                            . ' data-orig-gb="' . esc_attr( $orig_gb ) . '"'
                            . ' data-s="' . $wins . '"'
                            . ' data-n="' . $losses . '"';
            }

            $html .= '<tr class="' . $row_class . '"' . $data_attrs . '>';

            foreach ( $columns as $key => $col ) {
                $value = $entry[ $key ] ?? '';

                if ( $key === 'platz' ) {
                    $html .= '<th scope="row" class="bbb-table-col-platz" style="text-align:center">'
                           . esc_html( $value ) . '.</th>';
                    continue;
                }

                if ( $key === 'teamname' ) {
                    $html .= $this->render_team_cell( $entry, $show_logos, $is_own );
                    continue;
                }

                if ( $key === 'gb' ) {
                    $gb_class = ( (string) $value === '–' ) ? ' bbb-table-gb-leader' : '';
                    $html .= '<td class="bbb-table-col-gb' . $gb_class . '" style="text-align:center">'
                           . esc_html( $value ) . '</td>';
                    continue;
                }

                if ( in_array( $key, self::DIFF_COLUMNS, true ) ) {
                    $num        = (int) $value;
                    $display    = $num > 0 ? '+' . $num : (string) $num;
                    $diff_class = $num > 0 ? ' bbb-table-diff-pos' : ( $num < 0 ? ' bbb-table-diff-neg' : '' );
                    $html .= '<td class="bbb-table-col-' . esc_attr( $key ) . $diff_class . '"'
                           . ' style="text-align:' . esc_attr( $col['align'] ) . '">'
                           . esc_html( $display ) . '</td>';
                    continue;
                }

                $html .= '<td class="bbb-table-col-' . esc_attr( $key ) . '"'
                       . ' style="text-align:' . esc_attr( $col['align'] ) . '">'
                       . esc_html( $value ) . '</td>';
            }

            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        $html .= '<div class="bbb-table-footer">';
        $html .= '<span class="bbb-table-updated">Stand: ' . esc_html( $table_data['fetched_at'] ?? '' ) . '</span>';
        $html .= '<span class="bbb-table-source">Quelle: basketball-bund.net (Live)</span>';
        $html .= '</div></section>';

        if ( $show_gb ) {
            $html .= $this->get_gb_toggle_js();
        }

        return $html;
    }

    private function render_team_cell( array $entry, bool $show_logos, bool $is_own ): string {
        $full_name  = $this->shorten_team_name( (string) ( $entry['teamname'] ?? '' ) );
        $short_name = (string) ( $entry['teamnameSmall'] ?? '' );
        if ( empty( $short_name ) ) $short_name = mb_strtoupper( mb_substr( $full_name, 0, 3 ) );
        $team_pid   = (int) ( $entry['teamPermanentId'] ?? 0 );

        $logo_html = '';
        if ( $show_logos && $team_pid ) {
            // ★ FILTER: Logo-URL (Sync-Plugin kann SP Featured Image liefern)
            $logo_url = $this->get_team_logo_url( $team_pid );
            if ( $logo_url ) {
                $logo_html = '<img class="bbb-table-logo" src="' . esc_url( $logo_url ) . '"'
                           . ' alt="' . esc_attr( $full_name ) . '" width="20" height="20" loading="lazy">';
            }
        }

        $own_sr = $is_own ? '<span class="bbb-sr-only"> (eigener Verein)</span>' : '';

        $html = '<td class="bbb-table-col-teamname bbb-table-team-cell" style="text-align:left">';

        $html .= '<span class="bbb-team-full">' . $logo_html
               . '<span class="bbb-table-teamname">' . esc_html( $full_name ) . '</span>' . $own_sr . '</span>';

        $html .= '<span class="bbb-team-short">' . $logo_html
               . '<span class="bbb-table-teamname">' . esc_html( $short_name ) . '</span>' . $own_sr . '</span>';

        $html .= '<span class="bbb-team-logo">' . $logo_html
               . '<span class="bbb-sr-only">' . esc_html( $full_name ) . $own_sr . '</span></span>';

        $html .= '<span class="bbb-team-nameShort">'
               . '<span class="bbb-table-teamname">' . esc_html( $short_name ) . '</span>' . $own_sr . '</span>';

        $html .= '</td>';
        return $html;
    }

    // ═════════════════════════════════════════
    // GB-TOGGLE JS
    // ═════════════════════════════════════════

    private function get_gb_toggle_js(): string {
        if ( self::$gb_js_injected ) return '';
        self::$gb_js_injected = true;

        return '<script id="bbb-gb-toggle-js">
(function(){
    function calcGb(rows){
        var best={s:0,n:999,diff:-999};
        rows.forEach(function(r){
            var s=parseInt(r.dataset.s)||0;
            var n=parseInt(r.dataset.n)||0;
            var diff=s-n;
            if(diff>best.diff||(diff===best.diff&&s>best.s)){
                best={s:s,n:n,diff:diff};
            }
        });
        rows.forEach(function(r){
            var s=parseInt(r.dataset.s)||0;
            var n=parseInt(r.dataset.n)||0;
            var gb=((best.s-s)+(n-best.n))/2;
            r._gbVal=gb;
            var cell=r.querySelector(".bbb-table-col-gb");
            if(!cell)return;
            if(gb===0){
                cell.textContent="\u2013";
                cell.classList.add("bbb-table-gb-leader");
            }else{
                cell.textContent=(gb%1===0)?gb.toFixed(0):gb.toFixed(1);
                cell.classList.remove("bbb-table-gb-leader");
            }
        });
    }
    function restoreOrigGb(rows){
        rows.forEach(function(r){
            var cell=r.querySelector(".bbb-table-col-gb");
            if(!cell)return;
            var orig=r.dataset.origGb||"\u2013";
            cell.textContent=orig;
            if(orig==="\u2013")cell.classList.add("bbb-table-gb-leader");
            else cell.classList.remove("bbb-table-gb-leader");
        });
    }
    document.addEventListener("click",function(e){
        var btn=e.target.closest(".bbb-toggle-btn");
        if(!btn)return;
        var tid=btn.dataset.table;
        var mode=btn.dataset.sort;
        var wrap=document.getElementById(tid);
        if(!wrap)return;
        wrap.querySelectorAll(".bbb-toggle-btn").forEach(function(b){
            b.classList.remove("bbb-toggle-active");
            b.setAttribute("aria-selected","false");
        });
        btn.classList.add("bbb-toggle-active");
        btn.setAttribute("aria-selected","true");
        var tbody=wrap.querySelector("tbody");
        if(!tbody)return;
        var rows=Array.from(tbody.querySelectorAll("tr[data-s]"));
        if(mode==="gb")wrap.classList.remove("bbb-gb-hidden");
        else wrap.classList.add("bbb-gb-hidden");
        if(mode==="gb"){
            calcGb(rows);
            rows.sort(function(a,b){
                if(a._gbVal!==b._gbVal)return a._gbVal-b._gbVal;
                return (parseInt(b.dataset.s)||0)-(parseInt(a.dataset.s)||0);
            });
        }else{
            restoreOrigGb(rows);
            rows.sort(function(a,b){
                return parseInt(a.dataset.origRank||"0")-parseInt(b.dataset.origRank||"0");
            });
        }
        var rank=1;
        rows.forEach(function(r){
            tbody.appendChild(r);
            var platzCell=r.querySelector(".bbb-table-col-platz");
            if(platzCell){
                platzCell.textContent=(mode==="gb"?rank:r.dataset.origRank||rank)+".";
            }
            rank++;
        });
    });
})();
</script>';
    }

    // ═════════════════════════════════════════
    // RESPONSIVE CSS
    // ═════════════════════════════════════════

    private function get_responsive_column_css( string $id, array $columns, array $visibility ): string {
        $desktop_only = $mobile_only = [];

        foreach ( $visibility as $key => $mode ) {
            $sel = '#' . $id . ' .bbb-table-col-' . $key;
            if ( $mode === 'desktop-only' )  $desktop_only[] = $sel;
            elseif ( $mode === 'mobile-only' ) $mobile_only[] = $sel;
        }

        $css = '';
        if ( $desktop_only ) {
            $css .= '@media(max-width:599px){' . implode( ',', $desktop_only ) . '{display:none}}';
        }
        if ( $mobile_only ) {
            $css .= '@media(min-width:600px){' . implode( ',', $mobile_only ) . '{display:none}}';
        }
        return $css;
    }

    private function get_team_display_css( string $id, string $desktop, string $mobile ): string {
        $s = '#' . $id;
        $css  = $s . ' .bbb-team-full,' . $s . ' .bbb-team-short,' . $s . ' .bbb-team-logo,' . $s . ' .bbb-team-nameShort{display:none}';
        $css .= '@media(min-width:600px){' . $s . ' .bbb-team-' . $desktop . '{display:inline}}';
        $css .= '@media(max-width:599px){' . $s . ' .bbb-team-' . $mobile . '{display:inline}}';
        return $css;
    }

    // ═════════════════════════════════════════
    // COLUMN DETECTION
    // ═════════════════════════════════════════

    private function detect_columns( array $entries, array $requested ): array {
        $first = $entries[0] ?? [];
        $columns = [];

        if ( ! empty( $requested ) ) {
            foreach ( $requested as $key ) {
                if ( ! array_key_exists( $key, $first ) ) continue;
                $columns[ $key ] = $this->get_column_def( $key, $first[ $key ] ?? '' );
            }
            return $columns;
        }

        foreach ( array_keys( $first ) as $key ) {
            if ( in_array( $key, self::HIDDEN_COLUMNS, true ) ) continue;
            $columns[ $key ] = $this->get_column_def( $key, $first[ $key ] ?? '' );
        }

        uasort( $columns, fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
        return $columns;
    }

    private function get_column_def( string $key, mixed $sample ): array {
        if ( isset( self::COLUMN_MAP[ $key ] ) ) {
            return self::COLUMN_MAP[ $key ];
        }
        $readable = ucfirst( strtolower( preg_replace( '/([a-z])([A-Z])/', '$1 $2', $key ) ) );
        return [ 'label' => $readable, 'title' => $readable, 'align' => is_numeric( $sample ) ? 'right' : 'left', 'priority' => 50 ];
    }

    // ═════════════════════════════════════════
    // HELPERS (★ mit Filter-Hooks)
    // ═════════════════════════════════════════

    /**
     * Eigene Team-IDs ermitteln.
     *
     * ★ FILTER: bbb_table_own_team_ids
     * Sync-Plugin kann hier SP-basierte IDs liefern.
     * Standalone-Fallback: bbb_tables_own_team_pids Option.
     */
    private function get_own_team_permanent_ids( int $club_id ): array {
        static $cache = [];
        if ( ! $club_id ) return [];
        if ( isset( $cache[ $club_id ] ) ) return $cache[ $club_id ];

        // 1. Filter fragen (Sync-Plugin liefert SP-basierte IDs)
        $ids = apply_filters( 'bbb_table_own_team_ids', [], $club_id );

        // 2. API Auto-Discovery: Team-PIDs aus actualmatches (24h gecached)
        if ( empty( $ids ) ) {
            $ids = $this->api->get_club_team_ids( $club_id );
        }

        // 3. Manuelle Liste aus Plugin-Einstellungen (letzter Fallback)
        if ( empty( $ids ) ) {
            $manual = get_option( 'bbb_tables_own_team_pids', '' );
            if ( ! empty( $manual ) ) {
                $ids = array_map( 'intval', array_filter( explode( ',', $manual ) ) );
            }
        }

        $cache[ $club_id ] = $ids;
        return $ids;
    }

    private function shorten_team_name( string $name ): string {
        return preg_replace( '/\s*\([^)]*\)\s*$/', '', $name );
    }

    /**
     * Team-Logo URL.
     *
     * ★ FILTER: bbb_table_team_logo_url
     * Sync-Plugin kann SP Featured Image liefern.
     * Standalone-Fallback: BBB Media URL.
     */
    private function get_team_logo_url( int $permanent_id ): string {
        $fallback = "https://www.basketball-bund.net/media/team/{$permanent_id}/logo";
        $url = apply_filters( 'bbb_table_team_logo_url', '', $permanent_id );
        return $url ?: $fallback;
    }

    // ═════════════════════════════════════════
    // CACHE
    // ═════════════════════════════════════════

    public static function invalidate_cache( int $liga_id ): void {
        delete_transient( "bbb_live_table_{$liga_id}" );
    }

    public static function invalidate_all_caches(): void {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_bbb_live_table_%'
             OR option_name LIKE '_transient_timeout_bbb_live_table_%'"
        );
    }

    // ═════════════════════════════════════════
    // STYLES
    // ═════════════════════════════════════════

    private function get_table_css(): string {
        $c = $this->get_theme_colors();
        $primary_light = $this->hex_lighten( $c['primary'], 92 );
        $own_bg        = $this->hex_lighten( $c['link'], 88 );
        $own_border    = $c['link'];

        return '
/* ═══ BBB Live Table v1.3 (Standalone) ═══ */
.bbb-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.bbb-table-wrapper{margin:2em 0}
.bbb-table-title{font-size:1.3em;margin-bottom:.6em;color:' . esc_attr( $c['primary'] ) . '}
.bbb-table-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
.bbb-table{width:100%;border-collapse:collapse;font-size:.88em;line-height:1.4;font-variant-numeric:tabular-nums}
.bbb-table thead th{background:' . esc_attr( $c['primary'] ) . ';color:' . esc_attr( $c['heading'] ) . ';font-weight:700;font-size:.82em;letter-spacing:.03em;padding:8px 10px;white-space:nowrap;border-bottom:2px solid ' . esc_attr( $c['primary'] ) . '}
.bbb-table thead th abbr{text-decoration:none;cursor:help}
.bbb-table tbody td,.bbb-table tbody th{padding:7px 10px;border-bottom:1px solid #eee;vertical-align:middle;background:transparent!important;color:' . esc_attr( $c['text'] ) . '!important}
.bbb-table tbody th.bbb-table-col-platz{color:' . esc_attr( $c['primary'] ) . '!important}
.bbb-table tbody tr:nth-child(even) td,.bbb-table tbody tr:nth-child(even) th{background:' . esc_attr( $this->hex_lighten( $c['primary'], 90 ) ) . '!important}
.bbb-table tbody tr:hover td,.bbb-table tbody tr:hover th{background:' . esc_attr( $primary_light ) . '!important}
.bbb-table-row-own td,.bbb-table-row-own th{background:' . esc_attr( $own_bg ) . '!important;font-weight:600}
.bbb-table-row-own{position:relative}
.bbb-table-row-own>td:first-child,.bbb-table-row-own>th:first-child{box-shadow:inset 4px 0 0 ' . esc_attr( $own_border ) . '}
.bbb-table-row-own td,.bbb-table-row-own th{border-bottom-color:' . esc_attr( $own_border ) . '44}
.bbb-table-row-own .bbb-table-teamname{color:' . esc_attr( $own_border ) . '!important}
.bbb-table-col-platz{font-weight:700;min-width:32px;color:' . esc_attr( $c['primary'] ) . '}
.bbb-table-team-cell{white-space:nowrap}
.bbb-table-logo{width:20px;height:20px;object-fit:contain;border-radius:2px;vertical-align:middle;margin-right:6px}
.bbb-table-teamname{vertical-align:middle}
.bbb-team-full,.bbb-team-short,.bbb-team-logo,.bbb-team-nameShort{display:none}
.bbb-table-col-gb{font-weight:600;color:#666}
.bbb-table-gb-leader{color:' . esc_attr( $c['primary'] ) . '}
.bbb-table-diff-pos{color:#27ae60}
.bbb-table-diff-neg{color:#c0392b}
.bbb-gb-hidden .bbb-table-col-gb{display:none}
.bbb-table-toggle{display:flex;gap:0;margin-bottom:.8em}
.bbb-toggle-btn{padding:6px 14px;font-size:.82em;font-weight:600;border:1px solid #ccc;background:#f8f8f8;color:#666;cursor:pointer;transition:all .15s ease;line-height:1.4}
.bbb-toggle-btn:first-child{border-radius:4px 0 0 4px}
.bbb-toggle-btn:last-child{border-radius:0 4px 4px 0;border-left:0}
.bbb-toggle-btn:hover{background:#eee}
.bbb-toggle-btn.bbb-toggle-active{background:' . esc_attr( $c['primary'] ) . ';color:' . esc_attr( $c['heading'] ) . ';border-color:' . esc_attr( $c['primary'] ) . '}
.bbb-toggle-btn:focus-visible{outline:2px solid ' . esc_attr( $c['link'] ) . ';outline-offset:2px;z-index:1}
.bbb-table-footer{display:flex;justify-content:space-between;gap:1em;font-size:.72em;color:#999;margin-top:.5em;flex-wrap:wrap}
.bbb-table-source{font-style:italic}
.bbb-table-error{color:#c0392b;background:#fdecea;padding:.8em 1em;border-radius:4px;border-left:4px solid ' . esc_attr( $c['primary'] ) . '}
.bbb-table a:focus-visible{outline:2px solid ' . esc_attr( $c['link'] ) . ';outline-offset:2px}
@media(max-width:600px){
  .bbb-table{font-size:.78em}
  .bbb-table thead th,.bbb-table tbody td,.bbb-table tbody th{padding:5px 6px}
  .bbb-table-logo{width:16px;height:16px}
}
';
    }

    /**
     * Theme-Farben ermitteln.
     *
     * ★ FILTER: bbb_table_theme_colors
     * Sync-Plugin kann SP/ThemeBoy-Farben liefern.
     * Standalone: eigene Plugin-Settings + Goodlayers Fallback.
     */
    private function get_theme_colors(): array {
        $defaults = [
            'primary'    => get_option( 'bbb_tables_color_primary', '' ),
            'background' => get_option( 'bbb_tables_color_background', '' ),
            'link'       => get_option( 'bbb_tables_color_link', '' ),
            'text'       => get_option( 'bbb_tables_color_text', '' ),
            'heading'    => get_option( 'bbb_tables_color_heading', '' ),
        ];

        // Goodlayers Fallback (ohne SP)
        if ( empty( $defaults['primary'] ) && function_exists( 'gdlr_core_get_option' ) ) {
            $gp = gdlr_core_get_option( 'skin_color', '' );
            if ( $gp ) $defaults['primary'] = $gp;
            $gl = gdlr_core_get_option( 'link_color', '' );
            if ( $gl ) $defaults['link'] = $gl;
        }

        // Hardcoded Defaults für leere Werte
        $defaults = array_merge( [
            'primary'    => '#2b353e',
            'background' => '#f4f4f4',
            'link'       => '#00a69c',
            'text'       => '#222222',
            'heading'    => '#ffffff',
        ], array_filter( $defaults ) );

        // ★ FILTER: Sync-Plugin kann SP-Farben injizieren
        return apply_filters( 'bbb_table_theme_colors', $defaults );
    }

    private function hex_lighten( string $hex, int $percent ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = min( 255, hexdec( substr( $hex, 0, 2 ) ) + round( (255 - hexdec( substr( $hex, 0, 2 ) )) * $percent / 100 ) );
        $g = min( 255, hexdec( substr( $hex, 2, 2 ) ) + round( (255 - hexdec( substr( $hex, 2, 2 ) )) * $percent / 100 ) );
        $b = min( 255, hexdec( substr( $hex, 4, 2 ) ) + round( (255 - hexdec( substr( $hex, 4, 2 ) )) * $percent / 100 ) );
        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }
}
