<?php

namespace glueagency\searchabledocuments\jobs;

use craft\base\Batchable;
use craft\db\Query;
use craft\db\QueryBatcher;
use craft\elements\Asset;
use craft\errors\ElementNotFoundException;
use craft\i18n\Translation;
use craft\queue\BaseBatchedJob;
use glueagency\searchabledocuments\SearchableDocuments;
use yii\base\Exception;
use yii\base\InvalidConfigException;

class BatchParseDocumentsJob extends BaseBatchedJob {

    public int $batchSize = 50;

    public ?Query $query = null;

    protected function loadData(): Batchable
    {
        if (!$this->query) {
            $fileTypes = SearchableDocuments::getInstance()->getSettings()->getFileTypes();
            $this->query = Asset::find()->kind(array_keys($fileTypes));
        }

        return new QueryBatcher($this->query);
    }

    /**
     * @throws ElementNotFoundException
     * @throws \Throwable
     * @throws InvalidConfigException
     * @throws Exception
     */
    protected function processItem(mixed $item): void
    {
        SearchableDocuments::getInstance()->parserService->parseDocument($item);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Translation::prep('_searchable-documents', 'Parsing all documents');
    }
}
