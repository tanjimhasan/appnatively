<?php

namespace Crafium\AppNatively\App\Providers;

defined( "ABSPATH" ) || exit;

use Crafium\AppNatively\App\Support\Settings;
use Crafium\AppNatively\WpMVC\Contracts\Provider;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class ApiGateServiceProvider
 *
 * Gives the site owner a single switch over everything this plugin exposes.
 * Without it, the only way to stop the bridge answering requests would be to
 * deactivate the plugin and lose its configuration.
 */
class ApiGateServiceProvider extends Provider {
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register() {}

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot() {
        add_filter( 'craf_appna_rest_permission_filter', [$this, 'gate_requests'], 5, 3 );
        add_filter( 'rest_allowed_cors_headers', [$this, 'allow_connection_key_header'] );
        add_filter( 'rest_post_dispatch', [$this, 'protect_private_responses'], 10, 3 );
    }

    /**
     * Let the connection key header survive a cross-origin preflight.
     *
     * Studio runs on a different origin to the site it reads. Both bearer and
     * legacy authentication need an OPTIONS preflight. WordPress allows
     * Authorization by default; also retain the custom header for older clients.
     *
     * @param string[] $allow_headers Headers WordPress will accept cross-origin.
     * @return string[]
     */
    public function allow_connection_key_header( $allow_headers ) {
        $allow_headers   = is_array( $allow_headers ) ? $allow_headers : [];
        $allow_headers[] = 'Authorization';
        $allow_headers[] = 'X-AppNatively-Key';

        // The cart endpoints read these two from the app, which is not a browser
        // and so never preflights — but the Studio preview renders in one.
        $allow_headers[] = 'X-Cart-Id';
        $allow_headers[] = 'X-WC-Session';

        return array_values( array_unique( $allow_headers ) );
    }

    /**
     * Keep credentials, sessions and private discovery out of shared caches.
     *
     * This also covers permission failures and OPTIONS responses. Existing
     * upstream cache entries must be purged: they can bypass PHP entirely.
     *
     * @param WP_REST_Response $response The normalized REST response.
     * @param mixed            $server   The REST server.
     * @param WP_REST_Request  $request  The current REST request.
     * @return WP_REST_Response
     */
    public function protect_private_responses( $response, $server, WP_REST_Request $request ) {
        $route = $request->get_route();

        if ( ! preg_match( '#^/craf_appna/v1(?:/|$)#i', $route ) ) {
            return $response;
        }

        $path    = substr( $route, strlen( '/craf_appna/v1' ) );
        $private = (bool) preg_match(
            '#^/(?:connection|plugins|post-types/(?:available|selection)|ecommerce/products/filter-sources|directory/listings/filter-sources)/?$#i',
            $path
        ) || (bool) preg_match( '#^/(?:auth|ecommerce/(?:cart|orders|wishlist)|directory/wishlist)(?:/|$)#i', $path );

        foreach ( ['Authorization', 'X-AppNatively-Key', 'X-Cart-Id', 'X-WC-Session'] as $header ) {
            if ( null !== $request->get_header( $header ) ) {
                $private = true;
            }
        }

        if ( ! $private && ! is_user_logged_in() && in_array( $request->get_method(), ['GET', 'HEAD'], true ) && $response->get_status() < 400 ) {
            return $response;
        }

        $response->header( 'Cache-Control', 'private, no-store, max-age=0' );
        $response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
        do_action( 'litespeed_control_set_nocache', 'AppNatively private REST response' );

        return $response;
    }

    /**
     * Refuse every route while the bridge is switched off.
     *
     * This catches the routes that carry no middleware at all. Every middleware
     * also refuses on its own, so a route that requires a token or a key does
     * not depend on this filter still being wired up to enforce that.
     *
     * @param mixed  $permission The permission resolved so far.
     * @param mixed  $middleware The middleware applied to the route.
     * @param string $full_route The route being requested.
     * @return mixed
     */
    public function gate_requests( $permission, $middleware, $full_route ) {
        if ( Settings::is_api_enabled() ) {
            return $permission;
        }

        return Settings::api_disabled_error();
    }
}
