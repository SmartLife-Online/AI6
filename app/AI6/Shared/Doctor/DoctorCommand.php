<?php

namespace App\AI6\Shared\Doctor;

use Illuminate\Console\Command;

final class DoctorCommand extends Command
{
    protected $signature = 'ai6:doctor';

    protected $description = 'Prüft die aufgelöste AI6-Konfiguration und ihre sicheren Betriebsgrundlagen.';

    /** @param iterable<DoctorCheck> $checks */
    public function __construct(private readonly iterable $checks)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $passed = true;

        foreach ($this->checks as $check) {
            $result = $check->run();
            $passed = $passed && $result->passed;
            $this->line(sprintf('%s: %s', $check->label(), $result->passed ? 'OK' : 'FEHLER'));

            foreach ($result->details as $name => $value) {
                $this->line(sprintf('  %s: %s', $name, $value));
            }
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
