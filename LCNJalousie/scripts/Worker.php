<?php
/**
 * Jalousiesteuerung LCN / IP-Symcon 9.0
 * V11.3 - 1-s-Worker mit kurzer Millisekunden-Schlussphase
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

$rootID = JW_RootID((int) ($_IPS['SELF'] ?? 0));
$lockName = 'Jalousie_PHP_' . $rootID;

if (!IPS_SemaphoreEnter($lockName, 3000)) {
    return;
}

$action = '';
$order = -1;
$sleepMs = 0;

try {
    $storedKernelStart = GetValueInteger(JW_ID($rootID, '05_Intern', 'Kernel_Startzeit'));
    if ($storedKernelStart !== IPS_GetKernelStartTime()) {
        $action = 'INITIALIZE';
        $order = GetValueInteger(JW_ID($rootID, '05_Intern', 'Auftragsnummer'));
        IPS_SetScriptTimer((int) $_IPS['SELF'], 0);
        SetValueBoolean(JW_ID($rootID, '05_Intern', 'Worker_Aktiv'), false);
    }

    $phase = GetValueInteger(JW_ID($rootID, '04_Istwerte', 'Phase'));
    $state = GetValueInteger(JW_ID($rootID, '04_Istwerte', 'Fahrstatus'));
    $order = GetValueInteger(JW_ID($rootID, '05_Intern', 'Auftragsnummer'));
    $now = JW_NowMs();

    if ($action === '' && ($state === 1 || $state === 2)) {
        JW_UpdatePosition($rootID, $state, $now);
    }

    $stopRequested = GetValueBoolean(JW_ID($rootID, '05_Intern', 'Stop_Angefordert'));
    if ($action !== '') {
        // Kernelwechsel wurde oben bereits erkannt.
    } elseif ($phase === JW_PHASE_SYNC) {
        $syncUntil = GetValueFloat(JW_ID($rootID, '05_Intern', 'Sync_bis_ms'));
        if ($syncUntil > 0.0 && $now >= $syncUntil) {
            $action = 'SYNC_COMPLETE';
        }
    } elseif ($stopRequested) {
        $stopUntil = GetValueFloat(JW_ID($rootID, '05_Intern', 'Stop_bis_ms'));
        if ($stopUntil > 0.0 && $now >= $stopUntil) {
            $action = 'STOP_TIMEOUT';
        }
    } elseif ($phase === JW_PHASE_WAIT_START) {
        $confirmUntil = GetValueFloat(JW_ID($rootID, '05_Intern', 'Bestaetigung_bis_ms'));
        if ($confirmUntil > 0.0 && $now >= $confirmUntil) {
            $action = 'START_TIMEOUT';
        }
    } elseif ($phase === JW_PHASE_STOPPING
        && GetValueBoolean(JW_ID($rootID, '05_Intern', 'Abbruch_Wartet_Auf_Start'))) {
        $guardUntil = GetValueFloat(JW_ID($rootID, '05_Intern', 'Abbruch_bis_ms'));
        if ($guardUntil > 0.0 && $now >= $guardUntil) {
            $action = 'CANCEL_GUARD';
        }
    } elseif (in_array($phase, [JW_PHASE_BLIND, JW_PHASE_SLAT, JW_PHASE_SHAKE, JW_PHASE_REFERENCE], true)) {
        $deadline = GetValueFloat(JW_ID($rootID, '05_Intern', 'Zielzeit_ms'));
        if ($deadline > 0.0) {
            $remaining = $deadline - $now;
            $windowMs = GetValueInteger(JW_ID($rootID, '01_Konfiguration', 'Workerfenster_ms'));
            if ($remaining <= $windowMs) {
                $sleepMs = (int) max(0, round($remaining));
                $action = 'DEADLINE';
            }
        }
    }

    if ($action !== '') {
        // Verhindert parallele Wiederholungen durch den ScriptTimer waehrend der Schlussphase.
        IPS_SetScriptTimer((int) $_IPS['SELF'], 0);
        SetValueBoolean(JW_ID($rootID, '05_Intern', 'Worker_Aktiv'), false);
    } elseif ($phase === JW_PHASE_IDLE || ($phase === JW_PHASE_ERROR && $state === 0)) {
        IPS_SetScriptTimer((int) $_IPS['SELF'], 0);
        SetValueBoolean(JW_ID($rootID, '05_Intern', 'Worker_Aktiv'), false);
    }

    if (IPS_FunctionExists('LCNJAL_SyncVisualization')) {
        LCNJAL_SyncVisualization($rootID);
    }
} finally {
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

function JW_UpdatePosition(int $rootID, int $state, float $now): void
{
    $startMs = GetValueFloat(JW_ID($rootID, '05_Intern', 'Startzeit_ms'));
    if ($startMs <= 0.0) {
        return;
    }

    $elapsed = max(0.0, $now - $startMs);
    $startBlind = GetValueFloat(JW_ID($rootID, '05_Intern', 'Start_Behang'));
    $startSlat = GetValueFloat(JW_ID($rootID, '05_Intern', 'Start_Lamelle'));
    $turnMs = (float) GetValueInteger(JW_ID($rootID, '01_Konfiguration', 'Wendezeit_ms'));
    $blindTravelMs = (float) GetValueInteger(JW_ID($rootID, '01_Konfiguration', 'Behanglaufzeit_ms'));
    if ($turnMs <= 0.0 || $blindTravelMs <= 0.0) {
        return;
    }

    $turnNeeded = $state === 1
        ? JW_Clamp($startSlat) / 100.0 * $turnMs
        : (100.0 - JW_Clamp($startSlat)) / 100.0 * $turnMs;
    $turnUsed = min($elapsed, $turnNeeded);

    $slat = $state === 1
        ? $startSlat - 100.0 * $turnUsed / $turnMs
        : $startSlat + 100.0 * $turnUsed / $turnMs;

    $blindElapsed = max(0.0, $elapsed - $turnNeeded);
    $blindDelta = 100.0 * $blindElapsed / $blindTravelMs;
    $blind = $state === 1 ? $startBlind - $blindDelta : $startBlind + $blindDelta;

    SetValueFloat(JW_ID($rootID, '04_Istwerte', 'Ist_Lamelle'), JW_Clamp($slat));
    SetValueFloat(JW_ID($rootID, '04_Istwerte', 'Ist_Behang'), JW_Clamp($blind));
    SetValueInteger(JW_ID($rootID, '04_Istwerte', 'Letzte_Fahrtdauer_ms'), (int) round($elapsed));
}
