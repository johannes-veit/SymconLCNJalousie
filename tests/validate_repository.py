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

def main() -> int:
    check_required()
    check_root_structure()
    for path in ROOT.rglob('*.json'):
        check_json(path)
    check_metadata()
    check_configurator_configuration_object()
    check_lcn_connection_chain_validation()
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
