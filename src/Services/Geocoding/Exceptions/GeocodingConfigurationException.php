<?php

namespace App\Services\Geocoding\Exceptions;

/**
 * Configurazione del client non valida: `baseUrl` mancante/malformato, oppure
 * `userAgent` assente (obbligatorio per la policy d'uso di Nominatim). Sollevata
 * da {@see \App\Services\Geocoding\NominatimConfig} prima di ogni richiesta di rete.
 */
final class GeocodingConfigurationException extends GeocodingException {}
