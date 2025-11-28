<?php

/**
 * Meilisearch Editor for Craft CMS.
 *
 * @author        DelaneyMethod
 * @copyright     Copyright (c) 2025
 *
 * @see           https://github.com/delaneymethod/craft-meilisearch-editor
 */

namespace delaneymethod\meilisearcheditor\controllers;

use Craft;
use craft\web\Controller;
use delaneymethod\meilisearcheditor\MeilisearchEditor;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

class KeysController extends Controller
{
    /**
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionIssue(): Response
    {
        return $this->preflight(
            callback: fn (string $name, string $handle, array $actions, int $ttl) => MeilisearchEditor::$plugin->client->issueKey($name, $handle, $actions, $ttl),
        );
    }

    /**
     * Requires JSON + POST
     *
     * Verifies index exists and is enabled
     * Runs $callback() and wraps exceptions into JSON error
     *
     * @param callable $callback
     * @return Response
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     */
    private function preflight(callable $callback): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        $handle = (string) $request->getBodyParam('handle', '');
        if ($handle === '') {
            return $this->fail(Craft::t('meilisearch-editor', "Missing 'handle' parameter."));
        }

        $index = MeilisearchEditor::$plugin->indexes->getIndex($handle);
        if (!$index) {
            return $this->fail(Craft::t('meilisearch-editor', "Meilisearch index '{handle}' was not found.", ['handle' => $handle]));
        }

        if (empty($index['enabled'])) {
            return $this->fail(Craft::t('meilisearch-editor', "Meilisearch index '{handle}' was not enabled.", ['handle' => $handle]));
        }

        $ttl = (int) $request->getBodyParam('ttl', 900);
        $name = (string) $request->getBodyParam('name', 'Search');
        $actions = (array) $request->getBodyParam('actions', ['search']);

        try {
            $response = $callback($name, $handle, $actions, $ttl);

            return $this->asJson($response);
        } catch (Throwable $exception) {
            return $this->fail(Craft::t('meilisearch-editor', 'Operation failed: {message}', ['message' => $exception->getMessage()]));
        }
    }

    /**
     * @param string $message
     * @return Response
     */
    private function fail(string $message): Response
    {
        return $this->asJson([
            'success' => false,
            'message' => $message,
        ]);
    }
}
