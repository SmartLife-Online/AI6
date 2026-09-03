<div class="ai6-run-timeline" wire:poll.2s="poll">
    <header class="ai6-page-header">
        <h1>Run-Timeline</h1>
        <p>
            <a href="{{ route('projects.show', $project) }}">Zurück zum Projekt</a>
            · <a href="{{ $refreshUrl }}" data-manual-refresh>Ansicht neu laden</a>
        </p>
        <noscript><p class="ai6-muted">Ohne clientseitige Aktualisierung zeigt jeder Aufruf dieser Seite den aktuellen Serverzustand; bitte die Ansicht bei Bedarf neu laden.</p></noscript>
    </header>

    <section data-security-banner class="ai6-masked-note" role="status" aria-label="Sicherheitsstatus"
        data-security-profile="{{ $securityBanner->profile->value }}"
        data-security-disabled-count="{{ count($securityBanner->disabledMeasures) }}">
        <strong>Sicherheitsprofil {{ $securityBanner->profile->value }}.</strong>
        @if ($securityBanner->disabledMeasures === [])
            Alle Sicherheitskontrollen dieser Instanz sind aktiv.
        @else
            Deaktivierte Sicherheitskontrollen dieser Instanz{{ $securityBanner->reducedModeAcknowledged ? ' (reduzierter Modus ausdrücklich bestätigt)' : '' }}:
            <ul>
                @foreach ($securityBanner->disabledMeasures as $measure)
                    <li data-disabled-measure="{{ $measure->value }}"><code>{{ $measure->value }}</code></li>
                @endforeach
            </ul>
            Dieser Hinweis ist dauerhaft und lässt sich nicht ausblenden.
        @endif
        <span class="ai6-muted">Gebundener Policy-Hash: <code class="ai6-oid">{{ $run->security_policy_hash }}</code></span>
    </section>

    <section aria-labelledby="overview-heading" data-run-overview>
        <h2 id="overview-heading">Übersicht</h2>
        <dl class="ai6-state">
            <div class="ai6-state-item"><dt>Run</dt><dd><code class="ai6-oid">{{ $run->id }}</code></dd></div>
            <div class="ai6-state-item"><dt>Ticket</dt><dd>{{ $ticketId ?? '–' }}</dd></div>
            <div class="ai6-state-item"><dt>Laufart</dt><dd data-run-type="{{ $run->run_type->value }}">{{ $run->run_type->value }}</dd></div>
            <div class="ai6-state-item"><dt>Zustand</dt><dd data-run-state="{{ $run->state->value }}">{{ $run->state->value }}</dd></div>
            <div class="ai6-state-item"><dt>Phase</dt><dd data-run-phase="{{ $run->phase->value }}">{{ $run->phase->value }}</dd></div>
            <div class="ai6-state-item"><dt>Wartestatus</dt><dd data-run-wait="{{ $run->wait_reason?->value ?? '' }}">{{ $run->wait_reason?->value ?? '–' }}</dd></div>
            <div class="ai6-state-item"><dt>Runversion</dt><dd data-run-version="{{ $run->version }}">{{ $run->version }}</dd></div>
            <div class="ai6-state-item"><dt>Implementierungsmodell</dt>
                <dd data-implementation-slots="{{ count($agentSlots['implementation']) }}">
                    @forelse ($agentSlots['implementation'] as $agentSlot)
                        <span>{{ $agentSlot['label'] }}</span>
                    @empty
                        @if ($run->run_type === \App\AI6\Runs\RunType::REVIEW_ONLY)
                            kein Implementierungsslot (review-only)
                        @elseif ($plannedImplementation !== null)
                            <span data-implementation-planned>freigegeben, noch kein Slot: {{ $plannedImplementation }}</span>
                        @else
                            noch kein Slot
                        @endif
                    @endforelse
                </dd>
            </div>
            <div class="ai6-state-item"><dt>Reviewerslots</dt>
                <dd data-reviewer-slots="{{ count($agentSlots['quality_review']) }}">
                    @forelse ($agentSlots['quality_review'] as $agentSlot)
                        <span data-reviewer-slot="{{ $agentSlot['slot_id'] }}" data-slot-active="{{ $agentSlot['active'] ? '1' : '0' }}">{{ $agentSlot['label'] }} · Promptprofil {{ $agentSlot['prompt_profile'] }}{{ $agentSlot['active'] ? '' : ' (abgelöst)' }}</span>@if (! $loop->last)<br>@endif
                    @empty
                        @forelse ($plannedReviewers as $planned)
                            <span data-reviewer-planned>freigegeben, noch kein Slot: {{ $planned['label'] }} · Promptprofil {{ $planned['prompt_profile'] }}</span>@if (! $loop->last)<br>@endif
                        @empty
                            noch kein Reviewerslot
                        @endforelse
                    @endforelse
                </dd>
            </div>
            <div class="ai6-state-item"><dt>Verifierslots</dt>
                <dd data-verifier-slots="{{ count($agentSlots['finding_verification']) }}">
                    @forelse ($agentSlots['finding_verification'] as $agentSlot)
                        <span data-verifier-slot="{{ $agentSlot['slot_id'] }}">{{ $agentSlot['label'] }}</span>@if (! $loop->last)<br>@endif
                    @empty
                        kein Verifierslot
                    @endforelse
                </dd>
            </div>
            <div class="ai6-state-item"><dt>Securityslots</dt>
                <dd data-security-slots="{{ count($agentSlots['security_review']) }}">
                    @forelse ($agentSlots['security_review'] as $agentSlot)
                        <span>{{ $agentSlot['label'] }}</span>@if (! $loop->last)<br>@endif
                    @empty
                        kein Securityslot
                    @endforelse
                </dd>
            </div>
            <div class="ai6-state-item"><dt>Iterationen</dt>
                <dd data-review-rounds="{{ $rounds['review']['used'] }}" data-fix-rounds="{{ $rounds['fix']['used'] }}" data-verification-rounds="{{ $rounds['verify']['used'] }}">
                    Reviewrunden {{ $rounds['review']['used'] }} von {{ $rounds['review']['limit'] ?? '–' }} ·
                    Fixrunden {{ $rounds['fix']['used'] }} von {{ $rounds['fix']['limit'] ?? '–' }} ·
                    Verifikationsrunden {{ $rounds['verify']['used'] }} von {{ $rounds['verify']['limit'] ?? '–' }}
                </dd>
            </div>
            <div class="ai6-state-item"><dt>Pushmodus</dt><dd data-push-mode="{{ $approval?->push_mode ?? '' }}">{{ $approval?->push_mode ?? '–' }}</dd></div>
        </dl>
        <h3>Aktuelle Wartesituation</h3>
        @if ($run->wait_reason === null && $openRequests === [])
            <p data-wait-situation="none">Der Run wartet derzeit auf keine menschliche Entscheidung.</p>
        @else
            <p data-wait-situation="{{ $run->wait_reason?->value ?? 'open_request' }}">
                Wartegrund <code>{{ $run->wait_reason?->value ?? '–' }}</code>
                @if ($openRequests !== [])
                    · offene Anfragen:
                    @foreach ($openRequests as $request)
                        @if ($canAnswerRequests)
                            <a href="{{ route('projects.human-requests.show', [$project, $request['id']]) }}">{{ $request['title'] }}</a>@if (! $loop->last), @endif
                        @else
                            <span>{{ $request['title'] }}</span>@if (! $loop->last), @endif
                        @endif
                    @endforeach
                @endif
            </p>
        @endif
    </section>

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
                <p data-completion-report="{{ $completionReport->id }}">Der gebundene Abschlussbericht ist als redigiertes Runartefakt gespeichert (<code>{{ $completionReport->digest ?? 'Rohbezug nach Ablauf der Aufbewahrung entfernt' }}</code>).</p>
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
        @php($sessionCount = 0)
        @foreach ($agentSlots as $role => $roleSlots)
            @foreach ($roleSlots as $agentSlot)
                @php($sessionCount++)
                <li data-session-role="{{ $role }}" data-session-state="{{ $agentSlot['bound'] ? 'bound' : 'unbound' }}">
                    <strong>{{ $role }}</strong>:
                    {{ $agentSlot['label'] }}
                    ({{ $agentSlot['bound'] ? 'Sitzung gebunden' : 'noch keine Sitzung' }})
                </li>
            @endforeach
        @endforeach
        @if ($sessionCount === 0)
            <li>Noch keine Session.</li>
        @endif
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

    <section aria-labelledby="checks-heading" data-checks>
        <h2 id="checks-heading">Checks</h2>
        <h3>Pflichtchecks</h3>
        <ul>
            @forelse ($checkRows as $result)
                <li data-check-profile="{{ $result['profile'] }}" data-check-state="{{ $result['state'] }}" data-check-phase="{{ $result['phase'] }}">
                    <strong>{{ $result['profile'] }}</strong> ({{ $result['phase'] }}): {{ $result['state'] }}
                    @if ($result['reason'] !== null)· Grund <code>{{ $result['reason'] }}</code>@endif
                    · Exit-Code {{ $result['exit_code'] ?? '–' }} · {{ $result['duration_ms'] }} ms
                    · Tree <code class="ai6-oid">{{ $result['tree_sha'] }}</code>
                    @include('runs.partials.retention', ['retention' => $result['retention'], 'what' => 'Checkausgabe'])
                    @if ($result['output'] !== null)
                        <pre class="ai6-source" data-check-output>{{ $result['output']['text'] }}</pre>
                        @if ($result['output']['truncated'])
                            <p class="ai6-muted" data-truncated="check-output">Begrenzt: {{ $result['output']['shown'] }} von {{ $result['output']['total'] }} Bytes der Checkausgabe angezeigt.</p>
                        @endif
                    @endif
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
    </section>

    <section aria-labelledby="diff-heading" data-diff>
        <h2 id="diff-heading">Diff</h2>
        <dl class="ai6-state">
            <div class="ai6-state-item"><dt>Checkpoint-Commit</dt><dd><code class="ai6-oid">{{ $run->checkpoint_commit_sha ?? '–' }}</code></dd></div>
            <div class="ai6-state-item"><dt>Checkpoint-Tree</dt><dd><code class="ai6-oid">{{ $run->checkpoint_tree_sha ?? '–' }}</code></dd></div>
            <div class="ai6-state-item"><dt>Diff-Hash</dt><dd data-diff-hash="{{ $run->checkpoint_diff_hash ?? '' }}"><code class="ai6-oid">{{ $run->checkpoint_diff_hash ?? '–' }}</code></dd></div>
            <div class="ai6-state-item"><dt>Candidate-Tree</dt><dd><code class="ai6-oid">{{ $run->candidate_tree_sha ?? '–' }}</code></dd></div>
            <div class="ai6-state-item"><dt>Candidate-Diff-Hash</dt><dd><code class="ai6-oid">{{ $run->candidate_diff_hash ?? '–' }}</code></dd></div>
        </dl>
        <h3>Änderungen des gebundenen Checkpoints</h3>
        @if ($checkpointDiff === null)
            <p data-diff-text-missing>Für den aktuellen Checkpoint ist noch kein Diff-Artefakt abgelegt.</p>
        @elseif ($checkpointDiff['unavailable'] === 'deleted')
            <p data-diff-text-unavailable="deleted">Der Checkpoint-Diff wurde nach Ablauf seiner Aufbewahrung durch den Retentionlauf gelöscht.</p>
        @elseif ($checkpointDiff['unavailable'] === 'expired')
            <p data-diff-text-unavailable="expired">Die Aufbewahrung des Checkpoint-Diffs ist abgelaufen; er wird nicht mehr ausgegeben und im nächsten Retentionlauf gelöscht.</p>
        @elseif ($checkpointDiff['unavailable'] === 'not_utf8')
            <p data-diff-text-unavailable="not_utf8">Der Checkpoint-Diff konnte nicht abgelegt werden: Die Git-Ausgabe war kein gültiges UTF-8.</p>
        @elseif ($checkpointDiff['unavailable'] !== null)
            <p data-diff-text-unavailable="git_output_unavailable">Der Checkpoint-Diff konnte nicht abgelegt werden: Die Git-Ausgabe war nicht verfügbar oder über dem Ausgabelimit.</p>
        @elseif ($checkpointDiff['text'] !== null)
            @if ($checkpointDiff['stored_truncated'])
                <p class="ai6-muted" data-truncated="diff-stored">Begrenzt: Der Diff wurde beim Ablegen auf das Artefaktlimit gekürzt ({{ $checkpointDiff['total_bytes'] }} Bytes gesamt).</p>
            @endif
            <pre class="ai6-source" data-diff-text>{{ $checkpointDiff['text']['text'] }}</pre>
            @if ($checkpointDiff['text']['truncated'])
                <p class="ai6-muted" data-truncated="diff">Begrenzt: {{ $checkpointDiff['text']['shown'] }} von {{ $checkpointDiff['text']['total'] }} Bytes des Diffs angezeigt; die vollständige redigierte Fassung liefert der Artefaktdownload.</p>
            @endif
        @endif
        <h3>Geänderte Dateien</h3>
        @if ($summaryUnavailable === 'deleted')
            <p data-summary-unavailable="deleted">Die Implementierungszusammenfassung wurde nach Ablauf ihrer Aufbewahrung gelöscht; die Dateiliste ist nicht mehr verfügbar.</p>
        @elseif ($summaryUnavailable === 'expired')
            <p data-summary-unavailable="expired">Die Aufbewahrung der Implementierungszusammenfassung ist abgelaufen; ihre Rohdaten werden nicht mehr ausgegeben und im nächsten Retentionlauf gelöscht.</p>
        @endif
        @if ($changedFilePage['total'] > $changedFilePage['size'])
            <p class="ai6-muted" data-pagination="changed-files">
                Begrenzt: Dateien {{ $changedFilePage['from'] }} bis {{ $changedFilePage['to'] }} von {{ $changedFilePage['total'] }} angezeigt (Seite {{ $changedFilePage['page'] }} von {{ $changedFilePage['pages'] }}, serverseitig {{ $changedFilePage['size'] }} je Seite).
                @if ($changedFilePage['page'] > 1)<a href="{{ $pageUrl(['changedFilesPage' => $changedFilePage['page'] - 1]) }}">Vorherige Seite</a>@endif
                @if ($changedFilePage['page'] < $changedFilePage['pages'])<a href="{{ $pageUrl(['changedFilesPage' => $changedFilePage['page'] + 1]) }}">Nächste Seite</a>@endif
            </p>
        @endif
        <ul>
            @forelse ($changedFilePage['items'] as $change)
                <li data-changed-path="{{ $change['path'] }}" data-change-type="{{ $change['change'] }}">
                    <code>{{ $change['path'] }}</code>
                    ({{ $change['change'] }}@if ($change['bytes'] !== null), {{ $change['bytes'] }} Bytes @endif)
                </li>
            @empty
                <li>Noch keine geänderten Dateien.</li>
            @endforelse
        </ul>
    </section>

    <section aria-labelledby="findings-heading">
        <h2 id="findings-heading">Findings und AC-Abdeckung</h2>
        {{-- A plain GET form keeps the filters usable without client-side updating; with it, the selects apply live. --}}
        <form class="ai6-filters" method="get" action="{{ route('projects.runs.show', [$project, $run->id]) }}" data-finding-filters>
            <div class="ai6-filter">
                <label for="reviewer-filter">Reviewer</label>
                <select id="reviewer-filter" name="reviewerFilter" wire:model.live="reviewerFilter">
                    <option value="">Alle Reviewer</option>
                    @foreach ($reviewers as $slotId => $label)
                        <option value="{{ $slotId }}" @selected($reviewerFilter === $slotId)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ai6-filter">
                <label for="disposition-filter">Wirksame Disposition</label>
                <select id="disposition-filter" name="dispositionFilter" wire:model.live="dispositionFilter">
                    <option value="">Alle Dispositionen</option>
                    @foreach (['open', 'must_fix', 'human_required', 'suggestion', 'follow_up', 'fixed', 'not_applicable', 'accepted_risk'] as $value)
                        <option value="{{ $value }}" @selected($dispositionFilter === $value)>{{ $value }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ai6-filter">
                <button type="submit">Filter anwenden</button>
            </div>
        </form>

        <h3>Findings</h3>
        @if ($findingPage['total'] > $findingPage['size'])
            <p class="ai6-muted" data-pagination="findings">
                Begrenzt: Findings {{ $findingPage['from'] }} bis {{ $findingPage['to'] }} von {{ $findingPage['total'] }} angezeigt (Seite {{ $findingPage['page'] }} von {{ $findingPage['pages'] }}, serverseitig {{ $findingPage['size'] }} je Seite).
                @if ($findingPage['page'] > 1)<a href="{{ $pageUrl(['findingsPage' => $findingPage['page'] - 1]) }}">Vorherige Seite</a>@endif
                @if ($findingPage['page'] < $findingPage['pages'])<a href="{{ $pageUrl(['findingsPage' => $findingPage['page'] + 1]) }}">Nächste Seite</a>@endif
            </p>
        @endif
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
                    <p><strong>Evidenz:</strong> {{ $finding['evidence']['text'] }}@include('runs.partials.truncated', ['excerpt' => $finding['evidence'], 'what' => 'finding-evidence'])</p>
                    <p><strong>Erwartet:</strong> {{ $finding['expected_result']['text'] }}@include('runs.partials.truncated', ['excerpt' => $finding['expected_result'], 'what' => 'finding-expected'])</p>
                    <p><strong>AC:</strong> {{ implode(', ', $finding['criterion_refs']) }}</p>
                    <p><strong>Checkpoint:</strong> <code>{{ $finding['checkpoint_tree'] }}</code> · <strong>Diff:</strong> <code>{{ $finding['diff_hash'] }}</code></p>
                    <p><strong>Exakte Duplikatgruppe:</strong> <code>{{ $finding['duplicate_group'] }}</code></p>
                    @if ($finding['history'] !== [])
                        <h5>Dispositionshistorie</h5>
                        <ol>
                            @foreach ($finding['history'] as $entry)
                                <li data-disposition-effective="{{ $entry['effective'] ? '1' : '0' }}">
                                    {{ $entry['type'] }} ({{ $entry['source'] }}): {{ $entry['reason']['text'] }}@include('runs.partials.truncated', ['excerpt' => $entry['reason'], 'what' => 'disposition-reason'])
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
                                    Runde {{ $entry['round'] }} · {{ $entry['source'] }}: {{ $entry['status'] }} — {{ $entry['evidence']['text'] }}@include('runs.partials.truncated', ['excerpt' => $entry['evidence'], 'what' => 'status-evidence'])
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
                                    {{ $entry['assessment'] }} / Empfehlung {{ $entry['recommendation'] }} — {{ $entry['evidence']['text'] }}@include('runs.partials.truncated', ['excerpt' => $entry['evidence'], 'what' => 'verification-evidence'])
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
                    <strong>{{ $entry['criterion_id'] }}</strong> — {{ $entry['status'] }} · {{ $entry['source'] }}: {{ $entry['evidence']['text'] }}@include('runs.partials.truncated', ['excerpt' => $entry['evidence'], 'what' => 'coverage-evidence'])
                </li>
            @empty
                <li>Keine AC-Abdeckung für diesen Filter.</li>
            @endforelse
        </ul>

        <h3>Instruktionsempfehlungen</h3>
        <ul data-instruction-recommendations>
            @forelse ($instructionRecommendations as $entry)
                <li><strong>{{ $entry['title'] }}</strong> · {{ $entry['source'] }}: {{ $entry['recommendation']['text'] }}@include('runs.partials.truncated', ['excerpt' => $entry['recommendation'], 'what' => 'recommendation']) ({{ $entry['reason']['text'] }}@include('runs.partials.truncated', ['excerpt' => $entry['reason'], 'what' => 'recommendation-reason']))</li>
            @empty
                <li>Keine Instruktionsempfehlungen.</li>
            @endforelse
        </ul>
    </section>

    <h2>Entscheidungen</h2>
    <ul>
        @forelse ($decisions as $decision)
            <li data-decision-key="{{ $decision['key'] }}">
                <strong>{{ $decision['title'] }}</strong>
                — {{ $decision['rationale']['text'] }}@include('runs.partials.truncated', ['excerpt' => $decision['rationale'], 'what' => 'decision-rationale'])
            </li>
        @empty
            <li>Noch keine Entscheidungen.</li>
        @endforelse
    </ul>

    <section aria-labelledby="human-requests-heading" data-human-requests>
        <h2 id="human-requests-heading">Human Requests</h2>
        <ul>
            @forelse ($humanRequests as $request)
                <li data-human-request="{{ $request['id'] }}" data-human-request-kind="{{ $request['kind'] }}"
                    data-human-request-state="{{ $request['resolution_state'] }}" data-delivery-status="{{ $request['delivery_status'] }}">
                    <strong>{{ $request['title'] }}</strong> ({{ $request['kind'] }}, {{ $request['resolution_state'] }})
                    · Zustellstatus {{ $request['delivery_status'] }}@if ($request['delivery_failure_key'] !== null) ({{ $request['delivery_failure_key'] }})@endif
                    · gebunden an Runversion {{ $request['bound_run_version'] }}
                    · geöffnet <time datetime="{{ $request['created_at']?->toIso8601String() }}">{{ $request['created_at']?->format('Y-m-d H:i:s') }}</time>
                    @if ($request['chosen_effect'] !== null)
                        · Intervention <code>{{ $request['chosen_effect'] }}</code>
                    @endif
                    @if ($request['resolved_at'] !== null)
                        · aufgelöst <time datetime="{{ $request['resolved_at']->toIso8601String() }}">{{ $request['resolved_at']->format('Y-m-d H:i:s') }}</time>
                    @endif
                    <br>{{ $request['message']['text'] }}@include('runs.partials.truncated', ['excerpt' => $request['message'], 'what' => 'human-request-message'])
                    @if ($canAnswerRequests)
                        <br><a href="{{ route('projects.human-requests.show', [$project, $request['id']]) }}">Human-Request-Detail öffnen</a>
                    @endif
                </li>
            @empty
                <li>Noch keine Human Requests.</li>
            @endforelse
        </ul>
    </section>

    <section aria-labelledby="security-heading" data-security>
        <h2 id="security-heading">Security</h2>
        <dl class="ai6-state">
            <div class="ai6-state-item"><dt>Securityprofil der Instanz</dt><dd>{{ $securityBanner->profile->value }} ({{ count($securityBanner->disabledMeasures) }} deaktivierte Kontrollen)</dd></div>
            <div class="ai6-state-item"><dt>Security-Gate</dt>
                <dd data-security-gate="{{ $run->wait_reason?->value === 'security_gate' ? 'waiting' : 'not_waiting' }}">
                    {{ $run->wait_reason?->value === 'security_gate' ? 'Der Run wartet auf eine Securityentscheidung.' : 'Kein offenes Security-Gate.' }}
                </dd>
            </div>
            <div class="ai6-state-item"><dt>Letztes Sicherheitsreview</dt>
                <dd data-security-review="{{ $securityReview?->result_status ?? $securityReview?->invocation_outcome->value ?? 'none' }}">
                    @if ($securityReview === null)
                        Noch kein Sicherheitsreview.
                    @else
                        Runde {{ $securityReview->round_number }} · {{ $securityReview->invocation_outcome->value }}
                        @if ($securityReview->result_status !== null)· Ergebnis <code>{{ $securityReview->result_status }}</code>@endif
                        @if ($securityReview->failure_code !== null)· Fehler <code>{{ $securityReview->failure_code }}</code>@endif
                    @endif
                </dd>
            </div>
            <div class="ai6-state-item"><dt>Gebundener Policy-Hash</dt><dd><code class="ai6-oid">{{ $run->security_policy_hash }}</code></dd></div>
        </dl>
    </section>

    <section aria-labelledby="push-heading" data-push>
        <h2 id="push-heading">Pushstatus und Queue</h2>
        <dl class="ai6-state">
            <div class="ai6-state-item"><dt>Pushmodus</dt><dd>{{ $approval?->push_mode ?? '–' }}</dd></div>
            <div class="ai6-state-item"><dt>Branchveröffentlichung</dt><dd data-branch-publication-state="{{ $run->branch_publication_state ?? '' }}">{{ $run->branch_publication_state ?? 'noch nicht begonnen' }}</dd></div>
            <div class="ai6-state-item"><dt>Run-Branch</dt><dd><code>{{ $run->run_branch ?? '–' }}</code></dd></div>
            <div class="ai6-state-item"><dt>Ziel-OID</dt><dd><code class="ai6-oid">{{ $run->branch_publication_target_oid ?? '–' }}</code></dd></div>
            <div class="ai6-state-item"><dt>Bestätigte OID</dt><dd data-confirmed-publication="{{ $run->confirmed_branch_publication_oid ?? '' }}"><code class="ai6-oid">{{ $run->confirmed_branch_publication_oid ?? '–' }}</code></dd></div>
            <div class="ai6-state-item"><dt>Finaler Commit</dt><dd><code class="ai6-oid">{{ $run->final_commit_oid ?? '–' }}</code>@if ($run->final_commit_kind !== null) ({{ $run->final_commit_kind }})@endif</dd></div>
            <div class="ai6-state-item"><dt>Bestätigt am</dt><dd>{{ $run->branch_publication_confirmed_at?->toIso8601String() ?? '–' }}</dd></div>
            <div class="ai6-state-item"><dt>Statussaga</dt>
                <dd data-status-saga="{{ $run->pending_status_operation_id !== null ? 'pending' : ($run->wait_reason?->value === 'status_sync' ? 'conflict' : 'idle') }}">
                    @if ($run->pending_status_operation_id !== null)
                        Statusoperation <code class="ai6-oid">{{ $run->pending_status_operation_id }}</code> ausstehend
                    @elseif ($run->wait_reason?->value === 'status_sync')
                        Statusabgleich wartet auf eine Entscheidung
                    @else
                        keine ausstehende Statusoperation
                    @endif
                    · Approval-Sagaphase <code>{{ $approval?->saga_phase ?? '–' }}</code>
                </dd>
            </div>
            <div class="ai6-state-item"><dt>Zurückgeschriebener Scope</dt><dd><code class="ai6-oid">{{ $run->recorded_scope_sha256 ?? 'noch nicht zurückgeschrieben' }}</code></dd></div>
            <div class="ai6-state-item"><dt>Queuezustand der Approval</dt>
                <dd data-queue-state="{{ $approval?->queue_state ?? '' }}">
                    {{ $approval?->queue_state ?? '–' }}@if ($approval?->queued_at !== null) · eingereiht {{ $approval->queued_at->toIso8601String() }}@endif
                    · <a href="{{ route('projects.queue.index', $project) }}">Projektqueue</a>
                </dd>
            </div>
        </dl>
    </section>

    <section aria-labelledby="artifacts-heading" data-artifacts>
        <h2 id="artifacts-heading">Artefakte</h2>
        <p class="ai6-muted">Aufbewahrung je Kategorie:
            @foreach ($artifactLimits as $name => $limit)
                <span data-retention-limit="{{ $name }}">{{ $name }} {{ $limit->maxDays }} Tage, höchstens {{ $limit->maxBytes }} Bytes</span>@if (! $loop->last) · @endif
            @endforeach
            · aktive Runs schieben höchstens {{ $retentionPolicy->activeRunGraceDays }} Tage auf.
        </p>
        @if ($artifactPage['total'] > $artifactPage['size'])
            <p class="ai6-muted" data-pagination="artifacts">
                Begrenzt: Artefakte {{ $artifactPage['from'] }} bis {{ $artifactPage['to'] }} von {{ $artifactPage['total'] }} angezeigt (Seite {{ $artifactPage['page'] }} von {{ $artifactPage['pages'] }}, serverseitig {{ $artifactPage['size'] }} je Seite).
                @if ($artifactPage['page'] > 1)<a href="{{ $pageUrl(['artifactsPage' => $artifactPage['page'] - 1]) }}">Vorherige Seite</a>@endif
                @if ($artifactPage['page'] < $artifactPage['pages'])<a href="{{ $pageUrl(['artifactsPage' => $artifactPage['page'] + 1]) }}">Nächste Seite</a>@endif
            </p>
        @endif
        <ul data-artifact-list>
            @forelse ($artifactPage['items'] as $artifact)
                <li data-artifact="{{ $artifact['id'] }}" data-artifact-kind="{{ $artifact['kind'] }}"
                    data-artifact-retention="{{ $artifact['deleted'] ? 'deleted' : ($artifact['expired'] ? 'expired' : 'stored') }}"
                    data-artifact-remaining-days="{{ $artifact['remaining_days'] }}">
                    <strong>{{ $artifact['kind'] }}</strong> · Nr. {{ $artifact['sequence'] }} · {{ $artifact['size_bytes'] }} Bytes · Kategorie {{ $artifact['category'] }}
                    · erzeugt <time datetime="{{ $artifact['created_at']?->toIso8601String() }}">{{ $artifact['created_at']?->format('Y-m-d H:i:s') }}</time>
                    · Ablauf <time datetime="{{ $artifact['expires_at']->toIso8601String() }}">{{ $artifact['expires_at']->format('Y-m-d H:i:s') }}</time>
                    @if ($artifact['deleted'])
                        <br><span data-artifact-tombstone>Rohdaten am <time datetime="{{ $artifact['deleted_at']?->toIso8601String() }}">{{ $artifact['deleted_at']?->format('Y-m-d H:i:s') }}</time> durch den Retentionlauf gelöscht; Tombstone-Herkunft: Kategorie {{ $artifact['category'] }}, Fingerprint-Key <code>{{ $artifact['fingerprint_key_id'] ?? 'ohne Fingerprint (Altbestand)' }}</code> Version {{ $artifact['fingerprint_version'] ?? '–' }}.</span>
                    @elseif ($artifact['expired'])
                        <br><span data-artifact-expired>Aufbewahrung abgelaufen; die Rohdaten werden nicht mehr ausgegeben und im nächsten Retentionlauf gelöscht.</span>
                        · <span data-artifact-download-refused="{{ $artifact['id'] }}">kein Download (abgelaufen)</span>
                    @else
                        <br><span>Verbleibende Aufbewahrung: {{ $artifact['remaining_days'] }} Tage</span>
                        @if ($artifact['downloadable'])
                            · <a href="{{ route('projects.runs.artifacts.download', [$project, $run->id, $artifact['id']]) }}" data-artifact-download="{{ $artifact['id'] }}">Redigierte Bytes herunterladen</a>
                        @else
                            · <span data-artifact-download-refused="{{ $artifact['id'] }}">kein Download (über dem Größenlimit)</span>
                        @endif
                    @endif
                    @if ($artifact['fingerprint'] !== null)
                        <br><span class="ai6-muted">HMAC-Fingerprint <code class="ai6-oid">{{ $artifact['fingerprint'] }}</code> (Key <code>{{ $artifact['fingerprint_key_id'] }}</code>, Version {{ $artifact['fingerprint_version'] }})</span>
                    @endif
                </li>
            @empty
                <li>Noch keine Artefakte.</li>
            @endforelse
        </ul>
    </section>

    <h2>Timeline</h2>
    @if ($eventPage['total'] > $eventPage['size'])
        <p class="ai6-muted" data-pagination="events">
            Begrenzt: Ereignisse {{ $eventPage['from'] }} bis {{ $eventPage['to'] }} von {{ $eventPage['total'] }} angezeigt (Seite {{ $eventPage['page'] }} von {{ $eventPage['pages'] }}, serverseitig {{ $eventPage['size'] }} je Seite; die neueste Seite ist die Standardansicht).
            @if ($eventPage['page'] > 1)<a href="{{ $pageUrl(['eventsPage' => $eventPage['page'] - 1]) }}">Ältere Ereignisse</a>@endif
            @if ($eventPage['page'] < $eventPage['pages'])<a href="{{ $pageUrl(['eventsPage' => $eventPage['page'] + 1]) }}">Neuere Ereignisse</a>@endif
        </p>
    @endif
    <ol data-timeline>
        @forelse ($eventPage['items'] as $event)
            <li data-event-id="{{ $event['id'] }}" data-event-retention="{{ $event['payload'] === null ? 'deleted' : 'stored' }}">
                <time datetime="{{ $event['created_at']?->toIso8601String() }}">{{ $event['created_at']?->format('Y-m-d H:i:s') }}</time>
                <strong>{{ $event['event_type'] }}</strong>
                @if ($event['payload'] !== null)
                    – {{ $event['payload']['text'] }}@include('runs.partials.truncated', ['excerpt' => $event['payload'], 'what' => 'event-payload'])
                @endif
                @include('runs.partials.retention', ['retention' => $event['retention'], 'what' => 'Rohdaten des Eintrags'])
            </li>
        @empty
            <li>Noch keine Ereignisse.</li>
        @endforelse
    </ol>
</div>
