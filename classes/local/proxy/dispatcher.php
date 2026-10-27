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

namespace ltisource_ltipseudonym\local\proxy;

use mod_lti\local\ltiservice\resource_base;
use mod_lti\local\ltiservice\response;

/**
 * Request dispatch of the identity-aware service proxy.
 *
 * Replicates mod/lti/services.php for the two services that carry user identifiers (memberships and grade
 * service): the first resource whose media type and path template match the request handles it. The answer is
 * returned and sent by the caller.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dispatcher {
    /**
     * The services answered by the proxy, bound to the proxy URL.
     *
     * @return \mod_lti\local\ltiservice\service_base[] services of installed ltiservice plugins only
     */
    public static function services(): array {
        $services = [];
        if (class_exists(\ltiservice_gradebookservices\local\service\gradebookservices::class)) {
            $services[] = new \ltisource_ltipseudonym\local\services\gradebookservices();
        }
        if (class_exists(\ltiservice_memberships\local\service\memberships::class)) {
            $services[] = new \ltisource_ltipseudonym\local\services\memberships();
        }
        return $services;
    }

    /**
     * Handle the current request.
     *
     * @param string|null $path path info of the request, null for $_SERVER['PATH_INFO']
     * @param string|null $body request body, null for the input stream
     * @return response answer, not yet sent
     */
    public static function handle(?string $path = null, ?string $body = null): response {
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
        $path = $path ?? (isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '');
        $accept = $response->get_accept();
        $contenttype = $response->get_content_type();

        $handler = null;
        foreach (self::services() as $service) {
            foreach ($service->get_resources() as $resource) {
                $formats = $resource->get_formats();
                $acceptmismatch = $isget && !empty($accept) && strpos($accept, '*/*') === false && !in_array($accept, $formats);
                $typemismatch = !$isget && !$isdelete && !in_array($contenttype, $formats);
                if ($acceptmismatch || $typemismatch) {
                    continue;
                }
                $template = $resource->get_template();
                $template = preg_replace('/{config_type}/', '(toolproxy|tool)', $template);
                $template = preg_replace('/\{[a-zA-Z_]+\}/', '[^/]+', $template);
                $template = preg_replace('/\(([0-9a-zA-Z_\-,\/]+)\)/', '(\\1|)', $template);
                $template = str_replace('/', '\/', $template);
                if (preg_match("/^{$template}$/", $path) === 1) {
                    $handler = $resource;
                    break 2;
                }
            }
        }
        if ($handler === null) {
            $response->set_code(400);
            $response->set_reason("No handler found for {$path} {$accept} {$contenttype}");
            return $response;
        }
        $response->set_request_data($body ?? file_get_contents('php://input'));
        if (in_array($response->get_request_method(), $handler->get_methods())) {
            $handler->execute($response);
        } else {
            $response->set_code(405);
        }
        return $response;
    }
}
