<?php

return [
    'runtime_role' => env('AI6_RUNTIME_ROLE', ''),
    'security' => [
        'profile' => env('AI6_SECURITY_PROFILE', 'strict'),
        'acknowledge_reduced_mode' => env('AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE', 'false'),
        'measures' => [
            'AI6_SECURITY_LOGIN_EMAIL_CONFIRMATION' => env('AI6_SECURITY_LOGIN_EMAIL_CONFIRMATION', 'true'),
            'AI6_SECURITY_REQUIRE_PRIVILEGED_PASSKEY' => env('AI6_SECURITY_REQUIRE_PRIVILEGED_PASSKEY', 'true'),
            'AI6_SECURITY_REQUIRE_CRITICAL_ACTION_STEP_UP' => env('AI6_SECURITY_REQUIRE_CRITICAL_ACTION_STEP_UP', 'true'),
            'AI6_SECURITY_REQUIRE_HTTPS_OR_PRIVATE_ACCESS' => env('AI6_SECURITY_REQUIRE_HTTPS_OR_PRIVATE_ACCESS', 'true'),
            'AI6_SECURITY_REQUIRE_AGENT_SANDBOX' => env('AI6_SECURITY_REQUIRE_AGENT_SANDBOX', 'true'),
            'AI6_SECURITY_REQUIRE_CHECKER_NETWORK_ISOLATION' => env('AI6_SECURITY_REQUIRE_CHECKER_NETWORK_ISOLATION', 'true'),
            'AI6_SECURITY_REQUIRE_LLM_PRECOMMIT_REVIEW' => env('AI6_SECURITY_REQUIRE_LLM_PRECOMMIT_REVIEW', 'true'),
        ],
    ],
    'redaction' => [
        'environment' => env('APP_ENV', 'production'),
        'active_key_id' => env('AI6_REDACTION_ACTIVE_KEY_ID', 'app-key-v1'),
        'keys' => env('AI6_REDACTION_KEYS', ''),
        'app_key' => env('APP_KEY', ''),
    ],
];
