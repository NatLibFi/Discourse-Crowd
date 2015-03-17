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
 * @license GPL-3.0
 * @copyright 2015 University Of Helsinki (The National Library Of Finland)
 * @author Riikka Kalliomäki <riikka.kalliomaki@helsinki.fi>
 */

namespace Finna\Auth\Crowd;

use GuzzleHttp\Client;

class CrowdClient
{
    private $http;

    public function __construct($url, $username, $password)
    {
        $this->http = new Client([
            'base_url' => rtrim($url, '/') . '/rest/usermanagement/1/',
            'defaults' => [
                'auth'    => [$username, $password],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
            ],
        ]);
    }

    public function getUser($username)
    {
        return $this->http->get('user', ['query' => ['username' => $username]])->json();
    }

    public function getUserNestedGroups($username)
    {
        return $this->http->get('user/group/nested', ['query' => ['username' => $username]])->json();
    }

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
        return $this->http->post($url, ['json' => $json])->json();
    }

    public function getCookieConfig()
    {
        return $this->http->get('config/cookie')->json();
    }
}
