<?php

/**
 * Meilisearch Editor for Craft CMS.
 *
 * @author        DelaneyMethod
 * @copyright     Copyright (c) 2025
 *
 * @see           https://github.com/delaneymethod/craft-meilisearch-editor
 */

namespace delaneymethod\meilisearcheditor\helpers;

use Craft;
use craft\elements\Entry;
use craft\services\ProjectConfig;

final class IndexConfigHelper
{
    private const PROJECT_CONFIG_KEY = ProjectConfig::PATH_PLUGINS . '.meilisearch-editor.indexes';

    /**
     * @return string
     */
    public static function getProjectConfigKey(): string
    {
        return self::PROJECT_CONFIG_KEY;
    }

    /**
     * @param array $post
     * @param array $prevIndex
     * @return array
     */
    public static function parseUiBuilderConfig(array $post, array $prevIndex): array
    {
        $sectionsRaw = (array) ($post['sections'] ?? $prevIndex['sections'] ?? []);
        $sections = array_values(array_unique(array_filter(array_map('strval', $sectionsRaw), 'strlen')));

        $entryTypesRaw = (array) ($post['entryTypes'] ?? $prevIndex['entryTypes'] ?? []);
        $entryTypes = array_values(array_unique(array_filter(array_map('strval', $entryTypesRaw), 'strlen')));

        // fields[devicesEntryType] = ["devices.devicesEntryType.field", "devices.devicesEntryType.matrix.block.nested", ...] – namespaced paths per entry type handle
        $fields = [];
        $fieldsRaw = (array) ($post['fields'] ?? $prevIndex['fields'] ?? []);
        foreach ($fieldsRaw as $entryTypeHandle => $paths) {
            $fields[$entryTypeHandle] = array_values(array_unique(array_filter(array_map('strval', (array) $paths), 'strlen')));
        }

        // imageTransforms[] – ["<namespacePath>::<source>:<handle>", ...] -> ["section.entryType.field" => ["source:handle", ...]]
        $imageTransformsRaw = array_values(array_filter(array_map('strval', (array) ($post['imageTransforms'] ?? [])), 'strlen'));
        $imageTransforms = is_array($prevIndex['imageTransforms'] ?? null) ? (array) $prevIndex['imageTransforms'] : [];
        if ($imageTransformsRaw) {
            $tmp = [];

            foreach ($imageTransformsRaw as $imageTransform) {
                [$namespacePath, $rest] = explode('::', $imageTransform, 2) + [null, null];
                if (!$namespacePath || !$rest) {
                    continue;
                }

                $tmp[$namespacePath] ??= [];
                if (!in_array($rest, $tmp[$namespacePath], true)) {
                    $tmp[$namespacePath][] = $rest;
                }
            }

            $imageTransforms = $tmp;
        }

        return [
            $sections,
            $entryTypes,
            $fields,
            $imageTransforms,
        ];
    }

    /**
     * @param array $index
     * @return array
     */
    public static function normalizeIndexForUi(array $index): array
    {
        // Basic hygiene
        $index['siteAware'] = (bool) ($index['siteAware'] ?? false);

        $index['siteId'] = (int) ($index['siteId'] ?? 0);
        $index['siteIds'] = array_values(array_unique(array_map('intval', (array) ($index['siteIds'] ?? []))));

        $index['sections'] = array_values(array_filter(array_map('strval', $index['sections'] ?? []), 'strlen'));

        // Entry Types
        $index['entryTypes'] = array_values(array_unique(array_filter((array) ($index['entryTypes'] ?? []), 'strlen')));
        $index['entryTypes'] = array_values(array_unique(array_filter($index['entryTypes'], fn ($entryType) => is_string($entryType) && $entryType !== '')));

        $index['fields'] = is_array($index['fields'] ?? null) ? $index['fields'] : [];

        $index['attributes'] = array_values(array_filter(array_map('strval', $index['attributes'] ?? []), 'strlen'));

        $index['filterable'] = self::normalizeFilterables($index);

        $index['sortable'] = array_values(array_filter(array_map('strval', $index['sortable'] ?? []), 'strlen'));

        // Image Transforms
        $imageTransforms = $index['imageTransforms'] ?? [];
        if (is_array($imageTransforms)) {
            // detect nested: first value is array of arrays, not a list of strings
            $first = reset($imageTransforms);

            $looksNested = is_array($first) && (is_array(reset($first)) || array_keys($first) !== range(0, count($first) - 1));
            if ($looksNested) {
                $index['imageTransforms'] = self::flattenImageTransformsLegacy($imageTransforms);
            }
            // else assume already flat namespaced; keep as-is
        } else {
            $index['imageTransforms'] = [];
        }

        return $index;
    }

    /**
     * @param array $index
     * @return array
     */
    public static function normalizeFilterables(array $index): array
    {
        $filterables = array_values(array_filter(array_map('strval', $index['filterable'] ?? []), static fn ($value) => $value !== '' && substr_count($value, '.') >= 2));
        $filterables = array_values(array_unique($filterables));
        if (!$filterables) {
            return [];
        }

        // Build a set of all namespaced field paths chosen in the UI
        $namespacedFieldPaths = [];
        foreach ((array) ($index['fields'] ?? []) as $paths) {
            foreach ((array) $paths as $path) {
                $path = (string) $path;
                if ($path !== '') {
                    $namespacedFieldPaths[$path] = true;
                }
            }
        }

        $out = [];

        foreach ($filterables as $filterable) {
            // already namespaced? (section.entryType.field => 3+ parts)
            $parts = array_values(array_filter(explode('.', $filterable), 'strlen'));
            if (count($parts) >= 3) {
                $out[$filterable] = true;

                continue;
            }

            // keep *every* selected path that ends with ".{$filterable}"
            foreach (array_keys($namespacedFieldPaths) as $path) {
                if (str_ends_with($path, '.' . $filterable)) {
                    $out[$path] = true;
                }
            }
        }

        $result = array_keys($out);

        sort($result);

        return $result;
    }

    /**
     * @param array $list
     * @return array
     */
    public static function leafAttributes(array $list): array
    {
        $list = array_map('strval', $list);
        $list = array_filter($list, 'strlen');

        $toLeaf = function (string $key): string {
            $position = strrpos($key, '.');

            return $position !== false ? substr($key, $position + 1) : $key;
        };

        return array_values(array_unique(array_map($toLeaf, $list)));
    }

    /**
     * @param mixed $value
     * @return array
     */
    public static function parse(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }

        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return [];
    }

    /**
     * Normalizes an index array:
     * - attributes/filterable/sortable => arrays of strings
     * - entryTypes default => ['*']
     * - fields default => []
     *
     * @param array $index
     * @return array
     */
    public static function normalize(array $index): array
    {
        $index['sections'] = is_array($index['sections'] ?? null) ? array_values($index['sections']) : [];

        $index['entryTypes'] = is_array($index['entryTypes'] ?? null) ? array_values($index['entryTypes']) : ['*'];

        $index['fields'] ??= [];
        $index['siteIds'] = array_map('intval', (array) ($index['siteIds'] ?? []));
        $index['siteAware'] = !empty($index['siteAware']);
        $index['enabled'] = !empty($index['enabled']);
        $index['attributes'] = self::parse($index['attributes'] ?? []);
        $index['filterable'] = self::parse($index['filterable'] ?? []);
        $index['sortable'] = self::parse($index['sortable'] ?? []);
        $index['imageTransforms'] = self::normalizeImageTransforms($index['imageTransforms'] ?? []);

        return $index;
    }

    /**
     * Get all index names for an index, respecting the siteAware flag.
     *
     * Example:
     *  - siteAware = false: ["devices"]
     *  - siteAware = true: ["devices_site2"]
     *
     * @param array $index
     * @return array
     */
    public static function indexNames(array $index): array
    {
        $handle = $index['handle'];

        $siteAware = !empty($index['siteAware']);
        if (!$siteAware) {
            return [$handle];
        }

        // Prefer explicitly selected siteIds; otherwise fallback to all sites.
        $siteIds = array_map('intval', $index['siteIds'] ?? []);
        if (!$siteIds) {
            $siteIds = array_map(fn ($site) => $site->id, Craft::$app->sites->getAllSites());
        }

        return array_map(fn ($siteId) => "{$handle}_site{$siteId}", $siteIds);
    }

    /**
     * Get the index name for a index + optional siteId, respecting the siteAware flag.
     *
     * Example:
     *  - siteAware = false: "devices"
     *  - siteAware = true: "devices_site2"
     *
     * @param array $index
     * @param int|null $siteId
     * @return string
     */
    public static function indexName(array $index, ?int $siteId): string
    {
        $handle = $index['handle'];

        if (!empty($index['siteAware']) && $siteId) {
            return "{$handle}_site{$siteId}";
        }

        return $handle;
    }

    /**
     * Build the per-entry-type config
     *
     * @param array $index
     * @param Entry $entry
     * @return array {
     *   fields: string[],                                    	// top-level field handles
     *   fieldsNested: array<string,array<string,string[]>,    	// parent => blockType => [fieldNested...]
     *   imageTransforms: array<string,string[]> 				// parent => ["source:handle", ...]
     * }
     * @return array|array[]
     */
    public static function getConfigForEntryType(array $index, Entry $entry): array
    {
        $entryTypeHandle = $entry->type->handle;
        $sectionHandle = $entry->section?->handle;

        if (!$entryTypeHandle) {
            return [
                'fields' => [],
                'fieldsNested' => [],
                'imageTransforms' => [],
            ];
        }

        return self::getConfigForHandles($index, $entryTypeHandle, $sectionHandle);
    }

    /**
     * @param array $index
     * @param string $entryTypeHandle
     * @param string|null $sectionHandle
     * @return array
     */
    public static function getConfigForHandles(array $index, string $entryTypeHandle, ?string $sectionHandle = null): array
    {
        $fields = [];
        $fieldsNested = [];
        $imageTransforms = [];

        // Image transforms: ['section.entryType.field' => ['source:handle', ...]])
        foreach ((array) ($index['imageTransforms'] ?? []) as $key => $list) {
            $parts = self::splitPath((string) $key); // ['section','entryType','field','rest'=>[]]
            if (($parts['entryType'] ?? null) !== $entryTypeHandle || !$parts['field']) {
                continue;
            }

            // Avoid collisions across sections
            if ($sectionHandle && ($parts['section'] ?? null) !== $sectionHandle) {
                continue;
            }

            $imageTransforms[$parts['field']] ??= [];

            // merge and de-dup while preserving order
            $imageTransforms[$parts['field']] = array_values(array_unique(array_merge($imageTransforms[$parts['field']], array_map('strval', (array) $list))));
        }

        // Fields + nested fields: ['section.et.parent', 'section.entryType.parent.blockType.nested', ...])
        foreach ((array) ($index['fields'][$entryTypeHandle] ?? []) as $path) {
            $parts = self::splitPath((string) $path);
            if (!$parts['field']) {
                continue;
            }

            if ($sectionHandle && ($parts['section'] ?? null) !== $sectionHandle) {
                continue;
            }

            $parent = $parts['field'];
            $fields[] = $parent;

            // Nested: section.entryType.parent.blockType.nested
            if (isset($parts['rest'][0], $parts['rest'][1])) {
                $blockType = $parts['rest'][0];
                $fieldNested = $parts['rest'][1];

                $fieldsNested[$parent] ??= [];
                $fieldsNested[$parent][$blockType] ??= [];

                if (!in_array($fieldNested, $fieldsNested[$parent][$blockType], true)) {
                    $fieldsNested[$parent][$blockType][] = $fieldNested;
                }
            }
        }

        $fields = array_values(array_unique($fields));

        return [
            'fields' => $fields,
            'fieldsNested' => $fieldsNested,
            'imageTransforms' => $imageTransforms,
        ];
    }

    /**
     * Split a namespaced path like "section.entryType.field.blockType.fieldNested" into parts.
     *
     * @param string $path
     * @return array
     */
    public static function splitPath(string $path): array
    {
        // "section.entryType.field[.blockType.fieldNested]"
        $parts = array_values(array_filter(array_map('trim', explode('.', $path)), 'strlen'));

        return [
            'section' => $parts[0] ?? null,
            'entryType' => $parts[1] ?? null,
            'field' => $parts[2] ?? null,
            'rest' => array_values(array_map('strval', array_slice($parts, 3))), // blockType + fieldNested
        ];
    }
    /**
     * @param array $nested
     * @param string $prefix
     * @return array
     */
    public static function flattenImageTransformsLegacy(array $nested, string $prefix = ''): array
    {
        $out = [];

        foreach ($nested as $k => $v) {
            $path = $prefix ? "{$prefix}.{$k}" : (string)$k;

            if (is_array($v)) {
                // leaf list of transforms? (strings)
                $isLeaf = !empty($v) && !is_array(reset($v));
                if ($isLeaf) {
                    $out[$path] = array_values(array_map('strval', $v));
                } else {
                    $out += self::flattenImageTransformsLegacy($v, $path);
                }
            }
        }

        return $out;
    }

    /**
     * Accepts either legacy nested:
     *   ['devices' => ['devicesEntryType' => ['asset' => ['craft:thumb']]]]
     * or flat namespaced:
     *   ['devices.devicesEntryType.asset' => ['craft:thumb']]
     * and returns the flat form.
     *
     * @param $value
     * @return array
     */
    public static function normalizeImageTransforms($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        // If already flat (keys look like section.entryType.field), keep as is.
        $firstKey = array_key_first($value);
        if (is_string($firstKey) && substr_count($firstKey, '.') >= 2 && is_array($value[$firstKey])) {
            return $value;
        }

        // Otherwise, flatten legacy nested shape.
        $out = [];

        $walk = function (array $node, string $prefix = '') use (&$out, &$walk) {
            foreach ($node as $key => $value) {
                $path = $prefix ? "{$prefix}.{$key}" : (string) $key;

                if (is_array($value)) {
                    // leaf list of transforms (strings)?
                    $isLeaf = !empty($value) && !is_array(reset($value));
                    if ($isLeaf) {
                        $out[$path] = array_values(array_map('strval', $value));
                    } else {
                        $walk($value, $path);
                    }
                }
            }
        };

        $walk($value);

        return $out;
    }

    /**
     * Accepts an imager-x config array and returns just the named transforms section.
     * Supports common keys: transformPresets, namedTransforms, transforms
     *
     * @param array $config
     * @param array $presets
     * @return array
     */
    public static function getImagerXPresetsFromConfig(array $config, array $presets): array
    {
        // Common places users declare named transforms
        foreach (['transformPresets', 'namedTransforms', 'transforms'] as $key) {
            if (!empty($config[$key]) && is_array($config[$key])) {
                $presets = array_merge($presets, array_keys($config[$key]));
            }
        }

        // Some users put them top-level as an associative array
        if ($config) {
            $presets = array_merge($presets, array_keys($config));
        }

        // De-dupe and normalize
        return array_values(array_unique(array_map('strval', $presets)));
    }
}
