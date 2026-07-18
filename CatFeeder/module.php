<?php

declare(strict_types=1);

/**
 * CatFeeder - IP-Symcon Modul (Phase 3)
 * -------------------------------------
 * RFID-gesteuerte Katzenfuetterung aus EINEM Napf mit Tagesbudget pro Katze.
 *
 *  ESP32 (RFID am Napf)  --MQTT-->  cats/feeder/presence|unknown|status
 *  dieses Modul          --MQTT-->  cats/feeder/cmd/dispense {"portions":1}
 *  Tuya-Bridge (Dienst)  --MQTT-->  cats/feeder/device (Feederstatus, retained)
 *
 * Mikrodosierung: bei "present" eine Portion anfordern, danach alle N Sekunden
 * wiederholen, solange die Katze am Napf bleibt und Budget uebrig ist.
 * Bei "absent" stoppt der Timer sofort. Unbekannte Tags = Diebstahlversuch,
 * es wird NIE ausgegeben. Feeder leer/offline -> keine Ausgabe + Meldung.
 *
 * Haengt als Geraet unter dem MQTT Server (Splitter).
 */
class CatFeeder extends IPSModule
{
    // Datenfluss-GUIDs des IP-Symcon MQTT Servers
    private const GUID_MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
    private const GUID_MQTT_RX = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';
    private const GUID_ARCHIVE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const GUID_WEBHOOK = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    private const MAX_CATS    = 4;
    private const MAX_THIEVES = 20;
    private const MANUAL_MAX   = 3;   // max. Portionen pro manueller Ausgabe (Dashboard/Skript)

    public function Create()
    {
        parent::Create();

        // --- MQTT / Grundkonfiguration ---
        $this->RegisterPropertyString('BaseTopic', 'cats/feeder');
        $this->RegisterPropertyString('Cats', json_encode([
            ['Name' => 'Mila'],
            ['Name' => 'Nala']
        ]));
        $this->RegisterPropertyInteger('DefaultBudget', 60);     // g/Tag je Katze
        $this->RegisterPropertyInteger('PortionGrams', 8);       // Phase 0: nachgewogen
        $this->RegisterPropertyInteger('FeedIntervalSec', 20);   // Wiederholung waehrend Anwesenheit
        $this->RegisterPropertyInteger('TankCapacityG', 1500);   // 3,5 l Trockenfutter (geschaetzt)

        // --- Dashboard / Benachrichtigung ---
        $this->RegisterPropertyString('DashboardTitle', 'Katzenfütterung');
        $this->RegisterPropertyString('DashboardSubtitle', 'RFID · Mikrodosierung');
        $this->RegisterPropertyString('PinCode', '');            // leer = Steuerung ohne PIN
        $this->RegisterPropertyInteger('NotifyScriptID', 0);     // Skript fuer Push ($_IPS['MESSAGE'])

        // --- interne Zustaende ---
        $this->RegisterAttributeInteger('ActiveCat', 0);         // 1-basiert, 0 = keine
        $this->RegisterAttributeInteger('LastDispenseTs', 0);
        $this->RegisterAttributeString('ResetDate', '');
        $this->RegisterAttributeString('DayLog', '{}');          // Stundenverlauf fuer Chart
        $this->RegisterAttributeString('Thieves', '[]');
        $this->RegisterAttributeBoolean('NotifiedEmpty', false);
        $this->RegisterAttributeBoolean('NotifiedOffline', false);
        $this->RegisterAttributeBoolean('WasThrottled', false);
        $this->RegisterAttributeString('ThrottleReason', '');
        $this->RegisterAttributeString('InitializedCats', '[]');

        // --- Timer ---
        $this->RegisterTimer('FeedTick', 0, 'CFD_FeedTick($_IPS[\'TARGET\']);');
        $this->RegisterTimer('MidnightReset', 0, 'CFD_MidnightReset($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Empfangsfilter robust gegen (nicht) escapte Slashes im JSON
        $parts = array_map('preg_quote', explode('/', $this->ReadPropertyString('BaseTopic')));
        $this->SetReceiveDataFilter('.*' . implode('.*', $parts) . '.*');

        // --- Profile ---
        $this->ensureProfileInt('CFD.g', ' g');
        $this->ensureProfileInt('CFD.dbm', ' dBm');
        $this->ensureProfileInt('CFD.visits', '');
        $this->ensurePresentProfile();
        $this->ensurePausedProfile();

        // --- Variablen pro Katze ---
        $cats = $this->catList();
        $init = json_decode($this->ReadAttributeString('InitializedCats'), true) ?: [];
        foreach ($cats as $i => $cat) {
            $n = $i + 1;
            $pos = 100 * $n;
            $this->RegisterVariableBoolean("Cat{$n}Present", $cat['Name'] . ': am Napf', 'CFD.Present', $pos + 1);
            $this->RegisterVariableInteger("Cat{$n}EatenToday", $cat['Name'] . ': gefressen heute', 'CFD.g', $pos + 2);
            $this->RegisterVariableInteger("Cat{$n}Budget", $cat['Name'] . ': Tagesbudget', 'CFD.g', $pos + 3);
            $this->RegisterVariableInteger("Cat{$n}Visits", $cat['Name'] . ': Besuche heute', 'CFD.visits', $pos + 4);
            $this->RegisterVariableInteger("Cat{$n}LastVisit", $cat['Name'] . ': letzter Besuch', '~UnixTimestamp', $pos + 5);
            $this->EnableAction("Cat{$n}Budget");

            // Budget nur bei Neuanlage vorbelegen (0 = "heute nicht fuettern" bleibt erlaubt)
            if (!in_array("Cat{$n}", $init, true)) {
                $this->SetValue("Cat{$n}Budget", $this->ReadPropertyInteger('DefaultBudget'));
                $init[] = "Cat{$n}";
            }
            // Namen nachziehen, falls in der Liste umbenannt
            foreach (['Present' => ': am Napf', 'EatenToday' => ': gefressen heute',
                      'Budget' => ': Tagesbudget', 'Visits' => ': Besuche heute',
                      'LastVisit' => ': letzter Besuch'] as $suffix => $label) {
                $vid = @$this->GetIDForIdent("Cat{$n}{$suffix}");
                if ($vid && IPS_GetName($vid) !== $cat['Name'] . $label) {
                    IPS_SetName($vid, $cat['Name'] . $label);
                }
            }
        }
        $this->WriteAttributeString('InitializedCats', json_encode($init));

        // --- globale Statusvariablen ---
        $this->ensureSystemStateProfile();
        $this->RegisterVariableInteger('SystemState', 'Status', 'CFD.State', 5);
        $this->RegisterVariableBoolean('Paused', 'Fütterung pausiert', 'CFD.Paused', 10);
        $this->EnableAction('Paused');
        $this->RegisterVariableInteger('PortionsToday', 'Portionen heute', '', 20);
        $this->RegisterVariableInteger('GramsToday', 'Gesamt heute', 'CFD.g', 21);
        $this->RegisterVariableInteger('LastDispense', 'Letzte Ausgabe', '~UnixTimestamp', 22);
        $this->RegisterVariableInteger('TankRemaining', 'Tank-Restmenge (geschätzt)', 'CFD.g', 23);
        $this->RegisterVariableBoolean('FeederOnline', 'Feeder erreichbar', '~Switch', 30);
        $this->RegisterVariableBoolean('BridgeOnline', 'Bridge online', '~Switch', 31);
        $this->RegisterVariableBoolean('SuspectedEmpty', 'Futter leer/blockiert (Verdacht)', '~Alert', 32);
        $this->RegisterVariableBoolean('Throttled', 'Ausgabe gedrosselt (Bridge-Limit)', '~Alert', 33);
        $this->RegisterVariableBoolean('Esp32Online', 'RFID-Reader online', '~Switch', 40);
        $this->RegisterVariableInteger('Esp32Rssi', 'RFID-Reader WLAN', 'CFD.dbm', 41);
        $this->RegisterVariableInteger('Esp32LastSeen', 'RFID-Reader zuletzt gesehen', '~UnixTimestamp', 42);
        $this->RegisterVariableString('LastUnknownTag', 'Letzter fremder Tag', '', 50);
        $this->RegisterVariableInteger('UnknownToday', 'Fremde Tags heute', '', 51);
        $this->RegisterVariableString('FeedLog', 'Fütterungs-Protokoll', '', 60);

        if ($this->GetValue('TankRemaining') === 0 && $this->ReadAttributeInteger('LastDispenseTs') === 0) {
            $this->SetValue('TankRemaining', $this->ReadPropertyInteger('TankCapacityG'));
        }

        $this->enableArchiving();
        $this->armMidnightTimer();
        $this->refreshSystemState();

        // Dashboard-WebHook registrieren (erst wenn der Kernel bereit ist)
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->RegisterHook('/hook/catfeeder' . $this->InstanceID);
        } else {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELSTARTED) {
            $this->RegisterHook('/hook/catfeeder' . $this->InstanceID);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Paused') {
            $this->SetValue('Paused', (bool)$Value);
            $this->logFeed((bool)$Value ? '⏸ Fütterung pausiert' : '▶ Fütterung fortgesetzt');
            if ((bool)$Value) {
                $this->stopFeeding('Pause');
            }
            $this->refreshSystemState();
            return;
        }
        if (preg_match('/^Cat(\d+)Budget$/', $Ident, $m)) {
            $v = max(0, (int)$Value);
            $this->SetValue($Ident, $v);
            $this->logFeed($this->catName((int)$m[1]) . ': Tagesbudget auf ' . $v . ' g gesetzt');
            return;
        }
        throw new Exception('Unbekannte Aktion: ' . $Ident);
    }

    // ================= MQTT-Empfang =================
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        if ($data === null || !isset($data->Topic)) {
            return '';
        }
        $topic   = (string)$data->Topic;
        $payload = $this->decodePayload($data->Payload ?? '');
        $base    = $this->ReadPropertyString('BaseTopic');

        $this->SendDebug('RX', $topic . ' = ' . $payload, 0);
        $this->resetIfNewDay();

        switch ($topic) {
            case $base . '/presence':
                $j = json_decode($payload, true);
                if (is_array($j)) {
                    $this->onPresence($j);
                }
                break;

            case $base . '/unknown':
                $j = json_decode($payload, true);
                if (is_array($j) && isset($j['tag'])) {
                    $this->onUnknown((string)$j['tag']);
                }
                break;

            case $base . '/status':
                $j = json_decode($payload, true);
                if (is_array($j)) {
                    $online = ($j['state'] ?? '') === 'online';
                    $this->SetValue('Esp32Online', $online);
                    if (isset($j['rssi'])) $this->SetValue('Esp32Rssi', (int)$j['rssi']);
                    if ($online) $this->SetValue('Esp32LastSeen', time());
                }
                break;

            case $base . '/device':
                $j = json_decode($payload, true);
                if (is_array($j)) {
                    $this->onDeviceStatus($j);
                }
                break;

            case $base . '/bridge':
                $this->SetValue('BridgeOnline', trim($payload) === 'online');
                break;
        }
        $this->refreshSystemState();
        return '';
    }

    // ================= Präsenz / Fütterung =================
    private function onPresence(array $j): void
    {
        $name  = (string)($j['cat'] ?? '');
        $state = (string)($j['state'] ?? '');
        $idx   = $this->catIndexByName($name);
        if ($idx === 0) {
            $this->SendDebug('PRESENCE', 'Unbekannte Katze im Payload: ' . $name, 0);
            return;
        }

        if ($state === 'present') {
            $was = $this->GetValue("Cat{$idx}Present");
            $this->SetValue("Cat{$idx}Present", true);
            $this->SetValue("Cat{$idx}LastVisit", time());
            if (!$was) {
                $this->SetValue("Cat{$idx}Visits", $this->GetValue("Cat{$idx}Visits") + 1);
                $this->logFeed($this->catName($idx) . ' ist am Napf (Besuch ' . $this->GetValue("Cat{$idx}Visits") . ')');
            }
            $this->WriteAttributeInteger('ActiveCat', $idx);
            $this->tryDispense($idx, true);
            $this->SetTimerInterval('FeedTick', max(5, $this->ReadPropertyInteger('FeedIntervalSec')) * 1000);
        }

        if ($state === 'absent') {
            $this->SetValue("Cat{$idx}Present", false);
            $dur = (int)($j['duration_s'] ?? 0);
            $this->logFeed($this->catName($idx) . ' hat den Napf verlassen (' . $dur . ' s)');
            if ($this->ReadAttributeInteger('ActiveCat') === $idx) {
                $other = $this->anyPresentCat();
                if ($other > 0) {
                    $this->WriteAttributeInteger('ActiveCat', $other);
                } else {
                    $this->stopFeeding('Napf verlassen');
                }
            }
        }
    }

    /** Timer-Callback: alle FeedIntervalSec, solange eine Katze am Napf ist. */
    public function FeedTick()
    {
        $this->resetIfNewDay();
        $idx = $this->ReadAttributeInteger('ActiveCat');
        if ($idx <= 0 || !$this->GetValue("Cat{$idx}Present")) {
            $this->stopFeeding('Timer ohne aktive Katze');
            return;
        }
        $this->tryDispense($idx, false);
    }

    private function tryDispense(int $idx, bool $arrival): void
    {
        $portion = $this->ReadPropertyInteger('PortionGrams');

        if ($this->GetValue('Paused')) {
            if ($arrival) $this->logFeed($this->catName($idx) . ': keine Ausgabe (Fütterung pausiert)');
            return;
        }
        if ($this->GetValue('SuspectedEmpty')) {
            if ($arrival) $this->logFeed('⚠ Keine Ausgabe: Futter leer/blockiert (Verdacht)');
            return;
        }
        if ($this->GetValue('Throttled')) {
            // Bridge wuerde ablehnen — gar nicht erst anfordern (sonst Phantom-Buchung)
            if ($arrival) $this->logFeed('⚠ Keine Ausgabe: Bridge-Limit aktiv (Tagesmaximum)');
            return;
        }
        if (!$this->GetValue('BridgeOnline') || !$this->GetValue('FeederOnline')) {
            if ($arrival) $this->logFeed('⚠ Keine Ausgabe: Feeder/Bridge nicht erreichbar');
            return;
        }

        $eaten  = $this->GetValue("Cat{$idx}EatenToday");
        $budget = $this->GetValue("Cat{$idx}Budget");
        if ($eaten + $portion > $budget) {
            if ($arrival) $this->logFeed($this->catName($idx) . ': Budget erschöpft (' . $eaten . '/' . $budget . ' g) — nur Besuch geloggt');
            return;
        }

        // Doppel-Events abfangen (z.B. present kurz nach Timer-Tick)
        $gap = max(5, $this->ReadPropertyInteger('FeedIntervalSec')) - 2;
        if (time() - $this->ReadAttributeInteger('LastDispenseTs') < $gap) {
            return;
        }

        // Portion anfordern — die Bridge setzt zusaetzlich eigene harte Limits.
        $this->publish($this->ReadPropertyString('BaseTopic') . '/cmd/dispense', json_encode(['portions' => 1]), false);
        $this->WriteAttributeInteger('LastDispenseTs', time());

        // Optimistische Buchung (Kickoff): sofort zaehlen, Archiv uebernimmt den Verlauf.
        $this->SetValue("Cat{$idx}EatenToday", $eaten + $portion);
        $this->SetValue('GramsToday', $this->GetValue('GramsToday') + $portion);
        $this->SetValue('PortionsToday', $this->GetValue('PortionsToday') + 1);
        $this->SetValue('LastDispense', time());
        $this->SetValue('TankRemaining', max(0, $this->GetValue('TankRemaining') - $portion));
        $this->addDayLog($idx, $portion);
        $this->logFeed($this->catName($idx) . ': Portion ausgegeben (' . ($eaten + $portion) . '/' . $budget . ' g)');
    }

    private function stopFeeding(string $reason): void
    {
        $this->SetTimerInterval('FeedTick', 0);
        $this->WriteAttributeInteger('ActiveCat', 0);
        $this->SendDebug('FEED', 'Timer gestoppt: ' . $reason, 0);
    }

    // ================= Fremde Tags =================
    private function onUnknown(string $tag): void
    {
        // Rausch-Lesungen des RDM6300: komplett genullte Frames bestehen die
        // XOR-Pruefung (0^0=0); echte EM4100-Tags sind nie 0000000000.
        if ($tag === '' || $tag === '0000000000') {
            $this->SendDebug('RFID', 'Rausch-Frame verworfen: ' . $tag, 0);
            return;
        }
        $this->SetValue('LastUnknownTag', $tag);
        $this->SetValue('UnknownToday', $this->GetValue('UnknownToday') + 1);
        $thieves = json_decode($this->ReadAttributeString('Thieves'), true) ?: [];
        array_unshift($thieves, [time(), $tag]);
        $this->WriteAttributeString('Thieves', json_encode(array_slice($thieves, 0, self::MAX_THIEVES)));
        $this->logFeed('⚠ Fremder Tag am Napf: ' . $tag . ' — keine Ausgabe');
    }

    // ================= Feeder-/Bridge-Status =================
    private function onDeviceStatus(array $j): void
    {
        if (isset($j['online'])) $this->SetValue('FeederOnline', (bool)$j['online']);
        if (isset($j['bridge'])) $this->SetValue('BridgeOnline', $j['bridge'] === 'online');

        $empty = (bool)($j['suspected_empty'] ?? false);
        $this->SetValue('SuspectedEmpty', $empty);

        // Flankengesteuerte Meldungen (nur beim Wechsel, kein Spam)
        if ($empty && !$this->ReadAttributeBoolean('NotifiedEmpty')) {
            $this->WriteAttributeBoolean('NotifiedEmpty', true);
            $this->notify('🐱 Futterautomat: Tank vermutlich LEER oder blockiert — bitte nachfüllen! Ausgabe gestoppt.');
            $this->logFeed('⚠ Feeder meldet: vermutlich leer/blockiert — Ausgabe gestoppt');
        }
        if (!$empty) {
            $this->WriteAttributeBoolean('NotifiedEmpty', false);
        }

        $offline = !(bool)($j['online'] ?? true);
        if ($offline && !$this->ReadAttributeBoolean('NotifiedOffline')) {
            $this->WriteAttributeBoolean('NotifiedOffline', true);
            $this->notify('🐱 Futterautomat: Feeder nicht erreichbar!');
            $this->logFeed('⚠ Feeder nicht erreichbar');
        }
        if (!$offline) {
            $this->WriteAttributeBoolean('NotifiedOffline', false);
        }

        // Drossel-Status flankengesteuert (device kommt alle 60 s — sonst Log-Spam)
        $thr = !empty($j['throttled']);
        $this->SetValue('Throttled', $thr);
        if ($thr && !$this->ReadAttributeBoolean('WasThrottled')) {
            $this->WriteAttributeBoolean('WasThrottled', true);
            $this->WriteAttributeString('ThrottleReason', (string)($j['throttle_reason'] ?? ''));
            $this->logFeed('⚠ Bridge drosselt: ' . (string)($j['throttle_reason'] ?? ''));
        } elseif (!$thr && $this->ReadAttributeBoolean('WasThrottled')) {
            $this->WriteAttributeBoolean('WasThrottled', false);
            $this->WriteAttributeString('ThrottleReason', '');
            $this->logFeed('Bridge-Drosselung aufgehoben');
        }
    }

    // ================= Tagesreset =================
    public function MidnightReset()
    {
        $this->resetIfNewDay();
        $this->armMidnightTimer();
    }

    private function resetIfNewDay(): void
    {
        $today = date('Y-m-d');
        if ($this->ReadAttributeString('ResetDate') === $today) {
            return;
        }
        $this->WriteAttributeString('ResetDate', $today);
        $this->resetCounters('— Tagesreset —');
    }

    private function resetCounters(string $logMsg): void
    {
        foreach ($this->catList() as $i => $cat) {
            $n = $i + 1;
            $this->SetValue("Cat{$n}EatenToday", 0);
            $this->SetValue("Cat{$n}Visits", 0);
        }
        $this->SetValue('PortionsToday', 0);
        $this->SetValue('GramsToday', 0);
        $this->SetValue('UnknownToday', 0);
        $this->WriteAttributeString('DayLog', '{}');
        $this->logFeed($logMsg);
    }

    /** Test-Reset: Modul-Zaehler UND Bridge-Tageslimit zuruecksetzen (Dashboard/Formular). */
    public function ResetDay()
    {
        $this->WriteAttributeString('ResetDate', date('Y-m-d'));
        $this->resetCounters('🔄 Reset — Zähler und Bridge-Tageslimit zurückgesetzt');
        // Bridge leert ihre Ausgabe-Zaehler (state.json) und meldet device neu
        $this->publish($this->ReadPropertyString('BaseTopic') . '/cmd/reset', '{}', false);
        // Drossel-Status lokal sofort aufheben, die Bridge bestaetigt via device-Topic
        $this->SetValue('Throttled', false);
        $this->WriteAttributeBoolean('WasThrottled', false);
        $this->WriteAttributeString('ThrottleReason', '');
        $this->WriteAttributeString('Thieves', '[]');
        $this->SetValue('LastUnknownTag', '');
        $this->refreshSystemState();
    }

    private function armMidnightTimer(): void
    {
        $next = strtotime('tomorrow 00:00:02');
        $this->SetTimerInterval('MidnightReset', max(60, $next - time()) * 1000);
    }

    // ================= Öffentliche Funktionen =================
    /** Manuelle Portion(en) (Konsole/Dashboard/Skript). Zaehlt NICHT auf ein Katzenbudget.
     *  Bis MANUAL_MAX Portionen in einem Kommando — die Bridge begrenzt zusaetzlich hart. */
    public function Dispense(int $Portions)
    {
        $p = max(1, min(self::MANUAL_MAX, $Portions));
        // manual=true: Bridge laesst das Tageslimit aus (Minutenlimit/Abstand bleiben)
        $this->publish($this->ReadPropertyString('BaseTopic') . '/cmd/dispense', json_encode(['portions' => $p, 'manual' => true]), false);
        $portion = $this->ReadPropertyInteger('PortionGrams') * $p;
        $this->SetValue('GramsToday', $this->GetValue('GramsToday') + $portion);
        $this->SetValue('PortionsToday', $this->GetValue('PortionsToday') + $p);
        $this->SetValue('LastDispense', time());
        $this->SetValue('TankRemaining', max(0, $this->GetValue('TankRemaining') - $portion));
        $this->logFeed('Manuelle Ausgabe: ' . $p . ' Portion(en)');
    }

    /** Tank wurde aufgefuellt -> Schaetzung zuruecksetzen. */
    public function TankFilled()
    {
        $this->SetValue('TankRemaining', $this->ReadPropertyInteger('TankCapacityG'));
        $this->WriteAttributeBoolean('NotifiedEmpty', false);
        $this->logFeed('Tank aufgefüllt (' . $this->ReadPropertyInteger('TankCapacityG') . ' g)');
    }

    // ================= Helfer =================
    private function catList(): array
    {
        $cats = json_decode($this->ReadPropertyString('Cats'), true);
        if (!is_array($cats)) {
            return [];
        }
        return array_slice(array_values($cats), 0, self::MAX_CATS);
    }

    private function catName(int $idx): string
    {
        $cats = $this->catList();
        return (string)($cats[$idx - 1]['Name'] ?? ('Katze ' . $idx));
    }

    private function catIndexByName(string $name): int
    {
        foreach ($this->catList() as $i => $cat) {
            if (strcasecmp(trim((string)$cat['Name']), trim($name)) === 0) {
                return $i + 1;
            }
        }
        return 0;
    }

    private function anyPresentCat(): int
    {
        foreach ($this->catList() as $i => $cat) {
            if ($this->GetValue('Cat' . ($i + 1) . 'Present')) {
                return $i + 1;
            }
        }
        return 0;
    }

    private function addDayLog(int $idx, int $grams): void
    {
        $log = json_decode($this->ReadAttributeString('DayLog'), true) ?: [];
        $h = date('G');
        if (!isset($log[$h]) || !is_array($log[$h])) {
            $log[$h] = [];
        }
        $log[$h][(string)$idx] = (int)($log[$h][(string)$idx] ?? 0) + $grams;
        $this->WriteAttributeString('DayLog', json_encode($log));
    }

    private function notify(string $msg): void
    {
        $sid = $this->ReadPropertyInteger('NotifyScriptID');
        if ($sid > 0 && IPS_ScriptExists($sid)) {
            @IPS_RunScriptEx($sid, ['MESSAGE' => $msg]);
        }
        $this->LogMessage('CatFeeder: ' . $msg, KL_WARNING);
    }

    private function logFeed(string $msg): void
    {
        $this->SetValue('FeedLog', date('d.m. H:i') . ' ' . $msg);
        $this->SendDebug('FEED', $msg, 0);
    }

    private function publish(string $topic, string $payload, bool $retain): void
    {
        $this->SendDebug('TX', $topic . ' = ' . $payload, 0);
        $this->SendDataToParent(json_encode([
            'DataID'           => self::GUID_MQTT_TX,
            'PacketType'       => 3,        // PUBLISH
            'QualityOfService' => 0,
            'Retain'           => $retain,
            'Topic'            => $topic,
            'Payload'          => $payload
        ]));
    }

    private function decodePayload($raw): string
    {
        $p = (string)$raw;
        if ($p !== '' && ctype_xdigit($p) && strlen($p) % 2 === 0) {
            $bin = @hex2bin($p);
            if ($bin !== false && preg_match('//u', $bin) && (json_decode($bin) !== null || !is_numeric($p))) {
                return $bin;
            }
        }
        return $p;
    }

    private function enableArchiving(): void
    {
        $archList = IPS_GetInstanceListByModuleID(self::GUID_ARCHIVE);
        if (count($archList) === 0) {
            return;
        }
        $arch = $archList[0];

        $idents = ['SystemState', 'PortionsToday', 'GramsToday', 'TankRemaining', 'UnknownToday',
                   'FeederOnline', 'BridgeOnline', 'SuspectedEmpty', 'Throttled', 'Esp32Online', 'Esp32Rssi',
                   'FeedLog'];   // String-Log -> Archiv = lueckenloses Vorgangs-Protokoll
        foreach ($this->catList() as $i => $cat) {
            $n = $i + 1;
            $idents[] = "Cat{$n}EatenToday";
            $idents[] = "Cat{$n}Visits";
            $idents[] = "Cat{$n}Present";
        }

        $changed = false;
        foreach ($idents as $ident) {
            $vid = @$this->GetIDForIdent($ident);
            if ($vid === false || $vid <= 0) {
                continue;
            }
            if (!AC_GetLoggingStatus($arch, $vid)) {
                AC_SetLoggingStatus($arch, $vid, true);
                AC_SetAggregationType($arch, $vid, 0);
                $changed = true;
            }
        }
        if ($changed) {
            IPS_ApplyChanges($arch);
            $this->SendDebug('ARCHIV', 'Logging fuer Statusvariablen aktiviert', 0);
        }
    }

    private function ensureProfileInt(string $name, string $suffix): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1);
        }
        IPS_SetVariableProfileText($name, '', $suffix);
    }

    private function ensurePresentProfile(): void
    {
        $name = 'CFD.Present';
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 0);
        }
        IPS_SetVariableProfileAssociation($name, 0, 'abwesend', '', 0x808080);
        IPS_SetVariableProfileAssociation($name, 1, 'am Napf', '', 0x00A0FF);
    }

    private function ensureSystemStateProfile(): void
    {
        $name = 'CFD.State';
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1);
        }
        IPS_SetVariableProfileAssociation($name, 0, 'Bereit', '', 0x00A000);
        IPS_SetVariableProfileAssociation($name, 1, 'Tageslimit erreicht', '', 0xFFA500);
        IPS_SetVariableProfileAssociation($name, 2, 'Pausiert', '', 0xFFA500);
        IPS_SetVariableProfileAssociation($name, 3, 'Futter leer/blockiert', '', 0xFF0000);
        IPS_SetVariableProfileAssociation($name, 4, 'Feeder offline', '', 0xFF0000);
        IPS_SetVariableProfileAssociation($name, 5, 'Bridge offline', '', 0xFF0000);
    }

    /** Zentralen System-Status neu berechnen (Prioritaet: hart vor weich). */
    private function refreshSystemState(): void
    {
        if (!$this->GetValue('BridgeOnline')) {
            $s = 5;
        } elseif (!$this->GetValue('FeederOnline')) {
            $s = 4;
        } elseif ($this->GetValue('SuspectedEmpty')) {
            $s = 3;
        } elseif ($this->GetValue('Paused')) {
            $s = 2;
        } elseif ($this->GetValue('Throttled')) {
            $s = 1;
        } else {
            $s = 0;
        }
        if ($this->GetValue('SystemState') !== $s) {
            $this->SetValue('SystemState', $s);
        }
    }

    private function ensurePausedProfile(): void
    {
        $name = 'CFD.Paused';
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 0);
        }
        IPS_SetVariableProfileAssociation($name, 0, 'aktiv', '', 0x00A000);
        IPS_SetVariableProfileAssociation($name, 1, 'pausiert', '', 0xFFA500);
    }

    // ================= WebHook / Dashboard =================
    private function RegisterHook(string $Hook): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::GUID_WEBHOOK);
        if (count($ids) === 0) {
            return;
        }
        $hookID = $ids[0];
        $hooks = json_decode(IPS_GetProperty($hookID, 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }
        foreach ($hooks as $h) {
            if (($h['Hook'] ?? '') === $Hook && (int)($h['TargetID'] ?? 0) === $this->InstanceID) {
                return;
            }
        }
        $hooks = array_values(array_filter($hooks, fn ($h) => ($h['Hook'] ?? '') !== $Hook));
        $hooks[] = ['Hook' => $Hook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($hookID, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($hookID);
    }

    public function ProcessHookData()
    {
        $action = $_GET['action'] ?? '';

        if ($action === 'status') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->buildStatusData());
            return;
        }
        if ($action === 'log') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->buildLogData());
            return;
        }
        if ($action === 'cmd') {
            header('Content-Type: application/json; charset=utf-8');
            $payload = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($payload)) {
                echo json_encode(['ok' => false, 'error' => 'BAD']);
                return;
            }
            $pin = $this->ReadPropertyString('PinCode');
            if ($pin !== '' && (string)($payload['pin'] ?? '') !== $pin) {
                echo json_encode(['ok' => false, 'error' => 'PIN']);
                return;
            }
            $cmd = (string)($payload['cmd'] ?? '');
            $val = $payload['value'] ?? null;
            $ok  = true;
            $msg = '';
            try {
                switch ($cmd) {
                    case 'dispense':
                        $p = max(1, min(self::MANUAL_MAX, (int)($val ?? 1)));
                        $this->Dispense($p);
                        $msg = $p . ' Portion(en) angefordert';
                        break;
                    case 'pause':
                        $this->RequestAction('Paused', (bool)$val);
                        $msg = (bool)$val ? 'Fütterung pausiert' : 'Fütterung aktiv';
                        break;
                    case 'tank_full':
                        $this->TankFilled();
                        $msg = 'Tank-Zähler zurückgesetzt';
                        break;
                    case 'reset':
                        $this->ResetDay();
                        $msg = 'Zähler + Tageslimit zurückgesetzt';
                        break;
                    case 'set_budget':
                        $idx = (int)($payload['cat'] ?? 0);
                        if ($idx < 1 || $idx > count($this->catList())) {
                            throw new Exception('Ungültige Katze');
                        }
                        $this->RequestAction("Cat{$idx}Budget", (int)$val);
                        $msg = 'Budget gesetzt';
                        break;
                    default:
                        $ok = false;
                        $msg = 'Unbekanntes Kommando';
                }
            } catch (Exception $e) {
                $ok = false;
                $msg = $e->getMessage();
            }
            echo json_encode(['ok' => $ok, 'msg' => $msg]);
            return;
        }

        // Dashboard ausliefern
        $html = @file_get_contents(__DIR__ . '/dashboard.html');
        if ($html === false) {
            http_response_code(404);
            echo 'dashboard.html fehlt';
            return;
        }
        $html = str_replace(
            ['__HOOK__', '__TITLE__', '__SUBTITLE__'],
            [
                '/hook/catfeeder' . $this->InstanceID,
                htmlspecialchars($this->ReadPropertyString('DashboardTitle')),
                htmlspecialchars($this->ReadPropertyString('DashboardSubtitle')),
            ],
            $html
        );
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    private function buildStatusData(): array
    {
        $this->resetIfNewDay();
        $cats = [];
        foreach ($this->catList() as $i => $cat) {
            $n = $i + 1;
            $cats[] = [
                'name'       => (string)$cat['Name'],
                'eaten'      => $this->GetValue("Cat{$n}EatenToday"),
                'budget'     => $this->GetValue("Cat{$n}Budget"),
                'visits'     => $this->GetValue("Cat{$n}Visits"),
                'last_visit' => $this->GetValue("Cat{$n}LastVisit"),
                'present'    => $this->GetValue("Cat{$n}Present"),
            ];
        }

        $cap = max(1, $this->ReadPropertyInteger('TankCapacityG'));
        $dayLog = json_decode($this->ReadAttributeString('DayLog'), true) ?: [];
        $hourly = [];
        for ($h = 6; $h <= 22; $h++) {
            $row = ['h' => (string)$h];
            foreach ($this->catList() as $i => $cat) {
                $row['c' . ($i + 1)] = (int)($dayLog[(string)$h][(string)($i + 1)] ?? 0);
            }
            $hourly[] = $row;
        }

        $thieves = [];
        foreach (json_decode($this->ReadAttributeString('Thieves'), true) ?: [] as $t) {
            if ((string)$t[1] === '0000000000') {
                continue;   // Alt-Einträge aus Rausch-Frames ausblenden
            }
            $thieves[] = ['ts' => (int)$t[0], 'tag' => (string)$t[1]];
        }

        return [
            'cats'   => $cats,
            'feeder' => [
                'online'        => $this->GetValue('FeederOnline'),
                'bridge_online' => $this->GetValue('BridgeOnline'),
                'empty'         => $this->GetValue('SuspectedEmpty'),
                'paused'        => $this->GetValue('Paused'),
                'last_dispense' => $this->GetValue('LastDispense'),
                'portions_today'=> $this->GetValue('PortionsToday'),
                'grams_today'   => $this->GetValue('GramsToday'),
                'portion_g'     => $this->ReadPropertyInteger('PortionGrams'),
                'max_manual'    => self::MANUAL_MAX,
                'throttled'     => $this->GetValue('Throttled'),
                'throttle_reason' => $this->ReadAttributeString('ThrottleReason'),
                'tank_g'        => $this->GetValue('TankRemaining'),
                'tank_pct'      => (int)round($this->GetValue('TankRemaining') / $cap * 100),
            ],
            'esp' => [
                'online'    => $this->GetValue('Esp32Online'),
                'rssi'      => $this->GetValue('Esp32Rssi'),
                'last_seen' => $this->GetValue('Esp32LastSeen'),
            ],
            'hourly'       => $hourly,
            'thieves'      => $thieves,
            'unknown_today'=> $this->GetValue('UnknownToday'),
            'pin_required' => $this->ReadPropertyString('PinCode') !== '',
            'version'      => $this->moduleVersion(),
            'ts'           => time(),
        ];
    }

    /** Version/Build aus der library.json — eine Quelle, keine Missverstaendnisse. */
    private function moduleVersion(): string
    {
        $lib = @json_decode((string)@file_get_contents(__DIR__ . '/../library.json'), true);
        if (!is_array($lib)) {
            return '';
        }
        return 'v' . ($lib['version'] ?? '?') . ' · Build ' . ($lib['build'] ?? '?');
    }

    private function buildLogData(): array
    {
        $archList = IPS_GetInstanceListByModuleID(self::GUID_ARCHIVE);
        $vid = @$this->GetIDForIdent('FeedLog');
        if (count($archList) === 0 || $vid === false || $vid <= 0) {
            return [];
        }
        $vals = @AC_GetLoggedValues($archList[0], $vid, time() - 30 * 86400, time(), 300);
        if (!is_array($vals)) {
            return [];
        }
        $out = [];
        foreach ($vals as $v) {
            $msg = trim((string)$v['Value']);
            if ($msg !== '') {
                $out[] = ['ts' => (int)$v['TimeStamp'], 'msg' => $msg];
            }
        }
        return $out;
    }
}
