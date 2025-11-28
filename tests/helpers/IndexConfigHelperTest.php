<?php

/**
 * Meilisearch Editor for Craft CMS.
 *
 * @author        DelaneyMethod
 * @copyright     Copyright (c) 2025
 *
 * @see           https://github.com/delaneymethod/craft-meilisearch-editor
 */

namespace delaneymethod\meilisearcheditor\tests\helpers;

use delaneymethod\meilisearcheditor\helpers\IndexConfigHelper;
use PHPUnit\Framework\TestCase;

final class IndexConfigHelperTest extends TestCase
{
    private IndexConfigHelper $indexConfigHelper;

    protected function setUp(): void
    {
        $this->indexConfigHelper = new IndexConfigHelper();
    }

    public function test_parent_fields_only(): void
    {
        $index = [
            'fields' => [
                'article' => [
                    'news.article.title',
                    'news.article.heroImage',
                ],
            ],
            'imageTransforms' => [],
            'attributes' => [
                'id',
            ],
        ];

        $config = $this->indexConfigHelper->getConfigForHandles($index, 'article', 'news');

        $this->assertSame(['title', 'heroImage'], $config['fields']);
        $this->assertSame([], $config['fieldsNested']);
        $this->assertSame([], $config['imageTransforms']);
    }

    public function test_nested_matrix_like(): void
    {
        $index = [
            'fields' => [
                'article' => [
                    'news.article.contentMatrix',
                    'news.article.contentMatrix.textBlock.body',
                    'news.article.contentMatrix.gallery.images',
                ],
            ],
            'imageTransforms' => [],
        ];

        $config = $this->indexConfigHelper->getConfigForHandles($index, 'article', 'news');

        $this->assertSame(['contentMatrix'], $config['fields']);
        $this->assertSame([
            'contentMatrix' => [
                'textBlock' => [
                    'body',
                ],
                'gallery' => [
                    'images',
                ],
            ],
        ], $config['fieldsNested']);
    }

    public function test_multiple_sections_and_transforms(): void
    {
        $index = [
            'fields' => [
                'article' => [
                    'news.article.heroImage',
                    'press.article.heroImage',
                ],
            ],
            'imageTransforms' => [
                'news.article.heroImage' => [
                    'craft:thumb',
                ],
                'press.article.heroImage' => [
                    'imager-x:hero',
                ],
            ],
        ];

        $configNews = $this->indexConfigHelper->getConfigForHandles($index, 'article', 'news');
        $configPress = $this->indexConfigHelper->getConfigForHandles($index, 'article', 'press');

        $this->assertSame(['heroImage'], $configNews['fields']);
        $this->assertSame([
            'heroImage' => [
                'craft:thumb',
            ],
        ], $configNews['imageTransforms']);

        $this->assertSame(['heroImage'], $configPress['fields']);
        $this->assertSame([
            'heroImage' => [
                'imager-x:hero',
            ],
        ], $configPress['imageTransforms']);
    }

    public function test_no_section_gate_includes_all_matching_when_null(): void
    {
        $index = [
            'fields' => [
                'article' => [
                    'news.article.heroImage',
                    'press.article.heroImage',
                ],
            ],
            'imageTransforms' => [
                'news.article.heroImage' => [
                    'craft:thumb',
                ],
                'press.article.heroImage' => [
                    'imager-x:hero',
                ],
            ],
        ];

        $config = $this->indexConfigHelper->getConfigForHandles($index, 'article', null);

        // Both sections’ parents are considered the same parent handle
        $this->assertSame(['heroImage'], $config['fields']);

        // And both transform sets appear when no section is supplied
        $this->assertEqualsCanonicalizing(
            [
                'craft:thumb',
                'imager-x:hero',
            ],
            $config['imageTransforms']['heroImage'],
        );
    }
}
