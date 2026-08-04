<?php

declare(strict_types=1);

/**
 * LCN Jalousie – Symcon 9.0 compatibility module.
 *
 * This module creates and maintains the V11.3 object tree, runtime scripts,
 * events, links and configuration values below one module instance.
 * The motor interlock and local operation remain in LCN-PRO.
 */
class LCNJalousie extends IPSModuleStrict
{
    private const VERSION = '0.1.5';
    private const EXECUTE_PARENT_ACTION = '{7938A5A2-0981-5FE0-BE6C-8AA610D654EB}';

    private const STATUS_ACTIVE = 102;
    private const STATUS_SEND_MODULE_MISSING = 201;
    private const STATUS_ACTOR_MODULE_MISSING = 202;
    private const STATUS_RELAY_UP_INVALID = 203;
    private const STATUS_RELAY_DOWN_INVALID = 204;
    private const STATUS_GT8_INVALID = 205;
    private const STATUS_DUPLICATE_OBJECTS = 206;
    private const STATUS_TS_INVALID = 207;
    private const STATUS_TIMING_INVALID = 208;
    private const STATUS_LCN_FUNCTION_MISSING = 209;
    private const STATUS_STRUCTURE_ERROR = 210;
    private const STATUS_RELAY_CONFLICT = 211;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('ProjectName', 'Jalousie Wohnzimmer');
        $this->RegisterPropertyInteger('LCNSendModuleID', 0);
        $this->RegisterPropertyInteger('LCNActorModuleID', 0);
        $this->RegisterPropertyInteger('RelayUpVariableID', 0);
        $this->RegisterPropertyInteger('RelayDownVariableID', 0);
        $this->RegisterPropertyInteger('GT8LongUpVariableID', 0);
        $this->RegisterPropertyInteger('GT8LongDownVariableID', 0);
        $this->RegisterPropertyString('TSShortUp', 'K---00010000');
        $this->RegisterPropertyString('TSShortDown', 'K---00000100');
        $this->RegisterPropertyBoolean('TSMappingConfirmed', false);

        $this->RegisterPropertyInteger('TotalTravelMs', 182000);
        $this->RegisterPropertyInteger('TurnMs', 6500);
        $this->RegisterPropertyInteger('BlindTravelMs', 175500);
        $this->RegisterPropertyInteger('ReferenceReserveMs', 5000);
        $this->RegisterPropertyInteger('MaxTravelMs', 187000);
        $this->RegisterPropertyInteger('ShakeFreeMs', 6500);
        $this->RegisterPropertyInteger('RelayConfirmMs', 2500);
        $this->RegisterPropertyInteger('StopConfirmMs', 3000);
        $this->RegisterPropertyInteger('LateStartGuardMs', 5000);
        $this->RegisterPropertyInteger('WorkerWindowMs', 1500);
        $this->RegisterPropertyInteger('StatusSyncMs', 1500);
        $this->RegisterPropertyInteger('RelayCoalesceMs', 100);
        $this->RegisterPropertyInteger('HealthcheckSeconds', 30);

        $this->RegisterPropertyFloat('PositionTolerance', 0.5);
        $this->RegisterPropertyFloat('SlatTolerance', 0.5);
        $this->RegisterPropertyBoolean('AllowUnreferenced', false);
        $this->RegisterPropertyBoolean('RequestStatusOnStart', true);
        $this->RegisterPropertyBoolean('DiagnosticLog', false);
        $this->RegisterPropertyBoolean('ShowTechnicalObjects', true);

        $this->RegisterAttributeString('GeneratedVersion', '');
        $this->RegisterAttributeString('LastValidation', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        try {
            $this->ensureProfiles();
            $ids = $this->ensureObjectTree();
            $this->ensureRuntimeScripts($ids['scripts']);
            $this->synchronizeConfiguration($ids['configuration']);
            $this->ensureHardwareLinks($ids['lcn']);
            $this->ensureEvents($ids['scripts']);
            $this->ensureVisualizationLinks($ids['visualization'], $ids['control'], $ids['state']);
            $this->applyVisibility($ids);

            $validation = $this->validateConfiguration();
            $this->WriteAttributeString('LastValidation', json_encode($validation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->SetStatus($validation['status']);
            $this->SetSummary($validation['status'] === self::STATUS_ACTIVE ? 'bereit' : 'Konfiguration unvollständig');

            $this->setRuntimeEnabled($validation['status'] === self::STATUS_ACTIVE, $ids['scripts']);
            $this->WriteAttributeString('GeneratedVersion', self::VERSION);
        } catch (Throwable $e) {
            $this->SetStatus(self::STATUS_STRUCTURE_ERROR);
            $this->SetSummary('Aufbaufehler');
            $this->SendDebug('ApplyChanges', $e->getMessage(), 0);
            IPS_LogMessage('LCN Jalousie #' . $this->InstanceID, $e->getMessage());
        }
    }

    public function GetConfigurationForm(): string
    {
        $validation = $this->validateConfiguration(false);
        $messages = $validation['messages'];
        $summary = $messages === []
            ? 'Die gespeicherte Konfiguration ist vollständig. Vor dem Motorbetrieb die LCN-PRO-Verriegelung und die TS-Belegung am Bus prüfen.'
            : "Noch zu erledigen:\n• " . implode("\n• ", $messages);

        $form = [
            'elements' => [
                [
                    'type' => 'ExpansionPanel',
                    'caption' => '1. Allgemein',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'ValidationTextBox', 'name' => 'ProjectName', 'caption' => 'Name der Jalousie'],
                        ['type' => 'CheckBox', 'name' => 'ShowTechnicalObjects', 'caption' => 'Technische Unterkategorien und Skripte im Objektbaum anzeigen'],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => '2. LCN-Zuordnung – Pflichtfelder',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Wählen Sie vorhandene LCN-Objekte aus. Das Modul legt keine LCN-Verbindung und keine LCN-PRO-Programmierung an.'],
                        ['type' => 'SelectInstance', 'name' => 'LCNSendModuleID', 'caption' => 'LCN-Sendemodul für virtuelle Tasten (z. B. M22)'],
                        ['type' => 'SelectInstance', 'name' => 'LCNActorModuleID', 'caption' => 'LCN-Aktormodul mit Motorrelais (z. B. M93)'],
                        ['type' => 'SelectVariable', 'name' => 'RelayUpVariableID', 'caption' => 'Reale Relaisstatusvariable AUF', 'validVariableTypes' => [0]],
                        ['type' => 'SelectVariable', 'name' => 'RelayDownVariableID', 'caption' => 'Reale Relaisstatusvariable AB', 'validVariableTypes' => [0]],
                        ['type' => 'SelectVariable', 'name' => 'GT8LongUpVariableID', 'caption' => 'GT8 LANG AUF – simulierter Ausgang 3', 'validVariableTypes' => [0]],
                        ['type' => 'SelectVariable', 'name' => 'GT8LongDownVariableID', 'caption' => 'GT8 LANG AB – simulierter Ausgang 4', 'validVariableTypes' => [0]],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => '3. Virtuelle LCN-Tasten – erst nach Busprüfung freigeben',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'ValidationTextBox', 'name' => 'TSShortUp', 'caption' => 'TS-Datenfeld KURZ AUF', 'validate' => '^[K-]{4}[01]{8}$'],
                        ['type' => 'ValidationTextBox', 'name' => 'TSShortDown', 'caption' => 'TS-Datenfeld KURZ AB', 'validate' => '^[K-]{4}[01]{8}$'],
                        ['type' => 'CheckBox', 'name' => 'TSMappingConfirmed', 'caption' => 'Ich habe beide TS-Datenfelder mit LCN-PRO/PCHK-Busmonitor bestätigt'],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => '4. Laufzeiten',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'TotalTravelMs', 'caption' => 'Gesamtlaufzeit', 'suffix' => ' ms', 'minimum' => 1000],
                        ['type' => 'NumberSpinner', 'name' => 'TurnMs', 'caption' => 'Wendezeit', 'suffix' => ' ms', 'minimum' => 100],
                        ['type' => 'NumberSpinner', 'name' => 'BlindTravelMs', 'caption' => 'Reine Behanglaufzeit', 'suffix' => ' ms', 'minimum' => 1000],
                        ['type' => 'NumberSpinner', 'name' => 'ReferenceReserveMs', 'caption' => 'Referenzreserve', 'suffix' => ' ms', 'minimum' => 0],
                        ['type' => 'NumberSpinner', 'name' => 'MaxTravelMs', 'caption' => 'Maximale überwachte Fahrt', 'suffix' => ' ms', 'minimum' => 1000],
                        ['type' => 'NumberSpinner', 'name' => 'ShakeFreeMs', 'caption' => 'ShakeFree-Gegenfahrt', 'suffix' => ' ms', 'minimum' => 100],
                        ['type' => 'NumberSpinner', 'name' => 'PositionTolerance', 'caption' => 'Positionstoleranz', 'suffix' => ' %', 'digits' => 1, 'minimum' => 0.1, 'maximum' => 10],
                        ['type' => 'NumberSpinner', 'name' => 'SlatTolerance', 'caption' => 'Lamellentoleranz', 'suffix' => ' %', 'digits' => 1, 'minimum' => 0.1, 'maximum' => 10],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => '5. Rückmeldung und Sicherheit',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'RelayConfirmMs', 'caption' => 'Startbestätigung', 'suffix' => ' ms', 'minimum' => 500, 'maximum' => 10000],
                        ['type' => 'NumberSpinner', 'name' => 'StopConfirmMs', 'caption' => 'Stoppbestätigung', 'suffix' => ' ms', 'minimum' => 500, 'maximum' => 10000],
                        ['type' => 'NumberSpinner', 'name' => 'LateStartGuardMs', 'caption' => 'Spätstart-Schutz', 'suffix' => ' ms', 'minimum' => 500],
                        ['type' => 'NumberSpinner', 'name' => 'WorkerWindowMs', 'caption' => 'Millisekunden-Schlussfenster', 'suffix' => ' ms', 'minimum' => 1000, 'maximum' => 3000],
                        ['type' => 'NumberSpinner', 'name' => 'StatusSyncMs', 'caption' => 'LCN-Statusabgleich', 'suffix' => ' ms', 'minimum' => 500, 'maximum' => 10000],
                        ['type' => 'NumberSpinner', 'name' => 'RelayCoalesceMs', 'caption' => 'Relaismeldungen zusammenfassen', 'suffix' => ' ms', 'minimum' => 0, 'maximum' => 1000],
                        ['type' => 'NumberSpinner', 'name' => 'HealthcheckSeconds', 'caption' => 'Healthcheck', 'suffix' => ' s', 'minimum' => 5, 'maximum' => 3600],
                        ['type' => 'CheckBox', 'name' => 'RequestStatusOnStart', 'caption' => 'LCN-Status beim Initialisieren anfordern'],
                        ['type' => 'CheckBox', 'name' => 'AllowUnreferenced', 'caption' => 'Fahrt ohne vorherige Referenz erlauben'],
                        ['type' => 'CheckBox', 'name' => 'DiagnosticLog', 'caption' => 'Ausführliche Diagnose ins Symcon-Protokoll schreiben'],
                    ],
                ],
            ],
            'actions' => [
                ['type' => 'Label', 'caption' => $summary],
                ['type' => 'Button', 'caption' => 'Gespeicherte Konfiguration prüfen', 'onClick' => 'echo LCNJAL_CheckConfiguration($id);'],
                ['type' => 'Button', 'caption' => 'Objektbaum und Skripte neu aufbauen', 'onClick' => 'LCNJAL_Rebuild($id); echo "Objektbaum wurde geprüft und aktualisiert.";'],
                ['type' => 'Button', 'caption' => 'LCN-Status anfordern', 'onClick' => 'LCNJAL_RequestLCNStatus($id); echo "Statusanforderung wurde gesendet.";'],
                ['type' => 'Button', 'caption' => 'Diagnose anzeigen', 'onClick' => 'echo LCNJAL_GetDiagnostics($id);'],
            ],
            'status' => [
                ['code' => self::STATUS_ACTIVE, 'icon' => 'active', 'caption' => 'Konfiguration vollständig – Laufzeit freigegeben'],
                ['code' => self::STATUS_SEND_MODULE_MISSING, 'icon' => 'error', 'caption' => 'LCN-Sendemodul fehlt oder ist ungültig'],
                ['code' => self::STATUS_ACTOR_MODULE_MISSING, 'icon' => 'error', 'caption' => 'LCN-Aktormodul fehlt oder ist ungültig'],
                ['code' => self::STATUS_RELAY_UP_INVALID, 'icon' => 'error', 'caption' => 'Relaisstatus AUF fehlt, ist nicht Boolean oder ist nicht mit dem Aktormodul verbunden'],
                ['code' => self::STATUS_RELAY_DOWN_INVALID, 'icon' => 'error', 'caption' => 'Relaisstatus AB fehlt, ist nicht Boolean oder ist nicht mit dem Aktormodul verbunden'],
                ['code' => self::STATUS_GT8_INVALID, 'icon' => 'error', 'caption' => 'GT8-LANG-Variablen fehlen, sind nicht Boolean oder sind nicht mit dem Sendemodul verbunden'],
                ['code' => self::STATUS_DUPLICATE_OBJECTS, 'icon' => 'error', 'caption' => 'AUF/AB-Zuordnungen sind identisch'],
                ['code' => self::STATUS_TS_INVALID, 'icon' => 'error', 'caption' => 'TS-Datenfelder ungültig oder noch nicht bestätigt'],
                ['code' => self::STATUS_TIMING_INVALID, 'icon' => 'error', 'caption' => 'Zeitparameter sind widersprüchlich'],
                ['code' => self::STATUS_LCN_FUNCTION_MISSING, 'icon' => 'error', 'caption' => 'Benötigte LCN-Funktionen fehlen'],
                ['code' => self::STATUS_STRUCTURE_ERROR, 'icon' => 'error', 'caption' => 'Objektbaum oder Laufzeitskripte konnten nicht aufgebaut werden'],
                ['code' => self::STATUS_RELAY_CONFLICT, 'icon' => 'error', 'caption' => 'AUF und AB melden gleichzeitig TRUE – Motorbetrieb gesperrt'],
            ],
        ];

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function CheckConfiguration(): string
    {
        $result = $this->validateConfiguration();
        $lines = ['LCN Jalousie – Konfigurationsprüfung', 'Statuscode: ' . $result['status']];
        if ($result['messages'] === []) {
            $lines[] = 'Ergebnis: vollständig.';
        } else {
            $lines[] = 'Ergebnis: nicht vollständig.';
            foreach ($result['messages'] as $message) {
                $lines[] = '- ' . $message;
            }
        }
        $lines[] = '';
        $lines[] = 'Hinweis: Eine vollständige Softwarekonfiguration ersetzt nicht den Test der LCN-PRO-Verriegelung und der realen Motorfahrt.';
        return implode("\n", $lines);
    }

    public function Rebuild(): void
    {
        IPS_ApplyChanges($this->InstanceID);
    }

    public function RequestLCNStatus(): void
    {
        if (!IPS_FunctionExists('LCN_RequestStatus')) {
            throw new RuntimeException('LCN_RequestStatus ist in dieser Symcon-Installation nicht verfügbar.');
        }
        foreach ([$this->ReadPropertyInteger('LCNSendModuleID'), $this->ReadPropertyInteger('LCNActorModuleID')] as $moduleID) {
            if ($moduleID > 0 && IPS_InstanceExists($moduleID)) {
                LCN_RequestStatus($moduleID);
            }
        }
    }

    public function GetDiagnostics(): string
    {
        $validation = $this->validateConfiguration(false);
        $data = [
            'moduleVersion' => self::VERSION,
            'instanceID' => $this->InstanceID,
            'instanceName' => IPS_GetName($this->InstanceID),
            'kernelVersion' => IPS_GetKernelVersion(),
            'phpVersion' => PHP_VERSION,
            'status' => IPS_GetInstance($this->InstanceID)['InstanceStatus'],
            'configuration' => [
                'LCNSendModuleID' => $this->ReadPropertyInteger('LCNSendModuleID'),
                'LCNActorModuleID' => $this->ReadPropertyInteger('LCNActorModuleID'),
                'RelayUpVariableID' => $this->ReadPropertyInteger('RelayUpVariableID'),
                'RelayDownVariableID' => $this->ReadPropertyInteger('RelayDownVariableID'),
                'GT8LongUpVariableID' => $this->ReadPropertyInteger('GT8LongUpVariableID'),
                'GT8LongDownVariableID' => $this->ReadPropertyInteger('GT8LongDownVariableID'),
                'TSMappingConfirmed' => $this->ReadPropertyBoolean('TSMappingConfirmed'),
            ],
            'validation' => $validation,
        ];
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function validateConfiguration(bool $checkLiveRelayState = true): array
    {
        $messages = [];
        $status = self::STATUS_ACTIVE;

        $sendModule = $this->ReadPropertyInteger('LCNSendModuleID');
        $actorModule = $this->ReadPropertyInteger('LCNActorModuleID');
        if ($sendModule <= 0 || !IPS_InstanceExists($sendModule)) {
            $messages[] = 'LCN-Sendemodul auswählen.';
            $status = self::STATUS_SEND_MODULE_MISSING;
        }
        if ($actorModule <= 0 || !IPS_InstanceExists($actorModule)) {
            $messages[] = 'LCN-Aktormodul auswählen.';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_ACTOR_MODULE_MISSING;
            }
        }

        if ($sendModule > 0 && IPS_InstanceExists($sendModule) && !$this->isUsableLcnModule($sendModule)) {
            $messages[] = 'Das ausgewählte Sendemodul ist keine aktive LCN-Modul-/Splitterinstanz (Modultyp 2, Status 102).';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_SEND_MODULE_MISSING;
            }
        }
        if ($actorModule > 0 && IPS_InstanceExists($actorModule) && !$this->isUsableLcnModule($actorModule)) {
            $messages[] = 'Das ausgewählte Aktormodul ist keine aktive LCN-Modul-/Splitterinstanz (Modultyp 2, Status 102).';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_ACTOR_MODULE_MISSING;
            }
        }

        $relayUp = $this->ReadPropertyInteger('RelayUpVariableID');
        $relayDown = $this->ReadPropertyInteger('RelayDownVariableID');
        $gt8Up = $this->ReadPropertyInteger('GT8LongUpVariableID');
        $gt8Down = $this->ReadPropertyInteger('GT8LongDownVariableID');

        if (!$this->isBooleanVariable($relayUp)) {
            $messages[] = 'Reale Relaisstatusvariable AUF auswählen.';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_RELAY_UP_INVALID;
            }
        }
        if (!$this->isBooleanVariable($relayDown)) {
            $messages[] = 'Reale Relaisstatusvariable AB auswählen.';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_RELAY_DOWN_INVALID;
            }
        }
        if (!$this->isBooleanVariable($gt8Up) || !$this->isBooleanVariable($gt8Down)) {
            $messages[] = 'Beide GT8-LANG-Statusvariablen auswählen.';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_GT8_INVALID;
            }
        }
        if (($relayUp > 0 && $relayUp === $relayDown) || ($gt8Up > 0 && $gt8Up === $gt8Down)) {
            $messages[] = 'AUF und AB dürfen nicht auf dasselbe Objekt zeigen.';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_DUPLICATE_OBJECTS;
            }
        }

        if ($this->isBooleanVariable($relayUp) && $actorModule > 0 && !$this->variableBelongsToInstanceChain($relayUp, $actorModule)) {
            $messages[] = 'Die Relaisvariable AUF gehört nicht zur Verbindungskette des ausgewählten Aktormoduls.';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_RELAY_UP_INVALID;
            }
        }
        if ($this->isBooleanVariable($relayDown) && $actorModule > 0 && !$this->variableBelongsToInstanceChain($relayDown, $actorModule)) {
            $messages[] = 'Die Relaisvariable AB gehört nicht zur Verbindungskette des ausgewählten Aktormoduls.';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_RELAY_DOWN_INVALID;
            }
        }
        if ($this->isBooleanVariable($gt8Up) && $sendModule > 0 && !$this->variableBelongsToInstanceChain($gt8Up, $sendModule)) {
            $messages[] = 'Die GT8-LANG-AUF-Variable gehört nicht zur Verbindungskette des ausgewählten Sendemoduls.';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_GT8_INVALID;
            }
        }
        if ($this->isBooleanVariable($gt8Down) && $sendModule > 0 && !$this->variableBelongsToInstanceChain($gt8Down, $sendModule)) {
            $messages[] = 'Die GT8-LANG-AB-Variable gehört nicht zur Verbindungskette des ausgewählten Sendemoduls.';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_GT8_INVALID;
            }
        }

        if (!IPS_FunctionExists('LCN_SendCommand') || ($this->ReadPropertyBoolean('RequestStatusOnStart') && !IPS_FunctionExists('LCN_RequestStatus'))) {
            $messages[] = 'LCN_SendCommand beziehungsweise LCN_RequestStatus ist nicht verfügbar.';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_LCN_FUNCTION_MISSING;
            }
        }

        $tsUp = $this->ReadPropertyString('TSShortUp');
        $tsDown = $this->ReadPropertyString('TSShortDown');
        if (!$this->validateTS($tsUp) || !$this->validateTS($tsDown) || $tsUp === $tsDown || !$this->ReadPropertyBoolean('TSMappingConfirmed')) {
            $messages[] = 'TS-Datenfelder prüfen und erst nach Busmonitor-Prüfung bestätigen.';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_TS_INVALID;
            }
        }

        $total = $this->ReadPropertyInteger('TotalTravelMs');
        $turn = $this->ReadPropertyInteger('TurnMs');
        $blind = $this->ReadPropertyInteger('BlindTravelMs');
        $reserve = $this->ReadPropertyInteger('ReferenceReserveMs');
        $max = $this->ReadPropertyInteger('MaxTravelMs');
        $window = $this->ReadPropertyInteger('WorkerWindowMs');
        if ($total <= 0 || $turn <= 0 || $blind <= 0 || $turn + $blind !== $total || $max < $total + $reserve || $window < 1000 || $window > 3000) {
            $messages[] = 'Zeitparameter sind widersprüchlich: Gesamtlaufzeit = Wendezeit + Behanglaufzeit; MaxFahrt mindestens Gesamtlaufzeit + Reserve; Workerfenster 1000…3000 ms.';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_TIMING_INVALID;
            }
        }

        if ($checkLiveRelayState && $this->isBooleanVariable($relayUp) && $this->isBooleanVariable($relayDown) && GetValueBoolean($relayUp) && GetValueBoolean($relayDown)) {
            $messages[] = 'Relais AUF und AB melden gleichzeitig TRUE. LCN-Verriegelung und Verdrahtung prüfen.';
            $status = self::STATUS_RELAY_CONFLICT;
        }

        return ['status' => $status, 'messages' => $messages];
    }

    private function isBooleanVariable(int $variableID): bool
    {
        return $variableID > 0
            && IPS_VariableExists($variableID)
            && (int) IPS_GetVariable($variableID)['VariableType'] === 0;
    }

    private function isUsableLcnModule(int $instanceID): bool
    {
        if (!IPS_InstanceExists($instanceID)) {
            return false;
        }
        $instance = IPS_GetInstance($instanceID);
        $moduleName = (string) ($instance['ModuleInfo']['ModuleName'] ?? '');
        $moduleType = (int) ($instance['ModuleInfo']['ModuleType'] ?? -1);
        return $moduleType === 2
            && stripos($moduleName, 'LCN') !== false
            && (int) $instance['InstanceStatus'] === 102;
    }

    private function variableBelongsToInstanceChain(int $variableID, int $expectedInstanceID): bool
    {
        if (!IPS_VariableExists($variableID) || !IPS_InstanceExists($expectedInstanceID)) {
            return false;
        }

        // IPS_GetParent() beschreibt nur die frei verschiebbare logische
        // Objektbaum-Hierarchie. Die physische Device -> Splitter -> I/O-
        // Verbindung wird bei Instanzen dagegen über ConnectionID abgebildet.
        // LCN-Statusvariablen liegen unter Relais-/Ausgangsinstanzen, deren
        // ConnectionID auf die ausgewählte LCN-Modulinstanz zeigt.
        $currentObjectID = IPS_GetParent($variableID);
        $logicalGuard = 0;
        while ($currentObjectID > 0 && $logicalGuard < 32) {
            if ($currentObjectID === $expectedInstanceID) {
                return true;
            }

            if (IPS_InstanceExists($currentObjectID)
                && $this->instanceConnectionChainContains($currentObjectID, $expectedInstanceID)) {
                return true;
            }

            $currentObjectID = IPS_GetParent($currentObjectID);
            $logicalGuard++;
        }

        return false;
    }

    private function instanceConnectionChainContains(int $startInstanceID, int $expectedInstanceID): bool
    {
        $currentInstanceID = $startInstanceID;
        $visited = [];

        for ($guard = 0; $guard < 32 && $currentInstanceID > 0; $guard++) {
            if ($currentInstanceID === $expectedInstanceID) {
                return true;
            }
            if (isset($visited[$currentInstanceID]) || !IPS_InstanceExists($currentInstanceID)) {
                return false;
            }

            $visited[$currentInstanceID] = true;
            $instance = IPS_GetInstance($currentInstanceID);
            $currentInstanceID = (int) ($instance['ConnectionID'] ?? 0);
        }

        return false;
    }

    private function validateTS(string $value): bool
    {
        if (!preg_match('/^[K-]{4}[01]{8}$/', $value)) {
            return false;
        }
        if (substr_count(substr($value, 0, 4), 'K') !== 1) {
            return false;
        }
        return substr_count(substr($value, 4), '1') === 1;
    }

    private function ensureProfiles(): void
    {
        $this->profile('LCNJAL.Position.Int', 1, 0, 100, 1, '', ' %', 'Shutter');
        $this->profile('LCNJAL.Position.Float', 2, 0, 100, 0.1, '', ' %', 'Shutter');
        $this->profile('LCNJAL.Slat', 1, 0, 100, 0, '', ' %', 'Shutter');
        IPS_SetVariableProfileAssociation('LCNJAL.Slat', 0, '0 % AUF', 'ArrowUp', 0x4F81BD);
        IPS_SetVariableProfileAssociation('LCNJAL.Slat', 50, '50 %', 'Shutter', 0xF2C811);
        IPS_SetVariableProfileAssociation('LCNJAL.Slat', 100, '100 % AB', 'ArrowDown', 0x70AD47);

        $this->profile('LCNJAL.DriveState', 1, 0, 3, 0, '', '', 'Shutter');
        foreach ([[0, 'STOP', 'Stop', 0x808080], [1, 'AUF', 'ArrowUp', 0x4F81BD], [2, 'AB', 'ArrowDown', 0x70AD47], [3, 'FEHLER', 'Warning', 0xC00000]] as $a) {
            IPS_SetVariableProfileAssociation('LCNJAL.DriveState', $a[0], $a[1], $a[2], $a[3]);
        }
        $this->profile('LCNJAL.Phase', 1, 0, 9, 0, '', '', 'Clock');
        foreach ([0 => 'Ruhe', 1 => 'Warte Start', 2 => 'Behangfahrt', 3 => 'Lamellenfahrt', 4 => 'ShakeFree', 5 => 'Stoppen', 6 => 'Externe Bedienung', 7 => 'Fehler', 8 => 'Referenzfahrt', 9 => 'Statusabgleich'] as $v => $n) {
            IPS_SetVariableProfileAssociation('LCNJAL.Phase', $v, $n, '', -1);
        }
        $this->profile('LCNJAL.Direction', 1, 0, 3, 0, '', '', 'ArrowRight');
        foreach ([0 => 'keine', 1 => 'AUF', 2 => 'AB', 3 => 'beide'] as $v => $n) {
            IPS_SetVariableProfileAssociation('LCNJAL.Direction', $v, $n, '', -1);
        }
        $this->profile('LCNJAL.OrderType', 1, 0, 4, 0, '', '', 'Gear');
        foreach ([0 => 'kein Auftrag', 1 => 'Behang', 2 => 'Lamelle', 3 => 'ShakeFree', 4 => 'Referenz'] as $v => $n) {
            IPS_SetVariableProfileAssociation('LCNJAL.OrderType', $v, $n, '', -1);
        }
        $this->profile('LCNJAL.Pending', 1, 0, 3, 0, '', '', 'Clock');
        foreach ([0 => 'kein Auftrag', 1 => 'Behang', 2 => 'Lamelle', 3 => 'Referenz'] as $v => $n) {
            IPS_SetVariableProfileAssociation('LCNJAL.Pending', $v, $n, '', -1);
        }
        $this->profile('LCNJAL.Stop', 0, 0, 1, 0, '', '', 'Stop');
        IPS_SetVariableProfileAssociation('LCNJAL.Stop', false, 'Bereit', 'Stop', 0x808080);
        IPS_SetVariableProfileAssociation('LCNJAL.Stop', true, 'STOP', 'Stop', 0xC00000);
        $this->profile('LCNJAL.Reference', 1, 0, 2, 0, '', '', 'Shutter');
        IPS_SetVariableProfileAssociation('LCNJAL.Reference', 0, 'Keine', '', -1);
        IPS_SetVariableProfileAssociation('LCNJAL.Reference', 1, 'AUF referenzieren', 'ArrowUp', 0x4F81BD);
        IPS_SetVariableProfileAssociation('LCNJAL.Reference', 2, 'AB referenzieren', 'ArrowDown', 0x70AD47);
    }

    private function profile(string $name, int $type, float $min, float $max, float $step, string $prefix, string $suffix, string $icon): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
        }
        if ((int) IPS_GetVariableProfile($name)['ProfileType'] !== $type) {
            throw new RuntimeException('Variablenprofil hat falschen Typ: ' . $name);
        }
        IPS_SetVariableProfileValues($name, $min, $max, $step);
        IPS_SetVariableProfileText($name, $prefix, $suffix);
        IPS_SetVariableProfileIcon($name, $icon);
    }

    private function ensureObjectTree(): array
    {
        $configuration = $this->category('01_Konfiguration', '01 Konfiguration', 10);
        $lcn = $this->category('02_LCN_Status', '02 LCN-Status', 20);
        $control = $this->category('03_Bedienung', '03 Bedienung', 30);
        $state = $this->category('04_Istwerte', '04 Istwerte', 40);
        $internal = $this->category('05_Intern', '05 Intern', 50);
        $scripts = $this->category('06_Skripte', '06 Skripte', 60);
        $visualization = $this->category('07_Visualisierung', '07 Visualisierung', 70);
        $acceptance = $this->category('08_Abnahme', '08 Abnahme', 80);

        $this->createConfigurationVariables($configuration);
        $this->createControlVariables($control);
        $this->createStateVariables($state);
        $this->createInternalVariables($internal);
        $this->variable($acceptance, 'Hinweis', 'Hinweis', 3, '', 10, 'Motorbetrieb erst nach LCN-PRO-, Busmonitor- und Endlagentest freigeben.', false);

        return compact('configuration', 'lcn', 'control', 'state', 'internal', 'scripts', 'visualization', 'acceptance');
    }

    private function createConfigurationVariables(int $parentID): void
    {
        $schema = [
            ['Projektname', 'Projektname', 3, '', 10, '', true],
            ['LCN_Sendemodulinstanz_ID', 'LCN-Sendemodulinstanz ID', 1, '', 20, 0, true],
            ['LCN_Aktormodulinstanz_ID', 'LCN-Aktormodulinstanz ID', 1, '', 30, 0, true],
            ['Relais_AUF_ID', 'Relais AUF Variable ID', 1, '', 40, 0, true],
            ['Relais_AB_ID', 'Relais AB Variable ID', 1, '', 50, 0, true],
            ['GT8_LANG_AUF_ID', 'GT8 LANG AUF Variable ID', 1, '', 60, 0, true],
            ['GT8_LANG_AB_ID', 'GT8 LANG AB Variable ID', 1, '', 70, 0, true],
            ['TS_KURZ_AUF', 'TS KURZ AUF', 3, '', 80, '', true],
            ['TS_KURZ_AB', 'TS KURZ AB', 3, '', 90, '', true],
            ['TS_Belegung_bestaetigt', 'TS-Belegung bestätigt', 0, '~Switch', 100, false, true],
            ['Gesamtlaufzeit_ms', 'Gesamtlaufzeit [ms]', 1, '', 110, 0, true],
            ['Wendezeit_ms', 'Wendezeit [ms]', 1, '', 120, 0, true],
            ['Behanglaufzeit_ms', 'Reine Behanglaufzeit [ms]', 1, '', 130, 0, true],
            ['Referenzreserve_ms', 'Referenzreserve [ms]', 1, '', 140, 0, true],
            ['MaxFahrt_ms', 'Maximale Fahrt [ms]', 1, '', 150, 0, true],
            ['ShakeFree_ms', 'ShakeFree Gegenfahrt [ms]', 1, '', 160, 0, true],
            ['Relaisbestaetigung_ms', 'Startbestätigung [ms]', 1, '', 170, 0, true],
            ['Stoppbestaetigung_ms', 'Stoppbestätigung [ms]', 1, '', 180, 0, true],
            ['Spaetstart_Schutz_ms', 'Spätstart-Schutz [ms]', 1, '', 190, 0, true],
            ['Workerfenster_ms', 'Workerfenster [ms]', 1, '', 200, 0, true],
            ['Positionstoleranz', 'Positionstoleranz [%]', 2, 'LCNJAL.Position.Float', 210, 0.5, true],
            ['Lamellentoleranz', 'Lamellentoleranz [%]', 2, 'LCNJAL.Position.Float', 220, 0.5, true],
            ['Unreferenziert_erlauben', 'Fahrt ohne Referenz erlauben', 0, '~Switch', 230, false, true],
            ['Diagnose_Log', 'Diagnose-Logging', 0, '~Switch', 240, false, true],
            ['Statusabfrage_beim_Start', 'LCN-Status beim Start anfordern', 0, '~Switch', 250, true, true],
            ['Statussync_ms', 'Statusabgleich [ms]', 1, '', 260, 0, true],
            ['Relais_Koaleszenz_ms', 'Relais-Koaleszenz [ms]', 1, '', 270, 0, true],
            ['Healthcheck_s', 'Healthcheck [s]', 1, '', 280, 0, true],
        ];
        foreach ($schema as $v) {
            $this->variable($parentID, ...$v);
        }
    }

    private function createControlVariables(int $parentID): void
    {
        $this->variable($parentID, 'Soll_Behang', 'Soll Behang', 1, 'LCNJAL.Position.Int', 10, 0, false);
        $this->variable($parentID, 'Soll_Lamelle', 'Soll Lamelle', 1, 'LCNJAL.Slat', 20, 0, false);
        $this->variable($parentID, 'ShakeFree_Aktiv', 'ShakeFree aktiv', 0, '~Switch', 30, false, false);
        $this->variable($parentID, 'Stopp', 'STOP', 0, 'LCNJAL.Stop', 40, false, false);
        $this->variable($parentID, 'Referenzfahrt', 'Referenzfahrt', 1, 'LCNJAL.Reference', 50, 0, false);
    }

    private function createStateVariables(int $parentID): void
    {
        $this->variable($parentID, 'Ist_Behang', 'Ist Behang', 2, 'LCNJAL.Position.Float', 10, 0.0, false);
        $this->variable($parentID, 'Ist_Lamelle', 'Ist Lamelle', 2, 'LCNJAL.Position.Float', 20, 0.0, false);
        $this->variable($parentID, 'Fahrstatus', 'Fahrstatus', 1, 'LCNJAL.DriveState', 30, 0, false);
        $this->variable($parentID, 'Phase', 'Ablaufphase', 1, 'LCNJAL.Phase', 40, 0, false);
        $this->variable($parentID, 'Position_Referenziert', 'Position referenziert', 0, '~Switch', 50, false, false);
        $this->variable($parentID, 'Automatik_Aktiv', 'Automatik aktiv', 0, '~Switch', 60, false, false);
        $this->variable($parentID, 'Fehlertext', 'Fehlertext', 3, '', 70, '', false);
        $this->variable($parentID, 'Letzte_Aktion', 'Letzte Aktion', 3, '', 80, 'Noch nicht initialisiert', false);
        $this->variable($parentID, 'Letzte_Fahrtdauer_ms', 'Letzte Fahrtdauer [ms]', 1, '', 90, 0, false);
        $this->variable($parentID, 'Letzte_Statusmeldung', 'Letzte Relaisstatusmeldung', 1, '~UnixTimestamp', 100, 0, false);
    }

    private function createInternalVariables(int $parentID): void
    {
        $schema = [
            ['Auftragsnummer', 'Auftragsnummer', 1, '', 10, 0, false],
            ['Auftragstyp', 'Auftragstyp', 1, 'LCNJAL.OrderType', 20, 0, false],
            ['Erwartete_Richtung', 'Erwartete Richtung', 1, 'LCNJAL.Direction', 30, 0, false],
            ['Startzeit_ms', 'Startzeit [ms]', 2, '', 40, 0.0, false],
            ['Start_Behang', 'Start Behang', 2, 'LCNJAL.Position.Float', 50, 0.0, false],
            ['Start_Lamelle', 'Start Lamelle', 2, 'LCNJAL.Position.Float', 60, 0.0, false],
            ['Start_Richtung', 'Start Richtung', 1, 'LCNJAL.Direction', 70, 0, false],
            ['Geplante_Dauer_ms', 'Geplante Dauer [ms]', 1, '', 80, 0, false],
            ['Zielzeit_ms', 'Zielzeit [ms]', 2, '', 90, 0.0, false],
            ['Ziel_Behang', 'Ziel Behang intern', 2, 'LCNJAL.Position.Float', 100, 0.0, false],
            ['Ziel_Lamelle', 'Ziel Lamelle intern', 2, 'LCNJAL.Position.Float', 110, 0.0, false],
            ['Folge_Lamelle', 'Folge Lamelle', 1, '', 120, -1, false],
            ['Folge_Richtung', 'Folge Richtung', 1, 'LCNJAL.Direction', 130, 0, false],
            ['Stop_Angefordert', 'STOP angefordert', 0, '~Switch', 140, false, false],
            ['Endlage_Hart', 'Endlage hart referenzieren', 0, '~Switch', 150, false, false],
            ['Bestaetigung_bis_ms', 'Startbestätigung bis [ms]', 2, '', 160, 0.0, false],
            ['Stop_bis_ms', 'Stoppbestätigung bis [ms]', 2, '', 170, 0.0, false],
            ['Abbruch_bis_ms', 'Spätstart-Schutz bis [ms]', 2, '', 180, 0.0, false],
            ['Abbruch_Wartet_Auf_Start', 'Abbruch wartet auf verspäteten Start', 0, '~Switch', 190, false, false],
            ['Abbruch_Fehlerphase', 'Nach Abbruch Fehlerphase beibehalten', 0, '~Switch', 200, false, false],
            ['Pending_Aktion', 'Wartender Auftrag', 1, 'LCNJAL.Pending', 210, 0, false],
            ['Pending_Wert', 'Wartender Wert', 2, '', 220, 0.0, false],
            ['Pending_Richtung', 'Wartende Richtung', 1, 'LCNJAL.Direction', 230, 0, false],
            ['Worker_Aktiv', 'Worker aktiv', 0, '~Switch', 240, false, false],
            ['Kernel_Startzeit', 'Kernel-Startzeit', 1, '~UnixTimestamp', 250, 0, false],
            ['Sync_bis_ms', 'Statusabgleich bis [monotone ms]', 2, '', 260, 0.0, false],
            ['Sync_Relais_AUF_Empfangen', 'Statussync Relais AUF empfangen', 0, '~Switch', 270, false, false],
            ['Sync_Relais_AB_Empfangen', 'Statussync Relais AB empfangen', 0, '~Switch', 280, false, false],
        ];
        foreach ($schema as $v) {
            $this->variable($parentID, ...$v);
        }
    }

    private function synchronizeConfiguration(int $categoryID): void
    {
        $map = [
            'Projektname' => ['ProjectName', 3],
            'LCN_Sendemodulinstanz_ID' => ['LCNSendModuleID', 1],
            'LCN_Aktormodulinstanz_ID' => ['LCNActorModuleID', 1],
            'Relais_AUF_ID' => ['RelayUpVariableID', 1],
            'Relais_AB_ID' => ['RelayDownVariableID', 1],
            'GT8_LANG_AUF_ID' => ['GT8LongUpVariableID', 1],
            'GT8_LANG_AB_ID' => ['GT8LongDownVariableID', 1],
            'TS_KURZ_AUF' => ['TSShortUp', 3],
            'TS_KURZ_AB' => ['TSShortDown', 3],
            'TS_Belegung_bestaetigt' => ['TSMappingConfirmed', 0],
            'Gesamtlaufzeit_ms' => ['TotalTravelMs', 1],
            'Wendezeit_ms' => ['TurnMs', 1],
            'Behanglaufzeit_ms' => ['BlindTravelMs', 1],
            'Referenzreserve_ms' => ['ReferenceReserveMs', 1],
            'MaxFahrt_ms' => ['MaxTravelMs', 1],
            'ShakeFree_ms' => ['ShakeFreeMs', 1],
            'Relaisbestaetigung_ms' => ['RelayConfirmMs', 1],
            'Stoppbestaetigung_ms' => ['StopConfirmMs', 1],
            'Spaetstart_Schutz_ms' => ['LateStartGuardMs', 1],
            'Workerfenster_ms' => ['WorkerWindowMs', 1],
            'Positionstoleranz' => ['PositionTolerance', 2],
            'Lamellentoleranz' => ['SlatTolerance', 2],
            'Unreferenziert_erlauben' => ['AllowUnreferenced', 0],
            'Diagnose_Log' => ['DiagnosticLog', 0],
            'Statusabfrage_beim_Start' => ['RequestStatusOnStart', 0],
            'Statussync_ms' => ['StatusSyncMs', 1],
            'Relais_Koaleszenz_ms' => ['RelayCoalesceMs', 1],
            'Healthcheck_s' => ['HealthcheckSeconds', 1],
        ];
        foreach ($map as $ident => [$property, $type]) {
            $id = $this->find($categoryID, $ident);
            match ($type) {
                0 => SetValueBoolean($id, $this->ReadPropertyBoolean($property)),
                1 => SetValueInteger($id, $this->ReadPropertyInteger($property)),
                2 => SetValueFloat($id, $this->ReadPropertyFloat($property)),
                3 => SetValueString($id, $this->ReadPropertyString($property)),
            };
        }
    }

    private function ensureRuntimeScripts(int $scriptsCategoryID): void
    {
        $scripts = [
            'Controller' => ['10 Controller V11.3', 20, 'Controller.php'],
            'Worker' => ['20 Worker V11.3', 30, 'Worker.php'],
            'Healthcheck' => ['30 Healthcheck V11.3', 40, 'Healthcheck.php'],
            'Diagnose' => ['90 Diagnose V11.3', 90, 'Diagnose.php'],
        ];
        foreach ($scripts as $ident => [$name, $position, $file]) {
            $path = __DIR__ . '/scripts/' . $file;
            $content = file_get_contents($path);
            if ($content === false || $content === '') {
                throw new RuntimeException('Laufzeitskript fehlt oder ist leer: ' . $path);
            }
            $scriptID = $this->script($scriptsCategoryID, $ident, $name, $position);
            if (IPS_GetScriptContent($scriptID) !== $content) {
                IPS_SetScriptContent($scriptID, $content);
            }
        }
        $controllerID = $this->find($scriptsCategoryID, 'Controller');
        foreach (['Soll_Behang', 'Soll_Lamelle', 'ShakeFree_Aktiv', 'Stopp', 'Referenzfahrt'] as $ident) {
            $controlCategory = $this->find($this->InstanceID, '03_Bedienung');
            IPS_SetVariableCustomAction($this->find($controlCategory, $ident), $controllerID);
        }
    }

    private function ensureHardwareLinks(int $lcnCategoryID): void
    {
        $links = [
            ['Relais_AUF', 'Relais AUF', $this->ReadPropertyInteger('RelayUpVariableID'), 10],
            ['Relais_AB', 'Relais AB', $this->ReadPropertyInteger('RelayDownVariableID'), 20],
            ['GT8_LANG_AUF', 'GT8 LANG AUF Toggle / Ausgang 3', $this->ReadPropertyInteger('GT8LongUpVariableID'), 30],
            ['GT8_LANG_AB', 'GT8 LANG AB Toggle / Ausgang 4', $this->ReadPropertyInteger('GT8LongDownVariableID'), 40],
        ];
        foreach ($links as [$ident, $name, $target, $position]) {
            if ($target > 0 && IPS_VariableExists($target)) {
                $this->link($lcnCategoryID, $ident, $name, $target, $position);
            }
        }
    }

    private function ensureEvents(int $scriptsCategoryID): void
    {
        $controllerID = $this->find($scriptsCategoryID, 'Controller');
        $events = [
            ['Evt_Relais_AUF', 'Relais AUF – bei Aktualisierung', $this->ReadPropertyInteger('RelayUpVariableID'), 100, 0],
            ['Evt_Relais_AB', 'Relais AB – bei Aktualisierung', $this->ReadPropertyInteger('RelayDownVariableID'), 110, 0],
            ['Evt_GT8_LANG_AUF', 'GT8 LANG AUF – bei Änderung', $this->ReadPropertyInteger('GT8LongUpVariableID'), 120, 1],
            ['Evt_GT8_LANG_AB', 'GT8 LANG AB – bei Änderung', $this->ReadPropertyInteger('GT8LongDownVariableID'), 130, 1],
        ];
        foreach ($events as [$ident, $name, $variableID, $position, $triggerType]) {
            $this->triggerEvent($controllerID, $ident, $name, $variableID, $position, $triggerType);
        }
    }

    private function ensureVisualizationLinks(int $visualizationID, int $controlID, int $stateID): void
    {
        $links = [
            ['V_Soll_Behang', 'Behang', $this->find($controlID, 'Soll_Behang'), 10],
            ['V_Soll_Lamelle', 'Lamelle', $this->find($controlID, 'Soll_Lamelle'), 20],
            ['V_ShakeFree', 'ShakeFree', $this->find($controlID, 'ShakeFree_Aktiv'), 30],
            ['V_Stop', 'STOP', $this->find($controlID, 'Stopp'), 40],
            ['V_Referenz', 'Referenzfahrt', $this->find($controlID, 'Referenzfahrt'), 50],
            ['V_Ist_Behang', 'Ist Behang', $this->find($stateID, 'Ist_Behang'), 60],
            ['V_Ist_Lamelle', 'Ist Lamelle', $this->find($stateID, 'Ist_Lamelle'), 70],
            ['V_Fahrstatus', 'Fahrstatus', $this->find($stateID, 'Fahrstatus'), 80],
            ['V_Phase', 'Ablaufphase', $this->find($stateID, 'Phase'), 90],
            ['V_Referenziert', 'Position referenziert', $this->find($stateID, 'Position_Referenziert'), 100],
            ['V_Fehlertext', 'Fehlertext', $this->find($stateID, 'Fehlertext'), 110],
        ];
        foreach ($links as [$ident, $name, $target, $position]) {
            $this->link($visualizationID, $ident, $name, $target, $position);
        }
    }

    private function setRuntimeEnabled(bool $enabled, int $scriptsCategoryID): void
    {
        $controllerID = $this->find($scriptsCategoryID, 'Controller');
        foreach (['Evt_Relais_AUF', 'Evt_Relais_AB', 'Evt_GT8_LANG_AUF', 'Evt_GT8_LANG_AB'] as $ident) {
            $id = @IPS_GetObjectIDByIdent($ident, $controllerID);
            if ($id !== false && IPS_EventExists((int) $id)) {
                IPS_SetEventActive((int) $id, $enabled);
            }
        }
        $workerID = $this->find($scriptsCategoryID, 'Worker');
        $healthID = $this->find($scriptsCategoryID, 'Healthcheck');
        IPS_SetScriptTimer($workerID, 0);
        IPS_SetScriptTimer($healthID, $enabled ? $this->ReadPropertyInteger('HealthcheckSeconds') : 0);

    }

    private function applyVisibility(array $ids): void
    {
        $show = $this->ReadPropertyBoolean('ShowTechnicalObjects');
        foreach (['configuration', 'internal', 'scripts', 'acceptance'] as $key) {
            IPS_SetHidden($ids[$key], !$show);
        }
        foreach (IPS_GetChildrenIDs($ids['scripts']) as $childID) {
            IPS_SetHidden($childID, !$show);
        }
    }

    private function category(string $ident, string $name, int $position): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id === false) {
            $id = IPS_CreateCategory();
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, $ident);
        }
        if (!IPS_CategoryExists((int) $id)) {
            throw new RuntimeException('Ident wird bereits von anderem Objekttyp verwendet: ' . $ident);
        }
        IPS_SetName((int) $id, $name);
        IPS_SetPosition((int) $id, $position);
        return (int) $id;
    }

    private function variable(int $parentID, string $ident, string $name, int $type, string $profile, int $position, mixed $default, bool $hidden): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentID);
        $created = false;
        if ($id === false) {
            $id = IPS_CreateVariable($type);
            IPS_SetParent($id, $parentID);
            IPS_SetIdent($id, $ident);
            $created = true;
        }
        if (!IPS_VariableExists((int) $id) || (int) IPS_GetVariable((int) $id)['VariableType'] !== $type) {
            throw new RuntimeException('Variable fehlt oder hat falschen Typ: ' . $ident);
        }
        IPS_SetName((int) $id, $name);
        IPS_SetPosition((int) $id, $position);
        IPS_SetHidden((int) $id, $hidden);
        IPS_SetVariableCustomProfile((int) $id, $profile);
        if ($created) {
            match ($type) {
                0 => SetValueBoolean((int) $id, (bool) $default),
                1 => SetValueInteger((int) $id, (int) $default),
                2 => SetValueFloat((int) $id, (float) $default),
                3 => SetValueString((int) $id, (string) $default),
            };
        }
        return (int) $id;
    }

    private function script(int $parentID, string $ident, string $name, int $position): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentID);
        if ($id === false) {
            $id = IPS_CreateScript(0);
            IPS_SetParent($id, $parentID);
            IPS_SetIdent($id, $ident);
        }
        if (!IPS_ScriptExists((int) $id)) {
            throw new RuntimeException('Skript-Ident wird von anderem Objekttyp verwendet: ' . $ident);
        }
        IPS_SetName((int) $id, $name);
        IPS_SetPosition((int) $id, $position);
        return (int) $id;
    }

    private function link(int $parentID, string $ident, string $name, int $targetID, int $position): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentID);
        if ($id === false) {
            $id = IPS_CreateLink();
            IPS_SetParent($id, $parentID);
            IPS_SetIdent($id, $ident);
        }
        if (!IPS_LinkExists((int) $id)) {
            throw new RuntimeException('Link-Ident wird von anderem Objekttyp verwendet: ' . $ident);
        }
        IPS_SetName((int) $id, $name);
        IPS_SetPosition((int) $id, $position);
        IPS_SetLinkTargetID((int) $id, $targetID);
        return (int) $id;
    }

    private function triggerEvent(int $parentID, string $ident, string $name, int $variableID, int $position, int $triggerType): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentID);
        if ($id === false) {
            $id = IPS_CreateEvent(0);
            IPS_SetParent($id, $parentID);
            IPS_SetIdent($id, $ident);
        }
        if (!IPS_EventExists((int) $id)) {
            throw new RuntimeException('Ereignis-Ident wird von anderem Objekttyp verwendet: ' . $ident);
        }
        IPS_SetName((int) $id, $name);
        IPS_SetPosition((int) $id, $position);
        IPS_SetEventAction((int) $id, self::EXECUTE_PARENT_ACTION, []);
        if ($variableID > 0 && IPS_VariableExists($variableID)) {
            IPS_SetEventTrigger((int) $id, $triggerType, $variableID);
        }
        IPS_SetEventActive((int) $id, false);
        return (int) $id;
    }

    private function find(int $parentID, string $ident): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parentID);
        if ($id === false) {
            throw new RuntimeException('Objekt fehlt: ' . $ident . ' unter #' . $parentID);
        }
        return (int) $id;
    }
}
