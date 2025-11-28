<?php

/**
 * Meilisearch Editor for Craft CMS.
 *
 * @author        DelaneyMethod
 * @copyright     Copyright (c) 2025
 *
 * @see           https://github.com/delaneymethod/craft-meilisearch-editor
 */

namespace delaneymethod\meilisearcheditor\console\controllers;

use Craft;
use delaneymethod\meilisearcheditor\MeilisearchEditor;
use Throwable;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Manages Meilisearch Indexes.
 */
class IndexController extends Controller
{
    /**
     * @var string
     */
    public string $handle = '';

    /**
     * @var bool
     */
    public bool $dryRun = true;

    /**
     * @param $actionID
     * @return string[]
     */
    public function options($actionID): array
    {
        return ['dryRun', 'handle'];
    }

    /**
     * @return string[]
     */
    public function optionAliases(): array
    {
        return ['h' => 'handle'];
    }

    /**
     * Add: normalize boolean options reliably
     *
     * @param $action
     * @return bool
     */
    public function beforeAction($action): bool
    {
        // Accept 1/0/true/false/yes/no
        $this->dryRun = filter_var($this->dryRun, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);

        return parent::beforeAction($action);
    }

    /**
     * Rebuilds index.
     *
     * Example: craft meilisearch-editor/indexes/reindex --handle=devices --dryRun=1
     *
     * @return int
     */
    public function actionReindex(): int
    {
        return $this->preflight(
            callback: fn (array $index) => MeilisearchEditor::$plugin->indexes->rebuildIndex($index['handle']),
            callbackMessage: fn (string $handle) => Craft::t('meilisearch-editor', "Meilisearch index '{handle}' re-indexed.", ['handle' => $handle]),
        );
    }

    /**
     * Flushes index documents.
     *
     * Example: craft meilisearch-editor/indexes/flush --handle=devices --dryRun=1
     *
     * @return int
     */
    public function actionFlush(): int
    {
        return $this->preflight(
            callback: fn (array $index) => MeilisearchEditor::$plugin->client->deleteAllDocuments($index),
            callbackMessage: fn (string $handle) => Craft::t('meilisearch-editor', "Meilisearch index '{handle}' flushed.", ['handle' => $handle]),
        );
    }

    /**
     * Deletes index.
     *
     * Example: craft meilisearch-editor/indexes/delete --handle=devices --dryRun=1
     *
     * @return int
     */
    public function actionDelete(): int
    {
        return $this->preflight(
            callback: fn (array $index) => MeilisearchEditor::$plugin->client->deleteIndexByIndex($index),
            callbackMessage: fn (string $handle) => Craft::t('meilisearch-editor', "Meilisearch index '{handle}' deleted.", ['handle' => $handle]),
        );
    }

    /**
     * Reads "handle" from command
     * Verifies index exists and is enabled
     * Runs $callback() and wraps exceptions into stderror
     *
     * @param callable $callback
     * @param callable|string $callbackMessage
     * @return int
     */
    private function preflight(callable $callback, callable|string $callbackMessage): int
    {
        if (!$this->handle) {
            $this->stdout(Craft::t('meilisearch-editor', 'Required --handle param was missing.'));

            return ExitCode::OK;
        }

        $index = MeilisearchEditor::$plugin->indexes->getIndex($this->handle);
        if (!$index) {
            $this->stdout(Craft::t('meilisearch-editor', "Meilisearch index '{handle}' was not found.", ['handle' => $this->handle]));

            return ExitCode::OK;
        }

        if (empty($index['enabled'])) {
            $this->stderr(Craft::t('meilisearch-editor', "Meilisearch index '{handle}' was not enabled.", ['handle' => $index['handle']]));

            return ExitCode::OK;
        }

        try {
            if (!$this->dryRun) {
                $callback($index);
            }
        } catch (Throwable $exception) {
            $message = is_callable($callbackMessage) ? $callbackMessage($this->handle) : $callbackMessage;

            $this->stderr($message . ': ' . $exception->getMessage());

            return ExitCode::OK;
        }

        $message = is_callable($callbackMessage) ? $callbackMessage($this->handle) : $callbackMessage;

        $this->stdout($message);

        return ExitCode::OK;
    }
}
