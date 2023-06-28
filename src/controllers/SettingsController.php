<?php

namespace glueagency\searchabledocuments\controllers;


use Craft;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use glueagency\searchabledocuments\SearchableDocuments;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class SettingsController extends Controller {
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;


    /**
     * @throws BadRequestHttpException
     * @throws InvalidConfigException
     */
    public function actionGetFieldsForSection(): Response
    {
        if(!$handle = $this->request->getQueryParam('handle')) {
            throw new BadRequestHttpException('Handle query param required');
        }

        $fields = SearchableDocuments::getInstance()->getSettings()->getFieldsForSection($handle);

        return $this->asJson($fields);
    }

    /**
     * @throws InvalidConfigException
     * @throws \Exception
     * @throws \Throwable
     */
    public function actionUnlock(): Response
    {
        // Remove field from old section.
        $settings = SearchableDocuments::getInstance()->getSettings();
        $section = Craft::$app->sections->getSectionByHandle($settings->searchableSectionHandle);
        $field = Craft::$app->fields->getFieldByHandle(SearchableDocuments::SEARCHABLE_FIELD_HANDLE);
        $entryTypes = $section->getEntryTypes();
        $defaultEntry = $entryTypes[0];
        $layout = $defaultEntry->getFieldLayout();
        $tabs = $layout->getTabs();
        $elements = $tabs[0]->getElements();
        foreach ($elements as $key => &$element) {
            if ($element instanceof CustomField && $element->fieldUid === $field->uid) {
                unset($elements[$key]);
            }
        }

        $tabs[0]->setElements($elements);
        $layout->setTabs($tabs);

        Craft::$app->fields->saveLayout($layout);
        Craft::$app->sections->saveEntryType($defaultEntry);

        try {
            Craft::$app->projectConfig->set('plugins._searchable-documents.settings.settingsLocked', false);
        } catch (Exception $e) {
            throw new \Exception($e->getMessage());
        }

        return $this->redirect(UrlHelper::cpUrl('settings/plugins/_searchable-documents'));
    }
}
