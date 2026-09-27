<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\commerce\helpers\Currency;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\web\twig\TemplateLoaderException;
use johnhenry\bundlebuilder\assets\BundleEditorAsset;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\DiscountType;
use johnhenry\bundlebuilder\enums\PricingStrategy;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;

/**
 * Bundle pricing field.
 *
 * Mandatory native field for the bundle's pricing: strategy, fixed base and
 * promotional prices, and the automatic discount type and amount.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundlePricingField extends BaseNativeField
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
    public string $attribute = 'pricingStrategy';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param ElementInterface|null $element The element being edited.
     * @param bool $static Whether the field should be static (read-only).
     * @return string|null The input HTML.
     * @throws InvalidArgumentException If the element is not a bundle.
     * @throws TemplateLoaderException
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
        $currency = $element->getStore()->getCurrency();

        // Hide the inactive inputs in the markup itself, so they don't flash up
        // before the script runs; the script only handles changes.
        $isAutomatic = $element->pricingStrategy === PricingStrategy::Automatic->value;
        $currencyCode = $currency->getCode();

        // No visible label, as the field's own "Pricing" label sits right above;
        // aria-label keeps the accessible name.
        $strategyHtml = Cp::selectFieldHtml([
            'label' => '__blank__',
            'id' => 'pricingStrategy',
            'name' => 'pricingStrategy',
            'value' => $element->pricingStrategy,
            'disabled' => $static,
            'inputAttributes' => [
                'aria' => ['label' => Craft::t('bundle-builder', 'Strategy')],
                'data' => ['pricing-strategy' => true],
            ],
            'options' => [
                ['value' => PricingStrategy::Fixed->value, 'label' => Craft::t('bundle-builder', 'Fixed price')],
                ['value' => PricingStrategy::Automatic->value, 'label' => Craft::t('bundle-builder', 'Automatic (sum of components − discount)')],
            ],
        ]);

        $fixedHtml = Html::tag('div',
            Html::tag('div',
                Cp::fieldHtml(Currency::moneyInputHtml($this->_money($element->getBasePrice(), $element->getErrors('basePrice')), [
                    'id' => 'base-price',
                    'name' => 'basePrice',
                    'currency' => $currencyCode,
                    'currencyLabel' => $currencyCode,
                    'disabled' => $static,
                    'size' => 12,
                    'errors' => $element->getErrors('basePrice'),
                ]), [
                    'id' => 'base-price',
                    'label' => Craft::t('bundle-builder', 'Bundle price'),
                ]) .
                Cp::fieldHtml(Currency::moneyInputHtml($this->_money($element->getBasePromotionalPrice(), $element->getErrors('basePromotionalPrice')), [
                    'id' => 'base-promotional-price',
                    'name' => 'basePromotionalPrice',
                    'currency' => $currencyCode,
                    'currencyLabel' => $currencyCode,
                    'disabled' => $static,
                    'size' => 12,
                    'errors' => $element->getErrors('basePromotionalPrice'),
                ]), [
                    'id' => 'base-promotional-price',
                    'label' => Craft::t('bundle-builder', 'Promotional Price'),
                ]),
                ['class' => 'flex'],
            ),
            ['data-pricing' => 'fixed', 'class' => $isAutomatic ? 'hidden' : null],
        );

        $automaticHtml = Html::tag('div',
            Cp::selectFieldHtml([
                'label' => Craft::t('bundle-builder', 'Discount type'),
                'id' => 'discountType',
                'name' => 'discountType',
                'value' => $element->discountType,
                'disabled' => $static,
                'options' => [
                    ['value' => DiscountType::Percentage->value, 'label' => Craft::t('bundle-builder', 'Percentage off')],
                    ['value' => DiscountType::Flat->value, 'label' => Craft::t('bundle-builder', 'Flat amount off')],
                ],
            ]) .
            Cp::textFieldHtml([
                'label' => Craft::t('bundle-builder', 'Discount amount'),
                'instructions' => Craft::t('bundle-builder', 'For percentage, enter e.g. 10 for 10%.'),
                'id' => 'discountAmount',
                'name' => 'discountAmount',
                'value' => $element->discountAmount,
                'disabled' => $static,
                'size' => 10,
            ]),
            ['data-pricing' => 'automatic', 'class' => $isAutomatic ? null : 'hidden'],
        );

        $view->registerAssetBundle(BundleEditorAsset::class);
        $view->registerJs(sprintf(
            'new Craft.BundleBuilder.PricingInput(%s);',
            Json::htmlEncode('#' . $view->namespaceInputId('bundle-pricing')),
        ));

        return Html::tag('div', $strategyHtml . $fixedHtml . $automaticHtml, ['id' => 'bundle-pricing']);
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
        return Craft::t('bundle-builder', 'Pricing');
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a money value formatted for display, unless the attribute has errors.
     *
     * @param float|null $value The raw money value.
     * @param string[] $errors Any validation errors on the attribute.
     * @return float|string|null The display value.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _money(?float $value, array $errors): float|string|null
    {
        if ($value !== null && empty($errors)) {
            return Craft::$app->getFormatter()->asDecimal($value);
        }

        return $value;
    }
}
