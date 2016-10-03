<?php
namespace DreamFactory\Core\Soap\Services;

use DreamFactory\Core\Components\Cacheable;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Services\BaseRestService;
use DreamFactory\Core\Soap\Components\WsseAuthHeader;
use DreamFactory\Core\Soap\FunctionSchema;
use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Library\Utility\Inflector;
use DreamFactory\Library\Utility\Scalar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class Soap
 *
 * @package DreamFactory\Core\Soap\Services
 */
class Soap extends BaseRestService
{
    use Cacheable;

    //*************************************************************************
    //* Members
    //*************************************************************************

    /**
     * @var string
     */
    protected $wsdl;
    /**
     * @var \SoapClient
     */
    protected $client;
    /**
     * @var \DOMDocument
     */
    protected $dom;
    /**
     * @type bool
     */
    protected $cacheEnabled = false;
    /**
     * @type array
     */
    protected $functions = [];
    /**
     * @type array
     */
    protected $types = [];

    //*************************************************************************
    //* Methods
    //*************************************************************************

    /**
     * Create a new SoapService
     *
     * @param array $settings settings array
     *
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    public function __construct($settings)
    {
        parent::__construct($settings);
        $config = array_get($settings, 'config', []);
        $this->wsdl = array_get($config, 'wsdl');

        // Validate url setup
        if (empty($this->wsdl)) {
            // check for location and uri in options
            if (!isset($config['options']['location']) || !isset($config['options']['uri'])) {
                throw new \InvalidArgumentException('SOAP Services require either a WSDL or both location and URI to be configured.');
            }
        }
        $options = array_get($config, 'options', []);
        if (!is_array($options)) {
            $options = [];
        } else {
            foreach ($options as $key => $value) {
                if (!is_numeric($value)) {
                    if (defined($value)) {
                        $options[$key] = constant($value);
                    }
                }
            }
        }

        $this->cacheEnabled = Scalar::boolval(array_get($config, 'cache_enabled'));
        $this->cacheTTL = intval(array_get($config, 'cache_ttl', \Config::get('df.default_cache_ttl')));
        $this->cachePrefix = 'service_' . $this->id . ':';

        try {
            $this->client = @new \SoapClient($this->wsdl, $options);
            $this->dom = new \DOMDocument();
            if (!empty($this->wsdl)) {
                $this->dom->load($this->wsdl);
                $this->dom->preserveWhiteSpace = false;
            }

            $headers = array_get($config, 'headers');
            $soapHeaders = null;

            if (!empty($headers)) {
                foreach ($headers as $header) {
                    $headerType = array_get($header, 'type', 'generic');
                    switch ($headerType) {
                        case 'wsse':
                            $data = json_decode(stripslashes(array_get($header, 'data', '{}')), true);
                            $data = (is_null($data) || !is_array($data)) ? [] : $data;
                            $username = array_get($data, 'username');
                            $password = array_get($data, 'password');

                            if (!empty($username) && !empty($password)) {
                                $soapHeaders[] = new WsseAuthHeader($username, $password);
                            }

                            break;
                        default:
                            $data = json_decode(stripslashes(array_get($header, 'data', '{}')), true);
                            $data = (is_null($data) || !is_array($data)) ? [] : $data;
                            $namespace = array_get($header, 'namespace');
                            $name = array_get($header, 'name');
                            $mustUnderstand = array_get($header, 'mustunderstand', false);
                            $actor = array_get($header, 'actor');

                            if (!empty($namespace) && !empty($name) && !empty($data)) {
                                $soapHeaders[] = new \SoapHeader($namespace, $name, $data, $mustUnderstand, $actor);
                            }
                    }
                }
                if (!empty($soapHeaders)) {
                    $this->client->__setSoapHeaders($soapHeaders);
                }
            }
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Unexpected SOAP Service Exception:\n{$ex->getMessage()}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getResources($only_handlers = false)
    {
        if ($only_handlers) {
            return [];
        }

        $refresh = $this->request->getParameterAsBool(ApiOptions::REFRESH);
        $result = $this->getFunctions($refresh);
        $resources = [];
        foreach ($result as $function) {
            $access = $this->getPermissions($function->name);
            if (!empty($access)) {
                $out = $function->toArray();
                $out['access'] = VerbsMask::maskToArray($access);
                $resources[] = $out;
            }
        }

        return $resources;
    }

    /**
     * @param bool $refresh
     *
     * @return FunctionSchema[]
     */
    public function getFunctions($refresh = false)
    {
        if ($refresh ||
            (empty($this->functions) &&
                (null === $this->functions = $this->getFromCache('functions')))
        ) {
            $functions = $this->client->__getFunctions();
            $structures = $this->getTypes($refresh);
            $names = [];
            foreach ($functions as $function) {
                $schema = new FunctionSchema($function);
                $schema->requestFields =
                    isset($structures[$schema->requestType]) ? $structures[$schema->requestType] : null;
                $schema->responseFields =
                    isset($structures[$schema->responseType]) ? $structures[$schema->responseType] : null;
                $names[strtolower($schema->name)] = $schema;
            }
            ksort($names);
            $this->functions = $names;
            $this->addToCache('functions', $this->functions, true);
        }

        return $this->functions;
    }

    /**
     * @param bool $refresh
     *
     * @return FunctionSchema[]
     */
    public function getTypes($refresh = false)
    {
        if ($refresh ||
            (empty($this->types) &&
                (null === $this->types = $this->getFromCache('types')))
        ) {
            $types = $this->client->__getTypes();
            // first pass, build name-value pairs for easier lookups
            $structures = [];
            foreach ($types as $type) {
                if (0 === substr_compare($type, 'struct ', 0, 7)) {
                    // declared as "struct type { data_type field; ...}
                    $type = substr($type, 7);
                    $name = strstr($type, ' ', true);
                    $type = trim(strstr($type, ' '), "{} \t\n\r\0\x0B");
                    if (false !== stripos($type, ' complexObjectArray;')) {
                        // declared as "type complexObjectArray"
                        $type = strstr(trim($type), ' complexObjectArray;', true);
                        $structures[$name] = [$type];
                    } else {
                        $parameters = [];
                        foreach (explode(';', $type) as $param) {
                            // declared as "type data_type"
                            $parts = explode(' ', trim($param));
                            if (count($parts) > 1) {
                                $parameters[trim($parts[1])] = trim($parts[0]);
                            }
                        }
                        $structures[$name] = $parameters;
                    }
                } else {
                    // declared as "type data_type"
                    $parts = explode(' ', $type);
                    if (count($parts) > 1) {
                        $structures[$parts[1]] = $parts[0];
                    }
                }
            }
            foreach ($structures as $name => &$type) {
                if (is_array($type)) {
                    if ((1 === count($type)) && isset($type[0])) {
                        $type = $type[0];
                        // array of type
                        if (array_key_exists($type, $structures)) {
                            $type = ['type' => 'array', 'items' => ['$ref' => '#/definitions/' . $type]];
                        } else {
                            // convert simple types to swagger types
                            $newType = static::soapType2ApiDocType($type);
                            $type = ['type' => 'array', 'items' => $newType];
                        }
                    } else {
                        // array of field definitions
                        foreach ($type as $fieldName => &$fieldType) {
                            if (array_key_exists($fieldType, $structures)) {
                                $fieldType = ['$ref' => '#/definitions/' . $fieldType];
                            } else {
                                // convert simple types to swagger types
                                $newType = static::soapType2ApiDocType($fieldType);
                                $fieldType = $newType;
                            }
                        }
                        $type = ['type' => 'object', 'properties' => $type];
                    }
                } else {
                    if (array_key_exists($type, $structures)) {
                        $type = ['$ref' => '#/definitions/' . $type];
                    } else {
                        // convert simple types to swagger types
                        $newType = static::soapType2ApiDocType($type);
                        $type = $newType;
                    }
                }
            }

            ksort($structures);
            $this->types = $structures;
            $this->addToCache('types', $this->types, true);
        }

        return $this->types;
    }

    /**
     *
     */
    public function refreshTableCache()
    {
        $this->removeFromCache('functions');
        $this->functions = [];
        $this->removeFromCache('types');
        $this->types = [];
    }

    /**
     * @param string $name       The name of the function to check
     * @param bool   $returnName If true, the function name is returned instead of TRUE
     *
     * @throws \InvalidArgumentException
     * @return bool|string
     */
    public function doesFunctionExist($name, $returnName = false)
    {
        if (empty($name)) {
            throw new \InvalidArgumentException('Function name cannot be empty.');
        }

        //  Build the lower-cased table array
        $functions = $this->getFunctions(false);

        //	Search normal, return real name
        $ndx = strtolower($name);
        if (isset($functions[$ndx])) {
            return $returnName ? $functions[$ndx]->name : true;
        }

        return false;
    }

    protected function getEventName()
    {
        if (!empty($this->resourcePath)) {
            return parent::getEventName() . '.' . str_replace('/', '.', trim($this->resourcePath, '/'));
        }

        return parent::getEventName();
    }

    /**
     * Runs pre process tasks/scripts
     */
    protected function preProcess()
    {
        $this->checkPermission($this->getRequestedAction(), $this->name);

        parent::preProcess();
    }

    /**
     * @param $function
     * @param $payload
     *
     * @return mixed
     * @throws \DreamFactory\Core\Exceptions\NotFoundException
     * @throws InternalServerErrorException
     */
    protected function callFunction($function, $payload)
    {
        if (false === ($function = $this->doesFunctionExist($function, true))) {
            throw new NotFoundException("Function '$function' does not exist on this service.");
        }

        try {
            $result = $this->client->$function($payload);
            $result = static::object2Array($result);

            return $result;
        } catch (\SoapFault $e) {
            /** @noinspection PhpUndefinedFieldInspection */
            $faultCode = (property_exists($e, 'faultcode') ? $e->faultcode : $e->getCode());

            $errorCode = Response::HTTP_INTERNAL_SERVER_ERROR;
            // Fault code can be a string.
            if (is_numeric($faultCode) && strpos($faultCode, '.') === false) {
                $errorCode = $faultCode;
            }
            throw new InternalServerErrorException($e->getMessage() . ' [Fault code:' . $faultCode . ']', $errorCode);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function handleGet()
    {
        if (empty($this->resource)) {
            return parent::handleGET();
        }

        $result = $this->callFunction($this->resource, $this->request->getParameters());

        $asList = $this->request->getParameterAsBool(ApiOptions::AS_LIST);
        $idField = $this->request->getParameter(ApiOptions::ID_FIELD, static::getResourceIdentifier());
        $result = ResourcesWrapper::cleanResources($result, $asList, $idField, ApiOptions::FIELDS_ALL, !empty($meta));

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function handlePost()
    {
        if (empty($this->resource)) {
            // not currently supported, maybe batch opportunity?
            return false;
        }

        $result = $this->callFunction($this->resource, $this->request->getPayloadData());

        $asList = $this->request->getParameterAsBool(ApiOptions::AS_LIST);
        $idField = $this->request->getParameter(ApiOptions::ID_FIELD, static::getResourceIdentifier());
        $result = ResourcesWrapper::cleanResources($result, $asList, $idField, ApiOptions::FIELDS_ALL, !empty($meta));

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function buildApiDocInfo()
    {
        $name = strtolower($this->name);
        $capitalized = Inflector::camelize($this->name);
        $base = parent::getApiDocInfo($this);

        $apis = [];

        foreach ($this->getFunctions() as $resource) {
            if (!empty($access = $this->getPermissions($resource->name))) {
                $apis['/' . $name . '/' . $resource->name] = [
                    'post' => [
                        'tags'        => [$name],
                        'operationId' => 'call' . $capitalized . $resource->name,
                        'summary'     => 'call' . $capitalized . $resource->name . '()',
                        'description' => $resource->description,
                        'parameters'  => [
                            [
                                'name'        => 'body',
                                'description' => 'Data containing name-value pairs of fields to send.',
                                'schema'      => ['$ref' => '#/definitions/' . $resource->requestType],
                                'in'          => 'body',
                                'required'    => true,
                            ],
                        ],
                        'responses'   => [
                            '200'     => [
                                'description' => 'Success',
                                'schema'      => ['$ref' => '#/definitions/' . $resource->responseType]
                            ],
                            'default' => [
                                'description' => 'Error',
                                'schema'      => ['$ref' => '#/definitions/Error']
                            ]
                        ],
                    ],
                ];
            }
        }

        $models = $this->getTypes();

        $base['paths'] = array_merge($base['paths'], $apis);
        $base['definitions'] = array_merge($base['definitions'], $models);

        return $base;
    }

    protected static function soapType2ApiDocType($name)
    {
        switch ($name) {
            case 'byte':
                return ['type' => 'number', 'format' => 'int8', 'description' => 'signed 8-bit integer'];
            case 'unsignedByte':
                return ['type' => 'number', 'format' => 'int8', 'description' => 'unsigned 8-bit integer'];
            case 'short':
                return ['type' => 'number', 'format' => 'int16', 'description' => 'signed 16-bit integer'];
            case 'unsignedShort':
                return ['type' => 'number', 'format' => 'int8', 'description' => 'unsigned 16-bit integer'];
            case 'int':
            case 'integer':
            case 'negativeInteger':    // An integer containing only negative values (..,-2,-1)
            case 'nonNegativeInteger': // An integer containing only non-negative values (0,1,2,..)
            case 'nonPositiveInteger':    // An integer containing only non-positive values (..,-2,-1,0)
            case 'positiveInteger': // An integer containing only positive values (1,2,..)
                return ['type' => 'number', 'format' => 'int32', 'description' => 'signed 32-bit integer'];
            case 'unsignedInt':
                return ['type' => 'number', 'format' => 'int32', 'description' => 'unsigned 32-bit integer'];
            case 'long':
                return ['type' => 'number', 'format' => 'int64', 'description' => 'signed 64-bit integer'];
            case 'unsignedLong':
                return ['type' => 'number', 'format' => 'int8', 'description' => 'unsigned 64-bit integer'];
            case 'float':
                return ['type' => 'number', 'format' => 'float', 'description' => 'float'];
            case 'double':
                return ['type' => 'number', 'format' => 'double', 'description' => 'double'];
            case 'decimal':
                return ['type' => 'number', 'description' => 'decimal'];
            case 'string':
                return ['type' => 'string', 'description' => 'string'];
            case 'anyURI':
                return ['type' => 'string', 'format' => 'uri', 'description' => 'any valid URI'];
            case 'base64Binary':
                return ['type' => 'string', 'format' => 'byte', 'description' => 'Base64-encoded characters'];
            case 'hexBinary':
                return ['type' => 'string', 'format' => 'binary', 'description' => 'hexadecimal-encoded characters'];
            case 'binary':
                return ['type' => 'string', 'format' => 'binary', 'description' => 'any sequence of octets'];
            case 'boolean':
                return ['type' => 'boolean', 'description' => 'true or false'];
            case 'date':
                return ['type' => 'string', 'format' => 'date', 'description' => 'As defined by full-date - RFC3339'];
            case 'time':
                return ['type' => 'string', 'description' => 'As defined by time - RFC3339'];
            case 'dateTime':
                return [
                    'type'        => 'string',
                    'format'      => 'date-time',
                    'description' => 'As defined by date-time - RFC3339'
                ];
            case 'duration':
                return [
                    'type'        => 'string',
                    'description' => 'Duration or time interval as specified in the following form "PnYnMnDTnHnMnS".'
                ];
            case 'password':
                return [
                    'type'        => 'string',
                    'format'      => 'password',
                    'description' => 'Used to hint UIs the input needs to be obscured'
                ];
            case 'anyType': // SOAP specific, for now return string
                return ['type' => 'string', 'description' => 'any type'];
            default: // undetermined type, return string for now
                \Log::alert('SOAP to Swagger type unknown: ' . print_r($name, true));
                if (!is_string($name)) {
                    $name = 'object or array';
                }

                return ['type' => 'string', 'description' => 'undetermined type: ' . $name];
        }
    }

    /**
     * @param $object
     *
     * @return array
     */
    protected static function object2Array($object)
    {
        if (is_object($object)) {
            return array_map([static::class, __FUNCTION__], get_object_vars($object));
        } elseif (is_array($object)) {
            return array_map([static::class, __FUNCTION__], $object);
        } else {
            return $object;
        }
    }

    protected static function domCheckTypeForEnum($dom, $type)
    {
        $values = [];
        $node = static::domFindType($dom, $type);
        if (!$node) {
            return $values;
        }
        $value_list = $node->getElementsByTagName('enumeration');
        if ($value_list->length == 0) {
            return $values;
        }
        for ($i = 0; $i < $value_list->length; $i++) {
            $values[] = $value_list->item($i)->attributes->getNamedItem('value')->nodeValue;
        }

        return $values;
    }

    /**
     * Look for a type
     *
     * @param \DOMDocument $dom
     * @param string       $class
     *
     * @return \DOMNode
     */
    protected static function domFindType($dom, $class)
    {
        $types_node = $dom->getElementsByTagName('types')->item(0);
        $schema_list = $types_node->getElementsByTagName('schema');
        for ($i = 0; $i < $schema_list->length; $i++) {
            $children = $schema_list->item($i)->getElementsByTagName('simpleType');
            for ($j = 0; $j < $children->length; $j++) {
                $node = $children->item($j);
                if ($node->hasAttributes() &&
                    $node->attributes->getNamedItem('name') &&
                    $node->attributes->getNamedItem('name')->nodeValue == $class
                ) {
                    return $node;
                }
            }
        }

        return null;
    }
}
