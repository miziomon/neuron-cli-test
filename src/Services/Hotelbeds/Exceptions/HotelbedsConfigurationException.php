<?php

namespace App\Services\Hotelbeds\Exceptions;

/**
 * Configurazione del client non valida: `baseUrl` mancante/malformato, oppure
 * `apiKey`/`secret` assenti. Sollevata da {@see \App\Services\Hotelbeds\HotelbedsConfig}
 * prima che qualsiasi richiesta di rete venga tentata.
 */
final class HotelbedsConfigurationException extends HotelbedsException {}
