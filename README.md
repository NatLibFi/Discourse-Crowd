# Single Sign On Authentication #

This package implements single sign on using the Crowd api to different web
applications.

## Discourse Authentication ##

To set up the Discourse single sign on authentication, you must first enable it
in the Discourse admin panel. Instructions for enabling the single sign on can
be found on the discourse website:

https://meta.discourse.org/t/official-single-sign-on-for-discourse/13045

The `sso_url` should point to the `auth.php` in this package. The `sso_secret`
can be any value you choose, but it must be kept secret and it must be reflected
in the configuration of this single sign on package.

### Configuration ###

The configuration settings are read from the `forum_settings.json` file. Make
a copy of the sample file and rename it without the 'sample' suffix. The 
settings file contains the following configuration options:

  * `authLog` contains a path that stores authentication logs. The path is
    path accepts `strftime()` tokens. Set to null to disable logging.
    
  * `groupPrefix` is the prefix for group names imported from Crowd.
  * `groupMaxLength` is the maximum length of group names (Discourse 1.3 allows
    up to 20 characters).
  * `groupTruncateLength` is the maximum length of a truncated group name.
    
  * `ssoSecret` must be set to the same value as `sso_secret` in the admin panel.
  * `ssoUrl` is the URL path to the `auth.php` file.
  * `ssoCallbackUrl` is the URL path to the `/session/sso_login` url in Discourse.
    
  * `discourseUrl` indicates the base URL to the discourse api.
  * `discourseUsername` is the username used by api calls (usually 'system').
  * `discourseKey` is the api key for the username.
    
  * `crowdUsername` is the username used to authenticate to the Crowd api
  * `crowdPassword` is the password for the Crowd api user
  * `crowdUrl` is the base URL to the crowd api
  * `crowdLoginUrl` is the URL to the login page used to login into the Crowd

## Resources ##

  * [Crowd REST API documentation](https://developer.atlassian.com/display/CROWDDEV/Crowd+REST+Resources)
  * [Discourse Single Sign On documentation](https://meta.discourse.org/t/official-single-sign-on-for-discourse/13045)
  * [Discourse API documentation](https://meta.discourse.org/t/discourse-api-documentation/22706)
  * [Discourse API reference implementation](https://github.com/discourse/discourse_api)
