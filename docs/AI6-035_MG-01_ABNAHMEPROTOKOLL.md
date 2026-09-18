# AI6-035 / MG-01 — Fresh-install-Provider-Onboarding

Ergebnisfreie Vorlage. Dieses Dokument enthält keine Abnahme und keine Signatur. Automatisierte Doubles, ein erfolgreicher Imagebuild und synthetische Namespaceprüfungen ersetzen dieses Gate nicht. Grok verwendet das ausdrücklich freigegebene API-Key-Onboarding; der bekannte Sandboxblocker ist vor einer erfolgreichen Abnahme zu beheben. Schutzgrenzen dürfen hierfür nicht gelockert werden.

## Bindung

| Gegenstand | Vom prüfenden Menschen auszufüllen |
|---|---|
| Exakter Implementierungscommit (vollständiger SHA) | |
| Image-ID und Buildnachweis | |
| Linux-Kernel, Architektur, Docker-/Compose-Version | |
| Effektive Runtimekonfiguration, SecurityPolicy-Hash (`strict`) | |
| Codex: Pin, tatsächliche Version, Binary-SHA-256, Runtimehash, menschlicher Sandboxnachweis | |
| Grok: Pin, tatsächliche Version, Binary-SHA-256, Runtimehash, menschlicher Capabilitynachweis | |
| Copilot: Pin, tatsächliche Version, Binary-SHA-256, Runtimehash, menschlicher Capabilitynachweis | |
| Datum, prüfende Person, Referenz auf ausdrücklich freigegebene Testzugänge (keine Secrets) | |

## Durchführung und Evidenz

Eine frische, getrennte Linux-Compose-Testinstallation verwendet dieselben Rollen-, Mount-, UID-, Namespace- und Seccompgrenzen wie die ausgelieferte Runtime. Zugangsdaten, Gerätecodes und vollständige Authdateien werden weder hier noch in Logs oder Git abgelegt. Der Init provisioniert ausschließlich Verzeichnisse; die Agent-Mailboxschleife läuft während der Prüfung dauerhaft.

1. Image aus den festen, SHA-256-geprüften Herstellerdownloads bauen. Tatsächliche CLI-Versionen gegen Codex `0.129.0-alpha.15`, Grok `1.0.5` und Copilot `1.0.83` prüfen. Fehlender oder veränderter Download muss den Build verhindern. Automatische Updates bleiben aus.
2. Je V1-Alias interaktiv `docker compose exec agent php artisan ai6:provider login <Alias>` ausführen. Nur ausdrücklich bereitgestellte Testzugänge verwenden. Erfolgreiche Anmeldung, fehlgeschlagene Anmeldung und Cancel prüfen. Der bisherige Store und seine Generation müssen bei Fehler oder Cancel unverändert bleiben. Claude verwendet `github_copilot_cli` und keinen vierten Store. Für Grok den API-Key verdeckt eingeben und die Übernahme ausschließlich nach frischem gebundenem Remote-Cache prüfen. Eingebauter Katalog, fremde Origin und fehlende Modellberechtigung müssen die Übernahme verhindern.
3. Minimalität und Zugriff prüfen: Im Store nur passende Authdatei und Generation, im Providerprozess nur seine read-only Projektion. Cache-/History-/Config-/Pluginköder, fremde Stores, fremde Projektionen, Loginzwischenstände, Supervisor und Berichte müssen unerreichbar bleiben. Leseversuche aus App, Worker, Scheduler und Checker müssen ebenfalls scheitern. Die gleiche Lecksonde muss an einer ausdrücklich geöffneten, isolierten Testgrenze anschlagen. Fehlende Namespaces müssen den Start verweigern.
4. `docker compose exec worker php artisan ai6:doctor` und die berechtigte Profilseite prüfen: Diagnose, Grund und CLI-Version; keine Credentials oder Generation. Dabei darf außerhalb der Agentrolle kein nativer Probeprozess entstehen. Je einzelne Modell-/Rollen-/Effortkombination muss nur mit aktueller nativer und menschlicher Evidenz auswählbar sein. Fehlende Claude-Berechtigung darf das andere Copilot-Modell nicht sperren.
5. Unter laufendem Turn erneut anmelden und anschließend Logout prüfen: zufällige neue Generation, kontrollierter Prozessabbruch, Entfernung der Projektion, kein Import eines verspäteten Ergebnisses, keine Wiederfreigabe durch eine parallel auslaufende alte Probe. Beide Copilot-Modelle müssen betroffen sein, ein anderer Alias nicht. Unterbrechung zwischen Widerruf, Storewechsel und Berichtspublikation sowie Agentneustart prüfen.
6. Berichtsentzug, Ablauf, Zukunftszeitpunkt und tatsächlich neuen Agentboot in einem bereits gebooteten Worker prüfen. Auswahl, Claim, Start und Resume müssen die aktuelle Evidenz lesen; bestehende Approvals dürfen nicht still erneuert werden. Heartbeat und Neupublikation alter Bytes dürfen keinen alten Prüfzeitpunkt verlängern.
7. Gezielten Drift von CLI-Pin, Binary, Runtimeprofil und menschlichem Nachweis einzeln prüfen. Ein neu datierter Altbericht muss gesperrt bleiben. Nur eine echte neue Prüfung mit erneut gültigen Bindungen darf Freigabe liefern. Eine unbekannte CLI-Version bleibt gesperrt.
8. Im dedizierten Test-Agentprozess `APP_ENV=testing`, `AI6_RUN_PROVIDER_ONBOARDING_SMOKE=1` und `AI6_PROVIDER_ONBOARDING_TEST_ACCESS=1` setzen und `php artisan test --filter=ProviderOnboardingSmokeTest` ausführen. Ohne Onboardingflag muss der Nachweis übersprungen werden; gesetztes Flag ohne Voraussetzungen muss fehlschlagen. `AI6_RUN_COMPOSE_SMOKE=1` allein darf keinen Login auslösen.

| Schritt | Beobachtung / wertfreie Evidenzreferenz | Bestanden / fehlgeschlagen |
|---|---|---|
| 1 | | |
| 2 | | |
| 3 | | |
| 4 | | |
| 5 | | |
| 6 | | |
| 7 | | |
| 8 | | |

## Menschliche Entscheidung

| Feld | Eintrag |
|---|---|
| Gesamtergebnis | |
| Offene Abweichungen und Entscheidung | |
| Unterschrift / nachvollziehbare digitale Signatur | |
| Datum | |

Die Entscheidung bindet ausschließlich die oben genannten Implementierungsbytes. Spätere Implementierungsänderungen benötigen einen neuen gebundenen Lauf und eine neue Signatur. Ein separater späterer Entscheidungscommit ersetzt keinen fehlenden Laufzeitnachweis; der Ticketstatus bleibt eine eigene menschliche Entscheidung.
