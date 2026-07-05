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
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\DiscountType;
use johnhenry\bundlebuilder\enums\PricingStrategy;
use yii\base\InvalidArgumentException;

/**
 * Bundle pricing field.
 *
 * A mandatory native field-layout element that renders the bundle's pricing
 * controls: the pricing strategy (fixed or automatic), the fixed base and
 * promotional prices (as store-currency money inputs), and the automatic
 * discount type/amount. Each input posts back to the matching bundle attribute.
 *
 * @author JohnHenry <info@johnhenry.ie>
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
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function inputHtml(ElementInterface $element = null, bool $static = false): ?string
    {
        if (!$element instanceof Bundle) {
            throw new InvalidArgumentException(static::class . ' can only be used in bundle field layouts.');
        }

        $view = Craft::$app->getView();
        $currency = $element->getStore()->getCurrency();
        $currencyCode = $currency->getCode();

        // The visible label is suppressed (blank) since this select sits directly
        // under the field layout's own "Pricing" label; a second "Strategy"
        // label right beneath it just reads as a duplicated heading. The
        // accessible name is preserved via an explicit aria-label instead.
        $strategyHtml = Cp::selectFieldHtml([
            'label' => '__blank__',
            'id' => 'pricingStrategy',
            'name' => 'pricingStrategy',
            'value' => $element->pricingStrategy,
            'disabled' => $static,
            'inputAttributes' => [
                'aria' => ['label' => Craft::t('bundle-builder', 'Strategy')],
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
                    'id' => 'promotional-price',
                    'label' => Craft::t('bundle-builder', 'Promotional Price'),
                ]),
                ['class' => 'flex'],
            ),
            ['data-pricing' => 'fixed'],
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
            ['data-pricing' => 'automatic'],
        );

        $view->registerJs(<<<JS
(function() {
    var strategy = document.getElementById('pricingStrategy');
    function togglePricing() {
        document.querySelectorAll('[data-pricing]').forEach(function(el) {
            el.classList.toggle('hidden', el.getAttribute('data-pricing') !== strategy.value);
        });
    }
    if (strategy) {
        strategy.addEventListener('change', togglePricing);
        togglePricing();
    }
})();
JS, $view::POS_END);

        return $strategyHtml . $fixedHtml . $automaticHtml;
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
        return Craft::t('bundle-builder', 'Pricing');
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a money value formatted for display, unless the attribute has errors.
     *
     * @param float|null $value The raw money value.
     * @param array $errors Any validation errors on the attribute.
     * @return float|string|null The display value.
     * @author JohnHenry <info@johnhenry.ie>
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
