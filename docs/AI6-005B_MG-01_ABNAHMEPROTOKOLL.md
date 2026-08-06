# Entscheidungsprotokoll AI6-005B / MG-01 — Browserzugriff auf den Compose-Stack

Leeres Formular. Es wird ausschließlich von einem Menschen ausgefüllt. Anders als die beiden
Gates von `AI6-006C` ist dies **kein Messprotokoll**: `MG-01` verlangt eine Entscheidung darüber,
wie der Browserzugriff auf den mitgelieferten Compose-Stack die HTTPS-/Private-Access-Grenze
erfüllt. Die Messungen unter Abschnitt 3 dienen nur dazu, die Entscheidung informiert zu treffen;
sie ersetzen sie nicht. Der Gate-Text steht in `tickets/AI6-005B.md` unter
`## Manual and External Gates`.

## 0. Vorbereitung

Die `docker compose`-Befehle unter Abschnitt 3 laufen **im Repository-Root**, weil dort
`docker-compose.yml` liegt. Compose sucht die Datei im aktuellen Verzeichnis und in dessen
Elternverzeichnissen; ein Aufruf außerhalb des Repositoryzweigs endet mit
`no configuration file provided: not found`. Das veraltete `docker-compose` mit Bindestrich
sucht gar nicht nach oben und ist hier nicht zu verwenden. Wird aus einem anderen Verzeichnis
gearbeitet, ist `docker compose --file <Pfad>/docker-compose.yml --project-name <Projektname> …`
zu verwenden.

Die Befehle sind für zwei Shells angegeben. **POSIX** meint Linux, macOS oder Git Bash unter
Windows; **PowerShell** meint Windows PowerShell 5.1, das weder `&&` noch `grep`, `sha256sum`
oder Unix-`curl` kennt — dort ist `curl` ein Alias für `Invoke-WebRequest` und lehnt die
Unix-Flags mit `Fehlendes Argument für den Parameter "SessionVariable"` ab. Wo kein Unterschied
besteht, steht der Befehl nur einmal.

Der Stack muss für die Messungen laufen:

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
git add -A -N; git -c core.abbrev=40 -c diff.algorithm=myers -c diff.renames=false diff --binary --no-color --no-ext-diff --unified=3 --output=..i6-checkdiff.patch HEAD; git reset -q; (Get-FileHash ..i6-checkdiff.patch -Algorithm SHA256).Hash.ToLower()
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
| Entscheider | |
| Zeitpunkt (ISO 8601 mit Zeitzone) | |
| Zielinstallation (Host, Netzlage, Nutzerkreis) | |

## 2. Ausgangslage

`MG-01` hält fest: Die Loopback-Portveröffentlichung allein genügt nicht, weil Caddy den
Hostzugriff auf die nicht-loopbackgebundene Docker-Bridge-Gateway-Adresse auflöst und die
Anwendung Klartext deshalb normativ mit Status 400 ablehnt. Vorgesehener Produktionspfad ist die
HTTPS-Terminierung beziehungsweise der VPN-/SSH-Zugang aus `AI6-036`. Eine frühere,
gleichwertig sichere Netzwerklösung ist zulässig, benötigt aber eine ausdrückliche menschliche
Entscheidung und darf **weder das Docker-Gateway als Private Access einstufen noch die Host-
oder Proxyprüfung lockern**.

Mit `AI6-006C` liegt eine solche frühere Lösung vor: Der an die Loopback-Veröffentlichung
gebundene Caddy-Listener setzt den festen Header `X-AI6-Ingress: loopback-publication` und
ersetzt dabei jeden vom Client mitgeschickten Wert; `EnforceHttpsOrPrivateAccess` wertet diese
Behauptung ausschließlich aus, wenn der unmittelbare Peer eine konfigurierte vertrauenswürdige
Proxyadresse ist. Ob diese Lösung für die Zielinstallation gleichwertig sicher ist, entscheidet
dieses Protokoll.

## 3. Messungen zur Entscheidungsgrundlage

### 3.1 Klartext ohne Ingress-Behauptung wird weiterhin abgelehnt

Direkt gegen `app` unter Umgehung von Caddy, aus einer Rolle im Standardnetz:

```
docker compose exec -T scheduler curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: localhost' http://app:8080/health
```

| Erwartung | Gemessener Wert | Ergebnis |
|---|---|---|
| Status `400` ohne Ingress-Behauptung | | bestätigt |

### 3.2 Klartext über die Loopback-Veröffentlichung wird akzeptiert

POSIX:

```
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/health
```

PowerShell — dort ist `curl` ein Alias für `Invoke-WebRequest`, deshalb `curl.exe`:

```
curl.exe -s -o NUL -w "%{http_code}`n" http://127.0.0.1:8080/health
```

| Erwartung | Gemessener Wert | Ergebnis |
|---|---|---|
| Status `200` über Caddy | | bestätigt |

### 3.3 Die beiden Verbote aus `MG-01`

| Verbot aus `MG-01` | Nachzuweisen | Gemessener Wert | Ergebnis |
|---|---|---|---|
| Das Docker-Gateway wird nicht als Private Access eingestuft | Der Loopbackbegriff in `EnforceHttpsOrPrivateAccess::isLoopback()` ist unverändert `127.0.0.0/8` und `::1`; die Gatewayadresse erfüllt ihn nicht | | eingehalten / verletzt |
| Die Host- oder Proxyprüfung ist nicht gelockert | `ResolveTrustedProxies` ist unverändert; `AI6_HTTP_TRUSTED_HOSTS` und `AI6_HTTP_TRUSTED_PROXIES` sind unverändert wirksam | | eingehalten / verletzt |
| Die Clientadresse bleibt echt | `X-Forwarded-For` wird von Caddy nicht überschrieben; `getClientIp()` liefert den realen Client | | eingehalten / verletzt |

### 3.4 Restrisiko der früheren Lösung

| Eigenschaft | Wert |
|---|---|
| Wer erreicht den Klartextpfad? | jeder Prozess auf dem Docker-Host sowie der Dienst `app` über das Proxynetz |
| Wirksames Sicherheitsprofil der Zielinstallation | |
| Ist `REQUIRE_HTTPS_OR_PRIVATE_ACCESS` dort aktiv? | ja / nein |
| Folge für das Sessioncookie | bei aktiver Maßnahme bleibt es `Secure`, eine Klartextanmeldung funktioniert nicht |
| Wer hat Zugang zum Docker-Host? | |

## 4. Entscheidung

Genau eine Option ankreuzen.

| Option | Bedeutung | Gewählt |
|---|---|---|
| A — Gate bleibt offen | Der Klartextzugriff wird nicht freigegeben; der Browserzugriff wartet auf die HTTPS-Terminierung beziehungsweise den VPN-/SSH-Zugang aus `AI6-036`. `MG-01` bleibt blockierend. | |
| B — frühere Lösung akzeptiert | Die Ingress-Behauptung wird für die beschriebene Zielinstallation als gleichwertig sicher entschieden. Die kompensierende Maßnahme ist unter 4.1 zu benennen; ohne sie ist die Option nicht wählbar. | |
| C — andere Lösung | Eine abweichende Netzwerklösung wird entschieden und unter 4.1 vollständig beschrieben. | |

### 4.1 Kompensierende Maßnahme und Begründung

Bei Option B oder C verpflichtend. Zu benennen ist, was den fehlenden Transportschutz ersetzt —
etwa ausschließlicher Zugang über VPN oder SSH-Tunnel, ein auf bestimmte Hostbenutzer
eingeschränkter Zugriff, oder eine vorgelagerte TLS-Terminierung.

| Feld | Wert |
|---|---|
| Kompensierende Maßnahme | |
| Wer setzt sie durch und wie wird das überprüft? | |
| Begründung der Gleichwertigkeit | |
| Verbleibendes Restrisiko | |
| Befristung beziehungsweise Ablösung durch `AI6-036` | |

## 5. Ergebnis

| Feld | Wert |
|---|---|
| Gate-Zustand nach dieser Entscheidung | offen / geschlossen |
| Anlagen | |
| Unterschrift | |

## 6. Gültigkeit

Die Entscheidung ist an den unter 1 genannten Stand und an die unter 1 beschriebene
Zielinstallation gebunden. Sie verfällt, sobald sich `deploy/Caddyfile`,
`app/AI6/Shared/Http/EnforceHttpsOrPrivateAccess.php`, `app/AI6/Shared/Http/ResolveTrustedProxies.php`,
das Netzbild in `docker-compose.yml`, `AI6_HTTP_TRUSTED_PROXIES`, `AI6_HTTP_TRUSTED_HOSTS` oder
das wirksame Sicherheitsprofil ändert, und ebenso bei einem Wechsel der Netzlage oder des
Nutzerkreises der Zielinstallation. Ein offenes oder stale Gate blockiert nach `RUN-009`
Candidate, finalen Commit und Push.
