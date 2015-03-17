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

namespace Finna\Auth\Discourse;

use GuzzleHttp\Client;

class DiscourseClient
{
    private $http;

    public function __construct($url, $username, $key)
    {
        $this->http = new Client([
            'base_url' => rtrim($url, '/') . '/',
            'defaults' => [
                'verify'  => false,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'query'   => [
                    'api_key'      => $key,
                    'api_username' => $username,
                ],
            ],
        ]);
    }

    public function createGroup(array $args)
    {
        $args += ['visible' => 'true'];

        if (!isset($args['name'])) {
            throw new \InvalidArgumentException('Group requires a name');
        }

        return $this->http->post('admin/groups', ['json' => $args])->json();
    }

    public function groups()
    {
        return $this->http->get('admin/groups.json')->json();
    }

    public function groupAdd($groupId, array $users)
    {
        foreach ($users as $key => $value) {
            // Make comma separated lists of users provided as an array
            if (is_array($value)) {
                $users[$key] = implode(',', $value);
            }

            // Convert 'username' and 'user_id' to 'usernames' and 'user_ids'
            if (substr($key, -1) !== 's') {
                $users[$key . 's'] = $users[$key];
                unset($users[$key]);
            }
        }

        return $this->http->put(sprintf('admin/groups/%d/members.json', $groupId), ['json' => $users])->json();
    }

    public function groupRemove($groupId, $user)
    {
        $type = is_string($user) ? 'username' : 'user_id';

        return $this->http->delete(
            sprintf('admin/groups/%d/members.json', $groupId),
            ['query' => [$type => $user]]
        )->json();
    }

    public function syncSso(array $params)
    {
        $sso = new SingleSignOn($params['sso_secret']);
        $sso['name'] = $params['name'];
        $sso['username'] = $params['username'];
        $sso['email'] = $params['email'];
        $sso['external_id'] = $params['external_id'];
        parse_str($sso->getPayload(), $payload);

        return $this->http->post('admin/users/sync_sso', ['json' => $payload])->json();
    }
}
