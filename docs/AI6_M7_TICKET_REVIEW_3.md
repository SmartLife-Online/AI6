# Dritte Prüfliste zu AI6-036, AI6-037 und AI6-038

Stand: 19. September 2026. Geprüfte Codebasis: `a7d83e9` (`AI6-035`) mit den uncommitteten Arbeitsbaumänderungen aus Planrevision V1.7.9. Normative Grundlage: Plan V1.7.9 (§3, §5.4, §8.6–8.7, §12, §13, §15.8, §18–§21), Ticket-Template V1 und `AGENTS.md`. Diese Liste verändert weder Tickets noch Plan, Status oder Gate-Ergebnisse.

**Verhältnis zu den bisherigen Reviews.** Die drei Ticketdateien sind byteidentisch mit dem Stand, den `docs/AI6_M7_TICKET_REVIEW_2.md` geprüft hat (SHA-256 am Ende dieser Datei stimmen mit dessen Tabelle überein); das zweite Review ist also noch **nicht** eingearbeitet. Diese Liste ist eigenständig verwendbar: Sie enthält in Abschnitt 5 eine Bestätigungstabelle zu jedem Punkt des zweiten Reviews (jeder Punkt wurde erneut gegen den Code geprüft; abweichende Urteile sind markiert) und in den Abschnitten 1–4 ausschließlich **neue** Befunde, die das zweite Review nicht enthält. Für die Überarbeitung genügt deshalb diese Datei plus die Detailtexte des zweiten Reviews zu den dort referenzierten Kennungen.

**Gesamturteil.** Struktur, Blueprinttreue, Sprachgrenze, Coverage und Serialisierung der drei Entwürfe sind in Ordnung (erneut mit `TicketV1Parser` und `Ai6DetailV1TicketValidator` geprüft: null Fehler; `files` gleich Scope; `new`/`existing` gegen den Dateibestand korrekt; Manifest `--check` aktuell). Die neuen Befunde betreffen vier Sorten von Problemen: **(a)** Voraussetzungen, die im Code anders liegen als im Ticket beschrieben und den Pilot verfälschen würden (der FakeAgent sitzt heute im produktiven Verifierpool; die Compose-Rollen erhalten das Securityreview-Profil gar nicht); **(b)** ein Kernfeature ohne realen Input (im gesamten Repository und seiner Geschichte existiert kein Ticket im Legacy-Codeblock-Format, die Eingabetabelle von AI6-037 ist damit eine Annahme); **(c)** Bedienschritte, die ein anderer Entwickler braucht und die kein Ticket nennt (`return_to_todo` zwischen Review-only- und Implementierungslauf, Schlüsselerzeugung ohne Host-PHP, Reviewgegenstand des Review-only-Piloten); **(d)** Zusagen, die eine Prüfung im Code nicht halten kann (Retention-Elternverzeichnis vs. rekursives `mkdir`, Golden-Fixture-Hashes bei Katalogversion 3). Leitlinie für die Überarbeitung bleibt: **vorhandene Ergebnisse konsumieren, Mechanik streichen, jede Zusage vor der Formulierung einmal auf dem echten Pfad ausführen.**

## Verwendung durch das prüfende LLM

Die Punkte sind **Prüfvorschläge, keine freigegebenen Zusatzanforderungen**. Jeder Punkt ist gegen den dann aktuellen Code und Plan neu zu prüfen; Vorschläge dürfen begründet verworfen oder zusammengeführt werden. Vorrang hat immer die Variante mit **weniger** Klassen, Optionen, Tabellen, Tests und Dokumentation, solange Blueprint, Requirement-Refs und Sicherheitsinvarianten erfüllt bleiben. Ein Befund darf niemals durch ein neues Subsystem, einen neuen Schalter oder eine neue Abstraktion „gelöst“ werden, wenn eine Streichung, eine Präzisierung oder ein README-Satz genügt.

Prioritäten:

- **P1:** Vor Umsetzung auflösen — der Text verspricht etwas Unerreichbares, beruht auf einer falschen Codeannahme, erzeugt einen unbrauchbaren Test oder ein Gate, das nicht ehrlich geschlossen werden kann.
- **P2:** Konkrete Präzisierung, Vereinfachung oder fehlender Bedien-/Nachweisschritt mit erkennbarem Nutzen.
- **P3:** Redaktionell oder klein; nur übernehmen, wenn es Aufwand spart oder eine Fehlbehauptung entfernt.

Kennungen: `D06`–`D09` setzen die Entscheidungsliste des zweiten Reviews fort (`D01`–`D05` bleiben gültig und werden in Abschnitt 5 bestätigt). `N36-xx`, `N37-xx`, `N38-xx` sind neue Befunde je Ticket, `NQ-xx` übergreifend. Alle Kennungen sind Reviewreferenzen und ersetzen keine `AC-`/`TC-`/`MG-`-IDs. Die drei Tickets sind unveröffentlichte Entwürfe (Plan §13.7); ihre IDs dürfen noch bewegt werden, sofern `## Notes` alte und neue Bezeichnung nennt.

---

## 0. Zusätzlich zu klärende menschliche Entscheidungen

Weder das prüfende LLM noch ein Implementierer kann diese Punkte entscheiden. Sie kommen zu `D01`–`D05` des zweiten Reviews hinzu (Bestätigung dort in Abschnitt 5).

### D06 — Der FakeAgent ist in Produktion auswählbar und sitzt im Verifierpool jeder Approval

**Betroffen:** AI6-038 `## Context` (Satz zu `VerifierSlotSelector` und HumanLoop), Task 3/4 (Slot-Tabelle), AC-02, Voraussetzungstabelle; mittelbar AI6-036 Task 4 (Fake-Securityprofil).

**Befund (im Code geprüft):** Das Profil `fake` steht mit `capability_status: available` und allen vier Rollen im produktiven Profilregister (`config/ai6.php:310–318`); `CapabilityStatus::selectable()` ist dafür `true`, und `AgentProfileRegistry::get()`/`resolve()` behandeln den Alias `fake` ohne Bericht als auswählbar (`app/AI6/Agents/AgentProfileRegistry.php:184–194, 230–241`). `VerifierCandidatePoolFactory::all()` nimmt jedes auswählbare Profil mit Rolle `finding_verification` in den Pool und sortiert nach Profil-ID (`app/AI6/Reviews/VerifierCandidatePoolFactory.php:14–40`); `ApprovalSnapshotFactory` bindet genau diesen Pool, weil die Approval-Seite keine Verifierauswahl kennt (`app/AI6/Runs/ApprovalSnapshotFactory.php:94–96`, kein `verifier`-Bezug in `TicketApprovalPage`). `VerifierSlotSelector::select()` nimmt den **ersten** Kandidaten, dessen Provideralias nicht dem Quellprofil entspricht und dessen Profil nicht Implementierungsprofil ist (`app/AI6/Reviews/VerifierSlotSelector.php:12–35`). `fake` sortiert vor `grok-cli-review`. In einem realen Pilot mit den Reviewern `grok-cli-review` und `copilot-cli-review` würde deshalb **jedes** Finding — Copilot- wie Grok-Findings — vom FakeAgent „verifiziert“, der per Vorgabe `confirmed` liefert (`app/AI6/Agents/FakeAgentAdapter.php:106–111`); die Agentrolle führt den Alias `fake` auch produktiv aus (`app/AI6/Agents/AgentExecutionProcessor.php:126–128`). Der Satz in AI6-038 `## Context`, ein Grok-Finding gehe „nach Plan §19 an HumanLoop“, trifft im heutigen Code nicht zu; `verification_independence` (`app/AI6/Reviews/FindingVerificationRound.php:126`) wird nie erreicht, solange `fake` im Pool ist. Dasselbe Profil ist auf der Approval-Seite als Reviewer und Implementierer wählbar und Vorgabe des Securityreviews (`config/ai6.php:36`; dafür existiert `AI6-050`).

**Zu entscheiden:** Wie der FakeAgent aus produktiven Auswahlpools fernbleibt. Kleinste Varianten: (a) ein kleiner Folgeauftrag, der den Alias `fake` außerhalb von `local`/`testing` aus `VerifierCandidatePoolFactory::all()` und der Approval-Auswahl ausnimmt (keine neue Konfiguration, ein Umgebungsprädikat an einer Stelle); (b) Aufnahme in `AI6-050`, weil dieser Blueprint ohnehin die Fake-Sperre des Securityreviews behandelt — allerdings ist sein Titel enger. In beiden Fällen trägt AI6-038 den Punkt als **vierte Pilotvoraussetzung** in die Voraussetzungstabelle ein und korrigiert den `## Context`-Satz. Keine Instanzkonfiguration kann das heute lösen: `agent_profiles` ist ein fester PHP-Array ohne Env-Schalter, und das Entfernen von `fake` bräche die Vorgabe des Securityreview-Profils und die Fake-Tests.

### D07 — `AI6_AGENT_SECURITY_REVIEW_PROFILE` erreicht keine Compose-Rolle

**Betroffen:** AI6-036 Task 4, AC-03, MG-01 (Befund `security_review_adapter_fake` im Container); AI6-038 Voraussetzungstabelle, AC-05; Blueprint `AI6-050`.

**Befund:** `config/ai6.php:36` liest `AI6_AGENT_SECURITY_REVIEW_PROFILE` mit Vorgabe `fake`; `docker-compose.yml` reicht diese Variable **keiner** Rolle durch (Volltextsuche ohne Treffer). Der Securityreview läuft im Worker (`SecurityReviewStep`). Selbst nach `AI6-050` bliebe der Compose-Stack auf `fake`, und der strict-Doctor aus AI6-036 meldete im Container dauerhaft `security_review_adapter_fake`, solange die Compose-Zeile fehlt.

**Zu entscheiden:** Dass das spätere `AI6-050`-Detailticket die eine Compose-Zeile (Rolle `worker`, sensibler Pfad `docker-compose.yml`) und die `.env.example`-Zeile mitliefert. AI6-036 nennt den Zusammenhang in `## Context` und in der Liste erwarteter Befunde (siehe `D02`); AI6-038 nimmt die Zeile in die Voraussetzungstabelle auf. Kein Teil davon gehört in AI6-036 selbst.

### D08 — Reviewgegenstand des Review-only-Piloten

**Betroffen:** AI6-038 Task 2/3, AC-09, TC-03, Voraussetzungstabelle.

**Befund:** `GIT-011` erlaubt als Reviewgegenstand nur serverseitig gebundene Quellen (verwalteter Branch gegen den freigegebenen Control-Stand, Commit-Range/Einzelcommit, importierter Patch, vorhandener Checkpoint); Plan §1 nennt als Anwendungsfall „ein extern oder lokal entwickelter verwalteter Branch“. Der Review-only-Lauf ist ticketgebunden an M169, dessen Umsetzung zu diesem Zeitpunkt noch nicht existiert. Das Ticket sagt nicht, **was** die beiden Reviewer prüfen sollen. Ein beliebiger Commit-Range des Pilotprojekts erzeugt Findings und `criterion_coverage` gegen die Akzeptanzkriterien von M169 ohne Bezug; eine vorab von Menschen entwickelte Teil- oder Vollumsetzung von M169 auf einem verwalteten Branch wäre der einzige Gegenstand, an dem Findingqualität sinnvoll messbar ist (Plan §18 Nr. 8).

**Zu entscheiden:** Welcher gebundene Stand der Review-only-Pilot prüft (Empfehlung: ein menschlich erstellter Umsetzungsbranch zu M169 im Pilotprojekt, gebunden als verwalteter Branch gegen den Control-Stand). Task 2/3 nennen die Quelle als Vorbereitungsschritt mit Vorher-OID; die Voraussetzungstabelle erhält eine Zeile dafür.

### D09 — Zugangsweg für die mobile Intervention des Piloten

**Betroffen:** AI6-038 Task 5, AC-12, TC-05; AI6-036 Task 8 („Zugang“), `D05`.

**Befund:** AC-12 verlangt Human-Request-Antwort und Gate-Evidenz **vom Smartphone**; Gate-Evidenz braucht Step-up, also Passkey oder TOTP auf dem Gerät gegen die eine WebAuthn-Origin. Der in AI6-036 als Standard beschriebene Zugang (SSH-Tunnel auf `http://localhost:<port>`) setzt auf dem Smartphone einen SSH-Client mit lokaler Portweiterleitung voraus; der VPN-/HTTPS-Weg ist nach `D05` bisher weder ausgeführt noch abgenommen. Ohne Entscheidung hängt AC-12 an einem nicht abgenommenen Zugangsweg.

**Zu entscheiden:** Ob der Pilot die mobile Intervention über den Tunnel (Smartphone-SSH-Client, TOTP statt Passkey) oder über VPN/HTTPS durchführt. Die Wahl steht in der Voraussetzungstabelle von AI6-038 und bindet `D05`.

---

## 1. AI6-036 — Installation, Doctor und Security-Release-Gate

### N36-01 — P2: `RetentionDoctorCheck` prüft das falsche Elternverzeichnis und wird auf jeder frischen Installation rot

**Betroffen:** Task 3, AC-02, TC-02.

**Befund:** Die Artefaktwurzel ist per Vorgabe `storage/app/ai6/run-artifacts` (`config/ai6.php:122–124`); `RunArtifactStore` legt sie beim ersten Speichern **rekursiv** an (`app/AI6/Runs/RunArtifactStore.php:206`, `mkdir(..., 0700, true)`). Der Compose-`init` erzeugt `storage/app/private` und `storage/app/public`, nicht `storage/app/ai6` (`docker/entrypoint.sh:33`). Auf einer frischen Installation existiert also weder die Wurzel noch ihr unmittelbares Elternverzeichnis; die Ticketregel „vorhandenes beschreibbares Elternverzeichnis“ ergibt `FEHLER`, obwohl der Store funktioniert — der strict-Doctor bleibt bis zum ersten gespeicherten Artefakt rot.

**Kleinste Verbesserung:** Die Regel an das Verhalten des Stores binden: „die Wurzel oder ihr nächster **vorhandener** Vorfahr ist ein reguläres, beschreibbares Verzeichnis ohne Symlink“. TC-02 prüft eine fehlende Wurzel mit fehlendem direktem Elternverzeichnis unter beschreibbarem `storage/app` als `OK`.

### N36-02 — P2: `SecurityReviewerProfileResolver::resolve()` hat mehr Ausgänge als „aufgelöst mit Adapter fake“

**Betroffen:** Task 4, AC-03, TC-04.

**Befund:** `resolve()` wirft `ConfigurationException` bei ungültigem Schlüsselwert, `AgentProfileSelectionException(PROFILE_UNKNOWN)` bei unbekanntem Profil, `COMBINATION_NOT_ALLOWED` ohne Rolle `security_review` und `CAPABILITY_NOT_AVAILABLE`, wenn ein reales Profil nicht `ready` ist (`app/AI6/Agents/SecurityReviewerProfileResolver.php:12–29`, `AgentProfileRegistry::resolve()` `:230–241`). Task 4 beschreibt nur den Fall „aufgelöstes Profil mit Adapter `fake`“. Nach `AI6-050` und `D07` ist der häufigste Zustand einer frischen Installation aber ein konfiguriertes reales Profil, das noch nicht `ready` ist — der Doctor muss die Ausnahmefamilie fangen und benennen, sonst stirbt er an dieser Maßnahme (`AGENTS.md` §11: die typisierten Verweigerungen sind der Vertrag, nicht der eine Fall).

**Kleinste Verbesserung:** Task 4 ergänzen: „Wirft `resolve()`, ist die Maßnahme `FEHLER` mit dem Grund der Ausnahme (`security_review_profile_unresolved` plus Fehlercode der Auswahl), nie ein Absturz.“ TC-04 erhält den Fall „konfiguriertes reales Profil ohne `ready`“. Keine eigene Prüfklasse dafür; es ist derselbe Zweig.

### N36-03 — P2: Schlüsselerzeugung ohne Host-PHP fehlt im Installationspfad

**Betroffen:** Task 8 („Installation und Start“), AC-01, MG-01.

**Befund:** `OPS-001` macht Compose zum primären Installationsweg. `.env` braucht vor `docker compose up` einen gesetzten `APP_KEY` (Cookies und Sessions verschlüsseln damit; leer scheitert jeder Browserrequest) und einen expliziten Ring `AI6_REDACTION_KEYS` (README Zeile 186 ff., Beispiel ohne Erzeugungsvorschrift). README nennt für den Schlüssel nur `php artisan key:generate` im lokalen PHP-Pfad (Zeile 24). Ein Linux-Server ohne PHP hat keinen dokumentierten Weg, die beiden Werte zu erzeugen; `key:generate` schreibt außerdem in `.env` und ist im Image nur über einen Entrypoint-Umweg erreichbar.

**Kleinste Verbesserung:** Zwei Zeilen im Abschnitt „Installation und Start“: `APP_KEY=base64:$(openssl rand -base64 32)` und je Ringschlüssel `openssl rand -base64 32` in das dokumentierte JSON-Format; alternativ `docker compose run --rm --no-deps --entrypoint php app /opt/ai6/artisan key:generate --show` (läuft ohne Ring, weil `key:generate` in `mayBootstrapWithoutRedactionKeyring()` steht, `AI6ServiceProvider.php:777–818`). Kein neues Kommando, kein Skript.

### N36-04 — P3: Lokaler Tunnelport muss `AI6_HTTP_PORT` entsprechen, sonst stimmt die Origin nicht

**Betroffen:** Task 8 („Zugang“), AC-06, MG-01.

**Befund:** `PasskeyRelyingPartyFactory` bildet die Origin aus Schema, Host **und Port** von `APP_URL` (`app/AI6/Auth/PasskeyRelyingPartyFactory.php:17–39`); die Compose-Vorgabe ist `http://localhost:${AI6_HTTP_PORT:-8080}`. Das Tunnelrezept `-L 127.0.0.1:<port>:127.0.0.1:<port>` funktioniert für WebAuthn nur, wenn der **lokale** Port gleich `AI6_HTTP_PORT` ist; ein frei gewählter lokaler Port liefert eine andere Browser-Origin und lässt Passkey-Registrierung und -Anmeldung scheitern (TOTP bliebe möglich).

**Kleinste Verbesserung:** Ein Satz im Abschnitt „Zugang“ und im Protokoll: lokaler und entfernter Port identisch und gleich `AI6_HTTP_PORT`.

### N36-05 — P3: Der Upgrade-Abschnitt verlangt ein Release-Gate, das heute planmäßig ungleich null endet

**Betroffen:** Task 8 („Upgrade“), AC-05, MG-01.

**Befund:** `FakeAgentReleaseGateCommand` endet wegen `AC_COVERAGE_GAPS` (`AC-02`, `AC-04`) und bei übersprungenen Nachweisen (`SKIPPED = 2`) absichtlich ungleich null (`app/AI6/Runs/Console/FakeAgentReleaseGateCommand.php:17–20, 147–184`). Eine Upgrade-Anleitung, die „Release-Gate im Linux-Checkout“ als Schritt nennt, ist erst nach Schließung der Lücken wörtlich befolgbar.

**Kleinste Verbesserung:** Im Upgrade-Abschnitt festhalten, wie das Ergebnis zu lesen ist: Exitcode 0 **oder** ausschließlich die im Kommando deklarierten `OFFEN`-Zeilen bei null Fehlern und null übersprungenen Nachweisen; jede andere Abweichung blockiert das Upgrade. Kein Schalter, keine Anpassung des Gates.

### N36-06 — P3: Exitcodes des Manifestgenerators außer 0 und 1

**Betroffen:** Task 3 (`TicketManifestDoctorCheck`), Task 6, AC-05, TC-03.

**Befund:** `scripts/generate-ticket-manifest.php` endet bei Drift **und** bei fehlendem Export mit 1 (`:11–17`), bei unbekanntem Argument mit 2 (`:60–61`) und bei fehlendem Plan, fehlender Revisionszeile oder abweichender Blueprintanzahl mit einer unbehandelten `RuntimeException`, also Exitcode 255 (`:73–133`). „fehlender Export ergibt `manifest_source_unavailable`“ ist deshalb nur haltbar, wenn die Prüfung die drei Pfade **vor** dem Start selbst auf Existenz prüft; ein Exitcode ungleich 0 und 1 ist weder benannt noch gemappt.

**Kleinste Verbesserung:** Task 3 präzisieren: Existenz der drei Pfade vor dem Start (sonst `manifest_source_unavailable`), Exitcode 0 `OK`, jeder andere Exitcode `manifest_drift` mit ausgegebenem Exitcode. Keine weiteren Gründe.

### N36-07 — P3: Der `APP_KEY`-Schritt von `ai6:install` ist nur mit explizitem Ring beobachtbar

**Betroffen:** Task 1, AC-01, TC-01 (Ergänzung zu `I02`).

**Befund:** In `local`/`testing` mit leerem Ring leitet `RedactionKeyringFactory::derivedAppKeyring()` den Schlüssel aus `APP_KEY` ab und verweigert bei leerem `APP_KEY` den Bootstrap (`RedactionKeyringFactory.php:113–140`). Der Schritt „`APP_KEY` gesetzt“ ist deshalb ausschließlich in einer Umgebung mit explizitem Ring erreichbar (Compose-Produktion mit gesetztem `AI6_REDACTION_KEYS`).

**Kleinste Verbesserung:** AC-01 und TC-01 entsprechend fassen („mit explizitem Ring und leerem `APP_KEY` meldet der Schritt `FEHLT`“); den Fallbackfall aus dem Test nehmen.

### N36-08 — P3: `GitDoctorCheck` sollte wie die anderen neuen Prüfungen rollenbewusst „nicht zuständig“ melden

**Betroffen:** Task 3, AC-02, TC-02.

**Befund:** Git läuft ausschließlich im Worker (`ai6_managed`, `known_hosts`, Deploy-Keys nur dort). Task 3 lässt Binary-, Config- und Hookspfadprüfung in jeder Rolle laufen; auf einem Entwicklungsrechner ohne `/usr/bin/git` oder ohne `storage/framework/ai6/git-home/gitconfig` wird der ohnehin optionale lokale Doctor rot, ohne dass ein Betriebsproblem vorliegt.

**Kleinste Verbesserung:** Rolle `worker`: alle Teilprüfungen; jede andere Rolle: `nicht zuständig`. Das ist dieselbe Regel wie bei `MailDoctorCheck` (nach `I08`) und spart Testfälle.

### N36-09 — P3: `UNGEPRÜFT` und die drei konfigurativen Maßnahmen ohne neuen Ergebnistyp

**Betroffen:** Task 4, Task 5, AC-04, `## Review Focus`.

**Befund:** `DoctorCheckResult` kennt `passed` und `details` (`app/AI6/Shared/Doctor/DoctorCheckResult.php`). Task 5 führt den Zustand `UNGEPRÜFT` ein, Task 4 gibt die drei konfigurativen Maßnahmen als aktiv/deaktiviert aus — das druckt `SecurityPolicyDoctorCheck` bereits für alle sieben Maßnahmen.

**Kleinste Verbesserung:** Ausdrücklich festhalten: kein dritter Ergebniszustand, kein Enum; `UNGEPRÜFT` ist eine Detailzeile einer bestandenen Prüfung (`Rolle scheduler: UNGEPRÜFT (ai6:runtime-health --role=scheduler)`). Die drei konfigurativen Maßnahmen werden unter `--security` nicht erneut ausgegeben.

### N36-10 — P3: `MailDoctorCheck` — nur `smtp` gilt als zustellfähig

**Betroffen:** Task 3, TC-02.

**Befund:** Compose reicht `MAIL_MAILER`, `MAIL_URL`, `MAIL_HOST`, `MAIL_PORT` an den Worker durch. Ein Mailer `log` oder `array` würde die Login-Bestätigung still verschlucken (die Barriere fällt dann geschlossen aus, `LoginConfirmationManager.php:195–216`), ein `MAIL_URL`-DSN macht `MAIL_HOST`/`MAIL_PORT` irrelevant.

**Kleinste Verbesserung:** Die Prüfung bewusst eng halten und so dokumentieren: `mail.default === smtp`, Host nicht leer, Port 1–65535, Absender syntaktisch gültig; jeder andere Transport ist `FEHLER` mit Grund `mail_transport_unsupported`. Keine DSN-Auswertung.

### N36-11 — P3: Der `.dockerignore`-Weg ist blueprintkonform, aber die Alternative gehört als Frage zum Menschen

**Betroffen:** Task 7, AC-05, `## Do Not Change` (Image).

**Befund:** Der Blueprint verlangt den Manifestcheck des Doctors **im Container** und das Blockieren eines **fehlenden** Manifests; deshalb müssen Plan, Export und Generator ins Produktionsimage. Im Image ist der Export aber immer der Stand des Build-Checkouts — die Prüfung findet dort nur einen aus schmutzigem Checkout gebauten Build. Das Ticket setzt den Blueprint korrekt um; die Frage, ob eine Planrevision den Containerteil auf „Quelle vorhanden → prüfen, sonst `nicht zuständig`“ vereinfachen soll (dann entfielen die drei Ausnahmen, der Smoke-Umbau aus `I09` und die Plan-Datei im Image), ist eine Planentscheidung.

**Kleinste Verbesserung:** Ticket unverändert lassen; die Frage in `## Notes` als offene Vereinfachungsoption benennen, damit sie nicht still verloren geht. Nicht selbst entscheiden.

---

## 2. AI6-037 — Migration des bisherigen Ticket-Prompt-Tools

### N37-01 — P1: Für das Legacy-Codeblock-Format existiert im gesamten Repository und seiner Geschichte kein Eingabekorpus

**Betroffen:** `## Context`, Task 1 (Eingabetabelle), Task 2, Task 8, AC-01, AC-06, TC-01, TC-04, TC-05, MG-01; verstärkt `D01`.

**Befund:** Alle 55 Ticketdateien dieses Repositorys waren seit ihrem ersten Commit `1aeb20e` V1-Frontmatter (`git show 1aeb20e:tickets/AI6-001.md`); eine Suche nach eingefügten ` ```yaml `-Blöcken unter `tickets/` über die gesamte Historie findet nichts. Das „bisherige Ticket-Prompt-Tool“ (`ticket-prompt/api.php`, `tools/validate_tickets.php`) arbeitet ebenfalls auf V1-Frontmatter (`tools/validate_tickets.php:244–387`). Das Legacy-Format existiert nur im Plan (§5.4) und im synthetischen Fixture `tests/Fixtures/Tickets/legacy-m169.md`. Damit ist die Eingabetabelle aus Task 1 (Frontmatter- und Abschnittsschlüssel, Listen-zu-Zeilen-Regel) eine Annahme ohne Beleg, die Referenznormalisierung auf den AI6-Plan hat keinen bekannten Adressaten, und MG-01 („Dry-run des realen M169“) ist ohne Korpus nicht durchführbar.

**Kleinste Verbesserung:** Vor der Umsetzung eine Zeile im Ticket (`## Context`) und in der Bestandsübersicht, die den realen Korpus benennt: Projekt, Verzeichnis, Anzahl Dateien und **eine** echte, gegebenenfalls redigierte Beispieldatei als Fixture (`legacy-<id>-full.md` nach `D01`), aus der die Eingabetabelle abgeleitet wird. Existiert kein Korpus, ist das Migrationskommando ein Blueprint ohne Input und die Entscheidung gehört zum Menschen (Planrevision statt Erfindung). Bis dahin keine Umsetzung von Task 1–7.

### N37-02 — P2: Katalogversion `3` ändert jeden Snapshot-Hash — `catalog-v2.json` kann nicht „bytegleich“ bleiben

**Betroffen:** Task 8, AC-08, TC-08, `files`, `## Do Not Change` (`catalog-v2.json`).

**Befund:** `PromptRenderer::snapshot()` nimmt `catalog_version` in den Hash auf (`app/AI6/Prompts/PromptRenderer.php:93–98`); `PromptCatalogTest::test_nine_catalog_entries_render_byte_identically_against_the_golden_fixture` vergleicht `catalog_version` und je Eintrag `prompt` **und** `hash` gegen `catalog-v2.json` (`tests/Unit/Prompts/PromptCatalogTest.php:94–119, 327`). Mit `VERSION = '3'` stimmt kein Hash mehr; nur die `prompt`-Bytes der Alteinträge bleiben gleich. Zusätzlich weicht „`catalog-v2.json` bleibt als historische Golden-Datei erhalten“ vom Muster ab, das `AI6-044` gesetzt hat: Commit `d480a87` hat `catalog-v1.json` gelöscht und durch `catalog-v2.json` ersetzt.

**Kleinste Verbesserung:** Dem Präzedenzfall folgen: `catalog-v2.json` durch `catalog-v3.json` **ersetzen** (die Historie hält v2), `## Do Not Change` und `files` entsprechend; TC-08 fordert „`prompt`-Bytes der neun Alteinträge unverändert (Git-Diff der Fixture zeigt nur Version, Hashes und den neuen Eintrag)“ statt „`catalog-v2.json` bytegleich“. Eine zweite Fixture-Lesung entfällt.

### N37-03 — P2: H1 und Quoting der Listeneinträge sind nicht festgelegt; der Golden-Diff entscheidet sie implizit

**Betroffen:** Task 1, AC-01, TC-01, TC-04.

**Befund:** Template §6.3 verlangt für alle Prosa- und Pfadstrings doppelte Anführungszeichen mit JSON-Escapes (also auch für `files`- und `spec_refs`-Einträge, vgl. jede vorhandene Ticketdatei) und für `depends_on` die Flow-Schreibweise `[A, B]` mit unquoted IDs; §5 verlangt die H1 `# <id> — <title>`. Task 1 nennt nur den gequoteten `title` und „`depends_on` als Liste oder `[]`“. Ohne Festlegung entscheidet das Golden-Fixture die Serialisierung, und eine Migration ins Detailprofil ergäbe eine Datei ohne H1, die vom Generatorprofil abweicht (`GenericV1TicketValidator` und `Ai6DetailV1TicketValidator` prüfen die H1 nicht).

**Kleinste Verbesserung:** Task 1 um drei Festlegungen ergänzen: H1 `# <id> — <title>` immer geschrieben (Markdown-Escapes nach §6.3); `files`/`spec_refs` je Eintrag doppelt gequotet in Blockliste wie in den vorhandenen Tickets; `depends_on` in Flow-Schreibweise. TC-01 prüft die drei Punkte am Golden-Fixture.

### N37-04 — P3: „unverändert“ bezieht sich auf den dekodierten Skalar, nicht auf Quellbytes

**Betroffen:** Task 1, AC-01, `## Review Focus` (Ergänzung zu `M02`).

**Befund:** `RestrictedYaml::parseMapping()` liefert die von `symfony/yaml` dekodierten Werte (`app/AI6/Shared/Yaml/RestrictedYaml.php:15–56`): gefaltete Blockskalare (`>`) verlieren ihre Zeilenstruktur, unquoted `yes`/`2026` werden Bool beziehungsweise Int. Ein Stringwert steht also „unverändert“ nur relativ zum dekodierten Wert.

**Kleinste Verbesserung:** Ein Halbsatz in Task 1: „unverändert bedeutet den von `RestrictedYaml` dekodierten Stringwert; typfremde Werte folgen `M02`“. README nennt gefaltete Skalare als Fall, in dem der Mensch den Abschnittstext prüft.

### N37-05 — P3: Übernommene Laufzustände `ready`, `in_progress`, `review` ohne AI6-Autorität

**Betroffen:** Task 3, AC-04, README (Task 9).

**Befund:** „Jeder V1-Statuswert bleibt unverändert“ schreibt auch `ready` (nur der Approval-CAS `todo → ready` erzeugt diesen Zustand, `TKT-009`), `in_progress` (nur der Claim) und `review` (nur die Publish-Synchronisation) in eine Datei, zu der keine Approval, kein Run und kein Runbranch existieren. Das ist kein stiller Statuswechsel und deshalb blueprintkonform; im Panel bleibt ein solches Ticket aber unstartbar, bis ein Mensch `return_to_todo` ausführt (`TicketStatusOperation::RETURN_TO_TODO`, `app/AI6/Tickets/TicketStatusOperation.php`).

**Kleinste Verbesserung:** Keine neue Regel im Kommando. Der Dry-run-Bericht zählt diese drei Statuswerte sichtbar als „Laufzustände ohne AI6-Vorgang“ und README nennt den Weg (`return_to_todo` im Panel oder Status vor dem Apply in der Quelle auf `todo` setzen).

### N37-06 — P3: „Abschaltpunkt des Legacy-Lesens“ vs. Migrationskommando nach dem Cutoff

**Betroffen:** Task 5, AC-07, TC-07; AI6-038 Task 7.

**Befund:** AI6-038 lässt `LegacyTicketReader::read()` als Eingang des Migrationskommandos bestehen; nach dem Cutoff wird das Kommando für verbliebene Legacydateien weiter gebraucht (der neue Fehlercode nennt es ausdrücklich). Der Bericht in AI6-037 sagt dagegen, „das Legacy-Lesen“ ende mit dem Pilot.

**Kleinste Verbesserung:** Wortlaut: „Die **reguläre Leseroute** akzeptiert Legacy nach dem protokollierten Pilot `AI6-038` nicht mehr; das Migrationskommando bleibt der einzige Leser.“ In Task 5, AC-07 und TC-07 gleich formulieren.

### N37-07 — P3: Ein Dry-run über das eigene `tickets/`-Verzeichnis ist ein kostenloser Realdatentest

**Betroffen:** TC-07 (Ergänzung), `## Review Focus`.

**Befund:** Das eigene Verzeichnis enthält 55 gültige V1-Dateien, `README.md` und keinen Legacykandidaten; `AI6-038` hängt von `AI6-050` ab, wofür keine Datei existiert, also liefert `TicketDependencyGraph::validate()` dort `dependency_missing`. Ein Dry-run über dieses Verzeichnis prüft Klassifikation, Statusansicht, Dependency-Befund und „keine Datei verändert“ an echten Daten, ohne Fixture.

**Kleinste Verbesserung:** TC-07 um genau diesen Lauf ergänzen (Exitcode ≠ 0 wegen `dependency_missing` für `AI6-050`, null Legacykandidaten, alle 55 Dateien byteidentisch). Kein neues Fixture.

### N37-08 — P3: Blockieren bei Abhängigkeitsbefund ist produktkonform — als Begründung festhalten

**Betroffen:** Task 6, AC-03, TC-06.

**Befund:** Im Produkt macht ein Projektbefund (fehlende Abhängigkeit, Zyklus, Case-Fold-Kollision, doppelte ID) **jede** Projektion des Bestands `invalid` (`TicketInventoryResult::projectionFor()` → `TicketProjection::withErrors()`, `app/AI6/Tickets/TicketProjection.php:22–34`). Dass `--apply` bei einem Abhängigkeitsbefund nichts schreibt, ist deshalb kein zusätzliches Gate, sondern dieselbe Strenge wie die Read-Model-Projektion.

**Kleinste Verbesserung:** Diese Begründung in `## Context` aufnehmen (ein Satz), damit ein Reviewer die Blockade nicht als Overengineering streicht; zugleich `M08` (Case-Fold, doppelte ID) als dieselbe Befundklasse führen.

---

## 3. AI6-038 — Realer M169-Pilot und MVP-Abnahme

### N38-01 — P1: Der Verifierpfad des Piloten ist heute der FakeAgent, nicht HumanLoop

**Betroffen:** `## Context`, Task 3/4, AC-02, Voraussetzungstabelle; siehe `D06`.

**Befund:** Siehe `D06`. Die Slot-Tabelle nennt einen „menschlichen Weg für Grok-Findings“, den der Code nicht nimmt.

**Kleinste Verbesserung:** `## Context`-Satz korrigieren; Voraussetzungstabelle um die Zeile „kein `fake`-Profil in Verifierpool, Reviewer- und Implementierungsauswahl der Pilotinstanz (Folgeauftrag nach `D06`)“ ergänzen; in der Slot-Tabelle je Finding die tatsächlich gewählte Verifierquelle protokollieren. Nach Auflösung von `D06` ist die `verification_independence`-Entscheidung für Grok-Findings der natürliche, nicht erzwungene Anlass für die Human Requests aus Task 4/5 — das Ticket kann den Satz „ein definierter Anlass für einen Human Request“ darauf stützen statt auf eine Fallauswahl.

### N38-02 — P2: Zwischen Review-only- und Implementierungslauf fehlt der Schritt `return_to_todo`

**Betroffen:** Task 2, Task 4, AC-08, AC-09, TC-09.

**Befund:** Der report-only Abschluss setzt `in_progress → ready` (`TicketStatusOperation::COMPLETE_REPORT_ONLY`). Die Approval-Seite nimmt ausschließlich Tickets im Status `todo` an (`app/AI6/Runs/TicketApprovalPage.php:89`). Die im Ticket verlangte neue Approval für die Laufart `implementation` ist also erst nach der menschlichen Statusoperation `return_to_todo` (`ready → todo`, `TicketStatusOperation.php`) möglich. Der Schritt steht nirgends, obwohl AC-08 „jedes Kommando und jede Voraussetzung in Ausführungsreihenfolge“ verlangt.

**Kleinste Verbesserung:** In Task 2/4 und im README-Abschnitt die Reihenfolge ausschreiben: Approval `review_only` (`todo → ready`) → Claim (`ready → in_progress`) → report-only Abschluss (`in_progress → ready`) → `return_to_todo` durch Approver/Operator → Approval `implementation` (`todo → ready`) → Claim → Publish-Synchronisation (`in_progress → review`). Die Ref-Tabelle erwartet damit **sieben** autorisierte Statuscommits auf dem Control-Branch, nicht „genau den Statuscommit“ (TC-03) — siehe `N38-06`.

### N38-03 — P2: Zwei-Phasen-Lieferung ausdrücklich machen

**Betroffen:** Task 7–10, AC-07, AC-10, TC-07, TC-08, MG-03, MG-04, `## Notes`.

**Befund:** AC-07 verbietet die Integration des Cutoffs vor der Signatur von MG-03; TC-07 und TC-08 können erst mit dem Cutoff-Code grün werden. Das Ticket liefert also zwingend in zwei Commits derselben Lineage: **Phase A** (vor dem Pilot: README-Abschnitt, Protokollvorlage Teil A/B, Dokumentationstest TC-09) und **Phase B** (nach MG-03: Cutoff, TC-07, TC-08, Teil B). Zwischen beiden liegt der menschliche Pilot. Das steht nur implizit; die Definition of Done nach §12.2 kann erst mit Phase B erfüllt sein.

**Kleinste Verbesserung:** In `## Notes` die beiden Phasen mit ihren Artefakten und den jeweils grün erwarteten TCs benennen; `## Review Focus`: „Phase A enthält keinen Codepfad des Cutoffs.“ Kein Split in ein weiteres Ticket, weil der Blueprint den Cutoff ausdrücklich in dieselbe Lineage legt.

### N38-04 — P2: Die Wiederherstellungsinstanz darf keine Rolle mit Remote-Zugriff starten

**Betroffen:** Task 6, AC-13, TC-06.

**Befund:** Ein Restore des Vorbereitungsbackups auf eine zweite Instanz stellt Projektregistrierung, Approvals, Queue und die privaten Deploy-Keys wieder her. Startet dort `worker` oder `scheduler`, kann die Zweitinstanz mit derselben Identität gegen die Pilot-Remote arbeiten (Fetch, Reconciler, Queue-Neubewertung, im Extremfall ein Claim). „Prüfung der Remote-Bindungen“ ist dafür zu vage.

**Kleinste Verbesserung:** Task 6 konkret: Die Wiederherstellungsinstanz startet für die Probe ausschließlich `init` und `app` (Sessionwiderruf, Login, Retention-Sweep als einmaliges Kommando), keinen `worker`, `scheduler`, `agent` oder `checker`; Git-Zugriff wird dort nicht ausgeführt, oder erst nach Widerruf des Deploy-Keys auf der Gegenseite beziehungsweise gegen eine Kopie der Remote. TC-06 prüft nur diese Rollen.

### N38-05 — P2: Backup-, Neustart- und Doctor-Reihenfolge des Piloten (Ergänzung zu `P05`)

**Betroffen:** Task 2, TC-01.

**Befund:** Siehe `P05`. Zusätzlich: Nach dem Neustart aller Rollen entstehen neue Boot-IDs; Agentbericht und Präsenz werden neu geschrieben, die Checker-Attestation neu erzeugt. TC-01 bindet „Ausgaben und Exitcodes“ eines strict-Doctor-Laufs — es muss der Lauf **nach** dem Backup-Neustart sein, sonst bindet das Protokoll einen Zustand, der vor dem ersten Providerturn nicht mehr gilt.

**Kleinste Verbesserung:** Task 2: „Rollen stoppen → `ai6:backup` → Rollen starten → strict-Doctor (dieser Lauf wird gebunden) → Approval.“

### N38-06 — P3: Ref-Tabelle und TC-03 mit der realen Anzahl der Control-Commits

**Betroffen:** TC-03, AC-06, Task 9 (Ref-Tabelle).

**Befund:** Jeder Lauf erzeugt drei autorisierte Statuscommits (Approval, Claim, Abschluss); dazu kommen `return_to_todo` (`N38-02`) und vor dem Pilot die menschlichen Migrationscommits von M169 auf dem Control-Branch (AI6-037-Ablauf). „Der Control-Branch trägt genau den Statuscommit“ (TC-03) und „ausschließlich die autorisierten Statuscommits“ (AC-06) sind ohne Startpunkt nicht prüfbar.

**Kleinste Verbesserung:** Ref-Tabelle mit „Vorbereitungsstand“ (OID nach Migrationspush) als Nullpunkt; TC-03 „genau die drei Statuscommits des Review-only-Laufs“, AC-06 „ab dem Vorbereitungsstand ausschließlich autorisierte Statuscommits“.

### N38-07 — P3: Pilotprojekt braucht `.ai6/config.yaml` mit vollständigem Schema (Ergänzung zu `P02`)

**Betroffen:** Task 2, AC-01.

**Befund:** `ProjectConfigurationParser` verlangt exakt die zehn Schlüssel (`version` … `checks`) und im Block `defaults` genau `implementation_profile`, `implementation_effort`, `reviewers` (`app/AI6/Projects/ProjectConfigurationParser.php:17–18, 105`); gelesen wird `.ai6/config.yaml` (`QueueProjectConfigRefresh::CONFIG_PATH`) über `config_refresh`, danach Freigabe durch einen Approver. Ohne Datei gelten die Serverdefaults mit **einem** Reviewer und `generic_v1`. Ein Pilotprojekt braucht also eine vollständige Datei, kein Fragment; die Approval-Seite füllt Reviewer aus `defaults.reviewers` vor und erlaubt weitere Slots (`TicketApprovalPage::addReviewer()`).

**Kleinste Verbesserung:** Task 2 nennt Pfad, Vollständigkeit („alle zehn Schlüssel“), `config_refresh` und die Freigabe; README zeigt eine minimale vollständige Pilotkonfiguration mit zwei Reviewern und dem gewählten Validierungsprofil (ein Block, kein neues Kommando).

### N38-08 — P3: Human-Request-Häufigkeit in Budget und Stopppunkt einplanen

**Betroffen:** Task 2 („Grenzen“), Task 4, AC-11.

**Befund:** Nach Auflösung von `D06` erzeugt jede Grok-Findinggruppe ohne unabhängigen Verifier eine `verification_independence`-Entscheidung (`FindingVerificationRound.php:126`, Optionen `keep_finding`/`controlled_abort`), sequenziell je Gruppe. Bei einem „bewusst anspruchsvollen“ Ticket sind das potenziell viele Unterbrechungen — jede verlangt Step-up.

**Kleinste Verbesserung:** Im Budget des Piloten die erwartete Zahl solcher Entscheidungen und die Standardantwort (`keep_finding`) vorab festlegen; im Protokoll die Anzahl binden. Keine Produktänderung.

---

## 4. Übergreifende Punkte

### NQ-01 — P2: Die Aussage „`ai6:doctor` läuft im Container“ heißt in allen drei Tickets `docker compose exec worker`

**Betroffen:** AI6-036 Task 2–5, MG-01; AI6-038 TC-01, Task 2; AI6-049 (Kontext).

**Befund:** `CheckerRuntimeDoctorCheck` verlangt die Checker-Wurzeln, die nur `worker` mountet (`docker-compose.yml`, Rolle `worker`, Volumes `ai6_checker_executions`/`ai6_checker_outputs`; `app` und `scheduler` mounten sie nicht) — der Doctor ist in `app` und `scheduler` immer rot (`I08`). README dokumentiert den Aufruf bereits als `docker compose exec worker php artisan ai6:doctor` (Zeile 425). `run --rm` startet einen frischen Container mit leerem Heartbeat-`tmpfs` (`I06`).

**Kleinste Verbesserung:** In allen drei Tickets denselben wörtlichen Aufruf verwenden (`docker compose exec worker php artisan ai6:doctor --security --all-processes --require-strict`); `ai6:install` dagegen `docker compose exec app …` (Bestätigungsadresse, `I02`/`I08`).

### NQ-02 — P2: Jede Bootstrap-Zusage einmal in einem frischen Prozess ausführen, nicht nur im Featuretest

**Betroffen:** AI6-036 TC-01/TC-04; AI6-037 TC-06/TC-07; AI6-038 TC-01.

**Befund:** Die Featuretests laufen in der bereits gebooteten Testanwendung (`APP_ENV=testing`, Fallback-Ring, `runtime_role` leer). Mehrere Zusagen der Tickets hängen aber am Bootstrap eines frischen Prozesses in einer anderen Rolle oder Umgebung (`I02`, `N36-07`, `M07`). `AGENTS.md` §11 nennt genau diesen Fehlermodus („Substituted a seam in the test setup“).

**Kleinste Verbesserung:** Je Ticket einen Satz in `## Review Focus`: „Jede Aussage über Bootstrapverhalten oder Rollenkontext wurde einmal in einem frischen PHP-Prozess mit der genannten Umgebung beobachtet; der Befehl und seine Ausgabe stehen im Umsetzungsbericht.“ Keine zusätzlichen automatisierten Tests.

### NQ-03 — P3: Bezeichnung des Pilottickets in Fixtures, Gates und README erst nach `D01`

**Betroffen:** AI6-037 `files`, TC-01, MG-01; AI6-038 Titel, README-Abschnitt, MG-03/MG-04.

**Befund:** Siehe `D01` und `N37-01`. Solange offen, entstehen Dateinamen und Überschriften, die später umbenannt werden müssen.

**Kleinste Verbesserung:** Reihenfolge einhalten: `D01` → Korpus (`N37-01`) → Fixture-Namen → Ticketprosa.

---

## 5. Bestätigung der Punkte des zweiten Reviews

Jeder Punkt aus `docs/AI6_M7_TICKET_REVIEW_2.md` wurde erneut gegen den Code geprüft. `übernehmen` = Befund und kleinste Verbesserung bestätigt; `übernehmen, angepasst` = Befund bestätigt, kleinste Verbesserung in der genannten Weise geändert; `verwerfen` = kein Punkt dieser Liste. Die Detailtexte stehen im zweiten Review.

| Kennung | Urteil | Ergänzung dieser Prüfung |
|---|---|---|
| D01 | übernehmen | Verstärkt durch `N37-01`: kein Codeblock-Korpus im Repository oder seiner Historie; Korpus vor Fixture-Namen klären. |
| D02 | übernehmen | Empfehlung Variante (b): geschlossene Liste erwarteter, fremd zugeordneter Befunde (`security_review_adapter_fake` → AI6-050 und `D07`; `degraded/runtime` je Alias → AI6-033/041/048-Gates; Grok-Sandboxblocker → README Zeile 429). Nur so bleibt AI6-036 unabhängig umsetzbar. |
| D03 | übernehmen | Unter `generic_v1` Refs unverändert übernehmen; Normalisierung nur unter `ai6_detail_v1`. Mit `N37-01`: ohne AI6-internen Legacykorpus hat die Normalisierung keinen Adressaten — Kandidat für Streichung per Planentscheidung. |
| D04 | übernehmen | Variante (a) streichen; `reserved` → `legacy_status_mapping_required` mit Hinweis auf die Quelldatei. |
| D05 | übernehmen | Zusätzlich an `D09` gebunden: der mobile Pilotzugang hängt an derselben Entscheidung. |
| I01 | übernehmen | `.env.example:5` ist `APP_URL=http://localhost`; eigene Variable `AI6_APP_URL`, verschachtelte Interpolation einmal mit `docker compose config` prüfen. |
| I02 | übernehmen | Bestätigt: `AI6ServiceProvider.php:575, 688, 700` eager; `RedactionKeyringFactory.php:113–140` Fallback nur `local`/`testing`. Siehe `N36-07`. |
| I03 | übernehmen | Siehe `D02`, `D07`. |
| I04 | übernehmen, angepasst | Die Sicht über vorhandene Ergebnisse nicht als `DoctorCheck` bauen, dessen `run()` fremde Ergebnisse bräuchte, sondern als Zuordnung in `DoctorCommand` (Maßnahme → Labels der Prüfungen, deren Ergebnis gilt) plus dem einen neuen Prädikat Securityreview (`N36-02`). Kein zweiter Attestations-Read, kein zweiter `diagnosis()`-Aufruf. |
| I05 | übernehmen | `SecurityPolicyFactory.php:109–127` bestätigt; Ack-Flag im strict-Profil nur Hinweiszeile. |
| I06 | übernehmen | Rollenliste im Worker-Aufruf: eigene Rolle Heartbeatalter, `agent` Präsenz, `checker` Ergebnis der vorhandenen Prüfung, `scheduler` und `app` `UNGEPRÜFT`. Siehe `N36-09`, `NQ-01`. |
| I07 | übernehmen | `routes/console.php` `ai6-run-retention` fest im Code; Teilprüfung streichen. |
| I08 | übernehmen | Bestätigt an Compose-Umgebungen und -Mounts; `ai6:install` läuft in `app`. |
| I09 | übernehmen | `RuntimeComposeSmokeTest.php:92–109` behauptet `/opt/ai6/docs` und `/opt/ai6/scripts` fehlen; Datei in `files`, Erwartung umstellen, Wirkung nur per `docker build` beweisbar. |
| I10 | übernehmen | Eine Klasse mit injizierbaren Pfaden, vom Gate als Vorprüfung aufgerufen; Reihenfolge über Ausgabe prüfen. Siehe `N36-06` zu Exitcodes. |
| I11 | übernehmen | Kette einmal real ausführen, bevor README sie beschreibt; Header (`Host`, `X-Forwarded-Proto`) benennen. |
| I12 | übernehmen | Ein `sshd_config`-`Match`-Block (`AllowTcpForwarding local`, `PermitOpen 127.0.0.1:<port>`, `PermitTTY no`, `ForceCommand /bin/false`, kein Agent/X11/Tunnel); `authorized_keys`-Optionen höchstens als zweiter Riegel ohne eigene Zusage. Siehe `N36-04` zum Port. |
| I13 | übernehmen | Konkret: `ssh-keyscan -t ed25519 <host>` → Datei, `ssh-keygen -lf` für den Pin in `AI6_GIT_PINNED_HOST_KEYS`, Schreiben als Root (`docker compose cp … worker:/var/lib/ai6/managed/known_hosts` oder `exec -u 0`), weil `ai6_managed` `root:root 0755` ist (`docker/entrypoint.sh:35–37`) und das Image als UID 10001 läuft (`Dockerfile:179`). |
| I14 | übernehmen | `InstallCommand` nach `Shared/Doctor/`; `Operations/` bleibt AI6-049. |
| I15 | übernehmen | Halbsatz in AC-06; `.env.example` ist über `!.env.example` Teil des Images (`.dockerignore`). |
| I16 | übernehmen | „Empfehlungen ohne Abnahme“. |
| I17 | übernehmen | `RuntimeComposeContractTest.php:103` pinnt `APP_URL` für `app`; dort ergänzen. |
| I18 | übernehmen | Protokollreihenfolge Tunnel → Origin-Wechsel → Passkeys neu → HTTPS. |
| I19 | übernehmen | Wortlaut „einzig der lokale Manifestgenerator“. |
| I20 | übernehmen | Dokumentationstests nur auf Sicherheitsaussagen und Kommandos. |
| M01 | übernehmen | Siehe `D01`, `N37-01`. |
| M02 | übernehmen | Drei Regeln (`legacy_field_invalid`, nicht konsumierter Abschnittstyp, `legacy_section_heading_conflict`); dazu `N37-04`. |
| M03 | übernehmen | `TicketV1Parser.php:40–44` erkennt nur V1-Zeilenformate; Migration erzeugt kein Markup. |
| M04 | übernehmen | Siehe `D03`. |
| M05 | übernehmen | Siehe `D04`. |
| M06 | übernehmen | Rückschreiben und `legacy_source_changed` streichen; Stopp beim ersten Fehler, `git restore`. |
| M07 | übernehmen | Zusätzlich: das Kommando braucht weder Datenbank noch Managed-Clone; Symlinkfall selbst-skippend außerhalb POSIX. |
| M08 | übernehmen | Case-Fold-Kollision und doppelte deklarierte ID als Befund des Gesamtbestands; siehe `N37-08`. |
| M09 | übernehmen | Zaunlänge `max(3, längster Backtick-Lauf + 1)`; Block als Übergabehilfe dokumentieren. |
| M10 | übernehmen, angepasst | `MARKER_LINE`/`NOTHING_TO_FIX` wörtlich prüfen; Katalogwirkung über `QueueReevaluation` beschreiben. Zusätzlich `N37-02`: Hashes ändern sich mit der Version, Fixture ersetzen statt behalten. |
| M11 | übernehmen | Unverändertes Fixture aus `files`; Secretfund ausdrücklich außerhalb. |
| M12 | übernehmen | `GenericV1TicketValidator::validateOptionalEnums()` bestätigt. |
| M13 | übernehmen | „als unveränderter Text“. |
| P01 | übernehmen | Siehe `D01`. |
| P02 | übernehmen | Siehe `N38-07` zur Vollständigkeit der Konfigurationsdatei; Reviewer können zusätzlich zur Approval-Zeit ergänzt werden, die Konfigurationsdatei bleibt der saubere Weg. |
| P03 | übernehmen | Smokes laufen im Linux-Checkout (`.dockerignore` schließt `tests` aus). |
| P04 | übernehmen | TC-10 in TC-01 enthalten (`TEST_PATHS` enthält beide Klassen). |
| P05 | übernehmen | Siehe `N38-05`. |
| P06 | übernehmen | `LegacyTicketReader.php` aus `files` nach `## Do Not Change`; Protokollverweis in den README-Dokumentationstest. |
| P07 | übernehmen | Negativaussage über `reproject-unparsed` aus TC-08; Task 8 als Revalidierung. |
| P08 | übernehmen | `TicketReadModelProjector.php:24–36` bestätigt; Cutoff ist ein kleiner Diff. |
| P09 | übernehmen | Coverage nach P04 anpassen. |
| P10 | übernehmen | Panel-Gates des Pilotlaufs von den IDs des Pilottickets trennen. |
| Q01 | übernehmen | Siehe auch `NQ-02`. |
| Q02 | übernehmen | Mit `N37-02` entfällt zusätzlich die zweite Fixture-Lesung. |
| Q03 | übernehmen | Reihenfolge AI6-036 vor AI6-049 in beiden `## Notes`. |
| Q04 | übernehmen | — |
| Q05 | übernehmen | Erweitert in Abschnitt 6. |

---

## 6. Was das nächste LLM nicht bauen soll

- Keine zweite Attestations-, Capability- oder Sandboxprüfung im Doctor; `--security` ist eine Zuordnung vorhandener Ergebnisse plus dem einen Securityreview-Prädikat (`I04`, `N36-02`).
- Keinen dritten Doctor-Ergebniszustand, kein Enum für `UNGEPRÜFT` (`N36-09`).
- Keinen Heartbeat-Sammelmechanismus, keinen Docker-Socket, kein Prozessregister für `--all-processes` (`I06`).
- Keine Konfigurationsausnahme an der Bootstrapgrenze für `ai6:install`; keine Schlüsselerzeugung im Kommando, nur zwei dokumentierte Shell-Zeilen (`I02`, `N36-03`).
- Keinen Umbau von `FakeAgentReleaseGateCommand` über die eine Vorprüfung hinaus; Exitcode-Semantik des Gates bleibt (`I10`, `N36-05`).
- Keine DSN-Auswertung im Mail-Check (`N36-10`).
- Keinen YAML-Dumper, keine zeilenbasierte YAML-Zerlegung, keinen Reparatur- oder Markup-Erzeugungsschritt in der Migration (`M02`, `M03`, `M09`, `N37-04`).
- Kein Transaktionsjournal, keinen Rollbackmechanismus, keinen Injektionsseam, keine `--map-status`-Option (`M06`, `D04`).
- Keine Sonderregel für `ready`/`in_progress`/`review` im Migrationskommando — nur Zählung im Bericht und ein README-Satz (`N37-05`).
- Keine zweite Golden-Fixture neben der aktuellen Katalogversion (`N37-02`).
- Keinen zweiten Reindex- oder Neuaufbaupfad neben `ticket_refresh` (`P07`).
- Keine Verifierauswahl-UI und keine neue Konfiguration für den FakeAgent-Ausschluss; ein Umgebungsprädikat an einer Stelle im Folgeauftrag genügt (`D06`).
- Keine neue Compose-Rolle, kein zweites Caddyfile; höchstens die in `D05` entschiedene Zeile und die eine `AI6_AGENT_SECURITY_REVIEW_PROFILE`-Zeile aus `D07` — letztere in AI6-050, nicht hier.

---

## Empfohlene Reihenfolge

1. **Entscheidungen einholen:** `D01`–`D05` (zweites Review), `D06`–`D09` (diese Liste). `D01`/`N37-01` zuerst: ohne Korpus ist AI6-037 nicht umsetzbar, und AI6-038 hängt an demselben Bezeichner.
2. **Falsche Codeannahmen korrigieren:** `N38-01` (`## Context`, Voraussetzungstabelle), `D07` in AI6-036 `## Context` und AI6-038, `N36-01`, `N36-02`, `N37-02`, `N38-02`.
3. **Unerreichbares und Duplikate nach dem zweiten Review:** `I01`–`I10`, `M02`, `M05`, `M06`, `P02`, `P04`, `P07`.
4. **Bedien- und Nachweisschritte ergänzen:** `N36-03`, `N36-04`, `N38-03`–`N38-07`, `NQ-01`, `I11`–`I13`, `M07`, `P03`, `P05`.
5. **Redaktion:** `N36-05`–`N36-11`, `N37-03`–`N37-08`, `N38-08`, `NQ-02`, `NQ-03`, `I14`–`I20`, `M09`–`M13`, `P06`, `P08`–`P10`, `Q01`–`Q04`.

Für die unabhängige Prüfung genügt pro Kennung eine Zeile:

| Vorschlag | Urteil | Beleg/Gegenbeleg | Kleinste übernommene Änderung | Betroffene AC/TC/MG | Entscheidung nötig? |
|---|---|---|---|---|---|
| Kennung | übernehmen / teilweise / verwerfen / offen | konkrete Quelle | Text oder Verweis | vorhandene IDs | D01–D09 oder nein |

Zu jedem übernommenen Vorschlag muss erkennbar bleiben, welches konkrete Problem er löst und warum die gewählte Lösung für dieses kleine Produkt genügt. Ein Vorschlag, der nur „theoretisch nützlich“ ist, wird verworfen.

---

## Tatsächlich ausgeführte Prüfungen und Grenzen dieser Analyse

- Alle drei Tickets vollständig gelesen und gegen ihre Blueprints in Plan §15.8 (Titel, `kind`, `risk`, `milestone`, `depends_on`, Requirement-Refs, erster Goal-Absatz) sowie gegen §5.4, §8.6–8.7, §12.2–12.3, §13.5–13.7, §18, §19 und §20 verglichen. `AI6-049`, beide vorhergehenden Reviews und die Blueprints `AI6-049`/`AI6-050` als Kontext gelesen.
- Die drei Dateien mit dem realen `TicketV1Parser` und `Ai6DetailV1TicketValidator` geprüft: keine Fehler; AC-/TC-/MG-IDs lückenlos, Coverage bijektiv, `files` gleich Scope-Liste und -Reihenfolge, `new`/`existing` gegen den Dateibestand korrekt, UTF-8 ohne BOM, LF-only, genau ein finales LF. `php scripts/generate-ticket-manifest.php --check`: „Ticket manifest is current.“
- Im Code geprüft: `AI6ServiceProvider` (Registrierung der Doctor-Prüfungen, eager Auflösungen, Bootstrapgrenze), `DoctorCommand`, `DoctorCheck`, `DoctorCheckResult`, alle sechs vorhandenen Prüfungen, `ProviderCapabilityReport` (`read`, `boot`, `diagnosis`, `humanEvidence`, `doctor`), `ProviderOnboarding`, `AgentProfileRegistry`, `AgentProfile`, `CapabilityStatus`, `SecurityReviewerProfileResolver`, `SecurityReviewStep` (Fake-Sperre), `SecurityPolicy`/`SecurityPolicyFactory`/`SecurityMeasure`/`SecurityProfile`, `RedactionKeyringFactory`, `RunArtifactRoot`, `RunArtifactStore` (rekursives `mkdir`), `RetentionPolicy`, `routes/console.php`, `RuntimeHealthCommand`, `docker/healthcheck.sh`, `docker/role-process.sh`, `docker/entrypoint.sh`, `Dockerfile`, `docker-compose.yml` (Rollenumgebungen, Mounts, festes `APP_URL`, fehlendes `AI6_AGENT_SECURITY_REVIEW_PROFILE`), `deploy/Caddyfile`, `.dockerignore`, `.env.example`, `EnforceHttpsOrPrivateAccess`, `ResolveTrustedProxies`, `PasskeyRelyingPartyFactory`, `GitConfigurationFactory`, `KnownHostsVerifier`, `CreateAdministratorCommand`, `LoginConfirmationManager`, `FakeAgentReleaseGateCommand`, `scripts/generate-ticket-manifest.php`, Kontrollpolicy in `config/ai6.php`, `ControlProcessRunner` (Plattformgrenze), `config/ai6.php` (Agentprofile, Serverdefaults, Artefaktwurzel).
- Für AI6-037: `LegacyTicketReader`, `LegacyTicketDocument`, `RestrictedYaml`, `TicketV1Parser`, `TicketSectionLocator`, `GenericV1TicketValidator`, `Ai6DetailV1TicketValidator`, `TicketDependencyGraph`, `TicketInventory`, `TicketInventoryResult`, `TicketProjection::withErrors`, `TicketReadModelProjector`, `TicketStatusOperation`, `PromptCatalog`, `PromptRenderer` (Hash mit `catalog_version`), `PromptHelp`, `help.blade.php`, `ManualFindingListExtractor`, `PromptCatalogTest`, `ManualPromptCatalogTest`, Fixture-Historie (`d480a87` löscht `catalog-v1.json`), `ticket-prompt/index.html`, `ticket-prompt/api.php`, `tools/validate_tickets.php`, `ai/prompts/`, Git-Historie von `tickets/` ab `1aeb20e`.
- Für AI6-038: Existenz aller genannten Klassen, Tests und Smoke-Flags; `TicketApprovalPage` (Status `todo`, Reviewer-Vorbelegung, keine Verifierauswahl), `ApprovalSnapshotFactory`, `VerifierCandidatePoolFactory`, `VerifierSlotSelector`, `FindingVerificationRound` (`verification_independence`), `FakeAgentAdapter` (Verifikationsvorgabe), `AgentExecutionProcessor`/`AgentExecutionRunner` (Fake-Alias produktiv), `ProjectConfigurationParser`, `QueueProjectConfigRefresh`, `EffectiveProjectConfiguration`, `TicketReadModelRefresher`, `ControlOperationController` (Refresh-Route je Pfad), `ReportOnlyCompletionService`/`TicketMutationExecutor` (`complete_report_only`), README-Abschnitte zu Review-only, Onboarding und Doctor.

Nicht ausgeführt wurden `docker build`/`docker compose config`, die Proxykette, ein SSH-Aufbau, ein echter Provider-Turn, der Pilot und der Restore. Die Aussagen zu OpenSSH-Direktiven und zur Compose-`.env`-Interpolation beruhen auf dem dokumentierten Verhalten dieser Werkzeuge und sind vor einer Ticketänderung einmal am gepinnten Stand zu bestätigen. Das reale Pilotticket und sein Korpus lagen nicht vor (`N37-01`).

| Datei | SHA-256 der geprüften Bytes |
|---|---|
| tickets/AI6-036.md | 670bb6572786ac6ef6659c9bb654a315e63c9136f72fc580f23b2f36346d79f0 |
| tickets/AI6-037.md | e3fa113d2edfc53af84094c9603d620b3c9221869ed243478645fee36889e5e0 |
| tickets/AI6-038.md | 0632fb300bd1d255396469b3396f97db0244229b4f606cfab16bdb19f0343ad1 |
| tickets/AI6-049.md (Kontext) | 8b0a088b22b36d594c7572e1a149c861f7a4cc32449af3bca1648e9a43885e8c |
