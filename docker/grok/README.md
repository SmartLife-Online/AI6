# AI6-Grok-Quellbuildentwurf (nicht eingebunden)

Dieser nicht in das Dockerfile eingebundene Entwurf enthält den am 14. September 2026 für AI6-035 ausdrücklich freigegebenen Sandbox-Speicherpatch. Er beschreibt einen eigenen AI6-Build, keine unveränderte Herstellerbinärdatei. Die native Verifikation und die Umstellung des Adapterpins sind noch offen; dieser Arbeitsstand ist noch kein freigegebener Imagekandidat.

## Gebundene Eingaben

Seit der Reviewkorrektur vom 15. September 2026 wird dieser Entwurf nicht vom Dockerfile gebaut oder installiert. Das Runtimeimage bindet den Herstellerdownload 1.0.5. Vor einer Aktivierung müssen native Verifikation und sämtliche Adapter-, Login-, Fixture- und Dokumentationspins gemeinsam umgestellt werden. Alle folgenden Build-, Image- und Speicherangaben beschreiben den geplanten Quellbuild, keinen ausgelieferten Stand.

| Eingabe | Bindung |
|---|---|
| Öffentliches Repository | [xai-org/grok-build](https://github.com/xai-org/grok-build) |
| Öffentlicher Commit | `37949780c144e37df692e3d669051a21fec24f20` |
| Herstellerinterner `SOURCE_REV` | `c4ea71cfdbcdb21e32e41bc25a0043d7d4836714` |
| Quellarchiv SHA-256 | `e9d9791ce047da4c296cc7960a82cd7c2542029a80c9e3e0858cbd285879eff3` |
| Buildkennung | `1.0.24-ai6.1+37949780c144` |
| Rust | `1.94.0-bookworm`, OCI-Digest `365468470075493dc4583f47387001854321c5a8583ea9604b297e67f01c5a4f` |
| Native Buildpakete | Debian-Snapshot `20260731T000000Z` |
| Protoc | `29.3`, derselbe Download und Hash wie im gebundenen Hersteller-`bin/protoc` |
| Rust-Abhängigkeiten | Unverändertes Hersteller-`Cargo.lock`, `cargo build --locked` |

`build.sh` prüft Quellarchiv, `SOURCE_REV` und Protoc und wendet `sandbox-work-dir.patch` ohne Fuzz an. Der Build behält die Standardfeatures einschließlich `sandbox-enforce` und das Herstellerprofil `release-dist`. RELRO, sofortige Symbolbindung und nicht ausführbarer Stack sind explizite Linkerflags. Zwei parallele Cargo-Jobs begrenzen die Last. Compiler und Buildwerkzeuge gelangen nicht in das Runtimeimage. Lizenzhinweise, Quellrevision und Patch werden neben der Binärdatei im Image aufbewahrt. Diese Bindungen beschreiben die Build-Eingaben; Bitidentität zweier vollständiger Builds wurde noch nicht nachgewiesen.

## Speichergrenze

`AgentProcessScope` erzeugt `/tmp/ai6-provider-sandbox` mit Modus `0700` im frischen privaten tmpfs jedes Providerprozesses. Es ist kein Host-Bind-Mount und kein Teil des gemeinsamen Ergebnisbaums. `GrokCliConfiguration::environment()` setzt ausschließlich diesen festen Pfad als `GROK_SANDBOX_WORK_DIR`.

Der Patch verschiebt genau die nativen `sandbox-blocked.*`-/`sandbox-blocked-dir.*`-Platzhalter und den `sandbox-bwrap-sentinel` in diesen Bereich. Ein gesetzter Pfad muss absolut, kanonisch, ein echtes Verzeichnis, vom ausführenden Benutzer besessen und exakt `0700` sein sowie außerhalb von `GROK_HOME` liegen. Ein ungültiger Wert fällt geschlossen aus. Ohne die Variable behält der Herstellercode sein bisheriges Verhalten bei; AI6 setzt sie immer.

Die Platzhalter bleiben `000`. Grok erzeugt weiterhin die echten Read-only-Mounts, prüft die Deny-Ziele und den Sentinel und sperrt Namespaceänderungen. Home, Konfiguration, Instructions und Auth bleiben read-only. Die vorhandene, separat genehmigte Sessionumleitung bleibt bestehen. Beim Prozessende verschwinden die Arbeitsdateien mit dem Namespace, auch Dateien und Verzeichnisse mit Modus `000`.

## Noch zu erbringende Verifikation

- Vollständiger nativer Build und Rust-Test `ai6_sandbox_work_dir_tests`.
- Tatsächliche Versionsausgabe, Inspect-Oberfläche und Modellcacheformat des neuen Pins erfassen und Adapter samt Fixtures daran binden.
- Credentialfreie native Doctor-Probe unter der unveränderten Agent-Seccomp-Grenze und ohne Netzwerk: Sandbox vollständig vorbereitet; fehlende Anmeldung erst danach und ohne Modellturn.
- Negative Kontrollen für ungültigen Scratch-Pfad und weiterhin gesperrte Home-/Auth-/Instruction-Schreib- und Lesepfade; neue Scratch-Dateien dürfen nicht zwischen Invocations überleben.
- Betroffene Linuxregressionen, vollständiges Abschluss-Gate und endgültiger Imagebuild.
- Menschliche Providerabnahme und Account-Smokes bleiben separate offene Gates. Alte Capabilityevidenz darf den neuen Pin nicht freigeben.

Am 14. September waren Quell- und Protoc-Prüfsummen sowie `cargo fetch --locked` erfolgreich. Der native Build wurde nach einem Docker-Stillstand abgebrochen. Auf `C:` waren nur etwa 1 GB frei; zusätzlich meldete Docker einen fatalen internen Verbindungsfehler. Der Neustart endete im Zeitlimit. Das ist kein bestandener nativer Build und kein Nachweis der Sandboxwirksamkeit.
