# ANTRAG — AI6-048

**Art:** entscheidung
**Ausgelöst durch:** Menschlicher Auftrag vom 10. September 2026, ein neues Ticket zur Behebung der fehlenden Copilot-Voraussetzung für AI6-034 anzulegen.

Planungsentscheidung vom 11. September 2026: Der Mensch hat die vorgeschlagenen Planänderungen einschließlich Manifest, Abhängigkeitskorrektur von AI6-034 und Erzeugung des Detailtickets AI6-048 ausdrücklich freigegeben. Umsetzung der Entscheidung: Planrevision V1.7.7. Diese Freigabe betrifft die Planung; sie ist keine Capability-Freigabe, Schreibausnahme oder reale Abnahme. Der folgende Antragstext dokumentiert den Entscheidungsstand vor dieser Freigabe.

## Befund

Geprüfte Basis: `fbb09b631a46caac3940b18800f1770d99541153`. Die vorhandene uncommittete Überarbeitung von `tickets/AI6-034.md` bleibt erhalten. Ihr Rebase wurde durchgeführt; sein Ergebnis ist ein weiterhin offenes Gate wegen der fehlenden Copilot-Implementierung.

Alle sieben Abhängigkeiten von AI6-034 tragen `status: done`. Dennoch liefert AI6-042 bislang nur vorbereitete Konfiguration und deren Weitergabe. `app/AI6/Shared/AI6ServiceProvider.php` bindet `AgentAdapter` ausschließlich an `FakeAgentAdapter` und `CodexCliAdapter`; andere Aliase führen zu `agent_adapter_unavailable`. In `app/` und `tests/` existieren kein Copilot-Adapter, kein Copilot-Doctor, kein deterministisches Copilot-Binary und kein Copilot-Smoke. `README.md` dokumentiert diesen Stand ausdrücklich.

Die gemeinsamen technischen Grenzen sind vorhanden und wurden im Code geprüft:

- `app/AI6/Agents/AgentAdapter.php`: `result()` und `turn()` sind die bestehenden Adaptermethoden.
- `app/AI6/Agents/AgentTurnResult.php`: Antwortbytes und gemeldete Nutzungswerte samt Quelle bilden das Turnergebnis.
- `app/AI6/Agents/AgentExecutionRunner.php`: `prepare()` und `dispatchOrCollect()` binden und übergeben den Turn; `destroy()` gehört zum bestehenden Bereinigungsweg.
- `app/AI6/Agents/AgentExecutionProcessor.php`: `processNext()` prüft die Auftragsbindung, löst den Adapter auf und ordnet ungültige Antworten beziehungsweise Providerfehler dem Mailboxergebnis zu.
- `app/AI6/Agents/ExecutionHomeManager.php`: `create()` prüft Runtime-, Snapshot- und Credentialbindungen und trennt Eingabe- und Ausgabebaum.
- `app/AI6/Shared/Doctor/DoctorCheck.php` und `app/AI6/Shared/Doctor/CodexCliDoctorCheck.php`: bestehende Doctorgrenze und konkretes Integrationsmuster.

`docs/AI6-042_TRANSPORT_ENTSCHEIDUNGSANFRAGE.md` dokumentiert außerdem einen offenen technischen Nachweis: Ein echter Linux-Reviewturn mit vollständig schreibgeschütztem `COPILOT_HOME` einschließlich Sessionablage wurde nicht belegt. Die dort untersuchte Version 1.0.83 ist dadurch nicht freigegeben. Die Windows-Pfadprobe beweist weder Erfolg noch eine notwendige Schreibausnahme. Der Entwurf einer beschreibbaren Sessionprojektion ist ausdrücklich noch nicht zur Planübernahme vorgeschlagen.

## Konflikt mit dem Blueprint

Plan §15 endet bei der höchsten vergebenen ID AI6-047. AI6-048 ist nach Prüfung des aktuellen Plans und der erreichbaren Git-Historie noch nicht vergeben. Ein neuer Blueprint ist erforderlich: Plan §13.3 verlangt die Ableitung von ID, Ziel, Meilenstein und Abhängigkeiten aus §15; Template §9/C04 verlangt Blueprinttreue. Die Aufforderung, ein neues Ticket anzulegen, legt dessen Outcome fest, erlaubt nach `AGENTS.md` §10 aber keine stillschweigende Änderung der Planquelle.

Der veröffentlichte Vertrag von AI6-042 darf nicht rückwirkend auf bloße Konfigurationsvorbereitung umgedeutet werden. Sein Status und seine AC-/TC-/MG-IDs bleiben erhalten. Der neue Auftrag soll die fehlende Lieferung ausdrücklich nachholen; er ist kein zweiter Adaptervertrag.

Zusätzlich bezeichnet der Plan AI6-034 weiterhin als Claude-CLI-Adapter mit Claude-spezifischer Discovery. Die aktuelle Ticketfassung verlangt ausschließlich Claude-Modelle über Copilot. Auch diese Abweichung braucht eine ausdrückliche Planentscheidung, bevor die Copilot-Lieferung AI6-034 tatsächlich entsperren kann.

## Vorschlag

Entscheidungsfrage: Soll der Plan um den nachfolgenden Korrekturauftrag AI6-048 ergänzt und AI6-034 normativ auf Claude-Modelle über diesen einen Copilot-Transport ausgerichtet werden?

Empfohlen ist die Ergänzung eines eigenen Korrekturauftrags. Sie folgt dem Wunsch nach einem neuen Ticket, bewahrt den veröffentlichten AI6-042-Vertrag und macht die ausstehende Lieferung separat prüfbar. Die Alternative wäre ein ausdrücklich beauftragter weiterer Implementierungsdurchlauf auf AI6-042; dafür entstünde keine neue Ticket-ID und die fachliche Planabweichung von AI6-034 bliebe trotzdem zu klären.

Zur Freigabe vorgeschlagene Eckdaten des neuen Blueprints:

| Eigenschaft | Vorgeschlagener Wert |
|---|---|
| ID | AI6-048 |
| Titel | Fehlenden GitHub-Copilot-CLI-Transport nachliefern |
| Art | fix |
| Meilenstein | M6 |
| Risiko | high |
| Abhängigkeiten | AI6-011, AI6-015, AI6-016, AI6-032, AI6-046, AI6-047, AI6-042 |
| Module | Agents, Shared |
| Requirement-Refs | AGT-001, AGT-002, AGT-003, AGT-004, AGT-007, AGT-009, AGT-010, GIT-010, RUN-006, SEC-005 |
| Ziel | Die fehlende ausführbare GitHub-Copilot-CLI-Anbindung auf der vorhandenen Agentennaht nachliefern, damit freigegebene Reviewprofile über genau diesen Transport ausgeführt werden können. |

Das beobachtbare Ergebnis ist ein real ausführbarer, servergebundener Copilot-Reviewturn mit zentral validierter Antwort. Eine Registrierung, die jeden Copilot-Turn weiterhin ablehnt, erfüllt das Outcome nicht. Claude-Modellprofile werden weiterhin erst in AI6-034 ergänzt.

Der vorgeschlagene Lieferumfang und seine Abnahmebedingungen sind:

1. Den Copilot-Adapter neu anlegen und in der bestehenden Aliasauflösung binden. Unbekanntes Alias, fehlendes Home und `result()` starten keinen Prozess; Fake und Codex bleiben unverändert nutzbar. Den Erfolgsfall über die ausgelieferte Containerbindung und Agentmailbox prüfen, ohne den fehlenden Produktionsadapter im Test durch eine Ersatzbindung zu verdecken.
2. Die bereits vorbereiteten Binary-, Pin-, Credential- und Runtimewerte konsumieren. Modell, Effort, Rollen, Argumente und Environment folgen ausschließlich den servergebundenen Werten. Fehlender Pin, Versionsdrift, ungültige Auswahl oder fehlender Capability-Nachweis sperren nur das betroffene Profil. Keine konkrete Version oder Modellkennung ohne Nachweis freigeben.
3. Das vollständig versiegelte Copilot-Home, die minimale read-only Authprojektion und den gebundenen Instruktionssnapshot verwenden. Fremde Homes, andere Sessions, Gitmetadaten und nicht freigegebene Discovery bleiben unerreichbar. Keine native Claude-Discovery oder Claude-CLI einführen. Runtime-Konfiguration entsteht ausschließlich vor der Versiegelung an der zentralen Homegrenze.
4. Nur `quality_review` ausliefern. `finding_verification` setzt einen ausdrücklichen serverseitigen Rolleneintrag und Capability-Nachweis voraus. Implementierung, Fix und Security-Review werden vor Prozessstart abgewiesen. Shell, Schreiben, Delegation, Memory, URL-Zugriff und MCP einschließlich GitHub-MCP bleiben gesperrt; Toolpolicy und OS-Grenzen sind getrennt zu prüfen.
5. Genau einen für den Pin nachgewiesenen Antwortmodus konsumieren. Eindeutige Antwortbytes gehen durch die zentralen UTF-8- und Ergebnisverträge; fehlende, mehrdeutige oder ungültige Antworten führen beim Verbraucher zu `invalid_json`. Timeout, Abbruch, Exitfehler und Ausgabelimit führen zu `provider_error`. Gemeldete Nutzungswerte mit Quelle, gemeldete Null und fehlende Werte bleiben unterscheidbar.
6. AI6-Sessions je Run und Slot getrennt halten. Ohne nachgewiesene zustandsdateifreie Resume-Möglichkeit ist jeder Turn eine ausgewiesene neue Invocation; keine native Sessionablage zwischen Turns übernehmen. Promptmaximum und Eins-darüber-Fall vor Prozessstart und Teiltransfer prüfen. Credentialdrift, Rotation und Logout verhindern den Start beziehungsweise Resume.
7. Timeout, Cancel und alle Fehlerwege über die vorhandene Prozessgruppen- und Homebereinigung abwickeln. Keine Prozesse bleiben zurück, keine späten Ergebnisse werden übernommen und keine Cache-, History- oder Sessionbytes in einen Credential-Store zurückgeschrieben.
8. Einen Copilot-Doctor, deterministische Copilot-Testfixtures und einen echten Smoke hinter `AI6_RUN_COPILOT_SMOKE=1` ergänzen. Ohne Flag wird der Smoke übersprungen; mit Flag und fehlenden Voraussetzungen schlägt er fehl. Der Fake belegt Verkabelung und OS-Grenzen; die interne Tool- und Discoverywirkung der echten CLI braucht reale Evidenz. README und ein neues ergebnisfreies, commitgebundenes Abnahmeprotokoll halten diese Trennung fest.

Die Tests gehören in denselben Auftrag wie der Adapter. Die vorhandenen Einstiegspunkte `tests/Unit/Agents/ExecutionHomeManagerTest.php`, `tests/Unit/Agents/AgentExecutionDocumentTest.php`, `tests/Feature/Agents/AgentExecutionMailboxTest.php` und `tests/Feature/Agents/AgentExecutionBoundaryTest.php` sichern die konsumierten Grenzen ab. Die Codex-Adapter-, Ausführungs-, Smoke- und Doctortests sind vorhandene Muster, keine umzuwidmenden Copilot-Implementierungen. Neue Copilot-Tests müssen die aufgezählten Erfolgs-, Ablehnungs- und Fehlerfälle bis zum jeweiligen Endzustand prüfen.

Als Ausgangsscope sind die vorhandenen Verzeichnisse `app/AI6/Agents/`, `app/AI6/Shared/Doctor/`, `tests/Unit/Agents/`, `tests/Feature/Agents/`, `tests/Fixtures/Agents/` und `tests/Feature/Shared/Doctor/` sowie `app/AI6/Shared/AI6ServiceProvider.php`, `config/ai6.php` und `README.md` vorgesehen. Das Abnahmeprotokoll wird ein ausdrücklich neues Dokument. Weitere konkrete Testbindungen und eventuell nötige Konfigurationsweitergabe werden bei der Detailableitung geprüft. Eine Auth- oder Deployänderung benötigt ihren eigenen ausdrücklich beschriebenen Scope; aus der Blueprintfreigabe entsteht keine pauschale Freigabe dafür.

Vor einer realen Capability-Freigabe muss der Linux-Nachweis aus der vorhandenen Entscheidungsanfrage durchgeführt werden: gebundenes Linux-Binary, erreichbarer Modellendpunkt, eigens bereitgestellte Testauthprojektion, tatsächlich verweigerte Schreibversuche im Home und ein echter Reviewturn. Erfolg, ursächlich belegter Schreibschutzfehler und unklarer Ausgang werden getrennt dokumentiert. Ohne diesen Nachweis bleiben CLI-Version und Profil gesperrt; das reale Outcome ist nicht erreicht. Eine fehlende Testumgebung wird als offenes externes Gate festgehalten, nicht durch einen Fake ersetzt.

Falls eine Copilot-Version den unveränderten Homevertrag nachweislich nicht erfüllen kann, ist vor einer abweichenden Laufzeitimplementierung erneut eine Entscheidung erforderlich. Die beschreibbare Sessionprojektion wird mit diesem Antrag weder übernommen noch freigegeben. Eine notwendige Erweiterung der gemeinsamen Isolation ist nach Plan §13.2 und §13.7 separat zu prüfen. Ebenso bleiben Persistenz, Onboarding, ein zweiter ProcessRunner, zusätzliche Transportwege und GitHub-Mutationen außerhalb des Auftrags.

## Auswirkung auf den Plan

Die angefragte Freigabe umfasst folgende konkrete Planungsänderungen:

1. Den oben beschriebenen Blueprint AI6-048 in Plan §15/M6 ergänzen, seine Nachlieferungsbeziehung zu AI6-042 dokumentieren und Reihenfolge, Anzahl sowie Requirement-Traceability konsistent nachziehen. AI6-042 wird weder umnummeriert noch fachlich rückwirkend verkleinert.
2. AI6-034 unter derselben unveränderlichen ID auf den Titel „Claude-Modelle über GitHub-Copilot-CLI“ und den ausschließlich gemeinsamen Copilot-Transport ausrichten. Der bisherige Zieltext bleibt erhalten. Claude-CLI-Start und Claude-spezifische native Discovery entfallen; Rollen, Home-, Session-, Limit-, Tool- und Ergebnisgrenzen werden vom Copilot-Vertrag konsumiert. AI6-042 und AI6-048 werden zu den bestehenden Abhängigkeiten ergänzt; AGT-010 wird in die Requirement-Refs aufgenommen. AGT-001 und die betreffenden Übersichten werden konsistent angepasst.
3. Nach der Planrevision genau das neue Detailticket AI6-048 mit initialem `status: todo` ableiten und `tickets/README.md` ergänzen. In `tickets/AI6-034.md` ausschließlich die neue Abhängigkeit und den dazugehörigen Rebase-Hinweis nachziehen, die vorhandene Nutzerüberarbeitung bewahren und bestehende AC-/TC-/MG-IDs sowie sämtliche bestehenden Statuswerte unverändert lassen. Das Rebase-Gate bleibt bis zur tatsächlichen Lieferung und Prüfung offen.
4. `docs/AI6_TICKET_MANIFEST.yaml` ausschließlich durch `scripts/generate-ticket-manifest.php` aktualisieren und den Export auf Drift prüfen. Keine handgeschriebene zweite Blueprintquelle anlegen.

Diese Datei ist der Entscheidungsantrag nach Template §10. Sie vergibt die vorgeschlagene ID noch nicht normativ, enthält kein Detailticket und behauptet keine Umsetzung oder Abnahme. Die Planquelle, das Manifest, bestehende Tickets und ihre Statuswerte wurden durch die Erstellung dieses Antrags nicht verändert. Die gesonderte menschliche Copilot-Abnahme und die spätere Claude-Abnahme bleiben offen.
