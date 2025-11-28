<?php

/**
 * Meilisearch Editor for Craft CMS.
 *
 * @author        DelaneyMethod
 * @copyright     Copyright (c) 2025
 *
 * @see           https://github.com/delaneymethod/craft-meilisearch-editor
 */

namespace delaneymethod\meilisearcheditor\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class EditIndexAsset extends AssetBundle
{
    /**
     * @return void
     */
    public function init(): void
    {
        $this->sourcePath = '@delaneymethod/meilisearcheditor/resources';

        $this->depends = [CpAsset::class];

        $this->js = ['js/indexes/edit.js'];

        parent::init();
    }
}
