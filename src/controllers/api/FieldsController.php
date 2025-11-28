<?php

/**
 * Meilisearch Editor for Craft CMS.
 *
 * @author        DelaneyMethod
 * @copyright     Copyright (c) 2025
 *
 * @see           https://github.com/delaneymethod/craft-meilisearch-editor
 */

namespace delaneymethod\meilisearcheditor\controllers\api;

use benf\neo\Field as NeoField;
use benf\neo\models\BlockType;
use benf\neo\Plugin as NeoPlugin;
use Craft;
use craft\base\Field;
use craft\fields\Assets;
use craft\fields\Matrix;
use craft\models\EntryType;
use craft\web\Controller;
use verbb\supertable\fields\SuperTableField;
use verbb\supertable\SuperTable;
use yii\web\BadRequestHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

class FieldsController extends Controller
{
    /**
     * @var array<int|string>|bool|int
     */
    protected array|bool|int $allowAnonymous = false;

    private const TYPES_NEO = 'benf\\neo\\Field';

    private const TYPES_MATRIX = 'craft\\fields\\Matrix';

    private const TYPES_SUPERTABLE = 'verbb\\supertable\\fields\\SuperTableField';

    /**
     * POST /meilisearch-editor/api/fields
     * body: { entryTypes: string[] }.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionIndex(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $entryTypes = Craft::$app->getRequest()->getBodyParam('entryTypes');
        if (!$entryTypes) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('meilisearch-editor', 'entryTypes is required.'),
            ]);
        }

        if (!\is_array($entryTypes)) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('meilisearch-editor', 'entryTypes must be an array.'),
            ]);
        }

        $json = [];

        foreach ($entryTypes as $entryType) {
            $entryType = Craft::$app->entries->getEntryTypeByHandle($entryType);
            if (!$entryType) {
                continue;
            }

            $customFields = $this->getCustomFields($entryType);

            $json[] = [
                'entryType' => [
                    'uid' => $entryType->uid,
                    'name' => $entryType->name ?? $entryType->getHandle(),
                    'handle' => $entryType->getHandle(),
                ],
                'fields' => array_values($customFields),
            ];
        }

        return $this->asJson([
            'success' => true,
            'entryTypes' => $json,
        ]);
    }

    /**
     * POST /cp/meilisearch-editor/api/fields/nested
     * body: { fieldUid, type: 'matrix'|'neo'|'supertable' }
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionNested(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        $fieldUid = (string) $request->getBodyParam('fieldUid');
        if (!$fieldUid) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('meilisearch-editor', 'fieldUid is required.'),
            ]);
        }

        $type = (string) $request->getBodyParam('type');
        if (!$type) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('meilisearch-editor', 'type is required.'),
            ]);
        }

        $field = Craft::$app->getFields()->getFieldByUid($fieldUid);
        if (!$field) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('meilisearch-editor', 'Field was not found.'),
            ]);
        }

        switch ($type) {
            case self::TYPES_MATRIX:
                if (!$field instanceof Matrix) {
                    return $this->asJson([
                        'success' => false,
                        'message' => Craft::t('meilisearch-editor', 'Field is not a Matrix field.'),
                    ]);
                }

                $nestedFields = $this->getMatrixFields($field);
                break;

            case self::TYPES_NEO:
                if (!class_exists(NeoField::class) || !class_exists(NeoPlugin::class)) {
                    return $this->asJson([
                        'success' => false,
                        'message' => Craft::t('meilisearch-editor', 'Neo plugin is not installed.'),
                    ]);
                }

                if (!$field instanceof NeoField) {
                    return $this->asJson([
                        'success' => false,
                        'message' => Craft::t('meilisearch-editor', 'Field is not a Neo field.'),
                    ]);
                }

                $nestedFields = $this->getNeoFields($field);
                break;

            case self::TYPES_SUPERTABLE:
                if (!class_exists(SuperTableField::class) || !class_exists(SuperTable::class)) {
                    return $this->asJson([
                        'success' => false,
                        'message' => Craft::t('meilisearch-editor', 'Super Table plugin is not installed.'),
                    ]);
                }

                if (!$field instanceof SuperTableField) {
                    return $this->asJson([
                        'success' => false,
                        'message' => Craft::t('meilisearch-editor', 'Field is not a Super Table field.'),
                    ]);
                }

                $nestedFields = $this->getSuperTableFields($field);
                break;

            default:
                return $this->asJson([
                    'success' => false,
                    'message' => Craft::t('meilisearch-editor', 'Unsupported field type.'),
                ]);
        }

        return $this->asJson([
            'success' => true,
            'fields' => [
                'parentField' => $this->getFieldMeta($field),
                'nestedFields' => $nestedFields,
            ],
        ]);
    }

    /**
     * @param Matrix $field
     * @return array
     */
    private function getMatrixFields(Matrix $field): array
    {
        return $this->getGroups($field->getEntryTypes());
    }

    /**
     * @param NeoField $field
     * @return array
     */
    private function getNeoFields(NeoField $field): array
    {
        return $this->getGroups($field->getBlockTypes());
    }

    /**
     * @param SuperTableField $field
     * @return array
     */
    private function getSuperTableFields(SuperTableField $field): array
    {
        return $this->getMatrixFields($field);
    }

    /**
     * @param array $entryTypes
     * @return array
     */
    private function getGroups(array $entryTypes): array
    {
        $groups = [];

        foreach ($entryTypes as $entryType) {
            $customFields = $this->getCustomFields($entryType);

            $groups[] = [
                'entryType' => [
                    'uid' => $entryType->uid,
                    'name' => $entryType->name ?? $entryType->getHandle(),
                    'handle' => $entryType->getHandle(),
                ],
                'fields' => array_values($customFields),
            ];
        }

        return $groups;
    }

    /**
     * @param BlockType|EntryType $entryType
     * @return array
     */
    private function getCustomFields(BlockType|EntryType $entryType): array
    {
        $customFields = [];

        foreach ($entryType->getFieldLayout()->getCustomFields() as $customField) {
            if (!$customField instanceof Field) {
                continue;
            }

            $customFields[] = $this->getFieldMeta($customField);
        }

        return $customFields;
    }

    /**
     * @param Field $field
     * @return array
     */
    private function getFieldMeta(Field $field): array
    {
        // Assets -> include allowedKinds so the UI can decide if image transform are required
        if ($field instanceof Assets) {
            return [
                'uid' => $field->uid,
                'name' => $field->name,
                'handle' => $field->handle,
                'type' => $field::class,
                'shortType' => $this->getShortType($field),
                'allowedKinds' => $field->allowedKinds,
            ];
        }

        return [
            'uid' => $field->uid,
            'name' => $field->name,
            'handle' => $field->handle,
            'type' => $field::class,
            'shortType' => $this->getShortType($field),
        ];
    }

    /**
     * @param Field $field
     * @return string
     */
    private function getShortType(Field $field): string
    {
        if (class_exists(SuperTable::class) && $field instanceof SuperTableField) {
            return 'supertable';
        }

        if (class_exists(NeoField::class) && $field instanceof NeoField) {
            return 'neo';
        }

        // Plain Text/Rich Text/Entries/Categories/etc
        $class = get_class($field);
        $parts = explode('\\', $class);
        $short = strtolower(preg_replace('/Field$/', '', end($parts)));

        return $short ?: $class;
    }
}
