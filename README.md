# hubo-sse-client-php

Client PHP pour [hubo-sse](https://github.com/GBonnaire/hubo-sse), le hub SSE multi-tenant auto-hébergé.

Ce SDK permet à un backend PHP de :
- générer des tokens JWT d'abonnement pour le frontend
- publier des événements vers les abonnés SSE
- interroger le nombre de connexions actives sur un topic
- fermer explicitement une connexion SSE
- surveiller l'état et les métriques du serveur

---

## Prérequis

- **PHP** >= 8.4
- **Composer**
- Un serveur **hubo-sse** fonctionnel avec un tenant configuré

---

## Installation

```bash
composer require gbonnaire/hubo-sse-client-php
```

---

## Démarrage rapide

```php
use HuboSSE\Client;

$client = new Client(
    url:    'https://hubo.exemple.com',
    appId:  'mon-app',           // app_id du tenant (créé via la CLI hubo-sse)
    secret: 'mon-secret-32-chars-minimum',
);

// 1. Générer un token pour le frontend
$token = $client->subscriberToken(['commandes:*']);

// 2. Transmettre ce token au navigateur (session, cookie, endpoint dédié…)
//    Le frontend l'utilise pour s'abonner :
//    const es = new EventSource(`/subscribe?topics=commandes:42&authorization=${token}`)

// 3. Publier un événement depuis le backend
$eventId = $client->publish(
    topics: ['commandes:42:statut'],
    data:   ['statut' => 'expédié', 'transporteur' => 'Colissimo'],
);
```

---

## Architecture d'authentification

```
Votre backend PHP (ce SDK)
  └─ signe un JWT avec (app_id + secret)        ← le secret ne quitte jamais le backend
       └─ transmet le token au frontend
            └─ le frontend ouvre la connexion SSE avec ce token
```

Hubo vérifie le JWT à chaque requête :
1. Lit le claim `iss` pour identifier le tenant.
2. Vérifie la signature avec le secret du tenant (HS256).
3. Contrôle les claims `mode`, `topics`, `exp`.
4. Si `jti` présent, vérifie l'absence de révocation dans Redis.

---

## Référence API

### `new Client(url, appId, secret, httpClient?)`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `url` | `string` | URL de base du serveur, sans slash final. Ex : `https://hubo.exemple.com` |
| `appId` | `string` | Identifiant unique du tenant (créé via `tenant add --app-id=…`). |
| `secret` | `string` | Clé symétrique HS256, minimum 32 caractères. Ne jamais exposer côté client. |
| `httpClient` | `HttpClientInterface\|null` | Client HTTP Symfony optionnel. Utile pour les tests ou un proxy. |

---

### `subscriberToken(topics, ttl, jti, sessionId): string`

Génère un token JWT `mode: subscribe` à transmettre au frontend.

```php
// Token simple, valable 1 heure
$token = $client->subscriberToken(['commandes:42:statut']);

// Wildcard : l'abonné peut écouter tous les sous-topics de commandes
$token = $client->subscriberToken(['commandes:*']);

// Token révocable, limité par utilisateur, valable 30 minutes
$token = $client->subscriberToken(
    topics:    ['commandes:*', 'alertes:critique'],
    ttl:       1800,
    jti:       bin2hex(random_bytes(16)),  // pour révocation CLI ultérieure
    sessionId: 'user-42',                 // limite les onglets simultanés
);
```

| Paramètre | Défaut | Description |
|-----------|--------|-------------|
| `$topics` | — | Topics autorisés. Supporte les wildcards (`orders:*`, `orders:*:status`, `*`). |
| `$ttl` | `3600` | Durée de validité en secondes. À expiration, le serveur envoie `token.expired` et ferme la connexion. |
| `$jti` | `null` | JWT ID unique. Permet la révocation individuelle via `token revoke --jti=…`. |
| `$sessionId` | `null` | Limite le nombre de connexions simultanées pour cet identifiant (`rateLimitConnections` du tenant, défaut 500). |

---

### `publisherToken(topics, ttl, jti): string`

Génère un token JWT `mode: publish`. Appelé automatiquement par `publish()` et `listeners()`.  
À exposer uniquement si un autre service backend doit appeler `/publish` directement.

```php
$token = $client->publisherToken(['commandes:*'], ttl: 120);
```

---

### `publish(topics, data, options): string`

Publie un événement vers un ou plusieurs topics via `POST /publish`.  
Retourne l'ID de l'événement publié (UUIDv7).

```php
// Publication simple
$id = $client->publish(
    ['commandes:42:statut'],
    ['statut' => 'expédié', 'transporteur' => 'Colissimo'],
);

// Avec options avancées
$id = $client->publish(
    topics:  ['commandes:42:statut', 'alertes'],
    data:    ['statut' => 'retard'],
    options: [
        'id'      => 'evt-retard-42',  // ID explicite (UUIDv7 auto sinon)
        'retry'   => 5000,             // délai de reconnexion SSE suggéré (ms)
        'private' => false,            // true = non persisté dans Redis Streams
    ],
);

// Notification privée (non rejouable après reconnexion)
$id = $client->publish(
    ['notifications:user-5'],
    ['message' => 'Paiement accepté'],
    ['private' => true],
);
```

| Option | Type | Défaut | Description |
|--------|------|--------|-------------|
| `private` | `bool` | `false` | Si `true`, l'événement n'est pas persisté dans Redis Streams et ne peut pas être rejoué. |
| `id` | `string` | auto | ID de l'événement. Généré en UUIDv7 si absent. |
| `retry` | `int` | — | Délai de reconnexion SSE suggéré au client, en millisecondes. |

**Limites par défaut du tenant :** 100 publications/seconde (`429 rate_limit_exceeded`), body max 64 Ko (`413 payload_too_large`).

---

### `listeners(topic): int`

Retourne le nombre de connexions SSE actives sur un topic pour le tenant courant.

```php
$count = $client->listeners('commandes:42:statut');
// → 7
```

---

### `haveListeners(topic): bool`

Raccourci pour conditionner une publication à la présence d'abonnés.

```php
if ($client->haveListeners('commandes:42:statut')) {
    $client->publish(['commandes:42:statut'], ['statut' => 'expédié']);
}
```

---

### `unsubscribe(connectionId): bool`

Ferme explicitement une connexion SSE active côté serveur via `POST /unsubscribe`.  
Retourne `true` si la connexion a été fermée, `false` si elle était déjà fermée ou introuvable.

Le `connectionId` est envoyé par le serveur à l'ouverture du flux SSE :

```
event: connected
data: {"id": "550e8400-e29b-41d4-a716-446655440000"}
```

```php
// Côté frontend : stocker le connectionId
// es.addEventListener('connected', (e) => {
//     const { id } = JSON.parse(e.data);
//     fetch('/api/session', { method: 'POST', body: JSON.stringify({ connectionId: id }) });
// });

// Côté backend : fermer lors d'une déconnexion de session
$client->unsubscribe('550e8400-e29b-41d4-a716-446655440000');
```

---

### `health(): array`

Vérifie l'état du serveur via `GET /health`. Aucune authentification requise.

```php
$health = $client->health();
// [
//   'status'      => 'ok',       // 'ok' | 'degraded'
//   'redis'       => 'ok',       // 'ok' | 'error'
//   'database'    => 'ok',       // 'ok' | 'error'
//   'uptime'      => 3600,       // secondes
//   'connections' => 42,         // connexions SSE actives (tous tenants)
// ]

if ('degraded' === $health['status']) {
    // alerter, basculer sur un fallback…
}
```

---

### `isHealthy(): bool`

Raccourci booléen. Absorbe les exceptions réseau.

```php
if (!$client->isHealthy()) {
    throw new \RuntimeException('Hubo est indisponible.');
}
```

---

### `metrics(adminToken?): string`

Récupère les métriques au format Prometheus via `GET /metrics`.  
Le `$adminToken` est requis uniquement si `HUBO_ADMIN_TOKEN` est configuré sur le serveur.

```php
// Sans protection
echo $client->metrics();

// Avec token admin
echo $client->metrics($_ENV['HUBO_ADMIN_TOKEN']);
```

```
# HELP hubo_connections_active Connexions SSE actives
# TYPE hubo_connections_active gauge
hubo_connections_active{tenant="mon-app"} 14

# HELP hubo_messages_published_total Messages publiés
# TYPE hubo_messages_published_total counter
hubo_messages_published_total{tenant="mon-app"} 1042

# HELP hubo_jwt_errors_total Erreurs JWT
# TYPE hubo_jwt_errors_total counter
hubo_jwt_errors_total{tenant="mon-app",reason="token_expired"} 3
```

---

## Wildcards sur les topics

Les topics utilisent `:` comme séparateur de segments. Les wildcards `*` sont autorisés dans les claims du JWT, pas dans les appels à `publish()`.

| Pattern JWT | Topics couverts |
|-------------|----------------|
| `orders:*` | `orders:42`, `orders:42:status`, `orders:99:events`… |
| `orders:*:status` | `orders:42:status`, `orders:99:status` (un seul segment) |
| `alertes` | `alertes` uniquement (pas `alertes:critique`) |
| `*` | tous les topics |

```php
// Token couvrant tous les sous-topics d'une commande spécifique
$token = $client->subscriberToken(['commandes:42:*']);

// Token couvrant le champ "statut" de toutes les commandes
$token = $client->subscriberToken(['commandes:*:statut']);
```

---

## Événements SSE reçus par les abonnés

Le frontend reçoit les événements suivants sur le flux SSE :

| Événement | Déclencheur | Données |
|-----------|-------------|---------|
| `connected` | Ouverture de la connexion | `{"id": "<connection-uuid>"}` |
| *(sans nom)* | Publication via `/publish` | `{"clé": "valeur", ...}` |
| `: ping` | Keep-alive toutes les 20 s | aucune |
| `token.expired` | Expiration du JWT | `{}` |
| `server.shutdown` | Arrêt propre du serveur | `{}` |

```js
const es = new EventSource(`https://hubo.exemple.com/subscribe?topics=commandes:42&authorization=${token}`)

es.addEventListener('connected', (e) => {
    const { id } = JSON.parse(e.data)
    // stocker id pour fermeture explicite via unsubscribe()
})

es.onmessage = (e) => {
    const data = JSON.parse(e.data)
    console.log('Événement reçu :', data)
}

es.addEventListener('token.expired', () => {
    es.close()
    // obtenir un nouveau token depuis le backend et se reconnecter
})

es.addEventListener('server.shutdown', () => {
    es.close()
    setTimeout(reconnect, 5000)
})
```

### Replay après déconnexion

Les événements sont conservés dans Redis Streams pendant 1 heure (TTL configurable par tenant). Pour ne perdre aucun événement lors d'une reconnexion, passer `lastEventId` :

```js
let lastId

es.onmessage = (e) => { lastId = e.lastEventId }

// À la reconnexion :
const url = new URL('https://hubo.exemple.com/subscribe')
url.searchParams.set('topics', 'commandes:42')
url.searchParams.set('authorization', newToken)
url.searchParams.set('lastEventId', lastId)
const es = new EventSource(url)
```

---

## Gestion des erreurs

Toutes les méthodes HTTP lancent une exception Symfony en cas d'erreur réseau ou HTTP.

```php
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

try {
    $id = $client->publish(['commandes:42'], ['statut' => 'expédié']);
} catch (HttpExceptionInterface $e) {
    // Erreur HTTP (401, 403, 413, 429…)
    $status = $e->getResponse()->getStatusCode();
    $body   = $e->getResponse()->toArray(throw: false);
    // $body['error'] → 'rate_limit_exceeded', 'topic_not_allowed'…
} catch (TransportExceptionInterface $e) {
    // Réseau injoignable, timeout…
}
```

| Code | `error` | Cause |
|------|---------|-------|
| 400 | `topics_required` | `topics` absent ou vide |
| 401 | `missing_token` | Header `Authorization` absent |
| 401 | `invalid_token` | Signature invalide ou token malformé |
| 401 | `token_expired` | Claim `exp` dépassé |
| 401 | `unknown_tenant` | `iss` ne correspond à aucun tenant |
| 401 | `token_revoked` | JTI présent dans la blacklist Redis |
| 403 | `wrong_mode` | Token `publish` utilisé sur `/subscribe` ou inversement |
| 403 | `topic_not_allowed` | Topic non couvert par les `topics` du JWT |
| 413 | `payload_too_large` | Body dépasse `maxEventSize` du tenant (défaut 64 Ko) |
| 429 | `rate_limit_exceeded` | Limite de publications par seconde atteinte (défaut 100/s) |
| 429 | `too_many_connections` | Limite de connexions SSE simultanées atteinte (défaut 500) |

---

## Interface et extensibilité

`Client` implémente `ClientInterface`. Utilisez l'interface pour l'injection de dépendances ou pour créer un mock dans vos tests :

```php
use HuboSSE\ClientInterface;

class MonService
{
    public function __construct(private ClientInterface $hubo) {}

    public function notifier(int $userId, string $message): void
    {
        if ($this->hubo->haveListeners("notifications:{$userId}")) {
            $this->hubo->publish(["notifications:{$userId}"], ['message' => $message]);
        }
    }
}
```

---

## Licence

MIT — voir [LICENSE](LICENSE).
