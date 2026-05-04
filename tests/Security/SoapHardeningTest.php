<?php

namespace DreamFactory\Core\Soap\Tests\Security;

use DreamFactory\Core\Soap\Services\Soap;
use PHPUnit\Framework\TestCase;

/**
 * Security: SOAP service must
 *   1. SSRF-validate the WSDL URL when configured as http(s)
 *   2. Reject stream_context options that disable TLS verification
 *   3. Reject WSDL paths whose realpath() resolves outside storage_path('wsdl')
 *
 * Native PHP SoapClient does not enable LIBXML_NONET / entity-loader
 * controls on its own — and admin-configurable stream_context offered
 * an easy MITM path via verify_peer=false. The realpath() use also let
 * a symlink in the wsdl directory redirect SoapClient to load any file
 * the webserver user could read.
 */
class SoapHardeningTest extends TestCase
{
    /**
     * @dataProvider unsafeStreamContextProvider
     */
    public function testRejectsTlsBypassStreamContext(array $context): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Soap::assertSafeStreamContext($context);
    }

    public static function unsafeStreamContextProvider(): array
    {
        return [
            'verify_peer false'        => [['ssl' => ['verify_peer' => false]]],
            'verify_peer_name false'   => [['ssl' => ['verify_peer_name' => false]]],
            'allow_self_signed true'   => [['ssl' => ['allow_self_signed' => true]]],
            'multiple unsafe'          => [['ssl' => ['verify_peer' => false, 'allow_self_signed' => true]]],
        ];
    }

    /**
     * @dataProvider safeStreamContextProvider
     */
    public function testAcceptsSafeStreamContext(array $context): void
    {
        Soap::assertSafeStreamContext($context);
        $this->assertTrue(true);
    }

    public static function safeStreamContextProvider(): array
    {
        return [
            'empty'                    => [[]],
            'no ssl block'             => [['http' => ['method' => 'GET']]],
            'verify_peer true'         => [['ssl' => ['verify_peer' => true]]],
            'cafile only'              => [['ssl' => ['cafile' => '/etc/ssl/certs/ca.pem']]],
        ];
    }

    public function testSourceCallsSsrfValidatorOnWsdlUrl(): void
    {
        $sourcePath = __DIR__ . '/../../src/Services/Soap.php';
        $this->assertFileExists($sourcePath);
        $contents = file_get_contents($sourcePath);

        $this->assertMatchesRegularExpression(
            '/SsrfValidator::validateExternalUrl\s*\(\s*\$this->wsdl\s*\)/',
            $contents,
            'WSDL URL must be passed through SsrfValidator before SoapClient fetches it.'
        );
    }

    public function testSourceEnforcesSymlinkBoundary(): void
    {
        $sourcePath = __DIR__ . '/../../src/Services/Soap.php';
        $contents = file_get_contents($sourcePath);

        $this->assertMatchesRegularExpression(
            '/realpath\s*\(\s*storage_path\s*\(\s*[\'"]wsdl[\'"]\s*\)\s*\)/',
            $contents,
            'WSDL realpath result must be checked against the storage_path(wsdl) root.'
        );
        $this->assertMatchesRegularExpression(
            '/str_starts_with\s*\(\s*\$path/',
            $contents,
            'Symlink boundary check must use str_starts_with on the resolved path.'
        );
    }

    public function testSourceCallsAssertSafeStreamContext(): void
    {
        $sourcePath = __DIR__ . '/../../src/Services/Soap.php';
        $contents = file_get_contents($sourcePath);

        $this->assertMatchesRegularExpression(
            '/(self|static)::assertSafeStreamContext\s*\(/',
            $contents,
            'Soap constructor must call assertSafeStreamContext on the parsed stream_context value.'
        );
    }
}
