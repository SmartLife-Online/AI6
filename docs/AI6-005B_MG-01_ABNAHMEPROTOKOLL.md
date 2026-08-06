# Entscheidungsprotokoll AI6-005B / MG-01 — Browserzugriff auf den Compose-Stack

Ausgefüllter Entwurf. Solange die Unterschrift unter Abschnitt 5 aussteht, schließt dieser Stand
kein Gate; die Entscheidung wird ausschließlich von einem Menschen final bestätigt. Anders als
die beiden Gates von `AI6-006C` ist dies **kein Messprotokoll**: `MG-01` verlangt eine
Entscheidung darüber, wie der Browserzugriff auf den mitgelieferten Compose-Stack die
HTTPS-/Private-Access-Grenze erfüllt. Die Messungen unter Abschnitt 3 dienen nur dazu, die Entscheidung informiert zu treffen;
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
| Bindungsart | Commit |
| Commit beziehungsweise Base-Commit | 1f0e6b7002176195c51777f63b8c528d150e4331 |
| SHA-256 des Prüfdiffs | Entfällt bei Commit-Bindung; der Arbeitsbaum ist gegenüber diesem Commit bis auf dieses Protokoll unverändert |
| Entscheider | Michael Strübing — als menschlicher Entscheider vorgesehen; persönliche Bestätigung ausstehend |
| Zeitpunkt (ISO 8601 mit Zeitzone) | 2026-08-06T23:33:22+02:00 |
| Zielinstallation (Host, Netzlage, Nutzerkreis) | Geplanter Linux-VPS mit Docker Compose; administrativer Einzelbetrieb; keine öffentliche Erreichbarkeit im MVP; produktiver Browserzugriff erst über privates VPN und stabilen internen HTTPS-Hostnamen, eingeschränkter SSH-Tunnel nur als Fallback |

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
| Status `400` ohne Ingress-Behauptung | 400 | bestätigt |

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
| Status `200` über Caddy | 200 | bestätigt  |

### 3.3 Die beiden Verbote aus `MG-01`

| Verbot aus `MG-01` | Nachzuweisen | Gemessener Wert | Ergebnis |
|---|---|---|---|
| Das Docker-Gateway wird nicht als Private Access eingestuft | Der Loopbackbegriff in `EnforceHttpsOrPrivateAccess::isLoopback()` ist unverändert `127.0.0.0/8` und `::1`; die Gatewayadresse erfüllt ihn nicht | **Nicht am gebundenen Stand geprüft** | **Offen** |
| Die Host- oder Proxyprüfung ist nicht gelockert | `ResolveTrustedProxies` ist unverändert; `AI6_HTTP_TRUSTED_HOSTS` und `AI6_HTTP_TRUSTED_PROXIES` sind unverändert wirksam | **Nicht am gebundenen Stand geprüft** | **Offen** |
| Die Clientadresse bleibt echt | `X-Forwarded-For` wird von Caddy nicht überschrieben; `getClientIp()` liefert den realen Client | **Nicht am gebundenen Stand geprüft** | **Offen** |

### 3.4 Restrisiko der früheren Lösung

| Eigenschaft | Wert |
|---|---|
| Wer erreicht den Klartextpfad? | jeder Prozess auf dem Docker-Host sowie der Dienst `app` über das Proxynetz |
| Wirksames Sicherheitsprofil der Zielinstallation | `strict` als geplanter Standard |
| Ist `REQUIRE_HTTPS_OR_PRIVATE_ACCESS` dort aktiv? | **ja** |
| Folge für das Sessioncookie | bei aktiver Maßnahme bleibt es `Secure`, eine Klartextanmeldung funktioniert nicht |
| Wer hat Zugang zum Docker-Host? | Geplant ausschließlich der Administrator; konkrete Konten, Schlüssel und `AllowUsers`-Regeln sind vor der Produktionsabnahme nachzuweisen |

## 4. Entscheidung

Genau eine Option ankreuzen.

| Option | Bedeutung | Gewählt |
|---|---|---|
| A — Gate bleibt offen | Der Klartextzugriff wird nicht freigegeben; der Browserzugriff wartet auf die HTTPS-Terminierung beziehungsweise den VPN-/SSH-Zugang aus `AI6-036`. `MG-01` bleibt blockierend. | **X** |
| B — frühere Lösung akzeptiert | Die Ingress-Behauptung wird für die beschriebene Zielinstallation als gleichwertig sicher entschieden. Die kompensierende Maßnahme ist unter 4.1 zu benennen; ohne sie ist die Option nicht wählbar. |  |
| C — andere Lösung | Eine abweichende Netzwerklösung wird entschieden und unter 4.1 vollständig beschrieben. |  |

### 4.1 Kompensierende Maßnahme und Begründung

Bei Option B oder C verpflichtend. Zu benennen ist, was den fehlenden Transportschutz ersetzt —
etwa ausschließlicher Zugang über VPN oder SSH-Tunnel, ein auf bestimmte Hostbenutzer
eingeschränkter Zugriff, oder eine vorgelagerte TLS-Terminierung.

| Feld | Wert |
|---|---|
| Kompensierende Maßnahme | Entfällt bei Option A: Der Klartextpfad wird nicht als freigegebener Browserzugang akzeptiert. |
| Wer setzt sie durch und wie wird das überprüft? | Bis zur Abnahme von `AI6-036` bleiben öffentliche Portfreigabe und produktiver Klartext-Browserzugriff untersagt. HTTPS-/Private-Access-, Trusted-Host- und Trusted-Proxy-Prüfungen werden nicht gelockert. |
| Begründung der Gleichwertigkeit | Keine Gleichwertigkeitsentscheidung: Der Projektstandard verlangt privates VPN plus HTTPS. Außerdem bleibt das Sessioncookie bei aktiver Maßnahme `Secure`, sodass der reine Klartextpfad keinen regulären Anmeldeweg bereitstellt. |
| Verbleibendes Restrisiko | Der technische Loopbackpfad kann weiterhin von Hostprozessen und dem Proxynetz erreicht werden, ist aber nicht als produktiver Browserzugang freigegeben. Ein vollständig kompromittierter Host bleibt außerhalb dieser Transportentscheidung. |
| Befristung beziehungsweise Ablösung durch `AI6-036` | Neubewertung und Gate-Schließung erst nach nachgewiesener VPN-/HTTPS-Referenz oder einem eingeschränkten SSH-Tunnel mit gültigem HTTPS-Kontext im Browser. |

## 5. Ergebnis

| Feld | Wert |
|---|---|
| Gate-Zustand nach dieser Entscheidung | **offen** |
| Anlagen | Ausstehend: Commitbindung; Messwerte aus 3.1 bis 3.3; späterer VPN-/HTTPS-/SSH-Zugriffsnachweis aus `AI6-036` |
| Unterschrift | **Ausstehend — persönlich durch Michael Strübing** |

## 6. Gültigkeit

Die Entscheidung ist an den unter 1 genannten Stand und an die unter 1 beschriebene
Zielinstallation gebunden. Sie verfällt, sobald sich `deploy/Caddyfile`,
`app/AI6/Shared/Http/EnforceHttpsOrPrivateAccess.php`, `app/AI6/Shared/Http/ResolveTrustedProxies.php`,
das Netzbild in `docker-compose.yml`, `AI6_HTTP_TRUSTED_PROXIES`, `AI6_HTTP_TRUSTED_HOSTS` oder
das wirksame Sicherheitsprofil ändert, und ebenso bei einem Wechsel der Netzlage oder des
Nutzerkreises der Zielinstallation. Ein offenes oder stale Gate blockiert nach `RUN-009`
Candidate, finalen Commit und Push.
