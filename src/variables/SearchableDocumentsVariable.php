<?php
/**
 * Translate plugin for Craft CMS 3.x
 *
 * Translation management plugin for Craft CMS
 *
 * @link      https://enupal.com
 * @copyright Copyright (c) 2018 Enupal
 */

namespace glueagency\searchabledocuments\variables;

use glueagency\searchabledocuments\SearchableDocuments;

class SearchableDocumentsVariable
{

    /**
     * @return array
     */
    public function getFileTypes(): array
    {
        return SearchableDocuments::getInstance()->getSettings()->getFileTypes();
    }

    public function getParsers(): array
    {
        return SearchableDocuments::getInstance()->getSettings()->getParsers();
    }
}
