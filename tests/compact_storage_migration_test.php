<?php

declare(strict_types=1);

class IPSModuleStrict
{
    public int $InstanceID;
    public array $properties = [];
    public array $attributes = [];
    public array $buffers = [];
    public function __construct(int $id) { $this->InstanceID = $id; }
    public function Migrate(string $json): string { return ''; }
    public function ReadPropertyInteger(string $n): int { return (int)($this->properties[$n] ?? 0); }
    public function ReadPropertyBoolean(string $n): bool { return (bool)($this->properties[$n] ?? false); }
    public function ReadPropertyFloat(string $n): float { return (float)($this->properties[$n] ?? 0.0); }
    public function ReadPropertyString(string $n): string { return (string)($this->properties[$n] ?? ''); }
    public function ReadAttributeBoolean(string $n): bool { return (bool)($this->attributes[$n] ?? false); }
    public function ReadAttributeString(string $n): string { return (string)($this->attributes[$n] ?? ''); }
    public function ReadAttributeFloat(string $n): float { return (float)($this->attributes[$n] ?? 0.0); }
    public function ReadAttributeInteger(string $n): int { return (int)($this->attributes[$n] ?? 0); }
    public function WriteAttributeBoolean(string $n, bool $v): void { $this->attributes[$n] = $v; }
    public function WriteAttributeString(string $n, string $v): void { $this->attributes[$n] = $v; }
    public function WriteAttributeFloat(string $n, float $v): void { $this->attributes[$n] = $v; }
    public function WriteAttributeInteger(string $n, int $v): void { $this->attributes[$n] = $v; }
    public function SetBuffer(string $n, string $v): void { $this->buffers[$n] = $v; }
    public function GetBuffer(string $n): string { return (string)($this->buffers[$n] ?? ''); }
}

$GLOBALS['nextID'] = 100;
$GLOBALS['objects'] = [];
$GLOBALS['variables'] = [];
$GLOBALS['values'] = [];
$GLOBALS['deleteCalls'] = 0;
$GLOBALS['failDeleteAt'] = 0;

function newObject(string $type, int $parent, string $ident, string $name = ''): int {
    $id = $GLOBALS['nextID']++;
    $GLOBALS['objects'][$id] = ['type'=>$type,'parent'=>$parent,'ident'=>$ident,'name'=>$name ?: $ident,'position'=>0,'hidden'=>false];
    return $id;
}
function IPS_CreateCategory(): int { return newObject('category',0,''); }
function IPS_CreateVariable(int $type): int { $id=newObject('variable',0,''); $GLOBALS['variables'][$id]=['VariableType'=>$type,'VariableCustomProfile'=>'']; $GLOBALS['values'][$id]=match($type){0=>false,1=>0,2=>0.0,3=>''}; return $id; }
function IPS_SetParent(int $id,int $parent): void { $GLOBALS['objects'][$id]['parent']=$parent; }
function IPS_SetIdent(int $id,string $ident): void { $GLOBALS['objects'][$id]['ident']=$ident; }
function IPS_SetName(int $id,string $name): void { $GLOBALS['objects'][$id]['name']=$name; }
function IPS_SetPosition(int $id,int $pos): void { $GLOBALS['objects'][$id]['position']=$pos; }
function IPS_SetHidden(int $id,bool $hidden): void { $GLOBALS['objects'][$id]['hidden']=$hidden; }
function IPS_SetVariableCustomProfile(int $id,string $profile): void { $GLOBALS['variables'][$id]['VariableCustomProfile']=$profile; }
function IPS_GetObjectIDByIdent(string $ident,int $parent): int|false { foreach($GLOBALS['objects'] as $id=>$o){ if($o['parent']===$parent && $o['ident']===$ident) return $id; } return false; }
function IPS_CategoryExists(int $id): bool { return isset($GLOBALS['objects'][$id]) && $GLOBALS['objects'][$id]['type']==='category'; }
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['variables'][$id]); }
function IPS_GetVariable(int $id): array { return $GLOBALS['variables'][$id]; }
function IPS_GetObject(int $id): array { $o=$GLOBALS['objects'][$id]; return ['ObjectIdent'=>$o['ident'],'ObjectName'=>$o['name'],'ObjectPosition'=>$o['position'],'ObjectIsHidden'=>$o['hidden']]; }
function IPS_GetChildrenIDs(int $parent): array { $ids=[]; foreach($GLOBALS['objects'] as $id=>$o){ if($o['parent']===$parent) $ids[]=$id; } sort($ids); return $ids; }
function IPS_DeleteVariable(int $id): void { $GLOBALS['deleteCalls']++; if($GLOBALS['failDeleteAt']>0 && $GLOBALS['deleteCalls']===$GLOBALS['failDeleteAt']) throw new RuntimeException('simulierter Loeschfehler'); unset($GLOBALS['variables'][$id],$GLOBALS['values'][$id],$GLOBALS['objects'][$id]); }
function SetValueBoolean(int $id,bool $v): void { $GLOBALS['values'][$id]=$v; }
function SetValueInteger(int $id,int $v): void { $GLOBALS['values'][$id]=$v; }
function SetValueFloat(int $id,float $v): void { $GLOBALS['values'][$id]=$v; }
function SetValueString(int $id,string $v): void { $GLOBALS['values'][$id]=$v; }
function GetValue(int $id): mixed { return $GLOBALS['values'][$id]; }
function GetValueBoolean(int $id): bool { return (bool)$GLOBALS['values'][$id]; }
function GetValueInteger(int $id): int { return (int)$GLOBALS['values'][$id]; }
function GetValueFloat(int $id): float { return (float)$GLOBALS['values'][$id]; }
function GetValueString(int $id): string { return (string)$GLOBALS['values'][$id]; }
function IPS_SemaphoreEnter(string $name,int $timeout): bool { return true; }
function IPS_SemaphoreLeave(string $name): void {}

require dirname(__DIR__) . '/LCNJalousie/module.php';

function callPrivate(object $o,string $name,mixed ...$args): mixed { $r=new ReflectionMethod($o,$name); $r->setAccessible(true); return $r->invoke($o,...$args); }
function assertTrue(bool $v,string $m): void { if(!$v) throw new RuntimeException($m); }
function assertSameValue(mixed $e,mixed $a,string $m): void { if($e!==$a) throw new RuntimeException($m.' expected '.var_export($e,true).' got '.var_export($a,true)); }
function categoryCount(int $root,string $ident): int { $c=IPS_GetObjectIDByIdent($ident,$root); if($c===false) return 0; $n=0; foreach(IPS_GetChildrenIDs($c) as $id) if(IPS_VariableExists($id)) $n++; return $n; }
function varID(int $root,string $cat,string $ident): int { $c=IPS_GetObjectIDByIdent($cat,$root); $id=$c===false?false:IPS_GetObjectIDByIdent($ident,$c); if($id===false) throw new RuntimeException('missing '.$cat.'/'.$ident); return $id; }

function baseModule(int $id): LCNJalousie {
    $m=new LCNJalousie($id);
    $m->properties=[
        'ProjectName'=>'Test','ModuleEnabled'=>true,'LCNSendModuleID'=>10,'LCNActorModuleID'=>11,
        'RelayUpVariableID'=>9001,'RelayDownVariableID'=>9002,'GT8LongUpVariableID'=>9003,'GT8LongDownVariableID'=>9004,
        'TSShortUp'=>'K---00010000','TSShortDown'=>'K---00000100','TSMappingConfirmed'=>true,
        'TotalTravelMs'=>182000,'TurnMs'=>6500,'SoftStartMs'=>6000,'SoftStopUpMs'=>4500,'SoftStopDownMs'=>4500,
        'BlindTravelMs'=>175500,'ReferenceReserveMs'=>5000,'MaxTravelMs'=>187000,'ShakeFreeMs'=>6500,'ShakeFreePauseMs'=>500,
        'CalibrationWindowMs'=>30000,'RelayConfirmMs'=>2500,'StopConfirmMs'=>3000,'LateStartGuardMs'=>5000,'WorkerWindowMs'=>1500,
        'StatusSyncMs'=>1500,'RelayCoalesceMs'=>100,'CommandSpacingMs'=>100,'HealthcheckSeconds'=>10,
        'PositionTolerance'=>0.5,'SlatTolerance'=>0.5,'AllowUnreferenced'=>false,'DiagnosticLog'=>false,'RequestStatusOnStart'=>true,
    ];
    $m->attributes=[
        'GeneratedVersion'=>'0.1.27','ReferenceValid'=>true,'ReferencePosition'=>100.0,'ReferenceSlat'=>100.0,
        'ReferenceTimestamp'=>123456,'ReferenceReason'=>'sicher','FaultLatched'=>false,'FaultMessage'=>'',
        'CompactStorageSchemaVersion'=>0,'CompactMigrationComplete'=>false,'CompactMigrationSourceVersion'=>'',
        'LegacyV127Snapshot'=>'','LegacyV127SnapshotHash'=>'','LegacyV127SnapshotCreated'=>0,'RollbackPrepared'=>false,
    ];
    // Real relay variables outside module tree.
    foreach([9001,9002,9003,9004] as $rid){ $GLOBALS['objects'][$rid]=['type'=>'variable','parent'=>0,'ident'=>'r'.$rid,'name'=>'r'.$rid,'position'=>0,'hidden'=>false]; $GLOBALS['variables'][$rid]=['VariableType'=>0,'VariableCustomProfile'=>'']; $GLOBALS['values'][$rid]=false; }
    return $m;
}

function buildLegacyTree(LCNJalousie $m): void {
    $root=$m->InstanceID;
    $GLOBALS['objects'][$root]=['type'=>'instance','parent'=>0,'ident'=>'root'.$root,'name'=>'root','position'=>0,'hidden'=>false];
    foreach(['01_Konfiguration','03_Bedienung','04_Istwerte','05_Intern'] as $ident){ $c=IPS_CreateCategory(); IPS_SetParent($c,$root); IPS_SetIdent($c,$ident); }
    $config=IPS_GetObjectIDByIdent('01_Konfiguration',$root); $intern=IPS_GetObjectIDByIdent('05_Intern',$root);
    callPrivate($m,'createConfigurationVariables',$config);
    callPrivate($m,'createInternalVariables',$intern);
    callPrivate($m,'synchronizeConfiguration',$config);
    // Minimal visible values for snapshot/persistence verification.
    foreach([['03_Bedienung','Soll_Behang',1,42],['04_Istwerte','Ist_Behang',2,37.5],['04_Istwerte','Ist_Lamelle',2,50.0]] as [$cat,$ident,$type,$value]){
        $c=IPS_GetObjectIDByIdent($cat,$root); $v=IPS_CreateVariable($type); IPS_SetParent($v,$c); IPS_SetIdent($v,$ident); if($type===1) SetValueInteger($v,(int)$value); else SetValueFloat($v,(float)$value);
    }
    SetValueInteger(varID($root,'05_Intern','Auftragsnummer'),77);
    SetValueInteger(varID($root,'05_Intern','Auftragstyp'),2);
    SetValueFloat(varID($root,'05_Intern','Start_Behang'),37.5);
}

// 0) Symcon module-update migration must add the new persistent attributes
// without overwriting any existing persistence value.
$migrateProbe=new LCNJalousie(99);
$oldPersistence=json_encode([
    'attributes'=>['GeneratedVersion'=>'0.1.27','ReferenceValid'=>true,'ReferencePosition'=>42.0],
    'configuration'=>['ProjectName'=>'Probe']
],JSON_THROW_ON_ERROR);
$migratedJson=$migrateProbe->Migrate($oldPersistence);
assertTrue($migratedJson!=='','Migrate returns updated persistence on first V0.1.28 update');
$migrated=json_decode($migratedJson,true,512,JSON_THROW_ON_ERROR);
assertSameValue('0.1.27',$migrated['attributes']['GeneratedVersion'],'Migrate keeps existing version attribute');
assertSameValue(true,$migrated['attributes']['ReferenceValid'],'Migrate keeps reference validity');
assertSameValue(42,$migrated['attributes']['ReferencePosition'],'Migrate keeps reference position');
assertSameValue(0,$migrated['attributes']['CompactStorageSchemaVersion'],'Migrate adds compact schema attribute');
assertSameValue(false,$migrated['attributes']['CompactMigrationComplete'],'Migrate adds completion attribute');
assertSameValue('',$migrated['attributes']['LegacyV127Snapshot'],'Migrate adds rollback snapshot attribute');
assertSameValue(false,$migrated['attributes']['RollbackPrepared'],'Migrate adds rollback flag');
assertSameValue('', $migrateProbe->Migrate($migratedJson), 'Migrate is idempotent once attributes exist');

// A) Successful one-step migration.
$m=baseModule(1); buildLegacyTree($m);
assertSameValue(35,categoryCount(1,'01_Konfiguration'),'legacy config count');
assertSameValue(43,categoryCount(1,'05_Intern'),'legacy runtime count');
callPrivate($m,'prepareCompactStorageMigration','0.1.27');
assertTrue((string)$m->attributes['LegacyV127Snapshot']!=='','snapshot stored');
assertTrue(callPrivate($m,'verifyLegacySnapshot'),'snapshot hash valid');
$runtime=json_decode($m->GetCompactRuntimeState(),true,512,JSON_THROW_ON_ERROR);
assertSameValue(77,$runtime['Auftragsnummer'],'runtime order migrated');
assertSameValue(2,$runtime['Auftragstyp'],'runtime type migrated');
assertSameValue(37.5,$runtime['Start_Behang'],'runtime float migrated');
assertSameValue(35,categoryCount(1,'01_Konfiguration'),'legacy config retained before commit');
assertSameValue(43,categoryCount(1,'05_Intern'),'legacy runtime retained before commit');
callPrivate($m,'finalizeCompactStorageMigration');
assertSameValue(0,categoryCount(1,'01_Konfiguration'),'config removed after commit');
assertSameValue(0,categoryCount(1,'05_Intern'),'runtime removed after commit');
assertSameValue(true,$m->attributes['CompactMigrationComplete'],'migration complete');
assertTrue(callPrivate($m,'verifyLegacySnapshot'),'snapshot remains valid after commit');
assertSameValue(true,$m->attributes['ReferenceValid'],'reference retained');
assertSameValue(100.0,$m->attributes['ReferencePosition'],'reference position retained');
assertSameValue(37.5,GetValueFloat(varID(1,'04_Istwerte','Ist_Behang')),'visible position retained');

// B) Rollback preparation restores all 78 legacy variables using CURRENT properties/runtime.
$m->properties['TotalTravelMs']=190000;
$runtime['Auftragsnummer']=88; $runtime['Auftragstyp']=0; $m->SetCompactRuntimeState(json_encode($runtime,JSON_THROW_ON_ERROR));
$msg=$m->PrepareRollbackV127();
assertTrue(str_contains($msg,'Rollback auf V0.1.27 vorbereitet'),'rollback message');
assertSameValue(35,categoryCount(1,'01_Konfiguration'),'rollback config count');
assertSameValue(43,categoryCount(1,'05_Intern'),'rollback runtime count');
assertSameValue(190000,GetValueInteger(varID(1,'01_Konfiguration','Gesamtlaufzeit_ms')),'rollback uses current property');
assertSameValue(88,GetValueInteger(varID(1,'05_Intern','Auftragsnummer')),'rollback uses current runtime');
assertSameValue(true,$m->attributes['ReferenceValid'],'rollback leaves reference');
// Bis zum tatsächlichen Downgrade muss die rekonstruierte V0.1.27-Struktur
// mit dem Kompaktzustand synchron bleiben.
$runtimeAfterRollback=json_decode($m->GetCompactRuntimeState(),true,512,JSON_THROW_ON_ERROR);
$runtimeAfterRollback['Auftragsnummer']=89;
$m->SetCompactRuntimeState(json_encode($runtimeAfterRollback,JSON_THROW_ON_ERROR));
assertSameValue(89,GetValueInteger(varID(1,'05_Intern','Auftragsnummer')),'prepared rollback legacy runtime remains synchronized');

// C) Simulated partial deletion failure must reconstruct the complete legacy tree.
$m2=baseModule(2); buildLegacyTree($m2); callPrivate($m2,'prepareCompactStorageMigration','0.1.27');
// Die Legacy-Bereinigung kann bis zu einem späteren Relais-AUS-Zeitpunkt
// vertagt sein. Ein dann bereits fortgeschriebener Kompaktzustand darf bei
// einem simulierten Teil-Löschfehler nicht auf den alten Snapshot zurückfallen.
$currentRuntime=json_decode($m2->GetCompactRuntimeState(),true,512,JSON_THROW_ON_ERROR);
$currentRuntime['Auftragsnummer']=99;
$currentRuntime['Start_Behang']=63.25;
$m2->SetCompactRuntimeState(json_encode($currentRuntime,JSON_THROW_ON_ERROR));
$GLOBALS['deleteCalls']=0; $GLOBALS['failDeleteAt']=12;
$failed=false;
try { callPrivate($m2,'finalizeCompactStorageMigration'); } catch(Throwable $e) { $failed=true; }
$GLOBALS['failDeleteAt']=0;
assertTrue($failed,'simulated delete failure propagated');
assertSameValue(35,categoryCount(2,'01_Konfiguration'),'config fully restored after partial delete failure');
assertSameValue(43,categoryCount(2,'05_Intern'),'runtime fully restored after partial delete failure');
assertSameValue(false,$m2->attributes['CompactMigrationComplete'],'failed migration not committed');
assertTrue(callPrivate($m2,'verifyLegacySnapshot'),'rollback snapshot survives failed cleanup');
assertSameValue(99,GetValueInteger(varID(2,'05_Intern','Auftragsnummer')),'failed cleanup restores current compact runtime');
assertSameValue(63.25,GetValueFloat(varID(2,'05_Intern','Start_Behang')),'failed cleanup keeps current compact position state');
assertSameValue(true,$m2->attributes['ReferenceValid'],'failed cleanup keeps reference');


// D) Benutzerdefinierte Variablen in den Legacy-Kategorien dürfen niemals gelöscht werden.
$m3=baseModule(3); buildLegacyTree($m3);
$configCat=IPS_GetObjectIDByIdent('01_Konfiguration',3);
$internalCat=IPS_GetObjectIDByIdent('05_Intern',3);
$customConfig=IPS_CreateVariable(3); IPS_SetParent($customConfig,$configCat); IPS_SetIdent($customConfig,'Benutzer_Notiz'); SetValueString($customConfig,'bleibt');
$customInternal=IPS_CreateVariable(1); IPS_SetParent($customInternal,$internalCat); IPS_SetIdent($customInternal,'Benutzer_Zaehler'); SetValueInteger($customInternal,123);
callPrivate($m3,'prepareCompactStorageMigration','0.1.27');
callPrivate($m3,'finalizeCompactStorageMigration');
assertSameValue(0,callPrivate($m3,'legacyVariableCount','01_Konfiguration'),'all official legacy config variables removed');
assertSameValue(0,callPrivate($m3,'legacyVariableCount','05_Intern'),'all official legacy runtime variables removed');
assertTrue(IPS_VariableExists($customConfig),'custom config variable survives migration');
assertTrue(IPS_VariableExists($customInternal),'custom internal variable survives migration');
assertSameValue('bleibt',GetValueString($customConfig),'custom config value survives migration');
assertSameValue(123,GetValueInteger($customInternal),'custom internal value survives migration');

// E) Eine bereits unvollständige V0.1.27-Struktur wird vor jeder Migration/Löschung abgelehnt.
$m4=baseModule(4); buildLegacyTree($m4);
$missingID=varID(4,'05_Intern','Zielzeit_ms');
unset($GLOBALS['variables'][$missingID],$GLOBALS['values'][$missingID],$GLOBALS['objects'][$missingID]);
$incompleteRejected=false;
try { callPrivate($m4,'prepareCompactStorageMigration','0.1.27'); } catch(Throwable $e) { $incompleteRejected=str_contains($e->getMessage(),'Legacy-Struktur ist unvollständig'); }
assertTrue($incompleteRejected,'incomplete legacy tree rejected before migration');
assertSameValue(35,callPrivate($m4,'legacyVariableCount','01_Konfiguration'),'incomplete preflight does not delete config legacy');
assertSameValue(42,callPrivate($m4,'legacyVariableCount','05_Intern'),'incomplete preflight does not delete remaining runtime legacy');


// F) Bei aktivem Motorrelais wird die destruktive Bereinigung vertagt.
$m5=baseModule(5); buildLegacyTree($m5); callPrivate($m5,'prepareCompactStorageMigration','0.1.27');
$GLOBALS['values'][9001]=true;
callPrivate($m5,'finalizeCompactStorageMigration');
assertSameValue(35,callPrivate($m5,'legacyVariableCount','01_Konfiguration'),'active relay keeps config legacy');
assertSameValue(43,callPrivate($m5,'legacyVariableCount','05_Intern'),'active relay keeps runtime legacy');
assertSameValue(false,$m5->attributes['CompactMigrationComplete'],'active relay defers migration completion');
$GLOBALS['values'][9001]=false;
callPrivate($m5,'tryFinalizeCompactStorageMigrationSafely',false);
assertSameValue(35,callPrivate($m5,'legacyVariableCount','01_Konfiguration'),'unknown validation does not delete legacy');
callPrivate($m5,'tryFinalizeCompactStorageMigrationSafely',true);
assertSameValue(0,callPrivate($m5,'legacyVariableCount','01_Konfiguration'),'safe retry removes legacy after relay off');
assertSameValue(true,$m5->attributes['CompactMigrationComplete'],'safe retry completes migration');

echo "COMPACT STORAGE MIGRATION TEST OK (78 variables removed per migrated instance, rollback verified)\n";
