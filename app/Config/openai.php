<?php
declare(strict_types=1);

return [
    'api_key' => 'YOUR_OPENAI_API_KEY',
    'endpoint' => 'https://api.openai.com/v1/chat/completions',
    'model' => 'gpt-4.1-mini',
    'temperature' => 0.25,
    'max_completion_tokens' => 6000,
    'connect_timeout_seconds' => 20,
    'request_timeout_seconds' => 180,
];
