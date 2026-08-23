![Alt text](docs/logo.png?raw=true "logo")

# Contao Backend Column Toggle

Blendet Spalten einer Contao-Backend-Listenansicht per Klick auf den **Spaltentitel** aus
und wieder ein. Die Spalten können über einen Button im Menu der globalen Operationen wieder eingeblendet werden. Die Einstellung wird pro Backend-Benutzer und pro Tabelle in der User-Session gespeichert
und übersteht einen Logout.

## Screenshot

Listenansicht mit geöffnetem Spalten-Menü. Die abgewählten Spalten werden serverseitig
gar nicht erst gerendert.

![Listenansicht mit geöffnetem Spalten-Menü](docs/list_view_1.png?raw=true "Spalten ein- und ausblenden")

## Voraussetzung

Die Listenansicht muss den Spaltenmodus verwenden:

```php
$GLOBALS['TL_DCA']['tl_example']['list']['label']['showColumns'] = true;
```

Nur dann rendert Contao eine `<thead>`-Zeile mit `<th class="tl_folder_tlist col_<fieldname>">`.

### Ablauf

1. Klick auf einen Spaltentitel → Spalte wird sofort im DOM versteckt und per AJAX gespeichert.
2. Beim nächsten Seitenaufruf entfernt der Hook das Feld bereits serverseitig aus der DCA.
3. Über den Button *Spalten* bei den globalen Operationen lassen sich Spalten wieder einblenden
   (das erfordert einen Reload, da die Spalte serverseitig gar nicht gerendert wurde).
4. Mindestens eine Spalte bleibt immer sichtbar.
