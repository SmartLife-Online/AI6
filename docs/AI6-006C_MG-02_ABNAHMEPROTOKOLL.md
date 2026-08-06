# Abnahmeprotokoll AI6-006C / MG-02 — instanzübergreifender Effekt-Lock

Leeres Formular. Es wird ausschließlich von einem Menschen ausgefüllt; automatisierte Testläufe
ersetzen diese Evidenz nach `RUN-009` nicht. Der Gate-Text steht in `tickets/AI6-006C.md`
unter `## Manual and External Gates`; diese Vorlage bildet ihn eins zu eins ab und fügt
nichts hinzu.

Der Verhaltensteil dieses Gates wird zusätzlich von `RuntimeComposeSmokeTest` automatisiert
geprüft. Das Gate bleibt trotzdem menschlich, weil die Zusage vom eingesetzten Volumetreiber
und Dateisystem abhängt — genau diese beiden Werte protokolliert kein Test.

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

Zulässig ist der geprüfte Git-Commit oder — vor einem Commit — Base-Commit **und** SHA-256 des
vollständigen Prüfdiffs.

```
git rev-parse HEAD
```

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

## 2. Laufzeit und Speichergrundlage

| Feld | Wert |
|---|---|
| Prüfer | |
| Zeitpunkt (ISO 8601 mit Zeitzone) | |
| Host und Betriebssystem | |
| Docker-Version | |
| Compose-Projektname | |
| Lockpfad | `/var/lib/ai6/managed/effect-locks/lock-0001` |

Volumetreiber und Mountpoint des geteilten Volumes:

```
docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/ai6/managed"}}{{.Name}} {{.Driver}} {{.Source}}{{end}}{{end}}' "$(docker compose ps -q worker)"
```

Dateisystemtyp des Lockverzeichnisses aus Sicht des Workers:

```
docker compose exec -T worker stat -f -c '%T %b %S' /var/lib/ai6/managed/effect-locks
```

| Feld | Gemessener Wert |
|---|---|
| Volumetreiber | |
| Mountpoint und Optionen | |
| Dateisystemtyp des Lockverzeichnisses | |

## 3. Identitäten der beteiligten Container

Die Workercontainer müssen unter der unprivilegierten Imageidentität laufen; eine
Rechteübersteuerung ist ausschließlich für den einmaligen Dienst `init` genehmigt.

```
docker inspect --format '{{.Id}} {{.Name}} {{.Config.User}} {{.HostConfig.Privileged}}' "$(docker compose ps -q worker)"
```

| Feld | Erwartung | Gemessener Wert | Ergebnis |
|---|---|---|---|
| Containerkennung Lockhalter | | | |
| Containerkennung Kontrahent | | | |
| Wirksame Benutzerkennung beider Container | `10001:10001` | | bestanden / nicht bestanden |
| `Privileged` beider Container | `false` | | bestanden / nicht bestanden |

## 4. Messungen

### 4.1 Herkunft und Unersetzbarkeit des Lockverzeichnisses

| Erwartung | Gemessener Wert | Ergebnis |
|---|---|---|
| Lockverzeichnis gehört `0`, Modus `0555` | | bestanden / nicht bestanden |
| Genau die konfigurierte Anzahl Lockobjekte, Eigentümer `0`, Modus `0444` | | bestanden / nicht bestanden |
| Der unprivilegierte Worker kann kein Lockobjekt anlegen | | bestanden / nicht bestanden |
| Der unprivilegierte Worker kann kein Lockobjekt löschen | | bestanden / nicht bestanden |
| Der unprivilegierte Worker kann kein Lockobjekt ersetzen | | bestanden / nicht bestanden |

Inode vor und nach einem zweiten `init`-Lauf vergleichen:

```
docker compose exec -T worker stat -c '%d:%i' /var/lib/ai6/managed/effect-locks/lock-0001
```

```
docker compose run --rm --no-deps init
```

| Erwartung | Wert vorher | Wert nachher | Ergebnis |
|---|---|---|---|
| Inode unverändert nach zweitem `init`-Lauf | | | bestanden / nicht bestanden |
| Kein Lockobjekt neu angelegt | | | bestanden / nicht bestanden |

### 4.2 Serialisierung über zwei Workerinstanzen

Ablauf: erste Instanz erwirbt den Lock und hält ihn; zweite Instanz versucht denselben Lock zu
erwerben und muss nachweisbar blockieren; die lockhaltende Instanz wird **abrupt** beendet —
Abbruch des Containers, kein geordnetes Herunterfahren; die zweite Instanz erwirbt den Lock
anschließend.

| Schritt | Erwartung | Gemessener Wert | Zeitpunkt | Ergebnis |
|---|---|---|---|---|
| 1 Erste Instanz hält den Lock | Erwerb bestätigt | | | bestanden / nicht bestanden |
| 2 Zweite Instanz blockiert | kein Erwerb, solange die erste lebt | | | bestanden / nicht bestanden |
| 3 Abruptes Ende der ersten Instanz | `docker kill`, kein `stop` | | | bestanden / nicht bestanden |
| 4 Zweite Instanz erwirbt den Lock | Erwerb unmittelbar nach Schritt 3 | | | bestanden / nicht bestanden |
| 5 Lockobjekt unverändert | Inode wie vor Schritt 1 | | | bestanden / nicht bestanden |

## 5. Ergebnis

| Feld | Wert |
|---|---|
| Gesamtergebnis | bestanden / nicht bestanden |
| Abweichungen und Vorbehalte | |
| Anlagen | |
| Unterschrift | |

## 6. Gültigkeit

Die Evidenz verfällt, sobald sich der gebundene Stand ändert. Jede Änderung an
`docker-compose.yml`, `docker/entrypoint.sh`, dem Lockverzeichnis, der Lockobjektanzahl oder
dem Volumebild macht ein an den Vorgängerstand gebundenes Protokoll stale; das Gate ist dann
erneut abzunehmen. Weil die Zusage vom Volumetreiber abhängt, gilt die Evidenz außerdem nur
für den protokollierten Treiber und dessen Dateisystem — ein Wechsel der Speichergrundlage
verlangt eine neue Abnahme. Ein offenes oder stale Gate blockiert nach `RUN-009` Candidate,
finalen Commit und Push.
