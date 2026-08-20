<?php

declare(strict_types=1);

namespace App\Neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;

/**
 * Agente base con provider OpenRouter condiviso da tutti gli agenti dell'applicazione.
 */
abstract class OpenRouterAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new OpenAILike(
            baseUri: 'https://openrouter.ai/api/v1',
            key: $_ENV['OPENROUTER_API_KEY'],
            model: $_ENV['OPENROUTER_MODEL'],
        );
    }
}
