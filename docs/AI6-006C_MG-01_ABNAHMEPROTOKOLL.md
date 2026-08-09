# Abnahmeprotokoll AI6-006C / MG-01 — Deploy-Key-Rechte und Mountmatrix

Ergebnisfreie Vorlage. Bindung, Messwerte, Ergebnis und Unterschrift werden ausschließlich
von der menschlichen Prüfperson eingetragen.

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
| Bindungsart | Commit / Base-Commit + Prüfdiff |
| Commit beziehungsweise Base-Commit | |
| SHA-256 des Prüfdiffs | |
| Anlage `checkdiff.patch` beigefügt | ja / nein / entfällt |

## 2. Laufzeit

| Feld | Wert |
|---|---|
| Prüfer | |
| Zeitpunkt (ISO 8601 mit Zeitzone) | |
| Host und Betriebssystem | |
| Docker-Version | |
| Compose-Projektname | |
| Sicherheitsprofil (`AI6_SECURITY_PROFILE`) | |

## 3. Auslösung

Die Provisionierung wird über die Weboberfläche ausgelöst, nicht über einen Artisan-Aufruf:
Das Gate prüft den realen Weg einschließlich Policy, Step-up und Queue.

| Feld | Wert |
|---|---|
| Projekt (Name und `project_identifier`) | |
| Operation-ID | |
| Auslösender Actor | |
| Endzustand der Operation | |

## 4. Messungen

Jede Zeile wird mit der **wörtlichen Rohausgabe** gefüllt, nicht mit einer Zusammenfassung.

### 4.1 Rechte des privaten Schlüssels

```
docker compose exec -T worker sh -c 'ls -lnR /var/lib/ai6/managed/deploy-keys'
```

| Erwartung | Gemessener Wert | Ergebnis |
|---|---|---|
| Verzeichnis `<project_identifier>` gehört `10001 10001`, Modus `drwx------` | | bestanden / nicht bestanden |
| `id_ed25519` gehört `10001 10001`, Modus `-rw-------` | | bestanden / nicht bestanden |
| `id_ed25519.pub` gehört `10001 10001`, Modus `-rw-r--r--` | | bestanden / nicht bestanden |

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
| `app` | nicht gemountet | | bestanden / nicht bestanden |
| `worker` | gemountet, `rw` | | bestanden / nicht bestanden |
| `scheduler` | nicht gemountet | | bestanden / nicht bestanden |
| `agent` | nicht gemountet | | bestanden / nicht bestanden |
| `checker` | nicht gemountet | | bestanden / nicht bestanden |
| `init` | gemountet, `rw` | | bestanden / nicht bestanden |

### 4.3 Einmaliger Dienst `init` ist beendet

```
docker compose ps -a --format 'table {{.Service}}\t{{.Status}}\t{{.ID}}'
```

| Erwartung | Gemessener Wert | Ergebnis |
|---|---|---|
| `init` steht auf `exited (0)` | | bestanden / nicht bestanden |
| Die fünf dauerhaften Dienste laufen und sind `healthy` | | bestanden / nicht bestanden |

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
| Trefferzahl im Containerlog ist `0` | | bestanden / nicht bestanden |
| Die HTTP-Antwort der Auslösung enthält keinen privaten Teil | | bestanden / nicht bestanden |
| Die Projektansicht zeigt ausschließlich den öffentlichen Schlüssel | | bestanden / nicht bestanden |

## 5. Ergebnis

| Feld | Wert |
|---|---|
| Gesamtergebnis | bestanden / nicht bestanden |
| Abweichungen und Vorbehalte | |
| Anlagen | |
| Unterschrift | |

## 6. Gültigkeit

Die Evidenz verfällt, sobald sich der gebundene Stand ändert. Jede Änderung an
`docker-compose.yml`, `docker/entrypoint.sh`, `config/ai6.php`, dem verwalteten Root oder der
Schlüsselablage macht ein an den Vorgängerstand gebundenes Protokoll stale; das Gate ist dann
erneut abzunehmen. Ein offenes oder stale Gate blockiert nach `RUN-009` Candidate, finalen
Commit und Push.
