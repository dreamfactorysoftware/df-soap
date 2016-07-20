<?php
namespace DreamFactory\Core\Soap\Services;

use DreamFactory\Core\Components\Cacheable;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Events\ResourcePostProcess;
use DreamFactory\Core\Events\ResourcePreProcess;
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
     * Runs pre process tasks/scripts
     */
    protected function preProcess()
    {
        if (!empty($this->resourcePath)) {
            $path = str_replace('/','.',trim($this->resourcePath, '/'));
            /** @noinspection PhpUnusedLocalVariableInspection */
            $results = \Event::fire(
                new ResourcePreProcess($this->name, $path, $this->request)
            );
        } else {
            parent::preProcess();
        }

        $this->checkPermission($this->getRequestedAction(), $this->name);
    }

    /**
     * Runs post process tasks/scripts
     */
    protected function postProcess()
    {
        if (!empty($this->resourcePath)) {
            $path = str_replace('/','.',trim($this->resourcePath, '/'));
            $event =
                new ResourcePostProcess($this->name, $path, $this->request, $this->response);
            /** @noinspection PhpUnusedLocalVariableInspection */
            $results = \Event::fire($event);
        } else {
            parent::postProcess();
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
            $structures = [];
            foreach ($types as $type) {
                if (0 === substr_compare($type, 'struct ', 0, 7)) {
                    // declared as "struct type { data_type field; ...}
                    $type = substr($type, 7);
                    $name = strstr($type, ' ', true);
                    $body = trim(strstr($type, ' '), "{} \t\n\r\0\x0B");
                    $parameters = [];
                    foreach (explode(';', $body) as $param) {
                        // declared as "type data_type"
                        $parts = explode(' ', trim($param));
                        if (count($parts) > 1) {
                            $parameters[trim($parts[1])] = trim($parts[0]);
                        }
                    }
                    $structures[$name] = $parameters;
                } else {
                    // declared as "type data_type"
                    $parts = explode(' ', $type);
                    if (count($parts) > 1) {
                        $structures[$parts[1]] = $parts[0];
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
     * @return array
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     * @throws \DreamFactory\Core\Exceptions\NotFoundException
     * @throws \DreamFactory\Core\Exceptions\RestException
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
    public function getApiDocInfo()
    {
        $name = strtolower($this->name);
        $capitalized = Inflector::camelize($this->name);
        $base = parent::getApiDocInfo();

        $apis = [];

        foreach ($this->getFunctions() as $resource) {
            if (!empty($access = $this->getPermissions($resource->name))) {
                $apis['/' . $name . '/' . $resource->name] = [
                    'post' => [
                        'tags'              => [$name],
                        'operationId'       => 'call' . $capitalized . $resource->name,
                        'summary'           => 'call' . $capitalized . $resource->name . '()',
                        'description'       => $resource->description,
                        'x-publishedEvents' => [
                            $name . '.' . $resource->name . '.call',
                            $name . '.function_called',
                        ],
                        'parameters'        => [
                            [
                                'name'        => 'body',
                                'description' => 'Data containing name-value pairs of fields to send.',
                                'schema'      => ['$ref' => '#/definitions/' . $resource->requestType],
                                'in'          => 'body',
                                'required'    => true,
                            ],
                        ],
                        'responses'         => [
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

        $models = [];
        $types = $this->getTypes();
        foreach ($types as $name => $parameters) {
            if (!isset($models[$name])) {
                if (is_array($parameters)) {
                    $properties = [];
                    foreach ($parameters as $field => $type) {
                        if (null === $newType = static::soapType2ApiDocType($type)) {
                            if (array_key_exists($type, $types)) {
                                if (null === $newType = static::soapType2ApiDocType($types[$type])) {
                                    $newType = ['$ref' => '#/definitions/'.$type];
                                } else {
                                    // check for enumerations
                                    if (!empty($enums = static::domCheckTypeForEnum($this->dom, $type))) {
                                        $newType['default'] = '';
                                        $newType['enum'] = $enums;
                                    }
                                }
                            } else {
                                \Log::alert("SOAP to Swagger found unknown type: $type");
                            }

                        }
                        $properties[$field] = $newType;
                    }
                    $temp = static::soapType2ApiDocType($name);
                    $temp['properties'] = $properties;
                    $models[$name] = $temp;
                }
            }
        }

        $base['paths'] = array_merge($base['paths'], $apis);
        $base['definitions'] = array_merge($base['definitions'], $models);

        return $base;
    }

    protected static function soapType2ApiDocType($name)
    {
        switch ($name) {
            case 'integer':
                return ['type' => 'number', 'format' => 'int32', 'description' => 'signed 32 bits'];
            case 'long':
                return ['type' => 'number', 'format' => 'int64', 'description' => 'signed 64 bits'];
            case 'float':
                return ['type' => 'number', 'format' => 'float', 'description' => 'float'];
            case 'double':
                return ['type' => 'number', 'format' => 'double', 'description' => 'double'];
            case 'decimal':
                return ['type' => 'number', 'description' => 'decimal'];
            case 'string':
                return ['type' => 'string', 'description' => 'string'];
            case 'byte':
                return ['type' => 'string', 'format' => 'byte', 'description' => 'base64 encoded characters'];
            case 'binary':
                return ['type' => 'string', 'format' => 'binary', 'description' => 'any sequence of octets'];
            case 'boolean':
                return ['type' => 'boolean', 'description' => 'true or false'];
            case 'date':
                return ['type' => 'string', 'format' => 'date', 'description' => 'As defined by full-date - RFC3339'];
            case 'dateTime':
                return [
                    'type'        => 'string',
                    'format'      => 'date-time',
                    'description' => 'As defined by date-time - RFC3339'
                ];
            case 'password':
                return [
                    'type'        => 'string',
                    'format'      => 'password',
                    'description' => 'Used to hint UIs the input needs to be obscured'
                ];
            case 'anyType': // SOAP specific, for now return string
                return ['type' => 'string', 'description' => 'any type'];
        }

        return null;
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
        } else if (is_array($object)) {
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
