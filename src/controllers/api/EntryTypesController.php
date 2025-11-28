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

use Craft;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

class EntryTypesController extends Controller
{
    /**
     * @var array<int|string>|bool|int
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * POST /meilisearch-editor/api/entry-types
     * body: { sections: string[] }.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionIndex(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $sections = Craft::$app->getRequest()->getBodyParam('sections');
        if (!$sections) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('meilisearch-editor', 'sections is required.'),
            ]);
        }

        if (!\is_array($sections)) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('meilisearch-editor', 'sections must be an array.'),
            ]);
        }

        $json = [];

        foreach ($sections as $section) {
            $section = Craft::$app->entries->getSectionByHandle($section);
            if (!$section) {
                continue;
            }

            $entryTypes = Craft::$app->entries->getEntryTypesBySectionId($section->id);

            $json[] = [
                'section' => [
                    'uid' => $section->uid,
                    'name' => $section->name,
                    'handle' => $section->handle,
                ],
                'entryTypes' => array_map(fn ($entryType) => [
                    'uid' => $entryType->uid,
                    'name' => $entryType->name,
                    'handle' => $entryType->handle,
                ], $entryTypes),
            ];
        }

        return $this->asJson([
            'success' => true,
            'sections' => $json,
        ]);
    }
}
