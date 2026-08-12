<div class="ai6-tickets">
    <header class="ai6-page-header">
        <h1>Ticket bearbeiten</h1>
        <p><a href="{{ route('projects.tickets.show', [$project, $readModel]) }}">Zurück zum Ticket</a></p>
    </header>

    @if ($errors->any())
        <section class="ai6-errors" aria-label="Mutationskonflikt">
            <h2>Mutation nicht angenommen</h2>
            <p>{{ $errors->first() }}</p>
            <p><a href="{{ route('projects.tickets.edit', [$project, $readModel]) }}">Aktuellen Stand neu laden</a></p>
        </section>
    @endif

    <dl class="ai6-required-fields">
        <div class="ai6-required-field ai6-required-wide"><dt>Blob-SHA</dt><dd><code>{{ $readModel->blob_sha }}</code></dd></div>
        <div class="ai6-required-field ai6-required-wide"><dt>Kanonischer Contract-Hash</dt><dd><code>{{ $readModel->ticket_contract_sha256 ?? 'Ungültige Reparaturbasis' }}</code></dd></div>
    </dl>

    <form method="POST" action="{{ route('auth.step-up.totp.verify', ['action' => $stepUpAction]) }}">
        @csrf
        <p>Bitte Step-up bestätigen, bevor der Editorentwurf eingegeben wird; die Bestätigung lädt diese Seite neu.</p>
        <label for="ticket_edit_step_up_code">TOTP-Code für Step-up</label>
        <input id="ticket_edit_step_up_code" name="code" required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}">
        <button type="submit">Step-up bestätigen</button>
    </form>

    <form method="POST" action="{{ route('projects.tickets.update', [$project, $readModel]) }}">
        @csrf
        <input type="hidden" name="operation_id" value="{{ $operationId }}">
        <input type="hidden" name="expected_control_oid" value="{{ $readModel->control_commit }}">
        <input type="hidden" name="expected_blob" value="{{ $readModel->blob_sha }}">
        <textarea name="base_content" hidden>{{ $readModel->redacted_content }}</textarea>
        <label for="ticket_content">Markdown und Frontmatter</label>
        <textarea id="ticket_content" name="target_content" required rows="32">{{ old('target_content', $readModel->redacted_content) }}</textarea>
        <label for="ticket_edit_reason">Auditgrund</label>
        <textarea id="ticket_edit_reason" name="reason" required maxlength="2000">{{ old('reason') }}</textarea>
        <button type="submit">Mutation asynchron starten</button>
    </form>
</div>
