<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace GlpiPlugin\Pluginsync;
use RuntimeException;
use Session;
final class Client
{
    private function call(string $method, string $path, ?array $payload = null): array
    {
        if (!in_array($method, ['GET', 'POST', 'DELETE'], true)
            || !preg_match('~^/v1/jobs(?:\?page=[1-9][0-9]{0,8}|/[1-9][0-9]{0,12}(?:/(?:run|logs))?)?$~D', $path)) {
            throw new RuntimeException('Requête interne invalide.');
        }
        $file = '/run/secrets/pluginsync_token';
        $token = is_readable($file) ? trim((string) file_get_contents($file)) : '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new RuntimeException('Secret du service absent ou illisible par PHP.');
        }
        $handle = curl_init('http://pluginsync-worker:8080' . $path);
        if ($handle === false) { throw new RuntimeException('Connexion impossible.'); }
        $body = '';
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_PROXY => '',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json',
                'Content-Type: application/json', 'X-GLPI-User: ' . (int) Session::getLoginUserID()],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 1048576) { return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload === null ? '{}' : json_encode($payload, JSON_THROW_ON_ERROR));
        }
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($ok === false) {
            throw new RuntimeException('Service indisponible ou délai dépassé. Actualisez avant de réessayer.');
        }
        $result = json_decode($body, true, 32);
        if (!is_array($result)) { throw new RuntimeException('Réponse du service invalide.'); }
        if ($status < 200 || $status >= 300) {
            $message = $result['error'] ?? 'Requête refusée par le service.';
            throw new RuntimeException(is_string($message) ? mb_substr($message, 0, 500) : 'Erreur du service.');
        }
        return $result;
    }
    public function jobs(int $page = 1): array { return $this->call('GET', '/v1/jobs?page=' . max(1, min(999999999, $page))); }
    public function job(int $id): array { return $this->call('GET', '/v1/jobs/' . $id); }
    public function logs(int $id): array { return $this->call('GET', '/v1/jobs/' . $id . '/logs'); }
    public function save(array $values): array { return $this->call('POST', '/v1/jobs', $values); }
    public function run(int $id): array { return $this->call('POST', '/v1/jobs/' . $id . '/run'); }
    public function delete(int $id): array { return $this->call('DELETE', '/v1/jobs/' . $id); }
}
