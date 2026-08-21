<?php

declare(strict_types=1);

namespace App\Support;

use App\Neuron\TurnoHotel;
use App\Neuron\TurnoReceptionist;
use DateTime;

/**
 * Regole di validazione dei dati raccolti dagli agenti, condivise dai nodi del
 * workflow. I campi anagrafici (nome, cognome, email) sono opzionali e non
 * bloccano mai la validazione: vengono solo sanificati (scartati se malformati).
 */
class Validazione
{
    /** Nome, cognome e destinazione: solo lettere, spazi, apostrofi e trattini. */
    public static function soloLettere(?string $valore): bool
    {
        return $valore !== null && preg_match('/^[\p{L}][\p{L}\s\'-]*$/u', trim($valore)) === 1;
    }

    /** Codice IATA di 3 lettere. */
    public static function iataValido(?string $valore): bool
    {
        return $valore !== null && preg_match('/^[A-Za-z]{3}$/', trim($valore)) === 1;
    }

    /** Data in formato YYYY-MM-DD valida. */
    public static function dataValida(?string $valore): bool
    {
        if ($valore === null) {
            return false;
        }
        $data = DateTime::createFromFormat('Y-m-d', trim($valore));

        return $data !== false && $data->format('Y-m-d') === trim($valore);
    }

    /** Il modello a volte restituisce "" invece di null per i campi opzionali. */
    public static function vuoto(?string $valore): bool
    {
        return $valore === null || trim($valore) === '';
    }

    /**
     * Errori sui campi del viaggio VALORIZZATI ma non validi.
     * I campi anagrafici non generano errori: sono opzionali.
     *
     * @return string[]
     */
    public static function erroriDatiViaggio(TurnoReceptionist $t): array
    {
        $errori = [];
        if (!self::vuoto($t->destinazione) && !self::soloLettere($t->destinazione)) {
            $errori[] = "la destinazione \"{$t->destinazione}\" contiene caratteri non ammessi (solo lettere: indica la città, non il codice aeroporto)";
        }
        if (!self::vuoto($t->aeroportoPartenza) && !self::iataValido($t->aeroportoPartenza)) {
            $errori[] = "l'aeroporto di partenza \"{$t->aeroportoPartenza}\" non è un codice IATA di 3 lettere";
        }
        if (!self::vuoto($t->aeroportoDestinazione) && !self::iataValido($t->aeroportoDestinazione)) {
            $errori[] = "l'aeroporto di destinazione \"{$t->aeroportoDestinazione}\" non è un codice IATA di 3 lettere";
        }
        if (!self::vuoto($t->dataPartenza) && !self::dataValida($t->dataPartenza)) {
            $errori[] = "la data di partenza \"{$t->dataPartenza}\" non è nel formato YYYY-MM-DD";
        }
        if (!self::vuoto($t->dataRitorno)) {
            if (!self::dataValida($t->dataRitorno)) {
                $errori[] = "la data di ritorno \"{$t->dataRitorno}\" non è nel formato YYYY-MM-DD";
            } elseif (self::dataValida($t->dataPartenza) && trim($t->dataRitorno) < trim($t->dataPartenza)) {
                $errori[] = 'la data di ritorno precede la partenza';
            }
        }
        if ($t->adulti !== null && $t->adulti < 1) {
            $errori[] = 'deve esserci almeno 1 adulto';
        }
        if ($t->bambini !== null && $t->bambini < 0) {
            $errori[] = 'il numero di bambini non può essere negativo';
        }
        if ($t->adulti !== null && $t->bambini !== null && ($t->adulti + $t->bambini) > 9) {
            $errori[] = 'i passeggeri totali non possono superare 9';
        }

        return $errori;
    }

    /**
     * Campi obbligatori del viaggio ancora mancanti (null o vuoti).
     *
     * @return string[]
     */
    public static function campiMancanti(TurnoReceptionist $t): array
    {
        $mancanti = [];
        if (self::vuoto($t->destinazione)) {
            $mancanti[] = 'la destinazione';
        }
        if (self::vuoto($t->aeroportoPartenza)) {
            $mancanti[] = "l'aeroporto di partenza";
        }
        if (self::vuoto($t->aeroportoDestinazione)) {
            $mancanti[] = "l'aeroporto di destinazione";
        }
        if (self::vuoto($t->dataPartenza)) {
            $mancanti[] = 'la data di partenza';
        }
        if ($t->adulti === null) {
            $mancanti[] = 'il numero di adulti';
        }
        if ($t->bambini === null) {
            $mancanti[] = 'il numero di bambini';
        }

        return $mancanti;
    }

    /**
     * true se l'utente non ha fornito alcun dato del viaggio: usato per
     * distinguere il rifiuto esplicito dai dati parziali.
     */
    public static function nessunDatoViaggio(TurnoReceptionist $t): bool
    {
        return self::vuoto($t->destinazione)
            && self::vuoto($t->aeroportoPartenza)
            && self::vuoto($t->aeroportoDestinazione)
            && self::vuoto($t->dataPartenza)
            && self::vuoto($t->dataRitorno)
            && $t->adulti === null
            && $t->bambini === null;
    }

    /**
     * Anagrafica sanificata: null se l'utente non si è presentato o se i valori
     * forniti non superano il controllo di formato (l'anagrafica non blocca mai).
     *
     * @return array{nome: string, cognome: string, email: string}|null
     */
    public static function anagrafica(TurnoReceptionist $t): ?array
    {
        $nome = self::soloLettere($t->nome) ? trim($t->nome) : null;
        $cognome = self::soloLettere($t->cognome) ? trim($t->cognome) : null;
        $email = !self::vuoto($t->email) && filter_var(trim($t->email), FILTER_VALIDATE_EMAIL) !== false
            ? trim($t->email)
            : null;

        if ($nome === null && $cognome === null && $email === null) {
            return null;
        }

        return ['nome' => $nome, 'cognome' => $cognome, 'email' => $email];
    }

    /** Le età dei bambini sono obbligatorie per l'API Hotelbeds: tante età quanti sono i bambini. */
    public static function parseEtaBambini(?string $valore): array
    {
        if ($valore === null || trim($valore) === '') {
            return [];
        }

        return array_values(array_map('intval', preg_split('/\s*,\s*/', trim($valore)) ?: []));
    }

    /**
     * Errori sui dati dell'hotel VALORIZZATI ma non validi (la rinuncia non genera errori).
     *
     * @return string[]
     */
    public static function erroriHotel(TurnoHotel $t, int $bambini): array
    {
        if (self::vuoto($t->hotelSelezionato)) {
            return [];
        }

        $errori = [];
        if (!self::dataValida($t->dataCheckIn)) {
            $errori[] = "la data di check-in \"{$t->dataCheckIn}\" non è nel formato YYYY-MM-DD";
        }
        if (!self::vuoto($t->dataCheckOut)) {
            if (!self::dataValida($t->dataCheckOut)) {
                $errori[] = "la data di check-out \"{$t->dataCheckOut}\" non è nel formato YYYY-MM-DD";
            } elseif (self::dataValida($t->dataCheckIn) && trim($t->dataCheckOut) <= trim($t->dataCheckIn)) {
                $errori[] = 'la data di check-out deve essere successiva al check-in';
            }
        }
        if (count(self::parseEtaBambini($t->etaBambini)) !== $bambini) {
            $errori[] = "le età dei bambini devono essere esattamente {$bambini}";
        }

        return $errori;
    }
}
