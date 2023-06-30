<?php

namespace glueagency\searchabledocuments\behaviors;

use craft\elements\Asset;
use craft\elements\Entry;
use craft\web\Session;
use glueagency\searchabledocuments\SearchableDocuments;
use venveo\documentsearch\DocumentSearch as Plugin;
use yii\base\Behavior;

/**
 * @property mixed $contentKeywords
 * @property Session $owner
 * @author Venveo <info@venveo.com>
 */
class SearchableContentBehavior extends Behavior
{
    /**
     * @return string|null
     */
    public function getContentKeywords(): ?string
    {
        /** @var Entry $entry */
        $entry = $this->owner;
        return $entry->{SearchableDocuments::SEARCHABLE_FIELD_HANDLE};
    }
}
