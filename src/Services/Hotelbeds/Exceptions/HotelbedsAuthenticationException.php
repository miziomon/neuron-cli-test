<?php

namespace App\Services\Hotelbeds\Exceptions;

/**
 * Risposta HTTP 401/403: la firma inviata non è stata accettata.
 *
 * Cause tipiche, dedotte dal contratto reale dell'API (non un token scaduto:
 * Hotelbeds non usa un token, vedi {@see \App\Services\Hotelbeds\HotelbedsClient}):
 * `apiKey`/`secret` errati, oppure firma calcolata con un orologio di sistema
 * desincronizzato (la firma include il timestamp Unix e ha validità di pochi
 * minuti). Non ha senso ritentare automaticamente: la firma appena calcolata
 * era già corretta secondo l'orologio locale.
 */
final class HotelbedsAuthenticationException extends HotelbedsException {}
