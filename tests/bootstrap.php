<?php
/**
 * PHPUnit bootstrap.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'STORE_AUDIT_VERSION', '0.1.0' );
define( 'STORE_AUDIT_FILE', ABSPATH . 'store-audit-for-woocommerce.php' );
define( 'STORE_AUDIT_PATH', ABSPATH );
define( 'STORE_AUDIT_URL', 'https://example.test/wp-content/plugins/store-audit-for-woocommerce/' );

require_once ABSPATH . 'vendor/autoload.php';
require_once ABSPATH . 'includes/autoload.php';
