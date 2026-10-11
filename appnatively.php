<?php

defined( 'ABSPATH' ) || exit;

use Crafium\AppNatively\WpMVC\App;
use Crafium\AppNatively\Database\Setup;

/**
 * Plugin Name:       AppNatively
 * Description:       Turn your WordPress site into a native iOS and Android mobile app. Seamlessly sync WooCommerce, FluentCart.
 * Version:           0.0.9
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Crafium
 * Author URI:        https://crafium.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       appnatively
 * Domain Path:       /languages
 */

if ( ! defined( 'CRAF_APPNA_VENDOR_LOADED' ) ) {
    define( 'CRAF_APPNA_VENDOR_LOADED', true );
    if ( file_exists( __DIR__ . '/vendor/vendor-src/autoload.php' ) ) {
        require_once __DIR__ . '/vendor/vendor-src/autoload.php';
    } elseif ( file_exists( __DIR__ . '/vendor-src/autoload.php' ) ) {
        require_once __DIR__ . '/vendor-src/autoload.php';
    }
}

require_once __DIR__ . '/app/Helpers/helper.php';

final class AppNatively
{
    public static AppNatively $instance;

    public static function instance(): AppNatively {
        if ( empty( self::$instance ) ) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    public function load() {
        // Run Activation Tasks
        register_activation_hook(
            __FILE__, function() {
                ( new Setup )->execute();
            } 
        );

        $application = App::instance();

        $application->boot( __FILE__, __DIR__ );

        /**
         * Fires once activated plugins have loaded.
         *
         */
        add_action(
            'plugins_loaded', function () use ( $application ): void {

                do_action( 'craf_appna_before_load' );

                $application->load();

                do_action( 'craf_appna_after_load' );
            }
        );
    }
}

AppNatively::instance()->load();
