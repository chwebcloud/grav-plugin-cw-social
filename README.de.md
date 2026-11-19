# CHWEB-Social (cw-social)

Teilt Seiten automatisch auf Social Media: **Facebook** (Link-Post auf die
Seite) und **Instagram** (Bild der Seite mit Text). Welche Seiten in Frage
kommen, sagt die Konfiguration: Kinder der eingetragenen Routen (`/news`,
`/events`) mit den eingetragenen Seitentypen (`item`, `event`).

## Einrichtung

1. **Meta-App anlegen:** [developers.facebook.com](https://developers.facebook.com/) → App erstellen (Typ «Business»). Dafür braucht es ein Konto, das Administrator der Facebook-Seite ist.
2. **Page-Access-Token holen:** Graph API Explorer → App → User Token mit `pages_manage_posts`, `pages_read_engagement` und für Instagram zusätzlich `instagram_basic`, `instagram_content_publish` → damit `/me/accounts` abfragen → Page-Token der Zielseite kopieren → im Token-Debugger «Extend» (langlebig).
3. **Instagram verbinden** (wenn gewünscht): Das Instagram-Konto muss ein **Business- oder Creator-Konto** sein und in den Einstellungen der Facebook-Seite unter «Verknüpfte Konten» hängen. Die ID liest das Plugin selbst.
4. **Plugin konfigurieren:** Admin → Plugins → *CHWEB Social*: Page-ID, Token, **öffentliche Site-URL** (nie localhost, nie die Testdomain), Routen und Seitentypen eintragen, Instagram einschalten, aktivieren.
5. **Zeitplaner von Grav läuft lassen** — so postet das Plugin: Admin → Werkzeuge → Zeitplaner zeigt die Cron-Zeile, die eingerichtet sein muss; der Auftrag *cw-social-share* steht dort, sobald das Plugin aktiviert ist.
6. **Testen:** `bin/plugin cw-social test` → zeigt den Seitennamen und, wenn eingeschaltet, das Instagram-Konto.

## Nutzung

- Auf der Seite (Admin) im Reiter **«Teilen»** die Schalter «Auf Facebook teilen» und «Auf Instagram teilen» setzen, optional eigenen Text. Darunter zeigt die **Vorschau**, wie beide Beiträge aussehen werden – sie folgt jeder Änderung im Formular, noch vor dem Speichern.
- Geteilt wird, sobald die Seite publiziert und ihr Datum erreicht ist, beim nächsten Lauf des Zeitplaners (Vorgabe: alle 15 Minuten). Von Hand derselbe Lauf:
  `bin/plugin cw-social share` (zeigt nur) bzw. `--force` (postet).
- Eine Seite mit `entwurf: true` im Kopf (Schalter «Entwurf», wenn das Theme ihn anbietet) wird nicht geteilt, solange der Entwurf steht.
- Nach dem Teilen zeigt die Vorschau je Kanal **geteilt am … · Beitrag ansehen**. Die Beitrags-Nummern stehen unsichtbar im Seitenkopf (`cw_social.fb_post_id`, `ig_post_id`, `ig_permalink`, Zeitstempel) **und** in `user/data/cw-social/protokoll.json`. Eines von beidem genügt, damit nichts zweimal rausgeht — wichtig bei schreibgeschütztem `user/pages`, wo der Seitenkopf gar nicht geschrieben werden kann.
- **Nochmals teilen** (Schalter): beim nächsten Lauf geht der Beitrag auf den eingeschalteten Kanälen erneut raus, etwa als Erinnerung; der Schalter springt danach von selbst auf Nein.

## Was erfüllt sein muss, bevor etwas rausgeht

Drei Dinge, bei jedem Lauf geprüft, im Zeitplaner wie auf der Kommandozeile.
Fehlt eines, wird nicht gepostet, und der Grund steht im Grav-Protokoll und in
der Vorschau:

- **`site_url` ist gesetzt.** Es gibt bewusst keinen Rückfall auf den gerade
  aufgerufenen Host: Eine Kopie der Website auf einer Testdomain, die den
  Token mitbringt, würde sonst Beiträge mit Links auf die Testdomain
  veröffentlichen.
- **Page-ID und Token sind da.**
- **`user/data/cw-social/` ist beschreibbar.** Dort liegt das Protokoll, und
  das Protokoll ist es, was einen gescheiterten Lauf daran hindert, dieselbe
  Seite erneut zu posten.

Je Seite und Kanal wird höchstens **dreimal** versucht (`max_attempts`).
Danach bleibt der Kanal in Ruhe, der Grund steht im Protokoll. Ohne diese
Bremse lässt ein Bild, das Instagram nie annimmt, jeden Lauf auf sich warten.

## Ohne Zeitplaner

Auf einer Installation ohne Cron prüft `auto_share: true` während einer
Seitenanfrage — gedrosselt und mit Sperre, damit zwei gleichzeitige Anfragen
nicht beide posten. Ab Werk **aus**: Es macht die Anfrage eines Besuchers
langsamer und kann nicht so lange warten, wie Instagram manchmal braucht.

## Instagram: was Meta verlangt

- Ein **Bild auf der Seite**: das Titelbild (`header.bild`) oder sonst das erste Bild. Das Plugin liefert es Instagram als JPEG über die öffentliche Site-URL; PNG und WebP weist Instagram ab, darum wird umgerechnet.
- **Seitenverhältnis** zwischen 4:5 (0.80) und 1.91:1, mindestens 320 Pixel breit. Das Plugin misst das Bild selbst und legt für eines, das nicht gehen kann, gar keinen Medien-Container an; der Grund steht im Grav-Protokoll, und dieser Kanal wird für diese Seite nicht erneut versucht.
- **Links sind nicht klickbar.** Das Plugin hängt die Adresse der Seite als Text an.
- Das Plugin wartet bis 9 Sekunden, bis Instagram das Bild verarbeitet hat; dauert es länger, versucht es der nächste Lauf erneut.

## Umgebungen

Auf der **Werkstatt** und auf einer **Testdomain** darf nie gepostet werden.
Dafür sorgen zwei Dinge, und das erste braucht keine Einstellung: **ohne
`site_url` wird nicht gepostet**, und eine Kopie hat normalerweise keine
eigene. Wo eine Kopie die Live-Konfiguration mitbekommen hat, gehört in
`user/env/<host>/config/plugins/cw-social.yaml`:

```yaml
scheduler: false
auto_share: false
```

Die Reiter und Schalter bleiben sichtbar, es geht nichts raus.

## Warum kein Save-Hook?

Gepostet wird nicht beim Speichern, sondern im Lauf des Zeitplaners — so
funktioniert das Teilen unabhängig davon, WIE eine Seite entsteht (admin1,
admin2/api, Datei-Upload, Git-Sync), und ein geplantes Datum wird korrekt
abgewartet.

## Das Vorschau-Feld

Die Vorschau ist eine einzelne Web-Component in `admin-next/fields/`, die
Admin 2.0 wie jede andere Felddatei lädt. Sie wertet keinen Code aus und
läuft darum auch dort, wo Admin 2.0 mit einer Content Security Policy
arbeitet. Eine Folge davon: Nach einem Update des Plugins braucht der Admin
einmal einen Neuladen, bevor eine neue Vorschau gilt.

Die Nachbarfelder liest sie aus dem Formular, weil Admin 2.0 einer
Web-Component nur ihren eigenen Wert reicht. Erkennt sie sie nicht — ein
geändertes Admin-Markup genügt dafür —, sagt sie das und zeigt den
gespeicherten Stand, statt nichts zu zeigen.

Der Endpunkt dahinter (`POST /cw-social/vorschau`) verlangt `api.pages.read`
und achtet die Zugangsregeln der Seite selbst.

## Voraussetzungen

Grav ≥ 1.7, geprüft auf Grav 1.7 und 2.0. PHP 7.4 oder neuer mit cURL und GD.
