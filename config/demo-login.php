<?php

return [
    'enabled' => env('DEMO_LOGIN_ENABLED', false),
    'accounts' => [
        [
            'label' => 'Admin',
            'email' => env('DEMO_ADMIN_EMAIL'),
            'password' => env('DEMO_ADMIN_PASSWORD'),
            'icon' => 'bi-shield-lock-fill',
        ],
        [
            'label' => 'CEO',
            'email' => env('DEMO_CEO_EMAIL'),
            'password' => env('DEMO_CEO_PASSWORD'),
            'icon' => 'bi-crown-fill',
        ],
        [
            'label' => 'Team Leader',
            'email' => env('DEMO_TEAM_LEADER_EMAIL'),
            'password' => env('DEMO_TEAM_LEADER_PASSWORD'),
            'icon' => 'bi-people-fill',
        ],
    ],
];
