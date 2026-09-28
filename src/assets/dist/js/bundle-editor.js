(function() {
    'use strict';

    Craft.BundleBuilder = Craft.BundleBuilder || {};

    /**
     * The bundle editor's component picker: sortable product and quantity rows.
     * "Add a product" opens Craft's product selector; each product picked comes
     * back from the server as a row, and rows are removed in place.
     *
     * Garnish's `activate` event binds per element (it doesn't delegate), so
     * each row's remove control is bound as the row is added.
     *
     * A row's variant choices belong to its product, so when the product is
     * swapped in the row's picker, the row is fetched again for the new one.
     */
    Craft.BundleBuilder.ComponentsInput = Garnish.Base.extend({
        $container: null,
        $rows: null,
        $addBtn: null,
        dragSort: null,
        nextIndex: 0,
        observers: null,

        init: function(container, settings) {
            this.$container = $(container);
            this.setSettings(settings, Craft.BundleBuilder.ComponentsInput.defaults);

            this.$rows = this.$container.find('.bundle-product-rows');
            this.$addBtn = this.$container.find('.bundle-products-add');
            this.nextIndex = this.settings.nextIndex;
            this.observers = [];

            var $existingRows = this.$rows.children('.bundle-product-row');

            this.dragSort = new Garnish.DragSort($existingRows, {
                handle: '.move.icon',
                axis: Garnish.Y_AXIS,
                container: this.$rows,
            });

            $existingRows.each(function(i, row) {
                this._bindRow($(row));
            }.bind(this));

            this.addListener(this.$addBtn, 'activate', 'openPicker');
            this.addListener(this.$rows, 'click', 'onRowActionClick');
            this._updateMoveActions();
        },

        // The keyboard alternative to dragging a row by its handle: moves the
        // row's DOM node the same way a drop would, so the posted field order
        // (which the row's position in the form determines, not a hidden
        // input) matches exactly what dragging produces.
        onRowActionClick: function(ev) {
            var $target = $(ev.target).closest('[data-action="moveUp"], [data-action="moveDown"]');

            if (!$target.length) {
                return;
            }

            var $row = $target.closest('.bundle-product-row');
            var direction = $target.data('action') === 'moveUp' ? 'up' : 'down';

            this.moveRow($row, direction);
        },

        moveRow: function($row, direction) {
            var $sibling = direction === 'up' ? $row.prev('.bundle-product-row') : $row.next('.bundle-product-row');

            if (!$sibling.length) {
                return;
            }

            if (direction === 'up') {
                $row.insertBefore($sibling);
            } else {
                $row.insertAfter($sibling);
            }

            this._updateMoveActions();

            var $rows = this.$rows.children('.bundle-product-row');
            var position = $rows.index($row) + 1;

            Craft.cp.announce(Craft.t('bundle-builder', 'Product moved to position {position} of {total}.', {
                position: position,
                total: $rows.length,
            }));
        },

        // Hides each row's Move up/down menu item at the ends of the list,
        // matching Craft's own editable table action menu.
        _updateMoveActions: function() {
            var $rows = this.$rows.children('.bundle-product-row');
            var lastIndex = $rows.length - 1;

            $rows.each(function(index, row) {
                var $row = $(row);

                $row.find('[data-action="moveUp"]').closest('li').toggleClass('hidden', index === 0);
                $row.find('[data-action="moveDown"]').closest('li').toggleClass('hidden', index === lastIndex);
            });
        },

        // Opens Craft's product selector, as a Products field's add button
        // does. A fresh modal each time, so the products already in the rows,
        // saved or not, are the ones greyed out.
        openPicker: function() {
            var modal = Craft.createElementSelectorModal('craft\\commerce\\elements\\Product', {
                sources: this.settings.sources === '*' ? null : this.settings.sources,
                multiSelect: true,
                disabledElementIds: this._currentProductIds(),
                onSelect: function(elements) {
                    this.addProducts(elements.map(function(element) {
                        return element.id;
                    }));
                }.bind(this),
                onFadeOut: function() {
                    modal.destroy();
                },
            });
        },

        addProducts: function(productIds) {
            if (!productIds.length) {
                return;
            }

            var index = this.nextIndex;
            this.nextIndex += productIds.length;
            this.$addBtn.addClass('loading');

            this._fetchRows(index, productIds, null)
                .then(function(data) {
                    return this._insertRows(data, function($rows) {
                        this.$rows.append($rows);
                    }.bind(this));
                }.bind(this))
                .catch(function() {
                    Craft.cp.displayError(Craft.t('bundle-builder', 'Couldn’t add a product row.'));
                })
                .finally(function() {
                    this.$addBtn.removeClass('loading');
                }.bind(this));
        },

        // Fetches the row again for its newly picked product, keeping its
        // place, index and quantity, so the variant choices match the product.
        refreshRow: function($row, productId) {
            var qty = parseInt($row.find('input[name$="[qty]"]').val(), 10) || 1;

            this._fetchRows($row.data('index'), [productId], qty)
                .then(function(data) {
                    return this._insertRows(data, function($rows) {
                        this.dragSort.removeItems($row);
                        $row.replaceWith($rows);
                    }.bind(this));
                }.bind(this))
                .catch(function() {
                    // Leave the row usable: the old product's variants no
                    // longer apply, and the picker still needs watching.
                    $row.find('.bundle-product-variants').remove();
                    this._bindRow($row, true);
                    Craft.cp.displayError(Craft.t('bundle-builder', 'Couldn’t update the product row.'));
                }.bind(this));
        },

        removeRow: function(ev) {
            var $row = $(ev.currentTarget).closest('.bundle-product-row');
            var $next = $row.next('.bundle-product-row');

            this._unobserve($row);

            this.dragSort.removeItems($row);
            $row.remove();
            this._updateMoveActions();

            // Keep keyboard focus in the field rather than dropping it on the page.
            if ($next.length) {
                $next.find('.bundle-product-remove').trigger('focus');
            } else {
                this.$addBtn.trigger('focus');
            }
        },

        destroy: function() {
            if (this.dragSort) {
                this.dragSort.destroy();
            }

            this.observers.forEach(function(entry) {
                entry.observer.disconnect();
            });
            this.observers = [];

            this.base();
        },

        // The products in the rows: each product picker's selection, plus the
        // hidden ID a deleted product's row keeps.
        _currentProductIds: function() {
            return this.$rows.find('input[type="hidden"][name*="[productId]"]').map(function(i, input) {
                return parseInt(input.value, 10);
            }).get().filter(function(id) {
                return id > 0;
            });
        },

        _fetchRows: function(index, productIds, qty) {
            return Craft.sendActionRequest('POST', 'bundle-builder/bundles/product-rows', {
                data: {
                    index: index,
                    productIds: productIds,
                    qty: qty,
                    existingProductIds: this._currentProductIds(),
                    namespace: this.settings.namespace,
                    typeId: this.settings.typeId,
                },
            }).then(function(response) {
                return response.data;
            });
        },

        // The rows go into the page before their scripts run: each picker's
        // script looks its container up by ID as soon as it's appended.
        _insertRows: async function(data, insert) {
            var $rows = $(data.html.trim()).filter('.bundle-product-row');

            insert($rows);
            await Craft.appendHeadHtml(data.headHtml);
            await Craft.appendBodyHtml(data.bodyHtml);
            this._initRows($rows);
        },

        _initRows: function($rows) {
            Craft.initUiElements($rows);
            this.dragSort.addItems($rows);
            $rows.each(function(i, row) {
                this._bindRow($(row));
            }.bind(this));
            this._updateMoveActions();
        },

        _bindRow: function($row, observeOnly) {
            if (!observeOnly) {
                this.addListener($row.find('.bundle-product-remove'), 'activate', 'removeRow');
            }

            // The picker adds and removes its selected element in .elements,
            // so watching that catches a product being swapped or cleared.
            var elements = $row.find('.elementselect .elements').get(0);

            if (!elements) {
                return;
            }

            var observer = new MutationObserver(function() {
                this._onProductChange($row);
            }.bind(this));

            observer.observe(elements, {childList: true});
            this.observers.push({row: $row.get(0), observer: observer});
        },

        _onProductChange: function($row) {
            var $input = $row.find('.elementselect input[type="hidden"][name*="[productId]"]');
            var productId = $input.length ? parseInt($input.val(), 10) : 0;

            if (productId === (parseInt($row.attr('data-product-id'), 10) || 0)) {
                return;
            }

            $row.attr('data-product-id', productId || '');

            if (productId) {
                this._unobserve($row);
                this.refreshRow($row, productId);
            } else {
                $row.find('.bundle-product-variants').remove();
            }
        },

        _unobserve: function($row) {
            this.observers = this.observers.filter(function(entry) {
                if (entry.row === $row.get(0)) {
                    entry.observer.disconnect();
                    return false;
                }

                return true;
            });
        },
    }, {
        defaults: {
            nextIndex: 0,
            namespace: null,
            typeId: null,
            sources: '*',
        },
    });

    /**
     * The bundle editor's pricing field: shows the fixed or automatic pricing
     * inputs to match the selected strategy.
     */
    Craft.BundleBuilder.PricingInput = Garnish.Base.extend({
        $container: null,
        $strategy: null,

        init: function(container) {
            this.$container = $(container);
            this.$strategy = this.$container.find('[data-pricing-strategy]');

            this.addListener(this.$strategy, 'change', 'toggle');
            this.toggle();
        },

        toggle: function() {
            var strategy = this.$strategy.val();

            // The hidden strategy's inputs are disabled too, so they don't post
            this.$container.find('[data-pricing]').each(function(i, el) {
                var hidden = el.getAttribute('data-pricing') !== strategy;
                el.classList.toggle('hidden', hidden);
                $(el).find('input, select, textarea').prop('disabled', hidden);
            });
        },
    });
})();
