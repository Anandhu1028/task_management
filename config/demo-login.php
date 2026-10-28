<?php

return [
    'enabled' => env('DEMO_LOGIN_ENABLED', false),
    'password' => env('DEMO_LOGIN_PASSWORD'),
    'accounts' => [
        [
            'label' => 'Admin',
            'email' => env('DEMO_ADMIN_EMAIL'),
        ],
        [
            'label' => 'CEO',
            'email' => env('DEMO_CEO_EMAIL'),
        ],
        [
            'label' => 'Team Leader',
            'email' => env('DEMO_TEAM_LEADER_EMAIL'),
        ],
    ],
];
