# MDRRMO Part 6 PHP REST API

The classroom reporting API accepts practice reports from the MDRRMO Constituents Website. It validates the fields, saves reports and optional photos in PostgreSQL, and stores the location as a PostGIS point with SRID 4326. Successful creation returns a reference after the database commits.

## Routes

| Method | Path | Access |
|---|---|---|
| GET | `/health.php` | Public database and PostGIS status |
| POST | `/api/incidents/create.php` | Classroom submissions with `practice: true` |
| GET | `/api/incidents/list.php?limit=25&offset=0` | Bearer token |
| GET | `/api/incidents/view.php?id=<uuid>` | Bearer token |

The create request uses the Hatchable form's JSON fields `id`, `category`, `location`, `details`, `personsAffected`, `latitude`, `longitude`, `gpsAccuracyMeters`, `practice`, and optional `photo`. Use a UUIDv4 and fictional details. GPS accuracy may be null. The optional photo accepts JPEG, PNG, or WebP data URLs up to 2 MiB. Send the same UUID and payload to retry. A UUID with different data returns HTTP 409.

Use the token in the `Authorization: Bearer <INCIDENT_READ_TOKEN>` header for list and view. Keep the token server-side. View also returns the PostGIS location as GeoJSON and the optional photo as base64.

## Setup

1. In Render, connect the GitHub repository to a Docker Web Service.
2. In the service's Environment settings, set `DATABASE_URL` from the internal PostgreSQL URL and set `INCIDENT_READ_TOKEN`. If the site calls the API directly from a browser, set `HATCHABLE_ORIGIN` to the exact Hatchable site origin.
3. Set the health check path to `/health.php`. The Docker container initializes PostGIS and the incident schema at startup.

For local use, set `DATABASE_URL` to a PostgreSQL database with PostGIS available and enable the PHP `pdo_pgsql` extension. Run `php test-db.php --initialize`, then `php -S 127.0.0.1:8080 -t public`. Set `INCIDENT_READ_TOKEN` in your shell environment to try the read endpoints.

The Render Free PostgreSQL plan is for the activity and expires after 30 days. See the [Render free plan documentation](https://render.com/docs/free#free-postgres).
