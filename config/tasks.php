<?php

return [
    'deadline_risk_days' => (int) env('TASK_DEADLINE_RISK_DAYS', 2),
    'volume_weekly_holidays' => [0, 6],
    'volume_holidays' => [],
];
