# v1.5.0
## 2026-11-11

1. [](#improved)
    * **Everything a user of the plugin reads is translated; everything an operator reads is English.** The preview component and its hints were hardcoded German — including one that asked the reader to "contact CHWEB", which has no business in a plugin somebody else installed. The four hints come from the language files now; the preview carries its own table for German, English, French and Italian and takes the language from `<html lang>`, which Admin 2.0 sets from the user's own preference.
    * The messages that end up in the log, on the command line or in an exception are English. They are the first thing anyone pastes into a bug report.
    * A page is also left alone when it carries `draft: true`, not only the German `entwurf: true`.
    * This changelog is in English with ISO dates.

    Checked against the points that came back from the directory review of cw-spamschutz and cw-cookie ([getgrav/grav#4395](https://github.com/getgrav/grav/issues/4395), [#4396](https://github.com/getgrav/grav/issues/4396)) before submitting.

# v1.4.1
## 2026-11-09

1. [](#improved)
    * **Fuer die Anmeldung bei getgrav.org vorbereitet.** Beschreibung, Schlagworte und README auf Englisch -- das Verzeichnis wird international gelesen. Die deutsche Fassung steht als `README.de.md` daneben. `bugs` und `docs` zeigen auf das oeffentliche Repository, der Autor einheitlich auf die Firma.
    * Die letzten fest verdrahteten Auswahlwerte (`Ja`/`Nein`, `Aktiviert`/`Deaktiviert`) stehen jetzt als Sprachmarken in allen vier Sprachen. Die Marken heissen `YES_OPTION` und `NO_OPTION`, nicht `YES` und `NO`: Ein YAML-Schluessel `YES:` ist in YAML 1.1 der Wahrheitswert wahr, die Marke waere nie gefunden worden.
    * Als Beispieladresse steht ueberall `https://example.com` statt der eigenen Domain.

# v1.4.0
## 2026-11-09

1. [](#improved)
    * **Die Beschriftungen im Admin sprechen vier Sprachen.** 26 Beschriftungen, Hilfetexte, Platzhalter und Auswahlwerte standen fest verdrahtet auf Deutsch in den Blueprints; jetzt stehen sie als Sprachmarken in `languages/de|en|fr|it.yaml`. Auf einer deutschen Installation aendert sich nichts Sichtbares.
    * Die Angaben am Dateianfang (Name, Beschreibung, Autor) bleiben bewusst Text: Das Grav-Verzeichnis liest sie woertlich, eine Sprachmarke stuende dort im Katalog.

# CHWEB Social — Changelog

# v1.3.0
## 2026-10-07

1. [](#improved)
    * **Reiter «Teilen» aufgeraeumt.** Die zwei schreibgeschuetzten Felder mit den Beitrags-Nummern sind weg -- man konnte sie weder lesen noch sinnvoll leeren (Thomas: «Die unterste Sektion hat gar keine Funktion»). Die Nummern bleiben unsichtbar im Seitenkopf; admin2 behaelt unbekannte Kopfschluessel beim Speichern (gemessen 07.10.2026). Was der Kunde sieht: eine **Statuszeile je Kanal** in der Vorschau («geteilt am 07.10.2026, 14:12 · Beitrag ansehen» oder «noch nicht geteilt – geht nach dem Speichern raus») und der Schalter **«Nochmals teilen»**, der beim naechsten Speichern erneut postet und danach von selbst auf Nein springt.
    * Alle Beschreibungen im Reiter neu geschrieben: was passiert wann, in Kundensprache (de/en/fr/it).
    * Instagram: der Permalink des Beitrags wird beim Veroeffentlichen mitgeholt (`ig_permalink`), damit «Beitrag ansehen» auch dort geht.

# v1.2.5
## 2026-10-07

1. [](#bugfix)
    * **Vorschau las die Schalter nicht**: beide Beitraege blieben grau, auch mit «Ja». admin2 baut einen Schalter als zwei Knoepfe mit gleitendem Indikator, ohne aria-pressed; der gewaehlte Knopf traegt `text-white`. Gemessen im Admin (saloon.localhost, 07.10.2026), nicht geraten -- die Vorschau folgt den Schaltern jetzt sofort, vor dem Speichern.

# v1.2.4
## 2026-10-07

1. [](#bugfix)
    * **Vorschau: «Keine Seite unter …».** Das api-Plugin schaltet im API-Lauf die Seiten ab; der Vorschau-Endpunkt schaltet sie jetzt mit enablePages() wieder an, so wie es die Seiten-Endpunkte des api-Plugins selbst tun.

# v1.2.3
## 2026-10-07

1. [](#bugfix)
    * Autoloader von 1.2.1/1.2.2 verglich 22 statt 21 Zeichen des Namensraums und lud deshalb nichts. Jetzt gemessen (strlen) und mit einer Probe belegt, die den Controller ohne Routen-Ereignis laedt.

# v1.2.1
## 2026-10-07

1. [](#bugfix)
    * **Vorschau meldete HTTP 500**, sobald der api-Router seine Routen aus dem Cache nahm: `onApiRegisterRoutes` feuert dann nicht mehr, und der Controller war nicht geladen («Class ApiController not found»). Jetzt laedt ein Autoloader die Plugin-Klassen bei Bedarf -- dasselbe Muster wie in cw-newsletter.

# v1.2.0
## 2026-10-07

1. [](#new)
    * **Lebende Vorschau im Reiter «Teilen»** (admin2). Zeigt den Facebook-Beitrag (Text, Link-Karte mit Bild, Domain, Titel, Beschreibung) und den Instagram-Beitrag (Bild, Bildtext mit Adresse) so, wie sie rausgehen -- mit den Werten, die gerade im Formular stehen, vor dem Speichern. Folgt Titel, Einleitung, Datum, Titelbild, eigenem Text und den Schaltern; ausgeschaltete Kanaele erscheinen ausgegraut. Dazu Hinweise: Facebook noch nicht verbunden, kein Bild fuer Instagram, schon geteilt, Entwurf. Gerechnet wird auf dem Server mit derselben Logik wie beim Teilen (`ShareWorker::daten()`), damit die Vorschau nicht luegt. Huelle/Kern-Muster: Updates gelten ohne Neuladen des Admins. Thomas (07.10.2026): «Es waere perfekt, wenn ein Preview der Social-Posts vor dem Versenden gemacht werden kann.»
    * Endpunkte `POST /api/v1/cw-social/vorschau` und `GET /api/v1/cw-social/kern/{name}` (api.access). Neue Routen: nach dem Update den Cache leeren, auch `cache/api`.
2. [](#improved)
    * Einleitung (`header.lead`) dient als Anriss, wenn die Seite keinen Textanriss hat -- Anlaesse im Saloon haben ihren Text im Lead.

# v1.1.1
## 2026-10-07

1. [](#improved)
    * **Entwuerfe werden nicht geteilt.** Eine Seite mit `entwurf: true` im Kopf (CHWEB-Konvention: nur ueber den Link erreichbar, in keiner Liste, nicht bei Google) bleibt aussen vor, auch wenn die Schalter im Reiter «Teilen» schon stehen. Geteilt wird, sobald der Entwurf aufgehoben ist. Anlass: saloonmadiswil.ch, Anlaesse fertig machen und den Link zeigen, bevor sie raus gehen.

# v1.1.0
## 2026-10-06

1. [](#new)
    * **Instagram.** Schalter «Auch auf Instagram teilen» in der Konfiguration, dann auf jeder Seite «Auf Instagram teilen». Veroeffentlicht das Bild der Seite (Titelbild `header.bild`, sonst das erste Bild) als JPEG mit dem Text und der Adresse als Anhang ueber die Instagram Graph API (Container anlegen, warten, freigeben). Das verbundene Business-Konto liest das Plugin ueber die Facebook-Seite; `ig_user_id` nur als Ausweg. Post-ID und Zeitstempel landen als `ig_post_id` / `ig_posted_at` im Frontmatter. Erster Einsatz: saloonmadiswil.ch, Anlaesse auf Facebook und Instagram.
    * **Welche Seiten, sagt die Konfiguration.** `routes` (Elternseiten, mehrere mit Komma) und `templates` (Seitentypen) statt fest `/news` und `item`. `news_route` bleibt als Rueckfall lesbar, bestehende Installationen aendern nichts.
    * **Eigener Reiter «Teilen»** auf der Seite statt eines Abschnitts im Inhalt-Reiter -- so passt es in jedes Theme, auch wenn der Inhalt-Reiter anders heisst (saloon: «Inhalt», «Termin», «Bilder»). Die Felder heissen gleich wie bisher (`header.cw_social.*`).
    * Platzhalter `{datum}` in der Text-Vorlage: Kopffeld `datum` (Anlaesse) oder das Seitendatum, als TT.MM.JJJJ.
    * `bin/plugin cw-social test` prueft auch das Instagram-Konto; `share` berichtet je Kanal und gibt bei einem Fehler 1 zurueck (cron-tauglich). Eine einzelne Route mit `--force` teilt nur die faelligen Kanaele -- bisher postete sie blind nochmals.
2. [](#improved)
    * Gemeinsamer Graph-API-Unterbau (`classes/GraphApi.php`) fuer beide Publisher; Fehlerbilder der Graph API an einer Stelle.
    * Sprachdateien de/en/fr/it mit den neuen Texten.

# v1.0.0
## 2026-07-20

1. [](#new)
    * Facebook-Page-Posts via Meta Graph API (Link-Post mit Titel + Anriss).
    * Artikel-Felder im Admin: Teilen-Toggle, eigener Post-Text, Post-ID (readonly).
    * Laufzeit-getriebener Auto-Share-Worker (gedrosselt) — unabhaengig davon, wie der Artikel entsteht (admin1/admin2/Datei/Git).
    * Zustand im Frontmatter (fb_post_id) — kein Doppel-Posting; ID leeren = erneut teilen.
    * CLI: `bin/plugin cw-social test` (Verbindung) und `share [route] [--force]` (Trockenlauf/Post, cron-tauglich).
