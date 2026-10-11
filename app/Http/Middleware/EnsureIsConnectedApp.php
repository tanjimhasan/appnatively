<?php
/**
 * Require the site connection key issued to AppNatively Studio.
 *
 * @package Crafium\AppNatively\App\Http\Middleware
 */

namespace Crafium\AppNatively\App\Http\Middleware;

defined( 'ABSPATH' ) || exit;

use Crafium\AppNatively\App\Support\Settings;
use Crafium\AppNatively\WpMVC\Routing\Contracts\Middleware;
use WP_REST_Request;
use WP_Error;

/**
 * Class EnsureIsConnectedApp
 *
 * Guards endpoints that describe the site to its owner's app builder rather
 * than serving content to app users — the active integration list in
 * particular, which would otherwise let anyone enumerate installed plugins.
 *
 * The key is generated on the settings screen and pasted into Studio, so the
 * site owner can see it, rotate it, and revoke access without deactivating.
 */
class EnsureIsConnectedApp implements Middleware {
    /**
     * Handle an incoming request.
     *
     * @param WP_REST_Request $wp_rest_request The current request instance.
     * @param mixed           $next            The next middleware closure in the stack.
     * @return bool|WP_Error
     */
    public function handle( WP_REST_Request $wp_rest_request, $next ) {
        // Refuse here rather than deferring to the gate filter, so the key
        // requirement does not depend on that filter still being wired up.
        if ( ! Settings::is_api_enabled() ) {
            return Settings::api_disabled_error();
        }

        $authorization = $wp_rest_request->get_header( 'Authorization' );
        $key           = null;

        // An explicit Authorization header takes precedence, even if invalid.
        // Never silently downgrade a rejected bearer credential to the legacy key.
        if ( null !== $authorization ) {
            if ( preg_match( '/\A[ \t]*Bearer[ \t]+([^\s,]+)[ \t]*\z/i', $authorization, $matches ) ) {
                $key = $matches[1];
            }
        } else {
            $key = $wp_rest_request->get_header( 'X-AppNatively-Key' );
        }

        if ( ! Settings::verify_site_key( $key ) ) {
            return new WP_Error(
                'craf_appna_bad_site_key',
                __( 'A valid site connection key is required for this request.', 'appnatively' ),
                [ 'status' => 401 ]
            );
        }

        return $next( $wp_rest_request );
    }
}
