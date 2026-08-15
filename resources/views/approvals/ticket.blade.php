<div class="ai6-approval">
    <header><h1>Ticketfreigabe vorbereiten</h1><p><a href="{{ route('projects.tickets.show', [$project, $ticketReadModel]) }}">Zurück zum Ticket</a></p></header>
    <p>Geprüfte Todo-Bindung: <code>{{ $ticketReadModel->blob_sha }}</code> auf <code>{{ $ticketReadModel->control_commit }}</code>.</p>
    <form method="POST" action="{{ route('auth.step-up.totp.verify', ['action' => $stepUpAction]) }}">
        @csrf
        <label for="approval_step_up_code">TOTP-Code für Step-up</label>
        <input id="approval_step_up_code" name="code" required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}">
        <button type="submit">Step-up bestätigen</button>
    </form>
    <form method="POST" action="{{ route('projects.tickets.approval.store', [$project, $ticketReadModel]) }}" class="ai6-approval-form">
        @csrf
        <input type="hidden" name="operation_id" value="{{ $operationId }}">
        <input type="hidden" name="expected_control_oid" value="{{ $ticketReadModel->control_commit }}">
        <input type="hidden" name="expected_blob" value="{{ $ticketReadModel->blob_sha }}">
        <textarea name="base_content" hidden>{{ $ticketReadModel->redacted_content }}</textarea>
        <fieldset><legend>Implementierung</legend>
            @php($selectedImplementation = collect($profiles)->firstWhere('id', $implementationProfile))
            <label>Profil <select name="implementation_profile" wire:model.live="implementationProfile" required>@foreach ($profiles as $profile)<option value="{{ $profile->id }}">{{ $profile->id }} ({{ $profile->capabilityStatus->value }})</option>@endforeach</select></label>
            <label>Modell <select name="implementation_model" wire:model.live="implementationModel" required>@foreach ($selectedImplementation?->models ?? [] as $model)<option value="{{ $model }}">{{ $model }}</option>@endforeach</select></label>
            <label>Effort <select name="implementation_effort" wire:model.live="implementationEffort" required>@foreach ($selectedImplementation?->efforts ?? [] as $effort)<option value="{{ $effort }}">{{ $effort }}</option>@endforeach</select></label>
        </fieldset>
        <fieldset><legend>Reviewer-Slots</legend>
            @foreach ($reviewerInputs as $index => $reviewer)
                @php($selectedReviewer = collect($profiles)->firstWhere('id', $reviewer['profile']))
                <section class="ai6-approval-card" wire:key="reviewer-{{ $reviewer['id'] }}"><h2>Reviewer {{ $index + 1 }}</h2>
                    <input type="hidden" name="reviewers[{{ $index }}][id]" value="{{ $reviewer['id'] }}">
                    <label>Profil <select name="reviewers[{{ $index }}][profile]" wire:model.live="reviewerInputs.{{ $index }}.profile" required>@foreach ($profiles as $profile)<option value="{{ $profile->id }}">{{ $profile->id }} ({{ $profile->capabilityStatus->value }})</option>@endforeach</select></label>
                    <label>Modell <select name="reviewers[{{ $index }}][model]" wire:model.live="reviewerInputs.{{ $index }}.model" required>@foreach ($selectedReviewer?->models ?? [] as $model)<option value="{{ $model }}">{{ $model }}</option>@endforeach</select></label>
                    <label>Effort <select name="reviewers[{{ $index }}][effort]" wire:model.live="reviewerInputs.{{ $index }}.effort" required>@foreach ($selectedReviewer?->efforts ?? [] as $effort)<option value="{{ $effort }}">{{ $effort }}</option>@endforeach</select></label>
                    <label>Review-Promptprofil <select name="reviewers[{{ $index }}][prompt_profile]" wire:model.live="reviewerInputs.{{ $index }}.prompt_profile" required>@foreach ($promptCatalog->reviewProfiles() as $profile)<option value="{{ $profile->id }}">{{ $profile->displayName }}</option>@endforeach</select></label>
                    <p>Auswahlgründe: serverseitig registriertes Profil, verfügbare Review-Capability und registriertes Promptprofil.</p>
                    @if (count($reviewerInputs) > 1)<button type="button" wire:click="removeReviewer('{{ $reviewer['id'] }}')">Slot entfernen</button>@endif
                </section>
            @endforeach
            <button type="button" wire:click="addReviewer">Reviewer-Slot hinzufügen</button>
        </fieldset>
        <fieldset class="ai6-approval-grid"><legend>Wirksame Limits</legend>
            @foreach ($limitInputs as $name => $value)<label>{{ $name }} <input type="number" name="limits[{{ $name }}]" wire:model.live.debounce.300ms="limitInputs.{{ $name }}" min="1" required></label>@endforeach
            @foreach (config('ai6.agent_input_limits') as $name => $value)<p><strong>{{ $name }}</strong>: {{ $value }} (unveränderliches Serverlimit)</p>@endforeach
        </fieldset>
        <fieldset><legend>Queue und Push</legend>
            <label>Attention-User <select name="attention_user_id" wire:model.live="attentionUserId"><option value="">Keiner</option>@foreach ($attentionUsers as $user)<option value="{{ $user->getKey() }}">{{ $user->name ?? $user->email }}</option>@endforeach</select></label>
            <label>Pushmodus <select name="push_mode" wire:model.live="pushMode" required><option value="manual">manual</option><option value="automatic_after_gates">automatic_after_gates</option></select></label>
        </fieldset>
        <section><h2>Gemeinsam bestätigter Snapshot</h2>
            @error('preview')<p role="alert">{{ $message }}</p>@enderror
            <button type="button" wire:click="requestPreview">Snapshot asynchron vorbereiten</button>
            @if ($previewState === 'queued')<p wire:poll.2s>Der Worker bereitet den Snapshot vor.</p>@endif
            @if ($previewError !== null)<p role="alert">{{ $previewError }}</p>@endif
            @if ($preview !== null)
            <input type="hidden" name="preview_id" value="{{ $previewId }}">
            <input type="hidden" name="expected_approval_snapshot_hash" value="{{ $preview->aggregateHash }}">
            <h3>Prompt-Snapshot / Katalog {{ $preview->prompt['catalog_version'] }}</h3>
            <p>Hash: <code>{{ $preview->promptHash }}</code></p>
            @foreach ($preview->prompt['rendered_prompts'] as $id => $prompt)<details><summary>{{ $id }}</summary><pre>{{ $prompt }}</pre></details>@endforeach
            @foreach ($preview->prompt['review_profile_snapshots'] as $profileId => $snapshot)
                <details><summary>Review-Promptprofil {{ $profileId }} / <code>{{ $snapshot['prompt_snapshot_hash'] }}</code></summary><pre>{{ $snapshot['rendered_prompts']['quality_review'] }}</pre></details>
            @endforeach
            <h3>Native Instruktionsauflösung</h3>
            @forelse ($instructionEntries as $entry)
                <details><summary>{{ $entry['repository_path'] }} / {{ $entry['provider_profile'] }}</summary>
                    <dl><dt>Geltungsbereich</dt><dd>{{ $entry['scope'] }}</dd><dt>Rang</dt><dd>{{ $entry['priority'] }}</dd><dt>Blob-SHA</dt><dd><code>{{ $entry['blob_sha'] }}</code></dd><dt>Inhaltshash</dt><dd><code>{{ $entry['content_sha256'] }}</code></dd></dl>
                    <h4>Inhalt</h4><pre>{{ $entry['effective_content'] }}</pre><h4>Inhaltsdiff zur leeren Basis</h4><pre>{{ $entry['content_diff'] }}</pre>
                </details>
            @empty
                <p>Keine Repository-Instruktionskandidaten entdeckt; Host- und Parent-Instruktionen sind ausgeschlossen.</p>
            @endforelse
            <h3>Versiegelte Runtime-Profile</h3><pre>{{ json_encode($preview->runtimeProfiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            @endif
        </section>
        <label>Auditgrund <textarea name="reason" required maxlength="2000"></textarea></label>
        <label><input type="checkbox" name="confirm_snapshot" value="1" required> Ich bestätige Defaults, Prompt-Snapshot, Instruktionsauflösung und Runtime-Profil gemeinsam.</label>
        <button type="submit" @disabled($preview === null)>Ticket status-only freigeben</button>
    </form>
</div>
