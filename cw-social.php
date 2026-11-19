<?php
declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

/**
 * Autoloader fuer die Plugin-Klassen (classes/). Noetig, weil der api-Router
 * seine Routen CACHT (cache/api): onApiRegisterRoutes -- und damit das
 * require_once des Controllers -- feuert dann nicht mehr, und der Dispatch
 * meldete «Class Grav\Plugin\CwSocial\ApiController not found» (HTTP 500,
 * 07.10.2026 auf saloon.localhost). Laeuft bei jedem Request; auf Grav 1.7
 * harmlos, dort wird der Controller nie referenziert.
 */
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Grav\\Plugin\\CwSocial\\', 21) === 0) {
        $file = __DIR__ . '/classes/' . strtr(substr($class, 21), '\\', '/') . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

/**
 * CHWEB cw-social -- teilt Seiten automatisch auf Facebook und Instagram.
 *
 * Facebook: Link-Post auf die Seite (Meta Graph API).
 * Instagram: Bild der Seite mit Text (Instagram Graph API), wenn in der
 * Konfiguration eingeschaltet und mit der Facebook-Seite ein
 * Instagram-Business-Konto verbunden ist.
 *
 * Welche Seiten: Kinder der konfigurierten Routen (z. B. /news, /events) mit
 * einem der konfigurierten Seitentypen (z. B. item, event). Die Seite bekommt
 * im Admin einen Reiter «Teilen» mit den Schaltern und den Post-IDs.
 *
 * Der Worker laeuft laufzeit-getrieben (onPagesInitialized, gedrosselt)
 * statt an einen Admin-Save-Hook gekoppelt: so funktioniert er unabhaengig
 * davon, WIE eine Seite entsteht (admin1, admin2/api, Datei, Git), und ein
 * geplantes Datum wird abgewartet. Zustand (Post-IDs) steht im Frontmatter.
 */
class CwSocialPlugin extends Plugin
{
    public $features = ['blueprints' => 1000];

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onBlueprintCreated'   => ['onBlueprintCreated', 0],
            // Der vorgesehene Weg zum Posten: Gravs Zeitplaner.
            'onSchedulerInitialized' => ['onSchedulerInitialized', 0],
            // Grav 2.0 (api-Plugin): Vorschau-Endpunkt. Feuert nur dort; auf
            // Grav 1.7 ein toter Listener. Nicht hinter isAdmin() -- die API
            // laeuft im Frontend-Lifecycle.
            'onApiRegisterRoutes'  => ['onApiRegisterRoutes', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            return; // Worker nur im Frontend/CLI -- der Admin bleibt schnell.
        }
        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 0],
        ]);
    }

    /**
     * Seiten-Blueprints der konfigurierten Seitentypen um einen Reiter
     * «Teilen» erweitern: Facebook, Instagram (wenn eingeschaltet), eigener
     * Text, Post-IDs (nur lesbar). Das Ereignis feuert in admin1 und ueber
     * das api-Plugin auch fuer admin2.
     */
    public function onBlueprintCreated(Event $event): void
    {
        $blueprint = $event['blueprint'];
        $type      = (string) ($event['type'] ?? '');
        if (!$blueprint || $type === '') {
            return;
        }

        require_once __DIR__ . '/classes/ShareWorker.php';
        $cfg = (array) $this->config->get('plugins.cw-social');
        if (!in_array($type, CwSocial\ShareWorker::templates($cfg), true)) {
            return;
        }

        $tabs = $blueprint->get('form/fields/tabs/fields');
        if (!is_array($tabs)) {
            return;
        }

        $fields = [
            'cw_social_hinweis' => [
                'type'     => 'display',
                'markdown' => true,
                'content'  => 'PLUGIN_CW_SOCIAL.HINT',
            ],
            'header.cw_social.share' => [
                'type'      => 'toggle',
                'label'     => 'PLUGIN_CW_SOCIAL.SHARE_LABEL',
                'help'      => 'PLUGIN_CW_SOCIAL.SHARE_HELP',
                'highlight' => 1,
                'default'   => 0,
                'options'   => [1 => 'PLUGIN_ADMIN.YES', 0 => 'PLUGIN_ADMIN.NO'],
                'validate'  => ['type' => 'bool'],
            ],
        ];
        if (!empty($cfg['instagram'])) {
            $fields['header.cw_social.instagram'] = [
                'type'      => 'toggle',
                'label'     => 'PLUGIN_CW_SOCIAL.INSTAGRAM_LABEL',
                'help'      => 'PLUGIN_CW_SOCIAL.INSTAGRAM_HELP',
                'highlight' => 1,
                'default'   => 0,
                'options'   => [1 => 'PLUGIN_ADMIN.YES', 0 => 'PLUGIN_ADMIN.NO'],
                'validate'  => ['type' => 'bool'],
            ];
        }
        $fields['header.cw_social.message'] = [
            'type'  => 'textarea',
            'label' => 'PLUGIN_CW_SOCIAL.MESSAGE_LABEL',
            'help'  => 'PLUGIN_CW_SOCIAL.MESSAGE_HELP',
            'rows'  => 4,
        ];
        // Lebende Vorschau (admin2): zeigt den Beitrag mit den Werten, die
        // gerade im Formular stehen. Im klassischen Admin (Grav 1.7) ist der
        // Feldtyp unbekannt und wird uebergangen.
        $fields['cw_social_vorschau'] = [
            'type'  => 'cwsocialvorschau',
            'label' => 'PLUGIN_CW_SOCIAL.VORSCHAU_LABEL',
            'help'  => 'PLUGIN_CW_SOCIAL.VORSCHAU_HELP',
        ];
        // Die Beitrags-Nummern (fb_post_id, ig_post_id, Zeitstempel, Permalink)
        // bleiben im Kopf der Seite, ohne Feld: admin2 behaelt unbekannte
        // Kopfschluessel beim Speichern (gemessen 07.10.2026). Was der Kunde
        // sieht, ist der Status in der Vorschau und dieser Schalter.
        $fields['header.cw_social.erneut'] = [
            'type'      => 'toggle',
            'label'     => 'PLUGIN_CW_SOCIAL.ERNEUT_LABEL',
            'help'      => 'PLUGIN_CW_SOCIAL.ERNEUT_HELP',
            'highlight' => 1,
            'default'   => 0,
            'options'   => [1 => 'PLUGIN_ADMIN.YES', 0 => 'PLUGIN_ADMIN.NO'],
            'validate'  => ['type' => 'bool'],
        ];

        $tabs['cw_social'] = [
            'type'   => 'tab',
            'title'  => 'PLUGIN_CW_SOCIAL.TAB',
            'fields' => $fields,
        ];
        $blueprint->set('form/fields/tabs/fields', $tabs);
    }

    /**
     * Admin-2.0-Routen (api-Plugin). Das api-Plugin cacht seine Routentabelle
     * (cache/api); nach einem Update mit neuen Routen den Cache leeren.
     */
    public function onApiRegisterRoutes(Event $event): void
    {
        require_once __DIR__ . '/classes/ApiController.php';
        $event['routes']->post('/cw-social/vorschau', [CwSocial\ApiController::class, 'vorschau']);
    }

    /**
     * Der vorgesehene Weg zum Posten: ein Auftrag im Zeitplaner von Grav.
     *
     * Er laeuft ausserhalb jeder Seitenanfrage, einmal und nicht einmal je
     * Besucher, und er kann warten -- Instagram braucht nach dem Anlegen des
     * Medien-Containers ein paar Sekunden. Der laufzeit-getriebene Worker
     * (`auto_share`) ist seit 1.6.0 der Ausnahmeweg fuer Installationen ohne
     * Cron und ab Werk aus (getgrav/grav#4401, Punkt 1).
     *
     * Ein-, ausschalten und den Takt aendern: Admin -> Werkzeuge -> Zeitplaner.
     */
    public function onSchedulerInitialized(Event $event): void
    {
        $cfg = (array) $this->config->get('plugins.cw-social');
        if (empty($cfg['enabled']) || empty($cfg['scheduler'])) {
            return;
        }
        $takt = trim((string) ($cfg['scheduler_at'] ?? '')) ?: '*/15 * * * *';
        $job  = $event['scheduler']->addCommand('bin/plugin cw-social share --force', [], 'cw-social-share');
        $job->at($takt);
        $job->backlink('/plugins/cw-social');
    }

    /**
     * Ausnahmeweg fuer Installationen ohne Cron: gedrosselter Lauf in einer
     * Seitenanfrage, ab Werk AUS.
     *
     * Drei Dinge, die bis 1.5.1 fehlten und ohne die das nicht zu haben ist:
     * eine Sperre (zwei gleichzeitige Anfragen liefen beide los, weil Pruefen
     * und Setzen der Zeitmarke nicht zusammenhingen), der Verzicht bei
     * fehlender `site_url`, und der Rueckzug nach Fehlversuchen -- der steckt
     * im ShareWorker samt Protokoll.
     */
    public function onPagesInitialized(): void
    {
        $cfg = (array) $this->config->get('plugins.cw-social');
        if (empty($cfg['enabled']) || empty($cfg['auto_share'])
            || empty($cfg['page_id']) || empty($cfg['access_token'])
            || trim((string) ($cfg['site_url'] ?? '')) === '') {
            return;
        }

        $ordner = (string) $this->grav['locator']->findResource('cache://', true, true) . '/cw-social';
        if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
            return;
        }
        $marke = $ordner . '/last-run';
        $takt  = max(1, (int) ($cfg['throttle_minutes'] ?? 5)) * 60;

        // Vorpruefung ohne Sperre, damit die weit ueberwiegende Anfrage nicht
        // einmal eine Datei oeffnen muss.
        if (is_file($marke) && (time() - (int) @filemtime($marke)) < $takt) {
            return;
        }

        // Sperre: Nur eine Anfrage laeuft. Pruefen und Setzen der Zeitmarke
        // liegen darin, sonst kommen zwei gleichzeitige Anfragen beide durch
        // und posten dieselbe Seite zweimal.
        $sperre = @fopen($ordner . '/.lock', 'c');
        if (!$sperre) {
            return;
        }
        try {
            if (!flock($sperre, LOCK_EX | LOCK_NB)) {
                return;   // es laeuft schon einer
            }
            if (is_file($marke) && (time() - (int) @filemtime($marke)) < $takt) {
                return;
            }
            @touch($marke);

            require_once __DIR__ . '/classes/GraphApi.php';
            require_once __DIR__ . '/classes/FacebookPublisher.php';
            require_once __DIR__ . '/classes/InstagramPublisher.php';
            require_once __DIR__ . '/classes/Protokoll.php';
            require_once __DIR__ . '/classes/ShareWorker.php';

            (new CwSocial\ShareWorker($this->grav, $cfg))->run();
        } catch (\Throwable $e) {
            $this->grav['log']->error('cw-social: ' . $e->getMessage());
        } finally {
            @flock($sperre, LOCK_UN);
            @fclose($sperre);
        }
    }
}
