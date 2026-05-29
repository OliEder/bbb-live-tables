<?php

declare( strict_types=1 );

namespace BBB\Tests\Unit;

use BBB_Live_Table;

/**
 * Pure-logic tests for BBB_Live_Table.
 *
 * @covers \BBB_Live_Table
 */
final class LiveTableLogicTest extends TestCase {

    private BBB_Live_Table $table;

    protected function setUp(): void {
        parent::setUp();
        $this->table = $this->makeWithoutConstructor( BBB_Live_Table::class );
    }

    // ── shorten_team_name ───────────────────────────────

    public function test_shorten_team_name_strips_trailing_parentheses(): void {
        $this->assertSame( 'TV Musterstadt', $this->invoke( $this->table, 'shorten_team_name', 'TV Musterstadt (Herren 1)' ) );
    }

    public function test_shorten_team_name_keeps_inner_parentheses(): void {
        // Nur ein abschließendes Klammer-Suffix wird entfernt.
        $this->assertSame( 'A (B) C', $this->invoke( $this->table, 'shorten_team_name', 'A (B) C' ) );
    }

    public function test_shorten_team_name_noop_without_parentheses(): void {
        $this->assertSame( 'Bamberg Baskets', $this->invoke( $this->table, 'shorten_team_name', 'Bamberg Baskets' ) );
    }

    // ── hex_lighten ─────────────────────────────────────

    public function test_hex_lighten_zero_percent_keeps_color(): void {
        $this->assertSame( '#2b353e', strtolower( $this->invoke( $this->table, 'hex_lighten', '#2b353e', 0 ) ) );
    }

    public function test_hex_lighten_hundred_percent_is_white(): void {
        $this->assertSame( '#ffffff', $this->invoke( $this->table, 'hex_lighten', '#2b353e', 100 ) );
    }

    public function test_hex_lighten_expands_shorthand(): void {
        // #abc → #aabbcc, 0% lightening keeps it.
        $this->assertSame( '#aabbcc', $this->invoke( $this->table, 'hex_lighten', '#abc', 0 ) );
    }

    // ── compute_games_behind (private, by-ref) ──────────

    public function test_games_behind_leader_is_dash(): void {
        $entries = [
            [ 's' => 10, 'n' => 0 ],
            [ 's' => 8,  'n' => 2 ],
        ];
        $this->invokeByRef( $this->table, 'compute_games_behind', $entries );
        $this->assertSame( '–', $entries[0]['gb'] );
    }

    public function test_games_behind_integer_value(): void {
        $entries = [
            [ 's' => 10, 'n' => 0 ],
            [ 's' => 8,  'n' => 2 ],
        ];
        $this->invokeByRef( $this->table, 'compute_games_behind', $entries );
        // GB = ((10-8) + (2-0)) / 2 = 2
        $this->assertSame( '2', $entries[1]['gb'] );
    }

    public function test_games_behind_half_game(): void {
        $entries = [
            [ 's' => 10, 'n' => 1 ],
            [ 's' => 9,  'n' => 1 ],
        ];
        $this->invokeByRef( $this->table, 'compute_games_behind', $entries );
        // GB = ((10-9) + (1-1)) / 2 = 0.5
        $this->assertSame( '0.5', $entries[1]['gb'] );
    }

    public function test_games_behind_empty_is_safe(): void {
        $entries = [];
        $this->invokeByRef( $this->table, 'compute_games_behind', $entries );
        $this->assertSame( [], $entries );
    }

    // ── preprocess_entries ──────────────────────────────

    public function test_preprocess_computes_ppg_and_ratio(): void {
        $entries = [
            [
                'rang'        => 1,
                'team'        => [ 'teamname' => 'Team A', 'teamPermanentId' => 42, 'clubId' => 7 ],
                'anzSpiele'   => 4,
                'koerbe'      => 320,
                'gegenKoerbe' => 280,
                's'           => 3,
                'n'           => 1,
            ],
        ];
        $result = $this->invoke( $this->table, 'preprocess_entries', $entries );

        $this->assertSame( 1, $result[0]['platz'] );
        $this->assertSame( 'Team A', $result[0]['teamname'] );
        $this->assertSame( 42, $result[0]['teamPermanentId'] );
        $this->assertSame( '80,0', $result[0]['ppg'] );   // 320/4
        $this->assertSame( '70,0', $result[0]['oppg'] );  // 280/4
        $this->assertSame( '320:280', $result[0]['korbRatio'] );
    }

    public function test_preprocess_handles_zero_games_without_division_error(): void {
        $entries = [
            [
                'rang'      => 1,
                'team'      => [ 'teamname' => 'Team A' ],
                'anzSpiele' => 0,
                'koerbe'    => 0,
            ],
        ];
        $result = $this->invoke( $this->table, 'preprocess_entries', $entries );
        $this->assertSame( '–', $result[0]['ppg'] );
        $this->assertSame( '–', $result[0]['oppg'] );
    }

    public function test_preprocess_falls_back_for_missing_rank(): void {
        $entries = [
            [ 'team' => [ 'teamname' => 'A' ] ],
            [ 'team' => [ 'teamname' => 'B' ] ],
        ];
        $result = $this->invoke( $this->table, 'preprocess_entries', $entries );
        $this->assertSame( 1, $result[0]['platz'] );
        $this->assertSame( 2, $result[1]['platz'] );
    }

    public function test_preprocess_handles_string_team(): void {
        $entries = [ [ 'rang' => 1, 'team' => 'Plain Name' ] ];
        $result = $this->invoke( $this->table, 'preprocess_entries', $entries );
        $this->assertSame( 'Plain Name', $result[0]['teamname'] );
        $this->assertSame( 0, $result[0]['teamPermanentId'] );
    }
}
