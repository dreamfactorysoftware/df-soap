<?php

namespace DreamFactory\Core\Soap\Tests\Services;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Soap\Components\SoapClient;
use DreamFactory\Core\Soap\Services\Soap;
use PHPUnit\Framework\TestCase;

/**
 * Endpoint addresses from the WSDL document and HTTP redirects for WSDL
 * downloads and SOAP requests.
 */
class SoapEndpointTest extends TestCase
{
    private string $dir;

    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/df-soap-endpoint-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function wsdlFile(string $endpoint): string
    {
        $path = $this->dir . '/service.wsdl';
        $xml = file_get_contents(__DIR__ . '/../fixtures/ping.wsdl');
        file_put_contents($path, str_replace('__ENDPOINT__', htmlspecialchars($endpoint), $xml));

        return $path;
    }

    private function startServer(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, __DIR__ . '/../fixtures/router.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            $this->dir,
            ['SOAP_TEST_DIR' => $this->dir]
        );
        for ($i = 0; $i < 50; $i++) {
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($conn) {
                fclose($conn);

                return;
            }
            usleep(100000);
        }
        $this->fail('test web server did not start');
    }

    private function requests(): array
    {
        $log = $this->dir . '/requests.log';

        return is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];
    }

    private function options(): array
    {
        return [
            'cache_wsdl'     => WSDL_CACHE_NONE,
            'exceptions'     => true,
            'stream_context' => stream_context_create(Soap::withoutRedirects([])),
        ];
    }

    public function testRedirectOptionsAreAddedToStreamContext(): void
    {
        $context = Soap::withoutRedirects([
            'http' => ['timeout' => 5, 'follow_location' => 1, 'max_redirects' => 20],
            'ssl'  => ['verify_peer' => true],
        ]);

        $this->assertSame(0, $context['http']['follow_location']);
        $this->assertSame(1, $context['http']['max_redirects']);
        $this->assertSame(5, $context['http']['timeout']);
        $this->assertSame(['verify_peer' => true], $context['ssl']);
        $this->assertSame(['follow_location' => 0, 'max_redirects' => 1], Soap::withoutRedirects([])['http']);
    }

    public function testEndpointFromWsdlIsChecked(): void
    {
        $client = new SoapClient($this->wsdlFile('http://127.0.0.1:9/ws'), $this->options());
        $client->setLocationCheck(Soap::endpointCheck(null));

        try {
            $client->ping('x');
            $this->fail('the request should not be sent');
        } catch (BadRequestException $e) {
            $this->assertSame('SOAP endpoint host is not allowed.', $e->getMessage());
            $this->assertSame(400, $e->getStatusCode());
        }
        $this->assertNull($client->__getLastRequest());
    }

    public function testCheckedLocationOptionIsNotCheckedAgain(): void
    {
        $check = Soap::endpointCheck('http://10.1.2.3/ws');

        // Already checked when the service was configured.
        $check('http://10.1.2.3/ws');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('SOAP endpoint host is not allowed.');
        $check('http://10.1.2.4/ws');
    }

    public function testEndpointOfOtherSchemeIsRefused(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('SOAP endpoint must be an http or https URL.');
        (Soap::endpointCheck(null))('file:///tmp/ws');
    }

    public function testRequestIsSentWhenCheckPasses(): void
    {
        $this->startServer();
        $endpoint = 'http://127.0.0.1:' . $this->port . '/endpoint';
        $seen = [];

        $client = new SoapClient($this->wsdlFile($endpoint), $this->options());
        $client->setLocationCheck(function (string $location) use (&$seen) {
            $seen[] = $location;
        });

        $this->assertSame('pong', $client->ping('x'));
        $this->assertSame([$endpoint], $seen);
        $this->assertSame(['/endpoint'], $this->requests());
    }

    public function testSoapRequestDoesNotFollowRedirect(): void
    {
        $this->startServer();
        $client = new SoapClient(
            $this->wsdlFile('http://127.0.0.1:' . $this->port . '/moved-endpoint'),
            $this->options()
        );

        try {
            $client->ping('x');
            $this->fail('the redirect should not be followed');
        } catch (\SoapFault $e) {
            $this->assertStringContainsString('Redirection limit reached', $e->getMessage());
        }
        $this->assertSame(['/moved-endpoint'], $this->requests());
    }

    public function testWsdlDownloadDoesNotFollowRedirect(): void
    {
        $this->startServer();
        $this->wsdlFile('http://127.0.0.1:' . $this->port . '/endpoint');

        try {
            new SoapClient('http://127.0.0.1:' . $this->port . '/moved-wsdl', $this->options());
            $this->fail('the redirect should not be followed');
        } catch (\SoapFault $e) {
            $this->assertStringContainsString('Parsing WSDL', $e->getMessage());
        }
        $this->assertSame(['/moved-wsdl'], $this->requests());

        // The same document loads from its own address.
        $client = new SoapClient('http://127.0.0.1:' . $this->port . '/service.wsdl', $this->options());
        $this->assertNotEmpty($client->__getFunctions());
    }
}
