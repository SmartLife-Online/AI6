<?php

/*
 * Livewire runs under the fixed AI6 Content Security Policy: scripts load only
 * from the server-bound "/assets/" path, "unsafe-eval" and "unsafe-inline" do
 * not exist, and styles are same-origin external files. The keys below are a
 * security contract, not tuning values: AI6ServiceProvider fails closed when
 * "csp_safe" is not true or "inject_assets" is not false, and the script route
 * is registered exclusively under "/assets/". Every omitted key keeps the
 * package default through Livewire's own config merge.
 */

return [

    /*
     * Serve the eval-free CSP bundle (dist/livewire.csp*.js). The regular
     * bundle evaluates Alpine expressions through new Function() and would
     * require "unsafe-eval" in script-src, which the AI6 CSP never grants.
     */
    'csp_safe' => true,

    /*
     * Never let Livewire inject its inline <style> and <script> tags into the
     * response. The layout loads the script exclusively through the
     * hash-bound "/assets/" route and all styling lives in public/assets/ai6.css.
     */
    'inject_assets' => false,

    /*
     * AI6 does not use wire:navigate. Livewire still attempts the progress-bar
     * style injection while evaluating its bundle; the external, hash-bound
     * AI6 progress guard rejects exactly those known bytes before the fixed
     * style-src 'self' policy needs to report them.
     */
    'navigate' => [
        'show_progress_bar' => false,
        'progress_bar_color' => '#2299dd',
    ],
];
