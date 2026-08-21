<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Throwable;

/**
 * Esegue una chiamata al modello con retry e backoff esponenziale in caso di
 * rate limit (HTTP 429). Dopo 4 tentativi (o per qualsiasi altro errore)
 * segnala il problema ed esce con codice 1, come faceva il vecchio chat.php.
 */
class LlmRetry
{
    public static function esegui(Closure $operazione): mixed
    {
        $attesa = 5;
        for ($tentativo = 1; $tentativo <= 4; $tentativo++) {
            try {
                return $operazione();
            } catch (Throwable $e) {
                if ($tentativo === 4 || !str_contains($e->getMessage(), '429')) {
                    fwrite(STDERR, "Errore di comunicazione con il modello: {$e->getMessage()}\n");
                    exit(1);
                }
                echo "(il modello è momentaneamente occupato, riprovo tra {$attesa} secondi...)\n";
                sleep($attesa);
                $attesa *= 2;
            }
        }

        // Irrilevante: il ciclo termina sempre con return o exit.
        exit(1);
    }
}
