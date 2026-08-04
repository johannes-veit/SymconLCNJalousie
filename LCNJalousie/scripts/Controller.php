<?php
/**
 * Jalousiesteuerung LCN / IP-Symcon 9.0
 * V11.5 - Zentraler PHP-Controller
 *
 * Freigabestand fuer Symcon 9.0 / PHP 8.5.
 *
 * Sicherheitsprinzip:
 * - LCN-PRO verriegelt AUF/AB und bleibt die lokale Sicherheitsinstanz.
 * - Symcon sendet nur virtuelle KURZ-Tastenbefehle ueber LCN_SendCommand().
 * - Reale Relaisvariablen sind die einzige Quelle fuer Fahrbeginn/-ende.
 * - Alte Folgeauftraege werden durch eine Auftragsnummer unwirksam.
 */

declare(strict_types=1);

const J_PHASE_IDLE       = 0;
const J_PHASE_WAIT_START = 1;
const J_PHASE_BLIND      = 2;
const J_PHASE_SLAT       = 3;
const J_PHASE_SHAKE      = 4;
const J_PHASE_STOPPING   = 5;
const J_PHASE_EXTERNAL   = 6;
const J_PHASE_ERROR      = 7;
const J_PHASE_REFERENCE  = 8;
const J_PHASE_SYNC       = 9;
const J_PHASE_CALIBRATION = 10;

const J_ORDER_NONE      = 0;
const J_ORDER_BLIND     = 1;
const J_ORDER_SLAT      = 2;
const J_ORDER_SHAKE     = 3;
const J_ORDER_REFERENCE = 4;

const J_DIR_NONE = 0;
const J_DIR_UP   = 1;
const J_DIR_DOWN = 2;
const J_DIR_BOTH = 3;

const J_PENDING_NONE      = 0;
const J_PENDING_BLIND     = 1;
const J_PENDING_SLAT      = 2;
const J_PENDING_REFERENCE = 3;

$rootID = J_RootID((int) ($_IPS['SELF'] ?? 0));
$lockName = 'Jalousie_PHP_' . $rootID;

if (!IPS_SemaphoreEnter($lockName, 5000)) {
    IPS_LogMessage('Jalousie', 'Semaphore konnte nicht gesetzt werden: ' . $lockName);
    return;
}

try {
    $action = J_DetectAction($rootID, $_IPS);
    J_Log($rootID, 'Controller-Aufruf: ' . $action);

    if ($action !== 'RESET_ERROR'
        && IPS_FunctionExists('LCNJAL_IsRuntimePermitted')
        && !LCNJAL_IsRuntimePermitted($rootID)) {
        J_SetLastAction($rootID, 'Symcon-Steuerung inaktiv oder Fehler verriegelt; Aufruf verworfen');
        return;
    }

    if (!J_EnsureRuntimeEpoch($rootID, $action)) {
        return;
    }

    $phaseBeforeAction = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'));
    if ($phaseBeforeAction === J_PHASE_SYNC
        && in_array($action, ['GT8_SLAT_UP', 'GT8_SLAT_DOWN'], true)) {
        // Ein Statusabgleich des M22 kann den persistenten Toggle-Zustand aktualisieren.
        // Diese Aenderung ist kein sicher nachgewiesener LANG-Tastendruck und wird verworfen.
        J_SetLastAction($rootID, 'GT8-Toggle waehrend Statusabgleich als Baseline verworfen');
        return;
    }
    if ($phaseBeforeAction === J_PHASE_SYNC
        && !in_array($action, ['SYNC_COMPLETE', 'INITIALIZE', 'HEALTHCHECK', 'STATUS', 'STOP', 'RELAY_UPDATE'], true)) {
        J_Reject($rootID, 'LCN-Statusabgleich laeuft. Auftrag nach Abschluss erneut ausfuehren.');
        return;
    }
    if ($phaseBeforeAction === J_PHASE_CALIBRATION
        && !in_array($action, ['DEADLINE', 'HEALTHCHECK', 'STATUS', 'STOP', 'RELAY_UPDATE', 'SET_SHAKEFREE'], true)) {
        J_SetLastAction($rootID, 'Kalibrierfenster aktiv; neuer Symcon-Fahrbefehl verworfen');
        return;
    }

    switch ($action) {
        case 'SET_BLIND':
            J_RequestBlind($rootID, (float) ($_IPS['VALUE'] ?? 0));
            break;

        case 'SET_SLAT':
            J_RequestSlat($rootID, (int) ($_IPS['VALUE'] ?? 50), J_DIR_NONE);
            break;

        case 'SET_SHAKEFREE':
            $value = (bool) ($_IPS['VALUE'] ?? false);
            SetValueBoolean(J_ID($rootID, '03_Bedienung', 'ShakeFree_Aktiv'), $value);
            J_SetLastAction($rootID, 'ShakeFree ' . ($value ? 'EIN' : 'AUS'));
            break;

        case 'STOP':
            J_RequestStop($rootID, 'STOP aus Visualisierung');
            SetValueBoolean(J_ID($rootID, '03_Bedienung', 'Stopp'), false);
            break;

        case 'RESET_ERROR':
            J_ResetError($rootID);
            break;

        case 'REFERENCE_UP':
            SetValueInteger(J_ID($rootID, '03_Bedienung', 'Referenzfahrt'), 0);
            J_RequestReference($rootID, J_DIR_UP);
            break;

        case 'REFERENCE_DOWN':
            SetValueInteger(J_ID($rootID, '03_Bedienung', 'Referenzfahrt'), 0);
            J_RequestReference($rootID, J_DIR_DOWN);
            break;

        case 'GT8_SLAT_UP':
            J_RequestSlat($rootID, 50, J_DIR_UP);
            break;

        case 'GT8_SLAT_DOWN':
            J_RequestSlat($rootID, 50, J_DIR_DOWN);
            break;

        case 'RELAY_UPDATE':
            J_HandleRelayUpdate($rootID, true, (int) ($_IPS['VARIABLE'] ?? 0));
            break;

        case 'DEADLINE':
            J_HandleDeadline($rootID, (int) ($_IPS['ORDER'] ?? -1));
            break;

        case 'START_TIMEOUT':
            J_HandleStartTimeout($rootID, (int) ($_IPS['ORDER'] ?? -1));
            break;

        case 'STOP_TIMEOUT':
            J_HandleStopTimeout($rootID, (int) ($_IPS['ORDER'] ?? -1));
            break;

        case 'CANCEL_GUARD':
            J_HandleCancelGuard($rootID, (int) ($_IPS['ORDER'] ?? -1));
            break;

        case 'INITIALIZE':
            J_InitializeRuntime($rootID);
            break;

        case 'SYNC_COMPLETE':
            J_CompleteStatusSync($rootID, (int) ($_IPS['ORDER'] ?? -1));
            break;

        case 'HEALTHCHECK':
            if (GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase')) !== J_PHASE_SYNC) {
                J_ReconcileRelayState($rootID);
            }
            break;

        case 'STATUS':
        default:
            echo J_StatusText($rootID);
            break;
    }
} catch (Throwable $e) {
    try {
        J_SetError($rootID, 'Controller-Fehler: ' . $e->getMessage(), false);
    } catch (Throwable $errorHandlerFailure) {
        IPS_LogMessage('Jalousie', 'Fehlerbehandlung fehlgeschlagen: ' . $errorHandlerFailure->getMessage());
    }
    IPS_LogMessage('Jalousie', $e->getMessage());
} finally {
    try {
        if (IPS_FunctionExists('LCNJAL_SyncVisualization')) {
            LCNJAL_SyncVisualization($rootID);
        }
    } catch (Throwable $visualizationError) {
        IPS_LogMessage('Jalousie', 'Visualisierung konnte nicht synchronisiert werden: ' . $visualizationError->getMessage());
    }
    IPS_SemaphoreLeave($lockName);
}

function J_RootID(int $scriptID): int
{
    if ($scriptID <= 0 || !IPS_ScriptExists($scriptID)) {
        throw new RuntimeException('SELF ist keine gueltige Skript-ID.');
    }
    $scriptsCategory = IPS_GetParent($scriptID);
    $rootID = IPS_GetParent($scriptsCategory);
    if ($rootID <= 0) {
        throw new RuntimeException('Wurzelkategorie konnte nicht ermittelt werden.');
    }
    return $rootID;
}

function J_ID(int $rootID, string $categoryIdent, string $objectIdent): int
{
    $categoryID = @IPS_GetObjectIDByIdent($categoryIdent, $rootID);
    if ($categoryID === false) {
        throw new RuntimeException('Kategorie fehlt: ' . $categoryIdent);
    }
    $objectID = @IPS_GetObjectIDByIdent($objectIdent, (int) $categoryID);
    if ($objectID === false) {
        throw new RuntimeException('Objekt fehlt: ' . $categoryIdent . '/' . $objectIdent);
    }
    return (int) $objectID;
}

function J_ScriptID(int $rootID, string $ident): int
{
    return J_ID($rootID, '06_Skripte', $ident);
}

function J_NowMs(): float
{
    // Monotone Zeitbasis: nicht durch NTP-/Systemzeitkorrekturen verstellbar.
    $nanoseconds = hrtime(true);
    if ($nanoseconds === false) {
        throw new RuntimeException('hrtime() ist auf dieser Plattform nicht verfuegbar.');
    }
    return (float) $nanoseconds / 1_000_000.0;
}

function J_KernelStart(): int
{
    return IPS_GetKernelStartTime();
}

function J_EnsureRuntimeEpoch(int $rootID, string $action): bool
{
    if (IPS_GetKernelRunlevel() !== 10103) {
        J_SetLastAction($rootID, 'Kernel noch nicht bereit; Aufruf verworfen');
        return false;
    }

    $stored = GetValueInteger(J_ID($rootID, '05_Intern', 'Kernel_Startzeit'));
    $current = J_KernelStart();
    if ($stored === $current) {
        return true;
    }

    if (in_array($action, ['INITIALIZE', 'RESET_ERROR'], true)) {
        return true;
    }

    J_BeginStatusSync($rootID, 'Kernelneustart erkannt; Laufzeitzustaende verworfen');
    return false;
}

function J_Clamp(float $value, float $min = 0.0, float $max = 100.0): float
{
    return max($min, min($max, $value));
}

function J_ConfigInt(int $rootID, string $ident): int
{
    return GetValueInteger(J_ID($rootID, '01_Konfiguration', $ident));
}

function J_ConfigFloat(int $rootID, string $ident): float
{
    return GetValueFloat(J_ID($rootID, '01_Konfiguration', $ident));
}

function J_ConfigBool(int $rootID, string $ident): bool
{
    return GetValueBoolean(J_ID($rootID, '01_Konfiguration', $ident));
}

function J_ConfigString(int $rootID, string $ident): string
{
    return GetValueString(J_ID($rootID, '01_Konfiguration', $ident));
}

function J_Log(int $rootID, string $message): void
{
    if (J_ConfigBool($rootID, 'Diagnose_Log')) {
        IPS_LogMessage('Jalousie ' . IPS_GetName($rootID), $message);
    }
}

function J_SetLastAction(int $rootID, string $text): void
{
    SetValueString(J_ID($rootID, '04_Istwerte', 'Letzte_Aktion'), date('d.m.Y H:i:s') . ' - ' . $text);
}

function J_DetectAction(int $rootID, array $ips): string
{
    if (isset($ips['ACTION']) && is_string($ips['ACTION']) && $ips['ACTION'] !== '') {
        return $ips['ACTION'];
    }

    $variableID = isset($ips['VARIABLE']) ? (int) $ips['VARIABLE'] : 0;
    if ($variableID <= 0) {
        return 'STATUS';
    }

    $ids = [
        'Soll_Behang'      => J_ID($rootID, '03_Bedienung', 'Soll_Behang'),
        'Soll_Lamelle'     => J_ID($rootID, '03_Bedienung', 'Soll_Lamelle'),
        'ShakeFree_Aktiv'  => J_ID($rootID, '03_Bedienung', 'ShakeFree_Aktiv'),
        'Stopp'            => J_ID($rootID, '03_Bedienung', 'Stopp'),
        'Referenzfahrt'    => J_ID($rootID, '03_Bedienung', 'Referenzfahrt'),
        'Relais_AUF'       => J_ConfigInt($rootID, 'Relais_AUF_ID'),
        'Relais_AB'        => J_ConfigInt($rootID, 'Relais_AB_ID'),
        'GT8_LANG_AUF'     => J_ConfigInt($rootID, 'GT8_LANG_AUF_ID'),
        'GT8_LANG_AB'      => J_ConfigInt($rootID, 'GT8_LANG_AB_ID'),
    ];

    return match ($variableID) {
        $ids['Soll_Behang']     => 'SET_BLIND',
        $ids['Soll_Lamelle']    => 'SET_SLAT',
        $ids['ShakeFree_Aktiv'] => 'SET_SHAKEFREE',
        $ids['Stopp']           => 'STOP',
        $ids['Relais_AUF'], $ids['Relais_AB'] => 'RELAY_UPDATE',
        $ids['GT8_LANG_AUF']    => 'GT8_SLAT_UP',
        $ids['GT8_LANG_AB']     => 'GT8_SLAT_DOWN',
        $ids['Referenzfahrt']   => ((int) ($ips['VALUE'] ?? 0) === J_DIR_UP
            ? 'REFERENCE_UP'
            : ((int) ($ips['VALUE'] ?? 0) === J_DIR_DOWN ? 'REFERENCE_DOWN' : 'STATUS')),
        default => 'STATUS',
    };
}

function J_NextOrder(int $rootID): int
{
    $id = J_ID($rootID, '05_Intern', 'Auftragsnummer');
    $next = GetValueInteger($id) + 1;
    if ($next > 2000000000) {
        $next = 1;
    }
    SetValueInteger($id, $next);
    return $next;
}

function J_CurrentOrder(int $rootID): int
{
    return GetValueInteger(J_ID($rootID, '05_Intern', 'Auftragsnummer'));
}

function J_SetWorker(int $rootID, bool $active): void
{
    $workerID = J_ScriptID($rootID, 'Worker');
    IPS_SetScriptTimer($workerID, $active ? 1 : 0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Worker_Aktiv'), $active);
}

function J_SetGt8EventsActive(int $rootID, bool $active): void
{
    $controllerID = J_ScriptID($rootID, 'Controller');
    foreach (['Evt_GT8_LANG_AUF', 'Evt_GT8_LANG_AB'] as $ident) {
        $eventID = @IPS_GetObjectIDByIdent($ident, $controllerID);
        if ($eventID !== false && IPS_EventExists((int) $eventID)) {
            IPS_SetEventActive((int) $eventID, $active);
        }
    }
}

function J_IsDirection(int $state): bool
{
    return $state === J_DIR_UP || $state === J_DIR_DOWN;
}

function J_RelayState(int $rootID): int
{
    $upID = J_ConfigInt($rootID, 'Relais_AUF_ID');
    $downID = J_ConfigInt($rootID, 'Relais_AB_ID');
    if (!IPS_VariableExists($upID) || !IPS_VariableExists($downID)) {
        throw new RuntimeException('Relaisstatus-ID ist ungueltig.');
    }

    $up = GetValueBoolean($upID);
    $down = GetValueBoolean($downID);
    if ($up && $down) {
        return J_DIR_BOTH;
    }
    return $up ? J_DIR_UP : ($down ? J_DIR_DOWN : J_DIR_NONE);
}

function J_ReconcileRelayState(int $rootID): void
{
    $stored = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'));
    $real = J_RelayState($rootID);
    if ($stored !== $real) {
        J_HandleRelayUpdate($rootID);
    }
}

/**
 * Gueltig sind fuer TS_KURZ genau eine Tabelle (A-D) mit K und genau eine Taste.
 * Beispiele: K---00010000 (A4), -K--10000000 (B1).
 */
function J_ValidateTS(string $data): bool
{
    if (preg_match('/^(K---|-K--|--K-|---K)[01]{8}$/', $data) !== 1) {
        return false;
    }
    return substr_count(substr($data, 4), '1') === 1;
}

function J_LcnInstanceReady(int $instanceID): bool
{
    if (!IPS_InstanceExists($instanceID)) {
        return false;
    }
    $instance = IPS_GetInstance($instanceID);
    return (int) $instance['InstanceStatus'] === 102
        && (int) ($instance['ModuleInfo']['ModuleType'] ?? -1) === 2;
}

function J_SendDirection(int $rootID, int $direction): bool
{
    if (!J_IsDirection($direction)) {
        J_SetError($rootID, 'Ungueltige Fahrtrichtung: ' . $direction, false);
        return false;
    }

    if (!IPS_FunctionExists('LCN_SendCommand')) {
        J_SetError($rootID, 'LCN_SendCommand ist in diesem Symcon-Kernel nicht registriert. LCN-Modulinstallation pruefen.', false);
        return false;
    }

    $instanceID = J_ConfigInt($rootID, 'LCN_Sendemodulinstanz_ID');
    if (!J_LcnInstanceReady($instanceID)) {
        J_SetError($rootID, 'LCN-Sendemodulinstanz ist nicht aktiv: ' . $instanceID, false);
        return false;
    }

    $data = $direction === J_DIR_UP
        ? J_ConfigString($rootID, 'TS_KURZ_AUF')
        : J_ConfigString($rootID, 'TS_KURZ_AB');

    if (!J_ConfigBool($rootID, 'TS_Belegung_bestaetigt')) {
        J_SetError($rootID, 'TS-Belegung ist nicht vor Ort bestaetigt. Keine LCN-Taste wird gesendet.', false);
        return false;
    }

    if (!J_ValidateTS($data)) {
        J_SetError($rootID, 'Ungueltiges TS-KURZ-Datenfeld: ' . $data, false);
        return false;
    }

    $ok = LCN_SendCommand($instanceID, 'TS', $data);
    J_Log($rootID, 'LCN_SendCommand(' . $instanceID . ', TS, ' . $data . ') => ' . ($ok ? 'OK' : 'FEHLER'));
    if (!$ok) {
        J_SetError($rootID, 'LCN_SendCommand wurde von Symcon nicht angenommen.', false);
    }
    return $ok;
}

function J_SendStopForRealDirection(int $rootID): bool
{
    $state = J_RelayState($rootID);
    if ($state === J_DIR_UP || $state === J_DIR_DOWN) {
        return J_SendDirection($rootID, $state);
    }
    if ($state === J_DIR_BOTH) {
        J_SetError($rootID, 'AUF und AB sind gleichzeitig aktiv. LCN-Verriegelung pruefen.', false);
        return false;
    }
    return true;
}

function J_SlatTurnTimeMs(int $rootID, float $startSlat, int $direction): float
{
    $turnMs = (float) J_ConfigInt($rootID, 'Wendezeit_ms');
    if ($turnMs <= 0.0) {
        throw new RuntimeException('Wendezeit_ms muss groesser 0 sein.');
    }
    return $direction === J_DIR_UP
        ? J_Clamp($startSlat) / 100.0 * $turnMs
        : (100.0 - J_Clamp($startSlat)) / 100.0 * $turnMs;
}

function J_BlindStartDelayMs(int $rootID, float $startBlind, float $startSlat, int $direction): float
{
    $turnMs = (float) J_ConfigInt($rootID, 'Wendezeit_ms');
    $softStartMs = (float) J_ConfigInt($rootID, 'Sanftanlauf_ms');
    $positionTolerance = J_ConfigFloat($rootID, 'Positionstoleranz');
    if ($turnMs <= 0.0 || $softStartMs < 0.0 || $softStartMs > $turnMs) {
        throw new RuntimeException('Wendezeit_ms und Sanftanlauf_ms sind ungueltig.');
    }

    // Gemessene Mechanik:
    // - aus der oberen Endlage in Richtung AB beginnt der Behang sofort,
    // - aus der unteren Endlage in Richtung AUF beginnt er nach voller Wendezeit,
    // - aus einer Zwischenposition gilt mindestens die Sanftanlaufzeit,
    //   bei notwendiger Lamellenwendung jedoch die laengere Rest-Wendezeit.
    if ($direction === J_DIR_DOWN && $startBlind <= $positionTolerance) {
        return 0.0;
    }
    if ($direction === J_DIR_UP && $startBlind >= 100.0 - $positionTolerance) {
        return $turnMs;
    }

    return max($softStartMs, J_SlatTurnTimeMs($rootID, $startSlat, $direction));
}

function J_BlindDurationMs(int $rootID, float $startBlind, float $targetBlind, float $startSlat, int $direction, bool $withReserve): int
{
    $blindTravelMs = (float) J_ConfigInt($rootID, 'Behanglaufzeit_ms');
    if ($blindTravelMs <= 0.0) {
        throw new RuntimeException('Behanglaufzeit_ms muss groesser 0 sein.');
    }
    $blindMs = abs($targetBlind - $startBlind) / 100.0 * $blindTravelMs;
    $duration = J_BlindStartDelayMs($rootID, $startBlind, $startSlat, $direction) + $blindMs;
    if ($withReserve) {
        $duration += J_ConfigInt($rootID, 'Referenzreserve_ms');
    }
    return (int) round(min($duration, (float) J_ConfigInt($rootID, 'MaxFahrt_ms')));
}

function J_SlatDurationMs(int $rootID, float $startSlat, float $targetSlat): int
{
    return (int) round(abs($targetSlat - $startSlat) / 100.0 * J_ConfigInt($rootID, 'Wendezeit_ms'));
}

function J_UpdatePositionToNow(int $rootID, ?float $nowMs = null): void
{
    $state = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'));
    if (!J_IsDirection($state)) {
        return;
    }

    $nowMs ??= J_NowMs();
    $startMs = GetValueFloat(J_ID($rootID, '05_Intern', 'Startzeit_ms'));
    if ($startMs <= 0.0) {
        return;
    }

    $elapsed = max(0.0, $nowMs - $startMs);
    $startBlind = GetValueFloat(J_ID($rootID, '05_Intern', 'Start_Behang'));
    $startSlat = GetValueFloat(J_ID($rootID, '05_Intern', 'Start_Lamelle'));
    $turnMs = (float) J_ConfigInt($rootID, 'Wendezeit_ms');
    $blindTravelMs = (float) J_ConfigInt($rootID, 'Behanglaufzeit_ms');
    if ($turnMs <= 0.0 || $blindTravelMs <= 0.0) {
        throw new RuntimeException('Laufzeiten muessen groesser 0 sein.');
    }

    $turnNeeded = J_SlatTurnTimeMs($rootID, $startSlat, $state);
    $turnUsed = min($elapsed, $turnNeeded);
    $slat = $state === J_DIR_UP
        ? $startSlat - 100.0 * $turnUsed / $turnMs
        : $startSlat + 100.0 * $turnUsed / $turnMs;

    $blindStartDelay = J_BlindStartDelayMs($rootID, $startBlind, $startSlat, $state);
    $blindElapsed = max(0.0, $elapsed - $blindStartDelay);
    $blindDelta = 100.0 * $blindElapsed / $blindTravelMs;
    $blind = $state === J_DIR_UP ? $startBlind - $blindDelta : $startBlind + $blindDelta;

    SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'), J_Clamp($slat));
    SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Behang'), J_Clamp($blind));
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Letzte_Fahrtdauer_ms'), (int) round($elapsed));
}

function J_SnapshotRealStart(int $rootID, int $direction, float $nowMs): void
{
    SetValueFloat(J_ID($rootID, '05_Intern', 'Startzeit_ms'), $nowMs);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Start_Behang'), GetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Behang')));
    SetValueFloat(J_ID($rootID, '05_Intern', 'Start_Lamelle'), GetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle')));
    SetValueInteger(J_ID($rootID, '05_Intern', 'Start_Richtung'), $direction);
}

function J_ClearError(int $rootID): void
{
    SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), '');
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Fehlerphase'), false);
}

function J_Reject(int $rootID, string $message): void
{
    SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), $message);
    J_SetLastAction($rootID, 'ABGEWIESEN: ' . $message);
}

function J_RequestBlind(int $rootID, float $target): void
{
    $target = J_Clamp($target);
    SetValueInteger(J_ID($rootID, '03_Bedienung', 'Soll_Behang'), (int) round($target));
    J_ReconcileRelayState($rootID);
    J_UpdatePositionToNow($rootID);

    if (!GetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'))
        && !J_ConfigBool($rootID, 'Unreferenziert_erlauben')
        && $target > 0.0 && $target < 100.0) {
        J_Reject($rootID, 'Position ist nicht referenziert. Zuerst Referenzfahrt AUF oder AB ausfuehren.');
        return;
    }

    if (J_ShouldQueue($rootID)) {
        J_QueueAfterStop($rootID, J_PENDING_BLIND, $target, J_DIR_NONE, 'Neues Behangziel');
        return;
    }

    J_StartBlindNow($rootID, $target, false);
}

function J_StartBlindNow(int $rootID, float $target, bool $explicitReference): void
{
    if (J_RelayState($rootID) !== J_DIR_NONE) {
        J_QueueAfterStop($rootID, $explicitReference ? J_PENDING_REFERENCE : J_PENDING_BLIND, $target, $target <= 0.0 ? J_DIR_UP : J_DIR_DOWN, 'Behangstart bei aktivem Relais');
        return;
    }

    $actualBlind = GetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Behang'));
    $actualSlat = GetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'));
    $tol = J_ConfigFloat($rootID, 'Positionstoleranz');

    if (!$explicitReference && abs($target - $actualBlind) <= $tol && $target > 0.0 && $target < 100.0) {
        J_FinishIdle($rootID, 'Behang bereits im Ziel');
        return;
    }

    // Endlagen bestimmen die Richtung immer eindeutig. Das ist insbesondere
    // wichtig, wenn Ist_Behang bereits exakt 0 % ist: ein Vergleich mit <
    // wuerde sonst faelschlich AB waehlen. Endlagenbefehle dienen zugleich als
    // kurze Nachreferenzierung mit Reserve; explizite Referenzfahrten nutzen MaxFahrt.
    if ($target <= 0.0) {
        $direction = J_DIR_UP;
    } elseif ($target >= 100.0) {
        $direction = J_DIR_DOWN;
    } else {
        $direction = $target < $actualBlind ? J_DIR_UP : J_DIR_DOWN;
    }

    $targetSlat = $direction === J_DIR_UP ? 0 : 100;
    SetValueInteger(J_ID($rootID, '03_Bedienung', 'Soll_Lamelle'), $targetSlat);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), -1);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), J_DIR_NONE);

    $hardEnd = $target <= 0.0 || $target >= 100.0;
    $positionReferenced = GetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'));
    // Ist die Position noch unbekannt, muss ein Endlagenauftrag unabhaengig vom
    // gespeicherten Rechenwert als volle Referenzfahrt laufen. Andernfalls koennte
    // der Initialwert 0 % bei einem AUF-Auftrag zu einer zu kurzen Fahrt fuehren.
    $referenceRun = $explicitReference || (!$positionReferenced && $hardEnd);
    $duration = $referenceRun
        ? J_ConfigInt($rootID, 'MaxFahrt_ms')
        : J_BlindDurationMs($rootID, $actualBlind, $target, $actualSlat, $direction, $hardEnd);

    $order = J_NextOrder($rootID);
    J_ClearError($rootID);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Auftragstyp'), $referenceRun ? J_ORDER_REFERENCE : J_ORDER_BLIND);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Erwartete_Richtung'), $direction);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Geplante_Dauer_ms'), $duration);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Ziel_Behang'), $target);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Ziel_Lamelle'), (float) $targetSlat);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Endlage_Hart'), $hardEnd || $referenceRun);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Bestaetigung_bis_ms'), J_NowMs() + J_ConfigInt($rootID, 'Relaisbestaetigung_ms'));
    SetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Zielzeit_ms'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_WAIT_START);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), true);
    if ($hardEnd || $referenceRun) {
        SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'), false);
    }

    $actionName = $explicitReference
        ? 'Referenzfahrt '
        : ($referenceRun ? 'Automatische Referenzfahrt ' : 'Behangfahrt ');
    J_SetLastAction($rootID, $actionName . ($direction === J_DIR_UP ? 'AUF' : 'AB') . ', Auftrag ' . $order);
    J_SetWorker($rootID, true);
    if (!J_SendDirection($rootID, $direction)) {
        J_SetWorker($rootID, false);
    }
}

function J_RequestSlat(int $rootID, int $target, int $forcedDirection): void
{
    $target = (int) round(J_Clamp((float) $target));
    SetValueInteger(J_ID($rootID, '03_Bedienung', 'Soll_Lamelle'), $target);

    if (!GetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'))
        && !J_ConfigBool($rootID, 'Unreferenziert_erlauben')) {
        J_Reject($rootID, 'Lamellenposition ist nicht referenziert. Zuerst Referenzfahrt AUF oder AB ausfuehren.');
        return;
    }
    J_ReconcileRelayState($rootID);
    J_UpdatePositionToNow($rootID);

    $phase = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'));
    $orderType = GetValueInteger(J_ID($rootID, '05_Intern', 'Auftragstyp'));
    $relayState = J_RelayState($rootID);

    // Lamellenwahl waehrend einer Behang-/Referenz-/Shake-/externen Fahrt ist ein Folgeauftrag.
    if (in_array($phase, [J_PHASE_BLIND, J_PHASE_SHAKE, J_PHASE_REFERENCE, J_PHASE_EXTERNAL], true)
        || ($phase === J_PHASE_WAIT_START && in_array($orderType, [J_ORDER_BLIND, J_ORDER_REFERENCE, J_ORDER_SHAKE], true))) {
        SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), $target);
        SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), $forcedDirection);
        J_SetLastAction($rootID, 'Lamellenziel ' . $target . ' % als Folgeauftrag gespeichert');
        return;
    }

    if ($relayState !== J_DIR_NONE || in_array($phase, [J_PHASE_WAIT_START, J_PHASE_SLAT, J_PHASE_STOPPING], true)) {
        J_QueueAfterStop($rootID, J_PENDING_SLAT, (float) $target, $forcedDirection, 'Neues Lamellenziel');
        return;
    }

    J_StartSlatNow($rootID, $target, $forcedDirection);
}

function J_StartSlatNow(int $rootID, int $target, int $forcedDirection): void
{
    if (J_RelayState($rootID) !== J_DIR_NONE) {
        J_QueueAfterStop($rootID, J_PENDING_SLAT, (float) $target, $forcedDirection, 'Lamellenstart bei aktivem Relais');
        return;
    }

    $actual = GetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'));
    $tol = J_ConfigFloat($rootID, 'Lamellentoleranz');
    if (abs($target - $actual) <= $tol) {
        SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'), (float) $target);
        J_FinishIdle($rootID, 'Lamelle bereits im Ziel');
        return;
    }

    $direction = $forcedDirection;
    if ($direction === J_DIR_NONE) {
        $direction = $target < $actual ? J_DIR_UP : J_DIR_DOWN;
    }
    if (!J_IsDirection($direction)) {
        J_Reject($rootID, 'Ungueltige Lamellenrichtung.');
        return;
    }

    if (($direction === J_DIR_UP && $target >= $actual)
        || ($direction === J_DIR_DOWN && $target <= $actual)) {
        J_SetLastAction($rootID, 'GT8-LANG: 50 % in dieser Richtung nicht erreichbar; keine Fahrt');
        J_FinishIdle($rootID, 'Lamellenauftrag ohne Fahrt');
        return;
    }

    $duration = J_SlatDurationMs($rootID, $actual, (float) $target);
    if ($duration <= 0) {
        J_FinishIdle($rootID, 'Lamellenauftrag ohne Fahrzeit');
        return;
    }

    $order = J_NextOrder($rootID);
    J_ClearError($rootID);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Auftragstyp'), J_ORDER_SLAT);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Erwartete_Richtung'), $direction);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Geplante_Dauer_ms'), $duration);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Ziel_Behang'), GetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Behang')));
    SetValueFloat(J_ID($rootID, '05_Intern', 'Ziel_Lamelle'), (float) $target);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Endlage_Hart'), false);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Bestaetigung_bis_ms'), J_NowMs() + J_ConfigInt($rootID, 'Relaisbestaetigung_ms'));
    SetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Zielzeit_ms'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_WAIT_START);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), true);

    J_SetLastAction($rootID, 'Lamellenfahrt auf ' . $target . ' %, Auftrag ' . $order);
    J_SetWorker($rootID, true);
    if (!J_SendDirection($rootID, $direction)) {
        J_SetWorker($rootID, false);
    }
}

function J_RequestReference(int $rootID, int $direction): void
{
    if (!J_IsDirection($direction)) {
        J_Reject($rootID, 'Ungueltige Referenzrichtung.');
        return;
    }
    $target = $direction === J_DIR_UP ? 0.0 : 100.0;
    SetValueInteger(J_ID($rootID, '03_Bedienung', 'Soll_Behang'), (int) $target);
    SetValueInteger(J_ID($rootID, '03_Bedienung', 'Soll_Lamelle'), (int) $target);
    J_ReconcileRelayState($rootID);
    J_UpdatePositionToNow($rootID);

    if (J_ShouldQueue($rootID)) {
        J_QueueAfterStop($rootID, J_PENDING_REFERENCE, $target, $direction, 'Referenzfahrt');
        return;
    }
    J_StartBlindNow($rootID, $target, true);
}

function J_ShouldQueue(int $rootID): bool
{
    $phase = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'));
    return J_RelayState($rootID) !== J_DIR_NONE
        || in_array($phase, [J_PHASE_WAIT_START, J_PHASE_BLIND, J_PHASE_SLAT, J_PHASE_SHAKE, J_PHASE_STOPPING, J_PHASE_EXTERNAL, J_PHASE_REFERENCE, J_PHASE_CALIBRATION], true);
}

function J_RequestStop(int $rootID, string $reason): void
{
    J_ReconcileRelayState($rootID);

    $phase = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'));
    $state = J_RelayState($rootID);

    // Ein STOP im Stillstand darf den Start-/Statusabgleich nicht vorzeitig aufheben.
    // Bei einem real aktiven Relais bleibt der zustandsabhaengige STOP trotzdem wirksam.
    if ($phase === J_PHASE_SYNC && $state === J_DIR_NONE) {
        J_SetLastAction($rootID, $reason . ': kein aktives Relais; Statusabgleich laeuft weiter');
        return;
    }

    J_ClearPending($rootID);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), -1);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), J_DIR_NONE);

    if ($phase === J_PHASE_WAIT_START && $state === J_DIR_NONE) {
        J_BeginCancelGuard($rootID, $reason . ': wartender Start wird abgefangen', false);
        return;
    }

    if ($phase === J_PHASE_STOPPING) {
        J_SetLastAction($rootID, $reason . ': STOP bereits aktiv');
        return;
    }

    if (J_IsDirection($state)) {
        J_NextOrder($rootID);
        J_BeginStopWatch($rootID, $reason, false);
        return;
    }

    J_FinishIdle($rootID, 'STOP im Stillstand');
}

function J_QueueAfterStop(int $rootID, int $pendingType, float $value, int $direction, string $reason): void
{
    SetValueInteger(J_ID($rootID, '05_Intern', 'Pending_Aktion'), $pendingType);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Pending_Wert'), $value);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Pending_Richtung'), $direction);

    $phase = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'));
    $state = J_RelayState($rootID);

    if ($phase === J_PHASE_STOPPING) {
        J_SetLastAction($rootID, $reason . ': ersetzt wartenden Folgeauftrag');
        J_SetWorker($rootID, true);
        return;
    }

    if ($phase === J_PHASE_WAIT_START && $state === J_DIR_NONE) {
        J_BeginCancelGuard($rootID, $reason . ': unbestaetigten Start abfangen', false);
        return;
    }

    if (J_IsDirection($state)) {
        J_NextOrder($rootID);
        J_BeginStopWatch($rootID, $reason . ': laufende Fahrt wird gestoppt', false);
        return;
    }

    J_StartPending($rootID);
}

function J_BeginCancelGuard(int $rootID, string $reason, bool $errorAfter): void
{
    $order = J_NextOrder($rootID);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Auftragstyp'), J_ORDER_NONE);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Erwartete_Richtung'), J_DIR_NONE);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), true);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Fehlerphase'), $errorAfter);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Abbruch_bis_ms'), J_NowMs() + J_ConfigInt($rootID, 'Spaetstart_Schutz_ms'));
    SetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'), 0.0);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_STOPPING);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
    J_SetWorker($rootID, true);
    J_SetLastAction($rootID, $reason . ', Schutzauftrag ' . $order);
}

function J_BeginStopWatch(int $rootID, string $reason, bool $errorAfter): void
{
    $state = J_RelayState($rootID);
    if (!J_IsDirection($state)) {
        if ($state === J_DIR_NONE) {
            J_HandleRealStop($rootID, GetValueInteger(J_ID($rootID, '05_Intern', 'Start_Richtung')));
            return;
        }
        J_SetError($rootID, 'AUF und AB sind gleichzeitig aktiv.', false);
        return;
    }

    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Fehlerphase'), $errorAfter);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), true);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'), J_NowMs() + J_ConfigInt($rootID, 'Stoppbestaetigung_ms'));
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_STOPPING);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
    J_SetWorker($rootID, true);
    J_SetLastAction($rootID, $reason . '; STOP auf reale Richtung');
    if (!J_SendStopForRealDirection($rootID)) {
        J_SetError($rootID, 'STOP-Befehl konnte nicht gesendet werden.', false);
    }
}

function J_ClearPending(int $rootID): void
{
    SetValueInteger(J_ID($rootID, '05_Intern', 'Pending_Aktion'), J_PENDING_NONE);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Pending_Wert'), 0.0);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Pending_Richtung'), J_DIR_NONE);
}

function J_HasPending(int $rootID): bool
{
    return GetValueInteger(J_ID($rootID, '05_Intern', 'Pending_Aktion')) !== J_PENDING_NONE;
}

function J_StartPending(int $rootID): void
{
    $type = GetValueInteger(J_ID($rootID, '05_Intern', 'Pending_Aktion'));
    $value = GetValueFloat(J_ID($rootID, '05_Intern', 'Pending_Wert'));
    $direction = GetValueInteger(J_ID($rootID, '05_Intern', 'Pending_Richtung'));
    J_ClearPending($rootID);

    switch ($type) {
        case J_PENDING_BLIND:
            J_StartBlindNow($rootID, $value, false);
            break;
        case J_PENDING_SLAT:
            J_StartSlatNow($rootID, (int) round($value), $direction);
            break;
        case J_PENDING_REFERENCE:
            J_StartBlindNow($rootID, $direction === J_DIR_UP ? 0.0 : 100.0, true);
            break;
        default:
            J_FinishIdle($rootID, 'Kein Folgeauftrag');
            break;
    }
}

function J_HandleRelayUpdate(int $rootID, bool $coalesce = false, int $triggerVariableID = 0): void
{
    if ($coalesce) {
        $delay = J_ConfigInt($rootID, 'Relais_Koaleszenz_ms');
        if ($delay > 0) {
            IPS_Sleep($delay);
        }
    }

    $now = J_NowMs();
    $oldState = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'));
    $newState = J_RelayState($rootID);

    // Waehrend STATUS_SYNC werden Relaisrueckmeldungen nur als frischer Istzustand
    // uebernommen. Erst SYNC_COMPLETE entscheidet ueber IDLE oder externe Fahrt.
    if (GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase')) === J_PHASE_SYNC) {
        // OnUpdate-Ereignisse liefern den belastbaren Nachweis, dass genau diese
        // Statusvariable nach der aktuellen LCN_RequestStatus-Anfrage empfangen wurde.
        // VariableUpdated allein reicht bei einer erneuten Initialisierung im selben
        // Kernel nicht aus, weil ein alter Update-Zeitstempel noch nach Kernelstart liegt.
        if ($triggerVariableID === J_ConfigInt($rootID, 'Relais_AUF_ID')) {
            SetValueBoolean(J_ID($rootID, '05_Intern', 'Sync_Relais_AUF_Empfangen'), true);
        }
        if ($triggerVariableID === J_ConfigInt($rootID, 'Relais_AB_ID')) {
            SetValueBoolean(J_ID($rootID, '05_Intern', 'Sync_Relais_AB_Empfangen'), true);
        }
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), $newState);
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Letzte_Statusmeldung'), time());
        if ($newState === J_DIR_BOTH) {
            J_SetError($rootID, 'Statusabgleich: AUF und AB gleichzeitig aktiv.', false);
        }
        return;
    }

    if ($newState === J_DIR_BOTH) {
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), J_DIR_BOTH);
        J_SetError($rootID, 'AUF und AB sind gleichzeitig aktiv. LCN-Verriegelung pruefen.', false);
        return;
    }

    // Ein direkter Richtungswechsel ohne sichtbare STOP-Phase wird sicherheitshalber als extern bewertet.
    if (J_IsDirection($oldState) && J_IsDirection($newState) && $newState !== $oldState) {
        J_UpdatePositionToNow($rootID, $now);
        J_NextOrder($rootID);
        J_ClearPending($rootID);
        SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), -1);
        SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), J_DIR_NONE);
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
        J_SnapshotRealStart($rootID, $newState, $now);
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_EXTERNAL);
        SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), $newState);
        J_SetWorker($rootID, true);
        J_SetLastAction($rootID, 'Direkter externer Richtungswechsel erkannt; Automatik verworfen');
        return;
    }

    if (J_IsDirection($oldState) && $newState === J_DIR_NONE) {
        J_UpdatePositionToNow($rootID, $now);
        J_HandleRealStop($rootID, $oldState);
    } elseif ($oldState === J_DIR_NONE && J_IsDirection($newState)) {
        J_HandleRealStart($rootID, $newState, $now);
    }

    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), $newState);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Letzte_Statusmeldung'), time());
}

function J_HandleRealStart(int $rootID, int $direction, float $now): void
{
    J_SnapshotRealStart($rootID, $direction, $now);
    $phase = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'));
    $expected = GetValueInteger(J_ID($rootID, '05_Intern', 'Erwartete_Richtung'));
    $orderType = GetValueInteger(J_ID($rootID, '05_Intern', 'Auftragstyp'));

    if ($phase === J_PHASE_WAIT_START && $expected === $direction && $orderType !== J_ORDER_NONE) {
        $duration = GetValueInteger(J_ID($rootID, '05_Intern', 'Geplante_Dauer_ms'));
        SetValueFloat(J_ID($rootID, '05_Intern', 'Zielzeit_ms'), $now + $duration);
        $runPhase = match ($orderType) {
            J_ORDER_BLIND => J_PHASE_BLIND,
            J_ORDER_SLAT => J_PHASE_SLAT,
            J_ORDER_SHAKE => J_PHASE_SHAKE,
            J_ORDER_REFERENCE => J_PHASE_REFERENCE,
            default => J_PHASE_ERROR,
        };
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), $runPhase);
        SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), true);
        J_SetWorker($rootID, true);
        J_Log($rootID, 'Reale Relaisbestaetigung, Zielzeit gesetzt.');
        return;
    }

    // Ein nach STOP/Timeout verspaetet einlaufender Start wird sofort wieder gestoppt.
    if ($phase === J_PHASE_STOPPING && GetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'))) {
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), true);
        SetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'), $now + J_ConfigInt($rootID, 'Stoppbestaetigung_ms'));
        J_SetWorker($rootID, true);
        J_SetLastAction($rootID, 'Verspaeteter Relaisstart erkannt; sofortiger STOP');
        if (!J_SendStopForRealDirection($rootID)) {
            J_SetError($rootID, 'Verspaeteter Start konnte nicht gestoppt werden.', false);
        }
        return;
    }

    if ($phase === J_PHASE_ERROR) {
        // Fehlerzustände dürfen die lokale LCN-Bedienung niemals blockieren.
        // Ein realer Start wird deshalb nur noch als externe Fahrt verfolgt;
        // es wird ausdrücklich KEIN weiterer TS-/STOP-Befehl gesendet.
        J_NextOrder($rootID);
        J_ClearPending($rootID);
        SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), -1);
        SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), J_DIR_NONE);
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Fehlerphase'), false);
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_EXTERNAL);
        SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), $direction);
        J_SetWorker($rootID, true);
        J_SetLastAction($rootID, 'Externe Fahrt im Fehlerzustand erkannt; keine Automatikintervention');
        return;
    }

    // Jede sonstige reale Relaisaktivierung hat Vorrang.
    J_NextOrder($rootID);
    J_ClearPending($rootID);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), -1);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), J_DIR_NONE);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Auftragstyp'), J_ORDER_NONE);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Erwartete_Richtung'), J_DIR_NONE);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_EXTERNAL);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
    J_SetWorker($rootID, true); // Positionsfortschreibung, kein automatischer STOP.
    J_SetLastAction($rootID, 'Externe/GT8-KURZ-Fahrt erkannt; alter Automatikauftrag verworfen');
}

function J_HandleRealStop(int $rootID, int $oldDirection): void
{
    $phase = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'));
    $stopRequested = GetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'));

    if ($phase === J_PHASE_STOPPING) {
        J_SetWorker($rootID, false);
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
        SetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'), 0.0);
        $errorAfter = GetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Fehlerphase'));
        if (!$errorAfter && J_HasPending($rootID)) {
            J_StartPending($rootID);
        } elseif ($errorAfter) {
            $message = GetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'));
            J_SetError(
                $rootID,
                $message !== '' ? $message : 'Fahrt gestoppt; vorheriger Fehler bleibt bestehen.',
                false
            );
        } else {
            J_FinishIdle($rootID, 'STOP bestaetigt');
        }
        return;
    }

    if ($phase === J_PHASE_ERROR) {
        J_SetWorker($rootID, false);
        J_SetLastAction($rootID, 'Fahrt im Fehlerzustand beendet');
        return;
    }

    if ($phase === J_PHASE_EXTERNAL) {
        J_SetWorker($rootID, false);
        $follow = GetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'));
        $followDir = GetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'));
        SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), -1);
        SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), J_DIR_NONE);
        if ($follow >= 0) {
            J_StartSlatNow($rootID, $follow, $followDir);
        } else {
            J_FinishIdle($rootID, 'Externe Fahrt beendet');
        }
        return;
    }

    J_SetWorker($rootID, false);
    if (!$stopRequested) {
        J_NextOrder($rootID);
        J_ClearPending($rootID);
        SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), -1);
        SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), J_DIR_NONE);
        J_FinishIdle($rootID, 'Unerwartetes Fahrtende; Automatikfolge verworfen');
        return;
    }

    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'), 0.0);
    $hardEnd = GetValueBoolean(J_ID($rootID, '05_Intern', 'Endlage_Hart'));
    $targetBlind = GetValueFloat(J_ID($rootID, '05_Intern', 'Ziel_Behang'));
    $targetSlat = GetValueFloat(J_ID($rootID, '05_Intern', 'Ziel_Lamelle'));

    if ($phase === J_PHASE_BLIND || $phase === J_PHASE_REFERENCE) {
        SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Behang'), J_Clamp($targetBlind));
        SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'), J_Clamp($targetSlat));
        if ($hardEnd) {
            SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'), true);
        }

        J_StartConfiguredFollowSlatOrFinish($rootID);
        return;
    }

    if ($phase === J_PHASE_CALIBRATION) {
        SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Behang'), 100.0);
        SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'), 100.0);
        if (GetValueBoolean(J_ID($rootID, '05_Intern', 'Endlage_Hart'))) {
            SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'), true);
        }

        $calibrationDeadline = GetValueFloat(J_ID($rootID, '05_Intern', 'Zielzeit_ms'));
        $calibrationComplete = $stopRequested && ($calibrationDeadline <= 0.0 || J_NowMs() + 100.0 >= $calibrationDeadline);
        if (!$calibrationComplete) {
            J_FinishIdle($rootID, 'Kalibrierfenster vorzeitig beendet; ShakeFree aus Sicherheitsgruenden verworfen');
            return;
        }

        if (GetValueBoolean(J_ID($rootID, '03_Bedienung', 'ShakeFree_Aktiv'))) {
            J_StartShakeNow($rootID);
        } else {
            J_StartConfiguredFollowSlatOrFinish($rootID);
        }
        return;
    }

    if ($phase === J_PHASE_SLAT) {
        SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'), J_Clamp($targetSlat));
        J_FinishIdle($rootID, 'Lamellenziel erreicht');
        return;
    }

    if ($phase === J_PHASE_SHAKE) {
        SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'), 0.0);
        J_StartConfiguredFollowSlatOrFinish($rootID);
        return;
    }

    J_FinishIdle($rootID, 'Fahrt beendet');
}

function J_StartConfiguredFollowSlatOrFinish(int $rootID): void
{
    $follow = GetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'));
    $followDir = GetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'));
    if ($follow < 0) {
        $follow = GetValueInteger(J_ID($rootID, '03_Bedienung', 'Soll_Lamelle'));
        $followDir = J_DIR_NONE;
    }
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), -1);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), J_DIR_NONE);

    $actual = GetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'));
    if (abs($follow - $actual) <= J_ConfigFloat($rootID, 'Lamellentoleranz')) {
        J_FinishIdle($rootID, 'Behangfahrt und Lamellenziel abgeschlossen');
        return;
    }
    J_StartSlatNow($rootID, $follow, $followDir);
}

function J_StartShakeNow(int $rootID): void
{
    if (J_RelayState($rootID) !== J_DIR_NONE) {
        J_SetError($rootID, 'ShakeFree kann nur im Stillstand starten.', false);
        return;
    }

    // Nach dem bestätigten AB-STOP erhält LCN eine kurze Umschaltpause.
    // Die eigentliche Gegenfahrt bleibt exakt ShakeFree_ms lang.
    $pauseMs = J_ConfigInt($rootID, 'ShakeFree_Pause_ms');
    if ($pauseMs > 0) {
        IPS_Sleep($pauseMs);
    }
    if (J_RelayState($rootID) !== J_DIR_NONE) {
        J_SetError($rootID, 'ShakeFree-Umschaltpause: Relais sind nicht sicher AUS.', false);
        return;
    }

    $duration = J_ConfigInt($rootID, 'ShakeFree_ms');
    $order = J_NextOrder($rootID);
    J_ClearError($rootID);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Auftragstyp'), J_ORDER_SHAKE);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Erwartete_Richtung'), J_DIR_UP);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Geplante_Dauer_ms'), $duration);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Ziel_Behang'), GetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Behang')));
    SetValueFloat(J_ID($rootID, '05_Intern', 'Ziel_Lamelle'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Endlage_Hart'), false);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Bestaetigung_bis_ms'), J_NowMs() + J_ConfigInt($rootID, 'Relaisbestaetigung_ms'));
    SetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Zielzeit_ms'), 0.0);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_WAIT_START);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), true);
    J_SetWorker($rootID, true);
    J_SetLastAction($rootID, 'ShakeFree AUF ' . $duration . ' ms, Auftrag ' . $order);
    if (!J_SendDirection($rootID, J_DIR_UP)) {
        J_SetWorker($rootID, false);
    }
}

function J_HandleDeadline(int $rootID, int $order): void
{
    if ($order !== J_CurrentOrder($rootID)) {
        J_Log($rootID, 'Alter Worker verworfen, Auftrag ' . $order);
        return;
    }

    $phase = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'));
    if (!in_array($phase, [J_PHASE_BLIND, J_PHASE_SLAT, J_PHASE_SHAKE, J_PHASE_REFERENCE, J_PHASE_CALIBRATION], true)) {
        return;
    }
    if (GetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'))) {
        return;
    }

    J_UpdatePositionToNow($rootID);

    if (in_array($phase, [J_PHASE_BLIND, J_PHASE_REFERENCE], true)
        && GetValueInteger(J_ID($rootID, '05_Intern', 'Start_Richtung')) === J_DIR_DOWN
        && GetValueFloat(J_ID($rootID, '05_Intern', 'Ziel_Behang')) >= 100.0 - J_ConfigFloat($rootID, 'Positionstoleranz')) {
        SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Behang'), 100.0);
        SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'), 100.0);
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_CALIBRATION);
        SetValueFloat(
            J_ID($rootID, '05_Intern', 'Zielzeit_ms'),
            J_NowMs() + J_ConfigInt($rootID, 'Kalibrierfenster_ms')
        );
        SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), true);
        J_SetWorker($rootID, true);
        J_SetLastAction($rootID, '100 % ZU erreicht; Kalibrierfenster gestartet, ShakeFree fruehestens danach');
        return;
    }

    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), true);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'), J_NowMs() + J_ConfigInt($rootID, 'Stoppbestaetigung_ms'));
    J_SetWorker($rootID, true);
    J_SetLastAction($rootID, 'Zielzeit erreicht; STOP auf reale Richtung');

    if (J_RelayState($rootID) === J_DIR_NONE) {
        J_HandleRealStop($rootID, GetValueInteger(J_ID($rootID, '05_Intern', 'Start_Richtung')));
        return;
    }
    if (!J_SendStopForRealDirection($rootID)) {
        J_SetError($rootID, 'Ziel-STOP konnte nicht gesendet werden.', false);
    }
}

function J_HandleStartTimeout(int $rootID, int $order): void
{
    if ($order !== J_CurrentOrder($rootID)) {
        return;
    }
    if (GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase')) !== J_PHASE_WAIT_START) {
        return;
    }

    $state = J_RelayState($rootID);
    if (J_IsDirection($state)) {
        J_HandleRelayUpdate($rootID);
        return;
    }

    SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), 'Keine reale Relaisbestaetigung innerhalb der eingestellten Zeit.');
    J_ClearPending($rootID);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), -1);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), J_DIR_NONE);
    J_BeginCancelGuard($rootID, 'Starttimeout; verspaeteten Relaisstart abfangen', true);
}

function J_HandleStopTimeout(int $rootID, int $order): void
{
    if ($order !== J_CurrentOrder($rootID)) {
        return;
    }
    if (!GetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'))) {
        return;
    }

    $state = J_RelayState($rootID);
    if ($state === J_DIR_NONE) {
        J_HandleRelayUpdate($rootID);
        return;
    }

    J_SetError(
        $rootID,
        'Keine Relais-AUS-Bestaetigung nach STOP. Keine automatische Wiederholung wegen Toggle-Gefahr. Symcon wird bis zur Quittierung deaktiviert.',
        false
    );
}

function J_HandleCancelGuard(int $rootID, int $order): void
{
    if ($order !== J_CurrentOrder($rootID)) {
        return;
    }
    if (GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase')) !== J_PHASE_STOPPING
        || !GetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'))) {
        return;
    }

    $state = J_RelayState($rootID);
    if (J_IsDirection($state)) {
        J_HandleRelayUpdate($rootID);
        return;
    }

    if (J_NowMs() < GetValueFloat(J_ID($rootID, '05_Intern', 'Abbruch_bis_ms'))) {
        return;
    }

    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
    J_SetWorker($rootID, false);
    $errorAfter = GetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Fehlerphase'));
    if (!$errorAfter && J_HasPending($rootID)) {
        J_StartPending($rootID);
    } elseif ($errorAfter) {
        $message = GetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'));
        J_SetError(
            $rootID,
            $message !== '' ? $message : 'Starttimeout-Schutzfenster beendet; keine Relaisbestätigung.',
            false
        );
    } else {
        J_FinishIdle($rootID, 'Abbruch-Schutzfenster beendet');
    }
}

function J_ResetError(int $rootID): void
{
    $state = J_RelayState($rootID);
    if ($state !== J_DIR_NONE) {
        J_SetLastAction($rootID, 'Fehlerquittierung abgelehnt: zuerst beide Relais lokal ausschalten');
        return;
    }

    J_NextOrder($rootID);
    J_SetWorker($rootID, false);
    J_ClearPending($rootID);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), -1);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), J_DIR_NONE);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Auftragstyp'), J_ORDER_NONE);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Erwartete_Richtung'), J_DIR_NONE);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Geplante_Dauer_ms'), 0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Zielzeit_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Bestaetigung_bis_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Abbruch_bis_ms'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Fehlerphase'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Endlage_Hart'), false);
    SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), '');
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), J_DIR_NONE);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_IDLE);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
    J_SetLastAction($rootID, 'Fehler quittiert; keine LCN-Taste gesendet');
}

function J_FinishIdle(int $rootID, string $reason): void
{
    J_SetWorker($rootID, false);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Auftragstyp'), J_ORDER_NONE);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Erwartete_Richtung'), J_DIR_NONE);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Geplante_Dauer_ms'), 0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Zielzeit_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Abbruch_bis_ms'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Endlage_Hart'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Fehlerphase'), false);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_IDLE);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
    if (J_RelayState($rootID) === J_DIR_NONE) {
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), J_DIR_NONE);
    }
    J_SetLastAction($rootID, $reason);
}

function J_SetError(int $rootID, string $message, bool $tryStop): void
{
    // Sicherheitsprinzip V0.1.13: Ein Laufzeitfehler verriegelt die
    // Symcon-Instanz sofort. Es wird kein weiterer LCN-Toggle gesendet.
    // Die lokale LCN-Bedienung bleibt dadurch unbeeinflusst und der Nutzer
    // stoppt eine gegebenenfalls noch aktive Fahrt direkt über LCN.
    J_NextOrder($rootID);
    J_ClearPending($rootID);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), -1);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), J_DIR_NONE);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Auftragstyp'), J_ORDER_NONE);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Erwartete_Richtung'), J_DIR_NONE);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Fehlerphase'), false);
    SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), $message);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_ERROR);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'), false);
    J_SetWorker($rootID, false);
    J_SetLastAction($rootID, 'FEHLER VERRIEGELT: ' . $message);

    if (IPS_FunctionExists('LCNJAL_LatchFault')) {
        LCNJAL_LatchFault($rootID, $message);
    } else {
        IPS_LogMessage('Jalousie', 'LCNJAL_LatchFault fehlt; Fehler: ' . $message);
    }
}

function J_BeginStatusSync(int $rootID, string $reason): void
{
    J_SetWorker($rootID, false);
    // M22-Ausgangsstatus kann beim RequestStatus einen persistenten Toggle-Baselinewert
    // liefern. Die GT8-OnChange-Ereignisse werden deshalb waehrend des gesamten
    // Statusabgleichs deaktiviert und erst nach erfolgreichem Abschluss reaktiviert.
    J_SetGt8EventsActive($rootID, false);
    J_NextOrder($rootID);
    J_ClearPending($rootID);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), -1);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), J_DIR_NONE);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Fehlerphase'), false);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Auftragstyp'), J_ORDER_NONE);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Erwartete_Richtung'), J_DIR_NONE);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Startzeit_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Zielzeit_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Bestaetigung_bis_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Abbruch_bis_ms'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Sync_Relais_AUF_Empfangen'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Sync_Relais_AB_Empfangen'), false);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Kernel_Startzeit'), J_KernelStart());
    SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), '');
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'), false);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_SYNC);

    $requestErrors = [];
    if (J_ConfigBool($rootID, 'Statusabfrage_beim_Start')) {
        if (!IPS_FunctionExists('LCN_RequestStatus')) {
            $requestErrors[] = 'LCN_RequestStatus ist in diesem Symcon-Kernel nicht registriert. LCN-Modulinstallation pruefen.';
        } else {
            $statusInstances = array_values(array_unique([
                J_ConfigInt($rootID, 'LCN_Sendemodulinstanz_ID'),
                J_ConfigInt($rootID, 'LCN_Aktormodulinstanz_ID'),
            ]));
            foreach ($statusInstances as $instanceID) {
                if (!J_LcnInstanceReady($instanceID)) {
                    $requestErrors[] = 'LCN-Modulinstanz nicht aktiv/kein Splitter: ' . $instanceID;
                    continue;
                }
                if (!LCN_RequestStatus($instanceID)) {
                    $requestErrors[] = 'LCN_RequestStatus nicht angenommen: ' . $instanceID;
                }
            }
        }
    }

    if ($requestErrors !== []) {
        J_SetError($rootID, implode(' | ', $requestErrors), false);
        return;
    }

    $until = J_NowMs() + J_ConfigInt($rootID, 'Statussync_ms');
    SetValueFloat(J_ID($rootID, '05_Intern', 'Sync_bis_ms'), $until);
    J_SetWorker($rootID, true);
    J_SetLastAction($rootID, $reason . '; LCN-Statusabgleich gestartet');
}

function J_CompleteStatusSync(int $rootID, int $order): void
{
    if ($order !== J_CurrentOrder($rootID)) {
        return;
    }
    if (GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase')) !== J_PHASE_SYNC) {
        return;
    }

    // LCN_RequestStatus bestaetigt nur die Annahme der Anfrage. Der Abschluss
    // verlangt deshalb fuer beide Motorrelais ein OnUpdate-Ereignis aus genau
    // diesem Synchronisationslauf. Dies funktioniert auch bei einer erneuten
    // Initialisierung innerhalb desselben Kernelstarts.
    if (J_ConfigBool($rootID, 'Statusabfrage_beim_Start')) {
        $missing = [];
        if (!GetValueBoolean(J_ID($rootID, '05_Intern', 'Sync_Relais_AUF_Empfangen'))) {
            $missing[] = 'Relais AUF';
        }
        if (!GetValueBoolean(J_ID($rootID, '05_Intern', 'Sync_Relais_AB_Empfangen'))) {
            $missing[] = 'Relais AB';
        }
        if ($missing !== []) {
            SetValueFloat(J_ID($rootID, '05_Intern', 'Sync_bis_ms'), 0.0);
            J_SetError(
                $rootID,
                'Statusabgleich ohne aktuelle OnUpdate-Rueckmeldung: ' . implode(', ', $missing)
                    . '. Statussync_ms erhoehen oder LCN/PCHK und Relaisereignisse pruefen.',
                false
            );
            return;
        }
    }

    // Erst jetzt wieder freigeben: So kann kein verzögert ausgeführtes
    // Baseline-OnChange des M22 nach dem Statusabgleich einen Lamellenauftrag starten.
    J_SetGt8EventsActive($rootID, true);

    $state = J_RelayState($rootID);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), $state);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Letzte_Statusmeldung'), time());
    SetValueFloat(J_ID($rootID, '05_Intern', 'Sync_bis_ms'), 0.0);
    SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), '');

    if ($state === J_DIR_BOTH) {
        J_SetError($rootID, 'Statusabgleich: AUF und AB gleichzeitig aktiv.', false);
        return;
    }
    if (J_IsDirection($state)) {
        J_SnapshotRealStart($rootID, $state, J_NowMs());
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_EXTERNAL);
        SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
        J_SetWorker($rootID, true);
        J_SetLastAction($rootID, 'Statusabgleich: aktive externe Fahrt wird ab jetzt verfolgt');
        return;
    }

    J_FinishIdle($rootID, 'Statusabgleich abgeschlossen; Referenzfahrt erforderlich');
}

function J_InitializeRuntime(int $rootID): void
{
    J_BeginStatusSync($rootID, 'Initialisierung');
}

function J_StatusText(int $rootID): string
{
    $lines = [];
    $lines[] = 'Jalousie: ' . IPS_GetName($rootID) . ' (ID ' . $rootID . ')';
    $lines[] = 'Fahrstatus: ' . GetValueFormatted(J_ID($rootID, '04_Istwerte', 'Fahrstatus'));
    $lines[] = 'Phase: ' . GetValueFormatted(J_ID($rootID, '04_Istwerte', 'Phase'));
    $lines[] = 'Ist Behang: ' . GetValueFormatted(J_ID($rootID, '04_Istwerte', 'Ist_Behang'));
    $lines[] = 'Ist Lamelle: ' . GetValueFormatted(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'));
    $lines[] = 'Referenziert: ' . (GetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert')) ? 'JA' : 'NEIN');
    $lines[] = 'Auftragsnummer: ' . J_CurrentOrder($rootID);
    $lines[] = 'Kernelstart: ' . date('d.m.Y H:i:s', GetValueInteger(J_ID($rootID, '05_Intern', 'Kernel_Startzeit')));
    $lines[] = 'Fehler: ' . GetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'));
    return implode(PHP_EOL, $lines) . PHP_EOL;
}
