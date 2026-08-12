<?php

return [
    'enabled' => filter_var(env('CAPACITY_PLANNER_V2', true), FILTER_VALIDATE_BOOL),
    'default_location' => env('CAPACITY_PLANNER_DEFAULT_LOCATION', 'ikast'),
    'timezone' => 'Europe/Copenhagen',
];
