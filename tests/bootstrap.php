<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * These are pure unit tests: WordPress is NOT loaded. WP functions are
 * stubbed with Brain Monkey inside the individual test cases. We only need
 * to define the guard constants the plugin files check at include time and
 * then require the classes under test.
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Guard constant checked at the top of every plugin file.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Constants referenced by the classes under test.
if ( ! defined( 'BBB_API_BASE_URL' ) ) {
    define( 'BBB_API_BASE_URL', 'https://www.basketball-bund.net/rest' );
}
if ( ! defined( 'BBB_TABLES_DIR' ) ) {
    define( 'BBB_TABLES_DIR', dirname( __DIR__ ) . '/' );
}

// Time-related WP constants used in some methods.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

// A minimal WP_Error stand-in so type hints / is_wp_error() resolve in unit land.
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public array $errors = [];

        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( $code !== '' ) {
                $this->errors[ $code ][] = $message;
            }
        }

        public function get_error_message(): string {
            foreach ( $this->errors as $messages ) {
                return $messages[0] ?? '';
            }
            return '';
        }
    }
}

// Classes under test (pure-logic portions exercised via the test cases).
require_once dirname( __DIR__ ) . '/includes/class-bbb-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-bbb-live-table.php';
require_once dirname( __DIR__ ) . '/includes/class-bbb-tournament-bracket.php';
