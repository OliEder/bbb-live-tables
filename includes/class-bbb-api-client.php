<?php
/**
 * BBB API Client (Standalone – nur Tabellen + Brackets)
 *
 * Wrapper für die Basketball-Bund.net REST API.
 * Enthält nur die Endpoints die für Live-Tabellen und Turnier-Brackets nötig sind.
 *
 * Das bbb-sportspress-sync Plugin liefert eine erweiterte Version mit Team-Discovery,
 * Match-Sync, Boxscore etc. Wenn das Sync-Plugin aktiv ist, wird dessen Client genutzt
 * (die Hauptdatei prüft class_exists('BBB_Api_Client')).
 *
 * API-Basis: https://www.basketball-bund.net/rest
 * Auth: Keine (öffentliche API)
 * Rate Limit: ~1 req/sec empfohlen
 */

defined( 'ABSPATH' ) || exit;

class BBB_Api_Client {

    private string $base_url;
    private float $rate_limit_delay = 1.0;

    public function __construct() {
        $this->base_url = BBB_API_BASE_URL;
    }

    // ─────────────────────────────────────────
    // LEAGUE DATA
    // ─────────────────────────────────────────

    /**
     * Liga-Tabelle laden.
     *
     * @param int $liga_id BBB Liga-ID
     * @return array|WP_Error { liga_data, entries }
     */
    public function get_tabelle( int $liga_id ): array|WP_Error {
        $response = $this->get( "/competition/table/id/{$liga_id}" );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return [
            'liga_data' => $response['data']['ligaData'] ?? [],
            'entries'   => $response['data']['tabelle']['entries'] ?? [],
        ];
    }

    /**
     * Liga-Spieltag laden (für KO/Pokal-Brackets).
     *
     * @param int $liga_id    BBB Liga-ID
     * @param int $matchday   Spieltag-Nummer (1-basiert)
     * @return array|WP_Error
     */
    public function get_liga_matchday( int $liga_id, int $matchday = 1 ): array|WP_Error {
        $response = $this->get( "/competition/id/{$liga_id}/matchday/{$matchday}" );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = $response['data'] ?? [];

        return [
            'liga_data'  => $data['ligaData'] ?? [],
            'spieltage'  => $data['spieltage'] ?? [],
            'matches'    => $data['matches'] ?? [],
            'prev'       => $data['prevSpieltag'] ?? null,
            'next'       => $data['nextSpieltag'] ?? null,
        ];
    }

    /**
     * Alle Spieltage einer KO-Liga laden (iteriert über Matchdays).
     *
     * @param int $liga_id BBB Liga-ID
     * @return array|WP_Error { liga_data, rounds }
     */
    public function get_tournament_rounds( int $liga_id ): array|WP_Error {
        $first = $this->get_liga_matchday( $liga_id, 1 );

        if ( is_wp_error( $first ) ) {
            return $first;
        }

        $spieltage = $first['spieltage'] ?? [];
        $liga_data = $first['liga_data'] ?? [];

        $rounds = [
            1 => [
                'name'    => $spieltage[0]['bezeichnung'] ?? '1. Runde',
                'matches' => $first['matches'] ?? [],
            ],
        ];

        foreach ( $spieltage as $st ) {
            $nr = (int) ( $st['spieltag'] ?? 0 );
            if ( $nr <= 1 || $nr === 0 ) continue;

            $this->throttle();
            $round = $this->get_liga_matchday( $liga_id, $nr );

            if ( is_wp_error( $round ) ) continue;

            $rounds[ $nr ] = [
                'name'    => $st['bezeichnung'] ?? "{$nr}. Runde",
                'matches' => $round['matches'] ?? [],
            ];
        }

        ksort( $rounds );

        return [
            'liga_data' => $liga_data,
            'rounds'    => $rounds,
        ];
    }

    /**
     * Liga-Spielplan laden (optional, für Tabellen-Ergänzung).
     *
     * @param int $liga_id BBB Liga-ID
     * @return array|WP_Error { liga_data, matches }
     */
    public function get_liga_spielplan( int $liga_id ): array|WP_Error {
        $response = $this->get( "/competition/spielplan/id/{$liga_id}" );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return [
            'liga_data' => $response['data']['ligaData'] ?? [],
            'matches'   => $response['data']['matches'] ?? [],
        ];
    }

    // ─────────────────────────────────────────
    // CLUB DISCOVERY (Teams + Ligen)
    // ─────────────────────────────────────────

    /**
     * Rohe actualmatches-Antwort laden (shared Cache für Teams + Ligen).
     *
     * Beide Discovery-Methoden nutzen denselben API-Endpoint.
     * Raw Response wird als Transient gecached (24h), damit nur 1 API-Call.
     *
     * @param int $club_id    BBB Vereins-ID (z.B. 4468)
     * @param int $range_days Zeitraum in Tagen (default: 150)
     * @return array          Matches Array oder leeres Array bei Fehler
     */
    private function get_club_matches_raw( int $club_id, int $range_days = 150 ): array {
        $transient_key = "bbb_club_raw_{$club_id}";
        $cached = get_transient( $transient_key );
        if ( $cached !== false ) return $cached;

        $response = $this->get(
            "/club/id/{$club_id}/actualmatches?justHome=false&rangeDays={$range_days}"
        );

        if ( is_wp_error( $response ) ) {
            $this->log( "Club-Discovery fehlgeschlagen für Club {$club_id}: " . $response->get_error_message(), 'error' );
            return [];
        }

        $matches = $response['data']['matches'] ?? [];

        // 24h cachen – ändert sich nur bei Saison-Wechsel
        set_transient( $transient_key, $matches, DAY_IN_SECONDS );

        return $matches;
    }

    /**
     * Eigene Team-PIDs aus den aktuellen Club-Matches extrahieren.
     *
     * @param int $club_id    BBB Vereins-ID (z.B. 4468)
     * @param int $range_days Zeitraum in Tagen (default: 150)
     * @return int[]          Array von Team Permanent IDs
     */
    public function get_club_team_ids( int $club_id, int $range_days = 150 ): array {
        $transient_key = "bbb_club_teams_{$club_id}";
        $cached = get_transient( $transient_key );
        if ( $cached !== false ) return $cached;

        $matches  = $this->get_club_matches_raw( $club_id, $range_days );
        $team_ids = [];

        foreach ( $matches as $match ) {
            foreach ( [ 'homeTeam', 'guestTeam' ] as $side ) {
                $team = $match[ $side ] ?? [];
                if ( (int) ( $team['clubId'] ?? 0 ) !== $club_id ) continue;

                $pid = (int) ( $team['teamPermanentId'] ?? 0 );
                if ( $pid && ! in_array( $pid, $team_ids, true ) ) {
                    $team_ids[] = $pid;
                }
            }
        }

        $this->log( sprintf(
            'Club-Team-Discovery: Club %d → %d Teams (%s)',
            $club_id, count( $team_ids ), implode( ', ', $team_ids )
        ));

        set_transient( $transient_key, $team_ids, DAY_IN_SECONDS );
        return $team_ids;
    }

    /**
     * Detaillierte Team-Infos aus den aktuellen Club-Matches extrahieren.
     *
     * @param int $club_id    BBB Vereins-ID
     * @param int $range_days Zeitraum in Tagen (default: 150)
     * @return array          [ permanent_id => [ 'teamname', 'akName', 'geschlecht', 'ligen' ] ]
     */
    public function get_club_teams_detailed( int $club_id, int $range_days = 150 ): array {
        $matches = $this->get_club_matches_raw( $club_id, $range_days );
        $teams   = [];

        foreach ( $matches as $match ) {
            $liga_data = $match['ligaData'] ?? [];
            $liga_name = $liga_data['liganame'] ?? '';

            // akName + geschlecht sind in ligaData, nicht im Team-Objekt
            $ak_name    = $liga_data['akName'] ?? '';
            $geschlecht = $liga_data['geschlecht'] ?? '';

            foreach ( [ 'homeTeam', 'guestTeam' ] as $side ) {
                $team = $match[ $side ] ?? [];
                if ( (int) ( $team['clubId'] ?? 0 ) !== $club_id ) continue;

                $pid = (int) ( $team['teamPermanentId'] ?? 0 );
                if ( ! $pid ) continue;

                if ( ! isset( $teams[ $pid ] ) ) {
                    $teams[ $pid ] = [
                        'teamname'   => $team['teamname'] ?? "Team {$pid}",
                        'akName'     => $ak_name,
                        'geschlecht' => $geschlecht,
                        'ligen'      => [],
                    ];
                }

                // Nachträglich ergänzen falls erstes Match keine AK-Info hatte
                if ( empty( $teams[ $pid ]['akName'] ) && $ak_name ) {
                    $teams[ $pid ]['akName'] = $ak_name;
                }
                if ( empty( $teams[ $pid ]['geschlecht'] ) && $geschlecht ) {
                    $teams[ $pid ]['geschlecht'] = $geschlecht;
                }

                if ( $liga_name && ! in_array( $liga_name, $teams[ $pid ]['ligen'], true ) ) {
                    $teams[ $pid ]['ligen'][] = $liga_name;
                }
            }
        }

        return $teams;
    }

    /**
     * Alle Ligen des Vereins aus actualmatches extrahieren.
     *
     * @param int $club_id    BBB Vereins-ID
     * @param int $range_days Zeitraum in Tagen (default: 150)
     * @return array          Array von [ liga_id, label, slug, type ]
     */
    public function get_club_leagues( int $club_id, int $range_days = 150 ): array {
        $transient_key = "bbb_club_leagues_{$club_id}";
        $cached = get_transient( $transient_key );
        if ( $cached !== false ) return $cached;

        $matches = $this->get_club_matches_raw( $club_id, $range_days );
        $leagues = [];
        $seen    = [];

        foreach ( $matches as $match ) {
            $liga_data = $match['ligaData'] ?? [];
            $liga_id   = (int) ( $liga_data['ligaId'] ?? 0 );
            if ( ! $liga_id || isset( $seen[ $liga_id ] ) ) continue;
            $seen[ $liga_id ] = true;

            $name = $liga_data['liganame'] ?? "Liga {$liga_id}";
            $slug = sanitize_title( $name );

            // Typ-Erkennung: Liga hat Tabelle?
            $this->throttle();
            $has_table = $this->check_liga_has_table( $liga_id );

            $leagues[] = [
                'liga_id' => $liga_id,
                'label'   => $name,
                'slug'    => $slug,
                'type'    => $has_table ? 'league' : 'tournament',
            ];
        }

        usort( $leagues, fn( $a, $b ) => strcasecmp( $a['label'], $b['label'] ) );

        $this->log( sprintf(
            'Club-Liga-Discovery: Club %d → %d Ligen',
            $club_id, count( $leagues )
        ));

        set_transient( $transient_key, $leagues, DAY_IN_SECONDS );
        return $leagues;
    }

    /**
     * Prüft ob eine Liga eine Tabelle hat (= Liga vs. Turnier/Pokal).
     */
    private function check_liga_has_table( int $liga_id ): bool {
        $result = $this->get_tabelle( $liga_id );
        if ( is_wp_error( $result ) ) return false;
        return ! empty( $result['entries'] );
    }

    // ─────────────────────────────────────────
    // HTTP METHODS
    // ─────────────────────────────────────────

    private function get( string $endpoint ): array|WP_Error {
        $url = $this->base_url . $endpoint;
        $this->log( "GET {$url}" );

        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => [ 'Accept' => 'application/json' ],
        ]);

        return $this->handle_response( $response );
    }

    private function handle_response( $response ): array|WP_Error {
        if ( is_wp_error( $response ) ) {
            $this->log( 'HTTP Error: ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            $this->log( "HTTP {$code}: {$body}", 'error' );
            return new WP_Error( 'bbb_api_http_error', "HTTP {$code}", [ 'status' => $code ] );
        }

        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $this->log( 'JSON Parse Error: ' . json_last_error_msg(), 'error' );
            return new WP_Error( 'bbb_api_json_error', json_last_error_msg() );
        }

        if ( ( $data['status'] ?? '1' ) !== '0' ) {
            $message = $data['message'] ?? 'Unknown API error';
            $this->log( "API Error: {$message}", 'error' );
            return new WP_Error( 'bbb_api_error', $message );
        }

        return $data;
    }

    /**
     * Rate limit throttle.
     */
    public function throttle(): void {
        usleep( (int) ( $this->rate_limit_delay * 1_000_000 ) );
    }

    private function log( string $message, string $level = 'info' ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[BBB-Tables][{$level}] {$message}" );
        }
    }
}
