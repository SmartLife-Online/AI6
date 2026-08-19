# AI6 – Implementierungsplan V1.7.4 – Ticket-Ready, Lean & Secure

**Stand:** 19. August 2026

**Revision V1.7.4:** Der Backlog wächst von 50 auf 51 Blueprints. Die Umsetzung von `AI6-021` hat eine Vertragslücke zwischen der Checkdefinition und ihrem produktiven Vollzug sichtbar gemacht: Ein Checkprofil führt den Code des verwalteten Projekts aus, also untrusted Repositoryinhalt nach `SEC-007`, und die Zusagen aus `AGT-007`, `GIT-010` und `SEC-005` bestehen ausschließlich in der Checkerrolle — nur dort gelten fehlende Provider-, Git-, SMTP- und Datenbankcredentials, das Fehlen des Managed-Clone-Volumes, `network_mode: none` und die vollständige Isolationsprüfung. Der Worker dagegen trägt den Managed-Clone samt Deploy-Keys und normalen Netzzugriff; ein empirischer Nachweis am 19. August 2026 zeigte, dass ein dort ausgeführter Checkprozess eine beliebige absolute Datei außerhalb des exportierten Baums liest. Der Plan hielt bisher nicht fest, welche Rolle den Checkprozess tatsächlich startet. Diese Revision schließt das in zwei Schritten. Erstens wird der bestehende Blueprint `AI6-021` nach §13.7 gesplittet: Er behält unverändert seinen Vertrag — Profilregistry, Phasen, Runner, Ergebniszustände, Mutations- und Redactiongrenze — und schließt den rollenrichtigen Vollzug ausdrücklich aus; keine seiner Anforderungen, Ziele oder Abhängigkeiten wird umgewidmet. Zweitens erhält der neue Teil die nächste nie vergebene ID `AI6-045`: Der Worker staged den exportierten Baum in das Checker-Volume und schreibt genau einen Auftrag, der Checker konsumiert ihn in seiner eigenen Rolle unter der vollständigen Isolationsprüfung und publiziert das Ergebnis über die vorhandene Ergebnisnaht zurück, und der Checkschritt wird zum wiederaufnehmbaren, heartbeatgebundenen Warteschritt nach `RUN-003`. `AI6-045` entscheidet dabei ausdrücklich die bisher offene Frage, wie ein geprüfter Baum beschreibbar sein kann, obwohl das Eingangsvolumen der Checkerrolle read-only eingehängt ist; ohne diese Entscheidung ist weder ein realistischer Sprachtest noch eine falsifizierbare Mutationserkennung möglich. `AI6-022` hängt zusätzlich von `AI6-045` ab, weil eine Pre-Review-Verifikation ohne tatsächlich ausgeführten Check keine Aussage trägt. Requirement-Texte, veröffentlichte `AC-`/`TC-`/`MG-`/`EXT-`-IDs, Meilensteinzuschnitte und alle übrigen Blueprintverträge bleiben unverändert; §16 und §21 werden nachgezogen. Bis `AI6-045` integriert ist, bleibt die Ausführung außerhalb der Checkerrolle eine ausdrückliche, im Policyhash sichtbare Reduktion und im Profil `strict` unmöglich.

**Revision V1.7.3:** Auf ausdrückliche menschliche Entscheidung wird der Umgang mit dem Files-Scope eines Tickets neu gefasst: `files` ist die zum Freigabezeitpunkt beste Vermutung über den Ausgangsscope, keine abschließende Liste, und eine notwendige Erweiterung ist der Regelfall statt eines Fehlers. Erstens kehrt §8.2 die Vorgabe für nicht gelistete Pfade um — ein Pfad, der weder unter `scope.auto_allow` noch in einer sensiblen Kategorie liegt, wird unter dem unveränderten `max_added_scope_paths` automatisch in den `effective_scope` aufgenommen und dokumentiert, statt den Run zu blockieren; die neue vertrauenswürdige Projektvorgabe `scope.unlisted_paths` mit den Werten `auto_allow` und `require_approval` hält die strenge Variante verfügbar. Zweitens bleiben die sensiblen Kategorien — Instruktionsdateien, Ticketdateien, Migrationen, Abhängigkeitsdateien, CI-, Deploy- und Authpfade sowie jede Löschung — unverändert serverseitig entschieden und immer menschlich zu entscheiden; Ticketinhalt, Projektinhalt, Dateiname und Providertext ändern daran nichts. Drittens entsteht mit `TKT-012` die Rückschreibung des tatsächlich wirksamen Scopes in die Ticketdatei: AI6 schreibt sie ausschließlich im bereits vorhandenen Post-Push-Status-CAS aus `AI6-029` als AI6-eigenen Abschnitt `## Recorded Scope`, niemals ein Agent und niemals als Contract Amendment. Viertens nimmt der kanonische `ticket_contract_sha256` aus §5.2 diesen Abschnitt wie den Status aus, damit die Dokumentation keine Vertragsänderung vortäuscht und keine Reviewevidenz invalidiert. `TKT-007` wird entsprechend präzisiert; betroffen sind zusätzlich §6.2, §12.2, §12.4, §13.4, die Blueprints `AI6-010`, `AI6-020` und `AI6-029` sowie die Traceability aus §16. Requirement-Texte im Übrigen, AC-/TC-/MG-/EXT-IDs, Ziele, Abhängigkeiten, Meilensteine und die Blueprintanzahl bleiben unverändert. Das bereits erzeugte Detailticket `AI6-020` ist auf diese Revision zu rebasen.

**Revision V1.7.2:** Ein terminologischer Widerspruch zwischen dem normativen Plan und dem integrierten Read-Model-Vertrag wird auf ausdrückliche menschliche Entscheidung geschlossen: `redaction_state=clear` ist der kanonische Zustand einer unredigierten Ticketprojektion; `content_redacted` bleibt der Zustand einer inhaltlich redigierten Projektion. Die betroffenen Verträge in §4.1 sowie die Blueprints `AI6-008`, `AI6-009` und `AI6-012` werden vom bisherigen `redaction_state`-Wert `none` auf `clear` nachgezogen. Andere fachlich unabhängige `none`-Werte, insbesondere CSP-Direktiven, bleiben unverändert. Das ahead-derived Rebase-Gate von `AI6-012` wird damit gegen die bereits real verifizierten Nähte aus `AI6-011` und den nun konsistenten normativen Redaction-Vertrag bestätigt. Requirement- und Evidenz-IDs, Ziele, Abhängigkeiten, Meilensteine, Blueprintanzahl und Traceability bleiben unverändert; die fachliche Fail-closed-Semantik wird nur auf den bereits integrierten Enumwert vereinheitlicht.

**Revision V1.7.1:** Der Backlog wächst von 49 auf 50 Blueprints. Das neue `AGT-011` trennt eine ausdrücklich manuelle, projektunabhängige Prompt-Hilfe von der späteren Providerausführung, ohne einen zweiten Promptkatalog oder Renderer einzuführen: Statische Prompts werden als versionierte Einträge des zentralen Katalogs bytegenau kopiert; für den dynamischen Fixprompt wird eine vollständige Reviewantwort als untrusted Eingabe entgegengenommen, vor jeder Auswertung durch die zentrale UTF-8-/Redaction-Grenze geführt und ausschließlich der genau einmal vorhandene terminale Abschnitt `### Fix-Liste` in das katalogisierte Template eingesetzt. Die Eingabe und das Ergebnis werden weder persistiert noch geloggt oder an einen Provider übertragen; `Nichts zu fixen.` startet bewusst keinen weiteren Prompt. Das neue `UI-007` verlangt dafür einen globalen authentifizierten, responsiven Promptarbeitsbereich mit Vorschau, ehrlichem Clipboardstatus und auswählbarer Fallback-Ausgabe unter der unveränderten CSP. Der neue Blueprint `AI6-044` liefert diese Prompt-Hilfe nach `AI6-008` und `AI6-011`; seine Vorabableitung am 12. August 2026 wurde ausdrücklich menschlich angeordnet und bleibt bis zum Rebase gegen die reale `AI6-011`-Naht nicht freigabefähig. §17.2 präzisiert, dass Legacy-Browsertemplates nicht als zweite Quelle fortgeführt werden, ihre fachlich freigegebenen Inhalte aber in den zentralen Katalog überführt werden dürfen. Requirement-IDs, Ziele und Verträge der bisherigen 49 Blueprints bleiben unverändert.

**Revision V1.7.0:** Der Erweiterungsauftrag `docs/AI6_erweiterungsauftrag_modellrouting_multi_review_pipeline_v2.md` wird als zusammenhängende Vertragsrevision integriert; der Backlog wächst von 44 auf 49 Blueprints. Erstens entsteht der ticket- und approvalgebundene **Review-only-Modus**: Das neue `RUN-010` schließt die bisher offene Vertragslücke eines report-only Laufs ohne Push — Claim über denselben atomaren `ready → in_progress`-CAS, Abschluss über eine eigene absturzsichere Status-Saga mit der neuen, ausschließlich dieser Saga gehörenden Kante `in_progress → ready`, neuer Wartestatus `manual_report`, Lockfreigabe erst nach bestätigtem eigenem Status-CAS; weder `no_change_required` noch ein pushloser `in_progress → review`-Übergang werden dafür wiederverwendet. Das neue `GIT-011` erlaubt als Reviewgegenstand ausschließlich serverseitig gebundene Quellen (verwalteter Branch, Commit-Range beziehungsweise Einzelcommit, importierter validierter Patch, vorhandener Checkpoint), die der Worker in einen wegwerfbaren gitmetadatenfreien Review-Checkpoint normalisiert; freie Arbeitsverzeichnisse, freie Dateiauswahl und PR-URLs bleiben außerhalb. Die neuen Blueprints `AI6-039` (Runvertrag und Abschluss-Saga) und `AI6-040` (Quellbindung, Ausführung, gebundener Abschlussbericht und Bedienung) liefern das in M4, ohne den Modus in `AI6-023` zu verstecken. Zweitens wird die **erste reale Providerstufe** auf Codex-CLI, Grok-Build-CLI und GitHub-Copilot-CLI festgelegt: Das neue `AGT-010` verlangt je Adapter genau einen gepinnten, vom Capability-Doctor für die konkrete CLI-Version nachgewiesenen Headless-Transport ohne TUI-, ACP- oder SDK-/Servermodus, die Validierung jeder extrahierten Providerantwort gegen die zentralen `ai6.agent.v1`-/`ai6.quality-review.v1`-Verträge sowie Nutzungs-/Kostenwerte nur aus belastbarer Quelle mit explizitem `unknown`. Die neuen Blueprints `AI6-041` (Grok als Review-/Verifier-Adapter) und `AI6-042` (Copilot als unabhängiger Reviewer) ergänzen `AI6-033`; der Wortlaut von `AGT-001` nennt die neu freigegebenen Provider, ohne den Adaptervertrag zu ändern. `AI6-034` bleibt unverändert der Claude-Blueprint und wird nicht umgewidmet; `AI6-035` wird auf die drei V1-Profile erweitert, und seine bisherige Abhängigkeit von `AI6-034` wird durch `AI6-041`/`AI6-042` ersetzt, damit Claude die erste Providerstufe nicht blockiert. `AI6-038` pilotiert mit Codex-Implementierung und Grok-/Copilot-Reviews, beginnt mit einem Review-only-Lauf mit manuell bestätigtem report-only Abschluss und verwendet Claude nur optional. Drittens definiert das neue `REV-010` die **quellenabhängige advisory Finding-Verifikation** (`AI6-043`): Ein Verifierresultat ist ein weiteres unveränderliches, quell- und checkpointgebundenes Reviewresultat, hebt kein blockierendes Originalfinding auf, wird nie vom Quellprofil des Findings oder vom Implementierungsslot desselben Stands geliefert und eskaliert Widerspruch sichtbar an HumanLoop; `not_applicable` und `accepted_risk` bleiben nach `REV-006` menschlich autorisiert. Viertens verankert das neue `REV-011` **versionierte Review-Promptprofile und stufengerechte hashgebundene Kontextpakete** im bestehenden Promptkatalog: Approval bindet je Reviewer-Slot Promptprofil, Providerprofil und Auswahlgründe, Pfad-/Risikoregeln wählen nur serverseitig bekannte Profile, und jeder erforderliche Reviewlauf liefert unabhängig vom Profilfokus die vollständige `criterion_coverage`; `AI6-011` und `AI6-012` werden entsprechend ergänzt. `RUN-006` zählt zusätzlich Verifikationsrunden, `GIT-007` erlaubt serverseitigen Risikoregeln nur die Verengung von `automatic_after_gates` auf `manual`, und die Pushmodi bleiben exakt die zwei bestehenden Werte. Ein optionaler finaler Review ist als zusätzlicher regulärer Reviewer-Slot auf dem finalen Checkpoint beschrieben (§8.9) und bleibt bis nach dem Pilot deaktiviert; semantische LLM-Deduplizierung, PR-URL-Eingaben, parallele Providerprozesse, ein frei editierbarer Pipeline-DAG und automatische Routing-Selbstoptimierung sind ausdrücklich keine MVP-Ziele. §13.7 wird auf den realen Stand korrigiert: Plan, Template und die ersten zwölf Ticketdateien sind seit Commit `1aeb20e` veröffentlicht; alle Blueprint-IDs und veröffentlichten `AC-`/`TC-`/`MG-`/`EXT-`-IDs sind damit unveränderlich. Die dokumentierten Anpassungen der veröffentlichten Blueprints `AI6-011`, `AI6-012`, `AI6-033`, `AI6-035` und `AI6-038` sowie die reinen Provider-Namensklarstellungen in ADR-007 und den Ausschlusslisten von `AI6-011` und `AI6-015` sind ausdrücklich genehmigte Erweiterungen ohne Umwidmung; ihre IDs und bestehenden Verträge bleiben erhalten. ADR-016 bis ADR-018 halten die zugrunde liegenden Entscheidungen fest.

**Revision V1.6.21:** Die Laufzeitbaseline wird vor der Integration von `AI6-001` einmalig auf PHP 8.5, Laravel 13 und SQLite 3.53 korrigiert; die Blueprintanzahl bleibt bei 44. `OPS-007` und `AI6-001` verwenden nun `php: ^8.5`, Composer-`config.platform.php: 8.5.0` und den realen Locked-Install-Nachweis unter PHP 8.5. Laravel bleibt auf der Major-Linie 13; die konkrete Paketauflösung bleibt im committed `composer.lock` sichtbar. `AI6-002` baut auf PHP 8.5 und muss zur Laufzeit SQLite `3.53.x` nachweisen sowie die konkrete Image- und Paketauflösung reproduzierbar binden. Das neue `OPS-008` und §13.8 verbieten schwebende „latest“-Auflösungen und beiläufige Versionswechsel: Jede spätere Änderung der gebundenen PHP-, Laravel- oder SQLite-Version beziehungsweise ihrer konkreten Patchauflösung benötigt ein eigenes Upgrade-Ticket mit Kompatibilitäts-, Installations-, Migrations- und Rollbacknachweis. Die bereits veröffentlichten Detailtickets `AI6-001` und `AI6-002` werden als ausdrücklich genehmigte einmalige Baselinekorrektur auf diese Revision nachgezogen; ihre IDs sowie AC-, TC- und Gate-IDs bleiben unverändert. Das ahead-derived Rebase-Gate von `AI6-002` nach §13.6 bleibt davon unberührt und weiterhin offen.

**Revision V1.6.20:** Ein Befund wird geschlossen; die Blueprintanzahl bleibt bei 44. Die mit V1.6.19 eingeführte HTTPS-/Private-Access-Maßnahme nannte den zulässigen Klartextpfad nur als „Loopback-gebundenen Private-Access-Pfad", ohne prüfbares Prädikat: Weder erlaubte Quelladressen noch das Verhalten hinter einem vertrauenswürdigen Proxy waren festgelegt, sodass Implementierung und Test denselben Begriff verschieden hätten auslegen können — und eine großzügige Auslegung, etwa jede private Adresse, hätte die Maßnahme faktisch aufgehoben. §10.2 definiert Private Access jetzt abschließend über die unmittelbare Gegenstelle der Verbindung und die daraus abgeleitete Clientadresse, ausschließlich für `127.0.0.0/8` und `::1`, mit dem Trusted-Proxy-Fall als einziger Ableitung und einer ausdrücklichen Abgrenzung gegen sonstige private Adressbereiche. Requirement-Texte, Requirement-Refs, Meilensteine, Ziele, Abhängigkeiten, §14.1 und die Traceability aus §16 sind unverändert. Das Detailticket `AI6-005B` ist auf diese Revision rebased; sein ahead-derived Rebase-Gate nach §13.6 bleibt davon unberührt und weiterhin offen. Unabhängig von dieser Revision sind drei Detailtickets gegen den unveränderten Plan berichtigt worden: `AI6-005B` behandelte jedes `custom` mit irgendeiner Reduktion wie `development`, obwohl ausschließlich der Zustand der HTTPS-Maßnahme selbst zählt; `AI6-004` führte das Löschen von Benutzern in `AC-04` und in den Tests, aber nicht in Aufgabe 6 und `AC-06`, und fasste seine global gebundenen Aktionen zu fünf Gruppen statt sie atomar aufzuzählen; und `AI6-005A` ließ offen, ob die Registrierung von Recovery-Codes allein den Enrollment-Zustand beendet.

**Revision V1.6.19:** Zwei Befunde werden geschlossen; die Blueprintanzahl bleibt bei 44. Erstens fehlte der siebten abschaltbaren Maßnahme aus §10.2 — der HTTPS-/Private-Access-Durchsetzung — ein normativ entschiedener Schlüssel. Das Beispiel in §6.1 nannte sechs Maßnahmen-Schlüssel, sodass `AI6-003` den siebten allein aus Ticketprosa einführen musste; Schlüsselname, Default, Profilverhalten und Wirkung bei reinem HTTP gehören aber in den zentralen Securityvertrag und nicht in ein Detailticket. §6.1 nennt den Schlüssel `AI6_SECURITY_REQUIRE_HTTPS_OR_PRIVATE_ACCESS` jetzt ausdrücklich, und §10.2 legt seine Semantik fest: Bei aktiver Maßnahme wird eine Anfrage nur über HTTPS oder über einen Loopback-gebundenen Private-Access-Pfad bedient und jede andere unverschlüsselte Anfrage vor Anwendungslogik abgelehnt, und das Sessioncookie trägt `Secure`. Zugleich wird die bisher offene Reduktionsmenge des Profils `development` entschieden: Es deaktiviert genau diese eine Maßnahme und keine weitere, weil §10.2 ausschließlich sie auf „ausdrücklich erlaubte lokale Profile" bezieht; auch dann bleibt eine unverschlüsselte Anfrage außerhalb des Loopbackpfades abgelehnt, `HttpOnly` und `SameSite` bleiben unverändert, und die Reduktion verlangt weiterhin das Acknowledgement aus `SEC-001` und bleibt in Doctor und Banner sichtbar. Die Herkunft des Schemas ist davon unberührt und nicht reduzierbar: Sie wird nie aus einem Weiterleitungsheader untrusted Herkunft abgeleitet. Zweitens sprach §21 weiterhin von 43 Tickets, während Kopf und Backlog seit V1.6.10 bei 44 Blueprints stehen; die Zahl ist berichtigt, damit der Manifest-Driftcheck aus `AI6-001` gegen eine eindeutige normative Quelle läuft. Requirement-Texte, Requirement-Refs, Meilensteine, Ziele, Abhängigkeiten, §14.1 und die Traceability aus §16 sind unverändert. Die Detailtickets `AI6-003` und `AI6-005B` sind auf diese Revision rebased; ihr ahead-derived Rebase-Gate nach §13.6 bleibt davon unberührt und weiterhin offen. Unabhängig von dieser Revision sind drei Detailtickets gegen den unveränderten Plan berichtigt worden: `AI6-002` beschrieb den einmaligen `init`-Schritt und die dauerhaften Dienste in `AC-01` mit demselben Zustandsbegriff `healthy`, `AI6-004` verschob seine Rollenmatrix inhaltlich in die README statt sie im Akzeptanzvertrag festzulegen, und `AI6-005A` ließ Bibliotheksauswahl und Enrollment-Weg als zwei sicherheitsrelevante Entscheidungen offen.

**Revision V1.6.18:** Ein Befund wird geschlossen; die Blueprintanzahl bleibt bei 44. Die privilegierte Bereitstellung des Lockverzeichnisses war an zwei Stellen verschieden verortet: §4.1 wies sie seit V1.6.17 dem einmaligen Startschritt `init` zu, während §6.2 und der Blueprint `AI6-006C` weiterhin von einem „privilegierten, idempotenten Startschritt des Workercontainers" sprachen. Beide Lesarten sind nicht gleichzeitig umsetzbar: Ein privilegierter Startschritt des Workercontainers wäre eine zweite Stelle mit Sonderrechten neben `init`, widerspräche dem in §4.1 zugesagten „genau einen einmaligen Startschritt" und stünde gegen das unprivilegierte Rollenimage aus `AI6-002`. §6.2 und der Blueprint `AI6-006C` werden deshalb auf `init` nachgezogen. §4.1 hält zusätzlich fest, dass ausschließlich dieser Startschritt mit erhöhten Rechten laufen darf, dass das gemeinsame Image weiterhin einen unprivilegierten Benutzer setzt und diese Rechteübersteuerung ausschließlich für den `init`-Dienst in der Compose-Definition entsteht, und dass sein Ablauf eine feste, im Entrypoint hinterlegte Befehlsfolge ist. §6.2 schließt außerdem die dritte denkbare Mechanik ausdrücklich aus: Eine Erstbefüllung des Lockverzeichnisses aus dem Imagelayer beim ersten Mounten des Volumes wirkt nur auf einem leeren Volume, ist damit nicht idempotent und legt weder Eigentümer noch Modus des Verzeichnisses fest. Requirement-Texte, Requirement-Refs, Meilensteine, Ziele, Abhängigkeiten, §14.1 und die Traceability aus §16 sind unverändert. Das Detailticket `AI6-006C` ist auf diese Revision rebased und nimmt dafür `docker/` als bestehenden Pfad in seinen Ausgangsscope auf, weil es den `init`-Zweig des aus `AI6-002` stammenden Entrypoints erweitert; sein ahead-derived Rebase-Gate nach §13.6 bleibt davon unberührt und weiterhin offen. Unabhängig von dieser Revision ist `AI6-002` gegen den unveränderten Plan berichtigt worden: Sein `AC-10`/`TC-09` fixierte je Rolle genau eine Argumentliste und hätte damit jede spätere Erweiterung des `init`-Zweigs vertraglich ausgeschlossen, und die in Aufgabe 14 aufgenommene Dokumentation des Compose-Smoke-Test-Flags besaß in `AC-12`/`TC-11` keinen Nachweis.

**Revision V1.6.17:** Drei Befunde werden geschlossen; die Blueprintanzahl bleibt bei 44. Erstens verlangte der Blueprint `AI6-006A` einen instanzübergreifenden Locknachweis über das geteilte persistente Volume, obwohl Workervolume, Rollenbild und `docker-compose.yml` erst mit `AI6-006C` entstehen — in `AI6-006A` steht `docker-compose.yml` sogar unter „Do Not Change". Das Detailticket hatte die Verschiebung bereits vollzogen und nur in einer Note dokumentiert; eine Note autorisiert nach §13.3 aber keine Abweichung vom Blueprint. Das Acceptance-/Testpaar wandert deshalb ausdrücklich: `AI6-006A` trägt den prozesslokalen Nachweis samt separat gemountetem Dateisystem, `AI6-006C` den instanzübergreifenden Nachweis samt manuellem Gate. Zweitens war die Integrationsreihenfolge von `AI6-005A` und `AI6-005B` nicht durchsetzbar: Beide Blueprints trugen dieselben `depends_on`, berühren aber sechs Dateien gemeinsam — darunter `composer.lock` —, und nach `RUN-008` wird Eligibility ausschließlich gegen `depends_on` bewertet, während §14.1 nach V1.6.1 kein Startblocker ist. `AI6-005B` hängt deshalb zusätzlich von `AI6-005A` ab; damit ist die bisher nur in Ticketnotes behauptete Reihenfolge strukturell erzwungen und die `composer.lock`-Auflösung besitzt eine eindeutige Runbasis. Drittens ließ §4.1 offen, wo Datenbankmigrationen laufen, sodass `AI6-002` mit der Rolle `init` stillschweigend über das Fünf-Rollen-Bild hinausging. §4.1 führt den einmaligen Startschritt `init` jetzt ausdrücklich als Nicht-Prozessrolle mit genau einem Migrationsort, und der Blueprint `AI6-002` nennt ihn. Requirement-Texte, Requirement-Refs, Meilensteine, Ziele, §14.1 und die Traceability aus §16 sind unverändert; die Reihenfolge aus §14.1 bleibt gültig und wird für `AI6-005B` jetzt zusätzlich durch `depends_on` getragen. Die bereits erzeugten Detailtickets `AI6-002`, `AI6-005A`, `AI6-005B`, `AI6-006A` und `AI6-006C` sind auf diese Revision rebased; ihr ahead-derived Rebase-Gate nach §13.6 bleibt davon unberührt und weiterhin offen. Unabhängig von dieser Revision sind zwei Detailtickets gegen den unveränderten Plan berichtigt worden: `AI6-003` nannte die Schlüsselliste aus §6.1 falsch als sieben Variablen, wodurch sein `AC-05` unerfüllbar war, und `AI6-006B` hatte den Blueprintvertrag um eine Ununterscheidbarkeitsforderung erweitert, die kein Orakel schließt.

**Revision V1.6.16:** Vier Befunde werden geschlossen; die Blueprintanzahl bleibt bei 44. Erstens verhinderte der in V1.6.15 eingeführte Inode-Vergleich den Austausch der Lockdatei nicht dauerhaft: Er prüft unmittelbar nach dem Erwerb, während ein Unlink-und-Neuanlegen **danach** stattfinden kann — der zweite Halter fände dann bei seiner eigenen Prüfung eine gültige Inode vor, der erste prüft nicht erneut, und beide hielten einen scheinbar exklusiven Lock. Die Identität wird deshalb technisch unersetzbar gemacht statt nachträglich verglichen: Das Lockverzeichnis gehört einer privilegierten Identität und ist für den Workerbenutzer nicht beschreibbar, die Lockobjekte werden von einem privilegierten, idempotenten Startschritt vorab bereitgestellt, die Anwendung öffnet ausschließlich ein vorhandenes Lockobjekt ohne Schreibabsicht, und ein unbekannter Lockname ist ein Konfigurationsfehler. Auflösung, Containment-, Eigentümer- und Modusprüfung sowie der Inode-Vergleich bleiben als zweite Verteidigungslinie; nachzuweisen ist jetzt aber der **Austauschversuch selbst**, der an den Verzeichnisrechten scheitert. Weil die Lockobjekte damit nicht mehr projektindividuell erzeugt werden können, bestimmt der Aufrufer die deterministische Zuordnung seines Lockschlüssels zu einem Lockobjekt; sie muss nicht injektiv sein, und eine Kollision serialisiert zwei Projekte unnötig, verletzt aber keine Invariante. Zweitens war die Provisionierungssaga nach einem Lease-Takeover nicht wiederaufnehmbar: Der Schlüsselintent war an Operation-ID **und** Attempt-Token gebunden, sodass ein Absturz hinter `key_activated` einen aktiven Schlüssel hinterließ, den der neue Eigentümer nach der Fingerabdruckregel nicht übernehmen durfte, während der alte Versuch gefenced war — jeder Folgeversuch wäre im Recoveryzustand geendet und das Projekt dauerhaft gesperrt geblieben. Maßgeblich ist deshalb die Operation-ID: Ein aktiver Schlüssel, dessen Intent zu einem Vorgängerversuch derselben Operation gehört, wird unter dem Effekt-Lock und mit erneuter Eigentümerprüfung übernommen; nur eine andere Operation oder fehlende Zuordenbarkeit führt in den Recoveryzustand. Ein Takeover-Testfall über Absturz, Lease-Ablauf, höheren Attempt-Token und Wiedererwachen des alten Versuchs ist Pflicht. Drittens besaß die „ausdrückliche menschliche Entscheidung", die den nichtterminalen Recoveryzustand auflösen soll, keinen ausführbaren Vertrag; ein Projekt konnte damit dauerhaft blockiert bleiben. §6.2 definiert sie jetzt als typisierte, auditierte Recoveryentscheidung mit persistiertem Recoverybefund samt Hash, globalem Administrator mit frischem Step-up nach `SEC-002`, genau den drei Entscheidungen `retry_reconciliation`, `adopt_external_state` und `abandon_operation`, Compare-and-Swap gegen Operation, Attempt-Token, Versionszähler und Befundhash, einmaliger Konsumierbarkeit, Wirkung ausschließlich im Worker unter dem Effekt-Lock sowie redigiertem Audit; `abandon_operation` verlangt zusätzlich gebundene menschliche Evidenz nach `RUN-009`. `AI6-006C` erhält dafür `AI6-005A` als zusätzliche Abhängigkeit und `SEC-002` als zusätzlichen Requirement-Ref. Viertens war die in V1.6.15 begonnene Eingrenzung der Publish-CAS-Aussage nicht bis `AI6-006F` durchgezogen; Blueprint und Testliste nennen jetzt ebenfalls ausdrücklich den Publish **vor** dem Außeneffekt. Betroffen sind zusätzlich §6.2 sowie die Blueprints `AI6-006A`, `AI6-006C` und `AI6-006F` und die Traceabilityzeile von `SEC-002`. Requirement-Texte, Meilensteine, Ziele, §14.1 und die übrige Traceability sind unverändert. Die bereits erzeugten Detailtickets `AI6-006A`, `AI6-006C`, `AI6-006D` und `AI6-006F` sind auf diese Revision rebased; ihr ahead-derived Rebase-Gate nach §13.6 bleibt davon unberührt und weiterhin offen.

**Revision V1.6.15:** Fünf Befunde werden geschlossen; die Blueprintanzahl bleibt bei 44. Erstens besaß die Deploy-Key-Provisionierung als einziger Operationstyp keine eigene Saga, obwohl auch sie zwei Speicher überschreitet: Der private Schlüssel wandert im Dateisystem in den aktiven Bestand, während öffentlicher Schlüssel, Schlüsselreferenz und `provisioned` in SQLite geschrieben werden. Ein Absturz zwischen beiden Schritten hätte einen aktiven Schlüssel ohne Datenbankzustand oder einen `provisioning`-Zustand ohne aktiven Schlüssel hinterlassen, und die generische Crash-Injection kann eine Phasengrenze nicht abdecken, die gar nicht benannt ist. Der Operationstyp erhält deshalb die benannten Phasen `key_generated`, `key_activated`, `provisioning_finalized` und `attempt_completed`, eine Reconciliation, die anhand einer dauerhaften Bindung aus Operation-ID, Attempt-Token und Schlüsselfingerabdruck entscheidet, ob sie den vorgefundenen aktiven Schlüssel übernimmt oder als nachweislich eigenes verworfenes Artefakt entfernt, sowie Crash-Injection nach der atomaren Aktivierung vor dem Datenbank-Commit und nach dem Commit vor der Terminalisierung. Zweitens war die Identität der Lockdatei des Effekt-Locks nicht gehärtet: Der Vertrag verlangte nur eine Lockdatei „unterhalb" eines konfigurierten Verzeichnisses, ohne symlinksichere Auflösung, sichere Erzeugung, Eigentümer- und Modusprüfung und ohne Verbot des Löschens oder Ersetzens während ihrer Lebensdauer. Weil `flock` an der Inode und nicht am Pfad hängt, hätten nach einem Unlink-und-Neuanlegen zwei Prozesse gleichzeitig vermeintlich „dieselbe" Pfad-Lockdatei halten können — der Beendigungsnachweis wäre wertlos gewesen. Der Mechanismus prüft jetzt nach dem Erwerb die Inode-Identität des gehaltenen Deskriptors gegen eine frische Pfadauflösung und behandelt jede Abweichung als Lockkonflikt ohne Wirkung. Drittens war die zugesagte Freigabe bei Containerabbruch und die instanzübergreifende Wirkung des Locks über das geteilte Volume nirgends geprüft; beide erhalten einen Testfall und ein manuelles Gate in der realen Compose-Laufzeit. Viertens galt die Aussage, ein verlorener Publish-Compare-and-Swap habe keinen Bestand verändert, seit V1.6.14 nicht mehr uneingeschränkt: Ein nach `outcome_published` verlorener Bindungs-Compare-and-Swap findet einen bereits veränderten Außenzustand vor. Die Staleness-Zeile und die Aussage in §7.1 sind auf den Publish **vor** dem Außeneffekt eingegrenzt; für den Fall danach gelten die beiden benannten Konfliktpfade aus §6.2. Fünftens präzisiert §13.2 den Split-Trigger „unabhängig auslieferbar": Er zielt auf trennbare Outcomes über Modul- und Nahtgrenzen hinweg und greift nicht für eine Fähigkeit innerhalb genau einer technischen Naht, deren getrennte Lieferung ein zweites Ticket dieselbe Naht wesentlich verändern ließe — andernfalls hätte der Wrapper samt Effekt-Lock nach seinem Umzug nach `AI6-006A` dort erneut einen Split ausgelöst. Betroffen sind zusätzlich §6.2, §7.1 sowie die Blueprints `AI6-006A` und `AI6-006C`. Requirement-Texte, Requirement-Refs, Meilensteine, Ziele, Abhängigkeiten, §14.1 und die Traceability aus §16 sind unverändert. Die bereits erzeugten Detailtickets `AI6-006A`, `AI6-006C` und `AI6-006D` sind auf diese Revision rebased; ihr ahead-derived Rebase-Gate nach §13.6 bleibt davon unberührt und weiterhin offen.

**Revision V1.6.14:** Vier Befunde werden geschlossen; die Blueprintanzahl bleibt bei 44. Erstens war der Effekt-Lock nicht eindeutig an die Lebensdauer des wirkenden Prozesses gebunden: Der Wrapper hielt ihn „bis zum Prozessende", ohne festzulegen, ob er ein eigener Elternprozess bleibt. Ein `SIGKILL` allein auf diesen Wrapper hätte dessen Lock-Dateideskriptor geschlossen, während das Zielprogramm weiterläuft, sodass der nächste Versuch den Lock erwirbt und parallel wirkt — der Beendigungsnachweis wäre wertlos gewesen. Der Wrapper wird deshalb per `exec` selbst zum Zielprozess: Der Lock-Dateideskriptor bleibt über `exec` erhalten, zwischen Aufrufer und wirkendem Programm existiert kein zusätzlicher Supervisorprozess, und die dem Aufrufer gemeldete Prozesskennung ist die des wirkenden Programms. Ein Testdoppel, das das Zielprogramm stattdessen als Kindprozess des Wrappers startet, lässt den Testfall fehlschlagen. Zweitens deckte der Lock ausschließlich den Kindprozess ab, nicht den vom Worker selbst ausgeführten Publish, der Live-Refs beziehungsweise den verwalteten Pfad bewegt; die Publishschritte zweier Versuche hätten sich damit verschränken können. Der Lock ist deshalb der Serialisierer **jedes** Außeneffekts eines Versuchs: Der Worker erwirbt denselben projektgebundenen Lock direkt um seinen wirkenden Publishabschnitt und prüft unmittelbar nach dem Erwerb die Eigentümerschaft an Operation-ID und Attempt-Token erneut. Beide Verwendungsformen teilen den einen Mechanismus aus `AI6-006A`; eine zweite Lockimplementierung wäre ein Befund nach §4.3. Drittens war die terminale Phase `attempt_completed` widersprüchlich beschrieben: Sie sollte einen Effekt-Lock freigeben, den das Betriebssystem längst mit dem wirkenden Prozess freigegeben hat. Sie umfasst jetzt ausschließlich Bereinigung und Freigabe der Operationssperre. Viertens war der Konfliktpfad nach dem veröffentlichten Außenzustand widersprüchlich: Danach tragen Clone beziehungsweise Live-Refs bereits den Intent des Versuchs, sodass „durch ihn unverändert" nicht erfüllbar war. Ein überholter Versuch verändert ab der Konflikterkennung nichts mehr und setzt insbesondere nichts zurück; der neuere Versuch reconciled den Außenzustand gegen seinen eigenen, gegebenenfalls abweichenden Intent. Der Versionszählerkonflikt bei gehaltener Sperre wird nicht mehr terminal freigegeben, sondern endet in einem sichtbaren, nichtterminalen Recoveryzustand, der die Projektsperre hält, bis Außenstand und Bindung wieder konsistent sind; andernfalls wären neue Live-Refs bei alter Datenbankbindung terminal geworden. Betroffen sind zusätzlich §6.2 sowie die Blueprints `AI6-006A`, `AI6-006C` und `AI6-006D`; ergänzend wird die im Kopf stehengebliebene Backlogzahl 43 auf die seit V1.6.10 gültigen 44 Blueprints berichtigt. Requirement-Texte, Requirement-Refs, Meilensteine, Ziele, Abhängigkeiten, §14.1 und die Traceability aus §16 sind unverändert. Die bereits erzeugten Detailtickets `AI6-006A`, `AI6-006C` und `AI6-006D` sind auf diese Revision rebased, `AI6-006E` und `AI6-006F` in ihren Querverweisen nachgezogen; ihr ahead-derived Rebase-Gate nach §13.6 bleibt davon unberührt und weiterhin offen.

**Revision V1.6.13:** Acht Befunde werden geschlossen; die Blueprintanzahl bleibt bei 44. Erstens war die Versuchsisolation des Fetch unvollständig: Zugelassen waren versuchseigener Ref-Bereich und gemeinsamer Objektspeicher, doch `git fetch` schreibt zusätzlich `FETCH_HEAD` und kann automatische Maintenance auslösen, sodass ein überholter Versuch weiterhin gemeinsamen Zustand verändert. Der Vertrag verlangt jetzt ausdrücklich unterdrücktes `FETCH_HEAD`, abgeschaltete automatische Maintenance und einen Test, der den gesamten Git-Metadatenbaum vor und nach dem Lauf gegen eine explizite Allowlist zulässiger Änderungen vergleicht. Zweitens hätte die Bereinigung den erfolgreich provisionierten Deploy-Key gelöscht: Die Regel entfernte privates Schlüsselmaterial bei jedem verworfenen **oder terminalisierten** Versuch, und ein erfolgreicher Provisionierungsversuch ist terminal. Der Vertrag unterscheidet nun versuchseigenes temporäres Material, das immer entfernt wird, vom im Publish übernommenen aktiven Schlüssel, der erhalten bleibt und dessen Verwendbarkeit für Clone und Fetch nachgewiesen wird. Drittens behandelte die Publish-Saga einen verlorenen Bindungs-Compare-and-Swap nach `outcome_published` nicht; sie erhält einen benannten Konflikt- und Kompensationspfad, der ausschließlich nachweislich eigenen Außenzustand zurücksetzt, fremden Außenzustand einer neueren Übernahme überlässt und im Übrigen sichtbar blockiert, samt Fehlerinjektion genau an dieser Grenze. Viertens war `binding_finalized` gleichzeitig als terminal und als Vorstufe einer Terminalisierung beschrieben; die Saga erhält deshalb die vierte, eindeutig terminale Phase `attempt_completed`, die Bereinigung, Lockfreigabe und Sperrfreigabe umfasst. Fünftens verletzte `AI6-006C` erneut den Split-Trigger aus §13.2, weil es mit `Git`, `Projects` und `Shared/Process` drei fachliche Module wesentlich verändert und der blockierende Wrapper samt Effekt-Lock unabhängig lieferbar, zurückrollbar und prüfbar ist. Die Entscheidung fällt hier und nicht am Rebase-Gate: Der Wrapper und der Effekt-Lock sind generische Fähigkeiten der einen Prozessgrenze und wandern nach `AI6-006A`; `AI6-006C` bindet sie nur noch an Launch-Intent und Auftrag und berührt `Shared/Process` nicht mehr. Ein neuer Blueprint entsteht dadurch nicht, und §5 behält genau eine Prozessgrenze. Sechstens wird die in §7.1 stehengebliebene Aussage, Read Models würden „stale markiert", an die Lesezeit-Prädikate aus §6.2 angepasst. Siebtens beschrieb `AC-16` von `AI6-006C` noch den wartenden Reconciler aus V1.6.11; seit V1.6.12 stellt dieser sofort erneut zu, und ausschließlich der Wrapper serialisiert am Effekt-Lock. Achtens werden veraltete Quer- und Historienverweise berichtigt, einschließlich der Feststellung, dass zwar ein Initial-Commit mit `README.md` existiert, aber kein Plan-, Blueprint- oder Ticketstand committed ist. Betroffen sind zusätzlich §6.2, §7.1, §13.7 sowie die Blueprints `AI6-006A`, `AI6-006C` und `AI6-006D`. Requirement-Texte, Requirement-Refs, Meilensteine, Ziele, Abhängigkeiten, §14.1 und die Traceability aus §16 sind unverändert. Die bereits erzeugten Detailtickets `AI6-006A`, `AI6-006C` und `AI6-006D` sind auf diese Revision rebased; ihr ahead-derived Rebase-Gate nach §13.6 bleibt davon unberührt und weiterhin offen.

**Revision V1.6.12:** Fünf Befunde werden geschlossen; die Blueprintanzahl bleibt bei 44. Erstens war „der Prozess des vorherigen Versuchs ist nachweislich beendet“ technisch nicht nachweisbar: Die Rollenheartbeats nach `OPS-002` liegen auf containerlokalem `tmpfs`, und Prozesskennung sowie Startzeitpunkt sind PID-Namespace-gebunden, sodass eine andere Workerinstanz das Ende eines fremden Kindprozesses nicht belegen kann. An seine Stelle tritt ein **versuchsgebundener Effekt-Lock**: ein exklusiver Dateilock des Betriebssystems auf einer projektgebundenen Lockdatei im geteilten, persistenten Workervolume, den der blockierende Wrapper vor der Freigabe des Kindprozesses erwirbt und bis zu dessen Ende hält. Das Betriebssystem gibt ihn beim Prozessende frei, auch bei `SIGKILL` und bei Containerabbruch; der erfolgreiche Erwerb **ist** damit der Beendigungsnachweis. Prozesskennung und Startzeitpunkt bleiben Diagnosedaten und sind kein Livenessmechanismus, und ein Docker-Socket wird dafür nicht freigegeben. Zweitens löst derselbe Lock den Widerspruch zwischen wartendem Reconciler und Lease-Takeover-Test auf: Der Reconciler wartet nicht mehr, sondern stellt sofort erneut zu; der Wrapper des neuen Versuchs serialisiert am Lock, der überholte Versuch beendet ausschließlich seine versuchseigene Wirkung, und sein Publish scheitert am Attempt-Token. Ein nicht erreichbarer Lock endet als sichtbarer, wiederholbarer Lockkonflikt statt als Deadlock. Drittens lag der geforderte blockierende Wrapper außerhalb des erlaubten Scopes: `AI6-006C` erweitert die bestehende Prozessgrenze aus `AI6-006A` jetzt ausdrücklich und führt sie samt ihren Tests im Ausgangsscope, statt eine zweite Prozessnaht im Git-Modul zu erzeugen. Viertens war der Publish von `AI6-006D` über die Grenze zwischen Git beziehungsweise Dateisystem und SQLite nicht als Saga definiert; er erhält jetzt eigene benannte Publishphasen, eine Reconciliation, die den realen Git-Zustand gegen den persistierten Intent vergleicht und ausschließlich fehlende Schritte nachholt, sowie Crash-Injection an jeder dieser Grenzen. Fünftens fehlte die Bereinigung fehlgeschlagener Versuche: Der Übergang nach `provisioning_failed` entfernt das private Schlüsselmaterial des gescheiterten Versuchs, und versuchsgebundene Stagingbereiche samt Ref-Bereichen werden beim Verwerfen oder Terminalisieren symlinksicher entfernt; Retrytests belegen, dass kein verwaister Schlüssel und kein verwaistes Verzeichnis zurückbleibt. Betroffen sind zusätzlich §6.2 sowie die Blueprints `AI6-006C` und `AI6-006D`. Requirement-Texte, Requirement-Refs, Meilensteine, Ziele, Abhängigkeiten, §14.1 und die Traceability aus §16 sind unverändert. Die bereits erzeugten Detailtickets `AI6-006C`, `AI6-006D` und `AI6-006F` sind auf diese Revision rebased; ihr ahead-derived Rebase-Gate nach §13.6 bleibt davon unberührt und weiterhin offen.

**Revision V1.6.11:** Sieben Befunde werden geschlossen; die Blueprintanzahl bleibt bei 44. Erstens schrieb der Publish des Control-Branch-Wechsels den neuen Ref überhaupt nicht: §6.2 verlangt die Aktualisierung des Projektwerts, der Blueprint `AI6-006E` nannte jedoch nur ausstehende Bindung, geleerte aktive Bindung, `control_generation` und Auditeintrag. Nach dem konsumierenden Fetch hätte die aktive Control-OID damit vom neuen Branch gestammt, während `projects.control_branch` weiterhin den alten Ref nannte. Der Publish schreibt `control_branch` jetzt ausdrücklich in derselben Transaktion, und Erfolgs- wie Rollbacktest prüfen den Branchwert gemeinsam mit Bindung, Generation und Audit. Zweitens galt der versuchsgebundene Stagingbereich nur für den ersten Clone; ein gewöhnlicher Fetch veränderte Objekte und Refs des geteilten Managed-Clone bereits vor seinem Publish, sodass ein Versuch nach Lease-Verlust noch außen wirken konnte, obwohl sein Publish am Attempt-Token scheitert. Auch Fetch arbeitet jetzt versuchsgebunden: Er schreibt ausschließlich in einen versuchseigenen Ref-Bereich, die allowlisteten Live-Refs bewegt ausschließlich der eigentümergebundene Publish, und ein Lease-Takeover-Test belegt, dass der überholte Versuch den geteilten Bestand nicht verändert. Drittens war die Reihenfolge der Prozessidentität nicht implementierbar, weil Prozesskennung und tatsächlicher Startzeitpunkt erst nach dem Spawn existieren. §6.2 legt deshalb einen zweistufigen Launchvertrag fest: Launch-Intent aus Operation, Attempt-Token, Workerinstanz samt Boot-ID und Argumenthash persistieren, den Kindprozess über einen blockierenden Wrapper starten, Prozesskennung und tatsächlichen Startzeitpunkt persistieren und ihn erst danach freigeben. Viertens engt derselbe Vertrag das Fencing auf das tatsächlich Erreichbare ein: Ein überholter Versuch startet keinen Prozess, durchläuft keine Phase und veröffentlicht nichts; ein bereits laufender Kindprozess kann weiterlaufen, bleibt dabei aber auf seinen versuchsgebundenen Stagingbereich beschränkt. Fünftens trennt der Staleness-Vertrag seine Quellen, weil die `control_generation` ausschließlich beim Branchwechsel steigt: Branchwechsel über die abweichende Generation, Fetch über die Abweichung zwischen projiziertem Control-Commit und aktueller Control-OID, Profil- und Configwechsel über ihre jeweilige Snapshot- beziehungsweise Profilbindung, und ein verlorener Publish-Compare-and-Swap verändert keinen Bestand und macht deshalb auch keinen stale. „Kein zweiter Invalidierungsweg" bezieht sich ausschließlich auf die branchwechselbedingte Invalidierung und nicht auf jedes Freshness-Prädikat. Sechstens erhält `AI6-006E` den fehlenden Negativtest gegen Repositoryinhalt als Wechselautorität. Siebtens legt der neue §13.7 die ID-Stabilität zeitlich fest: Blueprint-, AC-, TC- und Gate-IDs sind ab dem ersten Commit stabil, alles davor ist ein unveröffentlichter Entwurf. Betroffen sind zusätzlich §6.2 und die noch veralteten Splitverweise in `AI6-013`; die bereits erzeugten Detailtickets `AI6-006C` bis `AI6-006F` sind auf diese Revision rebased, und ihr ahead-derived Rebase-Gate nach §13.6 bleibt davon unberührt und weiterhin offen.

**Revision V1.6.10:** Sechs Befunde werden geschlossen; der Backlog wächst von 43 auf 44 Blueprints. Erstens war der Control-Branch-Wechsel zeitlich nicht implementierbar beschrieben: Ein read-only Remote-Probe kann nicht innerhalb einer bedingten Aktualisierung stattfinden, und nach dem Anspruch ist die Operationssperre nicht mehr frei, sondern gehört der Operation. Eine mutierende Control Operation durchläuft deshalb genau zwei Compare-and-Swap-Phasen: der **Claim** prüft die freie Operationssperre und — sobald `AI6-013` sie erzeugt hat — die Abwesenheit eines aktiven Runs und legt Auftrag und Job an; der **Publish** nach dem externen Effekt prüft, dass die Sperre exakt dem Paar aus Operation-ID und Attempt-Token gehört und alle erwarteten Vorwerte unverändert sind. Zweitens erhält die Invalidierung einen konkreten Zustandsträger statt einer abstrakten Naht: eine monotone `control_generation` im Projektdatensatz, die der Publish des Branchwechsels erhöht und die Read Models, Config-Snapshots, Approvals und Queueeinträge bei ihrer Erzeugung mitschreiben; eine abweichende Generation macht sie sofort stale, und ein Callback-, Event- oder zweiter Invalidierungsweg wird dadurch entbehrlich. Drittens hält ein Datenbank-Fencing keinen bereits gestarteten Kindprozess an: Jeder Versuch persistiert vor seinem ersten externen Effekt Prozessidentität und Effektphase, arbeitet in einem versuchsgebundenen Stagingbereich und veröffentlicht ausschließlich im eigentümergebundenen Publish; der Reconciler startet keinen neuen externen Effekt, solange der Prozess des vorherigen Versuchs nicht nachweislich beendet ist — ein abgelaufener Lease allein ist kein Nachweis, das Verschwinden der Workerinstanz nach `OPS-002` dagegen schon. Viertens deckt `AI6-007` nicht nur den Backfill ab: Jeder danach abgeschlossene Refresh projiziert unmittelbar mit dem wirksamen Serverprofil, der Backfill schreibt per Compare-and-Swap gegen Projekt, Pfad, Control-Commit und Blob-SHA, und ein neueres Refreshergebnis oder ein Profilwechsel wird nicht von einem alten Backfill überschrieben. Fünftens erfüllte `AI6-006D` nach diesen Ergänzungen den Split-Trigger aus §13.2 — Managed-Clone samt Clone und Fetch einerseits und der administrative Control-Branch-Wechsel andererseits sind unabhängig lieferbar, testbar und zurückrollbar. Der Blueprint wird deshalb geteilt: `AI6-006D` behält Managed-Clone, Clone und Fetch, der neue `AI6-006E` erhält Control-Branch-Wechsel, ausstehende Bindung, Sperrphasen und Invalidierungsgeneration, und der bisherige `AI6-006E` mit Read Models und Einzelpfad-Refresh wird zu `AI6-006F`. Sechstens entstehen die Spalten für Attempt-Token und `control_generation` in `AI6-006B`, wo das Projektschema geführt wird; nachgezogen werden außerdem Scopebegründungen und Ticketverweise. Betroffen sind zusätzlich §6.2, §14.1, die `depends_on`-Listen von `AI6-007`, `AI6-009` und `AI6-010` sowie die Traceabilityzeilen von `GIT-001`, `GIT-009` und `SEC-007`. Die bereits erzeugten Detailtickets `AI6-006D` und `AI6-006E` sind auf diese Revision neu abzuleiten; ihr ahead-derived Rebase-Gate nach §13.6 bleibt davon unberührt und weiterhin offen.

**Revision V1.6.9:** Vier Befunde werden geschlossen; die Blueprintanzahl bleibt bei 43. Erstens wird ein Widerspruch im Invalidierungszeitpunkt aufgelöst: §6.2 beschrieb den Branch-Compare-and-Swap und die Invalidierung zunächst als aufeinanderfolgende Schritte („invalidiert anschließend"), legte sie zwei Absätze später aber als atomar mit demselben Compare-and-Swap fest. Die erste Formulierung ließ einen Absturzzustand mit bereits geändertem Branch, aber noch gültigen Approvals und Snapshots zu und wird deshalb auf „in derselben Transaktion atomar mit diesem Compare-and-Swap" gezogen. Zweitens wird die inverse Traceabilityabweichung von `GIT-001` beseitigt: Die Zeile in §16 nannte `AI6-010`, `AI6-012`, `AI6-013` und `AI6-030` als tragende Tickets, obwohl deren Blueprint-`Requirement-Refs` die ID nicht führten — weil das Ticket-Template `spec_refs` exakt aus diesen Refs erzeugt, hätten die späteren Detailtickets die Anforderung ausgelassen. `GIT-001` wird deshalb in diese vier Blueprints aufgenommen, ergänzend in `AI6-006E` als tragendes Ticket der Read-Model-Invalidierung, und die Zeile in §16 um `AI6-006E` erweitert. Drittens wird der zeitliche Vertrag der Sperren gestaffelt: Der Blueprint `AI6-006C` verlangte das gemeinsame Prüfen und Setzen von Operations- und Runsperre, obwohl die Runsperre erst mit `AI6-013` entsteht; er legt jetzt ausdrücklich genau eine Anspruchsnaht an, deren Bedingung `AI6-013` atomar um die Runsperre erweitert, statt einen zweiten Anspruchspfad einzuführen. Viertens erhält die Bootstrap-Projektion aus `AI6-007` eine verpflichtende Profilqualifikation: Gültigkeit wird ausschließlich relativ zum gebundenen Validierungsprofil ausgewiesen, und ein unter `generic_v1` gültiges Dokument eines Projekts, dessen erklärtes Profil `ai6_detail_v1` ist, gilt nicht als detailprofilgültig und ist nicht approvable. Requirement-Texte, Meilensteine, Ziele, Abhängigkeiten und §14.1 sind unverändert. Das bereits erzeugte Detailticket `AI6-006E` ist auf diese Revision rebased; sein ahead-derived Rebase-Gate nach §13.6 bleibt davon unberührt und weiterhin offen.

**Revision V1.6.8:** Acht Befunde werden geschlossen; die Blueprintanzahl bleibt bei 43. Erstens wird die von `GIT-001` verlangte Bedingung „kein aktiver Run“ verbindlich verankert: Operationssperre und Runsperre sind Felder desselben Projektdatensatzes und werden ausschließlich gemeinsam in einer Transaktion per Compare-and-Swap geprüft und gesetzt; `AI6-013` ist ausdrücklich verpflichtet, den Branchwechsel-Guard anzuschließen und den Wettlauf „Branchwechsel prüft frei, gleichzeitig startet ein Run“ nebenläufig zu testen. Zweitens erhält der Lease ein Fencing: Jeder Ausführungsversuch trägt neben der Operation-ID einen monotonen Attempt-Token, an den Heartbeat, Phasenfortschritt, Finalisierung und Sperrfreigabe per Compare-and-Swap gebunden sind, sodass ein nach einer Lease-Übernahme wieder erwachter älterer Versuch keine Wirkung mehr erzielt. Drittens wird die Invalidierungsnaht vertraglich vollständig: `AI6-010`, `AI6-012` und `AI6-030` schließen Config-Snapshots, Approvals und Queue-Freigaben ausdrücklich an genau diese Naht an, die Invalidierung wirkt atomar mit dem Compare-and-Swap zu Beginn des Branchwechsels, und jeder dieser Blueprints führt einen Branchwechseltest. Viertens bindet ein Fetch beim Anlegen seines Auftrags das Tripel aus Quelloperation, Version und OID der ausstehenden Bindung in seine operationstypspezifischen Parameter und damit in den Auftragsdatenhash und konsumiert per Compare-and-Swap exakt dieses Tripel; ein verzögerter Fetch einer bereits ersetzten Bindung bleibt damit wirkungslos. Fünftens erhält `AI6-007` die in `AI6-006E` zugesagte Reprojektion: Es findet bestehende `unparsed` Read Models, projiziert sie unter dem serverseitig konfigurierten Vorgabeprofil neu und bindet sie an dieses Profil, bis `AI6-010` den freigegebenen Profilwert liefert. Sechstens lehnt der Autorisierungssnapshot Normalisierungskollisionen ab: Werden zwei vorher verschiedene Objektschlüssel nach NFC identisch, ist das ein Fehler und kein stilles Überschreiben. Siebtens werden zwei Eingabeformate verengt — der Hostkey-Parser verlangt für `SHA256` genau 32 dekodierte Bytes und lehnt abweichende Länge sowie nichtkanonische Auffüllung ab, und die relative Projektkennung ist ein serverseitig erzeugter Bezeichner aus genau 32 Zeichen des Alphabets `[0-9a-f]`. Achtens werden die nach der Dreiteilung veralteten Ticketverweise berichtigt. Betroffen sind zusätzlich §6.2 sowie die Traceabilityzeile von `GIT-001`. Requirement-Texte, Meilensteine, Ziele, Abhängigkeiten und §14.1 sind unverändert.

**Revision V1.6.7:** Sieben Befunde werden geschlossen; der Backlog wächst von 42 auf 43 Blueprints. Erstens war die in V1.6.6 zugelassene Normalisierung des Hostkey-Fingerprints sicherheitskritisch falsch: Beim OpenSSH-Format `SHA256:<Base64>` ist der Nutzwert case-sensitive. Der Vergleich parst das Format nun streng, normalisiert ausschließlich das Präfix und optionale Auffüllung, dekodiert den Nutzwert case-sensitiv und vergleicht die dekodierten Digestbytes konstantzeitlich. Zweitens erhält die ausstehende Control-Bindung ein autoritatives Datenmodell: Projektfelder für ausstehenden Ref, ausstehenden OID und Quelloperation, fortgeschrieben per Compare-and-Swap gegen denselben Versionszähler wie die aktive Bindung und beim Fetch atomar konsumiert. Drittens wird die Split-Prüfung nicht mehr auf das Rebase-Gate verschoben — §13.6 verbietet das ausdrücklich —, sondern jetzt entschieden: `AI6-006C` wird dreigeteilt in `AI6-006C` (Control-Operation-Kern, Sperre und Deploy-Key-Provisionierung), `AI6-006D` (Managed-Clone und Control-Branch-Autorität) und `AI6-006E` (bisher `AI6-006D`, Read Models und Einzelpfad-Refresh); eine erneute Bewertung am Rebase-Gate bleibt zusätzlich zulässig, ersetzt diese Entscheidung aber nicht. Viertens erhält die Operationssperre einen zeitgebundenen Lease mit Heartbeat und einen periodischen Reconciler, der eine verwaiste nichtterminale Operation über ihre Operation-ID sicher requeued oder nach überprüfter Außenwirkung terminalisiert, statt die Sperre blind zu lösen. Fünftens ergänzt `AI6-010` Profil- und Snapshotbindung der Read Models samt Stale-Markierung, Reprojektion und negativem Freigabetest. Sechstens wird der Konflikt zwischen JCS und NFC aufgelöst: Stringwerte des Autorisierungssnapshots werden vor der RFC-8785-Serialisierung rekursiv nach NFC normalisiert, danach bleiben die JCS-Bytes unverändert. Siebtens werden die neuen Zustände und Queue-Invarianten vollständig getestet, einschließlich `provisioning_failed`, gemeinsamem Erfolgsfall von Auftrag und Job sowie Abwesenheit verwaister Jobs. Betroffen sind zusätzlich §14.1, die `depends_on`-Listen von `AI6-007`, `AI6-009`, `AI6-010` und `AI6-014` sowie die Traceabilityzeilen von `GIT-001`, `GIT-009`, `RUN-004`, `RUN-005` und `SEC-007`.

**Revision V1.6.6:** Sechs Lücken im Vertrag der M1-Kette werden geschlossen, alle innerhalb der bestehenden Blueprints `AI6-006B`, `AI6-006C` und `AI6-006D`. Erstens ist der Control-Branch-Wechsel ein eigener, phasenbasierter Operationstyp: Er bindet alten Branch, alten OID und dessen Version, den neuen Ref und den remotegeprüften Ziel-OID als **ausstehende Bindung**, und der anschließende Fetch läuft exakt gegen diesen ausstehenden OID, damit ein zwischenzeitlich verschobenes Remote nicht einen anderen als den menschlich geprüften OID binden kann. Zweitens schützt der Kern nicht nur Wiederholungen derselben Operation-ID: Je Projekt darf höchstens eine mutierende Control Operation aktiv sein, der Übergang `not_provisioned → provisioning` ist per Compare-and-Swap an genau eine Operation-ID gebunden, Schlüsselreferenz, öffentlicher Schlüssel und Zustand werden atomar finalisiert, und Auftragsdatensatz und Queue-Job entstehen in derselben Transaktion. Drittens speichert `AI6-006B` keinen verwalteten Pfad mehr, sondern ausschließlich eine serverseitig erzeugte, eindeutige relative Projektkennung; der Worker konstruiert und validiert den absoluten Pfad unter dem konfigurierten Root, und der gespeicherte Hostkey-Fingerprint wird bytegenau gegen den serverseitig gepinnten Wert geprüft. Viertens ist der Auftragsdatenhash bytegenau festgelegt: wörtlicher Domain-Separator, Präsenzmarker zur Unterscheidung von `null` und leerem Wert, RFC-8785-Serialisierung des strukturierten Autorisierungssnapshots sowie eine feste Feldliste und Reihenfolge je Operationstyp. Fünftens gilt die Crash-Injection-Pflicht für jede definierte Phase jedes Operationstyps und prüft zusätzlich genau einen Ergebnisdatensatz. Sechstens trägt ein `unparsed`-Envelope vor `AI6-007` ausdrücklich `validation_profile = null`. Ergänzend wird festgehalten, dass die Größe von `AI6-006C` am ahead-derived Rebase-Gate nach §13.6 anhand des dann erwarteten Diffs erneut gegen §13.2 zu bewerten ist. Requirement-IDs, Meilensteine, Ziele, Abhängigkeiten, die Blueprintanzahl und die Traceability aus §16 sind unverändert.

**Revision V1.6.5:** `AI6-006B` war nach V1.6.4 über die Split-Leitplanken aus §13.2 hinausgewachsen — zwei unabhängige Schemaänderungen sowie mehrere getrennt lieferbare Outcomes in einem Blueprint. Der Blueprint wird deshalb dreigeteilt; der Backlog wächst von 41 auf 42 Blueprints. `AI6-006B` behält Projektregistrierung und vertrauenswürdige Projektmetadaten ohne jeden Prozessstart. `AI6-006C` erhält den typisierten Control-Operation-Kern samt absturzsicherem Phasenvertrag, die Operationstypen Clone, Fetch und Deploy-Key-Provisionierung, die Bootstrapbindung sowie den Control-Branch-Änderungsflow. Der bisherige `AI6-006C` wird zu `AI6-006D` mit Read Models und Einzelpfad-Refresh. Zusätzlich normativ ergänzt: `RUN-005` als Requirement-Ref des Operationskerns — die Aussage in V1.6.4, die Traceability bleibe unverändert, war insoweit unzutreffend und wird hier korrigiert; ein versioniertes kanonisches Hashschema, das auch Actor, Autorisierungssnapshot und operationstypspezifische Parameter bindet; das atomare Leeren der Control-OID-Bindung beim Control-Branch-Wechsel; die normativ festgelegte Projektrolle `admin` der automatisch erzeugten Mitgliedschaft; und die Sichtbarkeit des öffentlichen Deploy-Keys erst nach terminal erfolgreicher Provisionierung. Betroffen sind zusätzlich §14.1, die `depends_on`-Listen von `AI6-007`, `AI6-009`, `AI6-010` und `AI6-014` sowie die Traceabilityzeilen von `GIT-001`, `GIT-009`, `RUN-004`, `RUN-005` und `SEC-007`.

**Revision V1.6.4:** Der in V1.6.3 nach `AI6-006B` verschobene Control-Operation-Kern erhält seinen vollständigen normativen Vertrag; die Ergänzungen aus der Reviewrunde nach V1.6.3 werden hier nachgeführt, statt unrevidiert im Blueprint zu stehen. Neu beziehungsweise erstmals normativ festgehalten sind: die erneute Prüfung von Autorisierungssnapshot und erwartetem Control-Commit im Worker unmittelbar vor dem Prozessstart; die zweistufige Bootstrapbindung des ersten Clone über einen read-only Remote-Probe; die Deploy-Key-Provisionierung als eigener Operationstyp mit privatem Material ausschließlich im Worker; die zeitliche Trennung der Bindungsprüfungen — Auftrag, Autorisierung und erwartete OID vor Prozessstart, Ergebnisherkunft nach Prozessende und vor jeder Persistenz; ein phasenbasierter, absturzsicherer Operationsvertrag nach dem Vorbild der Status-Saga aus §5.2 einschließlich unveränderlichem Auftragsdatenhash, operationstypspezifischer Reconciliation und Crash-Injection-Pflicht (`RUN-005`); die autoritative, per Compare-and-Swap fortgeschriebene aktuelle Control-OID-Bindung des Projekts; die atomare Mitgliedschaft des registrierenden Administrators; sowie die Ergebnisbindung an Projekt, Pfad und Blob-SHA zusätzlich zu Operation-ID und Control-Commit. Für `AI6-006C` wird außerdem klargestellt, dass die Oberfläche einen untrusted Kandidatenpfad vorschlagen darf, der weder Basispfad noch Autorität bestimmt und von Server und Worker unabhängig validiert wird. Requirement-IDs, Meilensteine, Ziele, Abhängigkeiten und die Traceability aus §16 sind unverändert. Die Detailtickets `AI6-006B` und `AI6-006C` sind an diese Revision angepasst; ihr ahead-derived Rebase-Gate nach §13.6 bleibt davon unberührt und weiterhin offen.

**Revision V1.6.3:** Zwei Vertragslücken werden geschlossen. Erstens definiert der neue §13.6 den Zustand **ahead-derived**: Ein vor der Umsetzung seiner `depends_on`-Tickets erzeugtes Detailticket ist zulässig, wenn ein Mensch das ausdrücklich anordnet, es den Zustand kennzeichnet und die aufgeschobenen Prüfungen vor `ready` in einem verpflichtenden Rebase nachholt. §13.2 wird entsprechend präzisiert: Eine noch nicht implementierte Voraussetzung erzwingt den Antragszweig weiterhin, sofern sie nicht ausschließlich aus bereits definierten `depends_on`-Blueprints besteht und die Vorabableitung angeordnet wurde. Zweitens erhält `AI6-006B` den minimalen Kern der typisierten Control Operations für Clone und Fetch; ohne ihn müsste dieses Ticket einen zweiten, später ersetzten Ausführungspfad einführen. `AI6-006C` erweitert diesen Kern anschließend um Refresh, Read Models und deren Redaction. Betroffen sind §13.2, §13.6, die Blueprints `AI6-006B` und `AI6-006C` sowie die Traceabilityzeilen von `GIT-009` und `RUN-004`. Requirement-Texte, Meilensteine und die übrigen Blueprints sind unverändert.

**Revision V1.6.2:** Zwei Blueprints verletzten die Split-Regeln aus §13.2 und werden aufgeteilt; der Backlog wächst dadurch von 38 auf 41 Blueprints. `AI6-005` wird zu `AI6-005A` (Passkey, TOTP, Recovery, LoginCompletionGate, E-Mail-Barriere, Step-up-Primitive) und `AI6-005B` (Cookie-, Host-, Proxy-, CSP- und Markdown-Härtung); `AI6-006` wird zu `AI6-006A` (ControlProcessRunner und gehärtete Git-Ausführung), `AI6-006B` (Projektregistrierung, Deploy-Key, Control-Branch-Autorität) und `AI6-006C` (typisierte Control Operations und blobgebundene Read Models). Die Requirement-Refs werden verteilt, nicht dupliziert: `SEC-002`/`SEC-003`/`HUM-003` an `AI6-005A`, `SEC-004`/`SEC-007` an `AI6-005B`, `GIT-001`/`AGT-006`/`SEC-006` an `AI6-006A`, `PROD-001`/`GIT-001` an `AI6-006B`, `GIT-009`/`RUN-004`/`SEC-007` an `AI6-006C`. Betroffen sind zusätzlich §14.1 sowie die `depends_on`-Listen von `AI6-007`, `AI6-009`, `AI6-010`, `AI6-014`, `AI6-015`, `AI6-018`, `AI6-035` und `AI6-036` und die Traceability in §16. Requirement-IDs, Meilensteine und Ziele der übrigen Blueprints sind unverändert. Bereits erzeugte Detailtickets `AI6-005` und `AI6-006` sind ungültig und werden durch die fünf neuen Tickets ersetzt.

**Revision V1.6.1:** Korrektur einer fehlenden Abhängigkeit im Blueprint `AI6-006`. `GIT-001` verlangt für die autorisierte Änderung des Control-Branch ein Step-up; die Step-up-Primitive entsteht in `AI6-005`, war dort aber nicht als `depends_on` geführt. Die topologische Reihenfolge aus §14.1 ist kein technischer Startblocker und ersetzt `depends_on` nicht, weil Eligibility und Claim ausschließlich gegen `depends_on` geprüft werden. `AI6-006` hängt deshalb zusätzlich von `AI6-005` ab. Requirement-IDs, Meilensteine, Risiko, Kind, Ziele und die Traceability aus §16 sind unverändert; §14.1 bleibt eine gültige Topologie. Ein bereits erzeugtes `AI6-006` muss auf diese Revision rebased und erneut freigegeben werden.

**Revision V1.6.0:** Die Reviewbefunde aus V1.5.2 sind als zusammenhängende Vertragsrevision eingearbeitet. Neu sind der blobgebundene asynchrone App↔Git-Worker-Datenpfad (`GIT-009`), die gitmetadatenfreie exportierte Provider-Tree-Sicht (`GIT-010`), die Trennung von generischem `ai6.ticket.v1` und dem AI6-Detailprofil (`TKT-011`), der vor Approval verfügbare Prompt-Snapshot (`AGT-008`), die gebundene native Provider-Instruktionsauflösung (`AGT-009`), das verbindliche manuelle/externe Gate-Prädikat (`RUN-009`), der deterministische Manifestexport (`OPS-006`) und der immutable Laravel-Scaffold-/Supply-Chain-Vertrag (`OPS-007`). Präzisiert wurden kanonischer Ticket-Contract-Hash, zentrale Redaction, Ticketerkennung, Control-Branch-Autorität, Approval-/Ready-/Queue-Semantik, initiale und aktuelle Runbasis, Contract Amendments, effektive Finding-Dispositionen, Ressourcenlimits, Wartestatusauflösung, Cancel-/No-change-Sagas, Retention und Legacy-Abschaltung. Die Blueprint-Topologie führt den zentralen Control-ProcessRunner und den minimalen RunOrchestrator nun vor ihren ersten Verbrauchern ein. AI6-001 ist reproduzierbar auf das verifizierte Laravel-Scaffold, PHP `^8.3`, Laravel Framework `^13.8` und einen committed `composer.lock` gebunden. Ein bereits aus einer älteren Planrevision erzeugtes `AI6-001` muss auf V1.6.0 neu erzeugt beziehungsweise rebased und erneut freigegeben werden.

**Revision V1.5.2:** Neues `TKT-010`: Als Ticket gilt ausschließlich eine Datei, deren Name ohne Endung der Standard-ID-Regel entspricht; alle übrigen Dateien im `tickets_path` werden ignoriert. Damit ist eine gepflegte Bestandsübersicht `tickets/README.md` ohne Validierungsfehler möglich. Betroffen: §0.1, §3.2, §5.1, §15.2 (`AI6-007`) und §16. Die damalige Aussage zur fehlenden Rebase-Notwendigkeit ist durch V1.6.0 überholt; `AI6-001` muss auf den neuen Scaffold-/Supply-Chain-Vertrag rebased werden.

**Revision V1.5.1:** Strukturelle Ticketbezeichner sind englisch. Frontmatter-Key `titel` → `title`; die Abschnittsüberschriften und wörtlich geprüften Marker des Ticketformats sind englisch. Betroffen: `TKT-002`, `UI-002`, §5.1, §12.3, §13.4, §17.1 sowie die Akzeptanzverträge von `AI6-007` und `AI6-008`. Ticketfließtext bleibt deutsch. Zusätzlich: Plandatei auf den kanonischen Namen umbenannt, Manifestpfad in §0.1 vereinheitlicht, Generatorprompt in §17.1 entdoppelt, Ticket-Template und `AGENTS.md` als kanonische Dateien ergänzt. Requirement-IDs und Traceability sind unverändert.

**Zweck:** Kanonische Architektur- und Anforderungsquelle zur Erzeugung einzelner, unabhängig entwickelbarer und reviewbarer Tickets

**Primärbetrieb:** eine zentrale AI6-Instanz auf einem Linux-Server

**Weitere Betriebsarten:** lokal unter Linux sowie über Docker Desktop/WSL2 unter Windows und macOS

**Produktform:** modularer Laravel-Monolith, eine Codebasis, ein Dockerimage, klar getrennte Prozessrollen

**Ticketquelle:** Markdown-Dateien im Git-Repository des jeweils verwalteten Projekts

**Backlog:** 50 Ticket-Blueprints in acht Meilensteinen; der ab AI6-001 deterministisch erzeugte maschinenlesbare Export liegt in `docs/AI6_TICKET_MANIFEST.yaml`

---

## 0. Wie dieser Plan verwendet wird

Dieser Plan ist **keine einzelne Umsetzungsanweisung**. Er besitzt vier getrennte Ebenen:

1. **Normative Produkt- und Architekturverträge** mit stabilen IDs wie `TKT-002`, `RUN-003` oder `SEC-005`.
2. **Meilensteine** als fachliche Lieferabschnitte und Integrations-Gates.
3. **Ticket-Blueprints** mit stabiler ID, Ziel, Abhängigkeiten, Deliverables und Prüfvertrag.
4. **Detaillierte Ticketdateien**, die aus jeweils einem Blueprint und dem dann tatsächlich vorhandenen Repositoryzustand erzeugt werden.

Die Blueprint-Liste ist damit detailliert genug für Planung und Abhängigkeiten, vermeidet aber bewusst fiktive Dateipfade für Code, der noch nicht existiert. Detaillierte Tickets werden **progressiv elaboriert**:

```text
Plan + Blueprint + aktueller Repositorystand
    → detaillierte Ticketdatei
    → menschliche Prüfung
    → status=ready
    → einzelne Umsetzung
    → einzelner Review
```

### 0.1 Kanonische Dateien

Im AI6-Repository sollen später liegen:

```text
AGENTS.md                             # Instruktionen für agentische LLMs
docs/AI6_IMPLEMENTATION_PLAN.md       # dieser Plan; normative Quelle
docs/AI6_TICKET_TEMPLATE_V1.md        # Erzeugungs- und Umsetzungsvertrag für Tickets
docs/AI6_TICKET_MANIFEST.yaml         # ab AI6-001 deterministisch generierter Export
tickets/README.md                     # Bestandsuebersicht; reine Ansicht, kein Ticket
tickets/AI6-001.md ...                # einzeln erzeugte Detailtickets
```

Der Markdown-Plan bleibt autoritativ. Das YAML-Manifest ist ein deterministisch daraus erzeugter Export und darf nicht unabhängig gepflegt werden. AI6-001 liefert Generator und Driftcheck; bis zu dessen Abschluss ist das fehlende Manifest erwarteter Bootstrapzustand. Das Ticket-Template konkretisiert das Format aus Abschnitt 5.1 für die automatisierte Erzeugung und besitzt den ausführbaren Generatorprompt; bei Widerspruch gilt dieser Plan.

### 0.2 Wesentliche Verbesserung gegenüber V1.4

V1.4 beschrieb die richtige Zielrichtung, bündelte die Umsetzung jedoch in zehn sehr großen Paketen. Diese Pakete vermischten häufig Infrastruktur, Fachlogik, UI, Security und E2E-Abnahme. Dadurch wären Diffs groß, Reviews unscharf und Abhängigkeiten teilweise erst während der Umsetzung sichtbar geworden.

V1.5 ändert deshalb:

- zehn Epics werden in **43 kleinere vertikale Tickets** zerlegt;
- Anforderungen erhalten stabile IDs und werden nicht in jedem Ticket dupliziert;
- jedes Ticket besitzt genau ein primäres Outcome und seinen eigenen Testvertrag;
- UI wird dort mitgeliefert, wo die jeweilige Funktion erstmals benutzbar wird, statt am Ende als Großpaket;
- FakeAgent und strukturierte Verträge kommen vor echten Provideradaptern;
- Human-in-the-loop wird vor dem ersten echten Implementierungsturn fertiggestellt;
- Finalisierung und optionales Security-LLM sind von normalen Qualitätsreviews getrennt;
- spätere Tickets werden erst detailliert, wenn ihre Abhängigkeiten den vorgesehenen Integrations-Gate erreicht haben;
- der LLM-Agent ändert keine Ticketstatus- oder Control-Plane-Metadaten; AI6 besitzt diese Verantwortung.

### 0.3 Änderungsregel

Ändert sich eine normative Entscheidung:

1. Planrevision erhöhen;
2. betroffene Requirement-IDs aktualisieren;
3. Traceability und Ticket-Blueprints anpassen;
4. bereits erzeugte, noch nicht begonnene Tickets neu generieren oder explizit rebasen;
5. laufende Tickets nicht still umdeuten, sondern über Contract Amendment oder Folge-Ticket behandeln.

---

## 1. Produktziel und Grenzen

### 1.1 Ziel

AI6 verwaltet Git-native Softwaretickets, lässt sie menschlich prüfen und orchestriert anschließend getrennte LLM-Sitzungen für Implementierung und einen oder mehrere Reviews. Findings werden in einer begrenzten Fix-/Re-Review-Schleife bearbeitet. Fragen und Freigaben pausieren den Run, erzeugen eine E-Mail und werden im mobilen Admin-Panel beantwortet. Nach grünen Checks kann optional ein getrenntes LLM den exakten Publish-Kandidaten auf schädliche oder nicht autorisierte Änderungen prüfen. Danach erstellt AI6 den finalen Commit und pusht gemäß konfigurierter Policy. Derselbe Review-Unterbau ist zusätzlich als eigenständiger, ticket- und approvalgebundener Review-only-Modus nutzbar: Ein serverseitig gebundener Stand — etwa ein extern oder lokal entwickelter verwalteter Branch — wird ohne Codeänderung und ohne Push mehrstufig geprüft und endet in einem gebundenen Abschlussbericht (`RUN-010`, `GIT-011`).

### 1.2 Hauptnutzer

- Entwickler, die Tickets prüfen, Modelle und Aufwand wählen und Runs starten;
- Reviewer/Approver, die Scope-, Vertrags-, Security- und Statusentscheidungen treffen;
- Administratoren, die Projekte, Benutzer, Git- und Providerzugänge sowie SecurityPolicy verwalten.

### 1.3 Nicht-Ziele des MVP

- mehrere konkurrierende AI6-Server für dasselbe Projekt;
- mehrere parallele Runs innerhalb desselben Projekts;
- Kubernetes, Redis, Service-Mesh, CQRS oder Event Sourcing;
- öffentlicher Multi-Tenant-SaaS-Betrieb;
- frei definierbare Shellbefehle aus Tickets oder Projektkonfiguration;
- automatische Pull-Request-Erstellung oder automatisches Merge;
- direkte OpenAI-/xAI-/Anthropic-API-Adapter; die erste Providerstufe läuft ausschließlich über die jeweiligen CLIs;
- Review-Aufträge außerhalb der Ticket-/Approvalbindung: freie Arbeitsverzeichnisse, freie Dateiauswahl oder PR-URLs als Reviewgegenstand;
- interaktive Provider-TUIs, ACP-Langzeitprozesse oder SDK-/Servermodi als Adaptertransport;
- parallele Providerprozesse innerhalb eines Runs sowie ein frei editierbarer Pipeline-DAG-Designer;
- semantische LLM-Deduplizierung von Findings;
- automatische Selbstoptimierung von Modell-Routing oder Prompts;
- Antworten oder Freigaben per Reply-to-Mail;
- Beweis, dass ein LLM-Sicherheitsreview vollständige Schadcodefreiheit garantiert.

---

## 2. Architekturentscheidungen

| ADR | Entscheidung | Begründung |
|---|---|---|
| ADR-001 | Modularer Laravel-Monolith | Einfache Installation, eine Codebasis, klare Module ohne Microservices. |
| ADR-002 | Docker Compose als Referenzbetrieb | Reproduzierbarer Linux-Server- und lokaler Betrieb. |
| ADR-003 | SQLite + Database Queue | Genügt für eine zentrale Instanz und einen aktiven Run je Projekt. |
| ADR-004 | Git-native Tickets | Mehrere Entwickler und Geräte teilen Anforderungen über das Projekt-Repository. |
| ADR-005 | Datenbank nur für Laufzeit | Kein zweiter autoritativer Ticketbestand. |
| ADR-006 | Ein aktiver Run je Projekt | Verhindert im MVP komplexe Scope-/Worktree-Konflikte ohne verteilte Locks. |
| ADR-007 | CLI-first-Agentenadapter | Alle Provider — Codex, Grok, Copilot und später Claude — werden über einen gemeinsamen Adaptervertrag eingebunden. |
| ADR-008 | Neue Sitzungen je Ticket und Rolle | Kein unkontrollierter Kontextübertrag zwischen Tickets. |
| ADR-009 | Alle Reviewer prüfen jeden neuen Checkpoint | Einfach, nachvollziehbar und ohne Primary-/Mehrheitslogik. |
| ADR-010 | Adaptive, policygebundene Scope-Erweiterung | Reale Repositoryarchitektur darf den Ausgangsscope kontrolliert ergänzen. |
| ADR-011 | Ein gemeinsamer Human-Request-Mechanismus | Fragen, Scope, Vertrag, Limits und Security nutzen denselben Ablauf. |
| ADR-012 | SecurityPolicy: strict default, development/custom explizit | Sichere Defaults bei weiterhin einfacher lokaler Einrichtung. |
| ADR-013 | Optionales LLM-Security-Gate vor finalem Commit | Zusätzliche Defense in Depth auf exakt gebundenem Candidate. |
| ADR-014 | Pushmodus manual oder automatic_after_gates | Unterstützt sicheren Standard und den gewünschten vollautomatischen Ablauf. |
| ADR-015 | Progressive Ticket-Elaboration | Spätere Tickets nutzen echten Code statt spekulativer Pfade und APIs. |
| ADR-016 | Review-only als ticket- und approvalgebundener Runmodus | Derselbe Check-/Review-/Finding-/Gate-Unterbau prüft serverseitig gebundene Stände ohne Push; freie Reviewaufträge bleiben außerhalb der Run-Grenze. |
| ADR-017 | Erste reale Providerstufe: Codex-, Grok-Build- und GitHub-Copilot-CLI | Drei Headless-CLI-Adapter mit je genau einem gepinnten, doctor-geprüften Transport; Claude bleibt spätere Erweiterung ohne V1-Blockade. |
| ADR-018 | Quellenabhängige advisory Finding-Verifikation | Unabhängige Evidenzprüfung ohne Auto-Unblock; wirksame Dispositionen bleiben menschlich autorisiert und checkpointgebunden. |

---

## 3. Normativer Anforderungskatalog

Die IDs sind stabil. Detaillierte Tickets referenzieren diese IDs unter `spec_refs`, statt die Verträge zu kopieren.

### 3.1 Produkt
- **PROD-001** – AI6 verwaltet mehrere bekannte Projekt-Repositories über eine zentrale Instanz.
- **PROD-002** – AI6 ist auf Linux-Servern und lokal über denselben modularen Monolithen betreibbar.

### 3.2 Tickets
- **TKT-001** – Ticketinhalt und dauerhafter Ticketstatus sind Git-native Dateien; die Datenbank ist keine zweite Ticketquelle.
- **TKT-002** – Jedes Ticket enthält mindestens `schema: ai6.ticket.v1`, id, title, status, depends_on und einen nicht leeren Abschnitt Goal.
- **TKT-003** – Das V1-Format verwendet YAML-Frontmatter und Markdown; das Legacy-Format darf ausschließlich read-only bis zum erfolgreich protokollierten M169-Migrationspilot gelesen werden und ist danach ohne stillen Fallback abzuschalten.
- **TKT-004** – Ticket-IDs, Statuswerte, Abhängigkeiten, Pfade sowie AC-/TC-IDs werden deterministisch validiert.
- **TKT-005** – Nur die Ticketdatei ist autoritativ für den Status; zentrale Markdown-Indizes sind reine Ansichten.
- **TKT-006** – Ticketänderungen aus dem Panel verwenden Git-Blob-Konfliktschutz und erzeugen nachvollziehbare Git-Commits. Eine inhaltlich redigierte Projektion ist niemals Editorquelle; bei Redaction eines vertragsrelevanten Bytes fallen Bearbeitung und Approval geschlossen aus, bis ein neuer unredigierter Blob sicher gelesen und an seine exakte Basis gebunden wurde. Ein unredigiertes schemaungültiges Dokument darf der Editor reparieren, aber niemals approved werden; der neue Schreibstand muss vor Commit vollständig gültig sein.
- **TKT-007** – `files` ist die zum Freigabezeitpunkt beste Vermutung über den Ausgangsscope und keine abschließende Liste. Erweiterungen erfolgen ausschließlich über die adaptive Scope-Policy; eine policygebunden aufgenommene Erweiterung wird dokumentiert und blockiert weder Umsetzung noch Review.
- **TKT-008** – Abhängigkeiten werden als gerichteter azyklischer Graph geprüft und im Panel verständlich dargestellt.
- **TKT-009** – Approval bewahrt die vor dem Status-CAS menschlich geprüfte Ticket-/Control-Bindung und die daraus ausschließlich durch `todo → ready` erzeugte freigegebene Ticket-/Control-Bindung getrennt. Der kanonische Anforderungs-Hash nimmt ausschließlich den Status aus.
- **TKT-010** – Als Ticketkandidat gilt ausschließlich ein regulärer, nicht symbolischer Git-Blob als direkter Kindpfad `<TICKET-ID>.md` im normalisierten `tickets_path`; `.md`, Pfad und ID werden ASCII-/case-sensitiv geprüft. Andere Endungen, Mehrfachendungen, Unterverzeichnisse, Symlinks und ungültige Namen werden ignoriert. Case-Fold-Kollisionen, mehrere Kandidaten mit derselben deklarierten ID sowie Abweichungen zwischen Dateiname und Frontmatter-ID sind deterministische Projektvalidierungsfehler.
- **TKT-011** – `ai6.ticket.v1` ist das generische Ticketformat mit den Mindestfeldern aus `TKT-002`. Das serverseitig bekannte Validierungsprofil `ai6_detail_v1` verschärft es für aus diesem Plan erzeugte AI6-Detailtickets um die Felder und Abschnitte aus §13.4; das Profil wird durch freigegebene Projektpolicy gewählt, niemals durch Ticketinhalt.
- **TKT-012** – Der tatsächlich wirksame Scope eines abgeschlossenen Runs wird in die Ticketdatei zurückgeschrieben: initialer Scope, je aufgenommenem Pfad der benannte Entscheidungsgrund, quarantänierte Pfade und der verbrauchte Anteil des Pfadlimits erscheinen im AI6-eigenen Abschnitt `## Recorded Scope`. Ihn schreibt ausschließlich AI6 im gebundenen Post-Push-Status-CAS, niemals ein Agent. Er ist Dokumentation und kein Vertrag: Er geht nicht in `ticket_contract_sha256` ein, ist kein Contract Amendment und invalidiert keine Reviewevidenz.

### 3.3 Git
- **GIT-001** – Jedes Projekt besitzt einen verwalteten Clone mit explizitem Control-Branch und gehärtetem SSH-/Remote-Vertrag. Control-Branch, Remote und Managed-Path stammen nur aus vertrauenswürdigen Projektmetadaten; eine autorisierte Änderung erfordert Step-up, Ref-/OID-Prüfung, keinen aktiven Run und invalidiert abhängige Read Models, Snapshots, Approvals und Queue-Freigaben.
- **GIT-002** – Jeder Run verwendet einen eigenen Branch und Worktree; höchstens ein aktiver Run je Projekt ist zulässig.
- **GIT-003** – Agentenänderungen werden vor Übernahme gegen Pfade, Scope, Dateityp, Symlinks, Größe und Diff geprüft.
- **GIT-004** – Qualitätsreviewer prüfen unveränderliche Checkpoints in getrennten read-only beziehungsweise wegwerfbaren Workspaces.
- **GIT-005** – Der Publish-Kandidat wird vor dem finalen Commit über Tree-OID und Diff-Hash gebunden.
- **GIT-006** – Vor Push werden Remote-Basis, erwartete OID, Branch-Allowlist und Provenienz erneut geprüft.
- **GIT-007** – Push ist konfigurierbar manuell oder automatisch nach allen Gates; automatisches Merge ist kein MVP-Ziel. Die Modi bleiben exakt `manual` und `automatic_after_gates`; serverseitige Risikoregeln dürfen ein freigegebenes `automatic_after_gates` für einen Approval-Snapshot nur auf `manual` verengen und niemals erweitern, und Projekt- oder Providerinhalt kann die Auswahl nicht ausdehnen.
- **GIT-008** – Grobe Ticketstatus-Übergänge werden per Compare-and-Swap auf dem jeweils frisch verifizierten Control-Head veröffentlicht. Der bei Approval gespeicherte Control-Commit bleibt Provenienz und ist nicht dauerhaft die erwartete Branchspitze; ein Run bleibt bis zur erfolgreichen Statussynchronisation gesperrt.
- **GIT-009** – Browser und App lesen oder mutieren Git ausschließlich über typisierte asynchrone Control Operations. Der Worker bindet jedes Ergebnis an Control-Commit und Blob-SHA und veröffentlicht ein redigiertes, nicht autoritatives Read Model mit getrenntem Parse-/Validierungs- und Redactionstatus; Staleness, Fehler und CAS-Konflikte bleiben sichtbar. Der Contract-Hash existiert nur für gültige Dokumente. Sichere Presentation-Sanitization verändert den Vertragsinhalt nicht, während jede inhaltliche Redaction Approval und Editor fail closed blockiert.
- **GIT-010** – Agent, Checker und Reviewer erhalten ausschließlich eine exportierte oder overlay-isolierte Tree-Sicht ohne erreichbare `.git`-, Common-Dir-, Alternates-, Ref-, Index- oder Hook-Metadaten des Managed-Clones. Ein optionaler Git-Lesekontext ist separat, bereinigt und technisch read-only; ausschließlich der Worker berechnet und importiert einen anschließend vollständig validierten Patch.
- **GIT-011** – Ein Review-only-Lauf prüft ausschließlich serverseitig gebundene Reviewgegenstände: einen verwalteten Branch gegen den im Approval gebundenen verifizierten Control-Stand, eine gebundene Commit-Range oder einen Einzelcommit im Managed-Clone mit nachgewiesener Basis, einen durch den Worker importierten und vollständig pfad-, typ-, scope- und diffvalidierten Patch oder einen vorhandenen AI6-Checkpoint samt Bindung. Der Worker normalisiert die Quelle in einen wegwerfbaren, gitmetadatenfreien Review-Checkpoint mit Tree-OID- und Diff-Hash-Bindung. Freie Arbeitsverzeichnisse, frei ausgewählte Dateien und PR-URLs sind keine zulässigen Eingaben; ein Pull Request kann später ausschließlich über seine gebundene Commit-Range abgebildet werden.

### 3.4 Konfiguration
- **CFG-001** – Instanzweite Security-, Modell-, Effort- und Prozessprofile liegen ausschließlich in vertrauenswürdiger Serverkonfiguration.
- **CFG-002** – Projektkonfiguration ist versionierter untrusted Input, wird streng validiert und als freigegebener Snapshot an Runs gebunden.
- **CFG-003** – Projektkonfiguration referenziert nur serverseitig bekannte Check- und Modellprofile und kann keine Shellstrings definieren.

### 3.5 Agenten und Ausführung
- **AGT-001** – Codex CLI, Grok-Build-CLI, GitHub-Copilot-CLI, Claude CLI und FakeAgent verwenden denselben AgentAdapter-Vertrag.
- **AGT-002** – Modell und Aufwand werden pro Implementierungs- und Reviewer-Slot aus einer Allowlist gewählt.
- **AGT-003** – Jedes Ticket startet neue Implementierungs- und Review-Sitzungen; innerhalb eines Runs wird die jeweilige Sitzung fortgesetzt, wenn unterstützt.
- **AGT-004** – Agentenergebnisse sind versioniertes strukturiertes JSON und werden vor jeder Wirkung schema-validiert.
- **AGT-005** – Ein deterministischer FakeAgent deckt Erfolg, Findings, Fragen, Fehler, ungültiges JSON und Security-Ergebnisse ab.
- **AGT-006** – ProcessRunner verwendet Argumentlisten, Environment-Allowlist, Laufzeit-/Outputlimits und kontrollierten Cancel ohne Shellstrings.
- **AGT-007** – Agent- und Checkprozesse sind von Git-Push-, SMTP-, Websession- und jeweils fremden Provider-Credentials getrennt. Persistente Providercredentials liegen pro Profil in einem dedizierten Store außerhalb des Execution-Home; jeder Agentenprozess erhält ausschließlich eine kurzlebige minimale read-only Authprojektion für genau sein Profil.
- **AGT-008** – Der zentrale versionierte Promptkatalog und sein kanonischer Prompt-Snapshot-/Hashvertrag existieren vor Approval. Runs, Agenten und Reviews verwenden ausschließlich den freigegebenen Prompt-Snapshot.
- **AGT-009** – Provider-native Instruktionsauflösung und Runtime-Erweiterungen bilden einen freigegebenen, versionierten Vertrag: relevante Repository-Instruktionspfade, Blob-SHAs, Reihenfolge, Geltungsbereiche, der effektive Hash und das versiegelte Provider-Runtime-Profil werden vor Approval ermittelt. Isolierte Providerprozesse sehen bei nativer Autodiscovery ausschließlich diese read-only Snapshotversionen und keine Host-/Parent-Instruktionen. Workspace-/Home-Konfiguration, MCP-Server, Plugins, Skills, Hooks, Commands und sonstige Autoload-Erweiterungen sind aus, sofern sie nicht aus einem vertrauenswürdigen serverseitigen Allowlistprofil stammen und dessen Hash mitfreigegeben ist; Projektinhalt kann sie nie aktivieren. Änderungen invalidieren die Bindung. Während eines aktiven Runs sind Instruction- und Runtime-Profil-Amendments verboten; Fortsetzung verlangt kontrollierte Rücksetzung auf `todo`, eine neue Approval, einen neuen Run und neue Agentensitzungen.
- **AGT-010** – Jeder reale Provideradapter der ersten Providerstufe — `codex_cli`, `grok_cli`, `github_copilot_cli` — läuft nicht-interaktiv über genau einen gepinnten, vom Capability-Doctor für die konkrete CLI-Version nachgewiesenen Headless-Transport; TUI-Sitzungen, ACP-Langzeitprozesse und SDK-/Servermodi sind kein V1-Transport. Der Adapter extrahiert die finale Providerantwort aus dem providereigenen Event- beziehungsweise Textformat und validiert sie gegen die zentralen `ai6.agent.v1`-/`ai6.quality-review.v1`-Verträge; ein fremdes Providerschema wird nie zweiter Fachvertrag, und ein ungültiges Ergebnis ist ein sichtbarer Fehler, niemals ein impliziter Erfolg. Vollzugriffs- und Auto-Approve-Optionen sind kein V1-Default und stammen nie aus Projektinput; nicht nachweisbar abschaltbare Autodiscovery-, Sandbox- oder Toolgrenzen beenden den Start fail closed. Belastbar von der CLI gemeldete Token-, Kosten- und Nutzungswerte werden je Providerturn samt Quelle gespeichert; fehlende Werte bleiben explizit `unknown` und werden nie aus Textlänge oder Laufzeit geschätzt.
- **AGT-011** – Eine manuelle, projektunabhängige Prompt-Hilfe für Codex- und Claude-Desktop-Sitzungen verwendet ausschließlich versionierte Einträge und den einen kanonischen Renderer des zentralen Promptkatalogs. Statische Prompts werden bytegenau aus dem gerenderten Katalogeintrag kopiert. Eine vollständige Reviewantwort ist untrusted Eingabe, durchläuft vor Parsing und Darstellung die zentrale UTF-8-/Redaction-Grenze und darf genau einen terminalen Abschnitt mit der Zeile `### Fix-Liste` liefern; ausschließlich dessen nicht leerer Inhalt wird einmal in das dynamische Fixtemplate eingesetzt. `Nichts zu fixen.` erzeugt keinen Folgeprompt. Weder Eingabe noch Ergebnis werden persistiert, geloggt oder automatisch an einen Provider übertragen; ein vertrauenswürdiges serverseitiges Bytelimit begrenzt die Eingabe vor Wirkung.

### 3.6 Run-Orchestrierung
- **RUN-001** – Ein persistenter Run besitzt zentrale state-, phase- und wait_reason-Werte sowie idempotente Übergänge.
- **RUN-002** – Runstart ist an den nach dem Approval-CAS entstandenen `approved_ticket_blob_sha`, die Provenienz `approved_control_sha` sowie Contract-, Config-, Modell-, Scope-, Prompt- und Security-Snapshot gebunden. Approval bewahrt zusätzlich `reviewed_ticket_blob_sha` und `reviewed_control_sha`, setzt ein gültiges `todo`-Ticket per Status-only-Saga auf `ready` und weist nach, dass sich zwischen beiden Bindungen ausschließlich das Statusfeld geändert hat. Vor Start wird der aktuelle Control-Head separat als `claim_parent_control_sha` verifiziert; er muss Fast-forward-Nachfahre von `approved_control_sha` sein. Erst danach dürfen unverändertes freigegebenes Ticket und gültige Snapshots auf einem durch irrelevante Commits fortgeschrittenen Control-Branch beansprucht werden. Eligibility und Queuezustand bleiben davon getrennte Verträge.
- **RUN-003** – Implementierung, Scopeprüfung, Checks, Checkpoint, Review, Fix, Finalisierung und Push bilden einen wiederaufnehmbaren Workflow.
- **RUN-004** – Browserrequests führen niemals direkt Agenten, Git oder Projektchecks aus.
- **RUN-005** – Workerneustart, Jobwiederholung und Browserwechsel dürfen keine doppelten Seiteneffekte erzeugen.
- **RUN-006** – Freigegebene Limits für Laufzeit, Agentenaufrufe, Reviewrunden, Verifikationsrunden, Fixrunden, zusätzlich freigegebene Scope-Pfade, geänderte Dateien, Changed Bytes, Instruktionsdateien/-einzelbytes/-gesamtbytes/-Importtiefe, finalen Promptinput, Artefaktanzahl, Einzelartefaktgröße, Gesamtartefaktgröße und Provideroutput werden unter unveränderlichen Servermaxima gezählt. Instruktionsimporte erkennen Zyklen und Duplikate kanonisch. Eine Überschreitung stoppt vor Approval beziehungsweise Provideraufruf und vor partieller Wirkung, erzeugt in einem bestehenden Run den gebundenen Wartestatus und bei Bedarf höchstens einen gleichzeitig offenen Human Request.
- **RUN-007** – Externe Änderungen an Ticketvertrag, Control-Branch oder Runbasis werden an sicheren Übergängen erkannt und pausieren den Run. Ein freigegebenes Contract Amendment wird per Control-Branch-CAS mit Status `in_progress`, neuem `run_base_sha`, isoliertem Ticketpatch, nachgewiesen unverändertem Codebaum und vollständiger Invalidierung abhängiger Evidenz fortgeführt; unerwarteter Drift führt ohne stilles Rebase zu `git_base_changed`.
- **RUN-008** – Gültig freigegebene `ready`-Tickets dürfen auch mit unerfüllten Abhängigkeiten in der Approval-Queue liegen. Vor jedem atomaren Claim wird Eligibility nach allen relevanten Git-, Snapshot-, Policy-, Capability-, Dependency-, Runabschluss- und Queueereignissen neu bewertet; erst danach entsteht ein `runs.state=queued` mit neuen Sessions.
- **RUN-009** – Deklarierte `MG-`-/`EXT-`-Gates sind standardmäßig blockierend. Ein offenes Gate verhindert Candidate, finalen Commit und Push; nur gebundene autorisierte Evidenz schließt es, und Änderungen an Vertrag oder relevantem Checkpoint invalidieren die Evidenz.
- **RUN-010** – Ein Review-only-Lauf ist ticket- und approvalgebunden, wird über denselben atomaren `ready → in_progress`-Claim beansprucht, verändert weder Code noch verwaltete Refs und endet ohne Push über eine eigene absturzsichere report-only Abschluss-Saga: Nach vollständigen Ergebnissen aller erforderlichen Reviewer- und gegebenenfalls Verifierslots, vollständiger AC-Abdeckung und aufgelösten Human-/Limit-Warteständen wird der Abschluss gemäß gebundenem Abschlussmodus bestätigt (`manual` erzeugt `wait_reason=manual_report`), der Ticketstatus per Compare-and-Swap `in_progress → ready` synchronisiert und erst nach bestätigtem eigenem Status-CAS `runs.state=completed` terminal gesetzt sowie die Projektsperre freigegeben. Wirksam blockierende Findings verhindern den gebundenen Abschlussbericht nicht, sondern bleiben in ihm sichtbar. Weder `no_change_required` noch ein pushloser `in_progress → review`-Übergang noch direktes Setzen von `runs.state` ersetzen diese Saga; Cancel, Crash-Recovery und Wiederanlauf folgen dem gemeinsamen Status-Saga-Vertrag. Ein Fix aus Review-only-Ergebnissen startet nie still im selben Lauf, sondern erfordert einen neuen, eigenständig menschlich freigegebenen Implementierungs-/Fixlauf mit frischer Snapshot-/Scopebindung; die Findings des Review-only-Laufs sind dabei referenzierte Evidenz, keine Mutationsautorität.

### 3.7 Reviews
- **REV-001** – Ein Ticket kann mehrere Qualitätsreviewer mit jeweils eigenem Modell und Aufwand besitzen.
- **REV-002** – Alle erforderlichen Reviewer prüfen unabhängig denselben Checkpoint; ein blockierendes Finding wird nicht durch Mehrheitsentscheidung aufgehoben.
- **REV-003** – Review-JSON enthält Finding-Quelle, Schweregrad, Disposition, Evidenz, erwartetes Ergebnis und AC-Bezug.
- **REV-004** – Jedes Akzeptanzkriterium wird durch jeden erforderlichen Reviewlauf genau einmal eingestuft.
- **REV-005** – Nach jeder Code-, Scope- oder Vertragsänderung entsteht ein neuer Checkpoint und ein vollständiger Review durch alle ausgewählten Reviewer.
- **REV-006** – Originalfindings und Reviewerresultate bleiben unverändert. Die effektive Blockade wird separat und checkpointgebunden aus Originalschwere und autorisierter Disposition berechnet: gültiges `fixed`, `not_applicable` oder `accepted_risk` kann ein Must-fix auflösen; Suggestions blockieren nicht, und relevante Vertrags-, Scope-, Prompt-, Policy- oder Checkpointänderungen invalidieren die Disposition.
- **REV-007** – Wiederholte Findings ohne relevanten Diff-Fortschritt und überschrittene Limits führen in einen menschlich lösbaren Wartestatus.
- **REV-008** – Optional prüft ein separater LLM-Sicherheitsreview den exakten Publish-Kandidaten vor finalem Commit und Push.
- **REV-009** – Reviewer können wiederverwendbare Instruktionsupdates strukturiert empfehlen, ändern AGENTS.md oder vergleichbare Dateien aber nicht selbst.
- **REV-010** – Ein Verifierergebnis ist ein eigenes unveränderliches, quell- und checkpointgebundenes advisory Reviewresultat zu genau einem Originalfinding beziehungsweise einer exakten Duplikatgruppe mit allen unabhängigen Quellen. Der Verifierslot wird quellenabhängig aus serverseitig erlaubten Profilen gewählt: Kein Slot verifiziert ein Finding aus demselben Provider-/Modellprofil, und ein Implementierungsslot verifiziert nicht den von ihm implementierten oder gefixten Stand. Bestätigung liefert zusätzliche Evidenz; Widerspruch, fehlende Evidenz oder `inconclusive` eskalieren sichtbar an HumanLoop. Ein Verifier schreibt kein Originalfinding um, hebt kein blockierendes Finding auf und erzeugt keine wirksame Disposition; `not_applicable` und `accepted_risk` bleiben nach `REV-006` menschlich autorisiert, Verifikationsrunden sind nach `RUN-006` begrenzt, und ein unbegrenzter Challenge-Loop ist ausgeschlossen.
- **REV-011** – Spezialisierte Review-Promptprofile — etwa Ticket-/AC-Treue, funktionale Korrektheit, Security, Datenbank/Migrationen, Concurrency, Performance, Tests, Architektur und API-Verträge — sind versionierte Profile des zentralen Promptkatalogs mit genau einem Renderer. Approval bindet je Reviewer-Slot Promptprofil, Providerprofil und Auswahlgründe; Pfad- und Risikoregeln wählen ausschließlich serverseitig bekannte Profile und erzeugen keine freien Prompts oder Befehle. Jede Stufe erhält ein stufengerechtes, redigiertes und an Run, Ticketblob, Checkpoint-Tree, Diff-Hash, Scope, Prompt-/Instruction-Snapshot, Provider-Runtime-Profil und SecurityPolicy gebundenes Kontextpaket statt des Gesamtrepositorys; eine Änderung dieser Bindungen invalidiert nachgelagerte Ergebnisse. Unabhängig vom Profilfokus liefert jeder erforderliche Reviewlauf die vollständige `criterion_coverage` nach `REV-004`, und die strukturierte Implementierungszusammenfassung bleibt untrusted Kontext des Implementierers, niemals geprüfte Wahrheit.

### 3.8 Human-in-the-loop
- **HUM-001** – Pro Run darf höchstens ein blockierender Human Request gleichzeitig offen sein. Nach dessen terminaler Beantwortung, Ablehnung oder Aufhebung darf ein späterer Warteschritt einen neuen Request erzeugen; alle historischen Requests und Interventionen bleiben unverändert auditierbar.
- **HUM-002** – Ein offener Human Request löst eine idempotente E-Mail-Benachrichtigung an den verantwortlichen Benutzer aus.
- **HUM-003** – Fragen und Freigaben werden ausschließlich im authentifizierten Panel beantwortet; E-Mail enthält keine One-Click-Wirkung.
- **HUM-004** – Antworten sind an Runversion, Ticketvertrag, Checkpoint, Scope, Agentensitzung und angeforderte Wirkung gebunden.
- **HUM-005** – Das Panel unterstützt Zusatzprompt, Scopeentscheidung, Vertragsänderung, zusätzliche Reviewrunde, Modellwechsel, Finding-Disposition und Cancel.

### 3.9 Weboberfläche
- **UI-001** – Ticket-, Run-, Review-, Diff-, Check- und Attention-Ansichten sind auf Laptop und Smartphone bedienbar.
- **UI-002** – Ticketlisten und Details zeigen id, title, status, depends_on und Goal besonders gut lesbar.
- **UI-003** – Die Freigabeoberfläche erfasst Implementierungsmodell/-aufwand, mehrere Reviewer samt Aufwand, Limits und Pushmodus.
- **UI-004** – Die Runansicht zeigt Phase, Sessions, geänderte Dateien, Diff, Checks, Findings, Security-Gate, Pushstatus und Interventionen.
- **UI-005** – Eine Attention-Inbox zeigt offene Fragen, Freigaben, Limits und Securityentscheidungen mit ihrem Mailstatus.
- **UI-006** – Das Panel zeigt die freigegebene Projektqueue, blockierende Abhängigkeiten und das nächste startbare Ticket.
- **UI-007** – Ein globaler authentifizierter Promptarbeitsbereich zeigt statische und dynamische manuelle Prompts mit bearbeitbarer Eingabe, read-only Vorschau und einer expliziten Kopieraktion. Er ist auf Laptop und Smartphone ohne horizontales Scrollen bedienbar, meldet Clipboard-Erfolg nur nach bestätigtem Browsererfolg und bietet bei verweigerter oder fehlender Clipboard-API stattdessen die vollständig auswählbare Vorschau samt klarer manueller Kopieranweisung; die feste CSP wird weder durch Inline-Script noch durch `unsafe-inline` oder `unsafe-eval` gelockert.

### 3.10 Sicherheit
- **SEC-001** – SecurityPolicy besitzt strict als Default sowie development/custom mit sichtbarer, explizit bestätigter Reduktion.
- **SEC-002** – Webzugriff nutzt projektbezogene Autorisierung; privilegierte Rollen verwenden standardmäßig Passkey/TOTP und Step-up.
- **SEC-003** – Jede neue autorisierte Websession benötigt standardmäßig einen Code an AI6_LOGIN_CONFIRMATION_EMAIL; die Maßnahme ist nur per Env/Config abschaltbar.
- **SEC-004** – CSRF, Pfad-/Ref-/JSON-Validierung, Shell-Injection-Schutz, Credentialtrennung, Anti-Replay und sichere Ausgabe sind nicht abschaltbar.
- **SEC-005** – Agenten- und Checker-Sandbox fallen bei aktivierter Kontrolle geschlossen aus und können nicht durch Projektinhalt gelockert werden.
- **SEC-006** – Git-SSH verwendet getrennte Schlüssel, gepinnte Hosts, erlaubte Protokolle/Refs und eine vollständig isolierte Git-Ausführungsumgebung: keine Repository-Hooks, System-/Host-Globalconfig, Credentialhelper, Submodule, externen Filter, Pager, textconv/ext-diff, fsmonitor oder Signing-Helfer. SSH läuft nur über einen vertrauenswürdigen festen Wrapper mit Argumentlisten.
- **SEC-007** – Ticket-Markdown, Logs, Diffs und Providertexte gelten als untrusted Webinhalt. Secret-/Sensitive-Value-Redaction wird zentral und deterministisch genau einmal aufgelöst; sichere Markdown-/HTML-/ANSI-Darstellung konsumiert dieses Ergebnis und bleibt als klar getrennte Presentation-Sanitization ohne zweite Redaction-Regelsammlung. Redaction-Fingerprints sind ausschließlich versionierte, domain-separierte und projekt-/rungebundene HMACs mit eigenem serverseitigem Schlüssel; ein unkeyed Digest des entfernten Werts ist verboten.
- **SEC-008** – LLM-Sicherheitsreview ist Defense in Depth, read-only, prompt-injection-resistent ausgelegt und bei aktiver Policy fail closed.
- **SEC-009** – Secrets, sensible Pfade und unbekannte Provenienz werden deterministisch vor dem Security-LLM und Push geprüft.
- **SEC-010** – Backups, Restore, APP_KEY-Erhalt, Credentialrotation und Retention sind dokumentiert und getestet.
- **SEC-011** – Runlogs, Artefakte und Providerdaten werden minimiert, redigiert, größenbegrenzt und mit zentral konfigurierter Retention gespeichert. Ein idempotenter Scheduler löscht abgelaufene Rohdaten tatsächlich, erhält nur redigierte Audit-Tombstones und verhindert danach Ausgabe oder Download.

### 3.11 Betrieb und Wartbarkeit
- **OPS-001** – Docker Compose ist der primäre Installationsweg; dieselbe Codebasis unterstützt Server- und lokalen Betrieb.
- **OPS-002** – SQLite, Database Queue und Scheduler genügen für eine zentrale Instanz mit einem aktiven Run je Projekt.
- **OPS-003** – ai6:doctor prüft Konfiguration, Mail, Git, Provider, Sandbox, Checker, SecurityPolicy und Betriebsprofile.
- **OPS-004** – Architektur bleibt modularer Monolith ohne Redis, Kubernetes, Microservices, CQRS oder Event Sourcing im MVP.
- **OPS-005** – Legacy-Tickets und das bisherige Prompt-Tool werden kontrolliert migriert; Statusindizes bleiben danach nicht autoritativ. Nach dem erfolgreichen M169-Pilot wird der Legacy-Leser im selben Release abgeschaltet beziehungsweise entfernt und der migrierte V1-Bestand neu validiert.
- **OPS-006** – Ein repositorylokaler deterministischer Generator exportiert Blueprint-Metadaten und Requirement-Zuordnungen aus diesem Plan nach `docs/AI6_TICKET_MANIFEST.yaml`; ein Driftcheck schlägt bei fehlendem oder abweichendem Export fehl.
- **OPS-007** – Das Bootstrap verwendet ausschließlich `laravel/laravel` Tag `v13.8.0` am verifizierten Commit `e196bfdfc96903f2e10219749fcbca7c0aefe99f` als immutable Scaffoldquelle. Es wird außerhalb des Repositorys ohne Composer-Skriptausführung bezogen; nur eine explizite Backend-Allowlist darf importiert werden. Bestehende Repositorydateien bleiben erhalten, Default-Fachartefakte und implizite SQLite-/Migrate-/Queue-Skripte werden nicht übernommen. Die zugesagte Mindestlaufzeit PHP 8.5 wird durch `config.platform.php: 8.5.0` bei der Lockauflösung sowie einen sauberen Locked Install und `composer check-platform-reqs` unter einer realen PHP-8.5-Laufzeit nachgewiesen.
- **OPS-008** – Die freigegebenen Laufzeitlinien sind PHP 8.5, Laravel 13 und SQLite 3.53. Composer-Lockfile, Containerimage und Betriebspakete binden ihre konkrete Auflösung reproduzierbar; Produktion und Prüfungen beziehen niemals eine schwebende `latest`-Version. Jede spätere Änderung einer gebundenen Major-, Minor- oder Patchversion von PHP, Laravel oder SQLite erfolgt ausschließlich in einem eigenen Upgrade-Ticket. Dieses Ticket weist mindestens Kompatibilität, saubere Installation aus den gebundenen Artefakten, erforderliche Migrationen oder deren Abwesenheit, aktualisierte Versionsprüfungen und einen ausführbaren Rollback nach; eine andere Fachimplementierung darf den Versionswechsel nicht beiläufig mitführen.


---

## 4. Zielarchitektur

### 4.1 Prozesse

```text
Laptop / Smartphone
        │
        │ privates VPN + HTTPS
        │ oder eingeschränkter SSH-Tunnel
        ▼
Caddy
        ▼
app        Laravel + Blade + Livewire
           keine Git-/Provider-/SMTP-Credentials
        │
        ├── SQLite + Database Queue
        │      ├── typisierte control_operations
        │      └── blobgebundene ticket_read_models
        ├── worker     Orchestrierung, Git, SMTP; einziger Besitzer verwalteter Clones
        ├── agent      Codex/Grok/Copilot/Claude/Fake; genau ein Providerprofil
        ├── checker    freigegebene Projektchecks; keine wiederverwendbaren Secrets
        └── scheduler  Recovery, Retention, Notifications
```

Alle Rollen verwenden dasselbe Image und dieselben DTOs. Neben diesen fünf dauerhaften Prozessrollen existiert genau ein einmaliger Startschritt `init`: Er führt die Datenbankmigrationen und die privilegierte Bereitstellung des Lockverzeichnisses aus, läuft vor den übrigen Diensten, endet danach und ist keine sechste Prozessrolle — insbesondere erhält er keine eingehenden Ports und keine Providercredentials. Sein Ablauf ist eine feste, im Entrypoint hinterlegte Befehlsfolge und kein frei konfigurierbarer Befehlsstring. Er ist zugleich der einzige Ausführungskontext, der mit erhöhten Rechten laufen darf: Das gemeinsame Image setzt weiterhin einen unprivilegierten Benutzer, und die Rechteübersteuerung entsteht ausschließlich für diesen einen Dienst in der Compose-Definition. Keine dauerhafte Rolle — insbesondere nicht der Worker — erhält dafür eine privilegierte Identität, weil das genau die Verzeichnisrechte umginge, auf denen die Unersetzbarkeit der Lockobjekte nach §6.2 beruht. Keine dauerhafte Rolle migriert beim Start; ein Migrationslauf je Containerstart ist ausdrücklich unzulässig. Die App erzeugt für Fetch, Refresh, Ticketlesen und Git-Mutationen ausschließlich typisierte, idempotente `control_operations` in der Database Queue. Der Worker prüft Autorisierungssnapshot, erwarteten Control-Commit, Blob-SHA und Argumentlisten, führt Git aus und schreibt ein redigiertes Ergebnis sowie ein verwerfbares `ticket_read_model`. Jedes Read Model enthält mindestens Projekt, Control-Commit, relativen Ticketpfad, Blob-SHA, Parser-/Validierungsprofil, Erzeugungszeit, Stale-Marker, `document_state: unparsed|invalid|valid`, strukturierte Parse-/Validierungsfehler, nullable `ticket_contract_sha256`, `redaction_state` und je Redaction nur Typ, Feld/Span, Marker, Fingerprintversion/-Key-ID und einen domain-separierten, projektgebundenen HMAC-Fingerprint — niemals den entfernten Klartext oder dessen unkeyed Digest. `ticket_contract_sha256` muss bei `valid` vorhanden und bei `unparsed|invalid` `null` sein. Runartefakte binden den Fingerprint zusätzlich an die Run-ID. Die UI liest niemals den Managed-Clone, zeigt Bindung, Staleness und Redactionstatus an und fordert Aktualisierung asynchron an. Read Models sind keine Ticketautorität und dürfen jederzeit aus Git rekonstruiert werden.

Ein Read Model mit `redaction_state=content_redacted` darf sicher maskiert angezeigt, aber weder approved noch als Editor-Roundtrip zurückgeschrieben werden. Approval verlangt `document_state=valid`, `redaction_state=clear`, Contract-Hash, unveränderten erwarteten Blob-SHA und erneute Worker-Validierung. Der Editor darf zusätzlich `document_state=invalid` als exakt blobgebundene unredigierte Reparaturbasis öffnen; `unparsed`, stale oder `content_redacted` bleiben gesperrt, und der neue Inhalt muss vor Commit `valid` sein. Markdown-/HTML-Escaping und Presentation-Sanitization arbeiten auf demselben unredigierten Text und verändern dessen Vertragsbytes nicht; sie sind keine Redaction. Ein redigierter Vertrag wird außerhalb der maskierten Projektion in einem autorisierten Git-Workflow bereinigt und anschließend als neuer Blob refreshed.

`agent` und `checker` besitzen keine primäre AI6-Datenbank und keinen produktiven `APP_KEY`. Sie kommunizieren über eine kleine, lokale, gehashte ExecutionMailbox. Dies ist neben Database Queue und den typisierten Control-Operation-Resultaten die einzige zusätzliche IPC-Grenze und wird nicht zu einem eigenständigen Dienst ausgebaut.

Nur der Worker sieht Managed-Clone und echte Git-Worktrees. Für Agent, Checker und Reviewer exportiert er eine neue, kurzlebige Tree-Sicht beziehungsweise ein Overlay ohne `.git`-Datei/-Verzeichnis und ohne erreichbares Common-Dir, Alternates, Refs, Index, Hooks oder Git-Credentials. Ein optional benötigter Historienkontext ist ein separat erzeugtes, bereinigtes read-only Artefakt, niemals der Clone. Der Worker berechnet den tatsächlichen Dateipatch aus der isolierten Sicht, validiert Pfade, Typen, Symlinks, Scope, Größe und Diff und importiert erst danach in den Run-Worktree.

### 4.2 Credential-Matrix

| Prozess | Git read/write | Provider | SMTP | Primäre DB | Projektworkspace |
|---|---:|---:|---:|---:|---:|
| app | nein | nein | nein | ja | nein |
| worker | ja | nein | ja | ja | verwaltend |
| agent | nein | genau ein Profil | nein | nein | isolierter exportierter Tree ohne Gitmetadaten |
| checker | nein | nein | nein | nein | isolierter exportierter Tree ohne Gitmetadaten |
| scheduler | nein | nein | nein | ja | nein |

Persistente Providercredentials und Loginzustände liegen pro Providerprofil in getrennten, nicht als Home verwendeten Credential-Stores. Für jeden Agentenslot beziehungsweise jede neue Session entsteht ein frisches versiegeltes Execution-Home. Es enthält nur die hashgebundene Runtime-Konfiguration und eine minimale read-only Authprojektion für genau das gewählte Profil; Cache, History, sonstige Home-Konfiguration, Instruktionen, Plugins und Credentials anderer Profile werden nicht übernommen. Rotation oder Logout invalidiert aktive Projektionen und Capabilities, ohne dass der Webprozess Credentialbytes liest. Nach Prozessende wird die Projektion zerstört.

### 4.3 Module

```text
app/AI6/
├── Auth
├── Projects
├── Tickets
├── Runs
├── Agents
├── Reviews
├── HumanLoop
├── Git
├── Checks
├── Prompts
└── Shared
```

Regeln:

- Controller und Livewire-Komponenten enthalten keine Git-, Prozess- oder Orchestrierungslogik.
- Interfaces gibt es nur an echten technischen Grenzen oder für einen benötigten Fake.
- Keine generischen Repository-, BaseService-, Eventbus- oder Plugin-Schichten im MVP.
- Prompt-, Scope-, JSON-, Redaction-, Config- und State-Machine-Logik existiert jeweils genau einmal.

---

## 5. Git-native Ticketverträge

### 5.1 Generisches V1-Format und AI6-Detailprofil

Das folgende gekürzte Beispiel zeigt ausschließlich das generische Basisschema. Es ist kein aus §15 erzeugtes AI6-Detailticket und darf nicht als Ersatz für das Profil `ai6_detail_v1` verwendet werden.

```markdown
---
schema: ai6.ticket.v1
id: DEMO1
title: "Generischen Ticketvertrag demonstrieren"
status: todo
depends_on: []
---

# DEMO1 — Generischen Ticketvertrag demonstrieren

## Goal

Das generische Mindestformat anhand eines nicht produktiven Beispiels veranschaulichen.
```

Strukturelle Bezeichner — Frontmatter-Keys, Abschnittsüberschriften und wörtlich geprüfte Marker — sind englisch. Fließtext, also Ziel, Kontext, Aufgaben, Kriterien und Hinweise, bleibt deutsch.

Für jedes generische `ai6.ticket.v1` verbindlich bleiben:

- `schema: ai6.ticket.v1`
- `id`
- `title`
- `status`
- `depends_on`
- `## Goal`

`kind`, `milestone`, `risk`, `files`, `spec_refs` und die zusätzlichen Detailabschnitte sind im generischen Profil optional. Das serverseitig allowlistete Profil `ai6_detail_v1` macht sie sowie eindeutige AC-/TC-/MG-/EXT-IDs und die vollständige AC-Coverage aus §13.4 verpflichtend. Alle aus den Blueprints dieses Plans erzeugten Tickets verwenden `ai6_detail_v1`; bestehende fremde Projekte und die Legacy-Migration dürfen bewusst `generic_v1` verwenden. Die Projektdatei referenziert nur den Profilnamen, die wirksame Definition stammt aus vertrauenswürdiger Serverkonfiguration.

`files` ist der erwartete Ausgangsscope, keine unveränderliche Sicherheitsgrenze. Die Standard-ID-Regel ist `^(?=.{2,32}$)(?=.*\d)[A-Z][A-Z0-9-]*$` und akzeptiert damit beispielsweise `M169` und `AI6-001`.

Der `tickets_path` darf zusätzlich Dateien enthalten, die keine Tickets sind — etwa eine gepflegte Bestandsübersicht `tickets/README.md`. Als Ticketkandidat gilt ausschließlich ein regulärer, nicht symbolischer Git-Blob als direkter Kindpfad `<TICKET-ID>.md`; Pfad, `.md` und ID werden ASCII-/case-sensitiv geprüft. Andere Endungen, Mehrfachendungen, Unterverzeichnisse, Symlinks und ungültige Namen werden ignoriert und erzeugen allein keinen Validierungsfehler. Case-Fold-Kollisionen, mehrere Kandidaten mit derselben deklarierten ID oder eine abweichende Frontmatter-ID sind dagegen Projektvalidierungsfehler; kein Kandidat gewinnt still. Eine Übersicht ist reine Ansicht und niemals Statusquelle (`TKT-005`, `TKT-010`).

### 5.2 Statuswerte

```text
todo        erstellt oder fachlich geändert, noch nicht gültig freigegeben
ready       Ticketvertrag freigegeben; Queueaufnahme und Start erfordern zusätzlich eine aktuell gültige Approval
in_progress Run wurde auf dem Control-Branch beansprucht
blocked     dauerhaft fachlich/organisatorisch blockiert
review      Arbeitsbranch veröffentlicht; Merge/Abnahme offen
done        fachlich integriert und abgenommen
cancelled   bewusst verworfen
```

Technische Detailzustände wie „Reviewer 2 läuft“ oder „wartet auf Scope-Freigabe“ bleiben ausschließlich im Run. Der grobe Status `in_progress` dient dagegen als Git-sichtbarer Claim für andere Entwickler. Der LLM-Agent verändert den Ticketstatus nie selbst.

Zulässige Hauptübergänge:

```text
todo → ready                     menschliche Freigabe
todo → blocked|cancelled         autorisierte fachliche Entscheidung
ready → todo|blocked|cancelled   Vertragsänderung, Approval-Widerruf oder autorisierte fachliche Entscheidung
ready → in_progress              atomar kontrollierter Runstart
in_progress → review             Arbeitsbranch erfolgreich veröffentlicht
in_progress → ready              report-only Abschluss eines Review-only-Laufs ohne Push
in_progress → todo|blocked       kontrollierter Abbruch oder Rework; neuer Start erfordert neue Freigabe
in_progress → cancelled          autorisierter Hard-Cancel
blocked → todo|cancelled         autorisierte Wiederaufnahme oder Verwerfung
review → done                    Merge/Abnahme bestätigt
review → todo                    bewusster neuer Rework-Zyklus; neue Freigabe erforderlich
review → cancelled               autorisierte Verwerfung des veröffentlichten Arbeitsstands
done | cancelled                 terminal
```

Die Transition-Policy ist actor- und operationsspezifisch. AI6-009 liefert die gehärtete Status-CAS-Primitive und sperrt reservierte Kanten in Editor und allgemeiner Status-UI. `todo → ready` gehört ausschließlich der Approval-Saga aus AI6-012, `ready → in_progress` ausschließlich dem atomaren Run-Claim aus AI6-013 — für Implementierungs- wie für Review-only-Läufe —, `in_progress → review` ausschließlich der Post-Push-Statussynchronisation aus AI6-029, `in_progress → ready` ausschließlich der report-only Abschluss-Saga aus AI6-039 und Statuswechsel eines aktiven Runs ausschließlich dem `RunOrchestrator`. Die normalen menschlichen Operationen dürfen `todo → blocked|cancelled`, `ready → todo|blocked|cancelled`, `blocked → todo|cancelled` sowie nach bestätigtem externem Merge/Abnahme beziehungsweise bewusster Verwerfung `review → done|todo|cancelled` auslösen; Rollen, Step-up und erwarteter Blob/Control-OID werden je Kante geprüft. Ein Inhaltsedit an einem `ready`-Ticket schreibt im selben CAS zwingend `ready → todo` und macht jede vorhandene Approval und Queue-Eligibility unstartbar. Keine UI darf einen Zielstatus frei übergeben.

Jeder Git/DB-Statuswechsel verwendet denselben versionierten Status-Saga-Vertrag. Vor jeder Git-Mutation persistiert die App eine eindeutige `status_operation_id`, Operationstyp, Actor-/Autorisierungsbindung, erwarteten Control-Parent und Ticketblob, Quell-/Zielstatus, Contract-Hash, erwarteten Zielblob/-tree sowie die Sagaphase `prepared`. Der Worker erzeugt einen Commit mit genau einem erwarteten Parent und ausschließlich der autorisierten Status- beziehungsweise ausdrücklich gekoppelten Ticketänderung, bindet ihn an die Operation-ID und persistiert dessen OID in `commit_prepared`, bevor er per Compare-and-Swap pusht. Nach frischer Remotebestätigung folgt `control_confirmed`; erst dann dürfen die jeweiligen DB-Wirkungen atomar finalisiert und Lock/Queue/Runzustand verändert werden. `db_finalized` ist terminal.

Retry und Recovery dürfen nur die gespeicherte Operation fortsetzen. Ist der Parent unverändert, wird der fehlende Schritt ausgeführt; ist der eigene beabsichtigte Commit bereits in der frischen ersten-Eltern-Control-Historie enthalten, werden Parent, Operation-ID, Commit-OID, Zielblob/-tree, exakte erlaubte Änderung und aktueller Nachfolgezustand erneut geprüft und nur die fehlenden DB-Wirkungen vervollständigt. Ein fremder, nur ähnlich aussehender Statuscommit, falscher Parent, History-Rewrite, zusätzliche Treeänderung oder Operation-ID-Replay wird nie übernommen, sondern sichtbar als Konflikt pausiert. Crash-Injection an `prepared`, `commit_prepared`, nach bestätigtem Push vor `control_confirmed`, nach Controlbestätigung vor DB-Finalisierung und nach `db_finalized` ist für jeden Operationstyp Pflicht.

Dieser gemeinsame Vertrag gilt mindestens für Approval, Run-Claim, Soft-/Hard-Cancel beziehungsweise kontrollierte Runrücksetzung, Post-Push-Statussynchronisation und den report-only Abschluss eines Review-only-Laufs. Beim Claim entstehen Run und Projektsperre nach dem bestätigten eigenen `ready → in_progress`-Commit genau einmal. Bei Cancel/Rücksetzung werden DB-Runabschluss und Lockfreigabe erst nach dem bestätigten eigenen Statuscommit terminal. Bei der Post-Push-Synchronisation bleiben der bereits veröffentlichte Branch und die Projektsperre bis zum bestätigten eigenen `in_progress → review`-Commit erhalten; generisches Cancel oder erneuter Branch-Push sind dann verboten. Beim report-only Abschluss bleiben Run und Projektsperre bis zum bestätigten eigenen `in_progress → ready`-Commit erhalten; er enthält ausschließlich die autorisierte Statusänderung, veröffentlicht keinen Branch und wird nach Bestätigung wie die Post-Push-Synchronisation ausschließlich per Retry oder autorisierter Konfliktentscheidung abgeschlossen.

Die Approval-Saga speichert zwei ausdrücklich verschiedene Bindungspaare. `reviewed_ticket_blob_sha` und `reviewed_control_sha` binden das `todo`-Dokument, das der Mensch tatsächlich geprüft hat. Ihre `status_operation_id` führt darauf per erwartetem Control-OID ausschließlich den Status-CAS `todo → ready` aus, verifiziert bytegenau, dass kein anderes Ticketfeld und kein anderer Treeeintrag verändert wurde, und speichert den resultierenden Ready-Blob/-Commit als `approved_ticket_blob_sha` und `approved_control_sha`. `ticket_contract_sha256` ist vor und nach diesem CAS identisch, weil ausschließlich das Statusfeld ausgeschlossen wird.

`approved_control_sha` bleibt unveränderliche Freigabeprovenienz, ist aber nicht auf Dauer die erwartete Control-Branchspitze. Vor Runstart liest der Worker einen frischen Head als `claim_parent_control_sha` und weist zuerst nach, dass `approved_control_sha` dessen Git-Ahn ist; History-Rewrite, Force-Push oder ein gleich aussehender Blob auf nicht verwandter Historie blockieren. Danach verifiziert er dort weiterhin exakt `approved_ticket_blob_sha`, Contract-Hash und alle relevanten Snapshots und führt `ready → in_progress` per CAS gegen diesen frischen Parent aus. Das Ergebnis wird anfangs sowohl als unveränderliches `initial_run_base_sha` für Branch/Worktree als auch als aktuelles `run_base_sha` für die Control-/Candidate-Basis gespeichert. Ein Contract Amendment darf nur `run_base_sha` fortschreiben. Irrelevante Fast-forward-Control-Commits, etwa Statussynchronisationen anderer Tickets, lösen eine Eligibility-Neubewertung aus, invalidieren die Approval aber nicht allein. Änderungen am freigegebenen Ticketblob, an wirksamer Config/Prompt/Policy, an benötigten Capabilities oder an sonstiger gebundener Evidenz machen sie unstartbar. Ein Rework setzt den Ticketstatus auf `todo` zurück und benötigt eine neue Approval-Saga.

**Kanonische Berechnung von `ticket_contract_sha256`**

Der Git-Blob-SHA bleibt die Bindung an die exakten Originalbytes. Der zusätzliche Contract-Hash wird erst nach erfolgreicher restriktiver Ticketvalidierung berechnet und besitzt folgenden bytegenauen Algorithmus:

1. Frontmatter wird als YAML 1.2 ohne Tags, Anchors, Aliases, Merge-Keys, unbekannte oder doppelte Keys geparst. Alle Strings werden nach Unicode NFC normalisiert; Listenreihenfolgen bleiben erhalten.
2. Aus den vorhandenen bekannten Keys `schema`, `id`, `title`, `depends_on`, `kind`, `milestone`, `risk`, `files`, `spec_refs` wird ein JSON-Objekt erzeugt. `status` wird samt Key als einziges Feld ausgeschlossen; optionale fehlende Keys bleiben abwesend und sind von leeren Listen verschieden.
3. Das Frontmatter-Objekt wird exakt nach RFC 8785 JSON Canonicalization Scheme (JCS) als UTF-8 ohne BOM serialisiert. Damit sind Property-Sortierung, String-Escaping, Unicodeausgabe und sonstige JSON-Bytes festgelegt; Arrays behalten ihre Reihenfolge. Zahlen, Booleans, `null` und frei ergänzte Objekte sind in diesen Feldern unzulässig.
4. Der Markdownkörper ist alles nach dem schließenden Frontmatter-Delimiter. Ein vorhandener AI6-eigener Abschnitt `## Recorded Scope` nach `TKT-012` wird vorher samt Überschrift und Inhalt bis zur nächsten `##`-Überschrift beziehungsweise bis zum Dateiende entfernt; er ist wie der Status Dokumentation und kein Vertragsbestandteil. Seine Zeilenenden werden zu LF und sein Unicode zu NFC normalisiert. Anschließend werden ausschließlich alle LF-Zeichen am Dateiende entfernt und genau ein LF angefügt; anderer führender, nachlaufender oder innerer Whitespace wird weder getrimmt noch umgebrochen. Die Frontmatter-Delimiter selbst gehören nicht zum Körper.
5. Der Hashinput ist die ASCII-Domäne `AI6-TICKET-CONTRACT-V1` plus NUL, danach die Länge der JSON-Bytes als unsigned 64-bit big-endian, die JSON-Bytes, die Länge der Markdownbytes im selben Format und die Markdownbytes. `ticket_contract_sha256` ist der kleingeschriebene hexadezimale SHA-256 dieses Inputs.

Damit behalten ausschließlich Statusänderungen, das Schreiben oder Fortschreiben des Abschnitts `## Recorded Scope` sowie die ausdrücklich kanonisierten Darstellungsunterschiede denselben Contract-Hash. Geänderte Listenreihenfolge, Prosa, innerer Markdown-Whitespace oder ein optionales Feld verändern ihn; reine YAML-Quoting-/Einrückungs-, CRLF/LF- und Null-/Ein-/Mehrfach-Final-LF-Unterschiede können denselben semantischen Hash besitzen, bleiben aber über den Git-Blob-SHA konfliktwirksam. Plan und Ticketparser pflegen gemeinsame Golden-Vektoren für generisches und `ai6_detail_v1`-Format.

### 5.3 Autorität

Git ist autoritativ für Ticket, Code, Spezifikationen und finalen Branch. SQLite ist autoritativ für Benutzer, Approvals, Runs, Sessions, Findings, Human Requests, Events und Auditmetadaten.

### 5.4 Legacy

Das bisherige Format mit genau einem YAML-Codeblock wird ausschließlich bis zum erfolgreich protokollierten M169-Migrationspilot read-only unterstützt. AI6-038 schaltet den Legacy-Leser danach in derselben Release-Lineage ab beziehungsweise entfernt ihn, baut die Read Models neu auf und validiert alle migrierten Tickets erneut als V1. Ein nach diesem Cutoff verbliebener Legacykandidat erzeugt einen expliziten Migrationsfehler; es gibt keinen stillen Fallback und keine dauerhafte Dual-Reader-Policy. Neue oder migrierte Tickets verwenden ausschließlich Frontmatter.

---

## 6. Konfiguration

### 6.1 Instanzkonfiguration

Instanzweit und nicht durch Projekte änderbar:

- SecurityProfile und einzelne Securityflags;
- Modell-/Effort-/Rollenprofile;
- Git-Hosts, Protokolle und Ref-Allowlist;
- Checkprofile und Executable-Allowlist;
- Mail, Login-Bestätigungsadresse, Retention und Ressourcenlimits.

Beispiel:

```dotenv
AI6_SECURITY_PROFILE=strict
AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE=false
AI6_SECURITY_LOGIN_EMAIL_CONFIRMATION=true
AI6_LOGIN_CONFIRMATION_EMAIL=security@example.net
AI6_SECURITY_REQUIRE_PRIVILEGED_PASSKEY=true
AI6_SECURITY_REQUIRE_CRITICAL_ACTION_STEP_UP=true
AI6_SECURITY_REQUIRE_AGENT_SANDBOX=true
AI6_SECURITY_REQUIRE_CHECKER_NETWORK_ISOLATION=true
AI6_SECURITY_REQUIRE_LLM_PRECOMMIT_REVIEW=true
AI6_SECURITY_REQUIRE_HTTPS_OR_PRIVATE_ACCESS=true
```

Diese sieben `AI6_SECURITY_REQUIRE_*`- beziehungsweise `AI6_SECURITY_LOGIN_*`-Schlüssel sind die vollständige und abschließende Menge der abschaltbaren Maßnahmen aus §10.2; `AI6_SECURITY_PROFILE` und `AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE` sind keine Maßnahmen, sondern Profilwahl und Reduktionsbestätigung. Eine achte Maßnahme entsteht nicht ohne Planrevision.

Alle Schutzmaßnahmen sind standardmäßig aktiv. Einzelne abschaltbare Maßnahmen werden nur über `custom`/Env reduziert und bleiben im Panel sowie Doctor sichtbar. Für nicht abschaltbare Invarianten existiert kein Disable-Flag; dazu gehören insbesondere Autorisierung, CSRF, Pfad-/Ref-/JSON-/Hostvalidierung, Shell-Injection-Schutz, Credentialtrennung, Anti-Replay, sichere Ausgabe/Redaction, gehärtetes Git-SSH samt deaktivierten Repository-Hooks, deterministischer Secret-/Provenienz-Preflight und die Bindung von Review bis Push.

### 6.2 Projektkonfiguration

```yaml
version: 1
tickets_path: tickets
ticket_validation_profile: generic_v1
push_mode: manual
auto_start_next: true
dependency_satisfied_statuses:
  - done

defaults:
  implementation_profile: codex-gpt-5.6-terra
  implementation_effort: medium
  reviewers:
    - profile: grok-cli-review
      effort: provider_default
    - profile: copilot-cli-review
      effort: provider_default

limits:
  max_fix_rounds: 3
  max_review_rounds: 4
  max_verification_rounds: 2
  max_agent_invocations: 20
  max_added_scope_paths: 12
  max_changed_files: 40
  max_changed_bytes: 2000000
  max_artifacts: 20
  max_artifact_bytes: 5000000
  max_total_artifact_bytes: 20000000
  max_provider_output_bytes: 2000000
  max_run_minutes: 180

scope:
  unlisted_paths: auto_allow
  auto_allow:
    - app/**
    - resources/**
    - tests/**
  require_approval:
    - AGENTS.md
    - CLAUDE.md
    - .ai6/**
    - tickets/**
    - database/migrations/**
    - composer.json
    - composer.lock
    - package.json
    - package-lock.json
    - .github/**
    - deploy/**

checks:
  before_review:
    - php-targeted
  final:
    - php-all
    - git-diff-check
```

Die Datei darf nur erlaubte Profilnamen referenzieren. Shellstrings, Instanz-Securityflags, Secrets, `control_branch`, Remote- oder Managed-Path-Werte und unbekannte Schlüssel sind Fehler. Ein Run verwendet den durch einen Approver bestätigten Snapshot, nicht den gerade im Worktree liegenden Inhalt. Servermaxima begrenzen alle Projekt- und Approval-Limits nach oben.

Remote, Managed-Path und `control_branch` sind ausschließlich vertrauenswürdige `projects`-Metadaten. Ein Admin ändert den Control-Branch nur mit Step-up, Ref-/Hostvalidierung, erwartetem altem Control-OID und ohne aktiven Run. Der Worker verifiziert den neuen Ref, aktualisiert den Projektwert per CAS und invalidiert in derselben Transaktion atomar mit diesem Compare-and-Swap Read Models, Blob-/Config-/Prompt-/Policy-Snapshots, Approvals und Queue-Eligibility, indem er die `control_generation` des Projekts erhöht; ein Zustand mit bereits geändertem Branch und noch gültigen Beständen entsteht auch bei einem Absturz nicht. Repositoryinhalt kann diesen Wechsel weder auslösen noch autorisieren.

Eine mutierende Control Operation durchläuft genau zwei Compare-and-Swap-Phasen auf dem Projektdatensatz. Der **Claim** prüft und setzt in genau einer bedingten Aktualisierung, dass die Operationssperre frei ist und — sobald `AI6-013` das Runfeld erzeugt hat — dass kein Run aktiv ist; er hinterlegt Operation-ID, einen monotonen Attempt-Token, Ablaufzeitpunkt und Heartbeat und legt Auftrag und Queue-Job in derselben Transaktion an. Ein Prüfen in einer und ein Setzen in einer weiteren Anweisung ist unzulässig, und es existiert genau eine Anspruchsnaht; ein späteres Ticket erweitert deren Bedingung, statt einen zweiten Anspruchspfad einzuführen. Der **Publish** nach dem externen Effekt prüft in genau einer bedingten Aktualisierung, dass die Sperre exakt dem Paar aus Operation-ID und Attempt-Token gehört und alle erwarteten Vorwerte unverändert sind, und schreibt erst dann das Ergebnis. Zwischen Claim und Publish ist die Sperre nicht frei, sondern gehört der Operation; ein Vertrag, der an dieser Stelle eine freie Sperre verlangt, ist falsch. Ein Runstart verlangt umgekehrt zusätzlich zur freien Runsperre die Abwesenheit einer laufenden mutierenden Control Operation und einer ausstehenden Control-Bindung; beide Prüfungen liegen in seinem Claim.

Heartbeat, Phasenfortschritt, Finalisierung und Sperrfreigabe sind per Compare-and-Swap an das Paar aus Operation-ID und Attempt-Token gebunden. Ein Datenbank-Fencing hält jedoch keinen bereits gestarteten Kindprozess an. Ein überholter Versuch startet deshalb keinen Prozess, durchläuft keine Phase und veröffentlicht nichts; sein bereits laufender Kindprozess darf weiterlaufen, bleibt dabei aber auf seinen versuchsgebundenen Stagingbereich beschränkt und erreicht den geteilten Bestand nie. Ein Vertrag, der von einem Fencing das Ausbleiben jedes externen Effekts eines schon laufenden Prozesses verlangt, ist falsch.

Weil Prozesskennung und tatsächlicher Startzeitpunkt erst nach dem Spawn existieren, ist die Prozessidentität in genau dieser Reihenfolge zu persistieren:

1. **Launch-Intent** aus Operation-ID, Attempt-Token, Workerinstanz samt Boot-ID nach `OPS-002`, Argumenthash und Effektphase persistieren, bevor ein Kindprozess entsteht.
2. Den Kindprozess über einen blockierenden Wrapper starten, der vor dem eigentlichen Programm auf eine Freigabe wartet und bis dahin keinen externen Effekt auslöst.
3. Prozesskennung und tatsächlichen Startzeitpunkt zum Launch-Intent persistieren.
4. Erst danach den Kindprozess freigeben; der Wrapper wird dabei per `exec` selbst zum Zielprogramm.

Damit existiert kein Zeitraum, in dem ein wirkender Prozess ohne persistierte Identität läuft. Prozesskennung und Startzeitpunkt sind dabei Diagnose- und Auditdaten; weil sie an den PID-Namespace des erzeugenden Containers gebunden sind, taugen sie nicht als Livenessnachweis für eine andere Workerinstanz.

Serialisiert wird der externe Effekt deshalb über einen **versuchsgebundenen Effekt-Lock**: einen exklusiven Dateilock des Betriebssystems auf einem projektgebundenen Lockobjekt im geteilten, persistenten Workervolume. Der blockierende Wrapper erwirbt ihn, bevor er den Kindprozess freigibt, und hält ihn bis zum Prozessende; Operation-ID, Attempt-Token, Workerinstanz samt Boot-ID, Prozesskennung und Startzeitpunkt stehen dabei im Launch-Intent und nicht im Lockobjekt, das keinen Inhalt trägt und von der Anwendung nie beschrieben wird. Das Betriebssystem gibt den Lock beim Prozessende frei — auch bei `SIGKILL` und bei Containerabbruch —, weshalb sein erfolgreicher Erwerb der einzige belastbare Nachweis ist, dass kein wirkender Vorgängerprozess mehr läuft. Ein Nachweis über Rollenheartbeats scheidet aus, weil diese nach `OPS-002` auf containerlokalem `tmpfs` liegen; ein Docker-Socket oder eine vergleichbare Hostschnittstelle wird dafür nicht freigegeben. Der Lock wirkt für alle Workerinstanzen, die dieses Volume teilen — genau die Einhostinstallation aus §1.3.

Der Nachweis hängt außerdem an der Identität des Lockobjekts, nicht an seinem Pfad: Ein exklusiver Dateilock des Betriebssystems bindet an die Inode, sodass ein Löschen und Neuanlegen derselben Pfadangabe zwei Prozesse gleichzeitig eine vermeintlich identische Lockdatei halten ließe. Eine Prüfung unmittelbar nach dem Erwerb schließt das **nicht** aus: Wird die Datei erst danach ersetzt, findet auch der zweite Halter bei seiner eigenen Prüfung eine gültige Inode vor, und der erste prüft nicht erneut — beide hielten dann einen scheinbar exklusiven Lock. Die Identität muss deshalb technisch unersetzbar sein, statt nachträglich verglichen zu werden.

Deshalb gilt: Das Lockverzeichnis gehört einer privilegierten Identität und ist für den Workerbenutzer **nicht beschreibbar**. Die Lockobjekte werden vom einmaligen privilegierten Startschritt `init` aus §4.1 idempotent vorab bereitgestellt und danach weder erzeugt noch umbenannt, ersetzt oder gelöscht; ihre Anzahl ist konfiguriert. Die Bereitstellung gehört ausdrücklich dorthin und nicht in den Start des Workercontainers: Der Workerprozess bleibt unprivilegiert, weil eine privilegierte Workeridentität genau die Verzeichnisrechte umginge, die diese Zusage tragen. Ebenso unzulässig ist eine Erstbefüllung des Lockverzeichnisses aus dem Imagelayer beim ersten Mounten des Volumes: Sie wirkt nur auf einem leeren Volume, ist damit nicht idempotent und legt weder Eigentümer noch Modus des Verzeichnisses fest. Die Anwendung öffnet ausschließlich ein bereits vorhandenes Lockobjekt — symlinksicher gegen das Lockverzeichnis aufgelöst, containment-geprüft, ohne Symlinkfolge und ohne Schreibabsicht —, und ein unbekannter Lockname endet als benannter Konfigurationsfehler statt als implizites Anlegen. Weil weder App noch Worker im Lockverzeichnis erzeugen, umbenennen oder löschen können, ist der Austausch einer gehaltenen Inode ausgeschlossen. Eigentümer- und Modusprüfung sowie der Inode-Vergleich unmittelbar nach dem Erwerb bleiben als zweite Verteidigungslinie erhalten und enden bei jeder Abweichung als Lockkonflikt ohne Wirkung; sie sind aber nicht die tragende Zusage. Nachzuweisen ist der **Austauschversuch selbst**: Löschen und Neuanlegen eines Lockobjekts scheitern unter der Workeridentität an den Rechten des Lockverzeichnisses. Ein Vertrag, der stattdessen erwartet, ein einmaliger Inode-Vergleich erkenne einen späteren Austausch, ist falsch.

Welches Lockobjekt eine Operation verwendet, bestimmt der Aufrufer deterministisch aus seinem Lockschlüssel — für die Control Operations die relative Projektkennung. Diese Zuordnung ist nicht notwendig injektiv: Teilen sich zwei Projekte ein Lockobjekt, serialisieren sie unnötig gegeneinander. Das verletzt keine Invariante — nie halten zwei Halter denselben Lock — und endet höchstens im benannten, wiederholbaren Lockkonflikt; die Anzahl der Lockobjekte ist deshalb so zu wählen, dass Kollisionen die Ausnahme bleiben.

Dieser Nachweis trägt nur, wenn Lockhalter und wirkendes Programm derselbe Prozess sind. Der Wrapper bleibt deshalb kein eigener Elternprozess, sondern wird nach der Freigabe per `exec` selbst zum Zielprogramm: Der Lock-Dateideskriptor bleibt über `exec` erhalten und trägt kein Close-on-Exec, zwischen Aufrufer und wirkendem Programm existiert kein zusätzlicher Supervisorprozess, und die nach Schritt 3 persistierte Prozesskennung ist die des wirkenden Programms. Bliebe der Wrapper ein separater Elternprozess, gäbe ein `SIGKILL` allein auf ihn den Lock frei, während sein Kind weiterwirkt, und ein nachfolgender Versuch könnte den Lock erwerben und parallel denselben Bestand verändern.

Aus demselben Grund endet die Serialisierung nicht am Kindprozess. Auch der Publish wirkt nach außen, wenn er Live-Refs bewegt oder einen gestagten Baum in den verwalteten Pfad überführt, und er läuft im Worker selbst statt in einem Kindprozess. Jeder solche Abschnitt findet unter demselben projektgebundenen Effekt-Lock statt: Der Worker erwirbt ihn direkt, prüft unmittelbar nach dem Erwerb erneut, dass die Operationssperre exakt seinem Paar aus Operation-ID und Attempt-Token gehört, wirkt und gibt ihn danach frei. Beide Verwendungsformen — wrappergehalten für einen Kindprozess und direkt gehalten für einen wirkenden Abschnitt des Workers — teilen denselben Mechanismus und dasselbe Lockobjekt; eine zweite Lockimplementierung wäre ein Befund nach §4.3. Zwischen zwei solchen Abschnitten darf der Lock frei sein, weil geteilter Bestand ausschließlich unter ihm verändert wird. Der Lock ist damit an einen wirkenden Abschnitt gebunden und nicht an den Versuch als Ganzes; eine terminale Phase, die ihn „freigibt", beschreibt einen bereits erloschenen Zustand und ist falsch.

Der Reconciler wartet daher nicht auf einen Livenessnachweis, sondern stellt eine verwaiste Operation sofort unter einem höheren Attempt-Token erneut zu. Die Serialisierung übernimmt der Lock: Der Wrapper des neuen Versuchs erhält ihn erst, wenn der Prozess des überholten Versuchs beendet ist. Gelingt der Erwerb nicht innerhalb der konfigurierten Frist, endet der Versuch als sichtbarer, wiederholbarer Lockkonflikt statt als Deadlock. Der überholte Versuch beendet in dieser Zeit ausschließlich seine versuchseigene Wirkung, und sein Publish scheitert am Attempt-Token.

Jeder Versuch arbeitet anschließend in einem versuchsgebundenen Stagingbereich und überführt dessen Ergebnis ausschließlich im eigentümergebundenen Publish in den geteilten Bestand. Für eine Operation, die Refs eines geteilten Repositorys verändert, heißt versuchsgebunden: Sie schreibt ausschließlich in einen je Paar aus Operation-ID und Attempt-Token eigenen Ref-Bereich; die allowlisteten Live-Refs bewegt ausschließlich der Publish. Objekte dürfen dabei im gemeinsamen Objektspeicher landen, weil sie inhaltsadressiert und additiv sind und ohne erreichbaren Ref keine Wirkung haben. Jeder andere Schreibzugriff auf den geteilten Git-Metadatenbaum ist dagegen zu unterdrücken und nicht bloß zu ignorieren: `FETCH_HEAD` wird nicht geschrieben, automatische Maintenance und Garbage Collection sind für jeden Operationslauf abgeschaltet, und der Vertrag wird gegen eine explizite Allowlist zulässiger Metadatenänderungen geprüft statt gegen eine Aufzählung bekannter Verstöße.

Stagingbereich, versuchseigener Ref-Bereich und **versuchseigenes temporäres** Schlüsselmaterial eines verworfenen oder terminalisierten Versuchs werden symlinksicher entfernt, sodass kein Retry verwaiste Artefakte anhäuft. Davon ausdrücklich ausgenommen ist Material, das ein erfolgreicher Publish in den aktiven Bestand übernommen hat: Der provisionierte private Deploy-Key gehört nach seiner Finalisierung dem Projekt und nicht mehr dem Versuch, liegt außerhalb jedes versuchsgebundenen Bereichs und bleibt erhalten, weil Clone und Fetch ihn benötigen. Eine Bereinigungsregel, die jeden terminalisierten Versuch einbezieht, ohne diese Grenze zu ziehen, löscht den aktiven Schlüssel und ist falsch.

Überschreitet ein Publish mehr als einen Speicher — etwa Git beziehungsweise Dateisystem und danach die Datenbank —, existiert keine gemeinsame Transaktion. Ein solcher Publish ist als Saga mit benannten Phasen zu führen: Der beabsichtigte Zielzustand wird vor der ersten wirkenden Phase persistiert, jede Phase ist für sich wiederholbar, und die Reconciliation vergleicht den realen Außenzustand mit diesem Intent und holt ausschließlich fehlende Schritte nach. Weicht der Außenzustand vom Intent ab, weil ein überholter Versuch ihn hinterlassen hat, stellt die Reconciliation des Eigentümers ihn gegen den **eigenen** Intent her, statt sich auf das Nachholen fehlender Schritte zu beschränken. Genau eine Phase ist terminal; sie umfasst Bereinigung und Freigabe der Operationssperre, nicht die Freigabe des Effekt-Locks. Crash-Injection an jeder dieser Grenzen ist Pflicht (`RUN-005`).

Diese Pflicht gilt für **jeden** Operationstyp, der mehr als einen Speicher überschreitet, und nicht nur für refverändernde Operationen. Auch die Deploy-Key-Provisionierung überschreitet sie: Der private Schlüssel wandert im Dateisystem aus dem versuchsgebundenen Bereich in den aktiven Bestand, während öffentlicher Schlüssel, Schlüsselreferenz und Provisionierungszustand in die Datenbank gehen. Ihre Phasen sind `key_generated` — das Schlüsselpaar liegt vollständig im versuchsgebundenen Bereich, und sein Fingerabdruck ist gemeinsam mit Operation-ID und Attempt-Token als Intent des Versuchs persistiert; `key_activated` — der private Schlüssel liegt atomar an seinem aktiven Platz, die Datenbank kennt ihn noch nicht; `provisioning_finalized` — öffentlicher Schlüssel, Schlüsselreferenz und `provisioned` sind in einer Transaktion geschrieben, der Versuch ist aber noch nicht abgeschlossen; `attempt_completed` — der versuchsgebundene Bereich ist bereinigt und die Operationssperre freigegeben, und ausschließlich diese Phase ist terminal. Die Reconciliation entscheidet ausschließlich anhand dieser dauerhaften Bindung, und die maßgebliche Einheit ist dabei die **Operation**, nicht der einzelne Versuch: Trägt ein vorgefundener aktiver Schlüssel den persistierten Fingerabdruck eines Intents **derselben Operation-ID** — gleichgültig, welcher Attempt-Token ihn hinterlassen hat —, übernimmt ihn der aktuelle Eigentümer und holt ausschließlich die fehlende Finalisierung nach; die Übernahme findet unter dem Effekt-Lock statt und prüft unmittelbar nach dem Erwerb erneut, dass die Operationssperre seinem Paar aus Operation-ID und Attempt-Token gehört. Gehört der Schlüssel nachweislich einer **anderen Operation** oder ist er keinem Intent zuordenbar, wird er weder übernommen noch gelöscht, sondern die Operation geht in den nichtterminalen Recoveryzustand. Ausschließlich versuchseigenes Material eines nachweislich verworfenen Versuchs wird entfernt.

Die Bindung an die Operation statt an den Versuch ist dabei nicht kosmetisch, sondern die Bedingung dafür, dass die Saga nach einem Lease-Takeover überhaupt fortsetzbar ist. Stürzt ein Versuch hinter `key_activated` ab und übernimmt der Reconciler die Operation unter einem höheren Attempt-Token, gehört der aktive Schlüssel einem Vorgängerversuch. Wäre die Übernahme auf den eigenen Attempt-Token beschränkt, dürfte der neue Eigentümer ihn nicht übernehmen, während der alte Versuch gefenced ist und nicht mehr finalisieren kann: Jeder Folgeversuch liefe in den Recoveryzustand, und das Projekt bliebe dauerhaft gesperrt — im Widerspruch zur Zusage, dass ein Absturz genau an dieser Grenze terminal konsistent endet. Ein wiedererwachter Vorgängerversuch bleibt dabei unschädlich, weil sein Publish am Attempt-Token scheitert und sein Zugriff auf den geteilten Bestand am Effekt-Lock serialisiert. Der Nachweis verlangt einen Testfall, der Absturz hinter der Aktivierung, Lease-Ablauf, Übernahme unter höherem Attempt-Token und Wiedererwachen des alten Versuchs kombiniert und danach genau einen aktiven Schlüssel findet, der zum Datenbankzustand passt. Crash-Injection ist an jeder dieser Grenzen Pflicht, ausdrücklich einschließlich der Grenze nach der atomaren Aktivierung vor dem Datenbank-Commit und der Grenze nach dem Commit vor der Terminalisierung.

Scheitert der abschließende Datenbank-Compare-and-Swap, obwohl der Außenzustand bereits veröffentlicht ist, entscheidet die Ursache über den Ausgang:

- **Attempt-Token-Konflikt.** Der Versuch ist überholt. Ab der Konflikterkennung verändert er weder geteilten Bestand noch Bindung und setzt insbesondere nichts zurück — der veröffentlichte Außenzustand kann bereits seinem eigenen Intent entsprechen, gehört aber nicht mehr ihm. Er räumt ausschließlich seinen versuchseigenen Bereich und endet als Fencing-Konflikt; der Außenzustand ist Sache der Reconciliation des neueren Versuchs. Ein Vertrag, der von diesem Versuch verlangt, den geteilten Bestand „unverändert" zu lassen, verwechselt das Ausbleiben weiterer Wirkung mit dem Ungeschehenmachen der bereits erfolgten und ist nicht erfüllbar.
- **Versionszählerkonflikt bei gehaltener Sperre.** Eine Invariante ist verletzt, denn bei gehaltener Sperre kann keine andere mutierende Operation die Bindung geschrieben haben. Der Versuch löst keine weitere Außenwirkung aus und setzt keinen fremden Außenzustand zurück, wird aber **nicht** terminal freigegeben: Außenstand und Bindung sind inkonsistent, und eine Terminalisierung würde genau diesen Zustand festschreiben. Die Operation geht in einen benannten, sichtbaren und ausdrücklich nichtterminalen Recoveryzustand, der die Projektsperre hält. Aufgelöst wird er, indem die Reconciliation den realen Außenzustand gegen den persistierten Intent prüft und bei Übereinstimmung ausschließlich die fehlende Bindung gegen den dann aktuellen Versionszähler nachzieht; gelingt das innerhalb des konfigurierten Budgets nicht, bleibt der Zustand sichtbar bestehen und verlangt die **typisierte Recoveryentscheidung** aus dem folgenden Absatz. Der periodische Reconciler terminalisiert ihn nicht und gibt die Sperre nicht frei — das ist die einzige Ausnahme von seiner Regel, jede verwaiste Operation entweder erneut zuzustellen oder nach überprüfter Außenwirkung zu terminalisieren, und sie ist sichtbar statt still.

Ohne einen ausführbaren Vertrag für diese Entscheidung bliebe das Projekt dauerhaft gesperrt; „ausdrückliche menschliche Entscheidung" ist deshalb kein Verweis auf einen undefinierten Vorgang, sondern eine typisierte, autorisierte und auditierte Operation mit folgendem Vertrag:

- **Befund.** Beim Eintritt in den Recoveryzustand persistiert die Reconciliation einen redigierten **Recoverybefund**: den beobachteten Außenstand, den persistierten Intent, die Abweichung und deren SHA-256. Der Befund ist die einzige Grundlage der Entscheidung und wird bei jedem weiteren Reconcilerlauf neu erhoben; ein abweichender Befundhash macht eine offene Entscheidung ungültig.
- **Akteur und Autorisierung.** Auslösbar ausschließlich durch einen globalen Administrator mit frischem Step-up nach `SEC-002`; Repositoryinhalt, Providertext und Projektmetadaten autorisieren sie nie.
- **Geschlossene Entscheidungsmenge.** Genau drei Entscheidungen, nie ein frei übergebener Zielzustand: `retry_reconciliation` stellt die Operation unter einem höheren Attempt-Token erneut zu; `adopt_external_state` erklärt den im Befund festgehaltenen Außenstand für gültig, zieht ausschließlich die fehlende Bindung gegen den dann aktuellen Versionszähler nach und terminalisiert; `abandon_operation` terminalisiert die Operation als gescheitert und gibt die Projektsperre frei, ohne Bindung oder Außenstand zu verändern.
- **Bindung.** Jede Entscheidung bindet Projekt, Operation-ID, den zuletzt gültigen Attempt-Token, den Versionszähler der Operation und den Befundhash. Sie wird per Compare-and-Swap gegen genau dieses Tupel und den fortbestehenden Recoveryzustand konsumiert; ein veralteter Befund, ein zwischenzeitlich fortgeschrittener Versuch oder ein bereits verlassener Recoveryzustand endet als sichtbarer Konflikt ohne Wirkung.
- **Anti-Replay.** Eine Entscheidung ist genau einmal konsumierbar. Eine erneute Zustellung derselben Entscheidung ist wirkungslos, und ihre Wirkung ist nach `SEC-004` an dieselbe Anti-Replay-Zusage gebunden wie jede andere menschliche Freigabe.
- **Ausführung.** Die Entscheidung wirkt nicht im Webprozess: Sie legt ausschließlich den Entscheidungsdatensatz an, und die Wirkung entsteht im Worker als Fortsetzung derselben Operation unter neuem Attempt-Token und unter dem Effekt-Lock (`RUN-004`). `adopt_external_state` ist zusätzlich daran gebunden, dass der Außenstand im Moment der Ausführung noch dem Befund entspricht.
- **Audit und Evidenz.** Actor, Zeitpunkt, Entscheidung, Befundhash und Begründung werden redigiert auditiert und in der Projektansicht angezeigt. `abandon_operation` verlangt zusätzlich gebundene menschliche Evidenz nach `RUN-009`, dass der Außenstand geprüft wurde — es ist die einzige Entscheidung, die einen inkonsistenten Außenstand stehen lässt, und deshalb ein manuelles Gate statt einer stillen Freigabe.

Die **branchwechselbedingte** Invalidierung abhängiger Bestände hat genau einen Zustandsträger: eine monotone `control_generation` im Projektdatensatz. Der Publish eines Control-Branch-Wechsels erhöht sie; Read Models, Config-Snapshots, Approvals und Queueeinträge schreiben die zur Erzeugungszeit gültige Generation mit, und eine davon abweichende Generation macht sie sofort stale und nicht mehr startbar. Ein Callback-, Event- oder zweiter Invalidierungsweg entsteht dadurch nicht, und kein Verbraucher entscheidet selbst, ob ein Wechsel stattgefunden hat.

Die Generation ist damit die Quelle genau einer Staleness-Ursache, nicht das einzige Freshness-Prädikat. Die Quellen sind getrennt und überschneiden sich nicht:

| Ursache | Prädikat |
|---|---|
| Control-Branch-Wechsel | mitgeschriebene `control_generation` weicht von der aktuellen ab |
| Fetch mit neuem Control-Head | projizierter Control-Commit weicht von der aktuellen aktiven Control-OID ab |
| Profil- oder Configwechsel | die jeweilige Profil- beziehungsweise Snapshotbindung des Bestands weicht ab |
| verlorener Publish-Compare-and-Swap **vor** dem Außeneffekt | keine; ein an dieser Stelle nicht wirksamer Publish verändert keinen Bestand und macht deshalb auch keinen stale |

Jedes dieser Prädikate ist ein Vergleich beim Lesen und kein Schreibvorgang. Die Aussage „kein zweiter Invalidierungsweg" bezieht sich ausschließlich auf die erste Zeile.

Die letzte Zeile ist ausdrücklich auf den Publish **vor** seinem Außeneffekt eingegrenzt und keine allgemeine Aussage über jeden verlorenen Compare-and-Swap. Ein nach `outcome_published` verlorener Bindungs-Compare-and-Swap findet einen bereits veränderten Außenzustand vor; für ihn gelten die beiden benannten Konfliktpfade oben, nicht diese Zeile. Er erzeugt trotzdem keinen stillen Staleness-Fall: Beim Attempt-Token-Konflikt bringt die Reconciliation des neueren Versuchs den Außenzustand auf dessen eigenen Intent und schreibt dessen Bindung, sodass die Lesezeit-Prädikate wieder gegen eine gültige aktive Bindung vergleichen; beim Versionszählerkonflikt bleibt die Operation im sichtbaren, nichtterminalen Recoveryzustand und hält die Projektsperre, bis Außenstand und Bindung wieder konsistent sind. Die Divergenz ist damit sichtbar und blockierend statt still.

---

## 7. Persistenz und Zustandsmodell

### 7.1 Minimale Tabellen

| Tabelle | Verantwortung |
|---|---|
| users / project_memberships | Identität und projektbezogene Rollen |
| login_confirmations | kurzlebige E-Mail-Code-Challenges |
| projects | Remote, Control-Branch, Managed-Path, aktiver Run |
| control_operations | typisierte, idempotente App→Worker-Git-Aufträge mit Autorisierungs-, OID- und Ergebnisbindung |
| ticket_read_models | verwerfbare, redigierte Projektionen aus Control-Commit und Ticket-Blob; niemals Statusautorität |
| project_config_snapshots | freigegebene effektive Projektkonfiguration |
| ticket_approvals | geprüfte Todo- und freigegebene Ready-Blob-/Control-Bindung, Contract-Hash, Config-/Prompt-/Modell-/Scope-Snapshot sowie Queuezustand |
| runs | state, phase, wait_reason, Laufart Implementierung oder Review-only samt gebundenem Reviewgegenstand, `claim_parent_control_sha`, unveränderliches `initial_run_base_sha`, aktuelles `run_base_sha`, Git- und Policybindung |
| run_agents | Implementierungs-, Reviewer-, Verifier- und Security-Slots/Sessions samt Promptprofil und belastbar gemeldeten Nutzungsdaten |
| execution_jobs | Control-Metadaten der Mailbox-Aufträge |
| check_results | profilgebundene Prüfergebnisse |
| review_results | unveränderte Qualitäts-, Verifier- und Securityresultate |
| findings | normalisierte, quellgebundene Findings |
| human_requests | offene Frage oder Freigabe |
| interventions | menschliche Antwort und Wirkung |
| run_gates | deklarierte MG-/EXT-Gates, gebundene Evidenz und Invalidierungsstatus |
| run_artifacts | redigierte Metadaten, Digest, Größe, Storage-Referenz und expires_at |
| run_events | redigierte Timeline |

Keine `tickets`-Tabelle ist autoritative Quelle. `ticket_read_models` sind ein notwendiger Sicherheitsübergang für die workspacefreie App, tragen immer Control-Commit, Blob-SHA und die zur Erzeugungszeit gültige `control_generation`, gelten nach den Lesezeit-Prädikaten aus §6.2 als stale — abweichende Generation nach einem Control-Branch-Wechsel, abweichender Control-Commit nach einem Fetch, abweichende Profilbindung nach einem Profilwechsel — und dürfen vollständig gelöscht und aus Git rekonstruiert werden. Ein Stale-Marker wird dabei nicht geschrieben, und ein vor seinem Außeneffekt verlorener Publish-Compare-and-Swap macht keinen Bestand stale, weil er keinen verändert hat; für einen nach `outcome_published` verlorenen Bindungs-Compare-and-Swap gelten stattdessen die Konfliktpfade aus §6.2. Ticketinhalt, dauerhafter Status und Konfliktentscheidung bleiben ausschließlich in Git.

Auch die Multi-Review-, Verifikations- und Review-only-Erweiterungen führen keine zusätzlichen Autoritätstabellen ein: Es entstehen weder `PipelineRun`-/`StageRun`-Modelle noch ein zweiter Orchestrator noch ein Ausführungsledger in der Ticketdatei. Pipeline- und Promptprofile sind versionierte Serverkonfiguration, eine Stufe ist ein Orchestratorschritt, ein Check, ein Review-/Verifierslot oder ein Gate, und Kontextpakete, gebundene Abschlussberichte sowie Roh-/Nutzungsdaten sind `run_artifacts` beziehungsweise Felder der bestehenden Run-, Agenten- und Reviewentitäten.

### 7.2 Runzustand

```text
state: queued | running | waiting | failed | completed | cancelled
phase: prepare | implement | check | review | fix | finalize | security_review | publish
wait_reason:
  human_question | scope_approval | contract_change | review_limit |
  resource_limit | provider_error | invalid_json | check_failure |
  manual_gate | security_gate |
  git_base_changed | git_conflict | manual_push | manual_report | status_sync
```

Der Queuezustand eines noch nicht gestarteten Tickets liegt ausschließlich in `ticket_approvals`. `runs.state=queued` bezeichnet nur einen bereits atomar beanspruchten Run zwischen erfolgreichem `ready → in_progress`-CAS und Beginn seines ersten Workerjobs; vorher existiert kein Run. Alle Übergänge laufen durch den in AI6-013 eingeführten `RunOrchestrator`. Jede mutierende Webaktion übergibt die erwartete `run.version`.

Jeder Wartestatus besitzt genau einen Producer und allowlistete Resolver:

| wait_reason | Producer | Zulässige Resolver |
|---|---|---|
| `human_question` | Agent-/Systemantwort `needs_human` | gebundene Antwort oder Cancel |
| `scope_approval` | ScopePolicy | approve/reject/partial innerhalb Serverpolicy oder Cancel |
| `contract_change` | Contract-Amendment-/Driftprüfung | für Tickettext gebundener Amendment-CAS; für Instruction-/Runtime-Profiländerung ausschließlich kontrollierte Rücksetzung auf `todo` oder Cancel |
| `review_limit` | Review-/Stall-Limit | genau eine Zusatzrunde, Slot-/Modellwechsel, Finding-Disposition oder Cancel |
| `resource_limit` | RunLimitPolicy | Reduktion, Erhöhung nur bis Servermaximum, oder Cancel; harte Sicherheitsmaxima sind nicht übersteuerbar |
| `provider_error` | AgentAdapter | idempotenter Retry, allowlisteter Profilwechsel mit neuer Slotrevision oder Cancel |
| `invalid_json` | Schemaimport | neuer gebundener Turn/Profilwechsel oder Cancel; ungültige Daten wirken nie |
| `check_failure` | CheckRunner | Retry auf unverändertem Tree, Code-Fix über Orchestrator oder Cancel |
| `manual_gate` | GateEvaluator für offene `MG-`/`EXT-`-IDs | autorisierte gebundene Evidenz oder Cancel |
| `security_gate` | Security-Reviewer/Preflight | neues gültiges `clear`, policykonformer Step-up-Override oder Cancel |
| `git_base_changed` | DriftDetector | kontrollierter Abbruch mit Status-CAS und neuer Approval/Run; kein stilles Rebase desselben Candidates |
| `git_conflict` | ControlOperation/Git-CAS | Refresh mit neuer erwarteter OID und neuer Autorisierung oder Cancel; kein Überschreiben |
| `manual_push` | PushPolicy | autorisierte Pushaktion auf unverändertem Candidate oder Cancel |
| `manual_report` | ReportPolicy des report-only Abschlusses | autorisierte Abschlussbestätigung auf unverändertem gebundenem Reviewstand oder Cancel |
| `status_sync` | Status-CAS nach bestätigtem Push beziehungsweise bestätigtem report-only Abschluss | idempotenter CAS-Retry oder autorisierte Konfliktentscheidung; Projekt bleibt gesperrt |

Resolver verändern Zustand ausschließlich über `RunOrchestrator`, sind an `run.version` und die jeweils genannten Hashes gebunden und besitzen für Erfolg, Stale, Replay und Abbruch eigene Testfälle.

Pro Warteereignis wird genau ein Grund nach der folgenden Präzedenz gewählt; die erste passende Zeile gewinnt:

| Ereignisgrenze | Exklusiver wait_reason |
|---|---|
| aktives Security-Gate ohne gültiges `clear`, einschließlich Provider-, Schema- oder Sandboxfehler | `security_gate` |
| Arbeitsbranch-Push beziehungsweise report-only Abschluss bestätigt, Ticketstatus-CAS noch nicht bestätigt | `status_sync` |
| manueller Push vor seiner Autorisierung | `manual_push` |
| report-only Abschluss eines Review-only-Laufs vor seiner Bestätigung | `manual_report` |
| offenes deklariertes `MG-`-/`EXT-`-Gate vor Candidate | `manual_gate` |
| überschrittenes Review-/Fixrundenlimit oder Stall-Grenze | `review_limit` |
| anderes überschrittenes freigegebenes Ressourcenlimit, einschließlich Instruction-/Promptinputlimit und `max_added_scope_paths` | `resource_limit` |
| materielle Ticketvertragsänderung innerhalb aller verbleibenden Ressourcenmaxima oder angeforderte Instruction-/Runtime-Profiländerung | `contract_change` |
| Entscheidung über zusätzliche Scope-Pfade innerhalb des verbleibenden Pfadlimits und der Serverpolicy | `scope_approval` |
| unerwarteter externer Basisdrift | `git_base_changed` |
| Git-CAS-Konflikt außerhalb der post-Push-Statussynchronisation | `git_conflict` |
| schemaungültiges Agentenergebnis außerhalb des Security-Gates | `invalid_json` |
| Providerfehler außerhalb des Security-Gates | `provider_error` |
| fehlgeschlagener Projektcheck | `check_failure` |
| sonstige strukturierte Agenten- oder Systemfrage | `human_question` |

Diese Tabellen sind der Zielvertrag, keine Erlaubnis für vorgezogene APIs. AI6-013 führt Enum, Transitionen und den zentralen erweiterbaren Registervertrag ein, ohne zukünftige Producer vorzutäuschen. Das jeweilige Verbraucherticket registriert einen produktiven Producer nur gemeinsam mit mindestens einem Resolver oder expliziten Cancelpfad. AI6-026 vervollständigt die einheitliche Interventions-UI für alle bis dahin vorhandenen Gründe; AI6-039 erweitert sie um `manual_report` und den report-only `status_sync`-Fall, AI6-027 um `manual_gate`, AI6-028 um `security_gate` und AI6-029 um `manual_push` sowie den Post-Push-`status_sync`-Fall.

Ein Abbruch ist vor bestätigtem Branch-Push eine Git-/DB-Saga, kein lokales Umschalten des Runzustands. Soft-Cancel schreibt das Ticket per aktuellem Control-Head-CAS von `in_progress` nach `todo` und erzwingt vor einem neuen Start eine neue Approval. Eine ausdrücklich durch einen Approver mit Step-up und Begründung autorisierte fachliche Blockierung schreibt `in_progress → blocked`; Hard-Cancel schreibt unter derselben starken Autorisierung nach `cancelled`. In allen drei Fällen wird erst nach bestätigtem eigenen Status-CAS `runs.state=cancelled` terminal und die Projektsperre freigegeben; der Ticketstatus bewahrt die fachliche Unterscheidung. Ein CAS-Konflikt wechselt nach `git_conflict`, bewahrt Run und Sperre und erlaubt nur Refresh plus erneut autorisierte Entscheidung. Nach bestätigtem Branch-Push ist generischer Abbruch verboten: `status_sync` muss den bereits veröffentlichten Zustand per Retry oder autorisierter Konfliktentscheidung abschließen und darf den Push nicht zurückrollen.

---

## 8. End-to-End-Workflow

```text
1. App erzeugt eine typisierte Fetch-/Refresh-Control-Operation; Worker fetcht, validiert Ticket/Graph und publiziert blobgebundene Read Models
2. Mensch prüft das an `reviewed_ticket_blob_sha`/`reviewed_control_sha` gebundene Todo-Ticket, Modelle, Limits und Approval-Snapshot; die Git-/DB-Saga weist einen reinen `todo → ready`-Status-CAS nach und bindet dessen Ergebnis separat als `approved_ticket_blob_sha`/`approved_control_sha`, danach darf der Eintrag auch mit noch unerfüllten Dependencies in die Queue
3. Vor jedem manuellen oder automatischen Start Eligibility auf einem frischen `claim_parent_control_sha` neu prüfen: freigegebener Blob/Contract/Config/Prompt/Profile/Policy, Dependencies, Queuezustand und Projektlock; dann ready → in_progress per Control-Branch-CAS gegen diesen Parent, `run_base_sha` auf dessen Ergebnis setzen und Projekt sperren
4. Branch + Worktree ausschließlich aus `initial_run_base_sha`; beim Start ist er identisch mit `run_base_sha`, spätere Contract Amendments verändern nur `run_base_sha`
5. Implementierungsagent in neuer Session
6. Human Request bei Frage/Freigabe
7. tatsächlichen Diff und adaptiven Scope prüfen
8. before_review-Checks
9. unveränderlichen Checkpoint erzeugen
10. alle Reviewer prüfen denselben Checkpoint unabhängig
11. Findings:
      must_fix → Implementierungsagent → neuer Scope/Check/Checkpoint → alle Reviewer erneut
      human_required → Human Request
      suggestion/follow_up → nicht blockierend
12. Reviewlimits/Stall → Human Request/Intervention
13. Finalchecks und Gate-Prädikat; offene MG-/EXT-Gates erzeugen `manual_gate`
14. Publish-Kandidat aus Code, wirksam disponierten Findings und unverändertem in_progress-Ticket, noch ohne finalen Commit
15. deterministische Provenienz-/Secret-/Tree-Prüfung
16. optionaler read-only LLM-Sicherheitsreview
17. Bei geändertem Candidate finalen Commit mit exakt derselben Tree-OID und dem aktuellen `run_base_sha` als einzigem Parent erzeugen; bei bestätigtem `no_change_required` keinen Commit erzeugen und den Run-Branchref exakt auf `run_base_sha` setzen
18. Arbeitsbranch beziehungsweise No-change-Branchref manuell oder automatic_after_gates mit erwarteter Remote-OID pushen
19. Erst nach bestätigtem Branchref Control-Branch per CAS in_progress → review synchronisieren; bis dahin Projekt gesperrt lassen
20. Run abschließen und optional nächstes bereits freigegebenes, abhängigkeitserfülltes Queue-Ticket mit neuen Sessions starten
```

### 8.1 Multi-Reviewer-Regel

Der MVP verwendet nur eine Strategie: **alle ausgewählten Reviewer prüfen nach jeder Änderung den vollständigen neuen Checkpoint**. Reviewer laufen zunächst seriell. Ein Finding wird nie durch eine Stimmenmehrheit verworfen.

Originale Reviewergebnisse bleiben unverändert. Für das Candidate-Gate zählt jedoch der separat auditierte effektive Findingzustand: vollständige AC-Coverage jedes erforderlichen Slots plus kein offenes `must_fix`/`human_required`. Ein rollen- und Step-up-autorisierter, an Checkpoint, Finding, Begründung und `run.version` gebundener Override kann ein Finding effektiv `not_applicable` oder `accepted_risk` setzen, ohne das Originalresultat umzuschreiben. Jede Code-, Scope-, Vertrags-, Prompt-, Policy-, Reviewer-, Profil- oder Checkpointänderung invalidiert diese Disposition.

### 8.2 Adaptive Scope-Regel

```text
initial_scope       Ticket.files bei Freigabe — erste Vermutung nach TKT-007
effective_scope     initial_scope + policygebunden und menschlich aufgenommene Erweiterungen
actual_changed      tatsächlicher Git-Diff
recorded_scope      nach Runabschluss in die Ticketdatei zurückgeschriebene Dokumentation (TKT-012)
```

Vor Review gilt `actual_changed ⊆ effective_scope`. `initial_scope` ist nach `TKT-007` die beste Vermutung zum Freigabezeitpunkt und keine abschließende Liste; eine notwendige Erweiterung ist der Regelfall und kein Fehler. Der Server entscheidet jeden zusätzlichen exakten Pfad ausschließlich aus vertrauenswürdiger Projektkonfiguration und den serverseitig festgelegten sensiblen Kategorien:

- Ein Pfad unter `scope.auto_allow` wird ohne Rückfrage aufgenommen.
- Ein Pfad einer sensiblen Kategorie — `scope.require_approval` sowie Instruktionsdateien, Ticketdateien, Migrationen, Abhängigkeitsdateien, CI-, Deploy- und Authpfade und jede Löschung — erzeugt immer einen Human Request und wird niemals automatisch aufgenommen.
- Jeder übrige Pfad folgt der vertrauenswürdigen Projektvorgabe `scope.unlisted_paths`: bei `auto_allow` als Vorgabe wird er ohne Rückfrage aufgenommen, bei `require_approval` erzeugt er einen Human Request.

Jede Aufnahme — automatisch wie menschlich entschieden — zählt gegen dasselbe freigegebene `max_added_scope_paths` unter unveränderlichem Servermaximum, trägt einen benannten Entscheidungsgrund und erscheint im Reviewpaket nach §12.4. Eine automatisch aufgenommene Erweiterung blockiert damit weder Umsetzung noch Review; ein erschöpftes Pfadlimit bleibt `resource_limit`. Ticketinhalt, Projektinhalt, Dateiname und Providertext bestimmen niemals eine Kategorie, ein Limit oder eine Vorgabe.

Nach Runabschluss schreibt AI6 den tatsächlich wirksamen Scope nach `TKT-012` in die Ticketdatei zurück. Das geschieht ausschließlich im bereits vorhandenen Post-Push-Status-CAS aus `AI6-029`, gemeinsam mit der autorisierten Statusänderung und in demselben Commit; jeder andere Treeeintrag bleibt bytegleich. Der Abschnitt `## Recorded Scope` ist AI6-eigen, geht nicht in `ticket_contract_sha256` ein, ist kein Contract Amendment, invalidiert keine Reviewevidenz und wird niemals von einem Agenten geschrieben.

Materielle Änderungen an Ziel, Akzeptanzkriterien oder Verträgen erzeugen eine neue Ticket-/Approval-Revision und invalidieren alle alten Reviewresultate.

Ein Contract Amendment ist eine eigene Git-/DB-Saga und darf innerhalb desselben Runs ausschließlich die Ticketdatei ändern. Der Worker schreibt den autorisierten Ticketpatch mit dem aktuell persistierten `run_base_sha` als erwartetem Control-OID per CAS auf den Control-Branch und behält `in_progress` bei; das ursprüngliche `approved_control_sha` bleibt als unveränderliche Freigabeprovenienz erhalten. Der resultierende Commit wird als neuer `run_base_sha` registriert; ausschließlich dieser Ticketpatch wird anschließend in den Run-Branch übernommen. AI6 verifiziert, dass jeder andere Treeeintrag, insbesondere Instruktionsdateien und Providerkonfiguration, unverändert blieb, erzeugt eine gebundene Amendment-/Approval-Revision sowie neue Ticket-/Prompt-/Scope-/Policybindungen, invalidiert Checkpoints, Reviews, Gate-Evidenz und Finding-Dispositionen und setzt den Run vor Scope/Checks fort. Die bestehende Run-Branch-Historie wird dabei nicht still rebased; AI6-027 rekonstruiert den späteren Candidate deterministisch aus dem erneut geprüften effektiven Code-Diff auf dem neuesten `run_base_sha`, und AI6-029 verwendet genau diesen SHA als einzigen Commit-Parent. Jeder unerwartete zusätzliche Control- oder Run-Branch-Diff ist `git_base_changed`; automatisches Rebase fremder Änderungen ist verboten.

Eine Änderung an Instruktionsblob/-hierarchie oder versiegeltem Provider-Runtime-Profil ist ausdrücklich kein zulässiger Same-run-Ticketpatch. Bei einer angeforderten Änderung pausiert der Orchestrator mit `contract_change`, verwirft alle Provider-Sessions und erlaubt nur die kontrollierte Rücksetzung `in_progress → todo` oder Cancel; der neue Snapshot wird erst in einer neuen Approval-Saga und einem neuen Run wirksam. Unerwarteter externer Instruction-/Runtime-Drift bleibt `git_base_changed`. Weder der alte Run-Branch noch bereits erzeugte Diffs werden in den neuen Run übernommen.

Ein eigenes, bereits vor Runstart ausdrücklich freigegebenes Instruction-Update-Ticket ist davon getrennt: Der zu ändernde Instruktionspfad muss im `initial_scope` stehen und wird im aktuellen Run niemals als wirksame Instruktion neu geladen. Weil der native Discoverypfad die alte freigegebene Snapshotversion read-only überlagert, liefert der Implementierungsagent die prospektive Änderung über einen strukturierten Instruction-Patch-Kanal; erst der Worker validiert Pfad, alten Blob, Patch und Scope und importiert sie nach Prozessende in den Run-Worktree. Reviewer erhalten die neue Fassung als untrusted Diff-/Dateievidenz, während ihre native Discovery ebenfalls am alten Snapshot bleibt. Die Änderung wird erst nach Veröffentlichung und einer späteren neuen Approval-/Run-Lineage wirksam. Instruktionspfade dürfen nie per Same-run-Scopeerweiterung oder gewöhnlichem Contract Amendment hinzukommen.

### 8.3 Human Requests

Ein Request enthält typisierte Frage, Antwortmodus, Optionen, Empfehlung, betroffene Pfade und serverseitige Bindungen. Human-Request-Mails gehen an die verifizierte E-Mail des verantwortlichen `attention_user`; die globale `AI6_LOGIN_CONFIRMATION_EMAIL` wird ausschließlich für neue Login-Challenges verwendet. Die E-Mail ist nur Hinweis. Die Wirkung entsteht ausschließlich nach Weblogin und Policyprüfung im Panel.

### 8.4 Reviewlimit und feststeckende Runs

Das Panel bietet ausschließlich zustandsabhängige Aktionen: Zusatzprompt, eine zusätzliche Runde, Reviewer-/Modellwechsel, Scope-/Vertragsentscheidung, Finding-Disposition, Soft-/Hard-Cancel und kontrollierter Ticketstatuswechsel. Es gibt keinen freien DB- oder State-Editor.

### 8.5 Manuelle und externe Gates

Jede im Detailticket deklarierte `MG-`-/`EXT-`-ID ist standardmäßig blockierend. `None.` bedeutet, dass keine solchen Gates existieren; rein informative Hinweise gehören unter `## Notes`. Offene Gates dürfen Qualitätsreview nicht vortäuschen oder als grün erscheinen, blockieren aber spätestens Candidate, finalen Commit und Push. Ein Approver schließt ein Gate ausschließlich im Panel mit typisierter Evidenz; externe Evidenz enthält Quelle, Zeitpunkt und gegebenenfalls Digest/Referenz, niemals eine bloße Agentenbehauptung. Gate-Evidenz ist an Ticketvertrag, Run, relevanten Checkpoint/Candidate und Wirkung gebunden und wird nach Vertrags-, Scope- oder Candidateänderung neu bewertet.

### 8.6 Review-only-Modus

Der Review-only-Modus verwendet denselben Check-, Review-, Finding-, Gate- und HumanLoop-Unterbau wie der Implementierungsworkflow, verändert aber weder Code noch verwaltete Refs und pusht nicht (`RUN-010`). Er bleibt ticketzentriert: Ein Lauf benötigt ein verwaltetes Projekt, einen gültigen Ticketvertrag, einen menschlich freigegebenen Approval-Snapshot und einen darin gebundenen Reviewgegenstand nach `GIT-011`. Eine freie „reviewe dieses Verzeichnis“-Operation außerhalb der Approval-/Run-Grenze ist kein AI6-Lauf.

Ablauf: Die Approval bindet zusätzlich zum Ticket den Reviewgegenstand, die Reviewer-/Verifierslots samt Promptprofilen und den Abschlussmodus (`manual` oder `automatic_after_gates`, sinngemäß für den report-only Abschluss; Risikoregeln dürfen nur auf `manual` verengen). Der Claim ist derselbe atomare `ready → in_progress`-Status-CAS wie beim Implementierungslauf. Der Worker normalisiert die gebundene Quelle in einen wegwerfbaren, gitmetadatenfreien Review-Checkpoint, führt die freigegebenen deterministischen Checks aus und lässt anschließend alle ausgewählten Reviewer seriell denselben unveränderlichen Checkpoint prüfen; Findings, AC-Abdeckung, exakte Duplikatgruppen, advisory Verifikation, Human Requests und Limits folgen unverändert den bestehenden Verträgen. Die Phasen bleiben die vorhandenen Runphasen (`prepare` umfasst die Quellnormalisierung; `implement`, `fix`, `security_review` und `publish` entfallen).

Der Abschluss erzeugt einen gebundenen Abschlussbericht als redigiertes `run_artifact` — Run, Checkpoint-Tree-OID, Diff-Hash, Slots, Checkstatus, AC-Abdeckung, Findings samt wirksamer Dispositionen, Human-/Gate-Entscheidungen und Artefaktverweise — und synchronisiert den Ticketstatus über die report-only Abschluss-Saga `in_progress → ready`. Wirksam blockierende Findings verhindern den Abschluss nicht; sie sind Ergebnis des Berichts. Der Bericht ist eine aus Git- und SQLite-Autoritäten ableitbare Projektion und begründet keine eigene Status- oder Ticketautorität; in die Ticketdatei wird kein Ausführungsledger geschrieben. Ein Fix aus Review-only-Ergebnissen erfordert einen neuen, eigenständig freigegebenen Implementierungs-/Fixlauf.

### 8.7 Quellenabhängige advisory Verifikation

Nach Schema-Validierung und exakter Duplikatgruppierung kann der Orchestrator Findings einzeln oder als exakte Duplikatgruppe an einen Verifierslot übergeben (`REV-010`). Der Slot wird quellenabhängig aus den im Approval-Snapshot erlaubten Profilen gewählt: In der ersten Providerstufe verifiziert standardmäßig Grok die Copilot- und zulässigen Codex-Findings; Grok-Findings gehen an einen unabhängigen Copilot- beziehungsweise zulässigen Codex-Slot oder an den HumanLoop. Ist kein unabhängiges Profil verfügbar, entsteht ein Human Request statt einer Selbstverifikation. Jeder Verifierlauf erhält eine neue Session und ein eigenes Kontextpaket (Finding, Quellen, Evidenz, relevanter Code, betroffene Akzeptanzkriterien, passende Checkausgaben) und liefert ein eigenes unveränderliches advisory Reviewresultat. Es gibt keine freie Modell-zu-Modell-Debatte und keine Mehrheitsentscheidung; Widerspruch oder `inconclusive` eskalieren nach der zentralen Human-/Security-Policy, ein Providerfehler folgt dem bestehenden `provider_error`-Vertrag (begrenzter Retry, allowlisteter Profilwechsel mit neuer Slotrevision, Human Request oder Cancel), und erschöpfte Verifikationsrunden führen in den `review_limit`-Wartestatus.

### 8.8 Stufengerechte Kontextpakete und Implementierungszusammenfassung

Jede Stufe erhält nur den Kontext, den sie benötigt (`REV-011`): Implementierung, Erst-Review je Promptprofil, Verifikation, Fixturn und ein optionaler finaler Review verwenden getrennt zusammengestellte, redigierte und hashgebundene Kontextpakete statt des Gesamtrepositorys oder vollständiger Rohdiskussionen. Reviewer erhalten dabei stets den exportierten, gitmetadatenfreien Tree; das Kontextpaket steuert die Prompteingaben, nicht die Sicherheitsgrenze. Der Implementierungsturn liefert zusätzlich eine strukturierte Implementierungszusammenfassung (geänderte Komponenten, Entscheidungen, Annahmen, Abweichungen vom Ticket, bekannte Grenzen, Tests, besonders reviewbedürftige Bereiche) als Run-Artefakt; Reviewer behandeln sie als untrusted Kontext des Implementierers, prüfen sie gegen Ticket und Code und suchen unerwähnte Seiteneffekte weiterhin selbst. Ein Fixturn erhält ausschließlich wirksam blockierende beziehungsweise ausdrücklich für den Fix autorisierte Findings; verworfenes Feedback wird nicht versehentlich umgesetzt.

### 8.9 Optionaler finaler Review

Ein optionaler finaler Review ist kein eigener Modelltyp und kein zweites Security-Gate, sondern ein zusätzlicher regulärer Reviewer-Slot aus einem serverseitig freigegebenen Providerprofil mit neuer Session, der den finalen Gesamtdiff und die Spezifikation auf dem letzten Checkpoint prüft — ohne die vollständigen Rohdebatten früherer Slots. Er wird nur bei Risiko-, Release- oder Human-Triggern zugeschaltet und unterliegt denselben Coverage-, Finding- und Checkpointverträgen wie jeder andere Reviewer. Ein Codex-Finalreview ist nur zulässig, wenn Codex den zu prüfenden Stand weder implementiert noch gefixt hat. Die Aktivierung bleibt bis nach dem realen Pilot deaktiviert (§18, §19); ein fest verdrahteter Modellname wird dafür nicht vergeben.

---

## 9. Strukturierte Agentenverträge

### 9.1 Gemeinsame Hülle

Jeder Agentenaufruf verwendet drei getrennte, gemeinsam freigegebene Eingabebindungen:

- `prompt_snapshot_hash` bindet den zentral gerenderten Rollenprompt.
- `instruction_snapshot_hash` bindet eine kanonische geordnete Liste aus relativem Instruktionspfad, Git-Blob-SHA, Geltungsbereich, Provider-Auflösungsrang und SHA-256 des effektiven Inhalts.
- `provider_runtime_profile_hash` bindet sämtliche wirksamen Adapterflags, Permissions und serverseitig erlaubten provider-nativen Erweiterungen.

Welche Namen und Hierarchien ein Provider nativ entdeckt, stammt aus dem vertrauenswürdigen Adapter-/Capability-Profil. Der Worker löst diese Pfade am Approval-Control-Commit auf, verwirft Symlinks und Pfade außerhalb des Projektroots und erzeugt einen Snapshot. Providerprozesse starten in einer isolierten Wurzel und mit einem neuen versiegelten Home ohne Instruktions- oder Konfigurationsdateien aus Host-Elternverzeichnissen. An nativen Discovery-Pfaden sehen sie read-only ausschließlich die Snapshotbytes. Bei einem ausdrücklich freigegebenen Instruction-Update-Ticket bleibt diese alte Version wirksam und read-only; die prospektive Dateiänderung läuft ausschließlich über den strukturierten Patchkanal aus §8.2. Projektinhalt kann Reihenfolge, Geltungsbereich oder Instruktionspriorität nicht selbst erhöhen.

Das mit dem AgentProfile freigegebene `provider_runtime_profile_hash` bindet sämtliche wirksamen Adapterflags, Permissions und provider-native Erweiterungen. Workspace-/Home-Konfiguration, MCP-Server, Plugins, Skills, Hooks, Commands, Agentdefinitionen und sonstige Autoload-/Helpermechanismen sind standardmäßig deaktiviert. Eine ausnahmsweise aktivierte Erweiterung stammt ausschließlich aus einem versiegelten serverseitigen Allowlistprofil, wird außerhalb des Projektbaums read-only eingebunden und ist in diesem Hash vollständig enthalten; weder Repositorydateien noch Provideroutput können sie hinzufügen oder umkonfigurieren. Vor jedem Turn werden Pfad-/Blob-/Instruction-/Runtime-Profilbindung erneut geprüft. Eine angeforderte Änderung pausiert mit `contract_change`, invalidiert nachgelagerte Ergebnisse und erzwingt die kontrollierte Rücksetzung auf `todo`; sie darf erst nach neuer Approval in einem neuen Run mit neuen Provider-Sessions wirken. Unerwarteter externer Drift fällt als `git_base_changed` geschlossen aus. Resume einer unter alten Instruktionen oder einem alten Runtime-Profil gestarteten Session ist verboten. Kann ein Adapter zusätzliche native Discovery oder Helperstarts nicht technisch verhindern, fällt der Start geschlossen aus.

```json
{
  "schema_version": "ai6.agent.v1",
  "status": "completed",
  "summary": "Ticket umgesetzt.",
  "human_request": null
}
```

Zulässige Top-Level-Statuswerte hängen von der Rolle ab, mindestens:

```text
completed
no_change_required
needs_human
failed
nothing_to_fix
findings_to_fix
inconclusive
clear
security_findings
```

### 9.2 Human Request

```json
{
  "kind": "scope_extension",
  "title": "Zusätzliche Testhilfe erforderlich",
  "message": "Darf tests/Support/Contrast.php geändert werden?",
  "why_needed": "Die vorhandene zentrale Testnaht liegt dort.",
  "response_mode": "approve_reject",
  "options": [
    {"key": "approve", "label": "Genehmigen"},
    {"key": "reject", "label": "Ablehnen"}
  ],
  "recommended_option": "approve",
  "affected_paths": ["tests/Support/Contrast.php"],
  "criterion_refs": ["AC-03"]
}
```

Der Server ergänzt Run-, Checkpoint-, Scope-, Session- und Wirkungshashes. Der Agent bestimmt niemals selbst Rolle, Step-up oder tatsächliche Berechtigung.

### 9.3 Quality Review

```json
{
  "schema_version": "ai6.quality-review.v1",
  "status": "findings_to_fix",
  "summary": "Ein blockierender Fehler.",
  "criterion_coverage": [
    {"criterion_id": "AC-01", "status": "satisfied", "evidence": "..."}
  ],
  "findings": [
    {
      "local_id": "R1-F1",
      "severity": "high",
      "disposition": "must_fix",
      "category": "correctness",
      "file": "app/Example.php",
      "line": 42,
      "title": "Fehlerhafter Zustand",
      "evidence": "...",
      "expected_result": "...",
      "criterion_refs": ["AC-01"]
    }
  ],
  "human_request": null
}
```

Ein Verifierlauf verwendet dieselbe versionierte Schemafamilie: Sein Resultat referenziert genau das geprüfte Originalfinding beziehungsweise dessen exakte Duplikatgruppe, liefert eigene Evidenz und eine Empfehlung und wird als weiteres unveränderliches Reviewresultat gespeichert. Eine Empfehlung „nicht zutreffend“ ist keine wirksame Disposition und kann ein fremdes `must_fix` nicht entblocken (`REV-010`).

### 9.4 Implementierungsergebnis

Das Implementierungsresultat enthält mindestens Entscheidungen, gemeldete Pfade, offene manuelle Gates, die strukturierte Implementierungszusammenfassung aus §8.8 und optional einen Human Request. `no_change_required` ist ausschließlich für einen Implementierungs- oder Fixturn zulässig, der einen leeren tatsächlichen Diff und eine konkrete Begründung meldet. Der Server bestätigt den leeren Diff selbst; der Status überspringt weder Scopeabgleich, Pflichtchecks, AC-Abdeckung, Reviews noch manuelle oder Security-Gates. Ein nicht leerer Diff mit diesem Status ist ein Schema-/Importfehler. Reviewer können zusätzlich `instruction_recommendations` für wiederverwendbare Regeln melden; diese ändern niemals automatisch `AGENTS.md`. AI6 vertraut niemals den gemeldeten Pfaden ohne Git-Diff-Prüfung.

Nur im vor Runstart freigegebenen Modus `instruction_update` darf ein Implementierungsresultat genau ein optionales `instruction_patch`-Objekt enthalten:

```json
{
  "schema_version": "ai6.instruction-patch.v1",
  "path": "AGENTS.md",
  "expected_blob_sha": "0123456789abcdef0123456789abcdef01234567",
  "format": "utf8_file_replacement_v1",
  "content_base64": "IyBBR0VOVFMubWQK",
  "content_length": 12,
  "content_sha256": "bc7f4408a2f9386558ff4bbb3e43856b516cb873c9df4c40eecd9c8e8f7c4e1b"
}
```

`path` ist genau ein kanonischer, bereits im `initial_scope` als Instruction-Update freigegebener POSIX-Pfad. `expected_blob_sha` ist der am Runstart gebundene alte Git-Blob-SHA; für einen ausdrücklich als neu genehmigten Pfad ist ausschließlich `null` zulässig und der Worker prüft die Abwesenheit. `content_base64` verwendet kanonisches RFC-4648-Base64 ohne Whitespace und dekodiert zu UTF-8 ohne BOM; `content_length` zählt die dekodierten Bytes, `content_sha256` ist deren kleingeschriebener SHA-256. Andere Formate, mehrere Ziele, Diffs gegen einen anderen Blob, Symlinks oder das Feld außerhalb des freigegebenen Modus sind Schemafehler ohne Wirkung. Die gesamte JSON-Ausgabe und die dekodierte Datei zählen vor jedem Import gegen Provideroutput-, Changed-Byte-, Datei-, Instruktions-Einzel-/Gesamtbyte- und Artefaktlimits. Erst nach vollständiger Schema-, Hash-, Blob-, Pfad-, Scope-, Limit- und Securityprüfung importiert der Worker die eine Ersatzdatei atomar; es gibt keinen partiellen Schreibstand.

### 9.5 Security Review

Der Security-Reviewer liefert `clear`, `security_findings`, `needs_human` oder `inconclusive` und bindet sein Ergebnis an Candidate-Tree, Diff, Base, Ticketvertrag, Scope, Prompt, Profil und SecurityPolicy. Bei aktiver Kontrolle erlaubt nur ein gültiges `clear` oder ein auditierter Human Override die Fortsetzung.

---

## 10. Sicherheitsmodell

### 10.1 Zugangsmodell

Empfohlener Serverbetrieb:

```text
privates VPN + HTTPS + Webauthentifizierung
```

Ein eingeschränkter SSH-Tunnel bleibt Fallback. Persönliche SSH-Schlüssel dienen Server-/Netzzugang und Git; sie werden nicht als Browserpasswort hochgeladen. Webauthentifizierung verwendet standardmäßig Passkey/TOTP sowie die zusätzliche Login-E-Mail-Codebarriere.

### 10.2 Abschaltbare und nicht abschaltbare Kontrollen

Abschaltbar über `custom`/Env, standardmäßig aktiv:

- Login-E-Mail-Bestätigung;
- privilegierte Passkey-/TOTP-/Step-up-Pflicht;
- HTTPS/private-access enforcement in ausdrücklich erlaubten lokalen Profilen, geschaltet über `AI6_SECURITY_REQUIRE_HTTPS_OR_PRIVATE_ACCESS`;
- Agentensandbox und Checker-Netzwerksperre;
- LLM-Precommit-Securityreview.

**Private Access** ist dabei abschließend definiert und nicht auslegbar. Ein Request gilt genau dann als Private Access, wenn eine der beiden folgenden Bedingungen zutrifft: Entweder ist die unmittelbare Gegenstelle der Verbindung selbst eine Loopbackadresse — ausschließlich `127.0.0.0/8` oder `::1` —, oder die unmittelbare Gegenstelle ist ein konfigurierter Trusted Proxy **und** die aus dessen Weiterleitungsangaben aufgelöste Clientadresse ist eine solche Loopbackadresse. Jede andere Quelle ist kein Private Access, insbesondere ein Trusted Proxy mit nicht-Loopback-Client. Sonstige private Adressbereiche — etwa `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `fc00::/7` oder Link-Local-Adressen — zählen ausdrücklich **nicht**; ein Vertrag, der sie einschließt, hebt die Maßnahme faktisch auf. Ist die unmittelbare Gegenstelle kein Trusted Proxy, wird keine Weiterleitungsangabe ausgewertet und allein die unmittelbare Gegenstelle entscheidet. Die Netzwerkseite dieser Zusage liefert §4.1: Ausschließlich der Reverse Proxy veröffentlicht einen an eine Loopbackadresse gebundenen Port, und die Anwendungsrolle veröffentlicht keinen.

Für die HTTPS-/Private-Access-Maßnahme gilt normativ: Bei aktiver Maßnahme wird eine Anfrage ausschließlich über HTTPS oder über einen Private-Access-Pfad im Sinne dieser Definition bedient; jede andere unverschlüsselte Anfrage wird vor Anwendungslogik abgelehnt, und das Sessioncookie trägt `Secure`. Bei reduzierter Maßnahme wird eine unverschlüsselte Anfrage ausschließlich über einen Private-Access-Pfad bedient und außerhalb davon weiterhin abgelehnt — die Reduktion erlaubt lokales HTTP und nicht HTTP allgemein —, und `Secure` entfällt genau für eine solche Klartextanfrage über Private Access. Maßgeblich ist dabei ausschließlich der Zustand **dieser** Maßnahme: Ein Profil, das andere Maßnahmen reduziert, sie aber aktiv lässt, verhält sich hier unverändert wie `strict`. `HttpOnly` und die konfigurierte `SameSite`-Einstellung sind keine Maßnahmen und bleiben in jedem Profil unverändert. Die Herkunft des Schemas ist ebenfalls nicht reduzierbar: Sie wird ausschließlich über die konfigurierten vertrauenswürdigen Proxys aufgelöst und nie aus einem Weiterleitungsheader untrusted Herkunft abgeleitet.

Das Profil `development` deaktiviert genau diese eine Maßnahme und keine weitere; jede andere abschaltbare Maßnahme bleibt dort aktiv. Es ist damit das „ausdrücklich erlaubte lokale Profil" dieses Aufzählungspunktes. Die Reduktion verlangt dasselbe Acknowledgement wie `custom` (`SEC-001`) und bleibt in Doctor und Banner sichtbar.

Nicht abschaltbar:

- Rollen-/Projekt-Policies und CSRF;
- Pfad-, Ref-, Scope-, JSON- und Hostvalidierung;
- keine Shellstrings aus untrusted Input;
- Credentialtrennung;
- Human-Request-Anti-Replay;
- sichere Ausgabe/Downloads und Secret-Redaction;
- gehärtete Git-SSH-/Remote-Kontrollen einschließlich Schlüsseltrennung, Hostpinning, Protokoll-/Ref-Allowlist, isolierter Git-Config/Home und deaktivierter Hooks, Credentialhelper, Submodule, externer Filter/Diff-/Pager-/fsmonitor-/Signing-Helfer;
- gitmetadatenfreie exportierte Provider-Tree-Sicht, gebundene read-only Instruction-Autodiscovery ohne Host-/Parent-Quellen und versiegelte Provider-Runtime ohne unfreigegebene Workspace-/Home-Konfiguration, MCP, Plugins, Skills, Hooks, Commands oder Helper;
- deterministischer Secret-/Provenienz-Preflight vor Security-LLM und Push;
- Bindung von Review, Candidate, Commit und Push.

### 10.3 E-Mail-Bestätigung neuer Logins

Nach erfolgreicher Primärauthentifizierung bleibt die Session im Pre-Auth-Zustand. Ein achtstelliger, kurzlebiger, einmaliger Code wird an `AI6_LOGIN_CONFIRMATION_EMAIL` gesendet und im selben Browser eingegeben. Kein Link löst eine Freigabe aus. Mailausfall bleibt bei aktiver Maßnahme fail closed. Die genaue TTL, Versuchslimits und Resend-Regeln sind zentrale Configwerte mit sicheren Defaults.

### 10.4 Agenten und Checks

Projektinhalt ist untrusted. Agent und Checker besitzen nur ihre exportierte Tree-Sicht ohne erreichbare Gitmetadaten und die minimal notwendige Prozessumgebung. Provider-, Git- und SMTP-Credentials werden getrennt. Codex-, Grok-, Copilot- und Claude-Home-, Config-, History- und Cachepfade werden pro Session versiegelt und getrennt; die jeweils dokumentierten nativen Discoverypfade werden entweder auf den gebundenen Instruction-Snapshot begrenzt oder fail closed blockiert (`AGT-009`, `AGT-010`). Native Provider-Instruktionsauflösung sieht ausschließlich den freigegebenen read-only Instruction-Snapshot; Host-/Parent-Autodiscovery und zur Laufzeit veränderte Instruktionsdateien sind ausgeschlossen. Das isolierte Provider-Home enthält nur die versiegelte serverseitige Runtime-Konfiguration und die kurzlebige minimale read-only Authprojektion des einen gewählten Providerprofils. Nicht mitfreigegebene Workspace-/Home-Configs, Caches, History, MCP-Server, Plugins, Skills, Hooks, Commands, Agentdefinitionen und externe Helper sind unerreichbar oder deaktiviert. Aktive Sandbox-, Workspace-, Instruction-, Credentialprojektions- und Runtime-Profil-Isolation fällt geschlossen aus. Projektkonfiguration kann keine schwächeren Flags einschleusen.

### 10.5 Optionaler Security-Reviewer

Der Security-Reviewer erhält eine neue Sitzung, einen read-only Candidate und keine Ausführungs-, Commit- oder Pushrechte. Er prüft unter anderem Backdoors, unerlaubte Konten/Rollen, Auth-Bypässe, Datenabfluss, RCE/Injection, verschleierte Payloads, Dependency-/CI-/Deploy-Manipulation und Prompt-Injection. Herkunft und Autorisierung von Git-Akteuren werden deterministisch, nicht durch das LLM entschieden.

### 10.6 Retention-Durchsetzung

Vertrauenswürdige Instanzconfig definiert getrennte maximale Aufbewahrungszeiten und Größenlimits für Runlogs, Agentenrohoutput, Checklogs und Artefakte. Jeder gespeicherte Datensatz besitzt zentral erzeugten projekt-/rungebundenen HMAC-Fingerprint samt Key-ID/Version, redigierte Metadaten, Größe, Erzeugungszeit und `expires_at`; ein unkeyed Inhaltsdigest wird nicht als langlebiger Ersatz für gelöschte Rohdaten bewahrt und Rohdaten werden nie allein als Auditnachweis benötigt. Der Scheduler löscht abgelaufene Rohdaten und Storageobjekte idempotent, schreibt einen redigierten Tombstone und macht spätere Downloads deterministisch unmöglich. Aktive Runs dürfen nur durch eine ebenfalls zentral begrenzte Active-Run-Frist aufschieben. Backup/Restore darf abgelaufene Rohdaten nicht wieder sichtbar machen; Doctor prüft Scheduler, Storage und Policy.

---

## 11. Weboberfläche

### 11.1 Seiten

- Login und E-Mail-Code-Challenge;
- Dashboard mit Projekten, aktiven Runs und offenen Aktionen;
- Projektliste/-detail inklusive Git-/Providerstatus;
- Ticketübersicht und Ticketdetail;
- Ticketeditor und Freigabe;
- Runseite mit Timeline, Diff, Sessions, Checks, Findings, Verifikation, Security und Push;
- Review-only-Start mit gebundenem Reviewgegenstand sowie gebundener Abschlussbericht;
- Attention-Inbox und Human-Request-Detail;
- Adminseiten für Benutzer, Projektrollen, Profile und Doctorstatus.

### 11.2 Mobile Anforderungen

- Kerninformationen ohne horizontales Scrollen;
- Aktionen mit klaren Folgen und Bestätigungsdialogen;
- große Diffs/Logs paginiert oder zusammengefasst;
- nach Reload oder Gerätewechsel konsistenter Serverzustand;
- Polling darf keine mutierende Aktion wiederholen.

---

## 12. Teststrategie und allgemeine Definition of Done

### 12.1 Testebenen

1. Unit-Tests für Parser, Validator, State-Machine, Policy, Schemas und Hashes.
2. Featuretests für Auth, Rollen, Ticket-, Approval-, Human- und UI-Aktionen.
3. Git-Integrationstests mit isolierten lokalen Remotes.
4. Process-/Mailbox-/Sandbox-Negativtests.
5. FakeAgent-End-to-End für alle Workflowzweige.
6. optionale echte Adapter-Smokes hinter explizitem Flag.
7. realer M169-Pilot als separates manuelles Gate.
8. konfigurierte statische Analyse; für AI6 verbindlich `vendor/bin/phpstan analyse`.

### 12.2 Definition of Done jedes Implementierungstickets

Ein Ticket ist nur reviewbereit, wenn:

- alle eigenen AC-/TC-IDs nachweisbar bearbeitet sind;
- gezielte und bestehende relevante Tests grün sind;
- neue Fachlogik eigene Tests besitzt;
- Formatierung, Syntax, die konfigurierte statische Analyse und `git diff --check` grün sind;
- keine unfreigegebene Scope-/Vertragsänderung offen ist; eine policygebunden aufgenommene und dokumentierte Scope-Erweiterung nach §8.2 gilt als freigegeben und blockiert nicht;
- keine Secrets oder Rohcredentials in Diff/Logs/Fixtures liegen;
- Dokumentation nur dort aktualisiert wurde, wo der Ticketvertrag dies verlangt;
- manuelle/externe Gates ehrlich als offen oder mit Evidenz dokumentiert sind;
- der Implementierungsagent weder Ticketstatus noch Run-Metadaten selbst verändert hat.

### 12.3 Definition of Ready eines Detailtickets

Ein aus einem Blueprint erzeugtes Ticket darf erst auf `ready` gesetzt werden, wenn:

- alle `depends_on`-IDs existieren, der Graph azyklisch ist und unerfüllte Dependencies als Startblocker sichtbar sind; sie verhindern `ready` oder Queueaufnahme nicht;
- für AI6-eigene Tickets die verwendete Planrevision aktuell ist; die Rolloutregel erzeugt spätere Meilensteine erst nach dem vorherigen Integrations-Gate, ohne `ready` mit Start-Eligibility gleichzusetzen;
- `## Goal`, `## Tasks`, `## Out of Scope` und die manuellen Gates ohne Chatverlauf verständlich sind;
- konkrete Dateien beziehungsweise eng begrenzte Verzeichnisse aus dem realen Repository abgeleitet wurden;
- jedes Akzeptanzkriterium mindestens einem automatisierten Testfall, einem manuellen Gate oder einer ausdrücklich externen Evidenz zugeordnet ist;
- keine offene Architekturentscheidung im Ticket versteckt ist;
- Risiko, sensitive Pfade und erwartete Review-Schwerpunkte erkennbar sind;
- der geschätzte Scope die Split-Regeln aus Abschnitt 13.2 nicht verletzt.

### 12.4 Verbindliches Reviewpaket je Ticket

Ein einzelner Review muss ohne Implementierungs-Chat möglich sein. AI6 stellt deshalb zusammen bereit:

- freigegebene Ticketrevision und referenzierte Requirements/Specs;
- Base- und Checkpoint-SHA sowie den vollständigen Diff;
- effektiven Scope einschließlich aller aufgenommenen Erweiterungen mit ihrem benannten Entscheidungsgrund;
- strukturierte Implementierungsentscheidungen und Human-Responses;
- Ergebnisse aller ticketbezogenen Checks;
- AC-/TC-Evidenz sowie Status und gebundene Evidenz jeder MG-/EXT-ID;
- unveränderte strukturierte Reviewergebnisse und Findinghistorie.

Fehlt ein Teil, der für die Bewertung notwendig ist, ist das Ticket nicht reviewbereit. Freie Chatverläufe, lokale Notizen oder das Gedächtnis eines Modells sind keine zulässige Anforderungsquelle.

---

## 13. Regeln für die Erzeugung detaillierter Tickets

### 13.1 Ein Ticket – ein Outcome

Ein Detailticket besitzt genau ein primäres, für Benutzer oder nachfolgende Entwickler beobachtbares Ergebnis. Tests gehören in dasselbe Ticket. Ein separates „Tests nachtragen“-Ticket ist nur bei einem projektweiten Harness zulässig.

### 13.2 Split-Trigger

Der Ticketgenerator stoppt und schlägt eine Planänderung vor, statt ein Riesenticket zu erzeugen, wenn voraussichtlich mindestens einer dieser Punkte zutrifft:

- mehr als zwei fachliche Module werden wesentlich verändert;
- mehr als eine unabhängige Datenmigration ist nötig;
- UI, Infrastruktur und Providerintegration bilden drei getrennte Outcomes;
- der erwartete nicht generierte Diff ist nicht mehr in einem Review sinnvoll erfassbar;
- ein Teil könnte unabhängig ausgeliefert, zurückgerollt und getestet werden;
- Datenmodell, Prozessintegration und umfangreiche UI bilden jeweils eigenständig prüfbare Änderungen;
- eine Voraussetzung ist noch nicht implementiert oder nur angenommen.

Die Schwellen sind Leitplanken, keine Lizenz für mechanische Kleinsttickets.

Der Punkt „unabhängig auslieferbar, zurückrollbar und getestet" zielt auf trennbare Outcomes über Modul- und Nahtgrenzen hinweg. Er greift **nicht** für eine Fähigkeit innerhalb genau einer technischen Naht, wenn ihre getrennte Lieferung ein zweites Ticket dieselbe Naht wesentlich verändern ließe oder ihr Vertrag nur als Ganzes prüfbar ist. Das ist keine Abschwächung, sondern die Auflösung eines Zirkels: Ein Mechanismus, den §4.3 genau einmal verlangt, kann nicht dadurch sauberer werden, dass zwei Tickets an derselben Datei arbeiten. Der blockierende Startmodus und der Effekt-Lock aus `AI6-006A` sind genau dieser Fall — sie sind Fähigkeiten der einen Prozessgrenze, ihr Beweiswert hängt an der `exec`-Bindung derselben Grenze, und ein eigener Blueprint hätte dort dieselben Dateien ein zweites Mal geöffnet. Ein Ticket, das sich auf diese Ausnahme stützt, hält den Grund in seinem `## Context` fest; die Ausnahme wird nie stillschweigend in Anspruch genommen und deckt weder ein zusätzliches Modul noch ein zweites Outcome.

Der letzte Punkt greift nicht, wenn die fehlende Voraussetzung ausschließlich aus bereits in Abschnitt 15 definierten `depends_on`-Blueprints besteht und die Vorabableitung nach §13.6 ausdrücklich angeordnet wurde. Eine angenommene Architektur, eine erfundene API oder eine Voraussetzung ohne eigenen Blueprint bleiben dagegen ein Abbruchgrund.

### 13.3 Progressive Elaboration

- IDs, Ziele, Meilensteine und Abhängigkeiten stammen unverändert aus Abschnitt 15.
- Detaillierte Tasks, `files`, konkrete Klassen und Tests werden erst aus dem aktuellen Repository abgeleitet.
- Pro Durchlauf wird vorzugsweise nur der nächste Meilenstein detailliert erzeugt.
- Nach jedem Meilenstein-Gate werden künftige Blueprints gegen den realen Stand geprüft.
- Ein Generator darf keine neue Architektur erfinden; echte Lücke wird als Entscheidungsfrage oder Planrevision markiert.

### 13.4 Pflichtinhalt eines Detailtickets

- Frontmatter mit mindestens Pflichtfeldern und `spec_refs`;
- `## Goal` in einem Satz plus überprüfbares Outcome;
- `## Context` mit vorhandenen Nähten;
- konkrete, begrenzte Aufgaben unter `## Tasks`;
- AC-IDs und TC-IDs;
- `## Initial Scope and Sensitive Paths`;
- `## Out of Scope`;
- `## AC Coverage` mit `| AC | Evidence |`;
- manuelle/externe Gates unter `## Manual and External Gates`;
- `## Review Focus`;
- Abhängigkeiten nur auf bereits definierte IDs;
- Hinweis unter `## Notes`, dass AI6 Ticketstatus/Runmetadaten verwaltet;
- kein `## Recorded Scope`: Dieser AI6-eigene Abschnitt entsteht erst nach Runabschluss nach `TKT-012` und wird vom Generator nie erzeugt.

### 13.5 Reviewbarkeit

- Keine Sammelaufgaben wie „Security vollständig implementieren“.
- Keine ACs wie „funktioniert korrekt“ ohne beobachtbares Verhalten.
- Keine reinen Dateilisten ohne fachliches Ziel.
- Keine versteckten Anforderungen nur in Hinweisen.
- Keine duplizierten normativen Verträge; `spec_refs` enthält ausschließlich Einträge im Format `docs/AI6_IMPLEMENTATION_PLAN.md — REQ-ID` für die Requirement-Refs des Blueprints. Hilfreiche genaue §-Verweise stehen bei Bedarf in deutscher Prosa unter `## Context` oder `## Notes`.
- Jeder Fehlerpfad, der den Run blockiert oder fortsetzt, benötigt mindestens einen Testfall.
- Jedes AC verweist auf mindestens einen TC, ein manuelles Gate oder eine externe Evidenz; verwaiste ACs sind ein Generatorfehler.
- Ein Detailticket muss in einem Reviewpaket ohne Kenntnis des Erstellungs- oder Implementierungs-Chats bewertbar sein.
- M169 ist bewusst ein anspruchsvoller Integrationspilot und kein Größenmaßstab für normale AI6-Tickets.

### 13.6 Vorab abgeleitete Tickets (`ahead-derived`)

Der Regelfall bleibt Abschnitt 13.3: Ein Detailticket wird erst erzeugt, wenn seine `depends_on`-Tickets umgesetzt sind, damit jeder Pfad, jede Klasse, jedes Kommando und jeder Test aus real vorhandenem Code stammt (`ADR-015`). Ein davon abweichend erzeugtes Ticket ist ein **vorab abgeleitetes Ticket** und trägt den Zustand `ahead-derived`.

Zulässig ist dieser Zustand ausschließlich unter allen folgenden Bedingungen:

1. Ein Mensch ordnet die Vorabableitung ausdrücklich an. Ein Generator wählt sie nie selbst.
2. Jede fehlende Voraussetzung ist ein bereits in Abschnitt 15 definierter Blueprint aus der eigenen `depends_on`-Liste. Fehlt eine Voraussetzung ohne Blueprint, gilt weiter der Antragszweig.
3. `## Context` benennt, welche Pfade noch nicht existieren und welches Abhängigkeitsticket sie erzeugt.
4. `## Notes` trägt die Rebase-Verpflichtung nach Punkt „Aufgeschobene Prüfungen“.
5. Die Bestandsübersicht `tickets/README.md` weist das Ticket als vorab abgeleitet aus.

**Aufgeschobene Prüfungen.** Ausschließlich die folgenden Prüfungen dürfen bis zum Rebase aufgeschoben werden; ihre Bezugsgröße ist dann die Runbasis des Tickets nach dem Landen seiner Abhängigkeiten statt des Erzeugungszeitpunkts:

- die Existenzprüfung eines mit `existing` markierten Pfades;
- die Verifikation einer als bestehend beschriebenen Naht, sofern sie ausschließlich mit Namen benannt wird, die dieser Plan selbst verwendet;
- die Ableitung konkreter Klassen-, Kommando- und Testnamen aus real vorhandenem Code.

**Nicht aufgeschoben** werden Blueprinttreue, Requirement-Refs, Abschnittsstruktur, ID-Vergabe, AC-Abdeckung, Sprachgrenze, Serialisierung, Split-Regeln und das Verbot, eine nicht vorhandene API als bestehend auszugeben oder Architektur zu erfinden. Die Split-Prüfung nach §13.2 wird also zum Erzeugungszeitpunkt entschieden; eine erneute Bewertung am Rebase-Gate ist zusätzlich zulässig, ersetzt die heutige Entscheidung aber nicht und darf nicht als Aufschub formuliert werden.

**Rebase-Gate.** Vor `status: ready` werden `files`, die Scope-Marker sowie alle genannten Pfade, Klassen, Kommandos und Tests gegen den dann realen Repositoryzustand neu abgeleitet und die aufgeschobenen Prüfungen vollständig nachgeholt. Erst danach gilt das Ticket als vollständig profilkonform. Ein `ahead-derived`-Ticket ohne durchgeführten Rebase darf weder freigegeben noch beansprucht werden; die Definition of Ready aus Abschnitt 12.3 gilt zusätzlich unverändert.

`ahead-derived` ist kein Frontmatter-Feld und kein Ticketstatus. Es ist eine Eigenschaft der Erzeugung, die in `## Context`, `## Notes` und der Bestandsübersicht sichtbar bleibt und mit dem Rebase endet.

### 13.7 Veröffentlichungszeitpunkt der ID-Stabilität

Dieser Plan bezeichnet Blueprint-IDs als stabil, und Template §7.1 verbietet nach der Veröffentlichung einer Ticketrevision jedes Umnummerieren und jede Wiederverwendung von `AC-`, `TC-`, `MG-` und `EXT-`, weil Review-JSON über `criterion_refs` daran gebunden ist. Beide Zusagen brauchen einen Zeitpunkt, ab dem sie greifen.

**Veröffentlicht** ist ein Blueprint oder eine Ticketrevision, sobald ihr eigener Stand als Commit in diesem Repository liegt. Maßgeblich ist der Commit des jeweiligen Artefakts, nicht das Alter des Repositorys: Ein Commit, der ausschließlich fremde Dateien enthält, veröffentlicht keinen Blueprint und keine Ticketrevision. Alles davor — auch eine vollständig ausgearbeitete, in einer Sitzung mehrfach überarbeitete Datei im Arbeitsbaum — ist ein **unveröffentlichter Entwurf**. Ein Entwurf darf beliebig umnummeriert, umbenannt, geteilt und neu geschnitten werden; genau davon haben die Revisionen bis einschließlich V1.6.10 Gebrauch gemacht, als der Read-Model-Blueprint von `AI6-006C` über `AI6-006D` und `AI6-006E` nach `AI6-006F` wanderte.

Der aktuelle Stand: Commit `1aeb20e` (`Planung`) hat diesen Plan, das Template und die ersten zwölf Ticketdateien erstmals veröffentlicht. Seitdem sind sämtliche Blueprint-IDs dieses Plans sowie alle in veröffentlichten Tickets vergebenen `AC-`-, `TC-`-, `MG-`- und `EXT-`-IDs unveränderlich; der Entwurfsspielraum der Revisionen bis V1.6.10 besteht für sie nicht mehr. Neue Blueprints erhalten ausschließlich die nächste noch nie verwendete ID.

Sobald der Stand eines Artefakts erstmals committed ist, gilt für dieses Artefakt ohne Ausnahme:

- Eine Blueprint-ID bezeichnet dauerhaft denselben fachlichen Vertrag. Wird ein Blueprint geteilt, behält der bestehende Teil seine ID und ausschließlich der neue Teil erhält die nächste noch nie verwendete ID.
- `AC-`, `TC-`, `MG-` und `EXT-` werden nie umnummeriert und nie wiederverwendet. Neue Einträge werden angehängt; eine autorisierte Entfernung hinterlässt eine Lücke (`REV-004`).
- Eine Umnummerierung ist auch dann unzulässig, wenn sie die Lesbarkeit verbessern würde.

Eine externe Bindung kann vor dem ersten Commit entstehen — etwa eine menschliche Reviewnotiz, die ein Kriterium bei seiner damaligen Nummer nennt. Sie begründet keine Stabilitätszusage, macht aber die Revisionshistorie zur Pflicht: Verschiebt ein Entwurfsschritt eine ID, benennt der zugehörige Revisionseintrag beziehungsweise `## Notes` die alte und die neue Bezeichnung, damit eine ältere Referenz auflösbar bleibt.

### 13.8 Versionsupgrades

Die in `OPS-008` freigegebenen PHP-, Laravel- und SQLite-Versionen sind ein geprüfter Vertrag, keine Empfehlung an einen Paketmanager, beim nächsten Lauf selbständig die neueste Version zu wählen. Lockfile, Containerimage und Betriebspakete halten die konkrete Auflösung reproduzierbar fest; die dokumentierte Versionslinie und die tatsächlich ausgeführte Version werden automatisiert geprüft.

Erscheint eine neuere Major-, Minor- oder Patchversion einer dieser drei Laufzeitkomponenten, entsteht für ihre Übernahme ein eigenes Upgrade-Ticket. Dieses Ticket wird gegen den realen Repositorystand abgeleitet, nennt alte und neue Auflösung, prüft Framework- und Erweiterungskompatibilität, aktualisiert Lock-, Image-, Dokumentations- und Versionsnachweise gemeinsam, entscheidet Migrationen ausdrücklich und beschreibt einen ausführbaren Rollback. Eine regelmäßige Versionsprüfung darf ein solches Ticket vorschlagen, aber weder Plan noch Lockfile noch Image automatisch verändern. Fach- und Sicherheitstickets dürfen eine Laufzeitversion nur ändern, wenn sie selbst ausdrücklich dieses Upgrade-Outcome tragen; ein beiläufiger Versionssprung ist ein Scopebefund.

---

## 14. Meilensteine und Integrations-Gates

| Meilenstein | Inhalt | Gate |
|---|---|---|
| M0 | Fundament und sichere Laufzeit | Das verifizierte immutable Scaffold, der committed Lockfile, PHPUnit, Pint, PHPStan und der deterministische Manifest-Driftcheck sind reproduzierbar grün; Auth- und Security-Grundpfad sind getestet. |
| M1 | Projekte und Tickets | Projektzugriffe laufen asynchron über Control Operations; blobgebundene Read Models können rekonstruiert werden, und Tickets werden profilgerecht gelesen, validiert, angezeigt und konfliktfest geändert. |
| M2 | Freigabe und Run-Fundament | Prompt-Snapshot, manuelle Prompt-Hilfe, Approval, Ready-/Queuevertrag, minimaler RunOrchestrator, `run_base_sha`, Worktree, Prozessgrenze und FakeAgent-Grundlagen stehen. |
| M3 | Human Loop und Implementierung | Fake-Implementierung kann fragen, pausieren, fortsetzen, Limits einhalten, Scope/Contract mit vollständiger Provenienz ändern und Checkpoint erzeugen. |
| M4 | Review und Fix | Mehrere Fake-Reviewer, unveränderliche Originalfindings, effektive Dispositionen, quellenabhängige advisory Verifikation und die vollständige Fix-/Re-Review-Schleife funktionieren; ein ticket- und approvalgebundener Review-only-Lauf endet ohne Push in einem gebundenen Abschlussbericht. |
| M5 | Finalisierung und Fake-E2E | Candidate, manuelle/externe Gates, Security-Gate, Commit, Push, Queue, alle Limits/Wartestatus und Recoverypfade funktionieren vollständig mit FakeAgent. |
| M6 | Echte Adapter | Codex, Grok-Build und GitHub Copilot sind erst nach dem vollständigen Fake-Workflow capability-geprüft und credentialgetrennt nutzbar; Claude bleibt eine spätere, die erste Providerstufe nicht blockierende Erweiterung. |
| M7 | Betrieb und Pilot | Fresh install, Restore, Retentionlöschung, Manifestprüfung, Migration und realer Pilot sind abgeschlossen; der Legacy-Leser ist danach abgeschaltet. |

### 14.1 Topologische Reihenfolge

```text
01. AI6-001 — Laravel-Grundgerüst und Qualitätsbaseline
02. AI6-002 — Docker-Compose-Laufzeit, SQLite-Queue und Scheduler
03. AI6-003 — Typed Config und zentrale SecurityPolicy
04. AI6-004 — Benutzer, Projektrollen und Basis-Authentifizierung
05. AI6-005A — Starke Primärauthentifizierung und E-Mail-Barriere
06. AI6-005B — Session- und HTTP-Härtung, CSP und sichere Markdown-Basispolitik
07. AI6-006A — ControlProcessRunner und gehärtete Git-Ausführung
08. AI6-006B — Projektregistrierung und vertrauenswürdige Projektmetadaten
09. AI6-006C — Control-Operation-Kern, Operationssperre und Deploy-Key-Provisionierung
10. AI6-006D — Managed-Clone, Clone und Fetch
11. AI6-006E — Control-Branch-Wechsel und Invalidierungsgeneration
12. AI6-006F — Blobgebundene Read Models und Einzelpfad-Refresh
13. AI6-007 — Ticketparser V1, Legacy-Leser und Validator
14. AI6-008 — Responsive Ticketübersicht und Ticketdetail
15. AI6-009 — Ticketbearbeitung, Statusübergänge und Git-Persistenz
16. AI6-010 — Projektkonfiguration und freigegebene Config-Snapshots
17. AI6-011 — Agentenprofil-, Capability- und Promptkatalog
18. AI6-044 — Manuelle Prompt-Hilfe für Codex und Claude
19. AI6-012 — Ticketprüfung, Approval-Snapshot und Multi-Reviewer-Auswahl
20. AI6-013 — Run-State-Machine, Persistenz und Projektsperre
21. AI6-014 — Run-Branch, Worktree, Checkpoint und Diff-Service
22. AI6-015 — ProcessRunner, ExecutionMailbox und Prozessgrenzen
23. AI6-016 — JSON-Verträge und FakeAgent
24. AI6-017 — Basisschritt-Orchestrator und Run-Timeline
25. AI6-018 — Human Requests, E-Mail, Attention-Inbox und Resume
26. AI6-019 — Implementierungsagent-Turn und sicherer Diff-Import
27. AI6-020 — Adaptive Scope- und Vertragsänderungen
28. AI6-021 — Checkprofile und credentialfreier Checker
29. AI6-045 — Checkausführung in der Checkerrolle
30. AI6-022 — Pre-Review-Verifikation und Checkpoint-Bereitschaft
31. AI6-023 — Read-only Review-Workspaces und Multi-Reviewer-Ausführung
32. AI6-024 — Findings, AC-Abdeckung und Reviewdarstellung
33. AI6-025 — Fixturn und vollständige Re-Review-Schleife
34. AI6-026 — Reviewlimits, Stall-Erkennung und Interventionsaktionen
35. AI6-039 — Review-only-Runvertrag: Claim und report-only Abschluss-Saga
36. AI6-040 — Review-only-Quellbindung, Ausführung, Bericht und Bedienung
37. AI6-043 — Quellenabhängige advisory Finding-Verifikation
38. AI6-027 — Finalchecks, Publish-Kandidat und deterministische Provenienz
39. AI6-028 — Optionales LLM-Sicherheitsgate
40. AI6-029 — Finaler Commit, Ticketstatus, Push, Drift und Cleanup
41. AI6-030 — Projektqueue und abhängigkeitssicherer Auto-Start
42. AI6-031 — Vollständige Runbeobachtung und mobile Bedienung
43. AI6-032 — Vollständiger FakeAgent-End-to-End- und Recovery-Test
44. AI6-033 — Codex-CLI-Adapter
45. AI6-041 — Grok-CLI-Adapter
46. AI6-042 — GitHub-Copilot-CLI-Adapter
47. AI6-035 — Provider-Onboarding, Credential-Setup und Capability-Doctor
48. AI6-034 — Claude-CLI-Adapter
49. AI6-036 — Installation, Backup/Restore und Security-Release-Gate
50. AI6-037 — Migration des bisherigen Ticket-Prompt-Tools
51. AI6-038 — Realer M169-Pilot und MVP-Abnahme
```

Die Reihenfolge ist eine gültige Topologie, aber nicht jede unabhängige Arbeit muss künstlich seriell erfolgen. Innerhalb eines Meilensteins dürfen nur Tickets parallel entwickelt werden, deren `depends_on` vollständig erfüllt ist und die nicht denselben noch instabilen Vertrag definieren.

---

## 15. Ticket-Blueprints

Die folgenden Blueprints sind die verbindliche Quelle für die späteren Detailtickets. `modules` ist eine Orientierung für den erwarteten Ausgangsscope, keine harte Dateiliste.

## 15.1 M0 – Fundament und sichere Laufzeit

### AI6-001 — Laravel-Grundgerüst und Qualitätsbaseline

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `low`
- **Kind:** `chore`
- **Depends on:** keine
- **Requirement-Refs:** `OPS-004`, `OPS-006`, `OPS-007`, `OPS-008`
- **Erwartete Module:** `Shared`

**Ziel**

Ein minimales, testbares Laravel-Repository schaffen, auf dem alle folgenden Tickets ohne Architekturprovisorien aufbauen.

**Deliverables**

- Immutable Scaffoldquelle `laravel/laravel` Tag `v13.8.0`, verifiziert gegen Commit `e196bfdfc96903f2e10219749fcbca7c0aefe99f`; Bezug ausschließlich außerhalb des Repositorys beziehungsweise in einem temporären Verzeichnis und ohne Composer-Skriptausführung.
- Explizite Backend-Allowlist für die Übernahme aus dem Scaffold; kein Root-Installer und kein rekursives Kopieren über den bestehenden Repositoryinhalt.
- Laravel-13-Anwendung mit `php: ^8.5`, Composer-`config.platform.php: 8.5.0`, `laravel/framework: ^13.8`, `phpunit/phpunit: ^12.5.12` und klarer app/AI6-Modulwurzel.
- Committed `composer.lock` als unter der PHP-8.5.0-Plattform aufgelöste, reproduzierbare Abhängigkeits- und Toolbaseline; Änderungen daran sind im Review sichtbar.
- PHPUnit über `php artisan test`, Pint über `vendor/bin/pint` und PHPStan über `vendor/bin/phpstan analyse` mit repositorylokaler Konfiguration und dokumentiertem Analyselevel.
- Health-Endpunkt und kurze Entwickler-README.
- Verbindliche Konventionen für Actions, Services und DTOs ohne BaseService-Hierarchien.
- Deterministischer repositorylokaler Generator für `docs/AI6_TICKET_MANIFEST.yaml` einschließlich Driftcheck; die initiale Manifestdatei wird committed.

**Akzeptanzvertrag**

- Anwendung startet lokal.
- Basis-Test und Health-Test sind grün.
- Eine saubere Installation aus dem committed `composer.lock` unter realem PHP 8.5 ist reproduzierbar; `composer validate --strict` und `composer check-platform-reqs` sind dort grün, ohne dass `composer update` benötigt wird.
- Tests, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` und der Manifest-Driftcheck sind grün.
- Der Generator bildet Blueprint-Metadaten und Requirement-Zuordnungen aus diesem Plan deterministisch und ohne handgepflegte zweite Wahrheit ab.
- `.gitattributes`, `AGENTS.md`, `CLAUDE.md`, `README.md`, `docs/` und `tickets/` werden nicht durch Scaffolddateien überschrieben.
- Defaultmigrationen, `User`-Modell, User-Factory, Default-Seeding, Welcome-UI und Composer-Skripte mit implizitem SQLite-, Migrate- oder Queue-Seiteneffekt sind nicht vorhanden.
- Noch keine Git-, Ticket-, Agenten- oder Auth-Fachlogik.

**Mindestens zu erzeugende Testfälle**

- Featuretest für Health-Endpunkt.
- Unit-Smoke-Test für Modul-Autoloading.
- CI-/lokale Befehle für PHPUnit, Pint und PHPStan.
- Locked-install-/`composer validate --strict`-/`composer check-platform-reqs`-Test unter realem PHP 8.5 samt Negativkontrolle gegen eine unter höherer Plattform unbemerkt inkompatibel aufgelöste Lockdatei sowie Manifest-Generate-/Drifttest.
- Herkunftsprüfung auf Tag und Commit, Allowlist-/Unexpected-File-Test und Negativtest gegen Überschreiben geschützter Bestandsdateien.
- Negativtest auf Defaultmigrationen, User-Artefakte, Welcome-UI und Composer-Skripte mit Datenbank-/Queue-Seiteneffekt.

**Nicht Teil dieses Tickets**

- Docker-Deployment.
- Authentifizierung.
- Projekt- oder Ticketverwaltung.
- Frontend-/Node-Bootstrap einschließlich `package.json`, Vite und Scaffold-JavaScript.

### AI6-002 — Docker-Compose-Laufzeit, SQLite-Queue und Scheduler

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `medium`
- **Kind:** `chore`
- **Depends on:** `AI6-001`
- **Requirement-Refs:** `PROD-002`, `OPS-001`, `OPS-002`, `OPS-004`, `OPS-008`
- **Erwartete Module:** `Shared`

**Ziel**

AI6 mit einem Befehl lokal beziehungsweise auf einem Linux-Server als eine Codebasis mit getrennten Prozessrollen starten.

**Deliverables**

- Ein reproduzierbar gebundenes Dockerimage auf PHP 8.5 für app, worker, agent, checker und scheduler sowie den einmaligen Startschritt `init` nach §4.1.
- Caddy/Loopback-Referenzprofil.
- SQLite `3.53.x` mit WAL, Database Queue und Scheduler; Laufzeittest auf die Versionslinie und sichtbare konkrete Paketauflösung.
- Persistente Volumes und Healthchecks.
- Keine eingehenden Ports für worker, agent, checker und scheduler.

**Akzeptanzvertrag**

- docker compose up startet alle Rollen.
- Ein Testjob wird genau einmal vom Worker verarbeitet.
- Scheduler führt einen Testtask aus.
- agent und checker haben keinen veröffentlichten Port.
- Default-Binding ist nicht öffentlich.
- Die Laufzeit meldet PHP `8.5.x` und SQLite `3.53.x`; ein abweichender Minor-Stand lässt den Versionsnachweis fehlschlagen.

**Mindestens zu erzeugende Testfälle**

- Compose-Smoke-Test.
- Queue-Retry-Test.
- Volume-/Dateirechte-Test.
- Healthcheck-Test.
- Laufzeitversionstest für PHP und SQLite samt negativer Kontrolle gegen eine abweichende Minor-Version.

**Nicht Teil dieses Tickets**

- Produktive Provider-Credentials.
- Fachliche Run-Orchestrierung.

### AI6-003 — Typed Config und zentrale SecurityPolicy

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-001`, `AI6-002`
- **Requirement-Refs:** `CFG-001`, `SEC-001`, `SEC-004`, `SEC-007`, `OPS-003`
- **Erwartete Module:** `Shared`, `Auth`

**Ziel**

Alle instanzweiten Sicherheits- und Betriebsentscheidungen genau einmal typisiert auflösen und mit sicheren Defaults sichtbar machen.

**Deliverables**

- SecurityProfile strict/development/custom.
- Strenger Boolean- und Enum-Parser.
- SecurityPolicy-DTO und stabiler Policyhash.
- Zentraler konkreter `Redactor` mit typisierter RedactionPolicy, stabilen Markern und einer einzigen serverseitigen Regelauflösung für Secrets sowie sensible Werte/Pfade; Presentation-Sanitizer liefern keine zweite Patternliste.
- Versionierter Redaction-Fingerprint-Vertrag über HMAC-SHA-256 mit eigener rotierbarer serverseitiger Key-Ring-Konfiguration, fester Domain `AI6-REDACTION-FINGERPRINT-V1`, Typ-/Kontextbindung und kanonischer Projekt-ID beziehungsweise Projekt-/Run-ID; Key-ID und Version werden gespeichert, der entfernte Wert und ein unkeyed Digest nie.
- Reduced-Mode-Acknowledgement und Banner-Daten.
- ai6:doctor-Grundgerüst.

**Akzeptanzvertrag**

- Ohne Env gilt strict.
- Ungültige Booleanwerte brechen den Start ab.
- custom mit deaktivierter Maßnahme verlangt explizite Bestätigung.
- Nicht abschaltbare Invarianten besitzen keine Flags.
- Module lesen Securitywerte nur über SecurityPolicy.
- Alle späteren Read-Model-, Log-, Diff-, Provider- und Artefaktpfade konsumieren denselben Redactor; eine lokale zweite Redaction-Regelsammlung ist ein Architekturfehler.
- Gleicher entfernter Wert korreliert weder projekt- noch runübergreifend; Keyrotation erzeugt eine neue Fingerprintversion, ohne alten Klartext zur Nachberechnung aufzubewahren.

**Mindestens zu erzeugende Testfälle**

- Konfigurationsmatrix strict/development/custom.
- Negativtests für Tippfehler und fehlendes Acknowledgement.
- Policyhash-Stabilitätstest.
- Golden-/Negativtests für zentrale Secret-/Pfad-/Tokenredaction, stabile Marker, Doppelanwendung und klare Abgrenzung zu Markdown-/HTML-/ANSI-Sanitizing.
- HMAC-Golden-, Keyrotations-, Projekt-/Run-Isolations- und Dictionary-Negativtests; kein gespeicherter Wert darf Offline-Raten über raw SHA oder unkeyed Digest erlauben.

**Nicht Teil dieses Tickets**

- Konkrete Passkey-, Mail-, Sandbox- oder Git-Implementierung.

### AI6-004 — Benutzer, Projektrollen und Basis-Authentifizierung

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-002`, `AI6-003`
- **Requirement-Refs:** `SEC-002`, `SEC-004`, `PROD-001`
- **Erwartete Module:** `Auth`, `Projects`

**Ziel**

Eine geschlossene Benutzerverwaltung mit globalem Admin und projektbezogenen Rollen bereitstellen.

**Deliverables**

- Benutzer- und project_memberships-Schema.
- Rollen admin, viewer, operator, approver.
- Admin-Bootstrap per CLI ohne öffentliche Registrierung.
- Basislogin und Laravel-Policies.
- Sessionwiderruf und Rate-Limits.

**Akzeptanzvertrag**

- Öffentliche Registrierung ist deaktiviert.
- Projektfremde Benutzer sehen keine Projektdaten.
- Nur berechtigte Rollen dürfen mutieren.
- Erster Admin kann sicher per CLI angelegt werden.

**Mindestens zu erzeugende Testfälle**

- Auth-Featuretests.
- Policy-Matrix je Rolle.
- Rate-Limit- und Sessionwiderrufstest.

**Nicht Teil dieses Tickets**

- Passkeys, TOTP und Login-E-Mail-Code.
- Projekt-Git-Verwaltung.

### AI6-005A — Starke Primärauthentifizierung und E-Mail-Barriere

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-003`, `AI6-004`
- **Requirement-Refs:** `SEC-002`, `SEC-003`, `HUM-003`
- **Erwartete Module:** `Auth`

**Ziel**

Jede neue Websession im strict-Profil erst nach starker Primärauthentifizierung und zusätzlicher E-Mail-Codebestätigung autorisieren.

**Deliverables**

- Ein zentraler LoginCompletionGate für Passkey, Passwort/TOTP und Recovery.
- login_confirmations mit HMAC-Digest, Revision, TTL, Versuchslimit und Zustellzustand.
- AI6_LOGIN_CONFIRMATION_EMAIL ausschließlich aus Env/Config.
- Security-Mailqueue mit verschlüsselter Payload, Resend und Recovery-CLI.
- Step-up-Primitive für kritische Aktionen, gebunden an Benutzer, Session, Aktionstyp und Zeitfenster.

**Akzeptanzvertrag**

- Im strict-Profil entsteht vor korrektem E-Mail-Code keine autorisierte Session.
- Deaktivierung ist nur über SecurityPolicy möglich und sichtbar.
- Kein One-Click-Login-Link.
- Empfängerwechsel widerruft offene Challenges.
- Mailausfall bleibt fail closed.
- Der Klartextcode liegt in keiner Datenbankspalte, auch nicht in der Jobtabelle der Queue.

**Mindestens zu erzeugende Testfälle**

- Passkey-/TOTP-/Recovery-Pipeline.
- Code falsch, abgelaufen, wiederverwendet, fremde Preauth-Session.
- Resend-Revision und parallele Mailjobs.
- Rohe Queue-Payload ohne Klartextcode.
- Step-up fehlend, abgelaufen und frisch.

**Nicht Teil dieses Tickets**

- Cookie-, Host-, Proxy-, CSP- und Markdown-Härtung.
- VPN- oder SSH-Tunnel-Einrichtung.
- Human-Request-Mails.

### AI6-005B — Session- und HTTP-Härtung, CSP und sichere Markdown-Basispolitik

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-003`, `AI6-004`, `AI6-005A`
- **Requirement-Refs:** `SEC-004`, `SEC-007`
- **Erwartete Module:** `Shared`

**Ziel**

Jede Webantwort und jede Session gegen Übernahme, Fremdhost- und Darstellungsangriffe härten, ohne eine zweite Redaction-Regelsammlung einzuführen.

**Deliverables**

- Secure Cookies mit HttpOnly-, SameSite- und Secure-Vertrag sowie abgeschaltetem Remember-me.
- Trusted Hosts und Trusted Proxies ausschließlich aus vertrauenswürdiger Konfiguration.
- Content-Security-Policy ohne unsafe-inline und unsafe-eval für Skripte.
- Sichere Markdown-Basispolicy, die den zentralen Redactor konsumiert und ausschließlich Darstellung/HTML-Sanitizing ergänzt.

**Akzeptanzvertrag**

- Kein Remember-me und kein persistentes Anmeldecookie.
- Nicht allowlistete Host- und Weiterleitungsangaben werden abgelehnt.
- Jede HTML-Antwort trägt die CSP; CSRF bleibt nicht abschaltbar wirksam.
- Rohes HTML, Eventhandler sowie javascript:- und data:-Linkziele werden entfernt.
- Presentation-Sanitizing definiert keine eigene Secret-Musterliste.

**Mindestens zu erzeugende Testfälle**

- Hostheader-, Proxy-, CSRF-, CSP- und Cookietests.
- Markdown-XSS-Negativtests.
- Architekturtest gegen eine zweite Redaction-Regelsammlung.

**Nicht Teil dieses Tickets**

- Passkey, TOTP, Recovery, E-Mail-Barriere und Step-up.
- Ticketdarstellung und gerendertes Ticket-Markdown.

## 15.2 M1 – Projekte und Git-native Tickets

### AI6-006A — ControlProcessRunner und gehärtete Git-Ausführung

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-002`, `AI6-003`
- **Requirement-Refs:** `GIT-001`, `AGT-006`, `SEC-006`
- **Erwartete Module:** `Shared`, `Git`

**Ziel**

Jeden Git- und Control-Prozess ausschließlich über eine gehärtete, argumentlistenbasierte Ausführungsgrenze ohne repositorygetriebene Helferprozesse starten.

**Deliverables**

- Zentraler `ControlProcessRunner` auf Symfony Process mit Argumentlisten, Environment-Allowlist, Timeout-/Outputlimit und kontrolliertem Cancel; spätere Executorprofile erweitern genau diese Grenze.
- Blockierender Startmodus derselben Grenze: Der Kindprozess wird über einen Wrapper gestartet, der vor dem eigentlichen Programm auf eine Freigabe des Aufrufers wartet und bis dahin keinen externen Effekt auslöst. Der Aufrufer erhält dabei Prozesskennung und tatsächlichen Startzeitpunkt, bevor er freigibt.
- Exklusiver Effekt-Lock als Fähigkeit desselben Wrappers: Er erwirbt vor der Freigabe einen exklusiven Dateilock des Betriebssystems auf einem vom Aufrufer benannten, bereits vorhandenen Lockobjekt des konfigurierten Lockverzeichnisses im persistenten Volume und hält ihn bis zum Prozessende; das Betriebssystem gibt ihn beim Prozessende frei, auch bei `SIGKILL` und Containerabbruch. Der Wrapper wird dabei nach §6.2 per `exec` selbst zum Zielprogramm: Der Lock-Dateideskriptor bleibt über `exec` erhalten und trägt kein Close-on-Exec, ein zusätzlicher Supervisorprozess existiert nicht, und die dem Aufrufer gemeldete Prozesskennung ist die des wirkenden Programms. Ein nicht erreichbarer Lock endet als benanntes, wiederholbares Lockergebnis statt als Deadlock. Der Aufrufer bestimmt Semantik und Pfad des Locks, die Grenze stellt nur den Mechanismus.
- Derselbe Lock ist zusätzlich direkt durch den aufrufenden Prozess erwerbbar, damit ein wirkender Abschnitt des Workers nach §6.2 unter derselben Serialisierung läuft wie ein Kindprozess: ein Mechanismus, dasselbe Lockobjekt, dieselbe Frist und dasselbe benannte Lockergebnis. Eine zweite Lockimplementierung ist unzulässig.
- Unersetzbare Lockobjekte nach §6.2 statt eines bloßen Identitätsvergleichs: Das Lockverzeichnis gehört einer privilegierten Identität und ist für den ausführenden Benutzer nicht beschreibbar, die Lockobjekte werden von einem privilegierten, idempotenten Startschritt in konfigurierter Anzahl vorab bereitgestellt, und die Anwendung erzeugt, benennt, ersetzt oder löscht dort nichts. Ein Lockname wird symlinksicher gegen das Lockverzeichnis aufgelöst und containment-geprüft, das Lockobjekt ohne Symlinkfolge und ohne Schreibabsicht geöffnet; ein unbekannter Name endet als benannter Konfigurationsfehler statt als implizites Anlegen. Eigentümer- und Modusprüfung sowie der Inode-Vergleich nach dem Erwerb bleiben als zweite Verteidigungslinie und enden bei jeder Abweichung als benannter Lockkonflikt ohne Wirkung; die tragende Zusage ist jedoch, dass der Austausch eines gehaltenen Lockobjekts an den Verzeichnisrechten scheitert. Ein Vertrag, der einen einmaligen Inode-Vergleich einen späteren Austausch erkennen lässt, ist falsch: Nach einem Austausch fände auch der zweite Halter eine gültige Inode vor.
- Gehärtete Git-Prozessumgebung mit `GIT_CONFIG_NOSYSTEM`, isoliertem Home/XDG, kontrollierter minimaler Globalconfig, leerem versiegeltem Hooks-Pfad und ohne externe Filter, Pager, textconv/ext-diff, fsmonitor oder Signing-Helfer.
- SSH ausschließlich über einen festen vertrauenswürdigen Wrapper mit Argumentlisten, eigener known_hosts und ohne Agent-Forwarding.
- Host-/Protokoll-/Ref-Allowlist und gepinnter Hostkey als serverseitiger Remote-Vertrag.
- Repository-Hooks und Credentialhelper zwingend deaktiviert; Submodule im MVP nicht zugelassen.

**Akzeptanzvertrag**

- Nur erlaubte SSH-Remotes werden akzeptiert; die Ablehnung erfolgt vor jedem Prozessstart.
- Repository-Hooks werden nicht ausgeführt.
- Repository-.gitattributes oder Hostkonfiguration können keinen Filter, Diff-/Textconv-, Pager-, fsmonitor-, Signing- oder sonstigen Helperprozess starten.
- Ein Shellstring ist kein zulässiger Eingabeweg; nicht allowlistete Umgebungsvariablen fehlen im Kindprozess.
- Laufzeit- und Ausgabelimit beenden den Prozess mit benanntem Ergebnis statt mit einem Teilergebnis.
- Im blockierenden Startmodus wirkt der Kindprozess vor der Freigabe nicht, und Prozesskennung sowie Startzeitpunkt liegen dem Aufrufer vor der Freigabe vor.
- Zwei Wrapper auf demselben Lockobjekt serialisieren: Der zweite erwirbt den Lock erst nach dem Ende des ersten Prozesses, und ein `SIGKILL` des ersten gibt ihn frei. Ein nicht erreichbarer Lock liefert ein benanntes wiederholbares Ergebnis und keinen Deadlock.
- Lockhalter und wirkendes Programm sind derselbe Prozess: Nach der Freigabe existiert kein Wrapper- oder Supervisorprozess zwischen Aufrufer und Zielprogramm, und ein `SIGKILL` auf genau die gemeldete Prozesskennung beendet das wirkende Programm und gibt den Lock frei.
- Ein direkt vom aufrufenden Prozess gehaltener Lock serialisiert gegen einen wrappergehaltenen Lock desselben Lockobjekts.
- Ein abrupt beendeter lockhaltender Kontext gibt den Lock frei: Wird der haltende Prozessbaum als Ganzes ohne Aufräumen beendet, erwirbt ein danach startender Wrapper dasselbe Lockobjekt erfolgreich. Der **instanzübergreifende** Nachweis über das geteilte persistente Volume gehört nach §14.1 zu `AI6-006C`, weil Workervolume, Rollenbild und `docker-compose.yml` erst dort entstehen; dieses Ticket erbringt den prozesslokalen Teil und zusätzlich einen Lauf mit einem separat gemounteten Dateisystem.
- Ein Lockname außerhalb des konfigurierten Lockverzeichnisses, ein Symlink im Pfad, ein unbekanntes Lockobjekt und eine zwischen Auflösung und Erwerb ausgetauschte Datei führen zu einem benannten Lockkonflikt beziehungsweise Konfigurationsfehler ohne Wirkung. Der Austausch eines Lockobjekts ist unter der ausführenden Identität technisch nicht möglich: Löschen, Umbenennen und Neuanlegen im Lockverzeichnis scheitern an dessen Rechten, und deshalb halten zwei Prozesse nie gleichzeitig denselben Lock.
- Fehler sind ohne Secretwerte sichtbar.

**Mindestens zu erzeugende Testfälle**

- Negativtests file://, git://, ext::, unbekannter Hostkey und Hook.
- Negativfixtures für Host-System-/Globalconfig, `.gitattributes` clean/smudge/process-Filter, textconv/ext-diff, Pager, fsmonitor, Signing und manipulierte SSH-Umgebung.
- Argumentlisten-/Environment-/Timeout-/Outputlimit-/Cancel-Tests für den Control-ProcessRunner.
- Submodulfixture ohne Checkout und ohne Netzwerkzugriff.
- Blockierender Startmodus: Der Fixture-Prozess hat vor der Freigabe nicht gewirkt, und Prozesskennung sowie Startzeitpunkt liegen vorher vor.
- Effekt-Lock: Serialisierung zweier Wrapper auf demselben Lockobjekt, Freigabe nach regulärem Prozessende und nach `SIGKILL`, benanntes wiederholbares Ergebnis bei nicht erreichbarem Lock.
- `exec`-Bindung des Locks: Nachweis, dass unter der gemeldeten Prozesskennung das Zielprogramm selbst läuft und kein Elternwrapper überlebt; ein Testdoppel, das das Zielprogramm als Kindprozess des Wrappers startet statt per `exec`, lässt den Testfall fehlschlagen, weil sonst ein `SIGKILL` des Wrappers den Lock freigäbe, während das Zielprogramm weiterwirkt.
- Serialisierung eines direkt vom aufrufenden Prozess gehaltenen Locks gegen einen wrappergehaltenen Lock desselben Lockobjekts.
- Locktest mit abruptem Ende des gesamten lockhaltenden Prozessbaums ohne Aufräumgelegenheit, zusätzlich mit einem Lockverzeichnis auf einem separat gemounteten Dateisystem; ein danach startender Wrapper erwirbt dasselbe Lockobjekt.
- Identitätstests des Lockobjekts: Traversal aus dem Lockverzeichnis heraus, Symlink im Lockpfad, unbekannter Lockname und der Versuch, ein gehaltenes Lockobjekt zu löschen, umzubenennen oder zu ersetzen. Der Austauschversuch selbst muss unter der ausführenden Identität scheitern; ein Testaufbau, der stattdessen erwartet, ein Inode-Vergleich erkenne einen erfolgreichen Austausch, erfüllt den Testfall nicht.

**Nicht Teil dieses Tickets**

- Projektverwaltung, Deploy-Key-Onboarding und Control-Branch-Autorität.
- Control Operations, Read Models und Weboberfläche.
- Bedeutung, Pfadwahl und Auftragsbindung des Effekt-Locks; dieses Ticket liefert ausschließlich den Mechanismus.

### AI6-006B — Projektregistrierung und vertrauenswürdige Projektmetadaten

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-004`, `AI6-006A`
- **Requirement-Refs:** `PROD-001`, `GIT-001`
- **Erwartete Module:** `Projects`

**Ziel**

Ein Projekt mit vertrauenswürdiger Remote-, Control-Branch- und Managed-Path-Bindung registrieren, ohne dass Repositoryinhalt einen dieser Werte bestimmen kann.

**Deliverables**

- Erweiterung der Projekttabelle um Remote, Control-Branch, eine serverseitig erzeugte eindeutige relative Projektkennung, gepinnten Hostkey-Fingerprint, Deploy-Key-Referenz samt öffentlichem Schlüssel, Provisionierungszustand samt beanspruchender Operation-ID, Projektsperre mit Lease-Ablauf, Heartbeat und Attempt-Token des ausführenden Versuchs, eine monotone `control_generation` als einzigen Zustandsträger der Invalidierung, die autoritative aktuelle Control-OID-Bindung sowie genau eine ausstehende Control-Bindung aus ausstehendem Ref, ausstehendem OID und Quelloperation; aktive und ausstehende Bindung teilen denselben Versionszähler. Ein absoluter Managed-Path wird nicht gespeichert; der Worker konstruiert ihn aus konfiguriertem Root und Projektkennung. Die Bindung ist bis zum ersten erfolgreichen Clone leer.
- Registrierungsaktion ausschließlich für globale Administratoren; sie validiert Remote und Host gegen die Allowlist aus AI6-006A rein prüfend und ohne Prozessstart und erzeugt die relative Projektkennung serverseitig als Bezeichner aus genau 32 Zeichen des Alphabets `[0-9a-f]`, abgeleitet aus 16 kryptografisch zufälligen Bytes. Dieses Format ist ohne datenbankabhängigen Unicode-Case-Fold-Vergleich eindeutig und enthält weder Pfadseparator noch `..`.
- Strenger Hostkey-Fingerprint-Vergleich: Das Format `SHA256:<Base64>` wird streng geparst, ausschließlich Präfixschreibweise und optionale Base64-Auffüllung werden normalisiert, der Nutzwert wird case-sensitiv dekodiert, und die dekodierten Digestbytes werden konstantzeitlich mit dem serverseitig gepinnten Wert verglichen. Eine Änderung der Groß-/Kleinschreibung im Nutzwert bezeichnet einen anderen Schlüssel und wird abgelehnt. Für `SHA256` sind ausschließlich genau 32 dekodierte Bytes zulässig; abweichende Länge, nichtkanonische Base64-Auffüllung, Zeichen außerhalb des Base64-Alphabets und ein anderes Digestpräfix werden abgelehnt.
- Atomare Projektmitgliedschaft des registrierenden Administrators mit der normativ festgelegten Projektrolle `admin`.
- Projektliste und Projektdetail mit Registrierungs- und Provisionierungszustand; der öffentliche Deploy-Key wird erst nach terminal erfolgreicher Provisionierung angezeigt.

**Akzeptanzvertrag**

- `control_branch`, Remote und Managed-Path stammen ausschließlich aus vertrauenswürdigen Projektmetadaten und können nicht aus Repositoryconfig überschrieben werden.
- Nur ein globaler Administrator registriert ein Projekt; Projektzugriff folgt danach project_memberships.
- Die Registrierung legt in derselben Transaktion die Mitgliedschaft des Registrierenden mit Projektrolle `admin` an; schlägt sie fehl, entsteht auch kein Projekt.
- Unmittelbar nach der Registrierung sind Projekt und Provisionierungszustand sichtbar; der öffentliche Deploy-Key erscheint erst nach terminal erfolgreicher Provisionierung.
- Die relative Projektkennung stammt ausschließlich vom Server, besteht aus genau 32 Zeichen des Alphabets `[0-9a-f]`, ist damit auch unter Case-Fold-Vergleich eindeutig und enthält keinen Pfadseparator und kein `..`; ein vom Client vorgeschlagener Wert wird ignoriert.
- Ein Hostkey-Fingerprint, dessen dekodierte Digestbytes nicht dem gepinnten Wert entsprechen, führt zur Ablehnung der Registrierung; eine Änderung der Groß-/Kleinschreibung im Base64-Nutzwert gilt als abweichender Schlüssel.
- Ein `SHA256`-Fingerprint mit einer von 32 Byte abweichenden Nutzwertlänge oder mit nichtkanonischer Auffüllung wird abgelehnt, bevor ein Vergleich stattfindet.
- Die ausstehende Control-Bindung ist nach der Registrierung leer und wird von diesem Ticket nicht gesetzt.
- `control_generation` steht nach der Registrierung auf ihrem Startwert und wird von diesem Ticket nicht erhöht.
- Der Provisionierungszustand ist nach der Registrierung `not_provisioned` und wird von diesem Ticket nicht weitergeschaltet.
- Die aktuelle Control-OID-Bindung ist nach der Registrierung leer und wird von diesem Ticket nicht gesetzt.
- Dieses Ticket startet keinen Git- oder Kindprozess.

**Mindestens zu erzeugende Testfälle**

- Schema- und Migrationstest der Projekterweiterung einschließlich Control-OID-Feld mit Versionszähler, Attempt-Token der Sperre und `control_generation` mit ihrem Startwert.
- Negativtest gegen Überschreiben von Control-Branch, Remote und Managed-Path aus Repositoryinhalt.
- Rollentest der automatisch erzeugten Mitgliedschaft und Transaktionstest bei fehlschlagender Mitgliedschaft.
- Formattest der Projektkennung auf Länge 32 und Alphabet `[0-9a-f]`, Eindeutigkeits- und Case-Fold-Kollisionstest sowie Negativtests gegen Pfadseparator, `..` und einen clientseitig vorgeschlagenen Wert.
- Fingerprinttests: gleicher Digest bei abweichender Präfixschreibweise und fehlender Auffüllung wird akzeptiert, ein im Base64-Nutzwert case-geänderter oder um ein Bit veränderter Fingerprint wird abgelehnt, und der Vergleich erfolgt konstantzeitlich.
- Längen- und Auffüllungsnegativtests des Fingerprints: 31 und 33 dekodierte Bytes, überschüssige und nichtkanonische Auffüllung sowie ein Zeichen außerhalb des Base64-Alphabets werden vor jedem Vergleich abgelehnt.
- Sichtbarkeitstest: kein öffentlicher Schlüssel vor terminal erfolgreicher Provisionierung.
- Architekturtest gegen jeden Prozessstart aus diesem Ticket.
- Policytests je Projektrolle und für die globale Administratorrolle.

**Nicht Teil dieses Tickets**

- Control Operations, Clone, Fetch, Deploy-Key-Erzeugung und Control-Branch-Wechsel.
- Read Models, Refresh und deren Redaction.

### AI6-006C — Control-Operation-Kern, Operationssperre und Deploy-Key-Provisionierung

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-005A`, `AI6-006A`, `AI6-006B`
- **Requirement-Refs:** `GIT-009`, `RUN-004`, `RUN-005`, `SEC-002`
- **Erwartete Module:** `Git`, `Projects`

**Ziel**

Jede Git-Wirkung der App ausschließlich als typisierte, absturzsichere Control Operation im Worker ausführen und diesen Vertrag an genau einem ersten Operationstyp belegen.

**Deliverables**

- Typisierter Control-Operation-Kern: Auftragstabelle mit Typ, Operation-ID, Projekt, Actor, Autorisierungssnapshot, erwartetem Control-Commit, kanonischem Auftragsdatenhash, Phasenzustand und Ergebnisbindung, ausgeführt ausschließlich im Worker. Auftragsdatensatz und Queue-Job entstehen in derselben Datenbanktransaktion.
- Bytegenaues, versioniertes Hashschema: wörtliche ASCII-Domäne `AI6-CONTROL-OPERATION-V1` plus NUL-Byte, je Feld ein Präsenzmarker zur Unterscheidung von `null` und leerem Wert, die Länge der UTF-8-Bytes als unsigned 64-bit big-endian und die Bytes in Unicode-Normalform NFC. Stringwerte des strukturierten Autorisierungssnapshots werden rekursiv nach NFC normalisiert und anschließend nach RFC 8785 serialisiert; die JCS-Bytes selbst bleiben danach unverändert. Werden zwei vorher verschiedene Objektschlüssel derselben Ebene durch die NFC-Normalisierung identisch, ist das ein Fehler; ein Schlüssel wird nie still überschrieben. Feldliste und Reihenfolge sind je Operationstyp als Codekonstante festgelegt.
- Phasenbasierter, absturzsicherer Operationsvertrag nach dem Vorbild der Status-Saga aus §5.2: persistierter Intent vor jedem externen Seiteneffekt, operationstypspezifische Reconciliation, atomar erzeugte oder über die Operation-ID wiedererkennbare temporäre Ressourcen und genau ein terminaler Zustand. Jede mutierende Operation durchläuft nach §6.2 genau zwei Compare-and-Swap-Phasen: den Claim vor dem Auftrag und den eigentümergebundenen Publish nach dem externen Effekt, der die Sperre gegen das Paar aus Operation-ID und Attempt-Token und alle erwarteten Vorwerte prüft. Crash-Injection an jeder definierten Phasengrenze jedes Operationstyps ist Pflicht (`RUN-005`).
- Projektweite Operationssperre als zeitgebundener Lease mit Fencing: Anspruch per Compare-and-Swap mit hinterlegter Operation-ID, monotonem Attempt-Token des Ausführungsversuchs, Ablaufzeitpunkt und Heartbeat des ausführenden Workers; Heartbeat, Phasenfortschritt, Finalisierung und Freigabe sind per Compare-and-Swap an das Paar aus Operation-ID und Attempt-Token gebunden, und die Freigabe erfolgt ausschließlich im terminalen Zustand. Weil Operations- und Runsperre nach §6.2 ausschließlich gemeinsam in einer Transaktion geprüft und gesetzt werden dürfen, entsteht hier genau eine Anspruchsnaht: eine einzige bedingte Aktualisierung des Projektdatensatzes, deren Bedingung alle Vorbedingungen trägt. Dieses Ticket legt kein Runfeld an — die Runsperre entsteht mit `AI6-013`, das genau diese Bedingung atomar erweitert, statt einen zweiten Anspruchspfad einzuführen. Nach dem Claim gehört die Sperre der Operation; jede spätere wirksame Änderung läuft über den eigentümergebundenen Publish und nicht über eine erneute Prüfung auf eine freie Sperre.
- Periodischer Reconciler für verwaiste nichtterminale Operationen: Er erkennt abgelaufene Leases, ausgeschöpfte Queue-Retries und Auftragsdatensätze ohne lauffähigen Job und requeued die Operation sicher über ihre Operation-ID unter einem neuen, höheren Attempt-Token oder terminalisiert sie nach überprüfter Außenwirkung; er löst die Sperre nie blind. Eine Operation in dem nach §6.2 sichtbaren, nichtterminalen Recoveryzustand wird davon ausgenommen: Er terminalisiert sie nicht und gibt ihre Projektsperre nicht frei, weil das einen inkonsistenten Außenstand festschreiben würde.
- Die typisierte Recoveryentscheidung nach §6.2 als einziger Ausweg aus diesem Zustand, damit ein Projekt nicht dauerhaft gesperrt bleibt: persistierter, redigierter Recoverybefund samt Hash beim Eintritt; Auslösung ausschließlich durch einen globalen Administrator mit frischem Step-up aus AI6-005A (`SEC-002`); genau die drei Entscheidungen `retry_reconciliation`, `adopt_external_state` und `abandon_operation` ohne frei übergebenen Zielzustand; Compare-and-Swap gegen Projekt, Operation-ID, letzten Attempt-Token, Versionszähler, Befundhash und fortbestehenden Recoveryzustand; einmalige Konsumierbarkeit; Wirkung ausschließlich im Worker unter neuem Attempt-Token und unter dem Effekt-Lock; redigierter Auditeintrag und Anzeige in der Projektansicht. `abandon_operation` verlangt zusätzlich gebundene menschliche Evidenz nach `RUN-009`.
- Prozessfencing über die Datenbankgrenze hinaus: Der zweistufige Launchvertrag aus §6.2 — Launch-Intent aus Operation-ID, Attempt-Token, Workerinstanz samt Boot-ID nach `OPS-002`, Argumenthash und Effektphase persistieren, Kindprozess über den blockierenden Startmodus der Prozessgrenze aus AI6-006A starten, Prozesskennung und tatsächlichen Startzeitpunkt persistieren, erst danach freigeben. Wrapper und Effekt-Lock sind generische Fähigkeiten dieser einen Grenze und entstehen dort; dieses Ticket verwendet sie, bindet sie an Launch-Intent, Auftrag und projektgebundenen Lockpfad und berührt `app/AI6/Shared/Process/` nicht.
- Versuchsgebundene Verwendung des Effekt-Locks aus AI6-006A: Dieses Ticket bestimmt die deterministische Zuordnung eines Projekts zu einem der vorab bereitgestellten Lockobjekte im geteilten, persistenten Workervolume und deren Semantik; es stellt außerdem sicher, dass das Lockverzeichnis in genau diesem Volume liegt, vom einmaligen privilegierten Startschritt `init` nach §4.1 idempotent befüllt wird und für den unprivilegierten Workerbenutzer nicht beschreibbar ist. Dafür werden der aus `AI6-002` stammende `init`-Zweig des Entrypoints um den Bereitstellungsschritt und der `init`-Dienst der Compose-Definition um Workervolume und Rechteübersteuerung erweitert; der Workercontainer erhält dafür keine privilegierte Identität, und eine Erstbefüllung des Lockverzeichnisses aus dem Imagelayer ist ausgeschlossen. Der Wrapper erwirbt den Lock vor der Freigabe des Kindprozesses und hält ihn — per `exec` selbst zum Zielprogramm geworden — bis zu dessen Ende. Auch jeder wirkende Abschnitt des Workers selbst, insbesondere ein Publish, der ein Artefakt aus dem versuchsgebundenen Bereich in den geteilten Bestand überführt, läuft unter demselben Lock, mit erneuter Eigentümerprüfung an Operation-ID und Attempt-Token unmittelbar nach dem Erwerb. Sein erfolgreicher Erwerb ist der Beendigungsnachweis des Vorgängerprozesses; ein nicht erreichbarer Lock endet als sichtbarer, wiederholbarer Lockkonflikt. Der Reconciler wartet nicht auf einen Livenessnachweis, sondern stellt sofort unter einem höheren Attempt-Token erneut zu. Externe Effekte entstehen in einem versuchsgebundenen Stagingbereich und gelangen ausschließlich im eigentümergebundenen Publish in den geteilten Bestand.
- Symlinksichere Bereinigung verworfener und terminalisierter Versuche: Stagingbereich, versuchseigener Ref-Bereich und privates Schlüsselmaterial eines gescheiterten Versuchs werden entfernt, sodass kein Retry verwaiste Artefakte anhäuft.
- Konstruktion und Validierung des absoluten Managed-Path im Worker aus konfiguriertem Root und relativer Projektkennung mit Root-Containment nach symlinksicherer Auflösung; der versuchsgebundene Stagingbereich liegt innerhalb desselben Root und wird derselben Prüfung unterworfen.
- Der erste Operationstyp Deploy-Key-Provisionierung: CAS-gebundener Übergang `not_provisioned → provisioning` auf genau die auslösende Operation-ID, atomare Finalisierung von öffentlichem Schlüssel, Schlüsselreferenz und Zustand `provisioned`, definierter Übergang nach `provisioning_failed` mit erlaubtem erneutem Versuch. Privates Schlüsselmaterial entsteht und bleibt ausschließlich im Worker.
- Die Übernahme des Schlüssels als Saga über Dateisystem und Datenbank nach §6.2, weil auch sie zwei Speicher überschreitet: benannte Phasen `key_generated`, `key_activated`, `provisioning_finalized` und `attempt_completed` mit ausschließlich `attempt_completed` als terminaler Phase; der Schlüsselfingerabdruck wird gemeinsam mit Operation-ID und Attempt-Token vor der Aktivierung als Intent persistiert; die Aktivierung des privaten Schlüssels ist ein atomarer Schritt; die Reconciliation entscheidet ausschließlich anhand dieser Bindung, ob sie einen vorgefundenen aktiven Schlüssel übernimmt, als nachweislich eigenes verworfenes Artefakt entfernt oder mangels Zuordenbarkeit in den nichtterminalen Recoveryzustand geht. Maßgeblich ist dabei nach §6.2 die Operation-ID und nicht der einzelne Attempt-Token: Ein aktiver Schlüssel, dessen Intent zu einem Vorgängerversuch **derselben** Operation gehört, wird vom aktuellen Eigentümer unter dem Effekt-Lock und mit erneuter Eigentümerprüfung übernommen, weil die Saga sonst nach jedem Lease-Takeover hinter `key_activated` unauflösbar im Recoveryzustand endete.

**Akzeptanzvertrag**

- Browser und Controller führen keinen Git-Befehl aus; jede Git-Wirkung entsteht ausschließlich aus einer typisierten Control Operation im Worker.
- Der Auftragsdatenhash ist bytegenau reproduzierbar; `null` und ein vorhandener leerer Wert erzeugen verschiedene Werte, und eine verschobene Feldgrenze ebenfalls.
- Zwei Objektschlüssel derselben Ebene, die erst nach NFC identisch werden, führen zu einem Fehler und nicht zu einem verlorenen Schlüssel.
- Dieselbe Operation-ID mit abweichenden Auftragsdaten ist ein Konflikt, kein Update; identische Auftragsdaten erzeugen keinen zweiten Seiteneffekt.
- Je Projekt ist höchstens eine mutierende Operation aktiv; ein zweiter Auftrag mit anderer Operation-ID endet als benannter Sperrkonflikt.
- Auftragsdatensatz und Queue-Job existieren ausschließlich gemeinsam; es gibt weder einen Auftrag ohne Job noch einen verwaisten Job.
- Ein Absturz an jeder definierten Phasengrenze führt bei Wiederzustellung zu Reconciliation, genau einem terminalen Zustand und genau einem Ergebnisdatensatz.
- Ein abgelaufener Lease, ein Workerausfall ohne erneute Zustellung und ausgeschöpfte Retries hinterlassen keine dauerhaft gehaltene Projektsperre; der Reconciler requeued oder terminalisiert nachvollziehbar.
- Ein nur pausierter Versuch, dessen Kindprozess weiterläuft, führt zu keinem zweiten gleichzeitigen externen Effekt: Der Wrapper des neuen Versuchs erhält den Effekt-Lock erst nach dem Ende dieses Prozesses, der überholte Versuch bleibt auf seinen versuchsgebundenen Bereich beschränkt, und ein Ergebnis gelangt ausschließlich über den eigentümergebundenen Publish in den geteilten Bestand. Ein `SIGKILL` auf die gemeldete Prozesskennung beendet dabei das wirkende Programm selbst; ein Wrapperprozess, dessen Tod den Lock ohne Ende der Wirkung freigäbe, existiert nicht.
- Kein Außeneffekt eines Versuchs läuft ohne Effekt-Lock: Auch der vom Worker selbst ausgeführte Publish hält ihn und prüft nach dem Erwerb erneut die Eigentümerschaft, sodass sich die Publishschritte zweier Versuche nicht verschränken.
- Der Lock wirkt instanzübergreifend über das geteilte persistente Volume: Sein Lockverzeichnis liegt in genau diesem Volume und nicht auf containerlokalem `tmpfs`, zwei Workerinstanzen serialisieren auf demselben Lockobjekt, und das abrupte Ende der lockhaltenden Instanz gibt den Lock frei, sodass die andere Instanz ihn anschließend erwirbt. Seine Lockobjekte stammen aus dem einmaligen `init`-Startschritt und nicht aus dem Start eines Workercontainers, der Workerdienst läuft dabei unprivilegiert, und der unprivilegierte Workerbenutzer kann im Lockverzeichnis weder erzeugen noch löschen noch ersetzen. Dieser Nachweis liegt hier und nicht in `AI6-006A`, weil Workervolume, Rollenbild und `docker-compose.yml` erst mit diesem Ticket entstehen; er verlangt zusätzlich ein manuelles Gate in der realen Compose-Laufzeit, weil er von Volumetreiber und Dateisystem abhängt.
- Ein Fehlschlag hinterlässt kein verwaistes Artefakt: `provisioning_failed` entfernt das private Schlüsselmaterial des gescheiterten Versuchs, und ein verworfener oder terminalisierter Versuch hinterlässt weder Stagingbereich noch versuchseigenen Ref-Bereich.
- Ein nach Lease-Übernahme wieder erwachter älterer Ausführungsversuch kann weder Heartbeat noch Phase, Ergebnis oder Sperrfreigabe schreiben und keinen neuen Kindprozess starten; jeder Versuch endet als benannter Fencing-Konflikt. Ein bereits laufender Kindprozess darf weiterlaufen, bleibt aber auf seinen versuchsgebundenen Stagingbereich beschränkt und erreicht den geteilten Bestand nicht.
- Zwischen Spawn und persistierter Prozesskennung wirkt kein Kindprozess: Der blockierende Wrapper löst vor seiner Freigabe keinen externen Effekt aus.
- Der Übergang nach `provisioning` ist an genau eine Operation-ID gebunden; nur diese finalisiert, ein Fehlschlag endet in `provisioning_failed`, und zwei gleichzeitige Aufträge erzeugen genau ein Schlüsselpaar.
- Ein Absturz zwischen der atomaren Aktivierung des privaten Schlüssels und dem Datenbank-Commit sowie zwischen dem Commit und der Terminalisierung hinterlässt keinen dauerhaft inkonsistenten Zustand: Weder bleibt ein aktiver Schlüssel ohne Provisionierungszustand noch ein `provisioning`-Zustand ohne aktiven Schlüssel bestehen, und die Reconciliation entscheidet ausschließlich anhand der persistierten Bindung aus Operation-ID, Attempt-Token und Schlüsselfingerabdruck. Diese Zusage gilt ausdrücklich auch dann, wenn zwischen Absturz und Fortsetzung ein Lease-Takeover unter höherem Attempt-Token liegt.
- Eine Operation im Recoveryzustand ist auflösbar: Die typisierte, step-up-autorisierte Recoveryentscheidung führt sie über eine der drei zulässigen Entscheidungen in genau einen terminalen Zustand oder in einen neuen Versuch; eine Entscheidung gegen einen veralteten Befund und eine wiederholt zugestellte Entscheidung bleiben wirkungslos.
- Privates Deploy-Key-Material erscheint in keiner Antwort, keiner Oberfläche und keinem Log.

**Mindestens zu erzeugende Testfälle**

- Golden-Vektoren des Auftragsdatenhashs je Operationstyp, Kollisionsprobe, `null` gegen leeren Wert sowie NFD-/NFC-, Property-Key- und Escape-Vektoren des Autorisierungssnapshots einschließlich eines negativen Vektors, dessen zwei Schlüssel erst nach NFC kollidieren.
- Über alle definierten Phasen parametrisierte Crash-Injection mit Prüfung von Außenzustand, terminalem Zustand und genau einem Ergebnisdatensatz.
- Nebenläufigkeitstests mit zwei verschiedenen Operation-IDs sowie zwei gleichzeitigen Provisionierungsaufträgen.
- Transaktionstests für den gemeinsamen Erfolgsfall von Auftrag und Job, für den Rollbackfall und gegen verwaiste Jobs.
- Lease-Tests für Workerausfall, abgelaufenen Lease und ausgeschöpfte Retries einschließlich Nachweis, dass der Reconciler die Sperre nicht blind löst.
- Fencing-Test mit tatsächlich weiterlaufendem Kindprozess: Der alte Versuch wird pausiert, sein Prozess läuft weiter, der Lease läuft ab, und der Reconciler stellt sofort erneut zu; der Wrapper des neuen Versuchs blockiert nachweisbar am Effekt-Lock, bis der alte Prozess endet, und alle Schreib- und Publishversuche des alten Versuchs werden abgewiesen. Der Test tötet dabei gezielt die gemeldete Prozesskennung und weist nach, dass damit das wirkende Programm endet und kein Elternprozess überlebt. Ein zweiter Test belegt den Lockkonflikt bei überschrittener Frist.
- Serialisierungstest des Publish: Der wirkende Publishabschnitt des Workers blockiert nachweisbar, solange ein Kindprozess desselben Projekts den Effekt-Lock hält, und eine unmittelbar nach dem Erwerb entzogene Eigentümerschaft führt zum Abbruch vor jeder Wirkung.
- Instanzübergreifender Locktest in der Compose-Laufzeit: Zwei Workerinstanzen auf demselben persistenten Volume serialisieren auf demselben Lockobjekt, die blockierte Instanz erwirbt ihn nach dem abrupten Ende der haltenden Instanz, und ein Architekturtest lässt jede Konfiguration scheitern, die das Lockverzeichnis außerhalb dieses Volumes oder in ein für den Workerbenutzer beschreibbares Verzeichnis legt.
- Bereinigungs- und Retrytests: Nach `provisioning_failed` existiert kein privates Schlüsselmaterial des gescheiterten Versuchs, nach einem verworfenen Versuch kein Stagingbereich und kein versuchseigener Ref-Bereich, und ein Symlink im Bereinigungspfad führt nicht aus dem verwalteten Root hinaus.
- Fencing-Test: Nach Lease-Übernahme durch einen neuen Versuch wird der alte Versuch wieder aufgeweckt; sämtliche Heartbeat-, Phasen-, Ergebnis-, Freigabe- und Prozessstartversuche werden abgewiesen, und der geteilte Bestand bleibt unverändert.
- Launchvertragstest: Der Kindprozess wird angehalten, bevor er wirkt; zu diesem Zeitpunkt liegen Launch-Intent, Prozesskennung und Startzeitpunkt persistiert vor. Ein Testdoppel, das den Wrapper vor der Persistenz freigibt, lässt den Testfall fehlschlagen.
- Zustands- und Retrytests der Provisionierung einschließlich `provisioning_failed` und fremder Operation-ID.
- Crash-Injection der Provisionierungssaga je Phasengrenze, ausdrücklich einschließlich der Grenze nach der atomaren Aktivierung vor dem Datenbank-Commit und der Grenze nach dem Commit vor der Terminalisierung. Getrennt geprüft werden ein aktiver Schlüssel mit dem Fingerabdruck eines Intents **derselben** Operation, ein aktiver Schlüssel einer nachweislich anderen Operation und ein keinem Intent zuordenbarer aktiver Schlüssel; nur der erste Fall wird übernommen, die beiden anderen führen in den nichtterminalen Recoveryzustand und löschen nichts.
- Takeover-Test der Provisionierungssaga: Absturz hinter `key_activated`, Ablauf des Lease, Übernahme durch einen neuen Versuch unter höherem Attempt-Token, anschließendes Wiedererwachen des alten Versuchs. Danach existiert genau ein aktiver Schlüssel, er passt zum Datenbankzustand, die Operation ist terminal, und der alte Versuch endet als Fencing-Konflikt. Ein Aufbau, der die Übernahme wegen des fremden Attempt-Token verweigert, lässt den Testfall fehlschlagen.
- Recoveryentscheidungstests: Jede der drei Entscheidungen führt aus dem Recoveryzustand heraus; eine Entscheidung ohne frisches Step-up, ohne globale Administratorrolle, gegen einen veralteten Befundhash oder ein zweites Mal zugestellt bleibt wirkungslos, und ein vollständiger Reconcilerlauf verändert den Zustand vor der Entscheidung nicht.
- Nachweis, dass die Bereinigung den aktiven Schlüssel nicht erfasst: Nach terminal erfolgreicher Provisionierung existiert der private Deploy-Key weiterhin, liegt außerhalb jedes versuchsgebundenen Bereichs und ist mit dem gespeicherten öffentlichen Schlüssel als Paar verwendbar.
- Negativtests der Managed-Path-Konstruktion für Traversal, Case-Fold-Kollision und Symlink-Alias.

**Nicht Teil dieses Tickets**

- Clone, Fetch, Bootstrapbindung, Control-OID-Autorität und Control-Branch-Wechsel.
- Read Models, Refresh und deren Redaction.
- Ticketparser, Arbeitsbranches und Push.

### AI6-006D — Managed-Clone, Clone und Fetch

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-006C`
- **Requirement-Refs:** `GIT-001`, `GIT-009`
- **Erwartete Module:** `Git`, `Projects`

**Ziel**

Den Managed-Clone über typisierte Operationen beschaffen und aktuell halten und die aktive Control-Bindung ausschließlich aus einem bestätigten eigenen Ergebnis fortschreiben.

**Deliverables**

- Die Operationstypen Clone und Fetch auf dem Kern aus AI6-006C; beide aktualisieren ausschließlich die konfigurierte Ref-Allowlist.
- Zweistufige Bootstrapbindung des ersten Clone über einen read-only Remote-Probe: Der ermittelte OID wird als bestätigte Bindung des Auftrags gespeichert, und der Clone läuft gegen genau diesen OID.
- Der erste Clone entsteht in einem versuchsgebundenen Stagingbereich und wird ausschließlich im eigentümergebundenen Publish in den Managed-Path überführt; ein abgebrochener Versuch hinterlässt keinen halben Clone.
- Auch der Fetch wirkt versuchsgebunden nach §6.2: Er schreibt ausschließlich in einen je Paar aus Operation-ID und Attempt-Token eigenen Ref-Bereich des Managed-Clone, und die allowlisteten Live-Refs bewegt ausschließlich der eigentümergebundene Publish. Ein überholter Versuch verändert damit weder Live-Ref noch Bindung, auch wenn sein Prozess noch läuft.
- Vollständige Isolation des übrigen Git-Metadatenbaums beim Fetch: `FETCH_HEAD` wird nicht geschrieben, automatische Maintenance und Garbage Collection sind für den Lauf abgeschaltet, und zulässige Metadatenänderungen sind als explizite Allowlist festgelegt — versuchseigene Refs und Objektzugänge — statt als Aufzählung bekannter Verstöße.
- Fortschreibung der aktiven Control-OID-Bindung im eigentümergebundenen Publish per Compare-and-Swap gegen ihren Versionszähler, ausschließlich nach erfolgreichem Clone oder Fetch.
- Operationstypspezifische Publish-Saga über die Grenze zwischen Git beziehungsweise Dateisystem und Datenbank, für die keine gemeinsame Transaktion existiert: benannte Phasen vom gestagten Effekt über den veröffentlichten Außenzustand und die finalisierte Bindung bis zu einer eigenen, eindeutig terminalen Abschlussphase, die Bereinigung und Freigabe der Operationssperre umfasst; ein vor der ersten wirkenden Phase persistierter Zielzustand, je Phase wiederholbare Schritte und eine Reconciliation, die den realen Git-Zustand gegen diesen Intent vergleicht, fehlende Schritte nachholt und einen abweichenden, von einem überholten Versuch hinterlassenen Außenzustand gegen den eigenen Intent herstellt. Der wirkende Teil des Publish läuft nach §6.2 unter dem Effekt-Lock; dessen Freigabe ist kein Bestandteil der Abschlussphase, weil das Betriebssystem ihn bereits mit dem jeweils wirkenden Prozess beziehungsweise Abschnitt freigegeben hat. Genau eine Phase ist terminal; keine Phase ist gleichzeitig terminal und Vorstufe einer weiteren. Crash-Injection an jeder dieser Grenzen ist Pflicht.
- Benannter Konfliktpfad für einen verlorenen Bindungs-Compare-and-Swap nach dem Veröffentlichen des Außenzustands, getrennt je Ursache. Scheitert er am Attempt-Token, ist der Versuch überholt: Ab der Konflikterkennung verändert er weder Live-Ref noch Bindung und setzt insbesondere nichts zurück — der bereits veröffentlichte Außenzustand kann seinem eigenen Intent entsprechen, gehört aber nicht mehr ihm. Er räumt ausschließlich seinen versuchseigenen Bereich und endet als Fencing-Konflikt; die Reconciliation des neueren Versuchs stellt den Außenzustand gegen dessen — gegebenenfalls abweichenden — Intent her. Scheitert er dagegen am Versionszähler, obwohl der Versuch die Sperre noch hält, ist eine Invariante verletzt: Der Versuch löst keine weitere Außenwirkung aus und setzt nichts zurück, wird aber nicht terminal freigegeben. Die Operation geht nach §6.2 in einen benannten, sichtbaren und nichtterminalen Recoveryzustand, der die Projektsperre hält, bis die Reconciliation die fehlende Bindung gegen den dann aktuellen Versionszähler nachgezogen hat oder ein Mensch ausdrücklich entschieden hat.
- Anzeige von Bindung, Aktualisierungszeit und Staleness sowie Auslösen von Clone und Fetch; Controller und Ansichten starten keinen Prozess und lesen den Managed-Clone nicht.

**Akzeptanzvertrag**

- Clone und Fetch verändern ausschließlich die Refs der Allowlist; alle übrigen lokalen und entfernten Refs bleiben unverändert.
- Der erste Clone besitzt eine definierte Bindung; eine Abweichung zwischen Probe und geklontem Control-Head endet als Bindungsfehler ohne verwendbaren Managed-Clone.
- Die aktive Control-OID-Bindung wird ausschließlich nach erfolgreichem Clone oder Fetch per Compare-and-Swap fortgeschrieben; ein verlorener Wettlauf ist ein sichtbarer Konflikt ohne Überschreiben.
- Der Publish gehört exakt dem Paar aus Operation-ID und Attempt-Token; ein wieder erwachter älterer Versuch veröffentlicht weder Clone noch Bindung.
- Kein Absturz an einer Publishgrenze hinterlässt einen dauerhaft inkonsistenten Zustand: Weder bleiben veröffentlichte Live-Refs bei alter Datenbankbindung noch eine neue Bindung bei alten Live-Refs noch ein veröffentlichter Clone ohne finalisierte Bindung; die Reconciliation führt jeden dieser Zustände in genau einen terminalen über. Ein Versionszählerkonflikt bei gehaltener Sperre wird dabei nicht terminalisiert, sondern bleibt sichtbar nichtterminal und hält die Projektsperre, bis Außenstand und Bindung wieder zusammenpassen.
- Ein verworfener Versuch hinterlässt keinen Stagingbereich und keinen versuchseigenen Ref-Bereich.
- Ein Fetchversuch, der nach seinem Prozessstart den Lease verliert und seinen Publish noch nicht begonnen hat, verändert keinen allowlisteten Live-Ref und keine Bindung; seine Wirkung bleibt in seinem versuchseigenen Ref-Bereich und wird verworfen.
- Zwei gleichzeitig eingestellte Fetchaufträge führen zu genau einer Ausführung; der zweite endet als benannter Sperrkonflikt.
- Browser, Controller und Livewire-Komponenten starten keinen Git- oder Kindprozess.

**Mindestens zu erzeugende Testfälle**

- Git-Fixture für Clone und Fetch gegen ein lokales Remote einschließlich Nachweis unveränderter fremder Refs.
- Bootstrapbindungstest mit zwischen Probe und Clone verschobenem Remote.
- Staging- und Publishtest: Ein Abbruch vor dem Publish hinterlässt keinen verwendbaren Managed-Clone, und ein Wiederholungsversuch führt zu genau einem.
- Crash-Injection an jeder Publishgrenze je Operationstyp: nach dem gestagten Effekt vor dem Veröffentlichen des Außenzustands, nach dem Veröffentlichen vor der Bindungsfinalisierung und nach der Finalisierung vor der Abschlussphase; je Fall werden Außenzustand, Bindung und genau ein terminaler Zustand geprüft. Ein Fall prüft zusätzlich, dass die Reconciliation einen vom überholten Versuch veröffentlichten, vom eigenen Intent abweichenden Außenzustand auf den eigenen Intent bringt.
- Fehlerinjektion des Bindungs-Compare-and-Swap genau nach dem Veröffentlichen des Außenzustands, getrennt für Attempt-Token-Konflikt und Versionszählerkonflikt: Im ersten Fall wird der Außenzustand bei der Konflikterkennung festgehalten, bleibt durch den überholten Versuch danach unverändert und wird von der Reconciliation des neueren Versuchs auf dessen abweichenden Intent gebracht; im zweiten bleibt die Operation sichtbar nichtterminal, hält die Projektsperre, und weder der Reconciler noch ein Folgelauf terminalisiert sie mit inkonsistentem Außenstand.
- Git-Metadatentest des Fetch: Der vollständige Metadatenbaum wird vor und nach dem Lauf verglichen; jede Änderung außerhalb der Allowlist — insbesondere `FETCH_HEAD` oder eine Maintenance-Spur — lässt den Testfall fehlschlagen.
- Bereinigungstest: Nach einem verworfenen Versuch existiert weder sein Stagingbereich noch sein versuchseigener Ref-Bereich.
- CAS-Test der aktiven Bindung einschließlich verlorenem Wettlauf.
- Fencing-Test des Publish mit wieder erwachtem älterem Versuch.
- Lease-Takeover-Test des Fetch: Ein Fetchversuch verliert nach seinem Prozessstart den Lease an einen höheren Attempt-Token und läuft weiter; die allowlisteten Live-Refs, die aktive Bindung und der Versionszähler bleiben durch ihn unverändert, und ausschließlich der neue Versuch veröffentlicht.
- Nebenläufigkeitstest zweier gleichzeitiger Fetchaufträge.
- Architekturtest gegen jeden Prozessstart aus Controller oder Livewire-Komponente.

**Nicht Teil dieses Tickets**

- Operationskern, Hashschema, Operationssperre, Reconciler und Deploy-Key-Provisionierung.
- Control-Branch-Wechsel, ausstehende Bindung und Invalidierungsgeneration.
- Read Models, Refresh und deren Redaction.
- Ticketparser, Arbeitsbranches und Push.

### AI6-006E — Control-Branch-Wechsel und Invalidierungsgeneration

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-005A`, `AI6-006D`
- **Requirement-Refs:** `GIT-001`, `GIT-009`
- **Erwartete Module:** `Git`, `Projects`

**Ziel**

Den Control-Branch eines Projekts ausschließlich autorisiert wechseln und alle davon abhängigen Bestände in derselben Entscheidung entwerten.

**Deliverables**

- Der Operationstyp Control-Branch-Wechsel mit eigener Phasenfolge und Reconciliation: Claim mit freier Operationssperre und — sobald `AI6-013` das Runfeld erzeugt hat — ohne aktiven Run, danach read-only Remote-Probe des neuen Ref, danach eigentümergebundener Publish gegen unveränderten alten Branch, unveränderten alten Control-OID und unveränderten Versionszähler.
- Frisches Step-up als Voraussetzung; Repositoryinhalt kann den Wechsel weder auslösen noch autorisieren.
- Genau eine ausstehende Bindung aus ausstehendem Ref, ausstehendem OID und Quelloperation; sie entsteht im Publish, ersetzt eine vorhandene ausschließlich per Compare-and-Swap gegen den gemeinsamen Versionszähler, und eine stale Quelloperation kann sie weder setzen noch konsumieren.
- Derselbe Publish schreibt `projects.control_branch` auf den geprüften neuen Ref, leert die aktive Control-OID-Bindung, erhöht die `control_generation` und schreibt einen Auditeintrag mit Actor, Zeitpunkt sowie altem und neuem Branch und Control-OID — alles in einer Transaktion. Ohne diesen Branchwert bliebe nach dem konsumierenden Fetch eine aktive OID des neuen Branch neben einem `control_branch` des alten stehen.
- Erweiterung des Fetch aus AI6-006D: Er bindet beim Anlegen seines Auftrags das Tripel aus Quelloperation, Version und OID der ausstehenden Bindung in seine operationstypspezifischen Parameter und damit in den Auftragsdatenhash und konsumiert im Publish per Compare-and-Swap exakt dieses Tripel.
- Sperre gegen Aufträge mit erwartetem Control-Commit, solange eine ausstehende Bindung existiert.
- Die `control_generation` als einziger Zustandsträger der Invalidierung; spätere Tickets schreiben sie bei der Erzeugung ihrer Bestände mit und behandeln eine Abweichung als stale, statt einen zweiten Invalidierungsweg zu ergänzen.

**Akzeptanzvertrag**

- Ein Control-Branch-Wechsel verlangt frisches Step-up, Ref- und OID-Prüfung sowie einen erfolgreichen Claim; er setzt `control_branch` auf den neuen Ref, hinterlegt genau eine ausstehende Bindung mit Quelloperation, leert die aktive Bindung, erhöht die `control_generation` und schreibt den Auditeintrag in derselben Transaktion. Ein Zustand mit neuem Control-OID und altem `control_branch` oder mit neuem `control_branch` und unveränderter Generation entsteht auch nach einem Absturz nicht.
- Der Publish gelingt ausschließlich, wenn die Sperre exakt dem Paar aus Operation-ID und Attempt-Token gehört und alter Branch, alter OID und Versionszähler unverändert sind; jede Abweichung ist ein sichtbarer Konflikt ohne Wirkung.
- Genau eine ausstehende Bindung ist wirksam; eine stale Quelloperation kann sie weder setzen noch konsumieren.
- Ein Fetch, dessen gebundenes Pending-Tripel nicht mehr dem aktuellen entspricht, konsumiert nichts und endet als benannter Bindungskonflikt; er kann insbesondere keine später gesetzte Bindung konsumieren.
- Existiert eine ausstehende Bindung, setzt ausschließlich ein Fetch mit exakt passendem Control-Head die aktive Bindung und konsumiert die ausstehende dabei atomar; jede Abweichung endet als Bindungsfehler, und die ausstehende Bindung bleibt erhalten.
- Solange eine ausstehende Bindung existiert, wird kein Auftrag angelegt oder ausgeführt, der einen erwarteten Control-Commit voraussetzt.
- Eine Wiederzustellung erzeugt keinen zweiten Probe-, Publish-, Generations- oder Auditeffekt.
- Dieses Ticket legt kein Runfeld an; die Vorbedingung „kein aktiver Run“ ist Bestandteil der einen Anspruchsnaht, die `AI6-013` erweitert.

**Mindestens zu erzeugende Testfälle**

- Erfolgstest: neuer `control_branch`, ausstehende Bindung mit Quelloperation, geleerte aktive Bindung, erhöhte `control_generation` und Auditeintrag entstehen gemeinsam; ein Rollback lässt alle fünf unverändert.
- Negativtest gegen Repositoryinhalt als Autorität: manipulierte `.git/config`, manipulierte Projektdatei im verwalteten Baum und eine als Anweisung formulierte Repositorydatei erzeugen weder einen Auftrag noch eine Zustandsänderung.
- Publish-Negativtests: fremder Attempt-Token, veränderter alter OID, veränderter Versionszähler und veränderter alter Branch.
- CAS-Tests der ausstehenden Bindung einschließlich stale Quelloperation und veraltetem Versionszähler.
- Test des verzögerten Fetch: Wird die ausstehende Bindung nach dem Anlegen des Fetchauftrags autorisiert ersetzt, konsumiert dieser Fetch nichts.
- Test, dass ein zwischen Prüfung und Fetch verschobenes Remote als Bindungsfehler endet und die ausstehende Bindung erhält.
- Generationstest: Ein Bestand mit älterer `control_generation` gilt sofort als stale und ist nicht startbar; ein Architekturtest weist nach, dass kein zweiter Invalidierungsweg existiert.
- Crash-Injection je Phase des Branchwechsels sowie Negativtests ohne Step-up, mit ungültigem Ref und bei belegter Sperre.
- Nachweis, dass die Vorbedingungen des Claim in genau einer bedingten Aktualisierung ausgewertet werden; der Wettlauf gegen einen gleichzeitig startenden Run wird in AI6-013 geprüft, weil die Runsperre erst dort entsteht.

**Nicht Teil dieses Tickets**

- Operationskern, Hashschema, Operationssperre und Reconciler.
- Clone, Fetch-Grundvertrag und Bootstrapbindung.
- Read Models, Refresh und deren Redaction.
- Runsperre, Runzustand und der nebenläufige Nachweis gegen einen gleichzeitig startenden Run.

### AI6-006F — Blobgebundene Read Models und Einzelpfad-Refresh

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-006E`
- **Requirement-Refs:** `GIT-001`, `GIT-009`, `SEC-007`
- **Erwartete Module:** `Projects`, `Git`

**Ziel**

Das Ergebnis einer Control Operation blobgebunden und redigiert projizieren, ohne vor AI6-007 Ticketgültigkeit zu behaupten.

**Deliverables**

- Erweiterung des Control-Operation-Kerns um den Operationstyp Refresh; Auftragsvertrag, Hashschema, Idempotenz und Ergebnisbindung werden übernommen, nicht neu definiert.
- Refresh als typisierte Einzelpfadoperation mit serverseitiger kanonischer Pfadvalidierung gegen einen ausschließlich serverseitig konfigurierten Basispfad. Die Oberfläche darf genau einen Kandidatenpfad vorschlagen; er gilt als untrusted, bestimmt weder Basispfad noch Autorität und wird von Server und Worker unabhängig kanonisiert, autorisiert und geprüft. Repositoryinhalt bestimmt weder Basispfad noch Auswahl.
- Bindung jedes Read Models an die `control_generation` aus AI6-006E: Die zur Erzeugungszeit gültige Generation wird mitgeschrieben, und eine davon abweichende Generation macht die Projektion stale.
- Blobgebundene Projekt-/Ticket-Read-Model-Basis, deren Redaction ausschließlich den zentralen AI6-003-Redactor verwendet; vor AI6-007 veröffentlicht sie höchstens einen ausdrücklich `unparsed` Envelope ohne Contract-Hash und behauptet keine Ticketgültigkeit. Das Validierungsprofil ist in diesem Zustand ausdrücklich `null`; AI6-007 und AI6-010 setzen es später und projizieren betroffene Read Models neu.
- Read-Model-Redactionmetadaten mit Zustand, Feld/Span, stabilem Marker, Fingerprintversion/-Key-ID und zentral erzeugtem projektgebundenem HMAC-Fingerprint ohne Klartext oder unkeyed Digest.
- Sichtbare Bindung, Aktualisierungszeit und Staleness in der Projektansicht. Die Staleness folgt nach §6.2 aus getrennten Prädikaten, die alle beim Lesen verglichen und nie geschrieben werden: abweichende `control_generation` nach einem Control-Branch-Wechsel, abweichender projizierter Control-Commit gegenüber der aktuellen aktiven Control-OID nach einem Fetch und abweichende Profilbindung nach einem Profilwechsel. Ein **vor** seinem Außeneffekt verlorener Publish-Compare-and-Swap verändert keinen Bestand und macht deshalb auch keinen stale; für einen nach `outcome_published` verlorenen Bindungs-Compare-and-Swap gelten dagegen die beiden Konfliktpfade aus §6.2, weil der Außenzustand dann bereits verändert ist.

**Akzeptanzvertrag**

- Workerresultate sind an erwarteten Control-Commit und Blob-SHA gebunden und weisen Staleness sichtbar aus; die App greift nie direkt auf den Managed-Clone zu.
- Ein Ergebnis, dessen Operation-ID, Projekt, Pfad, Control-Commit oder Blob-SHA nicht zum geladenen Auftrag gehört, wird nach Prozessende und vor jeder Persistenz und Projektion verworfen.
- Git-Modul und Read-Model-Projektion definieren keine eigenen Redactionpatterns.
- Eine vertragsinhaltliche Redaction ist sichtbar und macht die Projektion für Approval und Editor technisch unzulässig; `unparsed` ist ebenfalls keine Editor- oder Approvalquelle.
- Refresh akzeptiert ausschließlich einen kanonischen Pfad unterhalb des serverseitigen Basispfades und lehnt Symlink, Nicht-Blob und Ausbruch ab.
- Ein `unparsed` Read Model trägt `validation_profile = null`; ein Profilwert entsteht in diesem Ticket nicht.

**Mindestens zu erzeugende Testfälle**

- Stale-Read-Model- und OID-/Blob-Bindungstests je Prädikat getrennt — Generationsabweichung nach Branchwechsel, Control-Commit-Abweichung nach Fetch, Profilabweichung — sowie Nachweis, dass ein **vor** dem Außeneffekt verlorener Publish-Compare-and-Swap keinen Bestand stale macht, und Test, dass `validation_profile` im `unparsed`-Zustand `null` ist.
- Zustandsunionstest für `unparsed` mit `ticket_contract_sha256=null` sowie Redactionstatus-/HMAC-Fingerprinttest einschließlich Projektisolation und Key-ID ohne Klartextleck und Fail-closed-Marker für Approval/Editor.
- Pfadvalidierungsmatrix für Refresh einschließlich Symlink, Nicht-Blob und Basispfadausbruch sowie Kandidatenpfad außerhalb des Basispfades und ohne Berechtigung.
- Negativtests der Ergebnisbindung für fremdes Projekt, fremden Pfad, denselben Pfad mit Blob aus einem anderen Commit und denselben Blob unter einem anderen Auftragskontext.

**Nicht Teil dieses Tickets**

- Ticketparser.
- Arbeitsbranches und Push.

### AI6-007 — Ticketparser V1, Legacy-Leser und Validator

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `medium`
- **Kind:** `feature`
- **Depends on:** `AI6-006F`
- **Requirement-Refs:** `TKT-001`, `TKT-002`, `TKT-003`, `TKT-004`, `TKT-008`, `TKT-009`, `TKT-010`, `TKT-011`
- **Erwartete Module:** `Tickets`

**Ziel**

Git-native Tickets zuverlässig lesen, normalisieren und mit verständlichen Fehlern validieren.

**Deliverables**

- TicketDocument-DTO.
- V1-Parser für YAML-Frontmatter plus Markdown.
- Read-only-Legacy-Parser für bisherigen YAML-Codeblock.
- Getrennte Validatoren für das generische Profil `generic_v1` und das serverseitig definierte AI6-Detailprofil `ai6_detail_v1`.
- Extraktion von `## Goal`, `## Tasks`, AC-/TC-IDs, files und ausschließlich kanonischen Requirement-ID-`spec_refs`; genaue §-Hinweise werden nur als deutsche Prosa gelesen.
- Kanonischer `ticket_contract_sha256` exakt nach dem NFC-/YAML-/JSON-/LF-/Längenframing-Vertrag aus §5.2, der ausschließlich den Status-Key ausnimmt.
- Projektion in den Read-Model-Ergebnisvertrag: `invalid` mit strukturierten Fehlern und `ticket_contract_sha256=null` oder `valid` mit verpflichtendem Contract-Hash; kein Hash aus teilweise geparsten Daten.
- Validator und projektweiter Abhängigkeitsgraph.
- Einbindung in den laufenden Projektionspfad: Jeder nach diesem Ticket abgeschlossene Refresh projiziert unmittelbar mit dem wirksamen serverseitigen Validierungsprofil; ein Read Model im Zustand `unparsed` entsteht danach nicht mehr neu.
- Reprojektion der vor diesem Ticket erzeugten `unparsed` Read Models: Sie werden über ihren Zustand `unparsed` gefunden, unter dem serverseitig konfigurierten Vorgabeprofil neu projiziert und an genau dieses Profil gebunden. Der Backfill schreibt per Compare-and-Swap gegen Projekt, relativen Pfad, Control-Commit und Blob-SHA; ein zwischenzeitlich neueres Refreshergebnis, eine höhere `control_generation` und ein Profilwechsel werden von ihm nicht überschrieben, sondern lassen ihn für diesen Pfad wirkungslos enden; bis `AI6-010` den freigegebenen Profilwert liefert, ist der Vorgabewert der aus §6.2 bekannte `generic_v1`. Repositoryinhalt bestimmt das Profil nicht.
- Verpflichtende Profilqualifikation jedes Gültigkeitsbefunds: Ein Read Model weist Gültigkeit ausschließlich relativ zu seinem gebundenen Validierungsprofil aus, und keine Ausgabe behauptet unqualifizierte Gültigkeit. Ist das erklärte Profil eines Projekts nach §19 `ai6_detail_v1`, gilt ein ausschließlich unter dem Bootstrap-Vorgabeprofil `generic_v1` geprüftes Dokument nicht als detailprofilgültig; es trägt bis zur Bindung an das erklärte Profil ein maschinenlesbares Fail-closed-Kennzeichen und ist nicht approvable.

**Akzeptanzvertrag**

- `schema: ai6.ticket.v1`, id, title, status, depends_on und ein nicht leerer Goal-Abschnitt sind Pflicht.
- Dateiname und ID stimmen überein; die Standard-ID-Regel akzeptiert unter anderem M169 und AI6-001.
- Ein Kandidat ist ausschließlich ein regulärer, nicht verlinkter Git-Blob direkt unter `tickets_path` mit exakt `<TICKET-ID>.md`; Groß-/Kleinschreibung und Erweiterung werden ASCII-/bytegenau geprüft.
- Dateien mit anderer oder mehrfacher Erweiterung, Unterverzeichnisse, Symlinks und Namen außerhalb der ID-Regel werden ignoriert. Case-fold-Kollisionen, doppelt deklarierte IDs und ein Konflikt zwischen Dateiname und deklarierter ID sind dagegen projektweite Validierungsfehler.
- Zyklen, Selbstabhängigkeit und fehlende Tickets werden erkannt.
- Pfade verlassen das Repository nicht.
- Unbekannte oder doppelte Frontmatter-Schlüssel sind Fehler.
- Parser-/Schemafehler bleiben blobgebunden als `invalid` lesbar und reparierbar, aber nicht approvable; ausschließlich vollständig gültige Dokumente erhalten einen Contract-Hash.
- Nach diesem Ticket trägt kein Read Model mehr `validation_profile = null`: Jedes vorher `unparsed` projizierte Modell ist unter dem serverseitigen Vorgabeprofil neu projiziert und an dieses Profil gebunden, und jeder danach abgeschlossene Refresh projiziert unmittelbar mit dem wirksamen Profil.
- Ein Backfillergebnis überschreibt kein neueres Refreshergebnis: Bei abweichendem Control-Commit, abweichendem Blob-SHA, höherer `control_generation` oder gewechseltem Profil bleibt der Backfill für diesen Pfad wirkungslos und meldet den Konflikt.
- Jeder Gültigkeitsbefund nennt sein gebundenes Profil; eine Ausgabe ohne Profilangabe existiert nicht. Ein unter `generic_v1` gültiges Dokument eines Projekts mit erklärtem Profil `ai6_detail_v1` wird nicht als detailprofilgültig ausgewiesen und bleibt fail closed nicht approvable.

**Mindestens zu erzeugende Testfälle**

- Parser-Fixtures V1 und Legacy.
- Zyklus-/Pfad-/ID-/Status-Negativtests.
- Matrix für exakte `.md`-Endung, Groß-/Kleinschreibung, Mehrfachendung, Unterverzeichnis, Symlink, Case-fold-Kollision, doppelte ID und Dateiname-/ID-Konflikt.
- Ignorierte Nicht-Ticketdateien wie `README.md` erscheinen weder im Ticketbestand noch im Fehlerbericht.
- Profiltests zeigen, dass ein generisches V1-Ticket ohne AI6-Detailfelder gültig sein kann, während dasselbe Dokument unter `ai6_detail_v1` fehlschlägt.
- Qualifikationstest: Für ein Projekt mit erklärtem Profil `ai6_detail_v1` trägt ein ausschließlich unter dem Bootstrap-Vorgabeprofil geprüftes Dokument das gebundene Profil sichtbar, ist nicht als detailprofilgültig ausgewiesen und wird von der zentralen Prüfmethode als Approval- und Editorquelle abgewiesen.
- Gemeinsame Golden-Vektoren für generic/Detail, Statuswechsel mit identischem Hash, JCS-Sonderzeichen/Unicode, semantisch gleiches YAML/CRLF und null/ein/mehrere finale LF mit identischem Contract- aber verschiedenem Blob-SHA sowie Prosa-/Listen-/innerer-Whitespaceänderung mit verschiedenem Contract-Hash.
- Ergebnisunionstests für `invalid|null` gegenüber `valid|hash`, einschließlich YAML-Parsefehler und Profilvalidierungsfehler ohne Teilhash.
- M169 wird ohne Informationsverlust gelesen.
- Reprojektionstest: Ein vor diesem Ticket erzeugtes `unparsed` Read Model mit `validation_profile = null` wird gefunden, neu projiziert, trägt danach das serverseitige Vorgabeprofil und je nach Inhalt `valid` mit Contract-Hash oder `invalid` ohne Hash.
- Nebenläufigkeitstest von Backfill und Refresh: Ersetzt ein Refresh denselben Pfad währenddessen durch einen neueren Blob, gewinnt das Refreshergebnis, und der Backfill endet für diesen Pfad wirkungslos; dasselbe gilt für einen zwischenzeitlichen Profilwechsel und für eine erhöhte `control_generation`.
- Test des laufenden Pfades: Ein nach diesem Ticket ausgeführter Refresh erzeugt unmittelbar eine profilgebundene Projektion und kein `unparsed` Read Model.

**Nicht Teil dieses Tickets**

- Schreibende Migration.
- Ticketstatusänderung.

### AI6-008 — Responsive Ticketübersicht und Ticketdetail

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `medium`
- **Kind:** `feature`
- **Depends on:** `AI6-004`, `AI6-007`
- **Requirement-Refs:** `UI-001`, `UI-002`, `TKT-008`, `GIT-009`
- **Erwartete Module:** `Tickets`, `Projects`

**Ziel**

Tickets auf Laptop und Smartphone so darstellen, dass Status, Ziel und Abhängigkeiten sofort beurteilt werden können.

**Deliverables**

- Ticketliste mit Filtern und Validierungszustand.
- Detailansicht mit hervorgehobenen Pflichtfeldern und gerendertem Markdown.
- Sichtbarer Redactionstatus; maskierte Inhalte sind lesbar gekennzeichnet, aber besitzen keine Approval-/Edit-Aktion.
- Abhängigkeitsbadges mit Status.
- Asynchroner manueller Refresh über Control Operation mit sichtbarer Commit-/Blob-Bindung, Aktualisierungszeit und Staleness.

**Akzeptanzvertrag**

- id, title, status, depends_on und Goal sind ohne Aufklappen sichtbar.
- Ungültige Tickets sind mit strukturierten Fehlern lesbar und bei `redaction_state=clear` reparierbar, aber nicht approvable oder startbar; `unparsed` zeigt nur den Envelope/Refreshzustand.
- Mobile Darstellung benötigt kein horizontales Scrollen für Kerninformationen.
- Untrusted Markdown wird sicher gerendert.
- Liste und Detail lesen ausschließlich das redigierte Read Model; ein noch laufender oder fehlgeschlagener Refresh wird sichtbar und niemals durch synchronen Clonezugriff ersetzt.
- Sichere Markdown-/HTML-Darstellung verändert den unredigierten Vertragsinhalt nicht. `content_redacted` wird niemals als vollständig geprüfter Vertrag dargestellt.

**Mindestens zu erzeugende Testfälle**

- Livewire-/Featuretests für Liste und Detail.
- Mobile Browser-Smoke-Test.
- XSS-/Raw-HTML-Negativtest.
- UI-Test für unredigiert-sicher-gerendert gegenüber inhaltlich maskiert, einschließlich fehlender Approval-/Edit-Aktion.
- UI-Matrix `unparsed`, `invalid` ohne Contract-Hash, `valid` mit Contract-Hash und `content_redacted`; nur unredigiertes `invalid|valid` besitzt eine Edit-Aktion, nur `valid` eine Approval-Aktion.

**Nicht Teil dieses Tickets**

- Ticketbearbeitung.
- Runfreigabe.

### AI6-009 — Ticketbearbeitung, Statusübergänge und Git-Persistenz

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-006F`, `AI6-007`, `AI6-008`
- **Requirement-Refs:** `TKT-005`, `TKT-006`, `TKT-009`, `GIT-001`, `GIT-008`, `GIT-009`, `RUN-004`
- **Erwartete Module:** `Tickets`, `Git`

**Ziel**

Ticketinhalt und dauerhaften Status aus dem Panel konfliktfest als Git-Datei ändern.

**Deliverables**

- Actor-/operationsspezifische Status-Transition-Policy und gehärtete CAS-Primitive; reservierte Run-/Approval-Kanten sind in Editor und allgemeiner Status-UI technisch nicht aufrufbar.
- Markdown-/Frontmatter-Editor mit Blob-SHA und kanonischem Contract-Hash.
- Editorfreigabe ausschließlich aus `document_state=invalid|valid` und `redaction_state=clear`; eine maskierte oder `unparsed` Projektion kann weder geladen noch zurückserialisiert werden.
- Typisierte Control Operation, die im Worker validiert, per Compare-and-Swap committen und auf den Control-Branch pushen darf und danach das Read Model aus dem neuen Blob erneuert.
- Konfliktanzeige statt Überschreiben.
- Keine Synchronisation in README-/Docs-Indizes.

**Akzeptanzvertrag**

- Veralteter Blob oder Control-Branch erzeugt Konflikt ohne Änderung.
- Browser und Controller führen keinen Git-Befehl aus; Mutation und Read-Model-Aktualisierung bleiben als ein auditierter asynchroner Vorgang mit erwarteter OID nachvollziehbar.
- Jeder Schreibvorgang wird vor Commit validiert.
- Worker prüft unmittelbar vor Mutation erneut unveränderten Blob, `redaction_state=clear` und exakten unmaskierten Editor-Base-Inhalt; kein maskierter Marker gelangt in Git.
- Ein `invalid`-Dokument darf als Reparaturbasis dienen, aber ausschließlich ein anschließend vollständig `valid` geparster neuer Stand wird committed und erhält einen Contract-Hash.
- Nur die betroffene Ticketdatei ist Statusquelle.
- Commit enthält Benutzer, Grund und vorherigen Blob in Auditmetadaten.
- Unzulässige Übergänge werden abgelehnt.
- `todo → ready`, `ready → in_progress`, sämtliche aktiven Runtransitionen und `in_progress → review` können nur die später benannten Approval-, Claim-, Orchestrator- beziehungsweise Statussync-Operationen nutzen; AI6-009 bietet dafür keine vorgezogene UI-Aktion.
- Jeder Inhaltsedit eines `ready`-Tickets setzt im selben Git-CAS den Status auf `todo`; Blob-/Contractänderung macht bestehende Approval und Queue-Eligibility auch vor deren späterer DB-Projektion unstartbar.
- Während eines aktiven Runs sind normale Ticketedits gesperrt; Vertragsänderungen laufen ausschließlich über den Contract-Amendment-Flow.

**Mindestens zu erzeugende Testfälle**

- Zwei-Client-Konflikttest.
- Git-Commit-/Push-Integrationstest.
- Actor-/Operation-/Transition-Matrix einschließlich Direktmutations-Negativtests für jede reservierte Kante sowie human-owned `todo`-/`ready`-/`blocked`-/`review`-Kanten.
- Edit eines `ready`-Tickets mit atomarem `ready → todo`, unverändertem Resttree und sofort unstartbarer alter Approval-/Queuebindung.
- Validatorfehler führt zu keinem Commit.
- Reparaturtest von unredigiertem `invalid` zu `valid` sowie Negativtests gegen Edit/Statuswechsel aus `unparsed`/maskierter Projektion und gegen Roundtrip eines Redactionmarkers.

**Nicht Teil dieses Tickets**

- Konkrete Approval-, Run-Claim-, Cancel-/Rücksetzungs- und Post-Push-Statussync-Sagas; AI6-009 liefert nur Primitive und Policy.

### AI6-010 — Projektkonfiguration und freigegebene Config-Snapshots

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-003`, `AI6-006F`, `AI6-007`, `AI6-009`
- **Requirement-Refs:** `CFG-002`, `CFG-003`, `SEC-004`, `TKT-007`, `TKT-011`, `GIT-001`
- **Erwartete Module:** `Projects`, `Checks`, `Agents`

**Ziel**

Versionierte Projektdefaults nutzen, ohne dass Repositoryinhalt die Control-Plane oder Shellpolicy selbst autorisieren kann.

**Deliverables**

- Strenges Schema für .ai6/config.yaml einschließlich `scope.unlisted_paths` mit ausschließlich `auto_allow` oder `require_approval` und der Vorgabe `auto_allow`.
- Semantische Diffansicht und Approver-Freigabe.
- Effektiver Config-Snapshot mit Blob-SHA und Hash.
- Referenzen auf serverseitige Check-/Modellprofile.
- Allowlist-Referenz auf das serverseitige Ticketvalidierungsprofil; `generic_v1` und `ai6_detail_v1` sind semantisch getrennt.
- Bindung jedes Ticket-Read-Models an Validierungsprofil und Config-Snapshot: Ein Profilwechsel markiert die betroffenen Projektionen stale und erzwingt eine Reprojektion, bevor sie wieder als geprüft gelten.
- Bindung der Config-Snapshots an die `control_generation` aus AI6-006E: Jeder Snapshot schreibt die zur Erzeugungszeit gültige Generation mit, und eine davon abweichende Generation macht ihn stale. Dieses Ticket bewertet nicht selbst, ob ein Wechsel stattgefunden hat, und führt keinen zweiten Invalidierungsweg ein.
- Verbot von Securityflags, Shellstrings und unbekannten Schlüsseln.

**Akzeptanzvertrag**

- Run verwendet nur freigegebenen Snapshot.
- Agentenänderung an .ai6/config.yaml wirkt nicht auf aktuellen Run.
- Fehlende Datei verwendet Serverdefaults.
- Unsichere Checkdefinitionen werden abgelehnt.
- Repositoryinhalt kann weder die Definition des Validierungsprofils noch Control-Branch, Remote oder Managed-Path bestimmen.
- `scope.unlisted_paths` stammt ausschließlich aus der freigegebenen Projektkonfiguration; ein unbekannter Wert wird abgelehnt, und Ticket- oder Providerinhalt setzt ihn nicht.
- Ein Read Model, das unter einem anderen Validierungsprofil oder Config-Snapshot erzeugt wurde, ist stale und weder approvable noch startbar, bis es neu projiziert wurde.
- Nach einem Control-Branch-Wechsel ist der bisherige Config-Snapshot stale und kein Run verwendet ihn weiter; die Entwertung ergibt sich ausschließlich aus der abweichenden `control_generation`.

**Mindestens zu erzeugende Testfälle**

- Schema-/Snapshot-Tests.
- Semantischer Diff-Test.
- Negativtests für Securityflags, Shellstrings und unbekannte Profile.
- Profilwechseltest: bestehende Read Models werden stale, eine Freigabe wird bis zur Reprojektion abgelehnt, und nach der Reprojektion trägt die Projektion das neue Profil.
- Branchwechseltest: Ein Control-Branch-Wechsel erhöht die `control_generation`, wodurch der effektive Config-Snapshot stale wird; ein Architekturtest weist nach, dass kein zweiter Invalidierungsweg existiert.

**Nicht Teil dieses Tickets**

- Ausführung von Checks.
- Konkrete Modellprofile.

## 15.3 M2 – Freigabe und Run-Fundament

### AI6-011 — Agentenprofil-, Capability- und Promptkatalog

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `medium`
- **Kind:** `feature`
- **Depends on:** `AI6-003`, `AI6-004`
- **Requirement-Refs:** `CFG-001`, `AGT-001`, `AGT-002`, `AGT-008`, `AGT-009`, `REV-011`, `RUN-006`
- **Erwartete Module:** `Agents`, `Prompts`, `Shared`

**Ziel**

Zulässige Adapter-, Modell-, Rollen- und Effort-Kombinationen sowie den vor jeder Freigabe benötigten Promptvertrag zentral definieren und im Panel auswählbar machen.

**Deliverables**

- AgentProfile-DTO und Registry; serverseitige Providerprofil-Aliase mindestens für `codex_cli`, `grok_cli`, `github_copilot_cli` und `fake` als vertrauenswürdige Konfiguration.
- Rollen implementation, quality_review, finding_verification, security_review.
- Modell- und Effort-Allowlist je Adapter.
- Capability-Status verfügbar/nicht verfügbar/ungeprüft.
- Versionierter zentraler Promptkatalog für implementation, quality_review, fix, finding_verification, security_review und human_response samt kanonischem Renderer.
- Versionierte spezialisierte Review-Promptprofile nach `REV-011` als Profile desselben Katalogs und Renderers; kein zweiter Katalog und keine providerindividuellen Templates.
- Unveränderlicher Prompt-Snapshot mit Katalogversion, gerenderten Rollenprompts und kanonischem Hash.
- Vertrauenswürdige providerbezogene Instruction-Resolution-Profile mit Discovery-Namen, Rangfolge und Geltungsbereich sowie kanonischer `instruction_snapshot_hash`-Berechnung aus Pfad-/Blob-/Inhaltsbindung.
- Versionierte, versiegelte Provider-Runtime-Profile mit Hash über effektive Adapterflags, Permissions und serverseitig erlaubte MCP-/Plugin-/Skill-/Hook-/Command-Erweiterungen; Default ist jeweils deaktiviert.
- Serverseitige Maxima für Instruktionsdateien, Einzel-/Gesamtbytes, Importtiefe und finalen Promptinput sowie kanonische Cycle-/Duplicate-Erkennung; Projektinhalt kann sie nicht erhöhen.
- Read-only-UI/API für Auswahlfelder.

**Akzeptanzvertrag**

- Keine freie Modell- oder CLI-Flag-Eingabe.
- Ungültige Modell-/Effort-Kombination wird serverseitig abgelehnt.
- Profile sind unabhängig von Projektinhalt.
- Ein Approval kann für jede gewählte Rolle vor Bestätigung einen vollständigen Prompt-Snapshot erzeugen und anzeigen; dieselben Eingaben ergeben bytegleich denselben Hash.
- Native Provider-Instruktionen sind vor Approval vollständig auflösbar; Host-/Parent-Dateien, Symlinks und unbekannte Discoveryquellen werden abgelehnt.
- Projekt-/Workspace-/Home-Konfiguration und provider-native Erweiterungen können kein Runtime-Profil ergänzen; jede erlaubte Erweiterung ist serverseitig aufgelöst, angezeigt und hashgebunden.
- Zyklus, Duplikat oder Instruktions-/Promptinputlimit blockiert vor Snapshotfreigabe beziehungsweise Provideraufruf ohne partiellen Input.
- Fake-Profil steht für Tests bereit.

**Mindestens zu erzeugende Testfälle**

- Registry-Unit-Tests.
- Formvalidierung.
- Capability-Fallback-Tests.
- Golden-Tests für Renderer, Snapshot und Hash sowie Invalidierung nach autorisierter Promptkatalogänderung.
- Providerübergreifende Golden-Tests für Instruktionsreihenfolge, Geltungsbereich, Blob-/Inhaltshash, fehlende Datei, Symlink und ausgeschlossene Host-/Parent-Autodiscovery.
- Providerübergreifende Negativtests gegen Repository-/Workspace-/Home-Config, MCP, Plugins, Skills, Hooks, Commands, Agentdefinitionen und externe Helper sowie Hashinvalidierung nach autorisierter Runtime-Profiländerung.
- Grenz-, Eins-darüber-, Zyklus-, Duplikat- und Importtiefentests für alle Instruction-/Promptinput-Maxima.

**Nicht Teil dieses Tickets**

- Codex-/Grok-/Copilot-/Claude-Prozessaufrufe.
- Providerlogin.

### AI6-044 — Manuelle Prompt-Hilfe für Codex und Claude

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `medium`
- **Kind:** `feature`
- **Depends on:** `AI6-008`, `AI6-011`
- **Requirement-Refs:** `AGT-008`, `AGT-011`, `UI-001`, `UI-007`, `SEC-004`, `SEC-007`
- **Erwartete Module:** `Prompts`, `Shared`

**Ziel**

Einen globalen authentifizierten Promptarbeitsbereich bereitstellen, der statische und aus vollständigen Reviewantworten erzeugte Prompts für Codex- und Claude-Desktop-Sitzungen sicher in die Zwischenablage überträgt.

**Deliverables**

- Globaler Navigationseintrag und responsive Prompt-Hilfe ohne Projektbindung; jeder Zugriff verlangt eine vollständig authentifizierte Sitzung.
- Drei versionierte, providerneutrale manuelle Einträge im zentralen Promptkatalog: eigener Reviewbefund mit anschließendem Re-Review beheben, fremde Fixes read-only prüfen und re-reviewen sowie einen dynamischen Fixprompt aus einer Reviewantwort erzeugen. Die Texte sind knapp, vermeiden wiederholten Kontext, halten effektiven Scope und Vertragsgrenzen ein und autorisieren weder Ticketstatus- noch Instruktionsänderungen.
- Vollständige Reviewantwort als dynamische Eingabe bis einschließlich eines vertrauenswürdigen serverseitigen Maximums von 262144 UTF-8-Bytes; vor Parsing und Darstellung läuft sie durch die zentrale Redaction-Grenze und wird nie persistiert oder geloggt.
- Deterministische Extraktion genau eines terminalen Abschnitts, dessen Überschriftszeile exakt `### Fix-Liste` lautet; ausschließlich der nicht leere Inhalt bis zum Antwortende wird mit LF-Zeilenenden genau einmal in das katalogisierte Fixtemplate eingesetzt. Fehlende oder mehrfache Marker, leerer Inhalt und Grenzüberschreitung liefern eine generische sichtbare Ablehnung ohne Teilprompt; exakt `Nichts zu fixen.` liefert den sichtbaren Endzustand ohne Kopieraktion.
- Read-only Vorschau des vollständig gerenderten Prompts mit sichtbarer Katalogversion und Redactionstatus. Weder Eingabe noch Ergebnis erzeugen Approval-, Run-, Git-, Datenbank-, Session- oder Providerwirkung.
- Externe CSP-konforme Clipboard-Integration unter `/assets/`: bestätigter Erfolg wird als solcher gemeldet; bei verweigerter oder fehlender Clipboard-API wird die Vorschau vollständig ausgewählt und eine manuelle Kopieranweisung angezeigt, ohne Erfolg vorzutäuschen.
- Ergebnisfreies Abnahmeformular für den realen Copy/Paste-Weg in Codex Desktop und der Claude App.

**Akzeptanzvertrag**

- Gastzugriffe erreichen keine Promptinhalte; jeder vollständig authentifizierte Benutzer erreicht die globale Prompt-Hilfe ohne Projektwahl.
- Statische Kopieraktionen verwenden exakt die vom zentralen Renderer gelieferten Bytes; Codex und Claude besitzen keine duplizierten Templates.
- Bei einer gültigen vollständigen Reviewantwort erscheint ausschließlich die redigierte extrahierte Fix-Liste genau einmal im dynamischen Prompt; Text vor `### Fix-Liste` wird weder in Vorschau noch Clipboard übernommen.
- Fehlender oder mehrfacher Marker, leerer Abschnitt, ungültiges UTF-8 und 262145 Bytes erzeugen weder Vorschau noch Clipboard-Erfolg; 262144 Bytes werden akzeptiert, sofern der übrige Vertrag erfüllt ist.
- `Nichts zu fixen.` verhindert bewusst einen kostenerzeugenden Folgeprompt und wird als abgeschlossener Zustand angezeigt.
- Reload, Gerätewechsel und Serverlogs enthalten keine eingegebene Reviewantwort und keinen dynamisch gerenderten Prompt; es entsteht keine Historie und kein ausgehender Providerrequest.
- Redaction geschieht ausschließlich über die zentrale Naht vor Parsing und Ausgabe; maskierte Werte und Redactionstatus bleiben in der Vorschau erkennbar, der entfernte Klartext erscheint weder in HTML noch Clipboard.
- Die feste CSP bleibt unverändert; kein Inline-Script, Eventhandlerattribut, `unsafe-inline` oder `unsafe-eval` entsteht.
- Laptop- und Smartphoneansicht bleiben ohne horizontales Scrollen bedienbar; Tastaturfokus, Statusmeldung, Vorschauselektion und manueller Clipboard-Fallback sind zugänglich.

**Mindestens zu erzeugende Testfälle**

- Katalog-/Renderer-Golden-Tests für alle drei manuellen Einträge, stabile Versionen und identische providerneutrale Bytes.
- Authentifizierungs-, Navigations- und Routeninventurtest für Gast, vollständig authentifizierten Benutzer und projektunabhängigen Zugriff.
- Extraktions- und Grenztests für genau einen terminalen Marker, fehlenden und mehrfachen Marker, leeren Inhalt, CRLF-Normalisierung, `Nichts zu fixen.`, 262144 und 262145 Bytes sowie ungültiges UTF-8.
- Redactor-Integrationstest mit einem sensiblen Wert innerhalb der Fix-Liste und einem instruktionsartig formulierten Finding; nur das maskierte Datum wird als Datenblock genau einmal gerendert.
- Negativtest gegen Datenbank-, Session-, Log-, Queue-, Git-, Control-Operation- und Providerseiteneffekte.
- CSP-/Asset-/Clipboard-Vertragstest ohne Inlinecode und Browser-Smoke für Erfolg, verweigerte Clipboard-Berechtigung, vollständige Fallback-Selektion und mobile Breite hinter dem bestehenden expliziten Browser-Smoke-Flag.
- Manuelles Gate für bytegetreues Einfügen je eines statischen und dynamischen Prompts in Codex Desktop und die Claude App.

**Nicht Teil dieses Tickets**

- Automatisches Öffnen oder Steuern der Desktop-Apps.
- Provideraufrufe, Sitzungsfortsetzung oder Übernahme in einen AI6-Run.
- Freie Promptbearbeitung, Prompt-Historie oder Benutzer-/Projekttemplates.
- Ein zweiter Promptkatalog, Renderer oder providerindividuelle Varianten derselben manuellen Prompts.

### AI6-012 — Ticketprüfung, Approval-Snapshot und Multi-Reviewer-Auswahl

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-008`, `AI6-009`, `AI6-010`, `AI6-011`
- **Requirement-Refs:** `RUN-002`, `RUN-006`, `RUN-008`, `REV-001`, `REV-011`, `UI-003`, `AGT-002`, `AGT-008`, `AGT-009`, `GIT-001`, `GIT-007`, `TKT-009`
- **Erwartete Module:** `Tickets`, `Runs`, `Reviews`, `Agents`

**Ziel**

Ein geprüftes Ticket mit Implementierungsmodell, Aufwand, mehreren Reviewern, Limits und Pushmodus reproduzierbar freigeben.

**Deliverables**

- Assessment- und Approval-Datenmodell.
- Implementierungsprofil plus Effort.
- Wiederholbarer Reviewer-Editor mit Modell und Effort; je Slot werden zusätzlich das gewählte Review-Promptprofil, das Providerprofil und die serverseitig erzeugten Auswahlgründe nach `REV-011` im Approval-Snapshot persistiert.
- Sämtliche serverbegrenzt wirksamen Zeit-, Aufruf-, Review-, Fix-, zusätzlichen Scope-Pfad-, Datei-, Byte-, Instruction-Datei/-Byte/-Tiefe-, Promptinput- und Artefaktlimits einschließlich `max_added_scope_paths`, Attention-User und `push_mode` mit ausschließlich `manual` oder `automatic_after_gates`.
- `reviewed_ticket_blob_sha`, `reviewed_control_sha`, `approved_ticket_blob_sha`, `approved_control_sha` und `ticket_contract_sha256` sowie unveränderliche Snapshots/Hashes für Config, Scope, Prompts, native Instruktionsauflösung, versiegelte Provider-Runtime-Profile, Agentenprofile und SecurityPolicy.
- Approval-Saga als `status_operation_id`-gebundene Instanz des gemeinsamen Status-Saga-Vertrags mit erwartetem Todo-Parent/-Blob, Ready-Blob/-Tree, beabsichtigtem Commit, expliziten Phasen und Reconciler; sie schreibt per erwartetem Control-OID ausschließlich `todo → ready`, weist unveränderten Resttree und identischen Contract-Hash nach, bindet das resultierende Ready-Paar und weist getrennt davon Queue- sowie aktuelle Startberechtigung aus.
- Approvalquelle ausschließlich aus einem frischen Read Model mit `document_state=valid`, `redaction_state=clear` und vorhandenem Contract-Hash; `unparsed`, `invalid`, `content_redacted`, stale Bindung oder fehlgeschlagene erneute Worker-Validierung sperren die Aktion technisch.
- Bindung der Approvals an die `control_generation` aus AI6-006E: Jede Approval schreibt die zur Erzeugungszeit gültige Generation mit, und eine davon abweichende Generation macht sie stale und unstartbar. Dieses Ticket bewertet nicht selbst, ob ein Wechsel stattgefunden hat, und führt keinen zweiten Invalidierungsweg ein.

**Akzeptanzvertrag**

- Mindestens ein Implementierungsprofil und ein Reviewer sind erforderlich.
- Jeder Reviewer hat eigene stabile Slot-ID.
- Jede Ticketinhalt- oder Configänderung macht Approval unstartbar; reine orchestratorgesteuerte Statuswechsel verändern den Contract-Hash nicht.
- Ein Control-Branch-Wechsel macht bestehende Approvals unstartbar; die Entwertung ergibt sich ausschließlich aus der abweichenden `control_generation`.
- `approved_ticket_blob_sha`/`approved_control_sha` bezeichnen ausschließlich das nach dem Status-only-CAS entstandene Ready-Paar; die tatsächlich menschlich geprüfte Todo-Version bleibt separat und unveränderlich gebunden.
- Approval verlangt `document_state=valid`, `redaction_state=clear`, den unveränderten erwarteten Blob-SHA, vorhandenen Contract-Hash und eine erneute Worker-Validierung; eine ungültige oder maskierte Projektion kann weder bestätigt noch als unveränderte Vollansicht ausgegeben werden.
- Jede Änderung an Pfad, Blob, Rangfolge, Geltungsbereich oder Adapterprofil des aufgelösten Instruction-Snapshots macht Approval unstartbar; ein neuer Snapshot verlangt neue Freigabe und später eine neue Provider-Session.
- Nicht erfüllte Abhängigkeiten verhindern den Start, aber nicht Approval, `ready` oder das Einreihen; jede relevante Git-, Config-, Prompt-, Capability-, Policy- oder Abhängigkeitsänderung löst eine atomare Neubewertung aus.
- Approval zeigt alle wirksamen Defaults vor Bestätigung.
- Approval zeigt die effektiv geordnete native Instruktionsauflösung je Provider sicher gerendert mit Pfad, Geltungsbereich, Rang, Blob-SHA, Inhaltshash und Inhaltsdiff sowie das effektive versiegelte Runtime-Profil; der Mensch bestätigt beides ausdrücklich zusammen mit dem Prompt-Snapshot.
- Approval ist bei überschrittener Instruction-/Promptinputgrenze, Importzyklus oder kanonischem Duplikat nicht möglich; Projektconfig kann kein Servermaximum erhöhen.
- Doppelte identische Reviewer werden gewarnt oder verhindert.
- Retry und Recovery sind in jeder Sagaphase idempotent: Ein bereits bestätigter eigener Ready-Commit vervollständigt nur fehlende DB-Felder; ein fremder Ready-Commit, eine zusätzliche Treeänderung oder abweichende Historie erzeugt sichtbar einen Konflikt und niemals eine Approval.

**Mindestens zu erzeugende Testfälle**

- Approval-Hash-Tests gegen dieselben versionsgebundenen Golden-Vektoren des Ticketparsers.
- Saga- und Crash-Injection-Tests für Todo-Prüfbindung, Status-only-CAS, Ready-Bindung, Contract-Hash-Gleichheit sowie zusätzliche Ticket-/Treeänderung: vor Push, nach vorbereitetem Commit, nach bestätigtem Control-Push vor DB-Finalisierung, nach DB-Finalisierung und bei Retry jeder Phase.
- Reconciliation-Negativtests gegen fremden ähnlich aussehenden Ready-Commit, falschen Parent, History-Rewrite, abweichenden Resttree und Operation-ID-Replay.
- Approval-Negativtest für `unparsed`, `invalid|null`, `content_redacted`, stale Blob und Redactionmarker-Roundtrip; die maskierte Ansicht leakt keinen entfernten Klartext.
- Invalidierung nach Blob-/Configänderung.
- Branchwechseltest: Ein Control-Branch-Wechsel erhöht die `control_generation`, wodurch bestehende Approvals stale werden; ein danach versuchter Start wird mit sichtbarem Grund abgelehnt.
- `todo → ready`-/Queue-/Startberechtigungs-Matrix einschließlich später erfüllter und erneut unerfüllter Abhängigkeit.
- Prompt-Snapshot-Golden-Test und Invalidierung nach Katalog-/Profiländerung.
- Instruction-Snapshot-Golden-Test, Claim-Invalidierung nach Blob-/Hierarchie-/Adapterprofiländerung und Nachweis ausgeschlossener Hostinstruktionen.
- Feature-/Autorisierungstest der sicheren Approval-Ansicht für Reihenfolge, Geltungsbereich, Blob, Hash, Inhalt und Diff.
- Approval-Grenztests für Instruction-Dateien/-Bytes/-Tiefe, Promptinput, Zyklus und Duplikat.
- Mehrere Reviewer mit unterschiedlichen Efforts.
- Autorisierungs- und Step-up-Test.

**Nicht Teil dieses Tickets**

- Runstart.
- Providerausführung.

### AI6-013 — Run-State-Machine, Persistenz und Projektsperre

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-004`, `AI6-012`
- **Requirement-Refs:** `GIT-001`, `GIT-002`, `GIT-008`, `RUN-001`, `RUN-002`, `RUN-005`, `RUN-007`, `TKT-009`
- **Erwartete Module:** `Runs`

**Ziel**

Runs transaktional starten und ihren technischen Zustand zentral, idempotent und wiederaufnehmbar verwalten.

**Deliverables**

- runs, run_agents, run_events und erforderliche Approval-Bindungen einschließlich geprüfter Todo-, freigegebener Ready-, `claim_parent_control_sha`-, `initial_run_base_sha`-, aktuellem `run_base_sha`-, Prompt-/Vertragsbindungen und Queueclaim.
- state/phase/wait_reason-Enums, Transition-Map und zentraler erweiterbarer Producer-/Resolver-Registervertrag.
- projects.active_run_id beziehungsweise äquivalente DB-Sperre, geführt als Feld desselben Projektdatensatzes wie die Operationssperre aus AI6-006C und nach §6.2 ausschließlich gemeinsam mit ihr in einer Transaktion geprüft und gesetzt.
- Anschluss des Branchwechsel-Guards aus AI6-006E: Der Runstart verlangt zusätzlich zur freien Runsperre die Abwesenheit einer laufenden mutierenden Control Operation und einer ausstehenden Control-Bindung, und ein Control-Branch-Wechsel verlangt die Abwesenheit eines aktiven Runs; beide Prüfungen laufen über genau diesen einen gemeinsamen Compare-and-Swap.
- Minimaler `RunOrchestrator` als einzige Mutationsgrenze für Start, State, Phase, Wait und Abschluss.
- Runstart aus gültigem Approval als eigener `status_operation_id`-gebundener Claim: Eligibility auf einem frisch gelesenen `claim_parent_control_sha` unmittelbar neu prüfen, dort den unveränderten freigegebenen Ready-Blob und alle relevanten Bindungen verifizieren, `ready → in_progress` per Compare-and-Swap committen und nach bestätigtem eigenen Commit genau diesen SHA einmalig als unveränderliches `initial_run_base_sha` sowie aktuelles `run_base_sha` samt Run und Projektsperre persistieren.
- Registerinvariante: Ein späteres Ticket darf einen produktiven `wait_reason`-Producer nur zusammen mit mindestens einem autorisierten Resolver oder expliziten Cancelpfad registrieren. AI6-013 implementiert keine Producer zukünftiger Phasen; ein noch nicht gestartetes Ticket bleibt in `ticket_approvals`, `runs.state=queued` beginnt erst nach erfolgreichem atomarem Claim.
- Versionsfeld für optimistische Interventionen.

**Akzeptanzvertrag**

- Zwei gleichzeitige Starts erzeugen höchstens einen aktiven Run.
- Ein Runstart bei laufender mutierender Control Operation oder vorhandener ausstehender Control-Bindung wird abgelehnt, und ein Control-Branch-Wechsel bei aktivem Run ebenfalls; keine der beiden Prüfungen liegt außerhalb des gemeinsamen Compare-and-Swap.
- Direkte Statusmutation außerhalb des Orchestrators ist nicht möglich.
- Ein abgeschlossener oder abgebrochener Run gibt das Projekt frei.
- Approval wird genau einer Run-Lineage zugeordnet.
- `approved_control_sha` bleibt Freigabeprovenienz. `claim_parent_control_sha` ist der frisch verifizierte aktuelle Fast-forward-Nachfahre, darf irrelevante Control-Commits enthalten und wird Parent des Status-CAS; `initial_run_base_sha` und `run_base_sha` sind beim Start identisch dessen Ergebnis mit Status `in_progress`.
- Ein Crash nach Claim-Push vor Run-/Lockpersistenz wird aus der eigenen Statusoperation reconciled; ein fremder gleich aussehender `in_progress`-Commit erzeugt weder Run noch Sperre.
- Das Basisregister lehnt eine produktive Erweiterung ohne gekoppelten autorisierten Resolver oder expliziten Cancelpfad ab; noch nicht implementierte Producer gelten nicht als verfügbar.

**Mindestens zu erzeugende Testfälle**

- Concurrency-Test.
- Nebenläufigkeitstest gegen den Wettlauf zwischen Runstart und Control-Branch-Wechsel: Beide werden gleichzeitig ausgelöst, höchstens eines wird wirksam, das andere endet als benannter Konflikt, und es entsteht kein Zustand mit aktivem Run und begonnenem Branchwechsel.
- Transition-Matrix.
- Status-Saga-Crashmatrix des Claims an jeder gemeinsamen Phase einschließlich Push vor Run-/Lockpersistenz, Replay und fremdem ähnlich aussehendem `in_progress`-Commit.
- Test, dass der Start am frischen `claim_parent_control_sha` den freigegebenen Ready-Blob und die relevanten Snapshots erwartet, irrelevanten Fast-forward-Control-Fortschritt zulässt, relevante Änderung sowie nicht verwandten History-Rewrite trotz identischem Blob blockiert und beide Runbasisfelder zunächst auf dem neuen `in_progress`-Commit liegen.
- Registervertragstest: ungepaarter Producer wird abgelehnt; eine Testregistrierung mit Resolver beziehungsweise Cancelpfad ist idempotent, ohne zukünftige Produktfunktion vorzutäuschen.
- Stale run.version-Test.

**Nicht Teil dieses Tickets**

- Git-Worktree.
- Agentenjobs.

### AI6-014 — Run-Branch, Worktree, Checkpoint und Diff-Service

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-006D`, `AI6-013`
- **Requirement-Refs:** `GIT-002`, `GIT-003`, `GIT-004`, `GIT-010`
- **Erwartete Module:** `Git`, `Runs`

**Ziel**

Für jeden Run einen isolierten Git-Arbeitsbereich sowie reproduzierbare Checkpoints und Diffs bereitstellen.

**Deliverables**

- Branch-Namenspolicy.
- Worktree-Lifecycle.
- Lokale Checkpoint-Commits ohne Push.
- Diff-/Tree-/Blob-Service.
- Sämtliche Checkout-, Status-, Diff-, Archiv- und Worktree-Gitaufrufe verwenden dieselbe in AI6-006A gehärtete Git-Prozessumgebung; kein alternativer Git-Wrapper.
- Deterministischer Tree-Export/Overlay für Agent, Checker und Reviewer ohne `.git` oder erreichbare Common-Dir-/Alternates-/Ref-/Index-/Hook-Metadaten; separater bereinigter read-only Historienkontext nur bei explizitem Bedarf.
- Sicheres Cleanup und Recovery verwaister Worktrees.

**Akzeptanzvertrag**

- Run-Branch und Worktree basieren ausschließlich auf dem in AI6-013 persistierten unveränderlichen `initial_run_base_sha`, niemals direkt auf `approved_control_sha`. Ein später fortgeschriebenes `run_base_sha` ist die aktuelle Control-/Candidate-Basis, ohne fälschlich als Worktree-Ahn ausgegeben zu werden.
- Kein fremder Branch oder Worktree wird verändert.
- Checkpoint ist unveränderlich referenzierbar.
- Symlinks und Pfade außerhalb des Repositories werden abgelehnt.
- Worktree-/Diffoperationen führen keine durch Repositoryattribute oder Hostconfig referenzierten externen Prozesse aus.
- Export und Overlay enthalten keine erreichbare Gitmetadaten- oder Credentialnaht; ausschließlich der Worker darf den daraus berechneten Patch in den echten Run-Worktree importieren.

**Mindestens zu erzeugende Testfälle**

- Git-Integrationstests für Branch, Worktree, Checkpoint und Cleanup.
- Checkout-/Diff-Negativfixtures für externe Filter, textconv/ext-diff, fsmonitor und Host-Globalconfig.
- Crash-Recovery-Fixture.
- Diff-Hash-Stabilität.
- Negativtests gegen `.git`-Datei/-Verzeichnis, Worktree-Gitfile, Common-Dir, Alternates, Refs, Index, Hooks und beschreibbaren Historienkontext.
- Test, dass ein simuliert fortgeschriebenes `run_base_sha` den unveränderlichen Worktree-Anker `initial_run_base_sha` nicht umdeutet.

**Nicht Teil dieses Tickets**

- Agentenprozess.
- Finaler Push.

### AI6-015 — ProcessRunner, ExecutionMailbox und Prozessgrenzen

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-002`, `AI6-003`, `AI6-006A`, `AI6-013`, `AI6-014`
- **Requirement-Refs:** `AGT-006`, `AGT-007`, `AGT-009`, `GIT-010`, `RUN-004`, `RUN-006`, `SEC-005`, `OPS-004`
- **Erwartete Module:** `Shared`, `Agents`, `Checks`

**Ziel**

Agenten und Projektchecks über eine kleine, typisierte und credentialgetrennte Ausführungsgrenze starten.

**Deliverables**

- Erweiterung des in AI6-006A eingeführten zentralen ProcessRunners um getrennte ProcessPolicy-Profile für control, agent und checker; kein zweiter Wrapper.
- Timeout, Output-/PID-/Datei-/Byte-/Artefaktlimits und Prozessgruppen-Cancel mit serverseitigen Maxima.
- Typisiertes, hashgebundenes Limitüberschreitungsergebnis ohne partiellen Import oder Run-/HumanLoop-Seiteneffekt; die Orchestrierungsintegration folgt nach Einführung des HumanLoop-Vertrags in AI6-019.
- Getrennte Agent-/Checker-Mailboxes mit atomaren Envelopes und Hashbindung.
- Isolierte Prozesswurzel und neues versiegeltes Provider-Home, die ausschließlich exportierte Trees, read-only Instruction-Snapshot-Overlays an nativen Discoverypfaden, die freigegebene serverseitige Runtime-Konfiguration und eine kurzlebige minimale read-only Authprojektion für genau ein Providerprofil einbinden; Host-/Parent-Autodiscovery, echte Gitmetadaten, persistenter Credential-Store sowie nicht freigegebene Workspace-/Home-Erweiterungen sind nicht erreichbar.
- Strukturierter Instruction-Patch-Kanal für ausdrücklich vor Runstart im `initial_scope` freigegebene Instruction-Update-Tickets; der Pfad bleibt für native Discovery auf dem alten Snapshot read-only und ausschließlich der Worker importiert die prospektive Änderung nach Prozessende.
- Executor-Kommandos ohne primären DB-/APP_KEY-Zugriff.

**Akzeptanzvertrag**

- Browser und Controller starten keine Prozesse.
- Agent sieht keinen Git-Push-/SMTP-/Sessionzugang.
- Checker sieht keine Provider-/Git-/SMTP-Credentials.
- Agent sieht ausschließlich die minimale Authprojektion seines gewählten Profils; andere Credential-Stores, persistente Homes, Cache und History bleiben unerreichbar und werden nach Ende der Session nicht in das Execution-Home zurückgeschrieben.
- Ungültiges oder zu großes Ergebnis wird nicht importiert.
- Überschrittene Prozessgrenzen liefern genau ein typisiertes Limitresultat statt partiellen Imports; AI6-015 erzeugt weder einen Human Request noch eine vorgezogene Runtransition.
- Aktive Sandboxpflicht fällt geschlossen aus.
- Unvollständig isolierbare Git-, Instruktionsautodiscovery- oder Provider-Runtime-Grenzen fallen vor Provider-/Checkstart geschlossen aus.

**Mindestens zu erzeugende Testfälle**

- Fake-Executable für Timeout/Cancel/Outputlimit.
- Environment-Leak-Negativtest.
- Mailbox-Replay-/Tamper-Test.
- Limitresultat-Test einschließlich exakt-am-Limit, eins-darüber und fehlender partieller Wirkung.
- Sandbox-unavailable-Test.
- Negativtests für Host-/Parent-Instruktionen, veränderte Snapshotbytes, beschreibbare Instruktionsoverlays, erreichbare Git-Common-Dir-/Hookpfade sowie Repository-/Workspace-/Home-Konfiguration, `.codex`/`.claude`, MCP, Plugins, Skills, Commands und den Start externer Helper.
- Credentialprojektions-Tests für Profiltrennung, read-only/minimale Inhalte, zerstörtes Execution-Home nach Session, Rotation/Logout sowie ausgeschlossene Cache-/History-/Fremdprofilübernahme.
- Instruction-Update-Contracttest: nur vorab freigegebener `initial_scope`, alter Snapshot bleibt nativ wirksam, strukturierter Patch wird erst nach Prozessende durch den Worker importiert; Same-run-Scopeerweiterung fällt geschlossen aus.

**Nicht Teil dieses Tickets**

- Providerspezifische CLI-Flags.
- Fachliche Prompts.
- Human Request und `resource_limit`-Runintegration; beides folgt in AI6-019 nach AI6-018.

### AI6-016 — JSON-Verträge und FakeAgent

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `medium`
- **Kind:** `feature`
- **Depends on:** `AI6-011`, `AI6-015`
- **Requirement-Refs:** `AGT-001`, `AGT-004`, `AGT-005`, `AGT-008`, `AGT-009`, `REV-003`, `HUM-001`
- **Erwartete Module:** `Prompts`, `Agents`, `Shared`

**Ziel**

Alle Agentenrollen über den bereits freigegebenen Promptkatalog und validierbare Resultatverträge reproduzierbar testen.

**Deliverables**

- Versionierte JSON-Schemas für `completed`, `no_change_required`, `needs_human`, Findings und `inconclusive` sowie das optionale Ein-Datei-Objekt `ai6.instruction-patch.v1`.
- FakeAgentAdapter mit deterministischen Szenarien.
- Kompatibilitätsbindung jedes Ergebnisses an den in AI6-011 erzeugten Prompt- und Instruction-Snapshot samt Hashes.
- Keine Ticketstatus- oder Workflowmutation durch Agenten.
- `instruction_patch` ist ausschließlich im servergebundenen Modus `instruction_update` zulässig und bindet genau einen initial freigegebenen Pfad, alten Blob beziehungsweise nachgewiesene Abwesenheit, kanonisches Base64, dekodierte Länge und Inhalts-SHA-256.

**Akzeptanzvertrag**

- Ein Prompt je Rolle, nicht je Modell.
- AI6-016 erzeugt weder einen zweiten Renderer noch zweite Prompttemplates; es konsumiert ausschließlich den katalogisierten Snapshotvertrag aus AI6-011.
- Schemafehler haben keine Seiteneffekte.
- Außerhalb von `instruction_update`, bei mehreren Zielen, falschem alten Blob, ungültigem UTF-8/BOM/Base64, Längen-/Hashabweichung oder Limitüberschreitung wird kein Byte importiert.
- FakeAgent simuliert Erfolg, begründetes No-change, Frage, Findings, ungültiges JSON, Providerfehler und Securityfälle.
- Ticketdatei wird nicht als Review-Handoff missbraucht.

**Mindestens zu erzeugende Testfälle**

- Golden-Kompatibilitätstests zwischen Prompt-/Instruction-Snapshot, Rollenvertrag und Ergebnis-Schema.
- Schema-Validierung Positiv/Negativ.
- Fake-Szenario-Matrix einschließlich `no_change_required` mit leerem sowie unzulässig nicht leerem Diff.
- Instruction-Patch-Golden-/Negativmatrix für vorhandenen und neuen initial gescopten Einzelpfad, Modusbindung, kanonisches Base64, UTF-8/BOM, Blob-/Abwesenheits-, Länge-/Hash-, Mehrziel-, Symlink-, Scope- und sämtliche anwendbaren Limitfehler ohne partielle Wirkung.

**Nicht Teil dieses Tickets**

- Echte Provideradapter.
- Run-Orchestrierung.

### AI6-017 — Basisschritt-Orchestrator und Run-Timeline

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-013`, `AI6-014`, `AI6-015`, `AI6-016`
- **Requirement-Refs:** `RUN-001`, `RUN-003`, `RUN-005`, `UI-004`
- **Erwartete Module:** `Runs`

**Ziel**

Run-Schritte ausschließlich über idempotente Queuejobs planen, ausführen und in einer einfachen Timeline sichtbar machen.

**Deliverables**

- Erweiterung des in AI6-013 eingeführten `RunOrchestrator` um die Next-Step-Entscheidung; kein zweiter Orchestrator.
- Idempotency-Key je Schritt.
- Job-Leases und kontrollierter Retry.
- Run-Events und Basis-Runseite mit Polling.
- Preflight bis zum vorbereiteten Implementierungsschritt.

**Akzeptanzvertrag**

- Doppelter Job erzeugt keinen doppelten Agentenaufruf.
- Workerneustart lässt Run in eindeutigem Zustand.
- Timeline unterscheidet geplant, laufend, erfolgreich, wartend und fehlgeschlagen.
- Controller verändern Runzustände nicht direkt.

**Mindestens zu erzeugende Testfälle**

- Duplicate-delivery-Test.
- Crashpunkte vor/nach Seiteneffekt.
- Polling-/Autorisierungstest.
- Preflight-Failure-Szenarien.

**Nicht Teil dieses Tickets**

- Fachlicher Implementierungsturn.
- Reviewlogik.

## 15.4 M3 – Human-in-the-loop und Implementierung

### AI6-018 — Human Requests, E-Mail, Attention-Inbox und Resume

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-005A`, `AI6-017`
- **Requirement-Refs:** `HUM-001`, `HUM-002`, `HUM-003`, `HUM-004`, `UI-001`, `UI-005`
- **Erwartete Module:** `HumanLoop`, `Runs`

**Ziel**

Blockierende Fragen und Freigaben persistent per E-Mail melden, sicher im Panel beantworten und den richtigen Run genau einmal fortsetzen.

**Deliverables**

- human_requests und interventions.
- HumanRequestService und serverseitige Policyklassifikation.
- Transaktionale Invariante für höchstens einen gleichzeitig offenen blockierenden Request je Run; terminale Requests bleiben historisch erhalten und sperren keinen späteren Warteschritt.
- Idempotente Notification an die verifizierte E-Mail des attention_user mit Mailstatus/Retry; die globale Login-Bestätigungsadresse bleibt davon getrennt.
- Mobile Attention-Inbox und typisierte Antwortformulare.
- Bindung an Runversion, Vertrag, Checkpoint, Scope, Agentenslot und Wirkung.
- Resume-Hook für den Orchestrator.

**Akzeptanzvertrag**

- Pro Run existiert höchstens ein gleichzeitig offener blockierender Request; nach terminaler Auflösung darf ein späterer Warteschritt einen neuen Request mit eigener Bindung erzeugen.
- E-Mail-Link führt nur zur authentifizierten Detailseite.
- Stale, doppelte oder fremde Antwort wird abgelehnt.
- Mailfehler ändert den Wartestatus nicht und ist sichtbar.
- Antwort setzt exakt den gebundenen Schritt fort.

**Mindestens zu erzeugende Testfälle**

- Mail accepted/failed/retry.
- Anti-Replay- und stale-binding-Tests.
- Sequenztest mit zwei nacheinander geöffneten Requests und unveränderter Historie des ersten.
- Mobile Form-Smoke-Test.
- Doppelklick/Queue-Duplikat-Test.

**Nicht Teil dieses Tickets**

- Spezifische Scope- oder Reviewlimit-Entscheidungen.

### AI6-019 — Implementierungsagent-Turn und sicherer Diff-Import

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-014`, `AI6-016`, `AI6-017`, `AI6-018`
- **Requirement-Refs:** `AGT-003`, `AGT-004`, `AGT-008`, `AGT-009`, `GIT-003`, `GIT-010`, `RUN-003`, `RUN-006`, `UI-004`
- **Erwartete Module:** `Runs`, `Agents`, `Git`

**Ziel**

Den ersten Implementierungsturn mit FakeAgent ausführen, Ergebnisse importieren und tatsächliche Änderungen reviewbar anzeigen.

**Deliverables**

- Implementierungs-Agentenslot mit neuer Session je Ticket.
- Prompt aus Approval-Snapshot.
- Providerturn ausschließlich mit freigegebenem Instruction-Snapshot in isolierter Tree-Sicht; jede Instruktions-/Runtime-Profiländerung stoppt vor Resume und verlangt kontrollierte Rücksetzung auf `todo`, neue Approval, neuen Run und neue Sessions.
- Für ein explizites Instruction-Update-Ticket strukturierter prospektiver Patch statt Schreibzugriff auf den nativen Discoverypfad; der aktuelle Providerturn bleibt vollständig unter dem alten Snapshot.
- Start/Resume über AgentAdapter.
- Validierter Ergebnisimport und tatsächlicher Git-Diff.
- Importgrenzen für Anzahl und Gesamtbytes geänderter Dateien sowie Anzahl, Einzelgröße, Gesamtgröße und Provideroutput von Artefakten; jedes Projektlimit bleibt unter dem vertrauenswürdigen Servermaximum.
- Orchestrierungsintegration der typisierten AI6-015-Limitresultate: `resource_limit`-Producer, gekoppelter HumanLoop-Resolver beziehungsweise Cancelpfad und idempotente Registererweiterung.
- Basisanzeige geänderter Dateien und Entscheidungen.
- needs_human-Routing an HumanLoop.

**Akzeptanzvertrag**

- Agent kann nur die exportierte Tree-Sicht ändern; `.git`/Common-Dir, Managed-Worktree und Credentials sind unerreichbar. Ein optionaler bereinigter Git-Lesekontext besitzt technisch keine Schreib-, Hook- oder Pushrechte.
- Gemeldete und tatsächliche Pfade werden verglichen.
- Unbekannte Binär-/Symlink-/Oversize-Änderung blockiert.
- Eine Grenzüberschreitung importiert weder partiellen Diff noch partielles Artefakt, erzeugt `wait_reason=resource_limit` und höchstens einen gleichzeitig offenen gebundenen Human Request.
- `no_change_required` wird nur bei serverseitig bestätigtem leerem Diff und konkreter Begründung akzeptiert und durchläuft anschließend dieselben Checks, Reviews und Gates wie `completed`.
- Neue Tickets starten nie mit alter Provider-Session.
- Nach menschlicher Antwort wird derselbe Slot fortgesetzt, sofern unterstützt.

**Mindestens zu erzeugende Testfälle**

- Fake success/needs_human/invalid-json/provider-error.
- Diff-Tamper-Test.
- Gitmetadaten-/Hook-/Ref-Tampertest und Nachweis, dass ausschließlich der Worker den validierten Patch importiert.
- Instruktions-Blob-/Hash-/Rangfolgedrift vor Start und Resume; neuer Snapshot erzwingt Runrücksetzung, neue Approval, neuen Run und neue Session.
- Instruction-Update-Ticket mit initial gescoptem strukturiertem Patch, altem wirksamem Snapshot, Workerimport und Verbot einer nachträglichen Scopeaufnahme.
- Grenzwertmatrix für Datei-, Byte-, Artefakt- und Provideroutputlimits einschließlich exakt-am-Limit und eins-darüber.
- Producer-/Resolver-/Cancel- und Replay-Test für `resource_limit`.
- `no_change_required` mit leerem und mit unzulässig nicht leerem tatsächlichem Diff.
- Session-Neustart je Ticket.
- Resume-Test.

**Nicht Teil dieses Tickets**

- Automatische Scopegenehmigung.
- Projektchecks.
- Reviewer.

### AI6-020 — Adaptive Scope- und Vertragsänderungen

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-009`, `AI6-018`, `AI6-019`
- **Requirement-Refs:** `TKT-007`, `TKT-012`, `CFG-002`, `AGT-009`, `HUM-004`, `HUM-005`, `REV-005`, `RUN-006`, `RUN-007`
- **Erwartete Module:** `Tickets`, `Runs`, `HumanLoop`, `Git`

**Ziel**

Notwendige Abweichungen vom geplanten File-Scope kontrolliert zulassen, ohne Ziel oder Sicherheitsgrenzen still zu verändern.

**Deliverables**

- initial_scope, effective_scope und actual_changed_paths.
- Deterministische Scope-Policy mit den drei Spuren aus §8.2: `scope.auto_allow` ohne Rückfrage, sensible Kategorien immer per Human Request, jeder übrige Pfad nach der vertrauenswürdigen Projektvorgabe `scope.unlisted_paths` mit der Vorgabe `auto_allow`.
- `recorded_scope` als dokumentierender Runzustand nach `TKT-012`: initialer Scope, je aufgenommenem Pfad der benannte Entscheidungsgrund, quarantänierte Pfade und Verbrauch des Pfadlimits, dazu der Ausschluss des AI6-eigenen Abschnitts `## Recorded Scope` aus der einen vorhandenen kanonischen Contract-Hash-Naht ohne zweite Parser- oder Hashnaht.
- Zähler für neu in `effective_scope` aufgenommene exakte Pfade gegen das freigegebene `max_added_scope_paths`; Retry, Teilgenehmigung und Contract Amendment verbrauchen denselben idempotenten Zähler.
- Human Requests für sensible Pfade.
- Contract-Amendment-Saga: Ticketänderung mit unverändertem Status `in_progress` per Control-Branch-CAS committen, neuen Control-Commit ausschließlich als aktuelles `run_base_sha` speichern, `initial_run_base_sha` unverändert lassen und nur den gebundenen Ticketpatch in den bestehenden Run-Branch übernehmen.
- Nachweis vor und nach der Patchübernahme, dass der Codebaum unverändert blieb; neue Bindungen für Ticketblob, Contract-Hash, Scope, Config und Prompt.
- Quarantäne bereits geänderter Zusatzpfade.
- Invalidierung alter Checkpoints, Reviews, Gate-Evidenzen und Finding-Dispositionen nach Scope-/Vertragsänderung.
- Getrennter Instruction-/Runtime-Profil-Änderungspfad ohne Same-run-Amendment: laufende Provider-Sessions verwerfen, Ticket per kontrolliertem Status-CAS auf `todo` zurücksetzen und Änderung ausschließlich über neue Approval und neuen Run wirksam machen.
- Ausnahme nur für das bereits als solches genehmigte Instruction-Update-Ticket aus §8.2: initial gescopter strukturierter Patch als künftiger Repositoryinhalt, ohne den effektiven Snapshot des laufenden Runs zu ändern.

**Akzeptanzvertrag**

- actual_changed_paths ist vor Review Teil von effective_scope.
- Eine Überschreitung von `max_added_scope_paths` übernimmt keinen zusätzlichen Pfad, setzt `wait_reason=resource_limit` und bleibt durch Projektinhalt oder Intervention nicht über das Servermaximum hinaus erhöhbar.
- AGENTS.md, Tickets, Migrationen, Dependencies, CI/Deploy/Auth und Löschungen benötigen menschliche Entscheidung.
- Ein Pfad außerhalb von `scope.auto_allow` und außerhalb jeder sensiblen Kategorie wird bei `scope.unlisted_paths: auto_allow` ohne Rückfrage aufgenommen, mit benanntem Entscheidungsgrund dokumentiert und blockiert weder Umsetzung noch Review; bei `require_approval` erzeugt derselbe Pfad einen Human Request.
- Ein vorhandener Abschnitt `## Recorded Scope` verändert `ticket_contract_sha256` nicht; der Contract-Hash bleibt vor und nach seinem Schreiben identisch.
- Ablehnung entfernt nur AI6-eigene Änderungen und bewahrt Audit.
- Freigegebene Vertragsänderung ist über Human Request, Autorisierung, alten/neuen Ticketblob, alten/neuen Control-Commit, Patchhash und aktualisierte Runbindung vollständig nachvollziehbar.
- Contract Amendment verändert niemals `initial_run_base_sha`; das neue `run_base_sha` wird als spätere Candidate-Basis verwendet, nicht als nachträglich erfundener Worktree-Ahn.
- Eine Instruktions-/Runtime-Profiländerung ist kein Ticketpatch, verändert nicht den `run_base_sha` des alten Runs und wirkt erst nach dessen kontrollierter Rücksetzung, neuer Freigabe, neuem Run und neuen Sessions; weder alter Snapshot noch alter Code-Diff werden still weiterverwendet.
- Ein initial freigegebenes Instruction-Update-Ticket importiert seine prospektive Änderung ausschließlich über den Worker-Patchkanal und hält den alten Snapshot für Implementierung und Reviews wirksam; jede andere nachträgliche Aufnahme eines Instruktionspfads wird abgelehnt.
- Jede unerwartete Codebaum- oder Control-Branch-Abweichung blockiert mit `git_base_changed`; ein Contract Amendment führt niemals automatisch Rebase oder Merge fremder Änderungen aus.

**Mindestens zu erzeugende Testfälle**

- Auto-Allow-, Sensitive- und Unlisted-Kategorien einschließlich beider Werte von `scope.unlisted_paths` an demselben Pfad.
- Contract-Hash-Invarianz über einem geschriebenen und einem fortgeschriebenen `## Recorded Scope` sowie Golden-Vektor mit und ohne diesen Abschnitt.
- Grenz-, Überschreitungs-, Retry- und Teilgenehmigungstest für `max_added_scope_paths`.
- Teilgenehmigung.
- Ablehnung und Patch-Erhalt.
- Reviewinvalidierung.
- Stale Scope-Request.
- Erfolgreiche Amendment-Provenienz, unverändertes `initial_run_base_sha`, fortgeschriebenes `run_base_sha`, unveränderter Codebaum, CAS-Konflikt, Patch-Tampering und unerwarteter Basisdrift.
- Angeforderte Instruction-/Runtime-Profiländerung mit Snapshotinvalidierung, `in_progress → todo`, neuem Approval/Run, ausgeschlossener Session- und Diffübernahme sowie externer unerwarteter Drift als `git_base_changed`.
- Positiv-/Negativmatrix für initial freigegebenes Instruction-Update-Ticket gegenüber unzulässigem Same-run-Amendment beziehungsweise Scope-Request.

**Nicht Teil dieses Tickets**

- Repositoryweite automatische Freigabe.
- Stille Änderung von Akzeptanzkriterien.
- Das Zurückschreiben des `## Recorded Scope` in die Ticketdatei selbst (`AI6-029`).

### AI6-021 — Checkprofile und credentialfreier Checker

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-010`, `AI6-015`, `AI6-017`
- **Requirement-Refs:** `CFG-003`, `AGT-007`, `GIT-010`, `SEC-005`, `SEC-007`, `RUN-003`
- **Erwartete Module:** `Checks`, `Shared`

**Ziel**

Projektchecks ausschließlich über serverseitig erlaubte Profile in einem credentialfreien, standardmäßig netzlosen Prozess ausführen.

**Deliverables**

- CheckProfile-Registry.
- CheckRunner über Checker-Mailbox.
- Phasen before_review und final.
- Seiteneffekt-/Netzwerk-/Mutation-Metadaten.
- Strukturierte CheckResult-Ausgabe; Logredaction konsumiert ausschließlich den zentralen AI6-003-Redactor.

**Akzeptanzvertrag**

- Projekt kann keine freien Befehle einschleusen.
- Checker besitzt keine Provider-, Git- oder SMTP-Secrets.
- Checker sieht ausschließlich den exportierten Tree ohne `.git`, Common-Dir, Refs, Index, Hooks oder beschreibbaren Historienkontext.
- Network none ist Default.
- Fehler, Timeout und nicht verfügbare Tools sind unterscheidbar.
- Mutierende Checks können nicht unbemerkt Reviewstand verändern.
- Checkmodul besitzt keine eigene Secret-/Redaction-Patternliste.

**Mindestens zu erzeugende Testfälle**

- Erfolgreich/fehlgeschlagen/timeout/not-available.
- Secret-/Network-Negativtest.
- Integrations-Golden-Test gegen denselben zentralen Redactor wie Read Models und Provideroutput.
- Gitmetadaten-/Hook-/Ref-Isolationstest des Checker-Exports.
- Mutationsdetektion.
- Verbotene Shell-/Inlinecode-Profile.

**Nicht Teil dieses Tickets**

- Produktionsdeployments als Check.
- Beliebige benutzerdefinierte Befehle.
- Der rollenrichtige Vollzug der Ausführung: Staging in das Checker-Volume, Konsum in der Checkerrolle, Ergebnisrückweg und der wiederaufnehmbare Warteschritt (`AI6-045`). Bis dahin ist die Ausführung außerhalb der Checkerrolle ausschließlich als ausdrückliche, im Policyhash sichtbare Reduktion möglich und im Profil `strict` unmöglich.

### AI6-045 — Checkausführung in der Checkerrolle

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-015`, `AI6-017`, `AI6-021`
- **Requirement-Refs:** `AGT-006`, `AGT-007`, `GIT-010`, `RUN-003`, `RUN-004`, `SEC-005`, `SEC-007`, `OPS-003`
- **Erwartete Module:** `Checks`, `Runs`, `Shared`

**Ziel**

Den in `AI6-021` definierten Check tatsächlich in der isolierten Checkerrolle ausführen, sodass ein `before_review`-Check unter dem Profil `strict` ohne jede Reduktion produktiv läuft.

**Deliverables**

- Workerseitiges Staging: exportierter Baum und leeres Baselineverzeichnis im Checker-Volume, genau ein Auftrag mit Profil-, Phasen- und Baumbindung über die vorhandene Checker-Mailbox.
- Checkerseitige Konsumschleife als Erweiterung des vorhandenen Rollenkommandos, ohne zweiten Prozess-, Mailbox- oder Orchestratorpfad.
- Ausführung in der Checkerrolle über die vorhandene Checkerpolicy und die vollständige Isolationsprüfung; Ergebnisrückweg über die vorhandene Ergebnisnaht in das Ausgabevolumen.
- Entscheidung und Umsetzung der Schreibbarkeit des geprüften Baums gegenüber dem read-only eingehängten Eingangsvolumen der Checkerrolle, einschließlich der daraus folgenden Mount- und Rollenverträge.
- Wiederaufnehmbarer Checkschritt: Parken bis zum Ergebnis, heartbeatgebundene Lebendigkeits- und Zeitgrenze, Bindung des Ergebnisses an Run, Phase, Profil und geprüften Baum.
- Entfernen der Reduktionspflicht für den Normalbetrieb; die Reduktion bleibt ausschließlich für Entwicklung und Test bestehen.

**Akzeptanzvertrag**

- Ein `before_review`-Check läuft unter dem Profil `strict` vollständig durch, ohne dass eine Sicherheitsmaßnahme abgeschaltet wird.
- Der Checkprozess erreicht weder Managed-Clone noch Deploy-Keys, Provider-, Git-, SMTP- oder Datenbankcredentials noch das Netz; der Nachweis erfolgt in der Checkerrolle und nicht über eine Ersatzgrenze.
- Eine doppelt zugestellte Schrittnachricht erzeugt weiterhin genau einen Checkerprozess und genau ein Ergebnis.
- Ein zwischen Auftrag und Ergebnis abgestürzter oder stehengebliebener Checker führt niemals zu einem grünen Ergebnis; der Schritt endet benannt oder wird gebunden wiederholt.
- Ein toter Checker lässt einen Run nicht unbegrenzt warten, sondern erreicht eine benannte, sichtbare Grenze.
- Der geprüfte Baum ist genau so beschreibbar, wie es der entschiedene Vertrag zusagt; Mutationserkennung und Reviewstand bleiben aus `AI6-021` unverändert gültig.

**Mindestens zu erzeugende Testfälle**

- End-to-End in der Checkerrolle: Auftrag, Ausführung, Ergebnisrückweg und gebundenes Checkergebnis.
- Isolationsnachweis in der Checkerrolle für Credentials, Gitmetadaten und Netz.
- Doppelte Zustellung: genau ein Prozess, genau ein Ergebnis.
- Absturz zwischen Auftrag und Ergebnis sowie stehengebliebener Checker mit heartbeatgebundener Grenze.
- Schreibbarkeitsvertrag des geprüften Baums einschließlich Mutationsfall.
- Negativtest: keine Checkausführung außerhalb der Checkerrolle unter aktiver Kontrolle.

**Nicht Teil dieses Tickets**

- Neue Checkprofile, Ergebniszustände oder Redactionregeln; sie bleiben unverändert aus `AI6-021`.
- Die Entscheidung, wann `before_review` verlangt wird (`AI6-022`), und der Finalisierungsablauf (`AI6-027`).
- Parallele Checkerprozesse und mehrere aktive Runs je Projekt.

### AI6-022 — Pre-Review-Verifikation und Checkpoint-Bereitschaft

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `medium`
- **Kind:** `feature`
- **Depends on:** `AI6-019`, `AI6-020`, `AI6-021`, `AI6-045`
- **Requirement-Refs:** `RUN-003`, `RUN-007`, `RUN-009`, `GIT-003`, `GIT-004`, `TKT-007`
- **Erwartete Module:** `Runs`, `Checks`, `Git`

**Ziel**

Nur einen vollständig abgeglichenen, geprüften und unveränderlichen Implementierungsstand an Reviewer übergeben.

**Deliverables**

- Scope-Reconciliation.
- before_review-Checks.
- Persistente Erkennung aller `MG-`-/`EXT-`-Gates samt Status, autorisierter Evidenz und Bindung an Vertrag beziehungsweise relevanten Checkpoint.
- Checkpoint-Erstellung.
- Readiness-Entscheidung und verständliche Blockadegründe.

**Akzeptanzvertrag**

- Kein Review bei ungeklärtem Scope oder fehlgeschlagenem Pflichtcheck.
- Nicht ausführbare Pflichtchecks werden nicht als grün markiert.
- Checkpoint enthält exakt den freigegebenen Diff.
- Änderung nach Checkpoint erzwingt neuen Checkpoint.
- Externer Ticket- oder Control-Branch-Drift pausiert den Run statt alte Freigaben weiterzuverwenden.
- Offene Gates dürfen den Qualitätsreview durchlaufen, bleiben aber explizit blockierend für Candidate, finalen Commit und Push; `None.` erzeugt kein Gate.

**Mindestens zu erzeugende Testfälle**

- Scope-/Check-/Gate-Matrix einschließlich `None.`, offen, gebunden geschlossen und nach Vertrags-/Checkpointänderung erneut offen.
- Tree-Änderung nach Checkpoint.
- Checkpoint-Recovery.

**Nicht Teil dieses Tickets**

- Qualitätsreview selbst.

## 15.5 M4 – Multi-Review und Fixschleife

### AI6-023 — Read-only Review-Workspaces und Multi-Reviewer-Ausführung

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-012`, `AI6-016`, `AI6-022`
- **Requirement-Refs:** `REV-001`, `REV-002`, `AGT-003`, `AGT-009`, `GIT-004`, `GIT-010`
- **Erwartete Module:** `Reviews`, `Agents`, `Git`

**Ziel**

Alle ausgewählten Reviewer seriell, unabhängig und auf demselben unveränderlichen Checkpoint ausführen.

**Deliverables**

- Wegwerfbarer exportierter Review-Workspace je Slot ohne erreichbare Gitmetadaten; Instruction-Snapshot read-only an exakt den freigegebenen Discoverypfaden.
- Eigene Session/Home je Reviewer.
- Serielle Invocation mit demselben Checkpoint-, Prompt- und Instruction-Snapshot-Hash.
- Reviewrunde und Invocation-Persistenz.
- Fragen eines Reviewers über HumanLoop.

**Akzeptanzvertrag**

- Reviewer können Managed-Worktree, Gitmetadaten und Refs nicht erreichen oder verändern; native Instruktionen entsprechen exakt dem freigegebenen Snapshot.
- Initiale Reviewer sehen keine Ergebnisse anderer Reviewer.
- Jeder erforderliche Slot besitzt für den Checkpoint genau ein gültiges Ergebnis oder einen sichtbaren Fehler.
- Reviewerfrage setzt nur den betroffenen Slot fort.

**Mindestens zu erzeugende Testfälle**

- Zwei-Fake-Reviewer auf identischem Tree.
- Workspace-Schreibschutz.
- Negativtest gegen `.git`/Common-Dir/Refs/Hooks, Host-/Parent-Instruktionen und Instruktionsdrift.
- Sessiontrennung.
- Reviewer-needs_human.

**Nicht Teil dieses Tickets**

- Findingaggregation.
- Fixturn.

### AI6-024 — Findings, AC-Abdeckung und Reviewdarstellung

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-023`
- **Requirement-Refs:** `REV-002`, `REV-003`, `REV-004`, `REV-006`, `REV-009`, `UI-004`
- **Erwartete Module:** `Reviews`

**Ziel**

Reviewresultate ohne Mehrheitsentscheidung in nachvollziehbare Findings und vollständige Akzeptanzkriterien-Abdeckung überführen.

**Deliverables**

- ReviewResultParser und Schema-Fehlerpfad.
- Unveränderliche Finding-Persistenz mit Reviewerquelle und separat versionierter effektiver Disposition.
- AC-Coverage je Reviewer.
- Nur exakte deterministische Duplikatgruppierung.
- Findings-/Coverage-UI mit Filter nach Reviewer und Disposition.
- Autorisierte, begründete Dispositionen `fixed`, `not_applicable` und `accepted_risk`; die effektive Blockade wird aus Originalschwere, aktuellem Checkpoint und gültiger Disposition berechnet, niemals durch Überschreiben des Originals.
- Strukturierte, nicht automatisch angewendete Empfehlungen für AGENTS.md oder vergleichbare Instruktionsdateien.

**Akzeptanzvertrag**

- Ein must_fix eines Reviewers blockiert unabhängig von anderen Ergebnissen.
- nothing_to_fix ist nur gültig bei vollständiger AC-Abdeckung.
- Suggestions und follow_up blockieren nicht.
- Originalresultat bleibt unverändert erhalten.
- Ein `must_fix` blockiert, solange es nicht auf dem aktuellen Checkpoint nachweislich `fixed` oder per berechtigtem Step-up als `not_applicable` beziehungsweise `accepted_risk` disponiert ist; Vertrags-, Scope-, Prompt-, Policy- oder Checkpointänderungen invalidieren die Disposition.
- Instruktionsupdates werden nur als Empfehlung oder separates freigegebenes Folge-Ticket behandelt.

**Mindestens zu erzeugende Testfälle**

- Mehrheits-Negativtest.
- Fehlende/duplizierte AC-Abdeckung.
- Findingquellen und exakte Duplikate.
- Effektive-Finding-Matrix einschließlich Rollen-/Step-up-Prüfung, Stale-Disposition und Invalidierung.
- UI-Autorisierung/XSS.

**Nicht Teil dieses Tickets**

- Semantische LLM-Deduplizierung.
- Automatische Risikostimmen.

### AI6-025 — Fixturn und vollständige Re-Review-Schleife

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-019`, `AI6-020`, `AI6-022`, `AI6-024`
- **Requirement-Refs:** `REV-005`, `REV-006`, `RUN-003`
- **Erwartete Module:** `Runs`, `Reviews`, `Agents`

**Ziel**

Offene must_fix-Findings durch den Implementierungsagenten bearbeiten und jeden neuen Stand vollständig erneut prüfen.

**Deliverables**

- Fixprompt mit allen offenen blockierenden Findings und Quellen.
- Resume der Implementierungssitzung.
- Erneuter Scope-/Check-/Checkpoint-Ablauf.
- Vollständige neue Reviewrunde mit allen erforderlichen Reviewern.
- Finding-Historie fixed/partially_fixed/not_fixed/not_applicable.

**Akzeptanzvertrag**

- Kein altes nothing_to_fix gilt für einen geänderten Tree.
- Reviewer verifizieren nicht nur eigene alte Findings, sondern prüfen den gesamten neuen Stand.
- Abgelehnte Findings benötigen menschliche Disposition.
- Fix darf Scopepolicy nicht umgehen.

**Mindestens zu erzeugende Testfälle**

- Ein Reviewerfinding, zwei Reviewerfindings, Regression nach Fix.
- Scope-Erweiterung im Fix.
- Findingstatus-Historie.
- Neue Reviewrunde auf neuer Tree-OID.

**Nicht Teil dieses Tickets**

- Optimierte Primary-Reviewer-Strategien.
- Parallele Reviewer.

### AI6-026 — Reviewlimits, Stall-Erkennung und Interventionsaktionen

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-018`, `AI6-025`
- **Requirement-Refs:** `RUN-006`, `REV-007`, `HUM-005`, `UI-005`
- **Erwartete Module:** `Runs`, `Reviews`, `HumanLoop`

**Ziel**

Endlosschleifen und feststeckende Runs kontrolliert an einen Menschen übergeben und ohne Datenbankmanipulation fortsetzen.

**Deliverables**

- Durchsetzung aller freigegebenen Zeit-, Agentenaufruf-, Reviewrunden-, Fixrunden-, zusätzlichen Scope-Pfad-, geänderte-Dateien-, Changed-Bytes-, Instruktionsdatei-, Instruktions-Einzel-/Gesamtbyte-, Importtiefen-, finalen Promptinput-, Artefaktanzahl-, Einzelartefakt-, Gesamtartefakt- und Provideroutputlimits unter vertrauenswürdigen Servermaxima.
- Stall-Fingerprint aus Findings und Diff.
- Human Requests bei Limit/Stall.
- Aktionen Zusatzprompt, genau eine weitere Runde, Reviewer-/Modellwechsel, Finding-Disposition, Soft-/Hard-Cancel und Statusentscheidung.
- `status_operation_id`-gebundene Git-/DB-Abbruchsaga für `in_progress → todo` bei Soft-Cancel, `in_progress → blocked` bei durch Approver mit Step-up begründeter fachlicher Blockierung beziehungsweise `in_progress → cancelled` bei autorisiertem Hard-Cancel; erwarteter Parent, Zielblob/-tree und beabsichtigter Commit werden vor Push persistiert, `runs.state=cancelled` und Lockfreigabe folgen erst nach Reconciliation des bestätigten eigenen Control-CAS.
- Konsolidierte Interventions-UI und Erweiterung des in AI6-013 eingeführten Registers um UI-/Service-Resolver für die bis einschließlich AI6-026 vorhandenen Producer `human_question`, `scope_approval`, `contract_change`, `review_limit`, `resource_limit`, `provider_error`, `invalid_json`, `check_failure`, `git_base_changed` und `git_conflict`, jeweils mit erwarteter Runversion und unveränderlichem Audit.
- Vollständiges Interventionsaudit.

**Akzeptanzvertrag**

- Limitüberschreitung startet keinen weiteren Agentenjob.
- Zusatzrunde erhöht Limit nur um eins.
- Modellwechsel erzeugt neue Slotrevision und vollständigen Review.
- Security-/must_fix-Override verlangt passende Rolle/Step-up.
- Doppelte Intervention ist idempotent.
- Ein Cancel-CAS-Konflikt lässt Run und Projektsperre bestehen und führt über `git_conflict`; nach bestätigtem Branch-Push ist Cancel gesperrt und ausschließlich `status_sync` zulässig.
- `in_progress → blocked` ist keine pausierte DB-Runphase: Nach erfolgreicher Saga ist der Run terminal `cancelled`, der Ticketstatus bleibt `blocked`, und eine spätere Wiederaufnahme beginnt erst nach autorisiertem `blocked → todo` mit neuer Approval und neuem Run.
- Keine Limitart kann durch Projektconfig, Provideroutput, Retry oder Resume über das serverseitige Maximum hinaus erweitert beziehungsweise doppelt verbraucht werden.
- Jeder bis einschließlich AI6-026 eingeführte Wartestatus hat mindestens einen autorisierten Resolver oder einen expliziten Cancelpfad; die Auflösung setzt nur den gebundenen Orchestratorschritt fort. Spätere Tickets erweitern dasselbe Register gemeinsam mit ihrem neuen Producer.

**Mindestens zu erzeugende Testfälle**

- Identische Findings ohne Diff.
- Limitmatrix.
- Producer-/Resolver-Szenariomatrix für jeden bis einschließlich AI6-026 eingeführten `wait_reason`.
- Stale Intervention.
- Cancel während Agentenjob.
- Soft-/Block-/Hard-Abbruch-Status-Saga-Crashmatrix an jeder gemeinsamen Phase, Rollen-/Step-up-Prüfung, CAS-Konflikt, fremder ähnlich aussehender Statuscommit, Replay, korrekter terminaler DB-Zustand, Lockfreigabe erst nach Git-Erfolg und Abbruchverbot nach bestätigtem Branch-Push.
- Modellwechsel und neue Session.

**Nicht Teil dieses Tickets**

- Automatische unbegrenzte Schleife.
- Freie Status-/DB-Manipulation.

### AI6-039 — Review-only-Runvertrag: Claim und report-only Abschluss-Saga

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-013`, `AI6-026`
- **Requirement-Refs:** `RUN-010`, `RUN-001`, `RUN-002`, `RUN-005`, `GIT-008`, `TKT-005`
- **Erwartete Module:** `Runs`, `Tickets`, `Git`

**Ziel**

Review-only-Läufe als eigene Laufart mit gemeinsamem Claim, gebundenem Abschlussmodus und absturzsicherer report-only Abschluss-Saga normieren.

**Deliverables**

- Laufart `review_only` in `runs` samt Bindung des im Approval festgelegten Reviewgegenstands; die Quellnormalisierung selbst folgt in AI6-040.
- Approval-Erweiterung um Laufart, Reviewgegenstandsreferenz und Abschlussmodus mit exakt `manual` oder `automatic_after_gates`; serverseitige Risikoregeln dürfen nur auf `manual` verengen.
- Claim über denselben atomaren `ready → in_progress`-Status-CAS aus AI6-013 mit erweiterter Bedingung statt eines zweiten Anspruchspfads.
- Report-only Abschluss-Saga als `status_operation_id`-gebundene Instanz des gemeinsamen Status-Saga-Vertrags: Compare-and-Swap `in_progress → ready` mit vorab persistiertem Parent, Zielblob/-tree und beabsichtigtem Commit; `runs.state=completed` und Lockfreigabe erst nach Reconciliation des bestätigten eigenen Statuscommits.
- Abschlussprädikat: gültige Ergebnisse aller erforderlichen Reviewer- und konfigurierten Verifierslots, vollständige AC-Abdeckung, keine offenen Human-Requests-, Limit- oder Gate-Wartestände; wirksam blockierende Findings verhindern den Abschluss nicht.
- `manual_report`-Producer und Erweiterung des zentralen Resolverregisters um die autorisierte Abschlussbestätigung auf unverändertem gebundenem Reviewstand beziehungsweise Cancel sowie den report-only `status_sync`-Fall.
- Unveränderte Weiterverwendung der bestehenden Soft-/Block-/Hard-Cancel-Sagas für Review-only-Läufe.

**Akzeptanzvertrag**

- Ein Review-only-Lauf verändert weder Code noch verwaltete Refs; es entstehen weder Branch-Push noch `in_progress → review`.
- Der Abschluss läuft ausschließlich über die eigene Saga; direktes Setzen von `runs.state`, Wiederverwendung von `no_change_required` oder ein pushloser `in_progress → review`-Übergang sind technisch nicht möglich.
- `manual` erzeugt `wait_reason=manual_report`; die Bestätigung wirkt nur auf dem unveränderten gebundenen Reviewstand. `automatic_after_gates` schließt ohne zusätzliche Bestätigung erst nach exakt demselben Abschlussprädikat.
- Ein Crash an jeder Sagaphase reconciled ausschließlich den eigenen gespeicherten Commit; ein fremder ähnlich aussehender `ready`-Commit erzeugt weder Abschluss noch Lockfreigabe.
- Nach erfolgreichem Abschluss ist der Ticketstatus `ready`, die verbrauchte Approval ist keiner weiteren Run-Lineage zuordenbar, und ein neuer Lauf erfordert eine neue Approval-Saga nach kontrollierter Rücksetzung auf `todo`.
- Wiederanlauf nach Worker-Neustart setzt exakt die persistierte Sagaphase fort und erzeugt keine doppelten Wirkungen.

**Mindestens zu erzeugende Testfälle**

- Status-Saga-Crashmatrix des report-only Abschlusses an jeder gemeinsamen Phase einschließlich Replay, CAS-Konflikt und fremdem ähnlich aussehendem `ready`-Commit.
- Abschlussmodusmatrix `manual`/`automatic_after_gates` einschließlich Verengung durch Risikoregel und stale gewordener Bestätigung.
- Producer-/Resolver-/Cancel-Tests für `manual_report` und den report-only `status_sync`-Fall.
- Negativtests gegen Branch-Push, `in_progress → review`, direkten State-Write und `no_change_required`-Missbrauch.
- Abschlussprädikat mit offenem wirksamem `must_fix` (Abschluss möglich) gegenüber offenem Human Request beziehungsweise Limit-Wartestand (blockiert).

**Nicht Teil dieses Tickets**

- Quellnormalisierung, Bericht und Bedienung.
- Verifier-Orchestrierung.
- Echte Provideradapter.

### AI6-040 — Review-only-Quellbindung, Ausführung, Bericht und Bedienung

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-014`, `AI6-021`, `AI6-024`, `AI6-039`
- **Requirement-Refs:** `GIT-011`, `RUN-010`, `GIT-010`, `REV-002`, `REV-011`, `AGT-005`, `RUN-004`, `SEC-011`, `UI-004`
- **Erwartete Module:** `Runs`, `Git`, `Reviews`, `HumanLoop`, `Shared`

**Ziel**

Serverseitig gebundene Reviewgegenstände in einen wegwerfbaren Review-Checkpoint normalisieren, die Review-Pipeline mit FakeAgent vollständig ausführen und den gebundenen Abschlussbericht samt Bedienung bereitstellen.

**Deliverables**

- Quellnormalisierung für verwalteten Branch, gebundene Commit-Range beziehungsweise Einzelcommit, durch den Worker importierten validierten Patch und vorhandenen AI6-Checkpoint in einen wegwerfbaren, gitmetadatenfreien Review-Checkpoint mit Tree-OID-/Diff-Hash-Bindung; freie Arbeitsverzeichnisse, freie Dateiauswahl und PR-URLs werden deterministisch abgelehnt.
- Basisnachweis je Quelle gegen den im Approval gebundenen verifizierten Control-Stand; History-Rewrite und nicht verwandte Historie blockieren sichtbar.
- Ausführung der freigegebenen deterministischen Checks und aller ausgewählten Reviewer-Slots seriell auf demselben Review-Checkpoint über die bestehenden Verträge aus AI6-021, AI6-023 und AI6-024.
- Stufengerechte, redigierte und hashgebundene Kontextpakete als `run_artifacts` nach `REV-011`.
- Gebundener Abschlussbericht als redigiertes, retentionpflichtiges `run_artifact` mit Run-, Ticketblob-, Checkpoint-, Slot-, Check-, Coverage-, Finding-, Dispositions- und Entscheidungsbindung; derselbe Berichtsvertrag ist für den Abschluss von Implementierungsläufen wiederverwendbar.
- Bedienung im Panel: Review-only-Start mit Auswahl ausschließlich gebundener Gegenstände, Laufbeobachtung und Abschlussbestätigung; jede Wirkung läuft asynchron über Queue und Worker.
- FakeAgent-End-to-End für Erfolg, Findings, Human Request, Limitüberschreitung, Cancel und Crash-Recovery des Review-only-Modus.

**Akzeptanzvertrag**

- Nur gebundene Quellen sind wählbar; jede Quelle wird unmittelbar vor Ausführung erneut verifiziert, und Drift blockiert sichtbar statt still weiterzulaufen.
- Reviewer sehen ausschließlich den exportierten Review-Checkpoint ohne erreichbare Gitmetadaten; kein Providerprozess schreibt Code, Refs oder Ticketstatus.
- Der Bericht ist eine aus Git- und SQLite-Autoritäten rekonstruierbare Projektion, keine Status- oder Ticketautorität; in die Ticketdatei wird kein Ausführungsledger geschrieben.
- Wirksam blockierende Findings erscheinen vollständig im Bericht und verhindern den report-only Abschluss nicht.
- Browserrequests starten weder Agenten noch Git noch Checks direkt.
- Redaction und Retention gelten für Bericht und Kontextpakete wie für jedes andere Runartefakt.

**Mindestens zu erzeugende Testfälle**

- Quellmatrix Branch/Commit-Range/Patch/Checkpoint jeweils mit gültiger und ungültiger Basis sowie Drift zwischen Bindung und Ausführung.
- Ablehnungstests für PR-URL, freies Arbeitsverzeichnis und freie Dateiliste.
- Gitmetadaten-/Schreibschutz-Isolationstest des Review-Checkpoints.
- Berichts-Golden-Test einschließlich Redaction, Retention und Bindungsfeldern.
- Fake-E2E-Szenariomatrix einschließlich `manual_report`, `automatic_after_gates`, Cancel und Worker-Neustart.
- Feature-/Autorisierungstests der Bedienung.

**Nicht Teil dieses Tickets**

- Fixturn aus Review-only-Ergebnissen im selben Lauf.
- Echte Provideradapter.
- Semantische Deduplizierung.

### AI6-043 — Quellenabhängige advisory Finding-Verifikation

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-011`, `AI6-024`, `AI6-026`
- **Requirement-Refs:** `REV-010`, `REV-003`, `REV-006`, `REV-011`, `RUN-006`, `AGT-002`, `HUM-001`
- **Erwartete Module:** `Reviews`, `Runs`, `Agents`, `HumanLoop`

**Ziel**

Normalisierte Findings durch einen unabhängigen, quellenabhängig gewählten Verifierslot advisory prüfen lassen, ohne wirksame Dispositionen oder Auto-Unblock zu erzeugen.

**Deliverables**

- Verifier-Orchestrierungsschritt im bestehenden `RunOrchestrator`; providerunabhängig, kein zweiter Orchestrator, keine Orchestrierungslogik in Adaptern.
- Quellenabhängige Slotwahl aus den im Approval-Snapshot erlaubten Profilen: nie dasselbe Provider-/Modellprofil wie die Findingquelle, nie der Implementierungs-/Fixslot desselben Stands; ohne verfügbares unabhängiges Profil entsteht ein Human Request statt einer Selbstverifikation.
- Erweiterung der versionierten Schemafamilie aus AI6-016 um das Verifier-Resultatprofil mit Referenz auf genau ein Originalfinding beziehungsweise eine exakte Duplikatgruppe.
- Verifier-Kontextpaket nach `REV-011` mit Finding, Quellen, Evidenz, relevantem Code, betroffenen Akzeptanzkriterien und passenden Checkausgaben.
- Persistenz jedes Verifierergebnisses als weiteres unveränderliches `review_result` mit neuer Session je Lauf; Anzeige der Verifierevidenz in der Finding-UI aus AI6-024 ohne Mutation des Originals.
- Verifikationsrundenlimit unter dem zentralen `RUN-006`-Vertrag; Erschöpfung führt in `review_limit`, Widerspruch und `inconclusive` eskalieren nach zentraler Policy an HumanLoop.
- FakeAgent-Szenarien für bestätigt, widersprochen, `inconclusive` und ungültiges Schema.

**Akzeptanzvertrag**

- Ein Verifierergebnis verändert weder Originalfinding noch effektive Blockade; ein `must_fix` bleibt bis zu Fix oder autorisierter Disposition wirksam.
- Die Unabhängigkeitsmatrix ist nachweisbar: Ein Grok-Finding wird nie durch Grok verifiziert, ein Implementierungsslot verifiziert nie den eigenen Stand.
- Jede Verifikationsrunde liefert genau ein schema-validiertes, quellgebundenes Ergebnis; es gibt weder freie Debatte noch Mehrheitsentscheidung.
- Widerspruch oder fehlende Evidenz erzeugt einen sichtbaren HumanLoop-Eintrag und niemals ein automatisches Entblocken.
- Rundenlimits sind weder durch Retry noch durch Profilwechsel über das Servermaximum hinaus verbrauchbar.

**Mindestens zu erzeugende Testfälle**

- Slotwahl-/Unabhängigkeitsmatrix einschließlich fehlendem unabhängigem Profil.
- Advisory-Negativtest: Eine Verifierempfehlung „nicht zutreffend“ entblockt kein fremdes `must_fix`.
- Schema- und Referenzvalidierung positiv/negativ.
- Grenz- und Eins-darüber-Test des Verifikationsrundenlimits samt `review_limit`-Resolver.
- UI-Test der Verifierevidenz ohne Originalmutation.
- HumanLoop-Eskalation bei Widerspruch und `inconclusive`.

**Nicht Teil dieses Tickets**

- Wirksame Finding-Dispositionen.
- Echte Provideradapter.
- Modell-zu-Modell-Challenge-Loops.

## 15.6 M5 – Finalisierung und vollständiger Fake-Workflow

### AI6-027 — Finalchecks, Publish-Kandidat und deterministische Provenienz

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-021`, `AI6-025`, `AI6-026`
- **Requirement-Refs:** `GIT-005`, `GIT-006`, `REV-008`, `SEC-009`, `RUN-003`, `RUN-007`, `RUN-009`
- **Erwartete Module:** `Runs`, `Checks`, `Git`, `Reviews`

**Ziel**

Nach grünen Qualitätsreviews einen exakt gebundenen Publish-Kandidaten ohne finalen Commit erzeugen und deterministisch absichern.

**Deliverables**

- Final-Checkphase.
- Temporärer Git-Index und Candidate-Tree.
- Publish-Kandidat behält das unveränderte Ticket mit Status in_progress; kein Statuswechsel im Arbeitsbranch.
- Tree-OID, Diff-Hash und `candidate_base_sha`, das exakt dem neuesten `run_base_sha` entspricht, sowie Scope-/Config-/Prompt-/Policy-Bindung.
- Secret-/Symlink-/Modus-/Provenienz-Preflight.
- Zentrales Candidate-Gate aus wirksamen Finding-Dispositionen, vollständiger AC-Abdeckung, gültigen Review-/Checkbindungen und geschlossenen `MG-`-/`EXT-`-Gates.
- `manual_gate`-Producer und Erweiterung des zentralen Resolverregisters um autorisierte Evidenz, die vor Candidate an letzten gültigen Checkpoint, prospektive Tree-OID, Diff-Hash, Ticketvertrag und Runversion gebunden wird, beziehungsweise Cancel.

**Akzeptanzvertrag**

- Candidate entsteht nur, wenn kein effektiv blockierendes Finding offen ist, jede erforderliche AC-Abdeckung gültig ist und alle deklarierten `MG-`-/`EXT-`-Gates durch autorisierte, aktuelle Evidenz geschlossen sind.
- Ein offenes Gate setzt vor Candidate `wait_reason=manual_gate`; gültige Evidenz setzt ausschließlich das unveränderte Gate am gebundenen Checkpoint fort. Der daraus erzeugte Candidate übernimmt dieselbe Tree-/Diff-Bindung; eine Abweichung macht die Evidenz stale.
- Unbekannte Worktreeänderung blockiert vor LLM-Securityreview.
- Finalchecks können Reviewstand nicht still verändern.
- Candidate ist ohne finalen Commit reproduzierbar.

**Mindestens zu erzeugende Testfälle**

- Tree-/Diff-/`candidate_base_sha`-Bindung einschließlich Contract Amendment auf das neueste `run_base_sha`.
- Secretfixture.
- Unbekannte Datei/Commit/Mode/Symlink.
- Mutierender Finalcheck.
- Remote-Basisänderung.
- Effektive-Finding-/Disposition-/Gate-Matrix sowie Invalidierung nach Vertrags-, Prompt-, Policy- oder Checkpointänderung.
- Producer-/Resolver-/Cancel-Test für `manual_gate` einschließlich Evidenz vor Candidate und Invalidierung bei abweichender Candidate-Tree-OID.

**Nicht Teil dieses Tickets**

- LLM-Securityreview.
- Finaler Commit oder Push.

### AI6-028 — Optionales LLM-Sicherheitsgate

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-003`, `AI6-016`, `AI6-018`, `AI6-024`, `AI6-027`
- **Requirement-Refs:** `REV-008`, `SEC-008`, `SEC-009`, `AGT-009`, `GIT-010`, `HUM-001`, `HUM-002`
- **Erwartete Module:** `Reviews`, `Agents`, `HumanLoop`, `Shared`

**Ziel**

Den exakten Publish-Kandidaten optional mit einer frischen read-only LLM-Sitzung auf schädliche oder nicht autorisierte Codeänderungen prüfen.

**Deliverables**

- Security-Reviewer-Profil aus Instanzconfig.
- Read-only exportierter Candidate-Workspace ohne Projektcodeausführung oder erreichbare Gitmetadaten.
- Security-Prompt und Schema clear/security_findings/needs_human/inconclusive.
- Candidate-, Prompt-, Instruction-Snapshot-, Profil- und Policyhash-Bindung.
- Security-Findings über bestehendes Finding-/HumanLoop-Modell.
- `security_gate`-Producer und Erweiterung des zentralen Resolverregisters um neues gebundenes `clear`, policykonformen Step-up-Override beziehungsweise Cancel.
- Policybedingter Skip sichtbar und auditiert.

**Akzeptanzvertrag**

- strict aktiviert Gate standardmäßig.
- Aktives Gate fällt bei Provider-/Schema-/Sandboxfehler geschlossen aus.
- Ein nicht freigegebenes aktives Ergebnis setzt `wait_reason=security_gate`; sein Resolver akzeptiert nur Candidate-, Policy-, Profil- und Runversions-gebundene Wirkung.
- Repositoryprompt kann Rechte oder Instruktionspriorität nicht verändern.
- Candidate-Inhalt kann weder `.git`/Common-Dir noch Git-Hooks oder beschreibbare Ref-/Indexpfade sichtbar machen.
- Jede Candidateänderung invalidiert Ergebnis.
- Jede Änderung am Instruction-Snapshot invalidiert Ergebnis und erzwingt neue Security-Session.
- Kritischer Override verlangt Admin und Step-up.

**Mindestens zu erzeugende Testfälle**

- Prompt-Injection-Fixture.
- Gitmetadaten-/Hook-/Ref-Isolationstest.
- clear/finding/needs_human/inconclusive/invalid-json.
- Policy off/degraded.
- Treeänderung nach Review.
- Instruction-Snapshot-Drift und erzwungene neue Session.
- Human Override.
- Producer-/Resolver-/Cancel-Test für `security_gate`.

**Nicht Teil dieses Tickets**

- Versprechen vollständiger Malwarefreiheit.
- Mehrere Security-Reviewer oder Konsens.

### AI6-029 — Finaler Commit, Ticketstatus, Push, Drift und Cleanup

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-009`, `AI6-014`, `AI6-027`, `AI6-028`
- **Requirement-Refs:** `GIT-005`, `GIT-006`, `GIT-007`, `GIT-008`, `TKT-005`, `TKT-012`, `RUN-005`, `RUN-009`
- **Erwartete Module:** `Git`, `Runs`, `Tickets`

**Ziel**

Den akzeptierten Candidate unverändert committen, gemäß Pushpolicy veröffentlichen und den Run sauber abschließen.

**Deliverables**

- Bei geändertem Candidate Checkpoint-Squash zu genau einem Ticket-Commit mit `candidate_base_sha == latest run_base_sha` als einzigem Parent.
- Nachweis identischer Candidate- und Commit-Tree-OID sowie des exakten Commit-Parents.
- Bei bestätigtem `no_change_required` kein Commit: lokalen Run-Branchref exakt auf `run_base_sha` setzen und denselben Ref unter erwarteter Remote-OID veröffentlichen beziehungsweise als bereits identisch bestätigen.
- Manueller Pushbutton und optional automatic_after_gates.
- Expected-remote-OID und Branch-Allowlist.
- Nach bestätigtem Arbeits- oder No-change-Branchref separater, `status_operation_id`-gebundener Control-Branch-CAS `in_progress → review` mit vorab persistiertem Parent, Zielblob/-tree und beabsichtigtem Commit.
- Rückschreibung des `recorded_scope` aus `AI6-020` als AI6-eigener Abschnitt `## Recorded Scope` nach `TKT-012` in derselben Ticketdatei und in genau diesem einen Status-CAS, redigiert und ohne Contract Amendment.
- `manual_push`- und `status_sync`-Producer samt Erweiterung des zentralen Resolverregisters, Status-Saga-Reconciler, idempotentem Retry, Runabschluss, Lockfreigabe und Cleanup.

**Akzeptanzvertrag**

- Kein Commit/Push bei ungültigem oder veraltetem Security-/Reviewresultat.
- Kein finaler Commit und kein Push bei offenem beziehungsweise stale gewordenem `MG-`-/`EXT-`-Gate.
- Der Arbeitsbranch-Push verändert nur den Run-Branch; der spätere Status-CAS enthält ausschließlich die autorisierte Ticketstatusänderung und den gebundenen `## Recorded Scope`-Block derselben Ticketdatei. Jeder andere Treeeintrag bleibt bytegleich, und `ticket_contract_sha256` ist vor und nach dem CAS identisch.
- Jeder geänderte finale Commit besitzt genau das aktuelle `run_base_sha` als einzigen Parent. Der No-change-Zweig veröffentlicht stattdessen einen Run-Branchref, der exakt auf `run_base_sha` zeigt.
- Basisdrift blockiert verständlich.
- Retry nach unklarem Push prüft Remote statt doppelt zu pushen.
- Schlägt die Statussynchronisation fehl, bleibt das Projekt gesperrt und der bereits bestätigte Branch-Push wird nicht wiederholt.
- Ein Crash nach bestätigtem Status-CAS vor Runabschluss/Lockfreigabe reconciled ausschließlich den eigenen gespeicherten Commit; ein fremder ähnlich aussehender `review`-Commit wird nicht übernommen.
- Manuelles Warten setzt `wait_reason=manual_push`; ein autorisierter Push auf unverändertem Candidate oder Cancel ist der einzige Resolver.
- Ein durch leeren tatsächlichen Diff, Checks, AC-Abdeckung, Reviews und Gates bestätigter `no_change_required`-Lauf erzeugt keinen künstlichen leeren Code-Commit; erst der bestätigte Remote-Branchref auf `run_base_sha` erlaubt denselben Status-CAS und Runabschluss wie ein geänderter Candidate.

**Mindestens zu erzeugende Testfälle**

- Tree-OID-Gleichheit, `candidate_base_sha == run_base_sha` und exakter Single-Parent-Commit nach normalem Lauf sowie Contract Amendment.
- Pushmodi exakt `manual` und `automatic_after_gates`; unbekannte oder verkürzte Werte werden abgelehnt.
- Remote drift/non-fast-forward/unknown result.
- Post-Push-Status-Saga-Crashmatrix an jeder gemeinsamen Phase, Erfolg, Konflikt, Retry, fremder ähnlich aussehender `review`-Commit und genau einmaliger Runabschluss/Lockfreigabe.
- Producer-/Resolver-/Cancel-Tests für `manual_push` und `status_sync`.
- Recorded-Scope-Rückschreibung: derselbe Status-CAS schreibt Status und Scope-Dokumentation in genau einem Commit, lässt jeden anderen Treeeintrag bytegleich, hält `ticket_contract_sha256` unverändert, ist bei Wiederholung idempotent und fällt bei CAS-Konflikt ohne Teilwirkung aus.
- Cleanup nach Erfolg/Fehler.
- No-change-run ohne leeren Code-Commit, mit lokalem/remote Run-Branchref exakt auf `run_base_sha`, Driftprüfung, Status-CAS und Lockfreigabe.

**Nicht Teil dieses Tickets**

- Automatisches Merge.
- Push auf fremde Branches.

### AI6-030 — Projektqueue und abhängigkeitssicherer Auto-Start

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `medium`
- **Kind:** `feature`
- **Depends on:** `AI6-008`, `AI6-012`, `AI6-013`, `AI6-029`
- **Requirement-Refs:** `RUN-008`, `UI-006`, `TKT-008`, `RUN-005`, `GIT-001`
- **Erwartete Module:** `Runs`, `Tickets`, `Projects`

**Ziel**

Bereits freigegebene Tickets einfach in einer Projektqueue verwalten und nach einem vollständig abgeschlossenen Run optional das nächste startbare Ticket beginnen.

**Deliverables**

- FIFO-Queue innerhalb `ticket_approvals` auf Basis gültiger Approvals mit explizitem Einreihen/Entfernen; vor erfolgreichem atomarem Startclaim existiert kein `runs.state=queued`.
- Eligibility-Service für depends_on und konfigurierbare dependency_satisfied_statuses.
- Atomare Neubewertung nach Fetch/Read-Model-Refresh, Ticket-/Config-/Prompt-/Capability-/SecurityPolicy-Änderung, Approvalwiderruf, Dependency-Statuswechsel, Runabschluss und Queueintervention.
- Bindung der Queue-Eligibility an die `control_generation` aus AI6-006E: Jeder Queueeintrag führt die zur Erzeugungszeit gültige Generation mit, und eine davon abweichende Generation entzieht ihm die Startberechtigung. Dieses Ticket bewertet nicht selbst, ob ein Wechsel stattgefunden hat, und führt keinen zweiten Invalidierungsweg ein.
- Mobile Queueansicht mit blockierenden Gründen und nächstem startbaren Ticket.
- Idempotenter Auto-Start erst nach bestätigtem Branch-Push, erfolgreicher Statussynchronisation und Lockfreigabe.
- Neue Implementierungs- und Review-Sessions für jeden gestarteten Queueeintrag.

**Akzeptanzvertrag**

- Standardmäßig erfüllt nur done eine Abhängigkeit.
- Ein gültig freigegebenes `ready`-Ticket darf trotz noch nicht erfüllter Abhängigkeiten eingereiht bleiben; Eligibility entscheidet ausschließlich unmittelbar vor Claim und Start.
- Ungültig gewordene Approvals oder geänderte Ticketverträge werden nicht gestartet und bleiben mit Grund sichtbar.
- Ein Control-Branch-Wechsel entzieht allen wartenden Einträgen die Startberechtigung, bevor der zugehörige Fetch läuft; die Einträge bleiben mit diesem Grund sichtbar.
- Irrelevante Fast-forward-Control-Commits, einschließlich Claim/Statussync anderer Tickets, invalidieren wartende Approvals nicht allein; bei später erfüllter Dependency wird auf frischem `claim_parent_control_sha` erneut geprüft und atomar beansprucht.
- Blockierte Einträge verhindern nicht, dass ein späterer unabhängiger Eintrag startbar ist.
- Zwei konkurrierende Worker starten höchstens einen nächsten Run.
- Deaktiviertes auto_start_next lässt die Queue unverändert und erfordert manuellen Start.

**Mindestens zu erzeugende Testfälle**

- FIFO- und Dependency-Graph-Szenarien.
- Approval-Invalidierung während des Wartens.
- Wartende Approval über fremden Claim/Statussync und späteren Dependency-`done`-Commit hinweg; Fast-forward erlaubt, nicht verwandter History-Rewrite blockiert.
- Neubewertungsmatrix für jeden Trigger einschließlich erfüllter, wieder unerfüllter und zyklisch/fehlend gewordener Abhängigkeit.
- Branchwechseltest: Ein Control-Branch-Wechsel erhöht die `control_generation` und entzieht wartenden Einträgen damit die Startberechtigung; ein Autostart findet bis zum erfolgreichen Fetch nicht statt.
- Concurrent-completion/auto-start.
- Auto-Start an/aus.
- Neue Session-IDs je Ticket.

**Nicht Teil dieses Tickets**

- Prioritätsalgorithmus oder Optimierer.
- Parallele Runs innerhalb desselben Projekts.
- Projektübergreifende globale Queue.

### AI6-031 — Vollständige Runbeobachtung und mobile Bedienung

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `medium`
- **Kind:** `feature`
- **Depends on:** `AI6-008`, `AI6-018`, `AI6-024`, `AI6-026`, `AI6-029`, `AI6-030`
- **Requirement-Refs:** `UI-001`, `UI-004`, `SEC-007`, `SEC-011`
- **Erwartete Module:** `Runs`, `Reviews`, `HumanLoop`, `Shared`

**Ziel**

Den gesamten Run auf Smartphone und Laptop nachvollziehbar machen, ohne Rohlogs oder privilegierte Daten offenzulegen.

**Deliverables**

- Runübersicht mit Phase, Modellen, Reviewern und Iterationen.
- Diff-, Check-, Finding-, Human-Request-, Security- und Pushansichten.
- Polling mit Cursor/Version und Fallback.
- ANSI-/Control-Character-Sanitizing als reine Darstellungsgrenze und sichere Artefaktdownloads.
- Über den zentralen AI6-003-Redactor erzeugte Timeline sowie Retentionanzeige; das UI definiert keine zweite Redactionlogik.
- Idempotenter Retention-Scheduler, der abgelaufene Rohlogs, Provideroutputs und Artefaktbytes tatsächlich löscht, nur redigierte Audit-Tombstones mit zentralem projekt-/rungebundenem HMAC-Fingerprint, Fingerprint-Key-ID/-Version, Größe und Ablaufzeit erhält und weitere Ausgabe beziehungsweise Downloads sperrt.

**Akzeptanzvertrag**

- Reload oder Gerätewechsel verändert Run nicht.
- Keine doppelten Aktionen durch Polling.
- Große Diffs/Logs werden paginiert oder begrenzt.
- Untrusted Inhalte führen kein HTML/Script aus.
- ANSI-/HTML-Sanitizing und zentrale Redaction bleiben getrennt und werden in der richtigen Reihenfolge genau einmal angewendet.
- Deaktivierte Securitykontrollen sind dauerhaft sichtbar.
- Nach Ablauf ist weder über UI, Downloadroute, Queue-Retry noch den primären Artefaktspeicher auf gelöschte Rohdaten zuzugreifen; ein aktiver Run bleibt trotzdem durch Größenlimits begrenzt.

**Mindestens zu erzeugende Testfälle**

- Mobile Browser-Smoke.
- XSS/ANSI/OSC-Fixtures.
- Redactor-Integrations-Golden-Test ohne lokale UI-Patternliste oder Doppelredaction.
- Polling-Duplikate.
- Autorisierte Downloads und Größenlimits.
- Retentiontest mit Uhrzeitsteuerung für Löschen, idempotenten Wiederholungslauf, Tombstone und verweigerten Download.

**Nicht Teil dieses Tickets**

- WebSockets/SSE.
- Vollständige Rohprovidertranskripte als Default.
- Backup/Restore und der Nachweis gegen Wiederauferstehung gelöschter Rohdaten; beides folgt in AI6-036.

### AI6-032 — Vollständiger FakeAgent-End-to-End- und Recovery-Test

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `chore`
- **Depends on:** `AI6-026`, `AI6-028`, `AI6-029`, `AI6-030`, `AI6-031`
- **Requirement-Refs:** `AGT-005`, `AGT-009`, `GIT-010`, `RUN-005`, `RUN-006`, `RUN-009`, `REV-005`, `HUM-004`, `SEC-008`
- **Erwartete Module:** `Runs`, `Agents`, `Reviews`, `HumanLoop`, `Git`

**Ziel**

Den gesamten Workflow ohne Providerkosten reproduzierbar gegen Erfolgs-, Fehler-, Human- und Sicherheitsfälle absichern.

**Deliverables**

- E2E-Fixtures für Erfolg, bestätigtes No-change, Frage, Scope, vor Runstart freigegebenes `instruction_update` über den strukturierten Einzeldatei-Patchkanal, abgelehnte Same-run-Instruction-/Runtime-Profiländerung mit Reset und neuer Approval/Run-Lineage, Ticket-Contract-Amendment, Multi-Review, Fix, jede Limitart, jedes `wait_reason`, offene/geschlossene/stale Gates, Securityfinding, Mailfehler, Crash und Pushdrift.
- Wiederaufnahme nach Prozess-/Workerneustart.
- Deterministische Assertions auf Sessions, Tree-OIDs, Status und E-Mails.
- Release-Gate-Befehl für CI/Server.

**Akzeptanzvertrag**

- Jeder definierte Wartestatus ist über UI/Service auflösbar.
- Kein Szenario benötigt direkte DB-Manipulation.
- Doppelte Jobs/Antworten/Push-Retries bleiben idempotent.
- Kein Fake-Agent/Reviewer/Checker sieht Managed-Gitmetadaten, nicht freigegebene Host-/Parent-Instruktionen oder nicht freigegebene Workspace-/Home-Konfiguration, MCP, Plugins, Skills, Hooks, Commands beziehungsweise Helper; Instruction- oder Runtime-Profil-Drift erzwingt kontrollierte Runrücksetzung, neue Approval, neuen Run und neue Sessions.
- Alle finalen Commits entsprechen geprüftem Candidate.
- Kein Candidate, Commit oder Push ist mit effektiv blockierendem Finding oder offenem/stale `MG-`-/`EXT-`-Gate möglich.
- Jede Zeit-, Aufruf-, Review-, Fix-, zusätzliche Scope-Pfad-, Datei-, Changed-Byte-, Instruktionsdatei-, Instruktions-Einzel-/Gesamtbyte-, Importtiefen-, finale-Promptinput-, Artefakt- und Provideroutputgrenze fällt ohne partiellen Import kontrolliert aus und ist über den vorgesehenen Resolver oder Cancelpfad behandelbar.
- Testfehler erzeugen konkrete Folge-Tickets statt Scope-Ausweitung.

**Mindestens zu erzeugende Testfälle**

- Komplette Szenariomatrix für sämtliche `wait_reason`-Werte, Producer und Resolver.
- Grenzwertmatrix sämtlicher RUN-006-Limits einschließlich `max_added_scope_paths` und Gate-Prädikatmatrix einschließlich Evidenzinvalidierung.
- Crash-Injection an kritischen Grenzen.
- Gitmetadaten-/Instruction-Autodiscovery-/Provider-Runtime-/Snapshotdrift-E2E einschließlich bösartiger `.codex`-/`.claude`-Konfiguration, Helperstart und Sessionneustart.
- Zwei Browser-Sessions.
- SecurityPolicy strict/custom/development.

**Nicht Teil dieses Tickets**

- Echte Providerqualität.
- Produktiver Merge.

## 15.7 M6 – Echte Provideradapter

### AI6-033 — Codex-CLI-Adapter

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-011`, `AI6-015`, `AI6-016`, `AI6-032`
- **Requirement-Refs:** `AGT-001`, `AGT-002`, `AGT-003`, `AGT-004`, `AGT-007`, `AGT-009`, `AGT-010`, `GIT-010`, `RUN-006`, `SEC-005`
- **Erwartete Module:** `Agents`

**Ziel**

Codex über den gemeinsamen Adaptervertrag mit strukturiertem Output, Sessionfortsetzung und gehärteter Policy anbinden.

**Deliverables**

- Capability-Erkennung.
- Start und Resume.
- Modell-/Effort-Mapping aus Profil.
- Strukturierte Ausgabe und Fehlernormalisierung.
- Genau ein gepinnter Headless-Transport nach `AGT-010`: nicht-interaktiver `exec`-Modus mit JSONL-Ereignissen und schema-gebundener finaler Antwort, ephemerer Session ohne User-Config/-Rules sowie rollenabhängigem expliziten Sandboxprofil; Schreibrechte ausschließlich im wegwerfbaren Export für Implementierungs-/Fixturns, Reviews read-only. Die konkreten Flagformen sind Adapterdetails und werden je CLI-Version durch den Capability-Doctor nachgewiesen.
- Rollen implementation und fix; zusätzlich quality_review ausschließlich für einen Slot, der weder Implementierung noch Fix desselben Stands ausgeführt hat — praktisch nur in Review-only-Läufen mit neuer Session.
- Erfassung belastbar gemeldeter Token-/Kosten-/Nutzungswerte je Turn samt Quelle; fehlende Werte bleiben `unknown`.
- Codex-spezifische native AGENTS-/Discoveryauflösung ausschließlich aus dem gebundenen read-only Instruction-Snapshot in der gitmetadatenfreien Tree-Sicht; Resume prüft Snapshot-/Sessionhash.
- Codex startet mit versiegeltem serverseitigem Runtime-Profil und isoliertem Home; Repository-/Workspace-/Home-Config, MCP, Plugins, Skills, Hooks, Commands und sonstige Autoload-Helper bleiben aus, sofern sie nicht ausdrücklich serverseitig allowlisted und im freigegebenen Profilhash enthalten sind.
- Auth wird ausschließlich als kurzlebige read-only Projektion des gewählten persistenten Credential-Stores eingebunden; der Adapter liest oder schreibt keinen übrigen persistenten Homeinhalt.
- Providerseitige Voraufrufprüfung über den gemeinsamen Limitvertrag für Instruktionsdateien/-bytes/-Importtiefe und den tatsächlich assemblierten finalen Promptinput; Überschreitung startet weder Prozess noch partiellen stdin-Transfer.
- AI6-eigene Sandbox-/Netzwerkpolicy mit Fail Closed.

**Akzeptanzvertrag**

- Keine freien CLI-Flags aus Projekt oder UI.
- Session-ID wird nur im gebundenen Run verwendet.
- Ungültiges JSON wird als kontrollierter Fehler behandelt.
- SecurityPolicy kann nicht durch Repositoryconfig gelockert werden.
- Host-, Parent-, Home- oder zur Laufzeit geänderte Workspace-Instruktionen werden nicht zusätzlich entdeckt; lässt sich das nicht erzwingen, startet der Adapter nicht.
- `.codex`-, Home- oder Workspace-Konfiguration und nicht freigegebene MCP-/Plugin-/Skill-/Hook-/Command-/Helpermechanismen werden weder geladen noch gestartet; lässt sich dies für die eingesetzte CLI-Version nicht nachweisen, startet der Adapter nicht.
- Fremdprofilcredentials, persistentes Providerhome, Cache und History sind unerreichbar; Rotation oder Logout invalidiert die Sessionprojektion und verhindert Resume.
- Adapter und Resume verwenden die zentral gezählten Snapshot-/Promptbytes und können weder Projekt- noch Servermaximum durch providerinterne Imports umgehen.

**Mindestens zu erzeugende Testfälle**

- Fake-Codex-Binary-Contracttests.
- Timeout/Exitcode/invalid-json.
- Discovery-Negativtests für Host/Parent/Home, nicht freigegebene AGENTS-Hierarchie, `.codex`-/Workspace-/Home-Config, MCP, Plugins, Skills, Hooks, Commands, externe Helper, Snapshotdrift, erreichbare `.git`-Metadaten und Resume mit abweichendem Instruction-/Runtime-Profil-Hash.
- Authprojektions-Negativtests für Fremdprofil, Persistenz-/Cache-/Historyleck, Schreibversuch, Rotation/Logout und Resume.
- Grenz-/Eins-darüber-Tests für Instruktionsdateien, Einzel-/Gesamtbytes, Importtiefe und finalen Promptinput ohne Prozessstart oder partiellen stdin.
- Optionaler echter Smoke-Test hinter explizitem Flag.

**Nicht Teil dieses Tickets**

- Direkte OpenAI-API.
- Providerunabhängige Orchestrierungslogik.

### AI6-034 — Claude-CLI-Adapter

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-011`, `AI6-015`, `AI6-016`, `AI6-032`
- **Requirement-Refs:** `AGT-001`, `AGT-002`, `AGT-003`, `AGT-004`, `AGT-007`, `AGT-009`, `GIT-010`, `RUN-006`, `SEC-005`
- **Erwartete Module:** `Agents`

**Ziel**

Claude-Modelle einschließlich aller serverseitig konfigurierten Profile, zum Beispiel Sonnet, Opus und Fabel über denselben Adaptervertrag anbinden.

**Deliverables**

- Capability-Erkennung.
- Start und Resume.
- Modell-/Effort-Mapping aus Profil.
- Strukturierte Ausgabe und Fehlernormalisierung.
- Claude-spezifische native CLAUDE-/Import-/Discoveryauflösung ausschließlich aus dem gebundenen read-only Instruction-Snapshot in der gitmetadatenfreien Tree-Sicht; Resume prüft Snapshot-/Sessionhash.
- Claude startet mit versiegeltem serverseitigem Runtime-Profil und isoliertem Home; Repository-/Workspace-/Home-Config, MCP, Plugins, Skills, Hooks, Commands und sonstige Autoload-Helper bleiben aus, sofern sie nicht ausdrücklich serverseitig allowlisted und im freigegebenen Profilhash enthalten sind.
- Auth wird ausschließlich als kurzlebige read-only Projektion des gewählten persistenten Credential-Stores eingebunden; der Adapter liest oder schreibt keinen übrigen persistenten Homeinhalt.
- Providerseitige Voraufrufprüfung über den gemeinsamen Limitvertrag für Instruktionsdateien/-bytes/-Importtiefe und den tatsächlich assemblierten finalen Promptinput; Überschreitung startet weder Prozess noch partiellen stdin-Transfer.
- Managed Sandbox-/Permission-Policy mit Fail Closed.

**Akzeptanzvertrag**

- Keine projekt-/workspace-/homeseitige `.claude`-Konfiguration, Hooks, Plugins, Skills, Commands, MCP-Server oder externe Helper werden geladen oder lockern die Policy; lässt sich dies für die eingesetzte CLI-Version nicht nachweisen, startet der Adapter nicht.
- Jeder Reviewer besitzt getrennte Session.
- Unsupported effort/model ist vor Runstart sichtbar.
- Output wird identisch zum Fake/Codex-Schema validiert.
- Host-, Parent-, Home-, Import- oder zur Laufzeit geänderte Workspace-Instruktionen wirken nur, wenn sie exakt im freigegebenen Snapshot liegen; andernfalls fällt der Adapter geschlossen aus.
- Adapter und Resume verwenden die zentral gezählten Snapshot-/Promptbytes und können weder Projekt- noch Servermaximum durch providerinterne Imports umgehen.
- Fremdprofilcredentials, persistentes Providerhome, Cache und History sind unerreichbar; Rotation oder Logout invalidiert die Sessionprojektion und verhindert Resume.

**Mindestens zu erzeugende Testfälle**

- Fake-Claude-Binary-Contracttests.
- Resume/Sessiontrennung.
- Sandbox-unavailable.
- Discovery-Negativtests für Host/Parent/Home/Imports, `.claude`-/Workspace-/Home-Config, MCP, Plugins, Skills, Hooks, Commands, externe Helper, Snapshotdrift, erreichbare `.git`-Metadaten und Resume mit abweichendem Instruction-/Runtime-Profil-Hash.
- Authprojektions-Negativtests für Fremdprofil, Persistenz-/Cache-/Historyleck, Schreibversuch, Rotation/Logout und Resume.
- Grenz-/Eins-darüber-Tests für Instruktionsdateien, Einzel-/Gesamtbytes, Importtiefe und finalen Promptinput ohne Prozessstart oder partiellen stdin.
- Optionaler echter Smoke-Test hinter Flag.

**Nicht Teil dieses Tickets**

- Direkte Anthropic-API.
- Modellspezifische Prompts.

### AI6-035 — Provider-Onboarding, Credential-Setup und Capability-Doctor

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-003`, `AI6-005A`, `AI6-033`, `AI6-041`, `AI6-042`
- **Requirement-Refs:** `AGT-002`, `AGT-007`, `AGT-010`, `OPS-003`, `SEC-005`
- **Erwartete Module:** `Agents`, `Shared`

**Ziel**

Andere Entwickler sicher durch Providerlogin, Profilprüfung und Adapterfreigabe für die erste Providerstufe führen.

**Deliverables**

- Interaktive CLI-Kommandos für Codex-, Grok- und Copilot-Login im Agent-Prozess; das Claude-Onboarding wird bei Umsetzung von AI6-034 über denselben Vertrag ergänzt, ohne dass dieses Ticket darauf wartet.
- Persistente, getrennte Provider-Credential-Stores außerhalb jedes Execution-Home sowie pro Session ein frisches versiegeltes Home mit minimaler read-only Authprojektion für genau ein Profil.
- Capability-Synchronisierung und Profilstatus im Panel für alle Profile der ersten Providerstufe.
- Version-Pinning je CLI und Re-Doctor nach Upgrade; der Doctor weist je gepinnter Version den `AGT-010`-Headless-Transport sowie die nachweisbaren Sandbox-, Tool- und Autodiscovery-Grenzen des jeweiligen Adapters nach und setzt andernfalls `unavailable` oder `degraded` statt eines stillen Fallbacks.
- Negativtests auf Credential-Sichtbarkeit.

**Akzeptanzvertrag**

- Webprozess sieht keine Providercredentials.
- Ein Profil ist nur auswählbar, wenn Adapter/Modell/Effort verifiziert sind.
- Doctor unterscheidet unavailable, degraded und ready; ein nicht nachweisbarer Headless-Transport oder eine nicht nachweisbare Sandbox-/Tool-/Autodiscovery-Grenze macht genau dieses Profil nicht startbar, ohne andere Profile zu blockieren.
- Ein fehlendes oder nicht eingerichtetes Claude-Profil blockiert weder Onboarding noch Freigabe der drei V1-Profile.
- Upgrade invalidiert alte Sicherheitsbestätigung.
- Rotation und Logout invalidieren Authprojektionen, laufende Sessions und Capabilitystatus; Cache, History, Providerconfig oder Pluginzustand werden nicht aus dem Execution-Home in den Credential-Store zurückgeschrieben.

**Mindestens zu erzeugende Testfälle**

- Fresh-install-Onboarding.
- Credential-Leak-Test.
- Profiltrennungs-, minimale-Projektion-, read-only-, Rotation-/Logout- und Cache-/History-Nichtpersistenztests.
- Capability-Änderung.
- CLI-Upgrade-Recheck.

**Nicht Teil dieses Tickets**

- Automatische Beschaffung von Abos/API-Keys.
- Credentialanzeige im Panel.

### AI6-041 — Grok-CLI-Adapter

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-011`, `AI6-015`, `AI6-016`, `AI6-032`
- **Requirement-Refs:** `AGT-001`, `AGT-002`, `AGT-003`, `AGT-004`, `AGT-007`, `AGT-009`, `AGT-010`, `GIT-010`, `RUN-006`, `SEC-005`
- **Erwartete Module:** `Agents`

**Ziel**

Die Grok-Build-CLI als unabhängigen Review- und Verifier-Adapter über den gemeinsamen Adaptervertrag anbinden.

**Deliverables**

- Capability-Erkennung.
- Start und Sessionverwaltung je Slot.
- Modell-/Effort-Mapping aus Profil.
- Strukturierte Ausgabe und Fehlernormalisierung.
- Genau ein gepinnter Headless-Transport nach `AGT-010`: nicht-interaktiver Promptmodus mit Streaming-JSON-Ereignissen, deaktiviertem Auto-Update und begrenzten Turns; für Review und Verifikation ein nachgewiesenes read-only Sandboxprofil und ausschließlich über die Toolallowlist freigegebene Lesetools; Subagenten, Memory und Websuche sind deaktiviert. Die konkreten Flagformen sind Adapterdetails und werden je CLI-Version durch den Capability-Doctor nachgewiesen; ACP bleibt eine spätere, eigenständig zu prüfende Transportoption.
- Rollen quality_review und finding_verification; keine Implementierungs- oder Fixrolle in der ersten Providerstufe.
- Grok-spezifische native Discoveryauflösung ausschließlich aus dem gebundenen read-only Instruction-Snapshot in der gitmetadatenfreien Tree-Sicht; versiegeltes serverseitiges Runtime-Profil und isoliertes Home wie bei den übrigen Adaptern.
- Auth ausschließlich als kurzlebige read-only Projektion des gewählten persistenten Credential-Stores.
- Providerseitige Voraufrufprüfung über den gemeinsamen Limitvertrag; Überschreitung startet weder Prozess noch partiellen stdin-Transfer.
- Erfassung belastbar gemeldeter Token-/Kosten-/Nutzungswerte je Turn samt Quelle; fehlende Werte bleiben `unknown`.

**Akzeptanzvertrag**

- Keine freien CLI-Flags aus Projekt oder UI.
- Der Adapter extrahiert die finale Providerantwort aus dem Streaming-JSON und validiert sie gegen den zentralen `ai6.quality-review.v1`- beziehungsweise Verifier-Vertrag; das Grok-Eventschema wird nie zweiter Fachvertrag, und eine nicht extrahierbare oder ungültige Antwort ist ein kontrollierter Fehler.
- Ein nicht nachweisbares read-only Sandbox-/Toolprofil, nicht abschaltbare Autodiscovery oder nicht abschaltbare Subagenten-/Memory-/Websuchefunktionen verhindern den Start fail closed.
- Host-, Parent-, Home- oder zur Laufzeit geänderte Workspace-Instruktionen werden nicht zusätzlich entdeckt; nicht freigegebene MCP-/Plugin-/Skill-/Hook-/Command-/Helpermechanismen bleiben aus.
- Fremdprofilcredentials, persistentes Providerhome, Cache und History sind unerreichbar; Rotation oder Logout invalidiert die Sessionprojektion.
- Adapter und Voraufrufprüfung verwenden die zentral gezählten Snapshot-/Promptbytes und können kein Servermaximum umgehen.

**Mindestens zu erzeugende Testfälle**

- Fake-Grok-Binary-Contracttests einschließlich Streaming-JSON-Extraktion, verstümmeltem Stream und finaler Antwort.
- Timeout/Exitcode/invalid-json.
- Discovery-Negativtests für Host/Parent/Home, Workspace-Config, MCP, Plugins, Skills, Hooks, Commands, Subagenten, Memory, Websuche, Snapshotdrift und erreichbare `.git`-Metadaten.
- Authprojektions-Negativtests für Fremdprofil, Persistenz-/Cache-/Historyleck, Schreibversuch und Rotation/Logout.
- Grenz-/Eins-darüber-Tests für Instruktions-/Promptinput-Maxima ohne Prozessstart.
- Optionaler echter Smoke-Test hinter explizitem Flag.

**Nicht Teil dieses Tickets**

- Direkte xAI-API.
- ACP-Langzeitprozesse.
- Verifier-Orchestrierung und Slotwahl.
- Implementierungs- oder Fixturns.

### AI6-042 — GitHub-Copilot-CLI-Adapter

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `feature`
- **Depends on:** `AI6-011`, `AI6-015`, `AI6-016`, `AI6-032`
- **Requirement-Refs:** `AGT-001`, `AGT-002`, `AGT-003`, `AGT-004`, `AGT-007`, `AGT-009`, `AGT-010`, `GIT-010`, `RUN-006`, `SEC-005`
- **Erwartete Module:** `Agents`

**Ziel**

Die GitHub-Copilot-CLI als zweiten unabhängigen Review-Adapter über den gemeinsamen Adaptervertrag anbinden, ohne GitHub-Mutationen oder PR-Connector.

**Deliverables**

- Capability-Erkennung.
- Start und Sessionverwaltung je Slot.
- Modell-/Effort-Mapping aus Profil.
- Strukturierte Ausgabe und Fehlernormalisierung.
- Genau ein gepinnter Headless-Transport nach `AGT-010`: dokumentierter programmatischer Promptmodus ohne Rückfragen und ohne Remote-Export mit finaler Textantwort, aus der der Adapter das AI6-JSON extrahiert und validiert; ein nativer maschinenlesbarer Ausgabemodus wird erst verwendet, wenn der Capability-Doctor ihn für die gepinnte Version nachweist. Der Copilot-SDK-/Servermodus ist kein V1-Transport.
- Frisches versiegeltes `COPILOT_HOME` je Session als nachweisbare Konfigurationsgrenze ohne MCP-Server-, Plugin-, Skill- oder Hook-Konfiguration; repositorygetriebene Extensions, Hooks und Workspace-MCP bleiben aus.
- Toolgrenzen ausschließlich über die Tool-Allow-/Excludelist: Shell, Schreibzugriffe, Delegation, Memory, URL-Zugriff und MCP sind für Reviews ausgeschlossen; ein eingebauter GitHub-MCP-Server begründet keine Ausnahme.
- Rolle quality_review; eine Verwendung als quellenabhängiger advisory Verifierslot für fremde Findings ist über dasselbe Profil nur zulässig, wenn die Serverkonfiguration sie ausdrücklich freigibt.
- Auth ausschließlich als kurzlebige read-only Projektion des gewählten persistenten Credential-Stores.
- Providerseitige Voraufrufprüfung über den gemeinsamen Limitvertrag; Überschreitung startet weder Prozess noch partiellen stdin-Transfer.
- Erfassung belastbar gemeldeter Nutzungswerte je Turn samt Quelle; fehlende Werte bleiben `unknown`.

**Akzeptanzvertrag**

- Keine freien CLI-Flags aus Projekt oder UI.
- Die finale Textantwort wird gegen den zentralen `ai6.quality-review.v1`-Vertrag validiert; eine fehlende, mehrdeutige oder ungültige eingebettete JSON-Antwort ist ein kontrollierter `invalid_json`-Fehler und niemals ein impliziter Erfolg.
- Copilot mutiert keine Pull Requests, Issues, Commits, Branches oder Remotes; es existiert kein GitHub-PR-Review-Connector.
- Mangels dokumentiertem Sandboxmodus sind die Toolallowlist und die Container-/Dateisystemgrenze die maßgeblichen Kontrollen; weist die gepinnte CLI-Version das versiegelte `COPILOT_HOME` oder die Toolgrenzen nicht nach, bleibt das Profil nicht startbar.
- Host-, Parent-, Home- oder zur Laufzeit geänderte Workspace-Instruktionen werden nicht zusätzlich entdeckt; nicht freigegebene MCP-/Plugin-/Skill-/Hook-/Command-/Helpermechanismen bleiben aus.
- Fremdprofilcredentials, persistentes Providerhome, Cache und History sind unerreichbar; Rotation oder Logout invalidiert die Sessionprojektion.
- Adapter und Voraufrufprüfung verwenden die zentral gezählten Snapshot-/Promptbytes und können kein Servermaximum umgehen.

**Mindestens zu erzeugende Testfälle**

- Fake-Copilot-Binary-Contracttests für Textantwort mit gültigem, fehlendem, mehrfachem und ungültigem eingebettetem JSON.
- Timeout/Exitcode/Abbruch.
- `COPILOT_HOME`-Sealing- und Discovery-Negativtests für Host/Parent/Home, Workspace-Config, Extensions, MCP, Plugins, Skills, Hooks und erreichbare `.git`-Metadaten.
- Toolgrenzen-Negativtests für Shell, Schreibzugriff, Delegation, Memory, URL-Zugriff und MCP.
- Authprojektions-Negativtests für Fremdprofil, Persistenz-/Cache-/Historyleck, Schreibversuch und Rotation/Logout.
- Grenz-/Eins-darüber-Tests für Instruktions-/Promptinput-Maxima ohne Prozessstart.
- Optionaler echter Smoke-Test hinter explizitem Flag.

**Nicht Teil dieses Tickets**

- GitHub-PR-Review-Connector oder automatische GitHub-Mutationen.
- Copilot-SDK-/Servermodus.
- Implementierungs- oder Fixturns.
- Verifier-Orchestrierung und Slotwahl.

## 15.8 M7 – Betrieb, Migration und Pilot

### AI6-036 — Installation, Backup/Restore und Security-Release-Gate

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `chore`
- **Depends on:** `AI6-002`, `AI6-003`, `AI6-005A`, `AI6-005B`, `AI6-015`, `AI6-035`, `AI6-029`, `AI6-031`, `AI6-032`
- **Requirement-Refs:** `OPS-001`, `OPS-003`, `OPS-006`, `SEC-010`, `SEC-011`, `PROD-002`
- **Erwartete Module:** `Shared`, `Auth`, `Projects`

**Ziel**

AI6 für andere Entwickler reproduzierbar installierbar und im strict-Serverprofil sicher betreibbar dokumentieren und prüfen.

**Deliverables**

- ai6:install-Assistent und .env.example.
- VPN-/HTTPS-Referenz sowie eingeschränkter SSH-Tunnel.
- Backup/Restore für SQLite, APP_KEY, Projektmetadaten und verschlüsselte Artefakte.
- ai6:doctor --security --all-processes --require-strict.
- Doctor-/Releaseprüfung für aktuellen deterministischen Ticketmanifestexport ohne Drift.
- Retention, tatsächliche Löschung, Tombstones, Rotation, Upgrade und Disaster-Recovery-Dokumentation.
- Rootless-/Hardening-Empfehlungen ohne Plattformzwang.

**Akzeptanzvertrag**

- Frische Linux-Installation folgt dokumentiertem Pfad.
- Restore erhält entschlüsselbare Daten und widerruft offene Sessions/Challenges.
- Strict-Doctor blockiert defekte aktive Kontrollen.
- Strict-Doctor und Release-Gate blockieren ein fehlendes oder vom Plan abweichendes Ticketmanifest.
- Backup/Restore lässt abgelaufene und bereits gelöschte Rohlogs, Provideroutputs und Artefaktbytes nicht wiederauferstehen; Retention wird nach Restore idempotent fortgesetzt.
- Custom/development sind sichtbar und dokumentiert.
- Keine geheimen Schlüssel landen im Repository.

**Mindestens zu erzeugende Testfälle**

- Fresh-install-Smoke.
- Backup/Restore mit anschließender Rotation.
- Backup/Restore vor und nach Retentionablauf einschließlich Tombstone und verweigertem Download.
- Manifestgenerierung und Release-Drifttest.
- Mail/Git/Provider/Sandbox/Checker-Doctor.
- SSH-/VPN-Zugriffstest.

**Nicht Teil dieses Tickets**

- Kubernetes.
- Vault-/HSM-Pflicht.
- Öffentlicher SaaS-Betrieb.

### AI6-037 — Migration des bisherigen Ticket-Prompt-Tools

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `medium`
- **Kind:** `chore`
- **Depends on:** `AI6-007`, `AI6-008`, `AI6-009`, `AI6-016`, `AI6-032`
- **Requirement-Refs:** `TKT-003`, `TKT-005`, `TKT-011`, `OPS-005`
- **Erwartete Module:** `Tickets`, `Prompts`

**Ziel**

Bestehende Ticketdateien und Promptinhalte verlustfrei in die neue Git-native Struktur überführen, ohne Legacy-Statusdrift zu konservieren.

**Deliverables**

- Dry-run-Migrationskommando für YAML-Codeblock zu Frontmatter.
- Mapping alter Statuswerte; reserved erfordert eine explizite menschliche Zuordnung statt stiller Konvertierung.
- Übernahme von `## Goal`, `## Tasks`, AC, `## Test Cases` und files; alte Referenzen werden auf ausschließlich `docs/AI6_IMPLEMENTATION_PLAN.md — <REQ-ID>` unter `spec_refs` normalisiert, genaue §-Hinweise als deutsche Prosa bewahrt.
- Zuordnung statischer Browserprompts zum zentralen Promptkatalog aus AI6-011, ohne zweite Templates oder Renderer anzulegen.
- Explizite Zielprofilwahl: fremde migrierte Tickets dürfen `generic_v1` verwenden; ein AI6-Detailticket wird nur nach vollständiger Profilvalidierung `ai6_detail_v1`.
- Bericht über README-/docs-Statusindizes als nicht autoritative Altlast.

**Akzeptanzvertrag**

- M169 wird semantisch gleichwertig migriert.
- Originaldatei bleibt im Dry-run unverändert.
- Migration erzeugt validierbare V1-Datei.
- Keine automatische Massenlöschung oder stiller Statuswechsel.
- Legacy-Lesen bleibt ausschließlich bis zum erfolgreich protokollierten Pilotabschluss möglich; der Abschaltpunkt und alle noch nicht migrierten Kandidaten werden sichtbar ausgewiesen.

**Mindestens zu erzeugende Testfälle**

- M169-Golden-Diff.
- Statusmapping.
- Roundtrip Parser.
- Profiltests für `generic_v1`, `ai6_detail_v1` und unzulässige stillschweigende Hochstufung.
- Dry-run/apply/rollback-Szenarien.

**Nicht Teil dieses Tickets**

- Dauerhafte Pflege zweier Formate.
- Automatische Änderung fachlicher Anforderungen.

### AI6-038 — Realer M169-Pilot und MVP-Abnahme

- **Initialstatus des späteren Detailtickets:** `todo`
- **Risiko:** `high`
- **Kind:** `spike`
- **Depends on:** `AI6-032`, `AI6-035`, `AI6-036`, `AI6-037`
- **Requirement-Refs:** `PROD-001`, `AGT-001`, `RUN-010`, `REV-001`, `HUM-002`, `RUN-009`, `OPS-005`
- **Erwartete Module:** `Auth`, `Projects`, `Tickets`, `Runs`, `Agents`, `Reviews`, `HumanLoop`, `Git`, `Checks`, `Prompts`, `Shared`

**Ziel**

AI6 mit einem realen, anspruchsvollen Git-Ticket und echten CLI-Sitzungen der ersten Providerstufe unter kontrollierten Bedingungen abnehmen.

**Deliverables**

- Migriertes M169 als Pilot.
- Codex-Profil für Implementierung und Fixturns.
- Mindestens zwei unabhängige Qualitätsreviewer über die Grok-Build-CLI und die GitHub-Copilot-CLI; optional zusätzlich ein Claude-Reviewer, sofern AI6-034 umgesetzt ist.
- Zuerst ein realer Review-only-Pilotlauf auf einem gebundenen Stand mit manuell bestätigtem report-only Abschluss und Messung von Findingqualität, Laufzeit und Providerfehlern; erst danach der vollständige Implementierungsablauf.
- Realer Review-/Verifikations-/Fix-/Security-/Pushablauf auf Testbranch.
- Vor Candidate an letzten gültigen Checkpoint, prospektive Tree-OID und Diff-Hash gebundene autorisierte Evidenz für jedes M169-spezifische `MG-`-/`EXT-`-Gate.
- Nach Candidate getrennte Prüfung des Security-Ergebnisses über `security_gate`; sie ist keine vorgezogene `MG-`-/`EXT-`-Evidenz und ein erforderlicher Human Override bleibt Candidate-gebunden.
- Nach erfolgreichem Pilotabschluss Abschaltung beziehungsweise Entfernung des Legacy-Lesers, erneuter Read-Model-Aufbau und vollständige V1-Profilvalidierung aller migrierten Pilotickets.
- Pilotprotokoll mit beobachteten Grenzen und Folge-Tickets.

**Akzeptanzvertrag**

- Ticket wird im Panel geprüft und freigegeben.
- Modelle und Efforts entsprechen Approval.
- Alle Reviewer prüfen denselben Checkpoint.
- Manuelle/externe Gates bleiben bis zur gebundenen Evidenz ehrlich offen; ein offenes oder stale Gate verhindert Candidate, Commit und Push.
- Das LLM-Securityergebnis entsteht erst auf dem Candidate. Ein nicht freigegebenes Ergebnis blockiert Commit und Push über `security_gate`, ohne rückwirkend Voraussetzung der Candidate-Erzeugung zu sein.
- Branch wird ohne Änderung fremder Refs veröffentlicht.
- Nach dem Pilot akzeptiert die reguläre Leseroute kein Legacyformat mehr; noch vorhandene Legacydateien erzeugen einen expliziten Migrationsfehler statt stillen Fallbacks.
- Ein anderer Entwickler kann Ablauf anhand Doku nachvollziehen.

**Mindestens zu erzeugende Testfälle**

- Realer CLI-Smoke.
- End-to-End-Run mit Git-Remote.
- Mobile Einsicht/Intervention.
- Post-Pilot-Restore- und Cleanup-Check.
- Legacy-Cutoff-Test, Neuaufbau der Read Models und erneuter `generic_v1`-/`ai6_detail_v1`-Validierungslauf.

**Nicht Teil dieses Tickets**

- Unbeaufsichtigter Produktionsmerge.
- Breite Fehlerbehebung außerhalb separater Folge-Tickets.

**Manuelle/externe Gates**

- Vor Candidate: menschliche Prüfung des realen Diffs auf dem letzten gültigen Checkpoint und seiner prospektiven Tree-/Diff-Bindung.
- Vor Candidate: manuelle Bestätigung der M169-spezifischen externen/UX-Gates.

Die menschliche Sichtung eines nachgelagerten Security-Ergebnisses gehört zum Candidate-gebundenen `security_gate` beziehungsweise zur Pilotabnahme, nicht zu diesen vor Candidate zu schließenden `MG-`-/`EXT-`-Gates.


---

## 16. Requirement-Traceability

Jede normative Requirement-ID muss mindestens einem Blueprint zugeordnet sein. Mehrfachzuordnungen sind erlaubt, wenn ein Vertrag in Fundament und E2E erneut verifiziert wird.

| Requirement | Primäre Tickets |
|---|---|
| `PROD-001` | `AI6-004`, `AI6-006B`, `AI6-038` |
| `PROD-002` | `AI6-002`, `AI6-036` |
| `TKT-001` | `AI6-007` |
| `TKT-002` | `AI6-007` |
| `TKT-003` | `AI6-007`, `AI6-037` |
| `TKT-004` | `AI6-007` |
| `TKT-005` | `AI6-009`, `AI6-029`, `AI6-037`, `AI6-039` |
| `TKT-006` | `AI6-009` |
| `TKT-007` | `AI6-010`, `AI6-020`, `AI6-022`, `AI6-029` |
| `TKT-008` | `AI6-007`, `AI6-008`, `AI6-030` |
| `TKT-009` | `AI6-007`, `AI6-009`, `AI6-012`, `AI6-013` |
| `TKT-010` | `AI6-007` |
| `TKT-011` | `AI6-007`, `AI6-010`, `AI6-037` |
| `TKT-012` | `AI6-020`, `AI6-029` |
| `GIT-001` | `AI6-006A`, `AI6-006B`, `AI6-006D`, `AI6-006E`, `AI6-006F`, `AI6-009`, `AI6-010`, `AI6-012`, `AI6-013`, `AI6-030` |
| `GIT-002` | `AI6-013`, `AI6-014` |
| `GIT-003` | `AI6-014`, `AI6-019`, `AI6-022` |
| `GIT-004` | `AI6-014`, `AI6-022`, `AI6-023` |
| `GIT-005` | `AI6-027`, `AI6-029` |
| `GIT-006` | `AI6-027`, `AI6-029` |
| `GIT-007` | `AI6-012`, `AI6-029` |
| `GIT-008` | `AI6-009`, `AI6-013`, `AI6-029`, `AI6-039` |
| `GIT-009` | `AI6-006C`, `AI6-006D`, `AI6-006E`, `AI6-006F`, `AI6-008`, `AI6-009` |
| `GIT-010` | `AI6-014`, `AI6-015`, `AI6-019`, `AI6-021`, `AI6-023`, `AI6-028`, `AI6-032`, `AI6-033`, `AI6-034`, `AI6-040`, `AI6-041`, `AI6-042`, `AI6-045` |
| `GIT-011` | `AI6-040` |
| `CFG-001` | `AI6-003`, `AI6-011` |
| `CFG-002` | `AI6-010`, `AI6-020` |
| `CFG-003` | `AI6-010`, `AI6-021` |
| `AGT-001` | `AI6-011`, `AI6-016`, `AI6-033`, `AI6-034`, `AI6-038`, `AI6-041`, `AI6-042` |
| `AGT-002` | `AI6-011`, `AI6-012`, `AI6-033`, `AI6-034`, `AI6-035`, `AI6-041`, `AI6-042`, `AI6-043` |
| `AGT-003` | `AI6-019`, `AI6-023`, `AI6-033`, `AI6-034`, `AI6-041`, `AI6-042` |
| `AGT-004` | `AI6-016`, `AI6-019`, `AI6-033`, `AI6-034`, `AI6-041`, `AI6-042` |
| `AGT-005` | `AI6-016`, `AI6-032`, `AI6-040` |
| `AGT-006` | `AI6-006A`, `AI6-015`, `AI6-045` |
| `AGT-007` | `AI6-015`, `AI6-021`, `AI6-033`, `AI6-034`, `AI6-035`, `AI6-041`, `AI6-042`, `AI6-045` |
| `AGT-008` | `AI6-011`, `AI6-012`, `AI6-016`, `AI6-019`, `AI6-044` |
| `AGT-009` | `AI6-011`, `AI6-012`, `AI6-015`, `AI6-016`, `AI6-019`, `AI6-020`, `AI6-023`, `AI6-028`, `AI6-032`, `AI6-033`, `AI6-034`, `AI6-041`, `AI6-042` |
| `AGT-010` | `AI6-033`, `AI6-035`, `AI6-041`, `AI6-042` |
| `AGT-011` | `AI6-044` |
| `RUN-001` | `AI6-013`, `AI6-017`, `AI6-039` |
| `RUN-002` | `AI6-012`, `AI6-013`, `AI6-039` |
| `RUN-003` | `AI6-017`, `AI6-019`, `AI6-021`, `AI6-022`, `AI6-025`, `AI6-027`, `AI6-045` |
| `RUN-004` | `AI6-006C`, `AI6-009`, `AI6-015`, `AI6-040`, `AI6-045` |
| `RUN-005` | `AI6-006C`, `AI6-013`, `AI6-017`, `AI6-029`, `AI6-030`, `AI6-032`, `AI6-039` |
| `RUN-006` | `AI6-011`, `AI6-012`, `AI6-015`, `AI6-019`, `AI6-020`, `AI6-026`, `AI6-032`, `AI6-033`, `AI6-034`, `AI6-041`, `AI6-042`, `AI6-043` |
| `RUN-007` | `AI6-013`, `AI6-020`, `AI6-022`, `AI6-027` |
| `RUN-008` | `AI6-012`, `AI6-030` |
| `RUN-009` | `AI6-022`, `AI6-027`, `AI6-029`, `AI6-032`, `AI6-038` |
| `RUN-010` | `AI6-038`, `AI6-039`, `AI6-040` |
| `REV-001` | `AI6-012`, `AI6-023`, `AI6-038` |
| `REV-002` | `AI6-023`, `AI6-024`, `AI6-040` |
| `REV-003` | `AI6-016`, `AI6-024`, `AI6-043` |
| `REV-004` | `AI6-024` |
| `REV-005` | `AI6-020`, `AI6-025`, `AI6-032` |
| `REV-006` | `AI6-024`, `AI6-025`, `AI6-043` |
| `REV-007` | `AI6-026` |
| `REV-008` | `AI6-027`, `AI6-028` |
| `REV-009` | `AI6-024` |
| `REV-010` | `AI6-043` |
| `REV-011` | `AI6-011`, `AI6-012`, `AI6-040`, `AI6-043` |
| `HUM-001` | `AI6-016`, `AI6-018`, `AI6-028`, `AI6-043` |
| `HUM-002` | `AI6-018`, `AI6-028`, `AI6-038` |
| `HUM-003` | `AI6-005A`, `AI6-018` |
| `HUM-004` | `AI6-018`, `AI6-020`, `AI6-032` |
| `HUM-005` | `AI6-020`, `AI6-026` |
| `UI-001` | `AI6-008`, `AI6-018`, `AI6-031`, `AI6-044` |
| `UI-002` | `AI6-008` |
| `UI-003` | `AI6-012` |
| `UI-004` | `AI6-017`, `AI6-019`, `AI6-024`, `AI6-031`, `AI6-040` |
| `UI-005` | `AI6-018`, `AI6-026` |
| `UI-006` | `AI6-030` |
| `UI-007` | `AI6-044` |
| `SEC-001` | `AI6-003` |
| `SEC-002` | `AI6-004`, `AI6-005A`, `AI6-006C` |
| `SEC-003` | `AI6-005A` |
| `SEC-004` | `AI6-003`, `AI6-004`, `AI6-005B`, `AI6-010`, `AI6-044` |
| `SEC-005` | `AI6-015`, `AI6-021`, `AI6-033`, `AI6-034`, `AI6-035`, `AI6-041`, `AI6-042`, `AI6-045` |
| `SEC-006` | `AI6-006A` |
| `SEC-007` | `AI6-003`, `AI6-005B`, `AI6-006F`, `AI6-021`, `AI6-031`, `AI6-044`, `AI6-045` |
| `SEC-008` | `AI6-028`, `AI6-032` |
| `SEC-009` | `AI6-027`, `AI6-028` |
| `SEC-010` | `AI6-036` |
| `SEC-011` | `AI6-031`, `AI6-036`, `AI6-040` |
| `OPS-001` | `AI6-002`, `AI6-036` |
| `OPS-002` | `AI6-002` |
| `OPS-003` | `AI6-003`, `AI6-035`, `AI6-036`, `AI6-045` |
| `OPS-004` | `AI6-001`, `AI6-002`, `AI6-015` |
| `OPS-005` | `AI6-037`, `AI6-038` |
| `OPS-006` | `AI6-001`, `AI6-036` |
| `OPS-007` | `AI6-001` |
| `OPS-008` | `AI6-001`, `AI6-002` |


---

## 17. Prompt- und Ticketgenerator-Vertrag

### 17.1 Generatorprompt

Der ausführbare Prompt liegt in Abschnitt 12 von `docs/AI6_TICKET_TEMPLATE_V1.md` und wird ausschließlich dort gepflegt. Er wird hier bewusst nicht dupliziert: zwei Fassungen driften auseinander und machen die Erzeugung nicht mehr deterministisch.

Unabhängig vom Promptwortlaut bleiben folgende Regeln normativ:

- Genau ein Blueprint erzeugt genau ein Detailticket; ein Zusammenlegen ist unzulässig.
- Pflichtlektüre sind dieser Plan mit den Requirement-IDs des Blueprints, alle `depends_on`-Tickets samt ihrer real entstandenen öffentlichen Verträge sowie `AGENTS.md` und die vorhandene Repositorystruktur; historische Diffs nur bei konkretem Bedarf.
- `id`, `title`, `## Goal`, `milestone`, `risk`, `kind` und `depends_on` werden unverändert aus dem Blueprint übernommen.
- Konkrete Dateien, Klassen, Commands und Tests werden aus dem realen Repository abgeleitet; eine noch nicht vorhandene API wird nicht angenommen.
- Ein Ticket besitzt genau ein primäres Outcome und bleibt einzeln implementierbar und reviewbar.
- Das Schema ist `ai6.ticket.v1`, für alle Blueprints dieses Plans gilt zwingend das serverseitige Validierungsprofil `ai6_detail_v1` mit sämtlichen Feldern und Abschnitten aus §13.4; `status` ist bei Erzeugung `todo`.
- AC- und TC-IDs sind eindeutig; jedes AC ist mindestens einem TC, einem manuellen Gate oder einer externen Evidenz zugeordnet.
- `files` ist der erwartete Ausgangsscope; sensitive Erweiterungen werden gekennzeichnet.
- Jeder `spec_refs`-Eintrag besitzt ausschließlich die kanonische Form `docs/AI6_IMPLEMENTATION_PLAN.md — <REQ-ID>`. Genaue §-Verweise stehen bei Bedarf im deutschen Fließtext von `## Context` oder `## Notes`, niemals als konkurrierende `spec_refs`-Syntax.
- `## Out of Scope` sowie `## Manual and External Gates` werden ausdrücklich gefüllt.
- Jeder deklarierte `MG-`-/`EXT-`-Eintrag ist blockierend und muss in der AC-Coverage erscheinen; gibt es keinen, steht ausschließlich `None.`.
- Das Ticket ist ohne Erzeugungs-Chat und ohne historische Modellkonversation vollständig verständlich und reviewbar.
- Der Implementierungsagent ändert weder Ticketstatus noch AI6-Control-Metadaten.
- Ist der Blueprint nach Sichtung des Repositorys zu groß, widersprüchlich, nicht umsetzungsbereit oder durch eine fehlende Voraussetzung blockiert, entsteht kein Ticket. Die Ausgabe besteht dann ausschließlich aus einem konkreten Antrag der Art `split`, `entscheidung` oder `blockiert`; Ticket-Frontmatter und Teilticket sind in diesem Zweig verboten.
- Andernfalls besteht die Ausgabe ausschließlich aus dem vollständigen Inhalt der exakt geschriebenen regulären Datei `tickets/<TICKET-ID>.md`; Groß-/Kleinschreibung und einfache `.md`-Endung entsprechen `TKT-010`. Ticket- und Antragszweig sind gegenseitig ausschließend.

### 17.2 Implementierungs- und Reviewprompts

Die bisherigen statischen Browserprompt-Templates werden nicht als zweite Quelle fortgeführt. Fachlich freigegebene Inhalte dürfen als versionierte Einträge in den zentralen Katalog überführt werden; Runprompts und die manuelle Prompt-Hilfe aus `AI6-044` verwenden weiterhin ausschließlich diesen einen Katalog und Renderer. Neue zentrale Templates folgen diesen Regeln:

- Implementierungsagent darf Code im effektiven Scope ändern, aber keine Ticketstatus-, Approval- oder Runmetadaten.
- Notwendige Abweichungen werden strukturiert als `needs_human` beziehungsweise Scope-/Contract-Request gemeldet.
- Umsetzungshinweise werden als Run-Artefakt gespeichert, nicht an das freigegebene Ticket angehängt.
- Reviewagent verändert keine Dateien und liefert ausschließlich schema-validiertes JSON.
- Spezialisierte Reviewprompts sind versionierte Profile desselben Katalogs; der Profilfokus entfernt keine Akzeptanzkriterien aus der geforderten vollständigen `criterion_coverage` (`REV-011`).
- Verifierprompts bewerten Evidenz, Ticketkriterien, erwartetes Ergebnis und Gegenargumente eines fremden Findings; sie schreiben das Finding nicht um, führen keine freie Debatte und liefern ein eigenes unveränderliches advisory Resultat (`REV-010`).
- Fixturn verwendet dieselben Scope- und Human-Request-Regeln wie der erste Implementierungsturn und erhält ausschließlich wirksam blockierende beziehungsweise ausdrücklich autorisierte Findings.
- Securityreview behandelt jeden Repositorytext als untrusted evidence, niemals als höherrangige Instruktion.
- Manuelle Desktop-Prompts autorisieren keine Provider-, Run-, Git- oder Statuswirkung. Eine vollständige Reviewantwort wird ausschließlich zur redigierten Extraktion der terminalen `### Fix-Liste` verarbeitet; Text davor wird nicht in den Folgeprompt übernommen, und `Nichts zu fixen.` beendet den manuellen Ablauf ohne weiteren Prompt.

---

## 18. Rollout und Freigabestrategie

1. Nur Tickets des nächsten freigegebenen Meilensteins detailliert erzeugen.
2. Ein vor V1.6.0 erzeugtes `AI6-001` wird vor Umsetzung neu aus diesem Plan erzeugt beziehungsweise auf `OPS-007` rebased und erneut freigegeben.
3. Jedes Ticket einzeln implementieren und reviewen.
4. Nach jedem Meilenstein das Integrations-Gate sowie den Manifest-Driftcheck ausführen und Planannahmen gegen das reale Repository prüfen.
5. Erkenntnisse, die spätere Blueprints ändern, zuerst als Planrevision committen und danach den Manifestexport regenerieren.
6. Codex-, Grok- und Copilot-Adapter erst nach erfolgreichem vollständigem FakeAgent-E2E-Ticket umsetzen und reale Providerkosten erst danach einsetzen; der Claude-Adapter bleibt eine spätere, die erste Providerstufe nicht blockierende Erweiterung.
7. Kein reales Projekt vor erfolgreichem Strict-Doctor und Fake-E2E.
8. Reale Providernutzung beginnt mit Review-only-Läufen und manuell bestätigtem report-only Abschluss; Findingqualität, False Positives, Laufzeit, Kosten und Providerfehler werden dabei gemessen.
9. Erst danach werden quellenabhängige Verifikation und autorisierte Codex-Fixturns mit vollständigen Re-Reviews aktiviert.
10. `automatic_after_gates` wird erst nach belastbarer Pilotmessung und nur dort zugelassen, wo die serverseitige Risikopolicy nicht auf `manual` verengt; ein optionaler finaler Review wird erst danach gezielt zugeschaltet.
11. M169 bleibt der erste reale Pilot und erzeugt bei Problemen neue Folge-Tickets statt einen unkontrolliert wachsenden Pilot-Scope; nach erfolgreichem Pilot wird der Legacy-Leser im selben Release abgeschaltet und V1 erneut validiert.

---

## 19. Offene Produktentscheidungen und festgelegte Defaults

Die folgenden Punkte sind nicht blockierend; der Plan setzt diese Defaults:

| Punkt | Default |
|---|---|
| Control-Branch | `main`; ausschließlich in vertrauenswürdigen `projects`-Metadaten durch autorisierten Admin mit Step-up und ohne aktiven Run änderbar |
| Grober Runstatus in Git | `ready → in_progress → review`; Detailzustand nur in SQLite |
| Abhängigkeit erfüllt | standardmäßig nur `done` |
| Ready/Queue | Approval setzt `todo → ready`; nicht erfüllte Abhängigkeiten erlauben Queueing, blockieren aber Claim/Start |
| Auto-Start | für gültig freigegebene Queue-Tickets aktiv; projektweise abschaltbar und vor jedem Claim vollständig neu bewertet |
| Pushmodus | `manual`; `automatic_after_gates` optional; Risikoregeln verengen nur auf `manual` |
| Review-only-Abschlussmodus | `manual` (`wait_reason=manual_report`); `automatic_after_gates` sinngemäß optional |
| Review-only-Eingaben | ausschließlich gebundene Quellen nach `GIT-011`; PR-URL-Unterstützung ausdrücklich erst später über gebundene Commit-Ranges |
| Ticketvalidierungsprofil | `generic_v1` für fremde Projekte; dieses AI6-Repository verwendet explizit `ai6_detail_v1` |
| Reviewerstrategie | alle ausgewählten Reviewer bei jedem neuen Checkpoint |
| Reviewerparallelität | seriell |
| Aktive Runs | einer je Projekt |
| Datenbank | SQLite WAL |
| Queue | Laravel Database Queue |
| Frontend | Blade + Livewire + Alpine |
| SecurityProfile | strict |
| Erste Providerstufe | `codex_cli` (implementation, fix, eingeschränkt quality_review), `grok_cli` (quality_review, finding_verification), `github_copilot_cli` (quality_review), `fake`; Claude folgt später über `AI6-034`, ohne V1 zu blockieren |
| Implementierung | Profil `codex-gpt-5.6-terra`, Aufwand `medium` |
| Review | zwei unabhängige Slots der ersten Providerstufe, etwa `grok-cli-review` und `copilot-cli-review`; ein Claude-Profil wie `claude-opus-5` bleibt nach Umsetzung von `AI6-034` zulässig |
| Verifier | advisory only, quellenabhängig: Grok für Copilot-/zulässige Codex-Findings; Grok-Findings an einen unabhängigen Copilot-/Codex-Slot oder HumanLoop; ohne unabhängiges Profil Human Request |
| Finaler Review | deaktiviert bis nach dem Pilot; danach optionaler zusätzlicher Reviewer-Slot bei Risiko-, Release- oder Human-Triggern, kein fest verdrahteter Modellname |
| Semantische Finding-Deduplizierung | nicht im MVP; ausschließlich exakte deterministische Duplikatgruppen |
| Token-/Kostenwerte | nur aus belastbarer CLI-/Providerquelle; fehlende Werte explizit `unknown`, nie geschätzt |
| Login-E-Mail-Bestätigung | aktiv |
| LLM-Securityreview | im strict-Profil aktiv |
| Direkte Provider-APIs | nicht im MVP |
| Pull Request / Merge | manuell außerhalb des MVP |

Eine Abweichung wird über Config vorgenommen, sofern der entsprechende Vertrag dies erlaubt; andernfalls benötigt sie eine neue Planrevision.

Explizit offen bleiben bis zur Detailableitung von `AI6-035` beziehungsweise bis zum Pilot: die konkret gepinnten CLI-Versionen, Modelle und Effortwerte je Providerprofil samt Re-Doctor-Zyklus; die exakten gepinnten Headless-Flagformen und finalen Ergebnisextraktoren je CLI-Version; ob eine gepinnte Copilot-Version einen nativen maschinenlesbaren Ausgabemodus nachweist oder die textbasierte finale Antwort der V1-Vertrag bleibt; die Kriterien, unter denen ACP (Grok) oder ein SDK-/Servermodus (Copilot) später als eigener Transport geprüft würde; die konkrete Authentifizierungsform und der minimale Credential-Store je CLI im `agent`-Prozess; welche Provider belastbare Token-/Kostenwerte liefern; Kostenbudgets je Ticket oberhalb der `RUN-006`-Limits; sowie die Qualitätsmetriken-Auswertung (Bestätigungsraten, False Positives, Final-Review-Mehrwert), die nach dem Pilot separat geschnitten wird.

---

## 20. Abschlusskriterien des MVP

Der MVP ist erreicht, wenn:

- ein anderer Entwickler AI6 auf einem frischen Linux-Server anhand der Dokumentation installiert;
- Installation und CI das verifizierte immutable Laravel-Scaffold, den committed Lockfile sowie einen driftfreien Ticketmanifestexport nachweisen;
- Git-native Tickets korrekt gelesen, validiert, angezeigt, bearbeitet und freigegeben werden;
- Browser/App sämtliche Managed-Git-Zugriffe über asynchrone Control Operations und blobgebundene rekonstruierbare Read Models durchführen;
- mehrere Reviewmodelle mit jeweils eigenem Aufwand auswählbar sind;
- FakeAgent alle Erfolgs-, Frage-, Scope-, Contract-Amendment-, Fix-, Ressourcenlimit-, Wartestatus-, Gate-, Security- und Recoverypfade reproduzierbar abdeckt;
- ein ticket- und approvalgebundener Review-only-Lauf einen gebundenen Stand ohne Push prüft und über die report-only Abschluss-Saga in einem gebundenen Abschlussbericht endet;
- Codex, Grok und Copilot über denselben Adaptervertrag mit je genau einem doctor-nachgewiesenen Headless-Transport laufen; Claude bleibt optional über `AI6-034`;
- Findings quellenabhängig advisory verifiziert werden können, ohne dass ein Verifier ein blockierendes Finding aufhebt;
- ein Agenten-Human-Request eine E-Mail erzeugt und mobil im Panel beantwortet wird;
- alle Reviewer denselben Checkpoint prüfen und Codeänderungen eine vollständige Re-Review-Runde erzwingen;
- Finalchecks, Candidate, optionaler Securityreview, Commit und Push tree- und provenancegebunden sind;
- kein effektiv blockierendes Finding und kein offenes oder stale `MG-`-/`EXT-`-Gate Candidate, Commit oder Push passieren kann;
- abschaltbare Securitymaßnahmen standardmäßig aktiv und ausschließlich über vertrauenswürdige Env/Config sichtbar reduzierbar sind, während nicht abschaltbare Invarianten ohne Disable-Flag erzwungen bleiben;
- abgelaufene Rohlogs, Provideroutputs und Artefakte tatsächlich gelöscht, nur redigierte Tombstones erhalten und gelöschte Daten nicht mehr ausgegeben, heruntergeladen oder durch Restore reaktiviert werden;
- M169 auf einem Testbranch ohne manuelle Datenbankmanipulation durch den vollständigen Workflow läuft;
- nach erfolgreichem Push der Git-Status auf dem Control-Branch `review` ist und kein bereits veröffentlichter Run erneut gestartet werden kann;
- offene manuelle/externe Gates niemals als bestanden simuliert werden.
- nach erfolgreichem Pilot der Legacy-Leser abgeschaltet und der migrierte Bestand erneut unter den gewählten V1-Profilen validiert ist.

---

## 21. Kurzbegründung der Ticketanzahl

51 Tickets sind für den Funktionsumfang bewusst kleiner als die bisherigen zehn Pakete, aber keine künstlichen Mikrotickets. Jeder Blueprint bildet eine reviewbare Grenze: Datenvertrag, vertikaler Benutzerfluss oder sicherheitsrelevante technische Naht. Die fünf mit V1.7.0 ergänzten Blueprints folgen demselben Schnitt: zwei für den Review-only-Modus (Statusvertrag getrennt von Quellbindung und Bedienung), zwei für die neuen Provideradapter (je CLI ein eigenständig testbarer Adapter) und einer für die providerunabhängige Verifier-Orchestrierung. `AI6-044` ergänzt als eigener manueller Benutzerfluss ausschließlich die Clipboard-Bedienung des zentralen Promptkatalogs und bleibt von Provider- und Runwirkung getrennt. `AI6-045` folgt demselben Schnitt als sicherheitsrelevante technische Naht: Die Definition eines Checks und sein rollenrichtiger Vollzug sind getrennt reviewbar, weil der Vollzug eigene Container-, Mount- und Wartezustandsverträge berührt, die die Profildefinition nicht kennt. Ein Ticket darf während der Detailerzeugung weiter gesplittet werden, aber nur über eine explizite Planrevision; ein stilles Zusammenlegen mehrerer Blueprints ist nicht zulässig.
