<?php

namespace glueagency\searchabledocuments\migrations;

use Craft;
use craft\db\Migration;
use craft\db\mysql\Schema;
use craft\db\Query;
use craft\fields\PlainText;
use glueagency\searchabledocuments\SearchableDocuments;

/**
 * Install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     * @throws \Throwable
     */
    public function safeUp(): bool
    {
        // Get the group
        $group = (new Query())
            ->select("id")
            ->from("fieldgroups")
            ->where(["id" => 1])
            ->one();

        // Searchable field
        $createSearchField = false;
        if (!$searchableField = Craft::$app->fields->getFieldByHandle(SearchableDocuments::SEARCHABLE_FIELD_HANDLE)) {
            $searchableField = new PlainText([
                'groupId'     => $group['id'],
                'name'        => 'Searchable content',
                'handle'      => SearchableDocuments::SEARCHABLE_FIELD_HANDLE,
                'columnType'  => Schema::TYPE_MEDIUMTEXT,
                'multiline'   => true,
                'initialRows' => "4",
                'searchable'  => true,
            ]);
            $createSearchField = true;
        }

        if ($createSearchField) {
            Craft::$app->fields->saveField($searchableField);
        }

        return Craft::$app->fields->saveField($searchableField);

    }

    /**
     * @inheritdoc
     * @throws \Throwable
     */
    public function safeDown(): bool
    {
        $introField = Craft::$app->fields->getFieldByHandle(SearchableDocuments::SEARCHABLE_FIELD_HANDLE);
        return Craft::$app->fields->deleteFieldById($introField->id);
    }
}
