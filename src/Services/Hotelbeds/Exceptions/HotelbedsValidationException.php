<?php

namespace App\Services\Hotelbeds\Exceptions;

/**
 * Payload rifiutato: sia per una risposta HTTP 4xx diversa da 401/403/429, sia
 * per una validazione fatta localmente dal client prima di inviare la richiesta
 * (es. data non valida, coordinate fuori range, occupancy incoerente).
 */
final class HotelbedsValidationException extends HotelbedsException {}
