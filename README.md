Diplomacy is a popular turn based strategy game in which you battle to control Europe; to win you must be diplomatic and strategic.
 
webDiplomacy lets you play Diplomacy online.

---

Diplomacy Lab
-------------

This fork adds **Diplomacy Lab**, a free tactical analysis board built on webDiplomacy's sandbox
games: place any position you like on the Classic map, give every power its orders, adjudicate
with one button, and step straight back to the position you started from.

The adjudicator, the map, the order interface and the results display are webDiplomacy's own and
are not modified. See `doc/diplomacyLab.txt` for the architecture, the JSON position format and
the API.

### Running it on your Mac

Diplomacy Lab runs on your own Mac with **Docker Desktop as the only thing you install**. Unpack
the folder, right-click `mac/Diplomacy Lab` and choose Open, and it builds itself, starts, and
opens in your browser. After that it is a double-click, with no Terminal and no password.

Full instructions: [doc/DIPLOMACY_LAB_MAC.md](doc/DIPLOMACY_LAB_MAC.md).

### Putting it online

Diplomacy Lab is built to be deployed straight from GitHub to Railway as a single private,
password-protected site, with no terminal and no local setup. The step-by-step, click-only
procedure is in [doc/DIPLOMACY_LAB_ONLINE.md](doc/DIPLOMACY_LAB_ONLINE.md).

### Running it locally on macOS with Docker

Docker Desktop is the only prerequisite; PHP and MySQL stay inside the containers.

    git clone https://github.com/NKay5/DiplomacyLab.git
    cd DiplomacyLab
    docker run --rm -v "$PWD":/app -w /app composer:2 composer update --ignore-platform-reqs
    docker compose --profile core up -d

The first start builds the database and takes a few minutes. Watch it finish with:

    tail -f gamemaster-entrypoint.txt

Wait for the line `READY - webDiplomacy system initialized`, then:

1. Register an account at http://localhost:43000/register.php . The confirmation e-mail is
   caught locally, so with the sample config you can just use this link directly:
   http://localhost:43000/register.php?emailToken=9513e6f6%7C1665482821%7Ctest%40test.com
   (Start the `dev` profile as well &mdash; `docker compose --profile core --profile dev up -d`
   &mdash; if you would rather read the mail at http://localhost:43001 .)
2. Open **Diplomacy Lab** at http://localhost:43000/lab.php , or from the Games menu.

### Using it

* **New position** creates an empty board. Nothing is on it and every supply center is neutral.
* **EDIT POSITION** is webDiplomacy's own map editor. Pick a power's colour, pick whether you are
  placing a unit, a supply center or both, and click provinces. Clicking a coastal province that
  already holds that power's army places a fleet. Pick *None* to empty a province. Choose the
  year, season and phase here too, then press **APPLY POSITION**.
* **ORDERS** is webDiplomacy's own board. Because a Lab board is a sandbox game, every power's
  orders appear together and you enter them exactly as you would in a game.
* **RESOLVE** readies every power and adjudicates immediately, with the original adjudicator.
* **RESET** returns the board to exactly the position it was in before that adjudication, so you
  can change one order and try again.
* **Duplicate** makes an independent copy of the current position, for exploring variations side
  by side. **Save**, **Export JSON** and **Import** move positions in and out.

Nothing is enforced except the geography of the map: powers may have no units at all, unit counts
need not match supply center counts, and supply centers may be left neutral.

### Running the adjudicator test suite

Diplomacy Lab does not modify the adjudicator, so the DATC suite should be unaffected. To confirm
that on your own machine, log in and then:

1. Make yourself an admin: http://localhost:43000/gamemaster.php?gameMasterSecret=
2. Turn on maintenance mode:
   http://localhost:43000/admincp.php?tab=Control%20Panel&actionName=maintenance#maintenance
3. Run the suite: http://localhost:43000/datc.php?testID=101&batchTest=12345
4. Turn maintenance mode back off.

---

install/README.txt - Installation information.

AGPL.txt - The license webDiplomacy is distributed under.

---

We welcome code contributions for any of the issues on the "soon" milestone. Simply fork the project, and develop a fix in a branch. We accept pull requests that:

* are well tested
* only include one fix per pull request
* keep the code clean and maintainable
* use the same style as the rest of webdip
* keep whitespace changes to a minimum

When writing the text of your pull request, please include:

* The details of the testing that you've performed
* The github issue number that this pull request is a fix for

---

If you get errors for files within /javascript/ it is because some default Apache configurations use this as a shared folder by default. Disable this alias to resolve.

---

http://webdiplomacy.net/ - The official webDiplomacy server.

https://github.com/kestasjk/webDiplomacy - The webDiplomacy github source repository.

---

To get Philippe Paquette's MILA bots working with the base webDip docker install do:
Ensure that the IP address is the IP of the machine hosting docker (there is probably some docker context/network wizardry to do this..)

docker pull public.ecr.aws/n4k3z7o3/webdiplomacy:latest
docker run -d --env API_WEBDIPLOMACY=http://172.21.16.1:43000/api.php --env API_KEY_USER_01=bot1 --env API_KEY_USER_02=bot2 --env API_KEY_USER_03=bot3 --env API_KEY_USER_04=bot4 --env API_KEY_USER_05=bot5 --env API_KEY_USER_06=bot6 public.ecr.aws/n4k3z7o3/webdiplomacy:latest




Kestas J. Kuliukas - kestas@kuliukas.com
