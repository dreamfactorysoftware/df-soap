<?php
// Minimal SOAP simulator for df-soap
// Run: php -S localhost:8080 simulator.php

declare(strict_types=1);

// Define polyfills before autoload so vendor classes can use them during construction
if (!function_exists('array_get')) {
    function array_get($array, $key, $default = null) {
        if ($key === null) { return $array; }
        $segments = is_array($key) ? $key : explode('.', (string)$key);
        foreach ($segments as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } elseif ($array instanceof ArrayAccess && isset($array[$segment])) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }
        return $array;
    }
}

// Provide lightweight aliases for Illuminate helpers when framework aliases are not bootstrapped
if (!class_exists('Arr')) {
    if (class_exists('\\Illuminate\\Support\\Arr')) {
        class_alias('\\Illuminate\\Support\\Arr', 'Arr');
    } else {
        class Arr {
            public static function get($array, $key, $default = null) { return array_get($array, $key, $default); }
        }
    }
}
// Minimal Str helper
if (!class_exists('Str')) {
    if (class_exists('\\Illuminate\\Support\\Str')) {
        // Prefer Illuminate implementation if available (non-facade)
        class_alias('\\Illuminate\\Support\\Str', 'Str');
    } else {
        class Str {
            public static function contains($haystack, $needles): bool {
                foreach ((array)$needles as $n) {
                    if ($n !== '' && strpos((string)$haystack, (string)$n) !== false) { return true; }
                }
                return false;
            }
            public static function after($subject, $search): string {
                if ($search === '' || $search === null) { return (string)$subject; }
                $pos = strpos((string)$subject, (string)$search);
                return ($pos === false) ? (string)$subject : substr((string)$subject, $pos + strlen((string)$search));
            }
        }
    }
}

// Minimal Config polyfill (avoid Laravel Facade when app not bootstrapped)
if (!class_exists('Config')) {
    class Config {
        public static function get($key, $default = null) { return $default; }
    }
}

// Minimal Log polyfill (no-op to error_log)
if (!class_exists('Log')) {
    class Log {
        protected static function write($level, $message, array $context = []): void {
            if (is_array($message) || is_object($message)) { $message = json_encode($message); }
            $line = '[' . strtoupper($level) . "] " . (string)$message;
            if (!empty($context)) { $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES); }
            @error_log($line);
        }
        public static function info($m, array $c = []): void { self::write('info', $m, $c); }
        public static function debug($m, array $c = []): void { self::write('debug', $m, $c); }
        public static function warning($m, array $c = []): void { self::write('warning', $m, $c); }
        public static function error($m, array $c = []): void { self::write('error', $m, $c); }
    }
}

// Minimal storage_path helper to bypass Laravel helper usage
if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string {
        $base = __DIR__;
        return $path ? ($base . DIRECTORY_SEPARATOR . trim($path, DIRECTORY_SEPARATOR)) : $base;
    }
}

// Minimal Cache facade polyfill (array-backed, non-persistent)
if (!class_exists('Cache')) {
    class Cache {
        private static array $store = [];
        private static function now(): int { return time(); }
        private static function cleanup(): void {
            $t = self::now();
            foreach (self::$store as $k => $entry) {
                $exp = $entry['exp'] ?? null;
                if ($exp !== null && $exp <= $t) { unset(self::$store[$k]); }
            }
        }
        public static function get($key, $default = null) {
            self::cleanup();
            if (!array_key_exists($key, self::$store)) return $default;
            $entry = self::$store[$key];
            $exp = $entry['exp'] ?? null;
            if ($exp !== null && $exp <= self::now()) { unset(self::$store[$key]); return $default; }
            return $entry['val'] ?? $default;
        }
        public static function put($key, $value, $ttl = 0): void {
            $exp = ($ttl && is_numeric($ttl)) ? (self::now() + (int)$ttl) : null;
            self::$store[$key] = ['val' => $value, 'exp' => $exp];
        }
        public static function forever($key, $value): void {
            self::$store[$key] = ['val' => $value, 'exp' => null];
        }
        public static function remember($key, $ttl, $callback) {
            $val = self::get($key, null);
            if ($val !== null) return $val;
            $val = is_callable($callback) ? $callback() : $callback;
            self::put($key, $val, $ttl);
            return $val;
        }
        public static function rememberForever($key, $callback) {
            $val = self::get($key, null);
            if ($val !== null) return $val;
            $val = is_callable($callback) ? $callback() : $callback;
            self::forever($key, $val);
            return $val;
        }
        public static function forget($key): bool {
            $exists = array_key_exists($key, self::$store);
            unset(self::$store[$key]);
            return $exists;
        }
        public static function flush(): void { self::$store = []; }
        public static function pull($key, $default = null) {
            $val = self::get($key, $default);
            self::forget($key);
            return $val;
        }
    }
}

require __DIR__ . '/vendor/autoload.php';

// Persist selections across requests for mock REST routing
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// If SOAP extension is not loaded, render a friendly message and stop early
if (!class_exists('SoapClient')) {
    $ini = php_ini_loaded_file() ?: '(unknown php.ini)';
    $extDir = ini_get('extension_dir') ?: '(unknown)';
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>df-soap Simulator</title>';
    echo '<style>body{font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial;margin:24px} code,pre{background:#f6f8fa;padding:2px 6px;border-radius:4px}</style></head><body>';
    echo '<h1>df-soap Simulator</h1>';
    echo '<p style="color:#b91c1c;font-weight:600">PHP SOAP extension is not enabled.</p>';
    echo '<div>Enable it and restart the PHP server:</div>';
    echo '<ol>';
    echo '<li>Edit <code>' . htmlspecialchars($ini) . '</code></li>';
    echo '<li>Uncomment or add <code>extension=soap</code> (or <code>extension=php_soap.dll</code> on Windows)</li>';
    echo '<li>Ensure <code>extension_dir</code> points to <code>' . htmlspecialchars($extDir) . '</code></li>';
    echo '<li>Restart: <code>Ctrl+C</code> this server and run <code>php -S localhost:8080 simulator.php</code> again</li>';
    echo '</ol>';
    echo '<div>If the DLL is missing, install the SOAP extension that matches your PHP version.</div>';
    echo '</body></html>';
    exit;
}

// Helpers
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function read_json_array(?string $s): array {
    if ($s === null || $s === '') return [];
    $decoded = json_decode($s, true);
    return is_array($decoded) ? $decoded : [];
}

// Inline subclass to allow overriding the outbound SOAP request XML when desired
class InlineSoapClient extends \DreamFactory\Core\Soap\Components\SoapClient {
    private ?string $overrideXml = null;
    public function setOverrideXml(?string $xml): void { $this->overrideXml = ($xml !== null && $xml !== '') ? $xml : null; }
    public function __doRequest($request, $location, $action, $version, $one_way = 0)
    {
        if ($this->overrideXml !== null) {
            $request = $this->overrideXml;
        }
        return parent::__doRequest($request, $location, $action, $version, $one_way);
    }
}

// Preview-only SoapClient that captures the generated SOAP XML without sending the request
class PreviewSoapClient extends \DreamFactory\Core\Soap\Components\SoapClient {
    public ?string $capturedXml = null;
    public function __doRequest($request, $location, $action, $version, $one_way = 0)
    {
        $this->capturedXml = $request;
        // Do not forward; pretend success for one-way preview
        return '';
    }
}

// Map SOAP simple types to OpenAPI schema fragments
function soapTypeToSchema(string $name): array {
    switch ($name) {
        case 'byte': return ['type' => 'number', 'format' => 'int8', 'description' => 'signed 8-bit integer'];
        case 'unsignedByte': return ['type' => 'number', 'format' => 'int8', 'description' => 'unsigned 8-bit integer'];
        case 'short': return ['type' => 'number', 'format' => 'int16', 'description' => 'signed 16-bit integer'];
        case 'unsignedShort': return ['type' => 'number', 'format' => 'int8', 'description' => 'unsigned 16-bit integer'];
        case 'int': case 'integer': case 'negativeInteger': case 'nonNegativeInteger': case 'nonPositiveInteger': case 'positiveInteger':
            return ['type' => 'number', 'format' => 'int32', 'description' => 'signed 32-bit integer'];
        case 'unsignedInt': return ['type' => 'number', 'format' => 'int32', 'description' => 'unsigned 32-bit integer'];
        case 'long': return ['type' => 'number', 'format' => 'int64', 'description' => 'signed 64-bit integer'];
        case 'unsignedLong': return ['type' => 'number', 'format' => 'int8', 'description' => 'unsigned 64-bit integer'];
        case 'float': return ['type' => 'number', 'format' => 'float', 'description' => 'float'];
        case 'double': return ['type' => 'number', 'format' => 'double', 'description' => 'double'];
        case 'decimal': return ['type' => 'number', 'description' => 'decimal'];
        case 'string': return ['type' => 'string', 'description' => 'string'];
        case 'base64Binary': return ['type' => 'string', 'format' => 'byte', 'description' => 'Base64-encoded characters'];
        case 'hexBinary': return ['type' => 'string', 'format' => 'binary', 'description' => 'hexadecimal-encoded characters'];
        case 'binary': return ['type' => 'string', 'format' => 'binary', 'description' => 'any sequence of octets'];
        case 'boolean': return ['type' => 'boolean', 'description' => 'true or false'];
        case 'date': return ['type' => 'string', 'format' => 'date', 'description' => 'As defined by full-date - RFC3339'];
        case 'time': return ['type' => 'string', 'description' => 'As defined by time - RFC3339'];
        case 'dateTime': return ['type' => 'string', 'format' => 'date-time', 'description' => 'As defined by date-time - RFC3339'];
        case 'gYearMonth': case 'gYear': case 'gMonthDay': case 'gDay': case 'gMonth':
            return ['type' => 'string', 'format' => 'date-time', 'description' => 'As defined by date-time - RFC3339'];
        case 'duration': return ['type' => 'string', 'description' => 'Duration or time interval as ISO 8601 PnYnMnDTnHnMnS'];
        case 'password': return ['type' => 'string', 'format' => 'password', 'description' => 'Used to hint UIs the input needs to be obscured'];
        case 'anySimpleType': return ['description' => 'any simple type'];
        case 'anyType': return ['description' => 'any type'];
        case 'anyURI': return ['type' => 'string', 'format' => 'uri', 'description' => 'any valid URI'];
        case 'anyXML': case '<anyXML>': return ['description' => 'any XML'];
        default:
            return ['type' => 'string', 'description' => 'undetermined type: ' . (is_string($name) ? $name : 'object or array')];
    }
}

function buildTypesFromSoap(InlineSoapClient $client): array {
    $types = $client->__getTypes();
    $structures = [];
    foreach ($types as $type) {
        if (0 === substr_compare($type, 'struct ', 0, 7)) {
            $type = substr($type, 7);
            $name = strstr($type, ' ', true);
            $type = trim(strstr($type, ' '), "{} \t\n\r\0\x0B");
            if (false !== stripos($type, ' complexObjectArray;')) {
                $type = strstr(trim($type), ' complexObjectArray;', true);
                $structures[$name] = [$type];
            } else {
                $parameters = [];
                foreach (explode(';', $type) as $param) {
                    $parts = explode(' ', trim($param));
                    if (count($parts) > 1) {
                        $parameters[trim($parts[1])] = trim($parts[0]);
                    }
                }
                $structures[$name] = $parameters;
            }
        } else {
            $parts = explode(' ', $type);
            if (count($parts) > 1) {
                $structures[$parts[1]] = $parts[0];
            }
        }
    }
    foreach ($structures as $name => &$type) {
        if (is_array($type)) {
            if ((1 === count($type)) && isset($type[0])) {
                $t = $type[0];
                if (array_key_exists($t, $structures)) {
                    $type = ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/' . $t]];
                } else {
                    $type = ['type' => 'array', 'items' => soapTypeToSchema($t)];
                }
            } else {
                foreach ($type as $fieldName => &$fieldType) {
                    if (array_key_exists($fieldType, $structures)) {
                        $fieldType = ['$ref' => '#/components/schemas/' . $fieldType];
                    } else {
                        $fieldType = soapTypeToSchema($fieldType);
                    }
                }
                $type = ['type' => 'object', 'properties' => $type];
            }
        } else {
            if (array_key_exists($type, $structures)) {
                $type = ['$ref' => '#/components/schemas/' . $type];
            } else {
                $type = soapTypeToSchema($type);
            }
        }
    }
    ksort($structures);
    return $structures;
}

function parseFunctionSignature(string $function): array {
    $name = strstr(substr($function, strpos($function, ' ') + 1), '(', true);
    $responseType = strstr($function, ' ', true);
    $requestType = strstr(trim(strstr($function, '('), '()'), ' ', true);
    return ['name' => $name, 'requestType' => $requestType, 'responseType' => $responseType];
}

function buildOpenApiFromWsdl(InlineSoapClient $client, string $serviceName = 'soap'): array {
    $paths = [
        '/' => [
            'get' => [
                'summary' => 'Get resources for this service.',
                'operationId' => 'get' . ucfirst($serviceName) . 'Resources',
                'description' => 'Return an array of the resources available.',
                'responses' => [ '200' => ['$ref' => '#/components/responses/SoapResponse'] ]
            ]
        ]
    ];

    $types = buildTypesFromSoap($client);
    $functions = $client->__getFunctions();
    foreach ($functions as $fn) {
        $schema = parseFunctionSignature($fn);
        $paths['/' . $schema['name']] = [
            'post' => [
                'summary' => 'call the ' . $schema['name'] . ' operation.',
                'description' => '',
                'operationId' => 'call' . ucfirst($serviceName) . $schema['name'],
                'requestBody' => [ '$ref' => '#/components/requestBodies/' . $schema['requestType'] ],
                'responses' => [ '200' => ['$ref' => '#/components/responses/' . $schema['responseType']] ]
            ]
        ];
    }

    $requests = [];
    foreach ($functions as $fn) {
        $schema = parseFunctionSignature($fn);
        $requests[$schema['requestType']] = [
            'description' => $schema['requestType'] . ' Request',
            'content' => [
                'application/json' => [ 'schema' => ['$ref' => '#/components/schemas/' . $schema['requestType']] ],
                'application/xml' => [ 'schema' => ['$ref' => '#/components/schemas/' . $schema['requestType']] ],
            ]
        ];
    }

    $responses = [
        'SoapResponse' => [
            'description' => 'SOAP Response',
            'content' => [
                'application/json' => [ 'schema' => ['$ref' => '#/components/schemas/SoapResponse'] ],
                'application/xml' => [ 'schema' => ['$ref' => '#/components/schemas/SoapResponse'] ],
            ]
        ]
    ];
    foreach ($functions as $fn) {
        $schema = parseFunctionSignature($fn);
        $responses[$schema['responseType']] = [
            'description' => $schema['responseType'] . ' Response',
            'content' => [
                'application/json' => [ 'schema' => ['$ref' => '#/components/schemas/' . $schema['responseType']] ],
                'application/xml' => [ 'schema' => ['$ref' => '#/components/schemas/' . $schema['responseType']] ],
            ]
        ];
    }

    $schemas = array_merge([
        'SoapResponse' => [
            'type' => 'object',
            'properties' => [ 'resource' => [ 'type' => 'array', 'items' => ['type' => 'object'] ]]
        ]
    ], $types);

    return [
        'openapi' => '3.0.3',
        // Marker placed near top for easy visual confirmation
        'x-dfsoap' => [ 'variant' => 'fallback', 'version' => '0.1.0-dev', 'build' => date('c'), 'marker' => 'dfsoap-local' ],
        'info' => [
            'title' => 'df-soap OpenAPI from WSDL',
            'version' => '1.0.0'
        ],
        'paths' => $paths,
        'components' => [ 'requestBodies' => $requests, 'responses' => $responses, 'schemas' => $schemas ]
    ];
}

// Routing: simple form + action=call to invoke
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

$defaultWsdl = file_exists(__DIR__ . '/test_wsdl.xml') ? __DIR__ . '/test_wsdl.xml' : '';
$wsdl = $_GET['wsdl'] ?? ($_POST['wsdl'] ?? $defaultWsdl);
$optionsJson = $_GET['options'] ?? ($_POST['options'] ?? '{"trace": true}');
$headersJson = $_GET['headers'] ?? ($_POST['headers'] ?? '[]');
$functionName = $_GET['function'] ?? ($_POST['function'] ?? '');
$payloadJson = $_POST['payload'] ?? '';
$wsseUser = $_POST['wsse_user'] ?? '';
$wssePass = $_POST['wsse_pass'] ?? '';
$wsseToken = $_POST['wsse_token'] ?? '';
$overrideXml = $_POST['override_request_xml'] ?? '';

$errors = [];
$functions = [];
$last = [
    'request' => '',
    'request_headers' => '',
    'response' => '',
    'response_headers' => '',
];
$result = null;
$openApiDoc = null;

// If we previously handled a POST (action=call), hydrate display state from a one-time session flash
if ($method === 'GET' && isset($_SESSION['sim_flash_last'])) {
    $last = is_array($_SESSION['sim_flash_last']) ? $_SESSION['sim_flash_last'] : $last;
    $result = $_SESSION['sim_flash_result'] ?? $result;
    $errors = isset($_SESSION['sim_flash_errors']) && is_array($_SESSION['sim_flash_errors']) ? $_SESSION['sim_flash_errors'] : $errors;
    $payloadJson = $_SESSION['sim_flash_payload'] ?? $payloadJson;
    unset($_SESSION['sim_flash_last'], $_SESSION['sim_flash_result'], $_SESSION['sim_flash_errors'], $_SESSION['sim_flash_payload']);
}

// Try to load functions if WSDL provided
if ($wsdl) {
    // Save current selections for mock calls
    $_SESSION['sim_wsdl'] = $wsdl;
    $_SESSION['sim_options'] = $optionsJson;
    $_SESSION['sim_headers'] = $headersJson;
    $_SESSION['sim_wsse_user'] = $wsseUser;
    $_SESSION['sim_wsse_pass'] = $wssePass;
    $_SESSION['sim_wsse_token'] = $wsseToken;
    try {
        $opts = read_json_array($optionsJson);
        if (!isset($opts['trace'])) { $opts['trace'] = true; }
        $client = new InlineSoapClient($wsdl, $opts);
        $client->setOverrideXml($overrideXml);
        // Optional WSSE header
        if ($wsseUser !== '' && $wssePass !== '') {
            $wsse = new \DreamFactory\Core\Soap\Components\WsseAuthHeader($wsseUser, $wssePass, $wsseToken !== '' ? $wsseToken : null);
            $client->__setSoapHeaders([$wsse]);
        }
        // Generic headers
        $genericHeaders = read_json_array($headersJson);
        if (!empty($genericHeaders) && is_array($genericHeaders)) {
            $soapHeaders = [];
            foreach ($genericHeaders as $hdr) {
                $ns = $hdr['namespace'] ?? null; $name = $hdr['name'] ?? null; $data = $hdr['data'] ?? null;
                if ($ns && $name && $data !== null) {
                    $must = (bool)($hdr['mustunderstand'] ?? false); $actor = $hdr['actor'] ?? null;
                    $soapHeaders[] = new \SoapHeader($ns, $name, $data, $must, $actor);
                }
            }
            if (!empty($soapHeaders)) { $client->__setSoapHeaders($soapHeaders); }
        }
        $functions = $client->__getFunctions();
        // Sort operations by function name for a friendlier picker
        $functions_sorted = $functions;
        usort($functions_sorted, function($a, $b){
            $na = parseFunctionSignature($a)['name'] ?? $a;
            $nb = parseFunctionSignature($b)['name'] ?? $b;
            return strcasecmp($na, $nb);
        });
        // Build OpenAPI doc: prefer Soap.php export if available, fallback to local builder
        $openApiDoc = null;
        try {
            if (class_exists('DreamFactory\\Core\\Soap\\Services\\Soap')) {
                // Subclass Soap to expose protected API doc builders
                if (!class_exists('SoapDocExport')) {
                    class SoapDocExport extends \DreamFactory\Core\Soap\Services\Soap {
                        private function buildExampleFromFields($fields){
                            if (empty($fields) || !is_array($fields)) return new \stdClass();
                            $out = [];
                            foreach ($fields as $name => $field) {
                                // Field may be a $ref array or a primitive descriptor
                                if (is_array($field)) {
                                    if (isset($field['$ref'])) {
                                        // components schema ref name
                                        $ref = $field['$ref'];
                                        $refName = substr($ref, strrpos($ref, '/')+1);
                                        // recurse by looking up schema structure from getTypes()
                                        $types = $this->getTypes();
                                        $sub = $types[$refName] ?? null;
                                        $out[$name] = $this->buildExampleFromFields(($sub['properties'] ?? $sub) ?: []);
                                    } elseif (($field['type'] ?? null) === 'object') {
                                        $out[$name] = $this->buildExampleFromFields($field['properties'] ?? []);
                                    } elseif (($field['type'] ?? null) === 'array') {
                                        $arrItem = $this->buildExampleFromFields([ 'item' => ($field['items'] ?? []) ]);
                                        $out[$name] = [ $arrItem['item'] ?? new \stdClass() ];
                                    } else {
                                        // primitive
                                        $t = $field['type'] ?? 'string';
                                        $out[$name] = ($t === 'integer' || $t === 'number') ? 0 : (($t === 'boolean') ? false : '');
                                    }
                                } else {
                                    $out[$name] = '';
                                }
                            }
                            return $out;
                        }
                        public function exportOpenApi(): array {
                            $paths = $this->getApiDocPaths();
                            $components = [
                                'requestBodies' => $this->getApiDocRequests(),
                                'responses' => $this->getApiDocResponses(),
                                'schemas' => $this->getApiDocSchemas(),
                            ];
                            // Vendor extension: provide per-function example payloads derived from Soap.php structures
                            $examples = [];
                            foreach ($this->getFunctions() as $fn) {
                                $root = $fn->requestType;
                                $fields = $fn->requestFields; // object properties for request
                                $examples[$fn->name] = [ $root => $this->buildExampleFromFields($fields ?: []) ];
                            }
                            return [
                                'openapi' => '3.0.3',
                                'info' => [ 'title' => 'df-soap OpenAPI from WSDL (Soap.php)', 'version' => '1.0.0' ],
                                'paths' => $paths,
                                'components' => $components,
                                'x-dfExamples' => $examples,
                            ];
                        }
                    }
                }
                $settings = [
                    'name' => 'soap',
                    'config' => [
                        'wsdl' => $wsdl,
                        'options' => $opts,
                        // pass headers if any; Soap constructor will map
                        'headers' => read_json_array($headersJson),
                    ],
                ];
                $svc = new SoapDocExport($settings);
                $openApiDoc = $svc->exportOpenApi();
            }
        } catch (\Throwable $e) {
            // ignore and fallback
        }
        if ($openApiDoc === null) {
            $openApiDoc = buildOpenApiFromWsdl($client, 'soap');
        }

        if ($action === 'call' && $functionName !== '') {
            $payload = read_json_array($payloadJson);
            try {
                // DF REST semantics: POST with JSON body, or GET with query params
                $callArg = empty($payload) ? read_json_array($payloadJson) : $payload;
                // Unwrap common roots and wrap using accurate wrapper element name from Soap.php metadata
                if (is_array($callArg)) {
                    // If payload is { "FunctionName": { ... } } unwrap it
                    $keys = array_keys($callArg);
                    if (count($keys) === 1 && strcasecmp($keys[0], $functionName) === 0 && is_array($callArg[$keys[0]] ?? null)) {
                        $callArg = $callArg[$keys[0]];
                    }
                    // Resolve wrapper element key (e.g., addLogInput) from SoapDocExport if available
                    $wrapperKey = null;
                    try {
                        if (class_exists('SoapDocExport')) {
                            $settings2 = [ 'name' => 'soap', 'config' => [ 'wsdl' => $wsdl, 'options' => $opts ] ];
                            $svc2 = new SoapDocExport($settings2);
                            foreach ($svc2->getFunctions() as $fn) {
                                if (strcasecmp($fn->name, $functionName) === 0) {
                                    if (is_array($fn->requestFields) && count($fn->requestFields) === 1) {
                                        $wrapperKey = array_keys($fn->requestFields)[0];
                                    }
                                    break;
                                }
                            }
                        }
                    } catch (\Throwable $ignore) {}
                    if (!$wrapperKey) { $wrapperKey = lcfirst((string)$functionName) . 'Input'; }
                    // If payload is the inner object (fields only), wrap under wrapperKey
                    $hasParamKey = is_array($callArg) && array_key_exists($wrapperKey, $callArg);
                    if (!$hasParamKey) {
                        $callArg = [ $wrapperKey => $callArg ];
                    }
                }
                // If WSDL operation is document/literal wrapped with a single $parameters arg,
                // PHP SoapClient expects the payload under key 'parameters'
                try {
                    $needsWrapper = false;
                    foreach ($client->__getFunctions() as $sig) {
                        $info = parseFunctionSignature($sig);
                        if (strcasecmp($info['name'] ?? '', $functionName) === 0) {
                            if (preg_match('/\(\$parameters\)/i', $sig)) { $needsWrapper = true; }
                            break;
                        }
                    }
                    if ($needsWrapper && is_array($callArg) && !array_key_exists('parameters', $callArg)) {
                        $callArg = ['parameters' => $callArg];
                    }
                } catch (\Throwable $ignore) {}
                $response = $client->$functionName($callArg);
                $result = json_decode(json_encode($response, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
            } catch (Throwable $e) {
                // Retry alternates if the first attempt failed
                $errMsg = $e->getMessage();
                $didRetry = false;
                try {
                    // 1) Toggle 'parameters'
                    if (is_array($callArg)) {
                        $alt = array_key_exists('parameters', $callArg) ? $callArg['parameters'] : ['parameters' => $callArg];
                        $response = $client->$functionName($alt);
                        $result = json_decode(json_encode($response, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
                        $didRetry = true; $errMsg = '';
                    }
                } catch (\Throwable $e2) { $errMsg = $e2->getMessage(); }
                if (!$didRetry) {
                    try {
                        // 2) Wrap under operation name
                        if (is_array($callArg)) {
                            $inner = array_key_exists('parameters', $callArg) ? $callArg['parameters'] : $callArg;
                            $alt2 = [ $functionName => $inner ];
                            $response = $client->$functionName($alt2);
                            $result = json_decode(json_encode($response, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
                            $didRetry = true; $errMsg = '';
                        }
                    } catch (\Throwable $e3) { $errMsg = $e3->getMessage(); }
                }
                if (!$didRetry && $errMsg) { $errors[] = $errMsg; }
            }
            // Capture raw SOAP frames
            try { $last['request'] = (string)$client->__getLastRequest(); } catch (Throwable $e) {}
            try { $last['request_headers'] = (string)$client->__getLastRequestHeaders(); } catch (Throwable $e) {}
            try { $last['response'] = (string)$client->__getLastResponse(); } catch (Throwable $e) {}
            try { $last['response_headers'] = (string)$client->__getLastResponseHeaders(); } catch (Throwable $e) {}

            // If this is an AJAX request, return JSON and do not refresh the page
            $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);
            if ($isAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'last'   => $last,
                    'result' => $result,
                    'errors' => $errors,
                ], JSON_UNESCAPED_SLASHES);
                exit;
            }

            // Post/Redirect/Get to prevent browser resubmission warning on refresh (non-AJAX only)
            if ($method === 'POST') {
                $_SESSION['sim_flash_last'] = $last;
                $_SESSION['sim_flash_result'] = $result;
                $_SESSION['sim_flash_errors'] = $errors;
                $_SESSION['sim_flash_payload'] = $payloadJson;
                $qs = http_build_query([
                    'wsdl' => $wsdl,
                    'function' => $functionName,
                    'options' => $optionsJson,
                    'headers' => $headersJson,
                ]);
                $self = $_SERVER['PHP_SELF'] ?? 'simulator.php';
                header('Location: ' . $self . ($qs ? ('?' . $qs) : ''), true, 303);
                exit;
            }
        }

        // Live preview API: return generated SOAP request XML without sending
        if ($action === 'preview' && $functionName !== '') {
            header('Content-Type: application/json');
            try {
                $preview = new PreviewSoapClient($wsdl, $opts);
                // Mirror headers configuration
                if ($wsseUser !== '' && $wssePass !== '') {
                    $wsse = new \DreamFactory\Core\Soap\Components\WsseAuthHeader($wsseUser, $wssePass, $wsseToken !== '' ? $wsseToken : null);
                    $preview->__setSoapHeaders([$wsse]);
                }
                $genericHeaders = read_json_array($headersJson);
                if (!empty($genericHeaders) && is_array($genericHeaders)) {
                    $soapHeaders = [];
                    foreach ($genericHeaders as $hdr) {
                        $ns = $hdr['namespace'] ?? null; $name = $hdr['name'] ?? null; $data = $hdr['data'] ?? null;
                        if ($ns && $name && $data !== null) {
                            $must = (bool)($hdr['mustunderstand'] ?? false); $actor = $hdr['actor'] ?? null;
                            $soapHeaders[] = new \SoapHeader($ns, $name, $data, $must, $actor);
                        }
                    }
                    if (!empty($soapHeaders)) { $preview->__setSoapHeaders($soapHeaders); }
                }
                $payload = read_json_array($payloadJson);
                $callArg = empty($payload) ? [] : $payload;
                // Unwrap and normalize as in the invoke path
                if (is_array($callArg)) {
                    $keys = array_keys($callArg);
                    if (count($keys) === 1 && strcasecmp($keys[0], $functionName) === 0 && is_array($callArg[$keys[0]] ?? null)) {
                        $callArg = $callArg[$keys[0]];
                    }
                    $wrapperKey = null;
                    try {
                        if (class_exists('SoapDocExport')) {
                            $settings2 = [ 'name' => 'soap', 'config' => [ 'wsdl' => $wsdl, 'options' => $opts ] ];
                            $svc2 = new SoapDocExport($settings2);
                            foreach ($svc2->getFunctions() as $fn) {
                                if (strcasecmp($fn->name, $functionName) === 0) {
                                    if (is_array($fn->requestFields) && count($fn->requestFields) === 1) {
                                        $wrapperKey = array_keys($fn->requestFields)[0];
                                    }
                                    break;
                                }
                            }
                        }
                    } catch (\Throwable $ignore) {}
                    if (!$wrapperKey) { $wrapperKey = lcfirst((string)$functionName) . 'Input'; }
                    $hasParamKey = is_array($callArg) && array_key_exists($wrapperKey, $callArg);
                    if (!$hasParamKey) {
                        $callArg = [ $wrapperKey => $callArg ];
                    }
                }
                // Mirror wrapper semantics as above for preview
                try {
                    $needsWrapper = false;
                    foreach ($preview->__getFunctions() as $sig) {
                        $info = parseFunctionSignature($sig);
                        if (strcasecmp($info['name'] ?? '', $functionName) === 0) {
                            if (preg_match('/\(\$parameters\)/i', $sig)) { $needsWrapper = true; }
                            break;
                        }
                    }
                    if ($needsWrapper && is_array($callArg) && !array_key_exists('parameters', $callArg)) {
                        $callArg = ['parameters' => $callArg];
                    }
                } catch (\Throwable $ignore) {}
                try { $preview->$functionName($callArg); } catch (\Throwable $ignore) {}
                $xml = (string)($preview->capturedXml ?? '');
                // Fallback: if body looks empty or missing the wrapper element, try alternates (preview-only)
                try {
                    $expected = isset($wrapperKey) && is_string($wrapperKey) ? $wrapperKey : null;
                    $missingExpected = ($expected && $xml && (strpos($xml, '<'.$expected.'>') === false) && (strpos($xml, '<'.$expected.' ') === false));
                    $looksEmpty = ($xml && preg_match('/<([A-Za-z0-9_:]+)\s*[^>]*>\s*<\/\1>/', $xml));
                    if (($missingExpected || $looksEmpty) && is_array($callArg)) {
                        $alt = $callArg;
                        if (array_key_exists('parameters', $alt)) { $alt = $alt['parameters']; } else { $alt = ['parameters' => $alt]; }
                        $preview->capturedXml = null;
                        try { $preview->$functionName($alt); } catch (\Throwable $ignore2) {}
                        $altXml = (string)($preview->capturedXml ?? '');
                        if ($altXml && (!$xml || strlen($altXml) > strlen($xml))) { $xml = $altXml; }
                        // 2) Try wrapping under operation name
                        if ($xml && ($missingExpected || $looksEmpty)) {
                            $inner = array_key_exists('parameters', $callArg) ? $callArg['parameters'] : $callArg;
                            $alt2 = [ $functionName => $inner ];
                            $preview->capturedXml = null;
                            try { $preview->$functionName($alt2); } catch (\Throwable $ignore4) {}
                            $alt2Xml = (string)($preview->capturedXml ?? '');
                            if ($alt2Xml && (!$xml || strlen($alt2Xml) > strlen($xml))) { $xml = $alt2Xml; }
                        }
                    }
                } catch (\Throwable $ignore3) {}
                echo json_encode(['request_xml' => $xml]);
            } catch (\Throwable $e) {
                echo json_encode(['request_xml' => '', 'error' => $e->getMessage()]);
            }
            exit;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// Local-only debug endpoints
if (0 === strpos($_SERVER['REQUEST_URI'] ?? '', '/__debug/')) {
    $ra = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ra, ['127.0.0.1', '::1'])) {
        header('HTTP/1.1 404 Not Found');
        exit;
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    // Ensure SoapDocExport exists
    if (class_exists('DreamFactory\\Core\\Soap\\Services\\Soap') && !class_exists('SoapDocExport')) {
        class SoapDocExport extends \DreamFactory\Core\Soap\Services\Soap {
            public function exportOpenApi(): array {
                $paths = $this->getApiDocPaths();
                $components = [
                    'requestBodies' => $this->getApiDocRequests(),
                    'responses'     => $this->getApiDocResponses(),
                    'schemas'       => $this->getApiDocSchemas(),
                ];
                return [
                    'openapi'    => '3.0.3',
                    // Marker placed near top for easy visual confirmation
                    'x-dfsoap'   => [ 'variant' => 'Soap.php', 'version' => '0.1.0-dev', 'build' => date('c'), 'marker' => 'dfsoap-local' ],
                    'info'       => [ 'title' => 'df-soap OpenAPI from WSDL (Soap.php)', 'version' => '1.0.0' ],
                    'paths'      => $paths,
                    'components' => $components,
                ];
            }
        }
    }
    // Build defaults
    $defaultWsdl = file_exists(__DIR__ . '/test_wsdl.xml') ? __DIR__ . '/test_wsdl.xml' : '';
    $wsdlParam = $_GET['wsdl'] ?? $defaultWsdl;
    $opts = json_decode($_GET['options'] ?? '{}', true) ?: [];
    if (!isset($opts['trace'])) { $opts['trace'] = true; }

    if ($path === '/__debug/openapi.json') {
        header('Content-Type: application/json; charset=UTF-8');
        $doc = null;
        try {
            if (class_exists('SoapDocExport')) {
                $settings = [ 'name' => 'soap', 'config' => [ 'wsdl' => $wsdlParam, 'options' => $opts ] ];
                $svc = new SoapDocExport($settings);
                $doc = $svc->exportOpenApi();
            }
        } catch (\Throwable $ignore) {}
        if ($doc === null) {
            try {
                $client = new InlineSoapClient($wsdlParam, $opts);
                $doc = buildOpenApiFromWsdl($client, 'soap');
            } catch (\Throwable $e) {
                echo json_encode(['error' => $e->getMessage()]);
                exit;
            }
        }
        echo json_encode($doc, JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($path === '/__debug/compare') {
        header('Content-Type: application/json; charset=UTF-8');
        $out = [ 'wsdl' => $wsdlParam, 'summary' => [], 'details' => [] ];
        // Convert PHP errors to exceptions to surface as JSON
        $prevHandler = set_error_handler(function($severity, $message, $file, $line){
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            // Helper: parse __getFunctions signature -> ['name'=>..., 'params'=>['p1','p2',...]]
            $parseSig = function(string $sig): array {
                $info = parseFunctionSignature($sig); // ['name','requestType','responseType']
                $params = [];
                if (preg_match('/\((.*)\)/', $sig, $m)) {
                    $paramStr = trim($m[1] ?? '');
                    if ($paramStr !== '') {
                        foreach (array_map('trim', explode(',', $paramStr)) as $part) {
                            if (preg_match('/\\$([A-Za-z_][A-Za-z0-9_]*)/', $part, $pm)) {
                                $params[] = $pm[1];
                            }
                        }
                    }
                }
                return ['name' => $info['name'] ?? '', 'params' => $params, 'requestType' => $info['requestType'] ?? null];
            };
            $deep = false;
            $deepParam = $_GET['deep'] ?? '0';
            if ($deepParam === '1' || $deepParam === 'true' || $deepParam === 'yes') { $deep = true; }
            // Ground truth from raw WSDL via SoapClient
            $client = new InlineSoapClient($wsdlParam, $opts);
            $rawFns = $client->__getFunctions();
            $wsdlMap = [];
            foreach ($rawFns as $sig) {
                $p = $parseSig($sig);
                if (!empty($p['name'])) {
                    $wsdlMap[strtolower($p['name'])] = [ 'name' => $p['name'], 'params' => ($p['params'] ?? []), 'requestType' => ($p['requestType'] ?? null) ];
                }
            }
            // Soap.php view
            $apiMap = [];
            $types = [];
            if (class_exists('SoapDocExport')) {
                $settings = [ 'name' => 'soap', 'config' => [ 'wsdl' => $wsdlParam, 'options' => $opts ] ];
                $svc = new SoapDocExport($settings);
                foreach ($svc->getFunctions() as $fn) {
                    $props = [];
                    $rf = $fn->requestFields;
                    if (is_array($rf)) {
                        // Expect ['type'=>'object','properties'=>[...]]
                        if (isset($rf['properties']) && is_array($rf['properties'])) {
                            $props = array_keys($rf['properties']);
                        }
                    }
                    $apiMap[strtolower($fn->name)] = [ 'name' => $fn->name, 'params' => $props, 'requestType' => $fn->requestType ];
                }
                $types = $svc->getTypes();
            }
            // WSDL types (for deep compare)
            $wsdlTypes = buildTypesFromSoap($client);
            // Compare
            $wsdlOnly = array_values(array_diff(array_keys($wsdlMap), array_keys($apiMap)));
            $apiOnly = array_values(array_diff(array_keys($apiMap), array_keys($wsdlMap)));
            $both = array_values(array_intersect(array_keys($wsdlMap), array_keys($apiMap)));
            // Optional single-function filter
            $fnFilter = $_GET['fn'] ?? '';
            if ($fnFilter !== '') {
                $key = strtolower($fnFilter);
                $both = array_values(array_filter($both, function($k) use ($key){ return $k === $key; }));
            }
            $paramDiffs = [];
            foreach ($both as $k) {
                $w = $wsdlMap[$k]['params'];
                $a = $apiMap[$k]['params'];
                $miss = [];
                $extra = [];
                $mode = 'shallow';
                if ($deep) {
                    $mode = 'deep';
                    // Resolve wrapper type properties from WSDL types
                    $wrapper = $wsdlMap[$k]['requestType'] ?? null;
                    $wsdlProps = [];
                    if ($wrapper && isset($wsdlTypes[$wrapper])) {
                        $schema = $wsdlTypes[$wrapper];
                        // Dereference up to 3 levels if needed
                        $guard = 0; $visited = [];
                        while (is_array($schema) && isset($schema['$ref']) && $guard < 3) {
                            $ref = $schema['$ref'];
                            $refName = str_replace('#/components/schemas/', '', (string)$ref);
                            if (isset($visited[$refName])) { break; }
                            $visited[$refName] = true; $guard++;
                            $schema = $wsdlTypes[$refName] ?? $schema;
                        }
                        if (is_array($schema) && isset($schema['type']) && $schema['type'] === 'object' && isset($schema['properties']) && is_array($schema['properties'])) {
                            $wsdlProps = array_keys($schema['properties']);
                        }
                    }
                    $resolvedFrom = null; $unresolved = false;
                    if (empty($wsdlProps)) {
                        // Try heuristic: search any object type with properties whose name contains function or wrapper
                        $fnName = $wsdlMap[$k]['name'] ?? '';
                        $needleA = strtolower((string)$fnName);
                        $needleB = strtolower((string)$wrapper);
                        foreach ($wsdlTypes as $tName => $tSchema) {
                            if (!is_array($tSchema) || !isset($tSchema['type']) || $tSchema['type'] !== 'object' || !isset($tSchema['properties']) || !is_array($tSchema['properties'])) { continue; }
                            $lname = strtolower($tName);
                            if (($needleA && strpos($lname, $needleA) !== false) || ($needleB && strpos($lname, $needleB) !== false)) {
                                $wsdlProps = array_keys($tSchema['properties']);
                                $resolvedFrom = $tName;
                                break;
                            }
                        }
                        // DOM WSDL fallback: locate element/complexType and extract sequence elements
                        if (empty($wsdlProps)) {
                            try {
                                $wsdlXml = @file_get_contents($wsdlParam);
                                if ($wsdlXml !== false && is_string($wsdlXml) && trim($wsdlXml) !== '') {
                                    $dom = new \DOMDocument();
                                    $prev = libxml_use_internal_errors(true);
                                    if (@$dom->loadXML($wsdlXml)) {
                                        $xp = new \DOMXPath($dom);
                                        // Build safe XPath string literal
                                        $xpathLiteral = function(string $s): string {
                                            if (strpos($s, "'") === false) { return "'" . $s . "'"; }
                                            if (strpos($s, '"') === false) { return '"' . $s . '"'; }
                                            // contains both ' and ", use concat pieces
                                            $parts = preg_split("/(')/", $s, -1, PREG_SPLIT_DELIM_CAPTURE);
                                            $out = [];
                                            foreach ($parts as $part) {
                                                if ($part === "'") { $out[] = '"' . "'" . '"'; }
                                                elseif ($part !== '') { $out[] = "'" . $part . "'"; }
                                            }
                                            return 'concat(' . implode(',', $out) . ')';
                                        };
                                        // Helper to collect element(@name) under complexType/sequence
                                        $collectProps = function(\DOMNode $ctx) use ($xp): array {
                                            $props = [];
                                            foreach ($xp->query(".//*[local-name()='sequence']/*[local-name()='element']", $ctx) as $el) {
                                                /** @var \DOMElement $el */
                                                $n = $el->getAttribute('name');
                                                if ($n !== '') { $props[] = $n; }
                                            }
                                            return array_values(array_unique($props));
                                        };
                                        // Resolve complexType by name and include extension base chain (guarded)
                                        $visitedTypes = [];
                                        $propsFromTypeName = null;
                                        $propsFromTypeName = function(string $typeName) use ($xp, $collectProps, &$propsFromTypeName, &$visitedTypes): array {
                                            if (isset($visitedTypes[$typeName])) { return []; }
                                            $visitedTypes[$typeName] = true;
                                            $out = [];
                                            $typesFound = $xp->query("//*[local-name()='complexType' and @name='" . addslashes($typeName) . "']");
                                            if ($typesFound && $typesFound->length) {
                                                /** @var \DOMElement $ct */
                                                $ct = $typesFound->item(0);
                                                $out = array_values(array_unique(array_merge($out, $collectProps($ct))));
                                                // Follow complexContent/extension base
                                                $ext = $xp->query(".//*[local-name()='complexContent']/*[local-name()='extension']", $ct);
                                                if ($ext && $ext->length) {
                                                    /** @var \DOMElement $extEl */
                                                    $extEl = $ext->item(0);
                                                    $base = $extEl->getAttribute('base');
                                                    if ($base !== '') {
                                                        $baseLocal = ($p = strpos($base, ':')) !== false ? substr($base, $p + 1) : $base;
                                                        $out = array_values(array_unique(array_merge($out, $collectProps($extEl), $propsFromTypeName($baseLocal))));
                                                    } else {
                                                        $out = array_values(array_unique(array_merge($out, $collectProps($extEl))));
                                                    }
                                                }
                                            }
                                            return $out;
                                        };
                                        // Find element matching wrapper or function
                                        $candidates = [];
                                        if ($wrapper) { $candidates[] = $wrapper; }
                                        if ($fnName && (! $wrapper || strtolower($fnName) !== strtolower($wrapper))) { $candidates[] = $fnName; }
                                        foreach ($candidates as $cand) {
                                            $nodes = $xp->query("//*[local-name()='element' and @name=" . $xpathLiteral($cand) . "]");
                                            if ($nodes && $nodes->length) {
                                                /** @var \DOMElement $elem */
                                                $elem = $nodes->item(0);
                                                // Inline complexType
                                                $props = $collectProps($elem);
                                                if (!empty($props)) { $wsdlProps = $props; $resolvedFrom = $cand; break; }
                                                // type reference
                                                $typeAttr = $elem->getAttribute('type');
                                                if ($typeAttr !== '') {
                                                    $localType = ($p = strpos($typeAttr, ':')) !== false ? substr($typeAttr, $p + 1) : $typeAttr;
                                                    $props = $propsFromTypeName($localType);
                                                    if (!empty($props)) { $wsdlProps = $props; $resolvedFrom = $localType; break; }
                                                }
                                            }
                                        }
                                    }
                                    libxml_clear_errors();
                                    libxml_use_internal_errors($prev);
                                }
                            } catch (\Throwable $ignored) {}
                        }
                        if (empty($wsdlProps)) { $unresolved = true; }
                    }
                    $miss = array_values(array_diff($wsdlProps, $a));
                    $extra = array_values(array_diff($a, $wsdlProps));
                    if ($miss || $extra || $unresolved) {
                        $paramDiffs[] = [
                            'function' => $wsdlMap[$k]['name'],
                            'mode' => $mode,
                            'wsdlWrapper' => $wrapper,
                            'wsdlResolvedFrom' => $resolvedFrom,
                            'wsdlProps' => $wsdlProps,
                            'apiProps' => $a,
                            'missingInApi' => $miss,
                            'extraInApi' => $extra,
                            'unresolved' => $unresolved,
                            'requestType' => $apiMap[$k]['requestType'] ?? null,
                        ];
                    }
                } else {
                    // If WSDL uses single wrapper parameter like "$parameters", skip param-level comparison
                    if (count($w) <= 1) { continue; }
                    $miss = array_values(array_diff($w, $a));
                    $extra = array_values(array_diff($a, $w));
                    if ($miss || $extra) {
                        $paramDiffs[] = [
                            'function' => $wsdlMap[$k]['name'],
                            'mode' => $mode,
                            'wsdlParams' => $w,
                            'apiParams' => $a,
                            'missingInApi' => $miss,
                            'extraInApi' => $extra,
                            'requestType' => $apiMap[$k]['requestType'] ?? null,
                        ];
                    }
                }
            }
            $out['summary'] = [
                'wsdlFunctions' => count($wsdlMap),
                'apiFunctions'  => count($apiMap),
                'wsdlOnly'      => count($wsdlOnly),
                'apiOnly'       => count($apiOnly),
                'paramMismatches' => count($paramDiffs),
            ];
            $out['details'] = [
                'wsdlOnly' => array_map(function($k) use ($wsdlMap){ return $wsdlMap[$k]['name'] ?? $k; }, $wsdlOnly),
                'apiOnly'  => array_map(function($k) use ($apiMap){ return $apiMap[$k]['name'] ?? $k; }, $apiOnly),
                'paramMismatches' => $paramDiffs,
            ];
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        } finally {
            if ($prevHandler !== null) { restore_error_handler(); }
        }
        echo json_encode($out, JSON_UNESCAPED_SLASHES);
        exit;
    }
    // Unknown debug path
    header('HTTP/1.1 404 Not Found');
    exit;
}

// Mock REST endpoint for Swagger UI: /mock/{function}
if (0 === strpos($_SERVER['REQUEST_URI'] ?? '', '/mock')) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: *');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }
    header('Content-Type: application/json');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    // Remove /mock prefix and any leading slashes. If path contains additional segments, take first segment only.
    $rest = trim(substr($path, strlen('/mock')), '/');
    $fn = ($rest === '') ? '' : explode('/', $rest, 2)[0];
    $wsdlSel = $_SESSION['sim_wsdl'] ?? '';
    if (!$wsdlSel || !$fn) {
        http_response_code(400);
        echo json_encode(['error' => 'WSDL not selected or function missing']);
        exit;
    }
    $opts = json_decode($_SESSION['sim_options'] ?? '{}', true) ?: [];
    if (!isset($opts['trace'])) { $opts['trace'] = true; }
    $headersCfg = json_decode($_SESSION['sim_headers'] ?? '[]', true) ?: [];
    $body = file_get_contents('php://input');
    $payload = json_decode($body ?: '[]', true) ?: [];
    try {
        $mockClient = new InlineSoapClient($wsdlSel, $opts);
        // WSSE
        if (!empty($_SESSION['sim_wsse_user']) && !empty($_SESSION['sim_wsse_pass'])) {
            $wsse = new \DreamFactory\Core\Soap\Components\WsseAuthHeader($_SESSION['sim_wsse_user'], $_SESSION['sim_wsse_pass'], $_SESSION['sim_wsse_token'] ?: null);
            $mockClient->__setSoapHeaders([$wsse]);
        }
        // Generic headers
        if (!empty($headersCfg)) {
            $soapHeaders = [];
            foreach ($headersCfg as $hdr) {
                $ns = $hdr['namespace'] ?? null; $name = $hdr['name'] ?? null; $data = $hdr['data'] ?? null;
                if ($ns && $name && $data !== null) {
                    $must = (bool)($hdr['mustunderstand'] ?? false); $actor = $hdr['actor'] ?? null;
                    $soapHeaders[] = new \SoapHeader($ns, $name, $data, $must, $actor);
                }
            }
            if (!empty($soapHeaders)) { $mockClient->__setSoapHeaders($soapHeaders); }
        }
        $resp = $mockClient->$fn($payload);
        echo json_encode(json_decode(json_encode($resp, JSON_PARTIAL_OUTPUT_ON_ERROR), true));
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Serve local WSDL MHTML if requested
if ($action === 'wsdl_mhtml') {
    $mhtml = __DIR__ . '/wsdl.mhtml';
    if (is_file($mhtml)) {
        header('Content-Type: multipart/related');
        readfile($mhtml);
        exit;
    }
    header('HTTP/1.1 404 Not Found');
    echo 'wsdl.mhtml not found';
    exit;
}

// Build WSDL operation variant metadata to distinguish duplicates
$wsdlOps = [];
if ($wsdl) {
    try {
        $ctx = stream_context_create([ 'http' => ['timeout' => 3], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false] ]);
        $xmlRaw = @file_get_contents($wsdl, false, $ctx);
        if ($xmlRaw !== false) {
            $sx = @simplexml_load_string($xmlRaw);
            if ($sx) {
                $ns = $sx->getNamespaces(true);
                $wsdlNs = $ns['wsdl'] ?? 'http://schemas.xmlsoap.org/wsdl/';
                $soap11 = 'http://schemas.xmlsoap.org/wsdl/soap/';
                $soap12 = 'http://schemas.xmlsoap.org/wsdl/soap12/';
                $sx->registerXPathNamespace('w', $wsdlNs);
                $sx->registerXPathNamespace('s11', $soap11);
                $sx->registerXPathNamespace('s12', $soap12);
                // Map binding name -> soap version, style
                $bindings = [];
                foreach ($sx->xpath('//w:binding') as $b) {
                    $attrs = $b->attributes();
                    $bName = (string)($attrs['name'] ?? '');
                    $style = '';
                    $version = '';
                    if ($soap = $b->children($soap11)) { $style = (string)($soap->binding['style'] ?? ''); $version = '1.1'; }
                    if ($soap2 = $b->children($soap12)) { $style = (string)($soap2->binding['style'] ?? $style); $version = '1.2'; }
                    $ops = [];
                    foreach ($b->xpath('./w:operation') as $op) {
                        $name = (string)($op['name'] ?? '');
                        $action = '';
                        if ($oop = $op->children($soap11)) { $action = (string)($oop->operation['soapAction'] ?? ''); }
                        if (!$action && ($oop2 = $op->children($soap12))) { $action = (string)($oop2->operation['soapAction'] ?? ''); }
                        $ops[$name] = ['action' => $action];
                    }
                    $bindings[$bName] = ['version' => $version, 'style' => $style, 'ops' => $ops];
                }
                // Map port -> address and binding
                $ports = [];
                foreach ($sx->xpath('//w:service/w:port') as $p) {
                    $pAttrs = $p->attributes();
                    $pName = (string)($pAttrs['name'] ?? '');
                    $bindingQ = (string)($pAttrs['binding'] ?? '');
                    $bindingName = strpos($bindingQ, ':') !== false ? substr($bindingQ, strpos($bindingQ, ':')+1) : $bindingQ;
                    $addr = '';
                    if ($pc = $p->children($soap11)) { $addr = (string)($pc->address['location'] ?? ''); }
                    if (!$addr && ($pc2 = $p->children($soap12))) { $addr = (string)($pc2->address['location'] ?? ''); }
                    $ports[] = ['port' => $pName, 'binding' => $bindingName, 'address' => $addr];
                }
                // Build operation -> variants
                foreach ($bindings as $bName => $bInfo) {
                    $bindingPorts = array_values(array_filter($ports, fn($p) => $p['binding'] === $bName));
                    foreach ($bInfo['ops'] as $opName => $opInfo) {
                        $addr = $bindingPorts[0]['address'] ?? '';
                        $portName = $bindingPorts[0]['port'] ?? '';
                        $wsdlOps[$opName][] = [
                            'binding' => $bName,
                            'port' => $portName,
                            'address' => $addr,
                            'soapVersion' => $bInfo['version'] ?: '1.1',
                            'style' => $bInfo['style'] ?: '',
                            'soapAction' => $opInfo['action'] ?? ''
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) { /* ignore parse errors */ }
}

// Render simple UI with tabs
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>df-soap Simulator</title>
  <style>
    body { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji"; margin: 24px; color: #0d1117; background: #fff; }
    h1 { font-size: 20px; margin: 0 0 16px; }
    form { display: grid; grid-template-columns: 1fr; gap: 12px; max-width: 1200px; }
    label { font-weight: 600; }
    input[type=text], textarea, select { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d0d7de; border-radius: 6px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 13px; }
    textarea { min-height: 90px; }
    .row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
    .row-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
    .actions { display: flex; gap: 12px; }
    button { background: #0969da; color: #fff; border: none; padding: 10px 14px; border-radius: 6px; cursor: pointer; font-weight: 600; }
    button.secondary { background: #6e7781; }
    .panel { margin-top: 18px; padding: 12px; border: 1px solid #d0d7de; border-radius: 8px; }
    .panel h2 { font-size: 16px; margin: 0 0 8px; }
    pre { background: #0d1117; color: #c9d1d9; padding: 12px; border-radius: 6px; overflow: auto; max-height: 360px; }
    .panel.result { background: #d9f99d; border-color: #65a30d; }
    .errors { color: #b91c1c; font-weight: 600; }
    .muted { color: #57606a; }
    .tabs { display: flex; gap: 6px; margin: 16px 0; border-bottom: 1px solid #d0d7de; }
    .tab { padding: 8px 10px; border: 1px solid #d0d7de; border-bottom: none; border-radius: 6px 6px 0 0; cursor: pointer; background: #f6f8fa; }
    .tab.active { background: #fff; font-weight: 600; }
    .tab-panel { display: none; }
    .tab-panel.active { display: block; }
    @keyframes flash-bg { from { background: #fff6b3; } to { background: #ffffff; } }
    .flash { animation: flash-bg 0.6s ease-in-out; }
    button.success { background: #16a34a; color: #fff; }
    .rainbow-border { border-width: 2px; border-style: solid; border-image: linear-gradient(90deg, #ef4444, #f59e0b, #eab308, #22c55e, #06b6d4, #3b82f6, #8b5cf6) 1; }
    /* Flow indicator from JSON -> SOAP */
    .flow-row { display:flex; justify-content:center; align-items:center; margin: 4px 0 8px; }
    .flow-arrow { display:flex; align-items:center; gap:8px; color:#57606a; font-weight:600; }
    .flow-arrow svg { color:#6e7781; }

    /* Function list (custom combo) */
    .fn-list { border: 1px solid #d0d7de; border-radius: 8px; max-height: 280px; overflow: auto; padding: 4px; background:#fff; }
    .fn-item { display:flex; flex-direction:column; gap:4px; padding:8px; border-radius:6px; cursor:pointer; border:1px solid transparent; }
    .fn-item:hover { background:#f6f8fa; }
    .fn-item.active { border-color:#0969da; background:#eff6ff; }
    .fn-line { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .tok-op { font-weight:700; color:#0f766e; background:#ecfeff; border:1px solid #99f6e4; padding:2px 6px; border-radius:999px; }
    .tok-ret { color:#7c2d12; background:#ffedd5; border:1px solid #fdba74; padding:2px 6px; border-radius:999px; }
    .tok-param { color:#1e3a8a; background:#dbeafe; border:1px solid #93c5fd; padding:2px 6px; border-radius:999px; }
    .tok-sig { color:#6b7280; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size:12px; }
    .fn-empty { padding:10px; color:#6b7280; }

    /* Auto dark mode */
    @media (prefers-color-scheme: dark) {
      body { background:#0d1117; color:#c9d1d9; }
      .tab { background:#161b22; border-color:#30363d; color:#c9d1d9; }
      .tab.active { background:#0d1117; }
      .tabs { border-bottom-color:#30363d; }
      .panel { background:#0d1117; border-color:#30363d; }
      .panel.result { background:#0f2d18; border-color:#2ea043; }
      .muted { color:#8b949e; }
      pre { background:#0d1117; color:#c9d1d9; }
      input, textarea, select { background:#0d1117; color:#c9d1d9; border-color:#30363d; }
      .fn-list { background:#161b22; border-color:#30363d; }
      .fn-item:hover { background:#0b1020; }
      .fn-item.active { background:#0b1a36; border-color:#1f6feb; }
      .tok-op { color:#99f6e4; background:#042f2e; border-color:#115e59; }
      .tok-ret { color:#ffedd5; background:#451a03; border-color:#9a3412; }
      .tok-param { color:#93c5fd; background:#0b1730; border-color:#1d4ed8; }
      .rainbow-border { border-color: transparent; }
      button { background:#1f6feb; }
      button.secondary { background:#6e7681; }

      /* Swagger UI dark overrides */
      .swagger-ui, .swagger-ui .topbar { background:#0d1117; }
      .swagger-ui .info .title, .swagger-ui .info .base-url, .swagger-ui, .swagger-ui * { color:#c9d1d9; }
      .swagger-ui .opblock { background:#161b22; border-color:#30363d; }
      .swagger-ui .opblock .opblock-section-header { background:#0d1117; border-color:#30363d; }
      .swagger-ui .opblock-summary { background:#0d1117; border-color:#30363d; color:#c9d1d9; }
      .swagger-ui .opblock-summary-method { filter: brightness(0.9); }
      .swagger-ui .opblock-tag { color:#c9d1d9; background:#0d1117; border-color:#30363d; }
      .swagger-ui .scheme-container { background:#161b22; border-color:#30363d; }
      .swagger-ui .model, .swagger-ui .model-box, .swagger-ui .model-box-control, .swagger-ui .parameters-col_description { background:#0d1117; border-color:#30363d; color:#c9d1d9; }
      .swagger-ui table tbody tr td, .swagger-ui table thead tr th { border-color:#30363d; }
      .swagger-ui .response-col_status { color:#c9d1d9; }
      .swagger-ui .btn, .swagger-ui .btn.execute { background:#1f6feb; border-color:#1f6feb; color:#fff; }
      .swagger-ui .btn.authorize { background:#238636; border-color:#238636; color:#fff; }
      .swagger-ui .dialog-ux { background:#0d1117; border-color:#30363d; }
      .swagger-ui .dialog-ux .modal-ux { background:#161b22; border-color:#30363d; }
      .swagger-ui .opblock-description-wrapper, .swagger-ui .opblock-external-docs-wrapper, .swagger-ui .opblock-title_normal { color:#c9d1d9; }
      .swagger-ui .markdown code, .swagger-ui .prop-type { color:#a5d6ff; }
      .swagger-ui .parameter__name, .swagger-ui .parameter__type { color:#c9d1d9; }
      .swagger-ui .tab li { color:#c9d1d9; }
      .swagger-ui .tab li.active { border-color:#1f6feb; color:#c9d1d9; }
      .swagger-ui .copy-to-clipboard { filter: invert(0.85); }
      .swagger-ui .model-toggle { filter: invert(0.85); }
      .swagger-ui input[type="text"], .swagger-ui textarea, .swagger-ui select { background:#0d1117; color:#c9d1d9; border-color:#30363d; }
      .swagger-ui .checkbox input + label:before { border-color:#30363d; }
    }
    @media (prefers-color-scheme: dark) {
      body { background: #0b0f14; color: #e6edf3; }
      .tab { background: #0f1722; border-color: #30363d; color: #e6edf3; }
      .tab.active { background: #0b0f14; }
      .tabs { border-bottom-color: #30363d; }
      .panel { background: #0f1722; border-color: #30363d; }
      .panel h2 { color: #e6edf3; }
      .muted { color: #9aa4ae; }
      input[type="text"], textarea, select { background: #0b0f14; color: #e6edf3; border: 1px solid #30363d; }
      button { background: #1f6feb; color: #fff; }
      button.secondary { background: #57606a; color: #fff; }
      pre { background: #0d1117; color: #c9d1d9; }
      .panel.result { background: #1f2a12; border-color: #3f6212; }
      .flow-arrow { color:#9aa4ae; }
      .fn-list { background:#0b0f14; border-color:#30363d; }
      .fn-item:hover { background:#0f1722; }
      .fn-item.active { background:#10223a; border-color:#1f6feb; }
      .tok-op { color:#67e8f9; background:#042f2e; border-color:#155e75; }
      .tok-ret { color:#fdba74; background:#451a03; border-color:#7c2d12; }
      .tok-param { color:#93c5fd; background:#0a172a; border-color:#1e3a8a; }
      .tok-sig { color:#9aa4ae; }
    }
  </style>
  <script>
    function setWsdl(wsdl) {
      const u = new URL(window.location.href);
      u.searchParams.set('wsdl', wsdl);
      window.location.href = u.toString();
    }
    function switchTab(id) {
      document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
      document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
      document.getElementById('tab-'+id).classList.add('active');
      document.getElementById('panel-'+id).classList.add('active');
    }
    function copyResponseToOverride(){
      if (window.__lastResponse){
        if (window.__xmlEditor){
          window.__xmlEditor.setValue(window.__lastResponse || '', -1);
          window.__xmlTouched = true;
        } else {
          const ta = document.getElementById('override_xml_textarea');
          if (ta) ta.value = window.__lastResponse || '';
          const mount = document.getElementById('override_xml_editor');
          if (mount) mount.textContent = window.__lastResponse || '';
        }
        switchTab('builder');
      }
    }
    window.addEventListener('DOMContentLoaded', () => switchTab('builder'));
  </script>
  <!-- Swagger UI assets -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <!-- ACE editor for JSON payload -->
  <script src="https://cdn.jsdelivr.net/npm/ace-builds@1.33.2/src-min-noconflict/ace.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/ace-builds@1.33.2/src-min-noconflict/mode-json.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/ace-builds@1.33.2/src-min-noconflict/theme-github.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/ace-builds@1.33.2/src-min-noconflict/theme-github_dark.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/ace-builds@1.33.2/src-min-noconflict/mode-xml.js"></script>
  <!-- Highlight.js for pretty XML/JSON coloring: auto theme -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/highlight.js@11.9.0/styles/github.min.css" media="(prefers-color-scheme: light)">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/highlight.js@11.9.0/styles/github-dark.min.css" media="(prefers-color-scheme: dark)">
  <script src="https://cdn.jsdelivr.net/npm/highlight.js@11.9.0/lib/highlight.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/highlight.js@11.9.0/lib/languages/xml.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/highlight.js@11.9.0/lib/languages/json.min.js"></script>
  <!-- Ajv for JSON Schema validation -->
  <script src="https://cdn.jsdelivr.net/npm/ajv@8.17.1/dist/ajv7.min.js"></script>
</head>
<body>
  <h1>df-soap Simulator</h1>

  <script>
    // Expose last response to JS for quick copy-to-editor
    window.__lastResponse = <?php echo json_encode($last['response'] ?? '', JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
    window.__wsdlOps = <?php echo json_encode($wsdlOps, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
  </script>

  <?php if (!empty($errors)): ?>
    <div class="panel errors">
      <?php foreach ($errors as $e): ?>
        <div><?= h($e) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="tabs">
    <div id="tab-builder" class="tab" onclick="switchTab('builder')">Request & SOAP</div>
    <div id="tab-spec" class="tab" onclick="switchTab('spec')">OpenAPI</div>
    <div id="tab-wsdl" class="tab" onclick="switchTab('wsdl')">WSDL Viewer</div>
  </div>

  <div id="panel-builder" class="tab-panel">
  <form method="post" onsubmit="(function(e){
      // move ACE XML into hidden textarea
      const xmlEd = window.__xmlEditor; const xmlTa = document.getElementById('override_xml_textarea');
      if (xmlEd && xmlTa) xmlTa.value = xmlEd.getValue();
      // use AJAX to invoke without page refresh
      if (window.invokeSoapAjax) { e.preventDefault(); window.invokeSoapAjax(e); return false; }
    })(event);">
    <input type="hidden" name="action" value="call" />

    <label>WSDL location (file path or URL)</label>
    <input type="text" name="wsdl" value="<?= h($wsdl) ?>" placeholder="http(s)://... or C:\\path\\service.wsdl" />
    <div class="actions">
      <button type="button" class="secondary" onclick="setWsdl(document.querySelector('[name=wsdl]').value)">Load Functions</button>
    </div>

    <details>
      <summary>Advanced</summary>
      <div class="row-2" style="margin-top:8px">
        <div>
          <label>Options (JSON)</label>
          <textarea name="options" placeholder='{"trace": true}'><?= h($optionsJson) ?></textarea>
          <div class="muted">Any native SoapClient option. Set {"trace": true} to capture XML.</div>
        </div>
        <div>
          <label>Headers (JSON array, Generic)</label>
          <textarea name="headers" placeholder='[{"namespace":"urn:...","name":"Auth","data":{"k":"v"}}]'><?= h($headersJson) ?></textarea>
          <div class="muted">Generic SoapHeader entries.</div>
        </div>
      </div>
      <div class="row-2">
        <div>
          <label>WSSE Username (optional)</label>
          <input type="text" name="wsse_user" value="<?= h($wsseUser) ?>" />
        </div>
        <div>
          <label>WSSE Password (optional)</label>
          <input type="text" name="wsse_pass" value="<?= h($wssePass) ?>" />
        </div>
      </div>
      <div>
        <label>WSSE Username Token (optional)</label>
        <input type="text" name="wsse_token" value="<?= h($wsseToken) ?>" />
      </div>
    </details>

    <label style="display:flex;align-items:center;gap:8px">
      Function
      <span id="fn_count" class="muted" style="font-size:12px"></span>
    </label>
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:4px">
      <input id="fn_filter" type="text" placeholder="Filter functions..." style="flex:1" />
      <button type="button" class="secondary" id="fn_clear_btn" aria-label="Clear filter">Clear</button>
    </div>
    <select name="function" id="fn_select" onchange="window.autoTemplateFromSpec()" style="display:none" aria-hidden="true">
      <option value="">-- select --</option>
      <?php $__ops = isset($functions_sorted) ? $functions_sorted : (isset($functions)?$functions:[]); foreach ($__ops as $fn): $name = strstr(substr($fn, strpos($fn, ' ') + 1), '(', true); ?>
        <option title="<?= h($fn) ?>" value="<?= h($name) ?>" <?= $functionName === $name ? 'selected' : '' ?>><?= h($name) ?></option>
      <?php endforeach; ?>
    </select>
    <div id="fn_list" class="fn-list"></div>

    <label>Payload (JSON object)</label>
    <div id="payload_editor" style="height:220px;width:100%;border:1px solid #d0d7de;border-radius:6px;"></div>
    <textarea name="payload" id="payload_area" hidden><?= h($payloadJson) ?></textarea>
    <div id="param_hints" class="muted" style="margin-top:6px"></div>
    <div style="margin:6px 0 6px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <button id="btn_update_preview" type="button" class="secondary" onclick="window.updatePreviewSoap(true)">Update SOAP Preview</button>
      <span class="muted">Regenerates SOAP from JSON (forces overwrite of XML preview)</span>
      <span id="preview_status" class="muted" style="margin-left:6px"></span>
    </div>
    <div class="flow-row">
      <div id="flow_arrow" class="flow-arrow">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" style="margin-right:2px"><path fill="none" stroke="currentColor" stroke-width="2" d="M4 6h12M4 12h10M4 18h8"/></svg>
        JSON
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M4 11h10l-3-3 1.4-1.4L18.8 12l-6.4 5.4L11 16l3-3H4z"/></svg>
        SOAP
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" style="margin-left:2px"><path fill="none" stroke="currentColor" stroke-width="2" d="M3 5h18M3 10h18M3 15h12"/></svg>
      </div>
    </div>
    <label>SOAP Request XML (edit to override; auto-updates from payload)</label>
    <div id="override_xml_editor" style="height:220px;width:100%;border:1px solid #d0d7de;border-radius:6px;"></div>
    <textarea id="override_xml_textarea" name="override_request_xml" hidden><?= h($overrideXml) ?></textarea>

    <div class="actions">
      <button type="submit">Invoke</button>
    </div>
  </form>

  <?php if ($wsdl): ?>
    <div class="panel">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <h2 style="display:flex;align-items:center;gap:8px;margin:0 0 8px 0">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" d="M4 6h16M4 11h16M4 16h10"/></svg>
            SOAP Request Headers
          </h2>
          <pre class="language-xml" id="req_headers_pre"><?= h($last['request_headers']) ?></pre>
        </div>
        <div>
          <h2 style="display:flex;align-items:center;gap:8px;margin:0 0 8px 0">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" d="M4 6h16M4 11h16M4 16h10M6 13l3 3 9-9"/></svg>
            SOAP Response Headers
          </h2>
          <pre class="language-xml" id="resp_headers_pre"><?= h($last['response_headers']) ?></pre>
        </div>
      </div>
    </div>
    <div class="panel">
      <div style="display:flex;align-items:center;gap:12px">
        <h2 style="margin:0">SOAP Response XML</h2>
        <button class="secondary" type="button" onclick="copyResponseToOverride()">Edit as New Request</button>
      </div>
      <pre class="language-xml" id="resp_xml_pre"><?= h($last['response']) ?></pre>
    </div>
    <div class="panel result rainbow-border">
      <div style="display:flex;align-items:center;gap:12px;justify-content:space-between">
        <h2 style="margin:0">Result (parsed)</h2>
        <button type="button" class="secondary" onclick="window.validateAgainstSpec()">Validate JSON against OpenAPI</button>
      </div>
      <pre class="language-json" id="parsed_result_pre"><?= h(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
      <div id="validation_outcome" class="muted"></div>
    </div>
  <?php else: ?>
    <div class="panel"><div class="muted">Load a WSDL and invoke to view raw frames.</div></div>
  <?php endif; ?>

  </div>

  <div id="panel-spec" class="tab-panel">
    <div class="panel">
      <h2>OpenAPI (derived from WSDL)</h2>
      <div id="swagger-ui"></div>
      <details style="margin-top:10px"><summary>Raw JSON</summary>
        <pre><?= h(json_encode($openApiDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
      </details>
    </div>
  </div>

  <?php
  $wsdlViewerUri = null;
  if (!empty($wsdl)) {
      try {
          $wsdlContent = @file_get_contents($wsdl);
          if ($wsdlContent !== false) {
              if (stripos($wsdlContent, 'xml-stylesheet') === false) {
                  if (preg_match('/^<\?xml[^?]*\?>/i', $wsdlContent)) {
                      $wsdlContent = preg_replace('/^<\?xml[^?]*\?>/i', '$0' . "\n" . '<?xml-stylesheet type="text/xsl" href="https://tomi.vanek.sk/xml/wsdl-viewer.xsl"?>', $wsdlContent, 1);
                  } else {
                      $wsdlContent = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<?xml-stylesheet type="text/xsl" href="https://tomi.vanek.sk/xml/wsdl-viewer.xsl"?>' . "\n" . $wsdlContent;
                  }
              }
              $wsdlViewerUri = 'data:text/xml;charset=utf-8,' . rawurlencode($wsdlContent);
          }
      } catch (\Throwable $ignore) {}
  }
  ?>

  <div id="panel-wsdl" class="tab-panel">
    <div class="panel">
      <h2>WSDL Viewer (XSLT)</h2>
      <?php if ($wsdlViewerUri): ?>
        <iframe src="<?= $wsdlViewerUri ?>" style="width:100%;height:70vh;border:1px solid #d0d7de;border-radius:6px"></iframe>
        <div class="muted" style="margin-top:8px">Powered by WSDL viewer XSLT from <a href="https://tomi.vanek.sk/" target="_blank" rel="noreferrer">tomi.vanek.sk</a>.</div>
      <?php else: ?>
        <div class="muted">Load a WSDL (file or URL). If it is not publicly accessible, server may not render a preview.</div>
      <?php endif; ?>
    </div>
  </div>

  

<script>
  // Expose OpenAPI to JS and mount Swagger UI
  // Script URL for preview posts
  window.__SIM_SRC__ = '<?= h($_SERVER['PHP_SELF'] ?? 'simulator.php') ?>';
  window.__openapiSpec = <?php echo json_encode($openApiDoc ?? null, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
  if (window.__openapiSpec && document.getElementById('swagger-ui') && window.SwaggerUIBundle) {
    // Point all operations to /mock, keep paths intact to avoid duplication
    const specWithServer = (function(spec){
      if (!spec) return spec;
      const out = JSON.parse(JSON.stringify(spec));
      out.servers = [{ url: window.location.origin + '/mock' }];
      return out;
    })(window.__openapiSpec);

    window.ui = SwaggerUIBundle({
      spec: specWithServer,
      dom_id: '#swagger-ui',
      deepLinking: true,
      displayOperationId: true,
      presets: [SwaggerUIBundle.presets.apis, SwaggerUIBundle.SwaggerUIStandalonePreset],
    });
  }

  // Auto-fill payload from spec on load if a function is preselected
  window.addEventListener('DOMContentLoaded', function(){
    if (window.autoTemplateFromSpec) window.autoTemplateFromSpec();
  });

  // AJAX invoke: submit form without reload and update panels
  window.invokeSoapAjax = function(){
    const formEl = document.querySelector('#panel-builder form');
    if (!formEl) return;
    const data = new FormData(formEl);
    data.set('action', 'call');
    const targetUrl = window.__SIM_SRC__ || (window.location && window.location.pathname) || window.location.href;
    const invokeBtn = formEl.querySelector('button[type="submit"]');
    if (invokeBtn){ try { invokeBtn.disabled = true; invokeBtn.textContent = 'Invoking...'; } catch(_) {} }
    fetch(targetUrl, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      body: data
    })
    .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(j => {
      // Update headers
      const setPre = (id, val, lang) => { const el = document.getElementById(id); if (!el) return; el.textContent = val || ''; if (lang){ el.classList.add('language-'+lang); if (window.hljs) window.hljs.highlightElement(el); } };
      if (j && j.last){
        setPre('req_headers_pre', j.last.request_headers || '', 'xml');
        setPre('resp_headers_pre', j.last.response_headers || '', 'xml');
        setPre('resp_xml_pre', j.last.response || '', 'xml');
        try { window.__lastResponse = j.last.response || ''; } catch(_) {}
      }
      // Update parsed result panel
      try {
        const pre = document.getElementById('parsed_result_pre');
        if (pre){ pre.textContent = JSON.stringify(j && j.result ? j.result : null, null, 2); if (window.hljs) window.hljs.highlightElement(pre); }
      } catch(_){ }
      // Show errors if any
      try {
        const errorsBox = document.querySelector('.panel.errors');
        if (errorsBox){ errorsBox.innerHTML = ''; }
        const errs = Array.isArray(j && j.errors) ? j.errors : [];
        if (errs.length){
          if (errorsBox){ errs.forEach(e => { const d = document.createElement('div'); d.textContent = String(e); errorsBox.appendChild(d); }); }
        }
      } catch(_){ }
      // Re-format XML
      if (typeof formatAndHighlight === 'function') formatAndHighlight();
    })
    .catch(err => { console.error('Invoke error', err); alert('Invoke failed: ' + err.message); })
    .finally(() => { if (invokeBtn){ try { invokeBtn.disabled = false; invokeBtn.textContent = 'Invoke'; } catch(_) {} } });
  };

  // Pretty-print XML safely (temporarily disabled to avoid formatting issues)
  function prettyXml(xml){
    return xml;
  }

  // Generate a simple JSON request template from OpenAPI for the selected function
  function generateTemplateFromSchema(schema, components, seen){
    seen = seen || new Set();
    if (!schema) return null;
    if (schema.$ref){
      const ref = schema.$ref.replace('#/components/schemas/','');
      if (seen.has(ref)) return null; // break cycles
      seen.add(ref);
      return generateTemplateFromSchema(components.schemas[ref], components, seen);
    }
    if (schema.type === 'object' && schema.properties){
      const out = {};
      for (const [k, v] of Object.entries(schema.properties)){
        const val = generateTemplateFromSchema(v, components, seen);
        out[k] = (val === null ? '' : val);
      }
      return out;
    }
    if (schema.type === 'array' && schema.items){
      const it = generateTemplateFromSchema(schema.items, components, seen);
      return (it === null ? [] : [it]);
    }
    if (schema.type === 'string') return '';
    if (schema.type === 'number' || schema.type === 'integer') return 0;
    if (schema.type === 'boolean') return false;
    return null;
  }

  function generateTemplate(){
    const sel = document.querySelector('select[name="function"]');
    const fn = sel && sel.value;
    if (!fn || !window.__openapiSpec) return;
    // Prefer server-provided df examples if present
    const ex = window.__openapiSpec['x-dfExamples'] && window.__openapiSpec['x-dfExamples'][fn];
    if (ex){
      const ta = document.querySelector('textarea[name="payload"]');
      if (ta) { ta.value = JSON.stringify(ex, null, 2); }
      updateParamHints(ex);
      return;
    }
    // Fallback to deriving from requestBody schema
    const p = window.__openapiSpec.paths || {};
    const pathObj = p['/'+fn];
    if (!pathObj || !pathObj.post || !pathObj.post.requestBody) return;
    const rb = pathObj.post.requestBody['$ref'];
    if (!rb) return;
    const rbName = rb.replace('#/components/requestBodies/','');
    const rbObj = window.__openapiSpec.components && window.__openapiSpec.components.requestBodies && window.__openapiSpec.components.requestBodies[rbName];
    if (!rbObj) return;
    const content = rbObj.content && (rbObj.content['application/json'] || rbObj.content['application/xml']);
    if (!content || !content.schema) return;
    const tmpl = generateTemplateFromSchema(content.schema, window.__openapiSpec.components || {});
    if (tmpl !== undefined){
      const ta = document.querySelector('textarea[name="payload"]');
      if (ta) ta.value = JSON.stringify(tmpl, null, 2);
      updateParamHints(tmpl);
    }
  }

  function updateParamHints(obj){
    try {
      const el = document.getElementById('param_hints');
      if (!el) return;
      const keys = Object.keys(obj || {});
      // If single root with nested object, show its keys instead for clarity
      let top = obj;
      if (keys.length === 1 && typeof obj[keys[0]] === 'object' && obj[keys[0]] !== null){
        top = obj[keys[0]];
      }
      const fields = Object.keys(top || {});
      if (fields.length){
        el.textContent = 'Parameters: ' + fields.join(', ');
      } else {
        el.textContent = '';
      }
    } catch(_){}
  }
  window.autoTemplateFromSpec = function(){
    generateTemplate();
    // push into editor if present
    const ta = document.getElementById('payload_area');
    if (window.__payloadEditor && ta){
      try { window.__payloadEditor.setValue(JSON.stringify(JSON.parse(ta.value||'{}'), null, 2), -1); } catch(e) {}
      window.updatePreviewSoap();
    }
  };
  // Attach button dynamically if present
  // Initialize ACE editor for JSON payload
  (function(){
    const ta = document.getElementById('payload_area');
    const mount = document.getElementById('payload_editor');
    if (mount && window.ace){
      const ed = window.__payloadEditor = ace.edit('payload_editor');
      ed.session.setMode('ace/mode/json');
      // theme set below via matchMedia as well
      ed.setOptions({
        tabSize: 2,
        useSoftTabs: true,
        wrap: true,
        showPrintMargin: false,
      });
      // seed value
      let seed = ta && ta.value ? ta.value : '{\n  \n}';
      try { seed = JSON.stringify(JSON.parse(seed), null, 2); } catch(e) {}
      ed.setValue(seed, -1);
      // Update SOAP preview while typing (debounced)
      try {
        ed.on('change', function(){
          if (ta) ta.value = ed.getValue();
          if (window.__clickUpdateDebounced) window.__clickUpdateDebounced();
        });
      } catch(_) {}
    } else {
      // Fallback: no ACE, use the textarea directly and make it visible
      if (ta){
        try { ta.removeAttribute('hidden'); } catch(_) {}
        try {
          const handler = function(){ if (window.__clickUpdateDebounced) window.__clickUpdateDebounced(); };
          ta.addEventListener('input', handler);
          ta.addEventListener('change', handler);
        } catch(_) {}
      }
    }
  })();

  // Initialize ACE editor for XML override
  (function(){
    const mount = document.getElementById('override_xml_editor');
    const ta = document.getElementById('override_xml_textarea');
    if (mount && window.ace){
      const ed = window.__xmlEditor = ace.edit('override_xml_editor');
      ed.session.setMode('ace/mode/xml');
      // theme set below via matchMedia as well
      ed.setOptions({ tabSize: 2, useSoftTabs: true, wrap: true, showPrintMargin: false });
      // Seed value, pretty-print if possible
      let xmlSeed = (ta && ta.value) ? ta.value : '';
      try { if (xmlSeed) xmlSeed = prettyXml(xmlSeed); } catch(_) {}
      ed.setValue(xmlSeed, -1);
      ed.on('change', function(){ window.__xmlTouched = true; });
    }
  })();

  // Live preview: build SOAP XML from payload and selected operation using a preview SoapClient
  window.updatePreviewSoap = function(force){
    const wsdl = document.querySelector('input[name="wsdl"]').value;
    const fnSel = document.querySelector('select[name="function"]');
    const fn = fnSel && fnSel.value;
    const statusEl = document.getElementById('preview_status');
    const btn = document.getElementById('btn_update_preview');
    if (!wsdl){ if (statusEl) statusEl.textContent = 'Enter a WSDL first'; return; }
    if (!fn){ if (statusEl) statusEl.textContent = 'Select a function'; return; }
    let payload = {};
    let parsedOk = true;
    try {
      const src = window.__payloadEditor ? window.__payloadEditor.getValue() : (document.getElementById('payload_area').value || '{}');
      payload = JSON.parse(src || '{}');
      const ta = document.getElementById('payload_area');
      if (ta) ta.value = JSON.stringify(payload);
      window.__lastValidPayloadJson = JSON.stringify(payload);
    } catch(e) {
      parsedOk = false;
    }
    if (!parsedOk) {
      if (force === true && window.__lastValidPayloadJson){
        try { payload = JSON.parse(window.__lastValidPayloadJson); parsedOk = true; } catch(_) {}
      }
      if (statusEl){ statusEl.textContent = 'Invalid JSON – fix JSON or press button again to use last valid.'; }
    } else {
      if (statusEl){ statusEl.textContent = ''; }
    }
    if (!parsedOk) { return; }
    // Use fetch to call back to this script to build XML without sending
    const form = new FormData();
    form.append('action','preview');
    form.append('wsdl', wsdl);
    form.append('function', fn);
    form.append('payload', JSON.stringify(payload));
    // Include current options/headers and WSSE so preview mirrors the final call
    const optEl = document.querySelector('textarea[name="options"]');
    const hdrEl = document.querySelector('textarea[name="headers"]');
    const wsseUserEl = document.querySelector('input[name="wsse_user"]');
    const wssePassEl = document.querySelector('input[name="wsse_pass"]');
    const wsseTokEl  = document.querySelector('input[name="wsse_token"]');
    if (optEl) form.append('options', optEl.value || '');
    if (hdrEl) form.append('headers', hdrEl.value || '');
    if (wsseUserEl) form.append('wsse_user', wsseUserEl.value || '');
    if (wssePassEl) form.append('wsse_pass', wssePassEl.value || '');
    if (wsseTokEl)  form.append('wsse_token', wsseTokEl.value || '');
    const targetUrl = window.__SIM_SRC__ || (window.location && window.location.pathname) || window.location.href;
    if (btn){ try { btn.disabled = true; btn.textContent = 'Updating...'; } catch(_) {} }
    fetch(targetUrl, { method: 'POST', body: form })
      .then(r=>{
        const ct = r.headers.get('content-type') || '';
        if (!r.ok) throw new Error('Preview HTTP ' + r.status);
        if (ct.includes('application/json')) return r.json();
        return r.text().then(t=>{ throw new Error('Preview returned non-JSON: ' + t.slice(0,200)); });
      })
      .then(j=>{
        if (j && j.request_xml !== undefined){
          const mount = document.getElementById('override_xml_editor');
          const arrowEl = document.getElementById('flow_arrow');
          const incoming = j.request_xml || '';
          let pretty = incoming;
          try { pretty = prettyXml(incoming); } catch(_) {}
          if (window.__xmlEditor){
            const shouldUpdate = (force === true) || !window.__xmlTouched;
            if (shouldUpdate){
              const changed = (window.__xmlEditor.getValue() !== pretty);
              if (changed){
                window.__xmlEditor.setValue(pretty, -1);
                if (mount){
                  mount.classList.remove('flash'); // reset
                  void mount.offsetWidth; // reflow
                  mount.classList.add('flash');
                  setTimeout(()=> mount.classList.remove('flash'), 700);
                }
                if (arrowEl){
                  arrowEl.classList.remove('flash');
                  void arrowEl.offsetWidth;
                  arrowEl.classList.add('flash');
                  setTimeout(()=> arrowEl.classList.remove('flash'), 700);
                }
              }
              else {
                // Even if content same, still flash to give feedback on button
                if (mount){ mount.classList.remove('flash'); void mount.offsetWidth; mount.classList.add('flash'); setTimeout(()=> mount.classList.remove('flash'), 700); }
                if (arrowEl){ arrowEl.classList.remove('flash'); void arrowEl.offsetWidth; arrowEl.classList.add('flash'); setTimeout(()=> arrowEl.classList.remove('flash'), 700); }
              }
            }
          } else {
            const ta = document.getElementById('override_xml_textarea');
            if (ta && ta.value !== pretty){
              ta.value = pretty;
              if (mount){
                mount.classList.remove('flash'); // reset
                void mount.offsetWidth; // reflow
                mount.classList.add('flash');
                setTimeout(()=> mount.classList.remove('flash'), 700);
              }
              if (arrowEl){
                arrowEl.classList.remove('flash');
                void arrowEl.offsetWidth;
                arrowEl.classList.add('flash');
                setTimeout(()=> arrowEl.classList.remove('flash'), 700);
              }
            }
          }
        }
        if (statusEl){ statusEl.textContent = 'Updated'; setTimeout(()=>{ if (statusEl.textContent==='Updated') statusEl.textContent=''; }, 1200); }
      })
      .catch(err=>{ try { console.error('[Preview]', err); if (statusEl){ statusEl.textContent = 'Preview error – see console'; } } catch(_) {} })
      .finally(()=>{ if (btn){ try { btn.disabled = false; btn.textContent = 'Update SOAP Preview'; } catch(_) {} } });
  }
  // Trigger initial preview when page loads
  window.addEventListener('DOMContentLoaded', ()=> setTimeout(window.updatePreviewSoap, 100));

  // Debounced update function for editor/input events
  function debounce(fn, wait){ let t; return function(){ const ctx=this, args=arguments; clearTimeout(t); t=setTimeout(function(){ fn.apply(ctx,args); }, wait); }; }
  window.__updatePreviewDebounced = debounce(window.updatePreviewSoap, 250);
  
  // Function filter + custom hierarchical list
  (function(){
    const sel = document.getElementById('fn_select');
    const list = document.getElementById('fn_list');
    const filter = document.getElementById('fn_filter');
    const count = document.getElementById('fn_count');
    const clearBtn = document.getElementById('fn_clear_btn');
    if (!sel || !list || !filter) return;
    // Build unique list keyed by function name, aggregating signatures
    const all = [];
    // Parse signature into parts: ret, name, params
    function parseSig(sig){
      // Example: "string opName(string a, int b)"
      try {
        const m = sig.match(/^\s*([^\s]+)\s+([^(\s]+)\s*\((.*)\)\s*$/);
        if (!m) return {ret:'', name:sig, params:[]};
        const ret = m[1]||''; const name = m[2]||sig; const paramsStr = (m[3]||'').trim();
        const params = paramsStr ? paramsStr.split(',').map(p=>p.trim()) : [];
        return {ret, name, params};
      } catch(e){ return {ret:'', name:sig, params:[]}; }
    }
    const byName = new Map();
    for (let i=0;i<sel.options.length;i++){
      const o = sel.options[i]; if (!o.value) continue;
      const sig = o.title || o.text || o.value; const parts = parseSig(sig);
      const key = o.value;
      if (!byName.has(key)){
        const titles = new Set(); titles.add(sig);
        const meta = (window.__wsdlOps && window.__wsdlOps[key]) ? window.__wsdlOps[key] : [];
        byName.set(key, { value:o.value, label:o.text, title:sig, parts, titles, meta });
      } else {
        const entry = byName.get(key);
        entry.titles.add(sig);
      }
    }
    byName.forEach(v=> all.push(v));
    function render(items){
      list.innerHTML='';
      if (!items.length){
        const empty = document.createElement('div'); empty.className='fn-empty'; empty.textContent='No functions match your filter';
        list.appendChild(empty); return;
      }
      const current = sel.value;
      items.forEach(x=>{
        const item = document.createElement('div'); item.className='fn-item' + (current===x.value?' active':''); item.setAttribute('data-value', x.value);
        const line = document.createElement('div'); line.className='fn-line';
        const op = document.createElement('span'); op.className='tok-op'; op.textContent = x.parts.name || x.value;
        const ret = document.createElement('span'); ret.className='tok-ret'; ret.textContent = x.parts.ret || 'void';
        line.appendChild(op); line.appendChild(ret);
        // Determine if multiple bindings are exact copies
        let badgeText = '';
        const meta = Array.isArray(x.meta) ? x.meta : [];
        if (meta.length > 1){
          const keys = new Set(meta.map(m => JSON.stringify({
            sig: x.title,
            v: m.soapVersion||'', a: m.soapAction||'', u: m.address||''
          })));
          if (keys.size === 1) badgeText = meta.length + ' duplicates';
          else badgeText = meta.length + ' variants';
        }
        if (badgeText){
          const badge = document.createElement('span'); badge.className='muted'; badge.style.marginLeft='auto'; badge.style.fontSize='11px'; badge.textContent = badgeText;
          line.appendChild(badge);
        }
        if (x.parts.params && x.parts.params.length){
          x.parts.params.forEach(p=>{ const tp = document.createElement('span'); tp.className='tok-param'; tp.textContent=p; line.appendChild(tp); });
        }
        const sig = document.createElement('div'); sig.className='tok-sig'; sig.textContent = x.title;
        item.appendChild(line); item.appendChild(sig);
        item.addEventListener('click', function(){
          if (sel.value !== x.value){ sel.value = x.value; }
          // visual active
          list.querySelectorAll('.fn-item.active').forEach(n=>n.classList.remove('active'));
          item.classList.add('active');
          // trigger existing onchange behavior
          if (typeof sel.onchange === 'function') sel.onchange();
          else sel.dispatchEvent(new Event('change', {bubbles:true}));
        });
        list.appendChild(item);
      });
    }
    function applyFilter(){
      const v = (filter.value||'').toLowerCase();
      const keep = v ? all.filter(x=> (
          x.value.toLowerCase().includes(v) ||
          (x.label||'').toLowerCase().includes(v) ||
          (x.title||'').toLowerCase().includes(v)
        )) : all.slice();
      render(keep);
      if (count) count.textContent = keep.length ? `(${keep.length})` : '(0)';
    }
    filter.addEventListener('input', applyFilter);
    if (clearBtn){ clearBtn.addEventListener('click', function(){ filter.value=''; applyFilter(); filter.focus(); }); }
    // initial
    applyFilter();
  })();
  // Debounced programmatic click of the Update button
  window.__clickUpdateDebounced = debounce(function(){
    try {
      const btn = document.getElementById('btn_update_preview');
      if (btn) btn.click();
    } catch(_) {}
  }, 200);

  // Keyboard shortcut: Ctrl+Enter to force preview
  window.addEventListener('keydown', function(ev){
    if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter'){
      try { ev.preventDefault(); } catch(_) {}
      window.updatePreviewSoap(true);
    }
  });

  // Ensure onchange/input of the JSON textarea also triggers preview
  (function(){
    const ta = document.getElementById('payload_area');
    if (ta){
      const fire = function(){ if (window.__updatePreviewDebounced) window.__updatePreviewDebounced(); };
      try { ta.addEventListener('input', fire); ta.addEventListener('change', fire); } catch(_) {}
    }
  })();

  // Hook inputs to live preview
  window.addEventListener('DOMContentLoaded', function(){
    const hook = (sel, evt='input')=>{ const el = document.querySelector(sel); if (!el) return; el.addEventListener(evt, function(){ if (window.__updatePreviewDebounced) window.__updatePreviewDebounced(); }); };
    hook('textarea[name="options"]');
    hook('textarea[name="headers"]');
    hook('input[name="wsse_user"]');
    hook('input[name="wsse_pass"]');
    hook('input[name="wsse_token"]');
    hook('select[name="function"]', 'change');
  });

  // Pretty-print and highlight XML/JSON blocks
  function prettyXml(xml){
    if (!xml || typeof xml !== 'string') return xml || '';
    // Remove existing formatting
    xml = xml.replace(/\r?\n/g, '').replace(/>\s+</g, '><');
    // Insert line breaks
    xml = xml.replace(/>(<)(\/*)/g, ">$1$2").replace(/></g, ">\n<");
    const PADDING = '  ';
    let pad = 0;
    return xml.split('\n').map(line => {
      if (line.match(/^<\//)) pad = Math.max(pad - 1, 0);
      const out = PADDING.repeat(pad) + line;
      if (line.match(/^<[^!?][^>]*[^\/]>/) && !line.match(/<.*<.*/)) pad += 1;
      return out;
    }).join('\n');
  }
  function formatAndHighlight(){
    const ids = ['resp_xml_pre'];
    ids.forEach(id=>{
      const el = document.getElementById(id);
      if (el && el.textContent){
        try {
          el.textContent = prettyXml(el.textContent);
          el.classList.add('language-xml');
          if (window.hljs) window.hljs.highlightElement(el);
        } catch(e){}
      }
    });
    // headers leave as-is but still highlight for readability
    ['req_headers_pre','resp_headers_pre'].forEach(id => {
      const el = document.getElementById(id);
      if (el && el.textContent && window.hljs){
        el.classList.add('language-xml');
        window.hljs.highlightElement(el);
      }
    });
    const pj = document.getElementById('parsed_result_pre');
    if (pj && pj.textContent && window.hljs){ window.hljs.highlightElement(pj); }
  }
  window.addEventListener('DOMContentLoaded', formatAndHighlight);

  // Auto-switch ACE themes based on system scheme
  (function(){
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    function applyTheme(){
      const theme = mq.matches ? 'ace/theme/github_dark' : 'ace/theme/github';
      if (window.__payloadEditor) window.__payloadEditor.setTheme(theme);
      if (window.__xmlEditor) window.__xmlEditor.setTheme(theme);
    }
    mq.addEventListener ? mq.addEventListener('change', applyTheme) : mq.addListener(applyTheme);
    applyTheme();
  })();

  // Validate parsed JSON result against OpenAPI response schema
  window.validateAgainstSpec = function(){
    const spec = window.__openapiSpec; if (!spec) return;
    const sel = document.querySelector('select[name="function"]');
    const fn = sel && sel.value; if (!fn) return;
    const pathObj = spec.paths && spec.paths['/'+fn]; if (!pathObj || !pathObj.post) return;
    const respRef = pathObj.post.responses && pathObj.post.responses['200'] && pathObj.post.responses['200']['$ref'];
    if (!respRef) return;
    const respName = respRef.replace('#/components/responses/','');
    const response = spec.components && spec.components.responses && spec.components.responses[respName];
    if (!response) return;
    const schema = (response.content && (response.content['application/json'] || response.content['application/xml']))?.schema;
    if (!schema) return;
    let data = null;
    try { data = JSON.parse(document.getElementById('parsed_result_pre').textContent || 'null'); } catch(e){ data = null; }
    const ajv = new window.ajv7({ strict: false, allErrors: true });
    const validate = ajv.compile(schema);
    const ok = validate(data);
    const out = document.getElementById('validation_outcome');
    if (!out) return;
    if (ok) { out.textContent = 'Valid against OpenAPI schema.'; out.style.color = '#15803d'; }
    else { out.textContent = 'Validation errors: ' + JSON.stringify(validate.errors, null, 2); out.style.color = '#b91c1c'; }
  }
</script>
</body>
</html>


