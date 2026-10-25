# {{COMPONENT}} — JMeter load test

A read-endpoint plateau load test (AP6). It ramps up `threads` virtual users over `rampup`
seconds and keeps them active for `duration` seconds, repeatedly requesting a read endpoint and
asserting HTTP 200.

## Run locally

```bash
make jmeter BASE_URL=http://localhost:8000        # downloads JMeter on first run
# or manually, after seeding:
eval "$(php tests/load/seed_large.php)"
apache-jmeter/bin/jmeter -n -t tests/load/{{PLUGINNAME}}-read-endpoints.jmx \
  -Jbase_url="$BASE_URL" -Jthreads=25 -Jrampup=10 -Jduration=90 -l results.jtl
```

## Scope

Smoke-level: it exercises a public read path under concurrency to catch gross regressions. Load
tests for the account-creation + capacity-lock + mailqueue paths are tracked with the P0 access
work and will be added as those flows stabilise.

## {{PLUGINNAME}}-capacity-race.js (Concurrency-/Write-Gate)

Lässt viele Requests gleichzeitig um den **letzten freien Platz** einer kapazitätsbegrenzten
{{PLUGINTITLE}}-Instanz konkurrieren. Ergänzt die PHPUnit-Concurrency-Tests: Diese können die Exaktheit
der Kapazitätsgrenze und das saubere Freigeben des Locks nachweisen, **nicht aber die wechselseitige
Ausschließung** — PostgreSQL-Advisory-Locks sind innerhalb einer DB-Session wiedereintrittsfähig,
ein einzelner PHP-Prozess kann seine eigene kritische Sektion also immer erneut betreten. Erst echt
parallele Requests (getrennte Sessions) beanspruchen das Lock.

Der Schwellenwert ist das fachliche Verhalten, nicht nur die Latenz: `{{PLUGINNAME}}_enrolled` darf
`FREE_SEATS` nie überschreiten. Der Schreibpfad läuft korrekt über **POST** mit `confirm=1` und
`sesskey` (ein GET erzeugt kein Konto).

```
k6 run -e BASE_URL=https://... -e COURSEID=42 -e FREE_SEATS=1 -e VUS=30 \
  tests/load/{{PLUGINNAME}}-capacity-race.js
```

Voraussetzung: Die Instanz hat `maxparticipants` gesetzt und ist bis auf `FREE_SEATS` Plätze gefüllt.
