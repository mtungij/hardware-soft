<?php

return [
    'url' => rtrim((string) env('GOWA_URL', 'https://notify.buildcore.site'), '/'),
    'username' => env('GOWA_USERNAME'),
    'password' => env('GOWA_PASSWORD'),
    'timeout' => (int) env('GOWA_TIMEOUT', 30),
    'connect_timeout' => (int) env('GOWA_CONNECT_TIMEOUT', 10),
    'number_check_ttl' => (int) env('GOWA_NUMBER_CHECK_TTL', 86400),
];
