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

use GuzzleHttp\Exception\ClientException;

/**
 * Provides convenience methods for accessing the external Discourse Api.
 */
class DiscourseApi
{
    /** @var DiscourseClient The Discourse API client */
    private $client;

    /** @var int[] List of discourse group ids */
    private $groupIds;

    /**
     * Creates a new instance of DiscourseApi.
     * @param string $url Base url to the discourse api
     * @param string $username Username used to access the discourse api
     * @param string $key The api key for the user
     */
    public function __construct($url, $username, $key)
    {
        $this->client = new DiscourseClient($url, $username, $key);
    }

    /**
     * Syncs the given SSO payload with Discourse creating or updating the user.
     * @param SingleSignOn The payload data to synchronize with Discourse
     * @return array Discourse user information
     */
    public function syncUser(SingleSignOn $sso)
    {
        return $this->client->syncSso([
            'sso_secret'  => $sso->getSsoSecret(),
            'name'        => $sso['name'],
            'username'    => $sso['username'],
            'email'       => $sso['email'],
            'external_id' => $sso['external_id']
        ]);
    }

    /**
     * Adds user to a new or existing Discourse user group.
     * @param string $groupName Name of the group
     * @param string $username Name of the user to add to the group
     * @return array The api response to adding user a to a group
     */
    public function addGroupUser($groupName, $username)
    {
        $groupId = $this->getGroupId($groupName);
        return $this->client->groupAdd($groupId, ['username' => $username]);
    }

    /**
     * Removes user from a Discourse user group.
     * @param string $groupName Name of the group
     * @param string $username Name of the user to remove from the group
     * @return array The api response to removing user from a group
     */
    public function removeGroupUser($groupName, $username)
    {
        $groupId = $this->getGroupId($groupName);
        return $this->client->groupRemove($groupId, $username);
    }

    /**
     * Returns the ID for the group and creates the group if it does not exist.
     * @param string $groupName Name of the group
     * @return int Id for the group
     */
    public function getGroupId($groupName)
    {
        $list = $this->getGroupIds();

        if (!isset($list[$groupName])) {
            try {
                $group = $this->client->createGroup(['name' => $groupName, 'visible' => false]);
                $this->groupIds[$group['basic_group']['name']] = $group['basic_group']['id'];
            } catch (ClientException $exception) {
                // 422 means the group already exists (it was probably created by a parallel request)
                if ($exception->getResponse()->getStatusCode() !== 422) {
                    throw $exception;
                }

                $this->groupIds = null;
            }

            $list = $this->getGroupIds();
        }

        if (!isset($list[$groupName])) {
            throw new \UnexpectedValueException("Failed to create a group '$groupName'");
        }

        return $list[$groupName];
    }

    /**
     * Returns ids for all the groups.
     * @return int[] List of IDs for all groups according to their name
     */
    public function getGroupIds()
    {
        if (!isset($this->groupIds)) {
            $this->groupIds = [];

            foreach ($this->client->groups() as $group) {
                $this->groupIds[$group['name']] = (int) $group['id'];
            }
        }

        return $this->groupIds;
    }
}
