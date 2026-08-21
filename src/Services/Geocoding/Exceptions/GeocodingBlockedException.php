<?php

namespace App\Services\Geocoding\Exceptions;

/**
 * Risposta HTTP 401/403: il servizio ha bloccato il client.
 *
 * Nominatim non richiede credenziali, quindi questo non è un errore di
 * autenticazione ma una violazione della policy d'uso: `User-Agent` mancante
 * o non identificativo, oppure un volume di richieste giudicato abusivo.
 * Verificare la configurazione di `userAgent`, non "reinserire le credenziali".
 */
final class GeocodingBlockedException extends GeocodingException {}
