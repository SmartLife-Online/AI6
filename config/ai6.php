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
    'http_hardening' => [
        'trusted_hosts' => env('AI6_HTTP_TRUSTED_HOSTS', 'localhost,127.0.0.1,::1'),
        'trusted_proxies' => env('AI6_HTTP_TRUSTED_PROXIES', ''),
        'session_same_site' => env('AI6_HTTP_SESSION_SAME_SITE', 'lax'),
        'script_asset_path' => '/assets/',
        'csp_directives' => [
            'default-src' => "'self'",
            'script-src' => '{asset-origin}',
            'style-src' => "'self'",
            'img-src' => "'self'",
            'font-src' => "'self'",
            'connect-src' => "'self'",
            'form-action' => "'self'",
            'base-uri' => "'none'",
            'object-src' => "'none'",
            'frame-ancestors' => "'none'",
        ],
    ],
    'agent_security_review_profile' => env('AI6_AGENT_SECURITY_REVIEW_PROFILE', 'fake'),

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
    'process' => [
        'timeout_seconds' => env('AI6_PROCESS_TIMEOUT_SECONDS', '300'),
        'output_limit_bytes' => env('AI6_PROCESS_OUTPUT_LIMIT_BYTES', '1048576'),
        'cancel_grace_milliseconds' => env('AI6_PROCESS_CANCEL_GRACE_MILLISECONDS', '2000'),
        'wrapper_ready_timeout_seconds' => env('AI6_PROCESS_WRAPPER_READY_TIMEOUT_SECONDS', '30'),
        'wrapper_script' => base_path('app/AI6/Shared/Process/control-process-wrapper.sh'),
        'shell_binary' => env('AI6_PROCESS_SHELL_BINARY', '/usr/bin/dash'),
        'setsid_binary' => env('AI6_PROCESS_SETSID_BINARY', '/usr/bin/setsid'),
        'process_group_kill_binary' => env('AI6_PROCESS_GROUP_KILL_BINARY', '/usr/bin/kill'),
        'lock_directory' => env('AI6_EFFECT_LOCK_DIRECTORY', '/var/lib/ai6/managed/effect-locks'),
        'lock_object_count' => env('AI6_EFFECT_LOCK_OBJECT_COUNT', '64'),
        'lock_wait_milliseconds' => env('AI6_EFFECT_LOCK_WAIT_MILLISECONDS', '25000'),
        'lock_owner_uid' => env('AI6_EFFECT_LOCK_OWNER_UID', '0'),
        'server_limits' => [
            'runtime_seconds' => env('AI6_EXECUTION_MAX_RUNTIME_SECONDS', '3600'),
            'output_bytes' => env('AI6_EXECUTION_MAX_OUTPUT_BYTES', '10000000'),
            'process_count' => env('AI6_EXECUTION_MAX_PROCESSES', '64'),
            'file_count' => env('AI6_EXECUTION_MAX_FILES', '1000'),
            'total_bytes' => env('AI6_EXECUTION_MAX_BYTES', '20000000'),
            'artifact_count' => env('AI6_EXECUTION_MAX_ARTIFACTS', '100'),
        ],
        'policies' => [
            'control' => [
                'timeout_seconds' => env('AI6_PROCESS_TIMEOUT_SECONDS', '300'),
                'output_limit_bytes' => env('AI6_PROCESS_OUTPUT_LIMIT_BYTES', '1048576'),
                'allowed_executables' => ['*'],
                'environment_allowlist' => ['PATH', 'HOME', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'GIT_CONFIG_NOSYSTEM', 'GIT_CONFIG_GLOBAL', 'GIT_CONFIG_COUNT', 'GIT_TERMINAL_PROMPT', 'GIT_PAGER', 'GIT_EXTERNAL_DIFF', 'GIT_SSH', 'GIT_SSH_VARIANT', 'AI6_GIT_SSH_BINARY', 'AI6_GIT_SSH_KEY', 'AI6_GIT_KNOWN_HOSTS', 'LC_ALL', 'LANG', 'SYSTEMROOT', 'WINDIR', 'COMSPEC', 'PATHEXT', 'TMP', 'TEMP'],
                'working_roots' => [base_path(), storage_path()],
                'requires_process_group' => true,
                'cancel_grace_milliseconds' => env('AI6_PROCESS_CANCEL_GRACE_MILLISECONDS', '2000'),
            ],
            'agent' => [
                'timeout_seconds' => env('AI6_AGENT_PROCESS_TIMEOUT_SECONDS', '1800'),
                'output_limit_bytes' => env('AI6_AGENT_PROCESS_OUTPUT_LIMIT_BYTES', '10000000'),
                'allowed_executables' => [PHP_BINARY],
                'environment_allowlist' => ['PATH', 'HOME', 'XDG_CONFIG_HOME', 'TMPDIR', 'AI6_RUNTIME_PROFILE', 'AI6_AUTH_FILE', 'LC_ALL', 'LANG'],
                'working_roots' => [env('AI6_AGENT_EXECUTION_ROOT', '/var/lib/ai6/agent-executions')],
                'requires_process_group' => true,
                'cancel_grace_milliseconds' => env('AI6_AGENT_CANCEL_GRACE_MILLISECONDS', '2000'),
            ],
            'checker' => [
                'timeout_seconds' => env('AI6_CHECKER_PROCESS_TIMEOUT_SECONDS', '900'),
                'output_limit_bytes' => env('AI6_CHECKER_PROCESS_OUTPUT_LIMIT_BYTES', '5000000'),
                'allowed_executables' => [PHP_BINARY, env('AI6_GIT_BINARY', '/usr/bin/git')],
                'environment_allowlist' => ['PATH', 'HOME', 'XDG_CONFIG_HOME', 'TMPDIR', 'AI6_CHECK_PROFILE', 'LC_ALL', 'LANG'],
                'working_roots' => [
                    env('AI6_CHECKER_EXECUTION_ROOT', '/var/lib/ai6/checker-executions'),
                    env('AI6_CHECKER_WORKSPACE_ROOT', '/var/lib/ai6/checker-workspace'),
                ],
                'requires_process_group' => true,
                'cancel_grace_milliseconds' => env('AI6_CHECKER_CANCEL_GRACE_MILLISECONDS', '2000'),
            ],
        ],
    ],
    'run_artifacts' => [
        'root' => env('AI6_RUN_ARTIFACT_ROOT', storage_path('app/ai6/run-artifacts')),
    ],
    'execution_mailboxes' => [
        'version' => 1,
        'max_envelope_bytes' => env('AI6_MAILBOX_MAX_ENVELOPE_BYTES', '12000000'),
        'agent_root' => env('AI6_AGENT_EXECUTION_ROOT', '/var/lib/ai6/agent-executions'),
        'agent_output_root' => env('AI6_AGENT_OUTPUT_ROOT', '/var/lib/ai6/agent-outputs'),
        'checker_root' => env('AI6_CHECKER_EXECUTION_ROOT', '/var/lib/ai6/checker-executions'),
        'checker_output_root' => env('AI6_CHECKER_OUTPUT_ROOT', '/var/lib/ai6/checker-outputs'),
    ],
    'credential_revisions' => [
        'codex_cli' => env('AI6_CODEX_CREDENTIAL_REVISION', ''),
        'grok_cli' => env('AI6_GROK_CREDENTIAL_REVISION', ''),
        'github_copilot_cli' => env('AI6_COPILOT_CREDENTIAL_REVISION', ''),
        'fake' => env('AI6_FAKE_CREDENTIAL_REVISION', 'test-v1'),
    ],
    'git' => [
        'binary' => env('AI6_GIT_BINARY', '/usr/bin/git'),
        'ssh_binary' => env('AI6_GIT_SSH_BINARY', '/usr/bin/ssh'),
        'executable_path' => env('AI6_GIT_EXECUTABLE_PATH', '/usr/local/bin:/usr/bin:/bin'),
        'ssh_wrapper' => base_path('bin/ai6-git-ssh.sh'),
        'execution_home' => env('AI6_GIT_EXECUTION_HOME', storage_path('framework/ai6/git-home')),
        'xdg_config_home' => env('AI6_GIT_XDG_CONFIG_HOME', storage_path('framework/ai6/git-home/xdg')),
        'global_config' => env('AI6_GIT_GLOBAL_CONFIG', storage_path('framework/ai6/git-home/gitconfig')),
        'hooks_path' => env('AI6_GIT_HOOKS_PATH', storage_path('framework/ai6/git-hooks')),
        'allowed_hosts' => env('AI6_GIT_ALLOWED_HOSTS', ''),
        'allowed_remote_paths' => env('AI6_GIT_ALLOWED_REMOTE_PATHS', ''),
        'allowed_ref_patterns' => env('AI6_GIT_ALLOWED_REF_PATTERNS', 'refs/heads/*'),
        'pinned_host_keys' => env('AI6_GIT_PINNED_HOST_KEYS', ''),
    ],
    'control_operations' => [
        'managed_root' => env('AI6_MANAGED_PROJECT_ROOT', '/var/lib/ai6/managed'),
        'key_root' => env('AI6_DEPLOY_KEY_ROOT', '/var/lib/ai6/managed/deploy-keys'),
        'ssh_keygen_binary' => env('AI6_SSH_KEYGEN_BINARY', '/usr/bin/ssh-keygen'),
        'ssh_keygen_wrapper' => base_path('app/AI6/Git/generate-deploy-key.sh'),
        'known_hosts_file' => env('AI6_CONTROL_OPERATION_KNOWN_HOSTS_FILE', '/var/lib/ai6/managed/known_hosts'),
        'managed_ref_allowlist' => env('AI6_CONTROL_OPERATION_MANAGED_REF_ALLOWLIST', 'refs/heads/main'),
        'lease_seconds' => env('AI6_CONTROL_OPERATION_LEASE_SECONDS', '120'),
        'heartbeat_seconds' => env('AI6_CONTROL_OPERATION_HEARTBEAT_SECONDS', '30'),
        'reconciler_seconds' => env('AI6_CONTROL_OPERATION_RECONCILER_SECONDS', '30'),
        'max_attempts' => env('AI6_CONTROL_OPERATION_MAX_ATTEMPTS', '3'),
        'stale_seconds' => env('AI6_CONTROL_OPERATION_STALE_SECONDS', '300'),
        'reconciliation_budget' => env('AI6_CONTROL_OPERATION_RECONCILIATION_BUDGET', '8'),
    ],
    'run_steps' => [
        'lease_seconds' => env('AI6_RUN_STEP_LEASE_SECONDS', '120'),
        'max_attempts' => env('AI6_RUN_STEP_MAX_ATTEMPTS', '3'),
    ],
    'human_requests' => [
        'notification_max_attempts' => env('AI6_HUMAN_REQUEST_NOTIFICATION_MAX_ATTEMPTS', '5'),
        'notification_retry_seconds' => env('AI6_HUMAN_REQUEST_NOTIFICATION_RETRY_SECONDS', '60'),
    ],
    'tickets' => [
        'max_candidates' => env('AI6_TICKET_MAX_CANDIDATES', '100'),
    ],
    'instruction_patch_max_bytes' => env('AI6_MAX_INSTRUCTION_PATCH_BYTES', '1000000'),
    'agent_input_limits' => [
        'max_instruction_files' => 16,
        'max_instruction_file_bytes' => 262144,
        'max_instruction_total_bytes' => 1048576,
        'max_instruction_import_depth' => 8,
        'max_prompt_input_bytes' => 2097152,
    ],
    'manual_prompt_help' => [
        'max_review_answer_bytes' => 262144,
    ],
    'agent_profiles' => [
        'codex-gpt-5.6-terra' => [
            'provider_profile' => 'codex_cli',
            'adapter' => 'codex_cli',
            'models' => ['gpt-5.6-terra'],
            'efforts' => ['low', 'medium', 'high', 'xhigh', 'max'],
            'roles' => ['implementation', 'quality_review', 'finding_verification', 'security_review'],
            'capability_status' => 'unchecked',
            'runtime_profile' => 'codex-cli-v1',
        ],
        'grok-cli-review' => [
            'provider_profile' => 'grok_cli',
            'adapter' => 'grok_cli',
            'models' => ['provider_default'],
            'efforts' => ['provider_default'],
            'roles' => ['quality_review', 'finding_verification', 'security_review'],
            'capability_status' => 'unchecked',
            'runtime_profile' => 'grok-cli-v1',
        ],
        'copilot-cli-review' => [
            'provider_profile' => 'github_copilot_cli',
            'adapter' => 'github_copilot_cli',
            'models' => ['provider_default'],
            'efforts' => ['provider_default'],
            'roles' => ['quality_review', 'finding_verification', 'security_review'],
            'capability_status' => 'unchecked',
            'runtime_profile' => 'github-copilot-cli-v1',
        ],
        'fake' => [
            'provider_profile' => 'fake',
            'adapter' => 'fake',
            'models' => ['fake-model'],
            'efforts' => ['low', 'medium', 'high', 'provider_default'],
            'roles' => ['implementation', 'quality_review', 'finding_verification', 'security_review'],
            'capability_status' => 'available',
            'runtime_profile' => 'fake-v1',
        ],
    ],
    'instruction_profiles' => [
        'codex_cli' => [
            ['name' => 'agents_md', 'priority' => 10, 'scope' => 'repository'],
            ['name' => 'agents_md_nested', 'priority' => 20, 'scope' => 'nested'],
        ],
        'grok_cli' => [
            ['name' => 'agents_md', 'priority' => 10, 'scope' => 'repository'],
            ['name' => 'agents_md_nested', 'priority' => 20, 'scope' => 'nested'],
        ],
        'github_copilot_cli' => [
            ['name' => 'agents_md', 'priority' => 10, 'scope' => 'repository'],
            ['name' => 'agents_md_nested', 'priority' => 20, 'scope' => 'nested'],
        ],
        'fake' => [
            ['name' => 'agents_md', 'priority' => 10, 'scope' => 'repository'],
            ['name' => 'agents_md_nested', 'priority' => 20, 'scope' => 'nested'],
        ],
    ],
    'provider_runtime_profiles' => [
        'codex-cli-v1' => [
            'version' => 1,
            'adapter_flags' => [],
            'permissions' => ['network' => false, 'workspace' => 'read_only'],
            'extensions' => [
                'mcp_servers' => [],
                'plugins' => [],
                'skills' => [],
                'hooks' => [],
                'commands' => [],
                'agent_definitions' => [],
                'external_helpers' => [],
            ],
        ],
        'grok-cli-v1' => [
            'version' => 1,
            'adapter_flags' => [],
            'permissions' => ['network' => false, 'workspace' => 'read_only'],
            'extensions' => [
                'mcp_servers' => [],
                'plugins' => [],
                'skills' => [],
                'hooks' => [],
                'commands' => [],
                'agent_definitions' => [],
                'external_helpers' => [],
            ],
        ],
        'github-copilot-cli-v1' => [
            'version' => 1,
            'adapter_flags' => [],
            'permissions' => ['network' => false, 'workspace' => 'read_only'],
            'extensions' => [
                'mcp_servers' => [],
                'plugins' => [],
                'skills' => [],
                'hooks' => [],
                'commands' => [],
                'agent_definitions' => [],
                'external_helpers' => [],
            ],
        ],
        'fake-v1' => [
            'version' => 1,
            'adapter_flags' => [],
            'permissions' => ['network' => false, 'workspace' => 'read_only'],
            'extensions' => [
                'mcp_servers' => [],
                'plugins' => [],
                'skills' => [],
                'hooks' => [],
                'commands' => [],
                'agent_definitions' => [],
                'external_helpers' => [],
            ],
        ],
    ],
    /*
     * The trusted definition of every check profile (CFG-003).
     *
     * A managed project selects a name from here and can define neither the
     * program nor an argument. Each profile names an executable that must also
     * be allowed by the checker process policy, a plain argument list, the
     * phases it may run in, its working directory, the exit codes that count as
     * success, and its declared side effect, network and mutation behaviour.
     *
     * `working_directory` is `tree` for the exported project tree and `batch`
     * for its parent, which additionally holds the always empty `baseline`
     * directory. The exported tree carries no Git metadata (GIT-010), so
     * `git diff --check` cannot run inside it; the repo-less `--no-index` form
     * against that empty baseline is the working variant, and its clean exit
     * code is 1, not 0.
     */
    'checks' => [
        'runtime' => [
            'workspace_root' => env('AI6_CHECKER_WORKSPACE_ROOT', '/var/lib/ai6/checker-workspace'),
            'unshare_binary' => env('AI6_CHECKER_UNSHARE_BINARY', '/usr/bin/unshare'),
            'namespace_wrapper' => env('AI6_CHECKER_NAMESPACE_WRAPPER', base_path('app/AI6/Shared/Process/checker-process-wrapper.sh')),
            'poll_interval_seconds' => env('AI6_CHECKER_POLL_INTERVAL_SECONDS', '2'),
            'heartbeat_interval_seconds' => env('AI6_CHECKER_EXECUTION_HEARTBEAT_INTERVAL_SECONDS', '2'),
            'heartbeat_max_age_seconds' => env('AI6_CHECKER_EXECUTION_HEARTBEAT_MAX_AGE_SECONDS', '15'),
            'execution_deadline_seconds' => env('AI6_CHECKER_EXECUTION_DEADLINE_SECONDS', '1200'),
            'attestation_max_age_seconds' => env('AI6_CHECKER_ATTESTATION_MAX_AGE_SECONDS', '15'),
        ],
        'profiles' => [
            'php-targeted' => [
                'program' => PHP_BINARY,
                'arguments' => ['artisan', 'test', '--compact'],
                'phases' => ['before_review'],
                'working_directory' => 'tree',
                'success_exit_codes' => [0],
                'side_effects' => false,
                'network' => false,
                'mutates' => false,
            ],
            'php-all' => [
                'program' => PHP_BINARY,
                'arguments' => ['artisan', 'test'],
                'phases' => ['final'],
                'working_directory' => 'tree',
                'success_exit_codes' => [0],
                'side_effects' => false,
                'network' => false,
                'mutates' => false,
            ],
            /*
             * Whole-tree hygiene, deliberately not a shipped default.
             *
             * The repo-less `--no-index` form compares the exported tree
             * against an always empty baseline, so every file counts as newly
             * added and every pre-existing whitespace error in the repository
             * fails the check — not only the change of this run. That is usable
             * as an explicitly selected hygiene profile, but it would make a
             * default `final` gate unpassable for any project with legacy
             * whitespace, so `server_defaults.checks.final` does not name it.
             *
             * Binding the check to the change difference instead needs the run
             * base tree next to the current one; materialising it belongs to
             * the ticket that owns the checkpoint diff, not here.
             */
            'git-diff-check' => [
                'program' => env('AI6_GIT_BINARY', '/usr/bin/git'),
                'arguments' => ['--no-pager', 'diff', '--check', '--no-index', '--', 'baseline', 'tree'],
                'phases' => ['before_review', 'final'],
                'working_directory' => 'batch',
                // 1 means "differences, no whitespace errors" and is the clean
                // case here; 3 means whitespace errors or conflict markers.
                'success_exit_codes' => [0, 1],
                'side_effects' => false,
                'network' => false,
                'mutates' => false,
            ],
        ],
    ],
    'project_config' => [
        'dependency_satisfied_status_allowlist' => ['review', 'done'],
        'server_maxima' => [
            'max_fix_rounds' => 10,
            'max_review_rounds' => 10,
            'max_verification_rounds' => 10,
            'max_agent_invocations' => 100,
            'max_added_scope_paths' => 100,
            'max_changed_files' => 1000,
            'max_changed_bytes' => 20000000,
            'max_artifacts' => 100,
            'max_artifact_bytes' => 20000000,
            'max_total_artifact_bytes' => 100000000,
            'max_provider_output_bytes' => 10000000,
            'max_run_minutes' => 1440,
        ],
        'server_defaults' => [
            'version' => 1,
            'tickets_path' => 'tickets',
            'ticket_validation_profile' => 'generic_v1',
            'push_mode' => 'manual',
            'auto_start_next' => false,
            'dependency_satisfied_statuses' => ['done'],
            'defaults' => [
                'implementation_profile' => 'codex-gpt-5.6-terra',
                'implementation_effort' => 'medium',
                'reviewers' => [
                    ['profile' => 'grok-cli-review', 'effort' => 'provider_default'],
                ],
            ],
            'limits' => [
                'max_fix_rounds' => 3,
                'max_review_rounds' => 4,
                'max_verification_rounds' => 2,
                'max_agent_invocations' => 20,
                'max_added_scope_paths' => 12,
                'max_changed_files' => 40,
                'max_changed_bytes' => 2000000,
                'max_artifacts' => 20,
                'max_artifact_bytes' => 5000000,
                'max_total_artifact_bytes' => 20000000,
                'max_provider_output_bytes' => 2000000,
                'max_run_minutes' => 180,
            ],
            'scope' => [
                'unlisted_paths' => 'auto_allow',
                'auto_allow' => ['app/**', 'resources/**', 'tests/**'],
                'require_approval' => ['AGENTS.md', 'CLAUDE.md', '.ai6/**', 'tickets/**'],
            ],
            'checks' => [
                'before_review' => ['php-targeted'],
                // `git-diff-check` stays available but unselected: against the
                // empty baseline it reports every pre-existing whitespace error
                // in the repository, which no project with legacy code could
                // pass as a mandatory final gate. See the profile comment.
                'final' => ['php-all'],
            ],
        ],
    ],
    'ticket_mutations' => [
        'git_author_name' => env('AI6_TICKET_MUTATION_GIT_AUTHOR_NAME', 'AI6 Control Worker'),
        'git_author_email' => env('AI6_TICKET_MUTATION_GIT_AUTHOR_EMAIL', 'ai6-control@example.invalid'),
    ],
];
