<?php
/**
 * Jalousiesteuerung LCN / IP-Symcon 9.0
 * V11.9 - Zentraler PHP-Controller
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

    if (IPS_FunctionExists('LCNJAL_IsMaintenanceActive')
        && LCNJAL_IsMaintenanceActive($rootID)) {
        // Während ApplyChanges kann der Objektbaum gerade neu aufgebaut werden.
        // Deshalb hier bewusst keine Statusvariable schreiben und nur ohne
        // LCN-Befehl zurückkehren.
        IPS_LogMessage('Jalousie', 'Modulaktualisierung aktiv; Controller-Aufruf ohne LCN-Befehl verworfen (Instanz #' . $rootID . ').');
        return;
    }

    if (!in_array($action, ['RESET_ERROR', 'RELAY_UPDATE', 'STATUS', 'HEALTHCHECK', 'EXTERNAL_REFERENCE', 'EXTERNAL_STOP', 'STOP_TIMEOUT'], true)
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
            J_RunHealthcheck($rootID);
            break;

        case 'EXTERNAL_REFERENCE':
            J_HandleExternalReferenceDeadline($rootID, (int) ($_IPS['ORDER'] ?? -1));
            break;

        case 'EXTERNAL_STOP':
            J_HandleExternalEndStop($rootID, (int) ($_IPS['ORDER'] ?? -1));
            break;

        case 'STATUS':
        default:
            echo J_StatusText($rootID);
            break;
    }
} catch (Throwable $e) {
    try {
        if (J_IsTransientModuleInfrastructureError($e)) {
            J_SetLastAction($rootID, 'Vorübergehender Modul-/Updatezustand; kein LCN-Befehl gesendet: ' . $e->getMessage());
        } else {
            J_SetError($rootID, 'Controller-Fehler: ' . $e->getMessage(), false);
        }
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

function J_IsTransientModuleInfrastructureError(Throwable $error): bool
{
    $message = $error->getMessage();
    return str_contains($message, 'Die sichere Hardwarebindung des Moduls ist nicht verfügbar')
        || str_contains($message, 'Die sichere Hardwarebindung ist ungültig')
        || str_contains($message, 'Sichere richtungsgebundene Befehlsfunktion fehlt')
        || str_contains($message, 'Modulaktualisierung läuft');
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

function J_SetReference(int $rootID, float $position, float $slat, string $reason): void
{
    $position = $position < 50.0 ? 0.0 : 100.0;
    $slat = $position <= 0.0 ? 0.0 : 100.0;
    SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Behang'), $position);
    SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'), $slat);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'), true);
    $endID = J_ID($rootID, '04_Istwerte', 'Referenz_Endlage');
    SetValueInteger($endID, $position <= 0.0 ? 1 : 2);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Letzte_Referenzierung'), time());
    if (IPS_FunctionExists('LCNJAL_StoreReference')) {
        LCNJAL_StoreReference($rootID, $position, $slat, $reason);
    }
}

function J_InvalidateReference(int $rootID, string $reason): void
{
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'), false);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Referenz_Endlage'), 0);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Letzte_Referenzierung'), 0);
    if (IPS_FunctionExists('LCNJAL_InvalidateReference')) {
        LCNJAL_InvalidateReference($rootID, $reason);
    }
}

function J_MarkRelaysOff(int $rootID): void
{
    if (J_RelayState($rootID) === J_DIR_NONE) {
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Letzte_Relais_AUS_Bestaetigung'), time());
    }
}

function J_ClearCommandLease(int $rootID): void
{
    if (IPS_FunctionExists('LCNJAL_ClearCommandLease')) {
        LCNJAL_ClearCommandLease($rootID);
    }
}

function J_SendRouteKey(array $binding): string
{
    $address = $binding['sendModuleAddress'] ?? [];
    return is_array($address) ? (string) ($address['routeKey'] ?? '') : '';
}

function J_ReportPossibleForeignCommand(int $rootID, int $direction, float $nowMs): int
{
    if (!IPS_FunctionExists('IPS_GetInstanceListByModuleID')
        || !IPS_FunctionExists('LCNJAL_GetCommandLease')
        || !IPS_FunctionExists('LCNJAL_ReportForeignRelayResponse')
        || !IPS_FunctionExists('LCNJAL_GetHardwareBinding')) {
        return 0;
    }

    $candidates = [];
    foreach (IPS_GetInstanceListByModuleID('{3057B192-E835-4916-AF1D-D89D6302DF74}') as $instanceID) {
        $instanceID = (int) $instanceID;
        if ($instanceID <= 0 || $instanceID === $rootID || !IPS_InstanceExists($instanceID)) {
            continue;
        }
        $lease = json_decode(LCNJAL_GetCommandLease($instanceID), true);
        if (!is_array($lease)
            || !(bool) ($lease['active'] ?? false)
            || (int) ($lease['expectedRelayState'] ?? -1) !== J_DIR_NONE) {
            continue;
        }
        $startedMs = (float) ($lease['startedMs'] ?? 0.0);
        if ($startedMs <= 0.0 || $nowMs - $startedMs < -500.0 || $nowMs - $startedMs > 10000.0) {
            continue;
        }
        $candidates[] = ['instanceID' => $instanceID, 'lease' => $lease];
    }

    // Nur eine einzige offene START-Transaktion lässt sich sicher zeitlich
    // zuordnen. Bei mehreren parallelen, noch unbestätigten Starts wird keine
    // Fremdzuordnung behauptet.
    if (count($candidates) !== 1) {
        return 0;
    }

    $ownerID = (int) $candidates[0]['instanceID'];
    $lease = (array) $candidates[0]['lease'];
    $ownerBinding = json_decode(LCNJAL_GetHardwareBinding($ownerID), true);
    $receiverBinding = J_HardwareBinding($rootID);
    if (!is_array($ownerBinding)) {
        return 0;
    }

    $ownerDirection = (int) ($lease['direction'] ?? J_DIR_NONE);
    $ownerTs = $ownerDirection === J_DIR_UP
        ? (string) ($ownerBinding['tsShortUp'] ?? '')
        : (string) ($ownerBinding['tsShortDown'] ?? '');
    $ownerRouteKey = J_SendRouteKey($ownerBinding);
    $receiverActorAddress = $receiverBinding['actorModuleAddress'] ?? [];
    $receiverActorRouteKey = is_array($receiverActorAddress)
        ? (string) ($receiverActorAddress['routeKey'] ?? '')
        : '';

    SetValueInteger(J_ID($rootID, '05_Intern', 'Fremdbefehl_Quelle'), $ownerID);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Fremdbefehl_Erkannt_ms'), $nowMs);
    LCNJAL_ReportForeignRelayResponse(
        $ownerID,
        $rootID,
        $direction,
        json_encode([
            'correlationCandidate' => true,
            'ownerDirection' => $ownerDirection,
            'ownerTs' => $ownerTs,
            'ownerSendRouteKey' => $ownerRouteKey,
            'ownerLeaseStartedMs' => (float) ($lease['startedMs'] ?? 0.0),
            'receiverActorRouteKey' => $receiverActorRouteKey,
            'receiverRelayUpVariableID' => (int) ($receiverBinding['relayUpVariableID'] ?? 0),
            'receiverRelayDownVariableID' => (int) ($receiverBinding['relayDownVariableID'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
    );
    return $ownerID;
}

function J_GetForeignRelayResponse(int $rootID): array
{
    if (!IPS_FunctionExists('LCNJAL_GetForeignRelayResponse')) {
        return [];
    }
    $response = json_decode(LCNJAL_GetForeignRelayResponse($rootID), true);
    return is_array($response) ? $response : [];
}

function J_GetRecentForeignRelayResponse(int $rootID, float $maxAgeMs = 15000.0): array
{
    $response = J_GetForeignRelayResponse($rootID);
    $reportedMs = (float) ($response['reportedMs'] ?? 0.0);
    $receiverID = (int) ($response['receiverInstanceID'] ?? 0);
    $direction = (int) ($response['direction'] ?? J_DIR_NONE);
    if ($reportedMs <= 0.0
        || $receiverID <= 0
        || !J_IsDirection($direction)
        || J_NowMs() - $reportedMs < -500.0
        || J_NowMs() - $reportedMs > $maxAgeMs) {
        return [];
    }
    return $response;
}

function J_HandleForeignRelayResponse(int $rootID, int $order, bool $requireFreshSelectedRelaysOff = false): bool
{
    if (!$requireFreshSelectedRelaysOff
        || $order !== J_CurrentOrder($rootID)
        || GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase')) !== J_PHASE_WAIT_START
        || GetValueInteger(J_ID($rootID, '05_Intern', 'Auftragstyp')) === J_ORDER_NONE
        || J_RelayState($rootID) !== J_DIR_NONE) {
        return false;
    }

    // Ein Fremdstart wird erst dann als Routingfehler verriegelt, wenn eine
    // ausdrücklich angeforderte, frische Statusantwort bestätigt hat, dass
    // beide ausgewählten Relais des Senders weiterhin AUS sind. Damit kann ein
    // zufällig zeitgleich bedienter externer GT8 nicht allein durch seinen
    // Zeitstempel eine falsche Verriegelung auslösen.
    $freshUp = GetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Relais_AUF_Empfangen'));
    $freshDown = GetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Relais_AB_Empfangen'));
    if (!$freshUp || !$freshDown) {
        return false;
    }

    $foreign = J_GetRecentForeignRelayResponse($rootID);
    if ($foreign === [] || !(bool) ($foreign['correlationCandidate'] ?? false)) {
        return false;
    }

    $reportedMs = (float) ($foreign['reportedMs'] ?? 0.0);
    $sentMs = GetValueFloat(J_ID($rootID, '05_Intern', 'Befehl_gesendet_ms'));
    $correlationWindowMs = max(
        1000,
        min(
            5000,
            J_ConfigInt($rootID, 'Relaisbestaetigung_ms')
                + J_ConfigInt($rootID, 'Relais_Koaleszenz_ms')
                + 750
        )
    );
    if ($sentMs <= 0.0
        || $reportedMs < $sentMs - 250.0
        || $reportedMs - $sentMs > $correlationWindowMs) {
        return false;
    }

    $receiverID = (int) ($foreign['receiverInstanceID'] ?? 0);
    $receiverName = (string) ($foreign['receiverName'] ?? '');
    $foreignDirection = (int) ($foreign['direction'] ?? J_DIR_NONE);
    $expectedDirection = GetValueInteger(J_ID($rootID, '05_Intern', 'Erwartete_Richtung'));
    $binding = J_HardwareBinding($rootID);
    $ts = $expectedDirection === J_DIR_UP
        ? (string) $binding['tsShortUp']
        : (string) $binding['tsShortDown'];
    $sendRouteKey = J_SendRouteKey($binding);

    // Schutz gegen eine alte oder zwischenzeitlich überholte Fremdantwort.
    if ((int) ($foreign['ownerDirection'] ?? J_DIR_NONE) !== $expectedDirection
        || !hash_equals((string) ($foreign['ownerTs'] ?? ''), $ts)
        || $sendRouteKey === ''
        || !hash_equals((string) ($foreign['ownerSendRouteKey'] ?? ''), $sendRouteKey)) {
        return false;
    }

    J_ClearCommandLease($rootID);
    if (IPS_FunctionExists('LCNJAL_BlockCurrentRouting')) {
        LCNJAL_BlockCurrentRouting(
            $rootID,
            'Zeitlich bestätigter Fremdstart von ' . ($receiverName !== '' ? $receiverName . ' ' : '') . '(#' . $receiverID
                . ') nach Senderoute ' . $sendRouteKey . ' und TS-Befehl ' . $ts
        );
    }
    J_SetError(
        $rootID,
        'TS-Routingfehler bestätigt: Instanz ' . IPS_GetName($rootID) . ' (#' . $rootID . ') sendete Richtung '
            . ($expectedDirection === J_DIR_UP ? 'AUF' : 'ZU')
            . ' über reale Senderoute ' . $sendRouteKey . ' (Sendemodul #' . (int) $binding['sendModuleID'] . ') mit TS ' . $ts
            . '. Eine frische Statusabfrage bestätigte beide ausgewählten Relais #' . (int) $binding['relayUpVariableID']
            . '/#' . (int) $binding['relayDownVariableID'] . ' weiterhin AUS; im selben Startfenster meldete jedoch '
            . ($receiverName !== '' ? $receiverName . ' ' : '') . '(#' . $receiverID . ') Richtung '
            . ($foreignDirection === J_DIR_UP ? 'AUF' : 'ZU')
            . '. Reale Segment-/Target-Adresse des Sendemoduls und TS-KURZ-Programmierung prüfen. Die vorhandene Positionsreferenz wurde nicht verändert.',
        false,
        false
    );
    return true;
}

function J_PrepareStartConfirmation(int $rootID): void
{
    SetValueFloat(J_ID($rootID, '05_Intern', 'Bestaetigung_bis_ms'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Nachfrage_Aktiv'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Relais_AUF_Empfangen'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Relais_AB_Empfangen'), false);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Befehl_gesendet_ms'), 0.0);
}

function J_ArmStartConfirmation(int $rootID): void
{
    $sentAt = J_NowMs();
    SetValueFloat(J_ID($rootID, '05_Intern', 'Befehl_gesendet_ms'), $sentAt);
    SetValueFloat(
        J_ID($rootID, '05_Intern', 'Bestaetigung_bis_ms'),
        $sentAt + max(500, J_ConfigInt($rootID, 'Relaisbestaetigung_ms'))
    );
    J_SetWorker($rootID, true);
}

function J_PrepareStopConfirmation(int $rootID, bool $preserveVerifiedRetry = false): void
{
    SetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stopstatus_Nachfrage_Aktiv'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stopstatus_Relais_AUF_Empfangen'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stopstatus_Relais_AB_Empfangen'), false);
    if (!$preserveVerifiedRetry) {
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Wiederholung_Gesendet'), false);
    }
    SetValueFloat(J_ID($rootID, '05_Intern', 'Befehl_gesendet_ms'), 0.0);
}

function J_ArmStopConfirmation(int $rootID): void
{
    $sentAt = J_NowMs();
    SetValueFloat(J_ID($rootID, '05_Intern', 'Befehl_gesendet_ms'), $sentAt);
    SetValueFloat(
        J_ID($rootID, '05_Intern', 'Stop_bis_ms'),
        $sentAt + max(500, J_ConfigInt($rootID, 'Stoppbestaetigung_ms'))
    );
    J_SetWorker($rootID, true);
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

function J_HardwareBinding(int $rootID): array
{
    static $cache = [];
    if (isset($cache[$rootID])) {
        return $cache[$rootID];
    }
    if (!IPS_FunctionExists('LCNJAL_GetHardwareBinding')) {
        throw new RuntimeException('Die sichere Hardwarebindung des Moduls ist nicht verfügbar.');
    }

    $binding = json_decode(LCNJAL_GetHardwareBinding($rootID), true);
    if (!is_array($binding)) {
        throw new RuntimeException('Die sichere Hardwarebindung ist ungültig.');
    }
    foreach ([
        'sendModuleID',
        'actorModuleID',
        'relayUpVariableID',
        'relayDownVariableID',
        'gt8LongUpVariableID',
        'gt8LongDownVariableID',
        'tsShortUp',
        'tsShortDown',
    ] as $key) {
        if (!array_key_exists($key, $binding)) {
            throw new RuntimeException('Hardwarebindung unvollständig: ' . $key);
        }
    }

    $cache[$rootID] = $binding;
    return $binding;
}

function J_ConfiguredRelayIDs(int $rootID): array
{
    $binding = J_HardwareBinding($rootID);
    return [(int) $binding['relayUpVariableID'], (int) $binding['relayDownVariableID']];
}

function J_IsConfiguredRelayTrigger(int $rootID, int $variableID): bool
{
    if ($variableID <= 0) {
        return false;
    }
    return in_array($variableID, J_ConfiguredRelayIDs($rootID), true);
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

    $binding = J_HardwareBinding($rootID);
    $ids = [
        'Soll_Behang'      => J_ID($rootID, '03_Bedienung', 'Soll_Behang'),
        'Soll_Lamelle'     => J_ID($rootID, '03_Bedienung', 'Soll_Lamelle'),
        'ShakeFree_Aktiv'  => J_ID($rootID, '03_Bedienung', 'ShakeFree_Aktiv'),
        'Stopp'            => J_ID($rootID, '03_Bedienung', 'Stopp'),
        'Referenzfahrt'    => J_ID($rootID, '03_Bedienung', 'Referenzfahrt'),
        'Relais_AUF'       => (int) $binding['relayUpVariableID'],
        'Relais_AB'        => (int) $binding['relayDownVariableID'],
        'GT8_LANG_AUF'     => (int) $binding['gt8LongUpVariableID'],
        'GT8_LANG_AB'      => (int) $binding['gt8LongDownVariableID'],
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
    [$upID, $downID] = J_ConfiguredRelayIDs($rootID);
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

function J_LcnModuleForVariable(int $variableID): int
{
    if (!IPS_VariableExists($variableID)) {
        return 0;
    }

    $currentObjectID = IPS_GetParent($variableID);
    $logicalVisited = [];

    for ($logicalGuard = 0; $logicalGuard < 32 && $currentObjectID > 0; $logicalGuard++) {
        if (isset($logicalVisited[$currentObjectID])) {
            break;
        }
        $logicalVisited[$currentObjectID] = true;

        if (IPS_InstanceExists($currentObjectID)) {
            $currentInstanceID = $currentObjectID;
            $connectionVisited = [];

            for ($connectionGuard = 0; $connectionGuard < 32 && $currentInstanceID > 0; $connectionGuard++) {
                if (isset($connectionVisited[$currentInstanceID]) || !IPS_InstanceExists($currentInstanceID)) {
                    break;
                }
                $connectionVisited[$currentInstanceID] = true;

                if (J_LcnInstanceReady($currentInstanceID)) {
                    return $currentInstanceID;
                }

                $instance = IPS_GetInstance($currentInstanceID);
                $currentInstanceID = (int) ($instance['ConnectionID'] ?? 0);
            }
        }

        $currentObjectID = IPS_GetParent($currentObjectID);
    }

    return 0;
}

function J_SendDirection(int $rootID, int $direction, int $expectedRelayState): bool
{
    if (!J_IsDirection($direction)) {
        J_SetError($rootID, 'Ungueltige Fahrtrichtung: ' . $direction, false);
        return false;
    }
    if (!IPS_FunctionExists('LCNJAL_SendDirectionCommand')) {
        J_SetError($rootID, 'Sichere richtungsgebundene Befehlsfunktion fehlt.', false);
        return false;
    }

    try {
        $ok = LCNJAL_SendDirectionCommand($rootID, $direction, $expectedRelayState);
        J_Log($rootID, 'Sicherer Richtungsbefehl ' . ($direction === J_DIR_UP ? 'AUF' : 'ZU') . ' bei erwartetem Relaiszustand ' . $expectedRelayState . ' => ' . ($ok ? 'OK' : 'FEHLER'));
        return $ok;
    } catch (Throwable $e) {
        // Ein nicht bestätigter START bei weiterhin real ausgeschalteten Relais
        // ist kein gefährlicher Motorzustand. Er wird ohne dauerhafte
        // Fehlerverriegelung verworfen und durch das Spätstart-Schutzfenster
        // abgesichert. STOP-Fehler bei aktivem Relais bleiben dagegen
        // sicherheitskritisch und verriegeln die Instanz.
        if ($expectedRelayState === J_DIR_NONE && J_RelayState($rootID) === J_DIR_NONE) {
            $message = 'Starttelegramm konnte nicht sicher gesendet werden: ' . $e->getMessage();
            SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), $message);
            J_ClearPending($rootID);
            SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), -1);
            SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), J_DIR_NONE);
            J_BeginCancelGuard($rootID, 'Startsendung verworfen; Spätstart-Schutz ohne Fehlerverriegelung', false);
            return false;
        }
        J_SetError($rootID, 'Richtungsbefehl gesperrt: ' . $e->getMessage(), false);
        return false;
    }
}

function J_SendStopForRealDirection(int $rootID): bool
{
    $state = J_RelayState($rootID);
    if ($state === J_DIR_UP || $state === J_DIR_DOWN) {
        return J_SendDirection($rootID, $state, $state);
    }
    if ($state === J_DIR_BOTH) {
        J_SetError($rootID, 'AUF und AB sind gleichzeitig aktiv. LCN-Verriegelung pruefen.', false, true);
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

function J_DirectionalBlindTravelMs(int $rootID, int $direction): float
{
    $turnMs = (float) J_ConfigInt($rootID, 'Wendezeit_ms');
    $totalUpMs = (float) J_ConfigInt($rootID, 'Gesamtlaufzeit_ms');
    $totalDownMs = (float) J_ConfigInt($rootID, 'Behanglaufzeit_ms');

    // Kompatible Konfigurations-Idents:
    // Gesamtlaufzeit_ms = Gesamtzeit 100 % ZU -> 0 % AUF inkl. voller Wendezeit.
    // Behanglaufzeit_ms = Gesamtzeit 0 % AUF -> 100 % ZU.
    $blindTravelMs = $direction === J_DIR_UP
        ? $totalUpMs - $turnMs
        : $totalDownMs;

    if ($blindTravelMs <= 0.0) {
        throw new RuntimeException('Richtungsabhängige Behanglaufzeit ist ungueltig.');
    }

    return $blindTravelMs;
}

function J_DirectionalSoftStopMs(int $rootID, int $direction): float
{
    $ident = $direction === J_DIR_UP ? 'Sanftstopp_AUF_ms' : 'Sanftstopp_ZU_ms';
    $softStopMs = (float) J_ConfigInt($rootID, $ident);
    $blindTravelMs = J_DirectionalBlindTravelMs($rootID, $direction);

    if ($softStopMs < 0.0 || $softStopMs >= $blindTravelMs) {
        throw new RuntimeException('Richtungsabhängiger Sanft-Stopp ist ungueltig.');
    }

    return $softStopMs;
}


/**
 * Prozentualer Fahrweg innerhalb der physischen Sanft-Stopp-Endzone.
 * T = vollständige Behanglaufzeit, S = Sanft-Stopp-Zeit.
 * Bei linearer Verzögerung ist der Sanft-Stopp-Weg die Dreiecksfläche S/2;
 * bezogen auf den Gesamtweg T-S/2 ergibt sich S/(2*T-S).
 */
function J_DirectionalSoftStopRangePercent(int $rootID, int $direction): float
{
    $blindTravelMs = J_DirectionalBlindTravelMs($rootID, $direction);
    $softStopMs = J_DirectionalSoftStopMs($rootID, $direction);
    if ($softStopMs <= 0.0) {
        return 0.0;
    }

    return 100.0 * $softStopMs / (2.0 * $blindTravelMs - $softStopMs);
}

/**
 * Liefert die Zeitkoordinate des Behangs innerhalb einer vollständigen Fahrt
 * in der angegebenen Richtung. 0 ms entspricht der gegenüberliegenden
 * Endlage, die volle Behanglaufzeit der angefahrenen Endlage.
 */
function J_BlindTimeCoordinateMs(int $rootID, float $position, int $direction): float
{
    $blindTravelMs = J_DirectionalBlindTravelMs($rootID, $direction);
    $softStopMs = J_DirectionalSoftStopMs($rootID, $direction);
    $position = J_Clamp($position);
    $progress = $direction === J_DIR_UP
        ? (100.0 - $position) / 100.0
        : $position / 100.0;

    if ($softStopMs <= 0.0) {
        return $progress * $blindTravelMs;
    }

    // Die Gesamtstrecke entspricht bei linearer Verzögerung während S:
    // volle Geschwindigkeit für T-S plus Dreiecksfläche S/2.
    $effectiveTravelMs = $blindTravelMs - $softStopMs / 2.0;
    $distanceAtFullSpeed = $progress * $effectiveTravelMs;
    $softStopStartMs = $blindTravelMs - $softStopMs;
    $softStopRange = J_DirectionalSoftStopRangePercent($rootID, $direction) / 100.0;
    $softStopStartProgress = 1.0 - $softStopRange;

    // Außerhalb der positionsabhängigen Endzone bleibt die Geschwindigkeit
    // konstant. Innerhalb der Zone wird nur der bis zum Ziel durchfahrene
    // Anteil der linearen Verzögerung berücksichtigt.
    if ($progress <= $softStopStartProgress) {
        return $distanceAtFullSpeed;
    }

    $softDistance = min(
        $softStopMs / 2.0,
        max(0.0, $distanceAtFullSpeed - $softStopStartMs)
    );
    $discriminant = max(0.0, $softStopMs * $softStopMs - 2.0 * $softStopMs * $softDistance);
    $softElapsedMs = $softStopMs - sqrt($discriminant);

    return $softStopStartMs + $softElapsedMs;
}

/**
 * Inverse Funktion zu J_BlindTimeCoordinateMs(): berechnet aus einer
 * Zeitkoordinate die Behangposition einschließlich linearer Verzögerung nur
 * unmittelbar vor der angefahrenen Endlage.
 */
function J_BlindPositionAtTimeCoordinate(int $rootID, float $timeCoordinateMs, int $direction): float
{
    $blindTravelMs = J_DirectionalBlindTravelMs($rootID, $direction);
    $softStopMs = J_DirectionalSoftStopMs($rootID, $direction);
    $timeCoordinateMs = max(0.0, min($blindTravelMs, $timeCoordinateMs));

    if ($softStopMs <= 0.0) {
        $progress = $timeCoordinateMs / $blindTravelMs;
    } else {
        $effectiveTravelMs = $blindTravelMs - $softStopMs / 2.0;
        $softStopStartMs = $blindTravelMs - $softStopMs;

        if ($timeCoordinateMs <= $softStopStartMs) {
            $distanceAtFullSpeed = $timeCoordinateMs;
        } else {
            $softElapsedMs = $timeCoordinateMs - $softStopStartMs;
            $distanceAtFullSpeed = $softStopStartMs
                + $softElapsedMs
                - ($softElapsedMs * $softElapsedMs) / (2.0 * $softStopMs);
        }
        $progress = $distanceAtFullSpeed / $effectiveTravelMs;
    }

    $progress = max(0.0, min(1.0, $progress));
    return $direction === J_DIR_UP
        ? 100.0 * (1.0 - $progress)
        : 100.0 * $progress;
}

function J_ReferenceDurationMs(int $rootID, int $direction): int
{
    $totalMs = $direction === J_DIR_UP
        ? J_ConfigInt($rootID, 'Gesamtlaufzeit_ms')
        : J_ConfigInt($rootID, 'Behanglaufzeit_ms');
    $duration = $totalMs + J_ConfigInt($rootID, 'Referenzreserve_ms');
    return min($duration, J_ConfigInt($rootID, 'MaxFahrt_ms'));
}

function J_BlindDurationMs(int $rootID, float $startBlind, float $targetBlind, float $startSlat, int $direction, bool $withReserve): int
{
    // Prozentpositionen sind Fahrweg. Daher werden Start und Ziel immer über
    // dieselbe absolute Endlagenkennlinie in Zeitkoordinaten umgerechnet.
    // Liegt das Ziel außerhalb der Endzone, ist der Abschnitt rein linear.
    // Liegt es innerhalb, enthält die Dauer genau den bis dorthin gefahrenen
    // Anteil der Sanft-Stopp-Phase – ohne zusätzliche Abbremsung am Ziel.
    $startCoordinateMs = J_BlindTimeCoordinateMs($rootID, $startBlind, $direction);
    $targetCoordinateMs = J_BlindTimeCoordinateMs($rootID, $targetBlind, $direction);
    $blindMs = max(0.0, $targetCoordinateMs - $startCoordinateMs);

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
    $blindTravelMs = J_DirectionalBlindTravelMs($rootID, $state);
    if ($turnMs <= 0.0 || $blindTravelMs <= 0.0) {
        throw new RuntimeException('Richtungsabhängige Laufzeiten muessen groesser 0 sein.');
    }

    $turnNeeded = J_SlatTurnTimeMs($rootID, $startSlat, $state);
    $turnUsed = min($elapsed, $turnNeeded);
    $slat = $state === J_DIR_UP
        ? $startSlat - 100.0 * $turnUsed / $turnMs
        : $startSlat + 100.0 * $turnUsed / $turnMs;

    $blindStartDelay = J_BlindStartDelayMs($rootID, $startBlind, $startSlat, $state);
    $blindElapsed = max(0.0, $elapsed - $blindStartDelay);

    // Die physische Geschwindigkeit hängt von der absoluten Position zur
    // angefahrenen Endlage ab, nicht davon, ob das Sollziel 0/100 % oder eine
    // Zwischenposition ist. Ein Zwischenziel in der Endzone nutzt daher den
    // bis zu dieser Position tatsächlich durchfahrenen Sanft-Stopp-Anteil.
    $blindStartCoordinateMs = J_BlindTimeCoordinateMs($rootID, $startBlind, $state);
    $blind = J_BlindPositionAtTimeCoordinate(
        $rootID,
        $blindStartCoordinateMs + $blindElapsed,
        $state
    );

    SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'), J_Clamp($slat));
    SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Behang'), J_Clamp($blind));
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Letzte_Fahrtdauer_ms'), (int) round($elapsed));
}

function J_SnapshotRealStart(int $rootID, int $direction, float $nowMs): void
{
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Nachfrage_Aktiv'), false);
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

function J_ArmExternalEndMonitoring(int $rootID, int $direction, float $nowMs): void
{
    $referenced = GetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'));
    $startBlind = GetValueFloat(J_ID($rootID, '05_Intern', 'Start_Behang'));
    $startSlat = GetValueFloat(J_ID($rootID, '05_Intern', 'Start_Lamelle'));
    $target = $direction === J_DIR_UP ? 0.0 : 100.0;
    $duration = $referenced
        ? J_BlindDurationMs($rootID, $startBlind, $target, $startSlat, $direction, true)
        : J_ReferenceDurationMs($rootID, $direction);

    SetValueBoolean(J_ID($rootID, '05_Intern', 'Externe_Referenz_Gesetzt'), false);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Externe_Endlage_bis_ms'), $nowMs + max(1, $duration));
    SetValueFloat(J_ID($rootID, '05_Intern', 'Externer_Autostopp_bis_ms'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Externer_Autostopp_Aktiv'), false);
}

function J_RejectWhileExternalHasPriority(int $rootID, string $request): bool
{
    $phase = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'));
    $state = J_RelayState($rootID);
    $automatic = GetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'));
    if ($phase !== J_PHASE_EXTERNAL && !(!$automatic && J_IsDirection($state))) {
        return false;
    }

    J_ClearPending($rootID);
    J_SetLastAction($rootID, $request . ' verworfen: reale LCN-/GT8-Fahrt hat Vorrang');
    return true;
}

function J_RequestBlind(int $rootID, float $target): void
{
    $target = J_Clamp($target);
    if (J_RejectWhileExternalHasPriority($rootID, 'Symcon-Behangziel')) {
        return;
    }
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
    // Nachreferenzierung mit Reserve. Unbekannte Positionen nutzen die richtungsspezifische Gesamtzeit plus Referenzreserve; MaxFahrt bleibt nur die obere Sicherheitsgrenze.
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
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Shake_Nachlauf_Aktiv'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Externe_Referenz_Gesetzt'), false);

    $hardEnd = $target <= 0.0 || $target >= 100.0;
    $positionReferenced = GetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'));
    // Ist die Position noch unbekannt, muss ein Endlagenauftrag unabhaengig vom
    // gespeicherten Rechenwert als volle Referenzfahrt laufen. Andernfalls koennte
    // der Initialwert 0 % bei einem AUF-Auftrag zu einer zu kurzen Fahrt fuehren.
    $referenceRun = $explicitReference || (!$positionReferenced && $hardEnd);
    $duration = $referenceRun
        ? J_ReferenceDurationMs($rootID, $direction)
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
    J_PrepareStartConfirmation($rootID);
    J_PrepareStopConfirmation($rootID);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Zielzeit_ms'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_WAIT_START);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), true);
    // Eine bereits gültige Referenz bleibt während der Fahrt gültig. Die
    // Position wird aus dem gespeicherten Startwert fortgeschrieben und an der
    // sicher erreichten Endlage erneut persistiert. War die Referenz zuvor
    // ungültig, bleibt sie bis zur sicheren Endlage ungültig.

    $actionName = $explicitReference
        ? 'Referenzfahrt '
        : ($referenceRun ? 'Automatische Referenzfahrt ' : 'Behangfahrt ');
    J_SetLastAction($rootID, $actionName . ($direction === J_DIR_UP ? 'AUF' : 'AB') . ', Auftrag ' . $order . '; wartet auf Sendefreigabe');
    J_SetWorker($rootID, false);
    if (!J_SendDirection($rootID, $direction, J_DIR_NONE)) {
        return;
    }
    J_ArmStartConfirmation($rootID);
    J_SetLastAction($rootID, $actionName . ($direction === J_DIR_UP ? 'AUF' : 'AB') . ', Auftrag ' . $order . '; LCN-Telegramm angenommen, Startbestätigung läuft');
}

function J_RequestSlat(int $rootID, int $target, int $forcedDirection): void
{
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Shake_Nachlauf_Aktiv'), false);
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
    J_PrepareStartConfirmation($rootID);
    J_PrepareStopConfirmation($rootID);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Zielzeit_ms'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_WAIT_START);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), true);

    J_SetLastAction($rootID, 'Lamellenfahrt auf ' . $target . ' %, Auftrag ' . $order . '; wartet auf Sendefreigabe');
    J_SetWorker($rootID, false);
    if (!J_SendDirection($rootID, $direction, J_DIR_NONE)) {
        return;
    }
    J_ArmStartConfirmation($rootID);
    J_SetLastAction($rootID, 'Lamellenfahrt auf ' . $target . ' %, Auftrag ' . $order . '; LCN-Telegramm angenommen, Startbestätigung läuft');
}

function J_RequestReference(int $rootID, int $direction): void
{
    if (J_RejectWhileExternalHasPriority($rootID, 'Symcon-Referenzfahrt')) {
        return;
    }
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
    if (J_RejectWhileExternalHasPriority($rootID, $reason)) {
        return;
    }

    $phase = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'));
    $state = J_RelayState($rootID);

    if (GetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'))) {
        J_ClearPending($rootID);
        J_SetLastAction($rootID, $reason . ': STOP bereits gesendet; auf reale AUS-Bestätigung warten');
        J_SetWorker($rootID, true);
        return;
    }

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

    if (GetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'))) {
        J_SetLastAction($rootID, $reason . ': Folgeauftrag gespeichert; bereits gesendeter STOP wird nicht wiederholt');
        J_SetWorker($rootID, true);
        return;
    }

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
    J_PrepareStartConfirmation($rootID);
    J_PrepareStopConfirmation($rootID);
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
        J_SetError($rootID, 'AUF und AB sind gleichzeitig aktiv.', false, true);
        return;
    }

    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Fehlerphase'), $errorAfter);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), true);
    J_PrepareStopConfirmation($rootID);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_STOPPING);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
    J_SetWorker($rootID, false);
    J_SetLastAction($rootID, $reason . '; STOP wartet auf Sendefreigabe');
    if (!J_SendStopForRealDirection($rootID)) {
        J_SetError($rootID, 'STOP-Befehl konnte nicht gesendet werden.', false);
        return;
    }
    if (J_RelayState($rootID) === J_DIR_NONE) {
        J_HandleRealStop($rootID, $state);
        return;
    }
    J_ArmStopConfirmation($rootID);
    J_SetLastAction($rootID, $reason . '; STOP-Telegramm angenommen, AUS-Bestätigung läuft');
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
    if ($triggerVariableID > 0 && !J_IsConfiguredRelayTrigger($rootID, $triggerVariableID)) {
        J_Log($rootID, 'Fremde oder veraltete Relaismeldung #' . $triggerVariableID . ' verworfen.');
        return;
    }
    if ($coalesce) {
        $delay = J_ConfigInt($rootID, 'Relais_Koaleszenz_ms');
        if ($delay > 0) {
            IPS_Sleep($delay);
        }
    }

    $now = J_NowMs();
    $oldState = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'));
    $newState = J_RelayState($rootID);

    if ($triggerVariableID > 0) {
        $binding = J_HardwareBinding($rootID);
        $isUpTrigger = $triggerVariableID === (int) $binding['relayUpVariableID'];
        $isDownTrigger = $triggerVariableID === (int) $binding['relayDownVariableID'];
        if (GetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Nachfrage_Aktiv'))) {
            if ($isUpTrigger) {
                SetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Relais_AUF_Empfangen'), true);
            }
            if ($isDownTrigger) {
                SetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Relais_AB_Empfangen'), true);
            }
        }
        if (GetValueBoolean(J_ID($rootID, '05_Intern', 'Stopstatus_Nachfrage_Aktiv'))) {
            if ($isUpTrigger) {
                SetValueBoolean(J_ID($rootID, '05_Intern', 'Stopstatus_Relais_AUF_Empfangen'), true);
            }
            if ($isDownTrigger) {
                SetValueBoolean(J_ID($rootID, '05_Intern', 'Stopstatus_Relais_AB_Empfangen'), true);
            }
        }
    }

    // Waehrend STATUS_SYNC werden Relaisrueckmeldungen nur als frischer Istzustand
    // uebernommen. Erst SYNC_COMPLETE entscheidet ueber IDLE oder externe Fahrt.
    if (GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase')) === J_PHASE_SYNC) {
        // OnUpdate-Ereignisse liefern den belastbaren Nachweis, dass genau diese
        // Statusvariable nach der aktuellen LCN_RequestStatus-Anfrage empfangen wurde.
        // VariableUpdated allein reicht bei einer erneuten Initialisierung im selben
        // Kernel nicht aus, weil ein alter Update-Zeitstempel noch nach Kernelstart liegt.
        if ($triggerVariableID === (int) J_HardwareBinding($rootID)['relayUpVariableID']) {
            SetValueBoolean(J_ID($rootID, '05_Intern', 'Sync_Relais_AUF_Empfangen'), true);
        }
        if ($triggerVariableID === (int) J_HardwareBinding($rootID)['relayDownVariableID']) {
            SetValueBoolean(J_ID($rootID, '05_Intern', 'Sync_Relais_AB_Empfangen'), true);
        }
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), $newState);
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Letzte_Statusmeldung'), time());
        if ($newState === J_DIR_BOTH) {
            J_SetError($rootID, 'Statusabgleich: AUF und AB gleichzeitig aktiv.', false, true);
        }
        return;
    }

    if ($newState === J_DIR_BOTH) {
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), J_DIR_BOTH);
        J_SetError($rootID, 'AUF und AB sind gleichzeitig aktiv. LCN-Verriegelung pruefen.', false, true);
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
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Stopstatus_Nachfrage_Aktiv'), false);
        J_SnapshotRealStart($rootID, $newState, $now);
        J_ArmExternalEndMonitoring($rootID, $newState, $now);
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
        J_ClearCommandLease($rootID);
        SetValueFloat(J_ID($rootID, '05_Intern', 'Bestaetigung_bis_ms'), 0.0);
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Nachfrage_Aktiv'), false);
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Relais_AUF_Empfangen'), false);
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Relais_AB_Empfangen'), false);
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

    // Ein realer LCN-/GT8-Befehl hat Vorrang vor einem gleichzeitig wartenden
    // Symcon-Auftrag. Startet die andere Richtung, wird kein STOP gesendet und
    // keine Fehlerverriegelung ausgelöst; der Symcon-Auftrag wird verworfen.
    if ($phase === J_PHASE_WAIT_START
        && J_IsDirection($expected)
        && $orderType !== J_ORDER_NONE
        && $expected !== $direction) {
        J_NextOrder($rootID);
        J_ClearPending($rootID);
        SetValueInteger(J_ID($rootID, '05_Intern', 'Auftragstyp'), J_ORDER_NONE);
        SetValueInteger(J_ID($rootID, '05_Intern', 'Erwartete_Richtung'), J_DIR_NONE);
        SetValueFloat(J_ID($rootID, '05_Intern', 'Bestaetigung_bis_ms'), 0.0);
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
        J_ClearCommandLease($rootID);
        J_ArmExternalEndMonitoring($rootID, $direction, $now);
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_EXTERNAL);
        SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), $direction);
        J_SetWorker($rootID, true);
        J_SetLastAction($rootID, 'Reale LCN-/GT8-Gegenrichtung hat Vorrang; Symcon-Auftrag verworfen');
        return;
    }

    // Ein nach STOP/Timeout verspaetet einlaufender Start wird sofort wieder gestoppt.
    if ($phase === J_PHASE_STOPPING && GetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'))) {
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), true);
        J_PrepareStopConfirmation($rootID);
        J_InvalidateReference($rootID, 'Verspäteter Relaisstart nach abgelaufener Startbestätigung; Fahrbeginn zeitlich unsicher');
        SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), 'Verspäteter Relaisstart wurde erkannt und automatisch gestoppt; Referenz ist ungültig.');
        J_SetWorker($rootID, false);
        J_SetLastAction($rootID, 'Verspaeteter Relaisstart erkannt; STOP wartet auf Sendefreigabe');
        if (!J_SendStopForRealDirection($rootID)) {
            J_SetError($rootID, 'Verspaeteter Start konnte nicht gestoppt werden.', false);
            return;
        }
        if (J_RelayState($rootID) === J_DIR_NONE) {
            J_HandleRealStop($rootID, $direction);
            return;
        }
        J_ArmStopConfirmation($rootID);
        J_SetLastAction($rootID, 'Verspaeteter Relaisstart erkannt; STOP-Telegramm angenommen, AUS-Bestätigung läuft');
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
        $possibleOwner = J_ReportPossibleForeignCommand($rootID, $direction, $now);
        J_ArmExternalEndMonitoring($rootID, $direction, $now);
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_EXTERNAL);
        SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), $direction);
        J_SetWorker($rootID, true);
        J_SetLastAction(
            $rootID,
            $possibleOwner > 0
                ? 'Relaisstart im Fehlerzustand während eines Befehls von Instanz #' . $possibleOwner . ' erkannt; externe Endlage wird sicher überwacht und danach abgeschaltet'
                : 'Externe Fahrt im Fehlerzustand erkannt; Endlage wird sicher überwacht und danach abgeschaltet'
        );
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
    $possibleOwner = J_ReportPossibleForeignCommand($rootID, $direction, $now);
    J_ArmExternalEndMonitoring($rootID, $direction, $now);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_EXTERNAL);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
    J_SetWorker($rootID, true);
    J_SetLastAction(
        $rootID,
        $possibleOwner > 0
            ? 'Relaisstart während eines Befehls von Instanz #' . $possibleOwner . ' erkannt; als externe Fahrt übernommen, Routing wird beim Sender geprüft'
            : 'Externe/GT8-KURZ-Fahrt erkannt; alter Automatikauftrag verworfen'
    );
}

function J_HandleExternalReferenceDeadline(int $rootID, int $order): void
{
    if ($order !== J_CurrentOrder($rootID)
        || GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase')) !== J_PHASE_EXTERNAL
        || GetValueBoolean(J_ID($rootID, '05_Intern', 'Externe_Referenz_Gesetzt'))) {
        return;
    }
    $direction = J_RelayState($rootID);
    if (!J_IsDirection($direction)) {
        return;
    }
    $endDeadline = GetValueFloat(J_ID($rootID, '05_Intern', 'Externe_Endlage_bis_ms'));
    if ($endDeadline <= 0.0 || J_NowMs() < $endDeadline) {
        return;
    }

    J_SetReference(
        $rootID,
        $direction === J_DIR_UP ? 0.0 : 100.0,
        $direction === J_DIR_UP ? 0.0 : 100.0,
        'Externe LCN-/GT8-Fahrt erreichte die Endlage sicher; Referenz automatisch aktualisiert'
    );
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Externe_Referenz_Gesetzt'), true);
    if (!J_ConfigBool($rootID, 'Modul_Aktiv')) {
        // Bewusst deaktivierte Instanzen beobachten die lokale LCN-Fahrt nur.
        // Eine manuelle Deaktivierung darf niemals selbst einen TS-Toggle senden.
        SetValueFloat(J_ID($rootID, '05_Intern', 'Externer_Autostopp_bis_ms'), 0.0);
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Externer_Autostopp_Aktiv'), false);
        J_SetWorker($rootID, false);
        J_SetLastAction($rootID, 'Externe Endlage sicher erkannt und Referenz aktualisiert; Modul deaktiviert, daher kein automatischer STOP');
        return;
    }

    SetValueFloat(
        J_ID($rootID, '05_Intern', 'Externer_Autostopp_bis_ms'),
        J_NowMs() + max(0, J_ConfigInt($rootID, 'Kalibrierfenster_ms'))
    );
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Externer_Autostopp_Aktiv'), true);
    J_SetLastAction($rootID, 'Externe Endlage sicher erkannt und Referenz aktualisiert; automatischer Relais-STOP nach Kalibrierfenster vorgemerkt');
    J_SetWorker($rootID, true);
}

function J_HandleExternalEndStop(int $rootID, int $order): void
{
    if ($order !== J_CurrentOrder($rootID)
        || GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase')) !== J_PHASE_EXTERNAL
        || !GetValueBoolean(J_ID($rootID, '05_Intern', 'Externe_Referenz_Gesetzt'))
        || !GetValueBoolean(J_ID($rootID, '05_Intern', 'Externer_Autostopp_Aktiv'))) {
        return;
    }
    $deadline = GetValueFloat(J_ID($rootID, '05_Intern', 'Externer_Autostopp_bis_ms'));
    if ($deadline <= 0.0 || J_NowMs() < $deadline) {
        J_SetWorker($rootID, true);
        return;
    }
    $state = J_RelayState($rootID);
    if ($state === J_DIR_NONE) {
        J_HandleRelayUpdate($rootID);
        return;
    }
    if (!J_IsDirection($state)) {
        J_SetError($rootID, 'Externer Endlagen-STOP gesperrt: AUF und AB sind gleichzeitig aktiv.', false, true);
        return;
    }

    SetValueBoolean(J_ID($rootID, '05_Intern', 'Externer_Autostopp_Aktiv'), true);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), true);
    J_PrepareStopConfirmation($rootID);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_STOPPING);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
    J_SetWorker($rootID, false);
    J_SetLastAction($rootID, 'Externe Endlage plus Kalibrierfenster erreicht; sicherer STOP wartet auf Sendefreigabe');
    if (!J_SendExternalEndStop($rootID, $state)) {
        J_SetError($rootID, 'Automatischer STOP der externen Endlagenfahrt konnte nicht gesendet werden.', false);
        return;
    }
    if (J_RelayState($rootID) === J_DIR_NONE) {
        J_HandleRealStop($rootID, $state);
        return;
    }
    J_ArmStopConfirmation($rootID);
    J_SetLastAction($rootID, 'Externe Endlage plus Kalibrierfenster erreicht; STOP-Telegramm angenommen, AUS-Bestätigung läuft');
}

function J_SendExternalEndStop(int $rootID, int $direction): bool
{
    if (!IPS_FunctionExists('LCNJAL_SendExternalEndStop')) {
        J_SetError($rootID, 'Sichere Funktion für externen Endlagen-STOP fehlt.', false);
        return false;
    }
    try {
        return LCNJAL_SendExternalEndStop($rootID, $direction);
    } catch (Throwable $e) {
        J_SetError($rootID, 'Externer Endlagen-STOP gesperrt: ' . $e->getMessage(), false);
        return false;
    }
}

function J_HandleRealStop(int $rootID, int $oldDirection): void
{
    J_ClearCommandLease($rootID);
    J_MarkRelaysOff($rootID);
    J_PrepareStopConfirmation($rootID);
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
            J_SetReference($rootID, $targetBlind, $targetSlat, 'Endlage nach Referenzreserve und bestätigtem Relais-STOP gespeichert');
        }

        if (J_HasPending($rootID)) {
            J_StartPending($rootID);
            return;
        }

        $tolerance = J_ConfigFloat($rootID, 'Positionstoleranz');
        if ($hardEnd
            && $oldDirection === J_DIR_DOWN
            && $targetBlind >= 100.0 - $tolerance) {
            J_StartCalibrationWindow($rootID);
            return;
        }

        J_StartConfiguredFollowSlatOrFinish($rootID);
        return;
    }

    if ($phase === J_PHASE_CALIBRATION) {
        // Das Kalibrierfenster läuft nur mit real bestätigten AUS-Relais.
        // Eine Relaismeldung in dieser Phase ist daher keine Fortsetzung des
        // alten Auftrags, sondern wird außerhalb dieser Routine bewertet.
        J_FinishIdle($rootID, 'Kalibrierfenster durch Relaisstatusänderung beendet');
        return;
    }

    if ($phase === J_PHASE_SLAT) {
        SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'), J_Clamp($targetSlat));
        $shakeRestore = GetValueBoolean(J_ID($rootID, '05_Intern', 'Shake_Nachlauf_Aktiv'));
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Shake_Nachlauf_Aktiv'), false);
        if (J_HasPending($rootID)) {
            J_StartPending($rootID);
            return;
        }
        J_FinishIdle($rootID, $shakeRestore
            ? 'ShakeFree abgeschlossen; Lamellen-ZU-Befehl gestoppt und beide Relais AUS bestätigt'
            : 'Lamellenziel erreicht');
        return;
    }

    if ($phase === J_PHASE_SHAKE) {
        SetValueFloat(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'), 0.0);
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Shake_Nachlauf_Aktiv'), true);
        if (J_HasPending($rootID)) {
            SetValueBoolean(J_ID($rootID, '05_Intern', 'Shake_Nachlauf_Aktiv'), false);
            J_StartPending($rootID);
            return;
        }
        J_SetLastAction($rootID, 'ShakeFree-AUF gestoppt; Lamellen-ZU-Nachlauf wird überwacht');
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
        $shakeRestore = GetValueBoolean(J_ID($rootID, '05_Intern', 'Shake_Nachlauf_Aktiv'));
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Shake_Nachlauf_Aktiv'), false);
        J_FinishIdle($rootID, $shakeRestore
            ? 'ShakeFree abgeschlossen; beide Relais AUS bestätigt'
            : 'Behangfahrt und Lamellenziel abgeschlossen');
        return;
    }
    J_StartSlatNow($rootID, $follow, $followDir);
}

function J_StartShakeNow(int $rootID): void
{
    if (J_RelayState($rootID) !== J_DIR_NONE) {
        J_SetError($rootID, 'ShakeFree nach Endlage ZU kann nur im Stillstand starten.', false);
        return;
    }

    // Nach dem bestätigten AB-STOP erhält LCN eine kurze Umschaltpause.
    // Die eigentliche Gegenfahrt bleibt exakt ShakeFree_ms lang.
    $pauseMs = J_ConfigInt($rootID, 'ShakeFree_Pause_ms');
    if ($pauseMs > 0) {
        IPS_Sleep($pauseMs);
    }
    if (J_RelayState($rootID) !== J_DIR_NONE) {
        J_SetError($rootID, 'ShakeFree nach Endlage ZU – Umschaltpause: Relais sind nicht sicher AUS.', false);
        return;
    }

    SetValueBoolean(J_ID($rootID, '05_Intern', 'Shake_Nachlauf_Aktiv'), false);
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
    J_PrepareStartConfirmation($rootID);
    J_PrepareStopConfirmation($rootID);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Zielzeit_ms'), 0.0);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_WAIT_START);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), true);
    J_SetWorker($rootID, false);
    J_SetLastAction($rootID, 'ShakeFree nach Endlage ZU: AUF ' . $duration . ' ms, Auftrag ' . $order . '; wartet auf Sendefreigabe');
    if (!J_SendDirection($rootID, J_DIR_UP, J_DIR_NONE)) {
        return;
    }
    J_ArmStartConfirmation($rootID);
    J_SetLastAction($rootID, 'ShakeFree nach Endlage ZU: AUF ' . $duration . ' ms, Auftrag ' . $order . '; LCN-Telegramm angenommen, Startbestätigung läuft');
}

function J_StartCalibrationWindow(int $rootID): void
{
    if (J_RelayState($rootID) !== J_DIR_NONE) {
        J_SetError($rootID, 'Kalibrierfenster darf nur bei bestätigten AUS-Relais starten.', false);
        return;
    }

    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stopstatus_Nachfrage_Aktiv'), false);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'), 0.0);
    SetValueFloat(
        J_ID($rootID, '05_Intern', 'Zielzeit_ms'),
        J_NowMs() + J_ConfigInt($rootID, 'Kalibrierfenster_ms')
    );
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_CALIBRATION);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), true);
    J_SetWorker($rootID, true);
    J_SetLastAction($rootID, '100 % ZU und beide ausgewählten Relais AUS bestätigt; Kalibrierfenster gestartet');
}

function J_CompleteCalibrationWindow(int $rootID): void
{
    if (J_RelayState($rootID) !== J_DIR_NONE) {
        J_ReconcileRelayState($rootID);
        if (GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase')) === J_PHASE_CALIBRATION) {
            J_SetError($rootID, 'Kalibrierfenster beendet, aber mindestens ein ausgewähltes Motorrelais ist aktiv.', false);
        }
        return;
    }

    SetValueFloat(J_ID($rootID, '05_Intern', 'Zielzeit_ms'), 0.0);
    J_SetWorker($rootID, false);
    if (GetValueBoolean(J_ID($rootID, '03_Bedienung', 'ShakeFree_Aktiv'))) {
        J_StartShakeNow($rootID);
    } else {
        J_StartConfiguredFollowSlatOrFinish($rootID);
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

    if ($phase === J_PHASE_CALIBRATION) {
        J_CompleteCalibrationWindow($rootID);
        return;
    }

    J_UpdatePositionToNow($rootID);

    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), true);
    J_PrepareStopConfirmation($rootID);
    J_SetWorker($rootID, false);
    J_SetLastAction($rootID, 'Zielzeit erreicht; STOP wartet auf Sendefreigabe');

    $oldDirection = GetValueInteger(J_ID($rootID, '05_Intern', 'Start_Richtung'));
    if (J_RelayState($rootID) === J_DIR_NONE) {
        J_HandleRealStop($rootID, $oldDirection);
        return;
    }
    if (!J_SendStopForRealDirection($rootID)) {
        J_SetError($rootID, 'Ziel-STOP konnte nicht gesendet werden.', false);
        return;
    }
    if (J_RelayState($rootID) === J_DIR_NONE) {
        J_HandleRealStop($rootID, $oldDirection);
        return;
    }
    J_ArmStopConfirmation($rootID);
    J_SetLastAction($rootID, 'Zielzeit erreicht; STOP-Telegramm angenommen, AUS-Bestätigung läuft');
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

    $statusRetryID = J_ID($rootID, '05_Intern', 'Startstatus_Nachfrage_Aktiv');
    if (!GetValueBoolean($statusRetryID)) {
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Relais_AUF_Empfangen'), false);
        SetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Relais_AB_Empfangen'), false);
        SetValueBoolean($statusRetryID, true);

        $statusRequested = false;
        if (IPS_FunctionExists('LCN_RequestStatus')) {
            $actorModuleID = (int) J_HardwareBinding($rootID)['actorModuleID'];
            if ($actorModuleID > 0 && J_LcnInstanceReady($actorModuleID)) {
                try {
                    $statusRequested = LCN_RequestStatus($actorModuleID);
                } catch (Throwable $statusError) {
                    J_Log($rootID, 'Startstatusabfrage fehlgeschlagen: ' . $statusError->getMessage());
                }
            }
        }

        // Auch wenn eine aktive Statusabfrage momentan nicht möglich ist,
        // erhält die reale Relaismeldung ein zweites vollständiges
        // Bestätigungsfenster. Damit führen kurze Bus-/Symcon-Verzögerungen
        // nicht zu einer voreiligen Abbruchentscheidung.
        SetValueFloat(
            J_ID($rootID, '05_Intern', 'Bestaetigung_bis_ms'),
            J_NowMs() + max(1000, J_ConfigInt($rootID, 'Relaisbestaetigung_ms'))
        );
        J_SetWorker($rootID, true);
        J_SetLastAction(
            $rootID,
            $statusRequested
                ? 'Startbestätigung verzögert; ausgewähltes Aktormodul erneut abgefragt'
                : 'Startbestätigung verzögert; zweite passive Bestätigungsfrist läuft'
        );
        return;
    }

    $freshUp = GetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Relais_AUF_Empfangen'));
    $freshDown = GetValueBoolean(J_ID($rootID, '05_Intern', 'Startstatus_Relais_AB_Empfangen'));

    if ($freshUp && $freshDown && J_HandleForeignRelayResponse($rootID, $order, true)) {
        return;
    }

    SetValueBoolean($statusRetryID, false);
    J_ClearCommandLease($rootID);

    $message = $freshUp && $freshDown
        ? 'LCN-Telegramm wurde angenommen, aber beide ausgewählten Motorrelais blieben nach einer frischen Statusabfrage AUS. Der Auftrag wurde sicher verworfen; die Positionsreferenz blieb unverändert.'
        : 'Keine vollständige aktuelle Relaisstatusantwort innerhalb zweier Startbestätigungsfenster. Der Auftrag wurde sicher verworfen; die Instanz bleibt betriebsbereit und die Positionsreferenz unverändert.';
    SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), $message);
    J_ClearPending($rootID);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), -1);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), J_DIR_NONE);
    J_BeginCancelGuard($rootID, 'Start nicht bestätigt; Spätstart-Schutz ohne Fehlerverriegelung', false);
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

    $statusRetryID = J_ID($rootID, '05_Intern', 'Stopstatus_Nachfrage_Aktiv');
    $verifiedRetryID = J_ID($rootID, '05_Intern', 'Stop_Wiederholung_Gesendet');

    if (GetValueBoolean($verifiedRetryID)) {
        J_SetError(
            $rootID,
            'Das ausgewählte Motorrelais blieb auch nach einer frischen Statusbestätigung und genau einer verifizierten STOP-Wiederholung aktiv. Lokal ausschalten und Fehler quittieren.',
            false
        );
        return;
    }

    if (!GetValueBoolean($statusRetryID)) {
        if (IPS_FunctionExists('LCN_RequestStatus')) {
            $actorModuleID = (int) J_HardwareBinding($rootID)['actorModuleID'];
            if ($actorModuleID > 0 && J_LcnInstanceReady($actorModuleID)) {
                SetValueBoolean(J_ID($rootID, '05_Intern', 'Stopstatus_Relais_AUF_Empfangen'), false);
                SetValueBoolean(J_ID($rootID, '05_Intern', 'Stopstatus_Relais_AB_Empfangen'), false);
                if (LCN_RequestStatus($actorModuleID)) {
                    SetValueBoolean($statusRetryID, true);
                    SetValueFloat(
                        J_ID($rootID, '05_Intern', 'Stop_bis_ms'),
                        J_NowMs() + max(1000, J_ConfigInt($rootID, 'Stoppbestaetigung_ms'))
                    );
                    J_SetWorker($rootID, true);
                    J_SetLastAction($rootID, 'Relais-AUS-Bestätigung verzögert; frischen Status der ausgewählten Relais angefordert');
                    return;
                }
            }
        }

        J_SetError(
            $rootID,
            'Keine reale AUS-Bestätigung und keine frische Statusabfrage des ausgewählten Aktormoduls möglich. Kein unbestätigtes Toggle gesendet; lokal ausschalten und Fehler quittieren.',
            false
        );
        return;
    }

    $freshUp = GetValueBoolean(J_ID($rootID, '05_Intern', 'Stopstatus_Relais_AUF_Empfangen'));
    $freshDown = GetValueBoolean(J_ID($rootID, '05_Intern', 'Stopstatus_Relais_AB_Empfangen'));
    if (!$freshUp || !$freshDown) {
        J_SetError(
            $rootID,
            'Die Statusabfrage lieferte keine vollständige frische Rückmeldung beider ausgewählter Motorrelais. Aus Sicherheitsgründen wurde kein zweites Toggle gesendet; lokal ausschalten und Ereigniszuordnung prüfen.',
            false
        );
        return;
    }

    // Erst eine nach dem ersten STOP ausdrücklich angeforderte und vollständig
    // eingetroffene Statusantwort darf eine einmalige Wiederholung freigeben.
    // So wird ein verlorenes STOP-Telegramm behoben, ohne bei lediglich
    // verzögerter AUS-Rückmeldung blind erneut zu toggeln.
    $confirmedState = J_RelayState($rootID);
    if ($confirmedState === J_DIR_NONE) {
        J_HandleRelayUpdate($rootID);
        return;
    }
    if (!J_IsDirection($confirmedState)) {
        J_SetError($rootID, 'Frische Statusabfrage meldet AUF und AB gleichzeitig aktiv.', false, true);
        return;
    }

    SetValueBoolean($statusRetryID, false);
    SetValueBoolean($verifiedRetryID, true);
    J_PrepareStopConfirmation($rootID, true);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), true);
    J_SetWorker($rootID, false);
    J_SetLastAction($rootID, 'Frische Statusabfrage bestätigt Relais weiterhin EIN; einmalige verifizierte STOP-Wiederholung wartet auf Sendefreigabe');
    $externalSafetyStop = GetValueBoolean(J_ID($rootID, '05_Intern', 'Externer_Autostopp_Aktiv'));
    $retryOk = $externalSafetyStop
        ? J_SendExternalEndStop($rootID, $confirmedState)
        : J_SendDirection($rootID, $confirmedState, $confirmedState);
    if (!$retryOk) {
        J_SetError($rootID, 'Verifizierte STOP-Wiederholung konnte nicht gesendet werden.', false);
        return;
    }
    if (J_RelayState($rootID) === J_DIR_NONE) {
        J_HandleRealStop($rootID, $confirmedState);
        return;
    }
    J_ArmStopConfirmation($rootID);
    J_SetLastAction($rootID, 'Einmalige verifizierte STOP-Wiederholung gesendet; AUS-Bestätigung läuft');
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
        SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), '');
        J_StartPending($rootID);
    } elseif ($errorAfter) {
        $message = GetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'));
        J_SetError(
            $rootID,
            $message !== '' ? $message : 'Starttimeout-Schutzfenster beendet; keine Relaisbestätigung.',
            false
        );
    } else {
        $message = GetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'));
        SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), '');
        J_FinishIdle(
            $rootID,
            $message !== ''
                ? 'Startauftrag ohne Relaisbewegung verworfen: ' . $message
                : 'Abbruch-Schutzfenster beendet'
        );
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
    J_ClearCommandLease($rootID);
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
    J_PrepareStartConfirmation($rootID);
    J_PrepareStopConfirmation($rootID);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Endlage_Hart'), false);
    SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), '');
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), J_DIR_NONE);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_IDLE);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Externe_Endlage_bis_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Externer_Autostopp_bis_ms'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Externer_Autostopp_Aktiv'), false);
    J_MarkRelaysOff($rootID);
    J_SetLastAction(
        $rootID,
        GetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'))
            ? 'Fehler quittiert; gültige Referenz blieb erhalten; keine LCN-Taste gesendet'
            : 'Fehler quittiert; Referenz war bereits ungültig; keine LCN-Taste gesendet'
    );
}

function J_FinishIdle(int $rootID, string $reason): void
{
    J_ClearCommandLease($rootID);
    $relayState = J_RelayState($rootID);
    if ($relayState !== J_DIR_NONE) {
        J_SetError($rootID, 'Ablaufabschluss verweigert: mindestens ein Motorrelais ist noch aktiv. Lokale LCN-Bedienung verwenden und Fehler quittieren.', false);
        return;
    }
    J_MarkRelaysOff($rootID);
    J_ClearPending($rootID);
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
    J_PrepareStartConfirmation($rootID);
    J_PrepareStopConfirmation($rootID);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Shake_Nachlauf_Aktiv'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Externe_Referenz_Gesetzt'), false);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Externe_Endlage_bis_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Externer_Autostopp_bis_ms'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Externer_Autostopp_Aktiv'), false);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Fremdbefehl_Quelle'), 0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Fremdbefehl_Erkannt_ms'), 0.0);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_IDLE);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
    if (J_RelayState($rootID) === J_DIR_NONE) {
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), J_DIR_NONE);
    }
    J_SetLastAction($rootID, $reason);
}

function J_SetError(int $rootID, string $message, bool $tryStop, bool $invalidateReference = false): void
{
    // Sicherheitsprinzip V0.1.13: Ein Laufzeitfehler verriegelt die
    // Symcon-Instanz sofort. Es wird kein weiterer LCN-Toggle gesendet.
    // Die lokale LCN-Bedienung bleibt dadurch unbeeinflusst und der Nutzer
    // stoppt eine gegebenenfalls noch aktive Fahrt direkt über LCN.
    J_NextOrder($rootID);
    J_ClearCommandLease($rootID);
    J_ClearPending($rootID);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Lamelle'), -1);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Folge_Richtung'), J_DIR_NONE);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Auftragstyp'), J_ORDER_NONE);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Erwartete_Richtung'), J_DIR_NONE);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Abbruch_Fehlerphase'), false);
    J_PrepareStartConfirmation($rootID);
    J_PrepareStopConfirmation($rootID);
    SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), $message);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_ERROR);
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
    if ($invalidateReference) {
        J_InvalidateReference($rootID, 'Positionsunsicherer Fehler: ' . $message);
    }
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Shake_Nachlauf_Aktiv'), false);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Externe_Endlage_bis_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Externer_Autostopp_bis_ms'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Externer_Autostopp_Aktiv'), false);
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
    J_ClearCommandLease($rootID);
    J_SetWorker($rootID, false);
    // Der Ausgangsstatus des frei gewählten GT8-Ereignis-UPU kann beim RequestStatus einen persistenten Toggle-Baselinewert
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
    J_PrepareStartConfirmation($rootID);
    J_PrepareStopConfirmation($rootID);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Auftragstyp'), J_ORDER_NONE);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Erwartete_Richtung'), J_DIR_NONE);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Startzeit_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Zielzeit_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Bestaetigung_bis_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Abbruch_bis_ms'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Externe_Referenz_Gesetzt'), false);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Externe_Endlage_bis_ms'), 0.0);
    SetValueFloat(J_ID($rootID, '05_Intern', 'Externer_Autostopp_bis_ms'), 0.0);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Externer_Autostopp_Aktiv'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Sync_Relais_AUF_Empfangen'), false);
    SetValueBoolean(J_ID($rootID, '05_Intern', 'Sync_Relais_AB_Empfangen'), false);
    SetValueInteger(J_ID($rootID, '05_Intern', 'Kernel_Startzeit'), J_KernelStart());
    SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), '');
    // Eine normale Initialisierung, ein ApplyChanges, ein Kernelneustart oder
    // eine Fehlerquittierung darf eine gültige, persistent gespeicherte Referenz
    // nicht löschen. Nur ein nachweislich positionsunsicherer Bewegungsablauf
    // verwirft sie ausdrücklich.
    SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_SYNC);

    $requestErrors = [];
    if (J_ConfigBool($rootID, 'Statusabfrage_beim_Start')) {
        if (!IPS_FunctionExists('LCN_RequestStatus')) {
            $requestErrors[] = 'LCN_RequestStatus ist in diesem Symcon-Kernel nicht registriert. LCN-Modulinstallation pruefen.';
        } else {
            // Auch frei gewählte GT8-LANG-Ausgänge werden abgefragt. So ist
            // ihr Toggle-Basiswert nach einem Neustart korrekt synchronisiert,
            // selbst wenn Ausgang 3/4 auf einem anderen UPU liegt.
            $binding = J_HardwareBinding($rootID);
            $statusInstances = array_values(array_unique(array_filter([
                (int) $binding['sendModuleID'],
                (int) $binding['actorModuleID'],
                J_LcnModuleForVariable((int) $binding['gt8LongUpVariableID']),
                J_LcnModuleForVariable((int) $binding['gt8LongDownVariableID']),
            ], static fn (int $id): bool => $id > 0)));
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
            // Einige LCN-/PCHK-Konstellationen aktualisieren den gespeicherten
            // Booleanwert bei RequestStatus, erzeugen bei unverändertem AUS
            // aber kein separates OnUpdate-Ereignis. Sind beide ausgewählten
            // Motorrelais aktuell AUS, ist ein sicherer Stillstand gegeben:
            // Routineupdates dürfen dann weder eine Fehlerverriegelung noch
            // den Verlust einer gültigen Referenz verursachen.
            $currentState = J_RelayState($rootID);
            if ($currentState === J_DIR_NONE) {
                J_SetGt8EventsActive($rootID, true);
                SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), J_DIR_NONE);
                SetValueInteger(J_ID($rootID, '04_Istwerte', 'Letzte_Statusmeldung'), time());
                SetValueFloat(J_ID($rootID, '05_Intern', 'Sync_bis_ms'), 0.0);
                SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), '');
                J_MarkRelaysOff($rootID);
                J_FinishIdle(
                    $rootID,
                    GetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'))
                        ? 'Statusabgleich: beide Relais AUS; gespeicherte Referenz ohne manuelle Quittierung übernommen'
                        : 'Statusabgleich: beide Relais AUS; vorhandener unreferenzierter Zustand übernommen'
                );
                return;
            }

            SetValueFloat(J_ID($rootID, '05_Intern', 'Sync_bis_ms'), 0.0);
            J_SetError(
                $rootID,
                'Statusabgleich ohne aktuelle OnUpdate-Rueckmeldung bei aktiv gemeldetem Relais: ' . implode(', ', $missing)
                    . '. Zur Sicherheit reale Relaislage prüfen und erst danach quittieren.',
                false
            );
            return;
        }
    }

    // Erst jetzt wieder freigeben: So kann kein verzögert ausgeführtes
    // Baseline-OnChange des gewählten GT8-Ereignis-UPU nach dem Statusabgleich einen Lamellenauftrag starten.
    J_SetGt8EventsActive($rootID, true);

    $state = J_RelayState($rootID);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Fahrstatus'), $state);
    SetValueInteger(J_ID($rootID, '04_Istwerte', 'Letzte_Statusmeldung'), time());
    SetValueFloat(J_ID($rootID, '05_Intern', 'Sync_bis_ms'), 0.0);
    SetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'), '');

    if ($state === J_DIR_BOTH) {
        J_SetError($rootID, 'Statusabgleich: AUF und AB gleichzeitig aktiv.', false, true);
        return;
    }
    if (J_IsDirection($state)) {
        $now = J_NowMs();
        J_SnapshotRealStart($rootID, $state, $now);
        J_ArmExternalEndMonitoring($rootID, $state, $now);
        SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_EXTERNAL);
        SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false);
        J_SetWorker($rootID, true);
        J_SetLastAction($rootID, 'Statusabgleich: aktive externe Fahrt wird ab jetzt verfolgt');
        return;
    }

    J_FinishIdle(
        $rootID,
        GetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'))
            ? 'Statusabgleich abgeschlossen; gespeicherte Referenz bleibt gültig'
            : 'Statusabgleich abgeschlossen; Referenzfahrt erforderlich'
    );
}

function J_InitializeRuntime(int $rootID): void
{
    J_BeginStatusSync($rootID, 'Initialisierung');
}

function J_RunHealthcheck(int $rootID): void
{
    $phase = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'));
    if ($phase !== J_PHASE_SYNC) {
        J_ReconcileRelayState($rootID);
    }

    $phase = GetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'));
    $order = J_CurrentOrder($rootID);
    $now = J_NowMs();

    if (GetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'))) {
        $stopUntil = GetValueFloat(J_ID($rootID, '05_Intern', 'Stop_bis_ms'));
        if ($stopUntil > 0.0 && $now >= $stopUntil) {
            J_HandleStopTimeout($rootID, $order);
        }
        return;
    }

    if ($phase === J_PHASE_WAIT_START) {
        $confirmUntil = GetValueFloat(J_ID($rootID, '05_Intern', 'Bestaetigung_bis_ms'));
        if ($confirmUntil > 0.0 && $now >= $confirmUntil) {
            J_HandleStartTimeout($rootID, $order);
        }
        return;
    }

    if ($phase === J_PHASE_EXTERNAL) {
        $state = J_RelayState($rootID);
        if (!J_IsDirection($state)) {
            J_HandleRelayUpdate($rootID);
            return;
        }
        $externalReferenced = GetValueBoolean(J_ID($rootID, '05_Intern', 'Externe_Referenz_Gesetzt'));
        $endDeadline = GetValueFloat(J_ID($rootID, '05_Intern', 'Externe_Endlage_bis_ms'));
        $autoStopDeadline = GetValueFloat(J_ID($rootID, '05_Intern', 'Externer_Autostopp_bis_ms'));
        $autoStopActive = GetValueBoolean(J_ID($rootID, '05_Intern', 'Externer_Autostopp_Aktiv'));
        if (!$externalReferenced && $endDeadline > 0.0 && $now >= $endDeadline) {
            J_HandleExternalReferenceDeadline($rootID, $order);
        } elseif ($externalReferenced && $autoStopActive && $autoStopDeadline > 0.0 && $now >= $autoStopDeadline) {
            // Unabhängige zweite Sicherung neben dem 1-s-Worker: Auch bei einem
            // ausgefallenen Worker wird ein extern gehaltenes Relais nach sicherer
            // Endlage plus Kalibrierfenster einmalig ausgeschaltet.
            J_HandleExternalEndStop($rootID, $order);
        }
        return;
    }

    if (in_array($phase, [J_PHASE_BLIND, J_PHASE_SLAT, J_PHASE_SHAKE, J_PHASE_REFERENCE, J_PHASE_CALIBRATION], true)) {
        $deadline = GetValueFloat(J_ID($rootID, '05_Intern', 'Zielzeit_ms'));
        if ($deadline > 0.0 && $now >= $deadline) {
            // Zweite, unabhängige Sicherung neben dem 1-s-Worker: Ist dessen
            // Timer ausgefallen, löst der Healthcheck den einmaligen STOP aus.
            J_HandleDeadline($rootID, $order);
        }
    }
}

function J_StatusText(int $rootID): string
{
    $lines = [];
    $lines[] = 'Jalousie: ' . IPS_GetName($rootID) . ' (ID ' . $rootID . ')';
    $lines[] = 'Fahrstatus: ' . GetValueFormatted(J_ID($rootID, '04_Istwerte', 'Fahrstatus'));
    $lines[] = 'Phase: ' . GetValueFormatted(J_ID($rootID, '04_Istwerte', 'Phase'));
    $lines[] = 'Ist Behang: ' . GetValueFormatted(J_ID($rootID, '04_Istwerte', 'Ist_Behang'));
    $lines[] = 'Ist Lamelle: ' . GetValueFormatted(J_ID($rootID, '04_Istwerte', 'Ist_Lamelle'));
    $referenced = GetValueBoolean(J_ID($rootID, '04_Istwerte', 'Position_Referenziert'));
    $lines[] = 'Referenziert: ' . ($referenced ? 'JA' : 'NEIN');
    $lines[] = 'Referenz-Endlage: ' . GetValueFormatted(J_ID($rootID, '04_Istwerte', 'Referenz_Endlage'));
    $lines[] = 'Letzte Referenzierung: ' . GetValueFormatted(J_ID($rootID, '04_Istwerte', 'Letzte_Referenzierung'));
    $lines[] = 'Letzte Relais-AUS-Bestätigung: ' . GetValueFormatted(J_ID($rootID, '04_Istwerte', 'Letzte_Relais_AUS_Bestaetigung'));
    $lines[] = 'Auftragsnummer: ' . J_CurrentOrder($rootID);
    $lines[] = 'Kernelstart: ' . date('d.m.Y H:i:s', GetValueInteger(J_ID($rootID, '05_Intern', 'Kernel_Startzeit')));
    $lines[] = 'Fehler: ' . GetValueString(J_ID($rootID, '04_Istwerte', 'Fehlertext'));
    return implode(PHP_EOL, $lines) . PHP_EOL;
}
