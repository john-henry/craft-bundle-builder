(function() {
    'use strict';

    Craft.BundleBuilder = Craft.BundleBuilder || {};

    /**
     * Shows each bundle's chosen components under its line on the CP order edit
     * screen, with a "Change variants" modal while the order is incomplete.
     *
     * Commerce renders the line items with Vue, which gives them no IDs, so a
     * line is matched by its SKU and bundle choices. The watch is on
     * the server-rendered #orderDetailsTab: Vue replaces its own mount point
     * (#order-details-app) when it mounts, and loads the order after that, then
     * re-renders lines when the order is edited, so blocks are put back on any
     * change inside the tab.
     */
    Craft.BundleBuilder.OrderVariantEditor = Garnish.Base.extend({
        $app: null,
        observer: null,
        pending: false,

        init: function(settings) {
            this.setSettings(settings, Craft.BundleBuilder.OrderVariantEditor.defaults);
            this.$app = $('#orderDetailsTab');

            if (!this.$app.length) {
                return;
            }

            this.observer = new MutationObserver(this.scheduleInject.bind(this));
            this.observer.observe(this.$app[0], {childList: true, subtree: true});
            this.inject();
        },

        scheduleInject: function() {
            if (this.pending) {
                return;
            }

            this.pending = true;
            requestAnimationFrame(function() {
                this.pending = false;
                this.inject();
            }.bind(this));
        },

        inject: function() {
            var remaining = this.settings.lines.slice();

            this.$app.find('.line-item').each(function(i, el) {
                var $lineItem = $(el);
                var $existing = $lineItem.find('.bb-order-components');
                var line = this._matchLine($lineItem, remaining);

                if (!line) {
                    $existing.remove();

                    return;
                }

                remaining.splice(remaining.indexOf(line), 1);

                if ($existing.length && $existing.attr('data-line-item-id') === String(line.lineItemId)) {
                    return;
                }

                $existing.remove();
                $lineItem.find('code.extralight').first().parent().after(this._buildBlock(line));
            }.bind(this));
        },

        openEditor: function(line) {
            if (this._hasUnsavedOrderChanges()) {
                Craft.cp.displayError(Craft.t('bundle-builder', 'Save or discard your changes to the order before changing bundle variants.'));

                return;
            }

            var $form = $('<form class="modal fitted bb-variant-modal" role="dialog" aria-labelledby="bb-variant-modal-heading"/>');
            var $body = $('<div class="body"/>').appendTo($form);

            $('<h2 id="bb-variant-modal-heading"/>')
                .text(Craft.t('bundle-builder', 'Change bundle variants'))
                .appendTo($body);
            $('<p class="light"/>').text(line.description).appendTo($body);

            line.components.forEach(function(component, i) {
                var id = 'bb-variant-' + line.lineItemId + '-' + i;
                var $field = $('<div class="field"/>').appendTo($body);
                $('<div class="heading"/>')
                    .append($('<label/>').attr('for', id).text(component.productTitle))
                    .appendTo($field);

                var $select = $('<select/>')
                    .attr({id: id, name: 'variants[' + component.productId + ']'});

                component.variants.forEach(function(variant) {
                    $('<option/>')
                        .val(variant.id)
                        .text(variant.sku ? variant.title + ' (' + variant.sku + ')' : variant.title)
                        .prop('selected', variant.id === component.variantId)
                        .appendTo($select);
                });

                $('<div class="input"/>')
                    .append($('<div class="select"/>').append($select))
                    .appendTo($field);
            });

            var $error = $('<p class="error hidden" role="alert"/>').appendTo($body);
            var $footer = $('<div class="footer"/>').appendTo($form);
            var $buttons = $('<div class="buttons right"/>').appendTo($footer);
            var $cancel = $('<button type="button" class="btn"/>')
                .text(Craft.t('bundle-builder', 'Cancel'))
                .appendTo($buttons);
            var $save = $('<button type="submit" class="btn submit"/>')
                .text(Craft.t('bundle-builder', 'Save'))
                .appendTo($buttons);

            var modal = new Garnish.Modal($form, {
                onHide: function() {
                    modal.destroy();
                },
            });

            $cancel.on('click', function() {
                modal.hide();
            });

            $form.on('submit', function(ev) {
                ev.preventDefault();
                $error.addClass('hidden');
                $save.addClass('loading').prop('disabled', true);

                var variants = {};
                $form.find('select').each(function(i, select) {
                    variants[select.name.match(/\[(\d+)\]/)[1]] = select.value;
                });

                Craft.sendActionRequest('POST', 'bundle-builder/orders/update-variants', {
                    data: {
                        orderId: this.settings.orderId,
                        lineItemId: line.lineItemId,
                        variants: variants,
                    },
                })
                    .then(function(response) {
                        if (!response.data.success) {
                            throw new Error(response.data.error);
                        }

                        Craft.cp.displayNotice(Craft.t('bundle-builder', 'Variants changed.'));
                        window.location.reload();
                    })
                    .catch(function(e) {
                        var message = (e.response && e.response.data && (e.response.data.error || e.response.data.message))
                            || (e && e.message)
                            || Craft.t('bundle-builder', 'Couldn’t change the variants.');

                        $error.text(message).removeClass('hidden');
                        $save.removeClass('loading').prop('disabled', false);
                    });
            }.bind(this));
        },

        destroy: function() {
            if (this.observer) {
                this.observer.disconnect();
            }

            this.base();
        },

        // A line is identified by its SKU and its bundleProducts choices, which
        // Commerce's unique options index guarantees no two lines of the same
        // bundle share. SKU alone is only trusted when it leaves one candidate.
        _matchLine: function($lineItem, lines) {
            var sku = $lineItem.find('code.extralight').first().text().trim();
            var choices = this._readChoices($lineItem);
            var candidates = lines.filter(function(line) {
                return line.sku === sku;
            });

            if (choices) {
                return candidates.filter(function(line) {
                    return this._sameChoices(line.choices, choices);
                }, this)[0] || null;
            }

            return candidates.length === 1 ? candidates[0] : null;
        },

        _readChoices: function($lineItem) {
            var $key = $lineItem.find('.line-item-option-key').filter(function(i, el) {
                return $(el).text().trim() === 'bundleProducts:';
            }).first();

            if (!$key.length) {
                return null;
            }

            try {
                return JSON.parse($key.next('.line-item-option-value').text());
            } catch (e) {
                return null;
            }
        },

        _sameChoices: function(a, b) {
            var keysA = Object.keys(a || {});
            var keysB = Object.keys(b || {});

            return keysA.length === keysB.length && keysA.every(function(key) {
                return String(a[key]) === String(b[key]);
            });
        },

        _buildBlock: function(line) {
            var $block = $('<div class="bb-order-components"/>').attr('data-line-item-id', line.lineItemId);
            $('<h4 class="bb-order-components-heading"/>')
                .text(Craft.t('bundle-builder', 'Bundle components'))
                .appendTo($block);

            var $list = $('<ul/>').appendTo($block);

            line.components.forEach(function(component) {
                var label = component.productTitle;

                if (component.variantTitle && component.variantTitle !== component.productTitle) {
                    label += ': ' + component.variantTitle;
                }

                if (component.qtyPerBundle > 1) {
                    label += ' × ' + component.qtyPerBundle;
                }

                var $item = $('<li/>').text(label).appendTo($list);

                if (component.sku) {
                    $item.append(' ').append($('<code class="extralight"/>').text(component.sku));
                }
            });

            if (this.settings.editable && line.lineItemId) {
                var $button = $('<button type="button" class="btn small"/>')
                    .text(Craft.t('bundle-builder', 'Change variants'))
                    .appendTo($block);

                this.addListener($button, 'click', function() {
                    this.openEditor(line);
                });
            }

            return $block;
        },

        // Commerce keeps incomplete orders in edit mode, so edit mode alone
        // says nothing; unsaved changes are what the reload after saving would
        // throw away.
        _hasUnsavedOrderChanges: function() {
            var app = window.OrderDetailsApp;

            return !!(app && app.$store && app.$store.getters && app.$store.getters.hasOrderChanged);
        },
    }, {
        defaults: {
            orderId: null,
            editable: false,
            lines: [],
        },
    });
})();
