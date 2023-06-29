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
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Reader\MsDoc;
use PhpOffice\PhpWord\Reader\Word2007;
use PhpOffice\PhpWord\Settings;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\Config;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use Spatie\PdfToText\Pdf;

/**
 * Parser Service service
 */
class ParserService extends Component
{
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
            case 'pdf':
                $text = $this->parsePdf($url);
                break;
            case 'word':
                $text = $this->parseWord($asset);
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
     * @throws InvalidConfigException|\PhpOffice\PhpWord\Exception\Exception
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
     * @throws \PhpOffice\PhpWord\Exception\Exception
     * @throws \Exception
     */
    public function convertWordToPdf(Asset $asset, $content): string
    {
        $text = '';

        Settings::setPdfRendererName(Settings::PDF_RENDERER_DOMPDF);
        Settings::setPdfRendererPath('.');
        $objWriter = IOFactory::createWriter($content, 'PDF');

        $runtimePath = FileHelper::normalizePath(Craft::$app->getPath()->getRuntimePath() . '/searchable_documents/');
        if (FileHelper::createDirectory($runtimePath)) {
            $tempFilePath = Craft::$app->getPath()->getRuntimePath() . '/searchable_documents/'  . $asset->getFilename(false) . '.pdf';
            $objWriter->save($tempFilePath);

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
