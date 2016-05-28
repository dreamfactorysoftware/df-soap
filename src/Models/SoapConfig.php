<?php
namespace DreamFactory\Core\Soap\Models;

use DreamFactory\Core\Models\BaseServiceConfigModel;
use DreamFactory\Core\Models\ServiceCacheConfig;

class SoapConfig extends BaseServiceConfigModel
{
    protected $table = 'soap_config';

    protected $fillable = ['service_id', 'wsdl', 'options', 'headers'];

    protected $casts = ['options' => 'array', 'headers' => 'array'];

    /**
     * @param int $id
     *
     * @return array
     */
    public static function getConfig($id)
    {
        $config = parent::getConfig($id);

        $cacheConfig = ServiceCacheConfig::whereServiceId($id)->first();
        $config['cache_enabled'] = (empty($cacheConfig)) ? false : $cacheConfig->getAttribute('cache_enabled');
        $config['cache_ttl'] = (empty($cacheConfig)) ? 0 : $cacheConfig->getAttribute('cache_ttl');

        return $config;
    }

    /**
     * {@inheritdoc}
     */
    public static function validateConfig($config, $create = true)
    {

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public static function setConfig($id, $config)
    {
        $cache = [];
        if (isset($config['cache_enabled'])) {
            $cache['cache_enabled'] = $config['cache_enabled'];
            unset($config['cache_enabled']);
        }
        if (isset($config['cache_ttl'])) {
            $cache['cache_ttl'] = $config['cache_ttl'];
            unset($config['cache_ttl']);
        }
        if (!empty($cache)) {
            ServiceCacheConfig::setConfig($id, $cache);
        }

        parent::setConfig($id, $config);
    }

    /**
     * {@inheritdoc}
     */
    public static function getConfigSchema()
    {
        $schema = parent::getConfigSchema();
        $schema = array_merge($schema, ServiceCacheConfig::getConfigSchema());

        return $schema;
    }

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
                    ' For further options, see http://php.net/manual/en/soapclient.soapclient.php.';
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