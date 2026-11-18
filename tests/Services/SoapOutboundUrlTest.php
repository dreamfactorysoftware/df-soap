<?php

namespace DreamFactory\Core\Soap\Tests\Services;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Soap\Services\Soap;
use DreamFactory\Core\System\Components\ExternalUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exposes the SOAP refusal wording for a given validator exception.
 */
class SoapRefusalMessageProbe extends Soap
{
    public static function message(string $url, string $subject, BadRequestException $e): string
    {
        return static::outboundRefusalMessage($url, $subject, $e);
    }
}

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
            'endpoint, loopback'      => ['http://127.0.0.1:8080/ws', 'SOAP endpoint', 'SOAP endpoint host is not allowed.'],
            'endpoint, private range' => ['https://10.1.2.3/ws', 'SOAP endpoint', 'SOAP endpoint host is not allowed.'],
            'endpoint, link-local'    => ['http://169.254.1.1/ws', 'SOAP endpoint', 'SOAP endpoint host is not allowed.'],
            'endpoint, IPv6 loopback' => ['http://[::1]/ws', 'SOAP endpoint', 'SOAP endpoint host is not allowed.'],
            'endpoint, no host'       => ['https:///ws', 'SOAP endpoint', 'SOAP endpoint host is not valid.'],
            'endpoint, other scheme'  => ['ftp://10.1.2.3/ws', 'SOAP endpoint', 'SOAP endpoint must be an http or https URL.'],
            'WSDL, private range'     => ['http://192.168.1.10/service?wsdl', 'SOAP WSDL', 'SOAP WSDL host is not allowed.'],
            'proxy, loopback'         => ['http://127.0.0.1', 'SOAP proxy', 'SOAP proxy host is not allowed.'],
            'proxy, no host'          => ['http://', 'SOAP proxy', 'SOAP proxy host is not valid.'],
        ];
    }

    #[DataProvider('refusedUrlProvider')]
    public function testRefusalIsWordedForSoap(string $url, string $subject, string $message): void
    {
        $e = $this->refusal($url, $subject);

        $this->assertSame($message, $e->getMessage());
        $this->assertSame(400, $e->getStatusCode());
        $this->assertStringNotContainsStringIgnoringCase('import', $e->getMessage());
        $this->assertInstanceOf(BadRequestException::class, $e->getPrevious());
    }

    public function testUnresolvedHostIsWordedSeparately(): void
    {
        $e = new ExternalUrlException(ExternalUrlException::REASON_RESOLVE, 'hostname could not be resolved');

        $this->assertSame(
            'SOAP endpoint host could not be resolved.',
            SoapRefusalMessageProbe::message('http://soap.corp.local/ws', 'SOAP endpoint', $e)
        );
        $this->assertSame(
            'SOAP proxy host could not be resolved.',
            SoapRefusalMessageProbe::message('http://proxy.corp.local', 'SOAP proxy', $e)
        );
    }

    public function testAddressRefusalAndPlainExceptionKeepGeneralWording(): void
    {
        $address = new ExternalUrlException(ExternalUrlException::REASON_ADDRESS, 'private address');
        $plain = new BadRequestException('Invalid import URL: something else.');

        $this->assertSame(
            'SOAP WSDL host is not allowed.',
            SoapRefusalMessageProbe::message('http://wsdl.corp.local/x?wsdl', 'SOAP WSDL', $address)
        );
        $this->assertSame(
            'SOAP WSDL host is not allowed.',
            SoapRefusalMessageProbe::message('http://wsdl.corp.local/x?wsdl', 'SOAP WSDL', $plain)
        );
    }

    public function testPublicAddressIsAccepted(): void
    {
        Soap::assertAllowedOutboundUrl('https://93.184.216.34/ws', 'SOAP endpoint');
        Soap::assertAllowedOutboundUrl('http://93.184.216.34:3128', 'SOAP proxy');
        $this->assertTrue(true);
    }

    public function testConstructorChecksWsdlEndpointAndProxyOptions(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../src/Services/Soap.php');

        $this->assertMatchesRegularExpression(
            '/assertAllowedOutboundUrl\s*\(\s*\$this->wsdl\s*,\s*\'SOAP WSDL\'\s*\)/',
            $contents
        );
        $this->assertMatchesRegularExpression(
            '/assertAllowedOutboundUrl\s*\(\s*\$options\[\'location\'\]\s*,\s*\'SOAP endpoint\'\s*\)/',
            $contents
        );
        $this->assertMatchesRegularExpression(
            '/assertAllowedOutboundUrl\s*\(\s*\'http:\/\/\'\s*\.\s*\$options\[\'proxy_host\'\]\s*,\s*\'SOAP proxy\'\s*\)/',
            $contents
        );
    }

    public function testValidatorIsAskedToApplyTheOutboundList(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../src/Services/Soap.php');

        $this->assertMatchesRegularExpression('/SsrfValidator::validateExternalUrl\(\s*\$url\s*,\s*true\s*\)/', $contents);
    }
}
