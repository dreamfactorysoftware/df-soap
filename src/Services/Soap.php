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
use Illuminate\Support\Facades\Request;
use Log;
use Symfony\Component\HttpFoundation\Response;
use DreamFactory\Core\Soap\Components\SoapClient;
use Arr;
use Str;

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
     * @var SoapClient
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
        $config = Arr::get($settings, 'config', []);
        $this->wsdl = Arr::get($config, 'wsdl');

        // Validate url setup
        if (empty($this->wsdl)) {
            // check for location and uri in options
            if (!isset($config['options']['location']) || !isset($config['options']['uri'])) {
                throw new \InvalidArgumentException('SOAP Services require either a WSDL or both location and URI to be configured.');
            }
        } else {
            if ((!str_contains($this->wsdl, '/')) && (!str_contains($this->wsdl, '\\'))) {
                // no directories involved, store it where we want to store it
                if (!empty($storage = storage_path('wsdl'))) {
                    $this->wsdl = rtrim($storage, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->wsdl;
                }
            } elseif (false !== $path = realpath($this->wsdl)) {
                $this->wsdl = $path;
            }
        }
        $options = Arr::get($config, 'options', []);
        if (!is_array($options)) {
            $options = [];
        } else {
            foreach ($options as $key => $value) {
                if (!is_numeric($value)) {
                    if (is_string($value) && defined($value)) {
                        $options[$key] = constant($value);
                    }
                    if (0 === strcasecmp($key, 'stream_context')) {
                        // Need to make a stream context out of an array of options
                        if (is_string($value)) {
                            // try to convert json to array
                            $value = json_decode(stripslashes($value), true);
                        }
                        if (!is_array($value)) {
                            throw new \InvalidArgumentException('SOAP Services stream_context must be a valid array (or JSON object) of parameters.');
                        }
                        $context = stream_context_create($value);
                        $options[$key] = $context;
                    }
                }
            }
        }

        $this->cacheEnabled = array_get_bool($config, 'cache_enabled');
        $this->cacheTTL = intval(Arr::get($config, 'cache_ttl', \Config::get('df.default_cache_ttl')));

        try {
            $this->client = new SoapClient($this->wsdl, $options);
            $this->setupSoapHeaders($config);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Unexpected SOAP Service Exception:\n{$ex->getMessage()}");
        }
    }
    
    /**
     * Setup SOAP headers from configuration
     * 
     * @param array $config The service configuration
     */
    protected function setupSoapHeaders($config)
    {
        $queries = Request::query();
        $headers = Arr::get($config, 'headers');
        $wsseUsernameToken = Arr::get($config, 'wsse_username_token');
        $soapHeaders = null;

        if (!empty($headers)) {
            foreach ($headers as $header) {
                $headerType = Arr::get($header, 'type', 'generic');
                switch ($headerType) {
                    case 'wsse':
                        $this->processWsseHeader($header, $wsseUsernameToken, $soapHeaders);
                        break;
                    default:
                        $this->processGenericHeader($header, $queries, $soapHeaders);
                }
            }
            if (!empty($soapHeaders)) {
                $this->client->__setSoapHeaders($soapHeaders);
            }
        }
    }
    
    /**
     * Process WSSE authentication header
     * 
     * @param array $header The header configuration
     * @param string $wsseUsernameToken The WSSE username token
     * @param array $soapHeaders The SOAP headers array
     */
    protected function processWsseHeader($header, $wsseUsernameToken, &$soapHeaders)
    {
        $data = (is_null($header) || !is_array($header)) ? [] : $header;
        $username = null;
        $password = null;

        if (Arr::get($data, 'name') == 'username') {
            $username = Arr::get($data, 'data');
        } elseif (Arr::get($data, 'name') == 'password') {
            $password = Arr::get($data, 'data');
        }

        if (!empty($username) && !empty($password)) {
            $soapHeaders[] = new WsseAuthHeader($username, $password, $wsseUsernameToken);
        }
    }
    
    /**
     * Process generic SOAP header
     * 
     * @param array $header The header configuration
     * @param array $queries The request queries
     * @param array $soapHeaders The SOAP headers array
     */
    protected function processGenericHeader($header, $queries, &$soapHeaders)
    {
        $data = Arr::get($header, 'data', '{}');
        if (Str::contains($data, 'df:')) {
            $param = Str::after($data, 'df:');
            $data = $queries[$param] ?? '';
        } else {
            $data = json_decode(stripslashes($data), true);
            $data = (is_null($data) || !is_array($data)) ? [] : $data;
        }
        
        $namespace = Arr::get($header, 'namespace');
        $name = Arr::get($header, 'name');
        $mustUnderstand = Arr::get($header, 'mustunderstand', false);
        $actor = Arr::get($header, 'actor');

        if (!empty($namespace) && !empty($name) && !empty($data)) {
            $soapHeaders[] = new \SoapHeader($namespace, $name, $data, $mustUnderstand, $actor);
        }
    }

    public function getResources()
    {
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
                $schema->requestFields = $structures[$schema->requestType] ?? null;
                $schema->responseFields = $structures[$schema->responseType] ?? null;
                $names[strtolower($schema->name)] = $schema;
            }
            ksort($names);
            $this->functions = $names;
            $this->addToCache('functions', $this->functions, true);
        }

        return $this->functions;
    }

    /**
     * Parse WSDL to get minOccurs information for fields
     * 
     * @return array Array of required fields by type name
     */
    protected function parseWsdlForRequiredFields()
    {
        $requiredFields = [];
        
        if (empty($this->wsdl)) {
            return $requiredFields;
        }
        
        try {
            $xmlContent = $this->loadWsdlContent($this->wsdl);
            $dom = $this->createDomDocument($xmlContent);
            
            // Find all complexType elements
            $complexTypes = $dom->getElementsByTagName('complexType');
            
            foreach ($complexTypes as $complexType) {
                $typeName = $complexType->getAttribute('name');
                if (empty($typeName)) continue;
                
                $required = $this->extractRequiredFieldsFromComplexType($complexType);
                
                if (!empty($required)) {
                    $requiredFields[$typeName] = $required;
                }
            }
            
            // Also check for imported WSDL files
            $wsdlImports = $dom->getElementsByTagName('wsdl:import');
            
            foreach ($wsdlImports as $wsdlImport) {
                $location = $wsdlImport->getAttribute('location');
                if (!empty($location)) {
                    $importedRequired = $this->parseImportedWsdl($location);
                    $requiredFields = array_merge($requiredFields, $importedRequired);
                }
            }
            
            // Also check for import elements that might be WSDL imports
            $allImports = $dom->getElementsByTagName('import');
            
            foreach ($allImports as $import) {
                $location = $import->getAttribute('location');
                $namespace = $import->getAttribute('namespace');
                $schemaLocation = $import->getAttribute('schemaLocation');
                
                // If it has a location attribute and ends with wsdl=wsdl0, it's a WSDL import
                if (!empty($location) && strpos($location, 'wsdl=wsdl0') !== false) {
                    $importedRequired = $this->parseImportedWsdl($location);
                    $requiredFields = array_merge($requiredFields, $importedRequired);
                }
                // If it has a schemaLocation, it's an XSD import
                elseif (!empty($schemaLocation)) {
                    $importedRequired = $this->parseImportedSchema($schemaLocation);
                    $requiredFields = array_merge($requiredFields, $importedRequired);
                }
            }
            
        } catch (\Exception $e) {
            // Log error but don't fail
            \Log::warning('Failed to parse WSDL for required fields: ' . $e->getMessage());
        }
        
        return $requiredFields;
    }
    
    /**
     * Extract required fields from a complexType element
     * 
     * @param \DOMElement $complexType The complexType element
     * @return array Array of required field names
     */
    protected function extractRequiredFieldsFromComplexType($complexType)
    {
        $required = [];
        
        // Look for sequence elements
        $sequences = $complexType->getElementsByTagName('sequence');
        foreach ($sequences as $sequence) {
            $elements = $sequence->getElementsByTagName('element');
            foreach ($elements as $element) {
                $elementName = $element->getAttribute('name');
                $minOccurs = $element->getAttribute('minOccurs');
                
                if (!empty($elementName)) {
                    // If minOccurs is not specified or is "1", field is required
                    // getAttribute() returns empty string when attribute doesn't exist
                    $isRequired = false;
                    
                    if (strlen($minOccurs) === 0) {
                        // minOccurs not set, default is 1 (required)
                        $isRequired = true;
                    } elseif ($minOccurs === '1') {
                        // minOccurs explicitly set to 1 (required)
                        $isRequired = true;
                    } elseif ($minOccurs === '0') {
                        // minOccurs explicitly set to 0 (optional)
                        $isRequired = false;
                    } else {
                        // Any other value, check if it's numeric and greater than 0
                        if (is_numeric($minOccurs) && intval($minOccurs) > 0) {
                            $isRequired = true;
                        } else {
                            $isRequired = false;
                        }
                    }
                    
                    if ($isRequired) {
                        $required[] = $elementName;
                    }
                }
            }
        }
        
        return $required;
    }
    
    /**
     * Parse an imported XSD schema for required fields
     * 
     * @param string $schemaLocation URL to the schema
     * @return array Array of required fields by type name
     */
    protected function parseImportedSchema($schemaLocation)
    {
        $requiredFields = [];
        
        try {
            $xmlContent = $this->loadWsdlContent($schemaLocation);
            $dom = $this->createDomDocument($xmlContent);
            
            // Find all complexType elements
            $complexTypes = $dom->getElementsByTagName('complexType');
            
            foreach ($complexTypes as $complexType) {
                $typeName = $complexType->getAttribute('name');
                if (empty($typeName)) continue;
                
                $required = $this->extractRequiredFieldsFromComplexType($complexType);
                
                if (!empty($required)) {
                    $requiredFields[$typeName] = $required;
                }
            }
            
        } catch (\Exception $e) {
            // Log error but don't fail
            \Log::warning('Failed to parse imported schema ' . $schemaLocation . ': ' . $e->getMessage());
        }
        
        return $requiredFields;
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
            
            // Parse WSDL to get required field information and inheritance chains
            $requiredFieldsByType = $this->parseWsdlForRequiredFields();
            $inheritanceMap = $this->parseWsdlForInheritance();
            
            // First pass: Build all structures without inheritance
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
                        $required = [];
                        foreach (explode(';', $type) as $param) {
                            $param = trim($param);
                            if (empty($param)) continue;
                            
                            // Parse field definition
                            // Format from __getTypes(): "type field_name"
                            $parts = explode(' ', $param);
                            if (count($parts) >= 2) {
                                $fieldType = trim($parts[0]);
                                $fieldName = trim($parts[1]);
                                
                                $parameters[$fieldName] = $fieldType;
                                
                                // Check if this field is required based on WSDL parsing
                                if (isset($requiredFieldsByType[$name]) && 
                                    in_array($fieldName, $requiredFieldsByType[$name])) {
                                    $required[] = $fieldName;
                                }
                            }
                        }
                        
                        $structures[$name] = [
                            'properties' => $parameters,
                            'required' => $required
                        ];
                    }
                } else {
                    // declared as "type data_type"
                    $parts = explode(' ', $type);
                    if (count($parts) > 1) {
                        $structures[$parts[1]] = $parts[0];
                    }
                }
            }
            
            // Second pass: Apply inheritance to all types
            $this->applyInheritanceToStructures($structures, $inheritanceMap, $requiredFieldsByType);
            
            // Third pass: Convert to OpenAPI format
            foreach ($structures as $name => &$type) {
                if (is_array($type)) {
                    if (isset($type['properties'])) {
                        // This is a struct with properties and required fields
                        $properties = $type['properties'];
                        $required = $type['required'] ?? [];
                        
                        foreach ($properties as $fieldName => &$fieldType) {
                            if (array_key_exists($fieldType, $structures)) {
                                $fieldType = ['$ref' => '#/components/schemas/' . $fieldType];
                            } else {
                                // convert simple types to swagger types
                                $newType = static::soapType2ApiDocType($fieldType);
                                $fieldType = $newType;
                            }
                        }
                        
                        $type = [
                            'type' => 'object', 
                            'properties' => $properties
                        ];
                        
                        if (!empty($required)) {
                            $type['required'] = $required;
                        }
                    } elseif ((1 === count($type)) && isset($type[0])) {
                        $type = $type[0];
                        // array of type
                        if (array_key_exists($type, $structures)) {
                            $type = ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/' . $type]];
                        } else {
                            // convert simple types to swagger types
                            $newType = static::soapType2ApiDocType($type);
                            $type = ['type' => 'array', 'items' => $newType];
                        }
                    } else {
                        // Legacy array of field definitions (fallback)
                        foreach ($type as $fieldName => &$fieldType) {
                            if (array_key_exists($fieldType, $structures)) {
                                $fieldType = ['$ref' => '#/components/schemas/' . $fieldType];
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
                        $type = ['$ref' => '#/components/schemas/' . $type];
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
     * Apply inheritance to all structures in the correct order
     * 
     * @param array $structures The structures to apply inheritance to
     * @param array $inheritanceMap The inheritance relationships
     * @param array $requiredFieldsByType Required fields by type
     */
    protected function applyInheritanceToStructures(&$structures, $inheritanceMap, $requiredFieldsByType)
    {
        // Sort inheritance map by dependency order (base types first)
        $sortedInheritance = $this->sortInheritanceByDependency($inheritanceMap);
        
        // Apply inheritance in dependency order
        foreach ($sortedInheritance as $normalizedTypeName => $normalizedBaseTypeName) {
            // Find the original type name (not normalized)
            $originalTypeName = $this->findOriginalTypeName($normalizedTypeName, $structures);
            $originalBaseTypeName = $this->findOriginalTypeName($normalizedBaseTypeName, $structures);
            
            if ($originalTypeName && $originalBaseTypeName) {
                $this->applyInheritanceToType($structures, $originalTypeName, $originalBaseTypeName, $requiredFieldsByType);
            }
        }
        
        // Handle empty types with inheritance
        foreach ($inheritanceMap as $normalizedTypeName => $normalizedBaseTypeName) {
            $originalTypeName = $this->findOriginalTypeName($normalizedTypeName, $structures);
            $originalBaseTypeName = $this->findOriginalTypeName($normalizedBaseTypeName, $structures);
            
            if ($originalTypeName && $originalBaseTypeName && 
                !isset($structures[$originalTypeName]['properties']) && 
                isset($structures[$originalBaseTypeName]['properties'])) {
                
                // This type exists but doesn't have properties yet (empty type with inheritance)
                $inheritedFields = $structures[$originalBaseTypeName]['properties'];
                $inheritedRequired = $structures[$originalBaseTypeName]['required'] ?? [];
                
                $structures[$originalTypeName] = [
                    'properties' => $inheritedFields,
                    'required' => $inheritedRequired
                ];
            }
        }
    }
    
    /**
     * Sort inheritance map by dependency order (base types first)
     * 
     * @param array $inheritanceMap The inheritance relationships
     * @return array Sorted inheritance map
     */
    protected function sortInheritanceByDependency($inheritanceMap)
    {
        $sorted = [];
        $visited = [];
        
        foreach ($inheritanceMap as $type => $base) {
            $this->topologicalSort($type, $inheritanceMap, $sorted, $visited);
        }
        
        return $sorted;
    }
    
    /**
     * Topological sort for inheritance dependencies
     * 
     * @param string $type The type to process
     * @param array $inheritanceMap The inheritance relationships
     * @param array $sorted The sorted result
     * @param array $visited Visited nodes
     */
    protected function topologicalSort($type, $inheritanceMap, &$sorted, &$visited)
    {
        if (isset($visited[$type])) {
            return;
        }
        
        $visited[$type] = true;
        
        if (isset($inheritanceMap[$type])) {
            $this->topologicalSort($inheritanceMap[$type], $inheritanceMap, $sorted, $visited);
        }
        
        $sorted[$type] = $inheritanceMap[$type] ?? null;
    }
    
    /**
     * Find the original type name from normalized name
     * 
     * @param string $normalizedTypeName The normalized type name
     * @param array $structures The structures to search in
     * @return string|null The original type name or null if not found
     */
    protected function findOriginalTypeName($normalizedTypeName, $structures)
    {
        foreach (array_keys($structures) as $structureName) {
            if ($this->normalizeTypeName($structureName) === $normalizedTypeName) {
                return $structureName;
            }
        }
        return null;
    }
    
    /**
     * Apply inheritance to a specific type
     * 
     * @param array $structures The structures
     * @param string $typeName The type name
     * @param string $baseTypeName The base type name
     * @param array $requiredFieldsByType Required fields by type
     */
    protected function applyInheritanceToType(&$structures, $typeName, $baseTypeName, $requiredFieldsByType)
    {
        if (!isset($structures[$baseTypeName]['properties'])) {
            return; // Base type doesn't have properties
        }
        
        $baseProperties = $structures[$baseTypeName]['properties'];
        $baseRequired = $structures[$baseTypeName]['required'] ?? [];
        
        // Get current properties and required fields
        $currentProperties = $structures[$typeName]['properties'] ?? [];
        $currentRequired = $structures[$typeName]['required'] ?? [];
        
        // Merge properties (current properties override inherited ones)
        $mergedProperties = array_merge($baseProperties, $currentProperties);
        
        // Merge required fields
        $mergedRequired = array_merge($baseRequired, $currentRequired);
        
        // Update the structure
        $structures[$typeName] = [
            'properties' => $mergedProperties,
            'required' => array_unique($mergedRequired)
        ];
    }
    
    /**
     * Parse WSDL to get inheritance relationships
     * 
     * @return array Array of inheritance relationships by type name
     */
    protected function parseWsdlForInheritance()
    {
        $inheritanceMap = [];
        
        if (empty($this->wsdl)) {
            return $inheritanceMap;
        }
        
        try {
            $xmlContent = $this->loadWsdlContent($this->wsdl);
            $dom = $this->createDomDocument($xmlContent);
            
            // Find all complexType elements
            $complexTypes = $dom->getElementsByTagName('complexType');
            
            foreach ($complexTypes as $complexType) {
                $typeName = $complexType->getAttribute('name');
                if (empty($typeName)) continue;
                
                $this->extractInheritanceFromComplexType($complexType, $typeName, $inheritanceMap);
            }
            
            // Also check for imported WSDL files
            $imports = $dom->getElementsByTagName('import');
            foreach ($imports as $import) {
                $schemaLocation = $import->getAttribute('schemaLocation');
                if (!empty($schemaLocation)) {
                    $importedInheritance = $this->parseImportedSchemaForInheritance($schemaLocation);
                    $inheritanceMap = array_merge($inheritanceMap, $importedInheritance);
                }
            }
            
            // Also check for WSDL imports
            $wsdlImports = $dom->getElementsByTagName('wsdl:import');
            
            foreach ($wsdlImports as $wsdlImport) {
                $location = $wsdlImport->getAttribute('location');
                if (!empty($location)) {
                    $importedInheritance = $this->parseImportedWsdlForInheritance($location);
                    $inheritanceMap = array_merge($inheritanceMap, $importedInheritance);
                }
            }
            
            // Also check for import elements that might be WSDL imports
            $allImports = $dom->getElementsByTagName('import');
            
            foreach ($allImports as $import) {
                $location = $import->getAttribute('location');
                $namespace = $import->getAttribute('namespace');
                $schemaLocation = $import->getAttribute('schemaLocation');
                
                // If it has a location attribute and ends with wsdl=wsdl0, it's a WSDL import
                if (!empty($location) && strpos($location, 'wsdl=wsdl0') !== false) {
                    $importedInheritance = $this->parseImportedWsdlForInheritance($location);
                    $inheritanceMap = array_merge($inheritanceMap, $importedInheritance);
                }
                // If it has a schemaLocation, it's an XSD import
                elseif (!empty($schemaLocation)) {
                    $importedInheritance = $this->parseImportedSchemaForInheritance($schemaLocation);
                    $inheritanceMap = array_merge($inheritanceMap, $importedInheritance);
                }
            }
            
        } catch (\Exception $e) {
            // Log error but don't fail
            \Log::warning('Failed to parse WSDL for inheritance: ' . $e->getMessage());
        }
        
        return $inheritanceMap;
    }
    
    /**
     * Extract inheritance information from a complexType element
     * 
     * @param \DOMElement $complexType The complexType element
     * @param string $typeName The type name
     * @param array $inheritanceMap The inheritance map to update
     */
    protected function extractInheritanceFromComplexType($complexType, $typeName, &$inheritanceMap)
    {
        // Look for complexContent with extension
        $complexContents = $complexType->getElementsByTagName('complexContent');
        foreach ($complexContents as $complexContent) {
            $extensions = $complexContent->getElementsByTagName('extension');
            foreach ($extensions as $extension) {
                $base = $extension->getAttribute('base');
                if (!empty($base)) {
                    // Extract the base type name (remove namespace prefix if present)
                    $baseTypeName = $base;
                    if (strpos($base, ':') !== false) {
                        $baseTypeName = substr($base, strrpos($base, ':') + 1);
                    }
                    
                    // Normalize type names to match PHP SOAP client output
                    // Remove "Input." prefix and other common prefixes
                    $normalizedTypeName = $this->normalizeTypeName($typeName);
                    $normalizedBaseTypeName = $this->normalizeTypeName($baseTypeName);
                    
                    $inheritanceMap[$normalizedTypeName] = $normalizedBaseTypeName;
                }
            }
        }
    }
    
    /**
     * Parse an imported schema for inheritance relationships
     * 
     * @param string $schemaLocation URL to the schema
     * @return array Array of inheritance relationships by type name
     */
    protected function parseImportedSchemaForInheritance($schemaLocation)
    {
        $inheritanceMap = [];
        
        try {
            $dom = new \DOMDocument();
            
            // Load schema from URL
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'DreamFactory SOAP Client'
                ]
            ]);
            
            $xmlContent = file_get_contents($schemaLocation, false, $context);
            if ($xmlContent === false) {
                throw new \Exception('Failed to load schema from URL: ' . $schemaLocation);
            }
            
            $dom->loadXML($xmlContent, LIBXML_NONET);
            $dom->preserveWhiteSpace = false;
            
            // Find all complexType elements
            $complexTypes = $dom->getElementsByTagName('complexType');
            
            foreach ($complexTypes as $complexType) {
                $typeName = $complexType->getAttribute('name');
                if (empty($typeName)) continue;
                
                // Look for complexContent with extension
                $complexContents = $complexType->getElementsByTagName('complexContent');
                foreach ($complexContents as $complexContent) {
                    $extensions = $complexContent->getElementsByTagName('extension');
                    foreach ($extensions as $extension) {
                        $base = $extension->getAttribute('base');
                        if (!empty($base)) {
                            // Extract the base type name (remove namespace prefix if present)
                            $baseTypeName = $base;
                            if (strpos($base, ':') !== false) {
                                $baseTypeName = substr($base, strrpos($base, ':') + 1);
                            }
                            
                            // Normalize type names to match PHP SOAP client output
                            $normalizedTypeName = $this->normalizeTypeName($typeName);
                            $normalizedBaseTypeName = $this->normalizeTypeName($baseTypeName);
                            
                            $inheritanceMap[$normalizedTypeName] = $normalizedBaseTypeName;
                        }
                    }
                }
            }
            
        } catch (\Exception $e) {
            // Log error but don't fail
            \Log::warning('Failed to parse imported schema ' . $schemaLocation . ' for inheritance: ' . $e->getMessage());
        }
        
        return $inheritanceMap;
    }
    
    /**
     * Parse an imported WSDL file for inheritance relationships
     * 
     * @param string $wsdlLocation URL to the WSDL
     * @return array Array of inheritance relationships by type name
     */
    protected function parseImportedWsdlForInheritance($wsdlLocation)
    {
        $inheritanceMap = [];
        
        try {
            $dom = new \DOMDocument();
            
            // Load WSDL from URL
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'DreamFactory SOAP Client'
                ]
            ]);
            
            $xmlContent = file_get_contents($wsdlLocation, false, $context);
            if ($xmlContent === false) {
                throw new \Exception('Failed to load WSDL from URL: ' . $wsdlLocation);
            }
            
            $dom->loadXML($xmlContent, LIBXML_NONET);
            $dom->preserveWhiteSpace = false;
            
            // Find all complexType elements in the imported WSDL
            $complexTypes = $dom->getElementsByTagName('complexType');
            
            foreach ($complexTypes as $complexType) {
                $typeName = $complexType->getAttribute('name');
                if (empty($typeName)) continue;
                
                // Look for complexContent with extension
                $complexContents = $complexType->getElementsByTagName('complexContent');
                foreach ($complexContents as $complexContent) {
                    $extensions = $complexContent->getElementsByTagName('extension');
                    foreach ($extensions as $extension) {
                        $base = $extension->getAttribute('base');
                        if (!empty($base)) {
                            // Extract the base type name (remove namespace prefix if present)
                            $baseTypeName = $base;
                            if (strpos($base, ':') !== false) {
                                $baseTypeName = substr($base, strrpos($base, ':') + 1);
                            }
                            
                            // Normalize type names to match PHP SOAP client output
                            $normalizedTypeName = $this->normalizeTypeName($typeName);
                            $normalizedBaseTypeName = $this->normalizeTypeName($baseTypeName);
                            
                            $inheritanceMap[$normalizedTypeName] = $normalizedBaseTypeName;
                        }
                    }
                }
            }
            
            // Also check for nested imports in the imported WSDL
            $imports = $dom->getElementsByTagName('import');
            foreach ($imports as $import) {
                $schemaLocation = $import->getAttribute('schemaLocation');
                if (!empty($schemaLocation)) {
                    $importedInheritance = $this->parseImportedSchemaForInheritance($schemaLocation);
                    $inheritanceMap = array_merge($inheritanceMap, $importedInheritance);
                }
            }
            
            // Check for nested WSDL imports
            $wsdlImports = $dom->getElementsByTagName('wsdl:import');
            foreach ($wsdlImports as $wsdlImport) {
                $nestedWsdlLocation = $wsdlImport->getAttribute('location');
                if (!empty($nestedWsdlLocation)) {
                    $importedInheritance = $this->parseImportedWsdlForInheritance($nestedWsdlLocation);
                    $inheritanceMap = array_merge($inheritanceMap, $importedInheritance);
                }
            }
            
        } catch (\Exception $e) {
            // Log error but don't fail
            \Log::warning('Failed to parse imported WSDL ' . $wsdlLocation . ' for inheritance: ' . $e->getMessage());
        }
        
        return $inheritanceMap;
    }
    
    /**
     * Parse an imported WSDL file for required fields
     * 
     * @param string $wsdlLocation URL to the WSDL
     * @return array Array of required fields by type name
     */
    protected function parseImportedWsdl($wsdlLocation)
    {
        $requiredFields = [];
        
        try {
            $dom = new \DOMDocument();
            
            // Load WSDL from URL
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'DreamFactory SOAP Client'
                ]
            ]);
            
            $xmlContent = file_get_contents($wsdlLocation, false, $context);
            if ($xmlContent === false) {
                throw new \Exception('Failed to load WSDL from URL: ' . $wsdlLocation);
            }
            
            $dom->loadXML($xmlContent, LIBXML_NONET);
            $dom->preserveWhiteSpace = false;
            
            // Find all complexType elements in the imported WSDL
            $complexTypes = $dom->getElementsByTagName('complexType');
            
            foreach ($complexTypes as $complexType) {
                $typeName = $complexType->getAttribute('name');
                if (empty($typeName)) continue;
                
                $required = [];
                
                // Look for sequence elements
                $sequences = $complexType->getElementsByTagName('sequence');
                foreach ($sequences as $sequence) {
                    $elements = $sequence->getElementsByTagName('element');
                    foreach ($elements as $element) {
                        $elementName = $element->getAttribute('name');
                        $minOccurs = $element->getAttribute('minOccurs');
                        
                        if (!empty($elementName)) {
                            // If minOccurs is not specified or is "1", field is required
                            // getAttribute() returns empty string when attribute doesn't exist
                            $isRequired = false;
                            
                            if (strlen($minOccurs) === 0) {
                                // minOccurs not set, default is 1 (required)
                                $isRequired = true;
                            } elseif ($minOccurs === '1') {
                                // minOccurs explicitly set to 1 (required)
                                $isRequired = true;
                            } elseif ($minOccurs === '0') {
                                // minOccurs explicitly set to 0 (optional)
                                $isRequired = false;
                            } else {
                                // Any other value, check if it's numeric and greater than 0
                                if (is_numeric($minOccurs) && intval($minOccurs) > 0) {
                                    $isRequired = true;
                                } else {
                                    $isRequired = false;
                                }
                            }
                            
                            if ($isRequired) {
                                $required[] = $elementName;
                            }
                        }
                    }
                }
                
                if (!empty($required)) {
                    $requiredFields[$typeName] = $required;
                }
            }
            
            // Also check for nested imports in the imported WSDL
            $imports = $dom->getElementsByTagName('import');
            foreach ($imports as $import) {
                $schemaLocation = $import->getAttribute('schemaLocation');
                if (!empty($schemaLocation)) {
                    $importedRequired = $this->parseImportedSchema($schemaLocation);
                    $requiredFields = array_merge($requiredFields, $importedRequired);
                }
            }
            
            // Check for nested WSDL imports
            $wsdlImports = $dom->getElementsByTagName('wsdl:import');
            foreach ($wsdlImports as $wsdlImport) {
                $nestedWsdlLocation = $wsdlImport->getAttribute('location');
                if (!empty($nestedWsdlLocation)) {
                    $importedRequired = $this->parseImportedWsdl($nestedWsdlLocation);
                    $requiredFields = array_merge($requiredFields, $importedRequired);
                }
            }
            
        } catch (\Exception $e) {
            // Log error but don't fail
            \Log::warning('Failed to parse imported WSDL ' . $wsdlLocation . ': ' . $e->getMessage());
        }
        
        return $requiredFields;
    }
    
    /**
     * Normalize type names to match PHP SOAP client output
     * 
     * @param string $name The type name to normalize
     * @return string The normalized type name
     */
    protected function normalizeTypeName($name)
    {
        $name = strtolower($name);
        $name = str_replace('input.', '', $name);
        $name = str_replace('output.', '', $name);
        $name = str_replace('request.', '', $name);
        $name = str_replace('response.', '', $name);
        $name = str_replace('result.', '', $name);
        $name = str_replace('return.', '', $name);
        $name = str_replace('param.', '', $name);
        $name = str_replace('arg.', '', $name);
        $name = str_replace('value.', '', $name);
        $name = str_replace('item.', '', $name);
        $name = str_replace('element.', '', $name);
        $name = str_replace('complex.', '', $name);
        $name = str_replace('sequence.', '', $name);
        $name = str_replace('choice.', '', $name);
        $name = str_replace('group.', '', $name);
        $name = str_replace('all.', '', $name);
        $name = str_replace('any.', '', $name);
        $name = str_replace('attribute.', '', $name);
        $name = str_replace('attributegroup.', '', $name);
        $name = str_replace('extension.', '', $name);
        $name = str_replace('restriction.', '', $name);
        $name = str_replace('simpletype.', '', $name);
        $name = str_replace('complextype.', '', $name);
        $name = str_replace('schema.', '', $name);
        $name = str_replace('types.', '', $name);
        $name = str_replace('definitions.', '', $name);
        $name = str_replace('namespace.', '', $name);
        $name = str_replace('prefix.', '', $name);
        $name = str_replace('local.', '', $name);
        $name = str_replace('global.', '', $name);
        $name = str_replace('targetnamespace.', '', $name);
        $name = str_replace('schemalocation.', '', $name);
        $name = str_replace('import.', '', $name);
        $name = str_replace('wsdl:import.', '', $name);
        $name = str_replace('soap:header.', '', $name);
        $name = str_replace('soap:body.', '', $name);
        $name = str_replace('soap:fault.', '', $name);
        $name = str_replace('soap:envelope.', '', $name);
        
        return $name;
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

    protected function formatPayload(&$payload)
    {
        if (!is_array($payload)) {
            return;
        }
        foreach ($payload as $key => &$value) {
            if (is_array($value)) {
                if (0 === strcasecmp('soapvar', $key)) {
                    $data = Arr::get($value, 'data');
                    if ($encoding = Arr::get($value, 'encoding')) {
                        // see if there is a constant usage
                        if (!is_numeric($encoding)) {
                            if (defined($encoding)) {
                                $encoding = constant($encoding);
                            }
                        }
                    } else {
                        // attempt to determine it
                        switch (gettype($data)) {
                            case 'array':
                                $encoding = SOAP_ENC_ARRAY;
                                break;
                            case 'object':
                                $encoding = SOAP_ENC_OBJECT;
                                break;
                            case 'boolean':
                                $encoding = XSD_BOOLEAN;
                                break;
                            case 'double':
                                $encoding = XSD_DOUBLE;
                                break;
                            case 'integer':
                                $encoding = XSD_INTEGER;
                                break;
                            case 'string':
                                $encoding = XSD_STRING;
                                break;
                        }
                    }

                    $payload = new \SoapVar(
                        $data,
                        $encoding,
                        Arr::get($value, 'type_name'),
                        Arr::get($value, 'type_namespace'),
                        Arr::get($value, 'node_name'),
                        Arr::get($value, 'node_namespace')
                    );
                } else {
                    $this->formatPayload($value);
                }
            }
        }
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

        if (is_array($payload)) {
            $this->formatPayload($payload);
        }
        try {
            $result = $this->client->$function($payload);
            $result = static::object2Array($result);

            // debugging help
            if ($last = $this->client->__getLastRequest()) {
                Log::debug($this->name . ' last SOAP request: ' . $last);
            }
            if ($lastHeaders = $this->client->__getLastRequestHeaders()) {
                Log::debug($this->name . ' last SOAP request headers: ' . $lastHeaders);
            }
            if ($last = $this->client->__getLastResponse()) {
                Log::debug($this->name . ' last SOAP response: ' . $last);
            }
            if ($lastHeaders = $this->client->__getLastResponseHeaders()) {
                Log::debug($this->name . ' last SOAP response headers: ' . $lastHeaders);
            }

            return $result;
        } catch (\SoapFault $e) {
            // debugging help
            if ($last = $this->client->__getLastRequest()) {
                Log::debug($this->name . ' failed SOAP request: ' . $last);
            }
            if ($lastHeaders = $this->client->__getLastRequestHeaders()) {
                Log::debug($this->name . ' failed SOAP request headers: ' . $lastHeaders);
            }

            /** @noinspection PhpUndefinedFieldInspection */
            $faultCode = (property_exists($e, 'faultcode') ? $e->faultcode : $e->getCode());
            $errorCode = Response::HTTP_INTERNAL_SERVER_ERROR;
            // Fault code can be a string.
            if (is_numeric($faultCode) && !str_contains($faultCode, '.')) {
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
    protected function getApiDocPaths()
    {
        $capitalized = camelize($this->name);

        $paths = [
            '/' => [
                'get' => [
                    'summary'     => 'Get resources for this service.',
                    'operationId' => 'get' . $capitalized . 'Resources',
                    'description' => 'Return an array of the resources available.',
                    'parameters'  => [
                        ApiOptions::documentOption(ApiOptions::AS_LIST),
                        ApiOptions::documentOption(ApiOptions::AS_ACCESS_LIST),
                        ApiOptions::documentOption(ApiOptions::INCLUDE_ACCESS),
                        ApiOptions::documentOption(ApiOptions::REFRESH),
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SoapResponse']
                    ],
                ],
            ],
        ];
        foreach ($this->getFunctions() as $resource) {
            // Normalize the function name to match what the API actually accepts:
            // Convert to lowercase and remove underscores
            $normalizedName = str_replace('_', '', strtolower($resource->name));
            $paths['/' . $normalizedName] = [
                'post' => [
                    'summary'     => 'call the ' . $resource->name . ' operation.',
                    'description' => is_null($resource->description) ? '' : $resource->description,
                    'operationId' => 'call' . $capitalized . $resource->name,
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/' . $resource->requestType
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/' . $resource->responseType]
                    ],
                ],
            ];
        }

        return $paths;
    }

    protected function getApiDocRequests()
    {
        $requests = [];
        foreach ($this->getFunctions() as $resource) {
            $requests[$resource->requestType] = [
                'description' => $resource->requestType . ' Request',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/' . $resource->requestType]
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/' . $resource->requestType]
                    ],
                ],
            ];
        }

        return $requests;
    }

    protected function getApiDocResponses()
    {
        $responses = [
            'SoapResponse' => [
                'description' => 'SOAP Response',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/SoapResponse']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/SoapResponse']
                    ],
                ],
            ],
        ];

        foreach ($this->getFunctions() as $resource) {
            $responses[$resource->responseType] = [
                'description' => $resource->responseType . ' Response',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/' . $resource->responseType]
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/' . $resource->responseType]
                    ],
                ],
            ];
        }

        return $responses;
    }

    protected function getApiDocSchemas()
    {
        $wrapper = ResourcesWrapper::getWrapper();

        $models = [
            'SoapResponse' => [
                'type'       => 'object',
                'properties' => [
                    $wrapper => [
                        'type'        => 'array',
                        'description' => 'Array of system records.',
                        'items'       => [
                            '$ref' => '#/components/schemas/SoapMethods',
                        ],
                    ],
                ],
            ],
            'SoapMethods'  => [
                'type'       => 'object',
                'properties' => [
                    'name'           => [
                        'type'        => 'string',
                        'description' => 'A URL to the target host.',
                    ],
                    'description'    => [
                        'type'        => 'string',
                        'description' => 'An optional string describing the host designated by the URL.',
                    ],
                    'requestType'    => [
                        'type'        => 'string',
                        'description' => 'An optional string describing the host designated by the URL.',
                    ],
                    'requestFields'  => [
                        'type'        => 'object',
                        'description' => 'An optional string describing the host designated by the URL.',
                    ],
                    'responseType'   => [
                        'type'        => 'string',
                        'description' => 'An optional string describing the host designated by the URL.',
                    ],
                    'responseFields' => [
                        'type'        => 'object',
                        'description' => 'An optional string describing the host designated by the URL.',
                    ],
                    'access'         => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'An array of verbs allowed.',
                    ],
                ],
            ],
        ];

        return array_merge($models, $this->getTypes());
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
            case 'gYearMonth':
            case 'gYear':
            case 'gMonthDay':
            case 'gDay':
            case 'gMonth':
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
            case 'anySimpleType': // SOAP specific, use swagger's Any Type {} or no type
                return ['description' => 'any simple type'];
            case 'anyType': // SOAP specific, use swagger's Any Type {} or no type
                return ['description' => 'any type'];
            case 'anyURI':
                return ['type' => 'string', 'format' => 'uri', 'description' => 'any valid URI'];
            case 'anyXML': // SOAP specific, use swagger's Any Type {} or no type
            case '<anyXML>': // SOAP specific, use swagger's Any Type {} or no type
                return ['description' => 'any XML'];
            // derived string types
            case 'QName':
            case 'NOTATION':
            case 'normalizedString':
            case 'token':
            case 'language':
            case 'ID':
            case 'IDREF':
            case 'IDREFS':
            case 'ENTITY':
            case 'ENTITIES':
            case 'NMTOKEN':
            case 'NMTOKENS':
            case 'Name':
            case 'NCName':
                return ['type' => 'string', 'description' => 'derived string type: ' . $name];
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

    /**
     * Load WSDL content from URL
     * 
     * @param string $url The WSDL URL
     * @return string The XML content
     * @throws \Exception If loading fails
     */
    protected function loadWsdlContent($url)
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'DreamFactory SOAP Client'
            ]
        ]);
        
        $xmlContent = file_get_contents($url, false, $context);
        if ($xmlContent === false) {
            throw new \Exception('Failed to load WSDL from URL: ' . $url);
        }
        
        return $xmlContent;
    }
    
    /**
     * Create DOMDocument from XML content
     * 
     * @param string $xmlContent The XML content
     * @return \DOMDocument The DOM document
     */
    protected function createDomDocument($xmlContent)
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xmlContent, LIBXML_NONET);
        $dom->preserveWhiteSpace = false;
        return $dom;
    }
}