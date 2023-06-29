<?php

namespace glueagency\searchabledocuments\console\controllers;

use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Queue;
use glueagency\searchabledocuments\jobs\BatchClearDocumentsJob;
use glueagency\searchabledocuments\SearchableDocuments;
use yii\console\ExitCode;

class ConsoleDocumentsController extends Controller
{
    public function actionClearSearchableContent(): bool
    {
        $searchableSectionHandle = SearchableDocuments::getInstance()->getSettings()->searchableSectionHandle;
        $query = Entry::find()->section($searchableSectionHandle);
        Queue::push(new BatchClearDocumentsJob([
            'parseByEntry' => true,
            'query' => $query
        ]));

        return ExitCode::OK;
    }
}
