<?php
/**
 * Discourse single sign on authentication using Crowd.
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

namespace NatLibFi\Discourse\Crowd;

use GuzzleHttp\Client;

/**
 * Implements the Crowd API HTTP calls.
 */
class CrowdClient
{
    /** @var Client The HTTP client used to access the API */
    private $http;

    /**
     * Creates a new instance of CrowdClient.
     * @param string $url Base url to the Crowd API
     * @param string $username Username used to access the Crowd API
     * @param string $password Password for the API user
     */
    public function __construct($url, $username, $password)
    {
        $this->http = new Client([
            'base_uri'        => rtrim($url, '/') . '/rest/usermanagement/1/',
            'auth'            => [$username, $password],
            'connect_timeout' => 5,
            'timeout'         => 15,
            'headers'         => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
        ]);
    }

    /**
     * Decodes a JSON response body.
     * @param \Psr\Http\Message\ResponseInterface $response The response to decode
     * @return array The decoded response body
     */
    private function decode($response)
    {
        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Returns the Crowd user information.
     * @param string $username Username to look up
     * @return array The Crowd user information
     */
    public function getUser($username)
    {
        return $this->decode($this->http->get('user', ['query' => ['username' => $username]]));
    }

    /**
     * Returns information for all the nested groups for the user.
     * @param string $username Username to look up
     * @return array Information regarding all the nested groups for the user
     */
    public function getUserNestedGroups($username)
    {
        return $this->decode($this->http->get('user/group/nested', ['query' => ['username' => $username]]));
    }

    /**
     * Validates the session token.
     * @param string $token Session token to validate
     * @param array $validators List of validators
     * @return array The validation response
     */
    public function postSessionToken($token, array $validators)
    {
        $json = ['validationFactors' => []];

        foreach ($validators as $name => $value) {
            $json['validationFactors'][] = [
                'value' => $value,
                'name' => $name,
            ];
        }

        $url = sprintf('session/%s', urlencode($token));
        return $this->decode($this->http->post($url, ['json' => $json]));
    }

    /**
     * Returns the cookie configuration settings.
     * @return array The cookie configuration settings
     */
    public function getCookieConfig()
    {
        return $this->decode($this->http->get('config/cookie'));
    }
}
