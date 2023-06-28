<?php

namespace glueagency\searchabledocuments\controllers;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\errors\ElementNotFoundException;
use craft\helpers\Queue;
use craft\web\Controller;
use Exception;
use glueagency\searchabledocuments\jobs\BatchParseDocumentsJob;
use glueagency\searchabledocuments\SearchableDocuments;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Documents controller
 */
class DocumentsController extends Controller
{
    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * _searchable-documents/documents action
     * @throws BadRequestHttpException
     * @throws Exception
     * @throws \Throwable
     */
    public function actionIndex(int $asset_id): Response
    {
        $asset = Asset::find()->id($asset_id)->one();
        if (!$asset) {
            return $this->redirect($this->getPostedRedirectUrl());
        }
        if (SearchableDocuments::getInstance()->parserService->parseDocument($asset)) {
            $this->setSuccessFlash(Craft::t('_searchable-documents', 'Document successfully parsed'));
        } else {
            $this->setFailFlash(Craft::t('_searchable-documents', 'Something went wrong!'));
        }

        return $this->redirect($asset->cpEditUrl);
    }

    /**
     * @throws ElementNotFoundException
     * @throws \Throwable
     * @throws InvalidConfigException
     * @throws \yii\base\Exception
     */
    public function actionParseForEntry(int $entry_id, array $asset_ids): Response
    {
        $assets = Asset::find()->id($asset_ids)->all();
        $entry = Entry::find()->id($entry_id)->one();
        if (!$assets || !$entry) {
            return $this->redirect($this->getPostedRedirectUrl());
        }

        if (SearchableDocuments::getInstance()->parserService->parseMultipleDocumentsForEntry($entry, $assets)) {
            $this->setSuccessFlash(Craft::t('_searchable-documents', 'Documents successfully parsed'));
        } else {
            $this->setFailFlash(Craft::t('_searchable-documents', 'Something went wrong!'));
        }

        return $this->redirect($entry->cpEditUrl);
    }

    public function actionParseAll(): ?Response
    {
        Queue::push(new BatchParseDocumentsJob([
            'parseAll' => true,
        ]));

        return $this->asSuccess(Craft::t('_searchable-documents','Parsing of documents pushed to queue'));
    }

    public function actionParseAllEntries(): ?Response
    {
        Queue::push(new BatchParseDocumentsJob([
            'parseAllEntries' => true,
        ]));

        return $this->asSuccess(Craft::t('_searchable-documents','Parsing of documents pushed to queue'));
    }
}
