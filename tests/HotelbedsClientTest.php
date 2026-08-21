<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\Hotelbeds\Exceptions\HotelbedsConfigurationException;
use App\Services\Hotelbeds\Exceptions\HotelbedsValidationException;
use App\Services\Hotelbeds\HotelbedsClient;
use App\Services\Hotelbeds\HotelbedsConfig;
use PHPUnit\Framework\TestCase;

/**
 * Validazioni locali del wrapper Hotelbeds: nessuna chiamata di rete,
 * i controlli su date/coordinate/occupazioni avvengono prima dell'HTTP.
 */
class HotelbedsClientTest extends TestCase
{
    private string $checkIn;

    private string $checkOut;

    protected function setUp(): void
    {
        $this->checkIn = date('Y-m-d', strtotime('+30 days'));
        $this->checkOut = date('Y-m-d', strtotime('+32 days'));
    }

    private function client(): HotelbedsClient
    {
        return new HotelbedsClient(new HotelbedsConfig(
            baseUrl: 'https://api.test.hotelbeds.com/hotel-api/1.0',
            apiKey: 'test',
            secret: 'test',
        ));
    }

    public function testConfigRichiedeCredenziali(): void
    {
        $this->expectException(HotelbedsConfigurationException::class);
        new HotelbedsConfig(baseUrl: 'https://api.test.hotelbeds.com', apiKey: '', secret: 'x');
    }

    public function testConfigRichiedeBaseUrlValido(): void
    {
        $this->expectException(HotelbedsConfigurationException::class);
        new HotelbedsConfig(baseUrl: 'non-un-url', apiKey: 'k', secret: 's');
    }

    public function testCheckInNelPassato(): void
    {
        $this->expectException(HotelbedsValidationException::class);
        $this->client()->searchByGeolocation(45.0, 7.6, date('Y-m-d', strtotime('-2 days')), $this->checkOut);
    }

    public function testCheckOutNonSuccessivoAlCheckIn(): void
    {
        $this->expectException(HotelbedsValidationException::class);
        $this->client()->searchByGeolocation(45.0, 7.6, $this->checkIn, $this->checkIn);
    }

    public function testDataMalformata(): void
    {
        $this->expectException(HotelbedsValidationException::class);
        $this->client()->searchByGeolocation(45.0, 7.6, '15-09-2026', $this->checkOut);
    }

    public function testLatitudineFuoriRange(): void
    {
        $this->expectException(HotelbedsValidationException::class);
        $this->client()->searchByGeolocation(91.0, 7.6, $this->checkIn, $this->checkOut);
    }

    public function testRaggioFuoriRange(): void
    {
        $this->expectException(HotelbedsValidationException::class);
        $this->client()->searchByGeolocation(45.0, 7.6, $this->checkIn, $this->checkOut, radius: 201);
    }

    public function testUnitaNonValida(): void
    {
        $this->expectException(HotelbedsValidationException::class);
        $this->client()->searchByGeolocation(45.0, 7.6, $this->checkIn, $this->checkOut, unit: 'm');
    }

    public function testRettangoloDegenere(): void
    {
        $this->expectException(HotelbedsValidationException::class);
        $this->client()->searchByArea(45.0, 7.6, 45.0, 8.0, $this->checkIn, $this->checkOut);
    }

    public function testBambiniSenzaEta(): void
    {
        $this->expectException(HotelbedsValidationException::class);
        $this->client()->searchByGeolocation(45.0, 7.6, $this->checkIn, $this->checkOut, children: 2, childrenAges: [5]);
    }

    public function testEtaBambinoFuoriRange(): void
    {
        $this->expectException(HotelbedsValidationException::class);
        $this->client()->searchByGeolocation(45.0, 7.6, $this->checkIn, $this->checkOut, children: 1, childrenAges: [18]);
    }

    public function testCodiceDestinazioneNonValido(): void
    {
        $this->expectException(HotelbedsValidationException::class);
        $this->client()->searchByDestination('TORINO', $this->checkIn, $this->checkOut);
    }

    public function testCodiciHotelVuoti(): void
    {
        $this->expectException(HotelbedsValidationException::class);
        $this->client()->searchByHotelCodes([], $this->checkIn, $this->checkOut);
    }

    public function testCodiciHotelNonNumerici(): void
    {
        $this->expectException(HotelbedsValidationException::class);
        $this->client()->searchByHotelCodes(['abc'], $this->checkIn, $this->checkOut);
    }

    public function testFiltroConChiaveSconosciuta(): void
    {
        $this->expectException(HotelbedsValidationException::class);
        $this->client()->searchByGeolocation(45.0, 7.6, $this->checkIn, $this->checkOut, filter: ['sconto' => 10]);
    }

    public function testCheckRatesVuoti(): void
    {
        $this->expectException(HotelbedsValidationException::class);
        $this->client()->checkRates([]);
    }
}
