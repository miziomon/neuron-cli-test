<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\Pratica;
use App\Workflow\Events\EventoFine;
use App\Workflow\Events\EventoHotel;
use App\Workflow\Nodes\HotelNode;
use NeuronAI\Workflow\WorkflowState;

class HotelNodeTest extends NodoTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/hotel_test_' . uniqid();
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

    private function stato(int $bambini = 0): WorkflowState
    {
        $pratica = Pratica::crea($this->directory, [
            'utente' => null,
            'viaggio' => ['destinazione' => 'Barcellona'],
            'volo_selezionato' => ['descrizione' => 'Volo 1'],
            'hotel_selezionato' => null,
        ]);

        return new WorkflowState([
            'viaggio' => [
                'destinazione' => 'Barcellona',
                'aeroporto_partenza' => 'LIN',
                'aeroporto_destinazione' => 'BCN',
                'data_partenza' => '2026-09-15',
                'data_ritorno' => '2026-09-20',
                'adulti' => 2,
                'bambini' => $bambini,
            ],
            'pratica_percorso' => $pratica->percorso(),
        ]);
    }

    public function testNessunHotelRichiestoProduceEventoFine(): void
    {
        $node = new HotelNode($this->agenteConRisposte(
            '{"risposta":"Va bene, nessun hotel.","hotelRichiesto":false,"confermato":true}'
        ));
        $state = $this->stato();

        $risultato = $this->invoca($node, new EventoHotel(), $state);

        $this->assertInstanceOf(EventoFine::class, $risultato);
        $this->assertNull($state->get('hotel_selezionato'));
    }

    public function testSelezioneValidaRegistraHotelEProduceEventoFine(): void
    {
        $node = new HotelNode($this->agenteConRisposte(
            '{"risposta":"Hai scelto l\'hotel 1!","hotelRichiesto":true,"dataCheckIn":"2026-09-15",'
            . '"dataCheckOut":"2026-09-20","camere":1,"hotelSelezionato":"Opzione 1: Hotel Sol, 4 stelle, 500 EUR","confermato":true}'
        ));
        $state = $this->stato();

        $risultato = $this->invoca($node, new EventoHotel(), $state);

        $this->assertInstanceOf(EventoFine::class, $risultato);

        $hotel = $state->get('hotel_selezionato');
        $this->assertSame('Opzione 1: Hotel Sol, 4 stelle, 500 EUR', $hotel['descrizione']);
        $this->assertSame('2026-09-15', $hotel['check_in']);
        $this->assertSame('2026-09-20', $hotel['check_out']);
        $this->assertSame(1, $hotel['camere']);

        $dati = json_decode((string) file_get_contents($state->get('pratica_percorso')), true);
        $this->assertSame('Opzione 1: Hotel Sol, 4 stelle, 500 EUR', $dati['hotel_selezionato']['descrizione']);
    }

    public function testSelezioneConEtaErrateTornaAlLoopConErrori(): void
    {
        $node = new HotelNode($this->agenteConRisposte(
            '{"risposta":"Scelto.","hotelRichiesto":true,"dataCheckIn":"2026-09-15","dataCheckOut":"2026-09-20",'
            . '"etaBambini":"5","hotelSelezionato":"Hotel Sol","confermato":true}'
        ));
        $state = $this->stato(bambini: 2);

        $risultato = $this->invoca($node, new EventoHotel(), $state);

        $this->assertInstanceOf(EventoHotel::class, $risultato);
        $this->assertContains('le età dei bambini devono essere esattamente 2', $risultato->errori);
        $this->assertNull($state->get('hotel_selezionato'));
    }
}
