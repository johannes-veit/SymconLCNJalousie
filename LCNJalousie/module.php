<?php

declare(strict_types=1);

/**
 * LCN Jalousie – Symcon 9.0 compatibility module.
 *
 * This module creates and maintains the V12.0 object tree, runtime scripts,
 * events, links and configuration values below one module instance.
 * The motor interlock and local operation remain in LCN-PRO.
 */
class LCNJalousie extends IPSModuleStrict
{
    private const VERSION = '0.1.29';
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
    private const STATUS_BINDING_CONFLICT = 213;
    private const STATUS_LCN_ADDRESS_CONFLICT = 214;

    // Symcon-Nachrichten und Runlevel. Numerisch festgehalten, damit die
    // Startlogik unabhängig von der Reihenfolge der geladenen PHP-Konstanten
    // zuverlässig registriert werden kann.
    private const MESSAGE_KERNEL_STARTED = 10001;
    private const MESSAGE_INSTANCE_STATUS_CHANGED = 10505;
    private const KERNEL_READY = 10103;
    private const STARTUP_VALIDATION_GRACE_SECONDS = 30;
    private const MODULE_ID = '{3057B192-E835-4916-AF1D-D89D6302DF74}';
    private const LCN_MODULE_ID = '{0E31FED6-E465-4621-95D4-AAF2683C41EC}';
    private const STORAGE_SCHEMA_VERSION = 2;

    /**
     * Kompatible Konfigurations-Idents aus V0.1.27. Die Laufzeitskripte lesen
     * diese Werte ab V0.1.28 direkt aus den Modul-Properties; dafür werden
     * keine Spiegelvariablen mehr benötigt.
     */
    private const COMPACT_CONFIG_MAP = [
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
        'Befehlsabstand_ms' => ['CommandSpacingMs', 1],
        'Healthcheck_s' => ['HealthcheckSeconds', 1],
    ];

    /**
     * Interner Zustandsautomat aus V0.1.27. Diese Werte liegen ab V0.1.28
     * kompakt in einem Modulbuffer und werden pro Controller-/Worker-Lauf
     * transaktional gelesen und geschrieben.
     */
    private const COMPACT_RUNTIME_DEFAULTS = [
        'Auftragsnummer' => 0,
        'Auftragstyp' => 0,
        'Erwartete_Richtung' => 0,
        'Startzeit_ms' => 0.0,
        'Start_Behang' => 0.0,
        'Start_Lamelle' => 0.0,
        'Start_Richtung' => 0,
        'Geplante_Dauer_ms' => 0,
        'Zielzeit_ms' => 0.0,
        'Ziel_Behang' => 0.0,
        'Ziel_Lamelle' => 0.0,
        'Folge_Lamelle' => -1,
        'Folge_Richtung' => 0,
        'Stop_Angefordert' => false,
        'Endlage_Hart' => false,
        'Bestaetigung_bis_ms' => 0.0,
        'Stop_bis_ms' => 0.0,
        'Abbruch_bis_ms' => 0.0,
        'Abbruch_Wartet_Auf_Start' => false,
        'Abbruch_Fehlerphase' => false,
        'Pending_Aktion' => 0,
        'Pending_Wert' => 0.0,
        'Pending_Richtung' => 0,
        'Worker_Aktiv' => false,
        'Kernel_Startzeit' => 0,
        'Sync_bis_ms' => 0.0,
        'Sync_Relais_AUF_Empfangen' => false,
        'Sync_Relais_AB_Empfangen' => false,
        'Shake_Nachlauf_Aktiv' => false,
        'Startstatus_Nachfrage_Aktiv' => false,
        'Stopstatus_Nachfrage_Aktiv' => false,
        'Startstatus_Relais_AUF_Empfangen' => false,
        'Startstatus_Relais_AB_Empfangen' => false,
        'Stopstatus_Relais_AUF_Empfangen' => false,
        'Stopstatus_Relais_AB_Empfangen' => false,
        'Stop_Wiederholung_Gesendet' => false,
        'Befehl_gesendet_ms' => 0.0,
        'Externe_Referenz_Gesetzt' => false,
        'Externe_Endlage_bis_ms' => 0.0,
        'Externer_Autostopp_bis_ms' => 0.0,
        'Externer_Autostopp_Aktiv' => false,
        'Fremdbefehl_Quelle' => 0,
        'Fremdbefehl_Erkannt_ms' => 0.0,
    ];

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
        $this->RegisterPropertyInteger('CommandSpacingMs', 100);
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
        $this->RegisterAttributeBoolean('CommandLeaseActive', false);
        $this->RegisterAttributeInteger('CommandLeaseDirection', 0);
        $this->RegisterAttributeInteger('CommandLeaseExpectedState', -1);
        $this->RegisterAttributeString('CommandLeaseStartedMs', '0');
        $this->RegisterAttributeString('ForeignRelayResponse', '');
        $this->RegisterAttributeString('BlockedRoutingFingerprint', '');
        $this->RegisterAttributeString('BlockedRoutingReason', '');
        $this->RegisterAttributeBoolean('RoutingRearmAllowed', false);
        $this->RegisterAttributeInteger('CompactStorageSchemaVersion', 0);
        $this->RegisterAttributeBoolean('CompactMigrationComplete', false);
        $this->RegisterAttributeString('CompactMigrationSourceVersion', '');
        $this->RegisterAttributeString('LegacyV127Snapshot', '');
        $this->RegisterAttributeString('LegacyV127SnapshotHash', '');
        $this->RegisterAttributeInteger('LegacyV127SnapshotCreated', 0);
        $this->RegisterAttributeBoolean('RollbackPrepared', false);
    }

    public function Migrate(string $JSONData): string
    {
        parent::Migrate($JSONData);

        // Migrate() wird von Symcon ausdrücklich beim Modulupdate aufgerufen.
        // Dadurch stehen die ab V0.1.28 benötigten persistenten Attribute auch
        // ohne Dienstneustart bereits im unmittelbar folgenden ApplyChanges
        // bereit. Bestehende Werte werden niemals überschrieben.
        $data = json_decode($JSONData, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Modulpersistenz ist für die V0.1.28-Migration ungültig.');
        }
        if (!isset($data['attributes']) || !is_array($data['attributes'])) {
            $data['attributes'] = [];
        }

        $defaults = [
            'CompactStorageSchemaVersion' => 0,
            'CompactMigrationComplete' => false,
            'CompactMigrationSourceVersion' => '',
            'LegacyV127Snapshot' => '',
            'LegacyV127SnapshotHash' => '',
            'LegacyV127SnapshotCreated' => 0,
            'RollbackPrepared' => false,
        ];
        $changed = false;
        foreach ($defaults as $name => $default) {
            if (!array_key_exists($name, $data['attributes'])) {
                $data['attributes'][$name] = $default;
                $changed = true;
            }
        }
        if (!$changed) {
            return '';
        }

        $result = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($result === false) {
            throw new RuntimeException('Modulpersistenz konnte für V0.1.28 nicht serialisiert werden.');
        }
        return $result;
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Create() läuft bei bereits vorhandenen Instanzen nach einem Update
        // nicht erneut. Deshalb wird der Visualisierungstyp hier ebenfalls
        // gesetzt, damit die HTML-Kachel zuverlässig aktiviert wird.
        $this->SetVisualizationType(1);

        // Während des Neuaufbaus dürfen alte Ereignisse und Timer keinen
        // Controllerlauf gegen halb aktualisierte Modulfunktionen auslösen.
        // Die reale LCN-/GT8-Bedienung bleibt davon unberührt; nach dem Update
        // folgt ein vollständiger Statusabgleich.
        $this->SetBuffer('MaintenanceActive', '1');

        // Für die Migration muss ausgeschlossen sein, dass noch ein bereits
        // laufender Controller/Worker aus V0.1.27 gleichzeitig Legacy-Werte
        // verändert. Neue Aufrufe sehen MaintenanceActive und werden bereits
        // verworfen; diese Sperre wartet zusätzlich auf einen eventuell schon
        // begonnenen Lauf. Erst danach darf der Objektbaum angefasst werden.
        $applyLockName = 'Jalousie_PHP_' . $this->InstanceID;
        $applyLockAcquired = IPS_SemaphoreEnter($applyLockName, 30000);
        if (!$applyLockAcquired) {
            $this->SetBuffer('MaintenanceActive', '0');
            $this->WriteAttributeBoolean('FaultLatched', true);
            $this->WriteAttributeString(
                'FaultMessage',
                'Update/Migration abgebrochen: laufende Jalousiesteuerung konnte innerhalb von 30 s nicht exklusiv angehalten werden. Es wurden keine Legacy-Variablen gelöscht.'
            );
            $this->SetStatus(self::STATUS_FAULT_LATCHED);
            $this->SetSummary('inaktiv · Update sicher abgebrochen');
            return;
        }

        $initializeAfterApply = false;
        try {
            $this->suspendRuntimeForApplyChanges();
        } catch (Throwable $maintenanceError) {
            // Der eigentliche Neuaufbau darf trotz eines beschädigten alten
            // Laufzeitbaums fortgesetzt werden. Solange MaintenanceActive
            // gesetzt ist, verwirft der Controller alle Bedienaufrufe.
            $this->SendDebug('ApplyChanges', 'Alte Laufzeit konnte nicht vollständig angehalten werden: ' . $maintenanceError->getMessage(), 0);
        }

        // Eine nicht bestätigte Toggle-Transaktion darf weder einen Neustart
        // noch ein erneutes ApplyChanges überleben. Die reale Relaislage wird
        // anschließend ausschließlich über den Statusabgleich neu bewertet.
        $this->clearCommandLeaseState();
        $this->WriteAttributeString('ForeignRelayResponse', '');

        try {
            $this->updateRoutingBlockAfterConfigurationChange();
            $this->registerRuntimeMessages();
            $this->SetBuffer('StartupValidationDeadline', '0');
            $previousGeneratedVersion = $this->ReadAttributeString('GeneratedVersion');
            $this->ensureProfiles();
            $this->ensureInstanceVisualizationVariables();
            $this->prepareCompactStorageMigration($previousGeneratedVersion);
            $ids = $this->ensureObjectTree();
            $this->ensureRuntimeScripts($ids['scripts']);
            $this->migratePersistentReference($ids['state'], $previousGeneratedVersion);
            $this->invalidateReferenceAfterModelUpdate($ids['state'], $previousGeneratedVersion);
            $this->restorePersistentReference($ids['state']);
            $this->migrateLegacyStartConfirmationFault($previousGeneratedVersion);
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

            // Reine Update-/Initialisierungsfehler werden nach einem jetzt
            // erfolgreichen Neuaufbau automatisch aufgehoben, sofern beide
            // ausgewählten Motorrelais sicher AUS sind. Motor-, STOP- und
            // Routingfehler bleiben weiterhin quittierungspflichtig.
            $this->autoRecoverTransientMaintenanceFault($validation);

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

            // Eine vorübergehend oder manuell deaktivierte Symcon-Automatik
            // verändert die zuletzt sicher gespeicherte Positionsreferenz nicht.
            // Nur ein tatsächlich positionsunsicherer Bewegungsablauf darf sie
            // ausdrücklich über InvalidateReference() verwerfen.

            // INITIALIZE darf nicht innerhalb der exklusiven ApplyChanges-
            // Sperre aufgerufen werden: Der Controller verwendet dieselbe
            // Instanzsperre. Die beabsichtigte einmalige Initialisierung wird
            // deshalb erst nach Maintenance-Ende und Sperrenfreigabe ausgeführt.
            $initializeAfterApply = $kernelReady && $runtimeEnabled && !$wasRuntimeEnabled;

            $this->SyncVisualization();
            // Legacy-Variablen werden wirklich erst nach vollständig erfolgreichem
            // Neuaufbau, Validierung, Initialisierung und Visualisierung entfernt.
            if ($staticValidation['status'] === self::STATUS_ACTIVE && !$this->ReadAttributeBoolean('FaultLatched')) {
                $this->finalizeCompactStorageMigration();
            }
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
        } finally {
            $this->SetBuffer('MaintenanceActive', '0');
            IPS_SemaphoreLeave($applyLockName);
        }

        if ($initializeAfterApply
            && !$this->ReadAttributeBoolean('FaultLatched')
            && $this->ReadAttributeBoolean('RuntimeEnabled')) {
            $scriptsCategoryID = @IPS_GetObjectIDByIdent('06_Skripte', $this->InstanceID);
            $controllerID = $scriptsCategoryID === false
                ? false
                : @IPS_GetObjectIDByIdent('Controller', (int) $scriptsCategoryID);
            if ($controllerID !== false && IPS_ScriptExists((int) $controllerID)) {
                IPS_RunScriptWaitEx((int) $controllerID, ['ACTION' => 'INITIALIZE']);
            }
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === self::MESSAGE_KERNEL_STARTED) {
            $this->clearCommandLeaseState();
            $this->WriteAttributeString('ForeignRelayResponse', '');
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
        if (!$this->kernelIsReady() || $this->GetBuffer('MaintenanceActive') === '1') {
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

            // Wurde eine reine Updateverriegelung zunächst nur wegen noch
            // startender LCN-Abhängigkeiten beibehalten, wird sie hier nach
            // erfolgreicher Laufzeitprüfung ebenfalls automatisch bereinigt.
            $this->autoRecoverTransientMaintenanceFault($runtimeValidation);
            $this->tryFinalizeCompactStorageMigrationSafely(
                $staticValidation['status'] === self::STATUS_ACTIVE
                    && !$this->ReadAttributeBoolean('FaultLatched')
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
                        ['type' => 'CheckBox', 'name' => 'ModuleEnabled', 'caption' => 'Symcon-Steuerung aktiv (AUS = keine Symcon-Telegramme; reale Relais werden nur beobachtet, lokale LCN-Bedienung bleibt frei)'],
                        ['type' => 'CheckBox', 'name' => 'ShowTechnicalObjects', 'caption' => 'Technische Unterkategorien und Skripte im Objektbaum anzeigen'],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => '2. LCN-Zuordnung – Pflichtfelder',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Wählen Sie vorhandene LCN-Objekte aus. Das Modul legt keine LCN-Verbindung und keine LCN-PRO-Programmierung an.'],
                        ['type' => 'Label', 'caption' => $this->resolvedAddressCaption()],
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
                        ['type' => 'NumberSpinner', 'name' => 'CalibrationWindowMs', 'caption' => 'Zeitverzögerung / Kalibrierfenster nach bestätigtem STOP bei 100 % ZU', 'suffix' => ' ms', 'minimum' => 30000, 'maximum' => 120000],
                        ['type' => 'NumberSpinner', 'name' => 'PositionTolerance', 'caption' => 'Positionstoleranz', 'suffix' => ' %', 'digits' => 1, 'minimum' => 0.1, 'maximum' => 10],
                        ['type' => 'NumberSpinner', 'name' => 'SlatTolerance', 'caption' => 'Lamellentoleranz', 'suffix' => ' %', 'digits' => 1, 'minimum' => 0.1, 'maximum' => 10],
                        ['type' => 'Label', 'caption' => 'Richtungsabhängige Positionsrechnung: Für AUF wird aus der Gesamtzeit 100→0 die volle Wendezeit abgezogen; für ZU wird die Gesamtzeit 0→100 direkt als Behanglaufzeit verwendet.'],
                        ['type' => 'Label', 'caption' => $softStopRangeCaption],
                        ['type' => 'Label', 'caption' => 'Sanft-Stopp ist positionsabhängig: Er beginnt an der aus den Laufzeiten berechneten Prozentgrenze. Ein Zwischenziel außerhalb der Endzone fährt vollständig mit voller Geschwindigkeit; ein Zwischenziel innerhalb der Endzone enthält genau den bis zu dieser Position durchfahrenen Anteil der linearen Verzögerung. 0 ms deaktiviert die jeweilige Korrektur.'],
                        ['type' => 'Label', 'caption' => 'Bewegungsmodell: 0 % → ZU ohne Vorlauf; 100 % → AUF mit voller Wendezeit; Zwischenposition gleiche Richtung mit Sanftanlauf; Gegenrichtung mit dem längeren Wert aus Sanftanlauf und Rest-Wendezeit.'],
                        ['type' => 'Label', 'caption' => 'Die Zeitverzögerung / das Kalibrierfenster beginnt erst, nachdem bei 100 % ZU der richtungsabhängige STOP gesendet und beide ausgewählten Relais real als AUS bestätigt wurden. Während der Verzögerung bleiben beide Relais AUS. Ein neuer Fahrbefehl darf das Fenster sofort beenden; ShakeFree startet – sofern aktiviert – nur bei ungestörtem Ablauf danach.'],
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
                        ['type' => 'NumberSpinner', 'name' => 'CommandSpacingMs', 'caption' => 'Mindestabstand zwischen LCN-Telegrammen aller Jalousieinstanzen (effektiv mindestens 100 ms)', 'suffix' => ' ms', 'minimum' => 0, 'maximum' => 1000],
                        ['type' => 'NumberSpinner', 'name' => 'HealthcheckSeconds', 'caption' => 'Healthcheck / unabhängige STOP-Überwachung', 'suffix' => ' s', 'minimum' => 10, 'maximum' => 300],
                        ['type' => 'CheckBox', 'name' => 'RequestStatusOnStart', 'caption' => 'LCN-Status beim Initialisieren anfordern'],
                        ['type' => 'CheckBox', 'name' => 'AllowUnreferenced', 'caption' => 'Fahrt ohne vorherige Referenz erlauben'],
                        ['type' => 'CheckBox', 'name' => 'DiagnosticLog', 'caption' => 'Ausführliche Diagnose ins Symcon-Protokoll schreiben'],
                        ['type' => 'Label', 'caption' => 'Relais-AUS-Sicherheit: Start- und Stoppfristen beginnen erst nach erfolgreich angenommenem LCN-Telegramm. Alle Jalousie-Telegramme werden global serialisiert. Ein STOP wird zunächst genau einmal gesendet; nur wenn eine danach ausdrücklich angeforderte, frische Statusrückmeldung das ausgewählte Relais weiterhin als EIN bestätigt, erfolgt genau eine verifizierte Sicherheitswiederholung. Doppelte Relais-, GT8- oder TS-Zuordnungen zwischen Jalousieinstanzen sperren die Steuerung.'],
                    ],
                ],
            ],
            'actions' => [
                ['type' => 'Label', 'caption' => $summary],
                ['type' => 'Button', 'caption' => 'Gespeicherte Konfiguration prüfen', 'onClick' => 'echo LCNJAL_CheckConfiguration($id);'],
                ['type' => 'Button', 'caption' => 'Objektbaum und Skripte neu aufbauen', 'onClick' => 'LCNJAL_Rebuild($id); echo "Objektbaum wurde geprüft und aktualisiert.";'],
                ['type' => 'Button', 'caption' => 'LCN-Status anfordern', 'onClick' => 'LCNJAL_RequestLCNStatus($id); echo "Statusanforderung wurde gesendet.";'],
                ['type' => 'Button', 'caption' => 'Fehler quittieren (nur bei Relais AUS)', 'onClick' => 'echo LCNJAL_AcknowledgeFault($id);'],
                ['type' => 'Button', 'caption' => 'Rollback auf V0.1.27 vorbereiten', 'onClick' => 'echo LCNJAL_PrepareRollbackV127($id);'],
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
                ['code' => self::STATUS_BINDING_CONFLICT, 'icon' => 'error', 'caption' => 'Relais-, GT8- oder TS-Zuordnung wird von mehreren Jalousieinstanzen verwendet'],
                ['code' => self::STATUS_LCN_ADDRESS_CONFLICT, 'icon' => 'error', 'caption' => 'Reale LCN-Adresse widerspricht dem Instanznamen oder ist doppelt angelegt'],
            ],
        ];

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function GetCompactConfiguration(): string
    {
        $json = json_encode(
            $this->compactConfigurationArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($json === false) {
            throw new RuntimeException('Kompakte Modulkonfiguration kann nicht serialisiert werden.');
        }
        return $json;
    }

    public function GetCompactRuntimeState(): string
    {
        $state = $this->readCompactRuntimeState();
        $json = json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($json === false) {
            throw new RuntimeException('Kompakter Runtime-Zustand kann nicht serialisiert werden.');
        }
        return $json;
    }

    public function SetCompactRuntimeState(string $json): void
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Kompakter Runtime-Zustand ist kein gültiges JSON-Objekt.');
        }
        $state = $this->normalizeCompactRuntimeState($decoded);
        $encoded = json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($encoded === false) {
            throw new RuntimeException('Kompakter Runtime-Zustand kann nicht gespeichert werden.');
        }
        $this->SetBuffer('CompactRuntimeState', $encoded);

        // Nach einer bewusst vorbereiteten Rückkehr auf V0.1.27 werden die
        // wiederhergestellten Legacy-Internvariablen bis zum tatsächlichen
        // Downgrade mitgeführt. Damit bleibt der Rückweg auch dann konsistent,
        // wenn zwischen Vorbereitung und Repository-Rollback noch ein
        // Status-/Workerlauf stattfindet.
        $keepLegacyRuntimeSynchronized = $this->ReadAttributeBoolean('RollbackPrepared')
            || !$this->ReadAttributeBoolean('CompactMigrationComplete');
        if ($keepLegacyRuntimeSynchronized
            && $this->legacyVariableCount('05_Intern') === count(self::COMPACT_RUNTIME_DEFAULTS)) {
            $internalID = @IPS_GetObjectIDByIdent('05_Intern', $this->InstanceID);
            if ($internalID !== false && IPS_CategoryExists((int) $internalID)) {
                $this->writeCompactRuntimeToLegacyVariables((int) $internalID, $state);
            }
        }
    }

    /**
     * Stellt die von V0.1.27 erwarteten Spiegel-/Internvariablen wieder her.
     * Die eigentliche Konfiguration und die Referenzattribute werden nicht
     * verändert. Anschließend kann das Repository auf V0.1.27 zurückgesetzt
     * werden, ohne die Jalousie neu einpflegen zu müssen.
     */
    public function PrepareRollbackV127(): string
    {
        $relayUpID = $this->ReadPropertyInteger('RelayUpVariableID');
        $relayDownID = $this->ReadPropertyInteger('RelayDownVariableID');
        if (!$this->isBooleanVariable($relayUpID) || !$this->isBooleanVariable($relayDownID)) {
            return 'Rollback nicht vorbereitet: Relaisstatusvariablen sind nicht sicher lesbar.';
        }
        if (GetValueBoolean($relayUpID) || GetValueBoolean($relayDownID)) {
            return 'Rollback nicht vorbereitet: Mindestens ein Motorrelais ist aktiv. Erst beide Relais sicher AUS schalten.';
        }

        $lockName = 'Jalousie_PHP_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lockName, 5000)) {
            return 'Rollback nicht vorbereitet: Jalousiesteuerung ist momentan beschäftigt.';
        }

        try {
            if (!$this->verifyLegacySnapshot()) {
                $this->captureAndStoreLegacySnapshot($this->ReadAttributeString('GeneratedVersion') ?: self::VERSION);
            }

            $configurationID = $this->category('01_Konfiguration', '01 Konfiguration (Rollback V0.1.27)', 10);
            $internalID = $this->category('05_Intern', '05 Intern (Rollback V0.1.27)', 50);
            $this->createConfigurationVariables($configurationID);
            $this->createInternalVariables($internalID);
            $this->synchronizeConfiguration($configurationID);
            $this->writeCompactRuntimeToLegacyVariables($internalID, $this->readCompactRuntimeState());
            $this->verifyLegacyVariableTreeForRollback($configurationID, $internalID);
            $this->WriteAttributeBoolean('RollbackPrepared', true);
            return 'Rollback auf V0.1.27 vorbereitet. Beide Relais sind AUS; Konfiguration, Referenz und Legacy-Laufzeitstruktur wurden wiederhergestellt. Jetzt V0.1.27 installieren, ohne vorher erneut zu fahren.';
        } catch (Throwable $e) {
            return 'Rollback konnte nicht sicher vorbereitet werden: ' . $e->getMessage();
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
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

        // Eine Fehlerquittierung allein verändert die gespeicherte Referenz
        // nicht. Nur ein ausdrücklich als positionsunsicher erkannter Ablauf
        // darf die Referenz zuvor ungültig gemacht haben.
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
        $this->SetSummary($this->ReadAttributeBoolean('ReferenceValid')
            ? 'bereit · Position gültig'
            : 'bereit · Referenz erforderlich');
        IPS_RunScriptWaitEx((int) $controllerID, ['ACTION' => 'INITIALIZE']);
        $this->SyncVisualization();
        return $this->ReadAttributeBoolean('ReferenceValid')
            ? 'Fehler quittiert. Symcon wurde ohne LCN-Fahrbefehl reaktiviert; die gültige Referenz blieb erhalten.'
            : 'Fehler quittiert. Symcon wurde ohne LCN-Fahrbefehl reaktiviert; die Referenz war bereits ungültig.';
    }

    public function LatchFault(string $message): void
    {
        $message = trim($message) !== '' ? trim($message) : 'Unbekannter Laufzeitfehler';
        $this->WriteAttributeBoolean('FaultLatched', true);
        $this->WriteAttributeString('FaultMessage', $message);
        $this->WriteAttributeBoolean('RuntimeEnabled', false);
        $this->setFaultStateVariable(true);

        // Fehlerverriegelung und Positionsreferenz sind getrennte Zustände.
        // Die Referenz bleibt erhalten, sofern der Controller nicht ausdrücklich
        // einen positionsunsicheren Fahrverlauf erkannt hat.
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

    public function GetHardwareBinding(): string
    {
        $sendModuleID = $this->ReadPropertyInteger('LCNSendModuleID');
        $actorModuleID = $this->ReadPropertyInteger('LCNActorModuleID');
        $gt8LongUpVariableID = $this->ReadPropertyInteger('GT8LongUpVariableID');
        $gt8LongDownVariableID = $this->ReadPropertyInteger('GT8LongDownVariableID');
        $gt8UpSourceModuleID = $this->findConnectedLcnModuleForVariable($gt8LongUpVariableID, false);
        $gt8DownSourceModuleID = $this->findConnectedLcnModuleForVariable($gt8LongDownVariableID, false);

        $binding = [
            'instanceID' => $this->InstanceID,
            'sendModuleID' => $sendModuleID,
            'actorModuleID' => $actorModuleID,
            'relayUpVariableID' => $this->ReadPropertyInteger('RelayUpVariableID'),
            'relayDownVariableID' => $this->ReadPropertyInteger('RelayDownVariableID'),
            'gt8LongUpVariableID' => $gt8LongUpVariableID,
            'gt8LongDownVariableID' => $gt8LongDownVariableID,
            'gt8LongUpSourceModuleID' => $gt8UpSourceModuleID,
            'gt8LongDownSourceModuleID' => $gt8DownSourceModuleID,
            'tsShortUp' => $this->ReadPropertyString('TSShortUp'),
            'tsShortDown' => $this->ReadPropertyString('TSShortDown'),
            'sendModuleAddress' => $this->resolveLcnModuleAddress($sendModuleID),
            'actorModuleAddress' => $this->resolveLcnModuleAddress($actorModuleID),
            'gt8LongUpSourceAddress' => $this->resolveLcnModuleAddress($gt8UpSourceModuleID),
            'gt8LongDownSourceAddress' => $this->resolveLcnModuleAddress($gt8DownSourceModuleID),
        ];

        return json_encode($binding, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function GetCommandLease(): string
    {
        $active = $this->ReadAttributeBoolean('CommandLeaseActive');
        $startedMs = (float) $this->ReadAttributeString('CommandLeaseStartedMs');
        $nowMs = $this->monotonicMs();
        if ($active && ($startedMs <= 0.0
            || $startedMs > $nowMs + 1000.0
            || $nowMs - $startedMs > 30000.0)) {
            // hrtime() startet nach einem Kernel-/Systemneustart neu. Ein aus
            // dem alten Lauf persistierter Zeitwert liegt dann in der Zukunft
            // und muss ebenso wie eine überalterte Transaktion verworfen werden.
            $this->clearCommandLeaseState();
            $active = false;
            $startedMs = 0.0;
        }

        return json_encode([
            'active' => $active,
            'instanceID' => $this->InstanceID,
            'instanceName' => IPS_GetName($this->InstanceID),
            'direction' => $this->ReadAttributeInteger('CommandLeaseDirection'),
            'expectedRelayState' => $this->ReadAttributeInteger('CommandLeaseExpectedState'),
            'startedMs' => $startedMs,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function ClearCommandLease(): void
    {
        $this->clearCommandLeaseState();
    }

    public function ReportForeignRelayResponse(int $receiverInstanceID, int $direction, string $correlationJson = ''): void
    {
        if ($receiverInstanceID <= 0 || !in_array($direction, [1, 2], true)) {
            return;
        }
        $correlation = json_decode($correlationJson, true);
        if (!is_array($correlation)) {
            $correlation = [];
        }
        $payload = [
            'correlationCandidate' => (bool) ($correlation['correlationCandidate'] ?? false),
            'ownerDirection' => (int) ($correlation['ownerDirection'] ?? 0),
            'ownerTs' => (string) ($correlation['ownerTs'] ?? ''),
            'ownerSendRouteKey' => (string) ($correlation['ownerSendRouteKey'] ?? ''),
            'ownerLeaseStartedMs' => (float) ($correlation['ownerLeaseStartedMs'] ?? 0.0),
            'receiverActorRouteKey' => (string) ($correlation['receiverActorRouteKey'] ?? ''),
            'receiverRelayUpVariableID' => (int) ($correlation['receiverRelayUpVariableID'] ?? 0),
            'receiverRelayDownVariableID' => (int) ($correlation['receiverRelayDownVariableID'] ?? 0),
            'receiverInstanceID' => $receiverInstanceID,
            'receiverName' => IPS_InstanceExists($receiverInstanceID) ? IPS_GetName($receiverInstanceID) : '',
            'direction' => $direction,
            'reportedMs' => $this->monotonicMs(),
        ];
        $this->WriteAttributeString(
            'ForeignRelayResponse',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        );
    }

    public function GetForeignRelayResponse(): string
    {
        return $this->ReadAttributeString('ForeignRelayResponse');
    }

    public function BlockCurrentRouting(string $reason): void
    {
        $this->WriteAttributeString('BlockedRoutingFingerprint', $this->currentRoutingFingerprint());
        $this->WriteAttributeString('BlockedRoutingReason', trim($reason));
        $this->WriteAttributeBoolean('RoutingRearmAllowed', false);
    }

    public function GetRoutingBlock(): string
    {
        return json_encode([
            'active' => $this->routingIsBlocked(),
            'fingerprint' => $this->ReadAttributeString('BlockedRoutingFingerprint'),
            'reason' => $this->ReadAttributeString('BlockedRoutingReason'),
            'rearmAllowed' => $this->ReadAttributeBoolean('RoutingRearmAllowed'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function SendExternalEndStop(int $direction): bool
    {
        if (!in_array($direction, [1, 2], true)) {
            throw new InvalidArgumentException('Ungueltige externe STOP-Richtung: ' . $direction);
        }
        if (!$this->ReadPropertyBoolean('ModuleEnabled')) {
            throw new RuntimeException('Automatischer externer Endlagen-STOP ist bei deaktiviertem Modul gesperrt.');
        }
        return $this->sendDirectionCommandInternal($direction, $direction, true);
    }

    public function SendDirectionCommand(int $direction, int $expectedRelayState = -1): bool
    {
        return $this->sendDirectionCommandInternal($direction, $expectedRelayState, false);
    }

    private function sendDirectionCommandInternal(int $direction, int $expectedRelayState, bool $allowFaultLatchedExternalStop): bool
    {
        if (!in_array($direction, [1, 2], true)) {
            throw new InvalidArgumentException('Ungueltige Fahrtrichtung: ' . $direction);
        }
        if (!in_array($expectedRelayState, [-1, 0, 1, 2], true)) {
            throw new InvalidArgumentException('Ungueltiger erwarteter Relaiszustand: ' . $expectedRelayState);
        }
        if (!$allowFaultLatchedExternalStop && !$this->IsRuntimePermitted()) {
            throw new RuntimeException('Jalousiesteuerung ist nicht freigegeben.');
        }
        if ($allowFaultLatchedExternalStop && !$this->ReadPropertyBoolean('ModuleEnabled')) {
            throw new RuntimeException('Jalousiesteuerung ist deaktiviert.');
        }
        if ($this->routingIsBlocked()) {
            throw new RuntimeException('Diese reale Sendemodul-/TS-Route ist nach einem bestätigten Fremdstart gesperrt. Segment/Target beziehungsweise TS-Zuordnung korrigieren. Eine geänderte Senderoute wird automatisch neu bewertet; bei unveränderter Route ist eine erneute zweistufige TS-Abnahme erforderlich.');
        }

        $validation = $this->validateConfiguration(true, true);
        if ($validation['status'] !== self::STATUS_ACTIVE) {
            throw new RuntimeException('Befehl gesperrt: ' . implode(' | ', $validation['messages']));
        }

        $relayUpID = $this->ReadPropertyInteger('RelayUpVariableID');
        $relayDownID = $this->ReadPropertyInteger('RelayDownVariableID');
        $initialState = $this->selectedRelayState($relayUpID, $relayDownID);
        if (!$this->relayCommandStillRequired($initialState, $expectedRelayState, $direction, 'vor Sendesperre')) {
            $this->clearCommandLeaseState();
            $this->SendDebug('DirectionCommand', 'STOP bereits durch reale Relais-AUS-Meldung erfüllt; kein Toggle gesendet.', 0);
            return true;
        }

        if (!IPS_FunctionExists('LCN_SendCommand')) {
            throw new RuntimeException('LCN_SendCommand ist nicht verfügbar.');
        }
        $sendModuleID = $this->ReadPropertyInteger('LCNSendModuleID');
        if (!$this->isUsableLcnModule($sendModuleID, true)) {
            throw new RuntimeException('LCN-Sendemodul ist nicht betriebsbereit: ' . $sendModuleID);
        }

        $data = $direction === 1
            ? $this->ReadPropertyString('TSShortUp')
            : $this->ReadPropertyString('TSShortDown');
        if (!$this->ReadPropertyBoolean('TSMappingConfirmed') || !$this->validateTS($data)) {
            throw new RuntimeException('TS-Zuordnung ist nicht gültig und bestätigt.');
        }

        // Nur das sehr kurze Senden des Telegramms wird global serialisiert.
        // Offene Relaisbestätigungen anderer Instanzen blockieren den nächsten
        // Start nicht mehr. Dadurch können mehrere Motoren innerhalb weniger
        // Sekunden anlaufen, während der Mindestabstand den LCN-Bus schützt.
        $lockName = 'LCNJAL_LCN_BUS_SEND';
        if (!IPS_SemaphoreEnter($lockName, 20000)) {
            throw new RuntimeException('Der LCN-Bus ist durch parallele Jalousiebefehle länger als 20 Sekunden belegt.');
        }
        $ok = false;
        try {
            $lockedState = $this->selectedRelayState($relayUpID, $relayDownID);
            if (!$this->relayCommandStillRequired($lockedState, $expectedRelayState, $direction, 'unmittelbar vor LCN_SendCommand')) {
                $this->clearCommandLeaseState();
                $this->SendDebug('DirectionCommand', 'STOP während Sendesperre bereits durch Relais-AUS erfüllt; kein Toggle gesendet.', 0);
                return true;
            }

            $this->WriteAttributeString('ForeignRelayResponse', '');
            $this->markCommandLeaseState($direction, $expectedRelayState);
            try {
                $ok = LCN_SendCommand($sendModuleID, 'TS', $data);
            } catch (Throwable $sendError) {
                $this->clearCommandLeaseState();
                throw $sendError;
            }
            if (!$ok) {
                $this->clearCommandLeaseState();
            } else {
                $spacingMs = max(100, min(1000, $this->ReadPropertyInteger('CommandSpacingMs')));
                if ($spacingMs > 0) {
                    try {
                        IPS_Sleep($spacingMs);
                    } catch (Throwable $sleepError) {
                        // Das Telegramm wurde bereits angenommen. Ein Fehler in
                        // der reinen Busabstandspause darf deshalb nicht als
                        // fehlgeschlagene Sendung behandelt oder erneut gesendet werden.
                        $this->SendDebug('DirectionCommand', 'Busabstandspause fehlgeschlagen: ' . $sleepError->getMessage(), 0);
                    }
                }
            }
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
        if (!$ok) {
            throw new RuntimeException('LCN_SendCommand wurde von Symcon nicht angenommen.');
        }

        $this->SendDebug(
            'DirectionCommand',
            sprintf(
                'Instanz %d, Richtung %s, erwarteter Zustand %d, Sendemodul %d, TS %s, Relais AUF #%d, Relais ZU #%d',
                $this->InstanceID,
                $direction === 1 ? 'AUF' : 'ZU',
                $expectedRelayState,
                $sendModuleID,
                $data,
                $relayUpID,
                $relayDownID
            ),
            0
        );
        return true;
    }

    private function monotonicMs(): float
    {
        $nanoseconds = hrtime(true);
        if ($nanoseconds === false) {
            throw new RuntimeException('hrtime() ist auf dieser Plattform nicht verfuegbar.');
        }
        return (float) $nanoseconds / 1000000.0;
    }

    private function markCommandLeaseState(int $direction, int $expectedRelayState): void
    {
        $this->WriteAttributeInteger('CommandLeaseDirection', $direction);
        $this->WriteAttributeInteger('CommandLeaseExpectedState', $expectedRelayState);
        $this->WriteAttributeString('CommandLeaseStartedMs', (string) $this->monotonicMs());
        $this->WriteAttributeBoolean('CommandLeaseActive', true);
    }

    private function clearCommandLeaseState(): void
    {
        $this->WriteAttributeBoolean('CommandLeaseActive', false);
        $this->WriteAttributeInteger('CommandLeaseDirection', 0);
        $this->WriteAttributeInteger('CommandLeaseExpectedState', -1);
        $this->WriteAttributeString('CommandLeaseStartedMs', '0');
    }

    private function selectedRelayState(int $relayUpID, int $relayDownID): int
    {
        $up = GetValueBoolean($relayUpID);
        $down = GetValueBoolean($relayDownID);
        if ($up && $down) {
            return 3;
        }
        if ($up) {
            return 1;
        }
        if ($down) {
            return 2;
        }
        return 0;
    }

    private function relayCommandStillRequired(
        int $actualState,
        int $expectedState,
        int $direction,
        string $checkpoint
    ): bool {
        if ($actualState === 3) {
            throw new RuntimeException('Befehl gesperrt (' . $checkpoint . '): Beide ausgewählten Motorrelais melden EIN.');
        }

        // Bei einem STOP kann das Relais zwischen Controllerprüfung und
        // sendemodulweiter Sperre bereits real AUS gemeldet haben. Dann ist der
        // gewünschte Zustand erfüllt und ein Toggle wäre gefährlich.
        if ($expectedState === $direction && $actualState === 0) {
            return false;
        }

        if ($expectedState >= 0 && $actualState !== $expectedState) {
            throw new RuntimeException(
                'Befehl gesperrt (' . $checkpoint . '): Erwarteter Relaiszustand '
                . $expectedState . ', tatsächlich ' . $actualState . '.'
            );
        }
        if (($direction === 1 && $actualState === 2) || ($direction === 2 && $actualState === 1)) {
            throw new RuntimeException('Befehl gesperrt (' . $checkpoint . '): Das ausgewählte Gegenrichtungsrelais ist aktiv.');
        }
        return true;
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

    public function IsMaintenanceActive(): bool
    {
        return $this->GetBuffer('MaintenanceActive') === '1';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'ResetError') {
            $this->AcknowledgeFault();
            return;
        }
        if ($this->IsMaintenanceActive()) {
            throw new RuntimeException('Modulaktualisierung läuft. Der Bedienbefehl wurde nicht gesendet; bitte kurz erneut ausführen.');
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

        // Der echte Zustand wird bereits vor dem ersten JavaScript-Render direkt
        // in das statische HTML eingesetzt. Dadurch erscheint beim Neuaufbau der
        // HTML-SDK-Kachel nicht fuer einen kurzen Moment der Defaultzustand
        // "0 % / inaktiv". Laufzeitänderungen kommen weiterhin ausschliesslich
        // ueber UpdateVisualizationValue() -> handleMessage().
        $initialState = json_encode(
            $this->getVisualizationState(),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
                | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($initialState === false) {
            $initialState = '{}';
        }

        return str_replace(
            '/*__LCNJAL_INITIAL_STATE__*/',
            'jalInitialState = ' . $initialState . ';',
            $html
        );
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
                'RelayConfirmMs' => $this->ReadPropertyInteger('RelayConfirmMs'),
                'StopConfirmMs' => $this->ReadPropertyInteger('StopConfirmMs'),
                'LateStartGuardMs' => $this->ReadPropertyInteger('LateStartGuardMs'),
                'RelayCoalesceMs' => $this->ReadPropertyInteger('RelayCoalesceMs'),
                'CommandSpacingMs' => $this->ReadPropertyInteger('CommandSpacingMs'),
                'LCNSendModuleID' => $this->ReadPropertyInteger('LCNSendModuleID'),
                'LCNActorModuleID' => $this->ReadPropertyInteger('LCNActorModuleID'),
                'RelayUpVariableID' => $this->ReadPropertyInteger('RelayUpVariableID'),
                'RelayDownVariableID' => $this->ReadPropertyInteger('RelayDownVariableID'),
                'GT8LongUpVariableID' => $this->ReadPropertyInteger('GT8LongUpVariableID'),
                'GT8LongDownVariableID' => $this->ReadPropertyInteger('GT8LongDownVariableID'),
                'GT8LongUpSourceModuleID' => $this->findConnectedLcnModuleForVariable($this->ReadPropertyInteger('GT8LongUpVariableID')),
                'GT8LongDownSourceModuleID' => $this->findConnectedLcnModuleForVariable($this->ReadPropertyInteger('GT8LongDownVariableID')),
                'TSMappingConfirmed' => $this->ReadPropertyBoolean('TSMappingConfirmed'),
                'HardwareBinding' => json_decode($this->GetHardwareBinding(), true),
                'ResolvedLCNAddresses' => $this->getResolvedLcnAddresses(),
            ],
            'runtime' => $this->getRuntimeDiagnostics(),
            'validation' => $validation,
        ];
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function getRuntimeDiagnostics(): array
    {
        $stateCategoryID = @IPS_GetObjectIDByIdent('04_Istwerte', $this->InstanceID);
        $relayUpID = $this->ReadPropertyInteger('RelayUpVariableID');
        $relayDownID = $this->ReadPropertyInteger('RelayDownVariableID');
        $runtime = $this->readCompactRuntimeState();

        $readState = static function (int|false $parentID, string $ident, mixed $default): mixed {
            if ($parentID === false) {
                return $default;
            }
            $id = @IPS_GetObjectIDByIdent($ident, $parentID);
            if ($id === false || !IPS_VariableExists((int) $id)) {
                return $default;
            }
            return GetValue((int) $id);
        };

        return [
            'storageSchemaVersion' => $this->ReadAttributeInteger('CompactStorageSchemaVersion'),
            'compactMigrationComplete' => $this->ReadAttributeBoolean('CompactMigrationComplete'),
            'legacySnapshotValid' => $this->verifyLegacySnapshot(),
            'legacySnapshotCreated' => $this->ReadAttributeInteger('LegacyV127SnapshotCreated'),
            'rollbackPrepared' => $this->ReadAttributeBoolean('RollbackPrepared'),
            'legacyConfigurationVariableCount' => $this->legacyVariableCount('01_Konfiguration'),
            'legacyInternalVariableCount' => $this->legacyVariableCount('05_Intern'),
            'relayUpSelectedValue' => $relayUpID > 0 && IPS_VariableExists($relayUpID) ? GetValueBoolean($relayUpID) : null,
            'relayDownSelectedValue' => $relayDownID > 0 && IPS_VariableExists($relayDownID) ? GetValueBoolean($relayDownID) : null,
            'driveState' => $readState($stateCategoryID, 'Fahrstatus', 0),
            'phase' => $readState($stateCategoryID, 'Phase', 0),
            'lastAction' => $readState($stateCategoryID, 'Letzte_Aktion', ''),
            'lastStatusTimestamp' => $readState($stateCategoryID, 'Letzte_Statusmeldung', 0),
            'lastRelaysOffTimestamp' => $readState($stateCategoryID, 'Letzte_Relais_AUS_Bestaetigung', 0),
            'orderNumber' => (int) $runtime['Auftragsnummer'],
            'orderType' => (int) $runtime['Auftragstyp'],
            'expectedDirection' => (int) $runtime['Erwartete_Richtung'],
            'stopRequested' => (bool) $runtime['Stop_Angefordert'],
            'pendingAction' => (int) $runtime['Pending_Aktion'],
            'startStatusRetryActive' => (bool) $runtime['Startstatus_Nachfrage_Aktiv'],
            'stopStatusRetryActive' => (bool) $runtime['Stopstatus_Nachfrage_Aktiv'],
            'startStatusRelayUpFresh' => (bool) $runtime['Startstatus_Relais_AUF_Empfangen'],
            'startStatusRelayDownFresh' => (bool) $runtime['Startstatus_Relais_AB_Empfangen'],
            'stopStatusRelayUpFresh' => (bool) $runtime['Stopstatus_Relais_AUF_Empfangen'],
            'stopStatusRelayDownFresh' => (bool) $runtime['Stopstatus_Relais_AB_Empfangen'],
            'verifiedStopRetrySent' => (bool) $runtime['Stop_Wiederholung_Gesendet'],
            'commandSentTimestampMs' => (float) $runtime['Befehl_gesendet_ms'],
            'externalReferenceSet' => (bool) $runtime['Externe_Referenz_Gesetzt'],
            'externalEndDeadlineMs' => (float) $runtime['Externe_Endlage_bis_ms'],
            'externalAutoStopDeadlineMs' => (float) $runtime['Externer_Autostopp_bis_ms'],
            'externalAutoStopActive' => (bool) $runtime['Externer_Autostopp_Aktiv'],
            'possibleForeignCommandSourceInstanceID' => (int) $runtime['Fremdbefehl_Quelle'],
            'possibleForeignCommandDetectedMs' => (float) $runtime['Fremdbefehl_Erkannt_ms'],
            'commandLease' => json_decode($this->GetCommandLease(), true),
            'foreignRelayResponse' => json_decode($this->GetForeignRelayResponse(), true),
            'routingBlock' => json_decode($this->GetRoutingBlock(), true),
        ];
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
        $runtime = $this->readCompactRuntimeState();

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

        $orderType = (int) $runtime['Auftragstyp'];
        $targetPosition = (float) $runtime['Ziel_Behang'];
        $targetRotation = (float) $runtime['Ziel_Lamelle'];

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
            $deadline = (float) $runtime['Zielzeit_ms'];
            if ($deadline > 0.0) {
                $nowMs = (float) hrtime(true) / 1_000_000.0;
                $calibrationRemainingSeconds = (int) max(0, ceil(($deadline - $nowMs) / 1000.0));
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
        if ($this->routingIsBlocked()) {
            $messages[] = 'Die aktuelle Sendemodul-/TS-Zuordnung hat real eine andere Jalousie gestartet und bleibt gesperrt. Nach Korrektur in LCN-PRO: TS-Bestätigung deaktivieren und speichern, anschließend im Busmonitor prüfen, wieder aktivieren und erneut speichern.';
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_TS_INVALID;
            }
        }

        $addressConflicts = $this->validateSelectedLcnAddresses();
        if ($addressConflicts !== []) {
            foreach ($addressConflicts as $conflict) {
                $messages[] = $conflict;
            }
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_LCN_ADDRESS_CONFLICT;
            }
        }

        $bindingConflicts = $this->findBindingConflicts();
        if ($bindingConflicts !== []) {
            foreach ($bindingConflicts as $conflict) {
                $messages[] = $conflict;
            }
            if ($status === self::STATUS_ACTIVE) {
                $status = self::STATUS_BINDING_CONFLICT;
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
        $shakeMs = $this->ReadPropertyInteger('ShakeFreeMs');
        $shakePause = $this->ReadPropertyInteger('ShakeFreePauseMs');
        $calibrationWindow = $this->ReadPropertyInteger('CalibrationWindowMs');
        $relayConfirm = $this->ReadPropertyInteger('RelayConfirmMs');
        $stopConfirm = $this->ReadPropertyInteger('StopConfirmMs');
        $lateStartGuard = $this->ReadPropertyInteger('LateStartGuardMs');
        $statusSync = $this->ReadPropertyInteger('StatusSyncMs');
        $relayCoalesce = $this->ReadPropertyInteger('RelayCoalesceMs');
        $commandSpacing = $this->ReadPropertyInteger('CommandSpacingMs');
        $healthcheck = $this->ReadPropertyInteger('HealthcheckSeconds');
        $blindUp = $totalUp - $turn;
        $blindDown = $totalDown;
        if ($totalUp <= $turn
            || $totalDown <= 0
            || $turn <= 0
            || $softStart < 0
            || $softStart > $turn
            || $blindUp <= 0
            || $blindDown <= 0
            || $softStopUp < 0
            || $softStopUp >= $blindUp
            || $softStopDown < 0
            || $softStopDown >= $blindDown
            || $reserve < 0
            || $max < max($totalUp, $totalDown) + $reserve
            || $shakeMs < 100
            || $shakeMs > $max
            || $window < 1000
            || $window > 3000
            || $shakePause < 0
            || $shakePause > 3000
            || $calibrationWindow < 30000
            || $calibrationWindow > 120000
            || $relayConfirm < 500
            || $relayConfirm > 10000
            || $stopConfirm < 500
            || $stopConfirm > 10000
            || $lateStartGuard < 500
            || $lateStartGuard > 30000
            || $statusSync < 500
            || $statusSync > 10000
            || $relayCoalesce < 0
            || $relayCoalesce > 1000
            || $commandSpacing < 0
            || $commandSpacing > 1000
            || $healthcheck < 10
            || $healthcheck > 300) {
            $messages[] = 'Zeitparameter sind widersprüchlich: Gesamtzeit 100→0 muss größer als die Wendezeit sein; Gesamtzeit 0→100 muss positiv sein; 0 ≤ Sanftanlauf ≤ Wendezeit; Sanft-Stopp AUF/ZU jeweils 0 bis kleiner als die zugehörige Behanglaufzeit; Referenzreserve ≥ 0; MaxFahrt mindestens längere Richtungs-Gesamtzeit + Reserve; ShakeFree 100 ms bis MaxFahrt; Workerfenster 1000…3000 ms; Start-/Stoppbestätigung 500…10000 ms; Spätstart-Schutz 500…30000 ms; Statussync 500…10000 ms; Relais-Koaleszenz 0…1000 ms; Befehlsabstand 0…1000 ms (effektiv mindestens 100 ms); Healthcheck 10…300 s; Kalibrierfenster 30000…120000 ms.';
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

    private function currentRoutingFingerprint(): string
    {
        $sendModuleID = $this->ReadPropertyInteger('LCNSendModuleID');
        $actorModuleID = $this->ReadPropertyInteger('LCNActorModuleID');
        $sendAddress = $this->resolveLcnModuleAddress($sendModuleID);
        $actorAddress = $this->resolveLcnModuleAddress($actorModuleID);

        return hash('sha256', json_encode([
            'sendModuleID' => $sendModuleID,
            'sendRouteKey' => (string) ($sendAddress['routeKey'] ?? ''),
            'actorModuleID' => $actorModuleID,
            'actorRouteKey' => (string) ($actorAddress['routeKey'] ?? ''),
            'relayUpVariableID' => $this->ReadPropertyInteger('RelayUpVariableID'),
            'relayDownVariableID' => $this->ReadPropertyInteger('RelayDownVariableID'),
            'tsShortUp' => $this->ReadPropertyString('TSShortUp'),
            'tsShortDown' => $this->ReadPropertyString('TSShortDown'),
        ], JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function routingIsBlocked(): bool
    {
        $blocked = $this->ReadAttributeString('BlockedRoutingFingerprint');
        return $blocked !== '' && hash_equals($blocked, $this->currentRoutingFingerprint());
    }

    private function updateRoutingBlockAfterConfigurationChange(): void
    {
        $blocked = $this->ReadAttributeString('BlockedRoutingFingerprint');
        if ($blocked === '') {
            $this->WriteAttributeBoolean('RoutingRearmAllowed', false);
            return;
        }

        if (!hash_equals($blocked, $this->currentRoutingFingerprint())) {
            $this->WriteAttributeString('BlockedRoutingFingerprint', '');
            $this->WriteAttributeString('BlockedRoutingReason', '');
            $this->WriteAttributeBoolean('RoutingRearmAllowed', false);
            return;
        }

        if (!$this->ReadPropertyBoolean('TSMappingConfirmed')) {
            $this->WriteAttributeBoolean('RoutingRearmAllowed', true);
            return;
        }

        if ($this->ReadAttributeBoolean('RoutingRearmAllowed')) {
            $this->WriteAttributeString('BlockedRoutingFingerprint', '');
            $this->WriteAttributeString('BlockedRoutingReason', '');
            $this->WriteAttributeBoolean('RoutingRearmAllowed', false);
        }
    }

    private function resolvedAddressCaption(): string
    {
        $resolved = $this->getResolvedLcnAddresses();
        $parts = [];
        foreach ([
            'Sendemodul' => $resolved['sendModule'] ?? [],
            'Aktormodul' => $resolved['actorModule'] ?? [],
            'GT8 LANG AUF' => $resolved['gt8LongUpSource'] ?? [],
            'GT8 LANG ZU' => $resolved['gt8LongDownSource'] ?? [],
        ] as $label => $address) {
            if (!is_array($address) || !(bool) ($address['valid'] ?? false)) {
                continue;
            }
            $parts[] = $label . ' #' . (int) ($address['instanceID'] ?? 0)
                . ' → (' . (string) ($address['address'] ?? '') . ')';
        }
        return $parts === []
            ? 'Tatsächliche LCN-Adressen werden nach Auswahl aus den internen Segment-/Target-Werten angezeigt. Der Instanzname allein ist nicht maßgeblich.'
            : 'Tatsächlich verwendete LCN-Adressen: ' . implode(' · ', $parts)
                . '. Maßgeblich sind diese Segment-/Target-Werte, nicht der angezeigte Instanzname.';
    }

    private function resolveLcnModuleAddress(int $instanceID): array
    {
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return [];
        }

        $instance = IPS_GetInstance($instanceID);
        if (!function_exists('IPS_GetConfiguration')) {
            return [
                'instanceID' => $instanceID,
                'instanceName' => IPS_GetName($instanceID),
                'connectionID' => (int) ($instance['ConnectionID'] ?? 0),
                'valid' => false,
            ];
        }
        $configuration = json_decode(IPS_GetConfiguration($instanceID), true);
        if (!is_array($configuration)
            || !array_key_exists('Segment', $configuration)
            || !array_key_exists('Target', $configuration)) {
            return [
                'instanceID' => $instanceID,
                'instanceName' => IPS_GetName($instanceID),
                'connectionID' => (int) ($instance['ConnectionID'] ?? 0),
                'valid' => false,
            ];
        }

        $segment = (int) $configuration['Segment'];
        $target = (int) $configuration['Target'];
        $connectionID = (int) ($instance['ConnectionID'] ?? 0);
        $name = IPS_GetName($instanceID);
        $nameSegment = null;
        $nameTarget = null;
        if (preg_match('/\((\d{1,3})\s*,\s*(\d{1,3})\)/', $name, $matches) === 1) {
            $nameSegment = (int) $matches[1];
            $nameTarget = (int) $matches[2];
        }
        $nameMatchesAddress = $nameSegment === null
            ? null
            : ($nameSegment === $segment && $nameTarget === $target);

        return [
            'instanceID' => $instanceID,
            'instanceName' => $name,
            'connectionID' => $connectionID,
            'segment' => $segment,
            'target' => $target,
            'address' => sprintf('%03d,%03d', $segment, $target),
            'routeKey' => $connectionID . ':' . $segment . ':' . $target,
            'nameAddress' => $nameSegment === null ? '' : sprintf('%03d,%03d', $nameSegment, $nameTarget),
            'nameMatchesAddress' => $nameMatchesAddress,
            'duplicateInstanceIDs' => $this->findDuplicateLcnAddressInstances($instanceID, $connectionID, $segment, $target),
            'valid' => true,
        ];
    }

    private function findDuplicateLcnAddressInstances(
        int $selectedInstanceID,
        int $connectionID,
        int $segment,
        int $target
    ): array {
        if (!IPS_FunctionExists('IPS_GetInstanceListByModuleID')) {
            return [];
        }

        $duplicates = [];
        foreach (IPS_GetInstanceListByModuleID(self::LCN_MODULE_ID) as $instanceID) {
            $instanceID = (int) $instanceID;
            if ($instanceID <= 0 || $instanceID === $selectedInstanceID || !IPS_InstanceExists($instanceID)) {
                continue;
            }
            $instance = IPS_GetInstance($instanceID);
            if ((int) ($instance['InstanceStatus'] ?? 0) !== self::STATUS_ACTIVE
                || (int) ($instance['ConnectionID'] ?? 0) !== $connectionID) {
                continue;
            }
            $configuration = json_decode(IPS_GetConfiguration($instanceID), true);
            if (!is_array($configuration)) {
                continue;
            }
            if ((int) ($configuration['Segment'] ?? -1) === $segment
                && (int) ($configuration['Target'] ?? -1) === $target) {
                $duplicates[] = $instanceID;
            }
        }
        sort($duplicates);
        return $duplicates;
    }

    private function getResolvedLcnAddresses(): array
    {
        $gt8UpSource = $this->findConnectedLcnModuleForVariable(
            $this->ReadPropertyInteger('GT8LongUpVariableID'),
            false
        );
        $gt8DownSource = $this->findConnectedLcnModuleForVariable(
            $this->ReadPropertyInteger('GT8LongDownVariableID'),
            false
        );
        return [
            'sendModule' => $this->resolveLcnModuleAddress($this->ReadPropertyInteger('LCNSendModuleID')),
            'actorModule' => $this->resolveLcnModuleAddress($this->ReadPropertyInteger('LCNActorModuleID')),
            'gt8LongUpSource' => $this->resolveLcnModuleAddress($gt8UpSource),
            'gt8LongDownSource' => $this->resolveLcnModuleAddress($gt8DownSource),
        ];
    }

    private function validateSelectedLcnAddresses(): array
    {
        $messages = [];
        $roles = [
            'Sendemodul' => $this->ReadPropertyInteger('LCNSendModuleID'),
            'Aktormodul' => $this->ReadPropertyInteger('LCNActorModuleID'),
            'GT8-LANG-AUF-Quellmodul' => $this->findConnectedLcnModuleForVariable(
                $this->ReadPropertyInteger('GT8LongUpVariableID'),
                false
            ),
            'GT8-LANG-ZU-Quellmodul' => $this->findConnectedLcnModuleForVariable(
                $this->ReadPropertyInteger('GT8LongDownVariableID'),
                false
            ),
        ];

        $checked = [];
        foreach ($roles as $role => $instanceID) {
            $instanceID = (int) $instanceID;
            if ($instanceID <= 0 || isset($checked[$instanceID])) {
                continue;
            }
            $checked[$instanceID] = true;
            $address = $this->resolveLcnModuleAddress($instanceID);
            if (!(bool) ($address['valid'] ?? false)) {
                continue;
            }
            if (($address['nameMatchesAddress'] ?? null) === false) {
                $messages[] = $role . ' #' . $instanceID . ' heißt „' . (string) ($address['instanceName'] ?? '')
                    . '“ und nennt damit (' . (string) ($address['nameAddress'] ?? '') . '), ist intern aber auf ('
                    . (string) ($address['address'] ?? '') . ') konfiguriert. Der Name ist nicht die Zieladresse; Segment/Target korrigieren.';
            }
            $duplicates = array_map('intval', (array) ($address['duplicateInstanceIDs'] ?? []));
            if ($duplicates !== []) {
                $labels = [];
                foreach ($duplicates as $duplicateID) {
                    $labels[] = IPS_GetName($duplicateID) . ' (#' . $duplicateID . ')';
                }
                $messages[] = $role . ' #' . $instanceID . ' und ' . implode(', ', $labels)
                    . ' zeigen über denselben Splitter auf dieselbe reale LCN-Adresse ('
                    . (string) ($address['address'] ?? '')
                    . '). Doppelte LCN-Modulinstanzen sind für sichere TS-Zuordnung nicht zulässig.';
            }
        }
        return array_values(array_unique($messages));
    }

    private function findBindingConflicts(): array
    {
        if (!IPS_FunctionExists('IPS_GetInstanceListByModuleID')
            || !IPS_FunctionExists('IPS_GetConfiguration')) {
            return [];
        }

        $ownRelayIDs = array_values(array_filter([
            $this->ReadPropertyInteger('RelayUpVariableID'),
            $this->ReadPropertyInteger('RelayDownVariableID'),
        ], static fn (int $id): bool => $id > 0));
        $ownGt8IDs = array_values(array_filter([
            $this->ReadPropertyInteger('GT8LongUpVariableID'),
            $this->ReadPropertyInteger('GT8LongDownVariableID'),
        ], static fn (int $id): bool => $id > 0));
        $ownSendModule = $this->ReadPropertyInteger('LCNSendModuleID');
        $ownSendAddress = $this->resolveLcnModuleAddress($ownSendModule);
        $ownSendRouteKey = (string) ($ownSendAddress['routeKey'] ?? '');
        $ownCommands = array_values(array_filter([
            $this->ReadPropertyString('TSShortUp'),
            $this->ReadPropertyString('TSShortDown'),
        ], static fn (string $value): bool => $value !== ''));

        $conflicts = [];
        foreach (IPS_GetInstanceListByModuleID(self::MODULE_ID) as $instanceID) {
            $instanceID = (int) $instanceID;
            if ($instanceID <= 0 || $instanceID === $this->InstanceID || !IPS_InstanceExists($instanceID)) {
                continue;
            }

            $configuration = json_decode(IPS_GetConfiguration($instanceID), true);
            if (!is_array($configuration)) {
                continue;
            }
            $otherName = IPS_GetName($instanceID) . ' (#' . $instanceID . ')';
            $otherRelayIDs = array_values(array_filter([
                (int) ($configuration['RelayUpVariableID'] ?? 0),
                (int) ($configuration['RelayDownVariableID'] ?? 0),
            ], static fn (int $id): bool => $id > 0));
            $sharedRelays = array_values(array_intersect($ownRelayIDs, $otherRelayIDs));
            if ($sharedRelays !== []) {
                $conflicts[] = 'Motorrelaisvariable #' . implode('/#', $sharedRelays)
                    . ' wird auch von ' . $otherName . ' verwendet.';
            }

            $otherGt8IDs = array_values(array_filter([
                (int) ($configuration['GT8LongUpVariableID'] ?? 0),
                (int) ($configuration['GT8LongDownVariableID'] ?? 0),
            ], static fn (int $id): bool => $id > 0));
            $sharedGt8 = array_values(array_intersect($ownGt8IDs, $otherGt8IDs));
            if ($sharedGt8 !== []) {
                $conflicts[] = 'GT8-LANG-Ereignisvariable #' . implode('/#', $sharedGt8)
                    . ' wird auch von ' . $otherName . ' verwendet.';
            }

            $otherSendModule = (int) ($configuration['LCNSendModuleID'] ?? 0);
            $otherSendAddress = $this->resolveLcnModuleAddress($otherSendModule);
            $otherSendRouteKey = (string) ($otherSendAddress['routeKey'] ?? '');
            if ($ownSendModule > 0
                && ($otherSendModule === $ownSendModule
                    || ($ownSendRouteKey !== '' && hash_equals($ownSendRouteKey, $otherSendRouteKey)))) {
                $otherCommands = array_values(array_filter([
                    (string) ($configuration['TSShortUp'] ?? ''),
                    (string) ($configuration['TSShortDown'] ?? ''),
                ], static fn (string $value): bool => $value !== ''));
                $sharedCommands = array_values(array_unique(array_intersect($ownCommands, $otherCommands)));
                if ($sharedCommands !== []) {
                    $conflicts[] = 'TS-KURZ ' . implode(', ', $sharedCommands)
                        . ' auf realer Senderoute ' . ($ownSendRouteKey !== '' ? $ownSendRouteKey : ('Instanz #' . $ownSendModule)) . ' wird auch von ' . $otherName
                        . ' verwendet. Diese Zuordnung kann nicht eindeutig nur eine Jalousie schalten.';
                }
            }
        }

        return array_values(array_unique($conflicts));
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
        $moduleInfo = (array) ($instance['ModuleInfo'] ?? []);
        $moduleID = strtoupper((string) ($moduleInfo['ModuleID'] ?? ''));
        $moduleName = (string) ($moduleInfo['ModuleName'] ?? '');
        $moduleType = (int) ($moduleInfo['ModuleType'] ?? -1);
        $isExactLcnModule = $moduleID !== ''
            ? $moduleID === strtoupper(self::LCN_MODULE_ID)
            : ($moduleType === 2 && preg_match('/^LCN Modul(?:e)?$/i', trim($moduleName)) === 1);
        return $isExactLcnModule
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

    private function migrateLegacyStartConfirmationFault(string $previousVersion): void
    {
        if ($previousVersion === '' || version_compare($previousVersion, '0.1.25', '>=')) {
            return;
        }
        if (!$this->ReadAttributeBoolean('FaultLatched')) {
            return;
        }

        $message = trim($this->ReadAttributeString('FaultMessage'));
        $legacyMessages = [
            'Keine reale Relaisbestaetigung innerhalb der eingestellten Zeit.',
            'Keine reale Relaisbestätigung innerhalb der eingestellten Zeit.',
        ];
        if (!in_array($message, $legacyMessages, true)) {
            return;
        }

        $relayUpID = $this->ReadPropertyInteger('RelayUpVariableID');
        $relayDownID = $this->ReadPropertyInteger('RelayDownVariableID');
        if (!$this->isBooleanVariable($relayUpID)
            || !$this->isBooleanVariable($relayDownID)
            || GetValueBoolean($relayUpID)
            || GetValueBoolean($relayDownID)) {
            return;
        }

        // Diese alte Meldung beschrieb lediglich eine ausgebliebene
        // Startbestätigung bei nachweislich stromlosen Relais. Ab 0.1.25 ist
        // dies kein verriegelnder Motorfehler mehr. Eine eventuell bereits
        // ungültige Referenz wird aus Sicherheitsgründen nicht automatisch
        // wiederhergestellt.
        $this->WriteAttributeBoolean('FaultLatched', false);
        $this->WriteAttributeString('FaultMessage', '');
        $this->setFaultStateVariable(false);
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
        $referencePosition = $this->ReadAttributeFloat('ReferencePosition');
        $referenceSlat = $this->ReadAttributeFloat('ReferenceSlat');
        $timestamp = $this->ReadAttributeInteger('ReferenceTimestamp');
        $reason = $this->ReadAttributeString('ReferenceReason');

        // ReferencePosition/ReferenceSlat beschreiben die zuletzt sicher
        // erreichte Endlage, nicht die aktuelle Zwischenposition. Die sichtbaren
        // Istwerte sind eigenständig persistent und dürfen bei ApplyChanges oder
        // Neustart nicht auf diese Endlage zurückgesetzt werden.
        $this->writeReferenceObjects(
            $stateCategoryID,
            $valid,
            $referencePosition,
            $referenceSlat,
            $timestamp,
            $reason,
            false
        );
        $this->SetValue('Referenziert', $valid);

        $positionID = @IPS_GetObjectIDByIdent('Ist_Behang', $stateCategoryID);
        $slatID = @IPS_GetObjectIDByIdent('Ist_Lamelle', $stateCategoryID);
        if ($positionID !== false && IPS_VariableExists((int) $positionID)) {
            $this->SetValue('Position', (int) round(max(0.0, min(100.0, GetValueFloat((int) $positionID)))));
        }
        if ($slatID !== false && IPS_VariableExists((int) $slatID)) {
            $this->SetValue('Drehgrad', (int) round(max(0.0, min(100.0, GetValueFloat((int) $slatID)))));
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

    private function writeReferenceObjects(
        int $stateCategoryID,
        bool $valid,
        float $position,
        float $slat,
        int $timestamp,
        string $reason,
        bool $writeCurrentPosition = true
    ): void
    {
        $referencedID = @IPS_GetObjectIDByIdent('Position_Referenziert', $stateCategoryID);
        if ($referencedID !== false && IPS_VariableExists((int) $referencedID)) {
            SetValueBoolean((int) $referencedID, $valid);
        }
        $positionID = @IPS_GetObjectIDByIdent('Ist_Behang', $stateCategoryID);
        $slatID = @IPS_GetObjectIDByIdent('Ist_Lamelle', $stateCategoryID);
        if ($valid && $writeCurrentPosition && $positionID !== false && IPS_VariableExists((int) $positionID)) {
            SetValueFloat((int) $positionID, $position);
        }
        if ($valid && $writeCurrentPosition && $slatID !== false && IPS_VariableExists((int) $slatID)) {
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

    private function compactConfigurationArray(): array
    {
        $result = [];
        foreach (self::COMPACT_CONFIG_MAP as $ident => [$property, $type]) {
            $result[$ident] = match ($type) {
                0 => $this->ReadPropertyBoolean($property),
                1 => $this->ReadPropertyInteger($property),
                2 => $this->ReadPropertyFloat($property),
                3 => $this->ReadPropertyString($property),
                default => throw new RuntimeException('Unbekannter Konfigurationstyp für ' . $ident),
            };
        }
        return $result;
    }

    private function normalizeCompactRuntimeState(array $state): array
    {
        $normalized = [];
        foreach (self::COMPACT_RUNTIME_DEFAULTS as $ident => $default) {
            $value = array_key_exists($ident, $state) ? $state[$ident] : $default;
            $normalized[$ident] = match (true) {
                is_bool($default) => (bool) $value,
                is_int($default) => (int) $value,
                is_float($default) => (float) $value,
                default => $value,
            };
        }
        return $normalized;
    }

    private function readCompactRuntimeState(): array
    {
        $raw = $this->GetBuffer('CompactRuntimeState');
        if ($raw === '') {
            return self::COMPACT_RUNTIME_DEFAULTS;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            // Ein beschädigter flüchtiger Buffer darf niemals zu einem
            // ungeprüften Motorbefehl führen. Der Controller initialisiert den
            // neutralen Zustand anschließend aus der realen Relaislage neu.
            return self::COMPACT_RUNTIME_DEFAULTS;
        }
        return $this->normalizeCompactRuntimeState($decoded);
    }

    private function writeCompactRuntimeState(array $state): void
    {
        $normalized = $this->normalizeCompactRuntimeState($state);
        $json = json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($json === false) {
            throw new RuntimeException('Kompakter Runtime-Zustand kann nicht serialisiert werden.');
        }
        $this->SetBuffer('CompactRuntimeState', $json);
    }

    private function snapshotCategoryVariableValues(string $categoryIdent): array
    {
        $categoryID = @IPS_GetObjectIDByIdent($categoryIdent, $this->InstanceID);
        if ($categoryID === false || !IPS_CategoryExists((int) $categoryID)) {
            return [];
        }
        $result = [];
        foreach (IPS_GetChildrenIDs((int) $categoryID) as $childID) {
            if (!IPS_VariableExists((int) $childID)) {
                continue;
            }
            $object = IPS_GetObject((int) $childID);
            $ident = (string) ($object['ObjectIdent'] ?? '');
            if ($ident === '') {
                continue;
            }
            $result[$ident] = GetValue((int) $childID);
        }
        ksort($result);
        return $result;
    }

    private function snapshotInstanceVisualizationValues(): array
    {
        $result = [];
        foreach (['Position', 'Drehgrad', 'Referenziert'] as $ident) {
            $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            $result[$ident] = ($id !== false && IPS_VariableExists((int) $id))
                ? GetValue((int) $id)
                : null;
        }
        return $result;
    }

    private function captureAndStoreLegacySnapshot(string $sourceVersion): void
    {
        $internal = $this->snapshotCategoryVariableValues('05_Intern');
        if ($internal === []) {
            $internal = $this->readCompactRuntimeState();
        } else {
            $internal = $this->normalizeCompactRuntimeState($internal);
        }

        $snapshot = [
            'schema' => 1,
            'sourceVersion' => $sourceVersion,
            'created' => time(),
            'instanceID' => $this->InstanceID,
            'configurationProperties' => $this->compactConfigurationArray(),
            'legacyConfigurationVariables' => $this->snapshotCategoryVariableValues('01_Konfiguration'),
            'internal' => $internal,
            'control' => $this->snapshotCategoryVariableValues('03_Bedienung'),
            'state' => $this->snapshotCategoryVariableValues('04_Istwerte'),
            'instanceVisualization' => $this->snapshotInstanceVisualizationValues(),
            'persistentReference' => [
                'valid' => $this->ReadAttributeBoolean('ReferenceValid'),
                'position' => $this->ReadAttributeFloat('ReferencePosition'),
                'slat' => $this->ReadAttributeFloat('ReferenceSlat'),
                'timestamp' => $this->ReadAttributeInteger('ReferenceTimestamp'),
                'reason' => $this->ReadAttributeString('ReferenceReason'),
            ],
            'fault' => [
                'latched' => $this->ReadAttributeBoolean('FaultLatched'),
                'message' => $this->ReadAttributeString('FaultMessage'),
            ],
        ];
        $json = json_encode(
            $snapshot,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($json === false || $json === '') {
            throw new RuntimeException('Rollback-Snapshot konnte nicht serialisiert werden.');
        }
        $hash = hash('sha256', $json);
        $this->WriteAttributeString('LegacyV127Snapshot', $json);
        $this->WriteAttributeString('LegacyV127SnapshotHash', $hash);
        $this->WriteAttributeInteger('LegacyV127SnapshotCreated', time());

        if (!$this->verifyLegacySnapshot()) {
            throw new RuntimeException('Rollback-Snapshot konnte nach dem Speichern nicht verifiziert werden.');
        }
    }

    private function verifyLegacySnapshot(): bool
    {
        $json = $this->ReadAttributeString('LegacyV127Snapshot');
        $expectedHash = $this->ReadAttributeString('LegacyV127SnapshotHash');
        if ($json === '' || $expectedHash === '' || !hash_equals($expectedHash, hash('sha256', $json))) {
            return false;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)
            || (int) ($decoded['schema'] ?? 0) !== 1
            || !is_array($decoded['configurationProperties'] ?? null)
            || !is_array($decoded['internal'] ?? null)
            || !is_array($decoded['persistentReference'] ?? null)) {
            return false;
        }
        foreach (array_keys(self::COMPACT_CONFIG_MAP) as $ident) {
            if (!array_key_exists($ident, $decoded['configurationProperties'])) {
                return false;
            }
        }
        foreach (array_keys(self::COMPACT_RUNTIME_DEFAULTS) as $ident) {
            if (!array_key_exists($ident, $decoded['internal'])) {
                return false;
            }
        }
        return true;
    }

    private function prepareCompactStorageMigration(string $previousVersion): void
    {
        if ($this->ReadAttributeInteger('CompactStorageSchemaVersion') === self::STORAGE_SCHEMA_VERSION
            && $this->ReadAttributeBoolean('CompactMigrationComplete')) {
            return;
        }

        $legacyConfigCount = $this->legacyVariableCount('01_Konfiguration');
        $legacyRuntimeCount = $this->legacyVariableCount('05_Intern');
        $hasAnyLegacyState = $legacyConfigCount > 0 || $legacyRuntimeCount > 0;
        if ($hasAnyLegacyState
            && ($legacyConfigCount !== count(self::COMPACT_CONFIG_MAP)
                || $legacyRuntimeCount !== count(self::COMPACT_RUNTIME_DEFAULTS))) {
            throw new RuntimeException(
                sprintf(
                    'Legacy-Struktur ist unvollständig (%d/%d Konfiguration, %d/%d Intern). Migration wurde vor jeder Löschung abgebrochen.',
                    $legacyConfigCount,
                    count(self::COMPACT_CONFIG_MAP),
                    $legacyRuntimeCount,
                    count(self::COMPACT_RUNTIME_DEFAULTS)
                )
            );
        }

        if (!$this->verifyLegacySnapshot()) {
            $this->captureAndStoreLegacySnapshot($previousVersion !== '' ? $previousVersion : 'Neuinstallation');
        }

        // Solange die V0.1.27-Internvariablen noch vorhanden sind, werden ihre
        // aktuellen Werte exakt übernommen. Erst nach erfolgreichem Aufbau und
        // vollständiger Validierung werden die alten Variablen entfernt.
        $legacyInternal = $this->snapshotCategoryVariableValues('05_Intern');
        if ($legacyInternal !== []) {
            $runtime = $this->normalizeCompactRuntimeState($legacyInternal);
            $this->writeCompactRuntimeState($runtime);
        } elseif ($this->GetBuffer('CompactRuntimeState') === '') {
            $this->writeCompactRuntimeState(self::COMPACT_RUNTIME_DEFAULTS);
        }

        // Roundtrip-Prüfung: kein Löschen der Legacy-Struktur, solange der neue
        // Speicher nicht alle Zustandsfelder korrekt zurückliefert.
        $roundtrip = $this->readCompactRuntimeState();
        if (array_keys($roundtrip) !== array_keys(self::COMPACT_RUNTIME_DEFAULTS)) {
            throw new RuntimeException('Kompakter Runtime-Speicher ist unvollständig; Migration abgebrochen.');
        }
        $configRoundtrip = json_decode($this->GetCompactConfiguration(), true);
        if (!is_array($configRoundtrip) || array_keys($configRoundtrip) !== array_keys(self::COMPACT_CONFIG_MAP)) {
            throw new RuntimeException('Kompakte Konfiguration ist unvollständig; Migration abgebrochen.');
        }

        $this->WriteAttributeInteger('CompactStorageSchemaVersion', self::STORAGE_SCHEMA_VERSION);
        $this->WriteAttributeString('CompactMigrationSourceVersion', $previousVersion !== '' ? $previousVersion : 'Neuinstallation');
        $this->WriteAttributeBoolean('RollbackPrepared', false);
    }

    private function legacyVariableIdentsForCategory(string $categoryIdent): array
    {
        return match ($categoryIdent) {
            '01_Konfiguration' => array_keys(self::COMPACT_CONFIG_MAP),
            '05_Intern' => array_keys(self::COMPACT_RUNTIME_DEFAULTS),
            default => [],
        };
    }

    private function deleteLegacyVariablesFromCategory(string $categoryIdent): void
    {
        $categoryID = @IPS_GetObjectIDByIdent($categoryIdent, $this->InstanceID);
        if ($categoryID === false || !IPS_CategoryExists((int) $categoryID)) {
            return;
        }

        // Ausschließlich die vom Modul selbst erzeugten V0.1.27-Idents
        // entfernen. Eventuelle benutzerdefinierte Variablen in derselben
        // Kategorie sind kein Teil der Migration und bleiben unangetastet.
        foreach ($this->legacyVariableIdentsForCategory($categoryIdent) as $ident) {
            $childID = @IPS_GetObjectIDByIdent($ident, (int) $categoryID);
            if ($childID !== false && IPS_VariableExists((int) $childID)) {
                IPS_DeleteVariable((int) $childID);
            }
        }
    }

    private function legacyVariableCount(string $categoryIdent): int
    {
        $categoryID = @IPS_GetObjectIDByIdent($categoryIdent, $this->InstanceID);
        if ($categoryID === false || !IPS_CategoryExists((int) $categoryID)) {
            return 0;
        }
        $count = 0;
        foreach ($this->legacyVariableIdentsForCategory($categoryIdent) as $ident) {
            $childID = @IPS_GetObjectIDByIdent($ident, (int) $categoryID);
            if ($childID !== false && IPS_VariableExists((int) $childID)) {
                $count++;
            }
        }
        return $count;
    }

    private function writeCompactRuntimeToLegacyVariables(int $internalCategoryID, array $state): void
    {
        $state = $this->normalizeCompactRuntimeState($state);
        foreach (self::COMPACT_RUNTIME_DEFAULTS as $ident => $default) {
            $id = $this->find($internalCategoryID, $ident);
            $value = $state[$ident];
            match (true) {
                is_bool($default) => SetValueBoolean($id, (bool) $value),
                is_int($default) => SetValueInteger($id, (int) $value),
                is_float($default) => SetValueFloat($id, (float) $value),
                default => null,
            };
        }
    }

    private function verifyLegacyVariableTreeForRollback(int $configurationID, int $internalID): void
    {
        foreach (self::COMPACT_CONFIG_MAP as $ident => $_) {
            $id = @IPS_GetObjectIDByIdent($ident, $configurationID);
            if ($id === false || !IPS_VariableExists((int) $id)) {
                throw new RuntimeException('Rollback-Konfigurationsvariable fehlt: ' . $ident);
            }
        }
        foreach (self::COMPACT_RUNTIME_DEFAULTS as $ident => $_) {
            $id = @IPS_GetObjectIDByIdent($ident, $internalID);
            if ($id === false || !IPS_VariableExists((int) $id)) {
                throw new RuntimeException('Rollback-Internvariable fehlt: ' . $ident);
            }
        }
    }

    private function restoreLegacyVariableTreeFromSnapshot(): void
    {
        if (!$this->verifyLegacySnapshot()) {
            throw new RuntimeException('Rollback-Snapshot ist nicht verfügbar oder beschädigt.');
        }
        $snapshot = json_decode($this->ReadAttributeString('LegacyV127Snapshot'), true, 512, JSON_THROW_ON_ERROR);
        $configurationID = $this->category('01_Konfiguration', '01 Konfiguration', 10);
        $internalID = $this->category('05_Intern', '05 Intern', 50);
        $this->createConfigurationVariables($configurationID);
        $this->createInternalVariables($internalID);
        // Properties sind die maßgebliche aktuelle Konfiguration. Dadurch
        // bleibt auch eine nach der Migration vorgenommene Änderung rollbackfähig.
        $this->synchronizeConfiguration($configurationID);
        // Bei einem fehlgeschlagenen Löschvorgang muss die rekonstruierte
        // V0.1.27-Struktur den *aktuellen* Kompaktzustand erhalten. Der
        // ursprüngliche Snapshot dient als verifizierte Rückfallebene, darf
        // aber einen inzwischen fortgeschriebenen Runtime-Zustand nicht
        // zurückdrehen (z. B. wenn die Bereinigung bis Relais-AUS vertagt war).
        $this->writeCompactRuntimeToLegacyVariables(
            $internalID,
            $this->readCompactRuntimeState()
        );
        $this->verifyLegacyVariableTreeForRollback($configurationID, $internalID);
    }

    private function canSafelyDeleteLegacyVariables(): bool
    {
        $relayUpID = $this->ReadPropertyInteger('RelayUpVariableID');
        $relayDownID = $this->ReadPropertyInteger('RelayDownVariableID');
        return $this->isBooleanVariable($relayUpID)
            && $this->isBooleanVariable($relayDownID)
            && !GetValueBoolean($relayUpID)
            && !GetValueBoolean($relayDownID);
    }

    private function tryFinalizeCompactStorageMigrationSafely(bool $configurationKnownValid = false): void
    {
        if (!$configurationKnownValid
            || $this->ReadAttributeInteger('CompactStorageSchemaVersion') !== self::STORAGE_SCHEMA_VERSION
            || $this->ReadAttributeBoolean('CompactMigrationComplete')
            || $this->ReadAttributeBoolean('RollbackPrepared')
            || $this->GetBuffer('MaintenanceActive') === '1') {
            return;
        }

        // Die nachträgliche Bereinigung (z. B. nach einer beim Update noch
        // laufenden Fahrt) verwendet dieselbe Instanzsperre wie Controller und
        // Worker. Dadurch kann kein Runtime-Flush gleichzeitig Variablen
        // zurückschreiben, während die Legacy-Struktur entfernt wird.
        $lockName = 'Jalousie_PHP_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lockName, 1000)) {
            return;
        }
        try {
            $this->finalizeCompactStorageMigration();
        } catch (Throwable $e) {
            // Die Steuerung selbst bleibt auf dem bereits funktionierenden
            // Kompaktspeicher. Nur die Variablenbereinigung wird vertagt.
            $this->SendDebug('CompactMigration', 'Legacy-Bereinigung vertagt: ' . $e->getMessage(), 0);
            $this->LogMessage('Kompaktmigration: Legacy-Bereinigung wurde sicher zurückgerollt und wird später erneut versucht.', 10204);
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
    }

    private function finalizeCompactStorageMigration(): void
    {
        if ($this->ReadAttributeBoolean('RollbackPrepared') || $this->ReadAttributeBoolean('CompactMigrationComplete')) {
            return;
        }
        if ($this->legacyVariableCount('01_Konfiguration') === 0
            && $this->legacyVariableCount('05_Intern') === 0) {
            $this->WriteAttributeBoolean('CompactMigrationComplete', true);
            return;
        }

        // Während eines laufenden Motors wird niemals an der Legacy-Struktur
        // gelöscht. Die neuen Skripte arbeiten bereits aus dem Kompaktspeicher;
        // die Bereinigung erfolgt automatisch beim nächsten Healthcheck im
        // sicheren Relais-AUS-Zustand.
        if (!$this->canSafelyDeleteLegacyVariables()) {
            return;
        }
        if (!$this->verifyLegacySnapshot()) {
            throw new RuntimeException('Rollback-Snapshot ist vor der Bereinigung nicht verifizierbar.');
        }
        // Erneut sicherstellen, dass beide neuen Speicherquellen lesbar sind.
        $this->readCompactRuntimeState();
        $this->compactConfigurationArray();

        try {
            $this->deleteLegacyVariablesFromCategory('01_Konfiguration');
            $this->deleteLegacyVariablesFromCategory('05_Intern');
            if ($this->legacyVariableCount('01_Konfiguration') !== 0
                || $this->legacyVariableCount('05_Intern') !== 0) {
                throw new RuntimeException('Nicht alle Legacy-Variablen konnten entfernt werden.');
            }
            $configurationID = @IPS_GetObjectIDByIdent('01_Konfiguration', $this->InstanceID);
            $internalID = @IPS_GetObjectIDByIdent('05_Intern', $this->InstanceID);
            if ($configurationID !== false) {
                IPS_SetName((int) $configurationID, '01 Konfiguration (kompakt)');
            }
            if ($internalID !== false) {
                IPS_SetName((int) $internalID, '05 Intern (kompakt)');
            }
            $this->WriteAttributeBoolean('CompactMigrationComplete', true);
        } catch (Throwable $e) {
            // Die Bereinigung selbst ist nicht atomar. Falls Symcon beim
            // Löschen eines Objekts abbricht, wird die vollständige V0.1.27-
            // Struktur aus dem vorher verifizierten Snapshot rekonstruiert.
            $this->restoreLegacyVariableTreeFromSnapshot();
            $this->WriteAttributeBoolean('CompactMigrationComplete', false);
            throw new RuntimeException('Kompaktmigration zurückgerollt: ' . $e->getMessage(), 0, $e);
        }
    }

    private function ensureObjectTree(): array
    {
        $configuration = $this->category('01_Konfiguration', '01 Konfiguration (kompakt)', 10);
        $lcn = $this->category('02_LCN_Status', '02 LCN-Status', 20);
        $control = $this->category('03_Bedienung', '03 Bedienung', 30);
        $state = $this->category('04_Istwerte', '04 Istwerte', 40);
        $internal = $this->category('05_Intern', '05 Intern (kompakt)', 50);
        $scripts = $this->category('06_Skripte', '06 Skripte', 60);
        $visualization = $this->category('07_Visualisierung', '07 Visualisierung', 70);
        $acceptance = $this->category('08_Abnahme', '08 Abnahme', 80);

        $this->createControlVariables($control);
        $this->createStateVariables($state);
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
            ['Befehlsabstand_ms', 'Mindestabstand LCN-Telegramme [ms]', 1, '', 275, 100, true],
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
            ['Startstatus_Nachfrage_Aktiv', 'Zusätzliche Startstatus-Abfrage aktiv', 0, '~Switch', 300, false, false],
            ['Stopstatus_Nachfrage_Aktiv', 'Zusätzliche Stoppstatus-Abfrage aktiv', 0, '~Switch', 310, false, false],
            ['Startstatus_Relais_AUF_Empfangen', 'Startstatus Relais AUF frisch empfangen', 0, '~Switch', 320, false, false],
            ['Startstatus_Relais_AB_Empfangen', 'Startstatus Relais AB frisch empfangen', 0, '~Switch', 330, false, false],
            ['Stopstatus_Relais_AUF_Empfangen', 'Stoppstatus Relais AUF frisch empfangen', 0, '~Switch', 340, false, false],
            ['Stopstatus_Relais_AB_Empfangen', 'Stoppstatus Relais AB frisch empfangen', 0, '~Switch', 350, false, false],
            ['Stop_Wiederholung_Gesendet', 'Verifizierte STOP-Wiederholung gesendet', 0, '~Switch', 360, false, false],
            ['Befehl_gesendet_ms', 'LCN-Befehl gesendet [monotone ms]', 2, '', 370, 0.0, false],
            ['Externe_Referenz_Gesetzt', 'Externe Endlage während aktueller Fahrt referenziert', 0, '~Switch', 380, false, false],
            ['Externe_Endlage_bis_ms', 'Externe sichere Endlage bis [monotone ms]', 2, '', 390, 0.0, false],
            ['Externer_Autostopp_bis_ms', 'Externer Endlagen-Autostopp bis [monotone ms]', 2, '', 400, 0.0, false],
            ['Externer_Autostopp_Aktiv', 'Externer Endlagen-Autostopp aktiv', 0, '~Switch', 410, false, false],
            ['Fremdbefehl_Quelle', 'Möglicher fremder Symcon-Befehl von Instanz', 1, '', 420, 0, false],
            ['Fremdbefehl_Erkannt_ms', 'Möglicher Fremdbefehl erkannt [monotone ms]', 2, '', 430, 0.0, false],
        ];
        foreach ($schema as $v) {
            $this->variable($parentID, ...$v);
        }
    }

    private function synchronizeConfiguration(int $categoryID): void
    {
        foreach (self::COMPACT_CONFIG_MAP as $ident => [$property, $type]) {
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
            'Controller' => ['10 Controller V12.0', 20, 'Controller.php'],
            'Worker' => ['20 Worker V12.0', 30, 'Worker.php'],
            'Healthcheck' => ['30 Healthcheck V12.0', 40, 'Healthcheck.php'],
            'Diagnose' => ['90 Diagnose V12.0', 90, 'Diagnose.php'],
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

    private function suspendRuntimeForApplyChanges(): void
    {
        $this->WriteAttributeBoolean('RuntimeEnabled', false);
        $scriptsCategoryID = @IPS_GetObjectIDByIdent('06_Skripte', $this->InstanceID);
        if ($scriptsCategoryID === false) {
            return;
        }

        $controllerID = @IPS_GetObjectIDByIdent('Controller', (int) $scriptsCategoryID);
        if ($controllerID !== false && IPS_ScriptExists((int) $controllerID)) {
            foreach (['Evt_Relais_AUF', 'Evt_Relais_AB', 'Evt_GT8_LANG_AUF', 'Evt_GT8_LANG_AB'] as $ident) {
                $eventID = @IPS_GetObjectIDByIdent($ident, (int) $controllerID);
                if ($eventID !== false && IPS_EventExists((int) $eventID)) {
                    @IPS_SetEventActive((int) $eventID, false);
                }
            }
        }

        foreach (['Worker', 'Healthcheck'] as $ident) {
            $scriptID = @IPS_GetObjectIDByIdent($ident, (int) $scriptsCategoryID);
            if ($scriptID !== false && IPS_ScriptExists((int) $scriptID)) {
                @IPS_SetScriptTimer((int) $scriptID, 0);
            }
        }
    }

    private function autoRecoverTransientMaintenanceFault(array $validation): void
    {
        if (!$this->ReadAttributeBoolean('FaultLatched')
            || (int) ($validation['status'] ?? 0) !== self::STATUS_ACTIVE) {
            return;
        }

        $relayUpID = $this->ReadPropertyInteger('RelayUpVariableID');
        $relayDownID = $this->ReadPropertyInteger('RelayDownVariableID');
        if (!$this->isBooleanVariable($relayUpID)
            || !$this->isBooleanVariable($relayDownID)
            || GetValueBoolean($relayUpID)
            || GetValueBoolean($relayDownID)) {
            return;
        }

        $message = trim($this->ReadAttributeString('FaultMessage'));
        $transient = $message !== '' && (
            str_starts_with($message, 'Aufbaufehler:')
            || str_contains($message, 'Die sichere Hardwarebindung des Moduls ist nicht verfügbar')
            || str_contains($message, 'Die sichere Hardwarebindung ist ungültig')
            || str_contains($message, 'Sichere richtungsgebundene Befehlsfunktion fehlt')
            || str_contains($message, 'Statusabgleich ohne aktuelle OnUpdate-Rueckmeldung')
            || str_contains($message, 'Statusabgleich ohne aktuelle OnUpdate-Rückmeldung')
            || str_contains($message, 'LCN_RequestStatus ist in diesem Symcon-Kernel nicht registriert')
        );
        if (!$transient) {
            return;
        }

        $this->WriteAttributeBoolean('FaultLatched', false);
        $this->WriteAttributeString('FaultMessage', '');
        $this->setFaultStateVariable(false);

        $stateCategoryID = @IPS_GetObjectIDByIdent('04_Istwerte', $this->InstanceID);
        if ($stateCategoryID !== false) {
            $faultTextID = @IPS_GetObjectIDByIdent('Fehlertext', (int) $stateCategoryID);
            if ($faultTextID !== false && IPS_VariableExists((int) $faultTextID)) {
                SetValueString((int) $faultTextID, '');
            }
            $lastActionID = @IPS_GetObjectIDByIdent('Letzte_Aktion', (int) $stateCategoryID);
            if ($lastActionID !== false && IPS_VariableExists((int) $lastActionID)) {
                SetValueString((int) $lastActionID, date('d.m.Y H:i:s') . ' - Transiente Updateverriegelung automatisch aufgehoben');
            }
        }
    }

    private function setRuntimeEnabled(bool $enabled, int $scriptsCategoryID): void
    {
        $controllerID = $this->find($scriptsCategoryID, 'Controller');
        // Reale Relaisereignisse bleiben auch bei deaktivierter oder verriegelter
        // Symcon-Automatik aktiv. So kann eine lokale LCN-/GT8-Fahrt weiterhin
        // erkannt werden, ohne dass Symcon einen Befehl sendet. GT8-Hilfsereignisse
        // für Lamellenautomatik bleiben dagegen an RuntimeEnabled gekoppelt.
        foreach (['Evt_Relais_AUF', 'Evt_Relais_AB'] as $ident) {
            $id = @IPS_GetObjectIDByIdent($ident, $controllerID);
            if ($id !== false && IPS_EventExists((int) $id)) {
                IPS_SetEventActive((int) $id, true);
            }
        }
        foreach (['Evt_GT8_LANG_AUF', 'Evt_GT8_LANG_AB'] as $ident) {
            $id = @IPS_GetObjectIDByIdent($ident, $controllerID);
            if ($id !== false && IPS_EventExists((int) $id)) {
                IPS_SetEventActive((int) $id, $enabled);
            }
        }
        $workerID = $this->find($scriptsCategoryID, 'Worker');
        $healthID = $this->find($scriptsCategoryID, 'Healthcheck');
        IPS_SetScriptTimer($workerID, 0);
        // Der Healthcheck bleibt bei aktiviertem Modul auch während einer
        // Fehlerverriegelung oder vorübergehend fehlender Laufzeitfreigabe aktiv.
        // Er darf dann keine Bedienaufträge ausführen, sichert aber externe
        // Relaisfahrten und die automatische Endlagenabschaltung unabhängig ab.
        IPS_SetScriptTimer(
            $healthID,
            $this->ReadPropertyBoolean('ModuleEnabled')
                ? max(1, $this->ReadPropertyInteger('HealthcheckSeconds'))
                : 0
        );

        if (!$enabled) {
            $runtime = $this->readCompactRuntimeState();
            $runtime['Worker_Aktiv'] = false;
            $this->writeCompactRuntimeState($runtime);
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
