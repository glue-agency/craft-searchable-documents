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

class BatchParseDocumentsJob extends BaseBatchedJob {

    public int $batchSize = 50;

    public ?Query $query = null;
    public bool $parseAll = false;
    public bool $parseAllEntries = false;

    public bool $parseByEntry = false;

    public array $entryIds = [];

    protected function loadData(): Batchable
    {
        if ($this->parseAll) {
            $fileTypes = SearchableDocuments::getInstance()->getSettings()->getFileTypes();
            $this->query = Asset::find()->kind(array_keys($fileTypes));
        }

        if ($this->parseAllEntries) {
            $searchableSectionHandle = SearchableDocuments::getInstance()->getSettings()->searchableSectionHandle;
            $this->query = Entry::find()->section($searchableSectionHandle);
            $this->parseByEntry = true;
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
        if ($this->parseByEntry) {
            $searchableFieldHandle = SearchableDocuments::getInstance()->getSettings()->searchableFieldHandle;
            $asset = $item->{$searchableFieldHandle}->one();
            if ($asset) {
                SearchableDocuments::getInstance()->parserService->parseEntryDocument($item, $asset);
            }
        } else {
            SearchableDocuments::getInstance()->parserService->parseDocument($item);
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Translation::prep('_searchable-documents', 'Parsing all documents');
    }
}
