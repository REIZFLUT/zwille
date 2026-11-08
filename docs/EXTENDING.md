# Erweiterungen (Plugins)

Zwille lässt sich über Plugins erweitern, ohne den Kern anzufassen. Ein Plugin ist
ein Verzeichnis unter `plugins/`, das in `config/plugins.yaml` freigeschaltet wird. Es wird
beim Boot in den Container und den Router eingebunden und arbeitet mit **typisierten
Events** – nicht mit globalen Funktionen wie in klassischen PHP-Anwendungen.

Die Vertragsversion steht in `src/Plugin/PluginApi.php` (`PluginApi::VERSION`). Ein Plugin
deklariert, was es braucht (`requires.api`); die App verweigert den Start, wenn das nicht
erfüllt ist.

**Plugins sind vertrauenswürdiger Code.** Es gibt keine Sandbox: ein geladenes Plugin läuft
mit denselben Rechten wie die Anwendung selbst (Datenbank, Dateisystem, Netz, Secrets).
Lesen Sie ein Plugin, bevor Sie es freischalten – siehe [Sicherheit](#sicherheit).

## Aufbau eines Plugins

```
plugins/
└── utm-tagging/
    ├── plugin.yaml            # Pflicht: name, version, description, optional requires.api
    ├── services.yaml          # optional: Services des Plugins (autowire/autoconfigure)
    ├── config/
    │   └── routes.yaml        # optional: eigene Routen (YAML oder PHP)
    ├── src/                   # PHP-Klassen, Namespace Plugin\UtmTagging
    │   ├── UtmTagger.php
    │   └── ...
    └── README.md              # optional, aber empfohlen
```

| Regel | Bedeutung |
|---|---|
| Verzeichnisname = Plugin-Name = Schlüssel in der Whitelist | `[a-z0-9-]`, 3–40 Zeichen |
| Namespace | aus dem Namen abgeleitet: `utm-tagging` → `Plugin\UtmTagging` |
| Autoloading | über den Composer-ClassLoader; kein `composer dump-autoload` nötig |
| `plugin.yaml` | `name`, `version` (SemVer), `description`, optional `requires: {api: '>=1.0'}` |
| `services.yaml` | wird wie eine App-Konfiguration importiert; Autowiring, `#[AsEventListener]`, `#[AsCommand]` funktionieren |
| `config/routes.yaml` | wird in den Router importiert; die Zugriffskontrolle der eigenen Seiten liegt beim Plugin |

## Freischalten

Alles steht in [`config/plugins.yaml`](../config/plugins.yaml):

```yaml
plugins:
    utm-tagging:
        enabled: true          # fehlt der Schlüssel, gilt true
        settings:
            source: newsletter
            medium: email

when@dev:                      # Umgebungs-Overlay: wird über die Einträge oben gemergt
    plugins:
        utm-tagging:
            settings:
                campaign: lokaltest
```

- **Nur hier genannte Plugins werden geladen.** Ein Verzeichnis, das nicht in der Whitelist
  steht, wird vollständig ignoriert – kein Autoloading, keine Services, keine Routen.
- `enabled: false` deaktiviert ein Plugin, ohne es zu löschen.
- `when@<env>:` erlaubt es, je Umgebung zu schalten oder einzelne Einstellungen zu
  überschreiben (rekursiv gemergt).
- **Fail-closed:** ist ein aktiviertes Plugin nicht auffindbar, ist seine `plugin.yaml`
  unlesbar oder seine `requires.api`-Angabe nicht erfüllbar, bricht der Boot mit einer
  klaren Meldung ab, statt halb geladen weiterzulaufen.
- Ein Wechsel in der Whitelist (aktivieren, deaktivieren, `settings` ändern) und eine neue
  `version` in `plugin.yaml` wirken **ohne** `cache:clear`: Der kompilierte Container und der
  Routen-Cache gehören jeweils zu genau einer Menge aktiver Plugins (ein Fingerabdruck steckt im
  Klassennamen des Containers), eine geänderte Menge baut beim ersten Request ihren eigenen.
  Änderungen am *Code* eines Plugins (`services.yaml`, `config/routes.yaml`, Klassen) brauchen
  dagegen wie jeder andere Code `php bin/console cache:clear --env=<env>` bzw. einen Deploy.

## Einstellungen

`settings` ist frei formbar und gehört dem Plugin. Es wird unter dem Parameter
`app.plugins.<name>.settings` in den Container gelegt und in der `services.yaml` des Plugins
injiziert:

```yaml
services:
    Plugin\UtmTagging\UtmTagger:
        arguments:
            $settings: '%app.plugins.utm-tagging.settings%'
```

Fehlende Schlüssel muss die Klasse selbst mit Defaults abfangen – sie ist dafür
verantwortlich, dass ein nicht konfiguriertes Plugin nichts tut statt zu scheitern.

Weil die Werte Container-Parameter sind, leitet `%` einen Platzhalter ein (`%env(NAME)%`).
Ein wörtliches Prozentzeichen wird als `%%` geschrieben, etwa `campaign: '50%%-rabatt'` oder
eine URL-kodierte Adresse `url: 'https://hooks.example.com/x?sig=a%%2Fb'`. Zwei einzelne `%`
in einem Wert würden als Parametername gelesen und den Boot abbrechen.

## Event-Katalog

Alle Events liegen unter `App\Event\…` und sind über `@api` / `@since` als öffentlich
markiert. Abonniert wird mit `#[AsEventListener]` (Autoconfigure aktiv) oder per
`EventSubscriberInterface`.

| Event | Wann | Was ein Plugin darf |
|---|---|---|
| `Redirect\RedirectResolvingEvent` | Kurzlink gefunden und aktiv, vor dem Senden der Weiterleitung | Ziel überschreiben, Statuscode ändern, **vetoen** |
| `Redirect\RedirectUnresolvedEvent` | Pfad ohne passenden Link | ein eigenes Ziel setzen (`setTarget()`) |
| `Tracking\ClickRecordingEvent` | Klick wird gleich gespeichert, nach der Antwort | **vetoen** (Klick wird nicht geschrieben) |
| `Tracking\ClickRecordedEvent` | Klick ist gespeichert (nach dem Commit) | nur lesen (weiterleiten, protokollieren) |
| `Link\LinkPersistingEvent` | vor dem Schreiben eines Links (neu oder geändert) | **ablehnen** mit Nutzermeldung |
| `Link\LinkSavedEvent` | Link geschrieben (`wasCreated()`) | nur lesen |
| `Link\LinkDeletedEvent` | Link gelöscht | nur lesen |
| `Link\ShortCodeGeneratingEvent` | Kürzel wird erzeugt | Kürzel vorschlagen (`setShortCode()`) |
| `Api\ApiResponseEvent` | erfolgreiche API-Antwort wird serialisiert | Felder ergänzen (`setField()`) |
| `Admin\DashboardWidgetsEvent` | Dashboard wird gerendert (für jeden Nutzer) | Karten hinzufügen (`add(AdminWidget)`); `getViewer()`, `isViewerAdmin()` |
| `Admin\AdminNavBuildingEvent` | Admin-Navigation wird gerendert (nur für Admins) | Einträge hinzufügen (`add(AdminNavItem)`) |

### Weiterleitung

`RedirectResolvingEvent` bekommt `Request`, `Link`, das bereits berechnete Ziel und den
Statuscode. Ein Plugin kann das Ziel anpassen (Kampagnen-Parameter, A/B-Routing,
Regionen-Regeln) oder den Statuscode wechseln:

```php
#[AsEventListener(event: RedirectResolvingEvent::class)]
public function onRedirect(RedirectResolvingEvent $event): void
{
    $event->setTarget($event->getTarget().'?utm_source=newsletter');
}
```

Zwei Details, die man kennen sollte:

- Die Weiterleitung ist der heiße Pfad. Das Ziel wird **eigene** DB-Zugriffe kosten – der
  Link selbst ist schon geladen. Halten Sie Listener hier query-frei.
- Statuscodes 301/308 landen im Cache von Proxys; der Kern setzt dafür `Vary:
  Accept-Language`. Wer auf permanent umstellt, nimmt damit auch die Zählung zurück.

`RedirectUnresolvedEvent` greift, wenn kein Link existiert (`null` bei einer Domain, die
keine Kurzdomain ist). Ohne Ziel läuft die konfigurierte Logik („unbekannte Kürzel
weiterleiten") und sonst die 404-Seite.

### Klicks

`ClickRecordingEvent` läuft im `kernel.terminate`-Pfad – nach dem Senden der Antwort, unter
PHP-FPM nach `fastcgi_finish_request()`. Ein Veto verhindert das Schreiben; der Besucher
merkt davon nichts, im Log steht der Grund:

```php
#[AsEventListener(event: ClickRecordingEvent::class)]
public function onClick(ClickRecordingEvent $event): void
{
    if ($event->getRequest()->headers->get('X-Company-Probe')) {
        $event->veto('monitoring probe');   // zählt nicht in der Statistik
    }
}
```

`ClickRecordedEvent` ist der Anknüpfungspunkt für M2M-Meldungen (Webhook, eigenes
Analytics-System). Auch dieser Pfad läuft nach der Antwort: ein langsamer Endpunkt kostet
nur einen Worker, nie einen Besucher – Fehler dürfen deshalb protokolliert, aber nicht
geworfen werden.

### Links und Kürzel

`LinkPersistingEvent` feuert vor jedem Schreiben, auch vor dem Abruf des Seitentitels.
Ein `reject('Meldung')` bricht den Vorgang ab: im Web-Formular erscheint die Meldung als
Feldfehler, die API antwortet mit **422** und `{"error": "Meldung"}`. Massenaktionen und der
CSV-Import überspringen den Datensatz dann, statt abzubrechen.

`ShortCodeGeneratingEvent` erlaubt sprechende Kürzel. Der Vorschlag ist nur ein Vorschlag:
er muss die Formatregeln des Kerns erfüllen und auf der Domain frei sein, sonst greift die
Zufallserzeugung. So kann ein Plugin keine Kollision erzeugen.

### API und Admin-Oberfläche

`ApiResponseEvent` trägt einen `operation`-Namen (`links.list`, `links.show`,
`links.create`, `links.update`, `links.stats`, `links.expand`, `domains.list`,
`stats.show`) und den fertigen Payload. **Felder ergänzen, nie entfernen** – veröffentlichte
APIs verlassen sich auf das, was da ist. Fehlerantworten lösen das Event nicht aus.

`DashboardWidgetsEvent` und `AdminNavBuildingEvent` erweitern die Oberfläche:

```php
$event->add(new AdminWidget('my-campaigns', 'Meine Kampagnen', (string) $count, hint: 'live', url: '/plugin/campaigns'));
$event->add(new AdminNavItem('Wartung', '/plugin/admin/maintenance'));
```

**Das Dashboard ist die Startseite jedes angemeldeten Nutzers, keine Admin-Seite.** Das Event
nennt den Betrachter (`getViewer()`) und ob er gerade Admin-Rechte hat (`isViewerAdmin()`,
Rolle *und* IP-Freigabe). Eine Karte mit installationsweiten Zahlen gehört hinter
`isViewerAdmin()`, eine persönliche Karte zählt nur die Daten von `getViewer()`:

```php
if ($event->isViewerAdmin()) {
    $event->add(new AdminWidget('clicks-today', 'Klicks heute', (string) $count, hint: 'alle Nutzer'));
}
```

Karten werden nach den eingebauten Karten gerendert und nutzen deren Markup (`.stats`,
`.stat`); die Navigation erscheint nur dort, wo auch die eingebauten Admin-Punkte stehen.
Eigene Seiten liefert das Plugin selbst (Route + Zugriffsschutz, z. B. `#[IsGranted]`).

## Veto oder Ablehnung?

| | Veto (`VetoableEvent`) | Ablehnung (`RejectableEvent`) |
|---|---|---|
| Wirkung | Aktion unterbleibt | Vorgang scheitert mit Meldung |
| Sichtbarkeit | **keine** – für den Besucher sieht es aus wie ein unbekannter Link | sichtbar: Formularfehler bzw. HTTP 422 |
| Wofür | Sperren, Bot-/Probe-Filter, Datenschutz | Regeln wie „Ziel nicht erlaubt", „Domain gesperrt" |
| Protokoll | Grund landet im Log (`info`) | Meldung geht an den Aufrufer |

## Versionierung und Stabilitätszusage

- Innerhalb einer Major-Version von `PluginApi::VERSION` bleiben Events und ihre
  dokumentierten Methoden abwärtskompatibel; neue Events und optionale Methoden kommen in
  Minor-Schritten dazu.
- Was `@api` trägt, ist Vertrag. Alles andere (`App\…`-Interna, Services ohne `@api`) kann
  sich mit jedem Release ändern – verlassen Sie sich darauf nicht.
- `requires.api` prüft der Boot; ein Plugin mit unerfüllter Anforderung wird abgelehnt.

## Sicherheit

- **Kein Sandboxing.** Ein Plugin ist Anwendungscode: es kann Links ändern, Klicks
  unterdrücken, Daten lesen und nach außen senden.
- Vor dem Freischalten lesen und prüfen: Herkunft, Version, Inhalt. `app:plugin:validate`
  weist unter anderem auf **weltweit schreibbare** Dateien und Verzeichnisse im Plugin hin
  (ein Plugin, das jeder Prozess ändern kann, ist eine Hintertür).
- Secrets gehören nicht in `plugin.yaml` oder `settings` im Repository. Nutzen Sie
  Umgebungsvariablen: `%env(NAME)%` wird auch *innerhalb* der `settings` aufgelöst (geprüft –
  die Signatur des Beispiel-Plugins wurde mit dem Wert aus der Umgebung berechnet), die
  `.env` liegt außerhalb des Webroots.
- Weiterleitungsziele aus Plugin-Hand landen in `Location`-Headern. Was ein Plugin dort
  einsetzt, muss es selbst validieren (der Kern prüft nur, was er selbst erzeugt).
- Der Weiterleitungs- und Klickpfad ist auf Latenz optimiert; Plugins, die dort rechnen
  oder das Netz ansprechen, müssen das bewusst tun.

## Betrieb

- Plugin-Code wird **beim Kompilieren des Containers** gelesen: nach Änderungen an Services,
  Routen oder Klassen `cache:clear` (bzw. ein Deploy) ausführen. Whitelist-Änderungen erkennt
  der Kernel selbst (siehe oben); der neue Container entsteht dann beim ersten Request, das
  Cache-Verzeichnis muss dafür wie üblich für PHP-FPM schreibbar sein.
- Die aufgelöste Plugin-Liste wird in `var/cache/<env>/plugins.cache` gemerkt und nur neu
  gelesen, wenn sich Whitelist oder eine `plugin.yaml` inhaltlich ändert – der Boot parst
  also nicht bei jedem Request YAML.
- PHPStan liest den Dev-Container aus `var/cache/dev/App_KernelDevDebugContainer.xml`. Ist in
  `dev` ein Plugin aktiv, trägt der Container einen Fingerabdruck im Namen; für die Analyse
  dann `containerXmlPath` anpassen oder die Plugins in `dev` abschalten.
- Klassender Autoloading läuft bei jedem Boot; ein neues Plugin braucht keinen
  Composer-Lauf.
- `app:plugin:validate` gehört ins Deployment (CI und `bin/deploy.sh`, das es vor dem
  PHP-FPM-Reload ausführt): es findet Tippfehler in Namen, unerfüllte API-Anforderungen und
  Rechteprobleme, bevor sie im Betrieb auffallen. Der Befehl meldet Fehler mit **Exit-Code 1**
  und Warnungen mit Exit 0. Ein *fundamental* kaputtes Plugin (aktiviert, aber ohne
  Verzeichnis) lässt schon den Boot scheitern – dann bricht auch der Befehl ab, ebenfalls mit
  Exit 1; das ist die Fail-closed-Regel von oben.

## Werkzeuge

```bash
php bin/console app:plugin:list       # name | version | aktiv/deaktiviert/nicht gefunden | requires.api | Verzeichnis
php bin/console app:plugin:validate   # Fehler -> Exit 1, Warnungen -> Exit 0
```

## Plugins testen

Die Testumgebung zeigt mit `APP_PLUGIN_DIR` und `APP_PLUGIN_CONFIG` auf
`tests/Fixtures/plugins` bzw. `tests/Fixtures/plugins.yaml` (siehe `phpunit.dist.xml`). Ein
Test kann dort ein Plugin-Verzeichnis und eine Whitelist bereitstellen und damit beweisen,
dass Klassen, Services und Routen wirklich geladen werden – Vorbild ist das Fixture-Plugin
`probe` samt `tests/Functional/PluginLoadingTest.php`.

Für Verhalten im Ablauf ist der direkte Weg oft besser: den Listener des Plugins als
Testservice registrieren (`when@test` in `config/services.yaml`) und über einen echten
HTTP-Request prüfen, was er bewirkt.

## Mitgelieferte Beispiele

Zwei Plugins liegen bei und sind standardmäßig **deaktiviert** – als Vorlage und als
funktionierende Beispiele:

| Plugin | Zweck | Einstellungen |
|---|---|---|
| `plugins/utm-tagging` | hängt Kampagnen-Parameter an Weiterleitungsziele | `source`, `medium`, `campaign`, `content`, `term`, `overwrite` |
| `plugins/click-webhook` | meldet gespeicherte Klicks signiert an ein M2M-Ziel | `url`, `timeout`, `secret`, `headers` |

Aktivieren (Beispiel aus `config/plugins.yaml`):

```yaml
plugins:
    click-webhook:
        enabled: true
        settings:
            url: 'https://crm.example.com/hooks/clicks'
            secret: '%env(CLICK_WEBHOOK_SECRET)%'
```

Jedes Beispiel-Plugin bringt eine eigene `README.md` mit Zweck, Einstellungen und Hinweisen
mit.
