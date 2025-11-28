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
use craft\base\Field;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\errors\InvalidFieldException;
use craft\fields\Matrix;
use DateTimeInterface;
use spacecatninja\imagerx\ImagerX;
use Throwable;
use verbb\supertable\fields\SuperTableField;
use yii\base\InvalidConfigException;
use yii\log\Logger;

class MapperService
{
    /**
     * @param Entry $entry
     * @param array $config
     * @return array
     * @throws InvalidFieldException
     */
    public static function entryToDocument(Entry $entry, array $config): array
    {
        $attributes = array_values(array_filter(array_map('strval', $config['attributes'] ?? [])));

        $fields = $config['fields'] ?? [];

        // Handle all custom fields in this entry type's field layout
        if ($fields === ['*']) {
            $layout = $entry->getFieldLayout();

            $fields = $layout
                ? array_values(array_map(
                    fn (Field $customField) => $customField->handle,
                    array_filter($layout->getCustomFields(), fn ($customField) => $customField instanceof Field)
                ))
                : [];
        }

        $document = [];

        foreach ($attributes as $attribute) {
            $document[$attribute] = $entry->{$attribute} ?? '';
        }

        foreach ($fields as $handle) {
            $field = Craft::$app->fields->getFieldByHandle($handle);
            if (!$field) {
                continue;
            }

            $layout = $entry->getFieldLayout();

            $onLayout = $layout && array_filter($layout->getCustomFields(), fn ($customField) => $customField instanceof Field && $customField->handle === $handle);
            if (!$onLayout) {
                continue;
            }

            $value = $entry->getFieldValue($handle);

            $isBlockLike = ((class_exists(\benf\neo\Field::class) && $field instanceof \benf\neo\Field) || (class_exists(SuperTableField::class) && $field instanceof SuperTableField) || $field instanceof Matrix);

            $options = [
                'isBlockLike' => $isBlockLike,
                // Only include selected nested fields for this top-level block-like field. e.g. ['gallery' => ['images','caption']]
                'fieldsNested' => ($config['fieldsNested'][$handle] ?? null),
                // If you later want per-field image transform info available here: ['craft:thumb','imager-x:hero']
                'imageTransforms' => ($config['imageTransforms'][$handle] ?? []),
            ];

            $document[$handle] = self::normalizeField($value, $handle, $options);
        }

        $document['id'] = $entry->id;
        $document['objectID'] = $entry->id . '-' . $entry->siteId;
        $document['siteId'] = $entry->siteId;
        $document['url'] = $entry->url;
        $document['uri'] = $entry->uri;
        $document['slug'] = $entry->slug;
        $document['postDate'] = $entry->postDate->format('c');
        $document['dateCreated'] = $entry->dateCreated->format('c');
        $document['dateUpdated'] = $entry->dateUpdated->format('c');

        // Strip empty values at the very end
        return self::removeEmptyKeys($document);
    }

    /**
     * Normalize a Craft field value into something Meilisearch-friendly.
     *
     * Handles:
     *  - Relations (elements queries): returns array of slug/title/id (assets get URL by default)
     *  - Block-like fields (Matrix/Neo/Super Table etc.): concatenated text by default; JSON mode can be added later
     *  - Table fields (array of rows): array of flattened row strings
     *  - Lightswitch (bool), DateTime -> ISO 8601
     *  - Stringable objects
     *
     * @param mixed $value
     * @param string $handle
     * @param array $options
     * @return array|bool|mixed|string
     */
    private static function normalizeField(mixed $value, string $handle, array $options): mixed
    {
        // Relations/queries
        if (is_object($value) && method_exists($value, 'all')) {
            $items = $value->all();

            // If nothing on this site, and the query supports siteId(), try any-site
            if (!$items && method_exists($value, 'siteId')) {
                try {
                    $value->siteId('*');

                    $items = $value->all();
                } catch (Throwable) {
                    // ignore
                }
            }

            if (!$items) {
                return [];
            }

            // Assets
            if ($items[0] instanceof Asset) {
                $mode = $options['mode'] ?? 'url'; // urls|ids|titles

                $items = array_values($items);

                // image transforms are configured
                $imageTransforms = (array) ($options['imageTransforms'] ?? []);
                if (!empty($imageTransforms)) {
                    $assets = [];

                    foreach ($items as $asset) {
                        $modeValue = match ($mode) {
                            'id' => (string) $asset->id,
                            'title' => (string) $asset->title,
                            default => (string) $asset->getUrl(),
                        };

                        $assets[] = [
                            $mode => $modeValue,
                            'imageTransforms' => self::getImageTransformUrlsForAsset($asset, $imageTransforms),
                        ];
                    }

                    return $assets;
                }

                // Default behaviour (no image transforms configured)
                $assets = [];

                foreach ($items as $asset) {
                    $assets[] = match ($mode) {
                        'id' => (string) $asset->id,
                        'title' => (string) $asset->title,
                        default => (string) $asset->getUrl(),
                    };
                }

                return $assets;
            }

            // Matrix, Neo and Super Table
            $isBlockLike = (bool) ($options['isBlockLike'] ?? false);
            if ($isBlockLike) {
                $chunks = [];

                $fieldsNested = $options['fieldsNested'] ?? null; // null = include all

                foreach ($items as $item) {
                    $itemChunks = [];

                    $hadContent = false;

                    // Include block title if present (Matrix / Super Table may have it)
                    $blockTitle = null;

                    if (isset($item->title) && is_string($item->title)) {
                        $blockTitle = trim($item->title);
                    }

                    if ('' !== $blockTitle) {
                        $itemChunks[] = $blockTitle;

                        $hadContent = true;
                    }

                    // Then include custom fields
                    $customFields = $item->getFieldLayout()?->getCustomFields() ?? [];

                    $blockTypeHandle = method_exists($item, 'getType') && $item->getType() ? ($item->getType()->handle ?? null) : null;

                    $allowedForThisBlock = is_array($fieldsNested) && $blockTypeHandle && isset($fieldsNested[$blockTypeHandle]) ? array_flip((array) $fieldsNested[$blockTypeHandle]) : null;

                    foreach ($customFields as $customField) {
                        $customFieldHandle = $customField->handle;

                        if ($allowedForThisBlock && !isset($allowedForThisBlock[$customFieldHandle])) {
                            continue; // skip unselected nested field
                        }

                        $raw = $item->getFieldValue($customFieldHandle);

                        $string = trim(self::scalarize($raw));
                        if ('' !== $string) {
                            $itemChunks[] = $string;

                            $hadContent = true;
                        }
                    }

                    // If still empty, fall back to block type label/handle
                    if (!$hadContent) {
                        $label = null;

                        if (method_exists($item, 'getType') && ($type = $item->getType())) {
                            $label = $type->name ?? $type->handle ?? null;
                        }

                        if ($label) {
                            $itemChunks[] = $label;
                        }
                    }

                    // De-dupe within this block
                    $itemChunks = array_values(array_unique($itemChunks));
                    if ($itemChunks) {
                        $chunks[] = implode(' ', $itemChunks);
                    }
                }

                return implode(' ', $chunks);
            }

            // Generic relations (entries/categories/tags/users/etc.)
            $mode = $options['mode'] ?? 'title'; // title|slug|id (default: titles)

            $items = array_values($items);

            $relations = [];

            foreach ($items as $element) {
                $label = self::elementString($element, $mode);
                if ('' !== $label) {
                    $relations[] = $label;
                }
            }

            return $relations;
        }

        // Any array (e.g., top-level Table field) -> use the same logic as block-like inner tables
        if (is_array($value)) {
            return self::scalarize($value); // returns a single, de-duped string
        }

        // Lightswitch
        if (is_bool($value)) {
            return $value;
        }

        // Date/time
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        // Stringable objects
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return self::normalizeForMeilisearch($value);
    }

    /**
     * @param array $doc
     * @return array
     */
    private static function removeEmptyKeys(array $doc): array
    {
        return array_filter($doc, function ($value) {
            if (is_array($value)) {
                return !empty($value);
            }

            return $value !== '' && $value !== null;
        });
    }

    /**
     * Ensures that a normalized field value is safe for Meilisearch.
     * Converts nulls to empty strings/arrays depending on expected type.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalizeForMeilisearch(mixed $value): mixed
    {
        // null => type-appropriate empty value
        if ($value === null) {
            return '';
        }

        // Booleans and numerics are fine
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        // Arrays: keep shape, even if empty
        if (is_array($value)) {
            return $value ?: [];
        }

        // Objects that are probably meant to serialize to strings
        if (is_object($value) && method_exists($value, '__toString')) {
            $string = trim((string) $value);

            return $string !== '' ? $string : '';
        }

        // Fallback for scalars / strings
        if (is_string($value)) {
            return trim($value);
        }

        // Default safety fallback
        return '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function scalarize(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, 'all')) {
            try {
                $elements = $value->all();
            } catch (Throwable) {
                $elements = [];
            }

            // default to titles for relations inside blocks, too
            return implode(',', array_map(fn ($element) => self::elementString($element, 'titles'), $elements));
        }

        if (is_array($value)) {
            // Table field: array of row arrays
            if (isset($value[0]) && is_array($value[0])) {
                $rowStrings = array_map(
                    function (array $row): string {
                        // If the row has named keys, prefer those; ignore colN duplicates.
                        $named = [];

                        foreach ($row as $key => $value) {
                            if (is_string($key) && !preg_match('/^col\d+$/', $key)) {
                                $named[] = (string) $value;
                            }
                        }

                        $values = $named ?: array_map('strval', array_values($row));

                        // normalize whitespace and drop empties
                        $values = array_map(fn ($string) => preg_replace('/\s+/u', ' ', trim($string)), $values);
                        $values = array_values(array_filter($values, 'strlen'));

                        return implode(' ', $values);
                    },
                    $value
                );

                // De-dupe identical rows
                $rowStrings = array_values(array_unique($rowStrings));

                return implode(' ', $rowStrings);
            }

            // Simple arrays
            $values = array_map(fn ($string) => preg_replace('/\s+/u', ' ', trim((string) $string)), $value);
            $values = array_values(array_unique(array_filter($values, 'strlen')));

            return implode(' ', $values);
        }

        return (string) $value;
    }

    /**
     * @param object $element
     * @param string $mode
     * @return string
     */
    private static function elementString(object $element, string $mode = 'title'): string
    {
        // Helper to pick the first non-empty property
        $pick = function (array $keys) use ($element) {
            foreach ($keys as $key) {
                if (isset($element->{$key}) && $element->{$key} !== '' && $element->{$key} !== null) {
                    return (string) $element->{$key};
                }
            }

            return (string) ($element->id ?? '');
        };

        return match ($mode) {
            'id' => (string) ($element->id ?? ''),
            'slug' => $pick(['slug', 'username', 'title', 'name']),
            default => $pick(['title', 'fullName', 'username', 'name', 'slug', 'email']),
        };
    }

    /**
     * Builds image transform URLs for a single Asset, for both Craft and Imager X.
     *
     * @param Asset $asset
     * @param string[] $imageTransforms e.g. ['craft:thumb', 'imager-x:hero'] or ['thumb'] (treated as craft)
     * @return array ['craft:thumb' => 'https://…', 'imager-x:hero' => 'https://…']
     */
    private static function getImageTransformUrlsForAsset(Asset $asset, array $imageTransforms): array
    {
        static $hasImagerX = null;
        static $craftModelCache = [];  // handle => model|null
        static $imagerXConfigCache = [];  // handle => config|null

        if ($hasImagerX === null) {
            $hasImagerX = class_exists(ImagerX::class);
        }

        $urls = [];

        foreach ($imageTransforms as $imageTransform) {
            if ($imageTransform === '') {
                continue;
            }

            $source = 'craft';
            $handle = $imageTransform;
            if (str_contains($imageTransform, ':')) {
                [$source, $handle] = explode(':', $imageTransform, 2) + ['craft', $imageTransform];
                $source = strtolower($source);
            }

            try {
                if ($source === 'imager-x' || $source === 'imagerx' || $source === 'imager') {
                    if ($hasImagerX) {
                        /** @var object|null $transforms */
                        $transforms = self::imagerX('transforms');

                        /** @var object|null $imager */
                        $imager = self::imagerX('imager');

                        // Ask for transform config (cached, if available)
                        $config = $imagerXConfigCache[$handle] ?? null;
                        if ($config === null) {
                            $config = self::imagerXTransformConfig($transforms, $handle);

                            $imagerXConfigCache[$handle] = $config;
                        }

                        if ($config && $imager && method_exists($imager, 'transformImage')) {
                            $result = $imager->transformImage($asset, $config);

                            $url = null;
                            if (is_array($result)) {
                                $first = reset($result);

                                $url = isset($first->url) ? (string) $first->url : null;
                            } elseif (is_object($result) && isset($result->url)) {
                                $url = (string) $result->url;
                            }

                            if ($url) {
                                $urls["imager-x:{$handle}"] = $url;
                            }
                        }
                    }
                } else {
                    // Craft native
                    // Most Craft installs accept string handles directly:
                    $url = $asset->getUrl($handle);

                    // For older versions that need a model, cache it:
                    if (!$url && !array_key_exists($handle, $craftModelCache)) {
                        $craftModelCache[$handle] = method_exists(Craft::$app->getImageTransforms(), 'getTransformByHandle')
                            ? Craft::$app->getImageTransforms()->getTransformByHandle($handle)
                            : null;
                    }
                    if (!$url && $craftModelCache[$handle]) {
                        $url = $asset->getUrl($craftModelCache[$handle]);
                    }

                    if ($url) {
                        $urls["craft:{$handle}"] = $url;
                    }
                }
            } catch (Throwable $exception) {
                Craft::getLogger()->log("Failed to build image transform '{$imageTransform}' for asset {$asset->id}: {$exception->getMessage()}", Logger::LEVEL_WARNING, 'meilisearch-editor');
            }
        }

        return $urls;
    }

    /**
     * Resolve an Imager X service safely across versions.
     *
     * @param string $id
     * @return object|null
     * @throws InvalidConfigException
     */
    private static function imagerX(string $id): ?object
    {
        if (!class_exists(ImagerX::class)) {
            return null;
        }

        $plugin = ImagerX::getInstance();
        if (!$plugin) {
            return null;
        }

        // Prefer ServiceLocator::has/get when registered
        if (method_exists($plugin, 'has') && $plugin->has($id)) {
            /** @var object|null */
            return $plugin->get($id);
        }

        // Fallback to dynamic property (older Imager X versions)
        $service = isset($plugin->{$id}) ? $plugin->{$id} : null;

        return is_object($service) ? $service : null;
    }

    /**
     * @param object|null $transforms
     * @param string $handle
     * @return null
     */
    private static function imagerXTransformConfig(?object $transforms, string $handle)
    {
        if (!$transforms || !method_exists($transforms, 'getTransformByHandle')) {
            return null;
        }

        return $transforms->getTransformByHandle($handle) ?: null;
    }
}
