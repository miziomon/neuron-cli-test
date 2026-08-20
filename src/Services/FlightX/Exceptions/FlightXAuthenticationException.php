<?php

namespace App\Services\FlightX\Exceptions;

/**
 * Risposta HTTP 401 o 403: ApiKey non riconosciuta, credenziali di login errate,
 * oppure token JWT scaduto/rifiutato dal server.
 */
final class FlightXAuthenticationException extends FlightXException {}
