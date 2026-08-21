<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\StoriaChat;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

class StoriaChatTest extends TestCase
{
    public function testRoundTripJsonPreservaMessaggiEUsage(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('Voglio andare al mare'));
        $history->addMessage(
            (new AssistantMessage('Ti consiglio la Sardegna'))
                ->setUsage(new Usage(inputTokens: 120, outputTokens: 30))
        );

        $json = StoriaChat::daChatHistory($history);
        $ripristinata = StoriaChat::daJson($json);

        $messaggi = $ripristinata->getMessages();
        $this->assertCount(2, $messaggi);
        $this->assertInstanceOf(UserMessage::class, $messaggi[0]);
        $this->assertSame('Voglio andare al mare', $messaggi[0]->getContent());
        $this->assertInstanceOf(AssistantMessage::class, $messaggi[1]);
        $this->assertSame('Ti consiglio la Sardegna', $messaggi[1]->getContent());

        $usage = $messaggi[1]->getUsage();
        $this->assertNotNull($usage);
        $this->assertSame(120, $usage->inputTokens);
        $this->assertSame(30, $usage->outputTokens);
    }

    public function testDaJsonVuotoONonValidoProduceHistoryVuota(): void
    {
        $this->assertSame([], StoriaChat::daJson('')->getMessages());
        $this->assertSame([], StoriaChat::daJson('non-json')->getMessages());
    }

    public function testHistoryRipristinataSopravviveAllaSerializzazionePhp(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('Ciao'));

        $json = StoriaChat::daChatHistory($history);
        $ripristinata = unserialize(serialize(StoriaChat::daJson($json)));

        $this->assertCount(1, $ripristinata->getMessages());
    }
}
