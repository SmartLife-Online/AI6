# AI6-041 — Entscheidung und Nachweis zum nativen Grok-Home

**Stand:** 12. September 2026. Die vorgeschlagene Lösung ist ausdrücklich freigegeben und implementiert; die reale menschliche Abnahme MG-01 bleibt offen.

**Ausgangsbasis:** `d50c18d6ff04a0397d9d2e664cb2fd99829703f2`, Plan V1.7.7. Die Vertragsentscheidung steht jetzt in Plan V1.7.8 und im Files-Scope von AI6-041. Ticketstatus und Gate-Ergebnis wurden nicht verändert.

## Entscheidung

### Vierte Reviewkorrektur vom 14. September 2026: Redaction und echte Staging-Wurzel

Die bisherige Aussage „Sandbox vorbereitet“ war für reale Turns nicht belastbar: Das Doctor-Home lag außerhalb der Agent-Eingabewurzel; die Deny-Globs hatten keine Treffer. Der Doctor erzeugt seine Homes jetzt unter `AgentExecutionProcessor::inputRoot()` und `outputRoot()` mit gemeinsamen `execution-<hex>`-Elternverzeichnissen. Damit treffen beide Globs auf sein eigenes Auth- und Runtimeverzeichnis. Ohne Schreibrecht oder bei nicht verfügbarer Wurzel meldet er `agent_grok_sandbox_role_unverifiable`. Er bereinigt ausschließlich selbst angelegte Verzeichnisse. Die Control-Prozesspolicy lässt dafür zusätzlich genau die konfigurierte `AI6_AGENT_EXECUTION_ROOT` zu; keine allgemeine Dateisystemfreigabe.

Die obligatorische Redaction bleibt vor der JSON-Auswertung aktiv. Erwartete Pfade für `configSources.layers`, `cwd`, `projectInstructions` und `init.cwd` werden jetzt über denselben Redactor mit dem jeweiligen Surface-/Event-Kontext normalisiert. Das Fake emittiert unescapete Slashes; der am Linux-Pin unverändert aufgezeichnete `inspect --json`-Output liegt als `tests/Fixtures/Agents/grok-native-inspect.json` vor und durchläuft im nativen Extraktortest dieselbe produktive Auswertung. Der Pfad zur Testauthdatei wird ohne ein als Secret-Pattern interpretierbares Literal gebildet; der unveränderte `RedactionArchitectureTest` ist Teil der Iterationsauswahl und besteht unter Windows und Linux.

`tests/Feature/Shared/Doctor/GrokCliNativeDoctorSmokeTest.php` läuft mit Linux und `AI6_GROK_BINARY`, ohne Authprojektion und in einem Container mit `--network none`. Er ruft als PHP den echten Doctor und damit `probe()` sowie `probeSandbox()` auf. Erwartet wird beim unveränderten 1.0.5-Pin das bestandene `inspect` und anschließend `agent_grok_sandbox_unprepared` wegen des versiegelten Homes — ausdrücklich kein „Sandbox vorbereitet“. Ohne Binary/Linux bleibt dieser Smoke sichtbar übersprungen. Der separate reale Provider-Smoke und MG-01 bleiben unverändert offen.

Der native PHP-Smoke besteht mit 12 Assertions: PHP 8.5.9, glibc 2.41, Bubblewrap 0.12.0, UID/GID 10001, `cap_drop: ALL`, `no-new-privileges`, unverändertes Agent-Seccomp-Profil und keine Netzinterfaces außer Loopback. Das temporäre Prüfimage ist kein Produktimage- oder Pinwechsel; das Grok-Binary bleibt SHA-256 `9ba87444e1819e8f6104adbbf4676a870c204380aa5c3e1c38a926c4ea677238`. Ein erster Lauf fand das fehlende `kill`-Programm im Prüfimage; nach Ergänzung von procps funktioniert die bestehende Prozessgruppengrenze. Die native Inspect-Aufzeichnung erfolgte separat mit UID 10002/GID 10001 auf dem bereits beschriebenen Linux-Pin. Es wurden keine Credentials und kein Modellturn benötigt.

Zusätzliche Scopekorrektur: `tests/Unit/Shared/Process/ProcessPolicyAndLimitTest.php` war in seiner unveränderten PID-Grenzprobe rot. Die Ursache ist im Testsetup belegt: Der Runner hatte weder `setsid` noch Gruppenbeendigung konfiguriert, obwohl die Messung auf eine eigene Prozessgruppe angewiesen ist. Nur diese Probe startet nun verbindlich eine solche Gruppe; die produktive Kontrolle und alle Grenzwerte bleiben unverändert. Die fünf Tests dieser Klasse bestehen anschließend. Keine Lockerung oder Überspringung des fehlgeschlagenen Nachweises.

**Verifikation dieser Runde:** Die breite gezielte Linux-Auswahl lieferte zunächst 146 bestandene Tests, den oben beschriebenen fehlgeschlagenen PID-Test und zwei sichtbare Smokeskips. Nach der ursächlichen Setupkorrektur bestehen alle fünf Prozesspolicytests. Der separat mit echtem Binary ausgeführte credentialfreie Smoke besteht ebenfalls; damit sind 148 unterschiedliche gezielte Linux-Tests nachgewiesen, der reale Provider-Smoke bleibt offen. Die abschließenden lokalen Dokumentations-/Fixture-/Redactiontests bestehen unter Windows mit sieben Tests und 1145 Assertions. Die Detailticket-Validierung, der Manifest-Driftcheck und `composer validate --strict` bestehen. Pint, PHPStan und `git diff --check` bestehen ebenfalls. PHPStan wurde zunächst mit geleertem Ergebniscache ausgeführt; ein dabei gefundener unzulässiger Encode-Flag an einem Fake-Decode-Aufruf wurde korrigiert und erneut geprüft. Keine vollständige reguläre Suite und kein externer Locked Install: Diese Runde ändert weder Abhängigkeiten noch Produktplattform. Keine Status-/Gateänderung, kein Commit und kein Push.

### Dritte Reviewkorrektur vom 13. September 2026: Entscheidungsoptionen und Doctor

Die Optionen A bis D im folgenden Entscheidungsantrag ersetzen die unbestimmte Umleitungsempfehlung. B wird empfohlen, bleibt aber eine noch zu entscheidende Vertragsänderung. Weder Home-Rechte noch Ticketstatus oder Gate-Ergebnisse wurden in dieser Runde geändert.

Der Doctor führt nach Version und Discovery jetzt eine dritte credentialfreie Probe aus: dieselben `guardArguments()` wie beim Turn, derselbe Workspace-/Modellbezug und das Streamingformat, mit einer kurzen Promptdatei im eigenen Ergebnisverzeichnis. Keine Authprojektion und kein `XAI_API_KEY` werden bereitgestellt. Nur Exit 1 als regulärer Prozessfehler mit genau einer Init-Zeile und genau einem `error_during_execution` mit `num_turns: 0` und dem nativen „Not signed in.“-Fehler belegt „Sandbox vorbereitet“. Das ist keine Prüfung der Review-Initoberfläche: Im credentialfreien Fehlerpfad meldet der Pin dort leere Tools und ein unbekanntes Modell. Platzhalter-/Deny-Fehler und abweichende Ausgaben sperren als `agent_grok_sandbox_unprepared`. bwrap-Namespace-Fehler werden getrennt als `agent_grok_sandbox_role_unverifiable` / „in dieser Rolle nicht nachweisbar“ gemeldet. Die reale CLI-Evidenz wird erst nach allen drei Proben gemeldet; der menschliche Nachweis MG-01 bleibt zusätzlich notwendig.

Die native credentialfreie Gegenprobe des Linux-Pins lieferte das erwartete Init-/Auth-Muster ohne Modellrequest. Sie verwendete ein eigens beschreibbares Diagnose-Home, um den erfolgreichen Vorbereitungspfad zu untersuchen; das versiegelte Produkt-Home bleibt am dokumentierten Platzhalterfehler gesperrt. Diese Vorbereitungsevidenz ersetzt weder B noch MG-01. Lokale Probeausgaben bleiben außerhalb von `storage/framework/testing/`.

**Verifikation dieser Runde:** 117 unterschiedliche gezielte Linux-Tests bestehen: Adapter, Doctor, Prozessgrenze, Dokumentations-/Scaffoldvertrag sowie Grok-Ausführung und gemeinsame Home-Erzeugung. Die acht Doctor-Fälle prüfen außerdem fehlende/doppelte Init-Zeilen, beschädigtes JSON und einen unpassenden Exitcode; das Fake prüft die exakten Guards, Promptdatei und fehlende Authprojektion. PHPStan besteht nach vollständig geleertem Ergebniscache. Pint, Detailticket-Validierung, Manifest-Driftcheck, `composer validate --strict` und `git diff --check` bestehen. Keine vollständige reguläre Suite und kein externer Locked Install in dieser Findings-Fixiteration; kein realer Providerturn und keine Abnahme. Keine Dependency-/Plattformänderung, kein Commit und kein Push.

### Zweite Reviewkorrektur vom 13. September 2026: Deny-Profil und offener Pin-Blocker

Der aktuelle Aufruf verwendet `--sandbox ai6-review`. Vor dem Versiegeln materialisiert die gemeinsame Home-Erzeugung deterministisch `home/sandbox.toml` mit `extends = "strict"`, `restrict_network = true` und den beiden Deny-Globs `<AI6_AGENT_EXECUTION_ROOT>/execution-*/*/home/auth` sowie `<AI6_AGENT_EXECUTION_ROOT>/execution-*/*/runtime`. Auch Fake- und Doctor-Homes durchlaufen diese Erzeugung. Die exakten Bytes werden beim Start geprüft und einschließlich konfigurierter Wurzel in den Evidenzschlüssel aufgenommen. Die folgenden Abschnitte über `strict` dokumentieren den vorherigen Befund, nicht den aktuellen argv-Vertrag.

**Native Gegenprobe:** Derselbe Linux-Pin und dieselben UID-/Capability-/Seccomp-Grenzen wie unten wurden verwendet. Der Profilinhalt wurde direkt aus `GrokCliConfiguration::sandboxBytes()` erzeugt. Unter der Wurzel lagen fremde synthetische Auth- und Runtime-Köder (0750/0440, Worker-Eigentümer 10001, gemeinsame GID 10001). Mit versiegeltem Home scheitert der Pin vor dem ersten Modellrequest: `could not create bwrap placeholder for read-deny path …; refusing to start with a partial sandbox`. Die Dateisyscallspur zeigt `mkdir("…/home/sandbox-blocked-dir.<PID>", 0777) = -1 EACCES`. Damit ist der geforderte kombinierte Positivnachweis aus Promptdatei, Sessionziel und versiegeltem Home nicht erbracht. Das Verhalten bleibt fail closed.

Eine gesonderte, ausdrücklich unzulässige Diagnosekonfiguration machte nur den Home-Verzeichnisknoten beschreibbar, um die Ursache einzugrenzen. Damit lieferte `sandbox-events.jsonl` ein `ProfileApplied` für `ai6-review`, `enforced: true`, `restrict_network: true` und beide konfigurierten Globs in `deny_paths`. Je ein `FsViolation` und ein nativer `read_file`-Rückgabewert `PermissionDenied` belegten die Sperre von fremdem `home/auth/token` und `runtime/turn.json`. Promptdatei und umgeleitete Sessions funktionierten, das finale synthetische Resultat meldete `num_turns: 3`. Der Ereignislog enthält die Globs, nicht eine Aufzählung ihrer expandierten Treffer. Diese Diagnose ist ausdrücklich kein Nachweis für das versiegelte Produkt-Home und darf keinen Capability-Schlüssel freigeben. Lokale Skripte und Ausgaben stehen außerhalb des Testing-Ergebnisbaums unter `storage/app/private/grok-review/`.

**Entscheidungsantrag zu AC-12 — präzisiert nach der dritten Reviewrunde:** Der Pin 1.0.5 verwendet `$GROK_HOME/sandbox-blocked.<PID>` für Dateien und `$GROK_HOME/sandbox-blocked-dir.<PID>` für Verzeichnisse. Es gibt im untersuchten Pin keine Umleitungsvariable für diese Platzhalter; ein anderes `HOME` verschiebt sie bei gesetztem `GROK_HOME` nicht. Die ergänzende native Dateisyscallprobe dieser Runde belegt beide Platzhalter unter `GROK_HOME`, obwohl `HOME` auf `/tmp/alternate-home` zeigt (lokal `storage/app/private/grok-review/placeholder-output.txt`). Eine „Platzhalterumleitung“ ist deshalb keine verfügbare Option dieses Pins. Ein späterer korrigierter Pin ist eine Zukunftsoption, keine gegenwärtige Lösung. Die Optionen sind:

| Option | Konkreter Vertrag und Folgen |
|---|---|
| **B — Empfehlung: beschreibbarer Home-Knoten 01770** | Worker-eigener Home-Knoten mit Sticky Bit und gemeinsamer Gruppe; sämtliche serverseitig erzeugten Einträge einschließlich Konfiguration, Auth, Instruktionen und Sessionlink bleiben worker-eigen und versiegelt. Das Sticky Bit verhindert, dass die Agent-UID diese fremden Einträge entfernt oder ersetzt. Native Ergänzungen müssen vor und nach dem Aufruf in `assertHome` gegen eine am Pin gebundene Vendor-Allowlist geprüft werden — keine pauschale Wildcard für beliebige Home-Inhalte. Der Adapter entfernt seine eigenen Platzhalter, Logs und sonstigen erlaubten Vendor-Dateien nach Prozessende auch bei Fehler/Timeout/Cancel. Ein Pflichtnachweis aus `sandbox-events.jsonl` muss ein frisches, zur Invocation passendes `ProfileApplied` für `ai6-review`, `enforced: true`, `restrict_network: true` und die exakten `deny_paths` belegen. Fehlende oder abweichende Evidenz sperrt das Ergebnis. |
| **A — agent-materialisiertes privates GROK_HOME** | Der Agent baut ein eigenes privates natives Home aus den gebundenen Vorgaben auf. Dafür sind Materialisierung, Eigentum, Integritätsprüfung und Cleanup neu abzugrenzen; die Agent-UID darf servergebundene Konfiguration nicht unbemerkt austauschen. Größerer Eingriff in den gemeinsamen Home-Vertrag. |
| **C — strict ohne Deny** | Beim Pin lauffähige Rückkehr zum bereits geprüften `strict`, aber mit nachgewiesener fremder Auth-/Runtime-Leselücke. AC-12 bleibt unerfüllt. Keine Empfehlung und keine stillschweigende Abschwächung; eine solche Abweichung wäre ausdrücklich zu entscheiden. |
| **D — späterer Pin** | Auf einen Pin mit geeignetem unabhängigem Zustandsverzeichnis warten, anschließend Transport, Discovery, Sandbox, Eigentumsgrenzen und Abnahme neu binden. Heute keine verfügbare Reparatur für 1.0.5. |

**Begründung für B:** Diese Variante nutzt das am Pin beobachtete Verhalten, erhält die Worker-Eigentümerschaft der vertrauenswürdigen Einträge und begrenzt die Änderung auf den Home-Verzeichnisknoten samt kontrolliertem Vendor-Zustand. Sie benötigt weniger neue Materialisierungslogik als A und vermeidet die bekannte Leselücke von C. Das ist eine Empfehlung für die nächste Vertragsentscheidung, keine bereits erteilte Freigabe oder implementierte Ausnahme. Die bisherigen Proben mit beschreibbarem Home belegen noch nicht den vollständigen 01770-/Allowlist-/Cleanup-/Ereignisvertrag. Vor Freigabe sind insbesondere Austausch-/Rename-/Symlink-Angriffe auf versiegelte Einträge und unzulässige neue Discoverydateien negativ zu prüfen.

Verbleibende Residuen:

- Die Linux-Globexpansion erfasst den Startzeitpunkt. Später gestagte Nachbar-Homes benötigen einen eigenen Nachweis oder eine auch für spätere Pfade wirksame Isolation.
- Fremde Promptdateien, Sessions **und Workspaces** sind von den beiden Auth-/Runtime-Globs nicht erfasst. Die Gegenproben müssen diese Eingaben ebenfalls abdecken, ohne eigene Promptdatei, eigenes Sessionziel oder eigenen Workspace zu blockieren.
- Die Agent-UID gilt für die ganze Rolle. Dateimodi trennen deren persistente Ergebnisse nicht gegeneinander. `/proc` ist hinsichtlich gleichzeitig laufender fremder Providerturns dagegen entschärft: `docker/role-process.sh` startet einen Mailboxkonsumenten; `ExecutionMailboxCommand` ruft `AgentExecutionProcessor::processNext()` synchron auf. Im ausgelieferten Einzelkonsumentenbetrieb laufen damit keine parallelen Providerturns dieser Rolle. Das ist kein allgemeiner `/proc`-Leseschutz; mehrere Konsumenten oder zusätzliche Providerprozesse würden diese Annahme aufheben.

AC-12 bleibt vollständig bestehen, seine Coverage bleibt teilweise TC-12 plus offenes MG-01. Keine Statusänderung und keine Gate-Freigabe. Die gewählte Zwischenmaßnahme ist das gebundene, beim aktuellen Pin startverhindernde Profil, ohne Rückfall auf den nachweislich zu breiten Lesebereich.

Die weiteren Findings dieser Runde sind umgesetzt: explizite Modellauswahl wird zusätzlich an `init.model` gebunden; `provider_default` bleibt nativ. `result.num_turns` wird als numerischer Nutzungswert persistiert und kann auch bei unbekannten Token-/Kostenwerten allein eine bekannte Quelle begründen. MG-01 enthält die Kalibrierung gegen `MAX_TURNS`. Die Notes beginnen wieder mit den drei festen Boilerplate-Zeilen und dokumentieren alten → neuen AC-10-Wortlaut sowie die AC-12-Coverage-Änderung. Die native Ausgabe und ihr Extraktortest sind reguläre Fixtures/Tests statt limitwirksamer Ergebnisdateien.

**Verifikation der zweiten Reviewkorrektur:** 107 gezielte Linux-Tests mit 3636 Assertions bestehen, einschließlich Home-, Doctor-, Grok-Transport- und unverändertem Prozessgrenzentest; nur der echte Provider-Smoke bleibt übersprungen. Weitere 142 Vertragsregressionen bestehen. Die drei Dokumentationstests fanden einen beim Bearbeiten entstandenen Kodierungsfehler, der behoben wurde; ihr erneuter Linux-Lauf besteht mit 193 Assertions. Damit sind 252 unterschiedliche Tests der gezielten Linux-Auswahl grün. Ein anfänglicher Feature-Fixturefehler durch erst nach Evidenzerzeugung vergebene Mailboxwurzeln ist behoben; die Evidenz bindet jetzt die endgültigen Testwurzeln. Ein alter, ausschließlich in der Linux-Kopie verbliebener Probeskript-Rest wurde ebenfalls entfernt. Die Produktgrenzen und Testlimits wurden dafür nicht geändert.

Pint, Manifest-Driftcheck, `composer validate --strict`, Detailticket-Validierung und `git diff --check` bestehen. PHPStan wurde zunächst mit geleertem Ergebniscache vollständig ausgeführt, meldete einen überflüssigen Nullsafe-Zugriff im neuen Test und besteht nach dessen Korrektur. Die finalen Dokumentations-/Extraktortests bestehen zusätzlich lokal (vier Tests, 200 Assertions). Keine vollständige reguläre Suite und kein externer Locked Install in dieser Findings-Fixiteration; keine Dependency- oder Plattformänderung dieser Runde. Der native kombinierte Sandbox-/Home-Nachweis bleibt wie oben beschrieben ausdrücklich fehlgeschlagen. Kein Commit, kein Push, keine Status- oder Gateänderung.

### Reviewkorrektur vom 13. September 2026

Der menschliche Auftrag zur Prüfung und Behebung der zwölf Findings erlaubt auch die zugehörige Ticketpräzisierung. Status und Gate-Ergebnisse bleiben unverändert. Die weiter unten aufgeführten Abschlussprüfungen beziehen sich auf den vorherigen Implementierungsstand vom 12. September.

- Die gemeinsame Grok-Discoveryliste enthält exakt `AGENTS.md`, `Agents.md`, `AGENT.md`, `CLAUDE.md`, `Claude.md`, `CLAUDE.local.md`. Exportprojektion und Home-Prüfung verwenden dieselbe Liste; `.github` und `GROK.md` bleiben gewöhnliche Revieweingaben. Root- und verschachtelte Köder werden vor dem Prozessstart abgewiesen.
- Der exakte Aufrufvertrag enthält `--max-turns 16`, `--verbatim` und `--sandbox strict`. Der Extraktor fordert genau eine gebundene `system/init`-Zeile vor dem Resultat, die feste Toolmenge, leere MCP-/Skillmengen, `dontAsk`, den exakten Workspace und null Websuchanfragen. Drift und `error_max_turns` liefern benannte Fehler ohne Antwortübernahme.
- Rein nullzählige/nullwertige Token-Buckets und Kosten von null Dollar bleiben im Streamingformat unbekannt; positive Kosten begründen keine Null-Tokenwerte. Die untererfasste API-Dauer wird nicht gespeichert. Fehler erhalten weiterhin belastbare Nutzungswerte bis zum Providerartefakt, auch bei zusätzlichem Cleanupfehler. `GROK_TOOL_SEARCH` entfällt aus Environment und beiden Allowlists.
- Der Seccomp-Vertrag bindet zusätzlich sämtliche unveränderten Basisregeln an das Checkerprofil. Die Rebase-Historie verweist auf den separat menschlich gesetzten Status `ready`.

**Nicht vollständig behoben: Leseschutz fremder Turns (AC-12).** Der vorhandene Linux-Pin `1.0.5 (5115b46bc9)`, SHA-256 `9ba87444e1819e8f6104adbbf4676a870c204380aa5c3e1c38a926c4ea677238`, wurde ohne externe Netzanbindung gegen einen lokalen synthetischen HTTP-Endpunkt ausgeführt. UID 10002/GID 10001, `CapEff=0`, `NoNewPrivs=1`, die Agent-Seccomp-Datei und Bubblewrap waren aktiv; Kernel war `5.15.167.4-microsoft-standard-WSL2`. Promptdatei in TMPDIR, versiegeltes Home und Sessionumleitung funktionierten mit `strict`, `--verbatim` und begrenzten Turns. Die native Init-/Result-Ausgabe bestätigte die erwartete Oberfläche.

Die anschließende echte `read_file`-Anfrage verwendete das vom Pin gemeldete Argument `target_file`. Sie las erfolgreich einen rein synthetischen fremden Token-Köder: Verzeichnis 0750, Datei 0440, Eigentümer 10001/GID 10001. Der Köder lag außerhalb von Workspace, Home und TMPDIR; derselbe Zugriff gelang sowohl unter `/tmp` als auch unter `/var/lib`. Ein finales Resultat war erfolgreich, der Tool-Rückgabewert enthielt den Köder. Damit ist weder eine bloße Temp-Ausnahme noch ein grünes Init-Ereignis ein ausreichender Isolationsnachweis. Ein belastbarer `sandbox-events`-Nachweis für den geforderten Leseschutz fehlt. Diese Probe lief im separaten Linux-Prüfcontainer, nicht als reale Providerabnahme im ausgelieferten Agentendienst.

Die automatische Capability-Bindung ändert sich durch die Codekorrektur; frühere Evidenzschlüssel passen nicht mehr. Aus der beschriebenen negativen Probe darf keine neue Freigabe entstehen. Benötigt wird eine wirksame OS-Lesebeschränkung für fremde gestagte Homes und anschließend der reale Review-/Verifier-Smoke in der Agentrolle. README, AC-Coverage und MG-01 machen die Lücke ausdrücklich sichtbar. AC-12 wird nicht verkleinert; der bisherige TC-12 prüft nur die Projektion und ihre Bindungen. Die anderen elf Findings sind im Code beziehungsweise in der Dokumentation korrigiert.

**Verifikation dieser Reviewkorrektur:** Die abschließende gezielte Linux-Auswahl `GrokCli|RuntimeDocumentationTest|ScaffoldStructureTest|PhpStanConfigurationTest` besteht mit 108 Tests und 3972 Assertions; nur der reale Provider-Smoke bleibt übersprungen. Im vorherigen breiteren Vertragslauf bestanden 163 Tests; drei Setupfehler durch fehlende Git-/Storage-Metadaten der isolierten Kopie wurden durch Nachtragen der Testumgebung behoben und sind im abschließenden Lauf grün. Die übrigen darin enthaltenen Home-, Prozess-, Profil-, Compose-, Manifest-, Redaction-, Codex- und Importregressionen bestanden bereits. Der zuletzt ergänzte README-Vertrag besteht separat mit drei Tests und 191 Assertions.

Der produktive Extraktor verarbeitet die unveränderte synthetische Ausgabe des echten Pins erfolgreich (ein zusätzlicher Test, sechs Assertions). Sechs ausschließlich in der Linux-Testkopie gesetzte Mutationen an argv, Discovery, Init-Prüfung, Nutzungsfilter, Cleanup und Seccomp-Basis wurden durch die erwarteten Regressionen erkannt; die mutierten Dateien wurden anschließend bytegleich wiederhergestellt. PHPStan mit vorher geleertem Ergebniscache, Pint, Manifest-Driftcheck, `composer validate --strict` und `git diff --check` bestehen. Keine erneute vollständige reguläre Suite und kein externer Locked Install: Dies ist eine Findings-Fixiteration ohne Dependency-/Plattformänderung, keine abgeschlossene Ticketabnahme.

Zusätzlich zum Ticket-File-Scope wurde `tickets/README.md` zur Korrektur der Statusnotiz bearbeitet, wie im menschlichen Reviewauftrag freigegeben. Keine Statusänderung, kein Commit und kein Push. Die unveränderte native NDJSON-Ausgabe liegt jetzt als Fixture unter `tests/Fixtures/Agents/grok-native-events.ndjson`; `tests/Unit/Agents/GrokCliNativeOutputTest.php` prüft sie in der regulären Suite. Die übrigen lokalen Untersuchungsskripte wurden nach `storage/app/private/grok-review/` verschoben. Unter `storage/framework/testing/` verbleiben keine Grok-Probeskripte, damit der unveränderte Ergebnislimit-Test ausschließlich seine eigenen Artefakte bewertet. Die Probe ist keine signierte Abnahme.

Die menschliche Freigabe lautet: „Freigabe hiermit erteilt. Ändere auch den Files-Scope im Ticket.“ Sie umfasst die zuvor konkret vorgeschlagene Sessionumleitung und die erforderliche Linux-Sandbox.

`ExecutionHomeManager` erzeugt ausschließlich für Grok den festen Link `home/sessions → result/grok-sessions`. Das Home und der Link gehören dem Worker und bleiben nach der Übergabe read-only. Die native Konfiguration, leere Hookpfade, Authprojektion und gebundenen Instruktionen werden vorher angelegt. Der Adapter erzeugt das frische Sessionziel selbst mit Modus `0700`; dessen Inhalte sind nur für seine Identität zugänglich. Nach Ende des Kindprozesses entfernt der Adapter seine privaten Sessiondateien. Anschließend führt die gemeinsame Naht den bestehenden Home-Cleanup aus.

Ein exklusiver Startmarker verhindert die Wiederverwendung derselben Invocation. Ein geändertes Linkziel wird abgewiesen, ohne das fremde Ziel zu verändern. Es gibt keine native Fortsetzung und keinen Sessionstore zwischen Turns.

Im Image wird Bubblewrap installiert. Die Agentrolle erhält ein eigenes Seccomp-Profil auf Basis der vorhandenen Moby-29.6.1-Regeln: zusätzlich erlaubt sind ausschließlich der beobachtete `clone`-Flagwert `268566545` und `pivot_root`. `cap_drop: ALL` und `no-new-privileges` bleiben aktiv. Das Checkerprofil bleibt unverändert.

## Ursache und native Vergleichsproben

Untersucht wurde `grok 1.0.5 (5115b46bc9)`.

| Binary | SHA-256 |
|---|---|
| Windows | `4b924daa801663ea20e96382408b1f2b5ba39efad62c14d20d88618a9eb0be64` |
| Linux x86_64 | `9ba87444e1819e8f6104adbbf4676a870c204380aa5c3e1c38a926c4ea677238` |

Die Linux-Probe verwendete Docker 29.6.1 unter WSL, Kernel `5.15.167.4-microsoft-standard-WSL2`. Native Grok-Prozesse liefen als UID 10002/GID 10001 mit `CapEff=0` und `NoNewPrivs=1`. Ein lokaler HTTP-Testserver lieferte synthetische Modellantworten; echte Credentials oder Repositoryinhalte waren nicht beteiligt.

| Vergleich | Ergebnis |
|---|---|
| Beschreibbares Home | Erfolgreiches finales Resultat |
| Nur Sessionverzeichnis read-only | Exit 1, `FS_PERMISSION_DENIED`, kein Modellrequest |
| Gesamtes Home read-only | Derselbe Fehler vor dem Modellrequest |
| Rechte wiederhergestellt | Wieder erfolgreich |
| Versiegeltes Home mit fester Sessionumleitung | Exit 0, genau ein erfolgreiches finales Resultat; unveränderte Home-Hashes und Linkziel; Sessiondateien ausschließlich im Ergebnisverzeichnis |
| Unabhängige Schreib-/chmod-Sonde am versiegelten Home | Beide Zugriffe verweigert |
| Promptdatei über 1 MiB | Native CLI verarbeitet den vollständigen Dateiinput |

Das ältere Runtimeimage enthielt kein Bubblewrap. Docker-Standard-Seccomp und das unveränderte Checkerprofil verweigerten außerdem die beobachtete Namespace-Erzeugung. Eine Syscallspur lokalisierte die beiden benötigten Regeln. Im anschließend gebauten Produktimage wurde Bubblewrap mit der von Grok verwendeten User-/Mount-Namespace-Auswahl und der neuen Agentpolicy erfolgreich gestartet. Eine zusätzliche PID-Namespace-Auswahl ist nicht freigegeben und wurde in der negativen Gegenprobe weiterhin verweigert.

Die anfängliche Windows-/WSL-Nichtverfügbarkeit ist erledigt. Die nativen Vergleichsproben sind vorbereitende technische Evidenz und kein bestandener realer Modell-Smoke.

## Gewählter Transport

Die folgenden Flagangaben dokumentieren den Stand vom 12. September; die Reviewkorrektur oben ersetzt `read-only` durch `strict` und ergänzt `--max-turns 16` sowie `--verbatim`. Der dokumentierte offene Leseschutzbefund bleibt maßgeblich.

Der Adapter verwendet einen einzelnen Headless-Aufruf mit `--prompt-file` und `--output-format streaming-messages-json`. Er wertet die vollständige Ausgabe erst nach erfolgreichem Prozessende aus. Genau ein erfolgreiches finales `result` wird übernommen; Fragmente, Mehrfachantworten und beschädigte Ausgabe führen zu `invalid_json`. Bereits gemeldete Nutzungswerte bleiben dabei erhalten. Ein später Fehlerexit, Timeout, Cancel oder Ausgabelimit bleibt `provider_error`.

Der Pin erhält `--no-auto-update --no-memory --no-subagents --no-plan --disable-web-search`, die Toolmenge `read_file,list_dir,grep`, `--disallowed-tools search_tool,use_tool,Agent`, `--permission-mode dontAsk` und `--sandbox read-only`. Die ergänzende Sperre ist nötig: Die reine Tool-Allowlist ließ in der nativen Probe MCP-Metatools stehen. Die CLI kann intern zusätzlich einen Request zur Sessiontitelbildung ausführen; ein CLI-Turn bedeutet deshalb keine Zusage über genau einen Modellrequest.

Versions- und Discoveryprobe benötigen keine Credentials. Sie prüfen die tatsächlich beobachtete native Oberfläche einschließlich abgeschalteter Kompatibilitätsscanner, leerer Erweiterungen und ausschließlich gebundener Projektinstruktionen. Eine erfolgreiche Probe ersetzt den an Binary, Transport, Home, Policy, Runtimeprofil, Rolle und Auswahl gebundenen menschlichen Schutznachweis nicht.

## Prüfstand

Automatisierte Linux-Tests prüfen den echten AI6-Prozessrunner mit einer deterministischen Fake-Binary, die Produktionsbindung über die Mailbox bis zu Reviewresultat und Providerartefakt, Finding-Verifikation, Fehlerzuordnung, Nutzung, Promptgrenzen, Discoverydrift und Cleanup. Der Promptgrenzentest verwendet das ausgelieferte konfigurierte Maximum einschließlich Wrapper und Mehrbytezeichen.

Image-Build, PHPStan, Pint, Composer-Validierung und Manifest-Driftcheck sind erfolgreich. Der separate externe Locked-Install-Nachweis mit explizitem PHP 8.5 und Composer besteht mit zwei Tests und 1201 Assertions. Die abschließenden regulären Testergebnisse werden im Implementierungsbericht festgehalten.

MG-01 und der reale Grok-Smoke bleiben offen. Das resultfreie Abnahmeformular liegt unter `docs/AI6-041_MG-01_ABNAHMEPROTOKOLL.md`. Die technische Freigabe dieser Änderung ist keine Freigabe realer Modell-/Effortkombinationen.

### Bereits auf der Ausgangsbasis fehlschlagende Linux-Prüfungen

Die folgenden drei Tests scheitern in derselben Linux-Laufzeit auch gegen einen separat aus dem unveränderten Git-HEAD `d50c18d6ff04a0397d9d2e664cb2fd99829703f2` exportierten Stand. Der gezielte Vergleich umfasst drei Tests und 72 Assertions; alle drei Fehler sind identisch zum Implementierungslauf:

| Test | Beobachteter Fehler |
|---|---|
| `ProcessPolicyAndLimitTest::test_process_count_accepts_one_process_and_rejects_one_over_on_linux` | Der Zwei-Prozess-Fall meldet kein `PROCESS_COUNT`-Limit; der Istwert ist `null`. |
| `CheckIsolationTest::test_the_check_process_sees_no_credentials_and_no_variable_outside_the_allowlist` | Im Kindprozess erscheint `PWD` außerhalb der Checker-Allowlist. |
| `PublishCandidateTest::test_the_candidate_preflight_rejects_a_symlink_entry` | Statt `candidate_symlink_forbidden` entsteht bereits `candidate_worktree_drift`. |

Diese Fehler sind nicht als Grok-Regressionen ausgewiesen und wurden weder durch Teständerungen noch durch gelockerte Kontrollen verdeckt. Sie halten das vollständige reguläre Abschlussgate rot. Der Basisvergleich beweist ihre Unabhängigkeit von diesem Diff, nicht ihre Unbedenklichkeit.

Ein zweiter Vergleich mit vollständig auf dem Linux-Dateisystem liegenden Dependencies und der vorhandenen synthetischen Test-`.env` bestätigt zusätzlich zwei fehlschlagende Paralleltests auf unverändertem HEAD: `AgentExecutionMailboxTest::test_two_parallel_agent_consumers_start_one_turn_and_publish_one_result` meldet einen Kindprozess-Exitcode `1` statt `0`; `ProjectQueueStartFeatureTest::test_two_parallel_auto_start_attempts_claim_exactly_one_next_run` scheitert ebenfalls am Kindprozess-Exitcode. Beim Queue-Test trat im ersten Gesamtlauf stattdessen die spätere Abweichung „zwei `none`-Ergebnisse statt eines“ auf. Die beiden untersuchten `SecurityBootstrapTest`-Fälle bestehen in dieser vervollständigten Laufzeit. Ergebnis des zweiten Basisvergleichs: sieben Tests, 162 Assertions, fünf Fehler; die drei zuerst genannten bleiben identisch.

## Akzeptanzkriterien

| Kriterien | Implementierungsstand und Aussagegrenze |
|---|---|
| AC-01–AC-04 | Alias-/Homevertrag, einzelner Promptdatei-Transport, benannte Auswahlfehler und versiegelte Projektion implementiert; automatisierte Adapter-, Doctor- und Home-Evidenz. |
| AC-05–AC-06 | Discovery-, Tool- und Sandboxbegrenzung implementiert; automatische Negativtests und native synthetische Proben vorhanden. Die geforderte reale Grok-Abnahme bleibt Teil von MG-01. |
| AC-07–AC-08 | Review-/Verifierrollen und neue Invocations implementiert; Kontext-, Session- und Credentialbindungen konsumieren die vorhandene gemeinsame Naht. Keine Resume-Funktion angeboten. |
| AC-09–AC-10 | Finale Antwort nach Prozessende, Fehlerzuordnung und tatsächliche Nutzung implementiert; Verbraucher-/Artefakttests prüfen Mailbox und bestätigten reduzierten Direktpfad. |
| AC-11, AC-13 | Promptgrenze einschließlich Wrapper und Cleanup implementiert; neue Tests ergänzen die vorhandenen zentralen Grenz- und Bindungstests. |
| AC-12 | Teilnachweis für minimale Authprojektion und Bindungen; Leseschutz fremder Turns nach Gegenprobe vom 13. September offen. |
| AC-14 | Aktivierbarer Smoke, Doctor, Betriebshinweise und resultfreies Protokoll geliefert; der reale Smoke wurde nicht ausgeführt. |
| AC-15 | Offen: signierte menschliche Abnahme am exakten Implementierungscommit erforderlich. |

Unabhängig von diesen implementierten Kriterien ist die gesamte Definition of Done wegen des roten regulären Abschlussgates nicht erfüllt.

## Ausgeführte Abschlussprüfungen

Der finale gezielte Linux-Lauf besteht mit **141 Tests und 6068 Assertions**; genau ein Test ist absichtlich übersprungen: der echte Provider-Smoke ohne `AI6_RUN_GROK_SMOKE=1`. Der ausgeführte Befehl innerhalb der Linux-Kopie lautet:

```bash
php artisan test --compact --filter 'GrokCli|ExecutionHomeManagerTest|AgentProcessBoundaryTest|AgentProfileRegistryTest|RuntimeComposeContractTest|RuntimeDocumentationTest|ScaffoldStructureTest|ManifestGeneratorTest|RedactionArchitectureTest|PhpStanConfigurationTest|CodexCliExecutionTest|test_the_implementation_turn_starts_with_the_shipped_agent_policy'
```

| Prüfung | Ergebnis |
|---|---|
| `php vendor/bin/pint --test` | Bestanden |
| `php vendor/bin/phpstan analyse --no-progress` | Keine Fehler; nach dem letzten Nutzungsdaten-Fix erneut ausgeführt |
| `php scripts/generate-ticket-manifest.php --check` | Manifest aktuell |
| `composer validate --strict` | Gültig |
| `git diff --check` | Keine Whitespacefehler |
| `php vendor/bin/phpunit tests/Unit/LockedInstallTest.php` mit explizitem `AI6_PHP85_BINARY=C:\php\php.exe` und `AI6_COMPOSER_PHAR=C:\ProgramData\ComposerSetup\bin\composer.phar` | Zwei Tests, 1201 Assertions bestanden |
| Docker-Build für `ai6-grok-verification:20260912` | Erfolgreich; Bubblewrap und native Grok-Version zusätzlich im gebauten Image geprüft |

Für die Linux-Testkopie wurden Quellstand und Dependencies auf das native Dateisystem übertragen und die vorhandene synthetische `tests/.env` übernommen. Zwischenzeitliche Setupfehler durch fehlende Dateien, die Bibliotheks-Sicherung innerhalb der Testkopie und den doppelten Composer-Autoloader wurden behoben; Tests und Kontrollen wurden dafür nicht gelockert.

Der vollständige Lauf mit `php artisan test --compact --display-warnings` beendet **1838 Fälle mit 61322 Assertions**: sechs Fehler, 544 Warnungen, 127 übersprungene und 1161 bestandene Tests. Fünf Fehler sind die oben gegen HEAD geprüften Linux-Fälle. Der sechste war `PhpStanConfigurationTest`: Die vorübergehend innerhalb der Testkopie abgelegte Bibliotheks-Sicherung enthielt Baseline-Dateinamen und wurde anschließend aus der Repositorykopie entfernt; der unveränderte Test besteht danach. Sämtliche 544 Warnungen betrafen die zu Laufbeginn noch fehlende synthetische `tests/.env`. Der abschließende gezielte Lauf enthält diese korrigierte Testumgebung sowie die zuletzt ergänzten Nutzungsdatenfälle und ist ohne Warnungen grün. Der komplette reguläre Lauf wird deshalb ausdrücklich **nicht** als bestanden gewertet.

## Geänderte Dateien

Der ausdrücklich freigegebene `files`-Scope und die Scope-Marker in `tickets/AI6-041.md` enthalten diese Dateien. Die bestehenden Nutzeränderungen an `tickets/README.md` sind keine Änderung dieser Implementierung.

| Datei | Zweck |
|---|---|
| `app/AI6/Agents/GrokCliAdapter.php` | Gepinnter Headless-Transport, Auswahl-/Discoveryprüfung, finale Antwort und Nutzung, privater Session-Cleanup. |
| `app/AI6/Agents/GrokCliConfiguration.php` | Typisierte Konfiguration, native Einstellungen und gebundener Capability-Nachweis. |
| `app/AI6/Agents/ExecutionHomeManager.php` | Grok-Projektion, versiegelte Einstellungen und fester Sessionlink. |
| `app/AI6/Shared/AI6ServiceProvider.php` | Adapteralias, Konfiguration und Doctor registrieren. |
| `app/AI6/Shared/Doctor/GrokCliDoctorCheck.php` | Credentialfreie Versions-/Discoveryprobe und getrennte Nachweisprüfung. |
| `config/ai6.php` | Grok-Konfiguration, erlaubtes Binary/Environment und zwei unterstützte Rollen. |
| `.env.example` | Die drei Grok-Transportvariablen dokumentieren. |
| `tests/Unit/Agents/GrokCliAdapterTest.php` | Transport-, Grenzwert-, Fehler-, Discovery-, Verifier- und Cleanuptests. |
| `tests/Unit/Agents/GrokCliConfigurationTest.php` | Konfigurationsfehler, Auswahlgründe und Evidenzbindung. |
| `tests/Unit/Agents/AgentProfileRegistryTest.php` | Unterstützte Grok-Rollen festhalten. |
| `tests/Unit/Agents/ExecutionHomeManagerTest.php` | Sessionlink, Versiegelung und Discoveryprojektion prüfen. |
| `tests/Feature/Agents/GrokCliExecutionTest.php` | Produktionsbindung bis zu Reviewresultat und Nutzungsartefakt; Mailbox und reduzierter Direktpfad. |
| `tests/Feature/Agents/CodexCliExecutionTest.php` | Zentrales Adapterinventar aktualisieren; unbekannte Aliase bleiben abgewiesen. |
| `tests/Feature/Git/ImplementationImportIsolationTest.php` | Vollständiges Provider-Executable-Inventar unter unveränderter Isolation prüfen. |
| `tests/Feature/Agents/GrokCliSmokeTest.php` | Explizit aktivierbarer realer Review-/Verifier-Smoke mit Testauthprojektion. |
| `tests/Fixtures/Agents/fake-grok.php` | Deterministisches Prozessdouble für native Oberfläche, Endzustände und Nutzung. |
| `tests/Feature/Shared/Doctor/GrokCliDoctorCheckTest.php` | Nicht eingerichteten Provider und fehlenden echten Schutznachweis prüfen. |
| `tests/Unit/ScaffoldStructureTest.php` | Neue Produktionsklassen im vorhandenen Architektur-Inventar aufnehmen. |
| `tests/Feature/Shared/Runtime/RuntimeDocumentationTest.php` | Dokumentierten Grok-Vertrag festhalten. |
| `README.md` | Betrieb, Transportgrenzen, Variablen, Smoke und offenes Gate. |
| `docs/AI6-041_MG-01_ABNAHMEPROTOKOLL.md` | Ergebnisfreie menschliche Abnahmevorlage. |
| `tests/Fixtures/Agents/BuildsGrokHome.php` | Isolierte Homes und Testauth über die bestehende Materialisierung erzeugen. |
| `tests/Fixtures/Agents/FakeGrokBinary.php` | Explizites ausführbares CLI-Prozessdouble anlegen. |
| `tests/Unit/Shared/Runtime/RuntimeComposeContractTest.php` | Agentpolicy, zusätzliche Syscalls und minimale Environment-Erweiterung prüfen. |
| `tests/Unit/Agents/AgentProcessBoundaryTest.php` | Erweitertes Binary-Inventar bei unveränderter Credentialtrennung prüfen. |
| `Dockerfile` | Bubblewrap aus dem vorhandenen Debian-Snapshot installieren. |
| `docker-compose.yml` | Agent-Seccomp binden; Grok-Konfiguration an Worker/Agent übergeben. |
| `docker/agent-seccomp-moby-29.6.1.json` | Eigene Agentpolicy mit eng begrenzter Namespace-Erweiterung. |
| `docs/AI6_IMPLEMENTATION_PLAN.md` | Freigegebene Grok-Ausnahme in Revision V1.7.8 normativ festhalten. |
| `docs/AI6_TICKET_MANIFEST.yaml` | Deterministischen Export an die Planrevision anpassen. |
| `docs/AI6-041_TRANSPORT_ENTSCHEIDUNGSANFRAGE.md` | Entscheidung, native Proben, Implementierungsumfang und Prüflücken dokumentieren. |

## Quellen und Reproduktion

- [Hersteller: Headless und Scripting](https://docs.x.ai/build/cli/headless-scripting)
- [Hersteller: CLI-Referenz](https://docs.x.ai/build/cli/reference)
- [Hersteller: Einstellungen](https://docs.x.ai/build/settings/reference)
- [Hersteller: Sandbox](https://docs.x.ai/build/features/sandbox)
- Native Hilfe, `inspect --json` und die vom gepinnten Binary bereitgestellten Anleitungen.

Die lokalen Untersuchungshilfen liegen unter `C:\Users\Michael\AppData\Local\Temp\ai6-grok-linux-probe`: `probe.py`, `probe-trace.py`, `probe-session-redirect.py`, `probe-inspect.py` und `probe-input.py`. Sie verwenden ausschließlich synthetische Endpunkte und temporäre Testpfade. Temporäre Dateien sind kein dauerhaftes Abnahmeprotokoll.
