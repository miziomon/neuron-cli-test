<?php

declare(strict_types=1);

namespace App\Tests;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;

/**
 * Agente di test che risponde sempre con lo stesso messaggio tramite FakeProvider.
 */
class AgenteFinto extends Agent
{
    public function __construct(
        private readonly Message $risposta
    ) {
        parent::__construct();
    }

    protected function provider(): AIProviderInterface
    {
        return new FakeProvider($this->risposta);
    }

    protected function instructions(): string
    {
        return 'Agente di test';
    }
}
