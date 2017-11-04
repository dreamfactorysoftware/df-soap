<?php

namespace DreamFactory\Core\Soap\Components;

use SoapClient as PHPSoapClient;

class SoapClient extends PHPSoapClient
{
    private $options = [];

    /**
     * @param mixed $url  WSDL url (eg: http://dominio.tld/webservice.aspx?wsdl)
     * @param array $data Array to be used to create an instance of SoapClient. Can take the same parameters as the
     *                    native class
     *
     * @throws \Exception
     */
    public function __construct($url, $data)
    {
        $this->options = $data;

        if (empty($data['ntlm_username']) && empty($data['ntlm_password'])) {
            parent::__construct($url, $data);
        } else {
            $this->use_ntlm = true;
            NTLMStream::$user = $data['ntlm_username'];
            NTLMStream::$password = $data['ntlm_password'];

            // Remove HTTP stream registry
            stream_wrapper_unregister('http');

            // Register our defined NTLM stream
            if (!stream_wrapper_register('http', NTLMStream::class)) {
                throw new \Exception("Unable to register HTTP Handler");
            }

            // Create an instance of SoapClient
            parent::__construct($url, $data);

            // Since our instance is using the defined NTLM stream,
            // you now need to reset the stream wrapper to use the default HTTP.
            // This way you're not messing with the rest of your application or dependencies.
            stream_wrapper_restore('http');
        }
    }
}