<?php

declare(strict_types=1);

class LCNJalousieKonfigurator extends IPSModuleStrict
{
    private const DEVICE_MODULE_ID = '{3057B192-E835-4916-AF1D-D89D6302DF74}';

    public function Create(): void
    {
        parent::Create();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    public function GetConfigurationForm(): string
    {
        $values = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_ID) as $instanceID) {
            // Symcon requires create.configuration to be a JSON object, never a JSON array.
            // Decode without associative=true so even an empty configuration remains {}.
            $configuration = json_decode(IPS_GetConfiguration($instanceID));
            if (!is_object($configuration)) {
                $configuration = new stdClass();
            }
            $values[] = [
                'name' => IPS_GetName($instanceID),
                'status' => (string) IPS_GetInstance($instanceID)['InstanceStatus'],
                'instanceID' => $instanceID,
                'create' => [
                    'moduleID' => self::DEVICE_MODULE_ID,
                    'configuration' => $configuration,
                ],
            ];
        }

        $values[] = [
            'name' => 'Neue LCN-Jalousie',
            'status' => 'noch nicht angelegt',
            'instanceID' => 0,
            'create' => [
                'moduleID' => self::DEVICE_MODULE_ID,
                'name' => 'LCN Jalousie',
                'location' => ['Jalousiesteuerung'],
                'configuration' => new stdClass(),
            ],
        ];

        $form = [
            'elements' => [
                ['type' => 'Label', 'caption' => 'Hier können Sie neue Jalousieinstanzen anlegen. Die LCN-Zuordnungen werden anschließend in jeder Instanz ausgefüllt.'],
                [
                    'type' => 'Configurator',
                    'name' => 'Jalousien',
                    'rowCount' => 15,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'name', 'width' => 'auto'],
                        ['caption' => 'Instanzstatus', 'name' => 'status', 'width' => '160px'],
                    ],
                    'values' => $values,
                ],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Konfigurator bereit'],
            ],
        ];

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
