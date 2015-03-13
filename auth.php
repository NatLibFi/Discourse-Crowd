<?php

ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', __DIR__ . '/logs/php_error.log');

chdir(__DIR__);

require 'vendor/autoload.php';

$settings = json_decode(file_get_contents('forum_settings.json'), true);
$auth = new Finna\Auth\ForumAuth($settings);

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
