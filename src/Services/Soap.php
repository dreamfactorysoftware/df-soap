<?php
namespace DreamFactory\Core\Soap\Services;

use DreamFactory\Core\Components\Cacheable;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Services\BaseRestService;
use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Library\Utility\ArrayUtils;

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
     * @type bool
     */
    protected $cacheEnabled = false;
    /**
     * @type array
     */
    protected $functions = [];

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
        $config = ArrayUtils::get($settings, 'config', []);
        $this->wsdl = ArrayUtils::get($config, 'wsdl');

        // Validate url setup
        if (empty($this->wsdl)) {
            // check for location and uri in options
            if (!isset($config['options']['location']) || !isset($config['options']['uri'])) {
                throw new \InvalidArgumentException('SOAP Services require either a WSDL or both location and URI to be configured.');
            }
        }
        $options = ArrayUtils::get($config, 'options', []);
        if (empty($options)) {
            $options = [];
        }

        $this->cacheEnabled = ArrayUtils::getBool($config, 'cache_enabled');
        $this->cacheTTL = intval(ArrayUtils::get($config, 'cache_ttl', \Config::get('df.default_cache_ttl')));
        $this->cachePrefix = 'service_' . $this->id . ':';

        try {
            $this->client = @new \SoapClient($this->wsdl, $options);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Unexpected SOAP Service Exception:\n{$ex->getMessage()}");
        }
    }

    /**
     * A chance to pre-process the data.
     *
     * @return mixed|void
     */
    protected function preProcess()
    {
        parent::preProcess();

        $this->checkPermission($this->getRequestedAction(), $this->name);
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
            $name = $function['name'];
            $access = $this->getPermissions($name);
            if (!empty($access)) {
                $function['access'] = VerbsMask::maskToArray($access);
                $resources[] = $function;
            }
        }

        return $resources;
    }

    public function getFunctions($refresh = false)
    {
        if ($refresh ||
            (empty($this->functions) &&
                (null === $this->functions = $this->getFromCache('functions')))
        ) {
            $names = [];
            $result = $this->client->__getFunctions();
            foreach ($result as $function) {
                $name = strstr(substr($function, strpos($function, ' ') + 1), '(', true);
                $names[strtolower($name)] = ['name' => $name, 'function' => $function];
            }
            natcasesort($names);
            $this->functions = $names;
            $this->addToCache('functions', $this->functions, true);
        }

        return $this->functions;
    }

    public function refreshTableCache()
    {
        $this->removeFromCache('functions');
        $this->functions = [];
    }

    /**
     * @param string $name       The name of the function to check
     * @param bool   $returnName If true, the function name is returned instead of TRUE
     *
     * @throws \InvalidArgumentException
     * @return bool
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
            return $returnName ? $functions[$ndx]['name'] : true;
        }

        return false;
    }

    protected function callFunction($function, $payload)
    {
        $result = $this->client->$function($payload);

        $result = static::object2Array($result);
        return current($result);
    }

    /**
     * {@inheritdoc}
     */
    protected function handleGet()
    {
        if (empty($this->resource)) {
            return parent::handleGET();
        }

        if (false === ($function = $this->doesFunctionExist($this->resource, true))) {
            throw new NotFoundException('Function "' . $this->resource . '" does not exist on this service.');
        }

        $result = $this->callFunction($function, $this->request->getParameters());

        $asList = $this->request->getParameterAsBool(ApiOptions::AS_LIST);
        $idField = $this->request->getParameter(ApiOptions::ID_FIELD, $this->getResourceIdentifier());
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

        if (false === ($function = $this->doesFunctionExist($this->resource, true))) {
            throw new NotFoundException('Function "' . $this->resource . '" does not exist on this service.');
        }

        $payload = $this->request->getPayloadData();
        if (empty($payload)) {
            throw new BadRequestException('No record(s) detected in request.');
        }

        $result = $this->callFunction($function, $payload);

        $asList = $this->request->getParameterAsBool(ApiOptions::AS_LIST);
        $idField = $this->request->getParameter(ApiOptions::ID_FIELD, $this->getResourceIdentifier());
        $result = ResourcesWrapper::cleanResources($result, $asList, $idField, ApiOptions::FIELDS_ALL, !empty($meta));

        return $result;
    }

    public function getApiDocModels()
    {
        return [];
    }

    public function getApiDocInfo()
    {
        return [
            'resourcePath' => '/' . $this->name,
            'produces'     => ['application/json', 'application/xml'],
            'consumes'     => ['application/json', 'application/xml'],
            'apis'         => [],
            'models'       => [],
        ];
    }

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
}