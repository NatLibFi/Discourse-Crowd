<?php
/**
 * Discourse single sign on authentication using Crowd.
 * Copyright (c) 2015-2020 University Of Helsinki (The National Library Of Finland)
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
 * @author    Ere Maijala <ere.maijala@helsinki.fi>
 * @author    Aleksi Peebles <aleksi.peebles@helsinki.fi>
 * @copyright 2015-2020 University Of Helsinki (The National Library Of Finland)
 * @license   https://www.gnu.org/licenses/gpl-3.0.txt GPL-3.0
 */

namespace NatLibFi\Discourse\Discourse;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

/**
 * Implements Discourse api client calls
 *
 * The methods on this class are based on the Discourse api reference
 * implementation written in Ruby.
 *
 * @see https://github.com/discourse/discourse_api/blob/master/lib/discourse_api/api/groups.rb Groups reference
 * @see https://github.com/discourse/discourse_api/blob/master/lib/discourse_api/api/sso.rb SSO reference
 */
class DiscourseClient
{
    /** @var Client The http client used to access the api */
    private $http;

    /**
     * Creates a new instance of DiscourseClient.
     * @param string $url Base url to the discourse api
     * @param string $username Username used to access the discourse api
     * @param string $key The api key for the user
     */
    public function __construct($url, $username, $key)
    {
        $this->http = new Client([
            'base_url' => rtrim($url, '/') . '/',
            'defaults' => [
                'verify'  => false,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                    'Api-Key'      => $key,
                    'Api-Username' => $username,
                ],
            ],
        ]);
    }

    /**
     * Formats api parameters.
     * @param array $data Parameters to format
     * @return string[] Formatted api parameters
     */
    private function formatParameters(array $data)
    {
        return array_map(function ($value) {
            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }, $data);
    }


    public function getGroupId($name)
    {
        try {
            $res = $this->http->get('groups/' . urlencode($name) . '.json')->json();
        } catch (ClientException $exception) {
            // 403 probably means the group doesn't exist
            if ($exception->getResponse()->getStatusCode() === 403) {
                return null;
            }
            throw $exception;
        }
        return $res['group']['id'];
    }

    /**
     * Creates a new group.
     *
     * The group parameters can include at least the following parameters:
     * - name : The name for the group
     * - visible : 'true' if the group is visible, 'false' if not
     *
     * @param array $args Group parameters
     * @return array The information for the new group
     * @throws \InvalidArgumentException If no group name has been defined
     */
    public function createGroup(array $args)
    {
        if (!isset($args['name'])) {
            throw new \InvalidArgumentException('Group requires a name');
        }

        $createArgs = [
            'name' => $args['name'],
            'visibility_level' => empty($args['visible']) ? 0 : 3
        ];

        return $this->http->post('admin/groups', ['json' => $createArgs])->json();
    }

    /**
     * Returns the group information for all groups.
     * @return array Information for all groups
     */
    public function groups()
    {
        return $this->http->get('groups.json')->json();
    }

    /**
     * Adds new users to the group.
     *
     * The user array may define the following keys:
     * - username / usernames : An array or comma separated list of usernames
     * - user_id / user_ids : An array or comma separated list of user ids
     *
     * @param int $groupId Id of the group
     * @param array $users User definition
     * @return array The api response to adding user a to a group
     */
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

        $users = $this->formatParameters($users);
        return $this->http->put(sprintf('admin/groups/%d/members.json', $groupId), ['json' => $users])->json();
    }

    /**
     * Removes the user from the group
     * @param int $groupId The group id
     * @param array $user Array with 'username' or 'user_id' key
     * @return array The api response to removing user from a group
     */
    public function groupRemove($groupId, array $user)
    {
        return $this->http->delete(
            sprintf('admin/groups/%d/members.json', $groupId),
            ['query' => $user]
        )->json();
    }

    /**
     * Syncs SSO user with the Discourse user list.
     *
     * The user data array should contain the following keys:
     * - sso_secret : The secret key used to sign the SSO payload
     * - name : The real name for the user
     * - username : The username for the user
     * - email : The email address for the user
     * - external_id : The id used to refer to the user in the external system
     *
     * @param string[] $params The user parameters
     * @return array Discourse user information
     */
    public function syncSso(array $params)
    {
        $sso = new SingleSignOn($params['sso_secret']);
        $sso['name'] = $params['name'];
        $sso['username'] = $params['username'];
        $sso['email'] = $params['email'];
        $sso['external_id'] = $params['external_id'];
        parse_str($sso->getPayload(), $payload);

        $payload = $this->formatParameters($payload);
        return $this->http->post('admin/users/sync_sso', ['json' => $payload])->json();
    }
}

