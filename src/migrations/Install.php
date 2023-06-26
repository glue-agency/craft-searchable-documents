<?php

namespace glueagency\searchabledocuments\migrations;

use Craft;
use craft\db\Migration;
use craft\db\mysql\Schema;
use craft\elements\Asset;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;

/**
 * m230622_064006_add_searchable_content_field migration.
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
        $group = (new \craft\db\Query())
            ->select("id")
            ->from("fieldgroups")
            ->where(["id" => 1])
            ->one();

        // Initialize the field
        $field = new \craft\fields\PlainText([
            "groupId" => $group["id"],
            "name" => "Searchable content",
            "handle" => "glue_searchableContent",
            "columnType" => Schema::TYPE_MEDIUMTEXT,
            "multiline" => true,
            "initialRows" => "4",
        ]);


        if (Craft::$app->getFields()->saveField($field)) {
            $volume = Craft::$app->volumes->getVolumeByHandle('general');
            $layout = $volume->getFieldLayout();
            $tabs = $layout->getTabs();

            $newElement = [
                'type' => CustomField::class,
                'fieldUid' => $field->uid,
                'required' => false,
            ];

            $tabs[0]->setElements(array_merge($tabs[0]->getElements(), [$newElement]));
            $layout->setTabs($tabs);
            return (Craft::$app->fields->saveLayout($layout));
        }

        return false;

    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Find the field
        $introField = Craft::$app->fields->getFieldByHandle("glue_searchableContent");

        // Delete it
        return (Craft::$app->fields->deleteFieldById($introField->id));
    }
}
