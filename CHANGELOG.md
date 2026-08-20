# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate in questo file.

Il formato è basato su [Keep a Changelog](https://keepachangelog.com/it/1.1.0/)
e questo progetto aderisce al [Semantic Versioning](https://semver.org/lang/it/).

## [0.5.0] - 2026-08-20

### Cambiato

- Rimosso il saluto statico iniziale della CLI: la presentazione è affidata al primo agente
- Rimossi (commentati) gli echo di transizione tra una fase e la successiva
- Il secondo agente non si presenta più: chiede subito i dati del viaggio
- Il messaggio di avvio delle fasi successive alla prima non è più un saluto ("Ciao!") ma un avvio di contesto

## [0.4.0] - 2026-08-20

### Aggiunto

- Terza fase di conversazione (`VoliAgent`): ricerca dei voli sulla base del viaggio raccolto, con conferma esplicita di aeroporti (IATA), date e passeggeri
- Server MCP stdio (`flightx-mcp.php`) che espone i servizi FlightX come tool `cerca_voli` (elenco voli in formato leggibile) e `seleziona_volo` (verifica disponibilità + dossier temporaneo, nessuna prenotazione)
- Wrapper FlightX (`src/Services/FlightX/`) adattato per l'uso standalone: layer HTTP portato da Illuminate a Guzzle, supporto a password pre-hashata (`passwordMd5`), logging su STDERR con credenziali oscurate
- `App\MCP\LineStdioTransport`: transport MCP con accumulo della risposta fino a JSON completo (risolve il limite di `StdioTransport` di Neuron con payload oltre 4 KB)
- Test: validazioni locali di `FlightXClient` e handshake del server MCP (18 test, 59 asserzioni)

## [0.3.0] - 2026-08-20

### Aggiunto

- Riepilogo finale dei dati raccolti (anagrafica + viaggio) stampato prima dell'uscita

### Cambiato

- Estratta la classe base astratta `OpenRouterAgent` con il provider OpenRouter condiviso: `NeuronAgent` e `ViaggioAgent` ora definiscono solo il proprio system prompt

## [0.2.0] - 2026-08-20

### Aggiunto

- Raccolta dell'**email** nella fase anagrafica, con validazione lato CLI tramite `filter_var` (`FILTER_VALIDATE_EMAIL`)
- Secondo agente `ViaggioAgent` che parte solo dopo il completamento della prima fase e raccoglie **destinazione, numero di persone e periodo** del viaggio
- Persistenza dei viaggi in `data/viaggi.json`, collegati all'utente tramite l'email
- Il contatore delle iterazioni riparte da `#1` a ogni fase
- Gestione del rifiuto esplicito dell'utente: chiusura gentile senza salvataggio
- `Archivio::salva()` ora accetta un record generico (`array`) invece di nome/cognome fissi
- Test per il DTO `TurnoViaggio` e per i record di viaggio; helper condiviso `AgenteFinto`

## [0.1.0] - 2026-08-20

### Aggiunto

- Chat CLI interattiva basata su Neuron AI con provider OpenRouter (`OpenAILike`)
- Agente conversazionale in italiano con l'obiettivo di raccogliere nome e cognome
- Structured output ad ogni turno tramite il DTO `TurnoAgente` (`risposta`, `nome`, `cognome`, `confermato`)
- Conferma esplicita dei dati prima del salvataggio
- Validazione lato CLI di nome e cognome (regex Unicode: lettere, spazi, apostrofi, trattini)
- Persistenza dei dati confermati in `data/utenti.json` (`Archivio`)
- Conteggio dei token (input / output / totale) sotto ogni risposta dell'agente
- Limite di iterazioni configurabile (`MAX_ITERAZIONI`, default 6) con chiusura automatica della chat (exit code 2)
- Numerazione progressiva delle risposte dell'agente (`#1 Neuron: ...`)
- Retry con backoff esponenziale in caso di rate limit (HTTP 429)
- Colori ANSI con supporto Windows VT100 e disattivazione tramite `NO_COLOR=1`
- Caricamento del file `.env` con precedenza delle variabili d'ambiente reali
- Suite di test PHPUnit con provider finto senza chiamate HTTP reali

[0.5.0]: https://github.com/miziomon/neuron-cli-test/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/miziomon/neuron-cli-test/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/miziomon/neuron-cli-test/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/miziomon/neuron-cli-test/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/miziomon/neuron-cli-test/releases/tag/v0.1.0
