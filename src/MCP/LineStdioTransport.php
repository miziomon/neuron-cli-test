<?php

declare(strict_types=1);

namespace App\MCP;

use JsonException;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\McpTransportInterface;

/**
 * Transport MCP su stdio che accumula la risposta finché non è un JSON completo.
 *
 * `NeuronAI\MCP\StdioTransport::receive()` usa json_decode con JSON_THROW_ON_ERROR
 * su ogni chunk parziale: risposte più grandi di 4 KB arrivano in più letture e il
 * primo chunk incompleto solleva un'eccezione. Questa variante tratta l'errore di
 * parsing come "risposta non ancora completa" e continua ad accumulare.
 */
class LineStdioTransport implements McpTransportInterface
{
    private mixed $process = null;

    /** @var array<int, resource>|null */
    private ?array $pipes = null;

    /**
     * @param array<string, mixed> $config Chiavi: command, args, env (come StdioTransport).
     */
    public function __construct(
        protected array $config
    ) {
    }

    public function connect(): void
    {
        $commandLine = $this->config['command'];
        foreach ($this->config['args'] ?? [] as $arg) {
            $commandLine .= ' ' . escapeshellarg((string) $arg);
        }

        $this->process = proc_open(
            $commandLine,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
            null,
            array_merge(getenv(), $this->config['env'] ?? []),
        );

        if (!is_resource($this->process)) {
            throw new McpException('Impossibile avviare il processo del server MCP');
        }

        stream_set_write_buffer($this->pipes[0], 0);
        stream_set_read_buffer($this->pipes[1], 0);
    }

    public function send(array $data): void
    {
        if (!is_resource($this->process) || !proc_get_status($this->process)['running']) {
            throw new McpException('Il processo del server MCP non è in esecuzione');
        }

        $json = json_encode($data);
        if ($json === false || fwrite($this->pipes[0], $json . "\n") === false) {
            throw new McpException('Impossibile inviare la richiesta al server MCP');
        }
        fflush($this->pipes[0]);
    }

    public function receive(): array
    {
        if (!is_resource($this->process)) {
            throw new McpException('Il processo del server MCP non è in esecuzione');
        }

        stream_set_blocking($this->pipes[1], false);

        $risposta = '';
        $inizio = microtime(true);

        while (microtime(true) - $inizio < 60.0) {
            if (!proc_get_status($this->process)['running']) {
                throw new McpException('Il processo del server MCP è terminato inaspettatamente.');
            }

            $chunk = fread($this->pipes[1], 65536);
            if ($chunk !== false && $chunk !== '') {
                $risposta .= $chunk;

                try {
                    return json_decode($risposta, true, 64, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    // Risposta parziale: si continua ad accumulare.
                }
            }

            usleep(10000);
        }

        throw new McpException('Timeout in attesa di risposta dal server MCP');
    }

    public function disconnect(): void
    {
        if (is_resource($this->process)) {
            foreach ($this->pipes ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (proc_get_status($this->process)['running'] && function_exists('proc_terminate')) {
                proc_terminate($this->process);
                usleep(500000);
            }
            proc_close($this->process);
            $this->process = null;
            $this->pipes = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
