<?php

/**
 * Meilisearch Editor for Craft CMS.
 *
 * @author        DelaneyMethod
 * @copyright     Copyright (c) 2025
 *
 * @see           https://github.com/delaneymethod/craft-meilisearch-editor
 */

namespace delaneymethod\meilisearcheditor\variables;

use Craft;
use craft\elements\Category;
use craft\fields\Categories;
use delaneymethod\meilisearcheditor\MeilisearchEditor;

class MeilisearchEditorVariable
{
    public function getIndex(string $handle): ?array
    {
        return MeilisearchEditor::getInstance()->indexes->getIndex($handle);
    }

    public function getFilters(string $indexHandle): array
    {
        $index = $this->getIndex($indexHandle);
        if (!$index) {
            return [];
        }

        if (!$index['filterable']) {
            return [];
        }

        $filters = [];
        foreach ($index['filterable'] as $namespace) {
            $parts = explode('.', $namespace);
            $handle = array_pop($parts);

            $field = Craft::$app->getFields()->getFieldByHandle($handle);
            if (!$field) {
                continue;
            }

            if (!$field instanceof Categories) {
                continue;
            }

            $groupUid = substr($field->source, strlen('group:'));
            $group = Craft::$app->getCategories()->getGroupByUid($groupUid);
            if (!$group) {
                continue;
            }

            $options = array_map(function ($category) {
                return (object) [
                    'value' => $category->slug,
                    'label' => $category->title,
                ];
            }, Category::find()->groupId($group->id)->orderBy('lft ASC')->all());

            $filters[] = [
                'id' => $field->handle,
                'name' => $field->name,
                'options' => $options,
            ];
        }

        return $filters;
    }

    public function getSortables(string $indexHandle): array
    {
        $index = $this->getIndex($indexHandle);
        if (!$index) {
            return [];
        }

        if (!$index['sortable']) {
            return [];
        }

        $sortablesMap = [
            'id' => 'ID',
            'uri' => 'URI',
            'slug' => 'Slug',
            'title' => 'Title',
            'siteId' => 'Site ID',
            'postDate' => 'Date Posted',
            'dateCreated' => 'Date Created',
            'dateUpdated' => 'Date Updated',
        ];

        $sortables = [];
        foreach ($index['sortable'] as $sortable) {
            if (array_key_exists($sortable, $sortablesMap)) {
                $sortables[] = (object) [
                    'value' => "{$sortable}:asc",
                    'label' => Craft::t('meilisearch-editor', $sortablesMap[$sortable]),
                ];

                $sortables[] = (object) [
                    'value' => "{$sortable}:desc",
                    'label' => Craft::t('meilisearch-editor', "{$sortablesMap[$sortable]} (Desc)"),
                ];
            }
        }

        return $sortables;
    }
}
