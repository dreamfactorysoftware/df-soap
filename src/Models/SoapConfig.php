<?php

namespace DreamFactory\Core\Soap\Models;

use DreamFactory\Core\Components\SupportsCache;
use DreamFactory\Core\Models\BaseServiceConfigModel;

class SoapConfig extends BaseServiceConfigModel
{
    use SupportsCache;

    protected $table = 'soap_config';

    protected $fillable = ['service_id', 'wsdl', 'options', 'headers'];

    protected $casts = ['options' => 'array', 'headers' => 'array'];

    /**
     * @param array $schema
     */
    protected static function prepareConfigSchemaField(array &$schema)
    {
        parent::prepareConfigSchemaField($schema);

        switch ($schema['name']) {
            case 'wsdl':
                $schema['label'] = 'WSDL URI';
                $schema['default'] = null;
                $schema['description'] =
                    'This is the location of the WSDL file describing the SOAP connection, or null if not available.';
                break;
            case 'options':
                $schema['type'] = 'object';
                $schema['object'] =
                    [
                        'key'   => ['label' => 'Name', 'type' => 'string'],
                        'value' => ['label' => 'Value', 'type' => 'string']
                    ];
                $schema['description'] =
                    'An array of options for the connection.' .
                    ' For further options, see http://php.net/manual/en/soapclient.soapclient.php.' .
                    ' For stream_context options, provide a JSON object string with the various options for the value.';
                break;
            case 'headers':
                $schema['type'] = 'array';
                $schema['items'] = [
                    [
                        'label'  => 'Type',
                        'name'   => 'type',
                        'type'   => 'picklist',
                        'values' => [
                            [
                                'label' => 'Generic',
                                'name'  => 'generic'
                            ],
                            [
                                'label' => 'WSSE',
                                'name'  => 'wsse'
                            ]
                        ]
                    ],
                    [
                        'label' => 'Namespace',
                        'name'  => 'namespace',
                        'type'  => 'string'
                    ],
                    [
                        'label' => 'Name',
                        'name'  => 'name',
                        'type'  => 'string'
                    ],
                    [
                        'label' => 'Data',
                        'name'  => 'data',
                        'type'  => 'string'
                    ],
                    [
                        'label' => 'MustUnderstand',
                        'name'  => 'mustunderstand',
                        'type'  => 'boolean'
                    ],
                    [
                        'label' => 'Actor',
                        'name'  => 'actor',
                        'type'  => 'string'
                    ]
                ];
                $schema['description'] =
                    'An array of headers for the connection. ' .
                    'For further info, see http://php.net/manual/en/class.soapheader.php.';
                break;
        }
    }
}