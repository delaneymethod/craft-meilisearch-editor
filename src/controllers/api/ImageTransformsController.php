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
use craft\helpers\StringHelper;
use craft\web\Controller;
use delaneymethod\meilisearcheditor\helpers\IndexConfigHelper;
use spacecatninja\imagerx\ImagerX;
use yii\web\BadRequestHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

class ImageTransformsController extends Controller
{
    /**
     * @var array<int|string>|bool|int
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * @var IndexConfigHelper
     */
    private IndexConfigHelper $indexConfigHelper;

    public function __construct($id, $module, $config = [])
    {
        parent::__construct($id, $module, $config);

        $this->indexConfigHelper = Craft::$container->get(IndexConfigHelper::class);
    }

    /**
     * POST /meilisearch-editor/api/image-transforms
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionIndex(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $json = [];

        // Craft native transforms
        foreach (Craft::$app->imageTransforms->getAllTransforms() as $imageTransform) {
            $json[] = [
                'uid' => $imageTransform->uid,
                'name' => $imageTransform->name ?: $imageTransform->handle,
                'handle' => $imageTransform->handle,
                'source' => 'craft',
            ];
        }

        // Imager X presets (aka transformPresets / named transforms)
        $hasImagerX = Craft::$app->getPlugins()->isPluginEnabled('imager-x');
        if ($hasImagerX) {
            $presets = [];

            // settings first
            if (class_exists(ImagerX::class)) {
                $settings = ImagerX::getInstance()->getSettings();

                // Imager X commonly uses "transformPresets"
                if (!empty($settings->transformPresets) && is_array($settings->transformPresets)) {
                    $presets = $settings->transformPresets;
                }
            }

            // config/imager-x.php
            $config = Craft::$app->getConfig()->getConfigFromFile('imager-x');
            $presets = $this->indexConfigHelper->getImagerXPresetsFromConfig($config, $presets);

            // config/imager-x-transforms.php
            $configTransforms = Craft::$app->getConfig()->getConfigFromFile('imager-x-transforms');
            $presets = $this->indexConfigHelper->getImagerXPresetsFromConfig($configTransforms, $presets);

            foreach ($presets as $handle) {
                $name = StringHelper::toKebabCase($handle);
                $name = StringHelper::titleizeForHumans($name);
                $name = str_replace('-', ' ', $name);

                $json[] = [
                    'uid' => '',
                    'name' => $name,
                    'handle' => (string) $handle,
                    'source' => 'imager-x',
                ];
            }
        }

        // De-dupe by (source, handle) and sort
        $unique = [];

        foreach ($json as $imageTransform) {
            $key = $imageTransform['source'] . ':' . $imageTransform['handle'];

            $unique[$key] = $imageTransform;
        }

        $json = array_values($unique);

        usort($json, fn ($a, $b) => [$a['source'], $a['name']] <=> [$b['source'], $b['name']]);

        return $this->asJson([
            'success' => true,
            'imageTransforms' => $json,
        ]);
    }
}
