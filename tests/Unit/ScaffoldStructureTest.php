<?php

namespace Tests\Unit;

use App\AI6\Shared\AI6Marker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

final class ScaffoldStructureTest extends TestCase
{
    private const MODULES = [
        'Agents',
        'Auth',
        'Checks',
        'Git',
        'HumanLoop',
        'Projects',
        'Prompts',
        'Reviews',
        'Runs',
        'Shared',
        'Tickets',
    ];

    public function test_modules_and_marker_use_the_existing_app_autoload_mapping(): void
    {
        self::assertTrue(class_exists(AI6Marker::class));

        $modules = array_values(array_filter(
            scandir($this->path('app/AI6')) ?: [],
            static fn (string $entry): bool => ! in_array($entry, ['.', '..'], true),
        ));
        sort($modules);

        self::assertSame(self::MODULES, $modules);

        $composer = $this->readJson($this->path('composer.json'));
        self::assertSame('app/', $composer['autoload']['psr-4']['App\\'] ?? null);
        self::assertArrayNotHasKey('App\\AI6\\', $composer['autoload']['psr-4']);
    }

    public function test_ai6_modules_contain_only_the_approved_files_through_ai6_006c(): void
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path('app/AI6'), RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($this->path()) + 1));
            }
        }

        sort($files);
        self::assertSame([
            'app/AI6/Agents/.gitkeep',
            'app/AI6/Auth/.gitkeep',
            'app/AI6/Auth/Actions/CreateUser.php',
            'app/AI6/Auth/Actions/DeactivateUser.php',
            'app/AI6/Auth/Actions/DeleteUser.php',
            'app/AI6/Auth/Actions/GrantGlobalAdministrator.php',
            'app/AI6/Auth/Actions/LastActiveAdministratorGuard.php',
            'app/AI6/Auth/Actions/RemoveProjectMembership.php',
            'app/AI6/Auth/Actions/RevokeGlobalAdministrator.php',
            'app/AI6/Auth/Actions/RevokeUserSession.php',
            'app/AI6/Auth/Actions/SetProjectMembership.php',
            'app/AI6/Auth/AuthenticationAudit.php',
            'app/AI6/Auth/AuthenticationHmac.php',
            'app/AI6/Auth/AuthenticationSession.php',
            'app/AI6/Auth/Base64Url.php',
            'app/AI6/Auth/CannotRemoveLastAdministrator.php',
            'app/AI6/Auth/Config/AuthConfiguration.php',
            'app/AI6/Auth/Config/AuthConfigurationFactory.php',
            'app/AI6/Auth/Console/CreateAdministratorCommand.php',
            'app/AI6/Auth/Console/ReissueRecoveryCodesCommand.php',
            'app/AI6/Auth/EmailNormalizer.php',
            'app/AI6/Auth/EnrollmentRevocationAudit.php',
            'app/AI6/Auth/EnrollmentSessionManager.php',
            'app/AI6/Auth/Http/AdministrativeController.php',
            'app/AI6/Auth/Http/EnrollmentController.php',
            'app/AI6/Auth/Http/EnsureActiveUser.php',
            'app/AI6/Auth/Http/EnsureCompletedAuthentication.php',
            'app/AI6/Auth/Http/LoginConfirmationController.php',
            'app/AI6/Auth/Http/LoginController.php',
            'app/AI6/Auth/Http/PrimaryAuthenticationController.php',
            'app/AI6/Auth/Http/StepUpController.php',
            'app/AI6/Auth/Jobs/SendLoginConfirmationMail.php',
            'app/AI6/Auth/LbuchsPasskeyCeremony.php',
            'app/AI6/Auth/LoginCompletionGate.php',
            'app/AI6/Auth/LoginCompletionOutcome.php',
            'app/AI6/Auth/LoginConfirmationDeliveryStatus.php',
            'app/AI6/Auth/LoginConfirmationIssue.php',
            'app/AI6/Auth/LoginConfirmationManager.php',
            'app/AI6/Auth/LoginConfirmationUnavailableException.php',
            'app/AI6/Auth/LoginConfirmationVerification.php',
            'app/AI6/Auth/Mail/LoginConfirmationMail.php',
            'app/AI6/Auth/Models/AuthenticationAuditEntry.php',
            'app/AI6/Auth/Models/LoginConfirmation.php',
            'app/AI6/Auth/Models/PasskeyCredential.php',
            'app/AI6/Auth/Models/RecoveryCode.php',
            'app/AI6/Auth/Models/TotpCredential.php',
            'app/AI6/Auth/Models/User.php',
            'app/AI6/Auth/Models/UserSession.php',
            'app/AI6/Auth/PasskeyCeremony.php',
            'app/AI6/Auth/PasskeyCeremonyRejectedException.php',
            'app/AI6/Auth/PasskeyChallengeManager.php',
            'app/AI6/Auth/PasskeyManager.php',
            'app/AI6/Auth/PasskeyOptions.php',
            'app/AI6/Auth/PasskeyRegistration.php',
            'app/AI6/Auth/PasskeyRelyingParty.php',
            'app/AI6/Auth/PasskeyRelyingPartyFactory.php',
            'app/AI6/Auth/Policies/PrimaryAuthenticationPolicy.php',
            'app/AI6/Auth/Policies/UserPolicy.php',
            'app/AI6/Auth/PrimaryAuthenticationMethod.php',
            'app/AI6/Auth/RecoveryCodeManager.php',
            'app/AI6/Auth/StepUpGuard.php',
            'app/AI6/Auth/StepUpRequiredException.php',
            'app/AI6/Auth/StrongAuthenticationAttemptLimiter.php',
            'app/AI6/Auth/StrongFactorInventory.php',
            'app/AI6/Auth/TotpManager.php',
            'app/AI6/Auth/TotpSecretAuthenticationException.php',
            'app/AI6/Auth/TotpSecretCipher.php',
            'app/AI6/Checks/.gitkeep',
            'app/AI6/Git/.gitkeep',
            'app/AI6/Git/Actions/QueueDeployKeyProvisioning.php',
            'app/AI6/Git/Actions/RecordRecoveryDecision.php',
            'app/AI6/Git/CanonicalJson.php',
            'app/AI6/Git/CanonicalRequestException.php',
            'app/AI6/Git/ControlOperationAuthorizationSnapshot.php',
            'app/AI6/Git/ControlOperationConfiguration.php',
            'app/AI6/Git/ControlOperationConfigurationFactory.php',
            'app/AI6/Git/ControlOperationConflict.php',
            'app/AI6/Git/ControlOperationExecutor.php',
            'app/AI6/Git/ControlOperationHasher.php',
            'app/AI6/Git/ControlOperationOutcome.php',
            'app/AI6/Git/ControlOperationPhase.php',
            'app/AI6/Git/ControlOperationReconciler.php',
            'app/AI6/Git/ControlOperationRecoveryProcessor.php',
            'app/AI6/Git/ControlOperationRecoveryRequired.php',
            'app/AI6/Git/ControlOperationRetryableConflict.php',
            'app/AI6/Git/ControlOperationRuntimeIdentity.php',
            'app/AI6/Git/ControlOperationRuntimeIdentityFactory.php',
            'app/AI6/Git/ControlOperationState.php',
            'app/AI6/Git/ControlOperationType.php',
            'app/AI6/Git/DeployKeyProvisioner.php',
            'app/AI6/Git/GitConfiguration.php',
            'app/AI6/Git/GitConfigurationFactory.php',
            'app/AI6/Git/GitRemotePolicy.php',
            'app/AI6/Git/GitRemoteRejected.php',
            'app/AI6/Git/HardenedGitEnvironment.php',
            'app/AI6/Git/HardenedGitRunner.php',
            'app/AI6/Git/HostKeyFingerprint.php',
            'app/AI6/Git/Http/ControlOperationController.php',
            'app/AI6/Git/Jobs/ExecuteControlOperation.php',
            'app/AI6/Git/KnownHostsVerifier.php',
            'app/AI6/Git/ManagedProjectPath.php',
            'app/AI6/Git/Models/ControlOperation.php',
            'app/AI6/Git/Models/ControlOperationRecoveryDecision.php',
            'app/AI6/Git/Models/ControlOperationResult.php',
            'app/AI6/Git/ProjectEffectLockName.php',
            'app/AI6/Git/ProjectOperationLease.php',
            'app/AI6/Git/RecoveryDecisionType.php',
            'app/AI6/Git/RecoveryEvidenceReference.php',
            'app/AI6/Git/ValidatedGitRemote.php',
            'app/AI6/Git/generate-deploy-key.sh',
            'app/AI6/HumanLoop/.gitkeep',
            'app/AI6/Projects/.gitkeep',
            'app/AI6/Projects/Actions/RegisterProject.php',
            'app/AI6/Projects/Http/ProjectController.php',
            'app/AI6/Projects/Models/Project.php',
            'app/AI6/Projects/Models/ProjectMembership.php',
            'app/AI6/Projects/Policies/ProjectPolicy.php',
            'app/AI6/Projects/ProjectAction.php',
            'app/AI6/Projects/ProjectIdentifierGenerator.php',
            'app/AI6/Projects/ProjectProvisioningStatus.php',
            'app/AI6/Projects/ProjectRegistrationRejected.php',
            'app/AI6/Projects/ProjectRole.php',
            'app/AI6/Prompts/.gitkeep',
            'app/AI6/Reviews/.gitkeep',
            'app/AI6/Runs/.gitkeep',
            'app/AI6/Shared/AI6Marker.php',
            'app/AI6/Shared/AI6ServiceProvider.php',
            'app/AI6/Shared/Config/ConfigurationException.php',
            'app/AI6/Shared/Config/ConfigurationViolation.php',
            'app/AI6/Shared/Config/StrictBooleanParser.php',
            'app/AI6/Shared/Config/StrictEnumParser.php',
            'app/AI6/Shared/Config/StrictPositiveIntegerParser.php',
            'app/AI6/Shared/Doctor/DoctorCheck.php',
            'app/AI6/Shared/Doctor/DoctorCheckResult.php',
            'app/AI6/Shared/Doctor/DoctorCommand.php',
            'app/AI6/Shared/Doctor/RedactionKeyringDoctorCheck.php',
            'app/AI6/Shared/Doctor/SecurityPolicyDoctorCheck.php',
            'app/AI6/Shared/Http/BlockPersistentLoginCookies.php',
            'app/AI6/Shared/Http/ContentSecurityPolicy.php',
            'app/AI6/Shared/Http/EnforceHttpsOrPrivateAccess.php',
            'app/AI6/Shared/Http/HttpSecurityConfiguration.php',
            'app/AI6/Shared/Http/HttpSecurityConfigurationFactory.php',
            'app/AI6/Shared/Http/ResolveTrustedProxies.php',
            'app/AI6/Shared/Markdown/AllowedHtmlPolicy.php',
            'app/AI6/Shared/Markdown/SafeMarkdownRenderer.php',
            'app/AI6/Shared/Process/BlockedControlProcess.php',
            'app/AI6/Shared/Process/BlockedProcessStartResult.php',
            'app/AI6/Shared/Process/BlockedStartOutcome.php',
            'app/AI6/Shared/Process/ControlProcessRunner.php',
            'app/AI6/Shared/Process/EffectLock.php',
            'app/AI6/Shared/Process/EffectLockAcquisition.php',
            'app/AI6/Shared/Process/EffectLockHandle.php',
            'app/AI6/Shared/Process/EffectLockOutcome.php',
            'app/AI6/Shared/Process/ImmutableRuntimeFile.php',
            'app/AI6/Shared/Process/ProcessConfiguration.php',
            'app/AI6/Shared/Process/ProcessConfigurationFactory.php',
            'app/AI6/Shared/Process/ProcessOutcome.php',
            'app/AI6/Shared/Process/ProcessRequest.php',
            'app/AI6/Shared/Process/ProcessResult.php',
            'app/AI6/Shared/Process/RunningControlProcess.php',
            'app/AI6/Shared/Process/ValidatedLockObject.php',
            'app/AI6/Shared/Process/control-process-wrapper.sh',
            'app/AI6/Shared/Redaction/InvalidRedactionInputException.php',
            'app/AI6/Shared/Redaction/RedactionContext.php',
            'app/AI6/Shared/Redaction/RedactionFingerprint.php',
            'app/AI6/Shared/Redaction/RedactionFingerprintGenerator.php',
            'app/AI6/Shared/Redaction/RedactionKeyring.php',
            'app/AI6/Shared/Redaction/RedactionKeyringFactory.php',
            'app/AI6/Shared/Redaction/RedactionMatch.php',
            'app/AI6/Shared/Redaction/RedactionMatchType.php',
            'app/AI6/Shared/Redaction/RedactionPolicy.php',
            'app/AI6/Shared/Redaction/RedactionResult.php',
            'app/AI6/Shared/Redaction/RedactionRule.php',
            'app/AI6/Shared/Redaction/RedactionRuleSet.php',
            'app/AI6/Shared/Redaction/Redactor.php',
            'app/AI6/Shared/Runtime/RuntimeExecutionMark.php',
            'app/AI6/Shared/Runtime/RuntimeHealthCommand.php',
            'app/AI6/Shared/Runtime/RuntimeHeartbeat.php',
            'app/AI6/Shared/Runtime/RuntimeSelfTestCommand.php',
            'app/AI6/Shared/Runtime/RuntimeSelfTestJob.php',
            'app/AI6/Shared/Security/CanonicalByteFrame.php',
            'app/AI6/Shared/Security/SecurityBannerData.php',
            'app/AI6/Shared/Security/SecurityControlGroup.php',
            'app/AI6/Shared/Security/SecurityMeasure.php',
            'app/AI6/Shared/Security/SecurityPolicy.php',
            'app/AI6/Shared/Security/SecurityPolicyFactory.php',
            'app/AI6/Shared/Security/SecurityPolicyHasher.php',
            'app/AI6/Shared/Security/SecurityProfile.php',
            'app/AI6/Tickets/.gitkeep',
        ], $files);
    }

    #[DataProvider('excludedArtifactProvider')]
    public function test_excluded_scaffold_artifacts_are_absent(string $path): void
    {
        self::assertFileDoesNotExist($this->path($path));
        self::assertDirectoryDoesNotExist($this->path($path));
    }

    /** @return iterable<string, array{string}> */
    public static function excludedArtifactProvider(): iterable
    {
        foreach ([
            'app/Models/User.php',
            'database/database.sqlite',
            'database/factories',
            'database/seeders',
            'package.json',
            'package-lock.json',
            'pnpm-lock.yaml',
            'public/favicon.ico',
            'resources/css',
            'resources/js',
            'resources/views/welcome.blade.php',
            'vite.config.js',
            'yarn.lock',
        ] as $path) {
            yield $path => [$path];
        }
    }

    public function test_composer_contract_has_no_database_or_queue_side_effects(): void
    {
        $composer = $this->readJson($this->path('composer.json'));

        self::assertSame('^8.5', $composer['require']['php'] ?? null);
        self::assertSame('*', $composer['require']['ext-intl'] ?? null);
        self::assertSame('*', $composer['require']['ext-mbstring'] ?? null);
        self::assertSame('*', $composer['require']['ext-openssl'] ?? null);
        self::assertSame('^13.8', $composer['require']['laravel/framework'] ?? null);
        self::assertSame('~2.2.0', $composer['require']['lbuchs/webauthn'] ?? null);
        self::assertSame('~9.0.0', $composer['require']['pragmarx/google2fa'] ?? null);
        self::assertSame('^12.5.12', $composer['require-dev']['phpunit/phpunit'] ?? null);
        self::assertSame('8.5.0', $composer['config']['platform']['php'] ?? null);

        self::assertFalse($this->composerScriptsHaveImplicitSideEffects($composer['scripts'] ?? []));
    }

    public function test_composer_side_effect_detection_rejects_laravel_default_scripts(): void
    {
        $scripts = [
            'post-create-project-cmd' => [
                "@php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\"",
                '@php artisan migrate --graceful --ansi',
            ],
        ];

        self::assertTrue($this->composerScriptsHaveImplicitSideEffects($scripts));
    }

    public function test_environment_example_uses_database_sessions_and_queue_with_file_cache(): void
    {
        $lines = file($this->path('.env.example'), FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        foreach ([
            'SESSION_DRIVER=database',
            'CACHE_STORE=file',
            'QUEUE_CONNECTION=database',
            'AI6_AUTH_LOGIN_MAX_ATTEMPTS=5',
            'AI6_AUTH_LOGIN_DECAY_SECONDS=60',
            'AI6_AUTH_SESSION_LIFETIME_MINUTES=120',
            'AI6_HTTP_TRUSTED_HOSTS=localhost,127.0.0.1,::1',
            'AI6_HTTP_TRUSTED_PROXIES=',
            'AI6_HTTP_SESSION_SAME_SITE=lax',
            'AI6_WORKER_HEARTBEAT_MAX_AGE=75',
        ] as $default) {
            self::assertContains($default, $lines);
        }

        self::assertNotContains('DB_DATABASE=/var/lib/ai6/database/database.sqlite', $lines);
        self::assertNotContains('AI6_EXECUTION_DIRECTORY=/var/lib/ai6/executions', $lines);
    }

    public function test_framework_runtime_files_are_ignored(): void
    {
        foreach ([
            'storage/framework/maintenance.php',
            'storage/app/ai6-local.sqlite',
            'database/database.sqlite',
            'database/database.sqlite-shm',
            'database/database.sqlite-wal',
        ] as $path) {
            $process = new Process(['git', 'check-ignore', '--quiet', $path], $this->path());
            self::assertSame(0, $process->run(), $path.': '.$process->getErrorOutput());
        }
    }

    public function test_provenance_binds_source_allowlist_exclusions_and_protected_files(): void
    {
        $provenance = $this->readJson($this->path('docs/AI6_SCAFFOLD_PROVENANCE.json'));

        self::assertSame('https://github.com/laravel/laravel.git', $provenance['repository'] ?? null);
        self::assertSame('v13.8.0', $provenance['tag'] ?? null);
        self::assertSame('e196bfdfc96903f2e10219749fcbca7c0aefe99f', $provenance['commit'] ?? null);
        self::assertFalse($provenance['retrieval']['composer_scripts_executed'] ?? true);
        self::assertTrue($provenance['retrieval']['commit_verified_before_import'] ?? false);
        self::assertFalse($provenance['retrieval']['recursive_repository_copy'] ?? true);

        $allowlist = $provenance['backend_allowlist'];
        $importedPaths = $provenance['imported_paths'];
        $scaffoldPaths = $provenance['scaffold_file_paths'];
        $adaptedPaths = array_values(array_unique([
            ...$provenance['adapted_paths'],
            'bootstrap/providers.php',
        ]));
        $protectedPaths = array_keys($provenance['protected_base_snapshot']);

        self::assertSame($allowlist, $importedPaths, 'The recorded import must equal the approved backend allowlist.');
        self::assertSame($allowlist, array_values(array_unique($allowlist)), 'The allowlist must not contain duplicates.');
        self::assertSame($scaffoldPaths, array_values(array_unique($scaffoldPaths)), 'The scaffold inventory must not contain duplicates.');

        foreach ($importedPaths as $path) {
            self::assertContains($path, $scaffoldPaths, $path.' is not present in the immutable scaffold tree.');
            self::assertFileExists($this->path($path));
            self::assertFalse(is_link($this->path($path)), $path.' must be a regular imported file.');
            self::assertArrayHasKey($path, $provenance['scaffold_blob_oids']);
        }

        foreach ($provenance['adapted_paths'] as $path) {
            self::assertContains($path, $importedPaths);
        }

        foreach (array_diff($importedPaths, $adaptedPaths) as $path) {
            self::assertSame(
                $provenance['scaffold_blob_oids'][$path],
                $this->gitBlobOid($this->path($path)),
                $path.' differs from the recorded scaffold blob without being marked as adapted.',
            );
        }

        $approvedLaterPaths = [
            'config/auth.php',
            'config/database.php',
            'config/mail.php',
            'config/queue.php',
            'config/session.php',
        ];
        $unexpectedFiles = [];
        foreach ($scaffoldPaths as $path) {
            if (! is_file($this->path($path))) {
                continue;
            }

            if (! in_array($path, $importedPaths, true)
                && ! in_array($path, $protectedPaths, true)
                && ! in_array($path, $approvedLaterPaths, true)
            ) {
                $unexpectedFiles[] = $path;
            }
        }
        self::assertSame([], $unexpectedFiles, 'Unexpected scaffold files exist in the repository.');

        foreach ($scaffoldPaths as $path) {
            if (in_array($path, $importedPaths, true) || in_array($path, $protectedPaths, true)) {
                continue;
            }

            self::assertTrue(
                $this->matchesPathList($path, $provenance['excluded_scaffold_paths']),
                $path.' is neither imported, protected, nor explicitly excluded.',
            );
        }

        foreach ($provenance['excluded_scaffold_paths'] as $path) {
            self::assertFalse($this->matchesPathList($path, $importedPaths));
        }

        foreach ($provenance['protected_base_snapshot'] as $path => $hash) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
            self::assertFileExists($this->path($path));
        }

        self::assertSame(
            $provenance['protected_base_snapshot']['.gitattributes'],
            hash_file('sha256', $this->path('.gitattributes')),
            '.gitattributes differs from the immutable scaffold-protection boundary.',
        );
        self::assertNotSame($provenance['scaffold_readme_sha256'], hash_file('sha256', $this->path('README.md')));
        self::assertSame(
            'Deliberately omitted: AI6 uses Laravel framework configuration defaults at bootstrap; the migrations-free session, cache, and queue defaults are supplied exclusively through .env.example.',
            $provenance['exclusion_reasons']['config/'] ?? null,
        );
    }

    public function test_readme_documents_versions_commands_defaults_and_code_conventions(): void
    {
        $readme = file_get_contents($this->path('README.md'));
        self::assertNotFalse($readme);

        foreach ([
            'PHP-Vertrag: `^8.5`',
            '`config.platform.php: 8.5.0`',
            '`laravel/framework: ^13.8`',
            '`phpunit/phpunit: ^12.5.12`',
            '`v13.8.0`',
            '`e196bfdfc96903f2e10219749fcbca7c0aefe99f`',
            'composer install',
            'composer check-platform-reqs',
            'php artisan test',
            'php vendor/bin/phpunit tests/Unit/LockedInstallTest.php',
            'vendor/bin/pint',
            'vendor/bin/pint --test',
            'vendor/bin/phpstan analyse',
            'composer validate --strict',
            'php scripts/generate-ticket-manifest.php --check',
            'SESSION_DRIVER=file',
            'CACHE_STORE=file',
            'QUEUE_CONNECTION=sync',
            'QUEUE_CONNECTION=database',
            'AI6_PHP85_BINARY',
            'AI6_COMPOSER_PHAR',
            '`app/`, `bootstrap/`, `routes/`, `scripts/` und `tests/`',
            'Der Scaffoldpfad `config/` wurde bewusst nicht übernommen.',
        ] as $requiredText) {
            self::assertStringContainsString($requiredText, $readme);
        }

        self::assertSame(3, preg_match_all('/```php\n(.*?)```/s', $readme, $examples));
        self::assertStringContainsString('final class NormalizeValueAction', $examples[1][0]);
        self::assertStringContainsString('final class ValueNormalizer', $examples[1][1]);
        self::assertStringContainsString('final readonly class NormalizeValueData', $examples[1][2]);

        foreach ($examples[1] as $example) {
            self::assertStringNotContainsString('extends ', $example);
            self::assertStringNotContainsString('abstract class', $example);
            self::assertStringNotContainsString('interface ', $example);
        }
    }

    public function test_migrations_match_the_approved_state_through_ai6_006c(): void
    {
        $migrations = glob($this->path('database/migrations/*.php'));
        self::assertIsArray($migrations);

        $migrations = array_map('basename', $migrations);
        sort($migrations);

        self::assertSame([
            '2026_08_01_000000_create_jobs_table.php',
            '2026_08_01_000001_create_job_batches_table.php',
            '2026_08_01_000002_create_failed_jobs_table.php',
            '2026_08_03_000000_create_users_table.php',
            '2026_08_03_000001_create_projects_table.php',
            '2026_08_03_000002_create_project_memberships_table.php',
            '2026_08_03_000003_create_sessions_table.php',
            '2026_08_03_000004_create_passkey_credentials_table.php',
            '2026_08_03_000005_create_totp_credentials_table.php',
            '2026_08_03_000006_create_recovery_codes_table.php',
            '2026_08_03_000007_create_login_confirmations_table.php',
            '2026_08_03_000008_create_authentication_audit_entries_table.php',
            '2026_08_05_000000_add_registration_metadata_to_projects_table.php',
            '2026_08_06_000000_create_control_operations_table.php',
        ], $migrations);
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $content = file_get_contents($path);
        self::assertNotFalse($content);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $scripts */
    private function composerScriptsHaveImplicitSideEffects(array $scripts): bool
    {
        return preg_match(
            '/database\.sqlite|\bmigrate\b|queue:(?:listen|work|restart)|\btouch\s*\(/i',
            json_encode($scripts, JSON_THROW_ON_ERROR),
        ) === 1;
    }

    private function gitBlobOid(string $path): string
    {
        $content = file_get_contents($path);
        self::assertNotFalse($content);

        return sha1('blob '.strlen($content)."\0".$content);
    }

    /** @param list<string> $patterns */
    private function matchesPathList(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($path === rtrim($pattern, '/') || (str_ends_with($pattern, '/') && str_starts_with($path, $pattern))) {
                return true;
            }
        }

        return false;
    }

    private function path(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
    }
}
