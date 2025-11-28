<?php

/**
 * Meilisearch Editor for Craft CMS.
 *
 * @author        DelaneyMethod
 * @copyright     Copyright (c) 2025
 *
 * @see           https://github.com/delaneymethod/craft-meilisearch-editor
 */

namespace delaneymethod\meilisearcheditor\jobs;

use Craft;
use craft\errors\InvalidFieldException;
use craft\queue\BaseJob;
use delaneymethod\meilisearcheditor\MeilisearchEditor;

class UpsertEntryJob extends BaseJob
{
    /**
     * @var int
     */
    public int $entryId;

    /**
     * @var int|null
     */
    public ?int $siteId = null;

    /**
     * @param $queue
     * @return void
     * @throws InvalidFieldException
     */
    public function execute($queue): void
    {
        MeilisearchEditor::$plugin->indexes->upsertEntry($this->entryId, $this->siteId);
    }

    /**
     * @return string
     */
    protected function defaultDescription(): string
    {
        return Craft::t('meilisearch-editor', 'Upsert document into Meilisearch');
    }
}
