<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    */
    // 'default' => env('MAIL_MAILER', app()->environment('local') ? 'gmail' : 'sendgrid'),
    'default' => env('MAIL_MAILER', env('APP_ENV') === 'local' ? 'gmail' : 'sendgrid'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    */
    'mailers' => [

        // Localhost: Gmail SMTP
        'gmail' => [
            'transport' => 'smtp',
            'host' => env('GMAIL_MAIL_HOST', 'smtp.gmail.com'),
            'port' => env('GMAIL_MAIL_PORT', 587),
            'encryption' => env('GMAIL_MAIL_ENCRYPTION', 'tls'),
            'username' => env('GMAIL_MAIL_USERNAME'),
            'password' => env('GMAIL_MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        // Production: SendGrid SMTP
        'sendgrid' => [
            'transport' => 'smtp',
            'host' => env('SENDGRID_MAIL_HOST', 'smtp.sendgrid.net'),
            'port' => env('SENDGRID_MAIL_PORT', 587),
            'encryption' => env('SENDGRID_MAIL_ENCRYPTION', 'tls'),
            'username' => env('SENDGRID_MAIL_USERNAME', 'apikey'),
            'password' => env('SENDGRID_MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        // Keep your existing smtp mailer (optional fallback)
        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port' => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'mailgun' => [
            'transport' => 'mailgun',
        ],

        'postmark' => [
            'transport' => 'postmark',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        // optional failover: try sendgrid then log
        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'sendgrid',
                'log',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    */
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Markdown Mail Settings
    |--------------------------------------------------------------------------
    */
    'markdown' => [
        'theme' => 'default',
        'paths' => [
            resource_path('views/vendor/mail'),
        ],
    ],

];