<?php

namespace glueagency\searchabledocuments\services;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\errors\ElementNotFoundException;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\Search as SearchHelper;
use glueagency\searchabledocuments\SearchableDocuments;
use PhpOffice\PhpSpreadsheet\IOFactory as PhpSpreadsheetFactory;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory as PhpWordFactory;
use PhpOffice\PhpWord\Reader\MsDoc;
use PhpOffice\PhpWord\Reader\Word2007;
use PhpOffice\PhpWord\Settings as PhpWordSettings;
use Spatie\PdfToText\Pdf;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Parser Service service
 */
class ParserService extends Component
{
    public string $tempFilePath = '';

    /**
     * @throws Exception
     */
    public function __construct($config = [])
    {
        parent::__construct($config);

        $this->tempFilePath = FileHelper::normalizePath(Craft::$app->getPath()->getRuntimePath() . '/searchable_documents/');
    }

    /**
     * @throws ElementNotFoundException
     * @throws \Throwable
     * @throws InvalidConfigException
     * @throws Exception
     */
    public function parseDocument(Asset $asset, $return_text = false): bool|string
    {
        $kind = $asset->kind;
        $url = $this->getFilePath($asset);
        $text = false;
        switch ($kind) {
            case Asset::KIND_PDF:
                $text = $this->parsePdf($url);
                break;
            case Asset::KIND_WORD:
                $text = $this->parseWord($asset);
                break;
            case Asset::KIND_EXCEL:
                $text = $this->parseExcel($asset);
                break;
        }
        if (!empty($text)) {
            if ($return_text) {
                return $text;
            }
            return $this->saveToElement($asset, $text);
        }

        return false;
    }

    /**
     * @throws Exception
     * @throws \Throwable
     * @throws ElementNotFoundException
     */
    public function clearDocument(Asset $asset): void
    {
        $this->saveToElement($asset, null, false);
    }

    /**
     * @throws \Throwable
     * @throws ElementNotFoundException
     * @throws InvalidConfigException
     * @throws Exception
     */
    public function parseEntryDocument(Entry $entry, Asset $asset): bool
    {
        $text = $this->parseDocument($asset, true);
        return $this->saveToElement($entry, $text);
    }

    /**
     * @throws Exception
     * @throws \Throwable
     * @throws ElementNotFoundException
     */
    public function clearEntryDocument(Entry $entry): void
    {
        $this->saveToElement($entry, null, false);
    }

    /**
     * @throws \Throwable
     * @throws ElementNotFoundException
     * @throws InvalidConfigException
     * @throws Exception
     */
    public function parseMultipleDocumentsForEntry(Entry $entry, array $assets): bool
    {
        $text = '';
        foreach ($assets as $asset) {
            $text .= $this->parseDocument($asset, true);
        }

        if (empty($text)) return false;

        return $this->saveToElement($entry, $text);
    }

    /**
     * @throws ElementNotFoundException
     * @throws \Throwable
     * @throws Exception
     */
    public function saveToElement(Element $element, $text, $normalize = true): bool
    {
        $site = $element->getSite();
        if ($normalize) {
            $text = SearchHelper::normalizeKeywords($text, [], true, $site->language);
        }

        $element->setFieldValue(SearchableDocuments::SEARCHABLE_FIELD_HANDLE, $text);

        if (!Craft::$app->elements->saveElement($element)) {
            SearchableDocuments::error(json_encode($element->getErrors()));
            return false;
        }
        $type = get_class($element);
        SearchableDocuments::info("Searchable content saved for $type: $element->title");

        return true;
    }

    /**
     * @throws \Exception
     */
    public function parsePdf($filePath): string
    {
        $options = [
            'nopgbrk',
        ];

        $binaryPath = App::parseEnv(SearchableDocuments::getInstance()->getSettings()->pdfToTextBinary);

        return Pdf::getText($filePath, $binaryPath, $options);
    }

    /**
     * @throws InvalidConfigException
     */
    public function parseWord(Asset $asset): string
    {
        $extension = $asset->extension;
        $parser = new Word2007();
        if ($extension === 'doc') {
            $parser = new MsDoc();
        }

        $url = $this->getFilePath($asset);
        $text = '';

        try {
            $content = $parser->load($url);
            $sections = $content->getSections();
            foreach ($sections as $section) {
                $sectionElement = $section->getElements();
                foreach ($sectionElement as $elementValue) {
                    if ($elementValue instanceof TextRun) {
                        $secondSectionElement = $elementValue->getElements();
                        foreach ($secondSectionElement as $secondSectionElementValue) {
                            if ($secondSectionElementValue instanceof Text) {
                                $text .= $secondSectionElementValue->getText() . ' ';
                            }
                        }
                    }
                }
            }

            if (empty($text)) {
                try {
                    $text = $this->convertWordToPdf($asset, $content);
                } catch (Exception $e) {
                    SearchableDocuments::error($e->getMessage());
                }
            }
        } catch (\Exception $e) {
            SearchableDocuments::error($e->getMessage());
        }


        return $text;
    }

    /**
     * @throws Exception
     * @throws InvalidConfigException
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function parseExcel(Asset $asset): string
    {
        $url = $this->getFilePath($asset);
        $testAgainstFormats = [
            PhpSpreadsheetFactory::READER_XLS,
            PhpSpreadsheetFactory::READER_XLSX,
        ];
        $reader = PhpSpreadsheetFactory::createReaderForFile($url, $testAgainstFormats);
        $reader->setReadDataOnly(true);

        $content = $reader->load($url);

        return $this->convertExcelToPdf($asset, $content);
    }

    /**
     * @throws Exception
     * @throws \PhpOffice\PhpWord\Exception\Exception
     * @throws \Exception
     */
    public function convertWordToPdf(Asset $asset, $content): string
    {
        $text = '';

        PhpWordSettings::setPdfRendererName(PhpWordSettings::PDF_RENDERER_MPDF);
        PhpWordSettings::setPdfRendererPath('.');
        $objWriter = PhpWordFactory::createWriter($content, 'PDF');

        if (FileHelper::createDirectory($this->tempFilePath)) {
            $tempFilePath = $this->tempFilePath  . $asset->getFilename(false) . '.pdf';
            $objWriter->save($tempFilePath);

            $text = $this->parsePdf($tempFilePath);

            FileHelper::deleteFileAfterRequest($tempFilePath);
        }
        return $text;
    }

    /**
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     * @throws InvalidConfigException
     * @throws Exception
     * @throws \Exception
     */
    public function convertExcelToPdf(Asset $asset, $content): string
    {
        $text = '';

        if (FileHelper::createDirectory($this->tempFilePath)) {
            $tempFilePath = $this->tempFilePath  . $asset->getFilename(false) . '.pdf';
            $writer = new Mpdf($content);
            $writer->setUseInlineCss(false);
            $writer->writeAllSheets();
            $writer->setGenerateSheetNavigationBlock(false);
            $writer->save($tempFilePath);
            $text = $this->parsePdf($tempFilePath);
            FileHelper::deleteFileAfterRequest($tempFilePath);
        }

        return $text;
    }

    /**
     * @throws InvalidConfigException
     */
    public function getFilePath(Asset $asset): string
    {
        return $asset->getVolume()->getFs()->getRootPath() . DIRECTORY_SEPARATOR . $asset->getPath();
    }
}
