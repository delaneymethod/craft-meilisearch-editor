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
use craft\web\ServiceUnavailableHttpException;
use delaneymethod\meilisearcheditor\MeilisearchEditor;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

class WebhookController extends Controller
{
    /**
     * @var array<int|string>|bool|int
     */
    protected array|bool|int $allowAnonymous = true;

    /**
     * @param $action
     * @return bool
     * @throws BadRequestHttpException
     * @throws ServiceUnavailableHttpException
     * @throws ForbiddenHttpException
     * @throws UnauthorizedHttpException
     */
    public function beforeAction($action): bool
    {
        // Webhooks are POST+JSON; disable CSRF for these endpoints.
        $this->enableCsrfValidation = false;

        return parent::beforeAction($action);
    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionReindex(): Response
    {
        return $this->preflight(
            callback: fn (string $handle) => MeilisearchEditor::$plugin->indexes->rebuildIndex($handle),
            callbackMessage: fn (string $handle) => Craft::t('meilisearch-editor', "Meilisearch index '{handle}' re-indexed.", ['handle' => $handle]),
        );
    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionFlush(): Response
    {
        return $this->preflight(
            callback: function (string $handle) {
                $index = MeilisearchEditor::$plugin->indexes->getIndex($handle);

                MeilisearchEditor::$plugin->client->deleteAllDocuments($index);
            },
            callbackMessage: fn (string $handle) => Craft::t('meilisearch-editor', "Meilisearch index '{handle}' flushed.", ['handle' => $handle]),
        );
    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionDelete(): Response
    {
        return $this->preflight(
            callback: function (string $handle) {
                $index = MeilisearchEditor::$plugin->indexes->getIndex($handle);

                MeilisearchEditor::$plugin->client->deleteIndexByIndex($index);
            },
            callbackMessage: fn (string $handle) => Craft::t('meilisearch-editor', "Meilisearch index '{handle}' deleted.", ['handle' => $handle]),
        );
    }

    /**
     * Requires JSON + POST
     *
     * Reads "handle" from body
     * Verifies index exists and is enabled
     * Runs $callback() and wraps exceptions into JSON error
     *
     * @param callable $callback
     * @param callable|string $callbackMessage
     * @return Response
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     */
    private function preflight(callable $callback, callable|string $callbackMessage): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $handle = (string) Craft::$app->getRequest()->getBodyParam('handle', '');
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

        try {
            $callback($handle);
        } catch (Throwable $exception) {
            $message = is_callable($callbackMessage) ? $callbackMessage($handle) : $callbackMessage;

            return $this->fail($message . ': ' . $exception->getMessage());
        }

        $message = is_callable($callbackMessage) ? $callbackMessage($handle) : $callbackMessage;

        return $this->ok($message);
    }

    /**
     * @param string $message
     * @return Response
     */
    private function ok(string $message): Response
    {
        return $this->asJson([
            'success' => true,
            'message' => $message,
        ]);
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
