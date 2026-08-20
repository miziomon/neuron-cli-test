<?php

namespace App\Services\FlightX\Exceptions;

/**
 * Payload rifiutato: sia per una risposta HTTP 4xx diversa da 401/403/429, sia
 * per una validazione fatta localmente dal client prima di inviare la richiesta
 * (es. codice IATA non valido, regole passeggeri violate, search mancante prima
 * di una selectItem).
 */
final class FlightXValidationException extends FlightXException {}
