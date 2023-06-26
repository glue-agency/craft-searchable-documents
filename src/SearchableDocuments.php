<?php

namespace glueagency\searchabledocuments;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\DefineFieldLayoutCustomFieldsEvent;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\DefineHtmlEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementActionsEvent;
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
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = ParseUtility::class;
            }
        );
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('searchabledocuments', SearchableDocumentsVariable::class);
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

        if ($this->getSettings()->autoParse) {
            Event::on(
                Asset::class,
                Asset::EVENT_AFTER_SAVE,
                function (ModelEvent $event) {
                    /** @var Asset $asset */
                    $asset = $event->sender;

                    if (ElementHelper::isDraftOrRevision($asset)) {
                        return;
                    }

                    $fileTypes = SearchableDocuments::getInstance()->getSettings()->getFileTypes();

                    if ($asset->firstSave && $event->isNew && empty($asset->glue_searchableContent) && in_array($asset->kind, array_keys($fileTypes))) {
                        Queue::push(new ParseDocumentsJob([
                            'description' => Craft::t('_searchable-documents', 'Parsing “{title}”', [
                                'title' => $asset->title,
                            ]),
                            'asset'       => $asset
                        ]));
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
        // Register element action to assets for clearing transforms
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
