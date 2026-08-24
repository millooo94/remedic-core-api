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

## Accesso backoffice (RBAC)

`BackofficeAccessCatalog` è la source of truth per permission, ruoli e matrice ruolo-permission. Login e `/auth/me` confrontano lo stato reale del database con il catalogo e invocano il synchronizer soltanto quando rilevano drift; dopo restore o deploy è disponibile anche:

```bash
php artisan backoffice:sync-access
```

Il comando e `BackofficeAccessSeeder` usano lo stesso synchronizer idempotente. Il primary admin configurato tramite `PRIMARY_ADMIN_EMAIL` riceve sempre `super_admin`; gli Admin legacy senza alcun ruolo Spatie ricevono `admin`, mentre i ruoli espliciti esistenti non vengono promossi.

Per aggiungere una permission, aggiungila ad `AdminPermission`, inseriscila nella matrice del catalogo per i ruoli appropriati, aggiorna la costante TypeScript corrispondente e i guard di route/navigation, quindi esegui i test. Le permission non canoniche restano nel database, ma non vengono mantenute nella matrice dei ruoli canonici.
