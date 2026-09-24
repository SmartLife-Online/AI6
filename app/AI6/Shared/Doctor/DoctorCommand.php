<?php

namespace App\AI6\Shared\Doctor;

use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityProfile;
use Illuminate\Console\Command;

final class DoctorCommand extends Command
{
    protected $signature = 'ai6:doctor
        {--security : Prüft die evidenzgebundenen Sicherheitsmaßnahmen.}
        {--all-processes : Prüft die beobachtbaren Laufzeitrollen.}
        {--require-strict : Verlangt das strict-Sicherheitsprofil.}';

    protected $description = 'Prüft die aufgelöste AI6-Konfiguration und ihre sicheren Betriebsgrundlagen.';

    /**
     * @param  iterable<DoctorCheck>  $checks
     */
    public function __construct(
        private readonly iterable $checks,
        private readonly SecurityPolicy $policy,
        private readonly SecurityReviewProfileDoctorCheck $securityReview,
        private readonly ProcessRolesDoctorCheck $processRoles,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $passed = true;
        /** @var array<string, DoctorCheckResult> $results */
        $results = [];

        foreach ($this->checks as $check) {
            $results[$check->label()] = $this->printResult($check);
            $passed = $passed && $results[$check->label()]->passed;
        }

        if ($this->option('security')) {
            $securityResult = $this->printResult($this->securityReview);
            $results[$this->securityReview->label()] = $securityResult;
            $passed = $passed && $securityResult->passed;
            $passed = $this->printSecurityMeasureResults($results) && $passed;
        }

        if ($this->option('all-processes')) {
            $processResult = $this->printResult($this->processRoles);
            $passed = $passed && $processResult->passed;
        }

        if ($this->option('require-strict')) {
            $strict = $this->policy->profile === SecurityProfile::STRICT;
            $this->line('Strict-Profil: '.($strict ? 'OK' : 'FEHLER'));
            if (! $strict) {
                $this->line('  Profil: '.$this->policy->profile->value);
                $this->line('  Deaktivierte Maßnahmen: '.$this->disabledMeasureNames());
            } else {
                $this->line('  Profil: strict');
            }
            $passed = $strict && $passed;
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function printResult(DoctorCheck $check): DoctorCheckResult
    {
        $result = $check->run();
        $this->line(sprintf('%s: %s', $check->label(), $result->passed ? 'OK' : 'FEHLER'));

        foreach ($result->details as $name => $value) {
            $this->line(sprintf('  %s: %s', $name, $value));
        }

        return $result;
    }

    /** @param array<string, DoctorCheckResult> $results */
    private function printSecurityMeasureResults(array $results): bool
    {
        $bindings = [
            SecurityMeasure::LOGIN_EMAIL_CONFIRMATION->value => ['Mail'],
            SecurityMeasure::REQUIRE_CHECKER_NETWORK_ISOLATION->value => ['Checker-Laufzeit'],
            SecurityMeasure::REQUIRE_AGENT_SANDBOX->value => ['Codex-CLI', 'Grok-CLI', 'GitHub-Copilot-CLI'],
            SecurityMeasure::REQUIRE_LLM_PRECOMMIT_REVIEW->value => ['Securityreview-Profil'],
        ];
        $passed = true;

        foreach ($bindings as $measureValue => $labels) {
            $measure = SecurityMeasure::from($measureValue);
            if (! $this->policy->isEnabled($measure)) {
                continue;
            }

            foreach ($labels as $label) {
                $result = $results[$label] ?? null;
                if ($result?->passed === true) {
                    continue;
                }

                $passed = false;
                $reason = $result === null
                    ? 'Prüfung nicht ausgeführt'
                    : ($result->details['Fehler'] ?? 'Prüfung nicht bestanden');
                $this->line(sprintf('  Sicherheitsmaßnahme %s: FEHLER (%s: %s)', $measure->value, $label, $reason));
            }
        }

        return $passed;
    }

    private function disabledMeasureNames(): string
    {
        $names = array_map(
            static fn (SecurityMeasure $measure): string => $measure->value,
            $this->policy->disabledMeasures(),
        );

        return $names === [] ? 'keine' : implode(', ', $names);
    }
}
