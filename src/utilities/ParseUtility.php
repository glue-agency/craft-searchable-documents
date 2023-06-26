<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace glueagency\searchabledocuments\utilities;

use Craft;
use craft\base\Utility;

/**
 * Sync class offers the Shopify Sync utilities.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 */
class ParseUtility extends Utility
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('app', 'Parse documents');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'searchable-documents-parse';
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $view = Craft::$app->getView();

        return $view->renderTemplate('_searchable-documents/utilities/_parse.twig');
    }
}
