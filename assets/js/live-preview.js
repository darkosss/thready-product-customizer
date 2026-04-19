/**
 * Thready Live Preview — live-preview.js
 *
 * Canvas compositor for products in canvas render mode.
 * Works with multi-tip products: variations = pa_tip-proizvoda × pa_boja.
 * Outputs WebP at 95% quality with PNG fallback.
 * Updates existing Swiper slides in-place to avoid Greenshift conflicts.
 *
 * Gallery is hidden via CSS (.thready-gallery-ready) until the first
 * canvas composite is rendered, preventing the flash of the default
 * featured image on page load.
 */
/* global threadyCanvas, jQuery */
(function ($) {
    'use strict';

    var d = window.threadyCanvas;
    if (!d) return;

    // ── Configuration ─────────────────────────────────────────────────────────
    var MIN_LOADING_TIME = 150;
    var WEBP_QUALITY = 0.95;

    var supportsWebP = false;
    var webPCheck = new Image();
    webPCheck.onload = function() { supportsWebP = true; };
    webPCheck.onerror = function() { supportsWebP = false; };
    webPCheck.src = 'data:image/webp;base64,UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA';

    // ── State & cache ─────────────────────────────────────────────────────────
    var cache = {};
    var currentTip = null;
    var currentBoja = null;
    var isFirstLoad = true;
    var isCurrentVariationOnSale = false;
    var saleBadgeTimer = null;
    var capturedLightboxOptions = null;

    var canvas = document.createElement('canvas');
    var ctx = canvas.getContext('2d');

    // ── Gallery helpers ───────────────────────────────────────────────────────
    function getGalleryWrap() {
        return $('.gspb-product-image-gallery-wrap').first();
    }

    /**
     * Mark the gallery as ready — fades it in via CSS transition.
     * Called once after the first composite renders. Subsequent
     * variation changes use the loading overlay instead.
     */
    function revealGallery() {
        getGalleryWrap().addClass('thready-gallery-ready');
        isFirstLoad = false;
    }

    // ── Debounced update for sale badge visibility ───────────────────────────
    function debouncedUpdateSaleBadgeVisibility(isOnSale) {
        if (saleBadgeTimer) {
            clearTimeout(saleBadgeTimer);
        }
        saleBadgeTimer = setTimeout(function() {
            var $badge = $('.thready-sale-badge, .gspb-discountbox').first();
            if (!$badge.length) return;
            if (isOnSale) {
                $badge.css('display', '');
            } else {
                $badge.css('display', 'none');
            }
            saleBadgeTimer = null;
        }, 50);
    }

    // ── Notify zoom script after image update ─────────────────────────────────
    function refreshZoomForCurrentImage() {
        if (typeof window.threadyZoomRefresh === 'function') {
            $('.gspb-gallery-full .swiper-slide').each(function() {
                var slide = this;
                if ($(slide).find('img').length && $(slide).is(':visible')) {
                    window.threadyZoomRefresh(slide);
                }
            });
        }
    }

    // ── Clean up duplicate "slbActive" classes on <html> ──────────────────────
    function cleanupHtmlSlbActive() {
        var $html = $('html');
        $html.removeClass('slbActive');
        if ($('.slbElement:not(.slbLoading)').length) {
            $html.addClass('slbActive');
        }
    }

    // ── Image loading ─────────────────────────────────────────────────────────
    function loadImg(url) {
        return new Promise(function (resolve, reject) {
            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function () { resolve(img); };
            img.onerror = function () { reject(new Error('Load failed: ' + url)); };
            img.src = url + (url.indexOf('?') !== -1 ? '&' : '?') + '_tc=' + Date.now();
        });
    }

    // ── Composite (returns blob URL and MIME type) ────────────────────────────
    function composite(baseUrl, printUrl, pos) {
        var key = baseUrl + '||' + printUrl + '||' + JSON.stringify(pos);
        if (cache[key]) return Promise.resolve(cache[key]);

        var loads = [loadImg(baseUrl)];
        if (printUrl) loads.push(loadImg(printUrl));

        return Promise.all(loads).then(function (imgs) {
            var base = imgs[0];
            var print = imgs[1] || null;

            canvas.width = base.naturalWidth;
            canvas.height = base.naturalHeight;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(base, 0, 0);

            if (print && pos) {
                var tw = Math.round((pos.width / 100) * canvas.width);
                var th = Math.round(tw * print.naturalHeight / print.naturalWidth);
                var px = pos.x > 0
                    ? Math.round((pos.x / 100) * canvas.width) - Math.round(tw / 2)
                    : Math.round((pos.x / 100) * canvas.width) + Math.round(tw / 2);
                var py = Math.round((pos.y / 100) * canvas.height);
                ctx.drawImage(print, px, py, tw, th);
            }

            var mimeType = supportsWebP ? 'image/webp' : 'image/png';
            var quality = supportsWebP ? WEBP_QUALITY : undefined;

            return new Promise(function (resolve, reject) {
                canvas.toBlob(function (blob) {
                    if (!blob) {
                        reject(new Error('Canvas toBlob failed'));
                        return;
                    }
                    var url = URL.createObjectURL(blob);
                    cache[key] = { url: url, mimeType: mimeType };
                    resolve(cache[key]);
                }, mimeType, quality);
            });
        });
    }

    // ── Preload a single image (blob URL) ─────────────────────────────────────
    function preloadImage(url) {
        return new Promise(function (resolve, reject) {
            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function() { resolve(); };
            img.onerror = function() { reject(new Error('Preload failed: ' + url)); };
            img.src = url;
        });
    }

    // ── Generate composites and preload them ──────────────────────────────────
    function generateAndPreloadComposites(tipSlug, bojaSlug, lightPrint) {
        var mkKey = tipSlug + '|' + bojaSlug;
        var mockup = (d.mockups || {})[mkKey];
        if (!mockup) return Promise.resolve([]);

        var tipPos = (d.tip_positions || {})[tipSlug] || {};
        var compositePromises = [];

        // Front composite (always)
        var frontBase = mockup.front;
        var frontPrint = lightPrint && d.has_light ? d.print_light : d.print_front;
        var frontPos = tipPos.front || { x: 50, y: 25, width: 50 };
        if (frontBase && frontPrint) {
            compositePromises.push(
                composite(frontBase, frontPrint, frontPos).then(function(result) {
                    return preloadImage(result.url).then(function() {
                        return { view: 'front', url: result.url, mimeType: result.mimeType };
                    });
                })
            );
        }

        // Back composite (if available)
        var backBase = mockup.back;
        var backPrint = d.print_back;
        var backPos = tipPos.back;
        if (backBase && backPrint) {
            compositePromises.push(
                composite(backBase, backPrint, backPos || { x: 50, y: 25, width: 50 }).then(function(result) {
                    return preloadImage(result.url).then(function() {
                        return { view: 'back', url: result.url, mimeType: result.mimeType };
                    });
                })
            );
        }

        return Promise.all([
            Promise.all(compositePromises),
            new Promise(function(resolve) { setTimeout(resolve, MIN_LOADING_TIME); })
        ]).then(function(results) {
            return results[0];
        });
    }

    // ── Update existing Swiper slides in-place (no Swiper API) ───────────────
    function updateGalleryInPlace(composites, productTitle) {
        var $mainSlides = $('.gspb-gallery-full .swiper-slide');
        var $thumbSlides = $('.gspb-gallery-thumb .swiper-slide');
        var $thumbContainer = $('.gspb-gallery-thumb');

        $thumbContainer.show();

        // Update main slides
        composites.forEach(function(item, index) {
            var $slide = $mainSlides.eq(index);
            if (!$slide.length) return;

            var suffix = item.mimeType === 'image/webp' ? '#.webp' : '#.png';
            var displayUrl = item.url + suffix;

            // Ensure the slide has an <a class="imagelink"> wrapper.
            var $anchor = $slide.find('a.imagelink, a.woocommerce-product-gallery__trigger, a[data-lightbox]');
            if (!$anchor.length) {
                var $img = $slide.find('img');
                if ($img.length) {
                    $img.wrap('<a href="' + displayUrl + '" class="imagelink"></a>');
                    $anchor = $img.parent('a');
                }
            }

            $anchor.attr('href', displayUrl).attr('title', productTitle || '');
            $slide.find('img')
                .attr('src', displayUrl)
                .attr('data-main-featured-image-src', displayUrl)
                .attr('alt', productTitle || '');
        });

        // Update thumbnail slides
        composites.forEach(function(item, index) {
            var $thumb = $thumbSlides.eq(index);
            if (!$thumb.length) return;

            var suffix = item.mimeType === 'image/webp' ? '#.webp' : '#.png';
            var displayUrl = item.url + suffix;

            $thumb.find('img')
                .attr('src', displayUrl)
                .attr('data-main-featured-image-src', displayUrl);
        });

        // Keep all slides visible — canvas slots get updated above,
        // static gallery images and 360 gallery are never touched.
        $mainSlides.show();
        $thumbSlides.show();

        // Refresh lightbox on updated anchors
        var $anchors = $('.gspb-gallery-full').find('a.imagelink, a.woocommerce-product-gallery__trigger, a[data-lightbox]');
        if ($.fn.simpleLightbox && $anchors.length) {
            $anchors.each(function() {
                var instance = $(this).data('simpleLightbox');
                if (instance && typeof instance.destroy === 'function') {
                    instance.destroy();
                }
                $(this).removeData('simpleLightbox');
            });

            $('.slbElement').remove();
            $('html').removeClass('slbActive');

            var options = capturedLightboxOptions || {};
            $anchors.simpleLightbox(options);
        }

        refreshZoomForCurrentImage();
        cleanupHtmlSlbActive();
    }

    // ── WooCommerce variation events ──────────────────────────────────────────
    $(document).on('found_variation', function (e, variation) {
        var tipSlug = variation.thready_tip_slug || '';
        var bojaSlug = variation.thready_boja_slug || '';
        var lightPrint = !!variation.thready_light_print;
        var productTitle = $('.product_title').text() || '';

        tipSlug = tipSlug.toLowerCase().replace(/\s+/g, '-');
        bojaSlug = bojaSlug.toLowerCase().replace(/\s+/g, '-');

        currentTip = tipSlug;
        currentBoja = bojaSlug;

        var isOnSale = variation.display_price !== undefined &&
                       variation.display_regular_price !== undefined &&
                       parseFloat(variation.display_price) < parseFloat(variation.display_regular_price);
        isCurrentVariationOnSale = isOnSale;
        debouncedUpdateSaleBadgeVisibility(isOnSale);

        var $wrap = getGalleryWrap();

        // On first load the gallery is hidden via CSS (opacity:0).
        // On subsequent swaps, show the loading overlay instead.
        if (!isFirstLoad) {
            $wrap.addClass('thready-canvas-loading');
        }

        generateAndPreloadComposites(tipSlug, bojaSlug, lightPrint)
            .then(function(composites) {
                if (composites.length) {
                    updateGalleryInPlace(composites, productTitle);
                }

                // First load: fade in the gallery. Subsequent: remove overlay.
                if (isFirstLoad) {
                    revealGallery();
                } else {
                    $wrap.removeClass('thready-canvas-loading');
                }

                debouncedUpdateSaleBadgeVisibility(isOnSale);
            })
            .catch(function(err) {
                console.error('[Thready] Composite/preload failed:', err);
                if (isFirstLoad) {
                    revealGallery();
                } else {
                    $wrap.removeClass('thready-canvas-loading');
                }
                debouncedUpdateSaleBadgeVisibility(isOnSale);
            });
    });

    $(document).on('reset_data hide_variation', function () {
        currentTip = null;
        currentBoja = null;
        isCurrentVariationOnSale = false;
        debouncedUpdateSaleBadgeVisibility(false);
        getGalleryWrap().removeClass('thready-canvas-loading');
    });

    $(document).on('found_variation.wc-variation-form', function (e, variation) {
        if (variation.thready_boja_slug !== undefined) {
            variation.image = {
                src: '', srcset: '', sizes: '', title: '', alt: '',
                caption: '', full_src: '', gallery_thumbnail_src: '',
            };
        }
    });

    // ── Patch SimpleLightbox.destroy to prevent errors when modal is already gone ─
    function patchSimpleLightboxDestroy() {
        if (!window.SimpleLightbox || window.SimpleLightbox._patched) return;

        var originalDestroy = window.SimpleLightbox.prototype.destroy;
        window.SimpleLightbox.prototype.destroy = function() {
            if (this.$el && this.$el.parentNode) {
                originalDestroy.call(this);
            } else {
                if (this.options && typeof this.options.afterDestroy === 'function') {
                    this.options.afterDestroy(this);
                }
                if (this.eventRegistry) {
                    this.removeEvents('lightbox');
                    this.removeEvents('thumbnails');
                }
            }
        };
        window.SimpleLightbox._patched = true;
    }

    // ── Tag the real image modal with slbThready and hide duplicates ─────────
    function tagRealImageModal() {
        setTimeout(function() {
            $('.slbElement').each(function() {
                var $modal = $(this);
                if ($modal.find('img.slbImage').length) {
                    $modal.addClass('slbThready');
                } else {
                    $modal.removeClass('slbThready');
                }
            });

            var $validModals = $('.slbElement').filter(function() {
                return $(this).find('img.slbImage').length > 0;
            });

            if ($validModals.length > 1) {
                $validModals.slice(0, -1).addClass('slbDuplicate');
            } else {
                $('.slbElement').removeClass('slbDuplicate');
            }

            cleanupHtmlSlbActive();
        }, 150);
    }

    // ── Init ─────────────────────────────────────────────────────────────────
    $(function () {
        var $wrap = getGalleryWrap();
        if ($wrap.length) {
            patchSimpleLightboxDestroy();

            // Safety fallback: if no variation fires within 3 seconds
            // (e.g. no default variation set), reveal the gallery anyway
            // so the user isn't staring at a blank space.
            setTimeout(function() {
                if (!$wrap.hasClass('thready-gallery-ready')) {
                    revealGallery();
                }
            }, 3000);

            // Capture SimpleLightbox options from the theme's initial setup
            setTimeout(function() {
                var $a = $('.gspb-gallery-full').find('a.imagelink').first();
                if ($a.length && $a.data('simpleLightbox')) {
                    var inst = $a.data('simpleLightbox');
                    if (inst && inst.options) {
                        capturedLightboxOptions = $.extend({}, inst.options);
                    }
                }
            }, 500);

            $(document).on('click', '.slbCloseBtn, .slbOverlay', function() {
                setTimeout(cleanupHtmlSlbActive, 100);
            });

            $(document).on('click', 'a.imagelink, a.woocommerce-product-gallery__trigger, a[data-lightbox]', function() {
                tagRealImageModal();
            });

            // Initial sale badge state
            var $variationForm = $('.variations_form');
            if ($variationForm.length) {
                var $price = $('.woocommerce-variation-price .price');
                var hasSale = $price.find('del').length > 0;
                isCurrentVariationOnSale = hasSale;
                debouncedUpdateSaleBadgeVisibility(hasSale);
            }

            $('.thready-view-toggle').remove();
        }
    });

}(jQuery));