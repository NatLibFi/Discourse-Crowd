<?php

namespace Finna\Auth\Crowd;

use GuzzleHttp\Client;
use GuzzleHttp\Stream\Stream;

/**
 * @author Riikka Kalliomäki <riikka.kalliomaki@helsinki.fi>
 * @copyright Copyright 2015
 */
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
