#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ERRORS: list[str] = []


def check_json(path: Path) -> None:
    try:
        json.loads(path.read_text(encoding='utf-8'))
    except Exception as exc:
        ERRORS.append(f'{path.relative_to(ROOT)}: invalid JSON: {exc}')


def check_required() -> None:
    required = [
        'library.json',
        'README.md',
        'LICENSE',
        'LCNJalousie/module.json',
        'LCNJalousie/module.php',
        'LCNJalousie/module.html',
        'LCNJalousie/scripts/Controller.php',
        'LCNJalousie/scripts/Worker.php',
        'LCNJalousie/scripts/Healthcheck.php',
        'LCNJalousie/scripts/Diagnose.php',
        'LCNJalousieKonfigurator/module.json',
        'LCNJalousieKonfigurator/module.php',
    ]
    for rel in required:
        if not (ROOT / rel).is_file():
            ERRORS.append(f'missing file: {rel}')


def check_root_structure() -> None:
    allowed_without_module = {'docs', 'imgs', 'libs', 'tests', 'actions'}
    for path in ROOT.iterdir():
        if not path.is_dir() or path.name.startswith('.'):
            continue
        if path.name in allowed_without_module:
            continue
        if not (path / 'module.json').is_file():
            ERRORS.append(
                f'{path.name}: root directory is treated as a Symcon module, but module.json is missing'
            )


def check_php() -> None:
    try:
        subprocess.run(['php', '-v'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except Exception:
        print('NOTICE: PHP not available; PHP syntax check skipped.')
        return
    for path in sorted(ROOT.rglob('*.php')):
        result = subprocess.run(['php', '-l', str(path)], capture_output=True, text=True)
        if result.returncode != 0:
            ERRORS.append(f'{path.relative_to(ROOT)}: {result.stdout}{result.stderr}')


def check_module_identity(module_dir: Path) -> None:
    metadata_path = module_dir / 'module.json'
    php_path = module_dir / 'module.php'
    if not metadata_path.is_file() or not php_path.is_file():
        return
    data = json.loads(metadata_path.read_text(encoding='utf-8'))
    expected_class = str(data.get('name', '')).replace(' ', '')
    php = php_path.read_text(encoding='utf-8')
    import re
    match = re.search(r'\bclass\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+IPSModule(?:Strict)?\b', php)
    if not match:
        ERRORS.append(f'{php_path.relative_to(ROOT)}: module class declaration not found')
        return
    actual_class = match.group(1)
    if actual_class != expected_class:
        ERRORS.append(
            f'{php_path.relative_to(ROOT)}: class {actual_class} must equal module.json name without spaces: {expected_class}'
        )
    if module_dir.name != actual_class:
        ERRORS.append(
            f'{module_dir.relative_to(ROOT)}: folder name should equal module class name {actual_class}'
        )


def check_metadata() -> None:
    library = json.loads((ROOT / 'library.json').read_text(encoding='utf-8'))
    if library.get('compatibility', {}).get('version') != '9.0':
        ERRORS.append('library.json: compatibility.version must be 9.0')
    module_php = (ROOT / 'LCNJalousie' / 'module.php').read_text(encoding='utf-8')
    expected_version = str(library.get('version', ''))
    if f"private const VERSION = '{expected_version}';" not in module_php:
        ERRORS.append('LCNJalousie/module.php: VERSION constant must match library.json')
    module_ids: set[str] = set()
    for path in [ROOT / 'LCNJalousie' / 'module.json', ROOT / 'LCNJalousieKonfigurator' / 'module.json']:
        data = json.loads(path.read_text(encoding='utf-8'))
        module_id = data.get('id', '')
        if module_id in module_ids:
            ERRORS.append(f'duplicate module GUID: {module_id}')
        module_ids.add(module_id)
        if not data.get('prefix', '').isalnum():
            ERRORS.append(f'{path.relative_to(ROOT)}: prefix must be alphanumeric')
        check_module_identity(path.parent)



def check_configurator_configuration_object() -> None:
    path = ROOT / 'LCNJalousieKonfigurator' / 'module.php'
    if not path.is_file():
        return
    php = path.read_text(encoding='utf-8')
    forbidden = [
        "'configuration' => []",
        '"configuration" => []',
        'json_decode(IPS_GetConfiguration($instanceID), true)',
    ]
    for pattern in forbidden:
        if pattern in php:
            ERRORS.append(
                f'{path.relative_to(ROOT)}: create.configuration must be encoded as a JSON object, not an array ({pattern})'
            )
    if "'configuration' => new stdClass()" not in php:
        ERRORS.append(
            f'{path.relative_to(ROOT)}: empty create.configuration should use new stdClass() so json_encode emits {{}}'
        )


def check_lcn_connection_chain_validation() -> None:
    path = ROOT / 'LCNJalousie' / 'module.php'
    if not path.is_file():
        return
    php = path.read_text(encoding='utf-8')
    required = [
        "['ConnectionID']",
        'instanceConnectionChainContains',
        'IPS_GetInstance($currentInstanceID)',
    ]
    for pattern in required:
        if pattern not in php:
            ERRORS.append(
                f'{path.relative_to(ROOT)}: physical LCN assignment validation must follow instance ConnectionID ({pattern} missing)'
            )


def check_free_gt8_event_sources() -> None:
    path = ROOT / 'LCNJalousie' / 'module.php'
    if not path.is_file():
        return
    php = path.read_text(encoding='utf-8')
    required = [
        'Der simulierte Ausgang 3/4 darf von einem beliebigen freien UPU stammen',
        'als zweites Ziel der korrekten GT8-Taste am Haupt-UPU',
        'Eine Bindung an',
        'LCNSendModuleID wäre daher fachlich falsch',
        'findConnectedLcnModuleForVariable',
        'GT8LongUpSourceModuleID',
        'GT8LongDownSourceModuleID',
    ]
    for pattern in required:
        if pattern not in php:
            ERRORS.append(f'{path.relative_to(ROOT)}: free GT8 source description/logic missing ({pattern})')
    forbidden = [
        'Die GT8-LANG-AUF-Variable gehört nicht zur Verbindungskette des ausgewählten Sendemoduls.',
        'Die GT8-LANG-AB-Variable gehört nicht zur Verbindungskette des ausgewählten Sendemoduls.',
        'variableBelongsToInstanceChain($gt8Up, $sendModule)',
        'variableBelongsToInstanceChain($gt8Down, $sendModule)',
    ]
    for pattern in forbidden:
        if pattern in php:
            ERRORS.append(f'{path.relative_to(ROOT)}: GT8 source is still incorrectly bound to send module ({pattern})')


    controller_path = ROOT / 'LCNJalousie' / 'scripts' / 'Controller.php'
    if controller_path.is_file():
        controller = controller_path.read_text(encoding='utf-8')
        for pattern in [
            'function J_LcnModuleForVariable',
            "J_LcnModuleForVariable(J_ConfigInt($rootID, 'GT8_LANG_AUF_ID'))",
            "J_LcnModuleForVariable(J_ConfigInt($rootID, 'GT8_LANG_AB_ID'))",
        ]:
            if pattern not in controller:
                ERRORS.append(
                    f'{controller_path.relative_to(ROOT)}: arbitrary GT8 source module must participate in status synchronization ({pattern})'
                )


def check_native_shutter_visualization() -> None:
    module_path = ROOT / 'LCNJalousie' / 'module.php'
    controller_path = ROOT / 'LCNJalousie' / 'scripts' / 'Controller.php'
    worker_path = ROOT / 'LCNJalousie' / 'scripts' / 'Worker.php'
    if not module_path.is_file() or not controller_path.is_file() or not worker_path.is_file():
        return
    module = module_path.read_text(encoding='utf-8')
    controller = controller_path.read_text(encoding='utf-8')
    worker = worker_path.read_text(encoding='utf-8')
    required_module = [
        "RegisterVariableInteger(\n            'Position'",
        "RegisterVariableInteger(\n            'Drehgrad'",
        'VARIABLE_PRESENTATION_SHUTTER',
        "public function RequestAction(string $Ident, mixed $Value): void",
        "public function SyncVisualization(): void",
        "$this->EnableAction('Position')",
        "$this->EnableAction('Drehgrad')",
    ]
    for pattern in required_module:
        if pattern not in module:
            ERRORS.append(f'{module_path.relative_to(ROOT)}: native shutter visualization missing ({pattern})')
    if "LCNJAL_SyncVisualization($rootID)" not in controller:
        ERRORS.append(f'{controller_path.relative_to(ROOT)}: controller must synchronize native tile values')
    if "LCNJAL_SyncVisualization($rootID)" not in worker:
        ERRORS.append(f'{worker_path.relative_to(ROOT)}: worker must synchronize native tile values while moving')
    if "in_array($target, [0, 50, 100], true)" in controller:
        ERRORS.append(f'{controller_path.relative_to(ROOT)}: tile lamella target must not be limited to 0/50/100')
    if "Lamellenposition ist nicht referenziert. Zuerst Referenzfahrt AUF oder AB ausfuehren." not in controller:
        ERRORS.append(f'{controller_path.relative_to(ROOT)}: unreferenced lamella commands must be rejected')
    for pattern in [
        "$referenceRun = $explicitReference || (!$positionReferenced && $hardEnd);",
        "$duration = $referenceRun",
        "$referenceRun ? J_ORDER_REFERENCE : J_ORDER_BLIND",
    ]:
        if pattern not in controller:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: unknown end-position commands must perform a full reference run ({pattern})')





def check_javascript() -> None:
    html_path = ROOT / 'LCNJalousie' / 'module.html'
    if not html_path.is_file():
        return
    try:
        subprocess.run(['node', '--version'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except Exception:
        print('NOTICE: Node.js not available; JavaScript syntax check skipped.')
        return

    import re
    html = html_path.read_text(encoding='utf-8')
    scripts = re.findall(r'<script(?:\s[^>]*)?>(.*?)</script>', html, flags=re.S | re.I)
    for index, script in enumerate(scripts):
        if script.strip() == '':
            continue
        result = subprocess.run(['node', '--check', '-'], input=script, capture_output=True, text=True)
        if result.returncode != 0:
            ERRORS.append(
                f'{html_path.relative_to(ROOT)}: JavaScript block {index} has syntax errors: {result.stdout}{result.stderr}'
            )


def check_custom_html_tile() -> None:
    module_path = ROOT / 'LCNJalousie' / 'module.php'
    html_path = ROOT / 'LCNJalousie' / 'module.html'
    if not module_path.is_file() or not html_path.is_file():
        return
    module = module_path.read_text(encoding='utf-8')
    html = html_path.read_text(encoding='utf-8')

    required_module = [
        '$this->SetVisualizationType(1)',
        'public function GetVisualizationTile(): string',
        '$this->UpdateVisualizationValue($this->getVisualizationStateJson())',
        "'ShakeFree' => [",
        "'Stop' => [",
        "'ACTION' => 'STOP'",
        "'ACTION' => 'SET_SHAKEFREE'",
        'private function getVisualizationStateJson(): string',
        "'statusText' => $statusText",
        "'intermediateAllowed' => $intermediateAllowed",
        "'orderType' => $orderType",
        "'targetPosition' => round",
        "'targetRotation' => round",
        "'commandActive' => $commandActive",
        "$statusText = 'Geöffnet 100%'",
        "$statusText = 'Geschlossen 100%'",
    ]
    for pattern in required_module:
        if pattern not in module:
            ERRORS.append(f'{module_path.relative_to(ROOT)}: HTML-SDK tile integration missing ({pattern})')

    required_html = [
        '<script src="/icons.js"></script>',
        'function handleMessage(data)',
        "requestAction(ident, value)",
        "sendJalousieAction('Stop', true)",
        "sendJalousieAction('ShakeFree'",
        'class="jal-round-button jal-position-command"',
        'data-action="Position" data-value="0"',
        'data-action="Position" data-value="100"',
        'data-action="Drehgrad" data-value="0"',
        'data-action="Drehgrad" data-value="50"',
        'data-action="Drehgrad" data-value="100"',
        'id="jal-position"',
        'id="jal-rotation"',
        'id="jal-stop"',
        'id="jal-shakefree"',
        'id="jal-run-status-text"',
        'class="jal-control-layout"',
        'id="jal-blind-curtain"',
        'class="jal-round-button jal-slat-command"',
        'class="jal-graphic-wrap"',
        'var(--accent-color',
        'var(--content-color',
        'var(--card-color',
        'function renderCommandFeedback()',
        "orderType: 0",
        "commandActive: false",
        'Laufstatus: GESTOPPT',
    ]
    for pattern in required_html:
        if pattern not in html:
            ERRORS.append(f'{html_path.relative_to(ROOT)}: requested visualization control missing ({pattern})')

    forbidden_html = [
        'element.className =',
        '.className =',
        'id="jal-name"',
        'class="jal-status-panel"',
        'data-action="Position" data-value="50"',
        '@media (prefers-color-scheme: dark)',
        'findSymconBackground()',
        'syncSymconTheme()',
        'observeSymconTheme()',
        'window.parent.getComputedStyle',
        'data-jal-theme',
        'class="jal-presets"',
        'class="jal-preset"',
    ]
    for pattern in forbidden_html:
        if pattern in html:
            ERRORS.append(f'{html_path.relative_to(ROOT)}: obsolete or unsafe tile code still present ({pattern})')

    if '<iframe' in html.lower() or 'fetch(' in html or 'XMLHttpRequest' in html:
        ERRORS.append(f'{html_path.relative_to(ROOT)}: tile must use the secured HTML-SDK channel only')


def check_measured_blind_start_model() -> None:
    module_path = ROOT / 'LCNJalousie' / 'module.php'
    controller_path = ROOT / 'LCNJalousie' / 'scripts' / 'Controller.php'
    worker_path = ROOT / 'LCNJalousie' / 'scripts' / 'Worker.php'
    diagnose_path = ROOT / 'LCNJalousie' / 'scripts' / 'Diagnose.php'
    if not module_path.is_file() or not controller_path.is_file() or not worker_path.is_file() or not diagnose_path.is_file():
        return
    module = module_path.read_text(encoding='utf-8')
    controller = controller_path.read_text(encoding='utf-8')
    worker = worker_path.read_text(encoding='utf-8')
    diagnose = diagnose_path.read_text(encoding='utf-8')
    required_module = [
        "RegisterPropertyInteger('SoftStartMs', 6000)",
        "'Sanftanlauf_ms' => ['SoftStartMs', 1]",
        "['Sanftanlauf_ms', 'Sanftanlauf Zwischenposition [ms]'",
        'invalidateReferenceAfterModelUpdate',
        "version_compare($previousVersion, '0.1.14', '>=')",
        "SetValueBoolean((int) $referencedID, false)",
        "Gesamtlaufzeit 100 % ZU → 0 % AUF",
        "Gesamtlaufzeit 0 % AUF → 100 % ZU",
        "ShakeFree nach Endlage ZU",
    ]
    for pattern in required_module:
        if pattern not in module:
            ERRORS.append(f'{module_path.relative_to(ROOT)}: measured start model configuration missing ({pattern})')
    required_controller = [
        'function J_SlatTurnTimeMs',
        'function J_BlindStartDelayMs',
        "J_ConfigInt($rootID, 'Sanftanlauf_ms')",
        'if ($direction === J_DIR_DOWN && $startBlind <= $positionTolerance)',
        'if ($direction === J_DIR_UP && $startBlind >= 100.0 - $positionTolerance)',
        'return max($softStartMs, J_SlatTurnTimeMs($rootID, $startSlat, $direction));',
        '$blindStartDelay = J_BlindStartDelayMs',
        'function J_DirectionalBlindTravelMs',
        "J_ConfigInt($rootID, 'Gesamtlaufzeit_ms')",
        "J_ConfigInt($rootID, 'Behanglaufzeit_ms')",
    ]
    for pattern in required_controller:
        if pattern not in controller:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: measured start model missing ({pattern})')
    if '$blindElapsed = max(0.0, $elapsed - $turnNeeded);' in controller:
        ERRORS.append(f'{controller_path.relative_to(ROOT)}: blind position must not always start after slat turn time')
    required_worker = [
        'function JW_BlindStartDelayMs',
        "'Sanftanlauf_ms'",
        '$blindStartDelay = JW_BlindStartDelayMs',
        'function JW_DirectionalBlindTravelMs',
    ]
    for pattern in required_worker:
        if pattern not in worker:
            ERRORS.append(f'{worker_path.relative_to(ROOT)}: worker start model missing ({pattern})')
    if '$blindElapsed = max(0.0, $elapsed - $turnNeeded);' in worker:
        ERRORS.append(f'{worker_path.relative_to(ROOT)}: worker must not use the old slat-only blind delay')
    if "'Sanftanlauf_ms' => 1" not in diagnose or "Sanftanlauf_ms darf die volle Wendezeit" not in diagnose:
        ERRORS.append(f'{diagnose_path.relative_to(ROOT)}: diagnostics must validate Sanftanlauf_ms')



def check_timing_examples() -> None:
    turn_ms = 6500.0
    soft_ms = 6000.0
    total_up_ms = 182000.0
    total_down_ms = 175500.0
    blind_up_ms = total_up_ms - turn_ms
    blind_down_ms = total_down_ms
    tolerance = 0.5

    def slat_turn(start_slat: float, direction: int) -> float:
        return start_slat / 100.0 * turn_ms if direction == 1 else (100.0 - start_slat) / 100.0 * turn_ms

    def delay(start_blind: float, start_slat: float, direction: int) -> float:
        if direction == 2 and start_blind <= tolerance:
            return 0.0
        if direction == 1 and start_blind >= 100.0 - tolerance:
            return turn_ms
        return max(soft_ms, slat_turn(start_slat, direction))

    examples = [
        ('oben nach ZU', delay(0.0, 0.0, 2), 0.0),
        ('unten nach AUF', delay(100.0, 100.0, 1), 6500.0),
        ('Mitte gleiche Richtung ZU', delay(50.0, 100.0, 2), 6000.0),
        ('Mitte gleiche Richtung AUF', delay(50.0, 0.0, 1), 6000.0),
        ('Mitte Gegenrichtung ZU', delay(50.0, 0.0, 2), 6500.0),
        ('Mitte Gegenrichtung AUF', delay(50.0, 100.0, 1), 6500.0),
    ]
    for name, actual, expected in examples:
        if actual != expected:
            ERRORS.append(f'timing model {name}: expected {expected}, got {actual}')

    if blind_down_ms != 175500.0:
        ERRORS.append('directional timing: 0-to-100 ZU blind travel must use configured down total')
    if turn_ms + blind_up_ms != total_up_ms:
        ERRORS.append('directional timing: 100-to-0 AUF total must include full turn time')



def check_calibration_window_and_fault_latch() -> None:
    module_path = ROOT / 'LCNJalousie' / 'module.php'
    controller_path = ROOT / 'LCNJalousie' / 'scripts' / 'Controller.php'
    worker_path = ROOT / 'LCNJalousie' / 'scripts' / 'Worker.php'
    html_path = ROOT / 'LCNJalousie' / 'module.html'
    diagnose_path = ROOT / 'LCNJalousie' / 'scripts' / 'Diagnose.php'
    if not all(path.is_file() for path in [module_path, controller_path, worker_path, html_path, diagnose_path]):
        return

    module = module_path.read_text(encoding='utf-8')
    controller = controller_path.read_text(encoding='utf-8')
    worker = worker_path.read_text(encoding='utf-8')
    html = html_path.read_text(encoding='utf-8')
    diagnose = diagnose_path.read_text(encoding='utf-8')

    required_module = [
        "RegisterPropertyBoolean('ModuleEnabled', true)",
        "RegisterPropertyInteger('CalibrationWindowMs', 30000)",
        "RegisterAttributeBoolean('FaultLatched', false)",
        "RegisterAttributeBoolean('RuntimeEnabled', false)",
        "public function LatchFault(string $message): void",
        "public function IsRuntimePermitted(): bool",
        "private const STATUS_FAULT_LATCHED = 212",
        "$this->SetStatus(104)",
        "$this->setRuntimeEnabled(false",
        "['Kalibrierfenster_ms', 'Zeitverzögerung / Kalibrierfenster nach 100 % ZU [ms]'",
        "'Kalibrierfenster_ms' => ['CalibrationWindowMs', 1]",
        "['Modul_Aktiv', 'Symcon-Steuerung aktiv'",
        "'Modul_Aktiv' => ['ModuleEnabled', 0]",
        "Fehler_Verriegelt', 'Fehler verriegelt'",
        "Fehler quittiert. Symcon wurde ohne LCN-Fahrbefehl reaktiviert",
    ]
    for pattern in required_module:
        if pattern not in module:
            ERRORS.append(f'{module_path.relative_to(ROOT)}: safety extension missing ({pattern})')

    required_controller = [
        'const J_PHASE_CALIBRATION = 10;',
        "IPS_FunctionExists('LCNJAL_IsRuntimePermitted')",
        "LCNJAL_IsRuntimePermitted($rootID)",
        "J_ConfigInt($rootID, 'Kalibrierfenster_ms')",
        "SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_CALIBRATION)",
        "100 % ZU erreicht; Zeitverzoegerung/Kalibrierfenster gestartet",
        "if ($phase === J_PHASE_CALIBRATION)",
        "LCNJAL_LatchFault($rootID, $message)",
        "Symcon-Instanz sofort",
        "Starttimeout; verspaeteten Relaisstart abfangen', true",
    ]
    for pattern in required_controller:
        if pattern not in controller:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: calibration/fault logic missing ({pattern})')

    forbidden_controller = [
        "J_PHASE_BLIND && $oldDirection === J_DIR_DOWN",
        "J_SetWorker($rootID, true); // Weiter Positionsanzeige, bis das reale Relais AUS meldet.",
    ]
    for pattern in forbidden_controller:
        if pattern in controller:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: unsafe old behavior remains ({pattern})')

    if 'JW_PHASE_CALIBRATION = 10' not in worker or 'JW_PHASE_CALIBRATION' not in worker.split('in_array($phase', 1)[-1]:
        ERRORS.append(f'{worker_path.relative_to(ROOT)}: worker must supervise the 30-second calibration phase')

    for pattern in ['moduleEnabled: true', 'faultLatched: false', 'shakeFreeToggleEnabled: false', 'jalState.faultLatched', 'jalState.moduleEnabled']:
        if pattern not in html:
            ERRORS.append(f'{html_path.relative_to(ROOT)}: inactive/fault tile state missing ({pattern})')

    for pattern in ["'Kalibrierfenster_ms' => 1", "Zeitverzoegerung/Kalibrierfenster_ms muss zwischen 30000 und 120000 ms", "'Modul_Aktiv' => 0", "'Fehler_Verriegelt' => 0"]:
        if pattern not in diagnose:
            ERRORS.append(f'{diagnose_path.relative_to(ROOT)}: diagnostics missing ({pattern})')

    # The 30-second window is deliberately additional to MaxFahrt: MaxFahrt
    # monitors the mechanical travel, while the output remains energized for
    # the manufacturer's potential calibration routine.
    if "GetValueBoolean(J_ID($rootID, '03_Bedienung', 'ShakeFree_Aktiv'))" in controller.split("100 % ZU erreicht; Zeitverzoegerung/Kalibrierfenster gestartet", 1)[0].split('function J_HandleDeadline', 1)[-1]:
        ERRORS.append(f'{controller_path.relative_to(ROOT)}: calibration window must run after every complete close, not only when ShakeFree is enabled')


def main() -> int:
    check_required()
    check_root_structure()
    for path in ROOT.rglob('*.json'):
        check_json(path)
    check_metadata()
    check_configurator_configuration_object()
    check_lcn_connection_chain_validation()
    check_free_gt8_event_sources()
    check_native_shutter_visualization()
    check_custom_html_tile()
    check_javascript()
    check_measured_blind_start_model()
    check_timing_examples()
    check_calibration_window_and_fault_latch()
    check_php()
    if ERRORS:
        print('VALIDATION FAILED')
        for error in ERRORS:
            print('-', error)
        return 1
    print('VALIDATION OK')
    return 0


if __name__ == '__main__':
    sys.exit(main())
