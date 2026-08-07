<?php
/**
 * Jalousiesteuerung LCN / IP-Symcon 9.0
 * V12.0 - Diagnose fuer kompakte, rollbackfaehige Speicherung
 *
 * Die Diagnose prueft die formale Symcon-/LCN-Konfiguration, die verbleibende
 * Objektstruktur, Runtime-Skripte, Ereignisse und den Migrationszustand.
 * Sie ersetzt keine reale Motor-/Busabnahme.
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

function JD_Add(array &$target, string $text): void { $target[] = $text; }
function JD_ID(int $rootID, string $catIdent, string $ident): int|false
{
    $cat = @IPS_GetObjectIDByIdent($catIdent, $rootID);
    return $cat === false ? false : @IPS_GetObjectIDByIdent($ident, (int) $cat);
}
function JD_CheckVariable(int $rootID, string $category, string $ident, int $type, array &$errors): int|false
{
    $id = JD_ID($rootID, $category, $ident);
    if ($id === false || !IPS_VariableExists((int) $id)) {
        JD_Add($errors, 'Variable fehlt: ' . $category . '/' . $ident);
        return false;
    }
    $actual = (int) (IPS_GetVariable((int) $id)['VariableType'] ?? -1);
    if ($actual !== $type) {
        JD_Add($errors, 'Falscher Variablentyp: ' . $category . '/' . $ident . ' (ist ' . $actual . ', erwartet ' . $type . ')');
    }
    return (int) $id;
}
function JD_VariableCount(int $categoryID): int
{
    $count = 0;
    foreach (IPS_GetChildrenIDs($categoryID) as $childID) {
        if (IPS_VariableExists((int) $childID)) {
            $count++;
        }
    }
    return $count;
}

if (version_compare(IPS_GetKernelVersion(), '9.0', '<')) {
    JD_Add($errors, 'Mindestens Symcon 9.0 erforderlich; erkannt: ' . IPS_GetKernelVersion());
}
if (PHP_VERSION_ID < 80500) {
    JD_Add($errors, 'PHP 8.5 oder neuer erforderlich; erkannt: ' . PHP_VERSION);
}
if (IPS_GetKernelRunlevel() !== 10103) {
    JD_Add($warnings, 'Kernel ist momentan nicht im Runlevel KR_READY (10103).');
}
if (!function_exists('hrtime')) {
    JD_Add($errors, 'PHP-Funktion hrtime() fehlt; monotone Zeitmessung ist nicht moeglich.');
}

foreach (['LCNJAL_GetDiagnostics', 'LCNJAL_CheckConfiguration', 'LCNJAL_GetCompactConfiguration', 'LCNJAL_GetCompactRuntimeState'] as $function) {
    if (!IPS_FunctionExists($function)) {
        JD_Add($errors, 'Modulfunktion fehlt: ' . $function);
    }
}

$diagnostics = [];
if ($errors === []) {
    $decoded = json_decode(LCNJAL_GetDiagnostics($rootID), true);
    if (!is_array($decoded)) {
        JD_Add($errors, 'Moduldiagnose liefert kein gueltiges JSON.');
    } else {
        $diagnostics = $decoded;
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

$compactConfigSchema = [
    'Modul_Aktiv' => 0,
    'Sanftanlauf_ms' => 1,
    'Sanftstopp_AUF_ms' => 1,
    'Sanftstopp_ZU_ms' => 1,
    'Kalibrierfenster_ms' => 1,
    'Befehlsabstand_ms' => 1,
];
$compactRuntimeSchema = [
    'Stop_Wiederholung_Gesendet' => 0,
    'Befehl_gesendet_ms' => 2,
];
$compactConfiguration = [];
$compactRuntime = [];
if (IPS_FunctionExists('LCNJAL_GetCompactConfiguration')) {
    $compactConfiguration = json_decode(LCNJAL_GetCompactConfiguration($rootID), true) ?: [];
    foreach ($compactConfigSchema as $ident => $_type) {
        if (!array_key_exists($ident, $compactConfiguration)) {
            JD_Add($errors, 'Kompakte Konfiguration fehlt: ' . $ident);
        }
    }
}
if (IPS_FunctionExists('LCNJAL_GetCompactRuntimeState')) {
    $compactRuntime = json_decode(LCNJAL_GetCompactRuntimeState($rootID), true) ?: [];
    foreach ($compactRuntimeSchema as $ident => $_type) {
        if (!array_key_exists($ident, $compactRuntime)) {
            JD_Add($errors, 'Kompakter Runtime-Zustand fehlt: ' . $ident);
        }
    }
}

$remainingSchema = [
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
];
foreach ($remainingSchema as $category => $variables) {
    foreach ($variables as $ident => $type) {
        JD_CheckVariable($rootID, $category, $ident, $type, $errors);
    }
}

$runtime = is_array($diagnostics['runtime'] ?? null) ? $diagnostics['runtime'] : [];
if ((int) ($runtime['storageSchemaVersion'] ?? 0) !== 2) {
    JD_Add($errors, 'Kompaktes Speicherschema 2 ist nicht aktiv.');
}
if (!(bool) ($runtime['legacySnapshotValid'] ?? false)) {
    JD_Add($errors, 'Rollback-Snapshot ist nicht verifizierbar.');
}
$rollbackPrepared = (bool) ($runtime['rollbackPrepared'] ?? false);
if (!$rollbackPrepared && !(bool) ($runtime['compactMigrationComplete'] ?? false)) {
    JD_Add($errors, 'Kompaktmigration ist nicht als abgeschlossen markiert.');
}
if (!$rollbackPrepared) {
    if ((int) ($runtime['legacyConfigurationVariableCount'] ?? -1) !== 0) {
        JD_Add($errors, '01 Konfiguration enthaelt nach der Kompaktmigration noch Legacy-Variablen.');
    }
    if ((int) ($runtime['legacyInternalVariableCount'] ?? -1) !== 0) {
        JD_Add($errors, '05 Intern enthaelt nach der Kompaktmigration noch Legacy-Variablen.');
    }
} else {
    JD_Add($warnings, 'Rollback auf V0.1.27 wurde vorbereitet; Legacy-Variablen sind absichtlich wieder vorhanden.');
}

$validation = is_array($diagnostics['validation'] ?? null) ? $diagnostics['validation'] : [];
if ((int) ($validation['status'] ?? 0) !== 102) {
    JD_Add($errors, 'Gespeicherte Konfiguration ist nicht vollstaendig: ' . implode(' | ', (array) ($validation['messages'] ?? [])));
}

$config = is_array($diagnostics['configuration'] ?? null) ? $diagnostics['configuration'] : [];
$binding = is_array($config['HardwareBinding'] ?? null) ? $config['HardwareBinding'] : [];
foreach (['sendModuleID','actorModuleID','relayUpVariableID','relayDownVariableID','gt8LongUpVariableID','gt8LongDownVariableID','tsShortUp','tsShortDown'] as $key) {
    if (!array_key_exists($key, $binding)) {
        JD_Add($errors, 'Hardwarebindung unvollstaendig: ' . $key);
    }
}
if (($binding['relayUpVariableID'] ?? 0) === ($binding['relayDownVariableID'] ?? -1)) {
    JD_Add($errors, 'AUF- und ZU-Relaisvariable sind identisch.');
}

if ($compactConfiguration !== []) {
    $turn = (int) ($compactConfiguration['Wendezeit_ms'] ?? 0);
    $softStart = (int) ($compactConfiguration['Sanftanlauf_ms'] ?? 0);
    $softStopUp = (int) ($compactConfiguration['Sanftstopp_AUF_ms'] ?? 0);
    $softStopDown = (int) ($compactConfiguration['Sanftstopp_ZU_ms'] ?? 0);
    $totalUp = (int) ($compactConfiguration['Gesamtlaufzeit_ms'] ?? 0);
    $totalDown = (int) ($compactConfiguration['Behanglaufzeit_ms'] ?? 0);
    $calibrationWindow = (int) ($compactConfiguration['Kalibrierfenster_ms'] ?? 0);
    $commandSpacingMs = (int) ($compactConfiguration['Befehlsabstand_ms'] ?? 0);

    if ($softStart < 0 || $softStart > $turn) {
        JD_Add($errors, 'Sanftanlauf_ms darf die volle Wendezeit nicht überschreiten.');
    }
    if ($calibrationWindow < 30000 || $calibrationWindow > 120000) {
        JD_Add($errors, 'Zeitverzoegerung/Kalibrierfenster_ms muss zwischen 30000 und 120000 ms liegen.');
    }
    if ($commandSpacingMs < 0 || $commandSpacingMs > 1000) {
        JD_Add($errors, 'Befehlsabstand_ms muss zwischen 0 und 1000 ms liegen.');
    }

    $blindUp = max(0.0, (float) $totalUp - (float) $turn);
    $blindDown = max(0.0, (float) $totalDown);
    if ($softStopUp < 0 || ($blindUp > 0.0 && $softStopUp >= $blindUp)) {
        JD_Add($errors, 'Sanftstopp_AUF_ms ist für die AUF-Laufzeit ungültig.');
    }
    if ($softStopDown < 0 || ($blindDown > 0.0 && $softStopDown >= $blindDown)) {
        JD_Add($errors, 'Sanftstopp_ZU_ms ist für die ZU-Laufzeit ungültig.');
    }
    // Sanftstopp_AUF_ms und Sanftstopp_ZU_ms werden in einen realen
    // Fahrweganteil umgerechnet. Bei linearer Verzögerung gilt die
    // Dreiecksfläche: Anteil = S / (2*T-S).
    $softStopUpPercent = ($blindUp > 0.0 && $softStopUp > 0 && $softStopUp < $blindUp)
        ? 100.0 * $softStopUp / (2.0 * $blindUp - $softStopUp)
        : 0.0;
    $softStopDownPercent = ($blindDown > 0.0 && $softStopDown > 0 && $softStopDown < $blindDown)
        ? 100.0 * $softStopDown / (2.0 * $blindDown - $softStopDown)
        : 0.0;
    JD_Add($info, sprintf(
        'Sanft-Stopp-Fahrweg 0–%.2f %% (AUF) und %.2f–100 %% (ZU).',
        $softStopUpPercent,
        100.0 - $softStopDownPercent
    ));
}

$scriptsCategory = @IPS_GetObjectIDByIdent('06_Skripte', $rootID);
$controllerID = false;
if ($scriptsCategory !== false) {
    foreach (['Controller','Worker','Healthcheck','Diagnose'] as $ident) {
        $id = @IPS_GetObjectIDByIdent($ident, (int) $scriptsCategory);
        if ($id === false || !IPS_ScriptExists((int) $id)) {
            JD_Add($errors, $ident . '-Skript fehlt.');
            continue;
        }
        if (!str_contains(IPS_GetScriptContent((int) $id), 'V12.0')) {
            JD_Add($errors, $ident . '-Skript enthaelt nicht die erwartete V12.0-Kennung.');
        }
        if ($ident === 'Controller') {
            $controllerID = (int) $id;
        }
    }
}

if ($controllerID !== false) {
    $content = IPS_GetScriptContent($controllerID);
    foreach ([
        'function J_RuntimeFlush' => 'transaktionaler kompakter Runtime-Speicher',
        'function J_CompactConfig' => 'direkte Property-Konfiguration ohne Spiegelvariablen',
        'function J_HardwareBinding' => 'sichere Hardwarebindung',
        'function J_RunHealthcheck' => 'unabhaengige Deadline-/STOP-Ueberwachung',
        'bereits gesendeter STOP wird nicht wiederholt' => 'Doppel-Toggle-Schutz',
        'function J_StartCalibrationWindow' => 'Kalibrierfenster nach Relais-AUS',
    ] as $needle => $description) {
        if (!str_contains($content, $needle)) {
            JD_Add($errors, 'Controllerfunktion fehlt: ' . $description . '.');
        }
    }

    $eventByTrigger = [];
    foreach (IPS_GetScriptEventList($controllerID) as $eventID) {
        if (!IPS_EventExists((int) $eventID)) {
            JD_Add($errors, 'Ungueltige Ereignis-ID unter Controller: ' . $eventID);
            continue;
        }
        $event = IPS_GetEvent((int) $eventID);
        if ((string) ($event['EventActionID'] ?? '') !== JD_EXECUTE_PARENT) {
            JD_Add($errors, 'Ereignis ' . IPS_GetName((int) $eventID) . ' verwendet nicht die offizielle Parent-Aktion.');
        }
        $triggerID = (int) ($event['TriggerVariableID'] ?? 0);
        if ($triggerID > 0) {
            $eventByTrigger[$triggerID][] = $event;
        }
    }
    $expectedTriggers = [
        (int) ($binding['relayUpVariableID'] ?? 0) => 0,
        (int) ($binding['relayDownVariableID'] ?? 0) => 0,
        (int) ($binding['gt8LongUpVariableID'] ?? 0) => 1,
        (int) ($binding['gt8LongDownVariableID'] ?? 0) => 1,
    ];
    foreach ($expectedTriggers as $variableID => $triggerType) {
        if ($variableID <= 0) { continue; }
        $found = false;
        foreach ($eventByTrigger[$variableID] ?? [] as $event) {
            if ((int) ($event['TriggerType'] ?? -1) === $triggerType) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            JD_Add($errors, 'Passendes Relais-/GT8-Ereignis fehlt fuer Variable ID ' . $variableID . '.');
        }
    }

    foreach (['Soll_Behang','Soll_Lamelle','ShakeFree_Aktiv','Stopp','Referenzfahrt'] as $ident) {
        $variableID = JD_ID($rootID, '03_Bedienung', $ident);
        if ($variableID === false) {
            continue;
        }
        if ((int) (IPS_GetVariable((int) $variableID)['VariableCustomAction'] ?? 0) !== $controllerID) {
            JD_Add($errors, 'Benutzerdefinierte Aktion von ' . $ident . ' zeigt nicht auf den Controller.');
        }
    }
}

if ((int) ($runtime['phase'] ?? 0) === 0 && (int) ($runtime['driveState'] ?? 0) === 0) {
    JD_Add($info, 'Runtime-Zustand: Stillstand.');
}
if ((bool) ($config['ReferenceValid'] ?? false)) {
    JD_Add($info, 'Positionsreferenz ist gueltig und persistent gespeichert.');
} else {
    JD_Add($warnings, 'Positionsreferenz ist momentan ungueltig; Zwischenpositionen koennen eingeschraenkt sein.');
}
if ((bool) ($config['FaultLatched'] ?? false)) {
    JD_Add($errors, 'Fehler ist verriegelt: ' . (string) ($config['FaultMessage'] ?? ''));
}

$out = [];
$out[] = 'DIAGNOSE JALOUSIE V12.0 - KOMPAKTSPEICHER / SYMCON 9.0 / PHP 8.5';
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
    ? 'ERGEBNIS: Formale Modul-/Speicher-/LCN-Konfiguration bestanden. Reale Motor- und Busabnahme bleibt erforderlich.'
    : 'ERGEBNIS: NICHT FREIGEBEN. Fehler beheben und Diagnose erneut ausfuehren.';

$text = implode(PHP_EOL, $out) . PHP_EOL;
echo $text;
IPS_LogMessage('Jalousie Diagnose', str_replace(PHP_EOL, ' | ', $text));
