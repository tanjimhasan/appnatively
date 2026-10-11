<?php
/**
 * ConnectionController class
 *
 * @package Crafium\AppNatively\App\Http\Controllers
 */

namespace Crafium\AppNatively\App\Http\Controllers;

defined( "ABSPATH" ) || exit;

use Crafium\AppNatively\WpMVC\Routing\Response;
use Crafium\AppNatively\WpMVC\RequestValidator\Request;

/**
 * Class ConnectionController
 *
 * Answers the "is AppNatively installed and reachable here?" handshake that
 * Studio performs before the site owner supplies a connection key.
 *
 * Reports the plugin version and supported connection protocol, without
 * exposing the site's integration list, user data or settings.
 */
class ConnectionController extends Controller {
    /**
     * Report that the bridge is present.
     *
     * @param Request $request The REST request instance.
     * @return array
     */
    public function index( Request $request ): array {
        return Response::send(
            [
                "connected"    => true,
                "name"         => "AppNatively",
                "version"      => craf_appna_version(),
                "capabilities" => [
                    "bearerConnectionKey" => true,
                ],
            ]
        );
    }
}
