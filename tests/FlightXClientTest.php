<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\FlightX\Exceptions\FlightXConfigurationException;
use App\Services\FlightX\Exceptions\FlightXValidationException;
use App\Services\FlightX\FlightXClient;
use App\Services\FlightX\FlightXConfig;
use PHPUnit\Framework\TestCase;

/**
 * Validazioni locali del wrapper FlightX: nessuna chiamata di rete,
 * i controlli su IATA/date/passeggeri avvengono prima dell'HTTP.
 */
class FlightXClientTest extends TestCase
{
    private function client(): FlightXClient
    {
        return new FlightXClient(new FlightXConfig(
            baseUrl: 'https://api.stage.flightx.app',
            apiKey: 'test',
            username: 'test@example.com',
            passwordMd5: md5('test'),
        ));
    }

    public function testConfigRichiedeCredenziali(): void
    {
        $this->expectException(FlightXConfigurationException::class);
        new FlightXConfig(baseUrl: 'https://api.stage.flightx.app', apiKey: '', username: 'x');
    }

    public function testConfigRichiedePasswordOMd5(): void
    {
        $this->expectException(FlightXConfigurationException::class);
        new FlightXConfig(baseUrl: 'https://api.stage.flightx.app', apiKey: 'k', username: 'u');
    }

    public function testConfigAccettaPasswordMd5(): void
    {
        $config = FlightXConfig::fromArray([
            'base_url' => 'https://api.stage.flightx.app',
            'api_key' => 'k',
            'username' => 'u',
            'password_md5' => md5('segreta'),
        ]);

        $this->assertSame(md5('segreta'), $config->passwordMd5);
    }

    public function testCodiceIataNonValido(): void
    {
        $this->expectException(FlightXValidationException::class);
        $this->client()->searchFlights('LINO', 'BCN', '2026-09-15');
    }

    public function testDataNonValida(): void
    {
        $this->expectException(FlightXValidationException::class);
        $this->client()->searchFlights('LIN', 'BCN', '15-09-2026');
    }

    public function testServeAlmenoUnPasseggero(): void
    {
        $this->expectException(FlightXValidationException::class);
        $this->client()->searchFlights('LIN', 'BCN', '2026-09-15', adults: 0, children: 0);
    }

    public function testMassimoNovePasseggeri(): void
    {
        $this->expectException(FlightXValidationException::class);
        $this->client()->searchFlights('LIN', 'BCN', '2026-09-15', adults: 10);
    }

    public function testAndataERitornoRichiedeDataRitorno(): void
    {
        $this->expectException(FlightXValidationException::class);
        $this->client()->searchFlights('LIN', 'BCN', '2026-09-15', searchType: 'RT');
    }

    public function testSelezionaSenzaRicercaPrecedente(): void
    {
        $this->expectException(FlightXValidationException::class);
        $this->client()->selectFlightOption('1_1', 'chiave', ['opzione'], adults: 1);
    }
}
