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

    php_regressions = [
        ('restart_lifecycle_test.php', 'RESTART LIFECYCLE TEST OK'),
        ('relay_command_state_test.php', 'RELAY COMMAND STATE TEST OK'),
        ('lcn_address_validation_test.php', 'LCN ADDRESS VALIDATION TEST OK'),
        ('start_confirmation_migration_test.php', 'START CONFIRMATION MIGRATION TEST OK'),
        ('compact_storage_migration_test.php', 'COMPACT STORAGE MIGRATION TEST OK'),
    ]
    for filename, success_text in php_regressions:
        test_path = ROOT / 'tests' / filename
        if not test_path.is_file():
            ERRORS.append(f'missing regression test: tests/{filename}')
            continue
        result = subprocess.run(['php', str(test_path)], capture_output=True, text=True)
        if result.returncode != 0 or success_text not in result.stdout:
            ERRORS.append(
                f'{test_path.relative_to(ROOT)}: regression test failed: '
                f'{result.stdout}{result.stderr}'
            )

    operation_test = ROOT / 'tests' / 'operation_sequences_test.py'
    if not operation_test.is_file():
        ERRORS.append('missing regression test: tests/operation_sequences_test.py')
    else:
        result = subprocess.run([sys.executable, str(operation_test)], capture_output=True, text=True)
        if result.returncode != 0 or 'OPERATION SEQUENCES TEST OK' not in result.stdout:
            ERRORS.append(
                f'{operation_test.relative_to(ROOT)}: operation sequence test failed: '
                f'{result.stdout}{result.stderr}'
            )


    visualization_test = ROOT / 'tests' / 'visualization_transport_test.js'
    if not visualization_test.is_file():
        ERRORS.append('missing regression test: tests/visualization_transport_test.js')
    else:
        try:
            subprocess.run(['node', '--version'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        except Exception:
            print('NOTICE: Node.js not available; visualization transport regression skipped.')
        else:
            result = subprocess.run(['node', str(visualization_test)], capture_output=True, text=True)
            if result.returncode != 0 or 'VISUALIZATION TRANSPORT TEST OK' not in result.stdout:
                ERRORS.append(
                    f'{visualization_test.relative_to(ROOT)}: visualization transport test failed: '
                    f'{result.stdout}{result.stderr}'
                )

    interaction_test = ROOT / 'tests' / 'interaction_matrix_test.py'
    if not interaction_test.is_file():
        ERRORS.append('missing regression test: tests/interaction_matrix_test.py')
    else:
        result = subprocess.run([sys.executable, str(interaction_test)], capture_output=True, text=True)
        if result.returncode != 0 or 'INTERACTION MATRIX TEST OK' not in result.stdout:
            ERRORS.append(
                f'{interaction_test.relative_to(ROOT)}: interaction matrix test failed: '
                f'{result.stdout}{result.stderr}'
            )


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
            "J_LcnModuleForVariable((int) $binding['gt8LongUpVariableID'])",
            "J_LcnModuleForVariable((int) $binding['gt8LongDownVariableID'])",
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
        "version_compare($previousVersion, '0.1.18', '>=')",
        'persistReferenceInvalid',
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

    reserve_ms = 5000.0
    max_ms = max(total_up_ms, total_down_ms) + reserve_ms
    if min(total_up_ms + reserve_ms, max_ms) != 187000.0:
        ERRORS.append('reference timing: AUF reference must use total-up plus reserve')
    if min(total_down_ms + reserve_ms, max_ms) != 180500.0:
        ERRORS.append('reference timing: ZU reference must use total-down plus reserve')



def check_soft_stop_model() -> None:
    module_path = ROOT / 'LCNJalousie' / 'module.php'
    controller_path = ROOT / 'LCNJalousie' / 'scripts' / 'Controller.php'
    worker_path = ROOT / 'LCNJalousie' / 'scripts' / 'Worker.php'
    diagnose_path = ROOT / 'LCNJalousie' / 'scripts' / 'Diagnose.php'
    if not all(path.is_file() for path in [module_path, controller_path, worker_path, diagnose_path]):
        return

    module = module_path.read_text(encoding='utf-8')
    controller = controller_path.read_text(encoding='utf-8')
    worker = worker_path.read_text(encoding='utf-8')
    diagnose = diagnose_path.read_text(encoding='utf-8')

    required_module = [
        "RegisterPropertyInteger('SoftStopUpMs', 4500)",
        "RegisterPropertyInteger('SoftStopDownMs', 4500)",
        "'Sanftstopp_AUF_ms' => ['SoftStopUpMs', 1]",
        "'Sanftstopp_ZU_ms' => ['SoftStopDownMs', 1]",
        "['Sanftstopp_AUF_ms', 'Sanft-Stopp vor Endlage AUF [ms]'",
        "['Sanftstopp_ZU_ms', 'Sanft-Stopp vor Endlage ZU [ms]'",
        'Berechneter Sanft-Stopp-Fahrweg',
        'Sanft-Stopp ist positionsabhängig',
        '100.0 * $softStopMs / (2.0 * $travelMs - $softStopMs)',
    ]
    for pattern in required_module:
        if pattern not in module:
            ERRORS.append(f'{module_path.relative_to(ROOT)}: distance-based soft-stop configuration missing ({pattern})')

    required_controller = [
        'function J_DirectionalSoftStopMs',
        'function J_DirectionalSoftStopRangePercent',
        'function J_BlindTimeCoordinateMs',
        'function J_BlindPositionAtTimeCoordinate',
        "'Sanftstopp_AUF_ms'",
        "'Sanftstopp_ZU_ms'",
        '100.0 * $softStopMs / (2.0 * $blindTravelMs - $softStopMs)',
        '$effectiveTravelMs = $blindTravelMs - $softStopMs / 2.0;',
        '$softStopStartProgress = 1.0 - $softStopRange;',
        '$startCoordinateMs = J_BlindTimeCoordinateMs',
        '$targetCoordinateMs = J_BlindTimeCoordinateMs',
        '$blindStartCoordinateMs = J_BlindTimeCoordinateMs',
        '$blind = J_BlindPositionAtTimeCoordinate',
        'Zwischenziel in der Endzone nutzt daher den',
    ]
    for pattern in required_controller:
        if pattern not in controller:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: distance-based soft-stop model missing ({pattern})')

    forbidden_controller = [
        'Nur ein echter Auftrag auf 0 % oder 100 % durchläuft',
        'Zwischenpositionen werden mit voller Geschwindigkeit angefahren',
        '$blindMs = abs($targetBlind - $startBlind) / 100.0 * $fullSpeedTravelMs;',
        '$blindDelta = 100.0 * $blindElapsed / $fullSpeedTravelMs;',
    ]
    for pattern in forbidden_controller:
        if pattern in controller:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: obsolete endpoint-only soft-stop behavior remains ({pattern})')

    required_worker = [
        'function JW_DirectionalSoftStopMs',
        'function JW_DirectionalSoftStopRangePercent',
        'function JW_BlindTimeCoordinateMs',
        'function JW_BlindPositionAtTimeCoordinate',
        '100.0 * $softStopMs / (2.0 * $blindTravelMs - $softStopMs)',
        '$softStopStartProgress = 1.0 - $softStopRange;',
        '$blindStartCoordinateMs = JW_BlindTimeCoordinateMs',
        '$blind = JW_BlindPositionAtTimeCoordinate',
        'Positionsabhängige Kennlinie für jede Fahrt',
    ]
    for pattern in required_worker:
        if pattern not in worker:
            ERRORS.append(f'{worker_path.relative_to(ROOT)}: worker distance-based soft-stop model missing ({pattern})')

    forbidden_worker = [
        'Die Endlagenkennlinie gilt ausschließlich für echte 0-/100-%-Aufträge.',
        'Zwischenpositionen werden ohne Sanft-Stopp am Ziel',
        '$blindDelta = 100.0 * $blindElapsed / $fullSpeedTravelMs;',
    ]
    for pattern in forbidden_worker:
        if pattern in worker:
            ERRORS.append(f'{worker_path.relative_to(ROOT)}: worker endpoint-only soft-stop behavior remains ({pattern})')

    for pattern in [
        "'Sanftstopp_AUF_ms' => 1",
        "'Sanftstopp_ZU_ms' => 1",
        'Sanftstopp_AUF_ms und Sanftstopp_ZU_ms',
        '100.0 * $softStopUp / (2.0 * $blindUp - $softStopUp)',
        'Fahrweg 0–',
        '–100 %',
    ]:
        if pattern not in diagnose:
            ERRORS.append(f'{diagnose_path.relative_to(ROOT)}: diagnostics soft-stop range missing ({pattern})')

    def range_percent(travel_ms: float, soft_ms: float) -> float:
        if travel_ms <= 0.0 or soft_ms <= 0.0 or soft_ms >= travel_ms:
            return 0.0
        return 100.0 * soft_ms / (2.0 * travel_ms - soft_ms)

    def time_coordinate(position: float, direction: int, travel_ms: float, soft_ms: float) -> float:
        position = max(0.0, min(100.0, position))
        progress = (100.0 - position) / 100.0 if direction == 1 else position / 100.0
        if soft_ms <= 0.0:
            return progress * travel_ms
        effective = travel_ms - soft_ms / 2.0
        distance = progress * effective
        soft_start_time = travel_ms - soft_ms
        soft_start_progress = 1.0 - range_percent(travel_ms, soft_ms) / 100.0
        if progress <= soft_start_progress:
            return distance
        soft_distance = min(soft_ms / 2.0, max(0.0, distance - soft_start_time))
        discriminant = max(0.0, soft_ms * soft_ms - 2.0 * soft_ms * soft_distance)
        return soft_start_time + soft_ms - discriminant ** 0.5

    def position_at_time(time_ms: float, direction: int, travel_ms: float, soft_ms: float) -> float:
        time_ms = max(0.0, min(travel_ms, time_ms))
        if soft_ms <= 0.0:
            progress = time_ms / travel_ms
        else:
            effective = travel_ms - soft_ms / 2.0
            soft_start_time = travel_ms - soft_ms
            if time_ms <= soft_start_time:
                distance = time_ms
            else:
                elapsed_soft = time_ms - soft_start_time
                distance = soft_start_time + elapsed_soft - elapsed_soft * elapsed_soft / (2.0 * soft_ms)
            progress = distance / effective
        progress = max(0.0, min(1.0, progress))
        return 100.0 * (1.0 - progress) if direction == 1 else 100.0 * progress

    # Standardwerte: 4.500 ms bei 175.500 ms Behanglaufzeit ergeben rund
    # 1,30 % Fahrweg je Endlage, nicht 4.500/175.500 Prozent.
    standard_range = range_percent(175500.0, 4500.0)
    if abs(standard_range - 1.2987012987012987) > 1e-12:
        ERRORS.append(f'soft-stop standard range mismatch: {standard_range}')

    # Vorwärts- und Rückwärtsfunktion müssen für beide Richtungen und mehrere
    # Laufzeitkombinationen exakt zusammenpassen.
    cases = [
        (175500.0, 4500.0),
        (120000.0, 0.0),
        (90000.0, 9000.0),
        (47250.0, 4500.0),  # exakt 5 % Fahrweg in der Sanft-Stopp-Zone
    ]
    for direction in (1, 2):
        for travel_ms, soft_ms in cases:
            for step in range(0, 1001):
                position = step / 10.0
                reconstructed = position_at_time(
                    time_coordinate(position, direction, travel_ms, soft_ms),
                    direction,
                    travel_ms,
                    soft_ms,
                )
                if abs(reconstructed - position) > 1e-8:
                    ERRORS.append(
                        f'soft-stop inverse mismatch direction={direction}, travel={travel_ms}, '
                        f'soft={soft_ms}, position={position}: {reconstructed}'
                    )
                    break

    # Beispiel aus der Anforderung: Bei exakt 5 % Sanft-Stopp-Fahrweg beginnt
    # die Zone ZU bei 95 % und AUF bei 5 %. 95 % beziehungsweise 5 % enthalten
    # noch keine Verzögerungszeit; danach wächst der genutzte Anteil bis zur
    # vollen Sanft-Stopp-Zeit an der Endlage monoton.
    travel_ms = 47250.0
    soft_ms = 4500.0
    if abs(range_percent(travel_ms, soft_ms) - 5.0) > 1e-12:
        ERRORS.append('synthetic 5-percent soft-stop range is not exactly 5 %')

    down_zone_start = 95.0
    down_start_time = time_coordinate(down_zone_start, 2, travel_ms, soft_ms)
    if abs(down_start_time - (travel_ms - soft_ms)) > 1e-9:
        ERRORS.append(f'down soft-stop must begin at 95 %: {down_start_time}')
    down_soft_elapsed = [
        time_coordinate(target, 2, travel_ms, soft_ms) - down_start_time
        for target in (96.0, 97.0, 98.0, 99.0, 100.0)
    ]
    if not (0.0 < down_soft_elapsed[0] < down_soft_elapsed[1] < down_soft_elapsed[2] < down_soft_elapsed[3] < down_soft_elapsed[4]):
        ERRORS.append(f'down partial soft-stop times are not monotonic: {down_soft_elapsed}')
    if abs(down_soft_elapsed[-1] - soft_ms) > 1e-9:
        ERRORS.append(f'down endpoint must contain full soft-stop duration: {down_soft_elapsed[-1]}')

    up_zone_start = 5.0
    up_start_time = time_coordinate(up_zone_start, 1, travel_ms, soft_ms)
    if abs(up_start_time - (travel_ms - soft_ms)) > 1e-9:
        ERRORS.append(f'up soft-stop must begin at 5 %: {up_start_time}')
    up_soft_elapsed = [
        time_coordinate(target, 1, travel_ms, soft_ms) - up_start_time
        for target in (4.0, 3.0, 2.0, 1.0, 0.0)
    ]
    if not (0.0 < up_soft_elapsed[0] < up_soft_elapsed[1] < up_soft_elapsed[2] < up_soft_elapsed[3] < up_soft_elapsed[4]):
        ERRORS.append(f'up partial soft-stop times are not monotonic: {up_soft_elapsed}')
    if abs(up_soft_elapsed[-1] - soft_ms) > 1e-9:
        ERRORS.append(f'up endpoint must contain full soft-stop duration: {up_soft_elapsed[-1]}')

    # Außerhalb der Endzone muss die Zeit-Weg-Zuordnung exakt linear bleiben.
    effective_ms = travel_ms - soft_ms / 2.0
    if abs(time_coordinate(95.0, 2, travel_ms, soft_ms) - 0.95 * effective_ms) > 1e-9:
        ERRORS.append('down position 95 % must still be reached at full-speed linear timing')
    if abs(time_coordinate(5.0, 1, travel_ms, soft_ms) - 0.95 * effective_ms) > 1e-9:
        ERRORS.append('up position 5 % must still be reached at full-speed linear timing')

    # 0 ms deaktiviert die Korrektur vollständig und entspricht Version 0.1.15.
    for direction in (1, 2):
        for position in (0.0, 12.5, 50.0, 87.5, 100.0):
            expected_progress = (100.0 - position) / 100.0 if direction == 1 else position / 100.0
            actual = time_coordinate(position, direction, 175500.0, 0.0)
            if abs(actual - expected_progress * 175500.0) > 1e-9:
                ERRORS.append(
                    f'disabled soft-stop must retain linear 0.1.15 timing direction={direction}, position={position}'
                )


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
        "nach bestätigtem STOP bei 100 % ZU",
        "Während der Verzögerung bleiben beide Relais AUS",
    ]
    for pattern in required_module:
        if pattern not in module:
            ERRORS.append(f'{module_path.relative_to(ROOT)}: safety extension missing ({pattern})')

    required_controller = [
        'const J_PHASE_CALIBRATION = 10;',
        "IPS_FunctionExists('LCNJAL_IsRuntimePermitted')",
        "LCNJAL_IsRuntimePermitted($rootID)",
        "J_ConfigInt($rootID, 'Kalibrierfenster_ms')",
        'function J_StartCalibrationWindow',
        'function J_CompleteCalibrationWindow',
        'Kalibrierfenster darf nur bei bestätigten AUS-Relais starten',
        '100 % ZU und beide ausgewählten Relais AUS bestätigt; Kalibrierfenster gestartet',
        'if ($phase === J_PHASE_CALIBRATION)',
        "LCNJAL_LatchFault($rootID, $message)",
        'Symcon-Instanz sofort',
        "Start nicht bestätigt; Spätstart-Schutz ohne Fehlerverriegelung', false",
    ]
    for pattern in required_controller:
        if pattern not in controller:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: calibration/fault logic missing ({pattern})')

    forbidden_controller = [
        'Kalibrierfenster aktiv; neuer Symcon-Fahrbefehl verworfen',
        '100 % ZU erreicht; Zeitverzoegerung/Kalibrierfenster gestartet',
        "SetValueInteger(J_ID($rootID, '04_Istwerte', 'Phase'), J_PHASE_CALIBRATION);\n        SetValueFloat(\n            J_ID($rootID, '05_Intern', 'Zielzeit_ms'),\n            J_NowMs() + J_ConfigInt($rootID, 'Kalibrierfenster_ms')\n        );\n        SetValueBoolean(J_ID($rootID, '04_Istwerte', 'Automatik_Aktiv'), true);\n        J_SetWorker($rootID, true);\n        J_SetLastAction($rootID, '100 % ZU erreicht",
    ]
    for pattern in forbidden_controller:
        if pattern in controller:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: unsafe old calibration behavior remains ({pattern})')

    real_stop_start = controller.find('function J_HandleRealStop')
    calibration_start = controller.find('function J_StartCalibrationWindow')
    if real_stop_start < 0 or calibration_start < 0 or 'J_StartCalibrationWindow($rootID);' not in controller[real_stop_start:calibration_start]:
        ERRORS.append(f'{controller_path.relative_to(ROOT)}: calibration must begin only from confirmed real relay stop handling')

    deadline_start = controller.find('function J_HandleDeadline')
    deadline_end = controller.find('function J_HandleStartTimeout', deadline_start)
    if deadline_start >= 0 and deadline_end > deadline_start:
        deadline_body = controller[deadline_start:deadline_end]
        if 'J_CompleteCalibrationWindow($rootID);' not in deadline_body:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: calibration deadline must complete without a relay toggle')
        calibration_branch = deadline_body.split('if ($phase === J_PHASE_CALIBRATION)', 1)[-1].split('J_UpdatePositionToNow', 1)[0]
        if 'J_SendStopForRealDirection' in calibration_branch:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: calibration deadline must never send another STOP')

    if 'JW_PHASE_CALIBRATION = 10' not in worker or 'JW_PHASE_CALIBRATION' not in worker.split('in_array($phase', 1)[-1]:
        ERRORS.append(f'{worker_path.relative_to(ROOT)}: worker must supervise the calibration phase')

    for pattern in ['moduleEnabled: true', 'faultLatched: false', 'shakeFreeToggleEnabled: false', 'jalState.faultLatched', 'jalState.moduleEnabled']:
        if pattern not in html:
            ERRORS.append(f'{html_path.relative_to(ROOT)}: inactive/fault tile state missing ({pattern})')

    for pattern in [
        "'Kalibrierfenster_ms' => 1",
        "Zeitverzoegerung/Kalibrierfenster_ms muss zwischen 30000 und 120000 ms",
        "'Modul_Aktiv' => 0",
        "'Fehler_Verriegelt' => 0",
        "'Befehlsabstand_ms' => 1",
        "'Stop_Wiederholung_Gesendet' => 0",
        "'Befehl_gesendet_ms' => 2",
    ]:
        if pattern not in diagnose:
            ERRORS.append(f'{diagnose_path.relative_to(ROOT)}: diagnostics missing ({pattern})')


def check_reference_persistence_and_relay_off() -> None:
    module_path = ROOT / 'LCNJalousie' / 'module.php'
    controller_path = ROOT / 'LCNJalousie' / 'scripts' / 'Controller.php'
    worker_path = ROOT / 'LCNJalousie' / 'scripts' / 'Worker.php'
    if not all(path.is_file() for path in [module_path, controller_path, worker_path]):
        return

    module = module_path.read_text(encoding='utf-8')
    controller = controller_path.read_text(encoding='utf-8')
    worker = worker_path.read_text(encoding='utf-8')

    required_module = [
        "RegisterAttributeBoolean('ReferenceValid', false)",
        "RegisterAttributeFloat('ReferencePosition', 0.0)",
        "RegisterAttributeInteger('ReferenceTimestamp', 0)",
        'public function StoreReference(float $position, float $slat',
        'public function InvalidateReference(string $reason',
        'private function migratePersistentReference',
        'private function restorePersistentReference',
        'private function persistReferenceInvalid',
        "'Referenz_Endlage', 'Letzte Referenz-Endlage'",
        "'Letzte_Referenzierung', 'Letzte Referenzierung'",
        "'Letzte_Relais_AUS_Bestaetigung', 'Letzte Bestätigung: beide Relais AUS'",
        "RegisterPropertyInteger('HealthcheckSeconds', 10)",
        "'Healthcheck / unabhängige STOP-Überwachung'",
    ]
    for pattern in required_module:
        if pattern not in module:
            ERRORS.append(f'{module_path.relative_to(ROOT)}: persistent reference storage missing ({pattern})')

    required_controller = [
        'function J_SetReference',
        'function J_InvalidateReference',
        'function J_ReferenceDurationMs',
        "J_ConfigInt($rootID, 'Referenzreserve_ms')",
        'Endlage nach Referenzreserve und bestätigtem Relais-STOP gespeichert',
        'function J_StartCalibrationWindow',
        'function J_MarkRelaysOff',
        'Ablaufabschluss verweigert: mindestens ein Motorrelais ist noch aktiv',
        'function J_RunHealthcheck',
        'Zweite, unabhängige Sicherung neben dem 1-s-Worker',
        "'Shake_Nachlauf_Aktiv'",
        'Lamellen-ZU-Befehl gestoppt und beide Relais AUS bestätigt',
    ]
    for pattern in required_controller:
        if pattern not in controller:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: reference/relay-off safety missing ({pattern})')

    sync_start = controller.find('function J_BeginStatusSync')
    sync_end = controller.find('function J_CompleteStatusSync', sync_start)
    if sync_start >= 0 and sync_end > sync_start:
        sync_body = controller[sync_start:sync_end]
        if "Position_Referenziert'), false" in sync_body:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: status sync must not erase a persistent reference')

    if 'J_RunHealthcheck($rootID);' not in controller:
        ERRORS.append(f'{controller_path.relative_to(ROOT)}: HEALTHCHECK dispatch must call the deadline backup')

    # The 1-s worker remains the primary timer. The periodic healthcheck is the
    # independent fallback and therefore must remain installed as a separate script.
    if 'IPS_SetScriptTimer' not in worker:
        ERRORS.append(f'{worker_path.relative_to(ROOT)}: primary worker timer missing')




def check_relay_binding_and_toggle_safety() -> None:
    module_path = ROOT / 'LCNJalousie' / 'module.php'
    controller_path = ROOT / 'LCNJalousie' / 'scripts' / 'Controller.php'
    if not module_path.is_file() or not controller_path.is_file():
        return

    module = module_path.read_text(encoding='utf-8')
    controller = controller_path.read_text(encoding='utf-8')

    required_module = [
        'private const STATUS_BINDING_CONFLICT = 213;',
        'public function GetHardwareBinding(): string',
        'public function SendDirectionCommand(int $direction, int $expectedRelayState = -1): bool',
        "'LCNJAL_LCN_BUS_SEND'",
        "RegisterPropertyInteger('CommandSpacingMs', 100)",
        "IPS_Sleep($spacingMs)",
        "'unmittelbar vor LCN_SendCommand'",
        'STOP bereits durch reale Relais-AUS-Meldung erfüllt',
        'private function relayCommandStillRequired',
        'private function findBindingConflicts(): array',
        'Motorrelaisvariable #',
        'TS-KURZ ',
        "['Stopstatus_Nachfrage_Aktiv', 'Zusätzliche Stoppstatus-Abfrage aktiv'",
        "'HardwareBinding' => json_decode($this->GetHardwareBinding(), true)",
    ]
    for pattern in required_module:
        if pattern not in module:
            ERRORS.append(f'{module_path.relative_to(ROOT)}: relay binding safety missing ({pattern})')

    required_controller = [
        'function J_HardwareBinding',
        'function J_ConfiguredRelayIDs',
        'function J_IsConfiguredRelayTrigger',
        'Fremde oder veraltete Relaismeldung',
        'LCNJAL_SendDirectionCommand($rootID, $direction, $expectedRelayState)',
        'bereits gesendeter STOP wird nicht wiederholt',
        'Folgeauftrag gespeichert; bereits gesendeter STOP wird nicht wiederholt',
        'if (J_HasPending($rootID))',
        'Startstatus_Nachfrage_Aktiv',
        'Stopstatus_Nachfrage_Aktiv',
        'ausgewähltes Aktormodul erneut abgefragt',
        'Aus Sicherheitsgründen wurde kein zweites Toggle gesendet',
        'Stopstatus_Relais_AUF_Empfangen',
        'Stopstatus_Relais_AB_Empfangen',
        'Stop_Wiederholung_Gesendet',
        'einmalige verifizierte STOP-Wiederholung',
        'J_ArmStartConfirmation',
        'J_ArmStopConfirmation',
        'Startsendung verworfen; Spätstart-Schutz ohne Fehlerverriegelung',
        'Reale LCN-/GT8-Gegenrichtung hat Vorrang',
        'J_SendDirection($rootID, $state, $state)',
        'J_SendDirection($rootID, $direction, J_DIR_NONE)',
    ]
    for pattern in required_controller:
        if pattern not in controller:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: relay/toggle safety missing ({pattern})')

    send_function_start = controller.find('function J_SendDirection')
    send_function_end = controller.find('function J_SendStopForRealDirection', send_function_start)
    if send_function_start >= 0 and send_function_end > send_function_start:
        send_body = controller[send_function_start:send_function_end]
        if "LCN_SendCommand(" in send_body:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: controller must not build/send hardware commands from mirrored runtime variables')

    queue_start = controller.find('function J_QueueAfterStop')
    queue_end = controller.find('function J_BeginCancelGuard', queue_start)
    if queue_start >= 0 and queue_end > queue_start:
        queue_body = controller[queue_start:queue_end]
        stop_guard = queue_body.find("J_IGetBoolean($rootID, 'Stop_Angefordert')")
        stop_send = queue_body.find('J_BeginStopWatch')
        if stop_guard < 0 or stop_send < 0 or stop_guard > stop_send:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: pending command must be stored before any possibility of a second toggle-STOP')

    real_stop_start = controller.find('function J_HandleRealStop')
    real_stop_end = controller.find('function J_StartConfiguredFollowSlatOrFinish', real_stop_start)
    if real_stop_start >= 0 and real_stop_end > real_stop_start:
        real_stop_body = controller[real_stop_start:real_stop_end]
        pending_index = real_stop_body.find('if (J_HasPending($rootID))')
        calibration_index = real_stop_body.find('J_StartCalibrationWindow($rootID);')
        if pending_index < 0 or calibration_index < 0 or pending_index > calibration_index:
            ERRORS.append(f'{controller_path.relative_to(ROOT)}: a quick follow-up command must start after relay-OFF and before calibration')

    # State-machine regression: one STOP has already been emitted. A new target
    # may replace the pending target, but it may not increment the STOP count.
    stop_count = 1
    stop_requested = True
    pending = None
    requested_target = 100
    if stop_requested:
        pending = requested_target
    else:
        stop_count += 1
    if stop_count != 1 or pending != 100:
        ERRORS.append('toggle regression: quick follow-up command repeated the already sent STOP')


def check_restart_validation_lifecycle() -> None:
    module_path = ROOT / 'LCNJalousie' / 'module.php'
    healthcheck_path = ROOT / 'LCNJalousie' / 'scripts' / 'Healthcheck.php'
    if not module_path.is_file() or not healthcheck_path.is_file():
        return

    module = module_path.read_text(encoding='utf-8')
    healthcheck = healthcheck_path.read_text(encoding='utf-8')

    required_module = [
        'private const MESSAGE_KERNEL_STARTED = 10001;',
        'private const MESSAGE_INSTANCE_STATUS_CHANGED = 10505;',
        'private const KERNEL_READY = 10103;',
        'private const STARTUP_VALIDATION_GRACE_SECONDS = 30;',
        'public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void',
        '$this->RegisterMessage(0, self::MESSAGE_KERNEL_STARTED);',
        '$this->RegisterMessage($instanceID, self::MESSAGE_INSTANCE_STATUS_CHANGED);',
        '$staticValidation = $this->validateConfiguration(false, false);',
        '? $this->validateConfiguration(true, true)',
        '$runtimeValidation = $this->validateConfiguration(false, true);',
        '$this->isRuntimeDependencyUnavailable(',
        '$this->kernelIsWithinStartupGrace()',
        "self::STATUS_SEND_MODULE_MISSING,",
        "self::STATUS_ACTOR_MODULE_MISSING,",
        "self::STATUS_LCN_FUNCTION_MISSING,",
        "$this->SetSummary('bereit · Startprüfung');",
        "$this->SetSummary('bereit · LCN nicht verfügbar');",
        "$startupValidationPending = (int) $this->GetBuffer('StartupValidationDeadline') > time();",
        "Ergebnis: gespeicherte Konfiguration vollständig.",
        "$validation = $this->validateConfiguration(false, false);",
        "&& $this->ReadAttributeBoolean('RuntimeEnabled')",
        "IPS_RunScriptWaitEx((int) $controllerID, ['ACTION' => 'INITIALIZE']);",
        "max(1, $this->ReadPropertyInteger('HealthcheckSeconds'))",
    ]
    for pattern in required_module:
        if pattern not in module:
            ERRORS.append(f'{module_path.relative_to(ROOT)}: restart lifecycle protection missing ({pattern})')

    forbidden_runtime_error_status = "elseif ($runtimeDependencyUnavailable) {\n                $this->SetStatus($runtimeValidation['status']);"
    if forbidden_runtime_error_status in module:
        ERRORS.append(
            f'{module_path.relative_to(ROOT)}: temporary LCN runtime loss must not be reported as incomplete saved configuration'
        )

    apply_start = module.find('public function ApplyChanges')
    apply_end = module.find('public function MessageSink', apply_start)
    if apply_start >= 0 and apply_end > apply_start:
        apply_body = module[apply_start:apply_end]
        if 'invalidateReferenceWithoutCommand' in apply_body:
            ERRORS.append(
                f'{module_path.relative_to(ROOT)}: ApplyChanges/runtime state changes must not erase a persistent reference'
            )
        for required in [
            '$this->clearCommandLeaseState();',
            "$this->WriteAttributeString('ForeignRelayResponse', '');",
        ]:
            if required not in apply_body:
                ERRORS.append(
                    f'{module_path.relative_to(ROOT)}: ApplyChanges must discard stale cross-instance command transactions ({required})'
                )

    startup_start = module.find('public function CompleteStartupValidation')
    startup_end = module.find('public function GetConfigurationForm', startup_start)
    if startup_start >= 0 and startup_end > startup_start:
        startup_body = module[startup_start:startup_end]
        if 'invalidateReferenceWithoutCommand' in startup_body or 'persistReferenceInvalid' in startup_body:
            ERRORS.append(
                f'{module_path.relative_to(ROOT)}: transient restart validation must not erase reference data'
            )
        if 'IPS_SetProperty' in startup_body or 'WriteProperty' in startup_body:
            ERRORS.append(
                f'{module_path.relative_to(ROOT)}: restart validation must not alter saved module properties'
            )

    required_healthcheck = [
        "IPS_FunctionExists('LCNJAL_CompleteStartupValidation')",
        'LCNJAL_CompleteStartupValidation($rootID);',
        "IPS_RunScriptWaitEx($controllerID, ['ACTION' => 'HEALTHCHECK']);",
        'Der Healthcheck läuft auch bei einer Fehlerverriegelung weiter',
    ]
    for pattern in required_healthcheck:
        if pattern not in healthcheck:
            ERRORS.append(
                f'{healthcheck_path.relative_to(ROOT)}: automatic restart revalidation missing ({pattern})'
            )

    if healthcheck.find('LCNJAL_CompleteStartupValidation($rootID);') > healthcheck.find("IPS_RunScriptWaitEx($controllerID, ['ACTION' => 'HEALTHCHECK']);"):
        ERRORS.append(
            f'{healthcheck_path.relative_to(ROOT)}: startup validation must run before the controller healthcheck'
        )



def check_visualization_transport_resilience() -> None:
    html_path = ROOT / 'LCNJalousie' / 'module.html'
    if not html_path.is_file():
        return
    html = html_path.read_text(encoding='utf-8')
    required = [
        'let jalActionInFlight = false;',
        'const JAL_ACTION_TIMEOUT_MS = 8000;',
        'const JAL_ACTION_GUARD_MS = 220;',
        'const JAL_QUEUE_DELAY_MS = 75;',
        'function isTransientApiError(error)',
        "text.includes('failed to fetch')",
        "text.includes('uri=/api/')",
        "window.addEventListener('unhandledrejection'",
        "window.addEventListener('offline'",
        'event.preventDefault();',
        "typeof result.then === 'function'",
        'Der Befehl wird nicht automatisch wiederholt.',
        "navigator.onLine === false",
        'jalActionInFlight || jalQueuedCommand || jalQueueTimer',
        'queueLatestJalousieAction',
        'scheduleQueuedCommand',
        'sendImmediateStop',
        'failVisualizationTransport',
        '/*__LCNJAL_INITIAL_STATE__*/',
    ]
    for pattern in required:
        if pattern not in html:
            ERRORS.append(
                f'{html_path.relative_to(ROOT)}: visualization transport protection missing ({pattern})'
            )

    forbidden = [
        'setTimeout(() => requestAction(',
        'setInterval(() => requestAction(',
        'fetch("/api/',
        "fetch('/api/",
    ]
    for pattern in forbidden:
        if pattern in html:
            ERRORS.append(
                f'{html_path.relative_to(ROOT)}: unsafe automatic API retry/direct API use present ({pattern})'
            )

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
    check_visualization_transport_resilience()
    check_javascript()
    check_measured_blind_start_model()
    check_timing_examples()
    check_soft_stop_model()
    check_calibration_window_and_fault_latch()
    check_reference_persistence_and_relay_off()
    check_relay_binding_and_toggle_safety()
    check_restart_validation_lifecycle()
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
