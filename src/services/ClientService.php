<?php

/**
 * Meilisearch Editor for Craft CMS.
 *
 * @author        DelaneyMethod
 * @copyright     Copyright (c) 2025
 *
 * @see           https://github.com/delaneymethod/craft-meilisearch-editor
 */

namespace delaneymethod\meilisearcheditor\services;

use Craft;
use craft\helpers\App;
use DateTimeImmutable;
use delaneymethod\meilisearcheditor\helpers\IndexConfigHelper;
use delaneymethod\meilisearcheditor\MeilisearchEditor;
use delaneymethod\meilisearcheditor\models\SettingsModel;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;
use RuntimeException;
use Throwable;
use yii\base\Component;
use yii\caching\TagDependency;
use yii\log\Logger;

class ClientService extends Component
{
    private const HEALTH_CACHE_TTL = 60; // seconds

    private const HEALTH_CACHE_TAG = 'meilisearch-health';

    private const HEALTH_CACHE_LAST_STATUS_KEY = ':lastStatus:'; // for change-only logging

    /**
     * @var string|null
     */
    private ?string $host = null;

    /**
     * @var string|null
     */
    private ?string $adminKey = null;

    /**
     * @var string|null
     */
    private ?string $searchKey = null;

    /**
     * @var string|null
     */
    private ?string $lastError = null;

    /**
     * @var Client|null
     */
    private ?Client $admin = null;

    /**
     * @var Client|null
     */
    private ?Client $search = null;

    /**
     * @return void
     */
    public function init(): void
    {
        /** @var SettingsModel $settings */
        $settings = MeilisearchEditor::$plugin->getSettings();

        $this->adminKey = App::parseEnv($settings->adminKey) ?? '';
        $this->searchKey = App::parseEnv($settings->searchKey) ?? '';
        $this->host = rtrim(App::parseEnv($settings->host) ?? '', '/');
    }

    public function __construct(private IndexConfigHelper $indexConfigHelper)
    {
        parent::__construct();
    }

    /**
     * @return Client
     */
    public function adminClient(): Client
    {
        if (!$this->admin) {
            $this->admin = new Client($this->getHost(), $this->getAdminKey());
        }

        return $this->admin;
    }

    /**
     * @return Client
     */
    public function searchClient(): Client
    {
        if (!$this->search) {
            $this->search = new Client($this->getHost(), $this->getSearchKey());
        }

        return $this->search;
    }

    /**
     * @return string|null
     */
    public function getHost(): ?string
    {
        return $this->host;
    }

    /**
     * @return string|null
     */
    public function getAdminKey(): ?string
    {
        return $this->adminKey;
    }

    /**
     * @return string|null
     */
    public function getSearchKey(): ?string
    {
        return $this->searchKey;
    }

    /**
     * @return string|null
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Returns array like:
     * [
     * 		'filterableAttributes' => [...],
     *      'sortableAttributes'   => [...],
     *      'faceting' => ['maxValuesPerFacet' => 200],
     *      ...
     * ]
     *
     * @param string $uid
     * @return array
     */
    public function getSettings(string $uid): array
    {
        try {
            return $this->withMeilisearch(fn (Client $adminClient) => $adminClient->index($uid)->getSettings());
        } catch (Throwable $exception) {
            Craft::getLogger()->log("getSettings failed for '{$uid}': {$exception->getMessage()}", Logger::LEVEL_WARNING, 'meilisearch-editor');

            return [];
        }
    }

    /**
     * Cached health check. Returns true if Meilisearch is reachable, else false.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        $cache = Craft::$app->getCache();

        $key = $this->getHealthCacheKey();

        $status = $cache->get($key);

        if ($status !== false) {
            // Already cached
            return (bool) $status;
        }

        // Not cached — perform the real health check once
        $isOk = $this->withMeilisearch(
            function (Client $adminClient) {
                $response = $adminClient->health(); // ['status' => 'available'] when OK

                return ($response['status'] ?? null) === 'available';
            },
            fallback: false,
        );

        // Cache the result with a tag so we can invalidate if settings changes
        $cache->set($key, $isOk, self::HEALTH_CACHE_TTL, new TagDependency([
            'tags' => [self::HEALTH_CACHE_TAG, $this->getSettingsTag()],
        ]));

        // Change-only logging to avoid spam
        $this->logIfStatusChanged($isOk);

        return $isOk;
    }

    /**
     * @return array
     */
    public function stats(): array
    {
        return $this->withMeilisearch(fn (Client $adminClient) => $adminClient->stats());
    }

    /**
     * @return array
     */
    public function getResults(): array
    {
        return $this->withMeilisearch(fn (Client $adminClient) => $adminClient->getIndexes()->getResults());
    }

    /**
     * @param string $name
     * @param string $handle
     * @param array $actions
     * @param int $ttl
     * @return array
     */
    public function issueKey(string $name, string $handle, array $actions = ['search'], int $ttl = 900): array
    {
        try {
            return $this->withMeilisearch(
                function (Client $adminClient) use ($name, $handle, $actions, $ttl) {
                    // Resolve handles -> UIDs for current site (works for site-aware & non–site-aware)
                    $siteId = Craft::$app->sites->currentSite->id;

                    $uids = [];
                    // If it already looks like a Meili UID (contains "_site" or equals a base handle), keep it
                    if (preg_match('/_site\d+$/', $handle)) {
                        $uids[] = $handle;
                    }

                    // Treat as handle: look up plugin index config and map to the current site
                    $index = MeilisearchEditor::$plugin->indexes->getIndex($handle);
                    if ($index) {
                        $uids[] = $this->indexConfigHelper->indexName($index, $siteId);
                    }

                    // Fallback: if caller passed nothing resolvable, deny
                    $uids = array_values(array_unique(array_filter($uids, 'strlen')));
                    if (!$uids) {
                        throw new RuntimeException(Craft::t('meilisearch-editor', 'No valid indexes to scope the search key.'));
                    }

                    $ttl = max(60, min(3600, $ttl));
                    $expiresAt = (new DateTimeImmutable("+{$ttl} seconds"))->format(DATE_ATOM);

                    $payload = [
                        'name' => $name,
                        'indexes' => $uids,
                        'actions' => $actions,
                        'expiresAt' => $expiresAt,
                    ];

                    $keys = $adminClient->createKey($payload);

                    $key = (string) $keys->getKey();
                    if (!$key) {
                        throw new RuntimeException(Craft::t('meilisearch-editor', 'Key not present in createKey response'));
                    }

                    return [
                        'key' => $key,
                        'success' => true,
                        'indexes' => $uids,
                        'expiresAt' => $expiresAt,
                    ];
                },
            );
        } catch (Throwable $exception) {
            $message = "issueKey failed for '{$handle}': {$exception->getMessage()}";

            Craft::getLogger()->log($message, Logger::LEVEL_WARNING, 'meilisearch-editor');

            return [
                'success' => false,
                'message' => $message,
            ];
        }
    }

    /**
     * @return array
     */
    public function getUids(): array
    {
        $uids = [];

        try {
            foreach ($this->getResults() as $result) {
                $uid = method_exists($result, 'getUid') ? $result->getUid() : null;
                if ($uid) {
                    $uids[] = $uid;
                }
            }
        } catch (Throwable $exception) {
            Craft::getLogger()->log("getUids failed: {$exception->getMessage()}", Logger::LEVEL_ERROR, 'meilisearch-editor');
        }

        return $uids;
    }

    /**
     * @param string $handle
     * @return array
     */
    public function getUidsByHandle(string $handle): array
    {
        $pattern = '/^' . preg_quote($handle, '/') . '(?:_site\d+)?$/';

        return array_values(array_filter($this->getUids(), fn (string $uid) => (bool) preg_match($pattern, $uid)));
    }

    /**
     * @param array $index
     * @param array $settings
     * @return void
     */
    public function applyIndexSettings(array $index, array $settings): void
    {
        foreach (IndexConfigHelper::indexNames($index) as $uid) {
            $this->withMeilisearch(fn (Client $adminClient) => $adminClient->index($uid)->updateSettings($settings));
        }
    }

    /**
     * @param string $uid
     * @return Indexes|null
     */
    public function getIndex(string $uid): ?Indexes
    {
        return $this->withMeilisearch(fn (Client $adminClient) => $adminClient->index($uid));
    }

    /**
     * @return array
     */
    public function getAllIndexes(): array
    {
        $indexes = [];

        foreach ($this->getResults() as $result) {
            $uid = $result->getUid();
            if (!$uid) {
                continue;
            }

            // derive handle/site-aware from uid
            $handle = $uid;
            $siteAware = false;
            $siteId = null;

            if (preg_match('/^(.*)_site(\d+)$/', $uid, $matches)) {
                $handle = $matches[1];
                $siteAware = true;
                $siteId = (int) $matches[2];
            }

            // query settings & stats from the live endpoint
            $endpoint = $this->getIndex($uid);

            $attributes = $endpoint->getFilterableAttributes();
            $filterable = $endpoint->getFilterableAttributes();
            $sortable = $endpoint->getSortableAttributes();

            // Meilisearch faceting settings (optional)
            $faceting = [];

            try {
                // not all SDK versions have getFaceting(); guard it
                if (method_exists($endpoint, 'getFaceting')) {
                    $faceting = $endpoint->getFaceting();
                }
            } catch (Throwable) {
            }

            // stats (SDK versions differ: stats() vs getStats())
            $stats = [];

            try {
                $stats = method_exists($endpoint, 'stats') ? $endpoint->stats() : (method_exists($endpoint, 'getStats') ? $endpoint->getStats() : []);
            } catch (Throwable) {
            }

            $totalDocuments = (int) $stats['numberOfDocuments'];

            // Build our index shape
            // First time we see this handle → create base record
            if (!isset($indexes[$handle])) {
                $indexes[$handle] = [
                    'label' => ucfirst(str_replace('-', ' ', $handle)),
                    'handle' => $handle,
                    'sections' => [], // unknown; will populate on edit
                    'entryTypes' => ['*'], // unknown; default
                    'siteAware' => $siteAware,
                    'siteId' => $siteId ?? '',
                    'siteIds' => $siteId ? [$siteId] : [],
                    'attributes' => array_values($attributes),
                    'fields' => [], // unknown; will populate on edit
                    'imageTransforms' => [], // unknown; will populate on edit
                    'sortable' => array_values($sortable),
                    'filterable' => array_values($filterable),
                    'enabled' => true, // treat live Meilisearch index as enabled
                    'totalDocuments' => $totalDocuments,
                    'totalDocumentsPerHandle' => $siteId ? [$siteId => $totalDocuments] : ['*' => $totalDocuments],
                    'maxValuesPerFacet' => $faceting['maxValuesPerFacet'] ?? 500,
                    'dateUpdated' => gmdate('c'),
                ];

                continue;
            }

            // Merge into existing handle (aggregate)
            $index = &$indexes[$handle];

            $index['siteAware'] = $index['siteAware'] || $siteAware;

            if ($siteId) {
                $index['siteIds'] = array_values(array_unique(array_merge($index['siteIds'], [$siteId])));
                $index['totalDocumentsPerHandle'][$siteId] = $totalDocuments;
            } else {
                $index['totalDocumentsPerHandle']['*'] = $totalDocuments;
            }

            // Sum docs across all variants
            $index['totalDocuments'] += $totalDocuments;

            // Union runtime settings
            $index['attributes'] = array_values(array_unique(array_merge($index['attributes'], $attributes)));
            $index['filterable'] = array_values(array_unique(array_merge($index['filterable'], $filterable)));
            $index['sortable'] = array_values(array_unique(array_merge($index['sortable'], $sortable)));

            if (isset($faceting['maxValuesPerFacet'])) {
                $index['maxValuesPerFacet'] = max((int) $index['maxValuesPerFacet'], (int) $faceting['maxValuesPerFacet']);
            }
        }

        return $indexes;
    }

    /**
     * @param array $index
     * @return void
     */
    public function save(array $index): void
    {
        foreach ($this->indexConfigHelper->indexNames($index) as $indexName) {
            try {
                $task = $this->createIndex($indexName);

                $this->waitForTask($task);
            } catch (Throwable) {
                // Already exists
            }

            $clientIndex = $this->getIndex($indexName);

            $maxValuesPerFacet = (int) ($index['maxValuesPerFacet'] ?? 500);
            if ($maxValuesPerFacet > 0) {
                $task = $clientIndex->updateFaceting(['maxValuesPerFacet' => $maxValuesPerFacet]);

                $this->waitForTask($task);
            }

            if ($index['filterable']) {
                $task = $clientIndex->updateFilterableAttributes($index['filterable']);

                $this->waitForTask($task);
            }

            if (!empty($index['sortable'])) {
                $task = $clientIndex->updateSortableAttributes($index['sortable']);

                $this->waitForTask($task);
            }
        }
    }

    /**
     * @param string $indexName
     * @param array $settings
     * @return array
     */
    public function createIndex(string $indexName, array $settings = ['primaryKey' => 'objectID']): array
    {
        return $this->withMeilisearch(fn (Client $adminClient) => $adminClient->createIndex($indexName, $settings));
    }

    /**
     * @param string $indexName
     * @param array $documents
     * @return void
     * @throws ApiException
     * @throws Throwable
     */
    public function addDocuments(string $indexName, array $documents): void
    {
        if (!$documents) {
            return;
        }

        try {
            $task = $this->getIndex($indexName)->addDocuments($documents, 'objectID');

            $this->waitForTask($task);
        } catch (ApiException $exception) {
            $message = $exception->getMessage();

            // 1) Index missing → create it and retry once
            if (str_contains($message, 'index_not_found') || 404 === $exception->httpStatus) {
                Craft::getLogger()->log("Meilisearch index '{$indexName}' missing. Creating and retrying addDocuments...", Logger::LEVEL_WARNING, 'meilisearch-editor');

                try {
                    $create = $this->createIndex($indexName);

                    $this->waitForTask($create);

                    $task = $this->getIndex($indexName)->addDocuments($documents, 'objectID');

                    $this->waitForTask($task);

                    return;
                } catch (Throwable $anotherException) {
                    Craft::getLogger()->log("Failed to create index '{$indexName}' or add documents after create: {$anotherException->getMessage()}", Logger::LEVEL_ERROR, 'meilisearch-editor');

                    throw $anotherException; // fail the job
                }
            }

            // 2) Payload too large → chunk and retry
            if ((413 === $exception->httpStatus) || str_contains($message, 'payload_too_large') || str_contains($message, 'Payload too large')) {
                Craft::getLogger()->log("Payload too large for '{$indexName}'. Chunking and retrying...", Logger::LEVEL_WARNING, 'meilisearch-editor');

                $chunks = array_chunk($documents, 1000);
                foreach ($chunks as $i => $chunk) {
                    try {
                        $task = $this->getIndex($indexName)->addDocuments($chunk, 'objectID');

                        $this->waitForTask($task);
                    } catch (Throwable $exceptionChunk) {
                        Craft::getLogger()->log("Chunk {$i} failed for '{$indexName}': {$exceptionChunk->getMessage()}", Logger::LEVEL_ERROR, 'meilisearch-editor');

                        throw $exceptionChunk; // fail the job
                    }
                }

                return;
            }

            // 3) Other API errors → log + rethrow
            Craft::getLogger()->log("addDocuments failed for '{$indexName}': {$message}", Logger::LEVEL_ERROR, 'meilisearch-editor');

            throw $exception;
        } catch (Throwable $exception) {
            // Non-API exceptions (network, php, etc.)
            Craft::getLogger()->log("addDocuments unexpected error for '{$indexName}': {$exception->getMessage()}", Logger::LEVEL_ERROR, 'meilisearch-editor');

            throw $exception;
        }
    }

    /**
     * @param string $uid
     * @return void
     */
    public function deleteAllDocumentsByUid(string $uid): void
    {
        try {
            $task = $this->getIndex($uid)->deleteAllDocuments();

            $this->waitForTask($task);
        } catch (Throwable $exception) {
            Craft::getLogger()->log("deleteAllDocumentsByUid failed for '{$uid}': {$exception->getMessage()}", Logger::LEVEL_ERROR, 'meilisearch-editor');

            // swallow the 404 to keep UX smooth
        }
    }

    /**
     * @param array $index
     * @return void
     */
    public function deleteAllDocuments(array $index): void
    {
        foreach ($this->indexConfigHelper->indexNames($index) as $indexName) {
            $this->deleteAllDocumentsByUid($indexName);
        }
    }

    /**
     * @param array $index
     * @param int $entryId
     * @param int $siteId
     * @return void
     */
    public function deleteDocuments(array $index, int $entryId, int $siteId): void
    {
        foreach ($this->indexConfigHelper->indexNames($index) as $indexName) {
            try {
                $task = $this->getIndex($indexName)->deleteDocuments([$entryId . '-' . $siteId]);

                $this->waitForTask($task);
            } catch (Throwable $exception) {
                Craft::getLogger()->log("deleteDocuments failed for '{$indexName}': {$exception->getMessage()}", Logger::LEVEL_ERROR, 'meilisearch-editor');

                // swallow the 404 to keep UX smooth
            }
        }
    }

    /**
     * @param array $index
     * @return void
     */
    public function deleteIndexByIndex(array $index): void
    {
        foreach ($this->indexConfigHelper->indexNames($index) as $indexName) {
            $this->deleteIndexByUid($indexName);
        }
    }

    /**
     * @param string $uid
     * @return void
     */
    public function deleteIndexByUid(string $uid): void
    {
        try {
            $task = $this->deleteIndex($uid);

            $this->waitForTask($task);
        } catch (Throwable $exception) {
            Craft::getLogger()->log("deleteIndex failed for '{$uid}': {$exception->getMessage()}", Logger::LEVEL_ERROR, 'meilisearch-editor');

            // swallow the 404 to keep UX smooth
        }
    }

    /**
     * @param string $uid
     * @return array
     */
    public function deleteIndex(string $uid): array
    {
        return $this->withMeilisearch(fn (Client $adminClient) => $adminClient->deleteIndex($uid));
    }

    /**
     * Safely execute a Meilisearch operation.
     *
     * @param callable $fn
     * @param string $api
     * @param mixed $fallback
     * @return mixed
     */
    private function withMeilisearch(callable $fn, string $api = 'admin', mixed $fallback = []): mixed
    {
        $this->lastError = null;

        try {
            if ($api === 'search') {
                return $fn($this->searchClient());
            }

            return $fn($this->adminClient());
        } catch (CommunicationException $exception) {
            // Network/DNS/connectivity issues
            $this->lastError = 'Meilisearch connection error: ' . $exception->getMessage();

            Craft::getLogger()->log($this->lastError, Logger::LEVEL_WARNING, 'meilisearch-editor');

            return $fallback;
        } catch (ApiException $exception) {
            // HTTP-level Meili errors (4xx/5xx with JSON body)
            $this->lastError = 'Meilisearch API error: ' . $exception->getMessage();

            Craft::getLogger()->log($this->lastError, Logger::LEVEL_WARNING, 'meilisearch-editor');

            return $fallback;
        } catch (Throwable $exception) {
            // Anything else so our plugin never fatals
            $this->lastError = 'Meilisearch unexpected error: ' . $exception->getMessage();

            Craft::getLogger()->log($this->lastError, Logger::LEVEL_ERROR, 'meilisearch-editor');

            return $fallback;
        }
    }

    /**
     * @param array $task
     * @return void
     */
    private function waitForTask(array $task): void
    {
        if ($task && isset($task['taskUid'])) {
            $this->withMeilisearch(fn (Client $adminClient) => $adminClient->waitForTask($task['taskUid']));
        }
    }

    /**
     * Only log when status changes (up<->down).
     *
     * @param bool $isOk
     * @return void
     */
    private function logIfStatusChanged(bool $isOk): void
    {
        $cache = Craft::$app->getCache();

        $key = self::HEALTH_CACHE_LAST_STATUS_KEY . $this->getHostFingerprint();

        $prev = $cache->get($key);

        if ($prev === false || (bool) $prev !== $isOk) {
            // Status changed — write one log
            if ($isOk) {
                Craft::getLogger()->log('Meilisearch is reachable.', Logger::LEVEL_INFO, 'meilisearch-editor');
            } else {
                // Include lastError if we captured it
                $message = $this->lastError ?: 'Meilisearch is not reachable.';

                Craft::getLogger()->log($message, Logger::LEVEL_WARNING, 'meilisearch-editor');
            }

            // Persist the new state with a longer TTL (e.g., 1 day)
            $cache->set($key, $isOk, 86400);
        }
    }

    /**
     * @return string
     */
    private function getHostFingerprint(): string
    {
        // Use host + admin key so switching environments/settings isolates the cache
        return sha1($this->getHost() . '|' . $this->getAdminKey());
    }

    /**
     * @return string
     */
    private function getHealthCacheKey(): string
    {
        return $this->getHostFingerprint();
    }

    /**
     * @return string
     */
    private function getSettingsTag(): string
    {
        // Tag to bust caches if plugin settings change (see below)
        return 'meilisearch-settings-' . $this->getHealthCacheKey();
    }
}
