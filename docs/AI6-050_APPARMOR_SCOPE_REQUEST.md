# AI6-050 – Scope-/Vertragsanfrage für verschachtelte Turn-Homes

Status der Anfrage: Am 29. September 2026 im Chat ausdrücklich freigegeben
(„Freigabe hiermit erteilt“). Die Freigabe umfasst die nachstehenden
Repository-Änderungen und isolierten Tests, keine Auslieferung auf die Live-Instanz.

Umgesetzt und isoliert verifiziert: vier Regeln im Repository-Profil,
Runner-/Profilvertragstests, nativer Lauf ohne Patch und verweigerte
Root-Bindung. Ergebnis: 113 Tests bestanden, drei externe Opt-in-Smokes
übersprungen, 10939 Assertions. Details und Bindung in
`AI6-050_LINUX_VERIFICATION.md`. Keine Änderung des geladenen Live-Profils.

## Befund vor der Korrektur

`AgentExecutionRunner::prepare()` erzeugt Eingabe und Ausgabe unter
`execution-<32hex>/<home>/`. `AgentExecutionRequest` prüft die 32 Hexzeichen und
bindet das Verzeichnis an die Execution-ID. Das ausgelieferte Profil
`docker/apparmor/ai6-execution` erlaubt derzeit nur Homes direkt unterhalb der
Mailboxwurzel. AppArmor verweigert daher die verschachtelte Projektion realer
Mailbox-Turns. Betroffen sind auch AI6-048 und AI6-050/AC-01; MG-01 bleibt offen.

## Konkreter Freigabegegenstand

Im Agentprofil unmittelbar nach den vier bisherigen Home-Regeln folgende
vier Regeln samt erläuterndem Kommentar ergänzen, identisch zum geprüften
`tests/Fixtures/Agents/container/apparmor-turn-homes.patch`:

```text
  # AgentExecutionRunner stages a turn home below its execution-id directory.
  mount options=(rw,rbind) /oldroot/var/lib/ai6/agent-executions/execution-*/*-*-*/ -> /newroot/var/lib/ai6/agent-executions/execution-*/*-*-*/,
  mount options=(ro,nosuid,nodev,noexec,remount,bind,silent,relatime) -> /newroot/var/lib/ai6/agent-executions/execution-*/*-*-*/,
  mount options=(rw,rbind) /oldroot/var/lib/ai6/agent-outputs/execution-*/*-*-*/ -> /newroot/var/lib/ai6/agent-outputs/execution-*/*-*-*/,
  mount options=(rw,nosuid,nodev,noexec,remount,bind,silent,relatime) -> /newroot/var/lib/ai6/agent-outputs/execution-*/*-*-*/,
```

Dies erweitert die erlaubten Mount-Pfade um die serverseitig erzeugte zweite
Ebene. Eingaben werden weiterhin read-only projiziert; Ausgaben bleiben
beschreibbar und noexec/nosuid/nodev. Der Profil-Glob ersetzt nicht die genaue
32-Hex-Prüfung des Requestvertrags. Mailboxwurzeln und vollständige Root-Mounts
werden damit nicht freigegeben. Die nativen Label-/Mountprüfungen, Seccomp,
Instruktionsbindung und übrigen AppArmor-Regeln bleiben erhalten.

## Freigegebener und ausgeführter Umfang

1. Die vier Regeln in `docker/apparmor/ai6-execution` übernehmen.
2. In `tests/Feature/Agents/AgentExecutionMailboxTest.php` die vom tatsächlichen
   Runner erzeugten Eingabe-/Ausgabepfade und ihre Bindung an `request.home`
   prüfen. Den Zusammenhang mit den erlaubten Profilpfaden im bestehenden
   `tests/Unit/Shared/Runtime/RuntimeComposeContractTest.php` absichern.
3. In `tests/Fixtures/Agents/container/run.sh` die Patch-Anwendung entfernen.
   Der Linux-Lauf lädt nur noch die unveränderte Repository-Profilkopie in
   seinen separaten Test-Namespace. Den alten Testpatch als historischen
   Nachweis belassen, aber nicht mehr ausführen.
4. Profil mit `apparmor_parser` prüfen; Vertragstests und den dokumentierten
   nativen Linux-Lauf ausführen. Zusätzlich die weiterhin verweigerte
   vollständige Root-Bindung prüfen. Image-, Quell- und Profilbindung sowie
   Kommando und Testzahlen aktualisieren.
5. README und `docs/AI6-050_LINUX_VERIFICATION.md` an den belegten Stand
   anpassen. Eine noch ausstehende Auslieferung auf der Live-Instanz weiterhin
   ausdrücklich nennen.

Die früheren 94 bestandenen Tests mit dem separaten Profilentwurf waren
Vorabevidenz. Der erneute Lauf ohne Patch-Anwendung ist jetzt dokumentiert.

## Grenze der angefragten Freigabe

Angefragt sind Repository-Änderungen und isolierte VPS-Tests. Ein Reload der
produktiven AppArmor-Profile, ein Deployment, ein Commit, Push oder das
Schließen manueller Gates ist nicht Gegenstand dieser Freigabe. AGENTS.md §7
und der aktuelle Auftrag verlangen für den sensiblen Pfad die ausdrückliche
menschliche Entscheidung vor der Übernahme.
