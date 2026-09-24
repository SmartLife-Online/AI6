# Zweite Prüfliste zu AI6-036, AI6-037 und AI6-038

Stand: 19. September 2026. Geprüfte Codebasis: `a7d83e9` (`AI6-035`) mit den uncommitteten Arbeitsbaumänderungen aus Planrevision V1.7.9. Normative Grundlage: Plan V1.7.9 (§3, §12, §13, §15.8, §18–§21), Ticket-Template V1 und `AGENTS.md`. Geprüft wurden die drei überarbeiteten Entwürfe nach dem ersten Review `docs/AI6_M7_TICKET_REVIEW.md`; `AI6-049` wurde nur als Kontext gelesen. Diese Liste verändert weder Tickets noch Plan, Status oder Gate-Ergebnisse.

**Gesamturteil:** Die Überarbeitung nach dem ersten Review hat die großen Widersprüche beseitigt; alle drei Dateien bestehen `TicketV1Parser` und `Ai6DetailV1TicketValidator` ohne Fehler, Coverage, Scope-Marker, Blueprinttreue, Sprachgrenze und Byteform stimmen. Was bleibt, sind vor allem drei Sorten von Befunden: **(a)** Zusagen, die im realen Code nicht erreichbar sind und deshalb Tests erzeugen würden, die den Produktionspfad nicht prüfen (Bootstrapgrenze, Rollen-/Containerkontext, `.env`-Interpolation); **(b)** Logik, die ein zweites Mal gebaut würde, obwohl der Doctor sie schon hat; **(c)** Mechanik, die ohne Verlust wegfallen kann (Rückschreib-Rollback, `--map-status`, redundante Optionsbedingungen, ein Test, der ein anderes Kommando negiert). Die wichtigste Leitlinie für die Überarbeitung: **Ergebnisse vorhandener Prüfungen konsumieren statt Prädikate neu bauen, und jede Zusage vorher einmal auf dem echten Pfad ausführen.**

## Verwendung durch das prüfende LLM

Die Punkte sind **Prüfvorschläge, keine freigegebenen Zusatzanforderungen**. Jeder Punkt ist gegen den dann aktuellen Code und Plan neu zu prüfen; Vorschläge dürfen begründet verworfen oder zusammengeführt werden. Vorrang hat immer die Variante mit **weniger** Klassen, Optionen, Tabellen, Tests und Dokumentation, solange Blueprint, Requirement-Refs und Sicherheitsinvarianten erfüllt bleiben. Ein Befund darf niemals durch ein neues Subsystem, einen neuen Schalter oder eine neue Abstraktion „gelöst“ werden, wenn eine Streichung oder Präzisierung genügt.

Prioritäten:

- **P1:** Vor Umsetzung auflösen — der Text verspricht etwas Unerreichbares, erzeugt einen unbrauchbaren Test oder ein Gate, das nicht geschlossen werden kann.
- **P2:** Konkrete Präzisierung, Vereinfachung oder fehlender Nachweis mit erkennbarem Nutzen.
- **P3:** Redaktionell oder klein; nur übernehmen, wenn es Aufwand spart oder eine Fehlbehauptung entfernt.

Die Kennungen D01–D05, I01–I20, M01–M13, P01–P10 und Q01–Q05 sind Reviewreferenzen und ersetzen keine `AC-`/`TC-`/`MG-`-IDs. Die drei Tickets sind unveröffentlichte Entwürfe (Plan §13.7); ihre IDs dürfen noch bewegt werden, sofern `## Notes` alte und neue Bezeichnung nennt.

---

## 0. Vorab zu klärende menschliche Entscheidungen

Diese fünf Punkte kann weder das prüfende LLM noch ein Implementierer entscheiden. Jeder betrifft mehrere Befunde unten.

### D01 — Ist „M169“ der richtige Bezeichner des Pilottickets?

**Betroffen:** AI6-037 (Titel des Gates MG-01, Fixture-Namen `legacy-m169*.md`, `migrated-m169.md`, TC-01), AI6-038 (Blueprint-Titel, `## Goal`, README-Abschnitt „Pilot M169“, MG-03/MG-04), Plan (`TKT-003`, `OPS-005`, §12.1, §13.5, §18, §20, Blueprints AI6-037/AI6-038).

**Befund:** Es liegt eine noch unbestätigte menschliche Aussage vor, dass der Verweis auf M169 wahrscheinlich aus einem anderen Projekt stammt und entfernt werden kann. Beide Tickets, das vorhandene Fixture `tests/Fixtures/Tickets/legacy-m169.md` und der Plan verwenden den Bezeichner durchgehend als reale Ticket-ID des Pilotprojekts. Das reale Ticket liegt nicht im Repository; welche Legacy-Feldnamen es verwendet, ist unbekannt (AI6-037 `## Context`).

**Kleinste Verbesserung:** Zuerst entscheiden. Falls M169 falsch ist: Planrevision, die den Bezeichner durch „das Pilotticket“ ersetzt (Blueprint-IDs und Verträge bleiben, nur der Name ändert sich), danach in beiden Tickets Fixture-Namen und Prosa neutral fassen (`legacy-pilot.md`, „reales Pilotticket“). Falls M169 richtig ist: in AI6-037 `## Context` einen Satz ergänzen, woher der Bezeichner stammt und dass das Ticket außerhalb dieses Repositorys liegt. Kein Ticket darf vorher in Umsetzung gehen, weil Gate-Texte, Fixture-Namen und der README-Abschnitt daran hängen.

### D02 — Wann gilt AI6-036/MG-01 als geschlossen, wenn der strict-Doctor aus fremden Gründen rot ist?

**Betroffen:** AI6-036 Task 4, AC-03, MG-01; AI6-038 Voraussetzungstabelle.

**Befund:** `ProviderCapabilityReport::diagnosis()` liefert `ready` nur, wenn `humanEvidence()` gebunden ist: `AI6_CODEX_SANDBOX_PROOF` (laut README erst nach `AI6-033/MG-01` zu setzen), `AI6_GROK_CAPABILITY_EVIDENCE` (erst nach `AI6-041/MG-01`) und `AI6_COPILOT_CAPABILITY_EVIDENCE` (`AI6-048`). Die drei vorhandenen Provider-Doctor-Checks scheitern über `ProviderCapabilityReport::doctor()` für jedes nicht optionale Profil ohne `ready` (`app/AI6/Agents/ProviderCapabilityReport.php:117–193, 225–244`). Alle drei Gates sind offen. Zusätzlich verlangt Task 4 `security_review_adapter_fake` als `FEHLER`, solange `AI6-050` fehlt. Ein „grüner strict-Doctor“, wie ihn MG-01 und `## Goal` verlangen, ist damit erst nach vier fremden Gates erreichbar; das Ticket benennt nur eines davon (AI6-050) als erwartetes Ergebnis.

**Kleinste Verbesserung:** Entscheiden, ob MG-01 (a) erst nach diesen Gates geschlossen wird — dann `## Context` und MG-01 benennen alle vier Voraussetzungen — oder (b) mit einer geschlossenen Liste **erwarteter, fremd zugeordneter Befunde** geschlossen werden darf (`security_review_adapter_fake` → AI6-050; `degraded/runtime` je Provideralias → AI6-033/041/048). Variante (b) hält AI6-036 unabhängig umsetzbar, wie Plan V1.7.9 es beabsichtigt, ohne einen Befund zu verschweigen. Keine Umgehung über gesetzte Evidenzwerte ohne bestandenes Gate.

### D03 — Referenznormalisierung auch für fremde, generisch migrierte Bestände?

**Betroffen:** AI6-037 Task 2, AC-06, TC-05; Blueprint-Deliverable „alte Referenzen werden auf ausschließlich `docs/AI6_IMPLEMENTATION_PLAN.md — <REQ-ID>` normalisiert“.

**Befund:** `GenericV1TicketValidator` prüft `spec_refs` nur als Liste nicht leerer Strings (`GenericV1TicketValidator.php:126–142`); die kanonische Form verlangt allein `Ai6DetailV1TicketValidator` (`Ai6DetailV1TicketValidator.php:36–46`). Das Ticket verweigert trotzdem unter jedem Profil einen `refs`-Eintrag ohne Requirement-ID (`legacy_ref_unrecognized`). Ein fremdes Pilotticket mit `refs: ["Spec §4.2"]` wäre damit unter `generic_v1` unmigrierbar, obwohl der generische Vertrag den Eintrag erlaubt und AI6 ihn für fremde Projekte nicht auswertet.

**Kleinste Verbesserung:** Entscheiden, ob die Normalisierung profilgebunden ist: unter `generic_v1` werden `refs`/`spec_refs` unverändert als `spec_refs` übernommen; die Normalisierung samt `legacy_ref_unrecognized` und dem `## Notes`-Unterabschnitt gilt nur unter `ai6_detail_v1`. Das ist weniger Logik und passt zur Blueprint-Zeile „fremde migrierte Tickets dürfen `generic_v1` verwenden“. Falls der Blueprint-Satz wörtlich für beide Profile gelten soll, muss der Plan das sagen; dann bleibt das Ticket, und README erklärt, dass fremde `refs` vor der Migration entfernt werden.

### D04 — Braucht das Migrationskommando die Option `--map-status`?

**Betroffen:** AI6-037 Task 3, Task 4 (`option_invalid`), AC-04, AC-05, TC-02, TC-06.

**Befund:** Der Blueprint verlangt „`reserved` erfordert eine explizite menschliche Zuordnung statt stiller Konvertierung“. Die explizite Zuordnung kann genauso gut in der Quelldatei erfolgen: Der Mensch ändert `status: reserved` vor dem Lauf auf `todo` oder `blocked`, committet das (auditierbar in Git) und wiederholt den Dry-run. Die Option bringt eigene Syntax, Konfliktprüfung (`option_invalid` bei widersprüchlichen Zuordnungen), Tests und Doku mit. Zudem erlaubt das Ticket `reserved:ready`; `ready` ist aber der Freigabezustand, den ausschließlich der `todo → ready`-CAS einer Approval erzeugt (Template §7.4, `TKT-009`) — ein Werkzeug, das `ready` schreibt, erzeugt einen freigegebenen Zustand ohne Freigabe.

**Kleinste Verbesserung:** Entscheiden zwischen (a) Option streichen: `reserved` → `legacy_status_mapping_required` mit dem Hinweis „Status in der Quelldatei auf `todo` oder `blocked` setzen und erneut ausführen“; oder (b) Option behalten, Zielwerte auf `todo|blocked` begrenzen. Empfehlung (a): weniger Oberfläche, gleiche Blueprint-Erfüllung, und die Entscheidung landet im Git-Diff, wo der Mensch sie ohnehin prüft.

### D05 — Darf `deploy/Caddyfile` ein sensibler Pfad von AI6-036 werden, falls die VPN/HTTPS-Kette es braucht?

**Betroffen:** AI6-036 Task 8 („Zugang“), AC-06, MG-01, `## Out of Scope` („ein zweites Caddyfile oder Host-Proxy-Konfigurationsdateien“).

**Befund:** Die dokumentierte Kette „externer HTTPS-Proxy → `127.0.0.1:<port>` → Caddy → app“ wurde nie ausgeführt. Caddy ohne `trusted_proxies` ersetzt eingehende `X-Forwarded-*`-Header und setzt `X-Forwarded-Proto` auf das Schema seiner eigenen eingehenden Verbindung (`http`). `ResolveTrustedProxies` vertraut nur Caddy (`AI6_HTTP_TRUSTED_PROXIES`), `EnforceHttpsOrPrivateAccess` lässt den Klartextrequest über die Ingress-Behauptung durch (`app/AI6/Shared/Http/EnforceHttpsOrPrivateAccess.php:29–45`), aber `isSecure()` ist dann `false`, und Laravel erzeugt absolute URLs (Redirects nach Login, Livewire-Assets) mit `http://<hostname>`. Ob die Anmeldung damit funktioniert, hängt vom externen Proxy ab. Das Ticket schließt eine Caddy-Änderung aus und legt zugleich das Gate darauf.

**Kleinste Verbesserung:** Entscheiden, ob `deploy/Caddyfile` als sensibler Pfad mit erwarteter Entscheidung („genau ein `trusted_proxies`-Eintrag für den Host-Proxy, sonst nichts“) zugelassen wird, **oder** ob die VPN/HTTPS-Referenz ausdrücklich „Referenz ohne Abnahme“ bleibt und MG-01 nur den SSH-Tunnel abnimmt. In beiden Fällen: die Kette einmal real ausführen, bevor README sie beschreibt (siehe I11). Keine Auth-Lockerung, kein `URL::forceScheme` im Anwendungscode als Nebenprodukt.

---

## 1. AI6-036 — Installation, Doctor und Security-Release-Gate

### I01 — P1: `.env.example` enthält `APP_URL` bereits, und ein Compose-`APP_URL` aus `.env` bricht die WebAuthn-Origin

**Betroffen:** Task 7, AC-06, TC-06, sensibler Pfad `docker-compose.yml`.

**Befund:** `.env.example` Zeile 5 lautet heute `APP_URL=http://localhost` (für `php artisan serve`). Task 7 will „die auskommentierte Zeile `APP_URL=` ergänzen“, AC-06 verlangt „`.env.example` führt `APP_URL`“, TC-06 prüft „`.env.example` enthält `APP_URL=`“ — dieser Test besteht ohne jede Änderung (Assertion, die nicht fehlschlagen kann). Schwerer wiegt die Compose-Seite: Compose interpoliert `${VAR}` aus der `.env` des Projektverzeichnisses, und README lässt für den Stack `.env.example` nach `.env` kopieren. Macht Task 7 `APP_URL` in `docker-compose.yml` zu `${APP_URL:-http://localhost:${AI6_HTTP_PORT:-8080}}`, erhält `app` den Wert `http://localhost` **ohne Port**; `PasskeyRelyingPartyFactory` bindet daraus die Origin `http://localhost`, der Browser spricht `http://localhost:8080` — Passkey-Registrierung und -Anmeldung scheitern in der Standardinstallation. Heute ist der Compose-Wert fest und gegen `.env` immun.

**Kleinste Verbesserung:** Eine eigene Compose-Variable statt `APP_URL` verwenden, z. B. `"APP_URL": "${AI6_APP_URL:-http://localhost:${AI6_HTTP_PORT:-8080}}"`, in `.env.example` als auskommentierte Zeile `# AI6_APP_URL=https://ai6.example.org` mit Hinweis auf die eine WebAuthn-Origin. Task 7, AC-06 und TC-06 entsprechend umformulieren; TC-06 prüft dann die neue Zeile (die heute fehlt) und den unveränderten `APP_URL`-Wert der lokalen Vorlage. Die verschachtelte Interpolation `${A:-${B:-x}}` einmal mit `docker compose config` gegen die gepinnte Compose-Version prüfen (Failure-Mode-Tabelle: „Execute the flag against the version the runtime uses“).

**Nachweis:** `docker compose config` mit und ohne gesetztem `AI6_APP_URL` zeigt die erwartete `APP_URL` der Rolle `app`; ein Stack mit kopierter `.env.example` behält die Origin `http://localhost:8080`.

### I02 — P1: Der Schlüsselring- und der SecurityPolicy-Schritt von `ai6:install` können außerhalb von `local`/`testing` kein `FEHLT` liefern

**Betroffen:** Task 1, AC-01, TC-01.

**Befund:** `RedactionKeyringFactory` wirft in jeder Umgebung außer `local`/`testing` bei leerem Ring eine `ConfigurationException` (`app/AI6/Shared/Redaction/RedactionKeyringFactory.php:118–120`), und `AI6ServiceProvider::register()` löst den Ring für `ai6:install` beim Bootstrap auf (`app/AI6/Shared/AI6ServiceProvider.php:699, 777–818`). Der in Task 1 und TC-01 beschriebene Fall „unter `APP_ENV=production` mit `APP_KEY`-Fallback meldet der Schlüsselringschritt `FEHLT`“ ist unerreichbar: Der Fallback existiert nur in `local`/`testing`, in `production` stirbt der Prozess vorher. Ein Featuretest, der diesen Fall erzeugt, muss nach dem Testbootstrap die Konfiguration umbiegen und prüft einen Pfad, den die reale CLI nie nimmt (Failure-Mode „Substituted a seam in the test setup“). Dasselbe gilt für den Schritt „aufgelöste `SecurityPolicy`“: `register()` ruft `$this->app->make(SecurityPolicy::class)` eager (`AI6ServiceProvider.php:575`), eine ungültige Policy ist ein Bootstrapfehler, nie ein `FEHLT`.

**Kleinste Verbesserung:** Die Schrittliste ehrlich schneiden: `APP_KEY` (prüfbar), Datenbank erreichbar und keine ausstehenden Migrationen (prüfbar), erster Administrator (prüfbar), Login-Bestätigungsadresse bei aktiver Maßnahme (prüfbar, siehe I08), Schlüsselring und Policy als **informative Zeilen** („Ring: explizit, Key-ID …“ bzw. „nur lokal: `APP_KEY`-Fallback“; „Profil: strict“), deren Fehlerfall README als Bootstrapfehler vor dem Assistenten dokumentiert. Den production-Fallback-Fall aus TC-01 streichen. Rolle und Aufruf festlegen: `docker compose exec app php artisan ai6:install`, derselbe Container wie `ai6:create-admin`.

**Nachweis:** TC-01 mit leerer Datenbank, nach `migrate`, nach `ai6:create-admin`; zusätzlich ein frischer PHP-Prozess (kein Featuretest) mit `APP_ENV=production` und leerem Ring, der den dokumentierten Bootstrapfehler zeigt.

### I03 — P1: Die Erreichbarkeit von MG-01 hängt an vier fremden Gates, nicht nur an AI6-050

**Betroffen:** `## Goal`, Task 4, AC-03, MG-01, `## Context`.

**Befund:** Siehe D02. Das Ticket kennt als erwarteten roten Befund nur `security_review_adapter_fake`. Ohne bestandene Adaptergates kann kein Profil `ready` werden, und die drei bestehenden Provider-Checks — die der Doctor ohne Option ohnehin ausführt — enden `FEHLER`. Der in `## Goal` versprochene „Erfolg“ (Exitcode 0 nur bei striktem Profil mit haltender Evidenz) ist auf einer frischen Installation heute nicht herstellbar, und MG-01 würde entweder unehrlich geschlossen oder ewig offen bleiben.

**Kleinste Verbesserung:** Nach D02 entweder die vier Voraussetzungen in `## Context` und MG-01 nennen oder die geschlossene Liste erwarteter Befunde in MG-01 aufnehmen, jeweils mit dem zuständigen Gate. Für die Tests (TC-04) bleibt es beim synthetischen `ready`; die Testbeschreibung soll sagen, dass `ready` dort durch gebundene Evidenzwerte simuliert wird.

**Nachweis:** Protokollvorlage enthält je erwartetem Befund eine Zeile „beobachtet / zuständiges Gate“; ein Doctor-Lauf ohne Evidenzwerte zeigt genau diese Befunde und keine anderen.

### I04 — P2: `SecurityControlsDoctorCheck` baut Prädikate nach, die der Doctor schon besitzt

**Betroffen:** Task 4, AC-03, TC-04, `## Review Focus` („jede Maßnahme wird gegen genau eine vorhandene Evidenzquelle geprüft“).

**Befund:** Drei der vier evidenzgebundenen Maßnahmen haben bereits einen Check mit Exitwirkung: Checker-Netzwerksperre in `CheckerRuntimeDoctorCheck` (liest `network_isolated` samt Frische, `app/AI6/Shared/Doctor/CheckerRuntimeDoctorCheck.php`), Agentensandbox in den drei Provider-Checks über `ProviderCapabilityReport::doctor()` (jedes nicht optionale Profil muss `ready` sein), Mail über die neue `MailDoctorCheck`. Task 4 formuliert die Checker-Maßnahme aber als „gegen die frische Attestation mit `network_isolated`“ (zweites Parsen derselben Datei) und die Sandbox als eigene Schleife über `diagnosis()`. Außerdem ist der Satz „die menschliche Laufzeitevidenz der Adaptergates bleibt zusätzlich erforderlich“ unpräzise: `diagnosis()` liefert `ready` **nur** mit gebundener menschlicher Evidenz (`humanEvidence()`, `ProviderCapabilityReport.php:154–158, 171–193`); ohne sie ist das Ergebnis `degraded/runtime`. Es gibt nichts Zusätzliches zu verlangen.

**Kleinste Verbesserung:** `--security` als **Sicht über vorhandene Ergebnisse** definieren: `DoctorCommand` sammelt je Check das `DoctorCheckResult`; `SecurityControlsDoctorCheck` erhält Policy und diese Ergebnisse und ordnet jeder aktiven Maßnahme die Labels der Checks zu, deren Ergebnis ihre Evidenz ist (Checker → `CheckerRuntimeDoctorCheck`, Sandbox → die drei Provider-Checks, Mail → `MailDoctorCheck`). Neu ist ausschließlich die Maßnahme LLM-Precommit-Review (`SecurityReviewerProfileResolver::resolve()`, Adapter `fake` außerhalb `local`/`testing` = `security_review_adapter_fake`). Den Satz zur Laufzeitevidenz durch „`ready` setzt die gebundene menschliche Laufzeitevidenz bereits voraus“ ersetzen; die Formulierung „Aliase ohne Bericht werden als nicht eingerichtet genannt“ streichen — der Basis-Doctor wertet einen fehlenden Bericht schon als `FEHLER`, und `--security` darf nicht milder sein als der Lauf ohne Option.

**Nachweis:** TC-04 bleibt; zusätzlich ein Test, dass `--security` keinen Check ein zweites Mal ausführt (kein zweiter Attestations-Read, kein zweiter `diagnosis()`-Aufruf) — einfach über einen Zähler im Fake der Attestation oder über die Ausgabe.

### I05 — P2: Die drei Bedingungen von `--require-strict` sind redundant, eine davon unerreichbar

**Betroffen:** Task 2, AC-03, TC-04.

**Befund:** `SecurityPolicyFactory` verweigert im Profil `strict` jede deaktivierte Maßnahme (`app/AI6/Shared/Security/SecurityPolicyFactory.php:109–127`); „eine Maßnahme ist deaktiviert“ impliziert also „Profil ist nicht strict“. `AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE=true` ist im strict-Profil wirkungslos (nur bei Reduktionen verlangt, `:95–100`); ein `--require-strict`, das daran scheitert, würde eine korrekt strikte Instanz wegen eines toten Flags rot machen.

**Kleinste Verbesserung:** `--require-strict` = „`profile === strict`“, sonst Exitcode ≠ 0 mit Profil und Liste der deaktivierten Maßnahmen (aus `SecurityPolicy::disabledMeasures()`). Ein gesetztes Ack-Flag im strict-Profil höchstens als Hinweiszeile. AC-03 und TC-04 entsprechend kürzen.

### I06 — P2: `ProcessRolesDoctorCheck` muss den Aufrufweg (`exec` im laufenden Container) und die Rollenliste festlegen

**Betroffen:** Task 5, AC-04, TC-05, MG-01, README.

**Befund:** Der Worker-Heartbeat liegt auf einem containerprivaten `tmpfs` (`docker-compose.yml`, Rolle `worker`). `docker compose exec worker php artisan ai6:doctor` (README Zeile 425) sieht ihn; `docker compose run --rm worker …` startet einen frischen Container mit leerem `tmpfs` und meldet fälschlich einen fehlenden Worker-Heartbeat. Task 5 sagt außerdem „Worker und Scheduler werden als `UNGEPRÜFT` ausgewiesen“ — beim vorgesehenen Aufruf im Worker ist der Worker aber die eigene, geprüfte Rolle; `app` (HTTP-Healthcheck, kein Heartbeat) fehlt in der Liste ganz. Die Checker-Attestation wird schon von `CheckerRuntimeDoctorCheck` auf Frische geprüft.

**Kleinste Verbesserung:** Rollenliste fest schneiden: eigene Rolle → Heartbeatalter; `agent` → Präsenzalter aus `ProviderCapabilityReport::boot()` (nur Lebendigkeit); `checker` → Ergebnis der vorhandenen `CheckerRuntimeDoctorCheck` (nicht erneut lesen); jede andere dauerhafte Rolle (`scheduler`, im Worker-Aufruf; `app` mit HTTP-Healthcheck) → `UNGEPRÜFT` mit dem rolleneigenen Kommando. README und MG-01 pinnen den Aufruf `docker compose exec worker …` und erklären, warum `run --rm` hier falsch ist.

**Nachweis:** TC-05 unverändert plus ein Satz, dass der Test die Rolle über `ai6.runtime_role = worker` setzt und die `UNGEPRÜFT`-Zeilen genau `scheduler` und `app` nennen.

### I07 — P2: Der Scheduler-Eintrag `ai6-run-retention` ist eine Codeprüfung, die in einer Instanz nie fehlschlagen kann

**Betroffen:** Task 3 (`RetentionDoctorCheck`), AC-02, TC-02.

**Befund:** Der Eintrag steht fest in `routes/console.php` (`->name('ai6-run-retention')->hourly()`) und ist Teil des Images. Ein Doctor, der prüft, ob der Code den Eintrag enthält, kann in keiner ausgelieferten Instanz rot werden; der zugehörige Testfall in TC-02 („fehlender Scheduler-Eintrag“) ließe sich nur durch Manipulation des Schedules im Test erzeugen. Ob der Scheduler *läuft*, ist aus dem Worker nicht beobachtbar (I06).

**Kleinste Verbesserung:** Die Teilprüfung streichen. `RetentionDoctorCheck` prüft `RetentionPolicy` (auflösbar, weil sie im Gegensatz zu `RunArtifactRoot` nicht eager im Bootstrap steht — `AI6ServiceProvider.php:224` ist lazy, `:688` eager) und den Artefaktbaum wie beschrieben. Aus AC-02 und TC-02 „fehlender Scheduler-Eintrag“ entfernen.

### I08 — P2: Der `app`-Zweig von `MailDoctorCheck` ist praktisch tot; die Bestätigungsadresse gehört in `ai6:install`

**Betroffen:** Task 3, Task 4 (Mail-Evidenz), AC-02, TC-02.

**Befund:** `ai6:doctor` ist faktisch ein Worker-Kommando: `CheckerRuntimeDoctorCheck` verlangt `AI6_CHECKER_OUTPUT_ROOT` (`config/ai6.php:160`, Vorgabe `/var/lib/ai6/checker-outputs`), das nur `worker` mountet; in `app` ist der Doctor dadurch immer rot. `AI6_LOGIN_CONFIRMATION_EMAIL` erreicht aber ausschließlich `app`; `MAIL_*` ausschließlich `worker` (Compose). Die Login-Mail wird als Job verschickt (`LoginConfirmationManager.php:222`, `SendLoginConfirmationMail::dispatch`), ohne Adresse fällt die Barriere geschlossen aus (`recipient_unavailable`). Ein Worker-Doctor kann die Adresse also nie prüfen; ein App-Doctor ist nie grün.

**Kleinste Verbesserung:** `MailDoctorCheck` nur für die Rolle `worker` (Mailer `smtp`, Host nicht leer, Port 1–65535, Absender syntaktisch gültig; andere Rollen „nicht zuständig“). Die Bestätigungsadresse als Schritt des in `app` laufenden `ai6:install` prüfen (I02). Task 4: Mail-Evidenz der Maßnahme = Ergebnis der `MailDoctorCheck`; die Zustellung bleibt MG-01. In `## Context` den Satz ergänzen, dass der Doctor wegen der Checker-Wurzeln im Worker läuft.

### I09 — P2: Die `.dockerignore`-Ausnahmen sind nur gegen ein echtes `docker build` beweisbar — und ein vorhandener Smoke behauptet das Gegenteil

**Betroffen:** Task 7, AC-05, TC-06, `files`.

**Befund:** `.dockerignore` schließt `docs` und `scripts` als ganze Verzeichnisse aus. Ob `!docs/AI6_IMPLEMENTATION_PLAN.md` unter einem ausgeschlossenen Verzeichnis tatsächlich re-inkludiert wird, entscheidet der Builder, nicht die Textzeile; TC-06 prüft nur die Zeichenkette. `tests/Feature/Shared/Runtime/RuntimeComposeSmokeTest.php:92–109` (hinter `AI6_RUN_COMPOSE_SMOKE`) prüft heute mit `test ! -e /opt/ai6/docs` und `/opt/ai6/scripts`, dass beide Verzeichnisse im Image **fehlen** — nach Task 7 existieren sie mit je einer bzw. zwei Dateien; der Smoke wird rot, steht aber nicht in `files`.

**Kleinste Verbesserung:** `tests/Feature/Shared/Runtime/RuntimeComposeSmokeTest.php — existing` in `files` aufnehmen und dort die Erwartung umstellen: genau `docs/AI6_IMPLEMENTATION_PLAN.md`, `docs/AI6_TICKET_MANIFEST.yaml`, `scripts/generate-ticket-manifest.php` vorhanden, sonst nichts unter `docs/` und `scripts/`, plus ein Aufruf `docker compose exec worker php artisan ai6:doctor` mit bestandener Manifestprüfung. TC-06 behält die Textinventur, AC-05 verweist für die Wirkung auf den Smoke bzw. MG-01.

### I10 — P2: Die Manifestprüfung entsteht zweimal; ihre Verankerung im Release-Gate ist als „Auswahl“ falsch beschrieben

**Betroffen:** Task 3 (`TicketManifestDoctorCheck`), Task 6, AC-05, TC-03.

**Befund:** Doctor-Check und Release-Gate sollen beide `scripts/generate-ticket-manifest.php --check` über `ControlProcessRunner` starten und Exitcode/Fehlen in `manifest_drift`/`manifest_source_unavailable` übersetzen — dieselbe Logik an zwei Stellen. Task 6 nennt die Prüfung „erste gebundene Auswahl“; `FakeAgentReleaseGateCommand::testSelections()` liefert Testpfade, und `ReleaseGateCommandTest` prüft diese Liste per Reflection — die Manifestprüfung ist keine Testauswahl. TC-03 verlangt „Drift beendet das Gate mit Exitcode 1“; das Gate endet wegen `AC_COVERAGE_GAPS` (`AC-02`, `AC-04`) ohnehin ungleich null, der Exitcode unterscheidet den Fall nicht. Für den Drift-Test braucht der Check injizierbare Pfade, sonst müsste ein Test die Repositorydateien verändern.

**Kleinste Verbesserung:** Genau eine Klasse: `TicketManifestDoctorCheck` mit Konstruktorpfaden (Wurzelverzeichnis; Vorgabe `base_path()`), die das Gate vor seiner Schleife aufruft und deren Ergebnis es ausgibt („Vorprüfung“, nicht „Auswahl“). TC-03 prüft die Reihenfolge über die Ausgabe (Manifestzeile vor der ersten Testzeile, bei Drift keine Testzeile) statt über den Exitcode. Kontrollpolicy erlaubt `*` und `base_path()` als Arbeitswurzel (`config/ai6.php:79–86`), also keine Policyänderung.

### I11 — P2: Die VPN/HTTPS-Kette muss vor der Dokumentation einmal laufen; die Unsicherheit ist heute benannt, aber nicht adressiert

**Betroffen:** Task 8 („Zugang“), AC-06, MG-01, `## Out of Scope`.

**Befund:** Siehe D05. Zusätzlich: `session.secure` bleibt im strict-Profil `true`, was mit HTTPS am externen Proxy funktioniert; die absoluten `http://`-URLs sind der offene Punkt. Das Ticket verlangt in MG-01 eine funktionierende Passkey-/TOTP-Anmeldung über diesen Weg — ohne einen Freiheitsgrad, falls sie scheitert.

**Kleinste Verbesserung:** Nach D05 entweder `deploy/Caddyfile` als sensiblen Pfad benennen oder den HTTPS-Weg aus MG-01 herausnehmen und als „Referenz, nicht abgenommen“ kennzeichnen. In jedem Fall im Ticket festhalten, dass README den Weg erst nach einer realen Ausführung beschreibt und welche Header (`Host`, `X-Forwarded-Proto`) der externe Proxy setzen muss.

### I12 — P2: Das SSH-Rezept nutzt zwei Mechanismen; einer genügt und deckt alle vier Negativfälle

**Betroffen:** Task 8, Task 9, AC-06, TC-06, MG-01.

**Befund:** Task 8 kombiniert `authorized_keys`-Optionen (`command="/bin/false"`, `restrict`, `port-forwarding`, `permitopen=`) mit einem Verweis auf serverseitige OpenSSH-Konfiguration für Remote-Weiterleitungen. `restrict` plus `port-forwarding` gibt lokale **und** Remote-Weiterleitungen wieder frei; `permitopen` begrenzt nur lokale Ziele. Der Negativfall „Remote-Weiterleitung scheitert“ hängt also allein an der zweiten, nur angedeuteten Stelle. Zwei Stellen sind doppelt zu pflegen und doppelt zu prüfen.

**Kleinste Verbesserung:** Genau ein Rezept in `sshd_config`: `Match User <tunnelbenutzer>` mit `AllowTcpForwarding local`, `PermitOpen 127.0.0.1:<port>`, `PermitTTY no`, `ForceCommand /bin/false`, `AllowAgentForwarding no`, `X11Forwarding no`, `PermitTunnel no`. `AllowTcpForwarding local` ist die einzige saubere Sperre für `-R`. Die `authorized_keys`-Optionen entfallen oder bleiben als optionaler zweiter Riegel ohne eigene Zusage. TC-06 prüft das Vorkommen dieser Direktiven; MG-01 prüft die vier Negativfälle.

### I13 — P2: Hostkeys, Allowlisten und `known_hosts` haben keinen ausführbaren Installationsschritt

**Betroffen:** Task 3 (`GitDoctorCheck`), Task 8 („Installation und Start“), AC-02, MG-01.

**Befund:** Der Worker-Doctor verlangt eine vorhandene `known_hosts` (Vorgabe `/var/lib/ai6/managed/known_hosts`) und außerhalb `local`/`testing` nicht leere Allowlisten. `docker/entrypoint.sh:34–40` legt `deploy-keys/` und Lock-Verzeichnisse an, keine `known_hosts`; das Volume `ai6_managed` gehört `root:root 0755`, und README dokumentiert nirgends, wie der Betreiber die Datei dorthin schreibt. Auf einer frischen Installation bleibt der Doctor rot, bis jemand einen nicht dokumentierten Weg findet — genau die Lücke aus dem ersten Review (B02), nur verschoben.

**Kleinste Verbesserung:** In „Installation und Start“ den konkreten Schritt aufnehmen (etwa `docker compose cp known_hosts worker:/var/lib/ai6/managed/known_hosts` oder `docker compose exec worker sh -c 'cat > …'` gefolgt von Rechten `ai6:ai6 0644`), gebunden an `AI6_GIT_ALLOWED_HOSTS` und `AI6_GIT_PINNED_HOST_KEYS`. Kein Init-Automatismus, kein neues Kommando. Für Instanzen ohne Projekt: Doctor-Meldung „keine Hosts konfiguriert“ als `FEHLER` beibehalten, aber im README als erwarteten Zustand vor dem ersten Projekt erklären.

### I14 — P3: `InstallCommand` gehört zum Doctor, nicht in ein neues Verzeichnis, das zwei Tickets teilen

**Betroffen:** `files`, Task 1, Task 10, `## Notes` (Verzeichnis gemeinsam mit AI6-049).

**Befund:** `app/AI6/Shared/Operations/` und `tests/Feature/Shared/Operations/` entstehen in AI6-036 nur für eine lesende Checkliste und werden ausdrücklich mit AI6-049 geteilt („wer zuerst integriert, erzeugt es“). Der Assistent liest exakt die Nähte, die der Doctor liest.

**Kleinste Verbesserung:** `app/AI6/Shared/Doctor/InstallCommand.php` und `tests/Feature/Shared/Doctor/InstallCommandTest.php`; `Operations/` bleibt AI6-049 allein. Entfernt die Reihenfolgeabhängigkeit zwischen beiden Tickets bis auf `AI6ServiceProvider`, README, `.env.example`, `docker-compose.yml` (siehe Q03).

### I15 — P3: Die Blueprint-Akzeptanz „Keine geheimen Schlüssel landen im Repository“ hat kein AC

**Betroffen:** AC-06, TC-06, `## Review Focus`.

**Befund:** Der Akzeptanzvertrag des Blueprints nennt den Punkt; das Ticket deckt ihn nur indirekt (`.env`-Ausschlüsse in TC-06).

**Kleinste Verbesserung:** In AC-06 einen Halbsatz ergänzen: `.env.example` und die README-Beispiele enthalten keinen echten Schlüssel-, Ring- oder Tokenwert; `.dockerignore` behält `**/.env`. TC-06 prüft das mit dem vorhandenen Redactor-/Secret-Muster oder einer festen Negativliste (`base64:`-Werte außerhalb des Beispielformats).

### I16 — P3: Rootless- und Härtungsempfehlungen nicht als geprüft ausgeben

**Betroffen:** Task 8, AC-06.

**Befund:** `init` läuft als `0:0`, chownt Volumes, `AI6_EFFECT_LOCK_OWNER_UID`, seccomp-Profile `moby-29.6.1` — rootless Docker ändert UID-Mapping und Profilverhalten. MG-01 enthält keinen Rootless-Lauf.

**Kleinste Verbesserung:** Abschnitt als „Empfehlungen ohne Abnahme“ kennzeichnen; konkrete Rootless-Aussagen auf das beschränken, was ohne Lauf sicher ist (keine veröffentlichten Ports außer Loopback, Host-Firewall, Updates, Backup nach AI6-049). Kein Rootless-Nachweis erfinden.

### I17 — P3: Compose-Nachweis in den Compose-Vertragstest, nicht in `RuntimeScriptsTest`

**Betroffen:** Task 10, TC-06, `files`.

**Befund:** `tests/Unit/Shared/Runtime/RuntimeComposeContractTest.php` pinnt die Compose-Umgebung je Rolle (Zeile 103 nennt `APP_URL` für `app`); `RuntimeScriptsTest` pinnt `.dockerignore` und Skripte.

**Kleinste Verbesserung:** Die Compose-Assertion (konfigurierbarer Wert mit unveränderter Vorgabe) in `RuntimeComposeContractTest` legen und die Datei in `files` aufnehmen; `RuntimeScriptsTest` behält `.dockerignore`.

### I18 — P3: Origin-Wechsel im Gate und der portlose lokale `APP_URL`-Wert

**Betroffen:** Task 8 („Zugang“), Task 9, MG-01.

**Befund:** MG-01 prüft Tunnel (`http://localhost:<port>`) und VPN/HTTPS (`https://<hostname>`) auf derselben Instanz; das ist ein Origin-Wechsel, der laut Task 8 eine erneute Passkey-Registrierung verlangt — die Protokollvorlage sieht diesen Zwischenschritt nicht vor. Für den lokalen Betrieb steht in `.env.example` `APP_URL=http://localhost` ohne Port, während README `http://localhost:8000` nennt; die Origin enthält den Port.

**Kleinste Verbesserung:** Protokollvorlage: Reihenfolge Tunnel → Origin umstellen → Passkeys neu registrieren (TOTP bleibt) → HTTPS. Abschnitt „Zugang“: je Zugangsweg den exakten `APP_URL`-Wert samt Port nennen; ob `.env.example` für den lokalen Betrieb den Port erhält, ist eine kleine Klärung außerhalb der Compose-Frage aus I01.

### I19 — P3: „Der Doctor startet keinen Prozess“ stimmt seit der Manifestprüfung nicht mehr wörtlich

**Betroffen:** AC-02, `## Review Focus`.

**Befund:** `TicketManifestDoctorCheck` startet den Generator als PHP-Prozess.

**Kleinste Verbesserung:** „keinen Provider-, Checker- oder Netzwerkprozess; einzig der lokale Manifestgenerator läuft als Argumentliste über `ControlProcessRunner`“.

### I20 — P3: Dokumentationstests auf Sicherheitsaussagen und Kommandos begrenzen

**Betroffen:** TC-06, Task 10 (`DoctorDocumentationTest`).

**Befund:** TC-06 prüft Abschnittsüberschriften („Zugang“, Upgrade, Härtung) und Optionsnamen; das zementiert Redaktion, ohne eine Fehlbehauptung zu verhindern (erstes Review Q03).

**Kleinste Verbesserung:** Prüfen: die `sshd_config`-Direktiven (I12), das Tunnelkommando mit Zielhost, die Nennung der zwei Ausführungsorte, die `AI6_APP_URL`-Zeile (I01), die `exec`-Aufrufe (I06). Überschriften nicht.

---

## 2. AI6-037 — Migration des bisherigen Ticket-Prompt-Tools

### M01 — P1: Der Bezeichner M169 ist zu bestätigen, bevor Fixtures und Gate benannt werden

**Betroffen:** `## Context`, `files` (`legacy-m169*.md`, `migrated-m169.md`), TC-01, MG-01, `## Notes`.

**Befund:** Siehe D01. Alle Namen und das Gate hängen an einem möglicherweise fremden Bezeichner.

**Kleinste Verbesserung:** Nach D01 Namen neutralisieren oder Herkunft belegen. Bis dahin keine Umsetzung.

### M02 — P1: Der Eingabevertrag lässt drei Fälle offen, von denen einer still Abhängigkeiten verliert und einer die Zusage „unverändert“ mit „keine zusätzliche Überschrift“ kollidieren lässt

**Betroffen:** Task 1, Task 2, AC-01, AC-06, TC-03.

**Befund:** (1) Frontmatterschlüssel werden „sofern vorhanden und vom Typ her gültig“ übernommen. Ist `depends_on` vorhanden, aber ein String, wird er offenbar nicht konsumiert, während Task 1 immer `depends_on: … oder []` schreibt — die V1-Datei verlöre die Abhängigkeit still, obwohl sie in der Legacy-Quelle unter `## Notes` steht; `GenericV1TicketValidator` verlangt `depends_on` als Liste (`GenericV1TicketValidator.php:38–52`). (2) Für Abschnittsschlüssel sind nur „String“ und „Liste von Strings“ definiert; eine Liste von Mappings (etwa `acceptance_criteria: [{id: AC-01, text: …}]`) ist unbehandelt. (3) Ein Stringwert „steht unverändert als Abschnittstext“; enthält er außerhalb eines Zauns eine Zeile `## …`, entsteht eine neue Sektion. TC-03 behauptet, mehrzeilige Strings erzeugten keine Überschrift — das gilt nur für gutartige Eingaben; die Implementierung müsste den Text entweder ändern (nicht mehr unverändert) oder ablehnen.

**Kleinste Verbesserung:** Drei Regeln in Task 1/2: ein vorhandener, typ-ungültiger **Frontmatter**schlüssel ist eine benannte Verweigerung (`legacy_field_invalid`, Feld genannt), nie ein stiller Ersatz; ein **Abschnitts**schlüssel mit nicht unterstütztem Typ wird nicht konsumiert und bleibt in der Legacy-Quelle (Validator meldet dann den fehlenden Abschnitt); ein Abschnittswert mit einer Level-2-Überschrift außerhalb eines Zauns wird mit `legacy_section_heading_conflict` verweigert. TC-03 um genau diese drei Eingaben ergänzen. Kein Escaping, keine Umformung.

### M03 — P2: `- ` plus String erzeugt keine V1-AC-/TC-Zeilen; die Detailprofil-Gültigkeit in TC-04 setzt V1-Markup in der Legacyquelle voraus

**Betroffen:** Task 1, AC-01, TC-01, TC-04, README (Task 9).

**Befund:** Der Parser erkennt AC-IDs nur als `- [ ] **AC-xx**` und TC-IDs als `- **TC-xx**` (`TicketV1Parser.php:40–44`); `Ai6DetailV1TicketValidator` verlangt mindestens je eine. Die Migration schreibt Listenwerte als `- <String>` und vergibt/formatiert nichts. Ein Legacy-Eintrag `AC-01 Das System …` wird nie als AC erkannt. TC-04 („Legacy-Dokument mit allen Tabellenfeldern … ist unter `ai6_detail_v1` gültig“) ist nur mit einem Fixture wahr, dessen Legacy-Strings bereits `[ ] **AC-01** …` tragen — ein Legacyformat, das V1-Zeilensyntax spricht.

**Kleinste Verbesserung:** Ehrlich festlegen: Die Migration erzeugt keine V1-Zeilenformate; ID-Markup muss in der Quelle stehen oder wird vom Menschen vor dem Apply ergänzt. Fixture und TC-04 so beschreiben („Kriterienstrings tragen bereits V1-Markup“). Je Listenabschnitt das Präfix festlegen (`- ` für alle; keine Nummerierung von `tasks`), damit der Golden-Diff nicht implizit entscheidet. README nennt die menschlichen Nacharbeiten für das Detailprofil (AC-/TC-Zeilen, Coverage-Tabelle, Notes-Boilerplate): Der Validator prüft nur Abschnitte und IDs, nicht das Template.

### M04 — P2: Referenznormalisierung profilgebunden ausführen

**Betroffen:** Task 2, AC-06, TC-05.

**Befund:** Siehe D03.

**Kleinste Verbesserung:** Unter `generic_v1` `refs`/`spec_refs` unverändert (Duplikate entfernt) übernehmen; Normalisierung, `legacy_ref_unrecognized` und der Notes-Unterabschnitt nur unter `ai6_detail_v1`. TC-05 beide Profile prüfen.

### M05 — P2: `--map-status` streichen oder auf `todo|blocked` begrenzen

**Betroffen:** Task 3, Task 4, AC-04, AC-05, TC-02, TC-06, README.

**Befund:** Siehe D04. `ready` als Migrationsziel widerspricht dem Freigabevertrag.

**Kleinste Verbesserung:** Nach D04. Bei Streichung entfallen die Konfliktprüfung in Task 4, der `option_invalid`-Fall für doppelte Zuordnung in TC-06 und die Matrixzeile `reserved:blocked` in TC-02.

### M06 — P2: Rückschreib-Rollback und `legacy_source_changed` streichen; Git ist die Rücksetzung

**Betroffen:** Task 6, AC-03, TC-06, `## Review Focus`.

**Befund:** Voraussetzung ist ein committeter, ruhender Checkout; das Ticket erklärt den Git-Stand zur verbindlichen Rücksetzung. Trotzdem versucht das Kommando nach einem Schreibfehler, Originalbytes zurückzuschreiben (ein zweiter Fehlerpfad, der selbst scheitern kann), und prüft vor jedem `rename`, ob die Quelle unverändert ist. Beide Zusagen brauchen Tests mit Fehlerinjektion bzw. einem Eingriff *zwischen* Lesen und Schreiben — dafür müsste das Kommando einen Testseam erhalten, der im Produkt nichts tut.

**Kleinste Verbesserung:** Apply = alles im Speicher validieren → je Datei temporäre Datei + `rename` → beim ersten Fehler stoppen, je Datei „ersetzt / unverändert“ ausgeben, Exitcode ≠ 0, Hinweis `git restore -- <verzeichnis>`. `legacy_source_changed` und das Rückschreiben entfallen; TC-06 prüft „Fehler nach der ersten Datei: Ausgabe nennt genau die ersetzte Datei“ über ein schreibgeschütztes Ziel (POSIX) oder eine gefüllte Zielpfadkollision, ohne Injektionsseam.

### M07 — P2: Bedienpfad und Testplattform festlegen

**Betroffen:** `## Context`, Task 4, Task 9, TC-06, TC-07.

**Befund:** Das Kommando arbeitet auf einem lokalen Verzeichnis; im Compose-Stack sieht keine Rolle den Checkout eines Menschen. Es läuft also aus einem AI6-Entwickler-Checkout (`php artisan ai6:tickets:migrate-legacy <pfad>`) gegen einen lokalen Checkout des Pilotprojekts — das steht nirgends. TC-07 verlangt einen Symlink `AI6-900.md`; die reguläre Suite läuft auch unter Windows, wo Symlinks Privilegien brauchen.

**Kleinste Verbesserung:** README und `## Context`: „läuft ausschließlich im Entwickler-Checkout von AI6 gegen ein lokales Verzeichnis, nie im Container, nie auf dem Managed-Clone“. TC-07: Symlinkfall selbst-skippend außerhalb POSIX, wie die übrigen Symlinktests der Suite.

### M08 — P2: Klassifikation um Case-Fold-Kollision und doppelte deklarierte ID ergänzen; „sonstige“ Dateien namentlich listen

**Betroffen:** Task 4, Task 5, AC-07, TC-07.

**Befund:** `TKT-010` und `TicketInventory` (`TicketInventory.php:30–45`, `caseFoldErrors`, `declared_id_duplicate`) behandeln Case-Fold-Kollisionen und mehrere Kandidaten mit derselben `id` als Projektfehler; das Kommando kennt beide nicht. Außerdem ist eine Legacy-Datei mit Nicht-Kandidatennamen (etwa `m169.md` klein geschrieben) „sonstige unangetastete Datei“ — und verschwindet damit still aus dem Bericht, obwohl sie ein unmigrierter Legacykandidat ist.

**Kleinste Verbesserung:** Beide Fehlerklassen als „fehlerhafter Ticketkandidat“ des Gesamtbestands werten (Exitcode ≠ 0, kein Apply). Die Namen (redigiert) der „sonstigen“ Dateien im Bericht ausgeben; keine sechste Klasse, keine Inhaltsprüfung dieser Dateien.

### M09 — P3: Die Legacy-Quelle unter `## Notes` verdoppelt den Inhalt — als transitorisch dokumentieren

**Betroffen:** Task 2, AC-01, README.

**Befund:** Sobald ein unbekannter Schlüssel existiert, wandert der **gesamte** YAML-Rohtext in einen Codeblock; bei einem vollständigen Ticket steht jeder Abschnitt zweimal in der Datei. Byteexakte Teilextraktion ist ohne zweite YAML-Naht nicht möglich (erstes Review M05), und Git hält die Originalbytes ohnehin.

**Kleinste Verbesserung:** Beibehalten, aber README und Bericht sagen: Der Block ist eine Übergabehilfe; der Mensch überträgt fehlende Inhalte in Abschnitte und entfernt den Block vor dem Commit, die verlustfreie Quelle bleibt die Git-Historie. Zaunlänge als `max(3, längster Backtick-Lauf + 1)` schreiben (drei ist das Minimum).

### M10 — P3: `manual_review` an den Extractor binden; Browser-Smoke und Katalogwirkung konkret nennen

**Betroffen:** Task 8, AC-08, TC-08, `## Notes`, `files`.

**Befund:** Der Alt-Prompt (`ticket-prompt/index.html:401–429`) verlangt `### Fix-Liste` und `Nichts zu fixen.`; `ManualFindingListExtractor::MARKER_LINE`/`NOTHING_TO_FIX` erwarten exakt diese Zeilen. TC-08 sagt nur „enthält den Fix-Listen-Vertrag“. Die Kopieraktion ist Browserverhalten; `tests/Feature/Prompts/PromptHelpBrowserSmokeTest.php` existiert und steht nicht in `files`. `## Notes` behauptet, Version `3` mache Snapshots „unstartbar“; tatsächlich bindet `QueueReevaluation` den Katalog in den Trusted-Binding-Fingerprint (`app/AI6/Runs/QueueReevaluation.php:69`) und bewertet freigegebene Queue-Einträge neu — der Mechanismus sollte genannt werden, nicht das Ergebnis geraten.

**Kleinste Verbesserung:** TC-08: „enthält `ManualFindingListExtractor::MARKER_LINE` und `NOTHING_TO_FIX` wörtlich“. Entweder den Browser-Smoke um die dritte Karte erweitern (`files`) oder ausdrücklich sagen, dass die Kopieraktion nur über die vorhandene Alpine-Bindung geteilt wird und der Smoke unverändert bleibt. Notes-Satz zur Katalogversion auf den Reevaluationsmechanismus umschreiben und den vorhandenen `QueueReevaluationTest` als Nachweis nennen.

### M11 — P3: `files` enthält Unverändertes; Secretfund im Inhalt bewusst ausschließen

**Betroffen:** `files`, `## Initial Scope`, `## Out of Scope`.

**Befund:** `tests/Fixtures/Tickets/legacy-m169.md` wird nicht verändert (nur als Negativfixture gelesen) und steht trotzdem im Scope. Das erste Review (M08) fragte, wie ein Secret im Legacyinhalt behandelt wird; das Ticket schweigt.

**Kleinste Verbesserung:** Das unveränderte Fixture aus `files` nehmen. `## Out of Scope`: „Keine Secretprüfung des migrierten Inhalts: Der Bericht enthält keinen Inhalt, die Datei bleibt untrusted Repositoryinhalt, den die Projektion später redigiert.“

### M12 — P3: Enum-fremde `kind`/`milestone`/`risk`-Werte fremder Bestände erklären

**Betroffen:** Task 1, Task 9.

**Befund:** `GenericV1TicketValidator::validateOptionalEnums()` verlangt auch im generischen Profil `M0…M7`, `feature|chore|fix|spike`, `low|medium|high`. Ein fremdes `milestone: Sprint-4` ist typ-gültig, wird übernommen und dann mit `milestone_invalid` verweigert.

**Kleinste Verbesserung:** So belassen (benannte Verweigerung), aber README nennt den Fall und den Ausweg (Wert in der Quelle entfernen oder anpassen).

### M13 — P3: „deutsche Prosa“ für bewahrten Resttext

**Betroffen:** Task 2, AC-06.

**Befund:** Der Rest eines `refs`-Eintrags ist untrusted Text beliebiger Sprache.

**Kleinste Verbesserung:** „als unveränderter Text unter `## Notes` bewahrt“.

---

## 3. AI6-038 — Realer M169-Pilot und MVP-Abnahme

### P01 — P1: Bezeichner M169 (D01)

**Betroffen:** Titel, `## Goal`, README-Abschnitt, MG-03/MG-04, TC-03, TC-09.

**Kleinste Verbesserung:** Nach D01; ein Titelwechsel ist eine Planrevision ohne ID-Änderung.

### P02 — P1: Die Voraussetzungstabelle ist unvollständig — zwei Reviewer-Slots, Adaptergates und die Vorgates der Abhängigkeiten fehlen

**Betroffen:** `## Context`, Task 2, Task 3, AC-02, AC-09, MG-03.

**Befund:** (1) Die Serverdefaults der Projektkonfiguration setzen **genau einen** Reviewer `grok-cli-review` und `auto_start_next=false` (`config/ai6.php:496–502`, README Zeile 632: „Umstellung … bleibt bis zu einer ausdrücklichen menschlichen Betriebsentscheidung offen“). Der Pilot verlangt zwei unabhängige Slots; sie müssen über `.ai6/config.yaml` des Pilotprojekts (`defaults.reviewers`, `ProjectConfigurationParser.php:105–138`) am Control-Branch deklariert, per `config_refresh` gelesen und vom Approver freigegeben werden — vorhandene Nähte, die das Ticket nicht nennt. (2) Der grüne strict-Doctor aus TC-01 setzt `ready` für alle Profile voraus, also die offenen Gates `AI6-033/MG-01`, `AI6-041/MG-01`, `AI6-048/MG-01` (siehe D02/I03); die Tabelle nennt sie nur pauschal als „offene Installations- und Provider-Gates“. (3) `AI6-036/MG-01`, `AI6-037/MG-01` und `AI6-049/MG-01` sind Vorgates dieses Piloten; nur `AI6-037/MG-01` wird als Startbedingung genannt.

**Kleinste Verbesserung:** Zeilen in der Voraussetzungstabelle: „zwei Reviewer-Slots per `.ai6/config.yaml` des Pilotprojekts, freigegeben“, die drei Adaptergates namentlich, die drei MG-01-Gates der Abhängigkeiten. Task 2 nennt den Pfad `.ai6/config.yaml`, `config_refresh` und die Approver-Freigabe als vorhandene Nähte (sie existieren heute und dürfen benannt werden, ahead-derived betrifft nur die vier fehlenden Lieferungen).

### P03 — P2: Die Adapter-Smokes laufen im Linux-Checkout, nicht „auf der Pilotinstanz“

**Betroffen:** TC-02, AC-02.

**Befund:** Das Image enthält keine Tests (`.dockerignore`); `CodexCliSmokeTest`, `GrokCliSmokeTest`, `GitHubCopilotCliSmokeTest` und `ProviderOnboardingSmokeTest` sind PHPUnit-Tests hinter Flags und laufen dort, wo `tests/` und Entwicklungsabhängigkeiten liegen — derselbe Ortsfehler wie B06 im ersten Review, nur in AI6-038.

**Kleinste Verbesserung:** TC-02: „im Linux-Checkout desselben Commits mit den bereitgestellten Testzugängen; das Ergebnis wird mit Commit und Host im Protokoll gebunden“.

### P04 — P2: TC-10 ist in TC-01 enthalten

**Betroffen:** TC-10, AC-03, AC-04, `## AC Coverage`.

**Befund:** `PublishCandidateGateTest` und `ReReviewCompletenessTest` stehen in `FakeAgentReleaseGateCommand::TEST_PATHS`; ein Release-Gate mit Exitcode 0 auf dem Pilotcommit (TC-01) hat beide bereits grün ausgeführt. TC-10 wiederholt das und fügt nur die Protokollierung real beobachteter Verweigerungen hinzu.

**Kleinste Verbesserung:** TC-10 streichen; AC-03 und AC-04 auf TC-01 plus MG-01/MG-02 binden; den Satz „im Pilot beobachtete Verweigerungen werden getrennt protokolliert“ nach Task 4 bzw. Task 9 verschieben. Da die IDs noch Entwurfs-IDs sind, ist die Lücke zulässig, wenn `## Notes` sie nennt.

### P05 — P2: Backup nach AI6-049 verlangt gestoppte Rollen — Reihenfolge und Neuprüfung festlegen

**Betroffen:** Task 2, Task 6, AC-13, TC-06.

**Befund:** AI6-049 sichert nur eine ruhende Instanz mit gestoppten dauerhaften Rollen. Nach dem Neustart sind Agentpräsenz (`boot-id`), Checker-Attestation und Heartbeats bootgebunden; die Provider-Berichte werden beim Boot der Agentrolle neu geprüft. Task 2 nennt „Backup … erst nach diesem Stand und vor dem ersten realen Lauf“, aber nicht den Stopp/Start und dass der strict-Doctor danach erneut grün sein muss.

**Kleinste Verbesserung:** Task 2: „Rollen stoppen → `ai6:backup` → Rollen starten → strict-Doctor erneut mit Exitcode 0 → Approval“. Protokoll bindet beide Doctor-Läufe.

### P06 — P3: `LegacyTicketReader.php` aus `files`; Wortlaut zur Scaffoldinventur

**Betroffen:** `files`, `## Initial Scope`, sensibler Pfad, Task 10.

**Befund:** Task 7 und der sensible Pfad sagen, der Leser bleibt unverändert erhalten; trotzdem steht er im Scope. `tests/Unit/ScaffoldStructureTest.php` inventarisiert `app/AI6/` und README-Aussagen, keine `docs/`-Dateien; „die Scaffoldinventur führt die neuen Dateien“ trifft für `docs/AI6-038_PILOTPROTOKOLL.md` nicht zu (Dokumentationsdateien werden in den jeweiligen Doku-Tests genannt, etwa `RuntimeDocumentationTest`).

**Kleinste Verbesserung:** Leser aus `files` nehmen und in `## Do Not Change` führen („Eingang des Migrationskommandos; Entfernung ist eine gesonderte Entscheidung“); Task 10/TC-09: Protokollverweis im README-Dokumentationstest, nicht in der Scaffoldinventur. Gleiches gilt sinngemäß für AI6-036 und AI6-037.

### P07 — P3: Die Negativaussage über `reproject-unparsed` aus TC-08 streichen; Neuaufbau ehrlich als Revalidierung beschreiben

**Betroffen:** Task 8, AC-10, TC-08.

**Befund:** Die Aussage im Ticket ist korrekt (`ReprojectUnparsedTicketsCommand.php:46–54` überspringt aktuelle Bindungen), aber ein Test, der beweist, was ein *anderes* Kommando nicht tut, sichert nichts an diesem Ticket. Im Erwartungsfall sind nach dem Cutoff alle Pilottickets bereits V1 und `valid`; der Refresh je Pfad ändert nichts, nur ein verbliebenes Legacy-Dokument wechselt den Code.

**Kleinste Verbesserung:** TC-08 auf die drei Refresh-Fälle beschränken; die `reproject-unparsed`-Aussage bleibt als Satz in `## Context`. Task 8 als „erneute Validierung: Refresh je Pfad, dann Vergleich Kandidatenliste ↔ Projektionen“ formulieren, nicht als Neuaufbau.

### P08 — P3: Den Cutoff als kleinen Diff erwarten und beschreiben

**Betroffen:** Task 7, AC-07, TC-07.

**Befund:** Heute projiziert `TicketReadModelProjector` ein Legacy-Dokument bereits als `invalid` mit `legacy_format` und ruft `read()` nur, um Parsefehler anzuzeigen (`TicketReadModelProjector.php:24–36`). Der Cutoff ändert: kein `read()`-Aufruf, neuer Code, Meldung mit Kommandonamen. Das ist ein Zehn-Zeilen-Diff; das Ticket liest sich, als würde ein Lesepfad entfernt.

**Kleinste Verbesserung:** In `## Context` einen Satz: „Die reguläre Leseroute akzeptiert Legacy heute schon nicht als gültig; der Cutoff entfernt nur den Lesezugriff und benennt das Migrationskommando.“ Damit erwartet der Reviewer den kleinen Diff und sucht keinen Fallback, der nie existierte.

### P09 — P3: Beobachtungs-ACs nicht mehrfach belegen

**Betroffen:** AC-03, AC-04, AC-06, `## AC Coverage`.

**Befund:** Diese ACs sind Blueprint-Akzeptanzpunkte (bleiben), aber Beobachtungen bereits bewiesener Verträge. Mit P04 wird ihre Evidence TC-01 (Release-Gate) plus das jeweilige Gate; TC-04 belegt sie zusätzlich real.

**Kleinste Verbesserung:** Coverage-Zeilen nach P04 anpassen; keine weiteren ACs.

### P10 — P3: MG-01/MG-02 sind Run-Gates des Pilottickets — klar von den Gate-IDs des Pilottickets trennen

**Betroffen:** MG-01, MG-02, Task 4.

**Befund:** Die hier deklarierten `MG-01`/`MG-02` beschreiben die Panel-Gates des realen Pilotlaufs; das Pilotticket im Pilotprojekt hat eigene `MG-`/`EXT-`-IDs, die `run_gates` bindet.

**Kleinste Verbesserung:** In beiden Einträgen „(Panel-Gates des Pilotlaufs; die IDs des Pilottickets selbst stehen in dessen Datei)“ ergänzen.

---

## 4. Übergreifende Punkte

### Q01 — P2: Jeder neue Test muss einmal rot gewesen sein; Stringprüfungen sind Inventur, kein Nachweis

**Betroffen:** AI6-036 TC-06, AI6-037 TC-08, AI6-038 TC-09; die `AC Coverage`-Tabellen.

**Befund:** Mehrere TCs prüfen Vorkommen von Zeichenketten in README, `.env.example`, `.dockerignore`. I01 zeigt, dass eine davon heute schon besteht. `AGENTS.md` §11 verlangt, jede neue Assertion vor dem Nachweis einmal absichtlich scheitern zu lassen.

**Kleinste Verbesserung:** In jedem Ticket unter `## Review Focus` einen Satz: „Jede neue Assertion wurde einmal rot beobachtet; Dokumentationsprüfungen belegen nur Sicherheitsaussagen und Kommandos.“ Keine zusätzlichen Tests.

### Q02 — P3: Größe und neue Artefakte nach den Streichungen

**Betroffen:** AI6-036 (7 neue Klassen, 4 README-Abschnitte, 1 Protokoll), AI6-037 (3 Klassen, 4 Fixtures, 1 Katalogeintrag, 1 Protokoll), AI6-038 (1 Codeänderung, 1 Protokoll, 1 README-Abschnitt).

**Befund:** Der Split nach V1.7.9 ist erfolgt; ein weiterer Split lohnt nicht. Mit I04, I05, I07, M05, M06, P04 und P07 verschwinden Prädikatduplikate, zwei Fehlerpfade, eine Option und zwei Tests, ohne dass ein Blueprint-Deliverable fehlt.

**Kleinste Verbesserung:** Die Streichungen übernehmen; keine neue DTO, kein Reportformat, kein Rollbackjournal, kein Docker-Socket, kein zweiter Manifestparser, kein zweiter Renderer.

### Q03 — P3: Reihenfolge zwischen AI6-036 und AI6-049 festlegen

**Betroffen:** AI6-036 `## Notes`, AI6-049 `## Notes`.

**Befund:** Beide Tickets ändern `AI6ServiceProvider`, README, `.env.example`, `docker-compose.yml`, `RuntimeScriptsTest`/`RuntimeComposeContractTest`, `ScaffoldStructureTest`. „Wer zuerst integriert, erzeugt das Verzeichnis“ verschiebt Merge-Arbeit ins Ungewisse.

**Kleinste Verbesserung:** In beiden `## Notes` eine feste Reihenfolge (Empfehlung: AI6-036 zuerst, weil AI6-049 sein Restore-Gate auf einer nach README installierten Instanz durchführt) und mit I14 das gemeinsame Verzeichnis auflösen.

### Q04 — P3: Deutsch/Englisch und Formate sind sauber; nur zwei Kleinigkeiten

**Befund:** Sprachgrenze, Scope-Marker, Coverage-Header und Notes-Boilerplate stimmen in allen drei Dateien. AI6-037 `## Notes` und AI6-038 `## Notes` tragen die ID-Historie korrekt.

**Kleinste Verbesserung:** M13; sonst nichts.

### Q05 — P2: Was das nächste LLM nicht bauen soll

- Keine zweite Attestations-, Capability- oder Sandboxprüfung im Doctor (I04, I06).
- Keinen Heartbeat-Sammelmechanismus, keinen Docker-Socket, kein Prozessregister für `--all-processes` (I06).
- Keine Konfigurationsausnahme an der Bootstrapgrenze für `ai6:install` (I02).
- Keinen YAML-Dumper, keine zeilenbasierte YAML-Zerlegung, keinen Reparaturschritt in der Migration (M02, M09).
- Kein Transaktionsjournal, keinen Rollbackmechanismus, keinen Injektionsseam im Migrationskommando (M06).
- Keinen zweiten Reindex- oder Neuaufbaupfad neben `ticket_refresh` (P07).
- Keine neue Compose-Rolle, kein zweites Caddyfile; höchstens die eine in D05 entschiedene Zeile (I11).

---

## Empfohlene Reihenfolge

1. **Entscheidungen einholen:** D01–D05.
2. **Unerreichbares korrigieren:** I01, I02, I03, M02, P02.
3. **Duplikate und Mechanik streichen:** I04, I05, I07, I10, M05, M06, P04, P07.
4. **Kontext und Nachweisorte präzisieren:** I06, I08, I09, I11, I12, I13, M03, M04, M07, M08, P03, P05.
5. **Redaktion:** I14–I20, M09–M13, P06, P08–P10, Q01–Q04.

Für die unabhängige Prüfung genügt pro Kennung eine Zeile:

| Vorschlag | Urteil | Beleg/Gegenbeleg | Kleinste übernommene Änderung | Betroffene AC/TC/MG | Entscheidung nötig? |
|---|---|---|---|---|---|
| Kennung | übernehmen / teilweise / verwerfen / offen | konkrete Quelle | Text oder Verweis | vorhandene IDs | D01–D05 oder nein |

Zu jedem übernommenen Vorschlag muss erkennbar bleiben, welches konkrete Problem er löst und warum die gewählte Lösung für dieses kleine Produkt genügt. Ein Vorschlag, der nur „theoretisch nützlich“ ist, wird verworfen.

---

## Tatsächlich ausgeführte Prüfungen und Grenzen dieser Analyse

- Alle drei Tickets vollständig gelesen und gegen ihre Blueprints in Plan §15.8 (Titel, `kind`, `risk`, `milestone`, `depends_on`, Requirement-Refs, erster Goal-Absatz) verglichen: übereinstimmend. `AI6-049` und das erste Review als Kontext gelesen.
- Die drei Dateien mit dem realen `TicketV1Parser` und `Ai6DetailV1TicketValidator` geprüft: keine Fehler. Coverage bijektiv, keine unreferenzierten oder undeklarierten `TC-`/`MG-`-IDs, `files` gleich Scope-Liste und -Reihenfolge, `new`/`existing` stimmt mit dem Dateibestand überein (für AI6-038 als Runbasis-Aussage), Notes-Boilerplate wörtlich, UTF-8 ohne BOM, LF-only, genau ein finales LF.
- `php scripts/generate-ticket-manifest.php --check`: „Ticket manifest is current.“
- Im Code geprüft: Bootstrapreihenfolge und Ausnahmen in `AI6ServiceProvider` (`register()` löst `SecurityPolicy`, `RunArtifactRoot`, Provider-Konfigurationen und — außer für vier Kommandos — den Redaction-Ring eager auf); `RedactionKeyringFactory` (Fallback nur `local`/`testing`); `SecurityPolicyFactory` (strict verbietet Reduktionen; Ack nur bei Reduktion); `DoctorCommand`, alle sechs vorhandenen Checks, `ProviderCapabilityReport` (`diagnosis()`, `humanEvidence()`, `doctor()`, `boot()`); `SecurityReviewerProfileResolver`; `SecurityReviewStep` (Fake-Sperre); `RuntimeHealthCommand`/`RuntimeHeartbeat`; `docker-compose.yml` (Rollenumgebungen, `tmpfs`-Heartbeats, Volumes, festes `APP_URL`); `deploy/Caddyfile`; `EnforceHttpsOrPrivateAccess`, `ResolveTrustedProxies`; `PasskeyRelyingPartyFactory`; `docker/entrypoint.sh`; `.dockerignore`, `Dockerfile`; `FakeAgentReleaseGateCommand`; `scripts/generate-ticket-manifest.php`; Kontrollpolicy in `config/ai6.php`; `routes/console.php`; `RetentionPolicy`, `RunArtifactRoot`; `GitConfigurationFactory`; `.env.example`; `LoginConfirmationManager` (Mailversand als Job, fail closed ohne Adresse).
- Für AI6-037: `LegacyTicketReader`, `LegacyTicketDocument`, `RestrictedYaml`, `TicketV1Parser` (AC-/TC-Regexe, `KNOWN_KEYS`), `GenericV1TicketValidator`, `Ai6DetailV1TicketValidator`, `TicketDependencyGraph`, `TicketInventory`, `TicketReadModelProjector`, `ReprojectUnparsedTicketsCommand`, `PromptCatalog`, `PromptHelp`, `ManualFindingListExtractor`, `QueueReevaluation`, `ticket-prompt/index.html`, `ai/prompts/`, `tools/`, Fixture `legacy-m169.md`.
- Für AI6-038: Existenz aller genannten Klassen, Tests, Tabellen (`run_agents`, `run_gates`), Wait-Reason `manual_report`, Blockerwert `content_redacted`, Smoke-Flags, Agentprofile und Serverdefaults der Projektkonfiguration, `ProjectConfigurationParser` (`defaults.reviewers`).
- Vorhandene Tests geprüft, die von den Tickets berührt werden, aber nicht in `files` stehen: `RuntimeComposeSmokeTest` (I09), `RuntimeComposeContractTest` (I17), `PromptHelpBrowserSmokeTest` (M10).

Nicht ausgeführt wurden `docker build`/`docker compose config` (I01, I09), die Proxykette (D05/I11), ein SSH-Aufbau (I12), reale Providerturns oder der Pilot. Aussagen zu Caddys Umgang mit `X-Forwarded-Proto` und zu Compose-`.env`-Interpolation beruhen auf dem dokumentierten Verhalten dieser Werkzeuge und sind vor einer Ticketänderung einmal am gepinnten Stand zu bestätigen. Das reale Pilotticket lag nicht vor.

| Datei | SHA-256 der geprüften Bytes |
|---|---|
| tickets/AI6-036.md | 670bb6572786ac6ef6659c9bb654a315e63c9136f72fc580f23b2f36346d79f0 |
| tickets/AI6-037.md | e3fa113d2edfc53af84094c9603d620b3c9221869ed243478645fee36889e5e0 |
| tickets/AI6-038.md | 0632fb300bd1d255396469b3396f97db0244229b4f606cfab16bdb19f0343ad1 |
| tickets/AI6-049.md (Kontext) | 8b0a088b22b36d594c7572e1a149c861f7a4cc32449af3bca1648e9a43885e8c |
