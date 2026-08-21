<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifica l'handshake del server MCP hotel su stdio (initialize + tools/list).
 * Nessuna chiamata alle API Hotelbeds/Nominatim: i tool vengono solo elencati, mai invocati.
 */
class HotelbedsMcpServerTest extends TestCase
{
    /** @var array<int, resource> */
    private array $pipes = [];

    /** @var resource */
    private $process;

    protected function setUp(): void
    {
        $this->process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/hotelbeds-mcp.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
        );
        $this->assertIsResource($this->process, 'Il processo del server MCP non si è avviato');
        stream_set_timeout($this->pipes[1], 10);
    }

    protected function tearDown(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    private function richiedi(array $richiesta): array
    {
        fwrite($this->pipes[0], json_encode($richiesta) . "\n");
        $linea = fgets($this->pipes[1]);
        $this->assertNotFalse($linea, 'Nessuna risposta dal server MCP');

        return json_decode((string) $linea, true, 64, JSON_THROW_ON_ERROR);
    }

    public function testHandshakeEToolsList(): void
    {
        $init = $this->richiedi([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ]);

        $this->assertSame(1, $init['id']);
        $this->assertSame('hotelbeds-mcp', $init['result']['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $init['result']['capabilities']);

        $lista = $this->richiedi(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);

        $nomi = array_column($lista['result']['tools'], 'name');
        $this->assertSame(['cerca_hotel'], $nomi);

        // Ogni tool deve avere uno schema di input valido per il connettore Neuron
        foreach ($lista['result']['tools'] as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertArrayHasKey('properties', $tool['inputSchema']);
        }

        $cerca = $lista['result']['tools'][0];
        $this->assertSame(['destinazione', 'check_in', 'check_out'], $cerca['inputSchema']['required']);
    }

    public function testToolSconosciutoRestituisceErroreLeggibile(): void
    {
        $risposta = $this->richiedi([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'non_esiste', 'arguments' => new \stdClass()],
        ]);

        $this->assertTrue($risposta['result']['isError']);
        $this->assertStringContainsString('Tool sconosciuto', $risposta['result']['content'][0]['text']);
    }
}
