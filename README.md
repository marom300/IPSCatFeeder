# IPSCatFeeder — RFID-Katzenfütterung für IP-Symcon

Zwei (bis vier) Katzen, ein Napf, jede mit eigenem Tagesbudget. Ein ESP32 mit
RFID-Reader am Napf meldet per MQTT, welche Katze gerade frisst; dieses Modul
entscheidet nach Budget und fordert **Mikrodosierungen** (~8 g) bei der lokalen
Tuya-Bridge an. Der Napf bleibt grundsätzlich leer — ausgegeben wird nur,
solange die berechtigte Katze am Napf steht.

```
ESP32 (RFID)   --MQTT-->  cats/feeder/presence · unknown · status
CatFeeder-Modul  --MQTT-->  cats/feeder/cmd/dispense {"portions":1}
Tuya-Bridge    --MQTT-->  cats/feeder/device (retained) · bridge (LWT)
```

## Installation

1. **Modul einbinden:** Kern → Modulverwaltung → ⊕ → URL dieses Repos
   (oder lokales Verzeichnis).
2. **Instanz anlegen:** Objektbaum → ⊕ Instanz → „CatFeeder" (RFID Katzenfütterung).
   Beim Anlegen als **übergeordnetes Gerät den vorhandenen „MQTT Server"** wählen.
3. Konfigurieren (unten), Übernehmen — Variablen, Archiv-Logging und der
   Dashboard-WebHook werden automatisch angelegt.
4. **Dashboard:** `http://<symcon-ip>:3777/hook/catfeeder<InstanzID>` im Browser
   oder als IPSView-/WebFront-HTML-Box. (Hook-Namen sind case-sensitive.)

## Konfiguration (alle Parameter)

### MQTT

| Parameter | Standard | Wirkung |
|---|---|---|
| Basis-Topic | `cats/feeder` | Muss identisch in ESP32-`config.h` und Bridge-`config.json` stehen. Das Modul abonniert `<Basis>/presence`, `/unknown`, `/status`, `/device`, `/bridge` und sendet auf `<Basis>/cmd/dispense`. |

### Katzen

| Parameter | Standard | Wirkung |
|---|---|---|
| Katzen-Liste | Mila, Nala | Max. 4. **Name muss exakt dem `CAT_TAGS`-Namen in der ESP32-Firmware entsprechen** (Groß-/Kleinschreibung egal). Reihenfolge bestimmt die Variablen-Idents (Cat1…, Cat2…) — nachträgliches Umsortieren vertauscht die Historie! Umbenennen ist ok (Variablennamen ziehen nach). |
| Standard-Tagesbudget | 60 g | Vorbelegung der Budget-Variable **bei Neuanlage** einer Katze. Später zählt nur noch die Variable (WebFront/Dashboard änderbar). 0 g = heute nicht füttern. |

### Fütterung

| Parameter | Standard | Bereich | Wirkung |
|---|---|---|---|
| Gramm pro Portion | 8 g | ≥1 | Buchungsgröße je Ausgabe. In Phase 0 nachgewogen (oneisall 3.5L: 8 g). |
| Wiederholintervall | 20 s | ≥5 | Solange die Katze am Napf bleibt, wird alle N Sekunden eine weitere Portion angefordert (bis Budget erschöpft). |
| Tank-Kapazität | 1500 g | ≥100 | Nur für die Restmengen-Schätzung (Zähler runter je Portion; „Tank voll"-Button setzt zurück). Kein echter Füllstandssensor! |

### Dashboard & Benachrichtigung

| Parameter | Standard | Wirkung |
|---|---|---|
| Dashboard-Titel / Untertitel | Katzenfütterung / RFID · Mikrodosierung | Kopfzeile des Dashboards. |
| PIN | leer | Wenn gesetzt: Dashboard-Steuerung (Portion, Pause, Tank, Budget) nur mit PIN (Session-gecacht). |
| Push-Skript | keines | Wird bei „Futter leer/blockiert" und „Feeder offline" mit `$_IPS['MESSAGE']` aufgerufen — dort z. B. `WFC_PushNotification()` einbauen. Meldungen sind flankengesteuert (kein Spam). |

## Ablauf-Logik

- **present:** Besuch zählen → Budget prüfen → Portion anfordern → Timer alle
  N Sekunden, solange anwesend. Budget erschöpft → nur Besuch loggen.
- **absent:** Timer stoppt sofort (frisst eine zweite bekannte Katze noch, übernimmt sie).
- **unknown:** Fremder Tag → loggen (Liste im Dashboard), **niemals** ausgeben.
- **Feeder leer/gestört/offline oder Bridge offline:** keine Ausgabe + Push.
  „Leer" wird aus `feed_report < angefordert` abgeleitet (das Gerät hat kein Fehler-DP).
- **Pause-Schalter:** globaler Stopp (Urlaub/Tierarzt), Besuche werden weiter geloggt.
- **Tagesreset 00:00:** Gefressen/Besuche/Zähler auf 0; zusätzlich abgesichert
  bei jedem Ereignis (Datumswechsel-Erkennung).
- Die Bridge hat **eigene harte Limits** (max/min, Tagesmaximum) als letzte
  Verteidigungslinie — ein Fehler im Modul kann den Tank nicht leeren.

## Variablen (automatisch archiviert)

Pro Katze: `am Napf`, `gefressen heute`, `Tagesbudget` (bedienbar), `Besuche heute`,
`letzter Besuch`. Global: **`Status`** (eine Ampel-Variable: Bereit / Tageslimit erreicht /
Pausiert / Futter leer / Feeder offline / Bridge offline — grün heißt „alles normal"),
`Fütterung pausiert` (bedienbar), `Portionen/Gesamt heute`,
`Letzte Ausgabe`, `Tank-Restmenge`, `Feeder erreichbar`, `Bridge online`,
`Futter leer (Verdacht)`, `Ausgabe gedrosselt (Bridge-Limit)` — z. B. Tagesmaximum erreicht,
`RFID-Reader online/WLAN/zuletzt gesehen`,
`Letzter fremder Tag`, `Fremde Tags heute`, `Fütterungs-Protokoll` (String-Log).

## Öffentliche Funktionen

| Funktion | Wirkung |
|---|---|
| `CFD_Dispense(int $id, int $Portionen)` | Manuelle Ausgabe (1–3 Portionen), zählt auf kein Katzenbudget und **umgeht das Tageslimit der Bridge** (Minutenlimit/Mindestabstand gelten weiter). Obergrenze `MANUAL_MAX` (3) im Modul. |
| `CFD_TankFilled(int $id)` | Tank-Schätzung auf Kapazität zurücksetzen. |

Im Dashboard öffnet der Button **„Portion …"** einen Auswähler für 1–3 Portionen (8/16/24 g).
Mehr als 3 auf einmal: `MANUAL_MAX` im Modul UND `max_portions_per_cmd` in der Bridge-`config.json`
erhöhen (danach `restart-service.bat`).

## Dashboard-API (WebHook)

- `GET  …?action=status` → kompletter Zustand als JSON
- `GET  …?action=log` → Protokoll-Einträge (Archiv des Fütterungs-Logs)
- `POST …?action=cmd` `{cmd, value, pin}` → `dispense` (value = Portionen 1–3) · `pause` · `tank_full` · `set_budget` (+`cat`)
- ohne `action` → liefert das Dashboard-HTML aus
