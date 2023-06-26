<?php

namespace glueagency\searchabledocuments\jobs;

use Craft;
use craft\elements\Asset;
use craft\errors\ElementNotFoundException;
use craft\queue\BaseJob;
use glueagency\searchabledocuments\SearchableDocuments;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Parse Documents Job queue job
 */
class ParseDocumentsJob extends BaseJob
{
    public Asset $asset;

    /**
     * @throws \Throwable
     * @throws ElementNotFoundException
     * @throws InvalidConfigException
     * @throws Exception
     */
    function execute($queue): void
    {
        SearchableDocuments::getInstance()->parserService->parseDocument($this->asset);
    }

    protected function defaultDescription(): ?string
    {
        return null;
    }
}
