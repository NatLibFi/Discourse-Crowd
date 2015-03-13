<?php

namespace Finna\Auth;

use Finna\Auth\Crowd\CrowdApi;
use Finna\Auth\Discourse\DiscourseApi;
use Finna\Auth\Discourse\SingleSignOn;

class ForumAuth
{
    private $logFile;
    private $settings;
    private $crowd;
    private $discourse;

    public function __construct(array $settings)
    {
        $this->logFile = empty($settings['authLog']) ? null : strftime($settings['authLog']);
        $this->settings = $settings;
        $this->crowd = new CrowdApi($settings['crowdUrl'], $settings['crowdUsername'], $settings['crowdPassword']);
        $this->discourse = new DiscourseApi(
            $settings['discourseUrl'],
            $settings['discourseUsername'],
            $settings['discourseKey']
        );
    }

    private function query(array $params)
    {
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function processSsoRequest($sso, $sig)
    {
        $this->log("Received authentication request");

        $payload = $this->query(['sso' => $sso, 'sig' => $sig]);
        $sso = SingleSignOn::parse($payload, $this->settings['ssoSecret']);
        $username = $this->crowd->authenticateCookie();

        if ($username !== null) {
            return $this->loginUser($username, $sso);
        }

        $query = $this->query(['redirectTo' => sprintf(
            '%s?%s',
            $this->settings['ssoUrl'],
            $this->query(['ssoPayload' => base64_encode($sso->getPayload())])
        )]);

        $this->log("Redirecting to authentication portal");
        header(sprintf('Location: %s?%s', $this->settings['crowdLoginUrl'], $query), true, 302);

        return false;
    }

    public function processSsoResponse($payload)
    {
        $this->log("Received authentication response");

        $sso = SingleSignOn::parse(base64_decode($payload), $this->settings['ssoSecret']);
        $username = $this->crowd->authenticateCookie();

        if ($username === null) {
            $this->log("Authentication to failed");
            return false;
        }

        return $this->loginUser($username, $sso);
    }

    private function loginUser($username, SingleSignOn $sso)
    {
        $crowdUser = $this->crowd->getUser($username);

        // Prevent privilege escalation, if the attacker knows the sso secret
        unset($sso['admin']);
        unset($sso['moderator']);

        $sso['external_id'] = $username;
        $sso['email'] = $crowdUser['email'];
        $sso['username'] = $crowdUser['first-name'];
        $sso['name'] = sprintf('%s %s', $crowdUser['first-name'], $crowdUser['last-name']);

        $discourseUser = $this->discourse->syncUser($sso);
        $this->log(sprintf("Authenticated '%s' to '%s'", $username, $discourseUser['username']));

        $crowdGroups = $this->getCanonizedCrowdGroups($username);
        $discourseGroups = $this->getDiscourseCrowdGroups($discourseUser);

        foreach (array_diff($crowdGroups, $discourseGroups) as $group) {
            $this->log(sprintf("Adding user '%s' to group '%s'", $discourseUser['username'], $group));
            $this->discourse->addGroupUser($group, $discourseUser['username']);
        }

        foreach (array_diff($discourseGroups, $crowdGroups) as $group) {
            $this->log(sprintf("Removing user '%s' from group '%s'", $discourseUser['username'], $group));
            $this->discourse->removeGroupUser($group, $discourseUser['username']);
        }

        header(sprintf('Location: %s?%s', $this->settings['ssoCallbackUrl'], $sso->getPayload()), true, 302);
        return true;
    }

    private function getCanonizedCrowdGroups($username)
    {
        return array_map([$this, 'canonizeGroupName'], $this->crowd->getUserGroups($username));
    }

    private function canonizeOld($name)
    {
        $canon = substr(preg_replace("/[^A-Za-z0-9]/", "", $name), 0, 8);
        $prefixed = $this->settings['groupPrefix'] . $canon;
        $hashLength = 15 - strlen($prefixed);

        return $prefixed . substr(hash('md2', $name), 0, $hashLength);
    }

    private function canonizeGroupName($name)
    {
        $canon = preg_replace('/[^0-9a-z_]/', '', preg_replace('/[- ]/', '_', strtolower($name)));
        $prefixed = $this->settings['groupPrefix'] . $canon;

        if (strlen($prefixed) > $this->settings['groupMaxLength']) {
            $prefixed = substr($prefixed, 0, -(1 + $this->settings['groupHashLength']));
            $hash = base_convert(md5($canon), 16, 36);
            $prefixed .= '_' . substr($hash, 0, $this->settings['groupHashLength']);
        }

        return $prefixed;
    }

    private function getDiscourseCrowdGroups(array $user)
    {
        $groups = [];

        foreach ($user['groups'] as $group) {
            $groups[] = $group['name'];
        }

        $prefix = $this->settings['groupPrefix'];
        $length = strlen($prefix);

        return array_filter($groups, function ($name) use ($prefix, $length) {
            return strncmp($name, $prefix, $length) === 0;
        });
    }

    private function log($message)
    {
        if (!isset($this->logFile)) {
            return false;
        }

        return (bool) file_put_contents(
            $this->logFile,
            sprintf('[%s] %s (%s)' . PHP_EOL, date('c'), $message, $_SERVER['REMOTE_ADDR']),
            FILE_APPEND + LOCK_EX
        );
    }
}
