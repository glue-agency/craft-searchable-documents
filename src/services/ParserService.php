<?php

namespace glueagency\searchabledocuments\services;

use Craft;
use craft\elements\Asset;
use craft\errors\ElementNotFoundException;
use craft\helpers\FileHelper;
use glueagency\searchabledocuments\SearchableDocuments;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Reader\MsDoc;
use PhpOffice\PhpWord\Reader\Word2007;
use PhpOffice\PhpWord\Settings;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;

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
    public function parseDocument(Asset $asset): bool
    {
        $kind = $asset->kind;
        $url = $asset->getUrl();
        $text = false;
        switch ($kind) {
            case 'pdf':
                $text = $this->parsePdf($url);
                break;
            case 'word':
                $text = $this->parseWord($asset);
                break;
        }
        if ($text) {
            $asset->setFieldValues([
                'glue_searchableContent' => $text
            ]);

            if (!Craft::$app->elements->saveElement($asset)) {
                SearchableDocuments::error(json_encode($asset->getErrors()));
                return false;
            }
            SearchableDocuments::info("Searchable content saved for $asset->title");
            return true;
        }
        return false;
    }

    /**
     * @throws \Exception
     */
    public function parsePdf($filePath): string
    {
        $parser = new Parser();
        return $parser->parseFile($filePath)->getText();
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

        $url = $asset->getVolume()->getFs()->getRootPath() . DIRECTORY_SEPARATOR . $asset->getPath();
        $content = $parser->load($url);
        $sections = $content->getSections();
        $text = '';
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
}
