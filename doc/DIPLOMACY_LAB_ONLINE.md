# Diplomacy Lab online

---

## Part 1 — For you

### Putting it online (about ten minutes, all in the browser)

1. Go to **railway.com** and click **Login with GitHub**.
2. Click **New Project**, then **Deploy from GitHub repo**, then choose **NKay5/DiplomacyLab**.
   If Railway asks which branch, choose the branch holding this code.
3. Railway starts building. While it builds, click **+ Create** (top right), then **Database**,
   then **Add MySQL**.
4. Click your **DiplomacyLab** service, open the **Variables** tab, and click
   **New Variable** three times to add:

   | Name | Value |
   |---|---|
   | `LAB_ADMIN_USER` | a username you choose, for example `norman` |
   | `LAB_ADMIN_PASSWORD` | a long password you choose — this is what protects the site |
   | `MYSQL_URL` | `${{MySQL.MYSQL_URL}}` — type it exactly, including the braces |

5. Still on the service, open **Settings**, find **Networking**, and click **Generate Domain**.
   Accept the port it offers.
6. Open **Deployments** and click **Deploy** (or **Redeploy**) to apply the variables.
7. When the deployment turns green, open the `https://…` address from **Networking**.
8. Your browser asks for a name and password. Enter the `LAB_ADMIN_USER` and
   `LAB_ADMIN_PASSWORD` you chose. Diplomacy Lab opens, already signed in.

Safari will offer to remember the password, so you only type it once.

### Using it

The address you generated is the Lab, and it opens straight onto the board. Everything else on
the site redirects there. There is no sign-up page and no second login.

The controls sit along the top of the map:

- **Edit position** — click a province to put a unit on it or take one away. Land gives an army,
  sea gives a fleet; click a coastal province again to turn its army into a fleet, and again to
  move a fleet round a province's named coasts. **Centre** changes who owns a supply centre and
  **Erase** empties a province. Pick the power from the coloured row first. Positions are free:
  a power may have no units, and unit counts need not match centres.
- **Orders** — the board's normal behaviour. Click a unit, choose Hold, Move, Support or Convoy,
  then click where it is going. Orders save themselves, and every power is ordered from this one
  board.
- **Resolve** — adjudicates and shows you the position that came out of it. The arrows at the
  bottom of the map step back a phase to see what happened.
- **Reset** returns to the position as it was before the last Resolve, **Duplicate** copies the
  board so you can try a different line, **Save** keeps the position by name, and **New** starts
  from an empty board.

`lab.php` is still there for the things that are not the board: the list of saved positions,
importing and exporting JSON, setting the year and season, and deleting boards.

### Changing your password

Railway → your **DiplomacyLab** service → **Variables** → edit `LAB_ADMIN_PASSWORD` →
**Deploy**. The new password works as soon as the deployment turns green. Your positions
are not affected.

### Getting new versions

Railway watches the branch you deployed. When that branch is updated on GitHub, Railway
rebuilds and redeploys on its own. Your positions are kept.

### Backups

Railway → your **MySQL** service → **Backups**. Turn on scheduled backups there, and use
**Restore** on a backup if you ever need to go back. That is the only thing worth clicking:
everything else looks after itself.

### If the site stops opening

1. Railway → your **DiplomacyLab** service → **Deployments** → open the newest one and read the
   log. The lines beginning `[lab-init]` say what the application was doing.
2. The most common cause is a missing variable. Check that all three from step 4 are still there.
3. Click **Redeploy** on the last deployment that worked.

Your positions live in the MySQL service, not in the application, so redeploying never loses them.

---

## Part 2 — For a developer, or a future model

### What runs

Two Railway services and nothing else:

* **DiplomacyLab** — one container: Apache with mod_php, built from the repository's `Dockerfile`.
* **MySQL** — Railway's managed MySQL, reached over the project's private network.

There is deliberately no Redis, no worker and no cron:

* Redis is optional. `objects/redis.php` connects if a host is configured and otherwise turns every
  call into a no-op. It is used upstream for caching, for hinting the gamemaster, and for feeding
  the SSE server; a Lab needs none of those.
* No gamemaster runs. A Lab position must only ever be adjudicated when the user presses RESOLVE,
  which `processLabGame::resolve()` does synchronously.

### Deployment mechanics

Railway builds with the repository's `Dockerfile` whenever it finds one, which is why there is no
Railway configuration file in the repository. `railway.json` / `railway.toml` are deprecated with a
hard cutoff on 2026-12-01, and Railway's replacement, Infrastructure as Code in
`.railway/railway.ts`, is deliberately not used here: it was not possible to verify its exact API
when this was written, and an incorrect file would break the deploy, whereas the Dockerfile path is
stable. If it is added later, it should describe exactly the two services above.

`docker/entrypoint.sh` runs before Apache:

1. Refuses to start unless `LAB_ADMIN_PASSWORD` is set, so the Lab can never come up unprotected.
2. Writes `/etc/apache2/lab.htpasswd` from `LAB_ADMIN_USER` / `LAB_ADMIN_PASSWORD`, passing the
   password on stdin so it never reaches the process list.
3. Writes `Listen $PORT`; the virtual host uses `*:${PORT}`, which Apache substitutes from the
   environment.
4. Runs `docker/lab-init.php`.

`docker/lab-init.php` is idempotent and does the whole first-run setup:

1. Resolves the database from `LAB_DB_*`, Railway's `MYSQL*`, `MYSQL_URL` or `DATABASE_URL`.
2. Waits up to three minutes for it, creating the database itself if it is missing.
3. Generates one random signing secret on first boot and keeps it in `wD_LabConfig`, so that
   redeploying does not invalidate sessions or stored passwords.
4. Writes `config.php`: database credentials as `getenv()` calls so they never sit on disk, the
   signing secrets as literals, `debug` off and Lab mode on.
5. Installs `install/FullInstall/fullInstall.sql` **only if `wD_Users` is absent**, then verifies
   `wD_Misc.Version` matches `VERSION` and fails the deploy if it does not. The install runs through
   the `mysql` client, not `mysqli_multi_query`, which stops part way through that dump and reports
   success.
6. Compiles the Classic map by loading the variant.
7. Creates or updates the owner account through `libLabMode::createOrUpdateOwner()`.

### Variables

| Name | Required | Notes |
|---|---|---|
| `LAB_ADMIN_PASSWORD` | yes | The container refuses to start without it. |
| `LAB_ADMIN_USER` | no | Defaults to `owner`. |
| `MYSQL_URL` | yes | Set to `${{MySQL.MYSQL_URL}}`. `DATABASE_URL`, the `MYSQL*` set, or `LAB_DB_HOST`/`LAB_DB_USER`/`LAB_DB_PASSWORD`/`LAB_DB_NAME`/`LAB_DB_PORT` also work. |
| `PORT` | no | Supplied by Railway. |
| `LAB_REDIS_HOST` | no | Only if a Redis service is ever added. Empty disables Redis. |

No secret is stored in the repository, and none is printed to the logs.

### Access control

Two independent layers, both of which must pass:

1. **Apache** (`docker/apache-lab.conf`) requires HTTP Basic authentication for every request
   except `health.php`, so nothing — no page, asset, or API route — reaches PHP anonymously.
   It also denies `config.php`, `install/`, `docker/`, `.git`, and every `.sql`, `.sh` and `.md`.
2. **`lib/labMode.php`**, hooked into `header.php`, checks that the authenticated name is
   `Config::$labOwnerUsername`, signs that owner into their webDiplomacy account so there is only
   one credential to remember, and redirects everything outside the Lab's own pages back to
   `lab.php`. It runs for AJAX requests too, which do not otherwise load `$User`.

It fails closed. If Lab mode is on and either the owner is unconfigured or the web server is not
presenting credentials, every request gets a bare 503 rather than an open site.

The allowed pages are listed in `libLabMode::$allowedScripts`: `lab.php`, `board.php`, `map.php`,
`ajax.php`, `api.php`, `logon.php`, `health.php`, `cache.php`, `message.php`. Everything else —
the forum, game creation, profiles, matchmaking, DATC — redirects to the Lab. Registration is
additionally refused inside `register.php` itself.

Cookies get `Secure` and `HttpOnly` from an Apache `Header edit` rather than by changing
webDiplomacy's many `setcookie()` calls; nothing in the Lab's JavaScript reads them. PHP's own
session cookie is hardened in `docker/php-lab.ini`. CSRF is already covered by webDiplomacy's form
tokens, which every mutating Lab action uses.

The owner account is deliberately a plain `User` and not an `Admin`, because `header.php` turns
`Config::$debug` on for admins and a production deployment must not show stack traces.

### Health check

`health.php` is the only route reachable without credentials, because the platform has to be able
to see whether the container came up. It reports `configured` and `database` and nothing else — no
version, no hostname, no configuration, no game data — and never adjudicates. Set it as the
service's healthcheck path in Railway if you want one; it is not required.

### Inherited webDiplomacy behaviour worth knowing

* **Never let a gamemaster near a Lab board.** `processGame::process()` resets `processTime` to one
  phase length, and the gamemaster picks up any game whose process time has passed.
  `processLabGame::freezeClock()` pushes it ten years out after every change. Nothing in this
  deployment runs the gamemaster, but the guard matters if one is ever added.
* `install/FullInstall/fullInstall.sql` used to qualify one `ALTER TABLE` with the literal database
  name `webdiplomacy`, which broke installing into Railway's `railway` database. That qualifier has
  been removed so the script installs into whatever database it is run against.
* **The install script is developed against MariaDB but may be given MySQL.** Five statements used
  syntax MariaDB accepts and MySQL does not, and each one aborted the deploy in turn:
  `CAST(... AS INT)` (MySQL needs `SIGNED`/`UNSIGNED`), two `GROUP BY` clauses that MySQL's
  `only_full_group_by` rejects, a `'0000-00-00 00:00:00'` default that MySQL's strict mode rejects,
  and `ALTER TABLE IF EXISTS`, which MySQL has no equivalent for and which is now done with a
  prepared statement guarded on `information_schema`. Run
  `php install/checkSqlCompatibility.php --host=... --user=... --password=... --database=...`
  against a throwaway database after touching the install scripts: it runs every statement and
  reports all the failures in one pass rather than stopping at the first.
* `processSandboxGame::eraseGame()` compares the game's `sandboxCreatedByUserID`, which mysqli
  returns as a string, strictly against the integer `$User->id`, so it rejects even the creator.
  `processLabGame::deleteGame()` goes around it.
* The adjudicator, the map, the order interface and the results display are unmodified. All 122
  DATC cases pass with everything above in place.
