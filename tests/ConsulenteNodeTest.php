<?php

declare(strict_types=1);

namespace App\Tests;

use App\Workflow\Events\EventoConsulenza;
use App\Workflow\Events\EventoRaccoltaDati;
use App\Workflow\Nodes\ConsulenteNode;
use App\Workflow\RichiestaInput;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;

class ConsulenteNodeTest extends NodoTestCase
{
    public function testTurnoNonProntoInterrompePerInput(): void
    {
        $node = new ConsulenteNode($this->agenteConRisposte(
            '{"risposta":"Ti consiglio la Sardegna per il mare.","prontoAPrenotare":false}'
        ));
        $state = new WorkflowState();

        $risultato = $this->invoca($node, new EventoConsulenza(), $state);

        $this->assertInstanceOf(WorkflowInterrupt::class, $risultato);
        $richiesta = $risultato->getRequest();
        $this->assertInstanceOf(RichiestaInput::class, $richiesta);
        $this->assertSame('Ti consiglio la Sardegna per il mare.', $richiesta->getMessage());

        // La history e l'usage sono stati salvati nello stato del workflow
        $this->assertIsString($state->get('storia_consulente'));
        $this->assertSame(['in' => 100, 'out' => 20, 'tot' => 120], $state->get('ultimo_usage'));
    }

    public function testRipresaRiportaInputNelLoopSenzaChiamareIlModello(): void
    {
        // La factory lancia se viene invocata: al resume non serve il modello
        $node = new ConsulenteNode(static function (): never {
            throw new \LogicException('Il modello non deve essere chiamato al resume');
        });
        $state = new WorkflowState();

        $risultato = $this->invoca($node, new EventoConsulenza(), $state, new RichiestaInput('', 'Dimmi di più'));

        $this->assertInstanceOf(EventoConsulenza::class, $risultato);
        $this->assertSame('Dimmi di più', $risultato->messaggio);
    }

    public function testProntoAPrenotareProduceEventoRaccoltaDatiConSuggerimenti(): void
    {
        $node = new ConsulenteNode($this->agenteConRisposte(
            '{"risposta":"Passiamo alla prenotazione!","prontoAPrenotare":true,'
            . '"destinazioneSuggerita":"Tokyo","aeroportoPartenzaSuggerito":"MXP","aeroportoDestinazioneSuggerito":"NRT",'
            . '"dataPartenzaSuggerita":"2026-10-10","dataRitornoSuggerita":null,"note":"budget 2000 euro"}'
        ));
        $state = new WorkflowState();

        $risultato = $this->invoca($node, new EventoConsulenza('voglio prenotare'), $state);

        $this->assertInstanceOf(EventoRaccoltaDati::class, $risultato);
        $this->assertSame('Tokyo', $risultato->suggerimenti['destinazione']);
        $this->assertSame('MXP', $risultato->suggerimenti['aeroporto_partenza']);
        $this->assertSame('NRT', $risultato->suggerimenti['aeroporto_destinazione']);
        $this->assertSame('2026-10-10', $risultato->suggerimenti['data_partenza']);
        $this->assertArrayNotHasKey('data_ritorno', $risultato->suggerimenti);
        $this->assertSame('budget 2000 euro', $risultato->suggerimenti['note']);
    }

    public function testNodoSerializzabileAncheConFactoryClosure(): void
    {
        $node = new ConsulenteNode($this->agenteConRisposte('{}'));
        $node->setWorkflowContext(new WorkflowState(), new EventoConsulenza());

        $ripristinato = unserialize(serialize($node));

        $this->assertInstanceOf(ConsulenteNode::class, $ripristinato);
    }
}
