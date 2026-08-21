<?php

declare(strict_types=1);

namespace App\Tests;

use App\Neuron\TurnoHotel;
use App\Neuron\TurnoReceptionist;
use App\Support\Validazione;
use PHPUnit\Framework\TestCase;

class ValidazioneTest extends TestCase
{
    private function turnoViaggioCompleto(): TurnoReceptionist
    {
        $turno = new TurnoReceptionist();
        $turno->risposta = 'ok';
        $turno->destinazione = 'Barcellona';
        $turno->aeroportoPartenza = 'LIN';
        $turno->aeroportoDestinazione = 'BCN';
        $turno->dataPartenza = '2026-09-15';
        $turno->dataRitorno = null;
        $turno->adulti = 2;
        $turno->bambini = 1;

        return $turno;
    }

    public function testViaggioCompletoSenzaAnagraficaNonHaErroriNéMancanti(): void
    {
        $turno = $this->turnoViaggioCompleto();

        $this->assertSame([], Validazione::erroriDatiViaggio($turno));
        $this->assertSame([], Validazione::campiMancanti($turno));
        $this->assertNull(Validazione::anagrafica($turno));
        $this->assertFalse(Validazione::nessunDatoViaggio($turno));
    }

    public function testAnagraficaMalformataVieneScartataSenzaErrori(): void
    {
        $turno = $this->turnoViaggioCompleto();
        $turno->nome = 'M4rio';
        $turno->email = 'non-una-email';

        $this->assertSame([], Validazione::erroriDatiViaggio($turno));
        $this->assertNull(Validazione::anagrafica($turno));
    }

    public function testAnagraficaValidaVieneMantenuta(): void
    {
        $turno = $this->turnoViaggioCompleto();
        $turno->nome = ' Mario ';
        $turno->cognome = "D'Angelo";
        $turno->email = 'mario@example.com';

        $this->assertSame(
            ['nome' => 'Mario', 'cognome' => "D'Angelo", 'email' => 'mario@example.com'],
            Validazione::anagrafica($turno),
        );
    }

    public function testCampiViaggioNonValidiGeneranoErrori(): void
    {
        $turno = $this->turnoViaggioCompleto();
        $turno->aeroportoPartenza = 'MILANO';
        $turno->dataPartenza = '15/09/2026';
        $turno->adulti = 0;

        $errori = Validazione::erroriDatiViaggio($turno);

        $this->assertCount(3, $errori);
        $this->assertStringContainsString('IATA', $errori[0]);
        $this->assertStringContainsString('YYYY-MM-DD', $errori[1]);
        $this->assertStringContainsString('almeno 1 adulto', $errori[2]);
    }

    public function testRitornoPrecedenteAllaPartenza(): void
    {
        $turno = $this->turnoViaggioCompleto();
        $turno->dataRitorno = '2026-09-10';

        $this->assertSame(['la data di ritorno precede la partenza'], Validazione::erroriDatiViaggio($turno));
    }

    public function testCampiMancantiElencaSoloIObbligatoriDelViaggio(): void
    {
        $turno = new TurnoReceptionist();
        $turno->risposta = 'ok';
        $turno->destinazione = 'Roma';

        $mancanti = Validazione::campiMancanti($turno);

        $this->assertContains("l'aeroporto di partenza", $mancanti);
        $this->assertContains('il numero di adulti', $mancanti);
        $this->assertNotContains('la destinazione', $mancanti);
        $this->assertFalse(Validazione::nessunDatoViaggio($turno));
    }

    public function testNessunDatoViaggio(): void
    {
        $this->assertTrue(Validazione::nessunDatoViaggio(new TurnoReceptionist()));
        $this->assertFalse(Validazione::nessunDatoViaggio($this->turnoViaggioCompleto()));
    }

    public function testErroriHotel(): void
    {
        $turno = new TurnoHotel();
        $turno->risposta = 'ok';
        $turno->hotelSelezionato = 'Hotel Sol';
        $turno->dataCheckIn = '2026-09-15';
        $turno->dataCheckOut = '2026-09-10';
        $turno->etaBambini = '5';

        $errori = Validazione::erroriHotel($turno, 2);

        $this->assertContains('la data di check-out deve essere successiva al check-in', $errori);
        $this->assertContains('le età dei bambini devono essere esattamente 2', $errori);
    }

    public function testErroriHotelIgnoraLaRinuncia(): void
    {
        $turno = new TurnoHotel();
        $turno->risposta = 'ok';

        $this->assertSame([], Validazione::erroriHotel($turno, 2));
    }

    public function testParseEtaBambini(): void
    {
        $this->assertSame([], Validazione::parseEtaBambini(null));
        $this->assertSame([], Validazione::parseEtaBambini(''));
        $this->assertSame([5, 8], Validazione::parseEtaBambini('5, 8'));
    }
}
