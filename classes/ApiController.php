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
 * Admin-2.0-Endpunkte von cw-social (Grav 2.0 / api-Plugin).
 *
 *   GET  /cw-social/kern/{name}   -> kern()      (api.access)  Kern einer Admin-Komponente
 *   POST /cw-social/vorschau      -> vorschau()  (api.access)  Beitrags-Vorschau mit ungespeicherten Werten
 *
 * Die Vorschau rechnet dieselben Dinge wie der ShareWorker (Text, Link,
 * Bild), nur mit den Werten, die gerade im Formular stehen -- bevor
 * gespeichert ist. Was dabei herauskommt, ist das, was gepostet wuerde.
 */
class ApiController extends AbstractApiController
{
    /**
     * Kern einer Admin-Komponente ausliefern (Huelle/Kern-Muster, damit ein
     * Update ohne Neuladen des Admins gilt). no-cache + ETag: unveraendert = 304.
     */
    public function kern(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $name  = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($request->getAttribute('route_params', [])['name'] ?? '')));
        $datei = dirname(__DIR__) . '/admin-next/kern/' . $name . '.js';
        if ($name === '' || !is_file($datei)) {
            return ErrorResponse::create(404, 'Not found', "No core named {$name}.");
        }
        $etag    = '"' . md5($name . ':' . (@filemtime($datei) ?: 0) . ':' . (@filesize($datei) ?: 0)) . '"';
        $headers = [
            'Content-Type'  => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'private, no-cache',
            'ETag'          => $etag,
        ];
        $mitgeschickt = array_map('trim', explode(',', $request->getHeaderLine('If-None-Match')));
        if (in_array($etag, $mitgeschickt, true) || in_array('W/' . $etag, $mitgeschickt, true)) {
            return new Response(304, $headers, '');
        }
        return new Response(200, $headers, (string) file_get_contents($datei));
    }

    /**
     * Vorschau der Beitraege fuer eine Seite.
     * POST /cw-social/vorschau  { route, title?, summary?, datum?, message?, bild?, share?, instagram? }
     * -> { seite, link, facebook: {aktiv, text, karte}, instagram: {aktiv, text, bild}, hinweise }
     */
    public function vorschau(ServerRequestInterface $request): ResponseInterface
    {
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

        $hinweise = [];
        if (empty($cfg['page_id']) || empty($cfg['access_token'])) {
            $hinweise[] = self::text('HINWEIS_FB_OFFEN');
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
        $wann = static function ($iso): string {
            $ts = is_string($iso) && $iso !== '' ? strtotime($iso) : false;
            return $ts ? date('d.m.Y, H:i', $ts) : '';
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
                    'url'     => (string) ($social['ig_permalink'] ?? ''),
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
