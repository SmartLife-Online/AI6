<?php

namespace App\AI6\Shared;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\InstructionProfileRegistry;
use App\AI6\Agents\InstructionSnapshotResolver;
use App\AI6\Agents\ModelProfileAllowlist;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Auth\AuthenticationHmac;
use App\AI6\Auth\Config\AuthConfiguration;
use App\AI6\Auth\Config\AuthConfigurationFactory;
use App\AI6\Auth\LbuchsPasskeyCeremony;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\PasskeyCeremony;
use App\AI6\Auth\PasskeyRelyingParty;
use App\AI6\Auth\PasskeyRelyingPartyFactory;
use App\AI6\Auth\Policies\UserPolicy;
use App\AI6\Checks\CheckProfileAllowlist;
use App\AI6\Git\CanonicalDiffHasher;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationConfigurationFactory;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Git\ControlOperationRuntimeIdentityFactory;
use App\AI6\Git\ControlRemoteProbe;
use App\AI6\Git\GitConfiguration;
use App\AI6\Git\GitConfigurationFactory;
use App\AI6\Git\GitRemotePolicy;
use App\AI6\Git\HardenedControlRemoteProbe;
use App\AI6\Git\HardenedGitEnvironment;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\IsolatedTreeExporter;
use App\AI6\Git\KnownHostsVerifier;
use App\AI6\Git\RunCheckpointService;
use App\AI6\Git\RunHistoryContext;
use App\AI6\Git\RunPatchImporter;
use App\AI6\Git\RunTreeService;
use App\AI6\Git\RunWorkspaceLifecycle;
use App\AI6\Git\TicketMutationConfiguration;
use App\AI6\Git\TicketMutationConfigurationFactory;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Prompts\PromptCatalog;
use App\AI6\Prompts\PromptRenderer;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Runs\ApprovalSelectionFactory;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\ApprovalStatusPage;
use App\AI6\Runs\InstructionCandidateCollector;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\RunTransitionMap;
use App\AI6\Runs\TicketApprovalPage;
use App\AI6\Runs\WaitReasonRegistry;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\StrictEnumParser;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;
use App\AI6\Shared\Doctor\DoctorCommand;
use App\AI6\Shared\Doctor\RedactionKeyringDoctorCheck;
use App\AI6\Shared\Doctor\SecurityPolicyDoctorCheck;
use App\AI6\Shared\Http\HttpSecurityConfiguration;
use App\AI6\Shared\Http\HttpSecurityConfigurationFactory;
use App\AI6\Shared\Markdown\AllowedHtmlPolicy;
use App\AI6\Shared\Markdown\SafeMarkdownRenderer;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\ProcessConfiguration;
use App\AI6\Shared\Process\ProcessConfigurationFactory;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionKeyringFactory;
use App\AI6\Shared\Redaction\RedactionPolicy;
use App\AI6\Shared\Redaction\RedactionRuleSet;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityPolicyFactory;
use App\AI6\Shared\Yaml\RestrictedYaml;
use App\AI6\Tickets\DependencySatisfiedStatusAllowlist;
use App\AI6\Tickets\Livewire\TicketDetail;
use App\AI6\Tickets\Livewire\TicketList;
use App\AI6\Tickets\TicketValidationConfiguration;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use LogicException;
use PragmaRX\Google2FA\Google2FA;

final class AI6ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RestrictedYaml::class);
        $this->app->singleton(CheckProfileAllowlist::class, static fn (): CheckProfileAllowlist => CheckProfileAllowlist::fromConfiguredValues());
        $this->app->singleton(
            AgentInputLimits::class,
            static fn (Application $app): AgentInputLimits => AgentInputLimits::fromConfiguredValues(
                $app->make(StrictPositiveIntegerParser::class),
            ),
        );
        $this->app->singleton(
            AgentProfileRegistry::class,
            static fn (Application $app): AgentProfileRegistry => AgentProfileRegistry::fromConfiguredValues(
                $app->make(StrictEnumParser::class),
            ),
        );
        $this->app->singleton(
            ProviderRuntimeProfileRegistry::class,
            static fn (Application $app): ProviderRuntimeProfileRegistry => ProviderRuntimeProfileRegistry::fromConfiguredValues(
                $app->make(StrictPositiveIntegerParser::class),
                $app->make(CanonicalJson::class),
            ),
        );
        $this->app->singleton(InstructionProfileRegistry::class);
        $this->app->singleton(InstructionSnapshotResolver::class);
        $this->app->singleton(ModelProfileAllowlist::class);
        $this->app->singleton(PromptCatalog::class, static fn (): PromptCatalog => PromptCatalog::defaults());
        $this->app->singleton(PromptRenderer::class);
        $this->app->singleton(CanonicalDiffHasher::class);
        $this->app->singleton(IsolatedTreeExporter::class);
        $this->app->singleton(RunWorkspaceLifecycle::class);
        $this->app->singleton(RunCheckpointService::class);
        $this->app->singleton(RunTreeService::class);
        $this->app->singleton(RunHistoryContext::class);
        $this->app->singleton(RunPatchImporter::class);
        $this->app->singleton(ReviewerSlotFactory::class);
        $this->app->singleton(ApprovalSelectionFactory::class);
        $this->app->singleton(ApprovalSnapshotFactory::class);
        $this->app->singleton(RunTransitionMap::class);
        $this->app->singleton(WaitReasonRegistry::class);
        $this->app->singleton(InstructionCandidateCollector::class);
        $this->app->singleton(InstructionCandidateSource::class, InstructionCandidateCollector::class);
        $this->app->singleton(DependencySatisfiedStatusAllowlist::class, static fn (): DependencySatisfiedStatusAllowlist => DependencySatisfiedStatusAllowlist::fromConfiguredValues());
        $this->app->singleton(EffectiveProjectConfiguration::class);
        $this->app->singleton(
            TicketValidationConfiguration::class,
            static fn (Application $app): TicketValidationConfiguration => TicketValidationConfiguration::fromConfiguredValues(
                $app->make(StrictPositiveIntegerParser::class),
            ),
        );
        $this->app->singleton(AuthConfigurationFactory::class);
        $this->app->singleton(
            AuthConfiguration::class,
            static fn (Application $app): AuthConfiguration => $app->make(AuthConfigurationFactory::class)->fromConfiguredValues(),
        );
        $this->app->singleton(SecurityPolicyFactory::class);
        $this->app->singleton(HttpSecurityConfigurationFactory::class);
        $this->app->singleton(
            HttpSecurityConfiguration::class,
            static fn (Application $app): HttpSecurityConfiguration => $app
                ->make(HttpSecurityConfigurationFactory::class)
                ->fromConfiguredValues(),
        );
        $this->app->singleton(Google2FA::class);
        $this->app->singleton(PasskeyRelyingPartyFactory::class);
        $this->app->singleton(
            PasskeyRelyingParty::class,
            static fn (Application $app): PasskeyRelyingParty => $app->make(PasskeyRelyingPartyFactory::class)->fromConfiguredValues(),
        );
        $this->app->singleton(PasskeyCeremony::class, LbuchsPasskeyCeremony::class);
        $this->app->singleton(
            AuthenticationHmac::class,
            static function (Application $app): AuthenticationHmac {
                $encrypter = $app->make('encrypter');

                if (! $encrypter instanceof Encrypter) {
                    throw new LogicException('The application encrypter must expose its key for domain-separated authentication bindings.');
                }

                return new AuthenticationHmac($encrypter);
            },
        );
        $this->app->singleton(
            SecurityPolicy::class,
            static fn (Application $app): SecurityPolicy => $app->make(SecurityPolicyFactory::class)->fromConfiguredValues(),
        );
        $this->app->singleton(RedactionKeyringFactory::class);
        $this->app->singleton(
            RedactionKeyring::class,
            static fn (Application $app): RedactionKeyring => $app->make(RedactionKeyringFactory::class)->fromConfiguredValues(),
        );
        $this->app->singleton(
            RedactionPolicy::class,
            static fn (): RedactionPolicy => new RedactionPolicy(RedactionRuleSet::defaults()),
        );
        $this->app->singleton(
            RedactionFingerprintGenerator::class,
            static fn (Application $app): RedactionFingerprintGenerator => new RedactionFingerprintGenerator(
                $app->make(RedactionKeyring::class),
            ),
        );
        $this->app->singleton(
            Redactor::class,
            static fn (Application $app): Redactor => new Redactor(
                $app->make(RedactionPolicy::class),
                $app->make(RedactionFingerprintGenerator::class),
            ),
        );
        $this->app->singleton(ProcessConfigurationFactory::class);
        $this->app->singleton(
            ProcessConfiguration::class,
            static fn (Application $app): ProcessConfiguration => $app->make(ProcessConfigurationFactory::class)->fromConfiguredValues(),
        );
        $this->app->singleton(
            EffectLock::class,
            static fn (Application $app): EffectLock => new EffectLock($app->make(ProcessConfiguration::class)),
        );
        $this->app->singleton(
            ControlProcessRunner::class,
            static fn (Application $app): ControlProcessRunner => new ControlProcessRunner(
                $app->make(ProcessConfiguration::class),
                $app->make(Redactor::class),
                $app->make(EffectLock::class),
            ),
        );
        $this->app->singleton(GitConfigurationFactory::class);
        $this->app->singleton(
            ControlOperationConfigurationFactory::class,
            static fn (Application $app): ControlOperationConfigurationFactory => new ControlOperationConfigurationFactory(
                $app->make(ProcessConfiguration::class),
            ),
        );
        $this->app->singleton(
            ControlOperationConfiguration::class,
            static fn (Application $app): ControlOperationConfiguration => $app
                ->make(ControlOperationConfigurationFactory::class)
                ->fromConfiguredValues(),
        );
        $this->app->singleton(TicketMutationConfigurationFactory::class);
        $this->app->singleton(
            TicketMutationConfiguration::class,
            static fn (Application $app): TicketMutationConfiguration => $app
                ->make(TicketMutationConfigurationFactory::class)
                ->fromConfiguredValues(),
        );
        $this->app->singleton(ControlOperationRuntimeIdentityFactory::class);
        $this->app->singleton(
            ControlOperationRuntimeIdentity::class,
            static fn (Application $app): ControlOperationRuntimeIdentity => $app
                ->make(ControlOperationRuntimeIdentityFactory::class)
                ->fromConfiguredValues(),
        );
        $this->app->singleton(
            GitConfiguration::class,
            static fn (Application $app): GitConfiguration => $app->make(GitConfigurationFactory::class)->fromConfiguredValues(),
        );
        $this->app->singleton(KnownHostsVerifier::class);
        $this->app->singleton(
            GitRemotePolicy::class,
            static fn (Application $app): GitRemotePolicy => new GitRemotePolicy(
                $app->make(GitConfiguration::class),
                $app->make(KnownHostsVerifier::class),
            ),
        );
        $this->app->singleton(
            HardenedGitEnvironment::class,
            static fn (Application $app): HardenedGitEnvironment => new HardenedGitEnvironment($app->make(GitConfiguration::class)),
        );
        $this->app->singleton(
            HardenedGitRunner::class,
            static fn (Application $app): HardenedGitRunner => new HardenedGitRunner(
                $app->make(ControlProcessRunner::class),
                $app->make(GitRemotePolicy::class),
                $app->make(HardenedGitEnvironment::class),
            ),
        );
        $this->app->singleton(
            ControlRemoteProbe::class,
            static fn (Application $app): ControlRemoteProbe => new HardenedControlRemoteProbe(
                $app->make(ControlOperationConfiguration::class),
                $app->make(HardenedGitRunner::class),
            ),
        );
        $this->app->singleton(AllowedHtmlPolicy::class);
        $this->app->singleton(
            MarkdownConverter::class,
            static function (): MarkdownConverter {
                $environment = new Environment([
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                    'max_nesting_level' => 32,
                    'max_delimiters_per_line' => 1000,
                ]);
                $environment->addExtension(new CommonMarkCoreExtension);

                return new MarkdownConverter($environment);
            },
        );
        $this->app->singleton(
            SafeMarkdownRenderer::class,
            static fn (Application $app): SafeMarkdownRenderer => new SafeMarkdownRenderer(
                $app->make(Redactor::class),
                $app->make(MarkdownConverter::class),
                $app->make(AllowedHtmlPolicy::class),
            ),
        );
        $this->app->singleton(
            DoctorCommand::class,
            static fn (Application $app): DoctorCommand => new DoctorCommand([
                new SecurityPolicyDoctorCheck($app->make(SecurityPolicy::class)),
                new RedactionKeyringDoctorCheck($app->make(RedactionKeyringFactory::class)),
            ]),
        );

        $this->app->make(SecurityPolicy::class);
        $agentProfiles = $this->app->make(AgentProfileRegistry::class);
        $runtimeProfiles = $this->app->make(ProviderRuntimeProfileRegistry::class);
        $instructionProfiles = $this->app->make(InstructionProfileRegistry::class);
        $this->app->make(AgentInputLimits::class);
        foreach ($agentProfiles->all() as $agentProfile) {
            $runtimeProfiles->get($agentProfile->runtimeProfileId);
            $instructionProfiles->get($agentProfile->providerProfileAlias);
        }
        $authConfiguration = $this->app->make(AuthConfiguration::class);
        $httpConfiguration = $this->app->make(HttpSecurityConfiguration::class);
        config([
            'session.lifetime' => $authConfiguration->sessionLifetimeMinutes,
            'session.expire_on_close' => true,
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => $httpConfiguration->sessionSameSite,
        ]);

        if (! $this->mayBootstrapWithoutRedactionKeyring()) {
            $this->app->make(RedactionKeyring::class);
        }
    }

    public function boot(Gate $gate): void
    {
        $gate->policy(User::class, UserPolicy::class);
        $gate->policy(Project::class, ProjectPolicy::class);

        self::assertLivewireContentSecurityConfiguration();

        // The fixed CSP binds script-src to the server-side "/assets/" path;
        // the Livewire bundle therefore loads exclusively through this
        // hash-bound route while inline asset injection stays disabled.
        Livewire::setScriptRoute(
            /** @param  array{class-string, string}  $handle */
            static fn (array $handle): RegisteredRoute => Route::get('/assets/livewire/livewire.js', $handle),
        );
        self::neutralizeUnusedLivewireEndpoints();

        Livewire::component('ai6.tickets.ticket-list', TicketList::class);
        Livewire::component('ai6.tickets.ticket-detail', TicketDetail::class);
        Livewire::component('ai6.runs.ticket-approval', TicketApprovalPage::class);
        Livewire::component('ai6.runs.approval-status', ApprovalStatusPage::class);
    }

    /**
     * Livewire registers its endpoints under an APP_KEY-derived prefix during
     * package boot. AI6 uses exactly one of them — the CSRF-protected update
     * endpoint. Every other package endpoint (default bundle, source maps,
     * per-component JS/CSS modules, file upload and preview) is unused and
     * re-registered here with the same URI so it answers 404 instead of
     * exposing surface; the bundle loads exclusively through the hash-bound
     * "/assets/" route. The public route inventory test pins this contract.
     */
    private static function neutralizeUnusedLivewireEndpoints(): void
    {
        foreach ([
            EndpointResolver::scriptPath(minified: false),
            EndpointResolver::scriptPath(minified: true),
            EndpointResolver::mapPath(csp: false),
            EndpointResolver::mapPath(csp: true),
            EndpointResolver::componentJsPath(),
            EndpointResolver::componentCssPath(),
            EndpointResolver::componentGlobalCssPath(),
            EndpointResolver::previewPath(),
        ] as $unusedEndpoint) {
            Route::get($unusedEndpoint, static fn () => abort(404));
        }

        Route::post(EndpointResolver::uploadPath(), static fn () => abort(404));
    }

    public static function assertLivewireContentSecurityConfiguration(): void
    {
        if (config('livewire.csp_safe') !== true) {
            throw new ConfigurationException(
                'Configuration key livewire.csp_safe must stay enabled: the regular Livewire bundle requires unsafe-eval, which the fixed AI6 CSP never grants.',
            );
        }

        if (config('livewire.inject_assets') !== false) {
            throw new ConfigurationException(
                'Configuration key livewire.inject_assets must stay disabled: assets load only as external files from the bound asset path.',
            );
        }

        if (config('livewire.navigate.show_progress_bar') !== false) {
            throw new ConfigurationException(
                'Configuration key livewire.navigate.show_progress_bar must stay disabled: AI6 does not permit the Livewire progress bar or its runtime style.',
            );
        }
    }

    private function mayBootstrapWithoutRedactionKeyring(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }

        $arguments = $_SERVER['argv'] ?? [];
        $command = null;
        $skipOptionValue = false;

        if (is_array($arguments)) {
            foreach (array_slice($arguments, 1) as $argument) {
                if (! is_string($argument)) {
                    continue;
                }

                if ($skipOptionValue) {
                    $skipOptionValue = false;

                    continue;
                }

                if ($argument === '--env') {
                    $skipOptionValue = true;

                    continue;
                }

                if (! str_starts_with($argument, '-')) {
                    $command = $argument;

                    break;
                }
            }
        }

        if (in_array($command, ['key:generate', 'package:discover', 'test'], true)) {
            return true;
        }

        return $command === 'migrate' && config('ai6.runtime_role') === 'init';
    }
}
