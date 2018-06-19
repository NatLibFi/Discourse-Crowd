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

namespace NatLibFi\Discourse;

use NatLibFi\Discourse\Crowd\CrowdApi;
use NatLibFi\Discourse\Discourse\DiscourseApi;
use NatLibFi\Discourse\Discourse\SingleSignOn;

/**
 * Processes Discourse Authentication requests
 */
class ForumAuth
{
    /** @var string|null Path to the authentication log file or null for none */
    private $logFile;

    /** @var array Settings for the authentication handler */
    private $settings;

    /** @var CrowdApi The Crowd api access */
    private $crowd;

    /** @var DiscourseApi The Discourse api access */
    private $discourse;

    /** @var string[] Full names for canonized group names */
    private $fullNames;

    /** @var string Path to the file that stores the full names cache */
    private $fullNamesFile;

    /**
     * Creates a new instance of ForumAuth.
     * @param array $settings Settings for the authentication handler
     */
    public function __construct(array $settings)
    {
        $this->fullNamesFile = __DIR__ . '/../data/full-names.php';
        $this->fullNames = $this->loadFullNames();
        $this->logFile = empty($settings['authLog']) ? null : strftime($settings['authLog']);
        $this->settings = $settings;
        $this->crowd = new CrowdApi($settings['crowdUrl'], $settings['crowdUsername'], $settings['crowdPassword']);
        $this->discourse = new DiscourseApi(
            $settings['discourseUrl'],
            $settings['discourseUsername'],
            $settings['discourseKey']
        );
    }

    /**
     * Writes the full names cache file on destruct.
     */
    public function __destruct()
    {
        ksort($this->fullNames);

        if (!file_exists($this->fullNamesFile) && is_writable(dirname($this->fullNamesFile))) {
            touch($this->fullNamesFile);
        }

        if (is_writable($this->fullNamesFile)) {
            file_put_contents(
                $this->fullNamesFile,
                '<?php return ' . var_export($this->fullNames, true) . ';',
                LOCK_EX
            );
        }
    }

    /**
     * Loads the full names from the full names cache.
     * @return string[] Full group names loaded from the cache
     */
    private function loadFullNames()
    {
        if (is_readable($this->fullNamesFile)) {
            $data = include $this->fullNamesFile;

            if (is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Returns the full name for the canonized group name.
     * @param string $group The canonized group name
     * @return string The full name for the gorup
     */
    public function getFullName($group)
    {
        return isset($this->fullNames[$group]) ? $this->fullNames[$group] : '-';
    }

    /**
     * Returns an error message that gives reason to authentication failure.
     * @return string Reason for authentication failure
     */
    public function getAuthenticationError()
    {
        return $this->crowd->getAuthenticationError();
    }

    /**
     * Processes the SSO authentication request received from Discourse.
     * @param string $sso The payload parameter received from Discourse
     * @param string $sig The signature parameter received from the Discourse
     * @return bool True if the user was authenticated, false if not
     */
    public function processSsoRequest($sso, $sig)
    {
        $this->log('Received authentication request');
        $payload = http_build_query(['sso' => $sso, 'sig' => $sig], '', '&', PHP_QUERY_RFC3986);
        $sso = SingleSignOn::parse($payload, $this->settings['ssoSecret']);

        // Attempt to authenticate the user using existing crowd login token
        $username = $this->crowd->authenticateCookie();

        if ($username !== null) {
            return $this->loginUser($username, $sso);
        }

        $returnUrl = sprintf(
            '%s?%s',
            $this->settings['ssoUrl'],
            http_build_query(['ssoPayload' => base64_encode($sso->getPayload())], '', '&', PHP_QUERY_RFC3986)
        );

        $this->log('Redirecting to authentication portal');
        $query = http_build_query(['redirectTo' => $returnUrl], '', '&', PHP_QUERY_RFC3986);
        header(sprintf('Location: %s?%s', $this->settings['crowdLoginUrl'], $query), true, 302);

        return false;
    }

    /**
     * Processes the request when returning from crowd login
     * @param string $payload The full Discourse payload returned from the crowd login
     * @return bool True if the user was authenticated, false if not
     */
    public function processSsoResponse($payload)
    {
        $this->log('Received authentication response');
        $sso = SingleSignOn::parse(base64_decode($payload), $this->settings['ssoSecret']);
        $username = $this->crowd->authenticateCookie();

        if ($username === null) {
            $this->log('Authentication failed: ' . $this->crowd->getAuthenticationError());
            return false;

        }

        return $this->loginUser($username, $sso);
    }

    /**
     * Synchronizes the logged in user with Discourse and forwards back to the forum
     * @param string $username The username of the logged in user
     * @param SingleSignOn $sso The SSO payload from Discourse
     * @return bool True if the user was authenticated, false if not
     */
    private function loginUser($username, SingleSignOn $sso)
    {
        $crowdUser = $this->crowd->getUser($username);

        // For security reasons, ensure that these flags are not set
        unset($sso['admin']);
        unset($sso['moderator']);

        $sso['external_id'] = $username;
        $sso['email'] = $crowdUser['email'];
        $sso['username'] = $crowdUser['first-name'];
        // We may already have the last name in the first name, so check for that
        $fullName = $crowdUser['first-name'];
        $lastInFirst = strncmp(
            strrev($crowdUser['first-name']),
            strrev($crowdUser['last-name']),
            strlen($crowdUser['last-name'])
        );
        if ($lastInFirst !== 0) {
            $fullName .= ' ' . $crowdUser['last-name'];
        }
        $sso['name'] = $fullName;

        $discourseUser = $this->discourse->syncUser($sso);
        $this->log(sprintf("Authenticated '%s' to '%s'", $username, $discourseUser['username']));

        $crowdGroups = $this->getCanonizedCrowdGroups($username);
        $discourseGroups = $this->getDiscourseCrowdGroups($discourseUser);

        // Add user to missing groups
        foreach (array_diff($crowdGroups, $discourseGroups) as $group) {
            $this->log(sprintf(
                "Adding user '%s' to group '%s' (%s)",
                $discourseUser['username'],
                $group,
                $this->getFullName($group)
            ));

            $this->discourse->addGroupUser($group, $discourseUser['username']);
        }

        // Remove user from groups that the user does not belong to
        foreach (array_diff($discourseGroups, $crowdGroups) as $group) {
            $this->log(sprintf(
                "Removing user '%s' from group '%s' (%s)",
                $discourseUser['username'],
                $group,
                $this->getFullName($group)
            ));

            $this->discourse->removeGroupUser($group, $discourseUser['username']);
        }

        header(sprintf('Location: %s?%s', $this->settings['ssoCallbackUrl'], $sso->getPayload()), true, 302);
        return true;
    }

    /**
     * Returns Crowd user groups for the user formatted to be compatible with Discourse.
     * @param string $username Crowd username for the user
     * @return string[] List of formatted of Crowd user group names
     */
    private function getCanonizedCrowdGroups($username)
    {
        $groups = $this->crowd->getUserGroups($username);
        $canonized = $this->settings['groupShortName']
            ? array_map([$this, 'canonizeShortName'], $groups)
            : array_map([$this, 'canonizeLongName'], $groups);

        $this->fullNames = $this->fullNames + array_combine($canonized, $groups);

        return $canonized;
    }

    /**
     * Formats group name into short string that always contains a hash.
     * @param string $name Name of the group
     * @return string Formatted name of the group
     */
    private function canonizeShortName($name)
    {
        $canon = substr(preg_replace("/[^A-Za-z0-9]/", "", $name), 0, $this->settings['groupTruncateLength']);
        $prefixed = $this->settings['groupPrefix'] . $canon;
        $hashLength = $this->settings['groupMaxLength'] - strlen($prefixed);

        return $prefixed . substr(hash('md2', $name), 0, $hashLength);
    }

    /**
     * Formats group name in to a longer string that is only truncated if necessary.
     * @param string $name Name of the group
     * @return string Formatted name of the group
     */
    private function canonizeLongName($name)
    {
        $canon = preg_replace('/[^0-9a-z_]/', '', preg_replace('/[- ]/', '_', strtolower($name)));
        $prefixed = $this->settings['groupPrefix'] . $canon;

        if (strlen($prefixed) > $this->settings['groupMaxLength']) {
            $canon = substr($canon, 0, $this->settings['groupTruncateLength']);
            $prefixed = $this->settings['groupPrefix'] . $canon;
            $hashLength = $this->settings['groupMaxLength'] - strlen($prefixed) - 1;

            return $prefixed . '_' . substr(md5($name), 0, $hashLength);
        }

        return $prefixed;
    }

    /**
     * Returns all Discourse user groups that are managed by the SSO login
     * @param array $user The Discourse user data
     * @return string[] List of managed Discourse user group names
     */
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

    /**
     * Writes message to the authentication log.
     * @param string $message The message to write
     * @return bool True if the message was written, false if not
     */
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
