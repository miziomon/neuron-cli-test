<?php

namespace App\Services\FlightX\Exceptions;

/**
 * Errore di rete verso l'API FlightX: DNS, connessione rifiutata, timeout.
 * Nessuna risposta HTTP è stata ricevuta dal server.
 */
final class FlightXConnectionException extends FlightXException {}
