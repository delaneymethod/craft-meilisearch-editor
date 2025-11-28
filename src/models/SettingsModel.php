<?php

/**
 * Meilisearch Editor for Craft CMS.
 *
 * @author        DelaneyMethod
 * @copyright     Copyright (c) 2025
 *
 * @see           https://github.com/delaneymethod/craft-meilisearch-editor
 */

namespace delaneymethod\meilisearcheditor\models;

use craft\base\Model;

class SettingsModel extends Model
{
    /**
     * @var string
     */
    public string $host = '$MEILISEARCH_HOST';

    /**
     * @var string
     */
    public string $adminKey = '$MEILISEARCH_ADMIN_KEY';

    /**
     * @var string
     */
    public string $searchKey = '$MEILISEARCH_SEARCH_KEY';

    /**
     * @return array[]
     */
    public function rules(): array
    {
        return [
            [['host', 'adminKey', 'searchKey'], 'required'],
        ];
    }
}
