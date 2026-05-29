<?php

declare( strict_types=1 );

namespace BBB\Tests\Unit;

use BBB_Tournament_Bracket;

/**
 * Pure-logic tests for BBB_Tournament_Bracket.
 *
 * @covers \BBB_Tournament_Bracket
 */
final class TournamentBracketLogicTest extends TestCase {

    private BBB_Tournament_Bracket $bracket;

    protected function setUp(): void {
        parent::setUp();
        $this->bracket = $this->makeWithoutConstructor( BBB_Tournament_Bracket::class );
    }

    // ── calculate_total_rounds ──────────────────────────

    public function test_total_rounds_for_powers_of_two(): void {
        $this->assertSame( 1, $this->invoke( $this->bracket, 'calculate_total_rounds', 2 ) );
        $this->assertSame( 2, $this->invoke( $this->bracket, 'calculate_total_rounds', 4 ) );
        $this->assertSame( 3, $this->invoke( $this->bracket, 'calculate_total_rounds', 8 ) );
        $this->assertSame( 4, $this->invoke( $this->bracket, 'calculate_total_rounds', 16 ) );
    }

    public function test_total_rounds_rounds_up_for_non_powers(): void {
        // 6 Teams → ceil(log2(6)) = 3
        $this->assertSame( 3, $this->invoke( $this->bracket, 'calculate_total_rounds', 6 ) );
    }

    public function test_total_rounds_minimum_is_one(): void {
        $this->assertSame( 1, $this->invoke( $this->bracket, 'calculate_total_rounds', 1 ) );
        $this->assertSame( 1, $this->invoke( $this->bracket, 'calculate_total_rounds', 0 ) );
    }

    // ── get_round_name ──────────────────────────────────

    public function test_round_names_by_match_count(): void {
        $this->assertSame( 'Finale', $this->invoke( $this->bracket, 'get_round_name', 1, '' ) );
        $this->assertSame( 'Halbfinale', $this->invoke( $this->bracket, 'get_round_name', 2, '' ) );
        $this->assertSame( 'Viertelfinale', $this->invoke( $this->bracket, 'get_round_name', 4, '' ) );
        $this->assertSame( 'Achtelfinale', $this->invoke( $this->bracket, 'get_round_name', 8, '' ) );
    }

    public function test_round_name_falls_back_to_api_name(): void {
        $this->assertSame( 'Gruppenphase', $this->invoke( $this->bracket, 'get_round_name', 5, 'Gruppenphase' ) );
    }

    // ── is_bye_team ─────────────────────────────────────

    public function test_is_bye_when_no_permanent_id(): void {
        $this->assertTrue( $this->invoke( $this->bracket, 'is_bye_team', [ 'teamname' => 'X' ] ) );
        $this->assertTrue( $this->invoke( $this->bracket, 'is_bye_team', [ 'teamPermanentId' => 0, 'teamname' => 'X' ] ) );
    }

    public function test_is_bye_when_named_freilos(): void {
        $this->assertTrue( $this->invoke( $this->bracket, 'is_bye_team', [ 'teamPermanentId' => 99, 'teamname' => 'Freilos' ] ) );
    }

    public function test_is_not_bye_for_real_team(): void {
        $this->assertFalse( $this->invoke( $this->bracket, 'is_bye_team', [ 'teamPermanentId' => 99, 'teamname' => 'TV Real' ] ) );
    }

    // ── normalize_match ─────────────────────────────────

    public function test_normalize_match_detects_home_winner(): void {
        $match = [
            'matchId'   => 123,
            'result'    => '80:70',
            'homeTeam'  => [ 'teamname' => 'Home', 'teamPermanentId' => 1, 'clubId' => 10 ],
            'guestTeam' => [ 'teamname' => 'Guest', 'teamPermanentId' => 2, 'clubId' => 20 ],
        ];
        $result = $this->invoke( $this->bracket, 'normalize_match', $match );
        $this->assertSame( 'home', $result['winner'] );
        $this->assertSame( 123, $result['match_id'] );
    }

    public function test_normalize_match_detects_guest_winner(): void {
        $match = [
            'result'    => '70:80',
            'homeTeam'  => [ 'teamname' => 'Home', 'teamPermanentId' => 1 ],
            'guestTeam' => [ 'teamname' => 'Guest', 'teamPermanentId' => 2 ],
        ];
        $result = $this->invoke( $this->bracket, 'normalize_match', $match );
        $this->assertSame( 'guest', $result['winner'] );
    }

    public function test_normalize_match_tie_has_no_winner(): void {
        $match = [
            'result'    => '70:70',
            'homeTeam'  => [ 'teamname' => 'Home', 'teamPermanentId' => 1 ],
            'guestTeam' => [ 'teamname' => 'Guest', 'teamPermanentId' => 2 ],
        ];
        $result = $this->invoke( $this->bracket, 'normalize_match', $match );
        $this->assertNull( $result['winner'] );
    }

    public function test_normalize_match_bye_awards_other_side(): void {
        $match = [
            'homeTeam'  => [ 'teamname' => 'Freilos', 'teamPermanentId' => 0 ],
            'guestTeam' => [ 'teamname' => 'Real', 'teamPermanentId' => 2 ],
        ];
        $result = $this->invoke( $this->bracket, 'normalize_match', $match );
        $this->assertSame( 'guest', $result['winner'] );
    }

    // ── group_matches_into_series (Best-of) ─────────────

    public function test_series_groups_two_legs_and_finds_winner(): void {
        // Best of 3: team 1 vs team 2, two games, team 1 wins both → series winner.
        $matches = [
            $this->makeMatch( 1, 2, '80:70' ), // home(1) wins
            $this->makeMatch( 2, 1, '60:90' ), // away(1) wins
        ];
        $series = $this->invoke( $this->bracket, 'group_matches_into_series', $matches, 3 );

        $this->assertCount( 1, $series );
        $this->assertSame( 2, $series[0]['wins_needed'] ); // ceil(3/2)
        // team_a is the lower permanent id (1)
        $this->assertSame( 2, $series[0]['wins_a'] );
        $this->assertSame( 0, $series[0]['wins_b'] );
        $this->assertSame( 'a', $series[0]['series_winner'] );
    }

    public function test_series_not_decided_when_split(): void {
        $matches = [
            $this->makeMatch( 1, 2, '80:70' ), // team 1 wins
            $this->makeMatch( 1, 2, '70:80' ), // team 2 wins
        ];
        $series = $this->invoke( $this->bracket, 'group_matches_into_series', $matches, 5 );
        $this->assertSame( 1, $series[0]['wins_a'] );
        $this->assertSame( 1, $series[0]['wins_b'] );
        $this->assertNull( $series[0]['series_winner'] );
    }

    public function test_series_handles_bye(): void {
        $matches = [
            [
                'match_id' => 1,
                'home'     => [ 'name' => 'Real', 'permanent_id' => 5, 'club_id' => 1, 'is_bye' => false ],
                'guest'    => [ 'name' => 'Freilos', 'permanent_id' => 0, 'club_id' => 0, 'is_bye' => true ],
                'result'   => null,
                'winner'   => 'home',
                'date'     => null,
                'time'     => null,
            ],
        ];
        $series = $this->invoke( $this->bracket, 'group_matches_into_series', $matches, 5 );
        $this->assertCount( 1, $series );
        $this->assertTrue( $series[0]['is_bye'] );
        $this->assertSame( 'a', $series[0]['series_winner'] );
    }

    /**
     * Build a normalized-match array as produced by normalize_match(),
     * with a result string and derived winner.
     */
    private function makeMatch( int $homePid, int $guestPid, string $result ): array {
        [ $h, $g ] = array_map( 'intval', explode( ':', $result ) );
        $winner    = $h > $g ? 'home' : ( $g > $h ? 'guest' : null );
        return [
            'match_id' => $homePid * 100 + $guestPid,
            'home'     => [ 'name' => "T{$homePid}", 'permanent_id' => $homePid, 'club_id' => $homePid, 'is_bye' => false ],
            'guest'    => [ 'name' => "T{$guestPid}", 'permanent_id' => $guestPid, 'club_id' => $guestPid, 'is_bye' => false ],
            'result'   => $result,
            'winner'   => $winner,
            'date'     => null,
            'time'     => null,
        ];
    }
}
