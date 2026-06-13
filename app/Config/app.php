<?php
declare(strict_types=1);

return [
    // Keep server-side timestamps consistent. Browser messages use the user's local time.
    'timezone' => 'UTC',

    'max_input_characters' => 100000,
    'authorized_download_limit' => 20,

    'rate_limit' => [
        // Five valid generation submissions per IP during a one-hour window.
        'max_attempts' => 5,
        'window_seconds' => 3600,

        // Used to hash IP addresses before storing them in MySQL.
        // Replace this with your own long random string before production use.
        'hash_secret' => '2027c2ff0ffe19428b06a2df4dac319bb2b2ee67c4f977667609552e78e81ee8',

        // Enable only when the website is definitely proxied through Cloudflare.
        // When false, REMOTE_ADDR is used and cannot be spoofed through request headers.
        'trust_cloudflare_ip_header' => false,
    ],
];
