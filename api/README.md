# Timejump API

A Laravel 12 REST API that imports a user's calendars from **Google Calendar**, **Outlook /
Microsoft 365** and **iCal (ICS)** feeds, aggregates them into one timeline, and lets users
grant and revoke view access to other users.

## Quick start (Docker)

```bash
cp .env.example .env          # optional – the app container creates it on first boot
docker compose up -d --build
```

On first boot the `app` container installs Composer dependencies, generates `APP_KEY` and
runs migrations. The API is then available at **http://localhost:8080/api** (health check:
http://localhost:8080/up).

| Service     | Purpose                                     |
|-------------|---------------------------------------------|
| `app`       | PHP 8.3-FPM running Laravel                  |
| `nginx`     | Web server, published on `${APP_PORT:-8080}` |
| `queue`     | `queue:work` – runs calendar sync jobs       |
| `scheduler` | `schedule:work` – re-syncs every 15 minutes  |
| `mysql`     | MySQL 8.4 (host port `33060`)                |
| `redis`     | Cache + queue                                |

Useful commands:

```bash
docker compose exec app php artisan test             # run the test suite (SQLite in-memory)
docker compose exec app php artisan db:seed          # demo users alice@ / bob@timejump.test (password: "password")
docker compose exec app php artisan timejump:sync --now   # sync every account immediately
docker compose logs -f queue
```

## Connecting providers

Google and Outlook need OAuth client credentials in `.env`:

* **Google** – Google Cloud Console → enable *Google Calendar API* → Credentials → *OAuth client
  ID (Web application)*. Authorised redirect URI: `http://localhost:8080/api/integrations/google/callback`.
  Set `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`.
* **Outlook** – Microsoft Entra admin centre → App registrations → New registration
  (*Accounts in any organizational directory and personal Microsoft accounts*). Redirect URI
  (Web): `http://localhost:8080/api/integrations/outlook/callback`. Add delegated Graph
  permissions `User.Read`, `Calendars.Read`, `offline_access`; create a client secret.
  Set `MICROSOFT_CLIENT_ID` / `MICROSOFT_CLIENT_SECRET` (and `MICROSOFT_TENANT` if single-tenant).
* **iCal** needs no configuration.

## Example flow

```bash
# Register
curl -s -X POST localhost:8080/api/auth/register -H 'Content-Type: application/json' \
  -d '{"name":"Ada","email":"ada@example.com","password":"secret123","password_confirmation":"secret123","timezone":"Europe/London"}'
# -> {"user":{...},"token":"1|abc...","token_type":"Bearer"}

TOKEN=1|abc...

# Import an iCal feed
curl -s -X POST localhost:8080/api/integrations/ical -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"url":"webcal://example.com/holidays.ics"}'

# Connect Google: open the returned authorization_url in a browser
curl -s localhost:8080/api/integrations/google/connect -H "Authorization: Bearer $TOKEN"

# Aggregated events for the next week
curl -s "localhost:8080/api/events?start=2026-10-06&end=2026-10-13" -H "Authorization: Bearer $TOKEN"

# Share with another user (free/busy only), then revoke
curl -s -X POST localhost:8080/api/shares -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"email":"grace@example.com","permission":"free_busy"}'
curl -s -X DELETE localhost:8080/api/shares/1 -H "Authorization: Bearer $TOKEN"
```

Full endpoint reference and architecture notes are kept in the project documentation folder
(`Documents/Claude/Projects/Timejump`).
