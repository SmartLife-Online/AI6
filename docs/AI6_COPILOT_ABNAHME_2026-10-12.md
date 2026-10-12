# Copilot-Abnahme - Prüfergebnisse vom 12. Oktober 2026

Nicht signierter Ergebnisentwurf. Automatisierte Ausführung: Codex. Menschlicher Prüfer, Entscheidung und Unterschriften sind offen. Die ergebnisfreien MG-01-Vorlagen bleiben erhalten.

## Prüfstand

| Feld | Beobachtung |
|---|---|
| Implementierungscommit | 5a6b32b0d7540a7d7add4cf5503a71ad85b29d59 |
| Testcommit | f5554e20478021105ff044ed3d810b0a6db570a4 |
| Image | ai6-upgrade:5a6b32b-copilot-negative-fix-20261012 |
| Image-Digest | sha256:8301a2d10700bd4adff9fd144063077099e5c51a9b0f6fa6548bdb139f62644d |
| Git-Archiv SHA-256 | 39334e2d29451df3ae26c82b92af9b988ed8bf56c3f7f042c1b2bf0d610b458f |
| Runtime | Linux x86_64; Copilot CLI 1.0.83; Runtimeversion 2; Effort provider_default |
| Modellauswahl | auto - das tatsächlich verwendete Backendmodell ist nicht gemeldet |
| Produktion / Zusatzprobe | Agent UID 10002:GID 10001; ai6-agent-v1 (enforce); Agent ohne primäre DB und Produktions-APP_KEY |
| Realtest-Harness | Separate Testdatenbank und Volumes; UID 1000; echter Token ausschließlich im getrennten Agentstore |
| Dienste nach Auslieferung | app, worker, scheduler, agent, checker und caddy: healthy; AI6 und Smartlife: HTTP 200 |
| Sicherung | /var/lib/ai6-offline-backups/20261011T221759Z; vier verschlüsselte Archive lokal SHA-256-verifiziert |
| Produktionsfreigabe | AI6_COPILOT_CAPABILITY_EVIDENCE bleibt leer; keine Signatur und keine Ticketstatusänderung |

## Tests und Auslieferung

| Prüfung | Ergebnis |
|---|---|
| Native Negativmatrix | 22 Fälle bestanden: Tools, Parent, Home/Workspace-Discovery, Timeout, Cancel, Providerfehler, fehlende Projektion/Credentials, stale, Rotation, Logout, fremder Kontext, fehlende/falsche Evidenz und Wiederherstellung. Bereinigung in allen Fällen bestätigt. |
| Abschließender Gesamtlauf | Gemeinsamer abschließender Lauf: 25 Tests, 2,153 Assertions, Exit 0, keine Skips. Darin 22 native Negativfälle; QA nothing_to_fix / nothing_to_fix; Security clear und security_findings. Testtokenprojektionen bereinigt. |
| Historischer Fehler und Abschluss | Voller nativer Lauf: 25 Tests, 2.144 Assertions, Exit 1 wegen falscher kritischer Testannahme. Test-only Fix f5554e2; Security-Rerun 2 Tests / 592 Assertions / Exit 0. Jetzt zusätzlich gemeinsamer Gesamtlauf aller 25 nativen Tests bestanden; Details und aktuelle Ergebnisbindungen im final-full-run.json. |
| Linux-Regression | 138 Tests, 11.534 Assertions, 6 opt-in skips; Exit 0. |
| UID 10002 / Auslieferung | Gültiges inconclusive; Schreibschutz und Cleanup bestanden, Credentialgeneration unverändert. Alle sechs Dienste healthy, AI6 und Smartlife HTTP 200. |
| PHPStan / Pint / Verträge | Vollständige Analyse und Formatprüfung bestanden; geänderte Testdatei zusätzlich gezielt geprüft. Manifest, Composer strict/platform und Diffprüfung bestanden. |
| Windows / vorherige Belege | Feature-Regression: 25 passed, 17 POSIX/opt-in skips, 2.805 Assertions. Frühere Gesamtsuite und explizite Locked-Installation bleiben historische Belege; kein neuer Gesamtlauf behauptet. |
| Securityreview-Fehler | Am Stand 6bb017f wurde derselbe echte Security-Persistenzfehler mit finding_disposition_unknown reproduziert: Das ausgegebene Schema erlaubte freie Dispositionen, die die zentrale Persistenz zurückwies. Fix 5a6b32b bindet Severity, Disposition, Kategorie und Status an die vorhandenen Enums sowie positive Zeilennummern. Keine JSON-Reparatur oder Lockerung. Der ursprüngliche Einzelaufruf an 7ab5616 hat keine rückwirkend beweisbare Detailursache. Ein zusätzlicher Test erwartete jeden Befund als kritisch; f5554e2 korrigiert ausschließlich diese Testannahme anhand tatsächlicher Severity. Echter Security-Rerun und jetzt abschließender gemeinsamer 25-Testlauf bestanden. |

## AI6-048/MG-01 - tatsächliche Beobachtungen

| Prüfung | Stand | Beobachtung |
|---|---|---|
| Geschützte Eingaben / Session | Belegt | UID 10002: Home-, Workspace- und ungebundene Session-Writes verweigert. Frische RW-Session pro Turn; danach leer. |
| Programmatischer Review | Belegt | Zwei gültige QA-Ergebnisse über reale Agentmailbox: nothing_to_fix / nothing_to_fix; getrennte Slots und Sessions. Frühere QA-findings_to_fix-Beobachtung bleibt im historischen Nachweis. |
| Pin / Version / Capability | Belegt | Drift in Regression gesperrt. Native fehlende, falsche Rolle und kontrollierte Testwiederherstellung geprüft; Produktionsschlüssel leer. |
| Discovery / Instruktionsköder | Belegt | Native Parent-/Home-/Workspace-Matrix für AGENTS, CLAUDE, Skills, Hooks und MCP geprüft. Fremde ungebundene Discovery gesperrt; Hostköder nicht erreichbar. |
| Verbotene native Tools | Begrenzt | Shell, Write, Delegation, Memory, URL und MCP angefordert, aber nicht verfügbar und nicht ausgeführt. Beobachtet: view/glob/rg (grep-Alias). Keine Behauptung einer separaten nativen RPC-Ablehnung für jede Toolart. |
| CLI-Policy / OS-Isolation | Belegt | AppArmor enforce, read-only Eingaben, keine Gitmetadaten oder DB/APP_KEY im Agent. Keine verbotene Datei oder Toolausführung. |
| Runs / Slots / Versuche | Belegt | Frische gebundene Sessions; fremder Versuchkontext abgewiesen. Zwei reale QA-Slots; eigenständiger vollständiger Live-Pilot bleibt separat. |
| Credentials / Rotation / Logout | Belegt | Reale isolierte Rotation: neue Generation authentifiziert, alte gesperrt. Logout, fehlende Credentials/Projektion und stale geprüft. Produktiver Store unverändert. |
| Timeout / Cancel / Prozessende | Belegt | Echter CLI-Start für Timeout, Cancel und Providerfehler; Prozessende bestätigt, keine verbleibenden nativen Prozesse, Session bereinigt. |
| Prompt- / Instruktionslimits | Automatisiert | Unveränderte zentrale Grenz- und Fehlerpfadtests bestanden. |
| Antwort / Nutzung | Belegt | Zentrale Validierung und gemeldete Nutzung; auto ohne behauptetes Backendmodell. Schemaenumfehler bleibt fail closed. |
| V2-Smoke / Unterschrift | Offen | Technische Belege vorgelegt. Menschliche Sichtung, Entscheidung und Signatur fehlen; keine dauerhafte Livefreigabe. |

## AI6-050/MG-01 - tatsächliche Beobachtungen

| Prüfung | Stand | Beobachtung |
|---|---|---|
| Profil / eigenes Tupel | Belegt | security_review / auto / provider_default; Schlüssel exakt an Binary, Runtime und Implementierungsstand gebunden. Nur isolierte Testfreigabe. |
| Produktions-Doctor | Nach Signatur | strict aktiv. Copilot degraded wegen fehlender menschlicher Evidenz; nach signierter Freigabe und Aktivierung erneut prüfen. |
| Candidate / Mailbox | Belegt | Reale SHA-256-Git-Candidates, Produktions-SecurityReviewStep, native Agentmailbox, gespeicherte ReviewResults in eigener Testdatenbank. |
| Candidate / Credentials | Belegt | Read-only Export ohne Gitmetadaten; Token Agent-only. UID-10002-Zusatzprobe am ausgelieferten Image bestanden. |
| Gespeicherte Ergebnisse | Belegt | clear und security_findings mit Candidate, Diff, Basis, Ticket, Scope, Prompt, Instruktionen und Policy gebunden. |
| Clear / Gate / Override | Belegt | clear setzt zur Publishphase fort. security_findings hält am menschlichen Gate; Override hängt von tatsächlich kritischer Severity ab. Kein Push. |
| Fehlende / falsche Evidenz | Belegt | Native Negativmatrix verweigert ohne Securityevidenz; QA-Evidenz allein gibt Securityrolle nicht frei. |
| Wiederherstellung | Belegt | Isolierte Testevidenz kontrolliert wiederhergestellt und nativer gültiger Turn geprüft; dauerhafte Produktionsevidenz unverändert leer. |
| Schemafehler / Ursache / Fix | Belegt | finding_disposition_unknown real reproduziert. Schema bindet nun bestehende Persistence-Enums. Unbekannte Disposition wird weiterhin abgewiesen. |
| Smokes / menschliche Sichtung | Offen | clear und Findings real geprüft; vorgelagerte Implementierung/Checks synthetische Fixtures. Eigenständiger Live-Pilot, Sichtung und Signatur bleiben menschlich. |

## Exakte Bindungen

| Feld | Wert |
|---|---|
| QA-Evidenzschlüssel | `26d82130fa11ac5b8edb6062a14d937f215afb0581a008f3b0f805b58b5581e0` |
| Security-Evidenzschlüssel | `6db6db7a370b54bedd970010d65219df8d2f0dc18f3083e4bc8a7bfdd12bb8b4` |
| Runtimehash | `813996fbd7ddb27b36000265a5d05a44dc4ebcfa8d635a589713abdb384c744e` |
| Adapter SHA-256 | `ca643451228d79a90862defec2f0f48d9eeddaca97f2c783edbccfd8a36c07f0` |
| Binary SHA-256 | `99eb441db5e665ed559364f9cb293543d8200bdb37d5466bcea0e7bae59b3dcb` |
| AppArmor SHA-256 | `37f491c53a7d0ccdcc500609a6cda8dae1d057e2b5497cb06e22feae9fbaecde` |
| Native Release-Archiv SHA-256 | `ffbe1c429664b8a05efed67ecdb467123e40fcaa3c6c14ef9a98ba74da4687b7` |
| Basisimage-Digest | `sha256:7e353e582335f3a5900b7c42bbc7a1da99e9507e286550fa152a2b08692015c2` |
| QA Run / Ergebnisse | `d3e39421-9cb6-45b2-baf3-dc019366bf31 / b27146e5-9cbb-4511-baf7-47b343805733, 798f43a3-d5e2-47e3-8d9a-ab1f94c61737` |
| Security Run / Session | `ff471035-4dd0-4f4c-9a36-2a958acbf7a8 / a7711b21-bf01-4a90-98c0-2832a77130ba` |
| Security Slot / Ergebnis / Artefakt | `74a99020-1f30-4260-8e93-7f17586d0219 / e7982fc2-c2e3-4d1a-af6f-c2e63f09f911 / 5a5622e2-b779-4a5b-ae5b-ce31e7ba9b15` |
| Candidate Tree-OID (SHA-256 Git) | `234dd8fd3d53de3a424ef7f499779290eb6563ed4540c41bc0367fc9b3cba636` |
| Candidate Diff / Basis-OID | `86a8c85fd55499050202f905c8fe879d6af6b087ac044406ff4be0475174b6b8 / 03519f2cb1ea0694cfb9bd476cfb8b3e0d2a03f47fe7d00493a1a85dd883b735` |
| Ticketvertrag / Scope | `b1686df9a91c1fb6feccbe767e9ca65a200d240ef3fef2a22d99f504d935d2f1 / de3d5381dc6ae3220ad60000db0f25102a8ec8b9f49d20f6cb9f4f2266a38435` |
| Prompt / Instruktionen | `1807ba2b6e956862e46b07b2e4c97d84e12621fef9e52b6c57cfe009b99a7c67 / 2607aa293259402f0f45ddb0de601915fb633c686ca0a6a1e618df85977936c2` |
| Security Policyhash | `f6da67712bc4b52c98469acb5fd6f555ed09ed8f461c23f957cb52223d8a4674` |
| Gemeldete Securitynutzung | `{"premium_requests": 1, "api_duration_ms": 9396}` |
| Findings Run / Ergebnis | `715067cd-d84f-4167-8493-1bcd77be812c / e860fbd8-3052-42b7-a5c9-04a88f3a499b` |
| Findings Tree / Diff | `97a43a0630fdfbf21c902829e9cb2336c58f043580925a91986330a613f38799 / a7e83c79be6b4862f4bccef289beb502a10b727e962e9f96f86ed4dacd0f9a23` |

## Geklärter Securityreview-Fehler

Am Stand 6bb017f wurde derselbe echte Security-Persistenzfehler mit finding_disposition_unknown reproduziert: Das ausgegebene Schema erlaubte freie Dispositionen, die die zentrale Persistenz zurückwies. Fix 5a6b32b bindet Severity, Disposition, Kategorie und Status an die vorhandenen Enums sowie positive Zeilennummern. Keine JSON-Reparatur oder Lockerung. Der ursprüngliche Einzelaufruf an 7ab5616 hat keine rückwirkend beweisbare Detailursache. Ein zusätzlicher Test erwartete jeden Befund als kritisch; f5554e2 korrigiert ausschließlich diese Testannahme anhand tatsächlicher Severity. Echter Security-Rerun und jetzt abschließender gemeinsamer 25-Testlauf bestanden.

## Menschliche Entscheidungen

Technische Nachweise sind vorgelegt. Vor Unterzeichnung die Beobachtungen und Begrenzungen sichten. Signatur und dauerhafte Laufzeitfreigabe bleiben eine menschliche Entscheidung; unabhängige Pilot- und Releasegates gelten zusätzlich.

### AI6-048/MG-01

Prüfer:

Ergebnis:

Offene Abweichungen und ergänzende Nachweise:

Datum und Unterschrift:

### AI6-050/MG-01

Prüfer:

Ergebnis:

Offene Abweichungen und ergänzende Nachweise:

Datum und Unterschrift:

## Aktivierung nach vollständiger signierter Abnahme

Erst nach der menschlichen Entscheidung werden die passenden rollenspezifischen Schlüssel in der vertrauenswürdigen VPS-Umgebung eingetragen. Keine Schlüssel aus früheren Implementierungsständen verwenden. Die Dienste übernehmen die Umgebung durch Neuerstellung; der aktuelle Agentbericht muss anschließend die freigegebenen Tupel als ready ausweisen. Der Produktions-Doctor ist erneut zu prüfen. Andere Provider und weitere offene Release-/Pilotgates bleiben eigenständig.

```bash
sudo ai6-compose exec -T worker php artisan ai6:doctor --security --require-strict
```

## Nachweisdateien

Aktuelle wertfreie Nachweise liegen unter docs/abnahmen/copilot-2026-10-12/schema-fix/. Ältere Dateien im Elternverzeichnis bleiben historische Belege zum vorherigen Stand; die aktuelle SHA-256-Datei inventarisiert Bericht, PDF und neue Nachweise. Providerrohtexte und Credentials sind nicht enthalten. Die native Testdatenbank und temporären Tokenprojektionen wurden nach den Läufen bereinigt; IDs dokumentieren gespeicherte Ergebnisse des Testlaufs, keine fortbestehenden Produktionsobjekte.

## Referenzen

- docs/AI6-048_MG-01_ABNAHMEPROTOKOLL.md
- docs/AI6-050_MG-01_ABNAHMEPROTOKOLL.md
- deploy/copilot-acceptance.Dockerfile und zugehörige Kontext-Allowlist
- https://docs.github.com/en/copilot/reference/copilot-cli-reference/cli-command-reference#tool-permission-patterns
