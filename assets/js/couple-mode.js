(function ($) {
    'use strict';

    /* ---------------------------------------------------------
     * Helpers
     * --------------------------------------------------------- */

    function isCoupleModeProduct() {
        return $('body').hasClass('thready-couple-mode');
    }

    function csvToArray(csv) {
        if (!csv) return [];
        return csv.split(',').map(v => v.trim()).filter(Boolean);
    }

    function labelize(value) {
        return value
            .replace(/-/g, ' ')
            .replace(/\b\w/g, l => l.toUpperCase());
    }

    function isColorGroup(targetName) {
        return targetName.indexOf('_color') !== -1;
    }

    function getAddToCartButton() {
        return $('.single_add_to_cart_button');
    }

    /* ---------------------------------------------------------
     * Add to cart button control
     * --------------------------------------------------------- */

    function allCoupleFieldsSelected() {
        let complete = true;

        $('.thready-couple-field input[type="hidden"]').each(function () {
            if (!$(this).val()) {
                complete = false;
            }
        });

        return complete;
    }

    function disableAddToCart() {
        const $btn = getAddToCartButton();
        if (!$btn.length) return;

        $btn
            .addClass('disabled wc-variation-selection-needed')
            .prop('disabled', true);
    }

    function enableAddToCart() {
        const $btn = getAddToCartButton();
        if (!$btn.length) return;

        $btn
            .removeClass('disabled wc-variation-selection-needed')
            .prop('disabled', false);
    }

    function updateAddToCartState() {
        if (!isCoupleModeProduct()) return;

        if (allCoupleFieldsSelected()) {
            enableAddToCart();
        } else {
            disableAddToCart();
        }
    }

    /* ---------------------------------------------------------
     * Swatch builder (custom, Woo-style)
     * --------------------------------------------------------- */

    function buildSwatchGroup(targetName, values) {
        if (!values.length) return '';

        const isColor = isColorGroup(targetName);

        let html = `
            <div class="thready-swatch-group-wrapper">
                <ul class="variable-items-wrapper
                           ${isColor ? 'color-variable-items-wrapper' : 'button-variable-items-wrapper'}
                           wvs-style-squared
                           thready-swatch-group"
                    role="radiogroup"
                    data-thready-target="${targetName}">
        `;

        values.forEach(function (value) {
            const label = labelize(value);
            const hex   = isColor && window.thready_color_map
                ? window.thready_color_map[value]
                : null;

            html += `
                <li class="variable-item
                           ${isColor ? 'color-variable-item color-variable-item-' + value : 'button-variable-item'}"
                    role="radio"
                    aria-checked="false"
                    tabindex="0"
                    data-value="${value}"
                    title="${label}">
                    <div class="variable-item-contents">
            `;

            if (isColor && hex) {
                html += `
                    <span class="variable-item-span variable-item-span-color"
                          style="background-color:${hex};"></span>
                `;
            } else {
                html += `
                    <span class="variable-item-span variable-item-span-button">
                        ${label}
                    </span>
                `;
            }

            html += `
                    </div>
                </li>
            `;
        });

        html += `
                </ul>
                <input type="hidden" name="${targetName}" value="">
            </div>
        `;

        return html;
    }

    /* ---------------------------------------------------------
     * Layout helpers
     * --------------------------------------------------------- */

    function buildSection(title) {
        return `
            <tr class="thready-couple-section">
                <td colspan="2">
                    <h3 class="thready-couple-title">${title}</h3>
                    <hr class="thready-couple-divider">
                </td>
            </tr>
        `;
    }

    function buildField(label, swatchHtml) {
        if (!swatchHtml) return '';

        return `
            <tr class="thready-couple-field">
                <td colspan="2">
                    <div class="thready-field-label">${label}</div>
                    ${swatchHtml}
                </td>
            </tr>
        `;
    }

    /* ---------------------------------------------------------
     * Render UI
     * --------------------------------------------------------- */

    function renderCoupleUI(variation) {
        if (!isCoupleModeProduct()) return;

        $('.thready-couple-section, .thready-couple-field').remove();

        const herSizes  = csvToArray(variation.thready_her_sizes);
        const herColors = csvToArray(variation.thready_her_colors);
        const herEmb    = csvToArray(variation.thready_her_embroidery_colors);

        const himSizes  = csvToArray(variation.thready_him_sizes);
        const himColors = csvToArray(variation.thready_him_colors);
        const himEmb    = csvToArray(variation.thready_him_embroidery_colors);

        let rows = '';

        rows += buildSection('Za nju');
        rows += buildField('Veličina', buildSwatchGroup('thready_her_size', herSizes));
        rows += buildField('Boja', buildSwatchGroup('thready_her_color', herColors));
        rows += buildField('Boja veza', buildSwatchGroup('thready_her_embroidery_color', herEmb));

        rows += buildSection('Za njega');
        rows += buildField('Veličina', buildSwatchGroup('thready_him_size', himSizes));
        rows += buildField('Boja', buildSwatchGroup('thready_him_color', himColors));
        rows += buildField('Boja veza', buildSwatchGroup('thready_him_embroidery_color', himEmb));

        if (!rows.trim()) return;

        $('.variations tbody').append(rows);

        disableAddToCart();
    }

    /* ---------------------------------------------------------
     * Swatch interaction
     * --------------------------------------------------------- */

    function bindSwatchEvents() {
        $(document).on('click', '.thready-swatch-group .variable-item', function () {
            const $item  = $(this);
            const $group = $item.closest('.thready-swatch-group');
            const value  = $item.data('value');
            const target = $group.data('thready-target');

            $group.find('.variable-item')
                .removeClass('selected')
                .attr('aria-checked', 'false');

            $item
                .addClass('selected')
                .attr('aria-checked', 'true');

            $group
                .closest('.thready-swatch-group-wrapper')
                .find(`input[name="${target}"]`)
                .val(value);

            updateAddToCartState();
        });
    }

    /* ---------------------------------------------------------
     * Validation on submit
     * --------------------------------------------------------- */

    function validateOnSubmit() {
        $('form.cart').on('submit', function (e) {
            if (!isCoupleModeProduct()) return;

            if (!allCoupleFieldsSelected()) {
                e.preventDefault();
                alert('Molimo izaberite sve opcije za oba proizvoda.');
                return false;
            }
        });
    }

    /* ---------------------------------------------------------
     * Hooks
     * --------------------------------------------------------- */

    $(document).on('found_variation', function (e, variation) {
        renderCoupleUI(variation);
        updateAddToCartState();
    });

    $(document).on('reset_data hide_variation', function () {
        $('.thready-couple-section, .thready-couple-field').remove();
        disableAddToCart();
    });

    $(document).ready(function () {
        bindSwatchEvents();
        validateOnSubmit();
        disableAddToCart();
    });

})(jQuery);
