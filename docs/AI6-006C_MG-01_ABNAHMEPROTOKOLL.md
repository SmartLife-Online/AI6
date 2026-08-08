# Abnahmeprotokoll AI6-006C / MG-01 — Deploy-Key-Rechte und Mountmatrix

Leeres Formular.

## 0. Vorbereitung

Alle `docker compose`-Befehle dieses Protokolls laufen **im Repository-Root**, weil dort
`docker-compose.yml` liegt. Compose sucht die Datei im aktuellen Verzeichnis und in dessen
Elternverzeichnissen; ein Aufruf außerhalb des Repositoryzweigs endet mit
`no configuration file provided: not found`. Das veraltete `docker-compose` mit Bindestrich
sucht gar nicht nach oben und ist hier nicht zu verwenden.

Die Befehle sind für zwei Shells angegeben. **POSIX** meint Linux, macOS oder Git Bash unter
Windows; **PowerShell** meint Windows PowerShell 5.1, das weder `&&` noch `grep`, `sha256sum`
oder Unix-`curl` kennt — dort ist `curl` ein Alias für `Invoke-WebRequest` und lehnt die
Unix-Flags mit `Fehlendes Argument für den Parameter "SessionVariable"` ab. Wo kein Unterschied
besteht, steht der Befehl nur einmal.

Arbeitsverzeichnis, Compose-Datei und Projektname prüfen — POSIX:

```
docker compose config --quiet && docker compose ls
```

PowerShell:

```
docker compose config --quiet; if ($?) { docker compose ls }
```

Die Spalte `CONFIG FILES` zeigt die tatsächlich verwendete Compose-Datei, `NAME` den
Projektnamen.

Wird aus einem anderen Verzeichnis gearbeitet, ist die Datei explizit anzugeben und jedem
Befehl dieses Protokolls voranzustellen: `docker compose --file <Pfad>/docker-compose.yml
--project-name <Projektname> …`.

Der Compose-Projektname ist unter Abschnitt 2 zu vermerken; ohne `--project-name` und ohne
`COMPOSE_PROJECT_NAME` leitet Compose ihn kleingeschrieben aus dem Verzeichnisnamen ab, hier
also `ai6`.

Wichtig: Der Stack der automatisierten Compose-Tests existiert nach deren Lauf nicht mehr — sie
verwenden einen eigenen, zufälligen Projektnamen und räumen ihn im Teardown mit
`down --volumes` ab. Für die Abnahme ist ein eigener Stack zu starten:

```
docker compose up -d --build
```

Voraussetzung dafür ist eine ausgefüllte `.env`: Compose startet die PHP-Rollen mit
`APP_ENV=production`, deshalb müssen `AI6_REDACTION_ACTIVE_KEY_ID` und ein nicht leerer,
versionierter `AI6_REDACTION_KEYS`-Ring gesetzt sein (Format siehe `README.md`). Fehlt der
Ring, brechen `app`, `worker` und `scheduler` beim Bootstrap ab und `caddy` meldet
`dependency app failed to start`.

## 1. Bindung

Die Evidenz ist an genau einen Stand gebunden. Zulässig ist der geprüfte Git-Commit oder —
vor einem Commit — Base-Commit **und** SHA-256 des vollständigen Prüfdiffs.

Commit-Bindung ermitteln:
570141cc6a2db7a889fdddd95a4c61aec886073a
```
git rev-parse HEAD
```

Diffbindung ermitteln, falls noch nicht committed wurde. Die Optionen sind gepinnt, damit der
Hash auf einer anderen Maschine reproduzierbar bleibt; `git add -A -N` macht neue Dateien im
Diff sichtbar, verändert den Worktree nicht und wird mit `git reset` zurückgenommen:

POSIX:

```
git add -A -N && git -c core.abbrev=40 -c diff.algorithm=myers -c diff.renames=false diff --binary --no-color --no-ext-diff --unified=3 --output=../ai6-checkdiff.patch HEAD && git reset -q && sha256sum ../ai6-checkdiff.patch
```

PowerShell:

```
git add -A -N; git -c core.abbrev=40 -c diff.algorithm=myers -c diff.renames=false diff --binary --no-color --no-ext-diff --unified=3 --output=..\ai6-checkdiff.patch HEAD; git reset -q; (Get-FileHash ..\ai6-checkdiff.patch -Algorithm SHA256).Hash.ToLower()
```

Drei Details sind für die Reproduzierbarkeit zwingend und wurden in beiden Shells gegen
denselben Hash geprüft: Die Diffoptionen sind gepinnt; der Patch wird von Git selbst über
`--output=` geschrieben, weil eine Shellumleitung unter PowerShell UTF-8 mit BOM erzeugt und
den Hash verfälscht; und der Patch liegt **außerhalb** des Repositorys, weil ihn `git add -A -N`
sonst als neue Datei erfasst und der Diff sich selbst enthielte. `git reset -q` nimmt die
`intent-to-add`-Markierungen anschließend zurück.

| Feld | Wert |
|---|---|
| Bindungsart | Base-Commit + Prüfdiff erforderlich; technische Messung noch nicht vollständig gebunden |
| Commit beziehungsweise Base-Commit | 570141cc6a2db7a889fdddd95a4c61aec886073a |
| SHA-256 des Prüfdiffs | |
| Anlage `checkdiff.patch` beigefügt | nein |

## 2. Laufzeit

| Feld | Wert |
|---|---|
| Prüfer | Codex (technische Testausführung; keine menschliche Abnahme) |
| Zeitpunkt (ISO 8601 mit Zeitzone) | 2026-08-08T23:12:49.4251609+02:00 |
| Host und Betriebssystem | MICHAEL-PC — Microsoft Windows NT 10.0.26200.0 |
| Docker-Version | 29.6.1 |
| Compose-Projektname | ai6 |
| Sicherheitsprofil (`AI6_SECURITY_PROFILE`) | strict |

## 3. Auslösung

Die Provisionierung wird über die Weboberfläche ausgelöst, nicht über einen Artisan-Aufruf:
Das Gate prüft den realen Weg einschließlich Policy, Step-up und Queue.

| Feld | Wert |
|---|---|
| Projekt (Name und `project_identifier`) | AI6-Test (`c571a178d657ae9dfeb123501ac05741`) |
| Operation-ID | 621ebc5f-2c1b-44bb-b397-1278858d5e3e |
| Auslösender Actor | Michael (`info@smartlife-online.de`) |
| Endzustand der Operation | `completed` / `attempt_completed`; Ergebnis `succeeded` |

## 4. Messungen

Jede Zeile wird mit der **wörtlichen Rohausgabe** gefüllt, nicht mit einer Zusammenfassung.

### 4.1 Rechte des privaten Schlüssels

```
docker compose exec -T worker sh -c 'ls -lnR /var/lib/ai6/managed/deploy-keys'
```

| Erwartung | Gemessener Wert | Ergebnis |
|---|---|---|
| Verzeichnis `<project_identifier>` gehört `10001 10001`, Modus `drwx------` | `<pre>drwx------ 2 10001 10001 4096 Aug  8 21:11 c571a178d657ae9dfeb123501ac05741</pre>` | technisch bestanden |
| `id_ed25519` gehört `10001 10001`, Modus `-rw-------` | `<pre>-rw------- 1 10001 10001 529 Aug  8 21:11 id_ed25519</pre>` | technisch bestanden |
| `id_ed25519.pub` gehört `10001 10001`, Modus `-rw-r--r--` | `<pre>-rw-r--r-- 1 10001 10001 187 Aug  8 21:11 id_ed25519.pub</pre>` | technisch bestanden |

### 4.2 Mountmatrix je Rolle

POSIX:

```
for s in app worker scheduler agent checker init; do printf '%s: ' "$s"; docker inspect --format '{{range .Mounts}}{{.Name}}:{{.Destination}}:{{if .RW}}rw{{else}}ro{{end}} {{end}}' "$(docker compose ps -aq $s)"; done
```

PowerShell:

```
foreach ($s in 'app','worker','scheduler','agent','checker','init') { "$s : " + (docker inspect --format '{{range .Mounts}}{{.Name}}:{{.Destination}}:{{if .RW}}rw{{else}}ro{{end}} {{end}}' (docker compose ps -aq $s)) }
```

| Rolle | Erwartung für `ai6_managed` | Gemessener Wert | Ergebnis |
|---|---|---|---|
| `app` | nicht gemountet | `<pre>ai6_ai6_storage:/opt/ai6/storage:rw ai6_ai6_executions:/var/lib/ai6/executions:ro ai6_ai6_database:/var/lib/ai6/database:rw :/tmp:rw</pre>` | technisch bestanden |
| `worker` | gemountet, `rw` | `<pre>ai6_ai6_database:/var/lib/ai6/database:rw ai6_ai6_storage:/opt/ai6/storage:rw ai6_ai6_executions:/var/lib/ai6/executions:rw ai6_ai6_managed:/var/lib/ai6/managed:rw :/run/ai6/heartbeat/worker:rw :/tmp:rw</pre>` | technisch bestanden |
| `scheduler` | nicht gemountet | `<pre>ai6_ai6_database:/var/lib/ai6/database:rw ai6_ai6_storage:/opt/ai6/storage:rw :/tmp:rw :/run/ai6/heartbeat/scheduler:rw</pre>` | technisch bestanden |
| `agent` | nicht gemountet | `<pre>:/tmp:rw :/run/ai6/heartbeat/agent:rw</pre>` | technisch bestanden |
| `checker` | nicht gemountet | `<pre>:/run/ai6/heartbeat/checker:rw :/tmp:rw</pre>` | technisch bestanden |
| `init` | gemountet, `rw` | `<pre>ai6_ai6_database:/var/lib/ai6/database:rw ai6_ai6_storage:/opt/ai6/storage:rw ai6_ai6_managed:/var/lib/ai6/managed:rw :/tmp:rw</pre>` | technisch bestanden |

### 4.3 Einmaliger Dienst `init` ist beendet

```
docker compose ps -a --format 'table {{.Service}}\t{{.Status}}\t{{.ID}}'
```

| Erwartung | Gemessener Wert | Ergebnis |
|---|---|---|
| `init` steht auf `exited (0)` | `<pre>init        Exited (0) 29 minutes ago   f3d0986cf85c</pre>` | technisch bestanden |
| Die fünf dauerhaften Dienste laufen und sind `healthy` | `<pre>agent       Up 25 hours (healthy)<br>app         Up 29 minutes (healthy)<br>caddy       Up 2 days (healthy)<br>checker     Up 25 hours (healthy)<br>scheduler   Up 25 hours (healthy)<br>worker      Up 29 minutes (healthy)</pre>` | technisch bestanden; zusätzlich ist Caddy als sechster dauerhafter Dienst healthy |

### 4.4 Kein privates Schlüsselmaterial in Log und Antwort

POSIX:

```
docker compose logs --no-color | grep -c -- '-----BEGIN'
```

PowerShell:

```
(docker compose logs --no-color | Select-String -SimpleMatch '-----BEGIN').Count
```

| Erwartung | Gemessener Wert | Ergebnis |
|---|---|---|
| Trefferzahl im Containerlog ist `0` | `<pre>0</pre>` | technisch bestanden |
| Die HTTP-Antwort der Auslösung enthält keinen privaten Teil | `<pre>Ergebnis: succeeded<br>Zusammenfassung: Deploy-key provisioning completed.</pre>` | technisch bestanden |
| Die Projektansicht zeigt ausschließlich den öffentlichen Schlüssel | `<pre>Provisionierungszustand: provisioned<br>Öffentlicher Deploy-Key: ssh-ed25519 …</pre>` | technisch bestanden |

## 5. Ergebnis

| Feld | Wert |
|---|---|
| Gesamtergebnis | nicht abgeschlossen |
| Abweichungen und Vorbehalte | Die technischen Messungen sind erfolgreich. Die Bindung bleibt offen: Die laufzeitrelevante lokale `.env` ist ignoriert und deshalb nicht Teil eines vollständigen Git-Prüfdiffs. Die menschliche Abnahme und Unterschrift stehen aus. |
| Anlagen | keine; `checkdiff.patch` fehlt |
| Unterschrift | |

## 6. Gültigkeit

Die Evidenz verfällt, sobald sich der gebundene Stand ändert. Jede Änderung an
`docker-compose.yml`, `docker/entrypoint.sh`, `config/ai6.php`, dem verwalteten Root oder der
Schlüsselablage macht ein an den Vorgängerstand gebundenes Protokoll stale; das Gate ist dann
erneut abzunehmen. Ein offenes oder stale Gate blockiert nach `RUN-009` Candidate, finalen
Commit und Push.
