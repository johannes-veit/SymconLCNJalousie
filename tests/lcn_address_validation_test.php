<?php

declare(strict_types=1);

$GLOBALS['TEST_INSTANCES'] = [
    37617 => [
        'name' => 'EG Büro Säule (000,011)',
        'instance' => [
            'ConnectionID' => 54562,
            'InstanceStatus' => 102,
            'ModuleInfo' => ['ModuleName' => 'LCN Module', 'ModuleType' => 2],
        ],
        'configuration' => ['Segment' => 0, 'Target' => 22],
    ],
    36661 => [
        'name' => 'EG Küche Abstellraum (000,022)',
        'instance' => [
            'ConnectionID' => 54562,
            'InstanceStatus' => 102,
            'ModuleInfo' => ['ModuleName' => 'LCN Module', 'ModuleType' => 2],
        ],
        'configuration' => ['Segment' => 0, 'Target' => 22],
    ],
    34523 => [
        'name' => 'EG UV Kamin Fl2 (000,094)',
        'instance' => [
            'ConnectionID' => 54562,
            'InstanceStatus' => 102,
            'ModuleInfo' => ['ModuleName' => 'LCN Module', 'ModuleType' => 2],
        ],
        'configuration' => ['Segment' => 0, 'Target' => 94],
    ],
];

function IPS_InstanceExists(int $instanceID): bool
{
    return isset($GLOBALS['TEST_INSTANCES'][$instanceID]);
}

function IPS_GetInstance(int $instanceID): array
{
    return $GLOBALS['TEST_INSTANCES'][$instanceID]['instance'];
}

function IPS_GetConfiguration(int $instanceID): string
{
    return json_encode($GLOBALS['TEST_INSTANCES'][$instanceID]['configuration'], JSON_THROW_ON_ERROR);
}

function IPS_GetName(int $instanceID): string
{
    return $GLOBALS['TEST_INSTANCES'][$instanceID]['name'];
}

function IPS_FunctionExists(string $name): bool
{
    return function_exists($name);
}

function IPS_GetInstanceListByModuleID(string $moduleID): array
{
    return array_keys($GLOBALS['TEST_INSTANCES']);
}

class IPSModuleStrict
{
    protected array $testProperties = [
        'LCNSendModuleID' => 37617,
        'LCNActorModuleID' => 34523,
        'RelayUpVariableID' => 52992,
        'RelayDownVariableID' => 36063,
        'TSShortUp' => 'K---00010000',
        'TSShortDown' => 'K---00000100',
    ];

    protected function ReadPropertyInteger(string $name): int
    {
        return (int) ($this->testProperties[$name] ?? 0);
    }

    protected function ReadPropertyString(string $name): string
    {
        return (string) ($this->testProperties[$name] ?? '');
    }
}

require_once __DIR__ . '/../LCNJalousie/module.php';

$module = new LCNJalousie();
$resolve = new ReflectionMethod(LCNJalousie::class, 'resolveLcnModuleAddress');
$resolve->setAccessible(true);
$fingerprint = new ReflectionMethod(LCNJalousie::class, 'currentRoutingFingerprint');
$fingerprint->setAccessible(true);

$address = $resolve->invoke($module, 37617);
if (($address['address'] ?? '') !== '000,022') {
    throw new RuntimeException('Actual Segment/Target address was not resolved.');
}
if (($address['nameMatchesAddress'] ?? true) !== false) {
    throw new RuntimeException('Name/address mismatch was not detected.');
}
if (($address['duplicateInstanceIDs'] ?? []) !== [36661]) {
    throw new RuntimeException('Duplicate physical LCN module address was not detected.');
}

$actor = $resolve->invoke($module, 34523);
if (($actor['nameMatchesAddress'] ?? false) !== true || ($actor['address'] ?? '') !== '000,094') {
    throw new RuntimeException('Matching actor address was rejected.');
}

$before = $fingerprint->invoke($module);
$GLOBALS['TEST_INSTANCES'][37617]['configuration']['Target'] = 11;
$after = $fingerprint->invoke($module);
if ($before === $after) {
    throw new RuntimeException('Routing fingerprint did not change after actual Target correction.');
}
$corrected = $resolve->invoke($module, 37617);
if (($corrected['nameMatchesAddress'] ?? false) !== true || ($corrected['duplicateInstanceIDs'] ?? []) !== []) {
    throw new RuntimeException('Corrected physical LCN address did not clear mismatch/duplicate state.');
}

echo "LCN ADDRESS VALIDATION TEST OK\n";
