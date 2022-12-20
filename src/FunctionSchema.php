<?php
namespace DreamFactory\Core\Soap;

use Str;

/**
 * FunctionSchema is the class for representing the metadata of a WSDL-based SOAP function.
 *
 * FunctionSchema provides the following information about a table:
 * <ul>
 * <li>{@link name}</li>
 * <li>{@link description}</li>
 * <li>{@link requestType}</li>
 * <li>{@link requestFields}</li>
 * <li>{@link responseType}</li>
 * <li>{@link responseFields}</li>
 * </ul>
 *
 */
class FunctionSchema
{
    /**
     * @var string WSDL declared name of this function.
     */
    public $name;
    /**
     * @var string Full description of this function.
     */
    public $description;
    /**
     * @var string Request object type.
     */
    public $requestType;
    /**
     * @var array Request object fields.
     */
    public $requestFields;
    /**
     * @var string Response object type.
     */
    public $responseType;
    /**
     * @var array Response object fields.
     */
    public $responseFields;

    public function __construct($function)
    {
        $this->name = strstr(substr($function, strpos($function, ' ') + 1), '(', true);
        $this->responseType = strstr($function, ' ', true);
        $this->requestType = strstr(trim(strstr($function, '('), '()'), ' ', true);
    }

    public function fill(array $settings)
    {
        foreach ($settings as $key => $value) {
            if (!property_exists($this, $key)) {
                // try camel cased
                $camel = Str::camel($key);
                if (property_exists($this, $camel)) {
                    $this->{$camel} = $value;
                    continue;
                }
            }
            // set real and virtual
            $this->{$key} = $value;
        }
    }

    public function toArray()
    {
        return (array)$this;
    }
}
