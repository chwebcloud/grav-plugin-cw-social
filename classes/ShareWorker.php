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

    public function __construct(Grav $grav, array $cfg)
    {
        $this->grav = $grav;
        $this->cfg  = $cfg;
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

        $d       = $this->daten($item);
        $message = $d['text'];
        $link    = $d['link'];
        $teile   = [];
        $ok      = true;

        if (in_array('facebook', $kanaele, true)) {
            $fb  = new FacebookPublisher((string) $this->cfg['page_id'], (string) $this->cfg['access_token'], (string) ($this->cfg['api_version'] ?? 'v21.0'));
            $res = $fb->publishLink($message, $link);
            if ($res['ok']) {
                $this->markPosted($item, ['fb_post_id' => $res['post_id'], 'fb_posted_at' => date('c'), 'erneut' => false]);
                $this->grav['log']->info(sprintf('cw-social: "%s" auf Facebook geteilt (%s)', $item->title(), $res['post_id']));
                $teile[] = 'Facebook ' . $res['post_id'];
            } else {
                $ok = false;
                $this->grav['log']->error(sprintf('cw-social: Facebook, "%s": %s', $item->title(), $res['error']));
                $teile[] = 'Facebook ERROR ' . $res['error'];
            }
        }

        if (in_array('instagram', $kanaele, true)) {
            $bild = $d['bild'];
            if ($bild === '') {
                $ok = false;
                $this->grav['log']->error(sprintf('cw-social: Instagram, "%s": kein Bild auf der Seite', $item->title()));
                $teile[] = 'Instagram ERROR no image on the page (cover image or first image)';
            } else {
                $ig  = new InstagramPublisher((string) $this->cfg['page_id'], (string) $this->cfg['access_token'], (string) ($this->cfg['api_version'] ?? 'v21.0'), (string) ($this->cfg['ig_user_id'] ?? ''));
                $res = $ig->publishImage($message . "\n\n" . $link, $bild);
                if ($res['ok']) {
                    $this->markPosted($item, ['ig_post_id' => $res['post_id'], 'ig_posted_at' => date('c'), 'ig_permalink' => (string) ($res['permalink'] ?? ''), 'erneut' => false]);
                    $this->grav['log']->info(sprintf('cw-social: "%s" auf Instagram geteilt (%s)', $item->title(), $res['post_id']));
                    $teile[] = 'Instagram ' . $res['post_id'];
                } else {
                    $ok = false;
                    $this->grav['log']->error(sprintf('cw-social: Instagram, "%s": %s', $item->title(), $res['error']));
                    $teile[] = 'Instagram ERROR ' . $res['error'];
                }
            }
        }

        return ['route' => $item->route(), 'ok' => $ok, 'detail' => implode(' | ', $teile)];
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
        // der Beitrag geht auf den eingeschalteten Kanaelen erneut raus.
        $erneut  = !empty($social['erneut']);
        $kanaele = [];
        if (!empty($social['share']) && ($erneut || empty($social['fb_post_id']))) {
            $kanaele[] = 'facebook';
        }
        if ($this->instagramAktiv() && !empty($social['instagram']) && ($erneut || empty($social['ig_post_id']))) {
            $kanaele[] = 'instagram';
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
        return $item->date() ? date('d.m.Y', $item->date()) : '';
    }

    private function datumText(string $roh): string
    {
        $roh = trim($roh);
        if ($roh === '') {
            return '';
        }
        $ts = strtotime($roh);
        return $ts ? date('d.m.Y', $ts) : '';
    }

    /** Oeffentlicher Link: konfigurierte Site-URL (Prod!) + Route. */
    private function buildLink(PageInterface $item): string
    {
        return $this->basis() . $item->route();
    }

    private function basis(): string
    {
        $base = rtrim((string) ($this->cfg['site_url'] ?? ''), '/');
        if ($base === '') {
            $base = rtrim((string) ($this->grav['config']->get('system.custom_base_url') ?: $this->grav['uri']->rootUrl(true)), '/');
        }
        return $base;
    }

    /**
     * Oeffentliche Adresse des Seitenbilds fuer Instagram, immer als JPEG:
     * Kopffeld `bild` (Titelbild), sonst das erste Bild der Seite. Leer,
     * wenn die Seite kein Bild hat.
     */
    private function bildUrl(PageInterface $item, ?string $nameUeber = null): string
    {
        $media = $item->media();
        $name  = trim($nameUeber ?? (string) ($item->header()->bild ?? ''));
        $bild  = $name !== '' ? ($media[$name] ?? null) : null;
        if (!$bild instanceof ImageMedium) {
            $bilder = $media->images();
            $bild   = $bilder ? reset($bilder) : null;
        }
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

    /** Post-IDs und Zeitstempel ins Frontmatter schreiben (atomar via MarkdownFile). */
    private function markPosted(PageInterface $item, array $werte): void
    {
        $file   = MarkdownFile::instance($item->filePath());
        $header = (array) $file->header();
        $header['cw_social'] = array_merge((array) ($header['cw_social'] ?? []), $werte);
        $file->header($header);
        $file->save();
    }
}
