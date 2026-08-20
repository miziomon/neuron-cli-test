<?php

declare(strict_types=1);

namespace App\Tests;

use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use RuntimeException;

/**
 * Provider finto che restituisce sempre lo stesso messaggio, senza chiamate HTTP.
 */
class FakeProvider implements AIProviderInterface
{
    public function __construct(
        private readonly Message $risposta
    ) {
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        throw new RuntimeException('Non usato nei test');
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        throw new RuntimeException('Non usato nei test');
    }

    public function chat(Message ...$messages): Message
    {
        return $this->risposta;
    }

    public function stream(Message ...$messages): Generator
    {
        return $this->risposta;
        yield; // necessario per rendere il metodo un generatore
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        return $this->risposta;
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        return $this;
    }
}
