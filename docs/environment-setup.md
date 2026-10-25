# environment-setup.md
 
## Setup der Test- & Laufzeitumgebung
 
Anleitung, um die Verifikationsumgebung für das ADELE-Ökosystem von Grund auf
aufzusetzen: Code-Style (phpcs/moodle-cs), Moodle, PHP, PostgreSQL, PHPUnit,
Behat. Geschrieben für die Arbeit im Container.
 
> **Konvention:** QUELLE = `/home/claude/work/moodle-<plugin>_adele-development`
> (Arbeitskopien, werden ausgeliefert). SPIEGEL = `/home/claude/moodle/enrol/adele`,
> `/home/claude/moodle/local/adele`, `/home/claude/moodle/mod/adele`
> (Wegwerf-Kopien im Moodle-Baum, gegen die getestet wird). Nie im Spiegel
> entwickeln – er wird bei jedem Testlauf überschrieben.
 
> **Verifikationsstand dieses Dokuments:** §0–§4 und §9 sind am 2026-08-28
> Schritt für Schritt im Container ausgeführt worden; die angegebenen Ausgaben
> sind die tatsächlichen. §5–§8 sind aus dem `local_catquiz`-Setup übertragen
> und für ADELE angepasst, aber **hier noch nicht ausgeführt**. Wer sie zum
> ersten Mal nutzt, protokolliert Abweichungen und schreibt dieses Dokument fort.
 
---
 
## 0. Zielzustand
 
### Verifiziert (Session 005, 2026-08-28)
 
| Komponente | Wert |
|---|---|
| PHP | 8.3.6 (CLI, NTS) |
| Composer | 2.7.1 |
| phpcs / moodle-cs | `/tmp/moodlecs`, `moodlehq/moodle-cs ^3.7` (zieht php_codesniffer 3.13.6, phpcsextra 1.5.1) |
| Arbeitskopien | `/home/claude/work/moodle-{enrol,local,mod}_adele-development` |
| Nullmessung | alle drei Plugins `phpcs --standard=moodle` Exit 0, ohne Sniff-Ausschlüsse |
 
### Vorgesehen, hier noch nicht aufgebaut
 
| Komponente | Wert |
|---|---|
| Moodle | 4.5.x, Branch `MOODLE_405_STABLE` |
| DB | PostgreSQL 16.x, `dbname=moodle`, `dbuser=moodle`, `prefix=mdl_` |
| PHPUnit | 9.6.x (aus Moodle-Core-Composer) |
| Moodle-Pfad | `/home/claude/moodle` |
| dataroot | `/home/claude/moodledata` |
| phpunit_dataroot | `/home/claude/moodledata_phpu`, `phpunit_prefix=phpu_` |
 
Moodle **4.5** ist die Untergrenze, nicht 4.1: `enrol_adele` und `local_adele`
verlangen zwar nur `2022112800`, `mod_adele` aber `2024100700`. Das gesamte
Ökosystem lässt sich also erst ab 4.5 vollständig installieren, und die CI-Matrix
prüft 4.5 und 5.0.
 
---
 
## 1. Systempakete
 
```bash
apt-get update -qq
apt-get install -y -qq php8.3-cli php8.3-xml php8.3-mbstring php8.3-curl \
    php8.3-zip composer
php -v | head -1        # erwartet: PHP 8.3.x (cli)
composer --version      # erwartet: Composer version 2.7.x
```
 
Die vier Erweiterungen sind nicht optional: `phpcs` braucht `xml` und
`mbstring`, Composer braucht `curl` und `zip`.
 
Für den vollen Moodle-Betrieb (§6 ff.) zusätzlich:
 
```bash
apt-get install -y php8.3-pgsql php8.3-gd php8.3-intl php8.3-soap \
    postgresql postgresql-client git unzip
```
 
Moodle verlangt `max_input_vars >= 5000`. Statt die php.ini zu ändern, wird das
Flag bei den CLI-Aufrufen mitgegeben (siehe §7).
 
---
 
## 2. Code-Style: phpcs / moodle-cs
 
```bash
mkdir -p /tmp/moodlecs && cd /tmp/moodlecs
composer require --dev --no-interaction "moodlehq/moodle-cs:^3.7"
ls vendor/bin        # erwartet: phpcbf  phpcs
```
 
**Achtung, hier weicht der Container vom Normalfall ab.** `moodle-cs` bringt
`dealerdirect/phpcodesniffer-composer-installer` mit, der die Standards
normalerweise selbst registriert. In diesem Container passiert das **nicht** –
auch dann nicht, wenn `allow-plugins` in der `composer.json` gesetzt ist
(beides am 2026-08-28 geprüft). `phpcs -i` zeigt dann nur die eingebauten
Standards, und jeder Lauf bricht ab mit:
 
```
ERROR: Referenced sniff "Universal.UseStatements.LowercaseFunctionConst" does not exist.
```
 
Diese Meldung ist **kein Codeproblem**. Sie bedeutet, dass die Pfade fehlen.
Nachregistrieren:
 
```bash
cd /tmp/moodlecs
P="$(pwd)/vendor/moodlehq/moodle-cs/moodle"
P="$P,$(pwd)/vendor/phpcsstandards/phpcsextra/Universal"
P="$P,$(pwd)/vendor/phpcsstandards/phpcsextra/Modernize"
P="$P,$(pwd)/vendor/phpcsstandards/phpcsextra/NormalizedArrays"
./vendor/bin/phpcs --config-set installed_paths "$P"
./vendor/bin/phpcs -i
```
 
Erwartete Ausgabe:
 
```
The installed coding standards are MySource, PEAR, PSR1, PSR2, PSR12,
Squiz, Zend, moodle, Universal, Modernize and NormalizedArrays
```
 
Fehlt `moodle`, ist das `composer require` fehlgeschlagen. Fehlen `Universal`,
`Modernize` oder `NormalizedArrays`, ist die Registrierung fehlgeschlagen.
 
Prüfen (Exit 0 = sauber). **Ohne Sniff-Ausschlüsse** – anders als bei
`local_catquiz` ist der ADELE-Ausgangsstand sauber und soll es bleiben:
 
```bash
/tmp/moodlecs/vendor/bin/phpcs --standard=moodle --severity=1 --extensions=php \
    /home/claude/work/moodle-enrol_adele-development
# Auto-Fix, was maschinell fixbar ist:
/tmp/moodlecs/vendor/bin/phpcbf --standard=moodle --extensions=php .
```
 
---
 
## 3. Arbeitsstände holen
 
```bash
mkdir -p /home/claude/work && cd /home/claude/work
for p in enrol mod local; do
  curl -sSL -o $p.zip \
    "https://github.com/ralferlebach/moodle-${p}_adele/archive/refs/heads/development.zip" &
done
wait
for p in enrol mod local; do unzip -q -o $p.zip; done
```
 
**Das Archiv ist unvollständig, und zwar absichtlich.** Alle drei Repositories
tragen ein `.gitattributes` mit `export-ignore` für `.github/`, `docs/`,
`tools/`, `Makefile`, `CHANGELOG.md`, `.gitignore`, `.phpcsignore` und
`.phpcs.xml`. `git archive` — und damit auch der GitHub-Download — lässt diese
Pfade weg; im Repository sind sie vorhanden. Aus dem Fehlen im entpackten Archiv
also **nie** auf ein Fehlen im Repo schließen. Wer an CI, Makefile oder
Dokumentation arbeitet, braucht einen echten Clone oder einen Upload.
 
---
 
## 4. Nullmessung
 
```bash
cd /home/claude/work
for d in moodle-enrol_adele-development moodle-mod_adele-development; do
  echo "=== $d ==="
  /tmp/moodlecs/vendor/bin/phpcs --standard=moodle --severity=1 --extensions=php \
      --no-cache -q --report=summary $d
done
/tmp/moodlecs/vendor/bin/phpcs --standard=moodle --severity=1 --extensions=php \
    --no-cache -q --report=summary --ignore=*/node_modules/*,*/vendor/* \
    moodle-local_adele-development
```
 
Erwartung: **völlig leere Ausgabe** für alle drei. Bei `--report=summary` ist das
die Darstellung von 0 Fehlern und 0 Warnungen. Jede Ausgabe hier ist eine
Regression gegenüber Session 004 und wird untersucht, bevor irgendetwas anderes
passiert.
 
Dazu die reine Syntaxprüfung:
 
```bash
cd /home/claude/work
fail=0
for f in $(find moodle-*_adele-development -name '*.php' -not -path '*/node_modules/*'); do
  php -l "$f" >/dev/null 2>&1 || { echo "SYNTAXFEHLER: $f"; fail=1; }
done
[ $fail -eq 0 ] && echo "alle PHP-Dateien syntaktisch OK"
```
 
---
 
## 5. PostgreSQL: Start, Rolle, Datenbank
 
```bash
service postgresql start        # bzw. pg_ctlcluster 16 main start
sudo -u postgres psql -c "CREATE ROLE moodle LOGIN PASSWORD 'moodle';"
sudo -u postgres psql -c "CREATE DATABASE moodle OWNER moodle;"
```
 
Der DB-Server läuft nach einem Container-Neustart nicht automatisch – vor
Testläufen immer `service postgresql start` voranstellen.
 
---
 
## 6. Moodle beziehen und konfigurieren
 
```bash
cd /home/claude
git clone --branch MOODLE_405_STABLE --depth 1 \
    https://github.com/moodle/moodle.git moodle
mkdir -p /home/claude/moodledata /home/claude/moodledata_phpu
```
 
`/home/claude/moodle/config.php` (Minimalkonfiguration, PostgreSQL, inkl. PHPUnit):
 
```php
<?php
unset($CFG); global $CFG; $CFG = new stdClass();
$CFG->dbtype='pgsql'; $CFG->dblibrary='native';
$CFG->dbhost='localhost'; $CFG->dbname='moodle';
$CFG->dbuser='moodle'; $CFG->dbpass='moodle'; $CFG->prefix='mdl_';
$CFG->dboptions=['dbpersist'=>0,'dbport'=>5432,'dbsocket'=>''];
$CFG->wwwroot='http://localhost';
$CFG->dataroot='/home/claude/moodledata';
$CFG->admin='admin';
$CFG->directorypermissions=0777;
 
// PHPUnit.
$CFG->phpunit_prefix='phpu_';
$CFG->phpunit_dataroot='/home/claude/moodledata_phpu';
 
require_once(__DIR__.'/lib/setup.php');
```
 
Normal-Installation der DB-Tabellen (für Behat/Integration nötig; PHPUnit nutzt
eine eigene Test-DB, siehe §7):
 
```bash
cd /home/claude/moodle
php -d max_input_vars=5000 admin/cli/install_database.php \
    --agree-license --fullname="ADELE Dev" --shortname="adeledev" \
    --adminpass="Admin123!" --adminemail="admin@example.com"
```
 
---
 
## 7. Plugins spiegeln und PHPUnit
 
Die Installationsreihenfolge folgt der Abhängigkeitsrichtung:
`local_adele` zuerst, dann `enrol_adele` und `mod_adele`.
 
```bash
cd /home/claude/moodle
rm -rf local/adele  && cp -a /home/claude/work/moodle-local_adele-development  local/adele
rm -rf enrol/adele  && cp -a /home/claude/work/moodle-enrol_adele-development  enrol/adele
rm -rf mod/adele    && cp -a /home/claude/work/moodle-mod_adele-development    mod/adele
php -d max_input_vars=5000 admin/cli/upgrade.php --non-interactive
```
 
Nach jedem Neu-Spiegeln die PHPUnit-Testumgebung reinitialisieren
(Klassen- und Datenprovider-Registrierung):
 
```bash
cd /home/claude/moodle
php -d max_input_vars=5000 admin/tool/phpunit/cli/init.php --no-composer-self-update
vendor/bin/phpunit enrol/adele/tests/reconciler_test.php
vendor/bin/phpunit --filter test_host_course_removal_rules \
    enrol/adele/tests/reconciler_test.php
```
 
**`enrol_adele` muss aktiviert sein.** Enrol-Plugins sind nach der Installation
nicht automatisch aktiv; `db/install.php` erledigt das. Ist es aus irgendeinem
Grund nicht geschehen, liefert `reconciler::is_active()` `false` und **jeder**
Reconcile-Test wird grün, ohne irgendetwas geprüft zu haben – ein stiller
Fehlschlag ohne Fehlermeldung. Bei unerwartet grünen Tests zuerst
`enrol_is_enabled('adele')` prüfen.
 
---
 
## 8. Behat, Lint und moodle-plugin-ci
 
Behat braucht zusätzlich einen Browser-Treiber und die `behat_*`-Konfiguration.
Im reinen CLI-Container ist ein lokaler Behat-Lauf in der Regel nicht
praktikabel – dort wird Behat über `moodle-plugin-ci` in der CI ausgeführt.
 
```bash
cd /home/claude
composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci ci ^4
export PATH="$PATH:/home/claude/ci/bin:/home/claude/ci/vendor/bin"
 
cd /home/claude/moodle
moodle-plugin-ci phplint  enrol/adele
moodle-plugin-ci phpcs    enrol/adele
moodle-plugin-ci mustache enrol/adele
moodle-plugin-ci grunt    local/adele      # Vue3/AMD-Build-Konsistenz
moodle-plugin-ci phpunit  enrol/adele
moodle-plugin-ci behat    enrol/adele      # nur mit Browser-Treiber
```
 
Grunt muss aus dem **Moodle-Wurzelverzeichnis** laufen; das erzeugte
`amd/build/` wird committet und darf nie in `.gitignore` stehen.
 
---
 
## 9. Issues abrufen
 
Die unauthentifizierte GitHub-REST-API erlaubt **60 Anfragen pro Stunde und
IP-Adresse**. Sieben Issues plus Kommentare erschöpfen das Kontingent
zuverlässig. Deshalb mit Wiederholung arbeiten:
 
```bash
mkdir -p /home/claude/work/issues && cd /home/claude/work
for i in 2 3 4 5 6 7 8; do
  for try in 1 2 3 4 5 6; do
    curl -sS -H "Accept: application/vnd.github+json" \
      "https://api.github.com/repos/Wunderbyte-GmbH/moodle-enrol_adele/issues/$i" \
      -o issues/$i.json
    python3 -c "
import json,sys
sys.exit(0 if json.load(open('issues/$i.json')).get('title') else 1)" && break
    sleep 4
  done
done
```
 
Kommentare liegen unter `.../issues/$i/comments` und sind **nicht optional**: in
diesem Projekt stehen dort mehrfach Richtungsentscheidungen des Auftraggebers,
die dem ursprünglichen Lösungsvorschlag im Issue-Text widersprechen (Beispiel:
Issue #3, wo die Ad-hoc-Task-Lösung den Archivierungsvorschlag ersetzt).
 
Fällt die API dauerhaft aus, ist `web_fetch` auf
`https://github.com/Wunderbyte-GmbH/moodle-enrol_adele/issues/N` der Ausweg –
funktioniert, liefert aber viel Seitenbeiwerk mit.
 
---
 
## 10. Der kanonische Spiegel-und-Prüf-Ablauf
 
```bash
cd /home/claude/moodle
service postgresql start; sleep 2
rm -rf local/adele && cp -a /home/claude/work/moodle-local_adele-development local/adele
rm -rf enrol/adele && cp -a /home/claude/work/moodle-enrol_adele-development enrol/adele
rm -rf mod/adele   && cp -a /home/claude/work/moodle-mod_adele-development   mod/adele
php -d max_input_vars=5000 admin/tool/phpunit/cli/init.php --no-composer-self-update
vendor/bin/phpunit enrol/adele/tests/<test>.php
```
 
---
 
## 11. Typische Stolpersteine
 
- **`Referenced sniff "Universal..." does not exist`** → §2, `installed_paths`
  fehlt. Kein Codeproblem.
- **DB down**: `service postgresql start` vergessen → Verbindungsfehler.
- **PHPUnit nicht reinitialisiert** nach `cp -a` → „No tests executed" oder
  veraltete Datenprovider.
- **`max_input_vars` fehlt** → PHPUnit-Init bricht mit Moodle-Umgebungscheck ab.
- **`enrol_adele` nicht aktiviert** → alle Reconcile-Tests grün, ohne etwas zu
  prüfen. Der gefährlichste Fehlerfall in diesem Projekt, weil er wie Erfolg
  aussieht.
- **Installationsreihenfolge**: `mod_adele` vor `local_adele` einspielen scheitert
  an der deklarierten Abhängigkeit.
- **`export-ignore` verschweigt halbe Repos.** `.github/`, `docs/`, `tools/`,
  `Makefile` und `CHANGELOG.md` fehlen in jedem GitHub-Archiv-Download. Nie
  annehmen, ihre Abwesenheit im entpackten Baum bedeute Abwesenheit im Repo.
- **Docblock-Falle**: neue Methode vor einer privaten Methode eingefügt →
  verwaister Docblock → phpcs „Missing docblock". Nach Einfügungen phpcs laufen.
- **Zone.Identifier-Dateien** wandern bei rohen Ordner-Exporten von Windows mit
  und stören `find`-basierte Läufe. Sie stammen aus dem Export, nicht aus dem
  Repo; mit `find . -iname '*Zone.Identifier*' -delete` wegräumen.
