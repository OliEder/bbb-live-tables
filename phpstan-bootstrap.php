<?php
/**
 * PHPStan bootstrap: define the plugin constants that classes reference at
 * include time, so static analysis can resolve them.
 */

declare( strict_types=1 );

defined( 'BBB_TABLES_VERSION' ) || define( 'BBB_TABLES_VERSION', '0.0.0' );
defined( 'BBB_TABLES_DIR' )     || define( 'BBB_TABLES_DIR', __DIR__ . '/' );
defined( 'BBB_TABLES_URL' )     || define( 'BBB_TABLES_URL', '' );
defined( 'BBB_TABLES_BASENAME' ) || define( 'BBB_TABLES_BASENAME', 'bbb-live-tables/bbb-live-tables.php' );
defined( 'BBB_API_BASE_URL' )   || define( 'BBB_API_BASE_URL', 'https://www.basketball-bund.net/rest' );
