<div class="ai6-prompt-help">
    <header class="ai6-page-header">
        <h1>Prompt-Hilfe</h1>
        <p class="ai6-muted">Katalogversion {{ $catalogVersion }}</p>
    </header>

    <section class="ai6-prompt-panel" aria-labelledby="static-prompts-heading">
        <h2 id="static-prompts-heading">Statische Prompts</h2>
        <p>Diese Texte gelten für Codex und Claude gleichermaßen. Kopiert wird genau der in der Vorschau sichtbare, vom zentralen Katalog gerenderte Inhalt.</p>

        <article class="ai6-prompt-card" aria-labelledby="own-prompt-heading">
            <h3 id="own-prompt-heading">{{ $ownEntry->displayName }}</h3>
            <label class="ai6-prompt-label" for="static-own-preview">Vorschau</label>
            <textarea id="static-own-preview" class="ai6-prompt-preview" readonly rows="12">{{ $ownPrompt }}</textarea>
            <button type="button" data-prompt-copy="static-own-preview">In die Zwischenablage kopieren</button>
            <p id="static-own-preview-status" class="ai6-prompt-status" role="status" aria-live="polite"></p>
        </article>

        <article class="ai6-prompt-card" aria-labelledby="foreign-prompt-heading">
            <h3 id="foreign-prompt-heading">{{ $foreignEntry->displayName }}</h3>
            <label class="ai6-prompt-label" for="static-foreign-preview">Vorschau</label>
            <textarea id="static-foreign-preview" class="ai6-prompt-preview" readonly rows="12">{{ $foreignPrompt }}</textarea>
            <button type="button" data-prompt-copy="static-foreign-preview">In die Zwischenablage kopieren</button>
            <p id="static-foreign-preview-status" class="ai6-prompt-status" role="status" aria-live="polite"></p>
        </article>
    </section>

    <section class="ai6-prompt-panel" aria-labelledby="dynamic-prompt-heading">
        <h2 id="dynamic-prompt-heading">{{ $dynamicEntry->displayName }}</h2>
        <p>Füge eine vollständige Reviewantwort ein. Verarbeitet wird ausschließlich der terminale Abschnitt <code>### Fix-Liste</code>.</p>

        <form class="ai6-prompt-form" wire:submit="processReviewAnswer">
            <label class="ai6-prompt-label" for="review-answer">Vollständige Reviewantwort</label>
            <textarea
                id="review-answer"
                class="ai6-prompt-input"
                wire:model="reviewAnswer"
                rows="10"
                autocomplete="off"
                spellcheck="false"
            ></textarea>
            <button type="submit">Prompt erzeugen</button>
        </form>

        @if ($dynamicRejected)
            <p class="ai6-error-text" role="alert">Die Reviewantwort konnte nicht verarbeitet werden.</p>
        @endif

        @if ($nothingToFix)
            <p class="ai6-prompt-complete" role="status">Keine offenen Findings. Es ist kein weiterer Prompt nötig.</p>
        @endif

        @if ($redacted)
            <p class="ai6-prompt-redaction" role="status">Sensible Werte wurden maskiert.</p>
        @endif

        <label class="ai6-prompt-label" for="dynamic-preview">Dynamische Vorschau</label>
        <textarea
            id="dynamic-preview"
            class="ai6-prompt-preview"
            readonly
            rows="12"
        >{{ $dynamicPreview }}</textarea>
        <button
            type="button"
            data-prompt-copy="dynamic-preview"
            @disabled(! $dynamicCopyEnabled)
        >In die Zwischenablage kopieren</button>
        <p id="dynamic-preview-status" class="ai6-prompt-status" role="status" aria-live="polite"></p>
    </section>

    <script src="{{ asset('assets/prompt-help.js') }}"></script>
</div>
