# AI6-041 / MG-01 — Grok-Abnahme

Ergebnisfreie Vorlage. Gate offen; die technische Freigabe der Sessionumleitung ist kein bestandenes Gate.

Technischer Vorbefund vom 13. September 2026, kein Gate-Ergebnis: Der Pin 1.0.5 las in einer synthetischen Linux-Probe trotz `--sandbox strict` über `read_file` einen gruppenlesbaren fremden Turn-Köder außerhalb von Workspace, Home und TMPDIR. Der Lesebereich umfasst dort also fremde zugängliche Dateien. Promptdatei und Sessionumleitung funktionierten; ein `sandbox-events`-Nachweis für eine wirksame `strict`-Lesebeschränkung wurde nicht erbracht. AC-12 ist dadurch nicht erfüllt und darf nicht allein mit TC-12 geschlossen werden. Vor einer Abnahme ist die Leseschutzlücke ursächlich zu beheben und erneut am Pin in der Agentrolle zu prüfen.

Ergänzung der zweiten Reviewkorrektur: `ai6-review` ist implementiert. Der native Pin bricht jedoch beim Erzeugen von `home/sandbox-blocked-dir.<PID>` am versiegelten Home ab. Die erfolgreiche Deny-Diagnose mit eigens beschreibbarem Home ist keine zulässige Produktkonfiguration und kein Gate-Nachweis. Die Entscheidung zu diesem Blocker und den Isolationsresiduen steht in `AI6-041_TRANSPORT_ENTSCHEIDUNGSANFRAGE.md`.

| Bindung | Einzutragender Wert |
|---|---|
| Exakter Implementierungscommit | Offen |
| Prüfer, Datum und Unterschrift | Offen |
| Linux, Kernel, Architektur und Containerimage | Offen |
| CLI-Version, Binary-SHA-256 und Bubblewrap-Version | Offen |
| Agent-UID, Capabilities und Seccomp-SHA-256 | Offen |
| Runtimeprofil, Instruktionssnapshot und Modell/Effort je Rolle | Offen |
| Doctor-Evidenzbindung je Rolle | Offen |

| Prüfung | Ergebnis / Evidenz |
|---|---|
| Gültiger Reviewturn mit vollständiger Kriterienabdeckung | Offen |
| Gültiger Verifierturn zu exakt gebundenem Finding oder Duplikatgruppe | Offen |
| Neue Invocation ohne fremde oder frühere History | Offen |
| Home, Auth, Konfiguration und Linkziel gegen Änderung geschützt | Offen |
| Sessiondateien ausschließlich im Ergebnisverzeichnis; Cleanup nach Erfolg, Timeout und Cancel | Offen |
| Host-/Parent-/Workspace-Köder, MCP, Plugins, Skills, Hooks und Helper wirkungslos | Offen |
| Schreib-, Shell-, Subagenten-, Memory-, Websuch- und Toolnetzanfragen wirkungslos | Offen |
| Providertransport weiterhin funktionsfähig | Offen |
| Fehlende Sandboxunterstützung verhindert Start | Offen |
| Credentialfreier PHP-Doctor-Smoke mit echter Inspect-Redaction und unter inputRoot/outputRoot gestagtem Home; eigene Auth-/Runtimepfade treffen die Deny-Globs | Offen |
| Doctor: dritte credentialfreie Probe mit exakten Turn-Guards; genau eine Init-Zeile und Auth-Fehler belegen nur Sandboxvorbereitung. `agent_grok_sandbox_unprepared` sperrt; `agent_grok_sandbox_role_unverifiable` erfordert Prüfung in der Agentrolle | Offen |
| Sandboxereignisse melden `profile: ai6-review` und beide Globs in `deny_paths`; negative Auth-/Runtime-Köderlesungen bestätigen die Wirkung; Promptdatei in TMPDIR und festes Sessionziel bleiben bei versiegeltem Home erreichbar | Offen |
| `read_file` auf gruppenlesbare Auth-/turn.json-Köder eines anderen gestagten Turns wird verweigert (AC-12; TC-12 allein genügt nicht) | Offen |
| Promptmaximum und beschädigte / mehrfache / fehlende finale Antwort | Offen |
| `num_turns` des realen Review-/Verifierturns liegt deutlich unter `MAX_TURNS` | Offen |

Die Tests verwenden ausschließlich ausdrücklich bereitgestellte Testcredentials. Keine Credentialwerte oder unredigierten Providerantworten in dieses Protokoll übernehmen. Eine spätere Änderung der gebundenen Implementierung oder Laufzeit macht den zugehörigen Nachweis erneut erforderlich. Ticketstatus und Ergebnis trägt ausschließlich der Mensch ein.
