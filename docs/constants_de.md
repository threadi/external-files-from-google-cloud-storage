# Zugangsdaten per Konstante festlegen

Die Zugangsdaten für Google Cloud Storage lassen sich als Konstanten festlegen, z. B. in der `wp-config.php`. So landen sie nicht in der Datenbank und nicht in Datenbank-Backups, und jede Umgebung kann eigene Werte haben.

Erfordert _External Files in Media Library_ 5.7.0 oder neuer. Siehe auch die [Dokumentation des Hauptplugins](https://github.com/threadi/external-files-in-media-library/blob/master/docs/constants_de.md).

## So funktioniert es

* **Alle Pflichtfelder:** Die Konstanten werden nur genutzt, wenn für alle Pflichtfelder eine Konstante gesetzt ist (unten ohne „optional“). Fehlt eine, wird keine Konstante genutzt und die Einstellungsseite zeigt, welche fehlen.
* Eine Konstante ersetzt den Wert aus den Plugin-Einstellungen, dem Benutzerprofil oder einer gespeicherten externen Quelle.
* Der Speicherort der Zugangsdaten lässt sich dann nicht mehr ändern. In den Einstellungen erscheinen Hinweise statt der Eingabefelder.
* Werte von Zugangsdaten-Feldern werden nie an den Browser übertragen.
* Ohne `EFML_GOOGLE_CLOUD_STORAGE_BUCKET` kann jeder Bucket des Dienstkontos genutzt werden. Mit dieser Konstante sind gespeicherte externe Quellen für andere Buckets nicht nutzbar, solange die Konstanten gesetzt sind (kein Öffnen, Import, Synchronisierung oder Export), bereits importierte Dateien werden weiter ausgeliefert. Manuelle Importe aus anderen Buckets sind ebenfalls nicht möglich.

Der Name jeder Konstante setzt sich zusammen aus `EFML_` + Name des Dienstes + `_` + Name des Feldes, alles in Großbuchstaben.

## Konstanten

```php
define( 'EFML_GOOGLE_CLOUD_STORAGE_AUTHENTICATION_JSON', '{"type":"service_account", ...}' );
define( 'EFML_GOOGLE_CLOUD_STORAGE_BUCKET', 'dein-bucket' ); // optional
```
