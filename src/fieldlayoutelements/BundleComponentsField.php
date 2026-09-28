<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\Json;
use johnhenry\bundlebuilder\assets\BundleEditorAsset;
use johnhenry\bundlebuilder\elements\Bundle;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;

/**
 * Bundle components field.
 *
 * Mandatory native field for the bundle's component picker (product and
 * quantity rows). Rows post as `products[<index>][productId|qty]`, which
 * {@see Bundle::setProducts()} reads back.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleComponentsField extends BaseNativeField
{
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public bool $mandatory = true;

    /**
     * @inheritdoc
     */
    public string $attribute = 'products';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param ElementInterface|null $element The element being edited.
     * @param bool $static Whether the field should be static (read-only).
     * @return string|null The input HTML.
     * @throws InvalidArgumentException If the element is not a bundle.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        if (!$element instanceof Bundle) {
            throw new InvalidArgumentException(static::class . ' can only be used in bundle field layouts.');
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(BundleEditorAsset::class);

        $html = $view->renderTemplate('bundle-builder/_fields/components', [
            'bundle' => $element,
            'static' => $static,
        ]);

        if (!$static) {
            $view->registerTranslations('bundle-builder', ['Couldn’t add a product row.', 'Couldn’t update the product row.']);
            $view->registerJs(sprintf(
                'new Craft.BundleBuilder.ComponentsInput(%s, %s);',
                Json::htmlEncode('#' . $view->namespaceInputId('bundle-products')),
                Json::htmlEncode([
                    'nextIndex' => count($element->getProducts()),
                    'namespace' => $view->getNamespace(),
                    'typeId' => $element->typeId,
                    'sources' => $element->typeId ? $element->getType()->componentSources : '*',
                ]),
            ));
        }

        return $html;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param ElementInterface|null $element The element being edited.
     * @param bool $static Whether the field is static.
     * @return string|null The default label.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('bundle-builder', 'Components');
    }

    /**
     * @inheritdoc
     *
     * @param ElementInterface|null $element The element being edited.
     * @param bool $static Whether the field is static.
     * @return string|null The default instructions.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function defaultInstructions(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('bundle-builder', 'The products included in this bundle. Customers choose a variant for each at checkout.');
    }
}
