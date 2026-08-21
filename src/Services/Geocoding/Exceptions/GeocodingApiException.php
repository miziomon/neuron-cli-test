<?php

namespace App\Services\Geocoding\Exceptions;

/**
 * La risposta del servizio di geocoding non rispetta il contratto atteso:
 * un HTTP 200 il cui corpo non è la lista JSON prevista da Nominatim, oppure
 * un risultato privo delle coordinate `lat`/`lon`.
 */
final class GeocodingApiException extends GeocodingException {}
