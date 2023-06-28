<?php

namespace glueagency\searchabledocuments\elementactions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQueryInterface;
use craft\elements\db\EntryQuery;
use craft\helpers\Queue;
use glueagency\searchabledocuments\jobs\BatchParseDocumentsJob;
use glueagency\searchabledocuments\SearchableDocuments;

class ParseDocumentsAction extends ElementAction
{
    public function getTriggerLabel(): string
    {
        return Craft::t('_searchable-documents', 'Parse documents');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        if ($query instanceof AssetQuery){
            $fileTypes = SearchableDocuments::getInstance()->getSettings()->getFileTypes();
            Queue::push(new BatchParseDocumentsJob([
                'query' => $query->kind(array_keys($fileTypes))
            ]));
        }

        if ($query instanceof EntryQuery) {
            $searchableSectionHandle = SearchableDocuments::getInstance()->getSettings()->searchableSectionHandle;
            Queue::push(new BatchParseDocumentsJob([
                'parseByEntry' => true,
                'query' => $query->section($searchableSectionHandle)
            ]));
        }

        $this->setMessage(Craft::t('_searchable-documents', 'Parsing of documents pushed to queue'));
        return true;
    }

    public function getTriggerHtml(): ?string
    {
        Craft::$app->getView()->registerJsWithVars(fn($type) => <<<JS
            (() => {
                new Craft.ElementActionTrigger({
                    type: $type,
                    validateSelection: \$selectedItems => {
                        for (let i = 0; i < \$selectedItems.length; i++) {
                            if (!Garnish.hasAttr(\$selectedItems.eq(i).find('.element'), 'data-deletable')) {
                                return false;
                            }
                        }
                        return true;
                    },
                });
            })();
        JS, [static::class]);

        return null;
    }
}
