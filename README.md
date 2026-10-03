# WellNest

Multi-tenant practice-management and EHR platform: scheduling, clients, programs, clinical records,
billing, telehealth and a client portal, run as a SaaS for many organizations from one platform.

* Architecture contract: [`docs/architecture/README.md`](docs/architecture/README.md)
* UI conventions and route names: [`docs/architecture/ui-conventions.md`](docs/architecture/ui-conventions.md)
* Compliance register: [`docs/compliance/REGISTER.md`](docs/compliance/REGISTER.md)
* Delivery roadmap: [`docs/ROADMAP.md`](docs/ROADMAP.md)

## Requirements

PHP 8.3+ (developed on 8.5) with `pdo_pgsql`, `intl`, `bcmath`; Composer; PostgreSQL 16 with the
`btree_gist` and `pg_trgm` extensions available (both are "trusted", so the application role can create
them). No Node toolchain is needed — the stylesheet and script are plain files in `public/`.

## Setup

```bash
composer install
cp .env.example .env && php artisan key:generate
# create a non-superuser role and database, e.g.
#   CREATE ROLE ehr_app LOGIN PASSWORD '…';  CREATE DATABASE ehr OWNER ehr_app;
# then set DB_* in .env
php artisan migrate --seed          # catalogue (permissions, features, plans) + local sample organization
php artisan serve
```

In the `local` environment the seeder prints sample accounts (password `password-1234`). The super admin
must enrol two-factor authentication before the platform console opens.

## Deploying

```bash
php artisan migrate --force
php artisan catalogue:sync          # idempotent: permission catalogue, platform roles, feature catalogue
php artisan optimize
```

Run a queue worker (`php artisan queue:work`) and the scheduler (`php artisan schedule:run` every minute).
Set `APP_ENV=production`, `APP_DEBUG=false`, HTTPS (session cookies become `Secure` automatically), and a real
mail transport.

## Tests

PostgreSQL only — constraints, triggers and exclusion rules are part of the behaviour under test.

```bash
createdb -O ehr_app ehr_test
php artisan test
# concurrent runs need separate databases:
DB_DATABASE=ehr_test_2 php artisan test --filter=Scheduling
```
