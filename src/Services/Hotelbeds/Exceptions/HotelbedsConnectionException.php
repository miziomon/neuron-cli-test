<?php

namespace App\Services\Hotelbeds\Exceptions;

/**
 * Errore di connessione (DNS, timeout, TLS, ecc.) verso l'API Hotelbeds,
 * sollevato dopo aver esaurito i tentativi di retry configurati.
 */
final class HotelbedsConnectionException extends HotelbedsException {}
