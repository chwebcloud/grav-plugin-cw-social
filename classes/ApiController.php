<?php
declare(strict_types=1);

namespace Grav\Plugin\CwSocial;

use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Response\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin-2.0-Endpunkt von cw-social (Grav 2.0 / api-Plugin).
 *
 *   POST /cw-social/vorschau  -> vorschau()  (api.pages.read + Seitenrechte)
 *
 * Die Vorschau rechnet dieselben Dinge wie der ShareWorker (Text, Link,
 * Bild), nur mit den Werten, die gerade im Formular stehen -- bevor
 * gespeichert ist. Was dabei herauskommt, ist das, was gepostet wuerde.
 */
class ApiController extends AbstractApiController
{
    /**
     * Vorschau der Beitraege fuer eine Seite.
     * POST /cw-social/vorschau  { route, title?, summary?, datum?, message?, bild?, share?, instagram? }
     * -> { seite, link, facebook: {aktiv, text, karte}, instagram: {aktiv, text, bild}, hinweise }
     *
     * Rechte: Die Antwort traegt Titel, Anriss und Bildadresse einer Seite --
     * also deren Inhalt. Bis 1.5.1 genuegte dafuer `api.access`, und das gilt
     * fuer jede Adresse, auch fuer unveroeffentlichte und zugangsbeschraenkte
     * Seiten (getgrav/grav#4401, Punkt 4). Jetzt braucht es `api.pages.read`,
     * und die Rechte der Seite selbst entscheiden mit.
     */
    public function vorschau(ServerRequestInterface $request): ResponseInterface
    {
        // Erst das Tor zur API, damit die Suche nach der Seite nicht verraet,
        // welche Adressen es gibt.
        $this->requirePermission($request, 'api.access');

        $body = $request->getParsedBody();
        if (!is_array($body) || !$body) {
            $body = json_decode((string) $request->getBody(), true) ?: [];
        }
        $route = '/' . trim((string) ($body['route'] ?? ''), '/');
        if ($route === '/') {
            return ErrorResponse::create(400, 'Address missing', 'The preview needs the route of the page.');
        }

        $this->bootPages();
        $page = $this->grav['pages']->find($route);
        if (!$page) {
            return ErrorResponse::create(404, 'Not found', "No page at {$route}.");
        }
        $this->authorizePageAction($request, $page, 'read', 'api.pages.read');

        require_once __DIR__ . '/ShareWorker.php';
        $cfg    = (array) $this->grav['config']->get('plugins.cw-social');
        $worker = new ShareWorker($this->grav, $cfg);

        // Nur bekannte Felder uebersteuern; alles andere bleibt der gespeicherte Stand.
        $ueber = [];
        foreach (['title', 'summary', 'datum', 'message', 'bild'] as $k) {
            if (array_key_exists($k, $body) && is_scalar($body[$k])) {
                $ueber[$k] = (string) $body[$k];
            }
        }
        $d      = $worker->daten($page, $ueber);
        $social = (array) ($page->header()->cw_social ?? []);
        $fbAn   = array_key_exists('share', $body) ? (bool) (int) $body['share'] : !empty($social['share']);
        $igAn   = array_key_exists('instagram', $body) ? (bool) (int) $body['instagram'] : !empty($social['instagram']);
        $igMoeglich = !empty($cfg['instagram']);

        // Was dem Teilen im Weg steht, gehoert in die Vorschau -- sonst sieht
        // der Redakteur einen vollstaendigen Beitrag und wundert sich, dass
        // nichts rausgeht. Der Worker kennt dieselben Huerden; er schreibt sie
        // englisch ins Protokoll, hier stehen sie in der Sprache des Admins.
        $hinweise = [];
        if (empty($cfg['page_id']) || empty($cfg['access_token'])) {
            $hinweise[] = self::text('HINWEIS_FB_OFFEN');
        }
        if (trim((string) ($cfg['site_url'] ?? '')) === '') {
            $hinweise[] = self::text('HINWEIS_SITE_URL');
        }
        if ($igAn && $igMoeglich && $d['bild'] === '') {
            $hinweise[] = self::text('HINWEIS_IG_BILD');
        }
        $erneut = array_key_exists('erneut', $body) ? (bool) (int) $body['erneut'] : !empty($social['erneut']);
        if ($erneut && ($fbAn || $igAn)) {
            $hinweise[] = self::text('HINWEIS_ERNEUT');
        }
        if (!empty($page->header()->entwurf) || !empty($page->header()->draft)) {
            $hinweise[] = self::text('HINWEIS_ENTWURF');
        }

        $host = (string) parse_url($d['link'], PHP_URL_HOST);
        // Als ISO-Zeitstempel, nicht als fertiger Text: Bis 1.5.1 stand hier
        // `d.m.Y, H:i`, und das ist die Schreibweise einer Gegend, nicht die
        // der Benutzerin (getgrav/grav#4401, Punkt 6). Die Vorschau formt ihn
        // mit der Sprache des Admins.
        $wann = static function ($iso): string {
            $ts = is_string($iso) && $iso !== '' ? strtotime($iso) : false;
            return $ts ? date('c', $ts) : '';
        };
        return ApiResponse::create([
            'seite' => (string) ($this->grav['config']->get('site.title') ?: $host),
            'link'  => $d['link'],
            'status' => [
                'facebook' => [
                    'geteilt' => !empty($social['fb_post_id']),
                    'am'      => $wann($social['fb_posted_at'] ?? ''),
                    'url'     => !empty($social['fb_post_id']) ? 'https://www.facebook.com/' . rawurlencode((string) $social['fb_post_id']) : '',
                ],
                'instagram' => [
                    'geteilt' => !empty($social['ig_post_id']),
                    'am'      => $wann($social['ig_posted_at'] ?? ''),
                    // Der Permalink kommt aus dem Kopf der Seite, den ein
                    // Redakteur schreiben kann, und landet im Admin in einem
                    // href. Nur https:// (getgrav/grav#4401, Punkt 5) -- die
                    // Vorschau prueft es ein zweites Mal.
                    'url'     => self::nurHttps((string) ($social['ig_permalink'] ?? '')),
                ],
            ],
            'facebook' => [
                'aktiv' => $fbAn,
                'text'  => $d['text'],
                'karte' => [
                    'domain'       => strtoupper($host),
                    'titel'        => $d['title'],
                    'beschreibung' => $d['summary'],
                    'bild'         => $d['bild'],
                ],
            ],
            'instagram' => [
                'moeglich' => $igMoeglich,
                'aktiv'    => $igAn && $igMoeglich,
                'text'     => $d['text'] . "\n\n" . $d['link'],
                'bild'     => $d['bild'],
            ],
            'hinweise' => $hinweise,
        ]);
    }

    /**
     * Der API-Lifecycle initialisiert weder Seiten noch Twig; fuer find()
     * und fuer Seiten, deren Inhalt Twig verarbeitet, braucht es beides.
     */
    private function bootPages(): void
    {
        try {
            $twig = $this->grav['twig'];
            if (empty($twig->twig)) {
                $this->grav['themes']->init();
                $twig->init();
            }
        } catch (\Throwable $e) {
            $this->grav['log']->warning('cw-social bootPages (twig): ' . $e->getMessage());
        }
        try {
            // Die API schaltet die Seiten ab (api.php: disablePages). Wieder an --
            // enablePages() ruft init() gleich mit. Ohne das: «Keine Seite unter …»
            // fuer jede Adresse (07.10.2026).
            $this->grav['pages']->enablePages();
            if (!count($this->grav['pages']->all())) {
                $this->grav['pages']->init();
            }
        } catch (\Throwable $e) {
            $this->grav['log']->warning('cw-social bootPages: ' . $e->getMessage());
        }
    }

    /**
     * Eine Adresse nur dann durchlassen, wenn sie mit https:// beginnt.
     * `javascript:` in einem href laeuft, sobald ein Admin darauf klickt.
     */
    private static function nurHttps(string $url): string
    {
        $url = trim($url);
        return strncasecmp($url, 'https://', 8) === 0 ? $url : '';
    }

    /**
     * Hinweis in der Sprache der Website. Ohne `languages.supported` ist keine
     * Sprache aktiv und Grav faellt auf Englisch zurueck; dann gilt die
     * Standardsprache der Website, zuletzt Englisch (so haelt es Grav selbst).
     */
    private static function text(string $schluessel): string
    {
        $grav = \Grav\Common\Grav::instance();
        $sprache = $grav['language'];
        $code = $sprache->getActive()
            ?: $grav['config']->get('system.languages.default_lang')
            ?: $grav['config']->get('site.default_lang')
            ?: 'en';

        return (string) $sprache->translate('PLUGIN_CW_SOCIAL.' . $schluessel, array_unique([$code, 'en']));
    }
}
