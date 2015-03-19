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

use GuzzleHttp\Exception\ClientException;

/**
 * Provides convenience methods for accessing the external Crowd API.
 */
class CrowdApi
{
    /** @var CrowdClient The Crowd API client */
    private $client;

    /** @var array Cookie configuration settings cache */
    private $cookieSettings;

    /**
     * Creates a new instance of CrowdApi.
     * @param string $url Base url to the Crowd API
     * @param string $username Username used to access the Crowd API
     * @param string $password Password for the API user
     */
    public function __construct($url, $username, $password)
    {
        $this->client = new CrowdClient($url, $username, $password);
    }

    /**
     * Returns the user information for the username.
     * @param string $username Username to lookup
     * @return array The user data
     */
    public function getUser($username)
    {
        return $this->client->getUser($username);
    }

    /**
     * Returns the names of all the groups that the user belongs to.
     * @param string $username Username to lookup
     * @return string[] List of all the groups names
     */
    public function getUserGroups($username)
    {
        $groups = $this->client->getUserNestedGroups($username);
        $names = [];

        foreach ($groups['groups'] as $group) {
            $names[] = $group['name'];
        }

        return $names;
    }

    /**
     * Attempts to authenticate the current request using a cookie.
     * @return string|null Username of the logged in user or null if the user is not logged in
     */
    public function authenticateCookie()
    {
        $settings = $this->getCookieSettings();
        $cookieName = str_replace('.', '_', $settings['name']);

        if (!isset($_COOKIE[$cookieName])) {
            return null;
        }

        $token = $_COOKIE[$cookieName];

        try {
            $response = $this->client->postSessionToken($token, ['remote_address' => $_SERVER['REMOTE_ADDR']]);
        } catch (ClientException $exception) {
            $response = $exception->getResponse();

            // 400 means IP validation failed, 404 means expired token
            if (in_array($response->getStatusCode(), [400, 404], true)) {
                return null;
            }

            throw $exception;
        }

        if ($token !== $response['token']) {
            throw new \UnexpectedValueException(
                sprintf("Returned token mismatch, '%s' <> '%s'", $token, $response['token'])
            );
        }

        return $response['user']['name'];
    }

    /**
     * Returns the cookie configuration settings used by Crowd.
     * @return array The cookie configuration settings
     */
    private function getCookieSettings()
    {
        if (!isset($this->cookieSettings)) {
            $config = $this->client->getCookieConfig();

            $this->cookieSettings = [
                'domain' => (string) $config['domain'],
                'secure' => (string) $config['secure'],
                'name' => (string) $config['name'],
            ];
        }

        return $this->cookieSettings;
    }
}
