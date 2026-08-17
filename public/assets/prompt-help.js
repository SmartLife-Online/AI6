(function () {
    'use strict';

    if (window.__ai6PromptHelp) {
        return;
    }
    window.__ai6PromptHelp = true;

    var SUCCESS_TEXT = 'In die Zwischenablage kopiert.';
    var FALLBACK_TEXT = 'Zwischenablage nicht verfügbar. Markierten Text manuell kopieren (Strg+C oder Cmd+C).';

    function statusElement(previewId) {
        return document.getElementById(previewId + '-status');
    }

    function setStatus(previewId, message) {
        var status = statusElement(previewId);
        if (status) {
            status.textContent = message;
        }
    }

    function selectPreview(field) {
        field.focus();
        if (typeof field.select === 'function') {
            field.select();
        }
        if (typeof field.setSelectionRange === 'function') {
            field.setSelectionRange(0, field.value.length);
        }
    }

    function copyPreview(field, previewId) {
        var clipboard = navigator.clipboard;
        if (!clipboard || typeof clipboard.writeText !== 'function') {
            selectPreview(field);
            setStatus(previewId, FALLBACK_TEXT);
            return;
        }

        clipboard.writeText(field.value).then(function () {
            setStatus(previewId, SUCCESS_TEXT);
        }).catch(function () {
            selectPreview(field);
            setStatus(previewId, FALLBACK_TEXT);
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        var button = target.closest('[data-prompt-copy]');
        if (!(button instanceof HTMLElement) || button.hasAttribute('disabled')) {
            return;
        }

        var previewId = button.getAttribute('data-prompt-copy');
        if (!previewId) {
            return;
        }

        var field = document.getElementById(previewId);
        if (!(field instanceof HTMLTextAreaElement) || field.value === '') {
            return;
        }

        event.preventDefault();
        setStatus(previewId, '');
        copyPreview(field, previewId);
    });
})();
