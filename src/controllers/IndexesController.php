<?php

/**
 * Meilisearch Editor for Craft CMS.
 *
 * @author        DelaneyMethod
 * @copyright     Copyright (c) 2025
 *
 * @see           https://github.com/delaneymethod/craft-meilisearch-editor
 */

namespace delaneymethod\meilisearcheditor\controllers;

use Craft;
use craft\base\Field;
use craft\errors\MissingComponentException;
use craft\errors\SiteNotFoundException;
use craft\fields\Categories;
use craft\web\Controller;
use delaneymethod\meilisearcheditor\helpers\IndexConfigHelper;
use delaneymethod\meilisearcheditor\MeilisearchEditor;
use Throwable;
use yii\base\InvalidConfigException;
use yii\log\Logger;
use yii\web\BadRequestHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

class IndexesController extends Controller
{
    /**
     * @var array<int|string>|bool|int
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * @var IndexConfigHelper
     */
    private IndexConfigHelper $indexConfigHelper;

    public function __construct($id, $module, $config = [])
    {
        parent::__construct($id, $module, $config);

        $this->indexConfigHelper = Craft::$container->get(IndexConfigHelper::class);
    }

    /**
     * @return Response
     */
    public function actionIndex(): Response
    {
        $indexes = MeilisearchEditor::$plugin->indexes->getAllIndexes();

        $isClientAvailable = MeilisearchEditor::$plugin->client->isAvailable();

        // health: handle => [ [siteId => int|null, uid => string, settings => array], ... ]
        $health = [];

        if ($isClientAvailable) {
            foreach ($indexes as $handle => $index) {
                $uids = $this->indexConfigHelper->indexNames($index);
                foreach ($uids as $uid) {
                    $siteId = null;
                    if (preg_match('/_site(\d+)$/', (string) $uid, $matches)) {
                        $siteId = (int) $matches[1];
                    }

                    $settings = MeilisearchEditor::$plugin->client->getSettings($uid);

                    $health[$handle][] = [
                        'uid' => $uid,
                        'siteId' => $siteId,
                        'filterable' => $settings['filterableAttributes'] ?? [],
                        'sortable' => $settings['sortableAttributes'] ?? [],
                    ];
                }
            }
        }

        return $this->renderTemplate('meilisearch-editor/indexes/index', [
            'indexes' => $indexes,
            'isClientAvailable' => $isClientAvailable,
            'healths' => $health,
        ]);
    }

    /**
     * @param string|null $handle
     * @return Response
     */
    public function actionEdit(?string $handle = null): Response
    {
        $defaults = [
            'label' => '',
            'handle' => '',
            'sections' => [],
            'entryTypes' => ['*'],
            'fields' => [],
            'imageTransforms' => [],
            'siteAware' => false,
            'siteId' => '',
            'siteIds' => [],
            'attributes' => [],
            'filterable' => [],
            'sortable' => [],
            'enabled' => true,
        ];

        $attributes = [
            'id' => 'ID',
            'uri' => 'URI',
            'slug' => 'Slug',
            'title' => 'Title',
            'siteId' => 'Site ID',
            'postDate' => 'Date Posted',
            'dateCreated' => 'Date Created',
            'dateUpdated' => 'Date Updated',
        ];

        $filterables = [];

        $sortables = [
            'id' => 'ID',
            'uri' => 'URI',
            'slug' => 'Slug',
            'title' => 'Title',
            'siteId' => 'Site ID',
            'postDate' => 'Date Posted',
            'dateCreated' => 'Date Created',
            'dateUpdated' => 'Date Updated',
        ];

        if ($handle) {
            $index = MeilisearchEditor::$plugin->indexes->getIndex($handle);
        } else {
            $index = $defaults;
        }

        $index = $this->indexConfigHelper->normalizeIndexForUi($index);

        $sites = Craft::$app->sites->getAllSites();

        $sections = Craft::$app->entries->getAllSections();

        // Get entry types + fields for selected section (for initial render)
        $fields = [];
        $entryTypes = [];

        foreach ($index['sections'] as $sectionHandle) {
            $section = Craft::$app->entries->getSectionByHandle($sectionHandle);
            if (!$section) {
                continue;
            }

            foreach (Craft::$app->entries->getEntryTypesBySectionId($section->id) as $entryType) {
                $layout = $entryType->getFieldLayout();

                $handles = [];

                $entryTypeName = $entryType->name;
                $entryTypeHandle = $entryType->getHandle();

                foreach ($layout->getCustomFields() as $field) {
                    if ($field instanceof Field) {
                        $handles[] = $field->handle;

                        if ($field instanceof Categories) {
                            $filterables[$sectionHandle . '.' . $entryTypeHandle . '.' . $field->handle] = $field->label ?? $field->name . ' (' . $section->name . ' &#8594; ' . $entryTypeName . ')';
                        }
                    }
                }

                $entryTypes[] = $entryType;

                $fields[$entryTypeHandle] = $handles;
            }
        }

        $imageTransforms = $index['imageTransforms'] ?? [];

        $isNew = empty($index['handle']);

        return $this->renderTemplate(
            'meilisearch-editor/indexes/_edit',
            [
                'isNew' => $isNew,
                'index' => $index,
                'sites' => $sites,
                'fields' => $fields,
                'sections' => $sections,
                'sortables' => $sortables,
                'entryTypes' => $entryTypes,
                'attributes' => $attributes,
                'filterables' => $filterables,
                'imageTransforms' => $imageTransforms,
            ],
        );
    }

    /**
     * UI Builder namespace patterns
     *
     * sections[]                          -> ['devices','homepage', ...]
     * entryTypes[]                        -> ['devices.devicesEntryType', 'homepage.entryType', ...]
     * fields[devicesEntryType][]          -> ['devices.devicesEntryType.asset', 'devices.devicesEntryType.matrix.entryType.plainText', ...]
     * imageTransforms[]                   -> ['devices.devicesEntryType.asset::craft:imageTransformFit', ...]
     *
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws InvalidConfigException
     * @throws MethodNotAllowedHttpException
     * @throws MissingComponentException
     * @throws SiteNotFoundException
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        $handle = $request->getBodyParam('handle');

        // Pull previous config so we can keep values if POST omits them
        $prevIndex = MeilisearchEditor::$plugin->indexes->getIndex($handle) ?? null;

        [$sections, $entryTypesNamespaced, $fieldsNamespaced, $imageTransformsNamespaced] = $this->indexConfigHelper->parseUiBuilderConfig($request->getBodyParams(), $prevIndex);

        $siteAware = (bool) $request->getBodyParam('siteAware', $prevIndex['siteAware'] ?? false);
        $siteId = (int) $request->getBodyParam('siteId', $prevIndex['siteId'] ?? 0);
        $siteIds = array_map('intval', (array) $request->getBodyParam('siteIds', $prevIndex['siteIds'] ?? []));

        // If not site-aware, ensure exactly one siteId
        if (!$siteAware) {
            if (!$siteId) {
                // Default to primary site if not provided
                $siteId = Craft::$app->sites->getPrimarySite()->id;
            }

            $siteIds = [$siteId];
        } else {
            // site-aware requires at least one site
            if (!$siteIds) {
                $this->setFailFlash(Craft::t('meilisearch-editor', 'Select at least one site for site-aware indexes.'));

                return $this->redirectToPostedUrl();
            }
        }

        $index = [
            'label' => $request->getBodyParam('label'),
            'handle' => $handle,
            'sections' => $sections,
            'entryTypes' => $entryTypesNamespaced,
            'fields' => $fieldsNamespaced,
            'imageTransforms' => $imageTransformsNamespaced,
            'siteAware' => $siteAware,
            'siteId' => $siteId,
            'siteIds' => $siteIds,
            'attributes' => (array) $request->getBodyParam('attributes', $prevIndex['attributes'] ?? []),
            'filterable' => (array) $request->getBodyParam('filterable', $prevIndex['filterable'] ?? []),
            'sortable' => (array) $request->getBodyParam('sortable', $prevIndex['sortable'] ?? []),
            'enabled' => (bool) $request->getBodyParam('enabled', $prevIndex['enabled'] ?? true),
            'dateUpdated' => gmdate('c'),
        ];

        $index = $this->indexConfigHelper->normalize($index);

        $indexSettings = [
            'filterableAttributes' => $this->indexConfigHelper->leafAttributes($index['filterable']),
            'sortableAttributes' => $this->indexConfigHelper->leafAttributes($index['sortable']),
        ];

        // Purge legacy indexes like <handle>_siteN from Project Config and Meilisearch so they never come back
        $newUids = array_values(array_unique($this->indexConfigHelper->indexNames($index)));

        $existingUids = MeilisearchEditor::$plugin->client->getUidsByHandle($index['handle']);

        $deleteUids = array_values(array_diff($existingUids, $newUids));

        try {
            $isClientAvailable = MeilisearchEditor::$plugin->client->isAvailable();
            if ($isClientAvailable) {
                MeilisearchEditor::$plugin->client->save($index);
                MeilisearchEditor::$plugin->client->applyIndexSettings($index, $indexSettings);

                Craft::$app->projectConfig->set($this->indexConfigHelper->getProjectConfigKey() . '.' . $handle, $index);

                // remove <handle>_siteN keys
                MeilisearchEditor::$plugin->indexes->deleteIndexesFromProjectConfig($handle);

                // Meilisearch cleanup
                foreach ($deleteUids as $deleteUid) {
                    MeilisearchEditor::$plugin->client->deleteIndexByUid($deleteUid);
                }

                // Rebuild (Meilisearch only)
                MeilisearchEditor::$plugin->client->deleteAllDocuments($index);
                if ($index['enabled']) {
                    MeilisearchEditor::$plugin->indexes->rebuildIndex($handle);
                }

                $savedReindexed = Craft::t('meilisearch-editor', "Meilisearch index '{handle}' saved and re-indexed.", ['handle' => $handle]);
                $saved = Craft::t('meilisearch-editor', "Meilisearch index '{handle}' saved.", ['handle' => $handle]);
                $message = $index['enabled'] ? $savedReindexed : $saved;

                Craft::$app->getSession()->setNotice($message);
            } else {
                Craft::$app->projectConfig->set($this->indexConfigHelper->getProjectConfigKey() . '.' . $handle, $index);

                // remove <handle>_siteN keys
                MeilisearchEditor::$plugin->indexes->deleteIndexesFromProjectConfig($handle);

                Craft::$app->getSession()->setNotice(Craft::t('meilisearch-editor', "Project config for index '{handle}' saved.", ['handle' => $handle]));
            }
        } catch (Throwable $exception) {
            $message = Craft::t('meilisearch-editor', "Saving Meilisearch index '{handle}' failed: {exception}", [
                'handle' => $handle,
                'exception' => $exception->getMessage(),
            ]);

            Craft::getLogger()->log($message, Logger::LEVEL_ERROR, 'meilisearch-editor');

            Craft::$app->getSession()->setError($message);
        }

        return $this->redirect('meilisearch-editor/indexes');
    }

    /**
     * @return Response
     * @throws MethodNotAllowedHttpException
     * @throws MissingComponentException
     */
    public function actionReindex(): Response
    {
        $this->requirePostRequest();

        $isClientAvailable = MeilisearchEditor::$plugin->client->isAvailable();
        if (!$isClientAvailable) {
            return $this->redirect('meilisearch-editor/indexes');
        }

        $handle = Craft::$app->getRequest()->getBodyParam('handle');

        $index = MeilisearchEditor::$plugin->indexes->getIndex($handle);
        if (!$index) {
            Craft::$app->getSession()->setError(Craft::t('meilisearch-editor', "Meilisearch index '{handle}' was not found.", ['handle' => $handle]));

            return $this->redirect('meilisearch-editor/indexes');
        }

        if (empty($index['enabled'])) {
            Craft::$app->getSession()->setError(Craft::t('meilisearch-editor', "Meilisearch index '{handle}' was not enabled.", ['handle' => $handle]));

            return $this->redirect('meilisearch-editor/indexes');
        }

        // Clear first so stale docs (from unselected sections) are removed
        try {
            MeilisearchEditor::$plugin->client->deleteAllDocumentsByUid($handle);
        } catch (Throwable $exception) {
            $message = Craft::t('meilisearch-editor', "Deleting all documents for Meilisearch index '{handle}' failed: {exception}", [
                'handle' => $handle,
                'exception' => $exception->getMessage(),
            ]);

            Craft::getLogger()->log($message, Logger::LEVEL_ERROR, 'meilisearch-editor');

            Craft::$app->getSession()->setError($message);
        }

        try {
            MeilisearchEditor::$plugin->indexes->rebuildIndex($handle);

            Craft::$app->getSession()->setNotice(Craft::t('meilisearch-editor', "Meilisearch index '{handle}' queued for reindexing.", ['handle' => $handle]));
        } catch (Throwable $exception) {
            $message = Craft::t('meilisearch-editor', "Reindexing Meilisearch index '{handle}' failed: {exception}", [
                'handle' => $handle,
                'exception' => $exception->getMessage(),
            ]);

            Craft::getLogger()->log($message, Logger::LEVEL_ERROR, 'meilisearch-editor');

            Craft::$app->getSession()->setError($message);
        }

        return $this->redirect('meilisearch-editor/indexes');
    }

    /**
     * @return Response
     * @throws MethodNotAllowedHttpException
     * @throws MissingComponentException
     */
    public function actionFlush(): Response
    {
        $this->requirePostRequest();

        $isClientAvailable = MeilisearchEditor::$plugin->client->isAvailable();
        if (!$isClientAvailable) {
            return $this->redirect('meilisearch-editor/indexes');
        }

        $handle = Craft::$app->getRequest()->getBodyParam('handle');

        $index = MeilisearchEditor::$plugin->indexes->getIndex($handle);
        if (!$index) {
            Craft::$app->getSession()->setError(Craft::t('meilisearch-editor', "Meilisearch index '{handle}' was not found.", ['handle' => $handle]));

            return $this->redirect('meilisearch-editor/indexes');
        }

        if (empty($index['enabled'])) {
            Craft::$app->getSession()->setError(Craft::t('meilisearch-editor', "Meilisearch index '{handle}' was not enabled.", ['handle' => $handle]));

            return $this->redirect('meilisearch-editor/indexes');
        }

        try {
            MeilisearchEditor::$plugin->client->deleteAllDocuments($index);

            Craft::$app->getSession()->setNotice(Craft::t('meilisearch-editor', "Meilisearch index '{handle}' flushed.", ['handle' => $handle]));
        } catch (Throwable $exception) {
            $message = Craft::t('meilisearch-editor', "Flushing Meilisearch index '{handle}' failed: {exception}", [
                'handle' => $handle,
                'exception' => $exception->getMessage(),
            ]);

            Craft::getLogger()->log($message, Logger::LEVEL_ERROR, 'meilisearch-editor');

            Craft::$app->getSession()->setError($message);
        }

        return $this->redirect('meilisearch-editor/indexes');
    }

    /**
     * @return Response
     * @throws MethodNotAllowedHttpException
     * @throws MissingComponentException
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $isClientAvailable = MeilisearchEditor::$plugin->client->isAvailable();
        if (!$isClientAvailable) {
            return $this->redirect('meilisearch-editor/indexes');
        }

        $handle = Craft::$app->getRequest()->getBodyParam('handle');

        $index = MeilisearchEditor::$plugin->indexes->getIndex($handle);
        if (!$index) {
            Craft::$app->getSession()->setError(Craft::t('meilisearch-editor', "Meilisearch index '{handle}' was not found.", ['handle' => $handle]));

            return $this->redirect('meilisearch-editor/indexes');
        }

        try {
            MeilisearchEditor::$plugin->client->deleteIndexByIndex($index);

            Craft::$app->projectConfig->remove($this->indexConfigHelper->getProjectConfigKey() . '.' . $handle);

            Craft::$app->getSession()->setNotice(Craft::t('meilisearch-editor', "Meilisearch index '{handle}' deleted.", ['handle' => $handle]));
        } catch (Throwable $exception) {
            $message = "Deleting Meilisearch index '{$handle}' failed: " . $exception->getMessage();

            Craft::getLogger()->log($message, Logger::LEVEL_ERROR, 'meilisearch-editor');

            Craft::$app->getSession()->setError($message);
        }

        return $this->redirect('meilisearch-editor/indexes');
    }
}
