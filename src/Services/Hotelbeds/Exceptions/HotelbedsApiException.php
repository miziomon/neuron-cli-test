<?php

namespace App\Services\Hotelbeds\Exceptions;

/**
 * L'API Hotelbeds ha risposto con HTTP 200 ma con un envelope applicativo che
 * segnala un errore: una chiave `error` con `code`/`message`.
 *
 * Questo è il modo con cui l'API comunica errori di dominio (es. `INVALID_DATA`
 * per una geolocalizzazione a rettangolo mal formata): non basta controllare lo
 * status HTTP, va sempre ispezionato anche il corpo della risposta.
 */
final class HotelbedsApiException extends HotelbedsException
{
    /**
     * @param  array<int, array{code: ?string, text: ?string}>  $errors  Errori normalizzati da `error`.
     * @param  array<string, mixed>|null  $context
     */
    public function __construct(
        string $message,
        private readonly array $errors,
        ?array $context = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $context, $previous);
    }

    /**
     * @return array<int, array{code: ?string, text: ?string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
