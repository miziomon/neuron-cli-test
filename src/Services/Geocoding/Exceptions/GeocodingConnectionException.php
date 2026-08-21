<?php

namespace App\Services\Geocoding\Exceptions;

/**
 * Errore di connessione (DNS, timeout, TLS, ecc.) verso il servizio di
 * geocoding, sollevato dopo aver esaurito i tentativi di retry configurati.
 */
final class GeocodingConnectionException extends GeocodingException {}
