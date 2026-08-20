<?php

namespace App\Services\FlightX\Exceptions;

/**
 * L'API FlightX ha risposto con HTTP 200 ma con l'envelope applicativo che
 * segnala un errore: `IsValid: false` e uno o più elementi in `Errors[]`.
 *
 * Questo è il modo con cui l'API comunica errori di dominio (es. ItemKey
 * scaduta, VirtualDossierId non più valido): non basta controllare lo status
 * HTTP, va sempre ispezionato anche il campo `IsValid` del corpo risposta.
 */
final class FlightXApiException extends FlightXException
{
    /**
     * @param  array<int, array{code: ?string, text: ?string}>  $errors  Errori normalizzati da `Errors[]`.
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
