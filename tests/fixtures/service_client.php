<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace ltisource_ltipseudonym\testing;

use ltisource_ltipseudonym\local\proxy\dispatcher;
use ltisource_ltipseudonym\local\request_guard;
use mod_lti\local\ltiservice\resource_base;
use mod_lti\local\ltiservice\response;

/**
 * Performs LTI service requests (NRPS, AGS) in PHPUnit exactly as a web request would reach them.
 *
 * A URL of the proxy (mod/lti/source/ltipseudonym/service.php) is answered by the proxy dispatcher, as service.php does. A URL
 * of core (mod/lti/services.php) first passes the request guard of this plugin (after_config hook, as lib/setup.php
 * dispatches it) and, when the guard leaves it to core, the core dispatch of mod/lti/services.php, replicated line by line.
 * Only the sending of the answer is left out. The caller saves and restores $_SERVER and $_GET.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_client {
    /** @var string NRPS media type. */
    public const NRPS = 'application/vnd.ims.lti-nrps.v2.membershipcontainer+json';
    /** @var string Media type of the link memberships (LIS v2 container) of core. */
    public const LINK_MEMBERSHIPS = 'application/vnd.ims.lis.v2.membershipcontainer+json';
    /** @var string NRPS scope. */
    public const NRPS_SCOPE = 'https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly';
    /** @var string AGS score scope. */
    public const SCORE_SCOPE = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';
    /** @var string AGS result scope. */
    public const RESULT_SCOPE = 'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly';
    /** @var string AGS line item scope. */
    public const LINEITEM_SCOPE = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem';
    /** @var string AGS score media type. */
    public const SCORE = 'application/vnd.ims.lis.v1.score+json';
    /** @var string AGS result media type. */
    public const RESULT = 'application/vnd.ims.lis.v2.resultcontainer+json';
    /** @var string Path of the proxy. */
    public const PROXY_SCRIPT = '/mod/lti/source/ltipseudonym/service.php';
    /** @var string Path of core. */
    public const CORE_SCRIPT = '/mod/lti/services.php';

    /** @var string|null Which endpoint answered the last request: proxy, guard (core URL answered by the proxy) or core. */
    public static $answeredby = null;

    /**
     * A real access token row, as mod/lti/token.php writes it.
     *
     * @param int $typeid
     * @param string[]|null $scopes default: NRPS, score, result
     * @param int $validfor seconds (negative: expired)
     * @return string the bearer token
     */
    public static function access_token(int $typeid, ?array $scopes = null, int $validfor = 3600): string {
        global $DB;
        $scopes = $scopes ?? [self::NRPS_SCOPE, self::SCORE_SCOPE, self::RESULT_SCOPE, self::LINEITEM_SCOPE];
        $token = bin2hex(random_bytes(16));
        $DB->insert_record('lti_access_tokens', (object) ['typeid' => $typeid, 'scope' => json_encode($scopes),
            'token' => $token, 'validuntil' => time() + $validfor, 'timecreated' => time(), 'lastaccess' => null]);
        return $token;
    }

    /**
     * Send a request to a full service URL (as found in an id token or a Link header).
     *
     * @param string $method GET or POST
     * @param string $url absolute URL of the proxy or of core
     * @param string|null $bearer bearer token
     * @param array $extraget query parameters added to those of the URL
     * @param string $body request body
     * @param string $type Accept (GET) or Content-Type (POST)
     * @return response not yet sent
     */
    public static function call(
        string $method,
        string $url,
        ?string $bearer,
        array $extraget = [],
        string $body = '',
        string $type = ''
    ): response {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $query = $extraget + $query;
        $path = $parts['path'];
        if (($pos = strpos($path, self::PROXY_SCRIPT)) !== false) {
            return self::send($method, 'proxy', substr($path, $pos + strlen(self::PROXY_SCRIPT)), $bearer, $query, $body, $type);
        }
        $pos = strpos($path, self::CORE_SCRIPT);
        if ($pos === false) {
            throw new \coding_exception('Not a service URL: ' . $url);
        }
        return self::send($method, 'core', substr($path, $pos + strlen(self::CORE_SCRIPT)), $bearer, $query, $body, $type);
    }

    /**
     * Send a request by path info to the proxy or to core.
     *
     * @param string $method
     * @param string $endpoint proxy or core
     * @param string $path path info
     * @param string|null $bearer
     * @param array $get
     * @param string $body
     * @param string $type
     * @return response
     */
    public static function send(
        string $method,
        string $endpoint,
        string $path,
        ?string $bearer,
        array $get = [],
        string $body = '',
        string $type = ''
    ): response {
        foreach (['HTTP_ACCEPT', 'CONTENT_TYPE', 'HTTP_AUTHORIZATION'] as $key) {
            unset($_SERVER[$key]);
        }
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['PATH_INFO'] = $path;
        $_SERVER['QUERY_STRING'] = http_build_query($get);
        $_GET = $get;
        if ($bearer !== null) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
        }
        if ($method === 'GET') {
            $_SERVER['HTTP_ACCEPT'] = $type;
        } else {
            $_SERVER['CONTENT_TYPE'] = $type;
        }
        // Core's service resources throw "new \Exception(null, 401)" on a failed authentication, which PHP 8.1+ reports as a
        // deprecation of core code; it is swallowed here only when raised inside mod/lti/service (as fixture.php does for
        // backup code).
        set_error_handler(static function (int $errno, string $errstr, string $errfile): bool {
            return in_array($errno, [E_DEPRECATED, E_USER_DEPRECATED], true) &&
                strpos($errfile, DIRECTORY_SEPARATOR . 'mod' . DIRECTORY_SEPARATOR . 'lti' . DIRECTORY_SEPARATOR . 'service' .
                    DIRECTORY_SEPARATOR) !== false;
        });
        try {
            if ($endpoint === 'proxy') {
                self::$answeredby = 'proxy';
                return dispatcher::handle($path, $body);
            }
            $guarded = request_guard::core_service_response($path, $body);
            if ($guarded !== null) {
                self::$answeredby = 'guard';
                return $guarded;
            }
            self::$answeredby = 'core';
            return self::core_dispatch($path, $body);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The dispatch of mod/lti/services.php (4.5 and 5.3 identical), without sending.
     *
     * @param string $path
     * @param string $body
     * @return response
     */
    public static function core_dispatch(string $path, string $body): response {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $response = new response();
        $isget = $response->get_request_method() === resource_base::HTTP_GET;
        $isdelete = $response->get_request_method() === resource_base::HTTP_DELETE;
        if ($isget) {
            $response->set_accept(isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '');
        } else {
            $response->set_content_type(isset($_SERVER['CONTENT_TYPE']) ? explode(';', $_SERVER['CONTENT_TYPE'], 2)[0] : '');
        }
        $accept = $response->get_accept();
        $contenttype = $response->get_content_type();
        foreach (lti_get_services() as $service) {
            foreach ($service->get_resources() as $resource) {
                if (
                    ($isget && !empty($accept) && (strpos($accept, '*/*') === false) &&
                        !in_array($accept, $resource->get_formats())) ||
                        ((!$isget && !$isdelete) && !in_array($contenttype, $resource->get_formats()))
                ) {
                    continue;
                }
                $template = $resource->get_template();
                $template = preg_replace('/{config_type}/', '(toolproxy|tool)', $template);
                $template = preg_replace('/\{[a-zA-Z_]+\}/', '[^/]+', $template);
                $template = preg_replace('/\(([0-9a-zA-Z_\-,\/]+)\)/', '(\\1|)', $template);
                $template = str_replace('/', '\/', $template);
                if (preg_match("/^{$template}$/", $path) === 1) {
                    $response->set_request_data($body);
                    if (in_array($response->get_request_method(), $resource->get_methods())) {
                        $resource->execute($response);
                    } else {
                        $response->set_code(405);
                    }
                    return $response;
                }
            }
        }
        $response->set_code(400);
        $response->set_reason("No handler found for {$path} {$accept} {$contenttype}");
        return $response;
    }

    /**
     * A score body.
     *
     * @param string $userid the userId the tool sends
     * @param float $given score
     * @return string JSON
     */
    public static function score(string $userid, float $given): string {
        return json_encode([
            'userId' => $userid, 'timestamp' => date('c', time() + 60 + (int) $given), 'scoreGiven' => $given,
            'scoreMaximum' => 100, 'gradingProgress' => 'FullyGraded', 'activityProgress' => 'Completed',
        ]);
    }

    /**
     * The "next" URL of a Link header of a paged NRPS answer, or null.
     *
     * @param response $response
     * @return string|null
     */
    public static function next_link(response $response): ?string {
        $headers = (new \ReflectionProperty(response::class, 'additionalheaders'));
        $headers->setAccessible(true);
        foreach ((array) $headers->getValue($response) as $header) {
            if (preg_match('/^Link:\s*<([^>]+)>;\s*rel="next"/i', $header, $m)) {
                return $m[1];
            }
        }
        return null;
    }
}
