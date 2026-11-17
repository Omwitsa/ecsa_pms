<?php
// Application configuration.
// Database settings can also be supplied through environment variables.
return [
    'app_name' => 'Performance Management System',

    'db' => [
        'host'    => getenv('PMS_DB_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('PMS_DB_PORT') ?: 3306),
        'name'    => getenv('PMS_DB_NAME') ?: 'ecsa_pms',
        'user'    => getenv('PMS_DB_USER') ?: 'newtech',
        'pass'    => getenv('PMS_DB_PASS') !== false ? getenv('PMS_DB_PASS') : 'Admin.123',
        'charset' => 'utf8mb4',
    ],

    // The four perspectives every KPI belongs to (display order).
    // Keep identical to the ENUM values in database/schema.sql.
    'perspectives' => [
        'Finance/Stewardship',
        'Customer-Operations/Membership',
        'Business Process',
        'Organizational Capacity',
    ],

    // Suggested labels when a KPI uses the 1-5 scale (admin can edit per KPI)
    'default_scale_labels' => [
        1 => 'Unsatisfactory',
        2 => 'Needs Improvement',
        3 => 'Meets Expectations',
        4 => 'Exceeds Expectations',
        5 => 'Outstanding',
    ],

    // Final score (0-100%) -> performance band. Checked top to bottom.
    'score_bands' => [
        [90, 'Outstanding'],
        [75, 'Exceeds Expectations'],
        [55, 'Meets Expectations'],
        [35, 'Needs Improvement'],
        [0,  'Unsatisfactory'],
    ],

    // Created by install.php when the users table is empty. CHANGE THE PASSWORD after first login.
    'default_admin' => [
        'name'     => 'Administrator',
        'email'    => 'admin@newtech.com',
        'password' => 'Admin@12345',
    ],
];
