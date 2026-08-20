# Neuron CLI Test

Applicazione CLI PHP didattica basata sul framework [Neuron AI](https://github.com/neuron-core/neuron-ai). Un agente conversazionale chiamato **Neuron** dialoga con l'utente in italiano con l'unico obiettivo di raccogliere **nome e cognome**, chiedere conferma esplicita e salvarli in un file JSON.

## Caratteristiche

- Conversazione in italiano con system prompt strutturato (`SystemPrompt`)
- Structured output ad ogni turno: l'agente risponde con un oggetto tipizzato (`TurnoAgente`), non con testo libero
- Conferma esplicita dei dati prima del salvataggio
- Validazione lato CLI di nome e cognome (solo lettere, spazi, apostrofi e trattini)
- Persistenza su file JSON (`data/utenti.json`)
- Conteggio dei token (input / output / totale) sotto ogni risposta dell'agente
- Limite configurabile di iterazioni con chiusura automatica della chat
- Retry con backoff esponenziale in caso di rate limit (HTTP 429)
- Colori ANSI (disattivabili con `NO_COLOR=1`)
- Suite di test PHPUnit senza chiamate HTTP reali (provider finto)

## Requisiti

- PHP >= 8.1
- Composer
- Una chiave API [OpenRouter](https://openrouter.ai/)

## Installazione

```bash
git clone https://github.com/miziomon/neuron-cli-test.git
cd neuron-cli-test
composer install
```

## Configurazione

Creare un file `.env` nella radice del progetto:

```dotenv
OPENROUTER_API_KEY=sk-or-...
OPENROUTER_MODEL=openai/gpt-5.6-luna   # un qualsiasi modello disponibile su OpenRouter
MAX_ITERAZIONI=6                        # opzionale, default 6
```

Le variabili d'ambiente reali hanno precedenza sui valori del file `.env`:

```bash
OPENROUTER_MODEL='altro/modello' MAX_ITERAZIONI=3 php chat.php
```

Il modello deve supportare lo structured output (`response_format` JSON).

## Utilizzo

```bash
php chat.php
```

L'agente si presenta, chiede nome e cognome, ne chiede conferma e al termine salva i dati in `data/utenti.json`. Per uscire manualmente: `esci`, `exit` o `quit`.

Codici di uscita:

| Codice | Significato |
|--------|-------------|
| `0` | Dati raccolti e salvati, oppure uscita volontaria |
| `1` | Errore di configurazione o di comunicazione con il modello |
| `2` | Raggiunto il numero massimo di iterazioni senza raccogliere i dati |

## Test

```bash
vendor/bin/phpunit
```

I test non effettuano chiamate HTTP: usano un provider finto (`tests/FakeProvider.php`) e file temporanei per la persistenza.

## Struttura del progetto

```
chat.php                  # entry point della chat interattiva
src/Neuron/NeuronAgent.php    # agente: provider OpenRouter + system prompt
src/Neuron/TurnoAgente.php    # DTO dello structured output di ogni turno
src/Support/Archivio.php      # persistenza su file JSON
tests/                        # suite PHPUnit
data/utenti.json          # dati raccolti a runtime (git-ignored)
```

## Sicurezza

- Il file `.env` contiene la chiave API ed è escluso da git: non committarlo mai.
- `data/utenti.json` contiene dati personali in chiaro ed è anch'esso escluso da git.

## Changelog

Vedi [CHANGELOG.md](CHANGELOG.md).
