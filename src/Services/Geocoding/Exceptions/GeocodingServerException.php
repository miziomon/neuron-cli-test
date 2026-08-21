<?php

namespace App\Services\Geocoding\Exceptions;

/**
 * Risposta HTTP >= 500: errore lato server del servizio di geocoding,
 * sollevato dopo aver esaurito i tentativi di retry configurati.
 */
final class GeocodingServerException extends GeocodingException {}
