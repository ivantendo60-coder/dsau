<?php
/**
 * DSAU Admissions Portal - configuration.
 * Edit the values below to match your local MySQL / XAMPP / WAMP / Laragon setup.
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'dsau_portal',
        'user'    => 'root',
        'pass'    => '',          // XAMPP/WAMP default is an empty password
        'charset' => 'utf8mb4',
    ],

    // Folder the project is served from, WITHOUT trailing slash.
    // http://localhost/dsau-portal  ->  '/dsau-portal'
    // If you serve it from the web root (php -S localhost:8000), use ''.
    'base_url' => '/dsau-portal',
    // Public site origin used to build absolute links in admin password-reset emails.
    'app_url' => 'http://localhost',

    'app_name'  => 'Digital Shift Academy Uganda',
    'app_short' => 'DSAU',
    'tagline'   => 'Empowering the Next Digital Generation',

    // Logos. If assets/img/logo.png (and/or logow.png) exist locally they are used first.
    // Otherwise the logos are loaded straight from the live website.
    'logo_url'       => 'https://digitalshiftacademyug.com/logo.png',   // for light backgrounds
    'logo_white_url' => 'https://digitalshiftacademyug.com/logow.png',  // for dark backgrounds

    'currency'          => 'UGX',
    'timezone'          => 'Africa/Kampala',
    'admissions_email'  => 'admissions@digitalshiftacademyug.com',
    'whatsapp_number'   => '256740738815',
    'phone_numbers'     => '0740 738815 / 0782 021374',
    'website'           => 'https://digitalshiftacademyug.com',

    // SMTP settings for applicant notification emails.
    'mail' => [
        'smtp_host'       => '',
        'smtp_port'       => 587,
        'smtp_encryption' => 'tls', // tls, ssl, or none
        'smtp_username'   => '',
        'smtp_password'   => '',
        'from_email'      => 'admissions@digitalshiftacademyug.com',
        'from_name'       => 'Digital Shift Academy Uganda',
    ],

    // Admin login protection: max failed attempts per IP within the window (minutes)
    'login_max_attempts' => 5,
    'login_window_min'   => 15,
];
