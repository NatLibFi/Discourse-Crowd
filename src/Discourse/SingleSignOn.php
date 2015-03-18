<?php
/**
 * Single sign on authentication using Crowd.
 *
 * Copyright (c) 2015 University Of Helsinki (The National Library Of Finland)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Riikka Kalliomäki <riikka.kalliomaki@helsinki.fi>
 * @copyright 2015 University Of Helsinki (The National Library Of Finland)
 * @license   https://www.gnu.org/licenses/gpl-3.0.txt GPL-3.0
 */

namespace NatLibFi\Discourse\Discourse;

/**
 * Implements Discourse single sign on payload handling.
 *
 * The implementation of this class is based on the official api reference
 * implementation written in Ruby. This implementation should follow the
 * standard PHP coding practices, but follow the Ruby implementation as close
 * as possible.
 *
 * @see https://github.com/discourse/discourse_api/blob/master/lib/discourse_api/single_sign_on.rb Reference
 */
class SingleSignOn implements \ArrayAccess
{
    /** @var string[] List of allowed payload attributes */
    private static $attributes = [
        'nonce', 'name', 'username', 'email', 'avatar_url', 'avatar_force_update',
        'about_me', 'external_id', 'return_sso_url', 'admin', 'moderator',
    ];

    /** @var string[] List of payload attributes that are integers */
    private static $integers = [];

    /** @var string[] List of payload attributes that are booleans */
    private static $booleans = [
        'avatar_force_update', 'admin', 'moderator'
    ];

    /** @var array Values for payload attributes */
    private $values;

    /** @var array Custom payload attributes */
    private $customFields;

    /** @var string The secret key used for signing payloads */
    private $ssoSecret;

    /**
     * Creates a new instance of SingleSignOn.
     * @param string $secret The secret key used for signing payloads
     */
    public function __construct($secret)
    {
        $this->ssoSecret = $secret;
        $this->values = array_fill_keys(self::$attributes, null);
        $this->customFields = [];
    }

    /**
     * Returns The secret key used for signing payloads.
     * @return string The secret key used for signing payloads
     */
    public function getSsoSecret()
    {
        return $this->ssoSecret;
    }

    /**
     * Parses the single sign on payload and returns a new SingleSignOn instance.
     * @param string $payload The payload string containing the 'sso' and 'sig' parameters
     * @param string $ssoSecret The secret key used for signing payloads
     * @return SingleSignOn New payload handler based on the given payload
     */
    public static function parse($payload, $ssoSecret = null)
    {
        $sso = new SingleSignOn($ssoSecret);

        parse_str($payload, $parsed);

        if (!isset($parsed['sso'], $parsed['sig'])) {
            throw new \InvalidArgumentException('Bad sso payload');
        } elseif ($sso->sign($parsed['sso']) !== $parsed['sig']) {
            throw new \RuntimeException('Bad signature for payload');
        }

        $decoded = base64_decode($parsed['sso']);
        parse_str($decoded, $decodedHash);

        foreach (self::$attributes as $attribute) {
            if (!isset($decodedHash[$attribute])) {
                continue;
            }

            $value = $decodedHash[$attribute];

            if (in_array($attribute, self::$integers)) {
                $value = (int) $value;
            } elseif (in_array($attribute, self::$booleans)) {
                $value = in_array($value, ['true', 'false'], true) ? $value === 'true' : null;
            }

            $sso[$attribute] = $value;
        }

        foreach ($decodedHash as $attribute => $value) {
            if (substr($attribute, 0, 7) !== 'custom.') {
                continue;
            }

            $field = substr($attribute, 7);
            $sso->customFields[$field] = $value;
        }

        return $sso;
    }

    /**
     * Returns the signature for the payload signed using the secret key.
     * @param string $payload Payload to sign
     * @return string The signature for the payload
     */
    public function sign($payload)
    {
        return hash_hmac('sha256', $payload, $this->ssoSecret);
    }

    /**
     * Returns the payload string containing the 'sso' and 'sig' parameters.
     * @return string The signed payload string
     */
    public function getPayload()
    {
        $payload = base64_encode($this->getUnsignedPayload());
        return http_build_query([
            'sso' => $payload,
            'sig' => $this->sign($payload),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Returns the unsigned payload parameters.
     * @return string The unsigned payload parameters
     */
    public function getUnsignedPayload()
    {
        $payload = [];

        foreach ($this->values as $key => $value) {
            if ($value === null) {
                continue;
            }

            $payload[$key] = $value;
        }

        foreach ($this->customFields as $key => $value) {
            $payload['custom.' . $key] = $value;
        }

        // Handle string casting differences between ruby and php
        $payload = array_map(function ($value) {
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            return (string) $value;
        }, $payload);

        return http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Tells if the payload parameter is set.
     * @param string $offset name of the payload parameter
     * @return bool True if the payload parameter is set, false if not
     */
    public function offsetExists($offset)
    {
        $this->validateAttribute($offset);
        return $this->values[$offset] !== null;
    }

    /**
     * Returns the value for the payload parameter.
     * @param string $offset Name of the payload parameter
     * @return mixed Value for the payload parameter
     */
    public function offsetGet($offset)
    {
        $this->validateAttribute($offset);
        return $this->values[$offset];
    }

    /**
     * Sets the value for the payload parameter.
     * @param string $offset Name of the payload parameter
     * @param mixed $value Value for the payload parameter
     */
    public function offsetSet($offset, $value)
    {
        $this->validateAttribute($offset);
        $this->values[$offset] = $value;
    }

    /**
     * Sets value of the payload parameter to null.
     * @param string $offset Name of the payload parameter
     */
    public function offsetUnset($offset)
    {
        $this->validateAttribute($offset);
        $this->values[$offset] = null;
    }

    /**
     * Validates the name of the payload parameter
     * @param string $attribute Name of the payload parameter
     * @throws \InvalidArgumentException If the name is not a valid payload parameter
     */
    private function validateAttribute($attribute)
    {
        if (!array_key_exists($attribute, $this->values)) {
            throw new \InvalidArgumentException('Invalid SSO attribute');
        }
    }
}
