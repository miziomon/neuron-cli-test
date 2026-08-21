<?php

namespace App\Services\Hotelbeds\Exceptions;

/**
 * Risposta HTTP >= 500: errore lato server Hotelbeds, sollevato dopo aver
 * esaurito i tentativi di retry configurati.
 */
final class HotelbedsServerException extends HotelbedsException {}
