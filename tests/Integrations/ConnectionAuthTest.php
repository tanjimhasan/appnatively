<?php

namespace Crafium\AppNatively\Tests\Integrations;

use Crafium\AppNatively\App\Http\Middleware\EnsureIsConnectedApp;
use Crafium\AppNatively\App\Support\Auth;
use Crafium\AppNatively\App\Support\ContentTypes;
use Crafium\AppNatively\App\Support\Settings;

class ConnectionAuthTest extends \WP_UnitTestCase {
    private string $key;

    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( 0 );
        Settings::set_api_enabled( true );
        $this->key = Settings::regenerate_site_key();
        rest_get_server();
        rest_api_default_filters();
    }

    public function tearDown(): void {
        wp_set_current_user( 0 );
        ContentTypes::flush();
        parent::tearDown();
    }

    private function authorize( array $headers ) {
        $request = new \WP_REST_Request( 'GET', '/craf_appna/v1/plugins' );
        foreach ( $headers as $name => $value ) {
            $request->set_header( $name, $value );
        }
        return ( new EnsureIsConnectedApp() )->handle( $request, static fn() => true );
    }

    public function test_bearer_and_legacy_keys_work_without_establishing_a_user(): void {
        foreach ( [
            ['Authorization' => 'Bearer ' . $this->key],
            ['authorization' => 'bEaReR  ' . $this->key],
            ['Authorization' => "\tBearer\t" . $this->key . "\t"],
            ['X-AppNatively-Key' => $this->key],
            ['Authorization' => 'Bearer ' . $this->key, 'X-AppNatively-Key' => 'wrong'],
        ] as $headers ) {
            $this->assertTrue( $this->authorize( $headers ) );
            $this->assertSame( 0, get_current_user_id() );
        }
    }

    public function test_invalid_authorization_never_falls_back_to_a_valid_legacy_key(): void {
        foreach ( ['', 'Bearer', 'Bearer wrong', 'Basic ' . $this->key, 'prefix Bearer ' . $this->key,
            'Bearer ' . $this->key . ' extra', 'Bearer ' . $this->key . ',another', "Bearer " . $this->key . "\n",
        ] as $header ) {
            $error = $this->authorize( ['Authorization' => $header, 'X-AppNatively-Key' => $this->key] );
            $this->assertWPError( $error );
            $this->assertSame( 'craf_appna_bad_site_key', $error->get_error_code() );
            $this->assertSame( 401, $error->get_error_data()['status'] );
        }
    }

    public function test_missing_keys_do_not_generate_a_key(): void {
        delete_option( Settings::OPTION_SITE_KEY );
        $this->assertWPError( $this->authorize( [] ) );
        $this->assertSame( '', get_option( Settings::OPTION_SITE_KEY, '' ) );
    }

    public function test_rotation_revokes_both_transports(): void {
        $new_key = Settings::regenerate_site_key();
        foreach ( ['Authorization', 'X-AppNatively-Key'] as $header ) {
            $prefix = 'Authorization' === $header ? 'Bearer ' : '';
            $this->assertWPError( $this->authorize( [$header => $prefix . $this->key] ) );
            $this->assertTrue( $this->authorize( [$header => $prefix . $new_key] ) );
        }
    }

    public function test_api_disabled_refuses_a_valid_key(): void {
        Settings::set_api_enabled( false );
        $error = $this->authorize( ['Authorization' => 'Bearer ' . $this->key] );
        $this->assertSame( 'craf_appna_api_disabled', $error->get_error_code() );
        $this->assertSame( 503, $error->get_error_data()['status'] );
    }

    public function protected_routes(): array {
        return [
            ['GET', '/plugins'],
            ['GET', '/post-types/available'],
            ['POST', '/post-types/selection'],
            ['GET', '/ecommerce/products/filter-sources'],
            ['GET', '/directory/listings/filter-sources'],
        ];
    }

    /** @dataProvider protected_routes */
    public function test_all_studio_routes_enforce_the_site_key( string $method, string $path ): void {
        $route   = '/craf_appna/v1' . $path;
        $request = new \WP_REST_Request( $method, $route );
        $denied  = rest_do_request( $request );
        $this->assertSame( 401, $denied->get_status(), $route );
        $this->assertSame( 'craf_appna_bad_site_key', $denied->get_data()['code'] );

        $user_id = self::factory()->user->create();
        $token   = Auth::issue( $user_id );
        $request->set_header( 'Authorization', 'Bearer ' . $token );
        $this->assertSame( 401, rest_do_request( $request )->get_status() );

        // Check the real route's permission callback without needing an active
        // ecommerce/directory integration for its controller to run.
        $endpoints = rest_get_server()->get_routes()[$route];
        foreach ( ['Authorization', 'X-AppNatively-Key'] as $header ) {
            $authorized = new \WP_REST_Request( $method, $route );
            $authorized->set_header( $header, ( 'Authorization' === $header ? 'Bearer ' : '' ) . $this->key );
            $found = false;
            foreach ( $endpoints as $endpoint ) {
                if ( ! empty( $endpoint['methods'][$method] ) ) {
                    $this->assertTrue( $endpoint['permission_callback']( $authorized ) );
                    $found = true;
                }
            }
            $this->assertTrue( $found, 'Missing protected endpoint: ' . $route );
        }
    }

    public function test_connection_key_cannot_authenticate_a_mobile_user(): void {
        $request = new \WP_REST_Request( 'GET', '/craf_appna/v1/auth/me' );
        $request->set_header( 'Authorization', 'Bearer ' . $this->key );
        $this->assertNull( Auth::resolve( $request ) );
        $this->assertSame( 401, rest_do_request( $request )->get_status() );
        $this->assertSame( 0, get_current_user_id() );
    }

    public function test_handshake_preserves_fields_and_advertises_bearer_support(): void {
        $response = rest_do_request( new \WP_REST_Request( 'GET', '/craf_appna/v1/connection' ) );
        $data     = $response->get_data();
        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( $data['connected'] );
        $this->assertSame( 'AppNatively', $data['name'] );
        $this->assertSame( craf_appna_version(), $data['version'] );
        $this->assertTrue( $data['capabilities']['bearerConnectionKey'] );
        $this->assertArrayNotHasKey( 'plugins', $data );
    }

    public function test_authenticated_get_and_post_work_with_both_transports(): void {
        foreach ( ['Authorization', 'X-AppNatively-Key'] as $header ) {
            $value = ( 'Authorization' === $header ? 'Bearer ' : '' ) . $this->key;
            $get   = new \WP_REST_Request( 'GET', '/craf_appna/v1/plugins' );
            $get->set_header( $header, $value );
            $this->assertSame( 200, rest_do_request( $get )->get_status() );

            $post = new \WP_REST_Request( 'POST', '/craf_appna/v1/post-types/selection' );
            $post->set_header( $header, $value );
            $post->set_body_params( ['app_id' => 'auth-test', 'contentTypes' => []] );
            $response = rest_do_request( $post );
            $this->assertSame( 200, $response->get_status() );
            $this->assertSame( [], $response->get_data()['data']['selected'] );
        }
    }
}
