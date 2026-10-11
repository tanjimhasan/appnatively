<?php

namespace Crafium\AppNatively\Tests\Integrations;

use Crafium\AppNatively\App\Support\Settings;

class ApiCacheProtectionTest extends \WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( 0 );
        Settings::set_api_enabled( true );
        rest_get_server();
        rest_api_default_filters();
    }

    public function tearDown(): void {
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    private function protect( \WP_REST_Request $request, int $status = 200 ): \WP_REST_Response {
        return apply_filters( 'rest_post_dispatch', new \WP_REST_Response( [], $status ), rest_get_server(), $request );
    }

    public function private_routes(): array {
        return [
            ['/plugins'], ['/post-types/available'], ['/post-types/selection'],
            ['/ecommerce/products/filter-sources'], ['/directory/listings/filter-sources'],
            ['/auth/login'], ['/auth/register'], ['/auth/forgot-password'], ['/auth/me'],
            ['/ecommerce/cart'], ['/ecommerce/cart/add'], ['/ecommerce/orders'], ['/ecommerce/orders/1'],
            ['/ecommerce/wishlist'], ['/directory/wishlist'], ['/connection'],
        ];
    }

    /** @dataProvider private_routes */
    public function test_private_routes_are_not_cacheable_even_without_credentials( string $path ): void {
        foreach ( [200, 401, 403, 503] as $status ) {
            $before   = did_action( 'litespeed_control_set_nocache' );
            $response = $this->protect( new \WP_REST_Request( 'GET', '/craf_appna/v1' . $path ), $status );
            $this->assertSame( 'private, no-store, max-age=0', $response->get_headers()['Cache-Control'] );
            $this->assertSame( $before + 1, did_action( 'litespeed_control_set_nocache' ) );
        }
    }

    public function test_credentials_and_sessions_disable_public_catalog_caching(): void {
        foreach ( ['Authorization', 'X-AppNatively-Key', 'X-Cart-Id', 'X-WC-Session'] as $header ) {
            $request = new \WP_REST_Request( 'GET', '/craf_appna/v1/ecommerce/products' );
            $request->set_header( $header, 'example' );
            $this->assertStringContainsString( 'no-store', $this->protect( $request )->get_headers()['Cache-Control'] );
        }
        wp_set_current_user( self::factory()->user->create() );
        $response = $this->protect( new \WP_REST_Request( 'GET', '/craf_appna/v1/post-types/posts' ) );
        $this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );
    }

    public function test_mutations_errors_and_preflights_are_not_cached(): void {
        foreach ( ['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method ) {
            $response = $this->protect( new \WP_REST_Request( $method, '/craf_appna/v1/ecommerce/products' ) );
            $this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );
        }
        $response = $this->protect( new \WP_REST_Request( 'GET', '/craf_appna/v1/post-types/posts' ), 404 );
        $this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );
    }

    public function test_case_insensitive_routes_match_wordpress_routing(): void {
        foreach ( ['/CRAF_APPNA/V1/PLUGINS/', '/craf_appna/v1/POST-TYPES/AVAILABLE', '/craf_appna/v1/ECOMMERCE/ORDERS/1'] as $route ) {
            $response = $this->protect( new \WP_REST_Request( 'GET', $route ) );
            $this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );
        }
    }

    public function test_anonymous_public_gets_and_other_namespaces_keep_their_cache_policy(): void {
        foreach ( ['/craf_appna/v1/ecommerce/products', '/craf_appna/v1/directory/listings', '/craf_appna/v1/post-types/posts',
            '/wp/v2/posts', '/craf_appna/v10/plugins',
        ] as $route ) {
            $request  = new \WP_REST_Request( 'GET', $route );
            $original = new \WP_REST_Response( [], 200, ['Cache-Control' => 'public, max-age=60'] );
            $before   = did_action( 'litespeed_control_set_nocache' );
            $response = apply_filters( 'rest_post_dispatch', $original, rest_get_server(), $request );
            $this->assertSame( 'public, max-age=60', $response->get_headers()['Cache-Control'], $route );
            $this->assertSame( $before, did_action( 'litespeed_control_set_nocache' ) );
        }
    }

    public function test_actual_permission_failure_and_options_responses_are_protected(): void {
        foreach ( ['GET', 'OPTIONS'] as $method ) {
            $request  = new \WP_REST_Request( $method, '/craf_appna/v1/plugins' );
            $response = rest_do_request( $request );
            $this->assertSame( 'GET' === $method ? 401 : 200, $response->get_status() );
            $response = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
            $this->assertSame( 'private, no-store, max-age=0', $response->get_headers()['Cache-Control'] );
        }
        $headers = apply_filters( 'rest_allowed_cors_headers', ['Content-Type', 'Authorization'] );
        foreach ( ['Authorization', 'X-AppNatively-Key', 'X-Cart-Id', 'X-WC-Session'] as $header ) {
            $this->assertContains( $header, $headers );
        }
        $this->assertSame( count( array_unique( $headers ) ), count( $headers ) );
    }
}
