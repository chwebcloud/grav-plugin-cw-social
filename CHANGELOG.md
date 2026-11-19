# v1.6.0
## 2026-11-19

Everything the directory review asked for ([getgrav/grav#4401](https://github.com/getgrav/grav/issues/4401)). The six points, in its order:

1. [](#bugfix)
    * **1. No more duplicate posts, and no endless retries.** The post went out first and the post ID was written to the page file afterwards. Where `user/pages` is read-only — a Git-deployed site — that write fails, the page still counts as unshared, and the next run posts it again. Every five minutes, without end. Three changes: the attempt is **recorded before posting**, in a journal under `user/data/cw-social/protokoll.json` that is not part of a deployment; either the journal or the front matter is enough to make a page count as shared; and a page and channel is tried at most `max_attempts` times (3) before it is left alone with the reason in the log.
    * **A lock, and the throttle inside it.** The runtime worker checked the time stamp and then touched it, with nothing in between — two simultaneous requests both came through and both posted. Checking and touching now happen under `flock`.
    * **An image Instagram cannot accept is recognised before the container is created.** The aspect ratio (4:5 to 1.91:1) and the minimum width of 320 pixels are measured on the page's own image. The issue said this was handled; only the JPEG conversion was. Such a page no longer spends nine seconds of every run waiting for a refusal it already earned.
    * **2. Nothing is posted without `site_url`.** The link used to fall back to the host that happened to be calling, so a copy of the site on a staging domain that carried the token published posts linking to the staging domain. There is no fallback any more: without `site_url` the run refuses, and says so in the log, on the command line and in the preview.
    * **4. The preview endpoint requires `api.pages.read` and honours the page's access rules.** `api.access` was enough before, for any route — including unpublished and access-restricted pages, whose title, summary and cover image it returned.
    * **5. Only `https://` links in the preview.** `ig_permalink` comes from the page front matter, which an editor can write, and it ends up in an `href`. A `javascript:` URL there ran as soon as an admin clicked "view post". Checked when it is stored and again when it is rendered.

2. [](#improved)
    * **3. The preview is one ordinary field file; no code is evaluated any more.** It used to be a shell that fetched its core from the API and ran it with `new Function` — so that an update took effect without reloading the admin. Admin 2.0 can run with a Content Security Policy that has no `unsafe-eval`, and there the preview did not load at all. The price of the fix: after updating the plugin, the admin needs one reload.
    * **The scheduler is the way the plugin posts.** A job `cw-social-share` registers itself with Grav's scheduler (every 15 minutes by default, `Tools → Scheduler`). It runs outside page requests and may wait for Instagram. The runtime worker (`auto_share`) is the exception for installations without cron and is **off by default**.
    * **6. The rest of the German is gone from the preview.** "geteilt", "noch nicht geteilt", "Beitrag ansehen", "nicht eingerichtet", "Gerade eben", "Gefällt mir", "Kommentieren", "Kein Bild auf der Seite" and the Instagram notice are in the language table in all four languages. The timestamps are sent as ISO and formatted by the browser in the admin's own language; the `d.m.Y, H:i` is gone.
    * **`{datum}` follows the site, not one country.** The date in the post text was fixed to `d.m.Y`. It now uses the site's own short date format (`system.pages.dateformat.short`), or `date_format` if that is set.
    * **The link is built from the page URL, not from the route.** The route is the path in the page tree, without the subfolder of an installation and without a language prefix — on a site under `/blog` or with `/de/` the posted link pointed nowhere.
    * **The token travels in an `Authorization: Bearer` header** instead of the query string, where it would end up in logs, error messages and redirects.
    * **`composer.json` says PHP 7.4**, which is what the code needs and what the Grav 1.7 claim implies. It said 8.1.
    * The settings form is sorted into what it actually decides: the public address (required), when it posts, and the text of the post.

# v1.5.1
## 2026-11-15

1. [](#bugfix)
    * **German umlauts in the admin.** Eight German labels and help texts of the plugin settings were spelled with "ae", "oe", "ue" instead of umlauts ("Fuer", "zusaetzlich", "ueber", "koennen", "Prueft", "faellig", "Pruef-Intervall", "Oeffentliche", "haengt"). Only `languages/de.yaml` changed; log and command-line messages stay English.

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
