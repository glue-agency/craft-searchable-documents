<?php

namespace glueagency\searchabledocuments\jobs;

use craft\base\Batchable;
use craft\db\Query;
use craft\db\QueryBatcher;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\errors\ElementNotFoundException;
use craft\i18n\Translation;
use craft\queue\BaseBatchedJob;
use glueagency\searchabledocuments\SearchableDocuments;
use yii\base\Exception;
use yii\base\InvalidConfigException;

class BatchClearDocumentsJob extends BaseBatchedJob {

    public int $batchSize = 50;

    public ?Query $query = null;
    public bool $parseByEntry = false;

    protected function loadData(): Batchable
    {
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
        if ($this->parseByEntry) {
            SearchableDocuments::getInstance()->parserService->clearEntryDocument($item);
        } else {
            SearchableDocuments::getInstance()->parserService->clearDocument($item);
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Translation::prep('_searchable-documents', 'Clearing all documents');
    }
}
