<?php

namespace HuboSSE;

/**
 * Interface du client PHP pour le serveur hubo-sse.
 *
 * Hubo est un hub SSE (Server-Sent Events) multi-tenant basé sur JWT.
 * Ce client expose les opérations backend : génération de tokens JWT,
 * publication d'événements, interrogation des listeners, fermeture de
 * connexions et diagnostic du serveur.
 *
 * ─── Architecture d'authentification ────────────────────────────────────────
 *
 *  Votre backend (ce client)
 *    └─ signe un JWT avec (app_id + secret)          ← ne jamais exposer le secret
 *         └─ transmet le token au frontend
 *              └─ le frontend s'abonne via GET /subscribe?authorization=<token>
 *
 * Hubo vérifie le JWT à chaque requête :
 *   1. Décode le claim `iss` pour identifier le tenant (app_id).
 *   2. Vérifie la signature avec le secret du tenant (HS256 ou RS256).
 *   3. Contrôle les claims `mode`, `topics`, `exp`.
 *   4. Si `jti` présent, vérifie qu'il n'est pas révoqué dans Redis.
 *
 * ─── Usage minimal ──────────────────────────────────────────────────────────
 *
 *  $client = new Client('https://hubo.exemple.com', 'mon-app', 'mon-secret-32-chars');
 *
 *  // Générer un token d'abonnement pour le frontend
 *  $token = $client->subscriberToken(['commandes:42:statut']);
 *
 *  // Publier un événement depuis le backend
 *  $eventId = $client->publish(['commandes:42:statut'], ['statut' => 'expédié']);
 *
 * ─── Wildcards sur les topics ────────────────────────────────────────────────
 *
 *  Les topics utilisent `:` comme séparateur de segments. Les wildcards `*`
 *  sont autorisés dans les claims `topics` du JWT :
 *
 *    `orders:*`         → orders:42, orders:42:status, orders:99:events…
 *    `orders:*:status`  → orders:42:status, orders:99:status (un seul segment)
 *    `*`                → tous les topics
 *
 *  Le wildcard dans un JWT ne s'applique pas aux topics passés à `publish()` :
 *  ceux-ci doivent être des topics exacts (sans wildcard).
 *
 * ─── Événements SSE reçus par les abonnés ────────────────────────────────────
 *
 *  À l'ouverture de la connexion :
 *    event: connected
 *    data: {"id": "<connection-uuid>"}
 *
 *  Événement métier (publié via /publish) :
 *    id: <event-uuid>
 *    data: {"clé": "valeur", ...}
 *
 *  Keep-alive (toutes les 20 secondes) :
 *    : ping
 *
 *  Expiration du token :
 *    event: token.expired
 *    data: {}
 *
 *  Arrêt propre du serveur :
 *    event: server.shutdown
 *    data: {}
 */
interface ClientInterface
{
    // ─── Accesseurs ───────────────────────────────────────────────────────────

    /**
     * Retourne l'URL de base du serveur configuré.
     */
    public function getUrl(): string;

    /**
     * Retourne l'app_id du tenant configuré.
     */
    public function getAppId(): string;

    // ─── Génération de tokens JWT ────────────────────────────────────────────

    /**
     * Génère un token JWT destiné aux abonnés frontend (mode `subscribe`).
     *
     * Ce token est transmis au navigateur qui l'utilise pour ouvrir une connexion
     * SSE via `GET /subscribe`. Le navigateur peut le passer soit dans le header
     * `Authorization: Bearer <token>`, soit dans le query param `?authorization=<token>`
     * (obligatoire pour l'API `EventSource` native qui ne supporte pas les headers).
     *
     * Le serveur accepte les topics passés en query string séparés par des virgules
     * (`?topics=a,b`) ou en paramètres répétés (`?topics=a&topics=b`). Les topics
     * demandés doivent être couverts par les `topics` du JWT (correspondance exacte
     * ou par wildcard).
     *
     * Pour le replay des événements manqués après une déconnexion, le client passe
     * le paramètre `lastEventId` (ou le header `Last-Event-ID`) avec l'ID du dernier
     * événement reçu. Le serveur rejoue alors les événements depuis Redis Streams
     * (conservés pendant le TTL du tenant, 1 heure par défaut).
     *
     * @param string[]    $topics    Topics auxquels l'abonné est autorisé. Supporte les wildcards :
     *                               `'orders:*'`, `'orders:*:status'`, `'*'`.
     * @param int         $ttl       Durée de validité en secondes (défaut : 3600 = 1 heure).
     *                               À l'expiration, le serveur envoie l'événement `token.expired`
     *                               et ferme la connexion. Le frontend doit alors obtenir un nouveau
     *                               token et se reconnecter.
     * @param string|null $jti       Identifiant unique du token (JWT ID), permet sa révocation
     *                               individuelle via la CLI (`token revoke --jti=…`).
     *                               Générez-le avec `bin2hex(random_bytes(16))`.
     * @param string|null $sessionId Identifiant de session ou d'utilisateur. Limite le nombre de
     *                               connexions SSE simultanées pour cet identifiant (configuré par
     *                               `rateLimitConnections` du tenant, défaut 500). Utile pour
     *                               éviter qu'un même utilisateur ouvre des dizaines d'onglets.
     *
     * @return string Token JWT signé en HS256, à transmettre au frontend.
     *
     * @example
     *  // Token simple pour un topic exact
     *  $token = $client->subscriberToken(['commandes:42:statut']);
     *
     *  // Token multi-topics avec wildcard
     *  $token = $client->subscriberToken(['commandes:*', 'alertes:critique']);
     *
     *  // Token révocable et limité par utilisateur, valable 30 minutes
     *  $token = $client->subscriberToken(
     *      topics:    ['commandes:*'],
     *      ttl:       1800,
     *      jti:       bin2hex(random_bytes(16)),
     *      sessionId: 'user-42',
     *  );
     */
    public function subscriberToken(
        array $topics,
        int $ttl = 3600,
        ?string $jti = null,
        ?string $sessionId = null,
    ): string;

    /**
     * Génère un token JWT destiné aux publishers backend (mode `publish`).
     *
     * Utilisé en interne par {@see publish()} et {@see listeners()}. Exposez-le
     * uniquement si vous devez effectuer des appels HTTP directs depuis un autre
     * service backend (micro-service, worker de file d'attente, etc.).
     *
     * Un token publisher ne peut être utilisé que sur `POST /publish` et
     * `GET /listeners/:topic`. L'utiliser sur `GET /subscribe` retourne HTTP 403
     * (`wrong_mode`).
     *
     * @param string[]    $topics Topics sur lesquels le publisher est autorisé.
     *                            Les topics publiés doivent être couverts par cette liste.
     * @param int         $ttl    Durée de validité en secondes (défaut : 60).
     *                            Un TTL court est recommandé pour les tokens publisher
     *                            car ils sont générés à la volée avant chaque appel.
     * @param string|null $jti    Identifiant unique du token pour sa révocation.
     *
     * @return string Token JWT signé en HS256.
     */
    public function publisherToken(array $topics, int $ttl = 60, ?string $jti = null): string;

    // ─── Publication ─────────────────────────────────────────────────────────

    /**
     * Publie un événement vers un ou plusieurs topics via `POST /publish`.
     *
     * Les abonnés SSE connectés sur ces topics reçoivent l'événement en temps
     * réel. L'événement est également persisté dans Redis Streams pour le replay
     * lors d'une reconnexion (sauf si `private: true`).
     *
     * Un token publisher est généré automatiquement (TTL 60 s) puis transmis
     * dans le header `Authorization: Bearer <token>`.
     *
     * Limites configurées par tenant (valeurs par défaut) :
     *   - Débit : 100 publications/seconde → HTTP 429 (`rate_limit_exceeded`)
     *   - Taille maximale du body : 64 Ko  → HTTP 413 (`payload_too_large`)
     *
     * @param string[] $topics   Topics destinataires (au moins 1). Doivent être des topics
     *                           exacts (sans wildcard) couverts par le JWT publisher.
     * @param array    $data     Données de l'événement, sérialisables en JSON.
     *                           Les abonnés reçoivent ce tableau encodé en JSON dans le
     *                           champ `data:` du flux SSE.
     * @param array{
     *     private?: bool,
     *     id?: string,
     *     retry?: int,
     * }              $options   Options de publication :
     *     - `private` (bool, défaut `false`) : si `true`, l'événement n'est pas persisté
     *       dans Redis Streams et ne peut pas être rejoué. Utile pour les données sensibles
     *       ou les notifications ponctuelles.
     *     - `id` (string) : ID explicite de l'événement (transmis dans le champ `id:` SSE).
     *       Généré automatiquement en UUIDv7 si absent. Doit être unique par topic pour
     *       que le replay fonctionne correctement.
     *     - `retry` (int) : délai de reconnexion SSE suggéré au client, en millisecondes.
     *       Transmis dans le champ `retry:` du flux SSE.
     *
     * @return string ID de l'événement publié (UUIDv7 ou valeur de `options['id']`).
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     *   En cas d'erreur réseau (serveur injoignable, timeout…).
     * @throws \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface
     *   HTTP 400 : topics vide, data non sérialisable.
     *   HTTP 401 : token invalide, expiré ou tenant inconnu.
     *   HTTP 403 : topic non couvert par le JWT.
     *   HTTP 413 : body dépasse la limite du tenant.
     *   HTTP 429 : débit dépassé.
     *
     * @example
     *  // Publication simple
     *  $id = $client->publish(
     *      ['commandes:42:statut'],
     *      ['statut' => 'expédié', 'transporteur' => 'Colissimo']
     *  );
     *
     *  // Publication multi-topics avec ID explicite et délai de reconnexion
     *  $id = $client->publish(
     *      topics:  ['commandes:42:statut', 'alertes'],
     *      data:    ['statut' => 'retard'],
     *      options: ['id' => 'evt-retard-42', 'retry' => 5000],
     *  );
     *
     *  // Notification privée (non persistée dans Redis)
     *  $id = $client->publish(
     *      ['notifications:user-5'],
     *      ['message' => 'Votre paiement a été accepté'],
     *      ['private' => true],
     *  );
     */
    public function publish(array $topics, array $data, array $options = []): string;

    // ─── Listeners ───────────────────────────────────────────────────────────

    /**
     * Retourne le nombre de connexions SSE actives sur un topic via `GET /listeners/:topic`.
     *
     * Le comptage est scopé automatiquement au tenant authentifié : il est impossible
     * d'interroger les connexions d'un autre tenant, même en connaissant le nom du topic.
     *
     * @param string $topic Topic exact à interroger (sans wildcard).
     *                      Exemple : `'commandes:42:statut'`
     *
     * @return int Nombre de connexions SSE actives. Retourne `0` si $topic est vide.
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface
     *   HTTP 401 : token invalide, expiré ou tenant inconnu.
     *   HTTP 403 : token avec mode `subscribe` utilisé.
     *
     * @example
     *  $count = $client->listeners('commandes:42:statut');
     *  echo "Abonnés actifs : {$count}";
     */
    public function listeners(string $topic): int;

    /**
     * Indique si au moins un abonné est actif sur un topic.
     *
     * Raccourci pratique pour conditionner une publication à la présence d'abonnés
     * et éviter des appels inutiles quand personne ne reçoit.
     *
     * @param string $topic Topic exact à tester (sans wildcard).
     *
     * @return bool `true` si au moins un abonné est connecté, `false` sinon ou si $topic est vide.
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface
     *
     * @example
     *  if ($client->haveListeners('commandes:42:statut')) {
     *      $client->publish(['commandes:42:statut'], ['statut' => 'expédié']);
     *  }
     */
    public function haveListeners(string $topic): bool;

    // ─── Désinscription ──────────────────────────────────────────────────────

    /**
     * Ferme explicitement une connexion SSE active via `POST /unsubscribe`.
     *
     * L'ID de connexion est envoyé par le serveur dès l'ouverture du flux SSE
     * sous forme d'événement `connected` :
     *
     *   event: connected
     *   data: {"id": "550e8400-e29b-41d4-a716-446655440000"}
     *
     * Le frontend doit capturer cet ID et le transmettre au backend si une
     * fermeture explicite est nécessaire (déconnexion de session, révocation
     * de droits, migration de tenant, etc.).
     *
     * Sans cet appel, une connexion se ferme naturellement :
     *   - à l'expiration du token JWT (événement `token.expired`)
     *   - lors d'un arrêt du serveur (événement `server.shutdown`)
     *   - à la déconnexion réseau du client
     *
     * Cette méthode n'exige pas d'authentification : aucun token n'est requis,
     * l'ID de connexion sert lui-même de preuve d'autorisation.
     *
     * @param string $connectionId UUID de la connexion, reçu via l'événement `connected`.
     *
     * @return bool `true` si la connexion a été trouvée et fermée, `false` si introuvable
     *              (connexion déjà fermée ou ID invalide).
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     *   En cas d'erreur réseau.
     *
     * @example
     *  // Côté frontend (JavaScript) : capturer le connectionId à l'ouverture
     *  // let connectionId;
     *  // es.addEventListener('connected', (e) => {
     *  //     connectionId = JSON.parse(e.data).id;
     *  //     fetch('/api/store-connection', { method: 'POST', body: JSON.stringify({ connectionId }) });
     *  // });
     *
     *  // Côté backend PHP : fermer la connexion lors d'une déconnexion de session
     *  $closed = $client->unsubscribe('550e8400-e29b-41d4-a716-446655440000');
     *  if (!$closed) {
     *      // La connexion était déjà fermée, rien à faire
     *  }
     */
    public function unsubscribe(string $connectionId): bool;

    // ─── Diagnostic ──────────────────────────────────────────────────────────

    /**
     * Vérifie l'état des dépendances du serveur via `GET /health`.
     *
     * Interroge Redis et la base de données avec un timeout de 1 seconde chacun.
     * Aucune authentification requise. Retourne HTTP 200 si tout est opérationnel,
     * HTTP 503 si au moins une dépendance est dégradée.
     *
     * Utile pour les sondes de liveness/readiness Kubernetes, les health checks
     * de load balancers, ou pour conditionner le démarrage de votre application.
     *
     * @return array{
     *     status: 'ok'|'degraded',
     *     redis: 'ok'|'error',
     *     database: 'ok'|'error',
     *     uptime: int,
     *     connections: int,
     * } État courant du serveur :
     *   - `status`      : `ok` si Redis et la base sont joignables, `degraded` sinon.
     *   - `redis`       : résultat du PING Redis (timeout 1 s).
     *   - `database`    : résultat d'un SELECT 1 (timeout 1 s).
     *   - `uptime`      : temps de fonctionnement du processus en secondes.
     *   - `connections` : nombre total de connexions SSE actives (tous tenants confondus).
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     *   En cas d'erreur réseau (serveur injoignable).
     *
     * @example
     *  $health = $client->health();
     *  // ['status' => 'ok', 'redis' => 'ok', 'database' => 'ok', 'uptime' => 3600, 'connections' => 42]
     *
     *  if ('degraded' === $health['status']) {
     *      $logger->critical('Hubo dégradé', ['redis' => $health['redis'], 'db' => $health['database']]);
     *  }
     */
    public function health(): array;

    /**
     * Indique si le serveur est entièrement opérationnel (Redis + base de données OK).
     *
     * Enveloppe {@see health()} en absorbant toute exception réseau pour retourner
     * un simple booléen. Pratique pour les gardes rapides dans le code applicatif.
     *
     * @return bool `true` si le statut retourné est `ok`, `false` si dégradé ou injoignable.
     *
     * @example
     *  if (!$client->isHealthy()) {
     *      throw new \RuntimeException('Le serveur Hubo est indisponible.');
     *  }
     */
    public function isHealthy(): bool;
}
