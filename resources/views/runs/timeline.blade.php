<div class="ai6-run-timeline" wire:poll.2s>
    <header class="ai6-page-header">
        <h1>Run-Timeline</h1>
        <p><a href="{{ route('projects.show', $project) }}">Zurück zum Projekt</a></p>
    </header>

    <dl>
        <dt>Run</dt><dd><code>{{ $run->id }}</code></dd>
        <dt>Zustand</dt><dd data-run-state="{{ $run->state->value }}">{{ $run->state->value }}</dd>
        <dt>Phase</dt><dd data-run-phase="{{ $run->phase->value }}">{{ $run->phase->value }}</dd>
        <dt>Wartestatus</dt><dd data-run-wait="{{ $run->wait_reason?->value ?? '' }}">{{ $run->wait_reason?->value ?? '–' }}</dd>
    </dl>

    @if ($run->run_type === \App\AI6\Runs\RunType::REVIEW_ONLY)
        <section aria-labelledby="review-subject-heading">
            <h2 id="review-subject-heading">Gebundener Reviewgegenstand</h2>
            <dl>
                <dt>Quellart</dt><dd>{{ $run->review_subject_kind ?? 'noch nicht materialisiert' }}</dd>
                <dt>Basis</dt><dd><code>{{ $run->review_subject_base_sha ?? '–' }}</code></dd>
                <dt>Quelle</dt><dd><code>{{ $run->review_subject_source_sha ?? '–' }}</code></dd>
                <dt>Checkpoint-Tree</dt><dd><code>{{ $run->checkpoint_tree_sha ?? '–' }}</code></dd>
                <dt>Diff-Hash</dt><dd><code>{{ $run->checkpoint_diff_hash ?? '–' }}</code></dd>
            </dl>
            @if ($completionReport !== null)
                <p data-completion-report="{{ $completionReport->id }}">Der gebundene Abschlussbericht ist als redigiertes Runartefakt gespeichert (<code>{{ $completionReport->digest }}</code>).</p>
            @endif
            @if ($manualReportRequest !== null)
                <p><a href="{{ route('projects.human-requests.show', [$project, $manualReportRequest]) }}">Abschlussbericht prüfen und bestätigen</a></p>
            @endif
        </section>
    @endif

    <h2>Schritte</h2>
    <ul>
        @forelse ($jobs as $job)
            <li data-step-type="{{ $job->step_type }}" data-step-state="{{ $job->state->value }}">
                <strong>{{ $job->step_type }}</strong> · Runde {{ $job->step_number }}: {{ $job->state->value }}
                @if ($job->failure_code !== null)
                    (<code>{{ $job->failure_code }}</code>)
                @endif
            </li>
        @empty
            <li>Noch kein Schritt geplant.</li>
        @endforelse
    </ul>

    <h2>Sessions</h2>
    <ul>
        @forelse ($sessions as $session)
            <li data-session-role="{{ $session->role }}" data-session-state="{{ $session->session_id === null ? 'unbound' : 'bound' }}">
                <strong>{{ $session->role }}</strong>:
                {{ $session->provider_profile }} · {{ $session->model }} · {{ $session->effort }}
                ({{ $session->session_id === null ? 'noch keine Sitzung' : 'Sitzung gebunden' }})
            </li>
        @empty
            <li>Noch keine Session.</li>
        @endforelse
    </ul>

    <h2>Wirksamer Scope</h2>
    <p data-scope-limit-used="{{ $addedScopePathsUsed }}" data-scope-limit-max="{{ $addedScopePathsLimit ?? '' }}">
        Verbrauchte Zusatzpfade: {{ $addedScopePathsUsed }} von {{ $addedScopePathsLimit ?? '–' }}
    </p>
    <h3>Initialer Scope</h3>
    <ul>
        @forelse ($initialScope as $path)
            <li data-initial-scope-path="{{ $path }}"><code>{{ $path }}</code></li>
        @empty
            <li>Kein initialer Scope gebunden.</li>
        @endforelse
    </ul>
    <h3>Scope-Entscheidungen</h3>
    <ul>
        @forelse ($scopeDecisions as $decision)
            <li data-scope-decision-path="{{ $decision['path'] }}" data-scope-decision-outcome="{{ $decision['outcome'] }}" data-scope-decision-reason="{{ $decision['reason'] }}">
                <code>{{ $decision['path'] }}</code>
                ({{ $decision['outcome'] === 'approved' ? 'genehmigt' : 'abgelehnt' }},
                {{ $scopeDecisionReasons[$decision['reason']] ?? 'unbekannter Grund' }})
            </li>
        @empty
            <li>Noch keine Scope-Erweiterung.</li>
        @endforelse
    </ul>
    <h3>Quarantänierte Pfade</h3>
    <ul>
        @forelse ($quarantinedPaths as $entry)
            <li data-quarantined-path="{{ $entry['path'] }}" data-quarantined-change="{{ $entry['change'] }}">
                <code>{{ $entry['path'] }}</code> ({{ $entry['change'] }})
            </li>
        @empty
            <li>Keine quarantänierten Pfade.</li>
        @endforelse
    </ul>

    <h2>Reviewbereitschaft</h2>
    <p data-review-readiness="{{ $reviewReadinessState ?? '' }}">
        {{ $reviewReadinessState === 'ready' ? 'Reviewbereit' : ($reviewReadinessState === 'blocked' ? 'Nicht reviewbereit' : 'Noch nicht bewertet') }}
    </p>
    <h3>Blockadegründe</h3>
    <ul>
        @forelse ($reviewBlockers as $blocker)
            <li data-review-blocker="{{ $blocker['code'] }}"><strong>{{ $blocker['code'] }}</strong>: {{ $blocker['message'] }}</li>
        @empty
            <li>Keine gespeicherten Blockadegründe.</li>
        @endforelse
    </ul>
    <h3>Pflichtchecks</h3>
    <ul>
        @forelse ($checkResults as $result)
            <li data-check-profile="{{ $result->profile }}" data-check-state="{{ $result->state->value }}">
                <strong>{{ $result->profile }}</strong> ({{ $result->phase->value }}): {{ $result->state->value }}
            </li>
        @empty
            <li>Noch keine Prüfergebnisse.</li>
        @endforelse
    </ul>
    <h3>Manuelle und externe Gates</h3>
    <ul>
        @forelse ($runGates as $gate)
            <li data-run-gate="{{ $gate->gate_id }}" data-gate-state="{{ $gate->state->value }}"
                data-blocks-candidate="{{ $gate->blocks_candidate ? '1' : '0' }}"
                data-blocks-final-commit="{{ $gate->blocks_final_commit ? '1' : '0' }}"
                data-blocks-push="{{ $gate->blocks_push ? '1' : '0' }}">
                <strong>{{ $gate->gate_id }}</strong>: {{ $gate->state === \App\AI6\Runs\GateState::OPEN ? 'offen' : 'geschlossen' }}
            </li>
        @empty
            <li>Keine Gates deklariert.</li>
        @endforelse
    </ul>

    <h2>Geänderte Dateien</h2>
    <ul>
        @forelse ($changedFiles as $change)
            <li data-changed-path="{{ $change['path'] ?? '' }}" data-change-type="{{ $change['change'] ?? '' }}">
                <code>{{ $change['path'] ?? '' }}</code>
                ({{ $change['change'] ?? '' }})
            </li>
        @empty
            <li>Noch keine geänderten Dateien.</li>
        @endforelse
    </ul>

    <section aria-labelledby="findings-heading">
        <h2 id="findings-heading">Findings und AC-Abdeckung</h2>
        <div>
            <label for="reviewer-filter">Reviewer</label>
            <select id="reviewer-filter" wire:model.live="reviewerFilter">
                <option value="">Alle Reviewer</option>
                @foreach ($reviewers as $slotId => $label)
                    <option value="{{ $slotId }}">{{ $label }}</option>
                @endforeach
            </select>
            <label for="disposition-filter">Wirksame Disposition</label>
            <select id="disposition-filter" wire:model.live="dispositionFilter">
                <option value="">Alle Dispositionen</option>
                @foreach (['open', 'must_fix', 'human_required', 'suggestion', 'follow_up', 'fixed', 'not_applicable', 'accepted_risk'] as $value)
                    <option value="{{ $value }}">{{ $value }}</option>
                @endforeach
            </select>
        </div>

        <h3>Findings</h3>
        <ul data-findings-list>
            @forelse ($findingRows as $finding)
                <li wire:key="finding-{{ $finding['id'] }}" data-finding-id="{{ $finding['id'] }}" data-reviewer="{{ $finding['slot_id'] }}"
                    data-original-disposition="{{ $finding['original_disposition'] }}"
                    data-effective-disposition="{{ $finding['effective_disposition'] }}"
                    data-blocking="{{ $finding['blocks'] ? '1' : '0' }}">
                    <h4>{{ $finding['title'] }}</h4>
                    <p><strong>Quelle:</strong> {{ $finding['source'] }}, Runde {{ $finding['round'] }}</p>
                    <p><strong>Original:</strong> {{ $finding['severity'] }} / {{ $finding['original_disposition'] }} / {{ $finding['category'] }}</p>
                    <p><strong>Wirksam:</strong> {{ $finding['effective_disposition'] }} — {{ $finding['blocks'] ? 'blockierend' : 'nicht blockierend' }}</p>
                    <p><strong>Ort:</strong> <code>{{ $finding['file'] }}:{{ $finding['line'] }}</code></p>
                    <p><strong>Evidenz:</strong> {{ $finding['evidence'] }}</p>
                    <p><strong>Erwartet:</strong> {{ $finding['expected_result'] }}</p>
                    <p><strong>AC:</strong> {{ implode(', ', $finding['criterion_refs']) }}</p>
                    <p><strong>Checkpoint:</strong> <code>{{ $finding['checkpoint_tree'] }}</code> · <strong>Diff:</strong> <code>{{ $finding['diff_hash'] }}</code></p>
                    <p><strong>Exakte Duplikatgruppe:</strong> <code>{{ $finding['duplicate_group'] }}</code></p>
                    @if ($finding['history'] !== [])
                        <h5>Dispositionshistorie</h5>
                        <ol>
                            @foreach ($finding['history'] as $entry)
                                <li data-disposition-effective="{{ $entry['effective'] ? '1' : '0' }}">
                                    {{ $entry['type'] }} ({{ $entry['source'] }}): {{ $entry['reason'] }}
                                    @if ($entry['evidence_review_result_id'] !== null)
                                        · Reviewnachweis: {{ $entry['evidence_review_result_id'] }}
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                    @if ($finding['status_history'] !== [])
                        <h5>Findingstatus je Re-Review-Runde</h5>
                        <ol>
                            @foreach ($finding['status_history'] as $entry)
                                <li data-finding-status="{{ $entry['status'] }}" data-review-round="{{ $entry['round'] }}"
                                    data-status-source="{{ $entry['source_role'] }}">
                                    Runde {{ $entry['round'] }} · {{ $entry['source'] }}: {{ $entry['status'] }} — {{ $entry['evidence'] }}
                                    · Checkpoint <code>{{ $entry['checkpoint_tree'] }}</code>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                    @if ($finding['verifications'] !== [])
                        <h5>Advisory Verifierevidenz</h5>
                        <p>Diese Evidenz ändert weder das Originalfinding noch seine wirksame Disposition.</p>
                        <ol data-verification-evidence>
                            @foreach ($finding['verifications'] as $entry)
                                <li data-verification-assessment="{{ $entry['assessment'] }}">
                                    Runde {{ $entry['round'] }} · {{ $entry['source'] }}:
                                    {{ $entry['assessment'] }} / Empfehlung {{ $entry['recommendation'] }} — {{ $entry['evidence'] }}
                                    · Checkpoint <code>{{ $entry['checkpoint_tree'] }}</code>
                                    · Diff <code>{{ $entry['diff_hash'] }}</code>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                    @if ($canDisposeFindings)
                        <input type="hidden" name="expected_version" value="{{ $run->version }}"
                            form="finding-disposition-{{ $finding['id'] }}">
                        <form wire:ignore id="finding-disposition-{{ $finding['id'] }}" method="post"
                            action="{{ route('projects.runs.findings.disposition', [$project, $run->id, $finding['id']]) }}">
                            @csrf
                            <label>Disposition
                                <select name="disposition" required>
                                    <option value="not_applicable">not_applicable</option>
                                    <option value="accepted_risk">accepted_risk</option>
                                </select>
                            </label>
                            <label>Begründung <textarea name="reason" required maxlength="2000"></textarea></label>
                            <button type="submit">Disposition speichern</button>
                        </form>
                    @endif
                </li>
            @empty
                <li>Keine Findings für diesen Filter.</li>
            @endforelse
        </ul>

        <h3>AC-Abdeckung</h3>
        <ul data-coverage-list>
            @forelse ($coverageRows as $entry)
                <li data-coverage-reviewer="{{ $entry['slot_id'] }}" data-coverage-criterion="{{ $entry['criterion_id'] }}">
                    <strong>{{ $entry['criterion_id'] }}</strong> — {{ $entry['status'] }} · {{ $entry['source'] }}: {{ $entry['evidence'] }}
                </li>
            @empty
                <li>Keine AC-Abdeckung für diesen Filter.</li>
            @endforelse
        </ul>

        <h3>Instruktionsempfehlungen</h3>
        <ul data-instruction-recommendations>
            @forelse ($instructionRecommendations as $entry)
                <li><strong>{{ $entry['title'] }}</strong> · {{ $entry['source'] }}: {{ $entry['recommendation'] }} ({{ $entry['reason'] }})</li>
            @empty
                <li>Keine Instruktionsempfehlungen.</li>
            @endforelse
        </ul>
    </section>

    <h2>Entscheidungen</h2>
    <ul>
        @forelse ($decisions as $decision)
            <li data-decision-key="{{ $decision['key'] ?? '' }}">
                <strong>{{ $decision['title'] ?? '' }}</strong>
                — {{ $decision['rationale'] ?? '' }}
            </li>
        @empty
            <li>Noch keine Entscheidungen.</li>
        @endforelse
    </ul>

    <h2>Timeline</h2>
    <ol>
        @forelse ($events as $event)
            <li>
                <time datetime="{{ $event->created_at?->toIso8601String() }}">{{ $event->created_at?->format('Y-m-d H:i:s') }}</time>
                <strong>{{ $event->event_type }}</strong> – {{ $event->redacted_payload }}
            </li>
        @empty
            <li>Noch keine Ereignisse.</li>
        @endforelse
    </ol>
</div>
