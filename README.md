# Neuron CLI Test

Applicazione CLI PHP didattica basata sul framework [Neuron AI](https://github.com/neuron-core/neuron-ai). Due agenti conversazionali in italiano lavorano in sequenza:

1. **Receptionist** — un unico agente raccoglie **nome, cognome ed email**, poi **destinazione, aeroporti (IATA) di partenza e destinazione, data di partenza (ed eventuale ritorno), adulti e bambini**; chiede conferma esplicita del ricapitolo completo e salva in `data/utenti.json` e `data/viaggi.json` (collegati via email)
2. **Voli** — riceve tutti i parametri già raccolti, chiede una sola conferma e cerca i voli tramite il **server MCP FlightX**, presentando un elenco leggibile delle opzioni disponibili

## Caratteristiche

- Conversazione in italiano con system prompt strutturato (`SystemPrompt`)
- Structured output ad ogni turno: ogni agente risponde con un oggetto tipizzato (`TurnoReceptionist`, `TurnoVolo`), non con testo libero
- Conferma esplicita dei dati prima del salvataggio
- Validazione lato CLI: nome/cognome/destinazione (solo lettere, spazi, apostrofi e trattini), email (`filter_var` con `FILTER_VALIDATE_EMAIL`), aeroporti (IATA di 3 lettere), date (`YYYY-MM-DD`, ritorno non precedente alla partenza), passeggeri (almeno 1 adulto, massimo 9 totali)
- Persistenza su file JSON (`data/utenti.json`, `data/viaggi.json`)
- Conteggio dei token (input / output / totale) sotto ogni risposta dell'agente
- Riepilogo finale dei dati raccolti (anagrafica + viaggio) prima dell'uscita
- Limite configurabile di iterazioni con chiusura automatica; il contatore riparte da `#1` a ogni fase
- Ricerca voli via **MCP**: server stdio (`flightx-mcp.php`) che espone il wrapper FlightX come tool `cerca_voli` / `seleziona_volo`, collegato all'agente con `McpConnector`
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

# Credenziali FlightX (fase 3, ricerca voli via MCP)
FLIGHTX_BASE_URL=https://api.stage.flightx.app
FLIGHTX_API_KEY=...
FLIGHTX_USERNAME=...
FLIGHTX_PASSWORD_MD5=...                # password già hashata con md5(strtolower(...))
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

Il receptionist si presenta e raccoglie in un'unica conversazione anagrafica, destinazione, aeroporti, date e passeggeri (con ricapitolo e conferma); poi l'agente voli cerca le opzioni disponibili. I dati vengono salvati in `data/utenti.json` e `data/viaggi.json`. Per uscire manualmente: `esci`, `exit` o `quit`.

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
chat.php                      # entry point: fase receptionist + fase voli
flightx-mcp.php               # server MCP stdio che espone i tool FlightX
src/Neuron/OpenRouterAgent.php  # classe base astratta con il provider OpenRouter condiviso
src/Neuron/ReceptionistAgent.php  # agente di raccolta unico (anagrafica + viaggio + parametri volo)
src/Neuron/TurnoReceptionist.php  # DTO structured output della fase receptionist
src/Neuron/VoliAgent.php      # agente voli: tool MCP FlightX + system prompt
src/Neuron/TurnoVolo.php      # DTO structured output fase voli (aeroporti, date, passeggeri)
src/MCP/LineStdioTransport.php  # transport MCP stdio con buffer (gestisce risposte > 4 KB)
src/Services/FlightX/         # wrapper delle API FlightX (client, config, eccezioni)
src/Support/Archivio.php      # persistenza su file JSON
tests/                        # suite PHPUnit
data/utenti.json          # anagrafiche raccolte a runtime (git-ignored)
data/viaggi.json          # viaggi raccolti a runtime, collegati via email (git-ignored)
```

## Sicurezza

- Il file `.env` contiene la chiave API ed è escluso da git: non committarlo mai.
- `data/utenti.json` e `data/viaggi.json` contengono dati personali in chiaro e sono anch'essi esclusi da git.

## Changelog

Vedi [CHANGELOG.md](CHANGELOG.md).
