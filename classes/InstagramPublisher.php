<?php
declare(strict_types=1);

namespace Grav\Plugin\CwSocial;

/**
 * Instagram-Beitraege ueber die Instagram Graph API (Content Publishing).
 *
 * Voraussetzungen, die Meta stellt:
 *   - ein Instagram-Business- oder Creator-Konto, das mit der Facebook-Seite
 *     verbunden ist; die ID des Kontos holt sich dieser Publisher ueber die
 *     Seite (`instagram_business_account`), sie muss nicht eingetragen werden
 *   - derselbe Page-Token wie fuer Facebook, mit den Berechtigungen
 *     instagram_basic und instagram_content_publish
 *   - ein OEFFENTLICH erreichbares Bild als JPEG (Instagram laedt es selbst;
 *     PNG und WebP werden abgewiesen, Seitenverhaeltnis 4:5 bis 1.91:1)
 *   - Instagram macht Links im Text nicht klickbar; die Adresse steht
 *     trotzdem im Text, damit man sie abtippen kann
 *
 * Veroeffentlicht wird in zwei Schritten: erst ein Medien-Container
 * (/media), dann die Freigabe (/media_publish), sobald Instagram das Bild
 * verarbeitet hat.
 */
class InstagramPublisher extends GraphApi
{
    private string $pageId;
    private string $igUserId;

    public function __construct(string $pageId, string $accessToken, string $apiVersion = 'v21.0', string $igUserId = '')
    {
        parent::__construct($accessToken, $apiVersion);
        $this->pageId   = trim($pageId);
        $this->igUserId = trim($igUserId);
    }

    /**
     * Verbundenes Instagram-Konto ermitteln und pruefen.
     * @return array{ok:bool,id?:string,username?:string,error?:string}
     */
    public function account(): array
    {
        if ($this->igUserId === '') {
            $res = $this->get('/' . $this->pageId, ['fields' => 'instagram_business_account']);
            if (isset($res['_error'])) {
                return ['ok' => false, 'error' => $res['_error']];
            }
            $id = (string) ($res['instagram_business_account']['id'] ?? '');
            if ($id === '') {
                return ['ok' => false, 'error' => 'No Instagram business account is connected to this Facebook page (Facebook page -> Settings -> Linked accounts).'];
            }
            $this->igUserId = $id;
        }

        $res = $this->get('/' . $this->igUserId, ['fields' => 'username']);
        if (isset($res['username'])) {
            return ['ok' => true, 'id' => $this->igUserId, 'username' => (string) $res['username']];
        }
        return ['ok' => false, 'error' => $res['_error'] ?? 'Unexpected response'];
    }

    /**
     * Bild mit Bildunterschrift veroeffentlichen.
     * @return array{ok:bool,post_id?:string,error?:string}
     */
    public function publishImage(string $caption, string $imageUrl): array
    {
        $acc = $this->account();
        if (!$acc['ok']) {
            return $acc;
        }

        $container = $this->post('/' . $this->igUserId . '/media', [
            'image_url' => $imageUrl,
            'caption'   => $caption,
        ]);
        if (!isset($container['id'])) {
            return ['ok' => false, 'error' => $container['_error'] ?? 'Instagram did not create a media container'];
        }
        $creationId = (string) $container['id'];

        // Instagram holt das Bild selbst und meldet, wann es bereit ist.
        // Hoechstens sechs Versuche im Abstand von 1.5 Sekunden -- der Worker
        // laeuft in einer Seitenanfrage, laenger darf er nicht warten.
        $status = '';
        for ($i = 0; $i < 6; $i++) {
            $s = $this->get('/' . $creationId, ['fields' => 'status_code,status']);
            $status = (string) ($s['status_code'] ?? '');
            if ($status === 'FINISHED') {
                break;
            }
            if ($status === 'ERROR' || $status === 'EXPIRED') {
                return ['ok' => false, 'error' => 'Instagram rejected the image: ' . (string) ($s['status'] ?? $status)];
            }
            usleep(1500000);
        }
        if ($status !== 'FINISHED') {
            return ['ok' => false, 'error' => 'Instagram had not finished processing the image after 9 seconds; the next run tries again.'];
        }

        $res = $this->post('/' . $this->igUserId . '/media_publish', ['creation_id' => $creationId]);
        if (isset($res['id'])) {
            // Permalink gleich mitholen -- Instagram hat sonst keine Adresse aus der ID.
            $info = $this->get('/' . $res['id'], ['fields' => 'permalink']);
            return ['ok' => true, 'post_id' => (string) $res['id'], 'permalink' => (string) ($info['permalink'] ?? '')];
        }
        return ['ok' => false, 'error' => $res['_error'] ?? 'Unexpected response while publishing'];
    }
}
