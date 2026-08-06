#!/usr/bin/env python3
from __future__ import annotations

from dataclasses import dataclass
import random

UP = 1
DOWN = 2


@dataclass
class Blind:
    referenced: bool = True
    relay: int = 0
    phase: str = 'idle'
    automatic: bool = False
    external_endpoint_seen: bool = False
    external_autostop_armed: bool = False
    foreign_owner: str | None = None


class Plant:
    def __init__(self, names: list[str]) -> None:
        self.blinds = {name: Blind() for name in names}
        self.lease_owner: str | None = None
        self.lease_direction = 0
        self.rejected_visual = 0
        self.simultaneous_peak = 0

    def symcon_start(self, name: str, direction: int) -> bool:
        blind = self.blinds[name]
        if blind.phase == 'external' or (blind.relay in (UP, DOWN) and not blind.automatic):
            self.rejected_visual += 1
            return False
        if self.lease_owner is not None:
            return False
        self.lease_owner = name
        self.lease_direction = direction
        blind.phase = 'wait_start'
        blind.automatic = True
        blind.external_endpoint_seen = False
        blind.external_autostop_armed = False
        return True

    def confirm_own_start(self) -> bool:
        if self.lease_owner is None:
            return False
        blind = self.blinds[self.lease_owner]
        blind.relay = self.lease_direction
        blind.phase = 'automatic'
        blind.automatic = True
        blind.external_endpoint_seen = False
        blind.external_autostop_armed = False
        self.lease_owner = None
        self.lease_direction = 0
        self._update_peak()
        return True

    def confirm_misrouted_start(self, receiver: str, direction: int) -> bool:
        if self.lease_owner is None or receiver == self.lease_owner:
            return False
        blind = self.blinds[receiver]
        blind.relay = direction
        blind.phase = 'external'
        blind.automatic = False
        blind.foreign_owner = self.lease_owner
        # Sender stays unresolved/faults after its own confirmation timeout.
        self._update_peak()
        return True

    def external_start(self, name: str, direction: int) -> None:
        blind = self.blinds[name]
        blind.relay = direction
        blind.phase = 'external'
        blind.automatic = False
        blind.external_endpoint_seen = False
        blind.external_autostop_armed = False
        self._update_peak()

    def endpoint(self, name: str) -> None:
        blind = self.blinds[name]
        if blind.phase != 'external' or blind.relay not in (UP, DOWN):
            return
        blind.referenced = True
        blind.external_endpoint_seen = True
        blind.external_autostop_armed = True

    def calibration_expired(self, name: str) -> None:
        blind = self.blinds[name]
        if blind.phase == 'external' and blind.external_endpoint_seen and blind.external_autostop_armed:
            blind.phase = 'stopping'
            blind.external_autostop_armed = False
            blind.relay = 0
            blind.phase = 'idle'
            blind.automatic = False

    def stop(self, name: str) -> None:
        blind = self.blinds[name]
        blind.relay = 0
        blind.phase = 'idle'
        blind.automatic = False
        blind.external_endpoint_seen = False
        blind.external_autostop_armed = False

    def uncertain_motion(self, name: str) -> None:
        self.blinds[name].referenced = False

    def _update_peak(self) -> None:
        moving = sum(1 for blind in self.blinds.values() if blind.relay in (UP, DOWN))
        self.simultaneous_peak = max(self.simultaneous_peak, moving)

    def assert_invariants(self) -> None:
        assert self.lease_owner is None or self.lease_owner in self.blinds
        for blind in self.blinds.values():
            assert blind.relay in (0, UP, DOWN)
            if blind.phase == 'external':
                assert not blind.automatic
            if blind.external_autostop_armed:
                assert blind.phase == 'external'
                assert blind.external_endpoint_seen
                assert blind.relay in (UP, DOWN)


# Explicit interaction matrix.
plant = Plant(['Wohnen', 'Buero', 'Schlafen'])
assert plant.symcon_start('Wohnen', DOWN)
assert not plant.symcon_start('Buero', DOWN)  # unconfirmed toggle serialized
assert plant.confirm_own_start()
assert plant.symcon_start('Buero', UP)
assert plant.confirm_own_start()
assert plant.simultaneous_peak == 2  # confirmed motors may run together
assert plant.blinds['Wohnen'].referenced and plant.blinds['Buero'].referenced

plant.external_start('Schlafen', DOWN)
assert not plant.symcon_start('Schlafen', UP)  # LCN/GT8 owns the movement
assert plant.blinds['Schlafen'].referenced
plant.endpoint('Schlafen')
assert plant.blinds['Schlafen'].relay == DOWN
plant.calibration_expired('Schlafen')
assert plant.blinds['Schlafen'].relay == 0 and plant.blinds['Schlafen'].referenced

# Unknown external position stays unknown until the safe endpoint, then becomes
# referenced and is stopped only after the calibration window.
plant.blinds['Schlafen'].referenced = False
plant.external_start('Schlafen', UP)
assert not plant.blinds['Schlafen'].referenced
plant.endpoint('Schlafen')
assert plant.blinds['Schlafen'].referenced and plant.blinds['Schlafen'].relay == UP
plant.calibration_expired('Schlafen')
assert plant.blinds['Schlafen'].relay == 0

# A command/relay mismatch is attributed to the one active sender; the receiver
# is treated as external and therefore remains supervised through endpoint stop.
plant.stop('Wohnen')
plant.stop('Buero')
assert plant.symcon_start('Buero', DOWN)
assert plant.confirm_misrouted_start('Wohnen', DOWN)
assert plant.blinds['Wohnen'].foreign_owner == 'Buero'
assert plant.blinds['Wohnen'].phase == 'external'
plant.endpoint('Wohnen')
plant.calibration_expired('Wohnen')
assert plant.blinds['Wohnen'].relay == 0
plant.lease_owner = None  # sender timeout/fault completed
plant.lease_direction = 0

# Randomized combined operation sequences. Reference may only be cleared by the
# explicit uncertainty event; normal starts, stops, endpoint updates and visual
# rejection must never clear it.
rng = random.Random(230123)
plant = Plant(['A', 'B', 'C', 'D'])
operations = ['symcon', 'confirm', 'misroute', 'external', 'endpoint', 'calibration', 'stop', 'uncertain']
for _ in range(100_000):
    name = rng.choice(list(plant.blinds))
    direction = rng.choice([UP, DOWN])
    op = rng.choice(operations)
    before = {n: b.referenced for n, b in plant.blinds.items()}
    if op == 'symcon':
        plant.symcon_start(name, direction)
    elif op == 'confirm':
        plant.confirm_own_start()
    elif op == 'misroute':
        plant.confirm_misrouted_start(name, direction)
    elif op == 'external':
        plant.external_start(name, direction)
    elif op == 'endpoint':
        plant.endpoint(name)
    elif op == 'calibration':
        plant.calibration_expired(name)
    elif op == 'stop':
        plant.stop(name)
    else:
        plant.uncertain_motion(name)
    plant.assert_invariants()
    for n, was_valid in before.items():
        if was_valid and not plant.blinds[n].referenced:
            assert op == 'uncertain' and n == name

print('INTERACTION MATRIX TEST OK (4 explicit scenarios, 100000 randomized operations)')
