<?php
/**
 * BBB Tournament Bracket v1.1 (Standalone)
 *
 * Rendert KO-/Pokal-Brackets und Playoff-Serien aus BBB-API Daten.
 * Standalone – kein SportsPress nötig.
 *
 * Filter-Hooks (für optionale SP-Integration):
 *   bbb_table_team_logo_url   ( $url, $team_permanent_id )  → Logo-URL
 *   bbb_table_team_url        ( $url, $team_permanent_id )  → Team-Link
 *   bbb_table_event_url       ( $url, $match_id )           → Event/Spielbericht-Link
 *   bbb_table_theme_colors    ( $defaults )                  → Theme-Farben
 *
 * Shortcode: [bbb_bracket liga_id="47976"]
 * Block:     bbb/tournament-bracket (Gutenberg)
 *
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

class BBB_Tournament_Bracket {

    private BBB_Api_Client $api;
    private static bool $css_injected = false;

    public function __construct() {
        $this->api = new BBB_Api_Client();
        add_shortcode( 'bbb_bracket', [ $this, 'render_shortcode' ] );
        add_action( 'init', [ $this, 'register_block' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    public function register_block(): void {
        $block_dir = BBB_TABLES_DIR . 'blocks/bracket';
        if ( ! file_exists( $block_dir . '/block.json' ) ) return;
        register_block_type( $block_dir, [ 'render_callback' => [ $this, 'render_block' ] ] );
    }

    public function render_block( array $attributes, string $content, WP_Block $block ): string {
        $shortcode_atts = [
            'liga_id'        => (int) ( $attributes['ligaId'] ?? 0 ),
            'title'          => $attributes['title'] ?? '',
            'highlight_club' => (int) ( $attributes['highlightClub'] ?? 0 ),
            'cache'          => (int) ( $attributes['cache'] ?? 3600 ),
            'show_dates'     => ! empty( $attributes['showDates'] ) ? 'true' : 'false',
            'show_logos'     => ! empty( $attributes['showLogos'] ) ? 'true' : 'false',
            'mode'           => $attributes['mode'] ?? 'ko',
            'best_of'        => (int) ( $attributes['bestOf'] ?? 1 ),
        ];

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            $shortcode_atts['cache'] = min( $shortcode_atts['cache'], 60 );
        }

        $output = $this->render_shortcode( $shortcode_atts );
        if ( empty( $output ) ) {
            $output = '<p class="bbb-bracket-error" role="alert">Kein Bracket-Inhalt verfügbar.</p>';
        }
        return '<div ' . get_block_wrapper_attributes() . '>' . $output . '</div>';
    }

    // ═════════════════════════════════════════
    // REST API (für Gutenberg Block Editor)
    // ═════════════════════════════════════════

    public function register_rest_routes(): void {
        register_rest_route( 'bbb/v1', '/leagues', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_leagues' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ] );
    }

    /**
     * Alle verfügbaren Ligen als JSON liefern.
     *
     * Kaskade:
     *   1. Filter bbb_table_liga_options (Sync-Plugin kann SP-basierte Liste liefern)
     *   2. API Auto-Discovery via /club/id/{clubId}/actualmatches (24h Cache)
     *   3. Leeres Array (manuelle Liga-ID Eingabe)
     */
    public function rest_get_leagues( WP_REST_Request $request ): WP_REST_Response {
        $type_filter = $request->get_param( 'type' ) ?: '';

        // 1. Filter fragen (Sync-Plugin liefert SP-basierte Ligen)
        $leagues = apply_filters( 'bbb_table_liga_options', [], $type_filter );

        // 2. Standalone-Fallback: API Auto-Discovery
        if ( empty( $leagues ) ) {
            $club_id = (int) get_option( 'bbb_tables_club_id', 0 );
            if ( $club_id ) {
                $all_leagues = $this->api->get_club_leagues( $club_id );

                // Typ-Filter anwenden
                if ( $type_filter && ! empty( $all_leagues ) ) {
                    $all_leagues = array_values( array_filter(
                        $all_leagues,
                        fn( $l ) => $l['type'] === $type_filter
                    ) );
                }

                $leagues = $all_leagues;
            }
        }

        return new WP_REST_Response( $leagues, 200 );
    }

    // ═════════════════════════════════════════
    // SHORTCODE
    // ═════════════════════════════════════════

    public function render_shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'liga_id'        => 0,
            'title'          => '',
            'highlight_club' => (int) get_option( 'bbb_tables_club_id', 0 ),
            'cache'          => 3600,
            'show_dates'     => 'true',
            'show_logos'     => 'true',
            'mode'           => 'ko',
            'best_of'        => 1,
        ], $atts, 'bbb_bracket' );

        $liga_id = (int) $atts['liga_id'];
        if ( ! $liga_id ) {
            return '<p class="bbb-bracket-error" role="alert">Fehler: liga_id fehlt im Shortcode.</p>';
        }

        $tournament = $this->get_tournament_data( $liga_id, (int) $atts['cache'] );
        if ( is_wp_error( $tournament ) ) {
            return '<p class="bbb-bracket-error" role="alert">Bracket konnte nicht geladen werden.</p>';
        }

        $title = $atts['title'] ?: ( $tournament['liga_data']['liganame'] ?? "Tournament #{$liga_id}" );

        $css = '';
        if ( ! self::$css_injected ) {
            $css = '<style id="bbb-bracket-css">' . $this->get_bracket_css() . '</style>';
            self::$css_injected = true;
        }

        $mode    = in_array( $atts['mode'], [ 'ko', 'playoff' ], true ) ? $atts['mode'] : 'ko';
        $best_of = max( 1, (int) $atts['best_of'] );
        if ( $mode === 'playoff' && $best_of <= 1 ) $best_of = 5;
        if ( $best_of > 1 ) $mode = 'playoff';

        return $css . $this->render_bracket_html(
            $tournament,
            esc_html( $title ),
            (int) $atts['highlight_club'],
            $atts['show_dates'] === 'true',
            $atts['show_logos'] === 'true',
            $mode,
            $best_of
        );
    }

    // ═════════════════════════════════════════
    // DATA LOADING + CACHING
    // ═════════════════════════════════════════

    private function get_tournament_data( int $liga_id, int $cache_ttl ): array|WP_Error {
        $transient_key = "bbb_bracket_{$liga_id}";

        if ( $cache_ttl > 0 ) {
            $cached = get_transient( $transient_key );
            if ( $cached !== false ) return $cached;
        }

        $result = $this->api->get_tournament_rounds( $liga_id );
        if ( is_wp_error( $result ) ) return $result;

        $bracket = $this->build_bracket_structure( $result, $liga_id );

        if ( $cache_ttl > 0 ) {
            set_transient( $transient_key, $bracket, $cache_ttl );
        }

        return $bracket;
    }

    private function build_bracket_structure( array $api_data, int $liga_id ): array {
        $rounds = $api_data['rounds'] ?? [];
        $liga_data = $api_data['liga_data'] ?? [];

        $first_round = reset( $rounds );
        $first_round_matches = count( $first_round['matches'] ?? [] );
        $total_teams = $first_round_matches * 2;

        $bracket_rounds = [];
        foreach ( $rounds as $round_nr => $round ) {
            $matches = [];
            foreach ( $round['matches'] as $match ) {
                $matches[] = $this->normalize_match( $match );
            }
            $round_name = $this->get_round_name( count( $matches ), $round['name'] ?? '' );
            $bracket_rounds[ $round_nr ] = [
                'name'     => $round_name,
                'api_name' => $round['name'] ?? '',
                'matches'  => $matches,
            ];
        }

        return [
            'liga_id'     => $liga_id,
            'liga_data'   => $liga_data,
            'liga_name'   => $liga_data['liganame'] ?? '',
            'total_teams' => $total_teams,
            'rounds'      => $bracket_rounds,
            'updated_at'  => current_time( 'mysql' ),
        ];
    }

    private function normalize_match( array $match ): array {
        $match_id   = (int) ( $match['matchId'] ?? 0 );
        $result_str = $match['result'] ?? null;
        $abgesagt   = $match['abgesagt'] ?? false;

        $home = [
            'name'         => $match['homeTeam']['teamname'] ?? '?',
            'permanent_id' => $match['homeTeam']['teamPermanentId'] ?? null,
            'club_id'      => $match['homeTeam']['clubId'] ?? null,
            'is_bye'       => $this->is_bye_team( $match['homeTeam'] ?? [] ),
        ];
        $guest = [
            'name'         => $match['guestTeam']['teamname'] ?? '?',
            'permanent_id' => $match['guestTeam']['teamPermanentId'] ?? null,
            'club_id'      => $match['guestTeam']['clubId'] ?? null,
            'is_bye'       => $this->is_bye_team( $match['guestTeam'] ?? [] ),
        ];

        $winner = null;
        if ( $result_str && str_contains( $result_str, ':' ) ) {
            [ $home_score, $guest_score ] = array_map( 'intval', explode( ':', $result_str ) );
            $winner = $home_score > $guest_score ? 'home' : ( $guest_score > $home_score ? 'guest' : null );
        } elseif ( $home['is_bye'] ) {
            $winner = 'guest';
        } elseif ( $guest['is_bye'] ) {
            $winner = 'home';
        }

        return [
            'match_id'  => $match_id,
            'match_no'  => $match['matchNo'] ?? null,
            'date'      => $match['kickoffDate'] ?? null,
            'time'      => $match['kickoffTime'] ?? null,
            'home'      => $home,
            'guest'     => $guest,
            'result'    => $result_str,
            'abgesagt'  => $abgesagt,
            'winner'    => $winner,
            'confirmed' => $match['ergebnisbestaetigt'] ?? false,
        ];
    }

    private function is_bye_team( array $team ): bool {
        $pid  = $team['teamPermanentId'] ?? null;
        $name = mb_strtolower( $team['teamname'] ?? '' );
        if ( $pid === null || (int) $pid === 0 ) return true;
        if ( str_contains( $name, 'freilos' ) ) return true;
        if ( $name === '?' ) return true;
        return false;
    }

    private function get_round_name( int $match_count, string $api_name = '' ): string {
        return match( $match_count ) {
            1       => 'Finale',
            2       => 'Halbfinale',
            4       => 'Viertelfinale',
            8       => 'Achtelfinale',
            16      => 'Sechzehntelfinale',
            default => $api_name ?: "{$match_count} Spiele",
        };
    }

    // ═════════════════════════════════════════
    // HTML RENDERING (identisch, nur Helper nutzen Filter)
    // ═════════════════════════════════════════

    private function render_bracket_html(
        array $tournament, string $title, int $highlight_club,
        bool $show_dates, bool $show_logos, string $mode = 'ko', int $best_of = 1
    ): string {
        $rounds = $tournament['rounds'] ?? [];
        if ( empty( $rounds ) ) {
            return '<p class="bbb-bracket-error" role="alert">Keine Runden gefunden.</p>';
        }

        $total_rounds = $this->calculate_total_rounds( $tournament['total_teams'] );
        $heading_level = max( 2, min( 6, (int) apply_filters( 'bbb_bracket_heading_level', 3 ) ) );
        $sub_heading   = min( 6, $heading_level + 1 );

        $html = '<section class="bbb-bracket-wrapper" aria-label="' . esc_attr( $title ) . '">';
        $html .= '<h' . $heading_level . ' class="bbb-bracket-title">' . $title . '</h' . $heading_level . '>';
        $html .= '<p class="bbb-sr-only">' . esc_html( sprintf( 'Turnierklammer mit %d Runden. Horizontal scrollbar.', $total_rounds ) ) . '</p>';
        $html .= '<div class="bbb-bracket" role="group" aria-label="Turnierbaum" data-rounds="' . $total_rounds . '">';

        foreach ( $rounds as $round_nr => $round ) {
            if ( $mode === 'playoff' && $best_of > 1 ) {
                $series = $this->group_matches_into_series( $round['matches'], $best_of );
                $round_for_render = array_merge( $round, [ 'series' => $series ] );
                $html .= $this->render_round_playoff( $round_for_render, $round_nr, $highlight_club, $show_dates, $show_logos, $sub_heading, $best_of );
            } else {
                $html .= $this->render_round( $round, $round_nr, $highlight_club, $show_dates, $show_logos, $sub_heading );
            }
        }

        $max_existing = max( array_keys( $rounds ) );
        if ( $mode === 'playoff' && $best_of > 1 ) {
            $last_series = $this->group_matches_into_series( $rounds[ $max_existing ]['matches'] ?? [], $best_of );
            $last_match_count = count( $last_series );
        } else {
            $last_match_count = count( $rounds[ $max_existing ]['matches'] ?? [] );
        }
        $prev_round_data = $rounds[ $max_existing ] ?? null;

        for ( $r = $max_existing + 1; $r <= $total_rounds; $r++ ) {
            $expected_matches = max( 1, intdiv( $last_match_count, 2 ) );
            $round_name = $this->get_round_name( $expected_matches );
            $html .= $this->render_empty_round( $round_name, $expected_matches, $r, $prev_round_data, $sub_heading );
            $last_match_count = $expected_matches;
            $prev_round_data = null;
        }

        $html .= '</div>';
        $html .= '<p class="bbb-bracket-updated">Stand: ' . esc_html( $tournament['updated_at'] ?? '' ) . '</p>';
        $html .= '</section>';

        return $html;
    }

    private function render_round( array $round, int $round_nr, int $highlight_club, bool $show_dates, bool $show_logos, int $sub_heading = 4 ): string {
        $match_count = count( $round['matches'] );
        $html  = '<section class="bbb-bracket-round" data-round="' . $round_nr . '" aria-label="' . esc_attr( $round['name'] ) . '">';
        $html .= '<h' . $sub_heading . ' class="bbb-round-header">' . esc_html( $round['name'] ) . '</h' . $sub_heading . '>';
        $html .= '<div class="bbb-round-matches" role="list" aria-label="' . esc_attr( sprintf( '%s – %d Spiele', $round['name'], $match_count ) ) . '">';
        foreach ( $round['matches'] as $idx => $match ) {
            $html .= $this->render_match( $match, $highlight_club, $show_dates, $show_logos, $round['name'], $idx + 1 );
        }
        $html .= '</div></section>';
        return $html;
    }

    private function render_match( array $match, int $highlight_club, bool $show_dates, bool $show_logos, string $round_name = '', int $match_number = 1 ): string {
        $match_id = $match['match_id'];
        // ★ FILTER: Event-URL
        $event_url = $this->get_event_url( $match_id );
        $has_result = ! empty( $match['result'] );

        $match_class = 'bbb-match';
        if ( $match['abgesagt'] ) $match_class .= ' bbb-match-cancelled';
        if ( ! $has_result && ! $match['home']['is_bye'] && ! $match['guest']['is_bye'] ) {
            $match_class .= ' bbb-match-upcoming';
        }

        $home_name  = $match['home']['is_bye'] ? 'Freilos' : $this->shorten_team_name( $match['home']['name'] );
        $guest_name = $match['guest']['is_bye'] ? 'Freilos' : $this->shorten_team_name( $match['guest']['name'] );
        $sr_label   = sprintf( '%s Spiel %d: %s gegen %s', $round_name, $match_number, $home_name, $guest_name );
        if ( $has_result ) $sr_label .= ', Ergebnis ' . str_replace( ':', ' zu ', $match['result'] );

        $html = '<div class="' . $match_class . '" role="listitem" data-match-id="' . $match_id . '" aria-label="' . esc_attr( $sr_label ) . '">';

        if ( $show_dates && $match['date'] ) {
            $formatted_date = $this->format_date( $match['date'], $match['time'] );
            $iso_date = $match['date'] . ( $match['time'] ? 'T' . $match['time'] : '' );
            $html .= '<time class="bbb-match-date" datetime="' . esc_attr( $iso_date ) . '">' . esc_html( $formatted_date ) . '</time>';
        }

        $html .= $this->render_team_row( $match['home'], $match, 'home', $highlight_club, $show_logos, $event_url );
        $html .= $this->render_team_row( $match['guest'], $match, 'guest', $highlight_club, $show_logos, $event_url );
        $html .= '</div>';
        return $html;
    }

    private function render_team_row( array $team, array $match, string $side, int $highlight_club, bool $show_logos, ?string $event_url ): string {
        $is_winner = $match['winner'] === $side;
        $is_loser  = $match['winner'] !== null && ! $is_winner;
        $is_bye    = $team['is_bye'];
        $is_own    = (int) $team['club_id'] === $highlight_club && $highlight_club > 0;

        $classes = [ 'bbb-team' ];
        if ( $is_winner ) $classes[] = 'bbb-team-winner';
        if ( $is_loser )  $classes[] = 'bbb-team-loser';
        if ( $is_bye )    $classes[] = 'bbb-team-bye';
        if ( $is_own )    $classes[] = 'bbb-team-own';

        $result_parts = $match['result'] ? explode( ':', $match['result'] ) : [ null, null ];
        $score = $side === 'home' ? ( $result_parts[0] ?? '' ) : ( $result_parts[1] ?? '' );
        $short_name = $is_bye ? 'Freilos' : $this->shorten_team_name( $team['name'] );

        $html = '<div class="' . implode( ' ', $classes ) . '">';

        if ( $show_logos && ! $is_bye && $team['permanent_id'] ) {
            // ★ FILTER: Logo-URL
            $logo_url = $this->get_team_logo_url( (int) $team['permanent_id'] );
            if ( $logo_url ) {
                $html .= '<img class="bbb-team-logo" src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( sprintf( 'Logo %s', $short_name ) ) . '" width="20" height="20" loading="lazy">';
            }
        }

        $team_name_html = $is_bye ? '<em>Freilos</em>' : esc_html( $short_name );
        // ★ FILTER: Team-URL
        $team_url = $is_bye ? null : $this->get_team_url( $team['permanent_id'] );

        if ( $team_url ) {
            $html .= '<a class="bbb-team-name" href="' . esc_url( $team_url ) . '">' . $team_name_html . '</a>';
        } else {
            $html .= '<span class="bbb-team-name">' . $team_name_html . '</span>';
        }

        if ( $is_winner ) {
            $html .= '<span class="bbb-sr-only"> (Gewinner)</span>';
        } elseif ( $is_loser ) {
            $html .= '<span class="bbb-sr-only"> (Ausgeschieden)</span>';
        }

        if ( $score !== '' ) {
            if ( $event_url ) {
                $html .= '<a class="bbb-team-score" href="' . esc_url( $event_url ) . '" aria-label="' . esc_attr( sprintf( 'Ergebnis %s: %s Punkte – zum Spielbericht', $short_name, $score ) ) . '">' . esc_html( $score ) . '</a>';
            } else {
                $html .= '<span class="bbb-team-score" aria-label="' . esc_attr( sprintf( '%s Punkte für %s', $score, $short_name ) ) . '">' . esc_html( $score ) . '</span>';
            }
        }

        $html .= '</div>';
        return $html;
    }

    private function render_empty_round( string $name, int $match_count, int $round_nr, ?array $prev_round, int $sub_heading = 4 ): string {
        $html  = '<section class="bbb-bracket-round bbb-round-future" data-round="' . $round_nr . '" aria-label="' . esc_attr( $name ) . ' (ausstehend)">';
        $html .= '<h' . $sub_heading . ' class="bbb-round-header">' . esc_html( $name ) . '</h' . $sub_heading . '>';
        $html .= '<div class="bbb-round-matches" role="list" aria-label="' . esc_attr( sprintf( '%s – %d Spiele (ausstehend)', $name, $match_count ) ) . '">';

        $advancing_teams = [];
        if ( $prev_round ) {
            foreach ( $prev_round['matches'] as $m ) {
                if ( $m['winner'] ) {
                    $advancing_teams[] = $m['winner'] === 'home' ? $m['home'] : $m['guest'];
                } else {
                    $advancing_teams[] = null;
                }
            }
        }

        for ( $i = 0; $i < $match_count; $i++ ) {
            $home_team = $advancing_teams[ $i * 2 ] ?? null;
            $away_team = $advancing_teams[ $i * 2 + 1 ] ?? null;
            $home_label = $home_team ? $this->shorten_team_name( $home_team['name'] ) : 'Noch offen';
            $away_label = $away_team ? $this->shorten_team_name( $away_team['name'] ) : 'Noch offen';
            $sr_label = sprintf( '%s Spiel %d: %s gegen %s (ausstehend)', $name, $i + 1, $home_label, $away_label );

            $html .= '<div class="bbb-match bbb-match-future" role="listitem" aria-label="' . esc_attr( $sr_label ) . '">';
            $html .= '<div class="bbb-team bbb-team-tbd"><span class="bbb-team-name">'
                   . ( $home_team ? esc_html( $home_label ) : '<em aria-hidden="true">TBD</em><span class="bbb-sr-only">Noch offen</span>' )
                   . '</span></div>';
            $html .= '<div class="bbb-team bbb-team-tbd"><span class="bbb-team-name">'
                   . ( $away_team ? esc_html( $away_label ) : '<em aria-hidden="true">TBD</em><span class="bbb-sr-only">Noch offen</span>' )
                   . '</span></div>';
            $html .= '</div>';
        }

        $html .= '</div></section>';
        return $html;
    }

    // ═════════════════════════════════════════
    // PLAYOFF: SERIEN-LOGIK (identisch zum Original)
    // ═════════════════════════════════════════

    private function group_matches_into_series( array $matches, int $best_of ): array {
        $wins_needed = (int) ceil( $best_of / 2 );
        $pairings = [];

        foreach ( $matches as $match ) {
            $home_pid  = (int) ( $match['home']['permanent_id'] ?? 0 );
            $guest_pid = (int) ( $match['guest']['permanent_id'] ?? 0 );

            if ( $match['home']['is_bye'] || $match['guest']['is_bye'] ) {
                $key = 'bye_' . max( $home_pid, $guest_pid );
                $pairings[ $key ] = [
                    'team_a' => $match['guest']['is_bye'] ? $match['home'] : $match['guest'],
                    'team_b' => $match['guest']['is_bye'] ? $match['guest'] : $match['home'],
                    'wins_a' => $wins_needed, 'wins_b' => 0,
                    'games' => [ $match ], 'series_winner' => 'a',
                    'wins_needed' => $wins_needed, 'is_bye' => true,
                ];
                continue;
            }

            $ids = [ $home_pid, $guest_pid ];
            sort( $ids );
            $key = implode( '_', $ids );

            if ( ! isset( $pairings[ $key ] ) ) {
                $team_a = $home_pid < $guest_pid ? $match['home'] : $match['guest'];
                $team_b = $home_pid < $guest_pid ? $match['guest'] : $match['home'];
                $pairings[ $key ] = [
                    'team_a' => $team_a, 'team_b' => $team_b,
                    'wins_a' => 0, 'wins_b' => 0,
                    'games' => [], 'series_winner' => null,
                    'wins_needed' => $wins_needed, 'is_bye' => false,
                ];
            }

            $pairings[ $key ]['games'][] = $match;

            if ( $match['winner'] === 'home' ) $winner_pid = $home_pid;
            elseif ( $match['winner'] === 'guest' ) $winner_pid = $guest_pid;
            else continue;

            if ( $winner_pid === min( $ids ) ) $pairings[ $key ]['wins_a']++;
            else $pairings[ $key ]['wins_b']++;
        }

        foreach ( $pairings as &$series ) {
            if ( $series['is_bye'] ?? false ) continue;
            if ( $series['wins_a'] >= $wins_needed ) $series['series_winner'] = 'a';
            elseif ( $series['wins_b'] >= $wins_needed ) $series['series_winner'] = 'b';
            usort( $series['games'], fn( $a, $b ) => strcmp( ( $a['date'] ?? '' ) . ( $a['time'] ?? '' ), ( $b['date'] ?? '' ) . ( $b['time'] ?? '' ) ) );
        }
        unset( $series );

        return array_values( $pairings );
    }

    private function render_round_playoff( array $round, int $round_nr, int $highlight_club, bool $show_dates, bool $show_logos, int $sub_heading, int $best_of ): string {
        $series_list = $round['series'] ?? [];
        $round_label = sprintf( '%s (Best of %d)', $round['name'], $best_of );
        $html  = '<section class="bbb-bracket-round bbb-bracket-round-playoff" data-round="' . $round_nr . '" aria-label="' . esc_attr( $round_label ) . '">';
        $html .= '<h' . $sub_heading . ' class="bbb-round-header">' . esc_html( $round['name'] ) . ' <span class="bbb-round-best-of">Best of ' . $best_of . '</span></h' . $sub_heading . '>';
        $html .= '<div class="bbb-round-matches" role="list" aria-label="' . esc_attr( sprintf( '%s – %d Serien', $round['name'], count( $series_list ) ) ) . '">';
        foreach ( $series_list as $idx => $series ) {
            $html .= $this->render_series( $series, $highlight_club, $show_dates, $show_logos, $round['name'], $idx + 1, $best_of );
        }
        $html .= '</div></section>';
        return $html;
    }

    private function render_series( array $series, int $highlight_club, bool $show_dates, bool $show_logos, string $round_name, int $series_number, int $best_of ): string {
        $team_a = $series['team_a']; $team_b = $series['team_b'];
        $wins_a = $series['wins_a']; $wins_b = $series['wins_b'];
        $winner = $series['series_winner'];
        $is_bye = $series['is_bye'] ?? false;
        $games  = $series['games'] ?? [];

        $name_a = $this->shorten_team_name( $team_a['name'] );
        $name_b = $is_bye ? 'Freilos' : $this->shorten_team_name( $team_b['name'] );

        $classes = [ 'bbb-match', 'bbb-series' ];
        if ( $winner ) $classes[] = 'bbb-series-decided';
        if ( $is_bye ) $classes[] = 'bbb-series-bye';

        $sr_label = sprintf( '%s Serie %d: %s gegen %s, Serienstand %d:%d', $round_name, $series_number, $name_a, $name_b, $wins_a, $wins_b );
        if ( $winner === 'a' ) $sr_label .= sprintf( ', %s gewinnt die Serie', $name_a );
        elseif ( $winner === 'b' ) $sr_label .= sprintf( ', %s gewinnt die Serie', $name_b );

        $html = '<div class="' . implode( ' ', $classes ) . '" role="listitem" aria-label="' . esc_attr( $sr_label ) . '">';
        $html .= '<div class="bbb-series-header">';
        $html .= $this->render_series_team( $team_a, 'a', $winner, $highlight_club, $show_logos );
        $html .= '<div class="bbb-series-score">';
        $html .= '<span class="bbb-series-wins' . ( $winner === 'a' ? ' bbb-series-wins-leader' : '' ) . '">' . $wins_a . '</span>';
        $html .= '<span class="bbb-series-separator">:</span>';
        $html .= '<span class="bbb-series-wins' . ( $winner === 'b' ? ' bbb-series-wins-leader' : '' ) . '">' . $wins_b . '</span>';
        $html .= '</div>';
        $html .= $this->render_series_team( $team_b, 'b', $winner, $highlight_club, $show_logos );
        $html .= '</div>';

        if ( ! $is_bye && count( $games ) > 0 ) {
            $html .= '<details class="bbb-series-games"><summary class="bbb-series-toggle">'
                   . esc_html( sprintf( '%d von max. %d Spielen', count( $games ), $best_of ) )
                   . '</summary><div class="bbb-series-games-list" role="list">';
            foreach ( $games as $game_idx => $game ) {
                $event_url = $this->get_event_url( $game['match_id'] );
                $game_label = sprintf( 'Spiel %d', $game_idx + 1 );
                $has_result = ! empty( $game['result'] );
                $game_class = 'bbb-series-game' . ( ! $has_result ? ' bbb-series-game-upcoming' : '' );
                $html .= '<div class="' . $game_class . '" role="listitem">';
                $html .= '<span class="bbb-series-game-nr">' . esc_html( $game_label ) . '</span>';
                if ( $show_dates && $game['date'] ) {
                    $iso_date = $game['date'] . ( $game['time'] ? 'T' . $game['time'] : '' );
                    $html .= '<time class="bbb-series-game-date" datetime="' . esc_attr( $iso_date ) . '">' . esc_html( $this->format_date( $game['date'], $game['time'] ) ) . '</time>';
                }
                if ( $has_result ) {
                    $result_display = esc_html( $game['result'] );
                    if ( $event_url ) {
                        $html .= '<a class="bbb-series-game-result" href="' . esc_url( $event_url ) . '" aria-label="' . esc_attr( sprintf( '%s: %s – zum Spielbericht', $game_label, $game['result'] ) ) . '">' . $result_display . '</a>';
                    } else {
                        $html .= '<span class="bbb-series-game-result">' . $result_display . '</span>';
                    }
                    if ( $game['winner'] ) {
                        $game_winner_name = $game['winner'] === 'home' ? $this->shorten_team_name( $game['home']['name'] ) : $this->shorten_team_name( $game['guest']['name'] );
                        $html .= '<span class="bbb-series-game-winner" aria-label="Sieg ' . esc_attr( $game_winner_name ) . '">' . esc_html( mb_substr( $game_winner_name, 0, 3 ) ) . '</span>';
                    }
                } else {
                    $html .= '<span class="bbb-series-game-result bbb-series-game-pending">– : –</span>';
                }
                $html .= '</div>';
            }
            $html .= '</div></details>';
        }

        $html .= '</div>';
        return $html;
    }

    private function render_series_team( array $team, string $side, ?string $winner, int $highlight_club, bool $show_logos ): string {
        $is_winner = $winner === $side;
        $is_loser  = $winner !== null && ! $is_winner;
        $is_bye    = $team['is_bye'];
        $is_own    = (int) ( $team['club_id'] ?? 0 ) === $highlight_club && $highlight_club > 0;
        $short_name = $is_bye ? 'Freilos' : $this->shorten_team_name( $team['name'] );

        $classes = [ 'bbb-series-team' ];
        if ( $is_winner ) $classes[] = 'bbb-series-team-winner';
        if ( $is_loser )  $classes[] = 'bbb-series-team-loser';
        if ( $is_own )    $classes[] = 'bbb-team-own';
        if ( $side === 'b' ) $classes[] = 'bbb-series-team-right';

        $html = '<div class="' . implode( ' ', $classes ) . '">';
        if ( $show_logos && ! $is_bye && ( $team['permanent_id'] ?? null ) ) {
            $logo_url = $this->get_team_logo_url( (int) $team['permanent_id'] );
            if ( $logo_url ) {
                $html .= '<img class="bbb-team-logo" src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( sprintf( 'Logo %s', $short_name ) ) . '" width="20" height="20" loading="lazy">';
            }
        }
        $team_url = $is_bye ? null : $this->get_team_url( $team['permanent_id'] ?? null );
        $name_html = $is_bye ? '<em>Freilos</em>' : esc_html( $short_name );
        if ( $team_url ) {
            $html .= '<a class="bbb-team-name" href="' . esc_url( $team_url ) . '">' . $name_html . '</a>';
        } else {
            $html .= '<span class="bbb-team-name">' . $name_html . '</span>';
        }
        if ( $is_winner ) $html .= '<span class="bbb-sr-only"> (Serien-Gewinner)</span>';
        elseif ( $is_loser ) $html .= '<span class="bbb-sr-only"> (Ausgeschieden)</span>';
        $html .= '</div>';
        return $html;
    }

    // ═════════════════════════════════════════
    // HELPERS (★ mit Filter-Hooks)
    // ═════════════════════════════════════════

    /**
     * ★ FILTER: Event-URL (Sync-Plugin kann SP Event-Link liefern)
     */
    private function get_event_url( int $match_id ): ?string {
        if ( ! $match_id ) return null;
        $url = apply_filters( 'bbb_table_event_url', '', $match_id );
        return $url ?: null;
    }

    /**
     * ★ FILTER: Team-URL (Sync-Plugin kann SP Team-Link liefern)
     */
    private function get_team_url( ?int $permanent_id ): ?string {
        if ( ! $permanent_id ) return null;
        $url = apply_filters( 'bbb_table_team_url', '', $permanent_id );
        return $url ?: null;
    }

    /**
     * ★ FILTER: Logo-URL (Sync-Plugin kann SP Featured Image liefern)
     * Standalone-Fallback: BBB Media URL.
     */
    private function get_team_logo_url( int $permanent_id ): string {
        $url = apply_filters( 'bbb_table_team_logo_url', '', $permanent_id );
        if ( $url ) return $url;

        if ( get_option( 'bbb_tables_logo_proxy', false ) ) {
            $proxied = $this->api->get_team_logo_data_uri( $permanent_id );
            if ( $proxied ) return $proxied;
        }

        return "https://www.basketball-bund.net/media/team/{$permanent_id}/logo";
    }

    private function shorten_team_name( string $name ): string {
        return preg_replace( '/\s*\([^)]*\)\s*$/', '', $name );
    }

    private function format_date( string $date, ?string $time ): string {
        $ts = strtotime( $date );
        if ( ! $ts ) return $date;
        $formatted = date_i18n( 'j. M Y', $ts );
        if ( $time && $time !== '00:00' ) $formatted .= ', ' . $time . ' Uhr';
        return $formatted;
    }

    private function calculate_total_rounds( int $total_teams ): int {
        if ( $total_teams <= 1 ) return 1;
        return (int) ceil( log( $total_teams, 2 ) );
    }

    // ═════════════════════════════════════════
    // CACHE
    // ═════════════════════════════════════════

    public static function invalidate_cache( int $liga_id ): void {
        delete_transient( "bbb_bracket_{$liga_id}" );
    }

    public static function invalidate_all_caches(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fully hardcoded query, no user input
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_bbb_bracket_%'
             OR option_name LIKE '_transient_timeout_bbb_bracket_%'"
        );
    }

    // ═════════════════════════════════════════
    // STYLES (identisch, nutzt get_theme_colors mit Filter)
    // ═════════════════════════════════════════

    private function get_theme_colors(): array {
        $defaults = [
            'primary'    => get_option( 'bbb_tables_color_primary', '' ),
            'background' => get_option( 'bbb_tables_color_background', '' ),
            'link'       => get_option( 'bbb_tables_color_link', '' ),
            'text'       => get_option( 'bbb_tables_color_text', '' ),
            'heading'    => get_option( 'bbb_tables_color_heading', '' ),
        ];

        if ( empty( $defaults['primary'] ) && function_exists( 'gdlr_core_get_option' ) ) {
            $gp = gdlr_core_get_option( 'skin_color', '' );
            if ( $gp ) $defaults['primary'] = $gp;
            $gl = gdlr_core_get_option( 'link_color', '' );
            if ( $gl ) $defaults['link'] = $gl;
        }

        $defaults = array_merge( [
            'primary' => '#2b353e', 'background' => '#f4f4f4', 'link' => '#00a69c',
            'text' => '#222222', 'heading' => '#ffffff',
        ], array_filter( $defaults ) );

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

    private function hex_darken( string $hex, int $percent ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = max( 0, hexdec( substr( $hex, 0, 2 ) ) - round( 255 * $percent / 100 ) );
        $g = max( 0, hexdec( substr( $hex, 2, 2 ) ) - round( 255 * $percent / 100 ) );
        $b = max( 0, hexdec( substr( $hex, 4, 2 ) ) - round( 255 * $percent / 100 ) );
        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }

    private function get_bracket_css(): string {
        $c = $this->get_theme_colors();
        $primary_light  = $this->hex_lighten( $c['primary'], 90 );
        $winner_bg      = $this->hex_lighten( $c['link'], 85 );
        $winner_color   = $this->hex_darken( $c['link'], 20 );

        // CSS ist identisch zum Original – hier nur inline eingefügt
        return '
.bbb-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.bbb-bracket-wrapper{margin:2em 0}
.bbb-bracket-title{font-size:1.4em;margin-bottom:.8em;color:' . esc_attr( $c['primary'] ) . '}
.bbb-bracket{display:flex;gap:0;overflow-x:auto;padding:1em 0;-webkit-overflow-scrolling:touch}
.bbb-bracket-round{display:flex;flex-direction:column;min-width:220px;flex-shrink:0}
.bbb-round-header{text-align:center;font-weight:700;font-size:.85em;text-transform:uppercase;letter-spacing:.05em;color:' . esc_attr( $c['primary'] ) . ';padding:.5em 1em;margin-bottom:.5em;border-bottom:2px solid ' . esc_attr( $c['primary'] ) . '}
.bbb-round-matches{display:flex;flex-direction:column;justify-content:space-around;flex:1;padding:0 12px}
.bbb-match{background:#fff;border:1px solid #ddd;border-radius:6px;margin:6px 0;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);transition:box-shadow .2s ease;position:relative}
.bbb-match:hover{box-shadow:0 2px 8px rgba(0,0,0,.12)}
.bbb-match-upcoming{border-left:3px solid ' . esc_attr( $c['link'] ) . '}
.bbb-match-cancelled{opacity:.5}
.bbb-match-future{border:2px dashed ' . esc_attr( $c['primary'] ) . '44;background:' . esc_attr( $primary_light ) . '}
.bbb-match-date{font-size:.7em;color:#888;text-align:center;padding:3px 8px 0;white-space:nowrap}
.bbb-team{display:flex;align-items:center;padding:6px 8px;gap:6px;border-bottom:1px solid #f0f0f0;min-height:32px}
.bbb-team:last-child{border-bottom:none}
.bbb-team-winner{background:' . esc_attr( $winner_bg ) . ';font-weight:600}
.bbb-team-loser{color:#999}
.bbb-team-bye{color:#bbb;font-style:italic}
.bbb-team-own{border-left:3px solid ' . esc_attr( $c['primary'] ) . '}
.bbb-team-tbd{color:' . esc_attr( $c['text'] ) . '99;font-style:italic}
.bbb-team-logo{width:20px;height:20px;object-fit:contain;flex-shrink:0;border-radius:2px}
.bbb-team-name{flex:1;font-size:.82em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:inherit;text-decoration:none}
a.bbb-team-name:hover,a.bbb-team-name:focus{text-decoration:underline;color:' . esc_attr( $c['link'] ) . '}
.bbb-bracket a:focus-visible{outline:2px solid ' . esc_attr( $c['link'] ) . ';outline-offset:2px;border-radius:2px}
.bbb-team-score{font-weight:700;font-size:.85em;min-width:28px;text-align:right;flex-shrink:0;color:inherit;text-decoration:none}
a.bbb-team-score:hover,a.bbb-team-score:focus{color:' . esc_attr( $c['link'] ) . '}
.bbb-team-winner .bbb-team-score{color:' . esc_attr( $winner_color ) . '}
.bbb-bracket-round:not(:last-child) .bbb-match::after{content:"";position:absolute;right:-13px;top:50%;width:12px;height:1px;background:#ccc}
.bbb-bracket-updated{font-size:.75em;color:#999;text-align:right;margin-top:.5em}
.bbb-bracket-error{color:#c0392b;background:#fdecea;padding:.8em 1em;border-radius:4px;border-left:4px solid ' . esc_attr( $c['primary'] ) . '}
.bbb-round-best-of{display:inline-block;font-size:.75em;font-weight:400;text-transform:none;letter-spacing:0;background:' . esc_attr( $c['primary'] ) . '15;color:' . esc_attr( $c['primary'] ) . ';padding:.15em .5em;border-radius:3px;margin-left:.3em;vertical-align:middle}
.bbb-bracket-round-playoff{min-width:280px}
.bbb-series-header{display:flex;align-items:center;gap:4px;padding:8px}
.bbb-series-team{display:flex;align-items:center;gap:4px;flex:1;min-width:0}
.bbb-series-team-right{flex-direction:row-reverse;text-align:right}
.bbb-series-team-right .bbb-team-name{text-align:right}
.bbb-series-team-winner{font-weight:700}
.bbb-series-team-loser{opacity:.55}
.bbb-series-score{display:flex;align-items:center;gap:2px;flex-shrink:0;padding:0 6px}
.bbb-series-wins{font-size:1.3em;font-weight:700;min-width:20px;text-align:center;color:' . esc_attr( $c['text'] ) . '}
.bbb-series-wins-leader{color:' . esc_attr( $winner_color ) . '}
.bbb-series-separator{font-size:1.1em;color:#999}
.bbb-series-games{border-top:1px solid #eee}
.bbb-series-toggle{cursor:pointer;font-size:.72em;color:' . esc_attr( $c['link'] ) . ';padding:4px 8px;text-align:center;user-select:none;list-style:none}
.bbb-series-toggle::-webkit-details-marker{display:none}
.bbb-series-toggle::before{content:"▶ ";font-size:.7em}
details[open]>.bbb-series-toggle::before{content:"▼ "}
.bbb-series-toggle:hover,.bbb-series-toggle:focus{color:' . esc_attr( $c['primary'] ) . ';text-decoration:underline}
.bbb-series-game{display:flex;align-items:center;gap:6px;padding:3px 8px;font-size:.78em;border-bottom:1px solid #f5f5f5}
.bbb-series-game:last-child{border-bottom:none}
.bbb-series-game-nr{font-weight:600;color:' . esc_attr( $c['primary'] ) . ';min-width:48px;flex-shrink:0}
.bbb-series-game-date{color:#888;font-size:.9em;flex:1;white-space:nowrap}
.bbb-series-game-result{font-weight:700;min-width:40px;text-align:center;color:inherit;text-decoration:none}
a.bbb-series-game-result:hover,a.bbb-series-game-result:focus{color:' . esc_attr( $c['link'] ) . ';text-decoration:underline}
.bbb-series-game-pending{color:#bbb;font-weight:400}
.bbb-series-game-winner{font-size:.8em;font-weight:600;color:' . esc_attr( $winner_color ) . ';background:' . esc_attr( $winner_bg ) . ';padding:1px 4px;border-radius:2px;flex-shrink:0}
.bbb-series-game-upcoming{opacity:.7}
.bbb-series-decided{border-left:3px solid ' . esc_attr( $c['link'] ) . '}
.bbb-series-bye{opacity:.6}
@media(max-width:480px){
  .bbb-bracket{flex-direction:column}
  .bbb-bracket-round,.bbb-bracket-round-playoff{min-width:unset}
  .bbb-bracket-round:not(:last-child) .bbb-match::after{display:none}
}
';
    }
}
