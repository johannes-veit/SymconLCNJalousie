#!/usr/bin/env python3
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = (ROOT / 'LCNJalousie' / 'scripts' / 'Controller.php').read_text(encoding='utf-8')
MODULE = (ROOT / 'LCNJalousie' / 'module.php').read_text(encoding='utf-8')
WORKER = (ROOT / 'LCNJalousie' / 'scripts' / 'Worker.php').read_text(encoding='utf-8')


def function_body(source: str, name: str, next_name: str | None = None) -> str:
    start = source.find(f'function {name}')
    if start < 0:
        raise AssertionError(f'function missing: {name}')
    if next_name is not None:
        end = source.find(f'function {next_name}', start + 1)
    else:
        end = len(source)
    if end < 0:
        end = len(source)
    return source[start:end]


def assert_order(body: str, first: str, second: str, label: str) -> None:
    a = body.find(first)
    b = body.find(second)
    if a < 0 or b < 0 or a >= b:
        raise AssertionError(f'{label}: expected {first!r} before {second!r}')


# 1-3: Every automatic start arms the confirmation deadline only after the
# direction command has returned successfully.
for function, next_function, send_pattern in [
    ('J_StartBlindNow', 'J_RequestSlat', 'J_SendDirection($rootID, $direction, J_DIR_NONE)'),
    ('J_StartSlatNow', 'J_ShouldQueue', 'J_SendDirection($rootID, $direction, J_DIR_NONE)'),
    ('J_StartShakeNow', 'J_HandleDeadline', 'J_SendDirection($rootID, J_DIR_UP, J_DIR_NONE)'),
]:
    body = function_body(CONTROLLER, function, next_function)
    assert_order(body, send_pattern, 'J_ArmStartConfirmation($rootID);', function)
    if 'J_SetWorker($rootID, false);\n        return;' in body[body.find(send_pattern):]:
        raise AssertionError(f'{function}: failed start must not disable the late-start guard')

# 4: Start-send contention/failure is non-latching while both selected relays
# are really off, and is guarded against a delayed physical start.
send_body = function_body(CONTROLLER, 'J_SendDirection', 'J_SendStopForRealDirection')
for required in [
    '$expectedRelayState === J_DIR_NONE',
    'J_RelayState($rootID) === J_DIR_NONE',
    "J_BeginCancelGuard($rootID, 'Startsendung verworfen; Spätstart-Schutz ohne Fehlerverriegelung', false)",
]:
    if required not in send_body:
        raise AssertionError(f'non-latching start-send guard missing: {required}')

# 5: A quick follow-up command may replace the pending target but must not send
# the already emitted toggle STOP again.
queue_body = function_body(CONTROLLER, 'J_QueueAfterStop', 'J_BeginCancelGuard')
assert_order(
    queue_body,
    "GetValueBoolean(J_ID($rootID, '05_Intern', 'Stop_Angefordert'))",
    'J_BeginStopWatch',
    'quick follow-up duplicate STOP guard',
)
if 'bereits gesendeter STOP wird nicht wiederholt' not in queue_body:
    raise AssertionError('quick follow-up must explicitly retain one STOP only')

# 6: A real opposite-direction start has local LCN priority. The waiting
# Symcon order is discarded without sending a STOP or latching an error.
real_start = function_body(CONTROLLER, 'J_HandleRealStart', 'J_HandleRealStop')
for required in [
    '$expected !== $direction',
    'Reale LCN-/GT8-Gegenrichtung hat Vorrang',
    'J_NextOrder($rootID);',
    "SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_EXTERNAL)",
    "SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), false)",
]:
    if required not in real_start:
        raise AssertionError(f'external-priority override missing: {required}')
assert_order(real_start, '$expected !== $direction', '// Ein nach STOP/Timeout verspaetet', 'wrong direction before generic paths')

# 7-8: A start timeout first requests a fresh status from the selected actor;
# if no movement is confirmed, the order is discarded without a fault latch.
start_timeout = function_body(CONTROLLER, 'J_HandleStartTimeout', 'J_HandleStopTimeout')
assert_order(start_timeout, 'LCN_RequestStatus($actorModuleID)', "J_BeginCancelGuard($rootID, 'Start nicht bestätigt", 'start timeout verification')
for required in [
    'Startstatus_Relais_AUF_Empfangen',
    'Startstatus_Relais_AB_Empfangen',
    'Der Auftrag wurde sicher verworfen',
    "Spätstart-Schutz ohne Fehlerverriegelung', false",
]:
    if required not in start_timeout:
        raise AssertionError(f'start timeout robustness missing: {required}')

# 9-11: A missing relay-off feedback requests a fresh status. A second toggle is
# allowed exactly once and only after both selected relay variables delivered a
# fresh update that still proves one selected relay active.
stop_timeout = function_body(CONTROLLER, 'J_HandleStopTimeout', 'J_HandleCancelGuard')
for required in [
    'Stopstatus_Relais_AUF_Empfangen',
    'Stopstatus_Relais_AB_Empfangen',
    'if (!$freshUp || !$freshDown)',
    'Aus Sicherheitsgründen wurde kein zweites Toggle gesendet',
    'if (GetValueBoolean($verifiedRetryID))',
    'SetValueBoolean($verifiedRetryID, true)',
    'J_SendDirection($rootID, $confirmedState, $confirmedState)',
]:
    if required not in stop_timeout:
        raise AssertionError(f'stop timeout safety missing: {required}')
assert_order(stop_timeout, 'LCN_RequestStatus($actorModuleID)', 'SetValueBoolean($verifiedRetryID, true)', 'fresh status before STOP retry')
assert_order(stop_timeout, 'SetValueBoolean($verifiedRetryID, true)', 'J_SendDirection($rootID, $confirmedState, $confirmedState)', 'retry flag before telegram')

# 12: At a confirmed hard lower end, a queued opposite command wins before the
# calibration window. Thus a quick reversal cannot be blocked by calibration.
real_stop = function_body(CONTROLLER, 'J_HandleRealStop', 'J_StartConfiguredFollowSlatOrFinish')
assert_order(real_stop, 'if (J_HasPending($rootID))', 'J_StartCalibrationWindow($rootID);', 'pending reversal before calibration')

# 13: Event dispatch accepts only the two relay variables stored in this exact
# module configuration; foreign/stale event triggers are discarded.
relay_update = function_body(CONTROLLER, 'J_HandleRelayUpdate', 'J_HandleRealStart')
for required in [
    'J_IsConfiguredRelayTrigger($rootID, $triggerVariableID)',
    'Fremde oder veraltete Relaismeldung',
]:
    if required not in relay_update:
        raise AssertionError(f'exact relay trigger filter missing: {required}')

# 14-16: All blind instances share one bus lock; the selected relay state is
# verified immediately before a toggle; duplicate physical/TS bindings are
# rejected during validation.
send_command = function_body(MODULE, 'SendDirectionCommand', 'selectedRelayState')
for required in [
    "'LCNJAL_LCN_BUS_SEND'",
    "'unmittelbar vor LCN_SendCommand'",
    'relayCommandStillRequired($lockedState',
    "ReadPropertyInteger('CommandSpacingMs')",
]:
    if required not in send_command:
        raise AssertionError(f'global bus/last-moment guard missing: {required}')
for required in [
    'private function findBindingConflicts(): array',
    'Motorrelaisvariable #',
    'GT8-LANG-Ereignisvariable #',
    'TS-KURZ ',
]:
    if required not in MODULE:
        raise AssertionError(f'cross-instance binding validation missing: {required}')


@dataclass
class Model:
    stop_sent: int = 0
    pending_target: int | None = None
    verified_retry: bool = False
    latched: bool = False

    def quick_target_during_stop(self, target: int) -> None:
        self.pending_target = target

    def verified_stop_timeout(self, fresh_up: bool, fresh_down: bool, relay_active: bool) -> None:
        if not relay_active:
            return
        if not (fresh_up and fresh_down):
            self.latched = True
            return
        if self.verified_retry:
            self.latched = True
            return
        self.verified_retry = True
        self.stop_sent += 1


# Pure transition regressions for the safety invariants above.
m = Model(stop_sent=1)
m.quick_target_during_stop(100)
assert m.stop_sent == 1 and m.pending_target == 100

m = Model(stop_sent=1)
m.verified_stop_timeout(True, True, True)
assert m.stop_sent == 2 and m.verified_retry and not m.latched
m.verified_stop_timeout(True, True, True)
assert m.stop_sent == 2 and m.latched

m = Model(stop_sent=1)
m.verified_stop_timeout(True, False, True)
assert m.stop_sent == 1 and m.latched


# V0.1.22: externe LCN-Fahrt hat Vorrang und darf nie automatisch gestoppt werden.
for required in [
    "reale LCN-/GT8-Fahrt hat Vorrang",
    "EXTERNAL_REFERENCE",
    "kein STOP durch Symcon",
    "Externe_Referenz_Gesetzt",
]:
    if required not in CONTROLLER and required not in WORKER:
        raise AssertionError(f'external-priority safety missing: {required}')

external_worker_block = WORKER.split("$phase === JW_PHASE_EXTERNAL", 1)[1].split("} elseif", 1)[0]
if "STOP_TIMEOUT" in external_worker_block or "DEADLINE" in external_worker_block:
    raise AssertionError('external phase must not trigger an automatic stop/deadline')
print('OPERATION SEQUENCES TEST OK (16 code invariants, 3 transition regressions)')
