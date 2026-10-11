# Copilot-Abnahme - Prüfergebnisse vom 12. Oktober 2026

Nicht signierter Ergebnisentwurf. Automatisierte Ausführung: Codex. Menschlicher Prüfer, Entscheidung und Unterschriften sind offen. Die ergebnisfreien MG-01-Vorlagen bleiben erhalten.

## Prüfstand

| Feld | Beobachtung |
|---|---|
| Implementierungscommit | 6eb8f9f58c7925a30467e44f37dc638dc67c0157 |
| Image | ai6-upgrade:6eb8f9f-copilot-acceptance-20261012 |
| Image-Digest | sha256:2da43729882e62145ab2413ba141873da496ea7b27da7a396c08330ef8b15f73 |
| Git-Archiv SHA-256 | ed385abd76127a28c3edbf293e08efa0a0685246d1ba4a84ed874964df30c493 |
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
| Reguläre Suite, vier Partitionen | 2.336 Tests; 412 skips; zunächst ein Mail-Fixture-Fehler. Betroffene Klasse nach Korrektur vollständig bestanden; kein zweiter Gesamtlauf behauptet. |
| FixLoop nach Fixturekorrektur | 11 Tests, 2.059 Assertions, bestanden; SMTP-Testdouble statt unbeabsichtigtem Zustell-Retry. |
| Finale Linux-Regression | 135 Tests, 11.237 Assertions, fünf ausdrücklich übersprungene opt-in Smokes; bestanden am endgültigen Commit und Image. |
| Echter V2-Mailbox-Smoke | 2 Tests, 486 Assertions; zwei nothing_to_fix-Qualitätsreviews und ein clear-Securityreview. Getrennte Sessions; Mailbox vollständig bereinigt. |
| Native Produktionsidentität | UID 10002; gültiges inconclusive; geschützte Writes verweigert, Home unverändert, Session bereinigt, Credentialgeneration unverändert. |
| PHPStan / Pint | Vollständige Analyse und Formatprüfung bestanden; spätere Änderungen zusätzlich gezielt analysiert. |
| Installation / Composer | Explizit PHP 8.5.5 und Composer: 2 Tests, 1.309 Assertions; Composer strict/platform, Manifest und Diffprüfung bestanden. |
| Auslieferungsfehler | Erster Overlaystart wegen fehlendem Entrypoint-Ausführungsrecht abgebrochen, automatischer Rollback erfolgreich. Korrigierter Build und endgültiger Rollenstart geprüft. |
| Offene reale Abweichung | Ein vorheriger Securitysmoke speicherte kein gültiges ReviewResult; kein clear und keine Freigabe. Nach wertfreier Diagnoseerweiterung nicht reproduziert. Genaue Ursache damals nicht erfasst; vor vollständiger Abnahme einordnen. |

## AI6-048/MG-01 - tatsächliche Beobachtungen

| Prüfung | Stand | Beobachtung |
|---|---|---|
| Geschützte Eingaben / Session | Belegt | UID 10002: Writes an Home, Workspace und ungebundener Session verweigert; Kindprüfer kontrolliert ausschließlich die frische RW-Session. Nach Turn leer. |
| Programmatischer Review | Belegt | Zwei zentral gültige Qualitätsreviews über echte Agentmailbox, getrennte Sessions; nothing_to_fix. |
| Pin-, Versions- und Capabilitydrift | Automatisiert | Linux-/Vertragstests verweigern betroffene Auswahl. Produktionsschlüssel bleiben leer. |
| Instruktionsköder / Discovery | Teilnachweis | Gebundene Snapshots und negative Fixtures geprüft. Vollständige beobachtete native Ködermatrix bleibt menschlich zu ergänzen. |
| Verbotene native Toolversuche | Offen | Exakte Toolmenge view/glob/grep, read-Berechtigung und OS-Schreibschutz geprüft. Tatsächliche Versuche aller verbotenen nativen Toolarten sind nicht vollständig beobachtet. |
| CLI-Policy und OS-Isolation | Belegt | Shipped AppArmor/Seccomp; keine erreichbaren Gitmetadaten im Export; keine Shell-, Schreib-, URL- oder MCP-Freigabe. |
| Runs, Slots, Versuche | Teilnachweis | Zwei Slots und Sessions getrennt; frische Invocation und Bereinigung. Vollständige reale Zwei-Run-Abnahmematrix noch offen. |
| Credentials / Rotation / Logout | Teilnachweis | Echter Token nur Agent; Worker-Teststore enthält ausschließlich Testplaceholder. Produktive Generation unverändert. Store-/Rotationsregression grün; reale native Rotations-/Logoutbeobachtung offen. |
| Timeout, Cancel, Prozessende | Teilnachweis | Echte Linux-Prozess-/Mountnegativmatrix einschließlich unbestätigtem Ende; native Testdouble-Fehlerpfade. Vollständige echte Copilot-Cancel-/Timeoutbeobachtung offen. |
| Prompt- und Instruktionslimits | Automatisiert | Vertragstests einschließlich Grenz- und Fehlerpfaden bestanden. |
| Antwort und gemeldete Nutzung | Belegt | Zentrale Validierung, eindeutige native JSONL-Antwort; gemeldete Nutzung, null/0/unknown in Regression. Backendmodell wird nicht aus auto abgeleitet. |
| V2-Smoke und Signatur | Teilnachweis | Realer V2-Smoke bestanden. Menschliche Entscheidung und Unterschrift bleiben leer. |

## AI6-050/MG-01 - tatsächliche Beobachtungen

| Prüfung | Stand | Beobachtung |
|---|---|---|
| Securityprofil und eigenes Tupel | Teilnachweis | copilot-cli-review / security_review / auto / provider_default im Kandidatentest. Produktionsfreigabe noch leer. |
| Produktions-Doctor | Offen | strict-Schutz aktiv; Copilot bleibt wegen fehlender menschlicher Evidenz degraded. Nach Signatur und Aktivierung erneut prüfen. |
| Realer Candidate / Mailbox | Belegt | Echter SHA-256-Git-Candidate, Produktions-SecurityReviewStep, native Agentmailbox und gespeichertes ReviewResult in separater Testdatenbank. |
| Candidate und Credentials | Belegt | Read-only Export ohne Gitmetadaten; Credentials nur im Agent. UID-10002-Zusatzprobe am selben Image bestanden. |
| Gespeichertes Ergebnis | Belegt | clear; Candidate-Tree, Diff, Basis, Ticket, Scope, Prompt, Instruktionen und Policy gebunden; provider_raw-Artefakt mit gemeldeter Nutzung. |
| Clear / Gate / Override | Teilnachweis | Reales clear setzt zur Publishphase fort; kein Push. Andere Status und Override in Linux-Testdouble-Regression geprüft; menschliche Sichtung bleibt offen. |
| Fehlende / falsche Rollenevidenz | Automatisiert | Negative Regression sperrt Providerstart; QA-Evidenz gibt Securityrolle nicht frei. |
| Kontrollierte Wiederherstellung | Offen | Dauerhafte Evidenz wurde nicht gesetzt. Nach menschlicher Entscheidung aktuellen Agentbericht und Doctor prüfen. |
| Provider-/Schema-/Runtimefehler | Automatisiert | Fehlerpfade schließen fail closed; keine automatische Umdeutung in clear. |
| Securitysmoke / Signatur | Teilnachweis | Echter Securitysmoke bestanden. Vorgelagerte Implementierung und Checks sind synthetische Fixtures, kein vollständiger Live-Pilot. |

## Exakte Bindungen

| Feld | Wert |
|---|---|
| QA-Evidenzschlüssel | `053e7cf4ae51444e4022f97b81dd081590312731b0af3750e84f24a38036197b` |
| Security-Evidenzschlüssel | `2c945da949e8ff55506f2e39cd557f28a0ec96336c33b4726e435f2a04a6c2aa` |
| Runtimehash | `813996fbd7ddb27b36000265a5d05a44dc4ebcfa8d635a589713abdb384c744e` |
| Adapter SHA-256 | `ca643451228d79a90862defec2f0f48d9eeddaca97f2c783edbccfd8a36c07f0` |
| Binary SHA-256 | `99eb441db5e665ed559364f9cb293543d8200bdb37d5466bcea0e7bae59b3dcb` |
| AppArmor SHA-256 | `37f491c53a7d0ccdcc500609a6cda8dae1d057e2b5497cb06e22feae9fbaecde` |
| Native Release-Archiv SHA-256 | `ffbe1c429664b8a05efed67ecdb467123e40fcaa3c6c14ef9a98ba74da4687b7` |
| Basisimage-Digest | `sha256:7e353e582335f3a5900b7c42bbc7a1da99e9507e286550fa152a2b08692015c2` |
| QA Run / Ergebnisse | `f9e28bf0-cdb5-4394-8a09-d5f6447b746f / 20c54f69-83a0-4c72-ab46-08d6da3f357f, 5856f45e-a293-44b5-a3a8-92658e4a8a84` |
| Security Run / Session | `b1c7f2e4-8686-4fff-8db5-68255514c64c / ebbc8024-6406-4cce-ade3-a3f8c9197d0c` |
| Security Slot / Ergebnis / Artefakt | `be934f00-03e6-402b-a284-8b6c27d96a1b / 56a01252-0079-4ca4-b86b-bb0addf2edd6 / c2c53a34-00c9-4930-b97d-ce5602dbdd7c` |
| Candidate Tree-OID (SHA-256 Git) | `234dd8fd3d53de3a424ef7f499779290eb6563ed4540c41bc0367fc9b3cba636` |
| Candidate Diff / Basis-OID | `86a8c85fd55499050202f905c8fe879d6af6b087ac044406ff4be0475174b6b8 / 754da51a289abdf8671626ff55e7df3b215ad82a4608278fde3a6409ba10e207` |
| Ticketvertrag / Scope | `b1686df9a91c1fb6feccbe767e9ca65a200d240ef3fef2a22d99f504d935d2f1 / de3d5381dc6ae3220ad60000db0f25102a8ec8b9f49d20f6cb9f4f2266a38435` |
| Prompt / Instruktionen | `4038811ed947035a942ccc5fc252fd97ce3d99a17550ffe6700af15dcefafe22 / 2607aa293259402f0f45ddb0de601915fb633c686ca0a6a1e618df85977936c2` |
| Security Policyhash | `f6da67712bc4b52c98469acb5fd6f555ed09ed8f461c23f957cb52223d8a4674` |
| Gemeldete Securitynutzung | `{"premium_requests": 1, "api_duration_ms": 18494}` |

## Offene Abweichung aus echtem Lauf

Am Stand 7ab5616 gab es einen roten Securitysmoke ohne gespeichertes gültiges ReviewResult. Die davor und danach erfolgreichen Läufe überschreiben diese Beobachtung nicht. Die wertfreie Ursache war damals noch nicht erfasst; die ergänzte Diagnose am Stand 6eb8f9f reproduzierte den Fehler nicht. Eine uneingeschränkte Abnahme wird vor Einordnung dieser Abweichung und Ergänzung der offenen menschlichen Prüfungen nicht empfohlen.

## Menschliche Entscheidungen

Offene Zeilen sind vor einer vollständigen Abnahme mit tatsächlicher Beobachtung und Nachweisreferenz zu ergänzen. Die vorhandenen Testergebnisse ersetzen diese Beobachtungen nicht. Eine Unterschrift unter einen Teilnachweis ist keine vollständige Livefreigabe.

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

Die wertfreien JSON-Bindungen, Rolloutprüfung, native Logzusammenfassungen und SHA-256-Inventare liegen neben diesem Dokument unter docs/abnahmen/copilot-2026-10-12/. Providerrohtexte und Credentials sind nicht enthalten. Die native Testdatenbank und temporären Tokenprojektionen wurden nach den Läufen bereinigt; IDs dokumentieren gespeicherte Ergebnisse des Testlaufs, keine fortbestehenden Produktionsobjekte.

## Referenzen

- docs/AI6-048_MG-01_ABNAHMEPROTOKOLL.md
- docs/AI6-050_MG-01_ABNAHMEPROTOKOLL.md
- deploy/copilot-acceptance.Dockerfile und zugehörige Kontext-Allowlist
- https://docs.github.com/en/copilot/reference/copilot-cli-reference/cli-command-reference#tool-permission-patterns
