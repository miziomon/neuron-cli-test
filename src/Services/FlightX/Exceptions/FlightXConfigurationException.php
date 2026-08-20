<?php

namespace App\Services\FlightX\Exceptions;

/**
 * Configurazione del client mancante o non valida (es. baseUrl/apiKey/username/password
 * vuoti, o baseUrl non è un URL valido). Sollevata prima di qualsiasi chiamata di rete.
 */
final class FlightXConfigurationException extends FlightXException {}
