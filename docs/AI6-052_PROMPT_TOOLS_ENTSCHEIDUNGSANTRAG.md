# ANTRAG — AI6-052

**Art:** entscheidung
**Ausgelöst durch:** Menschlicher Auftrag vom 25. September 2026, ein neues Ticket für ohne Login erreichbare Prompt-Tools ohne Datenbank- oder LLM-Aufrufe zu erstellen.

**Entscheidung:** Am 25. September 2026 ausdrücklich menschlich freigegeben (»Freigabe hiermit erteilt.«). Angenommen ist die empfohlene Option 1 mit technischen Sessions. Die Entscheidung ist in Plan V1.7.11 und dem neuen Detailticket `tickets/AI6-052.md` umgesetzt; sie beauftragt keine Implementierung und bestätigt kein Testergebnis der späteren Funktion. Der folgende Antragstext dokumentiert den Stand vor dieser Entscheidung.

## Befund

Die Laravel-Anwendung bietet unter `/prompts/help` bereits drei manuelle Prompt-Funktionen an: einen statischen Prompt zur Behebung eigener Reviewbefunde, einen statischen Prompt zur Prüfung fremder Fixes und einen dynamischen Fixprompt aus einer eingefügten Reviewantwort. Die Route `prompts.help` steht in `routes/web.php` innerhalb der Gruppe mit `auth`. Die Navigation in `resources/views/layouts/app.blade.php` erscheint ausschließlich innerhalb von `@auth`.

`app/AI6/Prompts/Livewire/PromptHelp.php` verwendet den vorhandenen `PromptCatalog`, `PromptRenderer` und `ManualFindingListExtractor`. Diese Bedienung braucht keinen Projektbezug und startet keinen Providerturn. Die dynamische Verarbeitung erfolgt serverseitig über Livewire, nicht ausschließlich im Browser. `tests/Feature/Prompts/PromptHelpPageTest.php` verlangt derzeit sowohl für den Seitenaufruf als auch für die Livewire-Verarbeitung eine authentifizierte Sitzung. `tests/Feature/Shared/Http/PublicRouteInventoryTest.php` bindet die Route ausdrücklich an `[web, auth]`.

Die Unterscheidung zwischen fachlicher Verarbeitung und technischer Websitzung ist relevant: `config/session.php` verwendet standardmäßig den Datenbanktreiber. `bootstrap/app.php` ergänzt die Web-Middleware um `EnsureActiveUser` und `EnsureCompletedAuthentication`; diese lesen gegebenenfalls den angemeldeten Benutzer. Der bisherige Seiteneffekttest nimmt `sessions`, `cache` und `cache_locks` ausdrücklich von seinem Tabellenvergleich aus. Er beweist deshalb weder einen datenbankfreien HTTP-Request noch den Betrieb bei ausgefallener Datenbank.

Das ältere `ticket-prompt/api.php` liest dagegen reale Ticketdateien und besitzt zusätzlich eine statusändernde Aktion. Das bloße Fehlen eines LLM- oder SQL-Aufrufs genügt dort nicht zur Freigabe. Dieser Legacy-Pfad gehört nicht zur vorgeschlagenen öffentlichen Oberfläche.

Die Abhängigkeit `AI6-044` ist als Code vorhanden und trägt `status: done`; ihre Katalog-, Renderer-, UI- und Testnähte wurden im Arbeitsstand zu `4ed8927` geprüft. Der Plan V1.7.10 enthält noch keinen Blueprint für die neue Zugriffsfähigkeit. `AI6-051` ist die höchste dort vergebene ID; `AI6-052` ist daher die vorgeschlagene nächste ID, noch keine durch diesen Antrag veröffentlichte Blueprintvergabe. Andere vorhandene Arbeitsänderungen gehören nicht zu diesem Auftrag.

## Konflikt mit dem Blueprint

`UI-007` verlangt derzeit einen authentifizierten Promptarbeitsbereich. Der veröffentlichte Blueprint `AI6-044` verlangt ebenfalls vollständig authentifizierte Zugriffe und den Ausschluss von Gästen. Der neue Wunsch ist eine gezielte Produktänderung dieses Zugangsvertrags, kein Fehler gegenüber dem bisherigen Vertrag.

Plan §13.3 verlangt, dass ID, Ziel, Meilenstein und Abhängigkeiten eines neuen Detailtickets aus einem Blueprint in §15 stammen. Nach `AGENTS.md` §10 darf der Plan nur auf ausdrücklichen Auftrag geändert werden. Deshalb wird zunächst diese konkrete Planergänzung zur Entscheidung vorgelegt; das vorhandene Detailticket `AI6-044`, sein Status und seine veröffentlichten Evidenz-IDs werden nicht umgeschrieben.

## Vorschlag

**Entscheidungsfrage:** Soll Plan V1.7.11 den nachstehenden Blueprint `AI6-052` und die eng begrenzte öffentliche Nutzung der manuellen Prompt-Hilfe freigeben, damit daraus unmittelbar ein Detailticket mit `status: todo` erzeugt werden kann?

Die empfohlenen und alternativen Abgrenzungen sind:

1. **Empfehlung: Öffentliche manuelle Prompt-Hilfe mit bestehender technischer Websitzung.** Ohne Login sind die drei bestehenden Funktionen nutzbar. Sie lesen und schreiben keine fachlichen Datenbankdaten und rufen kein LLM auf. Die technischen Session- und CSRF-Mechanismen bleiben erhalten; der Tickettext verspricht keinen Betrieb ohne Datenbank. Das erfüllt den Zugangsauftrag mit einer begrenzten Änderung an der vorhandenen HTTP-/Livewire-Grenze.
2. **Streng datenbankfreier öffentlicher Requestpfad.** Zusätzlich muss die gesamte HTTP-Verarbeitung einschließlich Session-, Benutzer- und Cacheauflösung ohne Datenbank auskommen und bei nicht verfügbarer Datenbank funktionieren. Das verlangt einen gesondert festzulegenden Transport-/Sessionvertrag; die bestehende Webgruppe erfüllt diese Zusage nicht. Ein vollständiger Browserrenderer wäre ebenfalls keine beiläufige Lösung, weil der zentrale Renderer und die zentrale Redaction erhalten bleiben müssen. Diese Variante ist nicht in der empfohlenen Ticketabgrenzung enthalten.

Für Option 1 wird folgender neuer Blueprint vorgeschlagen:

| Merkmal | Vorgeschlagener Wert |
|---|---|
| ID | `AI6-052` |
| Titel | Öffentlicher Zugang zu manuellen Prompt-Tools |
| Initialstatus | `todo` |
| Meilenstein | `M2`, als Ergänzung der manuellen Prompt-Hilfe |
| Risiko | `medium` |
| Art | `feature` |
| Abhängigkeiten | `AI6-044` |
| Requirement-Refs | `AGT-008`, `AGT-011`, `UI-001`, `UI-007`, `SEC-002`, `SEC-004`, `SEC-007` |
| Fachlicher Umfang | `Prompts` und die begrenzte HTTP-Zugangsgrenze; keine neue Authentifizierungsarchitektur |

**Vorgeschlagenes Blueprint-Ziel:** Die manuellen Prompt-Tools ohne fachliche Datenbankzugriffe und ohne LLM-Aufrufe für Gäste und angemeldete Benutzer unter der bestehenden Prompt-Hilfe ohne Login nutzbar machen.

Der Lieferumfang und seine überprüfbaren Ergebnisse sind:

1. **Öffentlicher Einstieg:** Ein frischer Gast erreicht `/prompts/help` ohne Loginumleitung und sieht beide statischen Prompts sowie das Eingabefeld für die dynamische Verarbeitung. Der Link ist auch auf der Loginseite erreichbar; angemeldete Benutzer behalten den Zugriff. Die URL und der Routenname bleiben erhalten.
2. **Vollständige Bedienung:** Der Gast kann über den realen HTTP-/Livewire-Pfad eine gültige Reviewantwort verarbeiten und den vollständig gerenderten Prompt kopieren. Das gilt nicht nur für das initiale HTML oder einen isolierten Komponententest. Statische und dynamische Ausgabe bleiben an denselben Katalog und Renderer gebunden.
3. **Geschlossene Freigabegrenze:** Öffentlich sind genau diese manuellen Funktionen. Projekt-, Ticket-, Run-, Agentenprofil-, Administrations- und HumanLoop-Zugriffe behalten ihre Authentifizierungs- und Autorisierungsvoraussetzungen. Der gemeinsame Livewire-Endpunkt erhält keine pauschale Ausnahme; fremde Komponentensnapshots, manipulierte Snapshots und gemischte Anfragen öffnen keine geschützte Funktion. Eine begonnene oder abgelaufene Anmeldung erzeugt ebenfalls keine Berechtigung für diese Funktionen.
4. **Keine fachlichen Seiteneffekte:** Die öffentlichen Prompt-Funktionen lesen keine Benutzer-, Projekt-, Ticket-, Run- oder Providerdaten für ihre Ausgabe und verändern keine solchen Datensätze. Sie starten weder Queuejobs noch Git-, Prozess-, Mail- oder Provideraufrufe. Eingegebene Reviewantworten und dynamische Promptinhalte gelangen nicht in Datenbank, Session, Cache oder Logs. Technische Sitzungsverwaltung und gegebenenfalls Benutzerprüfung des vorhandenen Webstacks sind ausdrücklich von fachlicher Datenverarbeitung zu unterscheiden.
5. **Unveränderte Eingabe- und Ausgabesicherheit:** Zentrale UTF-8-Prüfung und Redaction, bestehendes Bytelimit, Markerprüfung, generische Fehler und der Endzustand `Nichts zu fixen.` gelten auch für Gäste. HTML-/Skripteingaben werden sicher dargestellt. CSRF, signierte Livewire-Snapshots, CSP, vertrauenswürdige Hosts und HTTPS-/Private-Access-Regeln bleiben wirksam.
6. **Nachvollziehbare Bedienung und Dokumentation:** Clipboard-Erfolg, vollständige Fallback-Auswahl und mobile Bedienbarkeit bleiben erhalten. Die Dokumentation nennt die öffentliche URL und die technische Sessionabhängigkeit. Browsernachweise laufen mit einer frischen Gastsitzung; ein übersprungener Smoke gilt nicht als bestanden.

Die Detailableitung soll diese Ergebnisse durch folgende Testnachweise abdecken:

- Featuretests über die registrierte Route für Gast und angemeldeten Benutzer sowie sichtbare Gastnavigation.
- Einen echten Gast-GET mit anschließendem gültigem Livewire-POST, einschließlich CSRF- und Snapshotbindung, bis zum dynamischen Vorschautext.
- Negativtests mit fehlendem/fremdem CSRF-Nachweis, manipuliertem Snapshot und geschützten fremden Komponenten, einschließlich einer gemischten Anfrage. Geschützte Routen bleiben ohne vollständige Anmeldung unerreichbar.
- Prüfung fachlicher Datenbankzugriffe und ausgehender Wirkungen während gültiger und ungültiger Gastverarbeitung; der bloße Vergleich von Zeilenzahlen genügt für die Abwesenheit fachlicher Lesezugriffe nicht. Ein technischer Sessionzugriff wird separat ausgewiesen.
- Bestehende Katalog-, Extraktions-, Grenz-, Redaction- und CSP-Regressionsprüfungen; insbesondere zulässige Höchstgröße und ein Byte darüber, fehlende/mehrfache Marker, ungültiges UTF-8 sowie `Nichts zu fixen.`.
- Gast-Browser-Smoke für statische und dynamische Ausgabe, erfolgreichen Copy-Vorgang, verweigerte Clipboard-Berechtigung, vollständige Fallback-Selektion und mobile Breite. Der vorhandene explizite Smoke-Schalter bleibt erhalten.
- Exakte Routeninventur sowie unveränderte HTTP-/CSRF-Architekturprüfungen; die neue öffentliche Route wird gezielt erwartet, weitere öffentliche Endpunkte bleiben ein Fehler.

Die verifizierten Ausgangspfade für die Detailableitung sind `routes/web.php`, `app/AI6/Prompts/Livewire/PromptHelp.php`, `resources/views/layouts/app.blade.php`, `resources/views/prompts/help.blade.php`, `tests/Feature/Prompts/PromptHelpPageTest.php`, `tests/Feature/Prompts/PromptHelpBrowserSmokeTest.php`, `tests/Feature/Shared/Http/PublicRouteInventoryTest.php`, `tests/Feature/Shared/Http/CsrfAndArchitectureTest.php` und `README.md`. Die vorhandenen Regressionen in `tests/Unit/Prompts/` und `tests/Feature/Shared/Http/HttpHardeningTest.php` sind mitzuberücksichtigen. Authentifizierungsrelevante Änderungen an der Route sind im späteren Ticket ausdrücklich als sensibler Scope auszuweisen. Weitere notwendige Änderungen an `bootstrap/app.php`, `app/AI6/Auth/Http/` oder `app/AI6/Shared/AI6ServiceProvider.php` dürfen nicht stillschweigend als bereits freigegeben gelten.

Nicht umfasst sind die Implementierung selbst, eine neue anonyme Ticket-/Projektansicht, eine Freigabe von `ticket-prompt/api.php`, ein zweiter Promptkatalog oder Renderer, LLM-Ausführung, Prompt-Historie, Betrieb ohne Datenbank sowie Änderungen an Statuswerten oder Gate-Ergebnissen bestehender Tickets.

## Auswirkung auf den Plan

Nach Freigabe wird `docs/AI6_IMPLEMENTATION_PLAN.md` gezielt auf V1.7.11 fortgeschrieben: `UI-007` benennt den öffentlichen manuellen Promptarbeitsbereich; `SEC-002` erhält die enge Ausnahme für explizit freigegebene projektunabhängige Prompt-Funktionen ohne fachliche Datenzugriffe und ohne Providerwirkung. CSRF und die übrigen nicht abschaltbaren Kontrollen werden nicht ausgenommen.

§15 erhält den Blueprint `AI6-052` unter M2. Ein Hinweis am historischen Blueprint `AI6-044` benennt ausschließlich die Ablösung seines Loginvertrags durch `AI6-052`; sein veröffentlichter Zieltext und seine übrigen Lieferzusagen bleiben erhalten. §14.1, §16 und §21 sowie die Blueprintanzahl werden auf 58 nachgeführt. Die neue ID und neue Evidenz-IDs werden nicht aus bestehenden Tickets übernommen oder umnummeriert.

Die feste Blueprintanzahl in `scripts/generate-ticket-manifest.php` wird von 57 auf 58 nachgeführt; seine exakte Inventurprüfung bleibt erhalten. Anschließend wird `docs/AI6_TICKET_MANIFEST.yaml` damit neu erzeugt und auf Drift geprüft. Die Determinismus- und Driftprüfungen in `tests/Unit/ManifestGeneratorTest.php` werden ausgeführt. Aus dem freigegebenen Blueprint entsteht genau `tickets/AI6-052.md` nach `docs/AI6_TICKET_TEMPLATE_V1.md` einschließlich C01–C17 und vollständiger AC-Abdeckung; `tickets/README.md` erhält den neuen Eintrag. Der Auftrag ändert keine bestehenden Ticketstatuswerte, schließt keine manuellen Gates und umfasst weder Commit noch Push.
