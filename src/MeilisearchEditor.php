<?php

/**
 * Meilisearch Editor for Craft CMS.
 *
 * @author        DelaneyMethod
 * @copyright     Copyright (c) 2025
 *
 * @see           https://github.com/delaneymethod/craft-meilisearch-editor
 */

namespace delaneymethod\meilisearcheditor;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\events\RegisterCpAlertsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\Cp;
use craft\services\Elements;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use delaneymethod\meilisearcheditor\jobs\DeleteEntryJob;
use delaneymethod\meilisearcheditor\jobs\UpsertEntryJob;
use delaneymethod\meilisearcheditor\models\SettingsModel;
use delaneymethod\meilisearcheditor\services\ClientService;
use delaneymethod\meilisearcheditor\services\IndexesService;
use delaneymethod\meilisearcheditor\services\MapperService;
use delaneymethod\meilisearcheditor\variables\MeilisearchEditorVariable;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Event;
use yii\base\Exception;
use yii\log\FileTarget;

/**
 * MeilisearchEditor Class.
 *
 * @property ClientService  $client
 * @property MapperService  $mapper
 * @property IndexesService $indexes
 */
class MeilisearchEditor extends Plugin
{
    /**
     * @var MeilisearchEditor
     */
    public static MeilisearchEditor $plugin;

    /**
     * @var string
     */
    public string $schemaVersion = '5.0.0';

    /**
     * @var bool
     */
    public bool $hasCpSettings = true;

    /**
     * @var bool
     */
    public bool $hasCpSection = true;

    /**
     * @return void
     */
    public function init(): void
    {
        parent::init();

        self::$plugin = $this;

        $this->setComponents([
            'client' => ClientService::class,
            'mapper' => MapperService::class,
            'indexes' => IndexesService::class,
        ]);

        Craft::getLogger()->dispatcher->targets[] = new FileTarget([
            'logFile' => Craft::getAlias('@storage/logs/meilisearch-editor.log'),
            'categories' => ['meilisearch-editor'],
            'logVars' => [],
        ]);

        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_ALERTS,
            function (RegisterCpAlertsEvent $event) {
                $request = Craft::$app->getRequest();
                if (!$request->getIsCpRequest() || $request->getIsAjax()) {
                    return;
                }

                // Only show on our plugin CP URLs
                if (!in_array('meilisearch-editor', $request->getSegments())) {
                    return;
                }

                if (!$this->client->isAvailable()) {
                    $event->alerts[] = $this->client->getLastError();
                }
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function (ElementEvent $event) {
                $element = $event->element;
                if (!$element instanceof Entry) {
                    return;
                }

                $isPropagation = property_exists($event, 'isPropagation') && $event->isPropagation;

                $siteIds = $isPropagation && $element->siteId ? [$element->siteId] : $this->indexes->siteIdsForPropagation($element);

                // Only upsert live, else delete
                if (Entry::STATUS_LIVE === $element->getStatus()) {
                    foreach ($siteIds as $siteId) {
                        Craft::$app->queue->push(new UpsertEntryJob([
                            'entryId' => $element->id,
                            'siteId' => $siteId,
                        ]));
                    }
                } else {
                    foreach ($siteIds as $siteId) {
                        Craft::$app->queue->push(new DeleteEntryJob([
                            'entryId' => $element->id,
                            'siteId' => $siteId,
                        ]));
                    }
                }
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function (ElementEvent $event) {
                $element = $event->element;
                if (!$element instanceof Entry) {
                    return;
                }

                $siteIds = $element->siteId ? [$element->siteId] : $this->indexes->siteIdsForPropagation($element);
                $siteIds = $siteIds ?: [null];

                foreach ($siteIds as $siteId) {
                    Craft::$app->queue->push(new DeleteEntryJob([
                        'entryId' => $element->id,
                        'siteId' => $siteId,
                    ]));
                }
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_RESTORE_ELEMENT,
            function (ElementEvent $event) {
                $element = $event->element;
                if (!$element instanceof Entry) {
                    return;
                }

                $siteIds = $element->siteId ? [$element->siteId] : $this->indexes->siteIdsForPropagation($element);

                foreach ($siteIds as $siteId) {
                    Craft::$app->queue->push(new UpsertEntryJob([
                        'entryId' => $element->id,
                        'siteId' => $siteId,
                    ]));
                }
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['meilisearch-editor'] = 'meilisearch-editor/indexes/index';
                $event->rules['meilisearch-editor/indexes'] = 'meilisearch-editor/indexes/index';
                $event->rules['meilisearch-editor/indexes/new'] = 'meilisearch-editor/indexes/edit';
                $event->rules['meilisearch-editor/indexes/save'] = 'meilisearch-editor/indexes/save';
                $event->rules['meilisearch-editor/indexes/delete'] = 'meilisearch-editor/indexes/delete';
                $event->rules['meilisearch-editor/indexes/reindex'] = 'meilisearch-editor/indexes/reindex';
                $event->rules['meilisearch-editor/indexes/flush'] = 'meilisearch-editor/indexes/flush';
                $event->rules['meilisearch-editor/indexes/<handle:[a-z0-9\-]+>'] = 'meilisearch-editor/indexes/edit';
                $event->rules['meilisearch-editor/api/fields'] = 'meilisearch-editor/api/fields/index';
                $event->rules['meilisearch-editor/api/fields/nested'] = 'meilisearch-editor/api/fields/nested';
                $event->rules['meilisearch-editor/api/entry-types'] = 'meilisearch-editor/api/entry-types/index';
                $event->rules['meilisearch-editor/api/image-transforms'] = 'meilisearch-editor/api/image-transforms/index';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['meilisearch-editor/keys'] = 'meilisearch-editor/keys/index';
                $event->rules['meilisearch-editor/keys/issue'] = 'meilisearch-editor/keys/issue';
                $event->rules['meilisearch-editor/webhooks'] = 'meilisearch-editor/webhook/index';
                $event->rules['meilisearch-editor/webhooks/reindex'] = 'meilisearch-editor/webhook/reindex';
                $event->rules['meilisearch-editor/webhooks/flush'] = 'meilisearch-editor/webhook/flush';
                $event->rules['meilisearch-editor/webhooks/delete'] = 'meilisearch-editor/webhook/delete';
            }
        );

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;

                $variable->set('meilisearchEditor', MeilisearchEditorVariable::class);
            }
        );
    }

    /**
     * @return array|null
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        $item['label'] = Craft::t('meilisearch-editor', 'Meilisearch Editor');
        $item['url'] = 'meilisearch-editor';

        return $item;
    }

    /**
     * @return Model|null
     */
    protected function createSettingsModel(): ?Model
    {
        return new SettingsModel();
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws Exception
     * @throws LoaderError
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'meilisearch-editor/settings',
            ['settings' => $this->getSettings()],
        );
    }
}
