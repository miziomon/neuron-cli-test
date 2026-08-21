<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\Pratica;
use App\Workflow\Events\EventoDatiValidati;
use App\Workflow\Events\EventoHotel;
use App\Workflow\Nodes\VoliNode;
use App\Workflow\RichiestaInput;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;

class VoliNodeTest extends NodoTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/voli_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function stato(): WorkflowState
    {
        $pratica = Pratica::crea($this->directory, [
            'utente' => null,
            'viaggio' => ['destinazione' => 'Barcellona'],
            'volo_selezionato' => null,
            'hotel_selezionato' => null,
        ]);

        return new WorkflowState([
            'viaggio' => [
                'destinazione' => 'Barcellona',
                'aeroporto_partenza' => 'LIN',
                'aeroporto_destinazione' => 'BCN',
                'data_partenza' => '2026-09-15',
                'data_ritorno' => null,
                'adulti' => 2,
                'bambini' => 0,
            ],
            'pratica_percorso' => $pratica->percorso(),
        ]);
    }

    public function testSceltaVoloRegistraSelezioneEProduceEventoHotel(): void
    {
        $node = new VoliNode($this->agenteConRisposte(
            '{"risposta":"Hai scelto il volo 1!","ricercaCompletata":true,'
            . '"voloSelezionato":"Opzione 1: LIN-BCN 08:00, Vueling, 120 EUR","confermato":true}'
        ));
        $state = $this->stato();

        $risultato = $this->invoca($node, new EventoDatiValidati(), $state);

        $this->assertInstanceOf(EventoHotel::class, $risultato);
        $this->assertSame('Hai scelto il volo 1!', $risultato->saluto);

        $volo = $state->get('volo_selezionato');
        $this->assertSame('Opzione 1: LIN-BCN 08:00, Vueling, 120 EUR', $volo['descrizione']);
        $this->assertArrayHasKey('selezionato_il', $volo);

        // La pratica su disco è stata aggiornata
        $dati = json_decode((string) file_get_contents($state->get('pratica_percorso')), true);
        $this->assertSame('Opzione 1: LIN-BCN 08:00, Vueling, 120 EUR', $dati['volo_selezionato']['descrizione']);
    }

    public function testTurnoNonConfermatoInterrompeERipresaRiportaInput(): void
    {
        $node = new VoliNode($this->agenteConRisposte(
            '{"risposta":"Ecco i voli: 1) ... 2) ...","ricercaCompletata":true,"confermato":false}'
        ));
        $state = $this->stato();

        $interrupt = $this->invoca($node, new EventoDatiValidati(saluto: 'Grazie!'), $state);

        $this->assertInstanceOf(WorkflowInterrupt::class, $interrupt);
        // Il saluto del receptionist precede la risposta dell'agente voli
        $this->assertSame("Grazie!\n\nEcco i voli: 1) ... 2) ...", $interrupt->getRequest()->getMessage());

        // Ripresa: l'input rientra nel loop senza chiamare il modello
        $nodoRipresa = new VoliNode(static function (): never {
            throw new \LogicException('Il modello non deve essere chiamato al resume');
        });
        $risultato = $this->invoca($nodoRipresa, new EventoDatiValidati(), $state, new RichiestaInput('', 'scelgo la 2'));

        $this->assertInstanceOf(EventoDatiValidati::class, $risultato);
        $this->assertSame('scelgo la 2', $risultato->messaggioUtente);
    }

    public function testRinunciaAllaSelezioneProduceEventoHotelSenzaVolo(): void
    {
        $node = new VoliNode($this->agenteConRisposte(
            '{"risposta":"Va bene, nessuna selezione.","ricercaCompletata":true,"voloSelezionato":null,"confermato":true}'
        ));
        $state = $this->stato();

        $risultato = $this->invoca($node, new EventoDatiValidati(), $state);

        $this->assertInstanceOf(EventoHotel::class, $risultato);
        $this->assertNull($state->get('volo_selezionato'));

        $dati = json_decode((string) file_get_contents($state->get('pratica_percorso')), true);
        $this->assertNull($dati['volo_selezionato']);
    }
}
