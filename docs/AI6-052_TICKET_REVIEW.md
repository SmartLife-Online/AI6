# AI6-052: Verbesserungsliste zur kritischen Prüfung

Prüfstand: 25. September 2026. Gegenstand ist ausschließlich der uncommittete Erstentwurf `tickets/AI6-052.md`. Diese Liste ist eine Prüfvorlage für ein zweites LLM, keine beschlossene Anforderung. Jeder Punkt soll einzeln übernommen, eingeschränkt, zusammengeführt oder verworfen werden.

**Bezug und Grenzen**

- Repository-HEAD `4ed8927`, Planarbeitsstand V1.7.11 (uncommittet), Entscheidungsantrag `docs/AI6-052_PROMPT_TOOLS_ENTSCHEIDUNGSANTRAG.md`.
- SHA-256 der geprüften Ticketdatei: `6d319d57b5f8178ae080575b9c69ef0075dd0433ad667e02e133a48dbb743b55`; des Plans: `044653149657d2aa29e3c8d58268387080d30c156dcf472d91ef00f07ba4f19d`.
- Ausgeführt: realer `TicketV1Parser` und `Ai6DetailV1TicketValidator` (0 Fehler) sowie Strukturabgleich (AC-/TC-IDs lückenlos, Coverage bijektiv, `files` = Scope, `existing` gegen Dateibestand, erster Goal-Absatz = Blueprint, LF/BOM/finales LF) ohne Befund. Zusätzlich Wegwerf-Proben ausschließlich im Scratchpad: PHPUnit-Proben über den realen HTTP-/Livewire-Pfad mit einer Zusatzroute, die `PromptHelp` nur mit der Gruppe `web` registriert und damit die geplante Routenform simuliert, sowie PHP-Proben gegen die zentralen Redaction-Regeln. Ergebnisse in Anhang A. Umgebung: Windows, PHP 8.5.5 NTS mit PCRE-JIT, Laravel `v13.23.0`, Livewire `v4.4.0`.
- Nicht ausgeführt: Implementierung, reguläre Test-Suite, Linux-Läufe, Browser-Smoke. Ticket, Plan, Status und Gates wurden nicht geändert; einzige neue Datei ist diese Liste.

**Kurzurteil.** Das Ticket ist formal gültig und blueprinttreu. Die fachliche Änderung ist sehr klein: eine Routenzeile und ein Gastlink. Die Schwächen liegen fast vollständig im Nachweisteil:

- Task 1 verlangt eine Gastnavigation, die es nicht gibt.
- Das Verhalten begonnener und abgelaufener Anmeldungen ist nicht festgelegt.
- Mehrere Tests wären grün, ohne etwas zu beweisen: CSRF wird im Test standardmäßig übersprungen und in der gesperrten Laravel-Version `v13.23.0` zusätzlich über `Sec-Fetch-Site` umgangen, und ein Zeitreisetest mit `actingAs` lässt keine Sitzung ablaufen.
- Die zentrale Redaction hat einen vorbestehenden 500er, der jetzt ohne Login auslösbar ist.
- Die Ausgabeproperties der Komponente sind vom Client setzbar.
- Die Testvorgaben sind überdimensioniert: Fixtures je Schutzbereich und eine zweite Grenzfallmatrix.

Die meisten Vorschläge machen die Umsetzung kleiner und die Nachweise schärfer.

**Prioritäten.** P1 = vor Implementierungsfreigabe klären (belegter Fehler, Scheinnachweis oder offene Entscheidung). P2 = Präzisierung oder Vereinfachung, die Nacharbeit oder Mehraufwand verhindert. P3 = Lesbarkeit, Abgrenzung, Aufräumen.

---

## Zielbild in fünf Sätzen

Vorschlag für den Anfang von `## Tasks` oder das Ende von `## Context`, damit Umsetzer und Reviewer die Größe der Änderung sofort sehen:

1. `routes/web.php`: ausschließlich die bestehende Registrierung von `prompts.help` aus der `auth`-Gruppe lösen, ohne neue Middleware oder Gruppe; die Inventurzeile lautet danach `GET /prompts/help [web]`.
2. `resources/views/layouts/app.blade.php`: ein neuer `@guest`-Block im Header mit genau einem Link „Prompt-Hilfe“ in derselben `<nav>`-Auszeichnung; der `@auth`-Block und das CSS bleiben unverändert.
3. `PromptHelp` und `resources/views/prompts/help.blade.php` bleiben fachlich unverändert; einzige mögliche Ausnahme ist V06.
4. Die Tests drehen die beiden bisherigen Gast-Ausschlusstests um und ergänzen genau vier Nachweise: realer Gast-GET mit Livewire-POST bei aktiver CSRF-Prüfung, die Livewire-Grenze mit einem geschützten Snapshot, eine Query-Beobachtung und einen Gast-Browser-Smoke.
5. Die README erhält einen kurzen Absatz zum anmeldungsfreien, aber nicht netzöffentlichen Einstieg.

---

## A. Belegte Lücken und Fehler

### V01 — P1: Die „vorhandene Gastnavigation“ existiert nicht

**Ticketbezug:** Task 1, AC-01, TC-01, Sensitive paths (`resources/views/layouts/app.blade.php`).

**Beleg:** `resources/views/layouts/app.blade.php:12–27` rendert die gesamte Navigation innerhalb von `@auth`; für Gäste gibt es keinen Navigationsblock. Die Loginseite verwendet dasselbe Layout (`resources/views/auth/login.blade.php:1`). Der Header-Stil gilt für jede `nav` im Header (`public/assets/ai6.css:47`, `body > header nav`).

**Vorschlag:** Task 1 so umformulieren: „Im Header des gemeinsamen Layouts einen neuen `@guest`-Block mit genau einem Link auf `route('prompts.help')` in derselben `<nav>`-Auszeichnung ergänzen; der `@auth`-Block und `public/assets/ai6.css` bleiben unverändert.“ TC-01 um eine Prüfung ergänzen: Loginseite und Gastansicht der Prompt-Hilfe enthalten keinen geschützten Navigationseintrag (Projekte, Attention-Inbox, Agentenprofile, Abmeldeformular). Das ist der falsifizierbare Nachweis für die im Sensitive-paths-Eintrag verlangte „fortbestehende Authentifizierungsgrenze aller übrigen Einträge“, der bisher fehlt.

**Overengineering-Check:** keine neue Datei, kein CSS.

### V02 — P1: Verhalten begonnener Anmeldungen auf der öffentlichen Seite festlegen

**Ticketbezug:** Context (dritter Absatz), AC-01, AC-03, TC-03, Do Not Change (`app/AI6/Auth/`).

**Beleg:** `EnsureCompletedAuthentication` hängt an der gesamten `web`-Gruppe (`bootstrap/app.php:71`). Eine Sitzung im Zustand `enrollment`, `primary_pending` oder `email_pending` wird auf jeder Route außerhalb des jeweiligen Anmeldeschritts umgeleitet; JSON-Anfragen wie Livewire-Updates erhalten 403 (`app/AI6/Auth/Http/EnsureCompletedAuthentication.php:49–79, 98–105`). `EnsureActiveUser` meldet deaktivierte Benutzer ab. Proben mit der simulierten öffentlichen Route: `primary_pending` plus GET ergibt 302 auf `/auth/factor`; `primary_pending` plus Livewire-Update der Prompt-Hilfe ergibt 403 `{"message":"Authentifizierung nicht abgeschlossen."}`. Die Seite sehen nach AI6-052 also Sitzungen ohne angemeldeten Benutzer, nicht jede nicht vollständig angemeldete Sitzung.

**Vorschlag:** Context um einen Satz ergänzen und AC-01/AC-03 daran ausrichten: „Eine begonnene Anmeldung bleibt beim ausstehenden Anmeldeschritt (Umleitung, bei Livewire 403); dafür wird keine Ausnahme in `EnsureCompletedAuthentication` eingeführt.“ Genau ein Testfall prüft beide Beobachtungen. So wird verhindert, dass die Umsetzung den Auth-Code „repariert“, der auf Do Not Change steht.

**Overengineering-Check:** ein Satz, ein Testfall, keine Auth-Änderung.

### V03 — P1: „Abgelaufene Anmeldung“ definieren; der naheliegende Test ist wirkungslos

**Ticketbezug:** AC-03, TC-03.

**Beleg:** `AuthFeatureTestCase::actingAs()` setzt den Benutzer direkt am Guard; Guard und Session-Store bleiben zwischen Testrequests erhalten. Probe: angemeldet, danach `travel(session.lifetime + 5)->minutes()`, dann GET `/projects` ergibt weiterhin 200. Ein Zeitreisetest mit `actingAs` wäre also grün, ohne etwas zu beweisen. Eine tatsächlich abgelaufene Laravel-Sitzung behandelt der Server als neue Gastsitzung.

**Vorschlag:** Im Ticket festlegen: „begonnen“ bedeutet Zustand `primary_pending` (V02); „abgelaufen“ bedeutet eine Sitzung ohne gültige Anmeldung, die der Server als neue Gastsitzung behandelt und die deshalb durch die Gastfälle abgedeckt ist. Ausdrücklich keinen Zeitreisetest mit `actingAs` verlangen. Wer dennoch einen eigenen Nachweis will, muss ohne `actingAs` über ein echtes Sessioncookie arbeiten; das ist nicht erprobt und wird nicht empfohlen.

### V04 — P1 (Entscheidung): Vorbestehender 500er der zentralen Redaction ist ohne Login auslösbar

**Ticketbezug:** AC-05, TC-05, Context, Out of Scope, Do Not Change.

**Beleg:**

- `ManualFindingListExtractor::extract()` redigiert zuerst die gesamte Rohangabe (`app/AI6/Prompts/ManualFindingListExtractor.php:29`) und prüft das Bytelimit erst danach (`:34`); gefangen wird nur `InvalidRedactionInputException` (`:30`).
- `Redactor::redact()` wirft eine untypisierte `RuntimeException`, wenn `preg_match_all` scheitert (`app/AI6/Shared/Redaction/Redactor.php:25`).
- `PromptHelp::processReviewAnswer()` fängt nur `ManualFindingListException` und `PromptRenderingException` (`app/AI6/Prompts/Livewire/PromptHelp.php:51, 70`).
- Proben mit PCRE-JIT: Die Regel `secret-assignment` scheitert ab einem gequoteten Wert von 8190 Byte mit „JIT stack limit exhausted“. `token-assignment`, `windows-user-path` und `unix-user-path` scheitern ebenso bei Eingaben innerhalb des Limits.
- Über die simulierte öffentliche Route liefert eine Fix-Liste mit `- password="<8190 × a>"` als Gast HTTP 500 und genau einen Fehlerlogeintrag „Redaction rule secret-assignment could not be evaluated.“ ohne Eingabebytes.
- Eine übergroße Eingabe mit einem solchen Wert endet ebenfalls als 500 statt als typisierte Grenzüberschreitung.
- Eine possessive oder „unrolled“ Schleife (etwa `(?:\\.|[^"\\])*+`) vermeidet den Fehler in der Probe bei gleichem Treffer.

**Einordnung:** Die Fehlerantwort verrät nichts, weil die Meldung fest ist und `zend.exception_ignore_args` gilt. Es entsteht aber ein generischer 500er statt der vertraglich generischen sichtbaren Ablehnung, und jeder, der die Instanz erreicht, kann Fehlerlogeinträge ohne Anmeldung erzeugen. Die Ursache liegt in `app/AI6/Shared/Redaction/` und betrifft alle Verbraucher, nicht nur AI6-052. Die Linux-Laufzeit ist nicht geprüft; die JIT-Stackgrenze legt PHP selbst fest, gleiches Verhalten ist wahrscheinlich.

**Vorschlag:** Nicht still in AI6-052 beheben: Die Redaction ist eine zentrale Grenze mit eigenen Golden-Vektoren. Der Mensch entscheidet zwischen (a) einem kleinen eigenen Fix vor der Freischaltung und (b) bewusstem Hinnehmen. In beiden Fällen gilt: Context und Out of Scope nennen die bekannte Grenze, `app/AI6/Shared/Redaction/` kommt auf Do Not Change, und weder AC-05 noch TC-05 behaupten, jede Eingabe bis 262144 Byte werde über HTTP verarbeitet.

### V05 — P1: CSRF im Test tatsächlich aktivieren und die Origin-Abkürzung ausschließen

**Ticketbezug:** TC-02, TC-06, AC-06, Review Focus (zweiter Punkt).

**Beleg:**

- `PreventRequestForgery::handle()` lässt jeden Request durch, solange `runningUnitTests()` gilt. In der gesperrten Version `v13.23.0` genügt außerdem der Kopf `Sec-Fetch-Site: same-origin` ohne Token (`vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/PreventRequestForgery.php:95–111, 143ff`).
- Das vorhandene Idiom zum Aktivieren der Prüfung ist `$this->app->instance('env', 'production')` (`tests/Feature/Shared/Http/CsrfAndArchitectureTest.php:54`).
- Proben mit echtem Gast-Snapshot bei aktiver Prüfung: ohne Token 419; fremdes Token 419; ohne Token, aber mit `Sec-Fetch-Site: same-origin` 200; Token aus der Gastseite (`data-csrf` am Livewire-Skript) 200.
- Ohne diese Vorgaben wäre TC-02 auch bei fehlender CSRF-Bindung grün, obwohl der Text „keine ausgeschaltete Middleware“ verlangt.

**Vorschlag:** TC-02 und TC-06 verlangen ausdrücklich:

- CSRF-Prüfung über das vorhandene Idiom aktiv;
- Token aus der geladenen Gastseite;
- weder die Negativfälle noch der positive Kontrollfall senden `Sec-Fetch-Site`.

Negativfälle und Kontrollfall laufen im selben Test von `PromptHelpPageTest` mit derselben Nutzlast. Damit ist ausgeschlossen, dass eine Ablehnung einen anderen Grund hat. Die vorhandenen Gastfälle in `CsrfAndArchitectureTest.php:52–78` (fehlendes und fremdes Token am Update-Endpunkt) laufen unverändert als Regression; dort nichts duplizieren.

**Nebenbefund, nicht Teil von AI6-052:** Der README-Satz „Ein fehlendes Token und ein Token einer anderen Session werden abgewiesen“ (Abschnitt „HTTP-, Session- und Markdown-Härtung“) gilt nur ohne `Sec-Fetch-Site: same-origin`. Das ist kein Sicherheitsproblem, weil Browser diesen Kopf selbst setzen; die neue README-Passage soll aber nichts Schärferes behaupten.

### V06 — P2: Die Ausgabeproperties der Komponente sind vom Client setzbar

**Ticketbezug:** AC-02 (Blueprintsatz „erzeugt ausschließlich den zentral gerenderten dynamischen Prompt“), AC-06, Task 2.

**Beleg:**

- `app/AI6/Prompts/Livewire/PromptHelp.php:29–37` deklariert `dynamicPreview`, `dynamicCopyEnabled`, `nothingToFix`, `dynamicRejected` und `redacted` als ungesperrte öffentliche Properties.
- Probe: Ein gültiger Gast-POST mit `updates: {dynamicPreview: "untergeschoben", dynamicCopyEnabled: true}` ergibt 200, und der untergeschobene Text steht in der Vorschau.

Das wirkt nur auf die eigene Ansicht, weil CSRF eine Fremdauslösung verhindert; ein Sicherheitsleck ist es nicht. Der Blueprintsatz gilt so aber nicht wörtlich. Außerdem wird „Updates mit verändertem Snapshot“ in AC-06 leicht mit Livewire-`updates` verwechselt, die gar nicht abgewiesen werden.

**Vorschlag (empfohlen):** die fünf Ausgabeproperties mit `#[Locked]` versehen, dasselbe Muster wie `app/AI6/Runs/RunTimelinePage.php:70ff`, und in TC-06 einen Negativfall ergänzen: Ein Update auf `dynamicPreview` wird abgewiesen. Probe mit einer gesperrten Property ohne Debug: 419, leerer Body, ein ERROR-Logeintrag, der nur den Propertynamen nennt. Einen vergleichbaren Logpfad gibt es schon heute, etwa ein `reviewAnswer` als Array über einen `TypeError`.

**Alternative ohne Codeänderung:** AC-02 und AC-06 präzisieren. Snapshotintegrität bedeutet eine unveränderte Prüfsumme; clientseitig gesetzte Ausgabewerte wirken nur auf die eigene Ansicht.

**Overengineering-Check:** fünf Attribute und ein Testfall. Der Code zeigt damit zugleich, dass diese Werte serverseitig entstehen.

### V07 — P2: `PromptHelp` fehlt in der Architekturinventur „kein Prozessstart aus HTTP/Livewire“

**Ticketbezug:** AC-04, TC-04, TC-07, `files` (`tests/Feature/Shared/Http/CsrfAndArchitectureTest.php`).

**Beleg:** `CsrfAndArchitectureTest::test_http_and_livewire_entry_points_cannot_start_a_process_synchronously` prüft Dateien unter `/Http/` sowie sieben namentlich gelistete Livewire-Klassen (`CsrfAndArchitectureTest.php:24–32`). `app/AI6/Prompts/Livewire/PromptHelp.php` fehlt darin. Nach AI6-052 ist sie der einzige ohne Anmeldung erreichbare Livewire-Einstieg.

**Vorschlag:** `AI6/Prompts/Livewire/PromptHelp.php` in die Liste aufnehmen und als Teil von TC-04 ausweisen. Das ersetzt jede zusätzliche Prozess- oder Git-Instrumentierung.

---

## B. Nachweise schärfen und verkleinern

### V08 — P1: TC-04 mit konkreten, billigen Beobachtungsmitteln festlegen

**Ticketbezug:** AC-04, TC-04, Task 4.

**Beleg:**

- `AuthFeatureTestCase::setUp()` setzt `session.driver` auf `database` mit In-Memory-SQLite (`tests/Feature/Auth/AuthFeatureTestCase.php:25–30`); der Cache ist im Test `array`.
- Compose betreibt den Dienst `app` mit `SESSION_DRIVER=database` und `CACHE_STORE=file` (`docker-compose.yml:144, 153`).
- Proben: Ein Gast-GET greift nur auf `sessions` zu (zweimal `select`, einmal `insert`). Ein gültiger Gast-POST greift ebenfalls nur auf `sessions` zu. `users` wird für Gäste nicht gelesen.
- Der bestehende Test `test_processing_has_no_persistent_or_outbound_side_effects` (`tests/Feature/Prompts/PromptHelpPageTest.php:263`) zählt nur Zeilen und schließt `sessions` aus.

**Vorschlag:** TC-04 konkret festlegen:

1. `DB::listen` über Gast-GET, gültigen und ungültigen Gast-POST. Erlaubt ist ausschließlich die Tabelle `sessions`; das ist die „gesondert ausgewiesene technische Sessionverwaltung“. Weil diese Menge im Test nicht leer ist, ist die Prüfung falsifizierbar.
2. Speicherung prüfen: Die Markertexte vor und in der Fix-Liste fehlen im dekodierten `sessions.payload`, in `Illuminate\Cache\Events\KeyWritten`-Ereignissen (erwartet: keine), im erfassten Log (vorhandenes Muster) und nach einem Reload.
3. Ausgehende Wirkungen: `Queue::fake()`, `Mail::fake()`, `Http::preventStrayRequests()`, dazu V07.

Den bestehenden Zeilenzählertest ersetzen oder erweitern, keinen parallelen anlegen. AC-04 entsprechend fassen: „… greifen ausschließlich auf die technische Sessiontabelle zu“ statt „Session-/Benutzerzugriffe“, denn für Gäste gibt es keine Benutzerzugriffe. „Reload“ meint einen neuen Server-GET; eine clientseitige Formularwiederherstellung des Browsers ist nicht Gegenstand.

**Overengineering-Check:** nur Laravel-Bordmittel; kein Query-Klassifizierer, keine Proxy-Fakes je Naht, keine neue Produktivklasse.

### V09 — P2: TC-03 ohne Fixture-Aufwand und mit dem relevanten Angriff

**Ticketbezug:** AC-03, TC-03, Task 3.

**Beleg:**

- `auth` läuft vor der Model-Bindung. Proben: Gast-GET auf `/projects/999999`, `/projects/999999/tickets`, `/projects/999999/runs/abc`, `/agents/profiles` und `/human-requests` ergibt jeweils 302 auf `/login`; ein Gast-JSON-POST auf `/admin/users` ergibt 401.
- Die exakte Inventur in `PublicRouteInventoryTest` pinnt zusätzlich die Middleware jeder anderen Route.
- Livewire v4 wendet je Snapshot die persistente Middleware (`auth`, `can`) seiner Ursprungsroute `memo.path` erneut an (`vendor/livewire/livewire/src/Mechanisms/PersistentMiddleware/PersistentMiddleware.php:41–48, 100ff`).
- Die Snapshotprüfsumme ist ein HMAC mit dem `APP_KEY` ohne Sessionbindung (`vendor/livewire/livewire/src/Mechanisms/HandleComponents/Checksum.php:81–90`). Deshalb erreicht ein Snapshot aus einer fremden Sitzung überhaupt erst die Middlewareprüfung.
- Probe mit `AttentionInboxPage` (`/human-requests`, braucht nur einen Benutzer): Ein Gast mit dem geschützten Snapshot allein, mit öffentlichem Snapshot zuerst oder mit geschütztem Snapshot zuerst erhält jeweils 401 und kein Inbox-HTML.

**Vorschlag:** „Real vorbereitete Ziele“ je Schutzbereich streichen. Stattdessen:

1. die exakte Inventur (V16);
2. ein kleiner Datenprovider mit je einer Route pro Schutzbereich (Projekt, Ticket, Run, Agentenprofil, Administration, HumanLoop) und nicht existierender ID; erwartet wird exakt die Loginumleitung beziehungsweise 401. Das ist falsifizierbar, weil ein fehlendes `auth` 404 oder 200 liefern würde;
3. Livewire: ein als Benutzer erzeugter Snapshot von `AttentionInboxPage`, als Gast allein und gemischt in beiden Reihenfolgen gesendet; erwartet wird 401 ohne geschütztes HTML oder Snapshot im Body;
4. derselbe Snapshot mit auf den öffentlichen Pfad umgeschriebenem `memo.path` ergibt 419 mit leerem Body (Probe). Genau diesen Umgehungsversuch macht die neue öffentliche Route erst möglich.

Begonnene und abgelaufene Anmeldungen folgen V02 und V03.

### V10 — P2: Snapshot-Negativfälle deterministisch prüfen

**Ticketbezug:** AC-03, AC-06, TC-03, TC-06.

**Beleg:** `CorruptComponentPayloadException::render()` liefert nur ohne Debug 419 mit leerem Body, sonst die Fehlerseite (`vendor/livewire/livewire/src/Mechanisms/HandleComponents/CorruptComponentPayloadException.php:19–30`). `tests/TestCase.php` lädt die Root-`.env` nicht; `APP_DEBUG` stammt dann aus der Prozessumgebung beziehungsweise dem git-ignorierten `tests/.env`. Probe: Veränderte Daten des öffentlichen Snapshots ergeben 419.

**Vorschlag:** In den Snapshot-Negativfällen `config(['app.debug' => false])` setzen und exakt 419 ohne Vorschau erwarten. Die Fälle den ACs sauber zuordnen, damit die Doppelnennung „manipulierte Snapshots“ in AC-03 und AC-06 verschwindet:

- AC-03 (Zugangsgrenze): fremder geschützter Snapshot, gemischte Anfragen, umgeschriebener `memo.path`;
- AC-06 (Anfrageintegrität): CSRF, veränderte Daten des öffentlichen Snapshots und bei Übernahme von V06 das gesperrte Property.

### V11 — P2: TC-02 beweist die Redaction auf dem öffentlichen Pfad nicht

**Ticketbezug:** AC-02 („redigierter terminaler Fix-Liste“), AC-05, TC-02.

**Beleg:** TC-02 sendet „zwei Listeneinträge“ ohne sensiblen Wert; die Redaction bleibt damit auf dem Gastpfad unbelegt. Die Livewire-Antwort enthält Snapshot und gerendertes HTML (`components[0].effects.html`). Proben: Mit `- password=hunter2` enthält die gesamte Antwort den Marker, aber weder `hunter2` noch den Vorlauftext. Die Zeichenfolge `</textarea><script>alert(1)</script>` erscheint in `effects.html` nur escaped. Für HTML- oder Skripteingaben gibt es bisher keinen Test (`PromptHelpPageTest` prüft nur den CSP-Kopf und das Fehlen von `<script>` im Seitengerüst).

**Vorschlag:** TC-02 mit einem sensiblen Wert und einer Textarea-Ausbruchszeichenfolge in der Fix-Liste ausführen und den gesamten JSON-Body prüfen: Marker vorhanden, Klartext und Vorlauf fehlen, `reviewAnswer` leer, Vorschau gleich den Rendererbytes, Ausbruchstext nur escaped. Damit ist zugleich die „sichere HTML-Ausgabe“ aus AC-05/TC-05 auf dem öffentlichen Pfad belegt.

### V12 — P2: TC-05 nicht als zweite Grenzfallmatrix „als Gast“ anlegen

**Ticketbezug:** AC-05, TC-05.

**Beleg:** Die Unit-Tests decken CRLF, die Markermatrix, `Nichts zu fixen.`, 262144/262145 Byte, ungültiges UTF-8 und die Redactionreihenfolge bereits ab (`tests/Unit/Prompts/ManualFindingListExtractorTest.php`), die Komponententests die sichtbaren Zustände (`tests/Feature/Prompts/PromptHelpPageTest.php:142–261`). Die Komponente liest keinen Benutzer (`RedactionContext('manual-prompt-help', null, 'prompt-help')`), und `Livewire::test` umgeht die Routenmiddleware ohnehin; ein zusätzlicher Durchlauf „als Gast“ beweist dort nichts Neues. Der Blueprint verlangt an dieser Stelle ausdrücklich „Regressionen“.

**Vorschlag:** TC-05 umfasst:

1. die bestehenden Tests, unverändert grün;
2. genau einen ungültigen Fall über den realen Gastpfad (aus TC-04 wiederverwenden) mit deaktiviertem Kopierknopf in der Antwort;
3. die HTML-Fälle aus V11;
4. optional einen 262144-Byte-ASCII-Fall über den TC-02-Helfer, falls der Mensch die HTTP-Schicht mit abdecken will (Livewire-Nutzlastgrenze 1 MiB).

Keine Aussage, die V04 widerspricht.

### V13 — P2: AC-07 teilen

**Ticketbezug:** AC-07, AC Coverage, TC-08, TC-09.

**Beleg:** AC-07 verbindet Browserverhalten, dessen Smoke mangels Umgebung übersprungen sein darf, mit README-Inhalt. Bei übersprungenem Smoke bliebe auch der README-Teil formal offen, und eine `criterion_refs`-Bindung kann beides nicht trennen.

**Vorschlag:** AC-07 nur für das Browserverhalten (TC-08), ein neues AC-08 für die README (TC-09). Die neue ID wird angehängt; bestehende IDs bleiben unverändert, weil diese Liste sie zitiert.

### V14 — P2: TC-08 strukturieren und die dynamische Kopie tatsächlich prüfen

**Ticketbezug:** TC-08, Task 5, AC-07.

**Beleg:** Der vorhandene Smoke meldet sich per Passwort und TOTP an (`tests/Feature/Prompts/PromptHelpBrowserSmokeTest.php:22–36`) und prüft beim dynamischen Prompt nur den Vorschautext, nicht die kopierten Bytes (`:82–87`). Der Smoke-Server läuft als `cli-server`, CSRF ist dort also aktiv; Sessions liegen in der Smoke-Datenbank.

**Vorschlag:** Eine zweite Testmethode im selben Test: ohne Benutzer-Seeding (nur `initializeBrowserSmokeDatabase`) und mit eigener Browsersitzung vor jeder Anmeldung. Die gemeinsamen Schritte (statisch kopieren, Fallback, dynamisch erzeugen und kopieren, Breiten, CSP-Konsole) wandern in eine private Methode, die auch die bestehende angemeldete Methode nutzt. Neu ist nur der Klick auf die dynamische Kopieraktion mit Bytevergleich gegen `#dynamic-preview`. Keine Änderung am Harness.

### V15 — P3: Ort der Gast-CSP-Prüfung festlegen

**Ticketbezug:** TC-07.

**Beleg:** „Gast-HTML … zusätzlich prüfen“ nennt keinen Ort. `tests/Feature/Shared/Http/HttpHardeningTest.php` steht nicht in `files`; `tests/Feature/Prompts/PromptHelpPageTest.php:315` enthält bereits den CSP-, Asset- und Inline-Test, derzeit angemeldet.

**Vorschlag:** diesen bestehenden Test auf einen Gastaufruf umstellen oder um einen Gastaufruf ergänzen; `HttpHardeningTest` läuft nur als Regression.

### V16 — P3: Die erwartete Inventurzeile wörtlich nennen

**Ticketbezug:** Task 6, AC-06, TC-07, Sensitive paths.

**Beleg:** `tests/Feature/Shared/Http/PublicRouteInventoryTest.php:65` enthält `'GET /prompts/help [web, auth]'`.

**Vorschlag:** Die einzige erwartete Änderung wörtlich nennen: `'GET /prompts/help [web]'`. `PublicRouteInventoryTest.php` wie in AI6-044 als sensitiven Pfad mit genau dieser Erwartung führen.

---

## C. Formulierung, Abgrenzung, Dokumentation

### V17 — P2: „Öffentlich“ heißt „ohne Anmeldung“, nicht „netzöffentlich“

**Ticketbezug:** Context, AC-07 beziehungsweise AC-08, TC-09, README.

**Beleg:** Der Zugang zur Instanz läuft über einen SSH-Tunnel oder über VPN plus HTTPS (`README.md:64ff`, `:113`). Host-, HTTPS- und Private-Access-Regeln bleiben unverändert. Ohne Erläuterung liest sich der Titel „Öffentlicher Zugang“ wie eine Internetfreigabe.

**Vorschlag:** Context und README-Anforderung um einen Satz ergänzen: „Öffentlich bedeutet ohne Anmeldung für jeden, der die Instanz über den dokumentierten Zugang erreicht; Netzwerk-, Host- und HTTPS-Grenzen ändern sich nicht.“

### V18 — P2: TC-09 prüfbar machen und den README-Ort nennen

**Ticketbezug:** TC-09, Task 6.

**Beleg:** TC-09 nennt weder Testebene noch Datei. Die README beschreibt die Prompt-Hilfe bisher überhaupt nicht. README-Aussagen werden im Repository durch Tests gebunden, zum Beispiel in `tests/Feature/Auth/AuthenticationDocumentationTest.php`.

**Vorschlag:** Entweder zwei oder drei gepinnte Kernaussagen in `PromptHelpPageTest`, das bereits in `files` steht (URL `/prompts/help`, „ohne Anmeldung“, technische Session), oder die Prüfung ausdrücklich als Reviewerprüfung ausweisen. Den Zielabschnitt nennen, etwa „## Benutzer, Projektrollen und Basislogin“. Die Formulierung „genau die drei Funktionen“ nicht pinnen (siehe V21).

### V19 — P3: Task 2 als „voraussichtlich keine Codeänderung“ kennzeichnen

**Ticketbezug:** Task 2, `files`.

**Beleg:** Weder `app/AI6/Prompts/Livewire/PromptHelp.php` noch `resources/views/prompts/help.blade.php` enthält einen Anmeldebezug.

**Vorschlag:** „Die Komponente und ihre Ansicht bleiben voraussichtlich unverändert; einzige mögliche Änderung ist V06. Die Aufgabe besteht im Nachweis über den realen Gastpfad.“ Das verhindert erfundene Änderungen, die nur die Aufgabe rechtfertigen sollen.

### V20 — P3: Context um den tragenden Mechanismus ergänzen

**Ticketbezug:** Context, Review Focus.

**Beleg:** siehe V09.

**Vorschlag:** Zwei Sätze im Context: „Der gemeinsame Update-Endpunkt wendet für jeden Snapshot die `auth`-/`can`-Middleware seiner Ursprungsroute erneut an; die Snapshotprüfsumme ist nicht sessiongebunden. Die öffentliche Route trägt nur `web`; geschützte Komponenten bleiben dadurch ohne endpunktweite Ausnahme geschützt.“ Review Focus ergänzen: „Keine Änderung an globaler Middleware, an Livewires persistenter Middleware oder an `EnsureCompletedAuthentication`; die Tests aktivieren CSRF tatsächlich.“

### V21 — P3: Folgewirkung auf AI6-037 festhalten

**Ticketbezug:** Out of Scope, Notes, TC-09.

**Beleg:** `tickets/AI6-037.md` (Task 8, AC-08, TC-08) plant `manual_review` als dritte statische Karte auf derselben Seite, geprüft „als berechtigter Benutzer“. Nach AI6-052 wäre diese Karte automatisch ohne Anmeldung sichtbar.

**Vorschlag:** Ein nicht normativer Hinweis in `## Notes`: „Weitere Karten späterer Tickets werden mit der Seite öffentlich; über deren Freigabe entscheidet das jeweilige Ticket, hier beim Rebase von AI6-037.“ README und TC-09 nicht auf „genau drei“ festlegen.

### V22 — P3: Abgrenzungen schärfen

**Ticketbezug:** Sensitive paths, Do Not Change, Out of Scope.

**Vorschlag:**

- **Do Not Change:** `app/AI6/Shared/Redaction/` (V04) und `docs/AI6-044_MG-01_ABNAHMEPROTOKOLL.md` ergänzen.
- **Out of Scope:** ausdrücklich nennen: Änderungen am Verhalten begonnener Anmeldungen (V02), eine Behebung der Redaction-Grenze (V04) und eine Missbrauchs- oder Ratenbegrenzung für die öffentliche Verarbeitung (Anhang B).
- **Sensitive paths:** den Satz zu `routes/web.php` vereinfachen: „Der Implementierungsauftrag für AI6-052 gibt genau diese eine Routenänderung frei; jede weitere Änderung an `routes/web.php` bleibt gesondert zu entscheiden.“

### V23 — P3: Überschneidung mit dem uncommitteten AI6-051-Stand

**Ticketbezug:** Context, Notes.

**Beleg:** `README.md` enthält uncommittete AI6-051-Änderungen (Objektformat-Abschnitte); AI6-052 ändert dieselbe Datei. Dasselbe gilt für den Plan und `tickets/README.md`, in denen V1.7.10- und V1.7.11-Änderungen gemeinsam uncommittet liegen.

**Vorschlag:** Ein nicht normativer Hinweis in `## Notes`: AI6-052 erst umsetzen, wenn der AI6-051-Stand committet ist, oder die README-Hunks strikt getrennt halten. Nur so bleiben Diff und Evidenz eindeutig zuordenbar.

### V24 — P3: Eine veraltete README-Angabe nicht still mitziehen

**Beleg:** `README.md:708` nennt „Katalogversion `1`“; `PromptCatalog::VERSION` ist seit AI6-044 `'2'` (`app/AI6/Prompts/PromptCatalog.php:9`).

**Vorschlag:** Nur auf ausdrückliche Entscheidung in AI6-052 mitkorrigieren; nach Plan §12.2 wird Dokumentation nur dort geändert, wo der Ticketvertrag es verlangt. Andernfalls als eigenen Befund melden.

---

## D. Offene menschliche Entscheidungen

1. **V04:** Redaction-Grenze vor der Freischaltung mit einem kleinen eigenen Auftrag beheben oder bewusst hinnehmen?
2. **V06:** `#[Locked]` für die Ausgabeproperties (empfohlen) oder nur präzisere AC-Formulierung?
3. **V24:** Die veraltete Katalogversion in der README in AI6-052 mitkorrigieren oder separat?
4. **V23:** Reihenfolge der Commits von AI6-051 und AI6-052.

---

## Anhang A — Beobachtungen der Wegwerf-Proben

Proben 1 bis 15 laufen als PHPUnit-Test auf `AuthFeatureTestCase` mit In-Memory-SQLite und Datenbanksessions. Die öffentliche Route ist simuliert: eine Zusatzroute für `PromptHelp` nur mit der Gruppe `web`. Probe 16 ist ein reines PHP-Skript gegen die Regeln aus `RedactionRuleSet`. Die Proben sind Hinweise, keine bestandene Evidenz.

| Nr. | Probe | Beobachtung |
|---|---|---|
| 1 | Gast-GET | 200; `data-csrf` im HTML; Datenbank nur `sessions` (zweimal `select`, einmal `insert`) |
| 2 | Gast-POST ohne Token, CSRF aktiv über `env=production` | 419 |
| 3 | Gast-POST mit fremdem Token | 419 |
| 4 | Gast-POST ohne Token mit `Sec-Fetch-Site: same-origin` | 200 |
| 5 | Gast-POST mit Token aus der Seite, Liste mit `password=hunter2` | 200; Marker enthalten; `hunter2` und Vorlauftext nicht enthalten; Datenbank nur `sessions` |
| 6 | geschützter `AttentionInboxPage`-Snapshot als Gast: allein, öffentlich zuerst, geschützt zuerst | jeweils 401, kein Inbox-HTML |
| 7 | geschützter Snapshot mit `memo.path` auf die öffentliche Route umgeschrieben | 419, leerer Body |
| 8 | veränderte Daten des öffentlichen Snapshots | 419 |
| 9 | `updates` auf `dynamicPreview` und `dynamicCopyEnabled` | 200, untergeschobener Text sichtbar |
| 10 | Probekomponente mit `#[Locked]`-Property, Update ohne Debug | 419, leerer Body, ein ERROR-Logeintrag nur mit Propertyname |
| 11 | Sitzung in `primary_pending`: GET beziehungsweise Livewire-POST | 302 auf `/auth/factor` beziehungsweise 403 mit JSON-Meldung |
| 12 | Gast-GET geschützter Routen mit nicht existierender ID; Gast-JSON-POST `/admin/users` | 302 auf `/login`; 401 |
| 13 | `actingAs`, dann `travel(session.lifetime + 5)->minutes()`, dann `/projects` | 200 (die Zeitreise lässt nichts ablaufen) |
| 14 | Gast-POST mit `- password="<8190 × a>"` | 500, genau ein ERROR-Logeintrag ohne Eingabebytes |
| 15 | Textarea-Ausbruch `</textarea><script>…` in der Fix-Liste | 200; roh nicht in `effects.html`, escaped enthalten |
| 16 | PHP-Regelprobe mit gequotetem Wert | `secret-assignment` scheitert ab 8190 Byte (JIT-Stack); possessive und „unrolled“ Variante ohne Fehler mit gleichem Treffer |

---

## Anhang B — Was ausdrücklich klein bleiben soll

- Kein datenbankfreier Pfad, kein neuer Session- oder Cachetreiber, kein eigener Update-Endpunkt und keine endpunktweite Livewire- oder CSRF-Ausnahme; Plan-Option 2 ist abgelehnt.
- Keine Ratenbegrenzung in AI6-052. Sie bräuchte persistente Middleware im `AI6ServiceProvider`, der auf Do Not Change steht, oder Cache-Schreibzugriffe je Gastanfrage, und der Netzzugang bleibt ohnehin beschränkt (V17). Nur bei einer geplanten Internetfreigabe wäre das eine eigene Entscheidung.
- Keine zweite Komponente, Route oder Layoutvariante für Gäste; kein zusätzlicher Anmelden-Link und keine `noindex`-Kopfzeile, weil beides nicht verlangt ist.
- Kein eigenes Beobachtungsframework; `DB::listen` und die Laravel-Fakes genügen (V08).
- Keine Fixtures je Schutzbereich (V09), keine zweite Grenzfallmatrix (V12), keine Änderung am Smoke-Harness (V14).
- Kein neues manuelles Gate: Der Kopiermechanismus bleibt unverändert, und `AI6-044/MG-01` bleibt ein eigenständiges offenes Gate.
- Keine Behebung der Redaction-Grenze in diesem Ticket (V04).

---

## Arbeitsauftrag für das kritisch prüfende LLM

> Prüfe jeden Punkt V01–V24 am aktuellen Ticket, am Plan V1.7.11 (Blueprint `AI6-052`, `UI-007`, `SEC-002`) und am realen Code; die Zeilenangaben beziehen sich auf HEAD `4ed8927` einschließlich des uncommitteten Arbeitsbaums. Entscheide je Punkt: übernehmen, präzisieren oder zusammenführen, oder verwerfen, jeweils mit kurzer Begründung und bei Abweichung mit Gegenbeleg. Vorrang haben V01–V05 und V08. Die Punkte in Abschnitt D sind menschliche Entscheidungen, die du vorbereitest, aber nicht selbst triffst. Ziehe Vereinfachungen einer zusätzlichen Regel vor; übernimm keine neue Klasse, Datei, Abstraktion, Testebene oder Gatestufe ohne konkret benannten Bedarf. Eine bereits enthaltene Anforderung wird durch einen Anker und einen gezielten Test präzisiert, nicht als weiteres AC dupliziert. Behalte die IDs AC-01–AC-07 und TC-01–TC-09, weil diese Liste sie zitiert; hänge neue Einträge nur an (etwa AC-08 aus V13). Status, Gates, Plan und `AGENTS.md` bleiben unverändert; V23 und V24 betreffen Dateien außerhalb des Tickets und brauchen eine eigene Freigabe. Die Proben dieser Liste sind Hinweise, keine bestandene Evidenz; prüfe nach der Überarbeitung das Ticket erneut mit dem realen `TicketV1Parser` und `Ai6DetailV1TicketValidator`.
