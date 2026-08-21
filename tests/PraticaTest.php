<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\Pratica;
use PHPUnit\Framework\TestCase;

class PraticaTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pratica_test_' . uniqid();
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

    public function testCreaFileConNomeTimestampEDatiIniziali(): void
    {
        $pratica = Pratica::crea($this->directory, [
            'utente' => ['nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario.rossi@example.com'],
            'viaggio' => ['destinazione' => 'Barcellona'],
            'volo_selezionato' => null,
            'hotel_selezionato' => null,
        ]);

        $this->assertMatchesRegularExpression('/pratica_\d{8}_\d{6}\.json$/', $pratica->percorso());
        $this->assertFileExists($pratica->percorso());

        $dati = json_decode((string) file_get_contents($pratica->percorso()), true);
        $this->assertSame('Mario', $dati['utente']['nome']);
        $this->assertSame('Barcellona', $dati['viaggio']['destinazione']);
        $this->assertNull($dati['volo_selezionato']);
        $this->assertArrayHasKey('raccolto_il', $dati);
    }

    public function testAggiornaAggiungeLeSelezioniSenzaPerdereIDati(): void
    {
        $pratica = Pratica::crea($this->directory, [
            'utente' => ['nome' => 'Mario'],
            'volo_selezionato' => null,
        ]);

        $pratica->aggiorna(['volo_selezionato' => ['descrizione' => '1) LIN → BCN', 'selezionato_il' => date(DATE_ATOM)]]);
        $pratica->aggiorna(['hotel_selezionato' => ['descrizione' => '2) Hotel Example', 'selezionato_il' => date(DATE_ATOM)]]);

        $dati = json_decode((string) file_get_contents($pratica->percorso()), true);
        $this->assertSame('Mario', $dati['utente']['nome']);
        $this->assertSame('1) LIN → BCN', $dati['volo_selezionato']['descrizione']);
        $this->assertSame('2) Hotel Example', $dati['hotel_selezionato']['descrizione']);

        // Lo stato in memoria riflette il contenuto del file
        $this->assertSame($dati, $pratica->dati());
    }

    public function testApriRiapreUnaPraticaEsistente(): void
    {
        $pratica = Pratica::crea($this->directory, [
            'utente' => ['nome' => 'Mario'],
            'volo_selezionato' => null,
        ]);

        $riaperta = Pratica::apri($pratica->percorso());
        $riaperta->aggiorna(['volo_selezionato' => ['descrizione' => '1) LIN → BCN']]);

        $dati = json_decode((string) file_get_contents($pratica->percorso()), true);
        $this->assertSame('Mario', $dati['utente']['nome']);
        $this->assertSame('1) LIN → BCN', $dati['volo_selezionato']['descrizione']);
    }

    public function testApriConFileInesistenteLanciaEccezione(): void
    {
        $this->expectException(\RuntimeException::class);

        Pratica::apri($this->directory . '/non_esiste.json');
    }
}
