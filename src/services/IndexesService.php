<?php

namespace delaneymethod\meilisearcheditor\services;

use Craft;
use craft\base\Component;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\errors\InvalidFieldException;
use craft\errors\SiteNotFoundException;
use delaneymethod\meilisearcheditor\helpers\IndexConfigHelper;
use delaneymethod\meilisearcheditor\MeilisearchEditor;
use Throwable;
use yii\log\Logger;

class IndexesService extends Component
{
    public function __construct(private IndexConfigHelper $indexConfigHelper)
    {
        parent::__construct();
    }

    /**
     * @return array
     */
    public function getIndexesFromProjectConfig(): array
    {
        $indexes = [];

        $rawIndexes = Craft::$app->getProjectConfig()->get($this->indexConfigHelper->getProjectConfigKey()) ?? [];
        foreach ($rawIndexes as $indexKey => $index) {
            // derive base handle from config or key
            $handle = $index['handle'] ?? preg_replace('/_site\d+$/', '', (string) $indexKey);
            $index['handle'] = $handle;

            // if the *key* had a site suffix, fold it into siteIds
            if (preg_match('/^(.*)_site(\d+)$/', (string) $indexKey, $matches)) {
                $index['siteAware'] = true;

                $index['siteIds'] = array_values(array_unique(array_merge($index['siteIds'] ?? [], [(int) $matches[2]])));
                if (empty($index['siteId'])) {
                    $index['siteId'] = (int) $matches[2];
                }
            }

            // merge into base handle bucket
            if (isset($indexes[$handle])) {
                $dst = &$indexes[$handle];

                // union site awareness
                $dst['siteAware'] = !empty($dst['siteAware']) || !empty($index['siteAware']);

                $dst['siteIds'] = array_values(array_unique(array_merge($dst['siteIds'] ?? [], $index['siteIds'] ?? [])));
                if (empty($dst['siteId']) && !empty($index['siteId'])) {
                    $dst['siteId'] = $index['siteId'];
                }

                // prefer non-empty arrays from the incoming index
                foreach (['sections', 'entryTypes', 'fields', 'imageTransforms', 'attributes', 'filterable', 'sortable'] as $key) {
                    if (!empty($index[$key])) {
                        $dst[$key] = $index[$key];
                    }
                }
            } else {
                $indexes[$handle] = $index;
            }
        }

        // ensure no legacy keys leak out - belt-and-suspenders check
        foreach (array_keys($indexes) as $key) {
            if (preg_match('/_site\d+$/', (string) $key)) {
                unset($indexes[$key]);
            }
        }

        return $indexes;
    }

    /**
     * @param string $handle
     * @return void
     */
    public function deleteIndexesFromProjectConfig(string $handle): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $projectConfigKey = $this->indexConfigHelper->getProjectConfigKey();

        $indexes = $projectConfig->get($projectConfigKey) ?? [];
        foreach (array_keys($indexes) as $key) {
            if (preg_match('/^' . preg_quote($handle, '/') . '_site\d+$/', (string) $key)) {
                $projectConfig->remove($projectConfigKey . '.' . $key);
            }
        }
    }

    /**
     * @return array
     */
    public function getAllIndexes(): array
    {
        $indexesFromMeilisearch = MeilisearchEditor::$plugin->client->getAllIndexes(); // runtime info, base handles only

        $indexesFromProjectConfig = $this->getIndexesFromProjectConfig(); // source of truth, cleaned, base handles only

        $indexes = $indexesFromProjectConfig;

        // merge by handle; keep Project Config arrays like sections/entryTypes/fields
        foreach ($indexesFromMeilisearch as $handle => $fromMeili) {
            if (!isset($indexes[$handle])) {
                // Handle exists in Meilisearch but not Project Config (rare)
                $indexes[$handle] = $fromMeili;

                continue;
            }

            // Overlay only runtime fields
            if (isset($fromMeili['totalDocuments'])) {
                $indexes[$handle]['totalDocuments'] = (int) $fromMeili['totalDocuments'];
            }

            if (isset($fromMeili['perSiteDocuments'])) {
                $indexes[$handle]['perSiteDocuments'] = $fromMeili['perSiteDocuments'];
            }

            if (isset($fromMeili['maxValuesPerFacet'])) {
                $indexes[$handle]['maxValuesPerFacet'] = (int) $fromMeili['maxValuesPerFacet'];
            }

            // final normalization
            $indexes[$handle] = $this->indexConfigHelper->normalize($indexes[$handle]);
        }

        return $indexes;
    }

    /**
     * @param string $handle
     * @return array|null
     */
    public function getIndex(string $handle): ?array
    {
        $indexes = $this->getAllIndexes();

        return $indexes[$handle] ?? null;
    }

    /**
     * @param int $entryId
     * @param int|null $siteId
     * @return void
     * @throws InvalidFieldException
     */
    public function upsertEntry(int $entryId, ?int $siteId = null): void
    {
        $entry = Entry::find()->id($entryId)->siteId($siteId)->one();
        if (!$entry) {
            return;
        }

        $indexes = $this->indexesForEntry($entry);

        foreach ($indexes as $index) {
            // Normalize to ensure arrays/flags are correct
            $index = $this->indexConfigHelper->normalize($index);

            $configForEntryType = $this->indexConfigHelper->getConfigForEntryType($index, $entry);
            $config = [
                'attributes' => (array) ($index['attributes'] ?? []),
                'fields' => $configForEntryType['fields'],
                'fieldsNested' => $configForEntryType['fieldsNested'],
                'imageTransforms' => $configForEntryType['imageTransforms'],
            ];

            $document = MeilisearchEditor::$plugin->mapper->entryToDocument($entry, $config);
            if ($document) {
                foreach ($this->indexConfigHelper->indexNames($index) as $indexName) {
                    if (!empty($index['siteAware'])) {
                        if (!preg_match('/_site(\d+)$/', $indexName, $matches) || (int) $matches[1] !== (int) $entry->siteId) {
                            continue;
                        }
                    }

                    try {
                        MeilisearchEditor::$plugin->client->addDocuments($indexName, [$document]);
                    } catch (Throwable $exception) {
                        Craft::getLogger()->log("upsertEntry failed for '{$indexName}': {$exception->getMessage()}", Logger::LEVEL_ERROR, 'meilisearch-editor');
                    }
                }
            }
        }
    }

    /**
     * @param int $entryId
     * @param int|null $siteId
     * @return void
     */
    public function deleteEntry(int $entryId, ?int $siteId = null): void
    {
        foreach ($this->getAllIndexes() as $index) {
            if (empty($index['enabled'])) {
                continue;
            }

            $siteIds = !$index['siteAware'] ? [$index['siteId'] ?? null] : ($index['siteIds'] ?: array_map(fn ($site) => $site->id, Craft::$app->sites->getAllSites()));

            foreach ($siteIds as $loopSiteId) {
                if (null !== $siteId && (int) $loopSiteId !== $siteId) {
                    continue;
                }

                try {
                    MeilisearchEditor::$plugin->client->deleteDocuments($index, $entryId, (int) $loopSiteId);
                } catch (Throwable $exception) {
                    Craft::getLogger()->log("deleteEntry failed for '{$index['handle']}': {$exception->getMessage()}", Logger::LEVEL_ERROR, 'meilisearch-editor');

                    // swallow the 404 to keep UX smooth
                }
            }
        }
    }

    /**
     * @param string $handle
     * @return void
     * @throws InvalidFieldException
     * @throws SiteNotFoundException
     */
    public function rebuildIndex(string $handle): void
    {
        $index = $this->getIndex($handle);
        if (!$index) {
            return;
        }

        if (empty($index['enabled'])) {
            return;
        }

        // If sections key exists and is an empty array, do not index anything
        if (array_key_exists('sections', $index) && is_array($index['sections']) && count($index['sections']) === 0) {
            Craft::getLogger()->log("Index '{$handle}': sections empty -> indexing skipped.", Logger::LEVEL_INFO, 'meilisearch-editor');

            return;
        }

        // for non–site-aware indexes, use the single configured siteId
        $siteIds = !empty($index['siteAware']) ? (array) ($index['siteIds'] ?? []) : [(int) ($index['siteId'] ?? Craft::$app->sites->getPrimarySite()->id)];

        // If site-aware but nothing selected, fall back to all sites
        if (!empty($index['siteAware']) && !$siteIds) {
            $siteIds = array_map(fn ($site) => $site->id, Craft::$app->sites->getAllSites());
        }

        foreach ($siteIds as $siteId) {
            $indexName = $this->indexConfigHelper->indexName($index, (int) $siteId);

            // Clear first so stale docs (from unselected sections) are removed
            try {
                MeilisearchEditor::$plugin->client->deleteAllDocumentsByUid($indexName);
            } catch (Throwable $exception) {
                Craft::getLogger()->log("deleteAllDocumentsByUid failed for '{$indexName}': {$exception->getMessage()}", Logger::LEVEL_WARNING, 'meilisearch-editor');
            }

            $query = $this->buildQuery($index, (int) $siteId);

            $total = $query->count();
            $batch = 500;

            for ($offset = 0; $offset < $total; $offset += $batch) {
                // @var Entry[] $entries
                $entries = (clone $query)->offset($offset)->limit($batch)->all();

                $documents = array_map(function (Entry $entry) use ($index) {
                    $configForEntryType = $this->indexConfigHelper->getConfigForEntryType($index, $entry);
                    $config = [
                        'attributes' => (array) ($index['attributes'] ?? []),
                        'fields' => $configForEntryType['fields'],
                        'fieldsNested' => $configForEntryType['fieldsNested'],
                        'imageTransforms' => $configForEntryType['imageTransforms'],
                    ];

                    return MeilisearchEditor::$plugin->mapper->entryToDocument($entry, $config);
                }, $entries);

                if ($documents) {
                    try {
                        MeilisearchEditor::$plugin->client->addDocuments($indexName, $documents);
                    } catch (Throwable $exception) {
                        Craft::getLogger()->log("rebuildIndex failed for '{$indexName}': {$exception->getMessage()}", Logger::LEVEL_ERROR, 'meilisearch-editor');
                    }
                }
            }
        }
    }

    /**
     * @param Entry $entry
     * @return array
     */
    public function siteIdsForPropagation(Entry $entry): array
    {
        $siteIds = [];

        try {
            foreach ($entry->getSupportedSites() as $site) {
                $siteIds[] = is_array($site) ? (int) ($site['siteId'] ?? 0) : (int) $site;
            }
        } catch (Throwable) {
            $siteIds = array_map(fn ($site) => (int) $site->id, Craft::$app->sites->getAllSites());
        }

        return array_values(array_filter(array_unique($siteIds)));
    }

    /**
     * @param Entry $entry
     * @return array
     */
    private function indexesForEntry(Entry $entry): array
    {
        $indexes = [];

        foreach ($this->getAllIndexes() as $index) {
            if (empty($index['enabled'])) {
                continue;
            }

            // If sections key exists and is an empty array, skip this index entirely
            if (array_key_exists('sections', $index) && is_array($index['sections']) && count($index['sections']) === 0) {
                continue;
            }

            // Section gate (empty = all)
            $sections = (array) ($index['sections'] ?? []);
            if ($sections) {
                $first = reset($sections);

                if (false !== $first && ctype_digit((string) $first)) {
                    // Index stores section IDs
                    $match = in_array((int) $entry->sectionId, array_map('intval', $sections), true);
                } else {
                    // Index stores section handles
                    $entryHandle = $entry->section?->handle ?? null;

                    $match = null !== $entryHandle && in_array($entryHandle, array_map('strval', $sections), true);
                }

                if (!$match) {
                    continue;
                }
            }

            // Entry type gate (['*'] = all)
            $entryTypes = (array) ($index['entryTypes'] ?? ['*']);
            if ($entryTypes !== ['*']) {
                $first = reset($entryTypes);

                if (false !== $first && ctype_digit((string) $first)) {
                    // Index stores entry type IDs
                    $match = in_array($entry->typeId, array_map('intval', $entryTypes), true);
                } else {
                    // Index stores handles namespaced "section.entryType"
                    $entryHandle = Craft::$app->entries->getEntryTypeById($entry->typeId)?->handle;

                    // Normalize all configured entry types to plain handles
                    $configuredEntryTypeHandles = array_values(array_filter(array_map(function ($entryType) {
                        // Support both "section.handle" and plain "handle"
                        $parts = array_values(array_filter(explode('.', $entryType), 'strlen'));

                        return $parts ? end($parts) : $entryType;
                    }, $entryTypes), 'strlen'));

                    $match = $entryHandle !== null && in_array($entryHandle, $configuredEntryTypeHandles, true);
                }

                if (!$match) {
                    continue;
                }
            }

            $indexes[] = $index;
        }

        return $indexes;
    }

    /**
     * @param array $index
     * @param int|null $siteId
     * @return EntryQuery
     */
    private function buildQuery(array $index, ?int $siteId): EntryQuery
    {
        $query = Entry::find()->limit(0);

        if ($siteId) {
            $query->siteId($siteId);
        }

        if (!empty($index['sections'])) {
            if (count($index['sections']) && ctype_digit((string) $index['sections'][0])) {
                $query->sectionId(array_map('intval', $index['sections']));
            } else {
                $query->section($index['sections']);
            }
        }

        $entryTypes = (array) ($index['entryTypes'] ?? ['*']);
        if ($entryTypes !== ['*']) {
            $handles = array_map(function ($entryType) {
                $parts = explode('.', (string) $entryType);

                return (string) end($parts);
            }, $entryTypes);

            $handles = array_values(array_filter(array_unique($handles), 'strlen'));
            if ($handles) {
                $query->type($handles);
            }
        }

        return $query;
    }
}
