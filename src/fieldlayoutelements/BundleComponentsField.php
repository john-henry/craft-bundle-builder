<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;
use johnhenry\bundlebuilder\elements\Bundle;
use yii\base\InvalidArgumentException;

/**
 * Bundle components field.
 *
 * A mandatory native field-layout element that renders the bundle's component
 * picker (a product + quantity repeater) inside the native element editor. The
 * posted rows are read back through the bundle's `products` attribute.
 *
 * @author JohnHenry <info@johnhenry.ie>
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
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function inputHtml(ElementInterface $element = null, bool $static = false): ?string
    {
        if (!$element instanceof Bundle) {
            throw new InvalidArgumentException(static::class . ' can only be used in bundle field layouts.');
        }

        return Craft::$app->getView()->renderTemplate('bundle-builder/_fields/components', [
            'bundle' => $element,
            'static' => $static,
        ]);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param ElementInterface|null $element The element being edited.
     * @param bool $static Whether the field is static.
     * @return string|null The default label.
     * @author JohnHenry <info@johnhenry.ie>
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
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function defaultInstructions(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('bundle-builder', 'The products included in this bundle. Customers choose a variant for each at checkout.');
    }
}
