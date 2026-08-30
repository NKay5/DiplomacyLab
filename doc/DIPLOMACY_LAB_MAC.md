# Diplomacy Lab on your Mac

---

## Part 1 — For you

### What you need

**Docker Desktop.** Nothing else. PHP, Apache, MariaDB and everything else live inside Docker,
so none of it is installed on your Mac.

### Setting it up (once)

1. Download **Docker Desktop** from [docker.com](https://www.docker.com/products/docker-desktop/)
   and install it the usual way (drag it to Applications). Open it once and leave it running.
2. Download Diplomacy Lab from GitHub: on the repository page click the green **Code** button,
   then **Download ZIP**. Double-click the downloaded ZIP to unpack it.
3. Move the unpacked **DiplomacyLab** folder wherever you like it — your Home folder or Documents
   is fine. Keep it somewhere permanent; the apps live inside it.
4. Open the folder, then open the **mac** folder inside it. **Right-click** on
   **Diplomacy Lab** and choose **Open**, then click **Open** in the box that appears.

   That right-click is only needed the very first time. macOS asks because the app is not signed
   by a registered developer. From then on a normal double-click works.

The first start takes a few minutes while Docker builds Diplomacy Lab. You will get a
notification when it is ready, and it opens in your browser by itself. Every start after that
takes a few seconds.

If you would like the apps somewhere handier, drag **Diplomacy Lab** and **Stop Diplomacy Lab**
into your Applications folder or your Dock. The first time you open one from its new home it asks
you to point at the DiplomacyLab folder; it remembers after that.

### Using it every day

Double-click **Diplomacy Lab**.

It starts Docker Desktop if it is not already running, starts Diplomacy Lab, waits until it is
really ready, and opens it at **http://127.0.0.1:43080/lab.php**. There is no password and nothing
to type.

If it is already running, double-clicking just brings it back up in the browser.

### Stopping it

Double-click **Stop Diplomacy Lab**. Your positions are kept.

You can also just quit Docker Desktop, or shut the Mac down. Nothing is lost either way.

### Where your data lives

Everything you make — positions, orders, results, saved positions — is kept by Docker in a
storage area called `diplomacy-lab-db`, outside the app itself. It survives stopping Diplomacy
Lab, quitting Docker Desktop, restarting the Mac, and updating Diplomacy Lab to a newer version.

Neither app ever deletes it. The only way to lose it is to delete `diplomacy-lab-db` yourself in
Docker Desktop under **Volumes**.

To keep a copy of a position you care about, open it in the Lab and click **Export JSON**. That
gives you a small file you can keep anywhere and import again later.

### Getting a newer version

Download the ZIP again, replace the folder, and double-click **Diplomacy Lab**. It rebuilds and
keeps all your data.

### If something goes wrong

* **Nothing happens when you double-click.** Open Docker Desktop and wait until it says it is
  running, then try again.
* **A message says it could not start.** It names a log file. Open it and look at the last few
  lines; the lines beginning `[lab-init]` say what it was doing.
* **The browser says it cannot connect.** Give it a few more seconds and reload. The first start
  after an update takes longer than usual.
* **Starting over completely.** In Docker Desktop, go to **Volumes**, delete `diplomacy-lab-db`,
  then start Diplomacy Lab again. This deletes all your positions, so export anything you want to
  keep first.

---

## Part 2 — For a developer, or a future model

### Shape

`docker-compose.local.yml` runs two containers:

* **app** — the repository's `Dockerfile` (Apache with mod_php), published on `127.0.0.1:43080`
  only, so the Lab is unreachable from the LAN or the internet.
* **db** — `mariadb:10.6`, with **no published port at all**, reachable only over the compose
  network. Its data is in the named volume `diplomacy-lab-db`.

No Redis, no worker, no cron. `objects/redis.php` no-ops when no host is configured, and a Lab
position is only adjudicated when the user presses RESOLVE.

The same image serves the hosted deployment described in `doc/DIPLOMACY_LAB_ONLINE.md`; only the
environment differs.

### Local mode

`LAB_LOCAL_MODE=1` selects it, and it changes exactly two things:

1. **`docker/entrypoint.sh`** writes `/etc/apache2/lab-access.conf` as `Require all granted`
   instead of an HTTP authentication challenge, so there is no password prompt. In hosted mode it
   writes the challenge and refuses to start at all without `LAB_ADMIN_PASSWORD`.
2. **`lib/labMode.php`** accepts the request as the owner without credentials, but only when the
   `Host` is a loopback name. Everything else about Lab mode is unchanged: registration is
   refused, only the Lab's own pages are reachable, and the owner is signed in automatically.

The security boundary in local mode is the `127.0.0.1:` prefix on the published port, which Docker
enforces before anything reaches the container. The `Host` check is a second line of defence in
case that binding is ever widened by mistake. The owner account still has a password, derived from
this installation's own secret in the database, so the user never has to invent, type or store
one.

The MariaDB password in `docker-compose.local.yml` is fixed and in source control on purpose. The
database has no published port, so it is reachable only from the app container; keeping it fixed
removes the failure mode where a generated password stops matching an existing volume. The real
secrets — the session signing keys and the owner's password — are generated on first boot and
stored in `wD_LabConfig`, never in the repository.

### First boot

`docker/lab-init.php` is unchanged in shape from the hosted deployment and is idempotent. Two
things about it are worth knowing:

* It runs `install/FullInstall/fullInstall.sql` through **mysqli**, splitting the file into
  statements itself (`labSplitSqlStatements`). `mysqli_multi_query()` cannot be used: it stops part
  way through the dump and still reports success. The splitter is quote-aware and follows the mysql
  client in treating a line that starts with `--` as a comment even with no space after it, which
  the dump relies on. This is why the image needs no MySQL client.
* If a first install fails part way, it **drops the tables it created** before failing, so the next
  start begins again from empty. Without that, a half-installed database would look like data worth
  preserving forever and the only way out would be deleting the volume by hand.

### The macOS apps

`mac/Diplomacy Lab.app` and `mac/Stop Diplomacy Lab.app` are plain app bundles whose
`CFBundleExecutable` is a bash script. They use only bash, `osascript`, `curl` and `open`, all of
which macOS ships with — no Electron, no Tauri, no Platypus, nothing to install. `LSUIElement` keeps
them out of the Dock; progress and errors are macOS notifications and dialogs.

`mac/lab-common.sh` holds the shared logic: finding the project folder (next to the app, then a
remembered path in `~/Library/Application Support/Diplomacy Lab/project-path`, then a folder
picker), and starting Docker Desktop and waiting for it. Docker Desktop's CLI tools are not on the
minimal `PATH` an app bundle gets, so the script adds the places Docker actually installs them.

The launcher runs `docker compose up -d --build`, then polls `/health.php` until it really answers
rather than trusting container state. The stopper runs `docker compose stop` and never `down -v`,
so the volume is never removed.

Because the apps are unsigned and un-notarized, the first launch needs right-click → Open. That is
the one manual step, and it is inherent to distributing an unsigned app without an Apple developer
account.

### Things that bit us, so they do not bite again

* **`contrib/` must be partly served.** It holds both PHP the Lab never uses and the vendored
  Prototype/Scriptaculous libraries every page loads. An earlier blanket deny broke the board with
  `$A is not defined`. `docker/apache-lab.conf` now denies the PHP and serves the assets.
* **`variants/*/cache` must be excluded from the build context** (`.dockerignore`). Compiled variant
  data is tied to a particular database; shipping a copy from the build machine makes a fresh
  database end up with zero territories, because the variant thinks it is already installed.
* **The adjudicator is untouched.** All 122 DATC cases pass with everything above in place.
