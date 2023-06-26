<?php

namespace glueagency\searchabledocuments\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use craft\helpers\Assets as AssetsHelper;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\Reader\Word2007 as WordParser;

/**
 * Searchable Documents settings
 */
class Settings extends Model
{
    public string $pluginName = "Searchable Documents";

    public bool $autoParse = false;

    public array $parseOptions = [
        'pdf' => PdfParser::class,
        'word' => WordParser::class,
    ];

    public function attributeLabels(): array
    {
        return [
            'pluginName' => Craft::t('searchable-documents', 'Plugin name'),
        ];
    }


    public function defineRules(): array
    {
        return [
            [['pluginName'], 'required'],
        ];
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
            Asset::KIND_TEXT => [
                'label' => AssetsHelper::getFileKindLabel(Asset::KIND_TEXT),
                'value' => Asset::KIND_TEXT
            ],
        ];
    }
}
