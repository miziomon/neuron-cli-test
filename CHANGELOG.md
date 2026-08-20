# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate in questo file.

Il formato è basato su [Keep a Changelog](https://keepachangelog.com/it/1.1.0/)
e questo progetto aderisce al [Semantic Versioning](https://semver.org/lang/it/).

## [1.0.0] - 2026-08-20

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

[1.0.0]: https://github.com/miziomon/neuron-cli-test/releases/tag/v1.0.0
