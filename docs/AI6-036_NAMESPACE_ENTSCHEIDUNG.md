# AI6-036 — Zusätzliche OCI-/AppArmor-Entscheidung

Stand: 28. September 2026. Der Mensch hat nach dem Quelltransfer und den eigenen AppArmor-Profilen auch den hier beschriebenen zusätzlichen OCI-Scope ausdrücklich freigegeben („Freigabe hiermit erteilt.“). Der folgende Antragstext dokumentiert den freigegebenen Umfang; Umsetzung und Nachweise stehen im Prüfbericht. Kein Gate ist damit abgenommen.

## Nachgewiesene Ursache

Mit dem neuen, ausschließlich im Wegwerfcontainer verwendeten `ai6-checker-v1` besteht `unshare --user --map-root-user --mount --pid --fork /usr/bin/id`. Die zuvor an `docker-default` gescheiterte Mount-Propagation ist damit behoben. Ein nicht freigegebener tmpfs-Mount nach `/etc` wird vom neuen Profil ausdrücklich verweigert; der Kernel protokolliert `failed mntpnt match` für `ai6-checker-v1`.

Mit dem zusätzlich erforderlichen `--mount-proc` scheitert derselbe Container jetzt mit `unshare: mount /proc failed: Operation not permitted`; der Kernel protokolliert `VFS: Mount too revealing`. Das ist eine andere Kontrolle als AppArmor: Der Kernel verhindert, dass ein unprivilegierter User-Namespace die geerbten gesperrten Docker-Procmasken durch ein neues procfs sichtbar macht. Weitere AppArmor-Mountfreigaben lösen diese Verweigerung nicht.

Dasselbe wurde für Bubblewrap 0.8.0 mit den ausgelieferten Namespace-Flags `--unshare-user --unshare-pid --unshare-ipc --unshare-uts` und unverändertem Agent-Seccomp-Profil bestätigt: Nach den eng zugelassenen `/usr`-Bind-/Readonly-Mounts scheitert `--proc /proc` mit `Can't mount proc on /newroot/proc: Operation not permitted` und derselben VFS-Meldung. Ein Vorversuch mit nur User-/PID-Flags wurde bereits von der bestehenden exakten Seccomp-Allowlist verweigert; dieser Vorversuch gilt nicht als Produktpfad.

Die tatsächliche Docker-Konfiguration des unveränderten Checkers bindet:

- Verdeckte Pfade: `/proc/acpi`, `/proc/asound`, `/proc/interrupts`, `/proc/kcore`, `/proc/keys`, `/proc/latency_stats`, `/proc/sched_debug`, `/proc/scsi`, `/proc/timer_list`, `/proc/timer_stats`, `/sys/devices/virtual/powercap`, `/sys/firmware`.
- Schreibgeschützte Pfade: `/proc/bus`, `/proc/fs`, `/proc/irq`, `/proc/sys`, `/proc/sysrq-trigger`.

## Konkreter zusätzlicher Scope

Als getrennt freizugebenden Lösungsversuch ausschließlich für `agent` und `checker` die OCI-Pfadmaskierung durch mindestens gleichwertige AppArmor-Zugriffsverbote ersetzen. Technisch würde neben den bereits freigegebenen `apparmor=ai6-*-v1`-Bindungen `systempaths=unconfined` in deren `security_opt` hinzukommen. Diese Option wird **nicht allein** verwendet: Zuvor muss das enforce-Profil jeden bisher verdeckten Pfad einschließlich Unterpfaden für Lesen, Schreiben, Ausführen und Verlinken sperren sowie alle bisherigen Readonly-Pfade gegen Schreiben und Links schützen. Die Mount-Allowlist bleibt geschlossen; sie darf keine Umbenennung oder Bindprojektion geschützter Inhalte an erlaubte Ziele ermöglichen.

Die vorgeschlagene Verlagerung ist noch kein nachgewiesener Ersatz: Ein versteckter Pfad und ein durch LSM verweigerter Zugriff sind nicht in allen Metadatenbeobachtungen identisch. Deshalb braucht sie eine ausdrückliche menschliche Sicherheitsentscheidung und eigenständige Negativnachweise. `docker-default`, andere Rollen, Host-Capabilities, Seccomp, Readonly-Root, Credentialtrennung und Netzwerkisolation bleiben unverändert. Es gibt keinen Rückfall auf ein unconfined-AppArmor-Profil.

Betroffene Dateien: `docker/apparmor/ai6-container-base`, `docker/apparmor/ai6-execution`, `docker-compose.yml`, `tests/Unit/Shared/Runtime/RuntimeComposeContractTest.php`, `tests/Feature/Shared/Runtime/RuntimeComposeSmokeTest.php`, README, Ticketprosa und Prüfbericht. Zum Zeitpunkt des Antrags waren die Profile noch nicht in Compose aktiviert und die Agent-Mountregeln noch nicht vollständig verifiziert. Der abschließende Nachweis nach der Freigabe steht unten und im Prüfbericht.

## Vor einer Fertigmeldung erforderliche Nachweise

1. Parserprüfung und tatsächlich geladene enforce-Profile; keine zusätzlichen Host-Capabilities und keine geänderten globalen Profile.
2. Je bisher verdecktem beziehungsweise schreibgeschütztem Pfad Zugriff durch beide Rollen verweigert; dieselben Prüfungen nach Namespace-Erzeugung und nach möglichen Bind-/Pivot-Umwegen.
3. Fremde tmpfs-/Bind-Mounts, Mounts von Supervisor-/Credentialzustand und Writable-Remounts geschützter Inhalte verweigert.
4. Der unveränderte Checker-Wrapper führt eine reale Prüfung vollständig aus; das Nutzprogramm besitzt keine verbleibenden Namespace-Capabilities und erreicht weder Mailbox noch Heartbeat oder Netzwerk.
5. Die echte `AgentProcessScope`-Projektion funktioniert mit versiegeltem Home und ausschließlich den vorgesehenen Outputs; fremde Turns und Supervisorzustand bleiben unerreichbar. Dies umfasst auch die serverseitigen Einzelpfade `projection-*`, `login-*` samt read-only `auth.json` und `probe-*/inputs|outputs/doctor-new-*`; die gesamte private Wurzel bleibt als Mountquelle gesperrt.
6. Vollständiger realer Compose-Smoke einschließlich Worker-Doctor mit `Ticketmanifest: OK`; betroffene Vertrags- und Architekturtests bestanden. MG-01 bleibt eine separate menschliche Abnahme.

Historischer Teilnachweis nach der ausdrücklichen Freigabe: Der Linux-Smoke mit 1 Test und 200 Assertions belegt die gekoppelte Compose-Bindung, den Ersatzschutz und den credentialfreien Agent-Turn. Er belegt noch nicht die Login-, Probe- und Credentialprojektion aus Nachweis 5. Diese Lücke wurde im weiteren Review erkannt und blieb bis zum erweiterten realen Smoke ausdrücklich offen.

Ergänzender technischer Nachweis vom 28. September 2026: Das bisherige Profil verweigert im erweiterten Smoke tatsächlich den `projection-*`-Bind (`private-projections-smoke-before-2.log`). Nach Ergänzung ausschließlich der privaten Einzelquellen und ihrer Remounts bestehen Parserprüfung und vollständiger Smoke mit **1 Test/210 Assertions** (`private-projections-smoke-after.log`). Die echten Scope-Erzeuger für Credential-Projektion, temporären Login mit und ohne versiegelte `auth.json` sowie Probe-Home führen PHP über den unveränderten `AgentProcessScope` und `ControlProcessRunner` aus. Synthetische Credentials, Readonly-/Writable-Prüfungen, unsichtbarer Supervisorzustand und verweigerte Binds der ganzen privaten Wurzel sichern Nachweis 5 ab. Kein externer Providerlogin wird damit behauptet: Die temporäre Login-Grenze wird ohne OAuth-Aufruf ausgeführt. Details und Rohprotokollbindung stehen im Abschnitt „Nachreview: Private Provider-Projektionen“ des Prüfberichts. Die menschliche MG-01-Abnahme bleibt unabhängig davon offen.

Quellen zur Kernelentscheidung: [Linux VFS `mount_too_revealing`](https://kernel.googlesource.com/pub/scm/linux/kernel/git/vfs/vfs/+/6755e89516180351960f2c8440c02f162455450e/fs/namespace.c), [AppArmor-Mount- und Pivot-Regeln](https://manpages.debian.org/unstable/apparmor/apparmor.d.5.en.html).
