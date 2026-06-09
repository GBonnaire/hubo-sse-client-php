<?php

namespace HuboSSE;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Client implements ClientInterface
{
    private HttpClientInterface $httpClient;

    /**
     * @param string                   $url        URL de base du serveur hubo-sse, sans slash final.
     * @param string                   $appId      Identifiant unique du tenant (app_id).
     * @param string                   $secret     Clé symétrique HS256 (minimum 32 caractères).
     * @param HttpClientInterface|null $httpClient Client HTTP optionnel (tests, proxy, timeout…).
     */
    public function __construct(
        private string $url,
        private string $appId,
        private string $secret,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getAppId(): string
    {
        return $this->appId;
    }

    public function subscriberToken(
        array $topics,
        int $ttl = 3600,
        ?string $jti = null,
        ?string $sessionId = null,
    ): string {
        return $this->buildToken('subscribe', $topics, $ttl, $jti, $sessionId);
    }

    public function publisherToken(array $topics, int $ttl = 60, ?string $jti = null): string
    {
        return $this->buildToken('publish', $topics, $ttl, $jti);
    }

    public function publish(array $topics, array $data, array $options = []): string
    {
        $body = ['topics' => $topics, 'data' => $data];

        if (isset($options['private'])) {
            $body['private'] = (bool) $options['private'];
        }
        if (isset($options['id'])) {
            $body['id'] = (string) $options['id'];
        }
        if (isset($options['retry'])) {
            $body['retry'] = (int) $options['retry'];
        }

        $response = $this->httpClient->request('POST', $this->url . '/publish', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->publisherToken($topics),
                'Content-Type'  => 'application/json',
            ],
            'json' => $body,
        ]);

        return $response->toArray()['id'];
    }

    public function listeners(string $topic): int
    {
        if ('' === $topic) {
            return 0;
        }

        $response = $this->httpClient->request('GET', $this->url . '/listeners/' . rawurlencode($topic), [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->publisherToken([$topic]),
            ],
        ]);

        return $response->toArray()['listeners'] ?? 0;
    }

    public function haveListeners(string $topic): bool
    {
        if ('' === $topic) {
            return false;
        }

        return $this->listeners($topic) > 0;
    }

    public function unsubscribe(string $connectionId): bool
    {
        $response = $this->httpClient->request('POST', $this->url . '/unsubscribe', [
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => ['connectionId' => $connectionId],
        ]);

        return 200 === $response->getStatusCode();
    }

    public function health(): array
    {
        $response = $this->httpClient->request('GET', $this->url . '/health');

        return $response->toArray(throw: false);
    }

    public function isHealthy(): bool
    {
        try {
            return 'ok' === ($this->health()['status'] ?? null);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Construit et signe un token JWT HS256.
     *
     * @param 'publish'|'subscribe' $mode
     * @param string[]              $topics
     */
    private function buildToken(
        string $mode,
        array $topics,
        int $ttl,
        ?string $jti = null,
        ?string $sessionId = null,
    ): string {
        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->secret)
        );

        $builder = $config->builder()
            ->issuedBy($this->appId)
            ->withClaim('mode', $mode)
            ->withClaim('topics', $topics)
            ->expiresAt(new DateTimeImmutable("+{$ttl} seconds"));

        if (null !== $jti) {
            $builder = $builder->identifiedBy($jti);
        }

        if (null !== $sessionId) {
            $builder = $builder->withClaim('session_id', $sessionId);
        }

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }
}
