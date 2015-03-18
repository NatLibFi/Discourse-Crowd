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

ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', __DIR__ . '/logs/php_error.log');

chdir(__DIR__);

require 'vendor/autoload.php';

$settings = json_decode(file_get_contents('forum_settings.json'), true);
$auth = new NatLibFi\Discourse\ForumAuth($settings);

try {
    if (isset($_GET['sso']) && isset($_GET['sig'])) {
        $auth->processSsoRequest($_GET['sso'], $_GET['sig']);
    } elseif (isset($_GET['ssoPayload'])) {
        $auth->processSsoResponse($_GET['ssoPayload']);
    } else {
        exit('Unexpected authentication request');
    }
} catch (Exception $exception) {
    echo
        'Error occurred during authentication. Please try again or contact ' .
        'an administrator, if the problem persists';

    throw $exception;
}
