# AI6-048/MG-01 — GitHub-Copilot-CLI-Abnahme

Ergebnisfreie Vorlage. Kein bestandener Test, keine Signatur und keine Ticketstatusänderung. Der Auftraggeber führt die realen Tests nach Abschluss der Entwicklung aus. EXT-01 ist dafür eine Laufzeitvoraussetzung, kein Entwicklungsblocker.

## Bindung

| Feld | Vom Prüfer auszufüllen |
|---|---|
| Exakter Implementierungscommit, sauberer Arbeitsbaum | |
| Datum, Prüfer | |
| Linux-Plattform, Architektur, Image und Runtimeidentität | |
| Binaryherkunft, Archiv- und Binary-SHA-256 | |
| Tatsächliche Versionsausgabe und Pin | |
| Profil, Rolle, Modell, Effort | |
| Runtimehash, Einstellungenhash, Adapterhash | |
| Prompt- und Instruktionssnapshotbindung | |
| Credentialrevision, Herkunft der eigens bereitgestellten Testprojektion (keine Werte) | |
| Doctor-Evidenzschlüssel und getrennte Prüfausgabe | |

## Prüfablauf und tatsächliche Ergebnisse

| Prüfung | Tatsächliche Beobachtung und Nachweisreferenz |
|---|---|
| Homewurzel, Auth, Einstellungen, Workspace und `home/session-state` unter der Turnidentität read-only; echte verweigerte Schreibversuche | |
| Echter programmatischer Reviewturn über die Agentmailbox bis zum zentral validierten Ergebnis | |
| Versionsdrift, fehlender Pin und fehlende Capability sperren nur das betroffene Profil | |
| Nur gebundene Instruktionen; Host-, Parent-, Home- und Workspace-Köder einschließlich `CLAUDE.md`, `.claude`, Skills, Hooks und MCP | |
| Tatsächlich angeforderte Shell-, Schreib-, Delegations-, Memory-, URL- und MCP-Tools bleiben wirkungslos; zulässige Lesetools funktionieren | |
| CLI-Toolpolicy getrennt von der OS-/Agentrollen-Isolation geprüft; keine GitHub-Mutation | |
| Zwei Runs, Slots und Versuche getrennt; neue Invocation ohne Resume und ohne Historyübernahme | |
| Fehlende/fremde Projektion, falsche Revision, Rotation und Logout verweigern alte Credentials | |
| Timeout, Cancel, Startfehler und Providerfehler beenden alle Prozesse; keine verbliebenen Homes und keine späten Ergebnisse | |
| Promptmaximum einschließlich Wrapper und Instruktionslimits | |
| Eindeutige gültige Antwort, Fehlerzuordnung und gemeldete Nutzungswerte einschließlich null/0/unknown | |
| Smoke-Ausgabe `AI6_COPILOT_SMOKE_EVIDENCE` und verbleibende Abnahmeprüfungen | |

## Einordnung des Linux-Home-Ergebnisses

Vom Prüfer genau einen belegten Ausgang eintragen: erfolgreicher vertragskonformer Turn / ursächlich nachgewiesener Schreibschutzfehler / unklarer Ausgang. Auth-, Netzwerk- oder allgemeine Exitfehler allein belegen keine notwendige native Session-Schreibablage.

Ausgang und Begründung:

Nachweisreferenzen:

Ein inkompatibler oder ungeprüfter Pin bleibt gesperrt. Eine Schreibausnahme erfordert einen separaten begründeten Entscheidungsauftrag; dieses Protokoll erlaubt keine Laufzeitänderung. Die automatische Smoke-Prüfung ersetzt weder beobachtete tatsächliche Toolversuche noch die vollständige Agentrollen-Isolation.

## Menschliche Entscheidung

Ergebnis:

Offene Abweichungen:

Datum und Unterschrift:

Eine spätere Implementierungsänderung am geprüften Kandidaten erfordert neue Evidenz und Signatur. Historische Status- und Gateentscheidungen von AI6-042 bleiben eigenständig.
