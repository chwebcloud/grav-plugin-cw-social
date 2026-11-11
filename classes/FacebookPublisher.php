<?php
declare(strict_types=1);

namespace Grav\Plugin\CwSocial;

/**
 * Facebook-Page-Posts ueber die Meta Graph API.
 */
class FacebookPublisher extends GraphApi
{
    private string $pageId;

    public function __construct(string $pageId, string $accessToken, string $apiVersion = 'v21.0')
    {
        parent::__construct($accessToken, $apiVersion);
        $this->pageId = trim($pageId);
    }

    /** Verbindung pruefen: Seitenname abrufen. @return array{ok:bool,name?:string,error?:string} */
    public function test(): array
    {
        $res = $this->get('/' . $this->pageId, ['fields' => 'name']);
        if (isset($res['name'])) {
            return ['ok' => true, 'name' => (string) $res['name']];
        }
        return ['ok' => false, 'error' => $res['_error'] ?? 'Unexpected response'];
    }

    /**
     * Link-Post auf die Page stellen. Facebook holt Bild und Titel aus den
     * Open-Graph-Angaben der verlinkten Seite.
     * @return array{ok:bool,post_id?:string,error?:string}
     */
    public function publishLink(string $message, string $link): array
    {
        $res = $this->post('/' . $this->pageId . '/feed', [
            'message' => $message,
            'link'    => $link,
        ]);
        if (isset($res['id'])) {
            return ['ok' => true, 'post_id' => (string) $res['id']];
        }
        return ['ok' => false, 'error' => $res['_error'] ?? 'Unexpected response'];
    }

    /**
     * Foto-Post: laedt ein lokales Bild direkt hoch (multipart) und stellt es
     * mit Bildunterschrift auf die Page. Braucht KEIN oeffentliches og:image.
     * @return array{ok:bool,post_id?:string,error?:string}
     */
    public function publishPhoto(string $message, string $imagePath): array
    {
        if (!is_file($imagePath)) {
            return ['ok' => false, 'error' => 'Image file not found: ' . $imagePath];
        }

        $url = 'https://graph.facebook.com/' . $this->apiVersion . '/' . $this->pageId . '/photos';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            // Array (statt http_build_query) -> curl sendet multipart/form-data.
            CURLOPT_POSTFIELDS     => [
                'message'      => $message,
                'access_token' => $this->accessToken,
                'source'       => new \CURLFile($imagePath),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'CHWEB cw-social (+https://chweb.ch)',
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = self::antwort($body, $err, $code);
        if (isset($json['_error'])) {
            return ['ok' => false, 'error' => $json['_error']];
        }
        // /photos liefert id (Foto) + post_id (Feed-Beitrag).
        return ['ok' => true, 'post_id' => (string) ($json['post_id'] ?? $json['id'] ?? '')];
    }
}
