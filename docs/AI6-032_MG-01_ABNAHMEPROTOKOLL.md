# Abnahmeprotokoll AI6-032 / MG-01 — FakeAgent-Release-Gate im Linux-Compose-Stack

Ergebnisfreie Vorlage. Bindung, Beobachtungen, Ergebnis und Unterschrift werden ausschließlich
von der menschlichen Prüfperson eingetragen. Das Gate bleibt offen, bis dieses Protokoll im
realen Linux-Compose-Stack vollständig ausgefüllt und signiert vorliegt. Automatisierte Tests,
Windows-Läufe und übersprungene POSIX-Nachweise tragen kein Ergebnis ein.

## 0. Vorbereitung

Der reale Compose-Stack läuft unter Linux mit dem Standardprofil `strict`. Der geprüfte Stand
enthält die zu testenden Implementierungsbytes; alle Rollen und Healthchecks sind bereit. Der
Release-Gate-Befehl wird in einer Rolle ausgeführt, die den Repository-Testbestand und die für
die Prozess-, Git-, Effekt-Lock- und Checker-Nachweise erforderlichen Linux-Grenzen erreicht.

Besonders zu prüfen sind der Push-Retry in
`tests/Feature/Git/TicketMutationExecutorTest.php::test_worker_publishes_one_file_commit_and_finalizes_every_bound_projection`
und alle drei Datensätze von
`tests/Feature/Runs/ReviewOnlyExecutionTest.php::test_a_complete_review_only_workflow_keeps_reductions_visible_under_every_security_profile`.
Eine Methodenbindung oder ein separater plattformunabhängiger Grenztest ersetzt deren Ausführung nicht.
Der Commit muss alle getesteten Implementierungsbytes enthalten; ein Windowslauf oder ein uncommitteter
Arbeitsstand darf nicht als commitgebundene Linux-Abnahme eingetragen werden.

## 1. Bindung

| Feld | Wert |
|---|---|
| Geprüfter Commit | |
| Datum und Uhrzeit | |
| Docker-/Compose-Version | |
| PHP-/Kernel-Version | |

## 2. Ausführung

Ausgeführter Befehl:

```bash
php artisan ai6:release-gate
```

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| R1 | Der Befehl führt die feste FakeAgent-Workflow- und Recovery-Suite aus. | | |
| R2 | Implementierung, No-change, Human-/Scope-/Contract-Fälle, Multi-Review, Fix und Securityreview laufen durch. | | |
| R3 | Alle fünfzehn Wartestatus, ihre Producer und vorgesehenen Resolver beziehungsweise Cancelpfade werden nachgewiesen. | | |
| R4 | Sämtliche `RUN-006`-Grenzen werden an der Grenze und einen Schritt darüber geprüft. | | |
| R5 | Candidate-/Commit-Bindung, Push-/Status-Sagas, Neustart, Redelivery und Crash-Grenzen laufen durch. | | |
| R6 | Agent-, Reviewer- und Checker-Isolation einschließlich bösartiger Konfiguration, Hook und Helperversuch wird geprüft. | | |
| R7 | Kein POSIX-gebundener Prozess-, Git-, Effekt-Lock- oder Checkernachweis wird als `ÜBERSPRUNGEN` gemeldet. | | |
| R8 | Die Abschlussmeldung nennt keinen übersprungenen Nachweis und der Exitcode ist exakt `0`. | | |
| R9 | Der oben benannte Push-Retry einschließlich seiner Folgeschritte und alle drei Profilworkflow-Datensätze wurden tatsächlich ausgeführt; keiner davon meldet einen Skip. | | |

## 3. Ergebnis

| Feld | Wert |
|---|---|
| Exitcode | |
| Anzahl Tests / Assertions | |
| Anzahl übersprungener Nachweise | |
| Gesamtergebnis (bestanden / nicht bestanden) | |
| Befunde und Nacharbeiten | |

## 4. Unterschrift

| Feld | Wert |
|---|---|
| Name der Prüfperson | |
| Datum | |
| Unterschrift | |
