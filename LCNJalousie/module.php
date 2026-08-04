<?php

declare(strict_types=1);

/**
 * LCN Jalousie – Symcon 9.0 compatibility module.
 *
 * This module creates and maintains the V11.6 object tree, runtime scripts,
 * events, links and configuration values below one module instance.
 * The motor interlock and local operation remain in LCN-PRO.
 */
class LCNJalousie extends IPSModuleStrict
{
    private const VERSION = '0.1.19';
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
    private const STATUS_FAULT_LATCHED = 212;

    // Symcon-Nachrichten und Runlevel. Numerisch festgehalten, damit die
    // Startlogik unabhängig von der Reihenfolge der geladenen PHP-Konstanten
    // zuverlässig registriert werden kann.
    private const MESSAGE_KERNEL_STARTED = 10001;
    private const MESSAGE_INSTANCE_STATUS_CHANGED = 10505;
    private const KERNEL_READY = 10103;
    private const STARTUP_VALIDATION_GRACE_SECONDS = 30;

    public function Create(): void
    {
        parent::Create();

        // Eigene, interaktive Kachel über das offizielle Symcon HTML-SDK.
        $this->SetVisualizationType(1);

        $this->RegisterPropertyString('ProjectName', 'Jalousie Wohnzimmer');
        $this->RegisterPropertyBoolean('ModuleEnabled', true);
        $this->RegisterPropertyInteger('LCNSendModuleID', 0);
        $this->RegisterPropertyInteger('LCNActorModuleID', 0);
        $this->RegisterPropertyInteger('RelayUpVariableID', 0);
        $this->RegisterPropertyInteger('RelayDownVariableID', 0);
        $this->RegisterPropertyInteger('GT8LongUpVariableID', 0);
        $this->RegisterPropertyInteger('GT8LongDownVariableID', 0);
        $this->RegisterPropertyString('TSShortUp', 'K---00010000');
        $this->RegisterPropertyString('TSShortDown', 'K---00000100');
        $this->RegisterPropertyBoolean('TSMappingConfirmed', false);

        // Aus Kompatibilitätsgründen bleiben die Property-Namen erhalten:
        // TotalTravelMs = Gesamtzeit 100 % ZU -> 0 % AUF inkl. voller Wendezeit.
        // BlindTravelMs = Gesamtzeit 0 % AUF -> 100 % ZU.
        $this->RegisterPropertyInteger('TotalTravelMs', 182000);
        $this->RegisterPropertyInteger('TurnMs', 6500);
        $this->RegisterPropertyInteger('SoftStartMs', 6000);
        $this->RegisterPropertyInteger('SoftStopUpMs', 4500);
        $this->RegisterPropertyInteger('SoftStopDownMs', 4500);
        $this->RegisterPropertyInteger('BlindTravelMs', 175500);
        $this->RegisterPropertyInteger('ReferenceReserveMs', 5000);
        $this->RegisterPropertyInteger('MaxTravelMs', 187000);
        $this->RegisterPropertyInteger('ShakeFreeMs', 6500);
        $this->RegisterPropertyInteger('ShakeFreePauseMs', 500);
        $this->RegisterPropertyInteger('CalibrationWindowMs', 30000);
        $this->RegisterPropertyInteger('RelayConfirmMs', 2500);
        $this->RegisterPropertyInteger('StopConfirmMs', 3000);
        $this->RegisterPropertyInteger('LateStartGuardMs', 5000);
        $this->RegisterPropertyInteger('WorkerWindowMs', 1500);
        $this->RegisterPropertyInteger('StatusSyncMs', 1500);
        $this->RegisterPropertyInteger('RelayCoalesceMs', 100);
        $this->RegisterPropertyInteger('HealthcheckSeconds', 10);

        $this->RegisterPropertyFloat('PositionTolerance', 0.5);
        $this->RegisterPropertyFloat('SlatTolerance', 0.5);
        $this->RegisterPropertyBoolean('AllowUnreferenced', false);
        $this->RegisterPropertyBoolean('RequestStatusOnStart', true);
        $this->RegisterPropertyBoolean('DiagnosticLog', false);
        $this->RegisterPropertyBoolean('ShowTechnicalObjects', true);

        $this->RegisterAttributeString('GeneratedVersion', '');
        $this->RegisterAttributeString('LastValidation', '');
        $this->RegisterAttributeBoolean('FaultLatched', false);
        $this->RegisterAttributeString('FaultMessage', '');
        $this->RegisterAttributeBoolean('RuntimeEnabled', false);
        $this->RegisterAttributeBoolean('ReferenceValid', false);
        $this->RegisterAttributeFloat('ReferencePosition', 0.0);
        $this->RegisterAttributeFloat('ReferenceSlat', 0.0);
        $this->RegisterAttributeInteger('ReferenceTimestamp', 0);
        $this->RegisterAttributeString('ReferenceReason', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Create() läuft bei bereits vorhandenen Instanzen nach einem Update
        // nicht erneut. Deshalb wird der Visualisierungstyp hier ebenfalls
        // gesetzt, damit die HTML-Kachel zuverlässig aktiviert wird.
        $this->SetVisualizationType(1);

        try {
            $this->registerRuntimeMessages();
            $this->SetBuffer('StartupValidationDeadline', '0');
            $previousGeneratedVersion = $this->ReadAttributeString('GeneratedVersion');
            $this->ensureProfiles();
            $this->ensureInstanceVisualizationVariables();
            $ids = $this->ensureObjectTree();
            $this->ensureRuntimeScripts($ids['scripts']);
            $this->synchronizeConfiguration($ids['configuration']);
            $this->migratePersistentReference($ids['state'], $previousGeneratedVersion);
            $this->invalidateReferenceAfterModelUpdate($ids['state'], $previousGeneratedVersion);
            $this->restorePersistentReference($ids['state']);
            $this->applySafetyMigration($ids['control'], $ids['state'], $previousGeneratedVersion);
            $this->ensureHardwareLinks($ids['lcn']);
            $this->ensureEvents($ids['scripts']);
            $this->ensureVisualizationLinks($ids['visualization'], $ids['control'], $ids['state']);
            $this->applyVisibility($ids);

            $kernelReady = $this->kernelIsReady();
            $staticValidation = $this->validateConfiguration(false, false);
            $runtimeValidation = $kernelReady
                ? $this->validateConfiguration(true, true)
                : $staticValidation;
            $runtimeDependencyUnavailable = $this->isRuntimeDependencyUnavailable(
                $staticValidation,
                $runtimeValidation
            );
            $startupWaiting = $staticValidation['status'] === self::STATUS_ACTIVE
                && (!$kernelReady
                    || ($runtimeDependencyUnavailable && $this->kernelIsWithinStartupGrace()));
            $validation = $kernelReady ? $runtimeValidation : $staticValidation;

            if ($startupWaiting) {
                $this->SetBuffer(
                    'StartupValidationDeadline',
                    (string) (time() + self::STARTUP_VALIDATION_GRACE_SECONDS)
                );
            }

            $this->WriteAttributeString('LastValidation', json_encode($validation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $moduleEnabled = $this->ReadPropertyBoolean('ModuleEnabled');
            $faultLatched = $this->ReadAttributeBoolean('FaultLatched');
            $wasRuntimeEnabled = $this->ReadAttributeBoolean('RuntimeEnabled');
            $runtimeEnabled = false;

            if ($faultLatched) {
                $this->SetStatus(self::STATUS_FAULT_LATCHED);
                $this->SetSummary('inaktiv · Fehler quittieren');
            } elseif (!$moduleEnabled) {
                $this->SetStatus(104);
                $this->SetSummary('inaktiv · nur LCN-Bedienung');
            } elseif ($startupWaiting) {
                // Während KR_INIT beziehungsweise kurz nach KR_READY sind
                // abhängige LCN-Instanzen und deren PHP-Funktionen noch nicht
                // zwingend verfügbar. Die gespeicherte Konfiguration bleibt
                // gültig; Bedienbefehle sind bis zur Abschlussprüfung gesperrt.
                $this->SetStatus(self::STATUS_ACTIVE);
                $this->SetSummary('bereit · Startprüfung');
            } elseif ($runtimeDependencyUnavailable) {
                // Eine gültig gespeicherte Konfiguration wird nicht als
                // fehlerhaft markiert, nur weil die abhängige LCN-Laufzeit
                // momentan noch nicht bereit ist. Bedienung bleibt gesperrt.
                $this->SetStatus(self::STATUS_ACTIVE);
                $this->SetSummary('bereit · LCN nicht verfügbar');
            } else {
                $this->SetStatus($validation['status']);
                $this->SetSummary($validation['status'] === self::STATUS_ACTIVE ? 'bereit' : 'Konfiguration unvollständig');
                $runtimeEnabled = $validation['status'] === self::STATUS_ACTIVE;
            }

            $this->setFaultStateVariable($faultLatched);
            $this->setRuntimeEnabled($runtimeEnabled, $ids['scripts']);
            $this->WriteAttributeBoolean('RuntimeEnabled', $runtimeEnabled);

            if ($kernelReady
                && ($startupWaiting || $runtimeDependencyUnavailable)
                && $moduleEnabled
                && !$faultLatched) {
                $healthcheckID = @IPS_GetObjectIDByIdent('Healthcheck', $ids['scripts']);
                if ($healthcheckID !== false && IPS_ScriptExists((int) $healthcheckID)) {
                    IPS_SetScriptTimer(
                        (int) $healthcheckID,
                        $startupWaiting
                            ? 1
                            : max(1, $this->ReadPropertyInteger('HealthcheckSeconds'))
                    );
                }
            }

            if ($kernelReady
                && $wasRuntimeEnabled
                && !$runtimeEnabled
                && !$startupWaiting
                && !$runtimeDependencyUnavailable) {
                $this->invalidateReferenceWithoutCommand($ids['state'], 'Symcon-Steuerung deaktiviert; lokale LCN-Bedienung bleibt frei');
            }

            if ($kernelReady && $runtimeEnabled && !$wasRuntimeEnabled) {
                $controllerID = @IPS_GetObjectIDByIdent('Controller', $ids['scripts']);
                if ($controllerID !== false && IPS_ScriptExists((int) $controllerID)) {
                    IPS_RunScriptWaitEx((int) $controllerID, ['ACTION' => 'INITIALIZE']);
                }
            }

            $this->SyncVisualization();
            $this->WriteAttributeString('GeneratedVersion', self::VERSION);
        } catch (Throwable $e) {
            $this->WriteAttributeBoolean('FaultLatched', true);
            $this->WriteAttributeString('FaultMessage', 'Aufbaufehler: ' . $e->getMessage());
            $this->WriteAttributeBoolean('RuntimeEnabled', false);
            $scriptsCategoryID = @IPS_GetObjectIDByIdent('06_Skripte', $this->InstanceID);
            if ($scriptsCategoryID !== false) {
                try {
                    $this->setRuntimeEnabled(false, (int) $scriptsCategoryID);
                } catch (Throwable) {
                    // Best effort: Ein beschädigter Objektbaum darf die
                    // Fehlerverriegelung nicht erneut scheitern lassen.
                }
            }
            $this->SetStatus(self::STATUS_FAULT_LATCHED);
            $this->SetSummary('inaktiv · Aufbaufehler quittieren');
            $this->SendDebug('ApplyChanges', $e->getMessage(), 0);
            IPS_LogMessage('LCN Jalousie #' . $this->InstanceID, $e->getMessage());
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === self::MESSAGE_KERNEL_STARTED) {
            $this->SetBuffer(
                'StartupValidationDeadline',
                (string) (time() + self::STARTUP_VALIDATION_GRACE_SECONDS)
            );
            $this->CompleteStartupValidation();
            return;
        }

        if ($Message !== self::MESSAGE_INSTANCE_STATUS_CHANGED || !$this->kernelIsReady()) {
            return;
        }

        if (!in_array($SenderID, $this->runtimeDependencyInstanceIDs(), true)) {
            return;
        }

        $this->CompleteStartupValidation();
    }

    public function CompleteStartupValidation(): void
    {
        if (!$this->kernelIsReady()) {
            return;
        }

        try {
            $scriptsCategoryID = @IPS_GetObjectIDByIdent('06_Skripte', $this->InstanceID);
            if ($scriptsCategoryID === false) {
                return;
            }

            $staticValidation = $this->validateConfiguration(false, false);
            $runtimeValidation = $this->validateConfiguration(false, true);
            $deadline = (int) $this->GetBuffer('StartupValidationDeadline');
            $withinGrace = $deadline > 0 && time() < $deadline;
            $runtimeDependencyUnavailable = $this->isRuntimeDependencyUnavailable(
                $staticValidation,
                $runtimeValidation
            );

            $this->WriteAttributeString(
                'LastValidation',
                json_encode($runtimeValidation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            if ($runtimeDependencyUnavailable && $withinGrace) {
                // Keine falsche Konfigurationsmeldung während LCN/PCHK seine
                // Instanzen nach dem Kernelstart noch aktiviert. Bedienung und
                // Ereignisse bleiben bis zur erfolgreichen Prüfung gesperrt.
                $this->WriteAttributeBoolean('RuntimeEnabled', false);
                $this->setRuntimeEnabled(false, (int) $scriptsCategoryID);
                $healthcheckID = @IPS_GetObjectIDByIdent('Healthcheck', (int) $scriptsCategoryID);
                if ($healthcheckID !== false && IPS_ScriptExists((int) $healthcheckID)) {
                    // Das vorhandene Healthcheck-Skript übernimmt während der
                    // Startphase die kurze Wiederholungsprüfung. Dadurch ist
                    // kein neu zu migrierender Modultimer erforderlich.
                    IPS_SetScriptTimer((int) $healthcheckID, 1);
                }

                if ($this->ReadAttributeBoolean('FaultLatched')) {
                    $this->SetStatus(self::STATUS_FAULT_LATCHED);
                    $this->SetSummary('inaktiv · Fehler quittieren');
                } elseif (!$this->ReadPropertyBoolean('ModuleEnabled')) {
                    $this->SetStatus(104);
                    $this->SetSummary('inaktiv · nur LCN-Bedienung');
                } else {
                    $this->SetStatus(self::STATUS_ACTIVE);
                    $this->SetSummary('bereit · Startprüfung');
                }

                $this->SyncVisualization();
                return;
            }

            // Im normalen Betrieb prüft auch der bestehende Healthcheck
            // die Abhängigkeiten. Solange alles weiterhin bereit ist, werden
            // weder Ereignisse noch der eventuell laufende 1-s-Worker neu
            // gesetzt oder unterbrochen.
            if ($runtimeValidation['status'] === self::STATUS_ACTIVE
                && $this->ReadPropertyBoolean('ModuleEnabled')
                && !$this->ReadAttributeBoolean('FaultLatched')
                && $this->ReadAttributeBoolean('RuntimeEnabled')
                && $this->GetStatus() === self::STATUS_ACTIVE) {
                $this->SetBuffer('StartupValidationDeadline', '0');
                return;
            }

            $this->SetBuffer('StartupValidationDeadline', '0');

            $wasRuntimeEnabled = $this->ReadAttributeBoolean('RuntimeEnabled');
            $runtimeEnabled = false;

            if ($this->ReadAttributeBoolean('FaultLatched')) {
                $this->SetStatus(self::STATUS_FAULT_LATCHED);
                $this->SetSummary('inaktiv · Fehler quittieren');
            } elseif (!$this->ReadPropertyBoolean('ModuleEnabled')) {
                $this->SetStatus(104);
                $this->SetSummary('inaktiv · nur LCN-Bedienung');
            } elseif ($runtimeDependencyUnavailable) {
                // Eine gültig gespeicherte Konfiguration wird nicht als
                // fehlerhaft markiert, nur weil die abhängige LCN-Laufzeit
                // momentan noch nicht bereit ist. Bedienung bleibt gesperrt.
                $this->SetStatus(self::STATUS_ACTIVE);
                $this->SetSummary('bereit · LCN nicht verfügbar');
            } else {
                $this->SetStatus($runtimeValidation['status']);
                $this->SetSummary(
                    $runtimeValidation['status'] === self::STATUS_ACTIVE
                        ? 'bereit'
                        : 'Konfiguration unvollständig'
                );
                $runtimeEnabled = $runtimeValidation['status'] === self::STATUS_ACTIVE;
            }

            $this->setRuntimeEnabled($runtimeEnabled, (int) $scriptsCategoryID);
            $this->WriteAttributeBoolean('RuntimeEnabled', $runtimeEnabled);

            if (!$runtimeEnabled
                && $runtimeDependencyUnavailable
                && $this->ReadPropertyBoolean('ModuleEnabled')
                && !$this->ReadAttributeBoolean('FaultLatched')) {
                $healthcheckID = @IPS_GetObjectIDByIdent('Healthcheck', (int) $scriptsCategoryID);
                if ($healthcheckID !== false && IPS_ScriptExists((int) $healthcheckID)) {
                    // Auch nach Ablauf der Startkulanz automatisch weiterprüfen.
                    // Ein erneutes Speichern der unveränderten Konfiguration ist
                    // dadurch nicht mehr erforderlich.
                    IPS_SetScriptTimer(
                        (int) $healthcheckID,
                        max(1, $this->ReadPropertyInteger('HealthcheckSeconds'))
                    );
                }
            }

            // Eine vorübergehend noch nicht aktive LCN-Instanz darf weder die
            // gespeicherten Property-Werte noch eine persistente Referenz
            // löschen. Sobald alle Abhängigkeiten bereit sind, wird der
            // Controller genau einmal initialisiert.
            if ($runtimeEnabled && !$wasRuntimeEnabled) {
                $controllerID = @IPS_GetObjectIDByIdent('Controller', (int) $scriptsCategoryID);
                if ($controllerID !== false && IPS_ScriptExists((int) $controllerID)) {
                    IPS_RunScriptWaitEx((int) $controllerID, ['ACTION' => 'INITIALIZE']);
                }
            }

            $this->SyncVisualization();
        } catch (Throwable $e) {
            $this->SendDebug('StartupValidation', $e->getMessage(), 0);
            $this->LogMessage('Startprüfung fehlgeschlagen: ' . $e->getMessage(), 10204);
        }
    }

    public function GetConfigurationForm(): string
    {
        // Das Formular bewertet die gespeicherten Werte ausschließlich
        // strukturell. Ein noch startendes oder vorübergehend getrenntes
        // LCN-Modul darf nicht als fehlende Konfiguration erscheinen.
        $validation = $this->validateConfiguration(false, false);
        $runtimeValidation = $this->kernelIsReady()
            ? $this->validateConfiguration(false, true)
            : $validation;
        $runtimeDependencyUnavailable = $this->isRuntimeDependencyUnavailable(
            $validation,
            $runtimeValidation
        );
        $messages = $validation['messages'];
        $faultLatched = $this->ReadAttributeBoolean('FaultLatched');
        $summary = $faultLatched
            ? 'FEHLER VERRIEGELT: ' . ($this->ReadAttributeString('FaultMessage') ?: 'Fehler quittieren. Bis dahin sendet Symcon keine LCN-Befehle.')
            : ($messages === []
                ? ($runtimeDependencyUnavailable
                    ? 'Die gespeicherte Konfiguration ist vollständig. LCN ist momentan noch nicht betriebsbereit; die automatische Prüfung läuft weiter.'
                    : 'Die gespeicherte Konfiguration ist vollständig. Vor dem Motorbetrieb die LCN-PRO-Verriegelung und die TS-Belegung am Bus prüfen.')
                : "Noch zu erledigen:\n• " . implode("\n• ", $messages));

        $softStopRangePercent = static function (int $travelMs, int $softStopMs): float {
            if ($travelMs <= 0 || $softStopMs <= 0 || $softStopMs >= $travelMs) {
                return 0.0;
            }
            // Bei linearer Verzögerung ist der Weg in der Sanft-Stopp-Phase
            // eine Dreiecksfläche. Anteil = S / (2*T - S).
            return 100.0 * $softStopMs / (2.0 * $travelMs - $softStopMs);
        };
        $blindTravelUpMs = max(0, $this->ReadPropertyInteger('TotalTravelMs') - $this->ReadPropertyInteger('TurnMs'));
        $blindTravelDownMs = max(0, $this->ReadPropertyInteger('BlindTravelMs'));
        $softStopUpPercent = $softStopRangePercent($blindTravelUpMs, $this->ReadPropertyInteger('SoftStopUpMs'));
        $softStopDownPercent = $softStopRangePercent($blindTravelDownMs, $this->ReadPropertyInteger('SoftStopDownMs'));
        $softStopRangeCaption = sprintf(
            'Berechneter Sanft-Stopp-Fahrweg: AUF 0–%s %% und ZU %s–100 %% (aus Gesamt-/Behanglaufzeit und Sanft-Stopp-Zeit). Zwischenziele innerhalb dieser Endzonen werden mit dem bereits verlangsamten Fahrprofil berechnet; es gibt keine zusätzliche Ziel-Abbremsung.',
            number_format($softStopUpPercent, 2, ',', '.'),
            number_format(100.0 - $softStopDownPercent, 2, ',', '.')
        );

        $form = [
            'elements' => [
                [
                    'type' => 'ExpansionPanel',
                    'caption' => '1. Allgemein',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'ValidationTextBox', 'name' => 'ProjectName', 'caption' => 'Name der Jalousie'],
                        ['type' => 'CheckBox', 'name' => 'ModuleEnabled', 'caption' => 'Symcon-Steuerung aktiv (AUS = keine Befehle/Ereignisse; lokale LCN-Bedienung bleibt frei)'],
                        ['type' => 'CheckBox', 'name' => 'ShowTechnicalObjects', 'caption' => 'Technische Unterkategorien und Skripte im Objektbaum anzeigen'],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => '2. LCN-Zuordnung – Pflichtfelder',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Wählen Sie vorhandene LCN-Objekte aus. Das Modul legt keine LCN-Verbindung und keine LCN-PRO-Programmierung an.'],
                        ['type' => 'SelectInstance', 'name' => 'LCNSendModuleID', 'caption' => 'LCN-Sendemodul für virtuelle TS-Tasten (Haupt-UPU des GT8, z. B. M22)'],
                        ['type' => 'SelectInstance', 'name' => 'LCNActorModuleID', 'caption' => 'LCN-Aktormodul mit Motorrelais (z. B. M93)'],
                        ['type' => 'SelectVariable', 'name' => 'RelayUpVariableID', 'caption' => 'Reale Relaisstatusvariable AUF', 'validVariableTypes' => [0]],
                        ['type' => 'SelectVariable', 'name' => 'RelayDownVariableID', 'caption' => 'Reale Relaisstatusvariable ZU', 'validVariableTypes' => [0]],
                        ['type' => 'Label', 'caption' => 'GT8-LANG: Der simulierte Ausgang 3/4 darf von einem beliebigen freien UPU stammen. Er muss nicht zum Haupt-UPU, Sendemodul oder Aktormodul gehören. In LCN-PRO muss der ausgewählte Ausgang jedoch als zweites Ziel der korrekten GT8-Taste am Haupt-UPU programmiert sein.'],
                        ['type' => 'SelectVariable', 'name' => 'GT8LongUpVariableID', 'caption' => 'GT8 LANG AUF – Status eines frei wählbaren simulierten Ausgangs 3', 'validVariableTypes' => [0]],
                        ['type' => 'SelectVariable', 'name' => 'GT8LongDownVariableID', 'caption' => 'GT8 LANG ZU – Status eines frei wählbaren simulierten Ausgangs 4', 'validVariableTypes' => [0]],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => '3. Virtuelle LCN-Tasten – erst nach Busprüfung freigeben',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'ValidationTextBox', 'name' => 'TSShortUp', 'caption' => 'TS-Datenfeld KURZ AUF', 'validate' => '^[K-]{4}[01]{8}$'],
                        ['type' => 'ValidationTextBox', 'name' => 'TSShortDown', 'caption' => 'TS-Datenfeld KURZ ZU', 'validate' => '^[K-]{4}[01]{8}$'],
                        ['type' => 'CheckBox', 'name' => 'TSMappingConfirmed', 'caption' => 'Ich habe beide TS-Datenfelder mit LCN-PRO/PCHK-Busmonitor bestätigt'],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => '4. Laufzeiten',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'TotalTravelMs', 'caption' => 'Gesamtlaufzeit 100 % ZU → 0 % AUF (inkl. vollständiger Lamellenwendung)', 'suffix' => ' ms', 'minimum' => 1000],
                        ['type' => 'NumberSpinner', 'name' => 'BlindTravelMs', 'caption' => 'Gesamtlaufzeit 0 % AUF → 100 % ZU', 'suffix' => ' ms', 'minimum' => 1000],
                        ['type' => 'NumberSpinner', 'name' => 'TurnMs', 'caption' => 'Volle Wendezeit / Richtungswechsel', 'suffix' => ' ms', 'minimum' => 100],
                        ['type' => 'NumberSpinner', 'name' => 'SoftStartMs', 'caption' => 'Sanftanlauf aus Zwischenposition bei gleicher Richtung', 'suffix' => ' ms', 'minimum' => 0],
                        ['type' => 'NumberSpinner', 'name' => 'SoftStopUpMs', 'caption' => 'Sanft-Stopp vor Endlage AUF (0 %)', 'suffix' => ' ms', 'minimum' => 0],
                        ['type' => 'NumberSpinner', 'name' => 'SoftStopDownMs', 'caption' => 'Sanft-Stopp vor Endlage ZU (100 %)', 'suffix' => ' ms', 'minimum' => 0],
                        ['type' => 'NumberSpinner', 'name' => 'ReferenceReserveMs', 'caption' => 'Referenzreserve', 'suffix' => ' ms', 'minimum' => 0],
                        ['type' => 'NumberSpinner', 'name' => 'MaxTravelMs', 'caption' => 'Maximale überwachte Fahrt', 'suffix' => ' ms', 'minimum' => 1000],
                        ['type' => 'NumberSpinner', 'name' => 'ShakeFreeMs', 'caption' => 'ShakeFree nach Endlage ZU – Gegenfahrt', 'suffix' => ' ms', 'minimum' => 100],
                        ['type' => 'NumberSpinner', 'name' => 'ShakeFreePauseMs', 'caption' => 'Umschaltpause vor ShakeFree nach Endlage ZU', 'suffix' => ' ms', 'minimum' => 0, 'maximum' => 3000],
                        ['type' => 'NumberSpinner', 'name' => 'CalibrationWindowMs', 'caption' => 'Zeitverzögerung / Kalibrierfenster nach 100 % ZU vor STOP und ShakeFree', 'suffix' => ' ms', 'minimum' => 30000, 'maximum' => 120000],
                        ['type' => 'NumberSpinner', 'name' => 'PositionTolerance', 'caption' => 'Positionstoleranz', 'suffix' => ' %', 'digits' => 1, 'minimum' => 0.1, 'maximum' => 10],
                        ['type' => 'NumberSpinner', 'name' => 'SlatTolerance', 'caption' => 'Lamellentoleranz', 'suffix' => ' %', 'digits' => 1, 'minimum' => 0.1, 'maximum' => 10],
                        ['type' => 'Label', 'caption' => 'Richtungsabhängige Positionsrechnung: Für AUF wird aus der Gesamtzeit 100→0 die volle Wendezeit abgezogen; für ZU wird die Gesamtzeit 0→100 direkt als Behanglaufzeit verwendet.'],
                        ['type' => 'Label', 'caption' => $softStopRangeCaption],
                        ['type' => 'Label', 'caption' => 'Sanft-Stopp ist positionsabhängig: Er beginnt an der aus den Laufzeiten berechneten Prozentgrenze. Ein Zwischenziel außerhalb der Endzone fährt vollständig mit voller Geschwindigkeit; ein Zwischenziel innerhalb der Endzone enthält genau den bis zu dieser Position durchfahrenen Anteil der linearen Verzögerung. 0 ms deaktiviert die jeweilige Korrektur.'],
                        ['type' => 'Label', 'caption' => 'Bewegungsmodell: 0 % → ZU ohne Vorlauf; 100 % → AUF mit voller Wendezeit; Zwischenposition gleiche Richtung mit Sanftanlauf; Gegenrichtung mit dem längeren Wert aus Sanftanlauf und Rest-Wendezeit.'],
                        ['type' => 'Label', 'caption' => 'Die Zeitverzögerung / das Kalibrierfenster läuft nach jeder vollständig von Symcon ausgeführten Fahrt auf 100 % ZU – unabhängig davon, ob ShakeFree aktiviert ist. Währenddessen sendet Symcon keinen STOP und keinen Gegenbefehl. ShakeFree nach Endlage ZU startet – sofern aktiviert – erst nach Ablauf dieser Verzögerung.'],
                        ['type' => 'Label', 'caption' => 'Referenzierung: 0 % AUF und 100 % ZU werden jeweils erst nach der richtungsabhängigen Gesamtzeit plus Referenzreserve als gültige Endlage gespeichert. Die Referenz wird zusätzlich persistent als Modulattribut gesichert und bleibt bei normalem Übernehmen/Rebuild erhalten.'],
                        ['type' => 'Label', 'caption' => 'Nach ShakeFree wird die Lamelle mit dem ZU-KURZ-Befehl auf 100 % zurückgestellt. Dieser Nachlauf wird nach der berechneten Wendezeit einmal gestoppt und erst nach real bestätigtem Relais-AUS als beendet bewertet.'],
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
                        ['type' => 'NumberSpinner', 'name' => 'HealthcheckSeconds', 'caption' => 'Healthcheck / unabhängige STOP-Überwachung', 'suffix' => ' s', 'minimum' => 10, 'maximum' => 300],
                        ['type' => 'CheckBox', 'name' => 'RequestStatusOnStart', 'caption' => 'LCN-Status beim Initialisieren anfordern'],
                        ['type' => 'CheckBox', 'name' => 'AllowUnreferenced', 'caption' => 'Fahrt ohne vorherige Referenz erlauben'],
                        ['type' => 'CheckBox', 'name' => 'DiagnosticLog', 'caption' => 'Ausführliche Diagnose ins Symcon-Protokoll schreiben'],
                        ['type' => 'Label', 'caption' => 'Relais-AUS-Sicherheit: Jede automatische Endlage, jedes ShakeFree-Teilstück und jeder Lamellennachlauf endet mit genau einem richtungsabhängigen KURZ-STOP und einer realen AUS-Bestätigung beider Relais. Bleibt ein Relais aktiv, wird die Instanz verriegelt; ein zweites automatisches Toggle wird wegen der Umschaltgefahr nicht gesendet.'],
                    ],
                ],
            ],
            'actions' => [
                ['type' => 'Label', 'caption' => $summary],
                ['type' => 'Button', 'caption' => 'Gespeicherte Konfiguration prüfen', 'onClick' => 'echo LCNJAL_CheckConfiguration($id);'],
                ['type' => 'Button', 'caption' => 'Objektbaum und Skripte neu aufbauen', 'onClick' => 'LCNJAL_Rebuild($id); echo "Objektbaum wurde geprüft und aktualisiert.";'],
                ['type' => 'Button', 'caption' => 'LCN-Status anfordern', 'onClick' => 'LCNJAL_RequestLCNStatus($id); echo "Statusanforderung wurde gesendet.";'],
                ['type' => 'Button', 'caption' => 'Fehler quittieren (nur bei Relais AUS)', 'onClick' => 'echo LCNJAL_AcknowledgeFault($id);'],
                ['type' => 'Button', 'caption' => 'Diagnose anzeigen', 'onClick' => 'echo LCNJAL_GetDiagnostics($id);'],
            ],
            'status' => [
                ['code' => self::STATUS_ACTIVE, 'icon' => 'active', 'caption' => 'Gespeicherte Konfiguration vollständig'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Symcon-Steuerung deaktiviert – lokale LCN-Bedienung bleibt aktiv'],
                ['code' => self::STATUS_SEND_MODULE_MISSING, 'icon' => 'error', 'caption' => 'LCN-Sendemodul fehlt oder ist ungültig'],
                ['code' => self::STATUS_ACTOR_MODULE_MISSING, 'icon' => 'error', 'caption' => 'LCN-Aktormodul fehlt oder ist ungültig'],
                ['code' => self::STATUS_RELAY_UP_INVALID, 'icon' => 'error', 'caption' => 'Relaisstatus AUF fehlt, ist nicht Boolean oder ist nicht mit dem Aktormodul verbunden'],
                ['code' => self::STATUS_RELAY_DOWN_INVALID, 'icon' => 'error', 'caption' => 'Relaisstatus AB fehlt, ist nicht Boolean oder ist nicht mit dem Aktormodul verbunden'],
                ['code' => self::STATUS_GT8_INVALID, 'icon' => 'error', 'caption' => 'GT8-LANG-Variablen fehlen oder sind nicht Boolean'],
                ['code' => self::STATUS_DUPLICATE_OBJECTS, 'icon' => 'error', 'caption' => 'AUF/AB-Zuordnungen sind identisch'],
                ['code' => self::STATUS_TS_INVALID, 'icon' => 'error', 'caption' => 'TS-Datenfelder ungültig oder noch nicht bestätigt'],
                ['code' => self::STATUS_TIMING_INVALID, 'icon' => 'error', 'caption' => 'Zeitparameter sind widersprüchlich'],
                ['code' => self::STATUS_LCN_FUNCTION_MISSING, 'icon' => 'error', 'caption' => 'Benötigte LCN-Funktionen fehlen'],
                ['code' => self::STATUS_STRUCTURE_ERROR, 'icon' => 'error', 'caption' => 'Objektbaum oder Laufzeitskripte konnten nicht aufgebaut werden'],
                ['code' => self::STATUS_RELAY_CONFLICT, 'icon' => 'error', 'caption' => 'AUF und AB melden gleichzeitig TRUE – Motorbetrieb gesperrt'],
                ['code' => self::STATUS_FAULT_LATCHED, 'icon' => 'error', 'caption' => 'Fehler verriegelt – Symcon inaktiv bis zur Quittierung'],
            ],
        ];

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function AcknowledgeFault(): string
    {
        $relayUpID = $this->ReadPropertyInteger('RelayUpVariableID');
        $relayDownID = $this->ReadPropertyInteger('RelayDownVariableID');
        if (!$this->isBooleanVariable($relayUpID) || !$this->isBooleanVariable($relayDownID)) {
            return 'Fehlerquittierung abgelehnt: Relaisstatusvariablen sind nicht gültig.';
        }
        if (GetValueBoolean($relayUpID) || GetValueBoolean($relayDownID)) {
            return 'Fehlerquittierung abgelehnt: zuerst beide Relais lokal ausschalten.';
        }

        $scriptsCategoryID = @IPS_GetObjectIDByIdent('06_Skripte', $this->InstanceID);
        if ($scriptsCategoryID === false) {
            return 'Skriptkategorie fehlt.';
        }
        $controllerID = @IPS_GetObjectIDByIdent('Controller', (int) $scriptsCategoryID);
        if ($controllerID === false || !IPS_ScriptExists((int) $controllerID)) {
            return 'Controller-Skript fehlt.';
        }

        IPS_RunScriptWaitEx((int) $controllerID, ['ACTION' => 'RESET_ERROR']);
        $this->WriteAttributeBoolean('FaultLatched', false);
        $this->WriteAttributeString('FaultMessage', '');
        $this->setFaultStateVariable(false);

        $stateCategoryID = @IPS_GetObjectIDByIdent('04_Istwerte', $this->InstanceID);
        if ($stateCategoryID !== false) {
            $this->invalidateReferenceWithoutCommand((int) $stateCategoryID, 'Fehler quittiert; erneute Referenzfahrt erforderlich');
        }

        $validation = $this->validateConfiguration();
        $runtimeEnabled = $this->ReadPropertyBoolean('ModuleEnabled')
            && $validation['status'] === self::STATUS_ACTIVE;
        $this->setRuntimeEnabled($runtimeEnabled, (int) $scriptsCategoryID);
        $this->WriteAttributeBoolean('RuntimeEnabled', $runtimeEnabled);

        if (!$this->ReadPropertyBoolean('ModuleEnabled')) {
            $this->SetStatus(104);
            $this->SetSummary('inaktiv · nur LCN-Bedienung');
            $this->SyncVisualization();
            return 'Fehler quittiert. Die Symcon-Steuerung bleibt über das Modulmenü deaktiviert.';
        }
        if (!$runtimeEnabled) {
            $this->SetStatus($validation['status']);
            $this->SetSummary('Konfiguration unvollständig');
            $this->SyncVisualization();
            return 'Fehler quittiert. Die Konfiguration ist noch nicht vollständig.';
        }

        $this->SetStatus(self::STATUS_ACTIVE);
        $this->SetSummary('bereit · Referenz erforderlich');
        IPS_RunScriptWaitEx((int) $controllerID, ['ACTION' => 'INITIALIZE']);
        $this->SyncVisualization();
        return 'Fehler quittiert. Symcon wurde ohne LCN-Fahrbefehl reaktiviert; eine neue Referenzfahrt ist erforderlich.';
    }

    public function LatchFault(string $message): void
    {
        $message = trim($message) !== '' ? trim($message) : 'Unbekannter Laufzeitfehler';
        $this->WriteAttributeBoolean('FaultLatched', true);
        $this->WriteAttributeString('FaultMessage', $message);
        $this->WriteAttributeBoolean('RuntimeEnabled', false);
        $this->setFaultStateVariable(true);

        $stateCategoryID = @IPS_GetObjectIDByIdent('04_Istwerte', $this->InstanceID);
        if ($stateCategoryID !== false) {
            $this->invalidateReferenceWithoutCommand((int) $stateCategoryID, 'FEHLER VERRIEGELT: ' . $message);
        }
        $controlCategoryID = @IPS_GetObjectIDByIdent('03_Bedienung', $this->InstanceID);
        if ($controlCategoryID !== false) {
            $shakeID = @IPS_GetObjectIDByIdent('ShakeFree_Aktiv', (int) $controlCategoryID);
            if ($shakeID !== false && IPS_VariableExists((int) $shakeID)) {
                SetValueBoolean((int) $shakeID, false);
            }
        }

        $scriptsCategoryID = @IPS_GetObjectIDByIdent('06_Skripte', $this->InstanceID);
        if ($scriptsCategoryID !== false) {
            $this->setRuntimeEnabled(false, (int) $scriptsCategoryID);
        }
        $this->SetStatus(self::STATUS_FAULT_LATCHED);
        $this->SetSummary('inaktiv · Fehler quittieren');
        $this->SyncVisualization();
    }

    public function StoreReference(float $position, float $slat, string $reason = ''): void
    {
        $position = max(0.0, min(100.0, $position));
        $slat = max(0.0, min(100.0, $slat));
        if ($position > 0.5 && $position < 99.5) {
            throw new InvalidArgumentException('Eine Referenz darf nur an 0 % AUF oder 100 % ZU gespeichert werden.');
        }

        $position = $position < 50.0 ? 0.0 : 100.0;
        $slat = $position <= 0.0 ? 0.0 : 100.0;
        $timestamp = time();

        $this->WriteAttributeBoolean('ReferenceValid', true);
        $this->WriteAttributeFloat('ReferencePosition', $position);
        $this->WriteAttributeFloat('ReferenceSlat', $slat);
        $this->WriteAttributeInteger('ReferenceTimestamp', $timestamp);
        $this->WriteAttributeString('ReferenceReason', trim($reason));

        $stateCategoryID = @IPS_GetObjectIDByIdent('04_Istwerte', $this->InstanceID);
        if ($stateCategoryID !== false) {
            $this->writeReferenceObjects((int) $stateCategoryID, true, $position, $slat, $timestamp, $reason);
        }
        $this->SetValue('Position', (int) round($position));
        $this->SetValue('Drehgrad', (int) round($slat));
        $this->SetValue('Referenziert', true);
        $this->SyncVisualization();
    }

    public function InvalidateReference(string $reason = ''): void
    {
        $this->WriteAttributeBoolean('ReferenceValid', false);
        $this->WriteAttributeInteger('ReferenceTimestamp', 0);
        $this->WriteAttributeString('ReferenceReason', trim($reason));

        $stateCategoryID = @IPS_GetObjectIDByIdent('04_Istwerte', $this->InstanceID);
        if ($stateCategoryID !== false) {
            $position = $this->ReadAttributeFloat('ReferencePosition');
            $slat = $this->ReadAttributeFloat('ReferenceSlat');
            $this->writeReferenceObjects((int) $stateCategoryID, false, $position, $slat, 0, $reason);
        }
        $this->SetValue('Referenziert', false);
        $this->SyncVisualization();
    }

    public function IsRuntimePermitted(): bool
    {
        return $this->ReadPropertyBoolean('ModuleEnabled')
            && !$this->ReadAttributeBoolean('FaultLatched')
            && $this->ReadAttributeBoolean('RuntimeEnabled')
            && $this->GetStatus() === self::STATUS_ACTIVE;
    }

    public function IsFaultLatched(): bool
    {
        return $this->ReadAttributeBoolean('FaultLatched');
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'ResetError') {
            $this->AcknowledgeFault();
            return;
        }
        if (!$this->IsRuntimePermitted()) {
            throw new RuntimeException('Jalousiesteuerung ist deaktiviert oder nach einem Fehler verriegelt. Zuerst Modul aktivieren beziehungsweise Fehler quittieren.');
        }
        $validation = $this->validateConfiguration();
        if ($validation['status'] !== self::STATUS_ACTIVE) {
            $message = 'Jalousiesteuerung ist nicht freigegeben: ' . implode(' | ', $validation['messages']);
            $this->LatchFault($message);
            throw new RuntimeException($message);
        }

        $scriptsCategoryID = @IPS_GetObjectIDByIdent('06_Skripte', $this->InstanceID);
        if ($scriptsCategoryID === false) {
            throw new RuntimeException('Skriptkategorie fehlt. Objektbaum neu aufbauen.');
        }
        $controllerID = @IPS_GetObjectIDByIdent('Controller', (int) $scriptsCategoryID);
        if ($controllerID === false || !IPS_ScriptExists((int) $controllerID)) {
            throw new RuntimeException('Controller-Skript fehlt. Objektbaum neu aufbauen.');
        }

        $parameters = match ($Ident) {
            // Slider und Schnellwahltasten nutzen denselben sicheren
            // Controllerpfad. 0 % = vollständig AUF, 100 % = vollständig ZU.
            'Position' => [
                'ACTION' => 'SET_BLIND',
                'VALUE'  => max(0, min(100, (int) round((float) $Value))),
            ],
            'Drehgrad' => [
                'ACTION' => 'SET_SLAT',
                'VALUE'  => max(0, min(100, (int) round((float) $Value))),
            ],
            'ShakeFree' => [
                'ACTION' => 'SET_SHAKEFREE',
                'VALUE'  => (bool) $Value,
            ],
            // Der Controller ermittelt aus den realen Relaisrückmeldungen die
            // aktive Richtung und sendet genau den zugehörigen KURZ-Befehl
            // erneut. Es gibt bewusst keinen künstlichen Universal-STOP.
            'Stop' => [
                'ACTION' => 'STOP',
            ],
            'ResetError' => [
                'ACTION' => 'RESET_ERROR',
            ],
            default => throw new InvalidArgumentException('Unbekannte Visualisierungsaktion: ' . $Ident),
        };

        IPS_RunScriptWaitEx((int) $controllerID, $parameters);
        $this->SyncVisualization();
    }

    public function SyncVisualization(): void
    {
        $stateCategoryID = @IPS_GetObjectIDByIdent('04_Istwerte', $this->InstanceID);
        if ($stateCategoryID === false) {
            return;
        }

        $blindID = @IPS_GetObjectIDByIdent('Ist_Behang', (int) $stateCategoryID);
        $slatID = @IPS_GetObjectIDByIdent('Ist_Lamelle', (int) $stateCategoryID);
        $referencedID = @IPS_GetObjectIDByIdent('Position_Referenziert', (int) $stateCategoryID);
        if ($blindID === false || $slatID === false || $referencedID === false) {
            return;
        }
        if (!IPS_VariableExists((int) $blindID)
            || !IPS_VariableExists((int) $slatID)
            || !IPS_VariableExists((int) $referencedID)) {
            return;
        }

        $position = max(0, min(100, (int) round(GetValueFloat((int) $blindID))));
        $rotation = max(0, min(100, (int) round(GetValueFloat((int) $slatID))));
        $referenced = GetValueBoolean((int) $referencedID);

        $this->SetValue('Position', $position);
        $this->SetValue('Drehgrad', $rotation);
        $this->SetValue('Referenziert', $referenced);

        if ($this->GetStatus() === self::STATUS_ACTIVE) {
            $startupValidationPending = (int) $this->GetBuffer('StartupValidationDeadline') > time();
            $this->SetSummary(
                $this->ReadAttributeBoolean('RuntimeEnabled')
                    ? ($referenced ? 'bereit · Position gültig' : 'bereit · Referenz erforderlich')
                    : ($startupValidationPending
                        ? 'bereit · Startprüfung'
                        : 'bereit · LCN nicht verfügbar')
            );
        }

        // Laufzeitwerte an alle geöffneten HTML-SDK-Kacheln senden.
        // Das Datenformat ist absichtlich ein JSON-Objekt und wird in
        // module.html durch handleMessage() verarbeitet.
        $this->UpdateVisualizationValue($this->getVisualizationStateJson());
    }

    public function GetVisualizationTile(): string
    {
        $path = __DIR__ . '/module.html';
        $html = file_get_contents($path);
        if ($html === false || $html === '') {
            return '<div style="padding:1rem">Visualisierung konnte nicht geladen werden.</div>';
        }

        // GetVisualizationTile() wird initial nur einmal aufgerufen. Der
        // aktuelle Zustand wird deshalb nach dem statischen HTML injiziert.
        // Spätere Änderungen gelangen über UpdateVisualizationValue() zur
        // implementierten JavaScript-Funktion handleMessage().
        $initialState = json_encode(
            $this->getVisualizationStateJson(),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
        );
        if ($initialState === false) {
            $initialState = json_encode('{}');
        }

        return $html
            . '<script>handleMessage('
            . $initialState
            . ');</script>';
    }

    public function CheckConfiguration(): string
    {
        $result = $this->validateConfiguration(false, false);
        $runtimeResult = $this->kernelIsReady()
            ? $this->validateConfiguration(false, true)
            : $result;
        $runtimeDependencyUnavailable = $this->isRuntimeDependencyUnavailable($result, $runtimeResult);
        $lines = ['LCN Jalousie – Konfigurationsprüfung', 'Statuscode: ' . $result['status']];
        if ($result['messages'] === []) {
            $lines[] = 'Ergebnis: gespeicherte Konfiguration vollständig.';
            if ($runtimeDependencyUnavailable) {
                $lines[] = 'Laufzeitstatus: LCN momentan noch nicht betriebsbereit; automatische Prüfung läuft.';
            }
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
        $moduleIDs = array_values(array_unique(array_filter([
            $this->ReadPropertyInteger('LCNSendModuleID'),
            $this->ReadPropertyInteger('LCNActorModuleID'),
            $this->findConnectedLcnModuleForVariable($this->ReadPropertyInteger('GT8LongUpVariableID')),
            $this->findConnectedLcnModuleForVariable($this->ReadPropertyInteger('GT8LongDownVariableID')),
        ], static fn (int $id): bool => $id > 0)));

        foreach ($moduleIDs as $moduleID) {
            if (IPS_InstanceExists($moduleID)) {
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
                'ModuleEnabled' => $this->ReadPropertyBoolean('ModuleEnabled'),
                'FaultLatched' => $this->ReadAttributeBoolean('FaultLatched'),
                'FaultMessage' => $this->ReadAttributeString('FaultMessage'),
                'ReferenceValid' => $this->ReadAttributeBoolean('ReferenceValid'),
                'ReferencePosition' => $this->ReadAttributeFloat('ReferencePosition'),
                'ReferenceSlat' => $this->ReadAttributeFloat('ReferenceSlat'),
                'ReferenceTimestamp' => $this->ReadAttributeInteger('ReferenceTimestamp'),
                'ReferenceReason' => $this->ReadAttributeString('ReferenceReason'),
                'TotalTravelUpMs_100_to_0' => $this->ReadPropertyInteger('TotalTravelMs'),
                'TotalTravelDownMs_0_to_100' => $this->ReadPropertyInteger('BlindTravelMs'),
                'SoftStopUpMs' => $this->ReadPropertyInteger('SoftStopUpMs'),
                'SoftStopDownMs' => $this->ReadPropertyInteger('SoftStopDownMs'),
                'CalibrationDelayMs' => $this->ReadPropertyInteger('CalibrationWindowMs'),
                'LCNSendModuleID' => $this->ReadPropertyInteger('LCNSendModuleID'),
                'LCNActorModuleID' => $this->ReadPropertyInteger('LCNActorModuleID'),
                'RelayUpVariableID' => $this->ReadPropertyInteger('RelayUpVariableID'),
                'RelayDownVariableID' => $this->ReadPropertyInteger('RelayDownVariableID'),
                'GT8LongUpVariableID' => $this->ReadPropertyInteger('GT8LongUpVariableID'),
                'GT8LongDownVariableID' => $this->ReadPropertyInteger('GT8LongDownVariableID'),
                'GT8LongUpSourceModuleID' => $this->findConnectedLcnModuleForVariable($this->ReadPropertyInteger('GT8LongUpVariableID')),
                'GT8LongDownSourceModuleID' => $this->findConnectedLcnModuleForVariable($this->ReadPropertyInteger('GT8LongDownVariableID')),
                'TSMappingConfirmed' => $this->ReadPropertyBoolean('TSMappingConfirmed'),
            ],
            'validation' => $validation,
        ];
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function getVisualizationStateJson(): string
    {
        $json = json_encode(
            $this->getVisualizationState(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );

        return $json === false ? '{}' : $json;
    }

    private function getVisualizationState(): array
    {
        $position = 0.0;
        $rotation = 0.0;
        $driveState = 0;
        $phase = 0;
        $orderType = 0;
        $targetPosition = 0.0;
        $targetRotation = 0.0;
        $referenced = false;
        $shakeFree = false;
        $errorText = '';

        $stateCategoryID = @IPS_GetObjectIDByIdent('04_Istwerte', $this->InstanceID);
        if ($stateCategoryID !== false) {
            $positionID = @IPS_GetObjectIDByIdent('Ist_Behang', (int) $stateCategoryID);
            $rotationID = @IPS_GetObjectIDByIdent('Ist_Lamelle', (int) $stateCategoryID);
            $driveID = @IPS_GetObjectIDByIdent('Fahrstatus', (int) $stateCategoryID);
            $phaseID = @IPS_GetObjectIDByIdent('Phase', (int) $stateCategoryID);
            $referenceID = @IPS_GetObjectIDByIdent('Position_Referenziert', (int) $stateCategoryID);
            $errorID = @IPS_GetObjectIDByIdent('Fehlertext', (int) $stateCategoryID);

            if ($positionID !== false && IPS_VariableExists((int) $positionID)) {
                $position = (float) GetValueFloat((int) $positionID);
            }
            if ($rotationID !== false && IPS_VariableExists((int) $rotationID)) {
                $rotation = (float) GetValueFloat((int) $rotationID);
            }
            if ($driveID !== false && IPS_VariableExists((int) $driveID)) {
                $driveState = (int) GetValueInteger((int) $driveID);
            }
            if ($phaseID !== false && IPS_VariableExists((int) $phaseID)) {
                $phase = (int) GetValueInteger((int) $phaseID);
            }
            if ($referenceID !== false && IPS_VariableExists((int) $referenceID)) {
                $referenced = (bool) GetValueBoolean((int) $referenceID);
            }
            if ($errorID !== false && IPS_VariableExists((int) $errorID)) {
                $errorText = (string) GetValueString((int) $errorID);
            }
        }

        $controlCategoryID = @IPS_GetObjectIDByIdent('03_Bedienung', $this->InstanceID);
        if ($controlCategoryID !== false) {
            $shakeID = @IPS_GetObjectIDByIdent('ShakeFree_Aktiv', (int) $controlCategoryID);
            if ($shakeID !== false && IPS_VariableExists((int) $shakeID)) {
                $shakeFree = (bool) GetValueBoolean((int) $shakeID);
            }
        }

        $internalCategoryID = @IPS_GetObjectIDByIdent('05_Intern', $this->InstanceID);
        if ($internalCategoryID !== false) {
            $orderTypeID = @IPS_GetObjectIDByIdent('Auftragstyp', (int) $internalCategoryID);
            $targetPositionID = @IPS_GetObjectIDByIdent('Ziel_Behang', (int) $internalCategoryID);
            $targetRotationID = @IPS_GetObjectIDByIdent('Ziel_Lamelle', (int) $internalCategoryID);

            if ($orderTypeID !== false && IPS_VariableExists((int) $orderTypeID)) {
                $orderType = (int) GetValueInteger((int) $orderTypeID);
            }
            if ($targetPositionID !== false && IPS_VariableExists((int) $targetPositionID)) {
                $targetPosition = (float) GetValueFloat((int) $targetPositionID);
            }
            if ($targetRotationID !== false && IPS_VariableExists((int) $targetRotationID)) {
                $targetRotation = (float) GetValueFloat((int) $targetRotationID);
            }
        }

        $position = max(0.0, min(100.0, $position));
        $rotation = max(0.0, min(100.0, $rotation));
        $moduleEnabled = $this->ReadPropertyBoolean('ModuleEnabled');
        $faultLatched = $this->ReadAttributeBoolean('FaultLatched');
        $active = $moduleEnabled
            && !$faultLatched
            && $this->ReadAttributeBoolean('RuntimeEnabled')
            && $this->GetStatus() === self::STATUS_ACTIVE;
        $controlsEnabled = $active && !in_array($phase, [7, 9, 10], true);
        $positionTolerance = max(0.1, $this->ReadPropertyFloat('PositionTolerance'));
        $intermediateAllowed = $controlsEnabled && ($referenced || $this->ReadPropertyBoolean('AllowUnreferenced'));
        $moving = in_array($driveState, [1, 2], true);
        $commandActive = in_array($phase, [1, 2, 3, 4, 5, 8, 10], true);
        $stopEnabled = $active
            && ($moving || in_array($phase, [1, 2, 3, 4, 8, 10], true));
        $realRelaysOff = true;
        foreach ([$this->ReadPropertyInteger('RelayUpVariableID'), $this->ReadPropertyInteger('RelayDownVariableID')] as $relayID) {
            if (!$this->isBooleanVariable($relayID) || GetValueBoolean($relayID)) {
                $realRelaysOff = false;
            }
        }
        $faultResetAllowed = $faultLatched && $realRelaysOff;
        $shakeFreeToggleEnabled = $active && !in_array($phase, [7, 9], true);
        $calibrationRemainingSeconds = 0;
        if ($phase === 10) {
            $internalCategoryID = @IPS_GetObjectIDByIdent('05_Intern', $this->InstanceID);
            if ($internalCategoryID !== false) {
                $deadlineID = @IPS_GetObjectIDByIdent('Zielzeit_ms', (int) $internalCategoryID);
                if ($deadlineID !== false && IPS_VariableExists((int) $deadlineID)) {
                    $deadline = GetValueFloat((int) $deadlineID);
                    $nowMs = (float) hrtime(true) / 1_000_000.0;
                    $calibrationRemainingSeconds = (int) max(0, ceil(($deadline - $nowMs) / 1000.0));
                }
            }
        }

        if ($phase === 10) {
            $statusText = 'Kalibrierfenster' . ($calibrationRemainingSeconds > 0 ? ' ' . $calibrationRemainingSeconds . ' s' : '');
            $statusKey = 'calibration';
            $statusIcon = 'clock';
        } elseif ($driveState === 1) {
            $statusText = 'fährt AUF';
            $statusKey = 'up';
            $statusIcon = 'arrow-up';
        } elseif ($driveState === 2) {
            $statusText = 'fährt ZU';
            $statusKey = 'down';
            $statusIcon = 'arrow-down';
        } elseif ($driveState === 3 || $phase === 7 || $errorText !== '') {
            $statusText = 'FEHLER';
            $statusKey = 'error';
            $statusIcon = 'triangle-exclamation';
        } elseif ($referenced && $position <= $positionTolerance) {
            $statusText = 'Geöffnet 100%';
            $statusKey = 'open';
            $statusIcon = 'window-maximize';
        } elseif ($referenced && $position >= 100.0 - $positionTolerance) {
            $statusText = 'Geschlossen 100%';
            $statusKey = 'closed';
            $statusIcon = 'blinds';
        } else {
            $statusText = 'GESTOPPT';
            $statusKey = 'stopped';
            $statusIcon = 'stop';
        }

        return [
            'name' => IPS_GetName($this->InstanceID),
            'position' => round($position, 1),
            'rotation' => round($rotation, 1),
            'shakeFree' => $shakeFree,
            'driveState' => $driveState,
            'phase' => $phase,
            'orderType' => $orderType,
            'targetPosition' => round(max(0.0, min(100.0, $targetPosition)), 1),
            'targetRotation' => round(max(0.0, min(100.0, $targetRotation)), 1),
            'commandActive' => $commandActive,
            'referenced' => $referenced,
            'active' => $active,
            'moduleEnabled' => $moduleEnabled,
            'faultLatched' => $faultLatched,
            'controlsEnabled' => $controlsEnabled,
            'shakeFreeToggleEnabled' => $shakeFreeToggleEnabled,
            'calibrationRemainingSeconds' => $calibrationRemainingSeconds,
            'intermediateAllowed' => $intermediateAllowed,
            'stopEnabled' => $stopEnabled,
            'faultResetAllowed' => $faultResetAllowed,
            'statusText' => $statusText,
            'statusKey' => $statusKey,
            'statusIcon' => $statusIcon,
            'errorText' => $errorText,
        ];
    }

    private function kernelIsReady(): bool
    {
        return IPS_GetKernelRunlevel() === self::KERNEL_READY;
    }

    private function kernelIsWithinStartupGrace(): bool
    {
        $kernelStart = IPS_GetKernelStartTime();
        return $kernelStart > 0
            && time() < $kernelStart + self::STARTUP_VALIDATION_GRACE_SECONDS;
    }

    private function isRuntimeDependencyUnavailable(
        array $staticValidation,
        array $runtimeValidation
    ): bool {
        return $staticValidation['status'] === self::STATUS_ACTIVE
            && in_array($runtimeValidation['status'], [
                self::STATUS_SEND_MODULE_MISSING,
                self::STATUS_ACTOR_MODULE_MISSING,
                self::STATUS_GT8_INVALID,
                self::STATUS_LCN_FUNCTION_MISSING,
            ], true);
    }

    private function registerRuntimeMessages(): void
    {
        $this->RegisterMessage(0, self::MESSAGE_KERNEL_STARTED);
        foreach ($this->runtimeDependencyInstanceIDs() as $instanceID) {
            $this->RegisterMessage($instanceID, self::MESSAGE_INSTANCE_STATUS_CHANGED);
        }
    }

    private function runtimeDependencyInstanceIDs(): array
    {
        return array_values(array_unique(array_filter([
            $this->ReadPropertyInteger('LCNSendModuleID'),
            $this->ReadPropertyInteger('LCNActorModuleID'),
            $this->findConnectedLcnModuleForVariable(
                $this->ReadPropertyInteger('GT8LongUpVariableID'),
                false
            ),
            $this->findConnectedLcnModuleForVariable(
                $this->ReadPropertyInteger('GT8LongDownVariableID'),
                false
            ),
        ], static fn (int $id): bool => $id > 0 && IPS_InstanceExists($id))));
    }

    private function validateConfiguration(
        bool $checkLiveRelayState = true,
        bool $checkRuntimeAvailability = true
    ): array
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

        if ($sendModule > 0 && IPS_InstanceExists($sendModule) && !$this->isUsableLcnModule($sendModule, $checkRuntimeAvailability)) {
            $messages[] = 'Das ausgewählte Sendemodul ist keine aktive LCN-Modul-/Splitterinstanz (Modultyp 2, Status 102).';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_SEND_MODULE_MISSING;
            }
        }
        if ($actorModule > 0 && IPS_InstanceExists($actorModule) && !$this->isUsableLcnModule($actorModule, $checkRuntimeAvailability)) {
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
            $messages[] = 'Reale Relaisstatusvariable ZU auswählen.';
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
        // Die beiden GT8-LANG-Ereignisvariablen dürfen von beliebigen freien
        // LCN-UPU stammen. Entscheidend ist ausschließlich, dass in LCN-PRO
        // der jeweilige simulierte Ausgang als zweites Ziel der korrekten
        // GT8-Taste am Haupt-UPU programmiert ist. Eine Bindung an
        // LCNSendModuleID wäre daher fachlich falsch.

        if ($this->isBooleanVariable($gt8Up) && $this->findConnectedLcnModuleForVariable($gt8Up, $checkRuntimeAvailability) <= 0) {
            $messages[] = 'GT8 LANG AUF ist mit keiner aktiven LCN-Modulinstanz verbunden.';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_GT8_INVALID;
            }
        }
        if ($this->isBooleanVariable($gt8Down) && $this->findConnectedLcnModuleForVariable($gt8Down, $checkRuntimeAvailability) <= 0) {
            $messages[] = 'GT8 LANG ZU ist mit keiner aktiven LCN-Modulinstanz verbunden.';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_GT8_INVALID;
            }
        }

        if ($checkRuntimeAvailability
            && (!IPS_FunctionExists('LCN_SendCommand')
                || ($this->ReadPropertyBoolean('RequestStatusOnStart')
                    && !IPS_FunctionExists('LCN_RequestStatus')))) {
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

        $totalUp = $this->ReadPropertyInteger('TotalTravelMs');
        $totalDown = $this->ReadPropertyInteger('BlindTravelMs');
        $turn = $this->ReadPropertyInteger('TurnMs');
        $softStart = $this->ReadPropertyInteger('SoftStartMs');
        $softStopUp = $this->ReadPropertyInteger('SoftStopUpMs');
        $softStopDown = $this->ReadPropertyInteger('SoftStopDownMs');
        $reserve = $this->ReadPropertyInteger('ReferenceReserveMs');
        $max = $this->ReadPropertyInteger('MaxTravelMs');
        $window = $this->ReadPropertyInteger('WorkerWindowMs');
        $shakePause = $this->ReadPropertyInteger('ShakeFreePauseMs');
        $calibrationWindow = $this->ReadPropertyInteger('CalibrationWindowMs');
        $blindUp = $totalUp - $turn;
        $blindDown = $totalDown;
        if ($totalUp <= $turn || $totalDown <= 0 || $turn <= 0 || $softStart < 0 || $softStart > $turn || $blindUp <= 0 || $blindDown <= 0 || $softStopUp < 0 || $softStopUp >= $blindUp || $softStopDown < 0 || $softStopDown >= $blindDown || $max < max($totalUp, $totalDown) + $reserve || $window < 1000 || $window > 3000 || $shakePause < 0 || $shakePause > 3000 || $calibrationWindow < 30000 || $calibrationWindow > 120000) {
            $messages[] = 'Zeitparameter sind widersprüchlich: Gesamtzeit 100→0 muss größer als die Wendezeit sein; Gesamtzeit 0→100 muss positiv sein; 0 ≤ Sanftanlauf ≤ Wendezeit; Sanft-Stopp AUF/ZU jeweils 0 bis kleiner als die zugehörige Behanglaufzeit; MaxFahrt mindestens längere Richtungs-Gesamtzeit + Reserve; Workerfenster 1000…3000 ms; ShakeFree-Umschaltpause 0…3000 ms; Zeitverzögerung/Kalibrierfenster 30000…120000 ms.';
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

    private function isUsableLcnModule(int $instanceID, bool $requireActiveStatus = true): bool
    {
        if (!IPS_InstanceExists($instanceID)) {
            return false;
        }
        $instance = IPS_GetInstance($instanceID);
        $moduleName = (string) ($instance['ModuleInfo']['ModuleName'] ?? '');
        $moduleType = (int) ($instance['ModuleInfo']['ModuleType'] ?? -1);
        return $moduleType === 2
            && stripos($moduleName, 'LCN') !== false
            && (!$requireActiveStatus || (int) $instance['InstanceStatus'] === self::STATUS_ACTIVE);
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

    private function findConnectedLcnModuleForVariable(
        int $variableID,
        bool $requireActiveStatus = true
    ): int
    {
        if (!IPS_VariableExists($variableID)) {
            return 0;
        }

        $currentObjectID = IPS_GetParent($variableID);
        $logicalVisited = [];

        for ($logicalGuard = 0; $logicalGuard < 32 && $currentObjectID > 0; $logicalGuard++) {
            if (isset($logicalVisited[$currentObjectID])) {
                break;
            }
            $logicalVisited[$currentObjectID] = true;

            if (IPS_InstanceExists($currentObjectID)) {
                $currentInstanceID = $currentObjectID;
                $connectionVisited = [];

                for ($connectionGuard = 0; $connectionGuard < 32 && $currentInstanceID > 0; $connectionGuard++) {
                    if (isset($connectionVisited[$currentInstanceID]) || !IPS_InstanceExists($currentInstanceID)) {
                        break;
                    }
                    $connectionVisited[$currentInstanceID] = true;

                    if ($this->isUsableLcnModule($currentInstanceID, $requireActiveStatus)) {
                        return $currentInstanceID;
                    }

                    $instance = IPS_GetInstance($currentInstanceID);
                    $currentInstanceID = (int) ($instance['ConnectionID'] ?? 0);
                }
            }

            $currentObjectID = IPS_GetParent($currentObjectID);
        }

        return 0;
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

    private function ensureInstanceVisualizationVariables(): void
    {
        $positionCreated = $this->RegisterVariableInteger(
            'Position',
            'Position',
            [
                'PRESENTATION'       => VARIABLE_PRESENTATION_SHUTTER,
                'USAGE_TYPE'         => 0,
                'OPEN_OUTSIDE_VALUE' => 0,
                'CLOSE_INSIDE_VALUE' => 100,
                'SUN_POSITION'       => 1,
            ],
            1
        );
        if ($positionCreated) {
            $this->SetValue('Position', 0);
        }
        $this->EnableAction('Position');

        $rotationCreated = $this->RegisterVariableInteger(
            'Drehgrad',
            'Drehgrad',
            [
                'PRESENTATION'         => VARIABLE_PRESENTATION_SHUTTER,
                'USAGE_TYPE'           => 1,
                'OPEN_OUTSIDE_VALUE'   => 100,
                'CLOSE_INSIDE_VALUE'   => 0,
                'MAX_ROTATION_INSIDE'  => -55,
                'MAX_ROTATION_OUTSIDE' => 55,
                'SUN_POSITION'         => 1,
            ],
            2
        );
        if ($rotationCreated) {
            $this->SetValue('Drehgrad', 0);
        }
        $this->EnableAction('Drehgrad');

        $referencedCreated = $this->RegisterVariableBoolean(
            'Referenziert',
            'Position gültig',
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            ],
            3
        );
        if ($referencedCreated) {
            $this->SetValue('Referenziert', false);
        }
    }

    private function applySafetyMigration(int $controlCategoryID, int $stateCategoryID, string $previousVersion): void
    {
        if ($previousVersion === '' || version_compare($previousVersion, '0.1.12', '>=')) {
            return;
        }

        // Nach dem Hotfix bleibt ShakeFree bewusst AUS, bis der Nutzer die
        // Gegenfahrt mit der neuen Umschaltpause beaufsichtigt erneut testet.
        $shakeID = @IPS_GetObjectIDByIdent('ShakeFree_Aktiv', $controlCategoryID);
        if ($shakeID !== false && IPS_VariableExists((int) $shakeID)) {
            SetValueBoolean((int) $shakeID, false);
        }
        $lastActionID = @IPS_GetObjectIDByIdent('Letzte_Aktion', $stateCategoryID);
        if ($lastActionID !== false && IPS_VariableExists((int) $lastActionID)) {
            SetValueString((int) $lastActionID, 'Sicherheitsupdate auf ' . self::VERSION . ': ShakeFree deaktiviert; Funktion neu testen');
        }
    }

    private function invalidateReferenceAfterModelUpdate(int $stateCategoryID, string $previousVersion): void
    {
        // Version 0.1.14 führt getrennte Richtungs-Gesamtzeiten ein. Ein zuvor
        // in einer Zwischenposition berechneter Wert kann noch auf dem alten,
        // symmetrischen Zeitmodell beruhen. Version 0.1.18 führt zusätzlich
        // die positionsabhängige Sanft-Stopp-Endzone ein. Deshalb ist nach dem
        // Update einmal eine neue Endlagenreferenz erforderlich. Frische
        // Instanzen und spätere reine Updates ab 0.1.18 behalten ihre Referenz.
        if ($previousVersion === '' || version_compare($previousVersion, '0.1.18', '>=')) {
            return;
        }

        $this->persistReferenceInvalid($stateCategoryID, 'Modellupdate auf ' . self::VERSION . ': erneute Endlagenreferenz erforderlich');
        $lastActionID = @IPS_GetObjectIDByIdent('Letzte_Aktion', $stateCategoryID);
        if ($lastActionID !== false && IPS_VariableExists((int) $lastActionID)) {
            SetValueString((int) $lastActionID, 'Modulupdate ' . $previousVersion . ' → ' . self::VERSION . ': erneute Referenzfahrt erforderlich');
        }
        $this->SetValue('Referenziert', false);
    }

    private function setFaultStateVariable(bool $latched): void
    {
        $stateCategoryID = @IPS_GetObjectIDByIdent('04_Istwerte', $this->InstanceID);
        if ($stateCategoryID === false) {
            return;
        }
        $faultID = @IPS_GetObjectIDByIdent('Fehler_Verriegelt', (int) $stateCategoryID);
        if ($faultID !== false && IPS_VariableExists((int) $faultID)) {
            SetValueBoolean((int) $faultID, $latched);
        }
    }

    private function invalidateReferenceWithoutCommand(int $stateCategoryID, string $reason): void
    {
        $this->persistReferenceInvalid($stateCategoryID, $reason);
        $automaticID = @IPS_GetObjectIDByIdent('Automatik_Aktiv', $stateCategoryID);
        if ($automaticID !== false && IPS_VariableExists((int) $automaticID)) {
            SetValueBoolean((int) $automaticID, false);
        }
        $lastActionID = @IPS_GetObjectIDByIdent('Letzte_Aktion', $stateCategoryID);
        if ($lastActionID !== false && IPS_VariableExists((int) $lastActionID)) {
            SetValueString((int) $lastActionID, date('d.m.Y H:i:s') . ' - ' . $reason);
        }
        $this->SetValue('Referenziert', false);
    }

    private function migratePersistentReference(int $stateCategoryID, string $previousVersion): void
    {
        // Ab 0.1.15 wird die Referenz zusätzlich als Modulattribute gespeichert.
        // Damit bleibt sie bei einem normalen Übernehmen/Rebuild sicher erhalten.
        if ($previousVersion === '' || version_compare($previousVersion, '0.1.15', '>=')) {
            return;
        }
        $referencedID = @IPS_GetObjectIDByIdent('Position_Referenziert', $stateCategoryID);
        if ($referencedID === false || !IPS_VariableExists((int) $referencedID) || !GetValueBoolean((int) $referencedID)) {
            return;
        }
        $positionID = @IPS_GetObjectIDByIdent('Ist_Behang', $stateCategoryID);
        $slatID = @IPS_GetObjectIDByIdent('Ist_Lamelle', $stateCategoryID);
        if ($positionID === false || $slatID === false) {
            return;
        }
        $position = GetValueFloat((int) $positionID);
        if ($position > 0.5 && $position < 99.5) {
            return;
        }
        $this->WriteAttributeBoolean('ReferenceValid', true);
        $this->WriteAttributeFloat('ReferencePosition', $position < 50.0 ? 0.0 : 100.0);
        $this->WriteAttributeFloat('ReferenceSlat', $position < 50.0 ? 0.0 : 100.0);
        $this->WriteAttributeInteger('ReferenceTimestamp', time());
        $this->WriteAttributeString('ReferenceReason', 'Migration aus Modulversion ' . $previousVersion);
    }

    private function restorePersistentReference(int $stateCategoryID): void
    {
        $valid = $this->ReadAttributeBoolean('ReferenceValid');
        $position = $this->ReadAttributeFloat('ReferencePosition');
        $slat = $this->ReadAttributeFloat('ReferenceSlat');
        $timestamp = $this->ReadAttributeInteger('ReferenceTimestamp');
        $reason = $this->ReadAttributeString('ReferenceReason');
        $this->writeReferenceObjects($stateCategoryID, $valid, $position, $slat, $timestamp, $reason);
        $this->SetValue('Referenziert', $valid);
        if ($valid) {
            $this->SetValue('Position', (int) round($position));
            $this->SetValue('Drehgrad', (int) round($slat));
        }
    }

    private function persistReferenceInvalid(int $stateCategoryID, string $reason): void
    {
        $this->WriteAttributeBoolean('ReferenceValid', false);
        $this->WriteAttributeInteger('ReferenceTimestamp', 0);
        $this->WriteAttributeString('ReferenceReason', $reason);
        $this->writeReferenceObjects(
            $stateCategoryID,
            false,
            $this->ReadAttributeFloat('ReferencePosition'),
            $this->ReadAttributeFloat('ReferenceSlat'),
            0,
            $reason
        );
    }

    private function writeReferenceObjects(int $stateCategoryID, bool $valid, float $position, float $slat, int $timestamp, string $reason): void
    {
        $referencedID = @IPS_GetObjectIDByIdent('Position_Referenziert', $stateCategoryID);
        if ($referencedID !== false && IPS_VariableExists((int) $referencedID)) {
            SetValueBoolean((int) $referencedID, $valid);
        }
        $positionID = @IPS_GetObjectIDByIdent('Ist_Behang', $stateCategoryID);
        $slatID = @IPS_GetObjectIDByIdent('Ist_Lamelle', $stateCategoryID);
        if ($valid && $positionID !== false && IPS_VariableExists((int) $positionID)) {
            SetValueFloat((int) $positionID, $position);
        }
        if ($valid && $slatID !== false && IPS_VariableExists((int) $slatID)) {
            SetValueFloat((int) $slatID, $slat);
        }
        $endID = @IPS_GetObjectIDByIdent('Referenz_Endlage', $stateCategoryID);
        if ($endID !== false && IPS_VariableExists((int) $endID)) {
            SetValueInteger((int) $endID, $valid ? ($position < 50.0 ? 1 : 2) : 0);
        }
        $timestampID = @IPS_GetObjectIDByIdent('Letzte_Referenzierung', $stateCategoryID);
        if ($timestampID !== false && IPS_VariableExists((int) $timestampID)) {
            SetValueInteger((int) $timestampID, $valid ? $timestamp : 0);
        }
        if ($reason !== '') {
            $lastActionID = @IPS_GetObjectIDByIdent('Letzte_Aktion', $stateCategoryID);
            if ($lastActionID !== false && IPS_VariableExists((int) $lastActionID)) {
                SetValueString((int) $lastActionID, date('d.m.Y H:i:s') . ' - ' . $reason);
            }
        }
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
        $this->profile('LCNJAL.Phase', 1, 0, 10, 0, '', '', 'Clock');
        foreach ([0 => 'Ruhe', 1 => 'Warte Start', 2 => 'Behangfahrt', 3 => 'Lamellenfahrt', 4 => 'ShakeFree', 5 => 'Stoppen', 6 => 'Externe Bedienung', 7 => 'Fehler', 8 => 'Referenzfahrt', 9 => 'Statusabgleich', 10 => 'Kalibrierfenster'] as $v => $n) {
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
        $this->profile('LCNJAL.ReferenceEnd', 1, 0, 2, 0, '', '', 'Shutter');
        IPS_SetVariableProfileAssociation('LCNJAL.ReferenceEnd', 0, 'keine gültige Referenz', 'Warning', 0xC00000);
        IPS_SetVariableProfileAssociation('LCNJAL.ReferenceEnd', 1, '0 % AUF', 'ArrowUp', 0x4F81BD);
        IPS_SetVariableProfileAssociation('LCNJAL.ReferenceEnd', 2, '100 % ZU', 'ArrowDown', 0x70AD47);
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
            ['Modul_Aktiv', 'Symcon-Steuerung aktiv', 0, '~Switch', 15, true, true],
            ['LCN_Sendemodulinstanz_ID', 'LCN-Sendemodulinstanz ID', 1, '', 20, 0, true],
            ['LCN_Aktormodulinstanz_ID', 'LCN-Aktormodulinstanz ID', 1, '', 30, 0, true],
            ['Relais_AUF_ID', 'Relais AUF Variable ID', 1, '', 40, 0, true],
            ['Relais_AB_ID', 'Relais AB Variable ID', 1, '', 50, 0, true],
            ['GT8_LANG_AUF_ID', 'GT8 LANG AUF Variable ID', 1, '', 60, 0, true],
            ['GT8_LANG_AB_ID', 'GT8 LANG AB Variable ID', 1, '', 70, 0, true],
            ['TS_KURZ_AUF', 'TS KURZ AUF', 3, '', 80, '', true],
            ['TS_KURZ_AB', 'TS KURZ AB', 3, '', 90, '', true],
            ['TS_Belegung_bestaetigt', 'TS-Belegung bestätigt', 0, '~Switch', 100, false, true],
            ['Gesamtlaufzeit_ms', 'Gesamtlaufzeit 100→0 AUF inkl. Wendezeit [ms]', 1, '', 110, 0, true],
            ['Wendezeit_ms', 'Volle Wendezeit [ms]', 1, '', 120, 0, true],
            ['Sanftanlauf_ms', 'Sanftanlauf Zwischenposition [ms]', 1, '', 125, 0, true],
            ['Sanftstopp_AUF_ms', 'Sanft-Stopp vor Endlage AUF [ms]', 1, '', 127, 4500, true],
            ['Sanftstopp_ZU_ms', 'Sanft-Stopp vor Endlage ZU [ms]', 1, '', 128, 4500, true],
            ['Behanglaufzeit_ms', 'Gesamtlaufzeit 0→100 ZU [ms]', 1, '', 130, 0, true],
            ['Referenzreserve_ms', 'Referenzreserve [ms]', 1, '', 140, 0, true],
            ['MaxFahrt_ms', 'Maximale Fahrt [ms]', 1, '', 150, 0, true],
            ['ShakeFree_ms', 'ShakeFree nach Endlage ZU – Gegenfahrt [ms]', 1, '', 160, 0, true],
            ['ShakeFree_Pause_ms', 'ShakeFree nach Endlage ZU – Umschaltpause [ms]', 1, '', 165, 0, true],
            ['Kalibrierfenster_ms', 'Zeitverzögerung / Kalibrierfenster nach 100 % ZU [ms]', 1, '', 167, 30000, true],
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
        $this->variable($parentID, 'ShakeFree_Aktiv', 'ShakeFree nach Endlage ZU', 0, '~Switch', 30, false, false);
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
        $this->variable($parentID, 'Referenz_Endlage', 'Letzte Referenz-Endlage', 1, 'LCNJAL.ReferenceEnd', 55, 0, false);
        $this->variable($parentID, 'Letzte_Referenzierung', 'Letzte Referenzierung', 1, '~UnixTimestamp', 57, 0, false);
        $this->variable($parentID, 'Automatik_Aktiv', 'Automatik aktiv', 0, '~Switch', 60, false, false);
        $this->variable($parentID, 'Fehlertext', 'Fehlertext', 3, '', 70, '', false);
        $this->variable($parentID, 'Letzte_Aktion', 'Letzte Aktion', 3, '', 80, 'Noch nicht initialisiert', false);
        $this->variable($parentID, 'Letzte_Fahrtdauer_ms', 'Letzte Fahrtdauer [ms]', 1, '', 90, 0, false);
        $this->variable($parentID, 'Letzte_Statusmeldung', 'Letzte Relaisstatusmeldung', 1, '~UnixTimestamp', 100, 0, false);
        $this->variable($parentID, 'Letzte_Relais_AUS_Bestaetigung', 'Letzte Bestätigung: beide Relais AUS', 1, '~UnixTimestamp', 105, 0, false);
        $this->variable($parentID, 'Fehler_Verriegelt', 'Fehler verriegelt', 0, '~Switch', 110, false, false);
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
            ['Shake_Nachlauf_Aktiv', 'ShakeFree-Lamellen-ZU-Nachlauf aktiv', 0, '~Switch', 290, false, false],
        ];
        foreach ($schema as $v) {
            $this->variable($parentID, ...$v);
        }
    }

    private function synchronizeConfiguration(int $categoryID): void
    {
        $map = [
            'Projektname' => ['ProjectName', 3],
            'Modul_Aktiv' => ['ModuleEnabled', 0],
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
            'Sanftanlauf_ms' => ['SoftStartMs', 1],
            'Sanftstopp_AUF_ms' => ['SoftStopUpMs', 1],
            'Sanftstopp_ZU_ms' => ['SoftStopDownMs', 1],
            'Behanglaufzeit_ms' => ['BlindTravelMs', 1],
            'Referenzreserve_ms' => ['ReferenceReserveMs', 1],
            'MaxFahrt_ms' => ['MaxTravelMs', 1],
            'ShakeFree_ms' => ['ShakeFreeMs', 1],
            'ShakeFree_Pause_ms' => ['ShakeFreePauseMs', 1],
            'Kalibrierfenster_ms' => ['CalibrationWindowMs', 1],
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
            'Controller' => ['10 Controller V11.6', 20, 'Controller.php'],
            'Worker' => ['20 Worker V11.6', 30, 'Worker.php'],
            'Healthcheck' => ['30 Healthcheck V11.6', 40, 'Healthcheck.php'],
            'Diagnose' => ['90 Diagnose V11.6', 90, 'Diagnose.php'],
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
            ['GT8_LANG_AUF', 'GT8 LANG AUF / frei wählbarer simulierter Ausgang 3', $this->ReadPropertyInteger('GT8LongUpVariableID'), 30],
            ['GT8_LANG_AB', 'GT8 LANG ZU / frei wählbarer simulierter Ausgang 4', $this->ReadPropertyInteger('GT8LongDownVariableID'), 40],
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
            ['V_ShakeFree', 'ShakeFree nach Endlage ZU', $this->find($controlID, 'ShakeFree_Aktiv'), 30],
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

        if (!$enabled) {
            $internalCategoryID = @IPS_GetObjectIDByIdent('05_Intern', $this->InstanceID);
            if ($internalCategoryID !== false) {
                $workerActiveID = @IPS_GetObjectIDByIdent('Worker_Aktiv', (int) $internalCategoryID);
                if ($workerActiveID !== false && IPS_VariableExists((int) $workerActiveID)) {
                    SetValueBoolean((int) $workerActiveID, false);
                }
            }
        }
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
