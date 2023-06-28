<?php

namespace glueagency\searchabledocuments;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\DefineFieldLayoutCustomFieldsEvent;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\DefineHtmlEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\models\FieldLayout;
use craft\services\Fields;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use glueagency\searchabledocuments\elementactions\ParseDocumentsAction;
use glueagency\searchabledocuments\jobs\ParseDocumentsJob;
use glueagency\searchabledocuments\models\Settings;
use glueagency\searchabledocuments\services\ParserService;
use glueagency\searchabledocuments\utilities\ParseUtility;
use glueagency\searchabledocuments\variables\SearchableDocumentsVariable;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Event;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Searchable Documents plugin
 *
 * @method static SearchableDocuments getInstance()
 * @method Settings getSettings()
 * @method ParserService getParserService()
 * @property-read ParserService $parserService
 */
class SearchableDocuments extends Plugin
{
    public const SEARCHABLE_FIELD_HANDLE = 'glue_searchableContent';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'parserService' => ParserService::class
            ],
        ];
    }

    /**
     */
    public function init(): void
    {
        parent::init();
        $this->setComponents(
            ['parserService' => ParserService::class]
        );
        Craft::$app->onInit(function () {
            // Defer most setup tasks until Craft is fully initialized
            $this->_registerUtilityTypes();
            $this->attachEventHandlers();
            $this->registerElementActions();
            $this->registerLogTarget();
        });

    }

    /**
     * @throws InvalidConfigException
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }


    /**
     * @throws Exception
     * @throws InvalidConfigException
     * @throws \Exception
     */
    public function afterSaveSettings(): void
    {
        // Add searchable content field to selected entry.
        $settings = $this->getSettings();
        $section = Craft::$app->sections->getSectionByHandle($settings->searchableSectionHandle);
        if ($section && !$settings->settingsLocked) {

            try {
                Craft::$app->projectConfig->set('plugins._searchable-documents.settings.settingsLocked', true);
            } catch (Exception $e) {
                throw new \Exception($e->getMessage());
            }

            $entryTypes = $section->getEntryTypes();
            $defaultEntry = $entryTypes[0];
            $layout = $defaultEntry->getFieldLayout();
            $tabs = $layout->getTabs();
            $field = Craft::$app->fields->getFieldByHandle(self::SEARCHABLE_FIELD_HANDLE);

            foreach ($tabs[0]->getElements() as $element) {
               if ($element instanceof CustomField && $element->fieldUid === $field->uid) {
                    return;
               }
            }

            $newElement = [
                'type' => CustomField::class,
                'fieldUid' => $field->uid,
                'required' => false,
            ];

            $tabs[0]->setElements(array_merge($tabs[0]->getElements(), [$newElement]));
            $layout->setTabs($tabs);
            Craft::$app->fields->saveLayout($layout);
        }
    }


    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws Exception
     * @throws LoaderError
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('_searchable-documents/settings.twig', [
            'plugin'   => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * Registers the utilities.
     *
     * @since 3.0
     */
    private function _registerUtilityTypes(): void
    {
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITY_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = ParseUtility::class;
            }
        );
    }

    private function attachEventHandlers(): void
    {
        $fileTypes = $this->getSettings()->getFileTypes();
        $searchableSectionHandle = $this->getSettings()->searchableSectionHandle;
        $searchableFieldHandle = $this->getSettings()->searchableFieldHandle;

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('searchabledocuments', SearchableDocumentsVariable::class);
            }
        );


        if ($searchableSectionHandle && $searchableFieldHandle) {
            Event::on(
                Entry::class,
                Element::EVENT_DEFINE_ADDITIONAL_BUTTONS,
                function (DefineHtmlEvent $event) use ($searchableSectionHandle, $searchableFieldHandle, $fileTypes) {
                    $entry = $event->sender;
                    if ($entry->section->handle === $searchableSectionHandle) {
                        $assetIds = $entry->{$searchableFieldHandle}->kind(array_keys($fileTypes))->ids();
                        $text = Craft::t('_searchable-documents', 'Parse document');
                        $count = count($assetIds);
                        if ($count > 1) {
                            $text = Craft::t('_searchable-documents', 'Parse documents');
                        }
                        if (count($assetIds)) {
                            // Return the button HTML
                            $url = UrlHelper::actionUrl('_searchable-documents/documents/parse-for-entry', ['entry_id' => $entry->id, 'asset_ids' => $assetIds]);
                            $event->html .= '<a href="' . $url . '" class="btn">' . $text . '</a>';
                        }
                    }
                }
            );

            Event::on(
                Asset::class,
                Element::EVENT_DEFINE_ADDITIONAL_BUTTONS,
                function (DefineHtmlEvent $event) {
                    $asset = $event->sender;
                    $fileTypes = $this->getSettings()->getFileTypes();
                    if (in_array($asset->kind, array_keys($fileTypes))) {
                        // Return the button HTML
                        $url = UrlHelper::actionUrl('_searchable-documents/documents/index', ['asset_id' => $asset->id]);
                        $event->html .= '<a href="' . $url . '" class="btn">Parse document</a>';
                    }
                }
            );

            Event::on(
                Entry::class,
                Element::EVENT_AFTER_SAVE,
                function (ModelEvent $event) use ($searchableSectionHandle, $searchableFieldHandle, $fileTypes) {
                    /** @var Entry $entry */
                    $entry = $event->sender;
                    if (ElementHelper::isDraftOrRevision($entry)) {
                        return;
                    }

                    if ($entry->getType()->handle !== $searchableSectionHandle) {
                        return;
                    }

                    if (!$entry->{$searchableFieldHandle}) {
                        return;
                    }

                    $asset = $entry->{$searchableFieldHandle}->one();
                    if (!$asset && !empty($entry->{self::SEARCHABLE_FIELD_HANDLE})) {
                        $entry->setFieldValue(self::SEARCHABLE_FIELD_HANDLE, null);
                        Craft::$app->elements->saveElement($entry);
                    }
                    if (
                        $entry->firstSave &&
                        $asset &&
                        in_array($asset->kind, array_keys($fileTypes)) &&
                        empty($entry->{self::SEARCHABLE_FIELD_HANDLE}) &&
                        $this->getSettings()->autoParseEntry
                    ) {
                        $this->parserService->parseEntryDocument($entry, $asset);
                    }
                }
            );
        }
    }

    /**
     * Register element actions
     */
    private function registerElementActions(): void
    {

        $searchableSectionHandle = $this->getSettings()->searchableSectionHandle;
        $searchableFieldHandle = $this->getSettings()->searchableFieldHandle;
        if ($searchableSectionHandle && $searchableFieldHandle) {
            Event::on(Entry::class, Element::EVENT_REGISTER_ACTIONS,
                static function (RegisterElementActionsEvent $event) {
                    $event->actions[] = ParseDocumentsAction::class;
                }
            );
        }
        Event::on(Asset::class, Element::EVENT_REGISTER_ACTIONS,
            static function (RegisterElementActionsEvent $event) {
                $event->actions[] = ParseDocumentsAction::class;
            }
        );
    }


    /**
     * Logs an informational message to our custom log target.
     */
    public static function info(string $message): void
    {
        Craft::info($message, 'searchable-documents');
    }

    /**
     * Logs an error message to our custom log target.
     */
    public static function error(string $message): void
    {
        Craft::error($message, 'searchable-documents');
    }

    /**
     * Registers a custom log target, keeping the format as simple as possible.
     */
    private function registerLogTarget(): void
    {
        Craft::getLogger()->dispatcher->targets['searchable-documents'] = new MonologTarget([
            'name'            => 'searchable-documents',
            'categories'      => ['searchable-documents'],
            'level'           => LogLevel::INFO,
            'logContext'      => false,
            'allowLineBreaks' => true,
            'formatter'       => new LineFormatter(
                format: "%datetime% %message%\n",
                dateFormat: 'Y-m-d H:i:s',
            ),
        ]);
    }
}
