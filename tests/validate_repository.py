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
        'modules/LCNJalousie/module.json',
        'modules/LCNJalousie/module.php',
        'modules/LCNJalousie/scripts/Controller.php',
        'modules/LCNJalousie/scripts/Worker.php',
        'modules/LCNJalousie/scripts/Healthcheck.php',
        'modules/LCNJalousie/scripts/Diagnose.php',
        'modules/LCNJalousieConfigurator/module.json',
        'modules/LCNJalousieConfigurator/module.php',
    ]
    for rel in required:
        if not (ROOT / rel).is_file():
            ERRORS.append(f'missing file: {rel}')


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


def check_metadata() -> None:
    library = json.loads((ROOT / 'library.json').read_text(encoding='utf-8'))
    if library.get('compatibility', {}).get('version') != '9.0':
        ERRORS.append('library.json: compatibility.version must be 9.0')
    module_ids: set[str] = set()
    for path in ROOT.glob('modules/*/module.json'):
        data = json.loads(path.read_text(encoding='utf-8'))
        module_id = data.get('id', '')
        if module_id in module_ids:
            ERRORS.append(f'duplicate module GUID: {module_id}')
        module_ids.add(module_id)
        if not data.get('prefix', '').isalnum():
            ERRORS.append(f'{path.relative_to(ROOT)}: prefix must be alphanumeric')


def main() -> int:
    check_required()
    for path in ROOT.rglob('*.json'):
        check_json(path)
    check_metadata()
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
