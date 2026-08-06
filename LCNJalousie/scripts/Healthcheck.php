<?php
/**
 * Jalousiesteuerung LCN / IP-Symcon 9.0
 * V11.7 - Neustart-/Gesundheitspruefung
 *
 * Der ScriptTimer bleibt zyklisch aktiv. Der Controller erkennt damit einen
 * Kernelneustart auch dann, wenn die Jalousie im Stillstand war.
 */

declare(strict_types=1);

function JH_ID(int $rootID, string $categoryIdent, string $objectIdent): int
{
    $categoryID = IPS_GetObjectIDByIdent($categoryIdent, $rootID);
    if ($categoryID === false) {
        throw new RuntimeException('Kategorie fehlt: ' . $categoryIdent);
    }
    $objectID = IPS_GetObjectIDByIdent($objectIdent, (int) $categoryID);
    if ($objectID === false) {
        throw new RuntimeException('Objekt fehlt: ' . $categoryIdent . '/' . $objectIdent);
    }
    return (int) $objectID;
}

$self = (int) ($_IPS['SELF'] ?? 0);
if ($self <= 0 || !IPS_ScriptExists($self)) {
    throw new RuntimeException('SELF ist keine gueltige Skript-ID.');
}
$rootID = IPS_GetParent(IPS_GetParent($self));

// Nach einem Kernelneustart können LCN/PCHK-Instanzen wenige Sekunden später
// aktiv werden als die Jalousieinstanz. Die erneute Prüfung verändert keine
// gespeicherten Eigenschaften und gibt die Bedienung erst nach erfolgreicher
// Laufzeitprüfung frei.
if (IPS_FunctionExists('LCNJAL_CompleteStartupValidation')) {
    LCNJAL_CompleteStartupValidation($rootID);
}
// Der Healthcheck läuft auch bei einer Fehlerverriegelung weiter. Der
// Controller lässt in diesem Zustand keine Visualisierungsaufträge zu, darf
// aber reale Relaisfahrten verfolgen und einen bereits sicher fälligen
// externen Endlagen-STOP absichern. Bei bewusst deaktiviertem Modul wird kein
// TS-Toggle erzeugt.
$controllerID = JH_ID($rootID, '06_Skripte', 'Controller');
IPS_RunScriptWaitEx($controllerID, ['ACTION' => 'HEALTHCHECK']);
