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
            case Asset::KIND_POWERPOINT:
                $text = $this->parsePowerpoint($asset);
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
        $filepath = $this->getFilePath($asset);
        if ($this->isZipFile($filepath)) {
            $text = $this->extractContentFromDocx($filepath);
        } else {
            $text = $this->extractContentFromDoc($filepath);
        }
        return $text;
    }

    /**
     * Quick method for extracting text from a word 2007+ document.
     * @param $filepath
     * @return bool|string
     */
    protected function extractContentFromDocx(string $filepath): string
    {
        $response = '';
        $xml_filename = 'word/document.xml';
        $zip = new \ZipArchive();

        if (true === $zip->open($filepath)) {
            $xml_index = $zip->locateName($xml_filename);
            if ($xml_index !== false) {
                $xml_data = $zip->getFromIndex($xml_index);
                //process data to retain line breaks between sections of text and remove all other tags.
                $response = str_replace('</w:r></w:p></w:tc><w:tc>', ' ', $xml_data);
                $response = str_replace('</w:r></w:p>', "\r\n", $response);
                $response = strip_tags($response);
            }
            $zip->close();
        }

        if (empty($response)) {
            $response = '';
        }

        return $response;
    }

    /**
     * Quick method for extracting text from a word 97 ".doc" document.
     * Only grabs text from the main document. Does not include headers, notes or footnotes.
     * @author Adapted from doc2txt by gouravmehta - https://www.phpclasses.org/package/7934-PHP-Convert-MS-Word-Docx-files-to-text.html
     * @author Adapted from Q/A by M Khalid Junaid - https://stackoverflow.com/questions/19503653/how-to-extract-text-from-word-file-doc-docx-xlsx-pptx-php
     * @see https://docs.microsoft.com/en-us/openspecs/office_file_formats/ms-doc/ccd7b486-7881-484c-a137-51170af7cc22
     * @param $filepath
     * @return string
     */
    protected function extractContentFromDoc(string $filepath): string
    {
        $fileHandle = fopen($filepath, 'r');
        $line = @fread($fileHandle, filesize($filepath));
        //Break document apart using paragraph markers.
        $lines = explode(chr(0x0D), $line);
        $response = '';

        foreach ($lines as $current_line) {

            $pos = strpos($current_line, chr(0x00));

            if (($pos !== false) || (strlen($current_line) == 0)) {
                //no op
            } else {
                $response .= $current_line . ' ';
            }
        }

        $response = preg_replace('/[^a-zA-Z0-9\s,.\-\n\r\t@\/_()]/', '', $response);

        //Technique pulls text in on first line. Subsequent lines are noise.
        $nl = stripos($response, "\n");
        if ($nl) {
            $response = substr($response, 0, $nl);
        }
        return $response;
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
     * Extract text for any type included in Asset::KIND_POWERPOINT.
     * @param Asset $asset
     * @return string
     * @throws InvalidConfigException
     * @see craft/vendor/craftcms/cms/src/helpers/Assets.php Line 525
     */
    public function parsePowerpoint(Asset $asset): string
    {
        $filepath = $this->getFilePath($asset);

        if ($this->isZipFile($filepath)) {
            $text = $this->extractContentFromPptx($filepath);
        } else {
            //TODO: Add support for powerpoint 97 (.ppt) documents
            Craft::info('Cannot extract text from ' . $filepath, __METHOD__);
            $text = '';
        }
        return $text;
    }

    /**
     * Extract content from a powerpoint pptx file.
     * @param string $filepath
     * @return string
     */
    protected function extractContentFromPptx(string $filepath): string
    {
        $zip_handle = new \ZipArchive();
        $response = '';

        if (true === $zip_handle->open($filepath)) {

            $slide_number = 1; //loop through slide files
            $doc = new \DOMDocument();

            while (($xml_index = $zip_handle->locateName('ppt/slides/slide' . $slide_number . '.xml')) !== false) {

                $xml_data = $zip_handle->getFromIndex($xml_index);

                $doc->loadXML($xml_data, LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_NOERROR | LIBXML_NOWARNING);
                $response .= strip_tags($doc->saveXML());

                $slide_number++;

            }
            $zip_handle->close();
        }
        return $response;
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
     * Extract text for any type included in Asset::KIND_TEXT.
     * @param Asset $asset
     * @return string
     * @throws InvalidConfigException
     * @see craft/vendor/craftcms/cms/src/helpers/Assets.php Line 525
     */
    public function extractContentFromText(Asset $asset): string
    {
        $filepath = $this->getFilePath($asset);
        Craft::info('Extracting text content from Text : ' . $filepath, __METHOD__);

        $text = file_get_contents($filepath, false);

        if (empty($text)) {
            $text = '';
        }
        return $text;
    }

    /**
     * Detect if a file is a pkzip archive.
     * @param string $filepath
     * @return bool
     */
    public function isZipFile(string $filepath): bool
    {
        $fh = fopen($filepath, 'r');
        $bytes = fread($fh, 4);
        fclose($fh);
        //according to zip file spec, all zip files start with the same 4 bytes.
        return ('504b0304' === bin2hex($bytes));
    }

    /**
     * @throws InvalidConfigException
     */
    public function getFilePath(Asset $asset): string
    {
        return $asset->getVolume()->getFs()->getRootPath() . DIRECTORY_SEPARATOR . $asset->getPath();
    }
}
