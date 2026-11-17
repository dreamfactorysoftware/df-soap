<?php

namespace DreamFactory\Core\Soap\Tests\Services;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Soap\Services\Soap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Wording of refused WSDL, endpoint and proxy URLs. The URLs used here are IP
 * literals or unparseable, so no DNS lookups are made.
 */
class SoapOutboundUrlTest extends TestCase
{
    private function refusal(string $url, string $subject): BadRequestException
    {
        try {
            Soap::assertAllowedOutboundUrl($url, $subject);
        } catch (BadRequestException $e) {
            return $e;
        }

        $this->fail("$url should not be accepted");
    }

    public static function refusedUrlProvider(): array
    {
        return [
            'endpoint, loopback'          => ['http://127.0.0.1:8080/ws', 'SOAP endpoint host'],
            'endpoint, private range'     => ['https://10.1.2.3/ws', 'SOAP endpoint host'],
            'endpoint, link-local'        => ['http://169.254.1.1/ws', 'SOAP endpoint host'],
            'endpoint, IPv6 loopback'     => ['http://[::1]/ws', 'SOAP endpoint host'],
            'WSDL, private range'         => ['http://192.168.1.10/service?wsdl', 'SOAP WSDL host'],
            'proxy, loopback'             => ['http://127.0.0.1', 'SOAP proxy host'],
            'proxy, no host'              => ['http://', 'SOAP proxy host'],
        ];
    }

    #[DataProvider('refusedUrlProvider')]
    public function testRefusalIsWordedForSoap(string $url, string $subject): void
    {
        $e = $this->refusal($url, $subject);

        $this->assertSame($subject . ' is not allowed.', $e->getMessage());
        $this->assertSame(400, $e->getStatusCode());
        $this->assertStringNotContainsStringIgnoringCase('import', $e->getMessage());
        $this->assertInstanceOf(BadRequestException::class, $e->getPrevious());
    }

    public function testPublicAddressIsAccepted(): void
    {
        Soap::assertAllowedOutboundUrl('https://93.184.216.34/ws', 'SOAP endpoint host');
        Soap::assertAllowedOutboundUrl('http://93.184.216.34:3128', 'SOAP proxy host');
        $this->assertTrue(true);
    }

    public function testConstructorChecksEndpointAndProxyOptions(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../src/Services/Soap.php');

        $this->assertMatchesRegularExpression(
            '/assertAllowedOutboundUrl\s*\(\s*\$options\[\'location\'\]\s*,\s*\'SOAP endpoint host\'\s*\)/',
            $contents
        );
        $this->assertMatchesRegularExpression(
            '/assertAllowedOutboundUrl\s*\(\s*\'http:\/\/\'\s*\.\s*\$options\[\'proxy_host\'\]\s*,\s*\'SOAP proxy host\'\s*\)/',
            $contents
        );
    }
}
