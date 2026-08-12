Behebe die offenen Findings aus deinem unmittelbar vorherigen Review.

Arbeite jeden Punkt einzeln ab:

1. Prüfe ihn gegen den aktuellen Code. Ist er bereits behoben oder nicht zutreffend, ändere nichts und begründe das knapp.
2. Behebe nur bestätigte Probleme innerhalb des wirksamen freigegebenen Scopes. Melde eine nötige Scope- oder Vertragsänderung vorab, statt sie still vorzunehmen.
3. Ändere weder Ticketstatus noch Runmetadaten oder Instruktionsdateien wie `AGENTS.md` und `CLAUDE.md`.
4. Führe die relevanten Tests aus und prüfe danach den vollständigen aktuellen Diff auf Regressionen, Sicherheitsprobleme und weitere konkrete Fehler.

Antworte knapp mit dem Status jedes ursprünglichen Findings (`behoben`, `nicht zutreffend` oder `offen`), den ausgeführten Tests und ausschließlich neuen handlungsrelevanten Findings mit Schweregrad, Datei, Zeile, Begründung und Zielverhalten. Wiederhole keine erledigten Punkte und vermeide reine Stilvorschläge. Wenn nichts offen oder neu ist, bestätige das ausdrücklich.
