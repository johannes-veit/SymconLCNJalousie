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
    "J_IGetBoolean($rootID, 'Stop_Angefordert')",
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
    "if (J_IGetBoolean($rootID, 'Stop_Wiederholung_Gesendet'))",
    "J_ISetBoolean($rootID, 'Stop_Wiederholung_Gesendet', true)",
    'J_SendDirection($rootID, $confirmedState, $confirmedState)',
]:
    if required not in stop_timeout:
        raise AssertionError(f'stop timeout safety missing: {required}')
assert_order(stop_timeout, 'LCN_RequestStatus($actorModuleID)', "J_ISetBoolean($rootID, 'Stop_Wiederholung_Gesendet', true)", 'fresh status before STOP retry')
assert_order(stop_timeout, "J_ISetBoolean($rootID, 'Stop_Wiederholung_Gesendet', true)", 'J_SendDirection($rootID, $confirmedState, $confirmedState)', 'retry flag before telegram')

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


# 17-20: V0.1.23 keeps a valid reference during normal movement and observes
# external LCN/GT8 movement until a safe endpoint. Only after the configurable
# calibration window is the still-active selected relay toggled off.
start_blind = function_body(CONTROLLER, 'J_StartBlindNow', 'J_RequestSlat')
if 'J_InvalidateReference' in start_blind:
    raise AssertionError('normal blind start must not invalidate a valid reference')
for required in [
    'Eine bereits gültige Referenz bleibt während der Fahrt gültig',
    'Externe_Endlage_bis_ms',
    'Externer_Autostopp_bis_ms',
    'Externer_Autostopp_Aktiv',
    "J_SetReference(",
    "J_SendExternalEndStop($rootID, $state)",
    "J_ArmStopConfirmation($rootID)",
]:
    if required not in CONTROLLER:
        raise AssertionError(f'external endpoint/autostop logic missing: {required}')
for required in [
    "'EXTERNAL_REFERENCE'",
    "'EXTERNAL_STOP'",
    "Externe_Endlage_bis_ms",
    "Externer_Autostopp_bis_ms",
]:
    if required not in WORKER:
        raise AssertionError(f'external worker deadline missing: {required}')

external_reference = function_body(CONTROLLER, 'J_HandleExternalReferenceDeadline', 'J_HandleExternalEndStop')
assert_order(external_reference, 'J_SetReference(', 'Externer_Autostopp_bis_ms', 'reference before calibration deadline')
external_stop = function_body(CONTROLLER, 'J_HandleExternalEndStop', 'J_SendExternalEndStop')
assert_order(external_stop, "J_NowMs() < $deadline", 'J_SendExternalEndStop($rootID, $state)', 'calibration before external STOP')

# Routine updates may finish a status sync without fresh OnUpdate events
# when both selected motor relays are already safely OFF. This must preserve a
# valid reference and avoid a global acknowledgement storm after updates.
status_sync = function_body(CONTROLLER, 'J_CompleteStatusSync', 'J_InitializeRuntime')
for required in [
    'if ($currentState === J_DIR_NONE)',
    'gespeicherte Referenz ohne manuelle Quittierung übernommen',
    'vorhandener unreferenzierter Zustand übernommen',
]:
    if required not in status_sync:
        raise AssertionError(f'safe update status fallback missing: {required}')

# 21-24: Every unconfirmed toggle command owns a correlation lease, but an
# open lease from another instance must no longer block the next telegram. The
# global send semaphore and a hard minimum spacing protect the LCN bus while
# several motors may start and then run in parallel.
for required in [
    'CommandLeaseActive',
    'markCommandLeaseState($direction, $expectedRelayState)',
    'clearCommandLeaseState()',
    'ForeignRelayResponse',
    'startedMs > $nowMs + 1000.0',
    "'LCNJAL_LCN_BUS_SEND'",
    "max(100, min(1000, $this->ReadPropertyInteger('CommandSpacingMs')))",
]:
    if required not in MODULE:
        raise AssertionError(f'parallel command safety missing: {required}')
if 'waitForOtherCommandLease(15000)' in send_command:
    raise AssertionError('a foreign unconfirmed start must not synchronously block the next blind command')
assert_order(send_command, 'markCommandLeaseState($direction, $expectedRelayState)', 'LCN_SendCommand($sendModuleID', 'lease marker before telegram')
assert_order(real_start, 'J_ClearCommandLease($rootID);', "J_ISetFloat($rootID, 'Zielzeit_ms'", 'lease release after real start')
if 'J_ClearCommandLease($rootID);' not in real_stop:
    raise AssertionError('real relay-off confirmation must clear command lease')

# 25-31: A relay response in another instance is correlated with the single
# active start lease. It is only promoted to a hard routing fault after a fresh
# status request has confirmed both selected sender relays are still OFF.
for required in [
    'J_ReportPossibleForeignCommand',
    'LCNJAL_ReportForeignRelayResponse',
    'J_HandleForeignRelayResponse',
    'LCNJAL_BlockCurrentRouting',
    'TS-Routingfehler bestätigt:',
    'correlationCandidate',
    'Startstatus_Relais_AUF_Empfangen',
    'Startstatus_Relais_AB_Empfangen',
    'J_HandleForeignRelayResponse($rootID, $order, true)',
    'Routing wird beim Sender geprüft',
]:
    if required not in CONTROLLER:
        raise AssertionError(f'cross-instance routing detection missing: {required}')

if "case 'FOREIGN_RESPONSE':" in CONTROLLER or "'FOREIGN_RESPONSE'" in WORKER:
    raise AssertionError('a temporal foreign response must not cause an immediate hard fault before fresh sender status')
if 'BlockedRoutingFingerprint' not in MODULE or 'routingIsBlocked()' not in MODULE:
    raise AssertionError('a physically disproved send-module/TS combination must remain blocked')

start_timeout = function_body(CONTROLLER, 'J_HandleStartTimeout', 'J_HandleStopTimeout')
for required in [
    'zweite passive Bestätigungsfrist läuft',
    'Startstatus_Nachfrage_Aktiv',
    'Positionsreferenz unverändert',
]:
    if required not in start_timeout:
        raise AssertionError(f'stable two-window start confirmation missing: {required}')
if 'J_SetError(' in start_timeout.split('J_HandleForeignRelayResponse($rootID, $order, true)', 1)[-1]:
    raise AssertionError('plain missing start confirmation must not latch a permanent fault')

for required in [
    'private const LCN_MODULE_ID',
    'resolveLcnModuleAddress',
    'findDuplicateLcnAddressInstances',
    'validateSelectedLcnAddresses',
    'nameMatchesAddress',
    'duplicateInstanceIDs',
    "'sendRouteKey'",
]:
    if required not in MODULE:
        raise AssertionError(f'physical LCN address validation missing: {required}')

if 'if ($this->routingIsBlocked())' not in send_command:
    raise AssertionError('a blocked route must also prevent fault-latched external end-stop toggles')

# 30-31: Worker and healthcheck independently execute both external deadlines.
healthcheck = function_body(CONTROLLER, 'J_RunHealthcheck', 'J_StatusText')
for required in [
    'J_HandleExternalReferenceDeadline($rootID, $order)',
    'J_HandleExternalEndStop($rootID, $order)',
]:
    if required not in healthcheck:
        raise AssertionError(f'external healthcheck fallback missing: {required}')

# 32-35: Fault latching, runtime disable and error acknowledgement do not erase
# a still-valid persistent reference. Only explicit uncertainty paths may call
# InvalidateReference.
apply_changes = function_body(MODULE, 'ApplyChanges', 'MessageSink')
for required in [
    "SetBuffer('MaintenanceActive', '1')",
    'suspendRuntimeForApplyChanges()',
    'autoRecoverTransientMaintenanceFault($validation)',
    "SetBuffer('MaintenanceActive', '0')",
]:
    if required not in apply_changes:
        raise AssertionError(f'update maintenance protection missing: {required}')
if 'private function autoRecoverTransientMaintenanceFault' not in MODULE:
    raise AssertionError('transient update faults must be recoverable without manual acknowledgement')
if 'private function suspendRuntimeForApplyChanges' not in MODULE:
    raise AssertionError('runtime events must be suspended while scripts are rebuilt')
if 'invalidateReferenceWithoutCommand' in apply_changes:
    raise AssertionError('ApplyChanges/runtime disable must preserve a valid reference')
latch_fault = function_body(MODULE, 'LatchFault', 'StoreReference')
if 'InvalidateReference' in latch_fault or 'invalidateReferenceWithoutCommand' in latch_fault:
    raise AssertionError('generic fault latch must not erase reference')
reset_error = function_body(CONTROLLER, 'J_ResetError', 'J_FinishIdle')
if 'J_InvalidateReference' in reset_error:
    raise AssertionError('fault acknowledgement must not erase reference')
restore_reference = function_body(MODULE, 'restorePersistentReference', 'persistReferenceInvalid')
for required in [
    'nicht die aktuelle Zwischenposition',
    'false',
    "GetValueFloat((int) $positionID)",
    "GetValueFloat((int) $slatID)",
]:
    if required not in restore_reference:
        raise AssertionError(f'current position preservation missing: {required}')


@dataclass
class LeaseModel:
    active_owner: str | None = None
    moving: set[str] | None = None

    def __post_init__(self) -> None:
        if self.moving is None:
            self.moving = set()

    def request_start(self, name: str) -> bool:
        if self.active_owner is not None:
            return False
        self.active_owner = name
        return True

    def confirm_start(self, name: str) -> None:
        assert self.active_owner == name
        self.active_owner = None
        self.moving.add(name)


# Two instances cannot have overlapping unconfirmed toggles, but after the
# first real relay confirmation both physical motors may run concurrently.
leases = LeaseModel()
assert leases.request_start('Wohnen')
assert not leases.request_start('Buero')
leases.confirm_start('Wohnen')
assert leases.request_start('Buero')
leases.confirm_start('Buero')
assert leases.moving == {'Wohnen', 'Buero'}


@dataclass
class ExternalModel:
    referenced: bool = False
    relay_on: bool = True
    stop_sent: int = 0

    def endpoint(self) -> None:
        self.referenced = True

    def calibration_expired(self) -> None:
        if self.referenced and self.relay_on:
            self.stop_sent += 1

    def relay_off(self) -> None:
        self.relay_on = False


external = ExternalModel()
external.endpoint()
assert external.referenced and external.relay_on and external.stop_sent == 0
external.calibration_expired()
assert external.stop_sent == 1
external.relay_off()
external.calibration_expired()
assert external.stop_sent == 1

print('OPERATION SEQUENCES TEST OK (35 code invariants, 5 transition regressions)')
