<?php

return [
    'token' => [
        'access_token_expiration' => 60, // in minutes
        'refresh_token_expiration' => 10, // in days
        'secret_key' => "GGPoDl2y3ayUszNnw/wQQ8++RR5r89poozLQOc8t4OM="
    ],
    'redis' => [
        'expiration' => 15 * 24 * 60 * 60, // in seconds (15 days)
    ],
];
