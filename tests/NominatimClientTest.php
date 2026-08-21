<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\Geocoding\Exceptions\GeocodingConfigurationException;
use App\Services\Geocoding\Exceptions\GeocodingValidationException;
use App\Services\Geocoding\NominatimClient;
use App\Services\Geocoding\NominatimConfig;
use PHPUnit\Framework\TestCase;

/**
 * Validazioni locali del wrapper Nominatim: nessuna chiamata di rete,
 * i controlli su query/limite/country codes avvengono prima dell'HTTP.
 */
class NominatimClientTest extends TestCase
{
    protected function setUp(): void
    {
        NominatimClient::resetThrottle();
    }

    private function client(): NominatimClient
    {
        return new NominatimClient(new NominatimConfig(
            baseUrl: 'https://nominatim.openstreetmap.org',
            userAgent: 'neuron-test/1.0',
        ));
    }

    public function testConfigRichiedeUserAgent(): void
    {
        $this->expectException(GeocodingConfigurationException::class);
        new NominatimConfig(baseUrl: 'https://nominatim.openstreetmap.org', userAgent: '');
    }

    public function testConfigRichiedeBaseUrlValido(): void
    {
        $this->expectException(GeocodingConfigurationException::class);
        new NominatimConfig(baseUrl: 'non-un-url', userAgent: 'neuron-test/1.0');
    }

    public function testQueryVuota(): void
    {
        $this->expectException(GeocodingValidationException::class);
        $this->client()->search('   ');
    }

    public function testLimiteFuoriRange(): void
    {
        $this->expectException(GeocodingValidationException::class);
        $this->client()->search('Torino', limit: 51);
    }

    public function testCountryCodesNonValidi(): void
    {
        $this->expectException(GeocodingValidationException::class);
        $this->client()->search('Torino', countryCodes: 'italia');
    }

    public function testRicercaStrutturataSenzaCampi(): void
    {
        $this->expectException(GeocodingValidationException::class);
        $this->client()->searchStructured();
    }
}
