# FressnapfTracker
Diese Modul stellt verschiedene Erweiterungen bereit, um die Arbeit mit Symcon zu vereinfachen.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

- SMS-gestützte Authentifizierung gegen den Fressnapf-Tracker-Dienst inklusive Token-Verwaltung.
- Automatisches Auslesen und Auswählen der verfügbaren Tracker-Geräte (Seriennummer / Token).
- Abruf der Gerätedaten (Position, Batterie, Ladezustand etc.) und Ablage in IP-Symcon-Statusvariablen.
- Mehrsprachige Oberfläche (Deutsch/Englisch) dank gepflegter Übersetzungen.

### 2. Voraussetzungen

- IP-Symcon ab Version 8.2

### 3. Software-Installation

* Über den Module Store das 'FressnapfTracker'-Modul installieren.
* Nach der Installation Instanz anlegen und Telefon-/SMS-Daten wie unten beschrieben eintragen.

### 4. Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' kann das 'FressnapfTracker'-Modul mithilfe des Schnellfilters gefunden werden.  
	- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name           | Beschreibung
-------------- | -------------------------------------------------------------
Handynummer    | Telefonnummer im Format `+4917...`; wird für SMS-Anfrage genutzt.
SMS Code       | Sechsstelliger Code aus der Fressnapf-SMS für die Authentifizierung.
Tracker Serial | Auswahlfeld, das nach erfolgreichem Abruf alle verfügbaren Tracker zeigt.

__Buttons__:

Button              | Wirkung
------------------- | ------------------------------------------------------------------------
SMS Anfordern       | Ruft `getSmsCode()` auf und sendet eine SMS an die angegebene Nummer.
SMS Verifizieren    | Prüft den eingegebenen Code via `authenticateSMSCode()` und speichert den Token.
Devices auslesen    | Lädt verfügbare Tracker per `getDevices()` und füllt das Auswahlfeld.
Device Daten holen  | Ruft `getDeviceData()` auf und aktualisiert sämtliche Statusvariablen.

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Name        | Typ     | Beschreibung
----------- | ------- | ---------------------------------------------
Name        | String  | Anzeigename des Trackers und Instanzzusammenfassung.
Latitude    | Float   | Aktuelle Breite des letzten Positionspunktes.
Longitude   | Float   | Aktuelle Länge des letzten Positionspunktes.
Accuracy    | Integer | Genauigkeit der Positionsmessung (Meter).
SampledAt   | String  | Zeitstempel, wann die Position erfasst wurde.
LastSeen    | String  | Zeitstempel, wann der Tracker zuletzt online war.
Battery     | Integer | Batteriestand in Prozent inkl. `%`-Suffix.
Charging    | Boolean | Ladezustand mit zweifarbigem Profil (lädt / lädt nicht).


### 6. Visualisierung

Alle Statusvariablen können im WebFront oder mobilen Apps dargestellt werden. Der Name erscheint in der Instanzübersicht, Positionsdaten lassen sich z. B. auf einer Karte verwenden.

### 7. PHP-Befehlsreferenz

`bool FRT_getSmsCode(int $InstanzID);`
Fordert per API eine SMS für die im Formular hinterlegte Nummer an.

`bool FRT_authenticateSMSCode(int $InstanzID);`
Validiert den SMS-Code, speichert Auth-Token und setzt den Instanzstatus aktiv.

`array FRT_getDevices(int $InstanzID);`
Liefert eine Optionsliste aller verfügbaren Tracker (Seriennummer + Token) und schreibt sie in das Formular.

`bool FRT_getDeviceData(int $InstanzID);`
Ruft aktuelle Gerätedaten ab und aktualisiert sämtliche angelegten Statusvariablen.