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

namespace Finna\Auth\Discourse;

use GuzzleHttp\Exception\ClientException;

class DiscourseApi
{
    private $client;
    private $groupIds;

    public function __construct($url, $username, $key)
    {
        $this->client = new DiscourseClient($url, $username, $key);
    }

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

    public function addGroupUser($groupName, $username)
    {
        $groupId = $this->getGroupId($groupName);
        return $this->client->groupAdd($groupId, ['username' => $username]);
    }

    public function removeGroupUser($groupName, $username)
    {
        $groupId = $this->getGroupId($groupName);
        return $this->client->groupRemove($groupId, $username);
    }

    public function getGroupId($groupName)
    {
        $list = $this->getGroupIds();

        if (!isset($list[$groupName])) {
            try {
                $group = $this->client->createGroup(['name' => $groupName , 'visible' => 'false']);
                $this->groupIds[$group['basic_group']['name']] = $group['basic_group']['id'];
            } catch (ClientException $exception) {
                if ($exception->getResponse()->getStatusCode() !== 422) {
                    throw $exception;
                }

                $this->groupIds = null;
            }

            $list = $this->getGroupIds();
        }

        if (!isset($list[$groupName])) {
            throw new \UnexpectedValueException("The group '$groupName' was not be created");
        }

        return $list[$groupName];
    }

    public function getGroupIds()
    {
        if (!isset($this->groupIds)) {
            $this->groupIds = [];

            foreach ($this->client->groups() as $group) {
                $this->groupIds[$group['name']] = $group['id'];
            }
        }

        return $this->groupIds;
    }
}
