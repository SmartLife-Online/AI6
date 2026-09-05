# ANTRAG — AI6-032 / Implementierungsisolation

**Art:** entscheidung
**Ausgelöst durch:** Review vom 5. September 2026 zu `AI6-032/AC-04` und `AGT-009`.

## Befund

`app/AI6/Runs/RunImplementation.php` exportiert den Worktree mit `IsolatedTreeExporter` und übergibt das Exportverzeichnis unmittelbar an `AgentAdapter::turn()`. Der Pfad ruft `ExecutionHomeManager::create()` nicht auf. Die Reviewpfade in `app/AI6/Reviews/ReviewRound.php`, `FindingVerificationRound.php` und `SecurityReviewStep.php` verwenden diese zentrale Naht bereits.

Der vorhandene Test `tests/Feature/Git/ImplementationImportIsolationTest.php::test_the_agent_view_carries_no_git_metadata_hook_or_host_instruction` belegt Gitmetadaten-/Hook-/Hostisolation. Er belegt nicht, dass ein Providerprozess bösartige Workspacekonfiguration nicht entdeckt: Der FakeAgent lädt solche Konfiguration von sich aus nicht. `AC-04` bleibt deshalb in `FakeAgentReleaseGateCommand::AC_COVERAGE_GAPS` offen.

## Konflikt mit dem Blueprint

`AI6-032/AC-17` und Plan §15 verlangen bei einem gefundenen Fachlogikfehler einen separaten Folgeauftrag. Für diesen neuen Fix existiert noch kein eigener Blueprint; eine neue Detailticket-ID wird hier nicht erfunden.

Die im Review genannte Abhängigkeit von `AI6-033` erzeugt einen Abnahmekonflikt: Plan §15.7 lässt `AI6-033` bereits von `AI6-032` abhängen. Wenn der Isolationsfix erst nach `AI6-033` erfolgen darf, kann `AI6-032` sein verpflichtendes `AC-04` zuvor nicht schließen. Das ist eine Reihenfolgeentscheidung im Plan, keine durch Testausnahmen lösbare Lücke.

## Vorschlag

Empfohlen wird ein eigener Fix-Blueprint vor der vollständigen Abnahme von `AI6-032`, auf Basis der bereits vorhandenen Execution-Home-Naht. Die echten Codex-Transportdetails und deren zusätzlicher Nachweis bleiben bei `AI6-033`. Alternativ muss eine ausdrückliche Planrevision den Providerteil von `AC-04` zeitlich nach `AI6-033` verlagern und die Freigabereihenfolge entsprechend ändern. Eine stille Verengung des bestehenden Akzeptanzkriteriums ist keine Alternative.

Der abzugrenzende Fixauftrag hat folgenden überprüfbaren Umfang:

1. Implementierungs- und Fixturns erhalten dieselbe serverseitig gebundene Execution-Home-Erzeugung wie Reviewturns. `RunImplementation`, `AgentResultContext` und `ExecutionHomeManager` werden gegen ihre realen Schreib-/Importverträge geprüft; ein bloßes Einfügen von `create()` genügt nicht, weil der versiegelte Reviewworkspace read-only ist, während ein Implementierungsturn einen validierbaren Änderungsausgang benötigt.
2. Snapshot, Runtimeprofil, ausgewähltes Credentialprofil und Session werden vor jedem Start und Resume geprüft. Unverfügbare oder abweichende Bindungen starten keinen Provider; Cleanup erfolgt auch nach Fehler und Abbruch. Es entsteht keine zweite Implementierung von Discovery-, Credential- oder Scopepolicy.
3. Ein deterministischer Prozessdouble versucht tatsächlich, nicht freigegebene `.codex`-/`.claude`-Konfiguration, MCP-, Plugin-, Skill-, Hook-, Command- und Helperkonfiguration zu entdecken bzw. zu aktivieren. Der Nachweis muss den Prozess erreichen, Wirkungslosigkeit beobachten und mit einer gezielt geöffneten Testgrenze fehlschlagen.
4. Reguläre Änderungen werden weiterhin nur über den bestehenden validierten Patchimport übernommen. Die Nachweise für unveränderten Snapshot, Rollen-/Credentialtrennung, keine Gitmetadaten, Retry, Resume und Cleanup bleiben erhalten.
5. Erst nach bestandenem Prozess-/Isolationsnachweis darf die Lücke `AC-04` entfernt werden. Wird damit die letzte Lücke geschlossen, werden im selben Änderungssatz die aktuell bewusst roten Gap-Erwartungen in `tests/Unit/Runs/ReleaseGateCommandTest.php` auf den vollständigen Erfolg umgestellt. Der bedingte Erfolgspfad und sein separater Zweigtest existieren bereits.

## Auswirkung auf den Plan

Benötigt werden die menschliche Entscheidung über die Reihenfolge, ein eigener neuer Blueprint mit der nächsten freien ID und anschließend dessen Detailticket nach dem Tickettemplate. `AI6-033` bleibt der unveränderte Codex-Adaptervertrag. Dieser Antrag ändert weder Plan, Ticketstatus noch Gate-Ergebnis und stellt keine Implementierungsfreigabe dar.
