<?php
declare(strict_types=1);

$GLOBALS['kernelRunlevel'] = 10103;
$GLOBALS['kernelStartTime'] = time();
$GLOBALS['instanceStatus'] = [
    10 => 104, // send module starts late
    11 => 104, // actor starts late
    12 => 104, // GT8 source starts late
    21 => 102,
    22 => 102,
    23 => 102,
    24 => 102,
];
$GLOBALS['functions'] = ['LCN_SendCommand' => false, 'LCN_RequestStatus' => false];
$GLOBALS['scriptTimers'] = [];
$GLOBALS['eventActive'] = [];
$GLOBALS['scriptRuns'] = [];
$GLOBALS['parents'] = [101 => 21, 102 => 22, 103 => 23, 104 => 24];
$GLOBALS['connections'] = [21 => 11, 22 => 11, 23 => 12, 24 => 12, 10 => 0, 11 => 0, 12 => 0];
$GLOBALS['objectIdents'] = [
    '1:06_Skripte' => 600,
    '600:Controller' => 601,
    '600:Worker' => 602,
    '600:Healthcheck' => 603,
    '601:Evt_Relais_AUF' => 611,
    '601:Evt_Relais_AB' => 612,
    '601:Evt_GT8_LANG_AUF' => 613,
    '601:Evt_GT8_LANG_AB' => 614,
];

class IPSModuleStrict
{
    public int $InstanceID;
    public array $properties = [];
    public array $attributes = [];
    public array $buffers = [];
    public int $status = 102;
    public string $summary = '';
    public array $messages = [];

    public function __construct(int $id = 1) { $this->InstanceID = $id; }
    public function ReadPropertyInteger(string $name): int { return (int)($this->properties[$name] ?? 0); }
    public function ReadPropertyBoolean(string $name): bool { return (bool)($this->properties[$name] ?? false); }
    public function ReadPropertyFloat(string $name): float { return (float)($this->properties[$name] ?? 0.0); }
    public function ReadPropertyString(string $name): string { return (string)($this->properties[$name] ?? ''); }
    public function ReadAttributeBoolean(string $name): bool { return (bool)($this->attributes[$name] ?? false); }
    public function ReadAttributeString(string $name): string { return (string)($this->attributes[$name] ?? ''); }
    public function ReadAttributeFloat(string $name): float { return (float)($this->attributes[$name] ?? 0.0); }
    public function ReadAttributeInteger(string $name): int { return (int)($this->attributes[$name] ?? 0); }
    public function WriteAttributeBoolean(string $name, bool $value): void { $this->attributes[$name] = $value; }
    public function WriteAttributeString(string $name, string $value): void { $this->attributes[$name] = $value; }
    public function WriteAttributeFloat(string $name, float $value): void { $this->attributes[$name] = $value; }
    public function WriteAttributeInteger(string $name, int $value): void { $this->attributes[$name] = $value; }
    public function SetBuffer(string $name, string $value): void { $this->buffers[$name] = $value; }
    public function GetBuffer(string $name): string { return (string)($this->buffers[$name] ?? ''); }
    public function SetStatus(int $status): void { $this->status = $status; }
    public function GetStatus(): int { return $this->status; }
    public function SetSummary(string $summary): void { $this->summary = $summary; }
    public function SendDebug(string $message, mixed $data, int $format): void {}
    public function LogMessage(string $message, int $type): bool { return true; }
    public function RegisterMessage(int $sender, int $message): bool { $this->messages[] = [$sender, $message]; return true; }
    public function SetValue(string $ident, mixed $value): void {}
    public function UpdateVisualizationValue(string $value): void {}
}

function IPS_GetKernelRunlevel(): int { return $GLOBALS['kernelRunlevel']; }
function IPS_GetKernelStartTime(): int { return $GLOBALS['kernelStartTime']; }
function IPS_GetObjectIDByIdent(string $ident, int $parent): int|false { return $GLOBALS['objectIdents']["$parent:$ident"] ?? false; }
function IPS_InstanceExists(int $id): bool { return array_key_exists($id, $GLOBALS['instanceStatus']); }
function IPS_GetInstance(int $id): array {
    $isLcn = in_array($id, [10,11,12], true);
    return [
        'InstanceStatus' => $GLOBALS['instanceStatus'][$id] ?? 0,
        'ConnectionID' => $GLOBALS['connections'][$id] ?? 0,
        'ModuleInfo' => ['ModuleName' => $isLcn ? 'LCN Modul' : 'LCN Relay', 'ModuleType' => 2],
    ];
}
function IPS_VariableExists(int $id): bool { return in_array($id, [101,102,103,104], true); }
function IPS_GetVariable(int $id): array { return ['VariableType' => 0]; }
function IPS_GetParent(int $id): int { return $GLOBALS['parents'][$id] ?? 0; }
function IPS_FunctionExists(string $name): bool { return (bool)($GLOBALS['functions'][$name] ?? false); }
function IPS_ScriptExists(int $id): bool { return in_array($id, [601,602,603], true); }
function IPS_EventExists(int $id): bool { return in_array($id, [611,612,613,614], true); }
function IPS_SetEventActive(int $id, bool $active): void { $GLOBALS['eventActive'][$id] = $active; }
function IPS_SetScriptTimer(int $id, int $seconds): void { $GLOBALS['scriptTimers'][$id] = $seconds; }
function IPS_RunScriptWaitEx(int $id, array $params): void { $GLOBALS['scriptRuns'][] = [$id, $params]; }
function GetValueBoolean(int $id): bool { return false; }
function GetValueFloat(int $id): float { return 0.0; }
function GetValueInteger(int $id): int { return 0; }
function GetValueString(int $id): string { return ''; }
function IPS_GetName(int $id): string { return 'Test'; }
function IPS_LogMessage(string $sender, string $message): void {}

require dirname(__DIR__) . '/LCNJalousie/module.php';

function assertSameValue(mixed $expected, mixed $actual, string $label): void {
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function assertContainsText(string $needle, string $haystack, string $label): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($label . ': missing ' . var_export($needle, true));
    }
}

function assertNotContainsText(string $needle, string $haystack, string $label): void {
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($label . ': unexpectedly contains ' . var_export($needle, true));
    }
}

$m = new LCNJalousie(1);
$m->properties = [
    'ModuleEnabled' => true,
    'LCNSendModuleID' => 10,
    'LCNActorModuleID' => 11,
    'RelayUpVariableID' => 101,
    'RelayDownVariableID' => 102,
    'GT8LongUpVariableID' => 103,
    'GT8LongDownVariableID' => 104,
    'TSShortUp' => 'K---00010000',
    'TSShortDown' => 'K---00000100',
    'TSMappingConfirmed' => true,
    'TotalTravelMs' => 182000,
    'BlindTravelMs' => 175500,
    'TurnMs' => 6500,
    'SoftStartMs' => 6000,
    'SoftStopUpMs' => 4500,
    'SoftStopDownMs' => 4500,
    'ReferenceReserveMs' => 5000,
    'MaxTravelMs' => 187000,
    'ShakeFreeMs' => 6500,
    'WorkerWindowMs' => 1500,
    'ShakeFreePauseMs' => 500,
    'CalibrationWindowMs' => 30000,
    'RelayConfirmMs' => 2500,
    'StopConfirmMs' => 3000,
    'LateStartGuardMs' => 5000,
    'StatusSyncMs' => 1500,
    'RelayCoalesceMs' => 100,
    'CommandSpacingMs' => 100,
    'RequestStatusOnStart' => true,
    'HealthcheckSeconds' => 10,
];
$m->attributes = [
    'FaultLatched' => false,
    'RuntimeEnabled' => false,
    'ReferenceValid' => true,
    'ReferencePosition' => 100.0,
    'ReferenceSlat' => 100.0,
    'ReferenceTimestamp' => 123456,
    'ReferenceReason' => 'Testreferenz',
];
$m->SetBuffer('StartupValidationDeadline', (string)(time() + 30));
$propertiesBefore = $m->properties;
$referenceBefore = array_intersect_key($m->attributes, array_flip(['ReferenceValid','ReferencePosition','ReferenceSlat','ReferenceTimestamp','ReferenceReason']));

// The saved configuration must already be reported as complete while LCN is still starting.
$configurationCheck = $m->CheckConfiguration();
assertContainsText('gespeicherte Konfiguration vollständig', $configurationCheck, 'static configuration check');
assertContainsText('LCN momentan noch nicht betriebsbereit', $configurationCheck, 'runtime note in configuration check');
assertNotContainsText('Ergebnis: nicht vollständig', $configurationCheck, 'no false incomplete configuration result');
$form = json_decode($m->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
assertContainsText('gespeicherte konfiguration ist vollständig', strtolower((string)($form['actions'][0]['caption'] ?? '')), 'configuration form remains complete');

// 1) LCN modules still starting: configuration remains active, control blocked.
$m->CompleteStartupValidation();
assertSameValue(102, $m->status, 'startup status');
assertSameValue('bereit · Startprüfung', $m->summary, 'startup summary');
assertSameValue(false, $m->attributes['RuntimeEnabled'], 'startup runtime lock');
assertSameValue(1, $GLOBALS['scriptTimers'][603] ?? null, 'startup retry timer');
assertSameValue([], $GLOBALS['scriptRuns'], 'no premature initialization');
assertSameValue($propertiesBefore, $m->properties, 'properties unchanged during startup');
assertSameValue($referenceBefore, array_intersect_key($m->attributes, $referenceBefore), 'reference unchanged during startup');

// 2) LCN is ready: automatic release and one initialization without saving.
$GLOBALS['instanceStatus'][10] = 102;
$GLOBALS['instanceStatus'][11] = 102;
$GLOBALS['instanceStatus'][12] = 102;
$GLOBALS['functions']['LCN_SendCommand'] = true;
$GLOBALS['functions']['LCN_RequestStatus'] = true;
$m->CompleteStartupValidation();
assertSameValue(102, $m->status, 'ready status');
assertSameValue(true, $m->attributes['RuntimeEnabled'], 'automatic runtime release');
assertSameValue(10, $GLOBALS['scriptTimers'][603] ?? null, 'normal health timer');
assertSameValue(1, count($GLOBALS['scriptRuns']), 'single initialization after ready');
assertSameValue($propertiesBefore, $m->properties, 'properties unchanged after release');
assertSameValue($referenceBefore, array_intersect_key($m->attributes, $referenceBefore), 'reference unchanged after release');

// 3) Later temporary loss: error is detected, automatic retry remains active.
$GLOBALS['instanceStatus'][10] = 104;
$m->MessageSink(1, 10, 10505, []);
assertSameValue(102, $m->status, 'dependency loss keeps configuration active');
assertSameValue('bereit · LCN nicht verfügbar', $m->summary, 'dependency loss runtime summary');
assertSameValue(false, $m->attributes['RuntimeEnabled'], 'dependency loss blocks runtime');
assertSameValue(10, $GLOBALS['scriptTimers'][603] ?? null, 'dependency retry remains enabled');
assertSameValue($propertiesBefore, $m->properties, 'properties unchanged after dependency loss');
assertSameValue($referenceBefore, array_intersect_key($m->attributes, $referenceBefore), 'reference unchanged after dependency loss');

// 4) Dependency returns: instance recovers automatically, no configuration save.
$GLOBALS['instanceStatus'][10] = 102;
$m->MessageSink(2, 10, 10505, []);
assertSameValue(102, $m->status, 'automatic recovery status');
assertSameValue(true, $m->attributes['RuntimeEnabled'], 'automatic recovery runtime');
assertSameValue(2, count($GLOBALS['scriptRuns']), 'initialization after automatic recovery');
assertSameValue($propertiesBefore, $m->properties, 'properties unchanged after recovery');
assertSameValue($referenceBefore, array_intersect_key($m->attributes, $referenceBefore), 'reference unchanged after recovery');

echo "RESTART LIFECYCLE TEST OK\n";
