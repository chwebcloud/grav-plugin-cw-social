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
5. **Testen:** `bin/plugin cw-social test` → zeigt den Seitennamen und, wenn eingeschaltet, das Instagram-Konto.

## Nutzung

- Auf der Seite (Admin) im Reiter **«Teilen»** die Schalter «Auf Facebook teilen» und «Auf Instagram teilen» setzen, optional eigenen Text. Darunter zeigt die **Vorschau**, wie beide Beiträge aussehen werden – sie folgt jeder Änderung im Formular, noch vor dem Speichern.
- Geteilt wird, sobald die Seite publiziert und ihr Datum erreicht ist — automatisch (gedrosselter Worker) oder per CLI/Cron:
  `bin/plugin cw-social share --force`
- Eine Seite mit `entwurf: true` im Kopf (Schalter «Entwurf», wenn das Theme ihn anbietet) wird nicht geteilt, solange der Entwurf steht.
- Nach dem Teilen zeigt die Vorschau je Kanal **geteilt am … · Beitrag ansehen**. Die Beitrags-Nummern stehen unsichtbar im Seitenkopf (`cw_social.fb_post_id`, `ig_post_id`, `ig_permalink`, Zeitstempel). Einmal geteilt heisst geteilt: spätere Änderungen an der Seite gehen nicht nochmals raus.
- **Nochmals teilen** (Schalter): beim nächsten Speichern geht der Beitrag auf den eingeschalteten Kanälen erneut raus, etwa als Erinnerung; der Schalter springt danach von selbst auf Nein.

## Instagram: was Meta verlangt

- Ein **Bild auf der Seite**: das Titelbild (`header.bild`) oder sonst das erste Bild. Das Plugin liefert es Instagram als JPEG über die öffentliche Site-URL; PNG und WebP weist Instagram ab, darum wird umgerechnet.
- **Seitenverhältnis** zwischen 4:5 (Hochformat) und 1.91:1 (Querformat), mindestens 320 Pixel breit. Ein Panorama lehnt Instagram ab; der Fehler steht dann im Grav-Protokoll und im Reiter bleibt die ID leer.
- **Links sind nicht klickbar.** Das Plugin hängt die Adresse der Seite als Text an.
- Das Plugin wartet bis 9 Sekunden, bis Instagram das Bild verarbeitet hat; dauert es länger, versucht es der nächste Lauf erneut.

## Umgebungen

Auf der **Werkstatt** und auf einer **Testdomain** darf nie gepostet werden.
Dort gehört in `user/env/<host>/config/plugins/cw-social.yaml`:

```yaml
auto_share: false
```

Die Reiter und Schalter bleiben sichtbar, der Worker schweigt. Posten tut nur
die Live-Umgebung — und dort nur, wenn `site_url` die Live-Adresse ist, sonst
zeigen die Beiträge ins Leere.

## Warum kein Save-Hook?

Der Worker prüft laufzeit-getrieben (Standard: alle 5 Min.) — so funktioniert
das Teilen unabhängig davon, WIE eine Seite entsteht (admin1, admin2/api,
Datei-Upload, Git-Sync), und ein geplantes Datum wird korrekt abgewartet.
