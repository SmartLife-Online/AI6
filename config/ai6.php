<?php

return [
    'runtime_role' => env('AI6_RUNTIME_ROLE', ''),
    'auth' => [
        'login_max_attempts' => env('AI6_AUTH_LOGIN_MAX_ATTEMPTS', '5'),
        'login_decay_seconds' => env('AI6_AUTH_LOGIN_DECAY_SECONDS', '60'),
        'session_lifetime_minutes' => env('AI6_AUTH_SESSION_LIFETIME_MINUTES', '120'),
        'login_confirmation_ttl_seconds' => env('AI6_AUTH_LOGIN_CONFIRMATION_TTL_SECONDS', '600'),
        'login_confirmation_max_attempts' => env('AI6_AUTH_LOGIN_CONFIRMATION_MAX_ATTEMPTS', '5'),
        'strong_authentication_max_attempts' => env('AI6_AUTH_STRONG_AUTHENTICATION_MAX_ATTEMPTS', '5'),
        'strong_authentication_decay_seconds' => env('AI6_AUTH_STRONG_AUTHENTICATION_DECAY_SECONDS', '300'),
        'login_confirmation_resend_cooldown_seconds' => env('AI6_AUTH_LOGIN_CONFIRMATION_RESEND_COOLDOWN_SECONDS', '30'),
        'step_up_window_seconds' => env('AI6_AUTH_STEP_UP_WINDOW_SECONDS', '300'),
        'enrollment_ttl_seconds' => env('AI6_AUTH_ENROLLMENT_TTL_SECONDS', '900'),
        'login_confirmation_email' => env('AI6_LOGIN_CONFIRMATION_EMAIL'),
    ],
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
