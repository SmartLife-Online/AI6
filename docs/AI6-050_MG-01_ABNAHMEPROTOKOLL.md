# AI6-050/MG-01 — Realer Copilot-Securityreview

Ergebnisfreie Vorlage. Kein bestandener Nachweis, keine Signatur und keine Ticketstatusänderung. Die bestehende Linux-Instanz wird wiederverwendet. Voraussetzung ist die Auslieferung des exakten Implementierungscommits; die Bereitstellung der Instanz ist keine Evidenz. AI6-048/MG-01 und AI6-048/EXT-01 bleiben eigenständige offene Gates.

## Bindung

| Feld | Vom Prüfer auszufüllen |
|---|---|
| Exakter Implementierungscommit, sauberer Arbeitsbaum und Auslieferungsnachweis | |
| Datum und Prüfer | |
| Linux-Plattform, Architektur, Image und unprivilegierte Runtimeidentität | |
| Binaryherkunft, Binary-SHA-256, Versionsausgabe und Pin | |
| Profil, Rolle `security_review`, Modell und Effort | |
| Runtimehash, Adapterhash und Einstellungenhash | |
| Rollenspezifischer Evidenzschlüssel | |
| Credentialrevision und Testauthherkunft (keine Geheimniswerte) | |
| Run-ID, Session-ID, Agent-Slot und gespeicherte Ergebnis-/Artefakt-ID | |
| Candidate-Tree-OID, Diff-Hash und Basis-OID | |
| Ticketvertrag und Scopehash | |
| Prompt- und Instruktionssnapshot-Hashes | |
| Approval-Snapshot-Hash und Policyhash | |
| Implementierungsprovider und unabhängiger Securityprovider | |

## Prüfablauf und tatsächliche Ergebnisse

| Prüfung | Tatsächliche Beobachtung und Nachweisreferenz |
|---|---|
| `AI6_AGENT_SECURITY_REVIEW_PROFILE=copilot-cli-review` erreicht ausschließlich `worker`; eigenes Security-Tupel ist `ready` | |
| `docker compose exec worker php artisan ai6:doctor --security --require-strict`: Securityreview-Profil bestanden, Profil und Adapter genannt, kein `security_review_adapter_fake` | |
| Echter Run erreicht `security_review`; Agentmailbox übergibt exakt den gebundenen Candidate und unveränderte Snapshots | |
| Candidate-Workspace, Home und Instruktionen read-only; keine erreichbaren Gitmetadaten oder Worker-Credentials | |
| Zentral validiertes `clear`, `security_findings`, `needs_human` oder `inconclusive` als gebundenes `ReviewResult` gespeichert; Rohartefakt mit gemeldeter Nutzung oder `unknown` | |
| Nur gebundenes `clear` setzt zum Publish fort; andere Ergebnisse parken unter `security_gate`; kritisches Finding bietet den bestehenden Override an | |
| Rollenspezifischen Evidenzschlüssel absichtlich entfernen: Doctor verweigert das Profil und der Securityturn startet keinen Provider; Qualitätsreview-Evidenz allein genügt nicht | |
| Evidenz kontrolliert wiederherstellen und aktuellen Agentbericht prüfen; keine stillschweigende Freigabe | |
| Provider-, Schema- und Runtimefehler erlauben kein `clear`; Wartegrund und Request enthalten keine Providertexte oder Credentialbytes | |
| Securitysmoke mit `AI6_RUN_COPILOT_SMOKE=1`: gebundene Ausgabe `AI6_COPILOT_SMOKE_EVIDENCE` | |

Der Smoke allein ersetzt weder die vollständige Agentrollen-Isolation noch den gebundenen Candidate-Run und die menschliche Prüfung. Ein Windows- oder Fake-Lauf ist keine reale Copilot-Abnahme. Ein unklarer Ausgang bleibt offen; er rechtfertigt keine Schreib-, Tool- oder Netzfreigabe.

## Menschliche Entscheidung

Ergebnis:

Offene Abweichungen und Nachweisreferenzen:

Datum und Unterschrift:

Jede spätere Änderung an Implementierungsbytes erfordert neue Evidenz und Signatur. Jede Änderung an den Copilot-Adapterbytes entwertet auch die bestehenden Copilot-Evidenzschlüssel.
