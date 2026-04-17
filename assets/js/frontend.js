jQuery(document).ready(function($) {
    // Track form submission state
    var formSubmitting = false;
    var sizeSelectorInitialized = false;
    var currentProductType = '';
    var isManualProductTypeChange = false;
    
    // Image transition variables
    var imageTransitionInProgress = false;
    var currentImageSrc = '';
    
    // Greenshift handling
    var currentVariationId = null;

    // Create and inject spinner styles
    function injectSpinnerStyles() {
        if ($('#thready-spinner-styles').length) return;
        
        var spinnerCSS = `
            <style id="thready-spinner-styles">
                .thready-image-spinner {
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    width: 40px;
                    height: 40px;
                    border: 3px solid #EEEEEE;
                    border-top: 3px solid #CF2929;
                    border-radius: 50%;
                    animation: thready-spin 0.8s linear infinite;
                    z-index: 1000;
                    display: none;
                }
                
                .thready-image-loading {
                    position: relative;
                }
                
                .thready-image-loading::after {
                    content: '';
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(255, 255, 255, 0.8);
                    z-index: 999;
                }
                
                @keyframes thready-spin {
                    0% { transform: translate(-50%, -50%) rotate(0deg); }
                    100% { transform: translate(-50%, -50%) rotate(360deg); }
                }
                
                .gspb-product-image-gallery {
                    position: relative;
                }
            </style>
        `;
        $('head').append(spinnerCSS);
    }
    
    // Create spinner element
    function createSpinner() {
        if ($('#thready-image-spinner').length) return;
        
        var spinnerHTML = '<div id="thready-image-spinner" class="thready-image-spinner"></div>';
        $('.gspb-product-image-gallery').append(spinnerHTML);
    }
    
    // Show spinner
    function showSpinner() {
        var $spinner = $('#thready-image-spinner');
        var $gallery = $('.gspb-product-image-gallery');
        
        if (!$spinner.length) {
            createSpinner();
            $spinner = $('#thready-image-spinner');
        }
        
        $gallery.addClass('thready-image-loading');
        $spinner.show();
    }
    
    // Hide spinner
    function hideSpinner() {
        var $spinner = $('#thready-image-spinner');
        var $gallery = $('.gspb-product-image-gallery');
        
        $gallery.removeClass('thready-image-loading');
        $spinner.hide();
    }

    // Function to get URL parameters
    function getUrlParameter(name) {
        name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
        var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
        var results = regex.exec(location.search);
        return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
    }
    
    // Function to reinitialize zoom after image change
    function reinitializeZoom() {
        if (typeof window.threadyProductZoomInit === 'function') {
            window.threadyProductZoomInit();
        } else {
            setTimeout(function() {
                window.dispatchEvent(new Event('resize'));
            }, 100);
        }
    }
    
    // Simple function to remove imagelink class from non-current variation images
    function updateImagelinkClasses(currentVariationId) {
        // Remove imagelink class from ALL variation images first
        $('.slide-variation-images a, .slide-variation-hidden a').removeClass('imagelink');
        
        // Add imagelink class back only for current variation
        if (currentVariationId) {
            $(`.slide-variation-${currentVariationId} a`).addClass('imagelink');
        }
    }
    
    // Initialize on page load
    function initImagelinkHandler() {
        // Check if we have an initial variation from URL
        var urlParams = new URLSearchParams(window.location.search);
        var urlVariationId = urlParams.get('variation_id');
        
        // Check if WooCommerce has a variation selected
        var $variationId = $('input.variation_id');
        var wooVariationId = $variationId.length && $variationId.val() ? $variationId.val() : null;
        
        var initialVariationId = urlVariationId || wooVariationId;
        
        if (initialVariationId) {
            currentVariationId = initialVariationId;
            updateImagelinkClasses(initialVariationId);
        } else {
            // If no variation selected, remove imagelink from all variation images
            updateImagelinkClasses(null);
        }
    }
    
    // Function to update gallery image with smooth transition
    function updateGalleryImage(newImageSrc, altText, titleText) {
        if (imageTransitionInProgress) return;
        
        var $gallery = $('.gspb-product-image-gallery');
        var $swiperWrapper = $gallery.find('.swiper-wrapper');
        var $mainSlide = $swiperWrapper.find('.swiper-slide-main-image').first();
        
        if (!$mainSlide.length || currentImageSrc === newImageSrc) {
            return;
        }
        
        imageTransitionInProgress = true;
        currentImageSrc = newImageSrc;
        
        showSpinner();
        
        var $mainImage = $mainSlide.find('img');
        var $imageLink = $mainSlide.find('a');
        
        $mainImage.css({
            'transition': 'opacity 0.4s ease-in-out',
            'opacity': '0'
        });
        
        var img = new Image();
        
        img.onload = function() {
            $mainImage.attr({
                'src': newImageSrc,
                'alt': altText,
                'srcset': '',
                'data-main-featured-image-src': newImageSrc,
                'data-natural-width': img.naturalWidth,
                'data-natural-height': img.naturalHeight
            });
            
            if ($imageLink.length) {
                $imageLink.attr({
                    'href': newImageSrc,
                    'title': titleText
                });
            }
            
            requestAnimationFrame(function() {
                $mainImage.css('opacity', '1');
                
                setTimeout(function() {
                    hideSpinner();
                    imageTransitionInProgress = false;
                    reinitializeZoom();
                }, 400);
            });
        };
        
        img.onerror = function() {
            $mainImage.css('opacity', '1');
            setTimeout(function() {
                hideSpinner();
                imageTransitionInProgress = false;
                reinitializeZoom();
            }, 400);
        };
        
        img.src = newImageSrc;
    }
    
    // Function to get image alt text from variation
    function getImageAltText(variation) {
        if (variation.image && variation.image.alt) {
            return variation.image.alt;
        }
        
        var productName = $('.product_title').text() || '';
        var attributes = [];
        
        if (variation.attributes) {
            $.each(variation.attributes, function(key, value) {
                if (value) {
                    attributes.push(value);
                }
            });
        }
        
        if (attributes.length > 0) {
            return productName + ' - ' + attributes.join(', ');
        }
        
        return productName || 'Product Image';
    }
    
    // Function to get image title text
    function getImageTitleText(variation) {
        if (variation.image && variation.image.title) {
            return variation.image.title;
        }
        return getImageAltText(variation);
    }

    // Initialize size selector
    function initSizeSelector() {
        if (sizeSelectorInitialized) return;
        
        var $sizeContainer = $('#thready-size-selector');
        if ($sizeContainer.length) {
            $sizeContainer.remove();
        }
        
        var sizeSelectorHTML = `
            <div id="thready-size-selector" style="display: none; margin-bottom: 20px;">
                <div class="thready-size-label" style="margin-bottom: 10px; font-weight: bold;">
                    ${thready_frontend_params.size_label}
                </div>
                <ul class="variable-items-wrapper button-variable-items-wrapper thready-size-wrapper" 
                    role="radiogroup" aria-label="${thready_frontend_params.size_label}">
                    <li class="thready-size-notice" style="color: #666; font-style: italic; padding: 10px;">
                        ${thready_frontend_params.select_variation_first}
                    </li>
                </ul>
            </div>
        `;
        
        if ($('.variations tr:has(.label:contains("Boja"))').length) {
            $('.variations tr:has(.label:contains("Boja"))').after(sizeSelectorHTML);
        } else {
            $('.variations').after(sizeSelectorHTML);
        }
        
        sizeSelectorInitialized = true;
    }
    
    // Get available colors for a product type
    function getAvailableColorsForProductType(productType) {
        var variationData = $('form.variations_form').data('product_variations');
        var availableColors = [];
        
        if (variationData) {
            $.each(variationData, function(index, variation) {
                if (variation.attributes['attribute_pa_tip-proizvoda'] === productType) {
                    var color = variation.attributes['attribute_pa_boja'];
                    if (color && availableColors.indexOf(color) === -1) {
                        availableColors.push(color);
                    }
                }
            });
        }
        
        return availableColors;
    }
    
    // Prevent Woo Variation Swatches from disabling product types
    function preventProductTypeDisabling() {
        var $productTypeWrapper = $('.variable-items-wrapper[data-attribute_name="attribute_pa_tip-proizvoda"]');
        
        $productTypeWrapper.find('.variable-item').removeClass('disabled').css({
            'opacity': '1',
            'pointer-events': 'auto'
        }).attr('tabindex', '0');
        
        $('#pa_tip-proizvoda option').prop('disabled', false);
    }
    
    // Update color swatches based on available colors
    function updateColorSwatches(availableColors) {
        var $colorWrapper = $('.variable-items-wrapper[data-attribute_name="attribute_pa_boja"]');
        
        if ($colorWrapper.length) {
            $colorWrapper.find('.variable-item').each(function() {
                var $colorItem = $(this);
                var colorValue = $colorItem.data('value');
                
                if (availableColors.indexOf(colorValue) !== -1) {
                    $colorItem.removeClass('disabled').show();
                } else {
                    $colorItem.addClass('disabled').hide();
                }
            });
        }
    }
    
    // Handle product type selection
    function handleProductTypeSelection(selectedProductType) {
        isManualProductTypeChange = true;
        currentProductType = selectedProductType;
        
        $('input[name="attribute_pa_tip-proizvoda"]').val(selectedProductType);
        $('#pa_tip-proizvoda').val(selectedProductType);
        
        var availableColors = getAvailableColorsForProductType(selectedProductType);
        updateColorSwatches(availableColors);
        
        var $colorWrapper = $('.variable-items-wrapper[data-attribute_name="attribute_pa_boja"]');
        var currentSelectedColor = $colorWrapper.find('.variable-item.selected').data('value');
        
        if (currentSelectedColor && availableColors.indexOf(currentSelectedColor) === -1) {
            if (availableColors.length > 0) {
                var firstAvailableColor = availableColors[0];
                var $firstColorItem = $colorWrapper.find('.variable-item[data-value="' + firstAvailableColor + '"]');
                
                if ($firstColorItem.length) {
                    $colorWrapper.find('.variable-item').removeClass('selected').attr('aria-checked', 'false');
                    $firstColorItem.addClass('selected').attr('aria-checked', 'true');
                    $('input[name="attribute_pa_boja"]').val(firstAvailableColor);
                    $('#pa_boja').val(firstAvailableColor);
                    $('form.variations_form').trigger('check_variations');
                }
            } else {
                $colorWrapper.find('.variable-item').removeClass('selected').attr('aria-checked', 'false');
                $('input[name="attribute_pa_boja"]').val('');
                $('#pa_boja').val('');
                $('form.variations_form').trigger('check_variations');
            }
        } else if (currentSelectedColor && availableColors.indexOf(currentSelectedColor) !== -1) {
            $('form.variations_form').trigger('check_variations');
        } else {
            $('form.variations_form').trigger('check_variations');
        }
        
        $('#thready-size-selector').hide();
        $('.thready-size-wrapper').empty().append('<li class="thready-size-notice" style="color: #666; font-style: italic; padding: 10px;">' + thready_frontend_params.select_variation_first + '</li>');
        $('.thready-size-wrapper').removeData('selected-size');
        
        updateAddToCartButton();
        
        setTimeout(function() {
            preventProductTypeDisabling();
            isManualProductTypeChange = false;
        }, 100);
    }
    
    // Initialize product type handler
    function initProductTypeHandler() {
        var $productTypeWrapper = $('.variable-items-wrapper[data-attribute_name="attribute_pa_tip-proizvoda"]');
        
        if ($productTypeWrapper.length) {
            preventProductTypeDisabling();
            
            $productTypeWrapper.on('click', '.variable-item', function(e) {
                if (!$(this).hasClass('disabled')) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    var $clickedItem = $(this);
                    var selectedProductType = $clickedItem.data('value');
                    
                    $productTypeWrapper.find('.variable-item').removeClass('selected').attr('aria-checked', 'false');
                    $clickedItem.addClass('selected').attr('aria-checked', 'true');
                    
                    handleProductTypeSelection(selectedProductType);
                }
            });
            
            var $initialSelected = $productTypeWrapper.find('.variable-item.selected');
            if ($initialSelected.length) {
                currentProductType = $initialSelected.data('value');
            }
            
            setInterval(preventProductTypeDisabling, 500);
        }
    }
    
    // Override Woo Variation Swatches disabling behavior
    function overrideWooVariationSwatches() {
        if (typeof $.fn.wvs_update_available_variations === 'function') {
            var originalUpdate = $.fn.wvs_update_available_variations;
            
            $.fn.wvs_update_available_variations = function() {
                var result = originalUpdate.apply(this, arguments);
                
                if (!isManualProductTypeChange) {
                    preventProductTypeDisabling();
                }
                
                return result;
            };
        }
    }
    
    // Store initial image state
    function storeInitialImageState() {
        var $mainImage = $('.gspb-product-image-gallery .swiper-slide-main-image img');
        if ($mainImage.length) {
            currentImageSrc = $mainImage.attr('src');
            $mainImage.css({
                'transition': 'opacity 0.4s ease-in-out'
            });
        }
    }
    
    // Update add to cart button state
    function updateAddToCartButton() {
        var $button = $('.single_add_to_cart_button');
        var variationSelected = $('form.variations_form').find('.variation_id').val() !== '';
        var sizeSelected = $('.thready-size-wrapper').data('selected-size') !== undefined;
        var sizeSelectorVisible = $('#thready-size-selector').is(':visible');
        
        if (variationSelected && (!sizeSelectorVisible || sizeSelected)) {
            $button.prop('disabled', false);
        } else {
            $button.prop('disabled', true);
        }
    }
    
    // Initialize on page load
    injectSpinnerStyles();
    initSizeSelector();
    initProductTypeHandler();
    overrideWooVariationSwatches();
    storeInitialImageState();
    
    // Initialize imagelink handler
    setTimeout(function() {
        initImagelinkHandler();
    }, 1000);
    
    // Prevent disabling on variation events
    $('form.variations_form').on('check_variations update_variation_values', function() {
        if (!isManualProductTypeChange) {
            setTimeout(preventProductTypeDisabling, 50);
        }
    });
    
    // Listen for variation changes
    $('form.variations_form').on('found_variation', function(event, variation) {
        var $sizeWrapper = $('.thready-size-wrapper');
        var $sizeNotice = $('.thready-size-notice');
        currentVariationId = variation.variation_id;
        
        $sizeWrapper.removeData('selected-size');
        $sizeWrapper.find('.variable-item').removeClass('selected').attr('aria-checked', 'false');
        
        // Update imagelink classes for current variation
        updateImagelinkClasses(currentVariationId);
        
        // Update gallery image
        if (variation.image && variation.image.src) {
            var altText = getImageAltText(variation);
            var titleText = getImageTitleText(variation);
            updateGalleryImage(variation.image.src, altText, titleText);
        }
        
        // Show size selector if available
        if (variation.thready_available_sizes && variation.thready_available_sizes.trim() !== '' && 
            variation.attributes['attribute_pa_tip-proizvoda'] === currentProductType) {
            
            var sizes = variation.thready_available_sizes.split(',')
                .map(function (s) { return s.trim(); })
                .filter(function (s) { return s !== ''; });

            // Sort sizes by canonical attribute order (S, M, L, XL, XXL, …)
            // so they don't change position when switching colors.
            var canonicalOrder = (thready_frontend_params.size_order || []);
            if (canonicalOrder.length) {
                var rank = {};
                canonicalOrder.forEach(function (slug, i) { rank[slug] = i; });
                sizes.sort(function (a, b) {
                    var ra = (a in rank) ? rank[a] : 9999;
                    var rb = (b in rank) ? rank[b] : 9999;
                    return ra - rb;
                });
            }

            $sizeWrapper.empty();
            $sizeNotice.remove();
            
            $.each(sizes, function(index, sizeSlug) {
                if (!sizeSlug || sizeSlug.trim() === '') return;
                
                sizeSlug = sizeSlug.trim();
                var sizeName = sizeSlug.toUpperCase();
                var $sizeOption = $('.variations tr:has(.label:contains("Veličina")) select option[value="' + sizeSlug + '"]');
                
                if ($sizeOption.length) {
                    sizeName = $sizeOption.text();
                } else {
                    if (thready_frontend_params.size_names && thready_frontend_params.size_names[sizeSlug]) {
                        sizeName = thready_frontend_params.size_names[sizeSlug];
                    } else {
                        sizeName = sizeSlug.replace(/-/g, ' ').replace(/\b\w/g, function(l) {
                            return l.toUpperCase();
                        });
                    }
                }
                
                var buttonHTML = `
                    <li aria-checked="false" tabindex="0" 
                        data-attribute_name="thready_size" 
                        data-wvstooltip="${sizeName}" 
                        title="${sizeName}" 
                        data-title="${sizeName}" 
                        data-value="${sizeSlug}" 
                        role="radio" 
                        class="variable-item button-variable-item button-variable-item-${sizeSlug}">
                        <div class="variable-item-contents">
                            <span class="variable-item-span variable-item-span-button">${sizeName}</span>
                        </div>
                    </li>
                `;
                
                $sizeWrapper.append(buttonHTML);
            });
            
            $sizeWrapper.find('.variable-item').on('click', function() {
                var $this = $(this);
                var value = $this.data('value');
                
                $sizeWrapper.find('.variable-item').removeClass('selected').attr('aria-checked', 'false');
                $this.addClass('selected').attr('aria-checked', 'true');
                $sizeWrapper.data('selected-size', value);
                updateAddToCartButton();
            });
            
            $('#thready-size-selector').show();
            
            var urlSize = getUrlParameter('attribute_pa_velicina');
            if (urlSize && $sizeWrapper.find('.variable-item[data-value="' + urlSize + '"]').length) {
                $sizeWrapper.find('.variable-item[data-value="' + urlSize + '"]').click();
            }
        } else {
            $('#thready-size-selector').hide();
            $sizeWrapper.empty().append('<li class="thready-size-notice" style="color: #666; font-style: italic; padding: 10px;">' + thready_frontend_params.no_sizes_available + '</li>');
            $sizeWrapper.removeData('selected-size');
            updateAddToCartButton();
        }
        
        updateAddToCartButton();
        setTimeout(preventProductTypeDisabling, 50);
    });
    
    // Reset size selector when variation is reset
    $('form.variations_form').on('reset_data', function() {
        $('#thready-size-selector').hide();
        $('.thready-size-wrapper').empty().append('<li class="thready-size-notice" style="color: #666; font-style: italic; padding: 10px;">' + thready_frontend_params.select_variation_first + '</li>');
        $('.thready-size-wrapper').removeData('selected-size');
        
        // Remove imagelink class from all variation images when resetting
        updateImagelinkClasses(null);
        
        // Reset to default product image
        var $defaultImage = $('.gspb-product-image-gallery .swiper-slide-main-image img');
        if ($defaultImage.length && currentImageSrc !== $defaultImage.attr('src')) {
            var defaultAlt = $defaultImage.attr('alt') || 'Product Image';
            var defaultTitle = $defaultImage.attr('title') || defaultAlt;
            updateGalleryImage($defaultImage.attr('src'), defaultAlt, defaultTitle);
        }
        
        updateAddToCartButton();
    });
    
    // Hide size selector if no variation is selected
    $('form.variations_form').on('hide_variation', function() {
        $('#thready-size-selector').hide();
        $('.thready-size-wrapper').empty().append('<li class="thready-size-notice" style="color: #666; font-style: italic; padding: 10px;">' + thready_frontend_params.select_variation_first + '</li>');
        $('.thready-size-wrapper').removeData('selected-size');
        
        // Remove imagelink class from all variation images when no variation is selected
        updateImagelinkClasses(null);
        
        updateAddToCartButton();
    });
    
    // Listen for variation changes to update button state
    $('form.variations_form').on('found_variation hide_variation reset_data', function() {
        updateAddToCartButton();
    });
    
    // Add size to cart when form is submitted
    $('form.cart').on('submit', function(e) {
        var selectedSize = $('.thready-size-wrapper').data('selected-size');
        
        $('input[name="thready_size"]').remove();
        
        if (selectedSize && $('#thready-size-selector').is(':visible')) {
            $('<input>').attr({
                type: 'hidden',
                name: 'thready_size',
                value: selectedSize
            }).appendTo('form.cart');
        }
        
        if (formSubmitting) {
            e.preventDefault();
            return false;
        }
        
        if ($('#thready-size-selector').is(':visible') && !$('.thready-size-wrapper').data('selected-size')) {
            e.preventDefault();
            alert(thready_frontend_params.select_size_required);
            return false;
        }
        
        formSubmitting = true;
        
        var $button = $('.single_add_to_cart_button');
        $button.prop('disabled', true).addClass('loading');
        
        setTimeout(function() {
            formSubmitting = false;
            $button.prop('disabled', false).removeClass('loading');
        }, 3000);
    });
    
    // Handle AJAX completion to reset form state
    $(document).on('ajaxComplete', function() {
        formSubmitting = false;
        $('.single_add_to_cart_button').prop('disabled', false).removeClass('loading');
    });
    
    // Fix for default variation setting issue
    setTimeout(function() {
        var $variationId = $('input.variation_id');
        if ($variationId.val() && $variationId.val() !== '') {
            var variationData = {};
            try {
                variationData = $('form.variations_form').data('product_variations');
                var variationId = $variationId.val();
                
                if (variationData && variationId) {
                    for (var i = 0; i < variationData.length; i++) {
                        if (variationData[i].variation_id == variationId) {
                            $('form.variations_form').trigger('found_variation', [variationData[i]]);
                            break;
                        }
                    }
                }
            } catch (e) {}
        }
        
        preventProductTypeDisabling();
    }, 500);
});

// Make zoom reinitialization function globally available
window.threadyProductZoomInit = function() {
    if (window.innerWidth < 768) return;
    
    document.querySelectorAll('.gspb-gallery-full .swiper-slide').forEach(function(slide, index) {
        var img = slide.querySelector('img');
        if (img && !slide._zoomSetup) {
            var imageSrc = img.getAttribute('data-main-featured-image-src') || img.src;
            var tempImg = new Image();
            
            tempImg.onload = function() {
                initZoom(slide, img, index, this.naturalWidth, this.naturalHeight);
            };
            
            tempImg.onerror = function() {
                initZoom(slide, img, index, img.naturalWidth, img.naturalHeight);
            };
            
            tempImg.src = imageSrc;
        }
    });
    
    function initZoom(slide, img, index, naturalWidth, naturalHeight) {
        var slideWidth = slide.offsetWidth;
        var slideHeight = slide.offsetHeight;
        
        if (slideWidth > 0 && slideHeight > 0) {
            var scale = Math.max(naturalWidth / slideWidth, naturalHeight / slideHeight);
            
            if (scale > 1) {
                var isZoomActive = false;
                slide.style.overflow = 'hidden';
                slide.style.cursor = 'zoom-in';
                img.style.transition = 'transform 0.1s ease';
                img.style.transformOrigin = 'center center';
                
                slide.addEventListener('mouseenter', function() {
                    isZoomActive = true;
                    img.style.transform = 'scale(' + scale + ')';
                });
                
                slide.addEventListener('mouseleave', function() {
                    isZoomActive = false;
                    img.style.transform = 'scale(1)';
                    img.style.transformOrigin = 'center center';
                });
                
                slide.addEventListener('mousemove', function(e) {
                    if (isZoomActive) {
                        var x = e.clientX - slide.getBoundingClientRect().left;
                        var y = e.clientY - slide.getBoundingClientRect().top;
                        img.style.transformOrigin = (100 * (x / slideWidth)) + '% ' + (100 * (y / slideHeight)) + '%';
                        img.style.transform = 'scale(' + scale + ')';
                    }
                });
                
                slide._zoomSetup = true;
            }
        }
    }
};