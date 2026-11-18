<?php
// Built-in web server router for SoapEndpointTest.
$dir = getenv('SOAP_TEST_DIR');
$uri = $_SERVER['REQUEST_URI'];
file_put_contents($dir . '/requests.log', $uri . "\n", FILE_APPEND);

if (str_starts_with($uri, '/moved-wsdl')) {
    header('Location: /service.wsdl', true, 302);
    exit;
}
if (str_starts_with($uri, '/moved-endpoint')) {
    header('Location: /endpoint', true, 307);
    exit;
}
if (str_starts_with($uri, '/missing-wsdl')) {
    http_response_code(404);
    header('Content-Type: text/html');
    echo '<html><body>not here</body></html>';
    exit;
}
header('Content-Type: text/xml');
if (str_starts_with($uri, '/service.wsdl')) {
    readfile($dir . '/service.wsdl');
    exit;
}
echo '<?xml version="1.0" encoding="UTF-8"?>'
    . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="urn:df-soap-test">'
    . '<SOAP-ENV:Body><ns1:pingResponse><result>pong</result></ns1:pingResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';
