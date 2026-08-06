<?php

declare(strict_types=1);

$GLOBALS['relayValues'] = [101 => false, 102 => false];

class IPSModuleStrict
{
    public int $InstanceID = 1;
    protected array $testProperties = [
        'RelayUpVariableID' => 101,
        'RelayDownVariableID' => 102,
    ];
    protected array $testAttributes = [
        'FaultLatched' => true,
        'FaultMessage' => 'Keine reale Relaisbestaetigung innerhalb der eingestellten Zeit.',
        'ReferenceValid' => true,
        'ReferencePosition' => 100.0,
        'ReferenceSlat' => 100.0,
        'ReferenceTimestamp' => 123456,
        'ReferenceReason' => 'gültige Endlage',
    ];

    protected function ReadPropertyInteger(string $name): int
    {
        return (int) ($this->testProperties[$name] ?? 0);
    }

    protected function ReadAttributeBoolean(string $name): bool
    {
        return (bool) ($this->testAttributes[$name] ?? false);
    }

    protected function ReadAttributeString(string $name): string
    {
        return (string) ($this->testAttributes[$name] ?? '');
    }

    protected function WriteAttributeBoolean(string $name, bool $value): void
    {
        $this->testAttributes[$name] = $value;
    }

    protected function WriteAttributeString(string $name, string $value): void
    {
        $this->testAttributes[$name] = $value;
    }

    public function snapshot(): array
    {
        return $this->testAttributes;
    }

    public function setAttributeForTest(string $name, mixed $value): void
    {
        $this->testAttributes[$name] = $value;
    }
}

function IPS_VariableExists(int $id): bool
{
    return array_key_exists($id, $GLOBALS['relayValues']);
}

function IPS_GetVariable(int $id): array
{
    return ['VariableType' => 0];
}

function GetValueBoolean(int $id): bool
{
    return (bool) ($GLOBALS['relayValues'][$id] ?? false);
}

function IPS_GetObjectIDByIdent(string $ident, int $parent): int|false
{
    return false;
}

require_once __DIR__ . '/../LCNJalousie/module.php';

$method = new ReflectionMethod(LCNJalousie::class, 'migrateLegacyStartConfirmationFault');
$method->setAccessible(true);

$module = new LCNJalousie();
$beforeReference = array_intersect_key(
    $module->snapshot(),
    array_flip(['ReferenceValid', 'ReferencePosition', 'ReferenceSlat', 'ReferenceTimestamp', 'ReferenceReason'])
);
$method->invoke($module, '0.1.23');
$after = $module->snapshot();
if (($after['FaultLatched'] ?? true) !== false || ($after['FaultMessage'] ?? 'x') !== '') {
    throw new RuntimeException('Legacy benign start-confirmation fault was not cleared.');
}
$afterReference = array_intersect_key($after, $beforeReference);
if ($afterReference !== $beforeReference) {
    throw new RuntimeException('Legacy start-fault migration changed a valid reference.');
}

$critical = new LCNJalousie();
$critical->setAttributeForTest('FaultMessage', 'Motorrelais blieb nach STOP aktiv.');
$method->invoke($critical, '0.1.23');
if (($critical->snapshot()['FaultLatched'] ?? false) !== true) {
    throw new RuntimeException('Critical fault was incorrectly cleared.');
}

$activeRelay = new LCNJalousie();
$GLOBALS['relayValues'][101] = true;
$method->invoke($activeRelay, '0.1.23');
if (($activeRelay->snapshot()['FaultLatched'] ?? false) !== true) {
    throw new RuntimeException('Legacy start fault was cleared while a relay was active.');
}

echo "START CONFIRMATION MIGRATION TEST OK\n";
