<?php
declare(strict_types=1);

namespace Grav\Plugin\CwSocial;

/**
 * Gemeinsamer Unterbau der Publisher: ein schlanker Meta-Graph-API-Client
 * (curl, ohne Fremdbibliothek). Der Access-Token wird ausschliesslich aus der
 * Plugin-Konfiguration gelesen und verlaesst den Server nur Richtung
 * graph.facebook.com.
 */
abstract class GraphApi
{
    protected string $apiVersion;
    protected string $accessToken;

    public function __construct(string $accessToken, string $apiVersion = 'v21.0')
    {
        $this->accessToken = trim($accessToken);
        $this->apiVersion  = trim($apiVersion) ?: 'v21.0';
    }

    /** @return array<string,mixed> dekodierte Antwort; Fehler unter "_error". */
    protected function get(string $path, array $params = []): array
    {
        return $this->request('GET', $path, $params);
    }

    /** @return array<string,mixed> dekodierte Antwort; Fehler unter "_error". */
    protected function post(string $path, array $params = []): array
    {
        return $this->request('POST', $path, $params);
    }

    /** @return array<string,mixed> */
    private function request(string $method, string $path, array $params): array
    {
        $params['access_token'] = $this->accessToken;
        $url = 'https://graph.facebook.com/' . $this->apiVersion . $path;

        $ch = curl_init();
        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'CHWEB cw-social (+https://chweb.ch)',
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return self::antwort($body, $err, $code);
    }

    /**
     * Antwort der Graph API in ein Array wandeln; Fehler landen unter "_error".
     * @param string|false $body
     * @return array<string,mixed>
     */
    protected static function antwort($body, string $err, int $code): array
    {
        if ($body === false) {
            return ['_error' => 'curl: ' . ($err ?: 'Connection failed')];
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            return ['_error' => 'HTTP ' . $code . ': ungueltige Antwort'];
        }
        if (isset($json['error'])) {
            $e = $json['error'];
            return ['_error' => sprintf('%s (Code %s, HTTP %d)',
                $e['message'] ?? 'Graph API error', $e['code'] ?? '?', $code)];
        }
        return $json;
    }
}
