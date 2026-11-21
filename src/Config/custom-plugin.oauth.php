<?php

/*
 * Sign-in with SeAT for the plugin owner's own applications
 * (OAuth 2.0 authorization code + PKCE).
 *
 * The applications are listed in one JSON file, for example a Docker Swarm
 * config or secret mounted into the SeAT containers. Without the file, or with
 * a file that breaks any rule (see README), the /custom-plugin/oauth/*
 * addresses answer 404.
 */
return [
    'clients_file' => env('CUSTOM_PLUGIN_OAUTH_CLIENTS_FILE'),
];
