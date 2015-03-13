<?php

namespace Finna\Auth\Discourse;

use GuzzleHttp\Exception\ClientException;

/**
 * @author Riikka Kalliomäki <riikka.kalliomaki@helsinki.fi>
 * @copyright Copyright 2015
 */
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
                $group = $this->client->createGroup(['name' => $groupName]);
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
