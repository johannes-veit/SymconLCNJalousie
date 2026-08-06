<?php
/**
 * Jalousiesteuerung LCN / IP-Symcon 9.0
 * V11.7 - Diagnose, Kompatibilitaets- und Installationspruefung
 *
 * Die Diagnose prueft die formale Symcon-/LCN-Konfiguration. Sie ersetzt
 * keine reale Inbetriebnahme mit Motor, Relaisrueckmeldung und LCN-Busmonitor.
 */

declare(strict_types=1);

const JD_EXECUTE_PARENT = '{7938A5A2-0981-5FE0-BE6C-8AA610D654EB}';

$scriptID = (int) ($_IPS['SELF'] ?? 0);
if ($scriptID <= 0 || !IPS_ScriptExists($scriptID)) {
    throw new RuntimeException('SELF ist keine gueltige Skript-ID.');
}
$rootID = IPS_GetParent(IPS_GetParent($scriptID));
$errors = [];
$warnings = [];
$info = [];

if (version_compare(IPS_GetKernelVersion(), '9.0', '<')) {
    JD_Add($errors, 'Mindestens Symcon 9.0 erforderlich; erkannt: ' . IPS_GetKernelVersion());
}
if (PHP_VERSION_ID < 80500) {
    JD_Add($errors, 'PHP 8.5 oder neuer erforderlich; erkannt: ' . PHP_VERSION);
}
if (IPS_GetKernelRunlevel() !== 10103) {
    JD_Add($errors, 'Kernel ist nicht im Runlevel KR_READY (10103).');
}
if (!function_exists('hrtime')) {
    JD_Add($errors, 'PHP-Funktion hrtime() fehlt; monotone Zeitmessung ist nicht moeglich.');
}

function JD_ID(int $rootID, string $catIdent, string $ident): int|false
{
    $cat = @IPS_GetObjectIDByIdent($catIdent, $rootID);
    return $cat === false ? false : @IPS_GetObjectIDByIdent($ident, (int) $cat);
}

function JD_Add(array &$target, string $text): void
{
    $target[] = $text;
}

function JD_ValidateTS(string $data): bool
{
    return preg_match('/^(K---|-K--|--K-|---K)[01]{8}$/', $data) === 1
        && substr_count(substr($data, 4), '1') === 1;
}

function JD_InstanceChain(int $instanceID): array
{
    $chain = [];
    $seen = [];
    $current = $instanceID;
    for ($i = 0; $i < 12 && $current > 0; $i++) {
        if (isset($seen[$current]) || !IPS_InstanceExists($current)) {
            break;
        }
        $seen[$current] = true;
        $instance = IPS_GetInstance($current);
        $chain[] = [
            'id' => $current,
            'name' => IPS_GetName($current),
            'status' => (int) $instance['InstanceStatus'],
            'module' => (string) ($instance['ModuleInfo']['ModuleName'] ?? ''),
            'type' => (int) ($instance['ModuleInfo']['ModuleType'] ?? -1),
        ];
        $current = (int) ($instance['ConnectionID'] ?? 0);
    }
    return $chain;
}

function JD_FirstParentInstance(int $objectID): int|false
{
    $current = IPS_GetParent($objectID);
    for ($i = 0; $i < 12 && $current > 0; $i++) {
        if (IPS_InstanceExists($current)) {
            return $current;
        }
        $current = IPS_GetParent($current);
    }
    return false;
}

function JD_ChainContains(int $instanceID, int $targetID): bool
{
    foreach (JD_InstanceChain($instanceID) as $node) {
        if ((int) $node['id'] === $targetID) {
            return true;
        }
    }
    return false;
}

function JD_CheckVariable(int $rootID, string $category, string $ident, int $type, array &$errors): int|false
{
    $id = JD_ID($rootID, $category, $ident);
    if ($id === false || !IPS_VariableExists((int) $id)) {
        JD_Add($errors, 'Variable fehlt: ' . $category . '/' . $ident);
        return false;
    }
    $variable = IPS_GetVariable((int) $id);
    if ((int) $variable['VariableType'] !== $type) {
        JD_Add($errors, 'Falscher Variablentyp: ' . $category . '/' . $ident
            . ' (ist ' . (int) $variable['VariableType'] . ', erwartet ' . $type . ')');
    }
    return (int) $id;
}

function JD_CheckLcnModule(int $instanceID, string $label, array &$errors, array &$warnings, array &$info): void
{
    if (!IPS_InstanceExists($instanceID)) {
        JD_Add($errors, $label . ' existiert nicht: ID ' . $instanceID);
        return;
    }
    $instance = IPS_GetInstance($instanceID);
    $moduleName = (string) ($instance['ModuleInfo']['ModuleName'] ?? '');
    $moduleType = (int) ($instance['ModuleInfo']['ModuleType'] ?? -1);
    $status = (int) $instance['InstanceStatus'];
    JD_Add($info, $label . ': ' . IPS_GetLocation($instanceID) . ' / ' . $moduleName
        . ' (ID ' . $instanceID . ', Typ ' . $moduleType . ', Status ' . $status . ')');
    if ($status !== 102) {
        JD_Add($errors, $label . ' ist nicht aktiv (Status ' . $status . ', erwartet 102).');
    }
    if ($moduleType !== 2) {
        JD_Add($errors, $label . ' muss die LCN-Modul-/Splitterinstanz sein (Modultyp 2), nicht eine Relais-/Ausgangs-Geraeteinstanz.');
    }
    if (stripos($moduleName, 'LCN') === false) {
        JD_Add($warnings, $label . ': Modulname enthaelt nicht "LCN" (' . $moduleName . ').');
    }
    foreach (JD_InstanceChain($instanceID) as $node) {
        JD_Add($info, 'Verbindungskette ' . $label . ': ID ' . $node['id'] . ' - '
            . $node['name'] . ' / ' . $node['module'] . ' / Status ' . $node['status']);
        if ($node['status'] !== 102) {
            JD_Add($errors, 'Inaktive/fehlerhafte Instanz in der Verbindungskette von ' . $label
                . ': ID ' . $node['id'] . ', Status ' . $node['status']);
        }
    }
}

$requiredCategories = [
    '01_Konfiguration', '02_LCN_Status', '03_Bedienung', '04_Istwerte',
    '05_Intern', '06_Skripte', '07_Visualisierung', '08_Abnahme',
];
foreach ($requiredCategories as $ident) {
    $id = @IPS_GetObjectIDByIdent($ident, $rootID);
    if ($id === false || !IPS_CategoryExists((int) $id)) {
        JD_Add($errors, 'Kategorie fehlt oder ist vom falschen Objekttyp: ' . $ident);
    }
}

$variableSchema = [
    '01_Konfiguration' => [
        'Projektname' => 3, 'Modul_Aktiv' => 0,
        'LCN_Sendemodulinstanz_ID' => 1,
        'LCN_Aktormodulinstanz_ID' => 1,
        'Relais_AUF_ID' => 1, 'Relais_AB_ID' => 1,
        'GT8_LANG_AUF_ID' => 1, 'GT8_LANG_AB_ID' => 1,
        'TS_KURZ_AUF' => 3, 'TS_KURZ_AB' => 3,
        'Gesamtlaufzeit_ms' => 1, 'Wendezeit_ms' => 1,
        'Sanftanlauf_ms' => 1, 'Sanftstopp_AUF_ms' => 1, 'Sanftstopp_ZU_ms' => 1,
        'Behanglaufzeit_ms' => 1, 'Referenzreserve_ms' => 1,
        'MaxFahrt_ms' => 1, 'ShakeFree_ms' => 1, 'ShakeFree_Pause_ms' => 1,
        'Kalibrierfenster_ms' => 1,
        'Relaisbestaetigung_ms' => 1, 'Stoppbestaetigung_ms' => 1,
        'Spaetstart_Schutz_ms' => 1, 'Workerfenster_ms' => 1,
        'Positionstoleranz' => 2, 'Lamellentoleranz' => 2,
        'Unreferenziert_erlauben' => 0, 'Diagnose_Log' => 0,
        'Statusabfrage_beim_Start' => 0, 'TS_Belegung_bestaetigt' => 0,
        'Statussync_ms' => 1, 'Relais_Koaleszenz_ms' => 1,
        'Befehlsabstand_ms' => 1, 'Healthcheck_s' => 1,
    ],
    '03_Bedienung' => [
        'Soll_Behang' => 1, 'Soll_Lamelle' => 1, 'ShakeFree_Aktiv' => 0,
        'Stopp' => 0, 'Referenzfahrt' => 1,
    ],
    '04_Istwerte' => [
        'Ist_Behang' => 2, 'Ist_Lamelle' => 2, 'Fahrstatus' => 1,
        'Phase' => 1, 'Position_Referenziert' => 0, 'Referenz_Endlage' => 1,
        'Letzte_Referenzierung' => 1, 'Automatik_Aktiv' => 0,
        'Fehlertext' => 3, 'Letzte_Aktion' => 3,
        'Letzte_Fahrtdauer_ms' => 1, 'Letzte_Statusmeldung' => 1,
        'Letzte_Relais_AUS_Bestaetigung' => 1, 'Fehler_Verriegelt' => 0,
    ],
    '05_Intern' => [
        'Auftragsnummer' => 1, 'Auftragstyp' => 1, 'Erwartete_Richtung' => 1,
        'Startzeit_ms' => 2, 'Start_Behang' => 2, 'Start_Lamelle' => 2,
        'Start_Richtung' => 1, 'Geplante_Dauer_ms' => 1, 'Zielzeit_ms' => 2,
        'Ziel_Behang' => 2, 'Ziel_Lamelle' => 2,
        'Folge_Lamelle' => 1, 'Folge_Richtung' => 1,
        'Stop_Angefordert' => 0, 'Endlage_Hart' => 0,
        'Bestaetigung_bis_ms' => 2, 'Stop_bis_ms' => 2,
        'Abbruch_bis_ms' => 2, 'Abbruch_Wartet_Auf_Start' => 0,
        'Abbruch_Fehlerphase' => 0,
        'Pending_Aktion' => 1, 'Pending_Wert' => 2, 'Pending_Richtung' => 1,
        'Worker_Aktiv' => 0, 'Kernel_Startzeit' => 1, 'Sync_bis_ms' => 2,
        'Sync_Relais_AUF_Empfangen' => 0, 'Sync_Relais_AB_Empfangen' => 0,
        'Shake_Nachlauf_Aktiv' => 0,
        'Startstatus_Nachfrage_Aktiv' => 0, 'Stopstatus_Nachfrage_Aktiv' => 0,
        'Startstatus_Relais_AUF_Empfangen' => 0, 'Startstatus_Relais_AB_Empfangen' => 0,
        'Stopstatus_Relais_AUF_Empfangen' => 0, 'Stopstatus_Relais_AB_Empfangen' => 0,
        'Stop_Wiederholung_Gesendet' => 0, 'Befehl_gesendet_ms' => 2,
    ],
];
foreach ($variableSchema as $category => $variables) {
    foreach ($variables as $ident => $type) {
        JD_CheckVariable($rootID, $category, $ident, $type, $errors);
    }
}

if ($errors === []) {
    $sendModuleID = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'LCN_Sendemodulinstanz_ID'));
    $actorModuleID = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'LCN_Aktormodulinstanz_ID'));
    $relayUpID = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Relais_AUF_ID'));
    $relayDownID = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Relais_AB_ID'));
    $gt8UpID = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'GT8_LANG_AUF_ID'));
    $gt8DownID = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'GT8_LANG_AB_ID'));
    $tsUp = GetValueString((int) JD_ID($rootID, '01_Konfiguration', 'TS_KURZ_AUF'));
    $tsDown = GetValueString((int) JD_ID($rootID, '01_Konfiguration', 'TS_KURZ_AB'));

    if (!IPS_FunctionExists('LCN_SendCommand')) {
        JD_Add($errors, 'LCN_SendCommand ist im Kernel nicht verfuegbar. LCN-Modul/Installation pruefen.');
    }
    $requestStatusOnStart = GetValueBoolean((int) JD_ID($rootID, '01_Konfiguration', 'Statusabfrage_beim_Start'));
    if ($requestStatusOnStart && !IPS_FunctionExists('LCN_RequestStatus')) {
        JD_Add($errors, 'LCN_RequestStatus ist im Kernel nicht verfuegbar, obwohl die Startabfrage aktiviert ist.');
    }

    JD_CheckLcnModule($sendModuleID, 'LCN-Sendemodulinstanz (TS)', $errors, $warnings, $info);
    JD_CheckLcnModule($actorModuleID, 'LCN-Aktormodulinstanz (Relaisstatus)', $errors, $warnings, $info);

    $statusVariables = [
        'Relais AUF' => [$relayUpID, $actorModuleID],
        'Relais AB' => [$relayDownID, $actorModuleID],
        'GT8 LANG AUF' => [$gt8UpID, $sendModuleID],
        'GT8 LANG AB' => [$gt8DownID, $sendModuleID],
    ];
    foreach ($statusVariables as $name => [$id, $expectedModuleID]) {
        if (!IPS_VariableExists($id)) {
            JD_Add($errors, $name . ': Variable existiert nicht, ID ' . $id);
            continue;
        }
        $variable = IPS_GetVariable($id);
        if ((int) $variable['VariableType'] !== 0) {
            JD_Add($errors, $name . ': Variable muss Boolean sein, ID ' . $id);
        } else {
            JD_Add($info, $name . ': ' . IPS_GetLocation($id) . ' (ID ' . $id
                . ', Wert ' . (GetValueBoolean($id) ? 'TRUE' : 'FALSE') . ')');
        }
        $parentInstance = JD_FirstParentInstance($id);
        if ($parentInstance === false) {
            JD_Add($warnings, $name . ': Keine Elterninstanz gefunden. Echte LCN-Statusvariable pruefen.');
        } elseif (!JD_ChainContains((int) $parentInstance, $expectedModuleID)) {
            JD_Add($warnings, $name . ': Instanzkette fuehrt nicht zur konfigurierten LCN-Modulinstanz ID '
                . $expectedModuleID . '. Objektzuordnung pruefen.');
        }
    }

    $lcnCategoryID = @IPS_GetObjectIDByIdent('02_LCN_Status', $rootID);
    if ($lcnCategoryID !== false) {
        foreach ([
            'Relais_AUF' => $relayUpID,
            'Relais_AB' => $relayDownID,
            'GT8_LANG_AUF' => $gt8UpID,
            'GT8_LANG_AB' => $gt8DownID,
        ] as $linkIdent => $expectedTarget) {
            $linkID = @IPS_GetObjectIDByIdent($linkIdent, (int) $lcnCategoryID);
            if ($linkID === false || !IPS_LinkExists((int) $linkID)) {
                JD_Add($errors, 'LCN-Statuslink fehlt: ' . $linkIdent);
            } elseif ((int) IPS_GetLink((int) $linkID)['TargetID'] !== $expectedTarget) {
                JD_Add($errors, 'LCN-Statuslink ' . $linkIdent . ' zeigt nicht auf die konfigurierte Variable ID ' . $expectedTarget . '.');
            }
        }
    }

    if ($relayUpID === $relayDownID || $gt8UpID === $gt8DownID) {
        JD_Add($errors, 'AUF/AB-IDs duerfen nicht identisch sein.');
    }
    if (IPS_VariableExists($relayUpID) && IPS_VariableExists($relayDownID)
        && GetValueBoolean($relayUpID) && GetValueBoolean($relayDownID)) {
        JD_Add($errors, 'Relais AUF und AB melden gleichzeitig TRUE. LCN-Verriegelung pruefen.');
    }

    foreach (['TS_KURZ_AUF' => $tsUp, 'TS_KURZ_AB' => $tsDown] as $name => $value) {
        if (!JD_ValidateTS($value)) {
            JD_Add($errors, $name . ' ist ungueltig: "' . $value
                . '". Erwartet: genau ein K in Tabelle A-D und genau eine Taste.');
        } else {
            JD_Add($info, $name . ': ' . $value);
        }
    }
    if ($tsUp === $tsDown) {
        JD_Add($errors, 'TS_KURZ_AUF und TS_KURZ_AB sind identisch.');
    }
    $tsConfirmed = GetValueBoolean((int) JD_ID($rootID, '01_Konfiguration', 'TS_Belegung_bestaetigt'));
    if (!$tsConfirmed) {
        JD_Add($errors, 'TS-Belegung ist nicht vor Ort bestaetigt. Produktivfreigabe ist gesperrt.');
    }

    $turn = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Wendezeit_ms'));
    $softStart = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Sanftanlauf_ms'));
    $softStopUp = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Sanftstopp_AUF_ms'));
    $softStopDown = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Sanftstopp_ZU_ms'));
    // Kompatible Idents: Gesamtlaufzeit_ms = 100 % ZU -> 0 % AUF inkl.
    // Wendezeit; Behanglaufzeit_ms = 0 % AUF -> 100 % ZU.
    $totalUp = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Gesamtlaufzeit_ms'));
    $totalDown = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Behanglaufzeit_ms'));
    $blindUp = $totalUp - $turn;
    $blindDown = $totalDown;
    $reserve = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Referenzreserve_ms'));
    $max = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'MaxFahrt_ms'));
    $shake = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'ShakeFree_ms'));
    $shakePause = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'ShakeFree_Pause_ms'));
    $calibrationWindow = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Kalibrierfenster_ms'));
    $startConfirm = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Relaisbestaetigung_ms'));
    $stopConfirm = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Stoppbestaetigung_ms'));
    $guard = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Spaetstart_Schutz_ms'));
    $window = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Workerfenster_ms'));
    $syncMs = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Statussync_ms'));
    $coalesceMs = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Relais_Koaleszenz_ms'));
    $commandSpacingMs = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Befehlsabstand_ms'));
    $healthSeconds = GetValueInteger((int) JD_ID($rootID, '01_Konfiguration', 'Healthcheck_s'));
    $moduleEnabled = GetValueBoolean((int) JD_ID($rootID, '01_Konfiguration', 'Modul_Aktiv'));
    $faultLatched = GetValueBoolean((int) JD_ID($rootID, '04_Istwerte', 'Fehler_Verriegelt'));
    JD_Add($info, 'Symcon-Steuerung: ' . ($moduleEnabled ? 'aktiv konfiguriert' : 'im Modulmenue deaktiviert'));
    JD_Add($info, 'Fehlerverriegelung: ' . ($faultLatched ? 'AKTIV – Quittierung erforderlich' : 'nicht aktiv'));

    if ($turn <= 0 || $softStart < 0 || $softStopUp < 0 || $softStopDown < 0 || $totalUp <= 0 || $totalDown <= 0 || $blindUp <= 0 || $blindDown <= 0 || $max <= 0) {
        JD_Add($errors, 'Wendezeit, beide Richtungs-Gesamtzeiten, abgeleitete Behanglaufzeiten und Maximalfahrzeit muessen positiv sein; Sanftanlauf und Sanft-Stopp duerfen 0 sein.');
    }
    if ($softStart > $turn) {
        JD_Add($errors, 'Sanftanlauf_ms darf die volle Wendezeit nicht ueberschreiten.');
    }
    if ($totalUp <= $turn) {
        JD_Add($errors, 'Gesamtzeit 100→0 AUF muss groesser als die volle Wendezeit sein.');
    }
    if ($softStopUp >= $blindUp || $softStopDown >= $blindDown) {
        JD_Add($errors, 'Sanftstopp_AUF_ms und Sanftstopp_ZU_ms muessen jeweils kleiner als die zugehoerige Behanglaufzeit sein.');
    }
    $softStopUpPercent = ($blindUp > 0 && $softStopUp > 0 && $softStopUp < $blindUp)
        ? 100.0 * $softStopUp / (2.0 * $blindUp - $softStopUp)
        : 0.0;
    $softStopDownPercent = ($blindDown > 0 && $softStopDown > 0 && $softStopDown < $blindDown)
        ? 100.0 * $softStopDown / (2.0 * $blindDown - $softStopDown)
        : 0.0;
    JD_Add($info, 'Gesamtzeit 100→0 AUF inkl. Wendezeit: ' . $totalUp . ' ms; abgeleitete Behanglaufzeit AUF: ' . $blindUp . ' ms; Sanft-Stopp AUF: ' . $softStopUp . ' ms = Fahrweg 0–' . number_format($softStopUpPercent, 2, ',', '.') . ' %.');
    JD_Add($info, 'Gesamtzeit 0→100 ZU: ' . $totalDown . ' ms; abgeleitete Behanglaufzeit ZU: ' . $blindDown . ' ms; Sanft-Stopp ZU: ' . $softStopDown . ' ms = Fahrweg ' . number_format(100.0 - $softStopDownPercent, 2, ',', '.') . '–100 %.');
    if (max($totalUp, $totalDown) + $reserve !== $max) {
        JD_Add($warnings, 'MaxFahrt entspricht nicht der laengeren Richtungs-Gesamtzeit + Referenzreserve.');
    }
    if ($shakePause < 0 || $shakePause > 3000) {
        JD_Add($errors, 'ShakeFree_Pause_ms muss zwischen 0 und 3000 ms liegen.');
    }
    if ($calibrationWindow < 30000 || $calibrationWindow > 120000) {
        JD_Add($errors, 'Zeitverzoegerung/Kalibrierfenster_ms muss zwischen 30000 und 120000 ms liegen.');
    }
    if ($shake !== $turn) {
        JD_Add($warnings, 'ShakeFree nach Endlage ZU: ShakeFree_ms weicht von Wendezeit_ms ab. Laut Funktionsvorgabe ist die Gegenfahrt exakt eine Wendezeit.');
    }
    if ($startConfirm < 500 || $startConfirm > 10000) {
        JD_Add($warnings, 'Relaisbestaetigung_ms liegt ausserhalb 500...10000 ms.');
    }
    if ($stopConfirm < 500 || $stopConfirm > 10000) {
        JD_Add($warnings, 'Stoppbestaetigung_ms liegt ausserhalb 500...10000 ms.');
    }
    if ($guard < max($startConfirm, $stopConfirm)) {
        JD_Add($warnings, 'Spaetstart_Schutz_ms sollte mindestens so gross wie Start- und Stoppbestaetigungszeit sein.');
    }
    if ($window < 1000 || $window > 3000) {
        JD_Add($warnings, 'Workerfenster_ms sollte fuer den 1-s-ScriptTimer zwischen 1000 und 3000 ms liegen.');
    }
    if ($window >= 5000) {
        JD_Add($errors, 'Workerfenster_ms ist zu gross. IPS_Sleep darf nur kurze Wartezeiten abdecken.');
    }
    if ($syncMs < 500 || $syncMs > 10000) { JD_Add($errors, 'Statussync_ms muss zwischen 500 und 10000 ms liegen.'); }
    if ($coalesceMs < 0 || $coalesceMs > 1000) { JD_Add($errors, 'Relais_Koaleszenz_ms muss zwischen 0 und 1000 ms liegen.'); }
    if ($commandSpacingMs < 0 || $commandSpacingMs > 1000) { JD_Add($errors, 'Befehlsabstand_ms muss zwischen 0 und 1000 ms liegen.'); }
    if ($healthSeconds < 10 || $healthSeconds > 300) { JD_Add($errors, 'Healthcheck_s muss zwischen 10 und 300 s liegen.'); }

    $controllerID = JD_ID($rootID, '06_Skripte', 'Controller');
    $workerID = JD_ID($rootID, '06_Skripte', 'Worker');
    $diagnoseID = JD_ID($rootID, '06_Skripte', 'Diagnose');
    $healthID = JD_ID($rootID, '06_Skripte', 'Healthcheck');
    foreach (['Controller' => $controllerID, 'Worker' => $workerID, 'Healthcheck' => $healthID, 'Diagnose' => $diagnoseID] as $name => $id) {
        if ($id === false || !IPS_ScriptExists((int) $id)) {
            JD_Add($errors, $name . '-Skript fehlt oder hat falschen Objekttyp.');
        } elseif (strpos(IPS_GetScriptContent((int) $id), 'V11.7') === false) {
            JD_Add($warnings, $name . '-Skript enthaelt keine V11.7-Kennung. Skriptstand pruefen.');
        }
    }

    if ($healthID !== false && IPS_ScriptExists((int) $healthID) && IPS_GetScriptTimer((int) $healthID) !== $healthSeconds) {
        JD_Add($errors, 'Healthcheck-ScriptTimer ist nicht auf Healthcheck_s eingestellt.');
    }

    if ($controllerID !== false && IPS_ScriptExists((int) $controllerID)) {
        $events = IPS_GetScriptEventList((int) $controllerID);
        $eventByTrigger = [];
        foreach ($events as $eventID) {
            if (!IPS_EventExists((int) $eventID)) {
                JD_Add($errors, 'Ungueltige Ereignis-ID unter dem Controller: ' . $eventID);
                continue;
            }
            $event = IPS_GetEvent((int) $eventID);
            if ((int) ($event['EventType'] ?? -1) !== 0) {
                JD_Add($errors, 'Ereignis ' . IPS_GetName((int) $eventID) . ' muss vom Typ 0 (ausgeloest) sein.');
            }
            if ((string) ($event['EventActionID'] ?? '') !== JD_EXECUTE_PARENT) {
                JD_Add($errors, 'Ereignis ' . IPS_GetName((int) $eventID)
                    . ' verwendet nicht die offizielle Aktion "Fuehre uebergeordnete Automation aus".');
            }
            if (!(bool) ($event['EventActive'] ?? false)) {
                JD_Add($errors, 'Ereignis ist deaktiviert: ' . IPS_GetName((int) $eventID));
            }
            $triggerID = (int) ($event['TriggerVariableID'] ?? 0);
            if ($triggerID > 0) {
                $eventByTrigger[$triggerID][] = (int) $eventID;
            }
        }

        $expectedTriggers = [
            $relayUpID => 0,
            $relayDownID => 0,
            $gt8UpID => 1,
            $gt8DownID => 1,
        ];
        foreach ($expectedTriggers as $variableID => $expectedTriggerType) {
            $found = false;
            foreach ($eventByTrigger[$variableID] ?? [] as $eventID) {
                $event = IPS_GetEvent($eventID);
                if ((int) ($event['TriggerType'] ?? -1) === $expectedTriggerType
                    && (bool) ($event['EventActive'] ?? false)
                    && (string) ($event['EventActionID'] ?? '') === JD_EXECUTE_PARENT) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $triggerName = $expectedTriggerType === 0 ? 'Bei Aktualisierung' : 'Bei Aenderung';
                JD_Add($errors, 'Aktives Ereignis "' . $triggerName
                    . '" mit korrekter Automation-Aktion fehlt fuer Variable ID ' . $variableID . '.');
            }
        }

        foreach (['Soll_Behang', 'Soll_Lamelle', 'ShakeFree_Aktiv', 'Stopp', 'Referenzfahrt'] as $ident) {
            $variableID = JD_ID($rootID, '03_Bedienung', $ident);
            if ($variableID === false) {
                JD_Add($errors, 'Bedienvariable fehlt: ' . $ident);
                continue;
            }
            $variable = IPS_GetVariable((int) $variableID);
            if ((int) ($variable['VariableCustomAction'] ?? 0) !== (int) $controllerID) {
                JD_Add($errors, 'Benutzerdefinierte Aktion von ' . $ident . ' zeigt nicht auf den Controller.');
            }
            if (isset($eventByTrigger[(int) $variableID])) {
                JD_Add($errors, 'Bedienvariable ' . $ident
                    . ' besitzt zusaetzlich ein Triggerereignis. Das wuerde einen Visualisierungsbefehl doppelt ausfuehren.');
            }
        }

        $controllerContent = IPS_GetScriptContent((int) $controllerID);
        foreach ([
            'hrtime(true)' => 'monotone Zeitmessung',
            "IPS_FunctionExists('LCNJAL_SendDirectionCommand')" => 'sichere richtungsgebundene Befehlsfunktion',
            'Sync_Relais_AUF_Empfangen' => 'laufbezogene AUF-Rueckmeldung',
            'Sync_Relais_AB_Empfangen' => 'laufbezogene AB-Rueckmeldung',
            "case 'SYNC_COMPLETE':" => 'Statussync-Abschluss',
            "case 'RELAY_UPDATE':" => 'koaleszierte Relaisauswertung',
            'function J_SetReference' => 'persistente Endlagenreferenz',
            'function J_ReferenceDurationMs' => 'richtungsabhängige Gesamtzeit plus Referenzreserve',
            'function J_RunHealthcheck' => 'unabhängige Deadline- und STOP-Überwachung',
            'Ablaufabschluss verweigert' => 'Relais-AUS-Prüfung vor dem Stillstand',
            'Shake_Nachlauf_Aktiv' => 'überwachter Lamellen-ZU-Nachlauf nach ShakeFree',
            'function J_HardwareBinding' => 'unveränderliche Hardwarebindung aus Modul-Properties',
            'function J_IsConfiguredRelayTrigger' => 'Filter gegen fremde oder veraltete Relaisereignisse',
            'bereits gesendeter STOP wird nicht wiederholt' => 'Schutz gegen doppelten Toggle-STOP',
            'function J_StartCalibrationWindow' => 'Kalibrierfenster erst nach bestätigtem Relais-AUS',
            'Startstatus_Nachfrage_Aktiv' => 'einmalige Startstatus-Nachfrage',
            'Stopstatus_Nachfrage_Aktiv' => 'einmalige Stoppstatus-Nachfrage',
        ] as $needle => $description) {
            if (strpos($controllerContent, $needle) === false) {
                JD_Add($errors, 'Controller enthaelt nicht die erwartete V11.7-Sicherheitsfunktion: ' . $description . '.');
            }
        }
    }

    $storedKernel = GetValueInteger((int) JD_ID($rootID, '05_Intern', 'Kernel_Startzeit'));
    $kernelStart = IPS_GetKernelStartTime();
    if ($storedKernel !== $kernelStart) {
        JD_Add($warnings, 'Controller wurde im aktuellen Kernel noch nicht vollstaendig initialisiert; INITIALIZE/Healthcheck ausfuehren.');
    } elseif ($requestStatusOnStart) {
        $phaseID = (int) JD_ID($rootID, '04_Istwerte', 'Phase');
        $phase = GetValueInteger($phaseID);
        $upReceived = GetValueBoolean((int) JD_ID($rootID, '05_Intern', 'Sync_Relais_AUF_Empfangen'));
        $downReceived = GetValueBoolean((int) JD_ID($rootID, '05_Intern', 'Sync_Relais_AB_Empfangen'));
        if ($phase === 9) {
            JD_Add($warnings, 'Statusabgleich laeuft noch. Empfangsflags: AUF=' . ($upReceived ? 'TRUE' : 'FALSE')
                . ', AB=' . ($downReceived ? 'TRUE' : 'FALSE') . '.');
        } elseif (!$upReceived || !$downReceived) {
            JD_Add($errors, 'Der letzte Statusabgleich besitzt nicht fuer beide Relais eine aktuelle OnUpdate-Rueckmeldung. '
                . 'AUF=' . ($upReceived ? 'TRUE' : 'FALSE') . ', AB=' . ($downReceived ? 'TRUE' : 'FALSE') . '.');
        } else {
            JD_Add($info, 'Letzter Statusabgleich: beide Relais-OnUpdate-Rueckmeldungen empfangen.');
        }
    }
}

$out = [];
$out[] = 'DIAGNOSE JALOUSIE V11.7 - SYMCON 9.0 / PHP 8.5';
$out[] = 'Objekt: ' . IPS_GetLocation($rootID) . ' (ID ' . $rootID . ')';
$out[] = str_repeat('=', 84);
$out[] = '';
$out[] = 'FEHLER (' . count($errors) . ')';
$out = array_merge($out, array_map(static fn(string $v): string => '  - ' . $v, $errors));
$out[] = '';
$out[] = 'WARNUNGEN (' . count($warnings) . ')';
$out = array_merge($out, array_map(static fn(string $v): string => '  - ' . $v, $warnings));
$out[] = '';
$out[] = 'INFORMATIONEN';
$out = array_merge($out, array_map(static fn(string $v): string => '  - ' . $v, $info));
$out[] = '';
$out[] = count($errors) === 0
    ? 'ERGEBNIS: Formale Symcon-/LCN-Konfiguration bestanden. Reale Motor-, Bus- und Abnahmetests bleiben erforderlich.'
    : 'ERGEBNIS: NICHT FREIGEBEN. Fehler beheben und Diagnose erneut ausfuehren.';

$text = implode(PHP_EOL, $out) . PHP_EOL;
echo $text;
IPS_LogMessage('Jalousie Diagnose', str_replace(PHP_EOL, ' | ', $text));
