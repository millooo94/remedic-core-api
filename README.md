# Backend Remedic Core

API Laravel 12 per autenticazione, CRUD, calcoli economici, report, export e backup.

Per setup completo, architettura, credenziali demo e deploy consulta il README principale nella root del progetto:

- `../README.md`

## Test e sicurezza del database

La suite usa SQLite `:memory:` come database disposable, configurato direttamente in `phpunit.xml`. Esegui i test con:

```bash
composer test
```

Per eseguire manualmente comandi Artisan con `--env=testing`, copia `.env.testing.example` in `.env.testing`. Non usare mai il database locale per PHPUnit, `migrate:fresh` o `db:wipe`.

In ambiente `testing` una guardia applicativa blocca il bootstrap prima dell'accesso al database, salvo che la configurazione risolta sia SQLite `:memory:` oppure un database dedicato con nome che termini in `_test` o `_testing` (per esempio `remedic_core_testing`). Il database di test deve essere sempre disposable e non deve contenere dati reali.
