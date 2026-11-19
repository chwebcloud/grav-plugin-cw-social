<?php
declare(strict_types=1);

namespace Grav\Plugin\CwSocial;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Medium\ImageMedium;
use RocketTheme\Toolbox\File\MarkdownFile;

/**
 * Findet freigegebene, noch nicht geteilte Seiten und teilt sie -- auf
 * Facebook (Link-Post) und, wenn eingeschaltet, auf Instagram (Bild mit
 * Text). Der Zustand (fb_post_id, ig_post_id und Zeitstempel) steht im
 * Frontmatter der Seite: flat-file als einzige Wahrheit, kein Doppel-Posting.
 *
 * Welche Seiten in Frage kommen, sagt die Konfiguration: `routes` (die
 * Elternseiten, z. B. /news oder /events) und `templates` (die Seitentypen,
 * z. B. item oder event).
 */
class ShareWorker
{
    private Grav $grav;
    private array $cfg;
    private ?Protokoll $protokoll = null;

    public function __construct(Grav $grav, array $cfg)
    {
        $this->grav = $grav;
        $this->cfg  = $cfg;
    }

    /**
     * Was dem Teilen im Weg steht -- als Satz, oder '' wenn nichts im Weg
     * steht. Geprueft wird vor jedem Lauf, in jedem Weg (Worker, CLI,
     * Zeitplaner).
     *
     * `site_url` ist Pflicht und hat keinen Rueckfall auf den gerade
     * aufgerufenen Host: Eine Kopie der Website auf einer Testdomain, die den
     * Token mitbringt, haette sonst Beitraege mit Links auf die Testdomain
     * veroeffentlicht (getgrav/grav#4401, Punkt 2).
     */
    public function hindernis(): string
    {
        if (trim((string) ($this->cfg['site_url'] ?? '')) === '') {
            return 'site_url is empty. Set the public address of this site (Admin -> Plugins -> CHWEB Social); '
                 . 'without it the links in the posts would point at whatever host was called.';
        }
        if (empty($this->cfg['page_id']) || empty($this->cfg['access_token'])) {
            return 'page_id or access_token missing (Admin -> Plugins -> CHWEB Social).';
        }
        if (!$this->protokoll()) {
            return 'the journal under user/data/cw-social is not writable. Nothing is posted without it: '
                 . 'it is what keeps a failed run from posting the same page again.';
        }
        return '';
    }

    /** Das Protokoll, oder null, wenn dort nicht geschrieben werden kann. */
    private function protokoll(): ?Protokoll
    {
        if ($this->protokoll === null) {
            $this->protokoll = Protokoll::fuer($this->grav);
        }
        return $this->protokoll;
    }

    private function maxVersuche(): int
    {
        return max(1, (int) ($this->cfg['max_attempts'] ?? 3));
    }

    /** Elternrouten aus der Konfiguration (`routes`, Rueckfall `news_route`). @return string[] */
    public static function routes(array $cfg): array
    {
        $roh = $cfg['routes'] ?? ($cfg['news_route'] ?? '/news');
        return self::liste($roh, ['/news']);
    }

    /** Seitentypen aus der Konfiguration (`templates`). @return string[] */
    public static function templates(array $cfg): array
    {
        return self::liste($cfg['templates'] ?? 'item', ['item']);
    }

    /** @param mixed $roh @return string[] */
    private static function liste($roh, array $vorgabe): array
    {
        $teile = is_array($roh) ? $roh : preg_split('/[\s,]+/', (string) $roh);
        $teile = array_values(array_filter(array_map('trim', (array) $teile), 'strlen'));
        return $teile ?: $vorgabe;
    }

    /** Ist Instagram in der Konfiguration eingeschaltet? */
    public function instagramAktiv(): bool
    {
        return !empty($this->cfg['instagram']);
    }

    /** @return array<int,array{route:string,ok:bool,detail:string}> Ergebnis je Seite */
    public function run(bool $dryRun = false): array
    {
        $templates = self::templates($this->cfg);
        $results   = [];

        $hindernis = $this->hindernis();
        if (!$dryRun && $hindernis !== '') {
            $this->grav['log']->error('cw-social: not posting -- ' . $hindernis);
            return [['route' => '', 'ok' => false, 'detail' => 'not posting -- ' . $hindernis]];
        }

        foreach (self::routes($this->cfg) as $route) {
            $eltern = $this->grav['pages']->find($route);
            if (!$eltern) {
                continue;
            }
            foreach ($eltern->children() as $item) {
                /** @var PageInterface $item */
                if (!in_array($item->template(), $templates, true)) {
                    continue;
                }
                $kanaele = $this->faellig($item);
                if (!$kanaele) {
                    continue;
                }
                if ($dryRun) {
                    $results[] = ['route' => $item->route(), 'ok' => true, 'detail' => 'Dry run -- would share: ' . implode(', ', $kanaele)];
                    continue;
                }
                $results[] = $this->share($item, $kanaele);
            }
        }
        return $results;
    }

    /**
     * Eine Seite auf den faelligen Kanaelen teilen. Ohne Angabe der Kanaele
     * werden die faelligen genommen (eingeschaltet und noch ohne Post-ID).
     * @param string[]|null $kanaele
     * @return array{route:string,ok:bool,detail:string}
     */
    public function share(PageInterface $item, ?array $kanaele = null): array
    {
        $kanaele ??= $this->faellig($item);
        if (!$kanaele) {
            return ['route' => $item->route(), 'ok' => false, 'detail' => 'nichts faellig: Teilen aus oder schon geteilt (Post-ID leeren fuer erneut)'];
        }

        $hindernis = $this->hindernis();
        if ($hindernis !== '') {
            $this->grav['log']->error('cw-social: not posting -- ' . $hindernis);
            return ['route' => $item->route(), 'ok' => false, 'detail' => 'not posting -- ' . $hindernis];
        }

        $d       = $this->daten($item);
        $message = $d['text'];
        $link    = $d['link'];
        $teile   = [];
        $ok      = true;

        if (in_array('facebook', $kanaele, true)) {
            $nr = $this->vorVersuch($item, 'facebook', $teile);
            if ($nr === null) {
                $ok = false;
            } else {
                $fb  = new FacebookPublisher((string) $this->cfg['page_id'], (string) $this->cfg['access_token'], (string) ($this->cfg['api_version'] ?? 'v21.0'));
                $res = $fb->publishLink($message, $link);
                if ($res['ok']) {
                    $this->nachErfolg($item, 'facebook', (string) $res['post_id'], ['fb_post_id' => $res['post_id'], 'fb_posted_at' => date('c')]);
                    $teile[] = 'Facebook ' . $res['post_id'];
                } else {
                    $ok = false;
                    $this->nachFehler($item, 'facebook', (string) $res['error'], $nr);
                    $teile[] = 'Facebook ERROR ' . $res['error'];
                }
            }
        }

        if (in_array('instagram', $kanaele, true)) {
            $grund = $d['bild'] === '' ? 'no image on the page (cover image or first image)' : $this->bildTauglich($item);
            if ($grund !== '') {
                // Daran aendert kein weiterer Lauf etwas: Die Versuche werden
                // sofort aufgebraucht, damit die Seite nicht alle fuenf Minuten
                // erneut neun Sekunden auf Instagram wartet.
                $ok = false;
                $this->aufgeben($item, 'instagram', $grund);
                $teile[] = 'Instagram ERROR ' . $grund;
            } else {
                $nr = $this->vorVersuch($item, 'instagram', $teile);
                if ($nr === null) {
                    $ok = false;
                } else {
                    $ig  = new InstagramPublisher((string) $this->cfg['page_id'], (string) $this->cfg['access_token'], (string) ($this->cfg['api_version'] ?? 'v21.0'), (string) ($this->cfg['ig_user_id'] ?? ''));
                    $res = $ig->publishImage($message . "\n\n" . $link, $d['bild']);
                    if ($res['ok']) {
                        $permalink = (string) ($res['permalink'] ?? '');
                        $this->nachErfolg($item, 'instagram', (string) $res['post_id'], [
                            'ig_post_id'   => $res['post_id'],
                            'ig_posted_at' => date('c'),
                            // Nur https:// -- der Wert landet im Admin in einem
                            // href (getgrav/grav#4401, Punkt 5).
                            'ig_permalink' => strncmp($permalink, 'https://', 8) === 0 ? $permalink : '',
                        ]);
                        $teile[] = 'Instagram ' . $res['post_id'];
                    } else {
                        $ok = false;
                        $this->nachFehler($item, 'instagram', (string) $res['error'], $nr);
                        $teile[] = 'Instagram ERROR ' . $res['error'];
                    }
                }
            }
        }

        return ['route' => $item->route(), 'ok' => $ok, 'detail' => implode(' | ', $teile)];
    }

    /**
     * Den Versuch festhalten, BEVOR gepostet wird. Gibt die Nummer des
     * Versuchs zurueck, oder null -- dann wird nicht gepostet, weil die
     * Versuche aufgebraucht sind oder das Protokoll nicht schreibbar ist.
     *
     * @param string[] $teile
     */
    private function vorVersuch(PageInterface $item, string $kanal, array &$teile): ?int
    {
        $p = $this->protokoll();
        $s = Protokoll::schluessel($item->path() ?: $item->route(), $kanal);
        if (!$p) {
            return null;
        }
        if ($p->aufgegeben($s, $this->maxVersuche())) {
            $teile[] = ucfirst($kanal) . ' SKIPPED after ' . $this->maxVersuche()
                     . ' failed attempts: ' . ($p->fehlerText($s) ?: 'see the log');
            return null;
        }
        $nr = $p->versuch($s);
        if ($nr === null) {
            $this->grav['log']->error('cw-social: could not record the attempt, not posting ' . $item->route());
            $teile[] = ucfirst($kanal) . ' ERROR the journal could not be written';
        }
        return $nr;
    }

    /**
     * Erfolg: zuerst ins Protokoll, dann ins Frontmatter. Scheitert das
     * Frontmatter -- schreibgeschuetztes user/pages --, steht der Beitrag
     * trotzdem fest und wird nicht erneut gepostet; die fehlende Nummer in der
     * Seite ist dann eine Meldung im Log, kein zweiter Beitrag.
     */
    private function nachErfolg(PageInterface $item, string $kanal, string $postId, array $werte): void
    {
        $p = $this->protokoll();
        if ($p) {
            $p->erfolg(Protokoll::schluessel($item->path() ?: $item->route(), $kanal), $postId);
        }
        $this->grav['log']->info(sprintf('cw-social: shared "%s" on %s (%s)', $item->title(), $kanal, $postId));
        try {
            $this->markPosted($item, $werte);
        } catch (\Throwable $e) {
            $this->grav['log']->error(sprintf(
                'cw-social: shared "%s" on %s (%s), but the page file could not be updated: %s',
                $item->title(), $kanal, $postId, $e->getMessage()
            ));
        }
    }

    private function nachFehler(PageInterface $item, string $kanal, string $fehler, int $nr): void
    {
        $p = $this->protokoll();
        if ($p) {
            $p->fehler(Protokoll::schluessel($item->path() ?: $item->route(), $kanal), $fehler);
        }
        $this->grav['log']->error(sprintf(
            'cw-social: %s, "%s" (attempt %d of %d): %s',
            $kanal, $item->title(), $nr, $this->maxVersuche(), $fehler
        ));
    }

    /** Ein Fehler, den kein weiterer Lauf behebt: Versuche sofort aufbrauchen. */
    private function aufgeben(PageInterface $item, string $kanal, string $grund): void
    {
        $p = $this->protokoll();
        $s = Protokoll::schluessel($item->path() ?: $item->route(), $kanal);
        if ($p && !$p->aufgegeben($s, $this->maxVersuche())) {
            for ($i = 0; $i < $this->maxVersuche(); $i++) {
                $p->versuch($s);
            }
            $p->fehler($s, $grund);
            $this->grav['log']->error(sprintf('cw-social: %s, "%s": %s', $kanal, $item->title(), $grund));
        }
    }

    /**
     * Nimmt Instagram dieses Bild ueberhaupt an? Gibt den Grund zurueck,
     * wenn nicht, sonst ''.
     *
     * Meta verlangt ein Seitenverhaeltnis zwischen 4:5 und 1.91:1 und
     * mindestens 320 Pixel Breite. Bis 1.5.1 wurde das nur im README
     * behauptet; gemessen wurde es nie, und ein Panorama lief in die Schleife
     * «Container anlegen, neun Sekunden warten, abgewiesen» -- bei jedem Lauf
     * (getgrav/grav#4401).
     */
    private function bildTauglich(PageInterface $item): string
    {
        $bild = $this->bildMedium($item);
        if (!$bild instanceof ImageMedium) {
            return '';   // ohne Medium nicht messbar; der Versuch entscheidet
        }
        return self::instagramGrund((int) $bild->get('width'), (int) $bild->get('height'));
    }

    /**
     * Die Regel von Meta als reine Rechnung: Seitenverhaeltnis zwischen 4:5
     * (0.8) und 1.91:1, mindestens 320 Pixel breit. Gibt den Grund zurueck,
     * wenn das Bild durchfaellt, sonst ''. Unbekannte Masse sind kein Grund --
     * dann entscheidet der Versuch.
     */
    public static function instagramGrund(int $breite, int $hoehe): string
    {
        if ($breite <= 0 || $hoehe <= 0) {
            return '';
        }
        if ($breite < 320) {
            return sprintf('Instagram needs at least 320 pixels of width, this image has %d.', $breite);
        }
        $v = $breite / $hoehe;
        if ($v < 0.8 - 0.001 || $v > 1.91 + 0.001) {
            return sprintf(
                'Instagram only accepts aspect ratios between 4:5 (0.80) and 1.91:1, this image is %dx%d (%.2f).',
                $breite, $hoehe, $v
            );
        }
        return '';
    }

    /**
     * Faellige Kanaele einer Seite: Teilen eingeschaltet, noch keine Post-ID,
     * Seite publiziert und Datum erreicht.
     * @return string[]  Teilmenge von ['facebook', 'instagram']
     */
    public function faellig(PageInterface $item): array
    {
        if (!$item->published() || ($item->date() && $item->date() > time())) {
            return [];
        }
        // CHWEB-Konvention «Entwurf»: Seite nur ueber den Link erreichbar,
        // in keiner Liste, nicht bei Google -- und nicht geteilt.
        if (!empty($item->header()->entwurf) || !empty($item->header()->draft)) {
            return [];
        }
        $social  = (array) ($item->header()->cw_social ?? []);
        // «Nochmals teilen»: die vorhandenen Beitrags-Nummern zaehlen nicht,
        // der Beitrag geht auf den eingeschalteten Kanaelen erneut raus. Dann
        // faengt auch das Protokoll fuer diese Seite von vorne an.
        $erneut  = !empty($social['erneut']);
        $p       = $this->protokoll();
        $pfad    = $item->path() ?: $item->route();
        $kanaele = [];

        foreach (['facebook' => 'share', 'instagram' => 'instagram'] as $kanal => $schalter) {
            if ($kanal === 'instagram' && !$this->instagramAktiv()) {
                continue;
            }
            if (empty($social[$schalter])) {
                continue;
            }
            $s = Protokoll::schluessel($pfad, $kanal);
            if ($erneut) {
                if ($p) {
                    $p->zuruecksetzen($s);
                }
                $kanaele[] = $kanal;
                continue;
            }
            // Zwei Gedaechtnisse, beide zaehlen: die Nummer im Frontmatter und
            // die im Protokoll. Das Protokoll ist das, das auch bei
            // schreibgeschuetztem user/pages gefuehrt werden kann -- ohne es
            // postete der Worker dieselbe Seite endlos erneut.
            $imKopf = !empty($social[$kanal === 'facebook' ? 'fb_post_id' : 'ig_post_id']);
            if ($imKopf || ($p && $p->geteilt($s))) {
                continue;
            }
            $kanaele[] = $kanal;
        }
        return $kanaele;
    }

    /**
     * Alles, was ein Beitrag braucht -- fuer das Teilen UND die Vorschau im
     * Admin, aus einer Rechnung, damit die Vorschau nicht luegt. $ueber
     * traegt ungespeicherte Werte aus dem Formular (title, summary, datum,
     * message, bild); was fehlt, kommt von der gespeicherten Seite.
     * @return array{title:string,summary:string,datum:string,text:string,link:string,bild:string}
     */
    public function daten(PageInterface $item, array $ueber = []): array
    {
        $h      = $item->header();
        $social = (array) ($h->cw_social ?? []);
        $link   = $this->buildLink($item);

        $title   = trim((string) ($ueber['title'] ?? $item->title()));
        if (array_key_exists('summary', $ueber)) {
            $summary = trim(strip_tags((string) $ueber['summary']));
        } else {
            // Einleitung (lead) zuerst: das ist der Satz, den Themes wie saloon
            // als Vorschautext fuehren. Sonst der Anriss aus dem Inhalt --
            // summary() verarbeitet den Inhalt samt Twig, und das ist im
            // API-Lifecycle und frueh im Frontend-Lauf nicht immer moeglich
            // (07.10.2026: Fatal «__clone method called on non-object» in
            // Twig::processPage). Darum abgesichert, mit Rohtext als letztem Weg.
            $summary = trim(strip_tags((string) ($h->lead ?? '')));
            if ($summary === '') {
                try {
                    $summary = trim(strip_tags($item->summary(280) ?: ''));
                } catch (\Throwable $e) {
                    $summary = '';
                }
            }
            if ($summary === '') {
                $roh = trim(strip_tags((string) $item->rawMarkdown()));
                $summary = mb_strlen($roh) > 280 ? rtrim(mb_substr($roh, 0, 277)) . '…' : $roh;
            }
        }
        $datum = array_key_exists('datum', $ueber) ? $this->datumText((string) $ueber['datum']) : $this->datum($item);

        $custom = trim((string) ($ueber['message'] ?? ($social['message'] ?? '')));
        if ($custom !== '') {
            $text = $custom;
        } else {
            $tpl   = (string) ($this->cfg['message_template'] ?? "{title}\n\n{summary}");
            $werte = ['{title}' => $title, '{summary}' => $summary, '{url}' => $link, '{datum}' => $datum];
            // Eine Zeile, deren Platzhalter alle leer sind, faellt ganz weg --
            // sonst stuende «im Rössli Saloon Madiswil» ohne Datum davor
            // (Anlass mit Regel statt festem Datum, 07.10.2026).
            $zeilen = [];
            foreach (preg_split("/\r?\n/", $tpl) as $zeile) {
                $hatPlatzhalter = false;
                $hatInhalt = false;
                foreach ($werte as $ph => $wert) {
                    if (strpos($zeile, $ph) !== false) {
                        $hatPlatzhalter = true;
                        if (trim((string) $wert) !== '') {
                            $hatInhalt = true;
                        }
                    }
                }
                if ($hatPlatzhalter && !$hatInhalt) {
                    continue;
                }
                $zeilen[] = strtr($zeile, $werte);
            }
            $text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $zeilen)));
        }

        return [
            'title'   => $title,
            'summary' => $summary,
            'datum'   => $datum,
            'text'    => $text,
            'link'    => $link,
            'bild'    => $this->bildUrl($item, array_key_exists('bild', $ueber) ? (string) $ueber['bild'] : null),
        ];
    }

    /** Datum fuer {datum}: Kopffeld `datum` (Anlaesse) oder das Seitendatum. */
    private function datum(PageInterface $item): string
    {
        $h = $item->header();
        if (property_exists($h, 'datum')) {
            // Die Seite kennt ein eigenes Datum (Anlaesse). Leer heisst leer --
            // ein Anlass mit Regel statt festem Datum bekommt nicht das
            // Erstelldatum der Seite untergeschoben.
            return $this->datumText((string) ($h->datum ?? ''));
        }
        return $item->date() ? date($this->datumFormat(), $item->date()) : '';
    }

    private function datumText(string $roh): string
    {
        $roh = trim($roh);
        if ($roh === '') {
            return '';
        }
        $ts = strtotime($roh);
        return $ts ? date($this->datumFormat(), $ts) : '';
    }

    /**
     * Das Format fuer {datum} im Beitragstext. Ohne eigene Angabe gilt, was
     * die Website fuer kurze Datumsangaben eingestellt hat
     * (`system.pages.dateformat.short`) -- bis 1.5.1 stand hier fest `d.m.Y`,
     * und das passt nur dort, wo man Daten so schreibt
     * (getgrav/grav#4401, Punkt 6).
     */
    private function datumFormat(): string
    {
        $eigen = trim((string) ($this->cfg['date_format'] ?? ''));
        if ($eigen !== '') {
            return $eigen;
        }
        $grav = (string) $this->grav['config']->get('system.pages.dateformat.short', '');
        return $grav !== '' ? $grav : 'd M Y';
    }

    /**
     * Oeffentlicher Link: die konfigurierte Site-URL und der Pfad, den Grav
     * selbst fuer diese Seite bildet.
     *
     * Nicht `route()`: Die Route ist der Pfad im Seitenbaum, ohne das
     * Unterverzeichnis einer Installation und ohne das Sprachkuerzel. Auf
     * einer Website unter /blog oder mit /de/ vor jeder Adresse zeigte der
     * Beitrag damit ins Leere. `url()` liefert beides mit.
     */
    private function buildLink(PageInterface $item): string
    {
        $basis = $this->basis();
        $pfad  = (string) $item->url(false, false, true);

        // Traegt die Site-URL schon das Unterverzeichnis (https://example.com/blog),
        // steht es im Pfad von Grav ein zweites Mal. Einmal genuegt.
        $basisPfad = rtrim((string) parse_url($basis, PHP_URL_PATH), '/');
        if ($basisPfad !== '' && strpos($pfad, $basisPfad . '/') === 0) {
            $pfad = substr($pfad, strlen($basisPfad));
        }

        return rtrim($basis, '/') . '/' . ltrim($pfad, '/');
    }

    /**
     * Die oeffentliche Adresse der Website -- ausschliesslich aus der
     * Konfiguration. KEIN Rueckfall auf den gerade aufgerufenen Host: Sonst
     * postet eine Kopie der Website auf einer Testdomain Links auf die
     * Testdomain (getgrav/grav#4401, Punkt 2). Fehlt sie, wird nicht gepostet;
     * dafuer sorgt hindernis().
     */
    private function basis(): string
    {
        return rtrim(trim((string) ($this->cfg['site_url'] ?? '')), '/');
    }

    /**
     * Oeffentliche Adresse des Seitenbilds fuer Instagram, immer als JPEG:
     * Kopffeld `bild` (Titelbild), sonst das erste Bild der Seite. Leer,
     * wenn die Seite kein Bild hat.
     */
    private function bildUrl(PageInterface $item, ?string $nameUeber = null): string
    {
        $bild = $this->bildMedium($item, $nameUeber);
        if (!$bild instanceof ImageMedium) {
            return '';
        }
        try {
            $url = (string) $bild->format('jpg')->quality(85)->url();
        } catch (\Throwable $e) {
            $url = (string) $bild->url();
        }
        if (preg_match('#^https?://#', $url)) {
            return $url;
        }
        return $this->basis() . '/' . ltrim($url, '/');
    }

    /**
     * Das Bild der Seite als Medium: Kopffeld `bild` (Titelbild), sonst das
     * erste Bild. Dieselbe Wahl fuer die Adresse und fuer die Messung des
     * Seitenverhaeltnisses -- sonst wird das eine geprueft und das andere
     * gepostet.
     */
    private function bildMedium(PageInterface $item, ?string $nameUeber = null): ?ImageMedium
    {
        $media = $item->media();
        $name  = trim($nameUeber ?? (string) ($item->header()->bild ?? ''));
        $bild  = $name !== '' ? ($media[$name] ?? null) : null;
        if (!$bild instanceof ImageMedium) {
            $bilder = $media->images();
            $bild   = $bilder ? reset($bilder) : null;
        }
        return $bild instanceof ImageMedium ? $bild : null;
    }

    /**
     * Post-IDs und Zeitstempel ins Frontmatter schreiben (atomar via
     * MarkdownFile). Schlaegt das fehl -- schreibgeschuetztes user/pages --,
     * fliegt die Ausnahme zum Aufrufer; der Beitrag ist dann im Protokoll
     * festgehalten und wird nicht erneut gepostet.
     *
     * Der Schalter «Nochmals teilen» wird dabei immer zurueckgenommen, auch
     * wenn nur ein Kanal raus ist: Er gilt fuer die naechste Speicherung, und
     * die hat stattgefunden.
     */
    private function markPosted(PageInterface $item, array $werte): void
    {
        $datei = (string) $item->filePath();
        if ($datei === '' || !is_writable($datei)) {
            throw new \RuntimeException(sprintf('"%s" is not writable', $datei ?: '(no file)'));
        }
        $file   = MarkdownFile::instance($datei);
        $header = (array) $file->header();
        $header['cw_social'] = array_merge((array) ($header['cw_social'] ?? []), $werte, ['erneut' => false]);
        $file->header($header);
        $file->save();
    }
}
