![Alt text](docs/logo.png?raw=true "logo")

# Contao Backend Column Toggle

Blendet Spalten einer Contao-Backend-Listenansicht per Klick auf den **Spaltentitel** aus
und wieder ein. Die Spalten können über einen Button im Menu der globalen Operationen wieder eingeblendet werden. Die Einstellung wird pro Backend-Benutzer und pro Tabelle in der User-Session gespeichert
und übersteht einen Logout.

## Screenshot

Listenansicht mit geöffnetem Spalten-Menü. Die abgewählten Spalten werden per CSS ausgeblendet.

![Listenansicht mit geöffnetem Spalten-Menü](docs/list_view_1.png?raw=true "Spalten ein- und ausblenden")

## Voraussetzung

Die Listenansicht muss den Spaltenmodus verwenden:

```php
$GLOBALS['TL_DCA']['tl_example']['list']['label']['showColumns'] = true;
```

Nur dann rendert Contao eine `<thead>`-Zeile mit `<th class="tl_folder_tlist col_<fieldname>">`.

## Funktionsweise für Entwickler und Contao Nerds :-)

| Baustein                                              | Aufgabe                                                                                                                                                                     |
|-------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `EventListener\DataContainer\ColumnToggleListener`    | `loadDataContainer`-Hook (Priorität `-300`). Injiziert ein unsichtbares Daten-Element als globale Operation und blendet die ausgeblendeten Spalten per Inline-CSS aus, bis der Stimulus-Controller übernimmt. Die DCA (`list.label.fields`) bleibt unverändert. |
| `Session\ColumnVisibilityStorage`                     | Liest/schreibt den Schlüssel `BE_COLUMN_TOGGLE` im Contao-Session-Bag `contao_backend`.                                                                                     |
| `Controller\BackendController\ColumnToggleController` | Nimmt den AJAX-Request unter `%contao.backend.route_prefix%/column-toggle` entgegen (POST, CSRF-geschützt) und persistiert die Auswahl.                                     |
| `assets/controllers/column_toggle_controller.js`      | Stimulus-Controller `cbct--column-toggle`. Macht die Header-Zellen klickbar und hängt den Button *Spalten* mit Dropdown in die globalen Operationen (`#tl_buttons`).        |

Es wird **kein** Core-Template überschrieben. Das Daten-Element wird als globale
Operation eingehängt, weil das der einzige Erweiterungspunkt einer Listenansicht ist,
der ohne Template-Override auskommt.

### Ablauf

1. Klick auf einen Spaltentitel → Spalte wird sofort per CSS versteckt (Klasse `cbct-column-toggle--hidden` auf den Zellen `col_<feld>`) und per AJAX gespeichert.
2. Beim nächsten Seitenaufruf blendet ein Inline-`<style>` des Hooks die Spalten bereits beim Rendern aus (kein Aufflackern). Der Stimulus-Controller übernimmt danach und entfernt das `<style>`-Element.
3. Über den Button *Spalten* bei den globalen Operationen lassen sich Spalten sofort wieder einblenden, ohne Reload.
4. Mindestens eine Spalte bleibt immer sichtbar.

### Warum der Session-Bag und nicht direkt `tl_user.session`?

Contaos `UserSessionListener` lädt bei `kernel.request` die serialisierte Spalte
`tl_user.session` in den Session-Bag `contao_backend` und schreibt bei
`kernel.response` den **kompletten Bag** wieder in diese Spalte zurück. Ein direktes
`UPDATE tl_user SET session = …` würde deshalb am Ende desselben Requests wieder
überschrieben. Der Bag ist also der richtige Zugriffsweg — die Persistenz in
`tl_user.session` erledigt Contao dann selbst.

Einschränkung: In Backend-Popups (`?popup=1`) verwendet Contao einen separaten
Bag (`_contao_be_attributes_popup`) und der `UserSessionListener` schreibt nichts in
die Datenbank. Änderungen in einem Popup-Listing sind daher nicht dauerhaft.

### Warum nur CSS?

Die Spalten werden bewusst nicht aus `list.label.fields` entfernt. Label-Callbacks
(`list.label.label`) anderer Bundles greifen oft über die Position auf die Spalten zu
(`$args[3]`). Würde eine Spalte entfernt, landeten ihre Werte in der falschen Spalte
oder der Callback würde fehlschlagen.

## Assets bauen

```bash
cd vendor/markocupic/contao-backend-column-toggle
npm install
npm run build
```

Erzeugt `public/backend.js` und `public/backend.css`. Die Dateinamen sind in
`ColumnToggleListener::addAssets()` referenziert, deshalb ist das Encore-Versioning
bewusst deaktiviert.

Anschließend:

```bash
vendor/bin/contao-console assets:install --symlink --relative
vendor/bin/contao-console cache:clear
```
