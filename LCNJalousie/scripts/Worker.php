<?php
/**
 * Jalousiesteuerung LCN / IP-Symcon 9.0
 * V12.0 - 1-s-Worker mit kurzer Millisekunden-Schlussphase
 *
 * Der ScriptTimer wird vom Controller auf 1 Sekunde gesetzt. Lange Fahrten
 * werden nicht mit IPS_Sleep abgewartet. Nur im letzten Workerfenster wird
 * einmal kurz bis zum berechneten Zielzeitpunkt gewartet.
 */

declare(strict_types=1);

const JW_PHASE_IDLE       = 0;
const JW_PHASE_WAIT_START = 1;
const JW_PHASE_BLIND      = 2;
const JW_PHASE_SLAT       = 3;
const JW_PHASE_SHAKE      = 4;
const JW_PHASE_STOPPING   = 5;
const JW_PHASE_EXTERNAL   = 6;
const JW_PHASE_ERROR      = 7;
const JW_PHASE_REFERENCE  = 8;
const JW_PHASE_SYNC       = 9;
const JW_PHASE_CALIBRATION = 10;

$rootID = JW_RootID((int) ($_IPS['SELF'] ?? 0));
$lockName = 'Jalousie_PHP_' . $rootID;

if (!IPS_SemaphoreEnter($lockName, 3000)) {
    return;
}

$action = '';
$order = -1;
$sleepMs = 0;

try {
    $storedKernelStart = JW_IGetInteger($rootID, 'Kernel_Startzeit');
    if ($storedKernelStart !== IPS_GetKernelStartTime()) {
        $action = 'INITIALIZE';
        $order = JW_IGetInteger($rootID, 'Auftragsnummer');
        IPS_SetScriptTimer((int) $_IPS['SELF'], 0);
        JW_ISetBoolean($rootID, 'Worker_Aktiv', false);
    }

    $phase = GetValueInteger(JW_ID($rootID, '04_Istwerte', 'Phase'));
    $state = GetValueInteger(JW_ID($rootID, '04_Istwerte', 'Fahrstatus'));
    $order = JW_IGetInteger($rootID, 'Auftragsnummer');
    $now = JW_NowMs();

    if ($action === '' && ($state === 1 || $state === 2)) {
        JW_UpdatePosition($rootID, $state, $now);
    }

    $stopRequested = JW_IGetBoolean($rootID, 'Stop_Angefordert');
    if ($action !== '') {
        // Kernelwechsel wurde oben bereits erkannt.
    } elseif ($phase === JW_PHASE_SYNC) {
        $syncUntil = JW_IGetFloat($rootID, 'Sync_bis_ms');
        if ($syncUntil > 0.0 && $now >= $syncUntil) {
            $action = 'SYNC_COMPLETE';
        }
    } elseif ($stopRequested) {
        $stopUntil = JW_IGetFloat($rootID, 'Stop_bis_ms');
        if ($stopUntil > 0.0 && $now >= $stopUntil) {
            $action = 'STOP_TIMEOUT';
        }
    } elseif ($phase === JW_PHASE_WAIT_START) {
        $confirmUntil = JW_IGetFloat($rootID, 'Bestaetigung_bis_ms');
        if ($confirmUntil > 0.0 && $now >= $confirmUntil) {
            $action = 'START_TIMEOUT';
        }
    } elseif ($phase === JW_PHASE_STOPPING
        && JW_IGetBoolean($rootID, 'Abbruch_Wartet_Auf_Start')) {
        $guardUntil = JW_IGetFloat($rootID, 'Abbruch_bis_ms');
        if ($guardUntil > 0.0 && $now >= $guardUntil) {
            $action = 'CANCEL_GUARD';
        }
    } elseif ($phase === JW_PHASE_EXTERNAL && ($state === 1 || $state === 2)) {
        $externalReferenced = JW_IGetBoolean($rootID, 'Externe_Referenz_Gesetzt');
        $endDeadline = JW_IGetFloat($rootID, 'Externe_Endlage_bis_ms');
        $autoStopDeadline = JW_IGetFloat($rootID, 'Externer_Autostopp_bis_ms');
        $autoStopActive = JW_IGetBoolean($rootID, 'Externer_Autostopp_Aktiv');

        if (!$externalReferenced && $endDeadline > 0.0 && $now >= $endDeadline) {
            $action = 'EXTERNAL_REFERENCE';
        } elseif ($externalReferenced && $autoStopActive && $autoStopDeadline > 0.0 && $now >= $autoStopDeadline) {
            $action = 'EXTERNAL_STOP';
        }
    } elseif (in_array($phase, [JW_PHASE_BLIND, JW_PHASE_SLAT, JW_PHASE_SHAKE, JW_PHASE_REFERENCE, JW_PHASE_CALIBRATION], true)) {
        $deadline = JW_IGetFloat($rootID, 'Zielzeit_ms');
        if ($deadline > 0.0) {
            $remaining = $deadline - $now;
            $windowMs = JW_ConfigInt($rootID, 'Workerfenster_ms');
            if ($remaining <= $windowMs) {
                $sleepMs = (int) max(0, round($remaining));
                $action = 'DEADLINE';
            }
        }
    }

    if ($action !== '') {
        // Verhindert parallele Wiederholungen durch den ScriptTimer waehrend der Schlussphase.
        IPS_SetScriptTimer((int) $_IPS['SELF'], 0);
        JW_ISetBoolean($rootID, 'Worker_Aktiv', false);
    } elseif ($phase === JW_PHASE_IDLE || ($phase === JW_PHASE_ERROR && $state === 0)) {
        IPS_SetScriptTimer((int) $_IPS['SELF'], 0);
        JW_ISetBoolean($rootID, 'Worker_Aktiv', false);
    }

    JW_RuntimeFlush($rootID);
    if (IPS_FunctionExists('LCNJAL_SyncVisualization')) {
        LCNJAL_SyncVisualization($rootID);
    }
} finally {
    try {
        JW_RuntimeFlush($rootID);
    } catch (Throwable $runtimeFlushError) {
        IPS_LogMessage('Jalousie', 'Kompakter Runtime-Speicher konnte nicht geschrieben werden: ' . $runtimeFlushError->getMessage());
    }
    IPS_SemaphoreLeave($lockName);
}

if ($action === '') {
    return;
}

if ($sleepMs > 0) {
    IPS_Sleep($sleepMs);
}

// Synchroner Aufruf: Der Controller bestaetigt Phase und Auftragsnummer erneut.
$controllerID = JW_ID($rootID, '06_Skripte', 'Controller');
IPS_RunScriptWaitEx($controllerID, [
    'ACTION' => $action,
    'ORDER'  => $order,
]);

function JW_RootID(int $scriptID): int
{
    if ($scriptID <= 0 || !IPS_ScriptExists($scriptID)) {
        throw new RuntimeException('SELF ist keine gueltige Skript-ID.');
    }
    return IPS_GetParent(IPS_GetParent($scriptID));
}

function JW_ID(int $rootID, string $categoryIdent, string $objectIdent): int
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


function JW_CompactConfig(int $rootID): array
{
    static $cache = [];
    if (isset($cache[$rootID])) {
        return $cache[$rootID];
    }
    if (!IPS_FunctionExists('LCNJAL_GetCompactConfiguration')) {
        throw new RuntimeException('Kompakte Modulkonfiguration ist nicht verfügbar.');
    }
    $decoded = json_decode(LCNJAL_GetCompactConfiguration($rootID), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Kompakte Modulkonfiguration ist ungültig.');
    }
    $cache[$rootID] = $decoded;
    return $cache[$rootID];
}

function JW_ConfigInt(int $rootID, string $ident): int
{
    return (int) (JW_CompactConfig($rootID)[$ident] ?? 0);
}

function JW_ConfigFloat(int $rootID, string $ident): float
{
    return (float) (JW_CompactConfig($rootID)[$ident] ?? 0.0);
}

function JW_RuntimeLoad(int $rootID): void
{
    if (isset($GLOBALS['JW_COMPACT_RUNTIME_CACHE'][$rootID])) {
        return;
    }
    if (!IPS_FunctionExists('LCNJAL_GetCompactRuntimeState')) {
        throw new RuntimeException('Kompakter Runtime-Speicher ist nicht verfügbar.');
    }
    $decoded = json_decode(LCNJAL_GetCompactRuntimeState($rootID), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Kompakter Runtime-Speicher ist ungültig.');
    }
    $GLOBALS['JW_COMPACT_RUNTIME_CACHE'][$rootID] = $decoded;
    $GLOBALS['JW_COMPACT_RUNTIME_DIRTY'][$rootID] = false;
}

function JW_RuntimeFlush(int $rootID): void
{
    if (!(bool) ($GLOBALS['JW_COMPACT_RUNTIME_DIRTY'][$rootID] ?? false)) {
        return;
    }
    if (!IPS_FunctionExists('LCNJAL_SetCompactRuntimeState')) {
        throw new RuntimeException('Kompakter Runtime-Speicher kann nicht geschrieben werden.');
    }
    $state = $GLOBALS['JW_COMPACT_RUNTIME_CACHE'][$rootID] ?? null;
    if (!is_array($state)) {
        throw new RuntimeException('Kompakter Runtime-Cache fehlt.');
    }
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) {
        throw new RuntimeException('Kompakter Runtime-Cache kann nicht serialisiert werden.');
    }
    LCNJAL_SetCompactRuntimeState($rootID, $json);
    $GLOBALS['JW_COMPACT_RUNTIME_DIRTY'][$rootID] = false;
}

function JW_IGetInteger(int $rootID, string $ident): int
{
    JW_RuntimeLoad($rootID);
    return (int) ($GLOBALS['JW_COMPACT_RUNTIME_CACHE'][$rootID][$ident] ?? 0);
}
function JW_IGetFloat(int $rootID, string $ident): float
{
    JW_RuntimeLoad($rootID);
    return (float) ($GLOBALS['JW_COMPACT_RUNTIME_CACHE'][$rootID][$ident] ?? 0.0);
}
function JW_IGetBoolean(int $rootID, string $ident): bool
{
    JW_RuntimeLoad($rootID);
    return (bool) ($GLOBALS['JW_COMPACT_RUNTIME_CACHE'][$rootID][$ident] ?? false);
}
function JW_ISetInteger(int $rootID, string $ident, int $value): void
{
    JW_RuntimeLoad($rootID);
    $GLOBALS['JW_COMPACT_RUNTIME_CACHE'][$rootID][$ident] = $value;
    $GLOBALS['JW_COMPACT_RUNTIME_DIRTY'][$rootID] = true;
}
function JW_ISetFloat(int $rootID, string $ident, float $value): void
{
    JW_RuntimeLoad($rootID);
    $GLOBALS['JW_COMPACT_RUNTIME_CACHE'][$rootID][$ident] = $value;
    $GLOBALS['JW_COMPACT_RUNTIME_DIRTY'][$rootID] = true;
}
function JW_ISetBoolean(int $rootID, string $ident, bool $value): void
{
    JW_RuntimeLoad($rootID);
    $GLOBALS['JW_COMPACT_RUNTIME_CACHE'][$rootID][$ident] = $value;
    $GLOBALS['JW_COMPACT_RUNTIME_DIRTY'][$rootID] = true;
}

function JW_NowMs(): float
{
    $nanoseconds = hrtime(true);
    if ($nanoseconds === false) {
        throw new RuntimeException('hrtime() ist auf dieser Plattform nicht verfuegbar.');
    }
    return (float) $nanoseconds / 1_000_000.0;
}

function JW_Clamp(float $value): float
{
    return max(0.0, min(100.0, $value));
}

function JW_SlatTurnTimeMs(float $startSlat, int $state, float $turnMs): float
{
    return $state === 1
        ? JW_Clamp($startSlat) / 100.0 * $turnMs
        : (100.0 - JW_Clamp($startSlat)) / 100.0 * $turnMs;
}

function JW_BlindStartDelayMs(int $rootID, float $startBlind, float $startSlat, int $state, float $turnMs): float
{
    $softStartMs = (float) JW_ConfigInt($rootID, 'Sanftanlauf_ms');
    $positionTolerance = JW_ConfigFloat($rootID, 'Positionstoleranz');

    if ($state === 2 && $startBlind <= $positionTolerance) {
        return 0.0;
    }
    if ($state === 1 && $startBlind >= 100.0 - $positionTolerance) {
        return $turnMs;
    }

    return max($softStartMs, JW_SlatTurnTimeMs($startSlat, $state, $turnMs));
}

function JW_DirectionalBlindTravelMs(int $rootID, int $state, float $turnMs): float
{
    $totalUpMs = (float) JW_ConfigInt($rootID, 'Gesamtlaufzeit_ms');
    $totalDownMs = (float) JW_ConfigInt($rootID, 'Behanglaufzeit_ms');

    // Kompatible Konfigurations-Idents:
    // Gesamtlaufzeit_ms = Gesamtzeit 100 % ZU -> 0 % AUF inkl. voller Wendezeit.
    // Behanglaufzeit_ms = Gesamtzeit 0 % AUF -> 100 % ZU.
    return $state === 1
        ? $totalUpMs - $turnMs
        : $totalDownMs;
}

function JW_DirectionalSoftStopMs(int $rootID, int $state, float $blindTravelMs): float
{
    $ident = $state === 1 ? 'Sanftstopp_AUF_ms' : 'Sanftstopp_ZU_ms';
    $softStopMs = (float) JW_ConfigInt($rootID, $ident);

    if ($softStopMs < 0.0 || $softStopMs >= $blindTravelMs) {
        return 0.0;
    }

    return $softStopMs;
}


function JW_DirectionalSoftStopRangePercent(float $blindTravelMs, float $softStopMs): float
{
    if ($blindTravelMs <= 0.0 || $softStopMs <= 0.0 || $softStopMs >= $blindTravelMs) {
        return 0.0;
    }

    return 100.0 * $softStopMs / (2.0 * $blindTravelMs - $softStopMs);
}

function JW_BlindTimeCoordinateMs(float $position, int $state, float $blindTravelMs, float $softStopMs): float
{
    $position = JW_Clamp($position);
    $progress = $state === 1
        ? (100.0 - $position) / 100.0
        : $position / 100.0;

    if ($softStopMs <= 0.0) {
        return $progress * $blindTravelMs;
    }

    $effectiveTravelMs = $blindTravelMs - $softStopMs / 2.0;
    $distanceAtFullSpeed = $progress * $effectiveTravelMs;
    $softStopStartMs = $blindTravelMs - $softStopMs;
    $softStopRange = JW_DirectionalSoftStopRangePercent($blindTravelMs, $softStopMs) / 100.0;
    $softStopStartProgress = 1.0 - $softStopRange;

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

function JW_BlindPositionAtTimeCoordinate(float $timeCoordinateMs, int $state, float $blindTravelMs, float $softStopMs): float
{
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
    return $state === 1
        ? 100.0 * (1.0 - $progress)
        : 100.0 * $progress;
}

function JW_UpdatePosition(int $rootID, int $state, float $now): void
{
    $startMs = JW_IGetFloat($rootID, 'Startzeit_ms');
    if ($startMs <= 0.0) {
        return;
    }

    $elapsed = max(0.0, $now - $startMs);
    $startBlind = JW_IGetFloat($rootID, 'Start_Behang');
    $startSlat = JW_IGetFloat($rootID, 'Start_Lamelle');
    $turnMs = (float) JW_ConfigInt($rootID, 'Wendezeit_ms');
    $blindTravelMs = JW_DirectionalBlindTravelMs($rootID, $state, $turnMs);
    if ($turnMs <= 0.0 || $blindTravelMs <= 0.0) {
        return;
    }

    $turnNeeded = JW_SlatTurnTimeMs($startSlat, $state, $turnMs);
    $turnUsed = min($elapsed, $turnNeeded);

    $slat = $state === 1
        ? $startSlat - 100.0 * $turnUsed / $turnMs
        : $startSlat + 100.0 * $turnUsed / $turnMs;

    $blindStartDelay = JW_BlindStartDelayMs($rootID, $startBlind, $startSlat, $state, $turnMs);
    $blindElapsed = max(0.0, $elapsed - $blindStartDelay);
    $softStopMs = JW_DirectionalSoftStopMs($rootID, $state, $blindTravelMs);

    // Positionsabhängige Kennlinie für jede Fahrt: Außerhalb der Endzone volle
    // Geschwindigkeit, innerhalb der Endzone der bis zur aktuellen Position
    // durchfahrene Anteil des linearen Sanft-Stopps.
    $blindStartCoordinateMs = JW_BlindTimeCoordinateMs($startBlind, $state, $blindTravelMs, $softStopMs);
    $blind = JW_BlindPositionAtTimeCoordinate(
        $blindStartCoordinateMs + $blindElapsed,
        $state,
        $blindTravelMs,
        $softStopMs
    );

    SetValueFloat(JW_ID($rootID, '04_Istwerte', 'Ist_Lamelle'), JW_Clamp($slat));
    SetValueFloat(JW_ID($rootID, '04_Istwerte', 'Ist_Behang'), JW_Clamp($blind));
    SetValueInteger(JW_ID($rootID, '04_Istwerte', 'Letzte_Fahrtdauer_ms'), (int) round($elapsed));
}
