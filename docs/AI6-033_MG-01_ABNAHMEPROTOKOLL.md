# Abnahmeprotokoll AI6-033 / MG-01 — Reale Codex-Abnahme

Ergebnisfreie Vorlage. Die menschliche Prüfperson trägt Bindung, Beobachtungen,
Ergebnis und Unterschrift nach Durchführung im realen Linux-Compose-Stack ein.
Automatisierte Tests mit dem Fake-Codex-Binary, Windowsläufe und ein
übersprungener Smoke schließen dieses Gate nicht. Jede spätere
Implementierungsänderung erfordert eine neue Prüfung am exakten
Implementierungscommit; eine neu verifizierte CLI-Version wird ausschließlich
über diese Prüfung in `CodexCliAdapter::VERIFIED_TRANSPORT_VERSIONS` aufgenommen.

## 0. Vorbereitung

Der Stack läuft mit Securityprofil `strict` und aktiver
`AI6_SECURITY_REQUIRE_AGENT_SANDBOX`, ohne bestätigten Reduced Mode. Das Image
enthält die gepinnte Codex-CLI unter `AI6_CODEX_BINARY`, und
`AI6_CODEX_PINNED_VERSION` ist die von `codex --version` gemeldete
Versionszeichenkette ohne das Präfix `codex-cli `. Eine ausdrücklich für die
Abnahme bereitgestellte Testauthprojektion (`auth.json` eines eigenen
Testprofils) wird als Credential-Projektion des Slots in das gestagte Home
gelegt; sie stammt aus keinem produktiven Konto. Ein verwaltetes Testprojekt
besitzt eine freigegebene Approval mit `codex-gpt-5.6-terra` als Implementierer
und `fake` als Reviewer sowie einen Review-only-Lauf mit Codex als Reviewer.

## 1. Bindung

| Feld | Wert |
|---|---|
| Geprüfter Implementierungscommit | |
| Datum und Uhrzeit | |
| Docker-/Compose-Version und Image | |
| `codex --version` im Agentcontainer | |
| `AI6_CODEX_BINARY`, `AI6_CODEX_PINNED_VERSION` | |
| Gesetzter `AI6_CODEX_SANDBOX_PROOF` nach dieser Prüfung | |
| Geprüfte Modell-/Effortkombinationen | |
| Run-, Slot-, Session- und Ausführungs-IDs | |
| Kontext-, Prompt-, Instruktions- und Runtimeprofil-Hashes | |
| Credential-Revision der Testprojektion | |

## 2. Statische Prüfung und Doctor

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| D1 | `ai6:doctor` zeigt `Codex-CLI: OK` mit `Statische Prüfung: OK` und einer von der Sonde erbrachten `Reale CLI-Evidenz`, die dem Pin entspricht. | | |
| D2 | Ein absichtlich falscher Pin führt zu `FEHLER (codex_version_drift)`; Fake und die übrigen Profile bleiben startbar. | | |
| D3 | Die gepinnte Version bietet in `codex exec --help` exakt die Flags aus `CodexCliAdapter::TRANSPORT_FLAGS`; `--ephemeral`, `--ignore-user-config`, `--ignore-rules`, `--output-schema` und `--json` sind vorhanden, und `[PROMPT]` liest bei `-` von der Standardeingabe. | | |
| D4 | `ai6:doctor` zeigt den `Schutznachweis` getrennt von der Versionsevidenz. `codex features list` mit den `--disable`-Overrides des Turns meldet jeden Namen aus `CodexCliAdapter::DISABLED_FEATURES` als `false` und als aktiv exakt `CodexCliAdapter::PERMITTED_ENABLED_FEATURES` — kein weiteres Feature. | | |
| D5 | `codex debug models` der gepinnten Version liefert genau die Slugs und Effortstufen aus `CodexCliAdapter::VERIFIED_MODELS`; ein im Profil konfigurierter Slug außerhalb des Katalogs sperrt das Profil mit `codex_model_unverified`, ohne einen Turn zu starten. | | |

## 3. Gültiger Turn

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| T1 | Ein Implementierungsturn in der Agentrolle ändert ausschließlich Dateien im beschreibbaren Workspace; der Worker importiert genau diesen Patch, und das Providerartefakt trägt Nutzungswerte mit Quelle `codex_cli_exec_json`. | | |
| T2 | Ein Review-only-Turn liefert genau eine finale Antwort, die `AgentResultValidator` mit vollständiger `criterion_coverage` annimmt; Schreibversuche auf Workspace, Snapshot, Auth und Konfiguration scheitern. | | |
| T3 | Die geprüften Modell-/Effortkombinationen des Profils starten; eine freie Option aus Projekt oder Prompttext verändert weder Binary noch Sandboxoption. | | |
| T5 | Ein Prompt exakt auf `max_prompt_input_bytes` erreicht die reale CLI vollständig über die Standardeingabe (Promptargument `-`); eins darüber startet keinen Prozess. | | |
| T6 | Zwei vollständige Ergebnisantworten desselben Turns und eine Antwort nach `turn.completed` enden beim Verbraucher als `invalid_json` ohne Import; die vom Turn gemeldeten Nutzungswerte liegen trotzdem mit Quelle am Providerartefakt. | | |
| T7 | Die Abnahme läuft in der Agentrolle über die Mailbox. Wird ergänzend der direkte Pfad im bestätigten Reduced Mode geprüft, liegt auch dort je fehlgeschlagenem Turn ein Providerartefakt mit Nutzungswerten und leeren Antwortbytes vor. | | |
| T4 | Ein Turn ohne Authprojektion, mit fremdem Profil oder nach Rotation/Logout der Revision startet nicht und endet benannt. | | |

## 4. Discovery-, Sandbox- und Credentialgrenzen

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| G1 | Codex liest ausschließlich die materialisierten `AGENTS.md`-Snapshotbytes; angelegte Host-, Parent-, Home- und veränderte Workspace-Instruktionen sowie `AGENTS.override.md` werden nicht geladen. | | |
| G2 | Nicht freigegebene `.agents`-, `.codex`-, `.claude`-, Home- und Workspace-Konfiguration, MCP, Plugins, Skills, Hooks, Commands und Helper bleiben unwirksam. Ein im verwalteten Repository angelegter Köder unter `.agents/skills/` erreicht den Workspace nicht und wird von Codex nicht aktiviert. | | |
| G2a | Die gepinnte Version entpackt ihre gebündelten Vendor-Skills beim Start nach `$CODEX_HOME/skills/.system` und legt dort Hilfsbinaries und Zustandsdatenbanken an. Festhalten, ob die CLI unter der read-only Authprojektion trotzdem startet und ob nach dem Turn in `CODEX_HOME` weiterhin nur `auth.json` liegt. Startet sie nicht, ist das eine Entscheidung nach Plan §13.3 und kein stiller Umbau der Projektion. | | |
| G3 | Die Sandbox (bwrap plus seccomp) ist im Agentcontainer verfügbar; Agententools haben keinen Netzzugriff, der Providertransport bleibt möglich. Ist die Sandbox nicht verfügbar, führt die CLI keinen Befehl unsandboxed aus — andernfalls bleibt das Profil gesperrt. | | |
| G3a | Diese Prüfung ist die einzige Quelle von `AI6_CODEX_SANDBOX_PROOF`: Die gepinnte CLI kann Sandbox und Toolnetzgrenze turnfrei nicht melden (`codex sandbox` verlangt `--permissions-profile` und eine `[permissions]`-Tabelle im versiegelten `CODEX_HOME`, `codex debug prompt-input` spiegelt `--sandbox` nicht, unbekannte `-c`-Schlüssel werden ignoriert). Der Wert wird erst nach bestandenem G3 auf `<Pin>:<Plattform>` gesetzt; vorher verweigert der Adapter jeden Turn als `agent_codex_sandbox_unproven`. Ein Schreibversuch außerhalb des Änderungsausgangs und ein Netzzugriff eines Agententools sind dabei einzeln beobachtet und hier festgehalten. | | |
| G4 | Der Prozess sieht nur die read-only Authprojektion seines Profils; Managed-Clone, Deploy-Keys, Git-/SMTP-/Datenbankcredentials und fremde Profile sind unerreichbar; Cache, History und Sessiondateien werden weder übernommen noch persistent geschrieben. | | |
| G5 | Nach Timeout und Cancel läuft kein Codex-Prozess weiter; nach dem Lauf bleiben weder Home, Export, Auftrag, Claim, Heartbeat noch Ergebnisdatei zurück. | | |

## 5. Sessions

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| S1 | Zwei Runs und zwei Slots erhalten getrennte AI6-Sessions; jeder Turn ist eine neue `codex exec`-Invocation mit `--ephemeral`, und weder `resume` noch `--last` werden übergeben. | | |
| S2 | Wird ein natives Resume beansprucht, ist es mit einem zweiten Turn derselben Session geprüft; andernfalls ist festgehalten, dass die gepinnte Version Resume nur mit zwischen Turns aufbewahrten Sessiondateien anbietet. | | |

## 6. Ergebnis

| Feld | Wert |
|---|---|
| Gesamtergebnis (bestanden / nicht bestanden) | |
| Evidenzreferenzen | |
| Befunde und Nacharbeiten | |

## 7. Unterschrift

| Feld | Wert |
|---|---|
| Name der Prüfperson | |
| Datum | |
| Unterschrift | |
