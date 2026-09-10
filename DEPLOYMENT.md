# Deployment auf der Synology NAS

Diese Anleitung bringt die App produktiv auf deine Synology, erreichbar von
außen über eine Domain mit HTTPS.

## Überblick

- Frontend (Vue) wird als statische Datei gebaut und von nginx ausgeliefert.
- Backend (Laravel) läuft als PHP-FPM, nginx reicht `/api/*` und `/sanctum/*`
  per fastcgi weiter.
- Alles läuft unter **einer** Adresse (kein CORS nötig, Auth läuft ohnehin
  über Bearer-Token, nicht über Cookies).
- Postgres und Redis sind nur intern im Docker-Netzwerk erreichbar, nicht
  vom LAN oder Internet aus.
- HTTPS macht **nicht** Docker, sondern Synologys eigener Reverse Proxy mit
  einem kostenlosen Let's-Encrypt-Zertifikat — das ist der Standardweg auf
  DSM und erspart dir Zertifikatsverwaltung in Docker.

## A. Voraussetzungen auf der NAS

1. **Container Manager** über das Paketzentrum installieren (falls noch
   nicht vorhanden).
2. SSH aktivieren (Systemsteuerung → Terminal & SNMP → SSH-Dienst aktivieren)
   — praktisch für die Ersteinrichtung, kann danach wieder deaktiviert werden.

## B. Projekt auf die NAS kopieren

Kopiere den kompletten Projektordner (`vibecoded-meal-panner`) auf die NAS,
z. B. per File Station in einen freigegebenen Ordner wie
`/volume1/docker/meal-planner`, oder per `rsync`/`scp` von deinem PC aus.

**Wichtig:** `backend/vendor`, `backend/node_modules`, `frontend/node_modules`
und `frontend/dist` müssen nicht mitkopiert werden — die werden beim Bauen
der Images neu erzeugt.

## C. Umgebungsvariablen einrichten

Auf der NAS im Projektordner:

```bash
cp .env.production .env
cp backend/.env.production backend/.env
```

Beide Dateien enthalten bereits generierte, sichere Passwörter für Postgres
und Redis sowie einen frischen `APP_KEY` — die musst du nicht anfassen,
außer du willst sie rotieren.

**Was du noch anpassen musst** (in `backend/.env`):

- `APP_URL` und `FRONTEND_URL` — vorerst auf die LAN-IP deiner NAS setzen,
  z. B. `http://192.168.1.50:8000` (Port aus `APP_PORT` in der `.env`,
  Standard `8000`). Sobald Domain + HTTPS eingerichtet sind (Abschnitt E),
  hier auf `https://deine-domain.example` umstellen.
- `MAIL_*` — siehe Abschnitt F, falls "Passwort vergessen" echte Mails
  verschicken soll.

## D. Bauen & starten

Im Projektordner auf der NAS (per SSH):

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

Das baut alle Images direkt auf der NAS (passt automatisch zur
NAS-Architektur) und startet den Stack. Die Datenbank-Migrationen laufen
automatisch vorab über den `migrate`-Service.

Alternativ über die **Container Manager**-Oberfläche: "Projekt" → "Erstellen"
→ Pfad auf den Projektordner zeigen lassen → die `docker-compose.prod.yml`
auswählen → Erstellen. Container Manager unterstützt aktuell nur den Dateinamen
`docker-compose.yml`; benenne die Datei dafür temporär um oder verlinke sie,
wenn du ausschließlich über die grafische Oberfläche arbeiten willst — über
SSH mit `-f docker-compose.prod.yml` brauchst du das nicht.

Danach ist die App unter `http://<NAS-IP>:8000` erreichbar. Kurz testen,
bevor du mit Domain/HTTPS weitermachst.

## E. Von außen erreichbar machen (Domain + HTTPS)

### 1. DDNS oder eigene Domain

Am einfachsten: Synologys kostenloser DDNS-Dienst.
**Systemsteuerung → Externer Zugriff → DDNS → Hinzufügen** → Anbieter
"Synology", einen Hostnamen wählen (z. B. `familienrezepte.synology.me`).

Falls du stattdessen eine eigene Domain hast: dort einen A-Record (oder
CNAME auf deine DDNS-Adresse) auf die öffentliche IP deines Routers zeigen
lassen.

### 2. Router: Portweiterleitung

Im Router die Ports **80** und **443** an die interne IP der NAS
weiterleiten (Port 80 wird für die automatische Let's-Encrypt-Verifizierung
gebraucht, danach läuft alles über 443).

### 3. Let's-Encrypt-Zertifikat

**Systemsteuerung → Sicherheit → Zertifikat → Hinzufügen** → "Neues
Zertifikat erhalten" → Let's Encrypt → deinen Hostnamen aus Schritt 1
eingeben.

### 4. Reverse Proxy einrichten

**Systemsteuerung → Anmeldeportal → Erweitert → Reverse Proxy** →
Erstellen:

- Quelle: HTTPS, dein Hostname, Port 443
- Ziel: HTTP, `localhost`, Port `8000` (bzw. dein `APP_PORT`)

Das Zertifikat aus Schritt 3 dem Hostnamen zuordnen (Systemsteuerung →
Sicherheit → Zertifikat → Konfigurieren).

### 5. APP_URL aktualisieren

In `backend/.env` auf der NAS `APP_URL` und `FRONTEND_URL` auf
`https://dein-hostname` setzen, dann:

```bash
docker compose -f docker-compose.prod.yml up -d --build nginx app queue
```

## F. E-Mail für "Passwort vergessen" aktivieren

In `backend/.env` auf der NAS:

```
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=<dein SMTP-Server>
MAIL_PORT=587
MAIL_USERNAME=<dein Benutzername>
MAIL_PASSWORD=<dein Passwort/App-Passwort>
MAIL_FROM_ADDRESS="deine-absenderadresse@example.com"
```

Trag die Zugangsdaten direkt in die Datei auf der NAS ein (nicht im Chat
teilen). Für Gmail brauchst du z. B. ein App-Passwort (Google-Konto →
Sicherheit → App-Passwörter, erfordert 2FA). Danach:

```bash
docker compose -f docker-compose.prod.yml up -d app queue
```

## G. Updates einspielen

Wenn sich der Code ändert (z. B. neue Recipe-Category-Funktion, künftige
Änderungen):

```bash
git pull   # oder neue Dateien erneut auf die NAS kopieren
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml run --rm migrate
```

Der letzte Befehl spielt neue Migrationen ein — das passiert nicht mehr
automatisch, sobald der `migrate`-Container einmal erfolgreich durchgelaufen
ist.

### Automatisch statt manuell

Das Repo ist öffentlich, also braucht `git pull` auf der NAS keine
Zugangsdaten. Damit lässt sich das oben stehende Update regelmäßig über den
Synology-Aufgabenplaner laufen lassen, statt es von Hand einzuspielen:

1. **Paketzentrum → "Git Server" installieren** (liefert den `git`-Befehl
   für die Shell).
2. **Systemsteuerung → Aufgabenplaner → Erstellen → Geplante Aufgabe →
   Benutzerdefiniertes Skript.**
3. Zeitplan festlegen, z. B. stündlich oder einmal täglich nachts — reicht
   für ein privates Familienprojekt völlig, und ein Lauf ohne Änderungen ist
   dank Docker-Layer-Cache in Sekunden durch.
4. Als Benutzer **root** ausführen (nötig für Docker-Zugriff) mit folgendem
   Befehl:
   ```
   sh /volume1/docker/meal-planner/scripts/update-from-git.sh >> /volume1/docker/meal-planner/update.log 2>&1
   ```
   (Pfad an deinen tatsächlichen Projektordner anpassen.)

Das ist bewusst ein Pull auf Zeitplan statt eines Webhooks, der bei jedem
Push sofort auslöst — kein zusätzlich offener Port/Endpunkt auf der NAS
nötig, und für dieses Projekt reicht der Zeitversatz von ein paar Stunden
locker aus. Falls du es doch sofort bei jedem Push haben willst, sag
Bescheid, das lässt sich mit einem GitHub-Actions-Workflow + einem kleinen
Webhook-Empfänger nachrüsten.

## H. Nützliche Befehle

```bash
# Logs ansehen
docker compose -f docker-compose.prod.yml logs -f app

# Stack stoppen
docker compose -f docker-compose.prod.yml down

# Laufende Container prüfen
docker compose -f docker-compose.prod.yml ps
```
