# Ticket-Prompt-Tool — ersetzt durch Smart Tickets

Das bisherige Ticket-Prompt-Tool dieses Verzeichnisses ist durch die eigenständige lokale Anwendung Smart Tickets ersetzt (`AI6-053`). Sie zeigt die AI6-Tickets, erzeugt die Prompts und ändert den Ticketstatus.

Im Profil `ai6` sperrt die Regel `require_clean` jeden Statuswechsel, solange `php tools/validate_tickets.php` Fehler meldet; derzeit sind Fehler in bestehenden Tickets die Ursache dieser Sperre.

- Pfad: `G:/software_projekte/smart-tickets`
- Start im Smart-Tickets-Verzeichnis: `php artisan serve --host=127.0.0.1 --port=8092`
- Adresse: `http://127.0.0.1:8092/`
- Projekt-ID: `ai6`

Smart Tickets verwendet über sein Profil `ai6` weiterhin `ai/prompts/implementierung_master_prompt.md` und `tools/validate_tickets.php` aus diesem Repository. Beide bleiben deshalb erhalten; wer sie entfernt oder verschiebt, muss das Profil gleichzeitig anpassen.

Der letzte Stand des alten Tools (`api.php`, `index.html`, `tests/status_update_test.php`) stammt aus Commit `29d67fa` und ist über die Git-Historie wiederherstellbar.
