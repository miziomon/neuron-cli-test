<?php

namespace App\Services\Geocoding\Exceptions;

/**
 * Payload rifiutato: sia per una risposta HTTP 4xx diversa da 401/403/429, sia
 * per una validazione fatta localmente dal client prima di inviare la richiesta
 * (es. query vuota, limite fuori range, ricerca strutturata senza alcun campo).
 */
final class GeocodingValidationException extends GeocodingException {}
