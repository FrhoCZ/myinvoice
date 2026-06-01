<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Azure;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Azure Functions JSON configuration files added in this PR:
 *   - host.json            — Functions host, custom handler and logging settings
 *   - HttpTrigger/function.json — catch-all HTTP trigger definition
 *
 * These files are static JSON; the tests validate their structure and values
 * to catch accidental edits that would break the Azure Functions deployment.
 */
final class AzureFunctionConfigTest extends TestCase
{
    /** Absolute path to the repository root. */
    private string $repoRoot;

    protected function setUp(): void
    {
        // From api/tests/Unit/Infrastructure/Azure/ we go up 5 directories to reach the repo root
        $this->repoRoot = dirname(__DIR__, 5);
    }

    // ── host.json ─────────────────────────────────────────────────────────────

    private function loadHostJson(): array
    {
        $path = $this->repoRoot . '/host.json';
        self::assertFileExists($path, 'host.json must exist in the repo root');

        $json = file_get_contents($path);
        self::assertIsString($json);

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'host.json must decode to an array');

        return $decoded;
    }

    public function testHostJsonIsValidJson(): void
    {
        // loadHostJson() already asserts this; call it and verify it returns an array
        $host = $this->loadHostJson();
        self::assertIsArray($host);
    }

    public function testHostJsonVersionIs2(): void
    {
        $host = $this->loadHostJson();

        self::assertArrayHasKey('version', $host);
        self::assertSame('2.0', $host['version']);
    }

    public function testHostJsonHasExtensionBundle(): void
    {
        $host = $this->loadHostJson();

        self::assertArrayHasKey('extensionBundle', $host);
        $bundle = $host['extensionBundle'];

        self::assertArrayHasKey('id', $bundle);
        self::assertSame('Microsoft.Azure.Functions.ExtensionBundle', $bundle['id']);

        self::assertArrayHasKey('version', $bundle);
        // Must reference the v4 bundle range
        self::assertStringContainsString('4.*', $bundle['version']);
    }

    public function testHostJsonHasCustomHandler(): void
    {
        $host = $this->loadHostJson();

        self::assertArrayHasKey('customHandler', $host);
        $ch = $host['customHandler'];

        self::assertArrayHasKey('description', $ch);
        self::assertArrayHasKey('defaultExecutablePath', $ch['description']);
        self::assertSame('bash', $ch['description']['defaultExecutablePath']);

        self::assertArrayHasKey('arguments', $ch['description']);
        self::assertContains('azure-start.sh', $ch['description']['arguments']);
    }

    public function testHostJsonEnablesHttpRequestForwarding(): void
    {
        $host = $this->loadHostJson();

        self::assertArrayHasKey('customHandler', $host);
        self::assertArrayHasKey('enableForwardingHttpRequest', $host['customHandler']);
        self::assertTrue($host['customHandler']['enableForwardingHttpRequest']);
    }

    public function testHostJsonHasLoggingSection(): void
    {
        $host = $this->loadHostJson();

        self::assertArrayHasKey('logging', $host);
        $logging = $host['logging'];

        self::assertArrayHasKey('applicationInsights', $logging);
        $ai = $logging['applicationInsights'];
        self::assertArrayHasKey('samplingSettings', $ai);
        self::assertTrue($ai['samplingSettings']['isEnabled']);

        self::assertArrayHasKey('logLevel', $logging);
        $logLevel = $logging['logLevel'];
        self::assertArrayHasKey('default', $logLevel);
        self::assertSame('Information', $logLevel['default']);
    }

    public function testHostJsonLogLevelHasRequiredKeys(): void
    {
        $host     = $this->loadHostJson();
        $logLevel = $host['logging']['logLevel'];

        foreach (['default', 'Host.Results', 'Function', 'Host.Aggregator'] as $key) {
            self::assertArrayHasKey($key, $logLevel, "logLevel must have key '{$key}'");
        }
    }

    public function testHostJsonSamplingExcludesRequestType(): void
    {
        $host     = $this->loadHostJson();
        $sampling = $host['logging']['applicationInsights']['samplingSettings'];

        self::assertArrayHasKey('excludedTypes', $sampling);
        self::assertStringContainsString('Request', $sampling['excludedTypes']);
    }

    // ── HttpTrigger/function.json ─────────────────────────────────────────────

    private function loadFunctionJson(): array
    {
        $path = $this->repoRoot . '/HttpTrigger/function.json';
        self::assertFileExists($path, 'HttpTrigger/function.json must exist');

        $json = file_get_contents($path);
        self::assertIsString($json);

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'HttpTrigger/function.json must decode to an array');

        return $decoded;
    }

    public function testFunctionJsonIsValidJson(): void
    {
        $func = $this->loadFunctionJson();
        self::assertIsArray($func);
    }

    public function testFunctionJsonHasBindingsArray(): void
    {
        $func = $this->loadFunctionJson();

        self::assertArrayHasKey('bindings', $func);
        self::assertIsArray($func['bindings']);
        self::assertNotEmpty($func['bindings'], 'bindings array must not be empty');
    }

    public function testFunctionJsonHasExactlyTwoBindings(): void
    {
        $func = $this->loadFunctionJson();

        self::assertCount(2, $func['bindings'], 'HttpTrigger function must have exactly 2 bindings');
    }

    public function testFunctionJsonInboundBindingIsHttpTrigger(): void
    {
        $func     = $this->loadFunctionJson();
        $inbound  = $func['bindings'][0];

        self::assertSame('httpTrigger', $inbound['type']);
        self::assertSame('in', $inbound['direction']);
        self::assertSame('req', $inbound['name']);
    }

    public function testFunctionJsonInboundBindingIsAnonymous(): void
    {
        $func    = $this->loadFunctionJson();
        $inbound = $func['bindings'][0];

        self::assertArrayHasKey('authLevel', $inbound);
        self::assertSame('anonymous', $inbound['authLevel']);
    }

    public function testFunctionJsonInboundBindingHasCatchAllRoute(): void
    {
        $func    = $this->loadFunctionJson();
        $inbound = $func['bindings'][0];

        self::assertArrayHasKey('route', $inbound);
        self::assertSame('{*route}', $inbound['route']);
    }

    public function testFunctionJsonSupportsAllHttpMethods(): void
    {
        $func    = $this->loadFunctionJson();
        $inbound = $func['bindings'][0];

        self::assertArrayHasKey('methods', $inbound);
        $methods = $inbound['methods'];

        $requiredMethods = ['get', 'post', 'put', 'delete', 'patch', 'head', 'options'];
        foreach ($requiredMethods as $method) {
            self::assertContains(
                $method,
                $methods,
                "HTTP method '{$method}' must be listed in the trigger methods",
            );
        }
    }

    public function testFunctionJsonOutboundBindingIsHttpResponse(): void
    {
        $func     = $this->loadFunctionJson();
        $outbound = $func['bindings'][1];

        self::assertSame('http', $outbound['type']);
        self::assertSame('out', $outbound['direction']);
        self::assertSame('res', $outbound['name']);
    }

    // ── Cross-file consistency ────────────────────────────────────────────────

    public function testCustomHandlerStartScriptMatchesRepoFile(): void
    {
        $host      = $this->loadHostJson();
        $scriptArg = $host['customHandler']['description']['arguments'][0] ?? '';

        $scriptPath = $this->repoRoot . '/' . $scriptArg;
        self::assertFileExists(
            $scriptPath,
            "The script referenced in host.json ({$scriptArg}) must exist in the repo root",
        );
    }

    public function testCustomHandlerStartScriptIsExecutable(): void
    {
        $host      = $this->loadHostJson();
        $scriptArg = $host['customHandler']['description']['arguments'][0] ?? '';
        $scriptPath = $this->repoRoot . '/' . $scriptArg;

        self::assertFileExists($scriptPath);
        self::assertTrue(
            is_executable($scriptPath),
            "azure-start.sh must be executable (chmod +x)",
        );
    }
}