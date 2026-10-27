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

namespace ltisource_ltipseudonym\local;

/**
 * Pseudonym generation and the persistent user to token mapping.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Atomic get-or-create of the pseudonym of a user in a scope.
 *
 * Uniqueness is enforced by the database (unique indexes on the mapping table). Two requests that
 * create the first mapping at the same moment both try to insert; the loser receives a DML error,
 * re-reads and returns the winner's token, so exactly one mapping exists.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_service {
    /** @var int Minimum seconds between two updates of timelastused of one mapping. */
    public const TOUCH_INTERVAL = 300;

    /** @var string Name of the HMAC secret in the plugin configuration (stored encrypted). */
    private const SECRET_CONFIG = 'hmacsecret_v1';

    /**
     * Coordinates (typeid, scopetype, scopeid) of the mapping for a scope.
     *
     * @param string $scopetype one of the catalogue scopes
     * @param int $typeid tool type
     * @param int $courseid
     * @param int $cmid
     * @return array [mapping typeid, scopetype, scopeid]
     * @throws \moodle_exception when the scope cannot be evaluated (no course module, ...)
     */
    public static function scope_coordinates(string $scopetype, int $typeid, int $courseid, int $cmid): array {
        switch ($scopetype) {
            case catalogue::SCOPE_SITE:
                return [0, catalogue::SCOPE_SITE, 0];
            case catalogue::SCOPE_DEPLOYMENT:
                return [$typeid, catalogue::SCOPE_DEPLOYMENT, 0];
            case catalogue::SCOPE_COURSE:
                if ($courseid <= 0) {
                    throw new \moodle_exception('launchrefused', config::COMPONENT, '', 'scope course without course');
                }
                return [$typeid, catalogue::SCOPE_COURSE, $courseid];
            case catalogue::SCOPE_CM:
                if ($cmid <= 0) {
                    throw new \moodle_exception('launchrefused', config::COMPONENT, '', 'scope cm without course module');
                }
                return [$typeid, catalogue::SCOPE_CM, $cmid];
        }
        throw new \moodle_exception('launchrefused', config::COMPONENT, '', 'unknown scope');
    }

    /**
     * Get the active pseudonym of a user in a scope, creating it on first use.
     *
     * @param int $userid
     * @param int $typeid mapping typeid (0 for the site scope)
     * @param string $scopetype
     * @param int $scopeid
     * @param string $ptype random_persisted or hmac_secret_v1
     * @return string the pseudonym
     */
    public function get_or_create(int $userid, int $typeid, string $scopetype, int $scopeid, string $ptype): string {
        global $DB;
        if (!in_array($ptype, [catalogue::TYPE_RANDOM, catalogue::TYPE_HMAC], true)) {
            throw new \coding_exception('Not a pseudonymising type: ' . $ptype);
        }
        if ($userid <= 0) {
            throw new \moodle_exception('launchrefused', config::COMPONENT, '', 'no user');
        }
        $key = [
            'userid' => $userid,
            'typeid' => $typeid,
            'scopetype' => $scopetype,
            'scopeid' => $scopeid,
            'ptype' => $ptype,
            'version' => config::generation($typeid),
        ];
        $existing = $DB->get_record('ltisource_ltipseudonym_map', $key + ['status' => 'active']);
        if ($existing) {
            $this->touch($existing);
            return $existing->token;
        }

        $token = ($ptype === catalogue::TYPE_RANDOM) ? self::random_token() : $this->hmac_token($key);
        $this->before_insert($key);
        $now = time();
        try {
            $DB->insert_record('ltisource_ltipseudonym_map', (object) ($key + [
                'token' => $token,
                'status' => 'active',
                'timecreated' => $now,
                'timelastused' => $now,
            ]));
            return $token;
        } catch (\dml_exception $e) {
            // Somebody else created the mapping between our read and our insert (unique index).
            $winner = $DB->get_record('ltisource_ltipseudonym_map', $key + ['status' => 'active']);
            if ($winner) {
                return $winner->token;
            }
            throw $e;
        }
    }

    /**
     * Extension point called right before the insert. Tests use it to simulate the competing request.
     *
     * @param array $key identity key of the mapping about to be created
     */
    protected function before_insert(array $key): void {
        // Nothing in production.
    }

    /**
     * Refresh timelastused, at most once per TOUCH_INTERVAL to keep launches write-free in the common case.
     *
     * @param \stdClass $mapping
     */
    private function touch(\stdClass $mapping): void {
        global $DB;
        if (time() - (int) $mapping->timelastused >= self::TOUCH_INTERVAL) {
            $DB->set_field('ltisource_ltipseudonym_map', 'timelastused', time(), ['id' => $mapping->id]);
        }
    }

    /**
     * Cryptographically random token of 192 bit, URL safe.
     *
     * @return string 32 characters
     */
    public static function random_token(): string {
        return self::base64url(random_bytes(24));
    }

    /**
     * Deterministic token for the HMAC pseudonym type.
     *
     * @param array $key identity key of the mapping
     * @return string
     */
    private function hmac_token(array $key): string {
        global $CFG;
        $clientid = '';
        if ($key['typeid'] > 0) {
            $clientid = (string) $this->client_id($key['typeid']);
        }
        return self::derive_hmac(
            $this->hmac_secret(),
            (int) $key['version'],
            $CFG->wwwroot,
            $clientid,
            (int) $key['typeid'],
            $key['scopetype'],
            (int) $key['scopeid'],
            (int) $key['userid']
        );
    }

    /**
     * Client id of a tool registration (part of the HMAC domain).
     *
     * @param int $typeid
     * @return string|null
     */
    private function client_id(int $typeid): ?string {
        global $DB;
        $value = $DB->get_field('lti_types', 'clientid', ['id' => $typeid], IGNORE_MISSING);
        return $value === false ? null : $value;
    }

    /**
     * The HMAC construction, public so that tests and auditors can reproduce it with a known key.
     *
     * The message is a JSON list, which is unambiguous (no separator injection) and carries the
     * domain: plugin, algorithm version, key generation, platform issuer, tool client id, tool
     * type (deployment), scope and user.
     *
     * @param string $secret raw secret
     * @param int $generation identity generation
     * @param string $issuer platform issuer (wwwroot)
     * @param string $clientid tool client id
     * @param int $typeid tool type id (deployment)
     * @param string $scopetype
     * @param int $scopeid
     * @param int $userid
     * @return string URL-safe base64 of the 256 bit MAC
     */
    public static function derive_hmac(
        string $secret,
        int $generation,
        string $issuer,
        string $clientid,
        int $typeid,
        string $scopetype,
        int $scopeid,
        int $userid
    ): string {
        $message = json_encode([
            'ltisource_ltipseudonym',
            catalogue::TYPE_HMAC,
            $generation,
            $issuer,
            $clientid,
            $typeid,
            $scopetype,
            $scopeid,
            $userid,
        ]);
        return self::base64url(hash_hmac('sha256', $message, $secret, true));
    }

    /**
     * The secret of the HMAC type; created on first use and stored encrypted via core encryption.
     *
     * @return string raw secret
     */
    public function hmac_secret(): string {
        $stored = get_config(config::COMPONENT, self::SECRET_CONFIG);
        if (!empty($stored)) {
            return $this->decode_secret($stored);
        }
        $factory = \core\lock\lock_config::get_lock_factory(config::COMPONENT);
        $lock = $factory->get_lock('hmacsecret', 10);
        if (!$lock) {
            throw new \moodle_exception('launchrefused', config::COMPONENT, '', 'cannot lock secret creation');
        }
        try {
            // Re-read under the lock: another process may have created it meanwhile.
            $stored = get_config(config::COMPONENT, self::SECRET_CONFIG);
            if (empty($stored)) {
                $raw = random_bytes(32);
                set_config(self::SECRET_CONFIG, \core\encryption::encrypt(base64_encode($raw)), config::COMPONENT);
                return $raw;
            }
        } finally {
            $lock->release();
        }
        return $this->decode_secret($stored);
    }

    /**
     * Decrypt the stored secret and refuse to continue with an empty key.
     *
     * @param string $stored encrypted configuration value
     * @return string raw secret
     */
    private function decode_secret(string $stored): string {
        $raw = base64_decode(\core\encryption::decrypt($stored), true);
        if ($raw === false || strlen($raw) < 32) {
            throw new \moodle_exception('launchrefused', config::COMPONENT, '', 'unusable HMAC secret');
        }
        return $raw;
    }

    /**
     * URL-safe base64 without padding.
     *
     * @param string $binary
     * @return string
     */
    private static function base64url(string $binary): string {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /**
     * Find the user a pseudonym belongs to within a tool (reverse translation, used by diagnostics and tests).
     *
     * @param string $token
     * @return \stdClass|null mapping row
     */
    public static function lookup(string $token): ?\stdClass {
        global $DB;
        $row = $DB->get_record('ltisource_ltipseudonym_map', ['token' => $token]);
        return $row ?: null;
    }
}
