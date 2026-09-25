# AI6-052 — Kritische Bewertung des Ticketreviews

Stand: 25. September 2026. Geprüft wurden `docs/AI6-052_TICKET_REVIEW.md`, der bisherige Ticketentwurf, Plan V1.7.11 und die betroffenen Anwendungs- und Vendorquellen im Arbeitsstand zu `4ed8927`. Die Ausgangshashes von Ticket und Plan stimmen mit dem Review überein. Der Review ist eine Befundquelle; seine vorgeschlagenen Zusatzentscheidungen sind keine eigenständige Freigabe.

Das Review trifft die wesentlichen Schwächen. Die Umsetzung soll klein bleiben: eine bestehende Route verschieben, einen Gastlink ergänzen und die fünf serverseitigen Ausgabeproperties mit vorhandenen Livewire-Attributen sperren. Die Nachweise werden gezielter, ohne neues Beobachtungsframework, neue Middleware oder zweite Gastkomponente. Die Promptansicht entfällt aus dem Änderungsscope.

## Bewertung der einzelnen Vorschläge

| Punkt | Bewertung | Umsetzung und Begründung |
|---|---|---|
| V01 | Übernommen | Die Gastnavigation ist neu. Ein `@guest`-Block genügt; TC-01 prüft zusätzlich, dass geschützte Navigation fehlt. Kein CSS-Umbau. |
| V02 | Übernommen | Begonnene Anmeldungen behalten die vorhandene Umleitung beziehungsweise 403. Ein `primary_pending`-Fall genügt; keine Auth-Ausnahme. |
| V03 | Übernommen | Neue Gastsitzung als beobachtbarer Endzustand; kein wirkungsloser Ablaufnachweis mittels Zeitreise nach `actingAs`. |
| V04 | Bestätigt; Entscheidung offen | Der echte Extraktor wirft bei 8217 Bytes eine `RuntimeException`. Context, Task 6 und AC-05 halten die Lücke offen; keine Behebung, Ausnahme vom Blueprint oder Risikoakzeptanz in diesem Ticket. Ein kleiner eigener Fix vor Freischaltung ist empfohlen. |
| V05 | Übernommen und präzisiert | Den Tokenpfad mit tatsächlich aktivierter CSRF-Prüfung und ohne `Sec-Fetch-Site` prüfen; derselbe Request muss mit richtigem Token gelingen. Ein akzeptierter Origin-Nachweis ist eine vorhandene Frameworkfunktion, kein in AI6-052 zu schließender Bypass. |
| V06 | Übernommen | Fünf `#[Locked]`-Attribute setzen den bereits verlangten serverseitigen Ausgabevertrag mit einem vorhandenen Muster durch. Ein gezielter Negativfall prüft Clientupdates. Das ist eine konkrete technische Präzisierung im schon freigegebenen Komponentenscope, keine neue Produktentscheidung. |
| V07 | Übernommen, Aussage begrenzt | `PromptHelp` gehört in die vorhandene Prozessstart-Inventur. Diese statische Prüfung allein beweist keine beliebigen indirekten Aufrufe; zusammen mit Query-/Laravel-Beobachtungen und der eng begrenzten Codeänderung genügt sie hier. Keine Fakes je Git-/Providernaht. |
| V08 | Übernommen und eingegrenzt | `DB::listen`, Sessionpayload, Cache-Schreibereignisse, bestehende Logerfassung sowie Queue-/Mail-/HTTP-Bordmittel. Nur frische Gastverarbeitung wird auf `sessions` begrenzt; angemeldete Benutzerprüfung bleibt erlaubt. Fachlich ungültige Reviewtexte sind von manipulierten Frameworkrequests zu unterscheiden: Livewire kann Prüfsummenfehler selbst im Cache zählen. |
| V09 | Übernommen | Nicht existierende IDs reichen für die Auth-Barriere; exakte Redirect-/401-Erwartungen verhindern einen Scheinnachweis durch 404. Ein geschützter Inbox-Snapshot deckt Wiederverwendung, beide Mischreihenfolgen und die Manipulation von `memo.path` ab. |
| V10 | Übernommen | `app.debug=false`, deterministische 419-Antworten. Zugangsgrenze in TC-03, öffentliche Anfrageintegrität in TC-06; keine doppelte Manipulationsmatrix. |
| V11 | Übernommen und präzisiert | Ein Testinput verbindet Redaction und Textarea-Ausbruch. Klartext/Vorlauf fehlen im gesamten Antwortpayload; HTML-Escaping wird ausdrücklich nur in `effects.html` verlangt. Die Snapshotdaten dürfen den ungefährlich dargestellten Ausbruchstext als Daten enthalten. |
| V12 | Übernommen | Bestehende Extraktor- und Komponententests wiederverwenden, nur einen realen ungültigen Gastrequest ergänzen. Kein zusätzlicher optionaler HTTP-Maximalgrößentest und keine neue Matrix. Ein ASCII-Grenztest widerlegt V04 nicht. |
| V13 | Übernommen | AC-07 behält den Browsernachweis; README erhält AC-08. Die Auslagerung ist in Notes dokumentiert, alle bisherigen IDs bleiben erhalten. |
| V14 | Vereinfacht übernommen | Der vorhandene Prompt-Smoke läuft künftig als Gast und behält seine Browserassertionen; dynamische Kopie kommt hinzu. Ein zweiter vollständiger angemeldeter Browserdurchlauf mit neuer Hilfsmethode wäre für dieselbe Darstellung unnötig. Angemeldeter Zugriff bleibt im Featuretest belegt. |
| V15 | Übernommen | Bestehenden CSP-/Assettest in `PromptHelpPageTest` als Gast ausführen; `HttpHardeningTest` bleibt unveränderte Regression. |
| V16 | Übernommen | Exakt `GET /prompts/help [web]` festgelegt und die Inventur als sensibler Pfad ausgewiesen. |
| V17 | Übernommen | Ohne Anmeldung bedeutet keine neue Netzwerk- oder Internetfreigabe. Context und README-Vertrag erklären das. |
| V18 | Übernommen | Kleiner Dokumentationscheck im vorhandenen Seitentest, konkreter README-Abschnitt, kein Volltext-Snapshot und keine neue Testdatei. |
| V19 | Mit V06 zusammengeführt | Nur die fünf Ausgabeattribute ändern die Komponente; die unveränderte Ansicht wird aus `files` entfernt. Keine künstliche Verarbeitungserweiterung. |
| V20 | Übernommen | Die vorhandene persistente Middleware und die nicht sessiongebundene Prüfsumme erklären, warum eine einfache Routenverschiebung genügt. Kein neuer Mechanismus. |
| V21 | Übernommen | Nicht normativer Hinweis auf `AI6-037/manual_review`; keine pauschale Freigabe künftiger Karten und keine unnötig festgeschriebene Kartenanzahl in der README. |
| V22 | Größtenteils übernommen | Redaction, alte Gate-Evidenz, Auth-Verhalten und neue Ratenbegrenzung abgegrenzt. „Keine Ratenbegrenzung“ bedeutet keine neue Produktfunktion, nicht Abschalten der vorhandenen Livewire-Prüfsummenbegrenzung. Keine neue Gatestufe. |
| V23 | Eingeschränkt übernommen | Fremde README-Änderungen bleiben unberührt. Eine vorgeschriebene Commitreihenfolge oder neue Abhängigkeit zu AI6-051 ist für einen getrennten Absatz unnötig und wird nicht eingeführt. |
| V24 | Separat belassen | Die veraltete Katalogversion ist bestätigt, für die Zugangsänderung aber unerheblich. Keine beiläufige README-Bereinigung und keine zusätzliche Freigaberunde für dieses Ticket. |

## Nachgeprüfte Evidenz und Grenzen

- Der Quellcode bestätigt die fehlende Gastnavigation, die Behandlung begonnener Anmeldungen, die ungesperrten Ausgabeproperties, die fehlende Komponente in der Prozessstart-Inventur sowie die Funktionsweise von CSRF, persistenten Middlewareprüfungen und Snapshotintegrität.
- Eine eigene lokale PHP-Probe gegen `RedactionRuleSet` und anschließend den realen `ManualFindingListExtractor` bestätigt V04 mit synthetischen Werten: 91 Eingabebytes werden redigiert; 8217 und 262172 Bytes lösen `RuntimeException: Redaction rule secret-assignment could not be evaluated.` aus. Die Regelprobe meldet `JIT stack limit exhausted`. Kein Secret aus der Umgebung wurde verwendet oder ausgegeben. Windows/PHP 8.5.5, `pcre.jit=1`.
- `php artisan test --compact tests/Unit/Prompts/ManualFindingListExtractorTest.php`: 7 Tests, 34 Assertions bestanden. Diese bestehende Testsuite erfasst die bestätigte JIT-Fehlerfamilie nicht und schließt sie daher nicht.
- Der überarbeitete Entwurf besteht den realen `TicketV1Parser` und `Ai6DetailV1TicketValidator` sowie den Abgleich von Blueprintfeldern, Zieltext, acht ACs, neun TCs, vollständiger Coverage, acht bestehenden Scopepfaden, unveränderten Alt-IDs und UTF-8-/LF-Format. Manifest-Driftprüfung und `git diff --check` sind grün; der Planhash ist unverändert. Diese formalen Prüfungen ersetzen keine Entscheidung zu V04.
- Die HTTP-Proben aus dem Review wurden nicht als eigene ausgeführte Tests übernommen. Die Aussage zum HTTP-500-Pfad stützt sich auf dessen dokumentierte Probe und den nachvollzogenen ungefangenen Ausnahmeweg. Kein Linux-Nachweis und kein neuer Browser-Smoke wurden in dieser Ticketüberarbeitung ausgeführt.

Die Behandlung des zentralen Redactionfehlers bleibt die einzige offene Freischaltungsfrage aus dieser Bewertung. Sie ist im Ticket sichtbar, keine neue manuelle Gate-ID und keine stillschweigende Abschwächung des Planvertrags. Plan, Anwendungs- und Testcode, Ticketstatus und bestehende Gate-Ergebnisse wurden durch diese Überarbeitung nicht verändert.
