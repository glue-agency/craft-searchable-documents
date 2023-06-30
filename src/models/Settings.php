<?php

namespace glueagency\searchabledocuments\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use craft\fields\Assets;
use craft\helpers\Assets as AssetsHelper;
use PhpOffice\PhpWord\Reader\Word2007 as WordParser;
use Smalot\PdfParser\Parser as PdfParser;
use yii\base\InvalidConfigException;

/**
 * Searchable Documents settings
 */
class Settings extends Model
{
    public string $pluginName = "Searchable Documents";
    public bool $autoParseEntry = false;

    public ?string $searchableSectionHandle = null;
    public ?string $searchableFieldHandle = null;

    public bool $settingsLocked = false;
    public string $pdfToTextBinary = '/usr/local/bin/pdftotext';
    public function attributeLabels(): array
    {
        return [
            'pluginName' => Craft::t('_searchable-documents', 'Plugin name'),
        ];
    }


    public function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['pluginName', 'searchableSectionHandle', 'searchableFieldHandle'], 'required'];
        return $rules;
    }

    public function getParsers(): array
    {
        return [
            [
                'value' => PdfParser::class,
                'label' => 'PDF parser',
            ],
            [
                'value' => WordParser::class,
                'label' => 'Word parser',
            ],
        ];
    }

    public function getFileTypes(): array
    {
        return [
            Asset::KIND_PDF =>[
                'label' => AssetsHelper::getFileKindLabel(Asset::KIND_PDF),
                'value' => Asset::KIND_PDF
            ],
            Asset::KIND_WORD => [
                'label' => AssetsHelper::getFileKindLabel(Asset::KIND_WORD),
                'value' => Asset::KIND_WORD
            ],
            Asset::KIND_EXCEL => [
                'label' => AssetsHelper::getFileKindLabel(Asset::KIND_EXCEL),
                'value' => Asset::KIND_EXCEL
            ],
            Asset::KIND_POWERPOINT => [
                'label' => AssetsHelper::getFileKindLabel(Asset::KIND_POWERPOINT),
                'value' => Asset::KIND_POWERPOINT
            ],
            Asset::KIND_TEXT => [
                'label' => AssetsHelper::getFileKindLabel(Asset::KIND_TEXT),
                'value' => Asset::KIND_TEXT
            ],
        ];
    }

    public function getSections(): array
    {
        $sections = [
            [
                'value' => '',
                'label' => Craft::t('_searchable-documents', 'Select a section'),
                'disabled' => true,
            ]
        ];
        foreach (Craft::$app->sections->getAllSections() as $section) {
            $sections[] = [
                'value' => $section->handle,
                'label' => $section->name
            ];
        }

        return $sections;
    }

    /**
     * @throws InvalidConfigException
     */
    public function getFieldsForSection($sectionHandel): array
    {
        $entryTypes = Craft::$app->sections->getSectionByHandle($sectionHandel)->getEntryTypes();
        $defaultEntryType = $entryTypes[0];
        $layout = $defaultEntryType->getFieldLayout();
        $customFields = $layout->getCustomFields();
        $fields = [
            [
                'value' => '',
                'label' => Craft::t('_searchable-documents', 'Select a field'),
                'disabled' => true,
            ]
        ];
        foreach ($customFields as $field) {
            if ($field instanceof Assets) {
                $fields[] = [
                    'value' => $field->handle,
                    'label' => $field->name,
                ];
            }
        }

        return $fields;
    }
}
