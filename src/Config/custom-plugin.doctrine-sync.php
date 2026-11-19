<?php

/*
 * Following doctrines from the doctrine site (doctrine.nisuwaz.com).
 *
 * Both values live in the server environment only. With either one missing,
 * importing is off and the Doctrine Fits page works as before.
 */
return [
    // base address the server reads from, e.g. the internal http://host:port
    'url' => env('CUSTOM_PLUGIN_DOCTRINE_URL'),
    // read-only token the doctrine site accepts as "Authorization: Bearer"
    'token' => env('CUSTOM_PLUGIN_DOCTRINE_TOKEN'),
];
