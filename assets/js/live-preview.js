/**
 * Thready Live Preview — live-preview.js
 *
 * Canvas compositor for products in canvas render mode.
 * Works with multi-tip products: variations = pa_tip-proizvoda × pa_boja.
 * Outputs WebP at 95% quality with PNG fallback.
 */
/* global threadyCanvas, jQuery */
(function ($) {
    'use strict';

    var d = window.threadyCanvas;
    if (!d) return;

    // ── Configuration ─────────────────────────────────────────────────────────
    var MIN_LOADING_TIME = 150; // milliseconds = simulates transition delay for better user experience
    var WEBP_QUALITY = 0.95;    // 95% quality for WebP

    // Feature detection for WebP support
    var supportsWebP = false;
    var webPCheck = new Image();
    webPCheck.onload = function() { supportsWebP = true; };
    webPCheck.onerror = function() { supportsWebP = false; };
    webPCheck.src = 'data:image/webp;base64,UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA';

    // ── State & cache ─────────────────────────────────────────────────────────
    var cache = {};
    var currentTip = null;
    var currentBoja = null;
    var currentView = 'front';
    var originalGalleryHTML = null;
    var isCurrentVariationOnSale = false;
    var saleBadgeTimer = null;

    var canvas = document.createElement('canvas');
    var ctx = canvas.getContext('2d');

    var preloader = new Image();
    preloader.crossOrigin = 'anonymous';

    // ── Gallery helpers ───────────────────────────────────────────────────────
    function getGalleryImg() {
        var selectors = [
            '.woocommerce-product-gallery__image img',
            '.gspb-product-image-gallery-wrap img',
            '.gspb-product-image-gallery img',
            '.swiper-slide-main-image img',
        ];
        for (var i = 0; i < selectors.length; i++) {
            var $img = $(selectors[i]).first();
            if ($img.length) return $img;
        }
        return $();
    }

    function getGalleryWrap() {
        var $img = getGalleryImg();
        if ($img.length) {
            var $wrap = $img.closest(
                '.swiper-slide-main-image, ' +
                '.woocommerce-product-gallery__image, ' +
                '.gspb-product-image-gallery-wrap, ' +
                '.gspb-product-image-gallery'
            );
            if ($wrap.length) return $wrap.first();
            return $img.parent();
        }
        return $(
            '.woocommerce-product-gallery__image, ' +
            '.gspb-product-image-gallery-wrap, ' +
            '.gspb-product-image-gallery'
        ).first();
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
            var $img = getGalleryImg();
            if ($img.length) {
                var container = $img.closest('.swiper-slide')[0];
                if (container) {
                    window.threadyZoomRefresh(container);
                }
            }
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

    // ── Refresh SimpleLightbox by destroying and recreating it ────────────────
    function refreshLightbox() {
        var $gallery = $('.gspb-gallery-full, .woocommerce-product-gallery');
        if (!$gallery.length) return;

        var $anchors = $gallery.find('a.imagelink, a.woocommerce-product-gallery__trigger, a[data-lightbox]');
        if (!$anchors.length) return;

        if (!$.fn.simpleLightbox) return;

        $anchors.each(function () {
            var instance = $(this).data('simpleLightbox');
            if (instance && typeof instance.destroy === 'function') {
                instance.destroy();
            }
            $(this).removeData('simpleLightbox');
        });

        var galleryInstance = $gallery.data('simpleLightbox');
        if (galleryInstance && typeof galleryInstance.destroy === 'function') {
            galleryInstance.destroy();
            $gallery.removeData('simpleLightbox');
        }

        var options = $gallery.data('simpleLightboxOptions') || {};
        $anchors.simpleLightbox(options);

        setTimeout(cleanupHtmlSlbActive, 50);
    }

    // ── Update existing image src (no DOM replacement) ────────────────────────
    function updateGalleryImage(blobUrl, mimeType) {
        var $img = getGalleryImg();
        var $wrap = getGalleryWrap();
        if (!$img.length) return;

        var visibleImg = $img[0];
        var $anchor = $img.closest('a');

        // Choose fake extension based on mime type for lightbox compatibility
        var suffix = (mimeType === 'image/webp') ? '#.webp' : '#.png';
        var displayUrl = blobUrl + suffix;

        var imageLoadedPromise = new Promise(function (resolve, reject) {
            preloader.onload = function () {
                visibleImg.src = displayUrl;
                if ($anchor.length) {
                    $anchor.attr('href', displayUrl);
                }
                resolve();
                preloader.onload = null;
                preloader.onerror = null;
            };
            preloader.onerror = function () {
                reject(new Error('Preload failed'));
                preloader.onload = null;
                preloader.onerror = null;
            };
            preloader.src = blobUrl;
        });

        var timerPromise = new Promise(function (resolve) {
            setTimeout(resolve, MIN_LOADING_TIME);
        });

        Promise.all([imageLoadedPromise, timerPromise])
            .then(function () {
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () {
                        $wrap.removeClass('thready-canvas-loading');
                        refreshZoomForCurrentImage();
                        debouncedUpdateSaleBadgeVisibility(isCurrentVariationOnSale);
                        refreshLightbox();
                    });
                });
            })
            .catch(function () {
                $wrap.removeClass('thready-canvas-loading');
            });
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

            // Determine output format
            var mimeType = supportsWebP ? 'image/webp' : 'image/png';
            var quality = supportsWebP ? WEBP_QUALITY : undefined;

            return new Promise(function (resolve, reject) {
                canvas.toBlob(function (blob) {
                    if (!blob) {
                        reject(new Error('Canvas toBlob failed'));
                        return;
                    }
                    var url = URL.createObjectURL(blob);
                    // Store both URL and mime type in cache
                    cache[key] = { url: url, mimeType: mimeType };
                    resolve(cache[key]);
                }, mimeType, quality);
            });
        });
    }

    // ── Update view ───────────────────────────────────────────────────────────
    function updateView(tipSlug, bojaSlug, view, lightPrint) {
        if (!tipSlug || !bojaSlug) return;

        var mkKey = tipSlug + '|' + bojaSlug;
        var mockup = (d.mockups || {})[mkKey];

        if (!mockup) {
            getGalleryWrap().removeClass('thready-canvas-loading');
            return;
        }

        var baseUrl = view === 'back' && mockup.back ? mockup.back : mockup.front;
        if (!baseUrl) {
            getGalleryWrap().removeClass('thready-canvas-loading');
            return;
        }

        var printUrl;
        if (view === 'back') {
            printUrl = d.print_back || '';
        } else if (lightPrint && d.has_light) {
            printUrl = d.print_light || '';
        } else {
            printUrl = d.print_front || '';
        }

        var tipPos = (d.tip_positions || {})[tipSlug] || {};
        var pos = view === 'back' ? (tipPos.back || null) : (tipPos.front || { x: 50, y: 25, width: 50 });

        getGalleryWrap().addClass('thready-canvas-loading');

        composite(baseUrl, printUrl || null, pos)
            .then(function (result) {
                updateGalleryImage(result.url, result.mimeType);
            })
            .catch(function () {
                getGalleryWrap().removeClass('thready-canvas-loading');
            });
    }

    // ── Front / Back toggle UI ────────────────────────────────────────────────
    function injectViewToggle() {
        if (!d.has_back) return;
        if ($('.thready-view-toggle').length) return;

        var $wrap = getGalleryWrap();
        if (!$wrap.length) return;

        var html = '<div class="thready-view-toggle">'
            + '<button type="button" class="tvt-btn tvt-active" data-view="front">Front</button>'
            + '<button type="button" class="tvt-btn" data-view="back">Back</button>'
            + '</div>';

        $wrap.after(html);

        $(document).on('click', '.tvt-btn', function () {
            currentView = $(this).data('view');
            $('.tvt-btn').removeClass('tvt-active');
            $(this).addClass('tvt-active');
            updateView(currentTip, currentBoja, currentView, false);
        });
    }

    // ── WooCommerce variation events ──────────────────────────────────────────
    $(document).on('found_variation', function (e, variation) {
        var tipSlug = variation.thready_tip_slug || '';
        var bojaSlug = variation.thready_boja_slug || '';
        var lightPrint = !!variation.thready_light_print;

        tipSlug = tipSlug.toLowerCase().replace(/\s+/g, '-');
        bojaSlug = bojaSlug.toLowerCase().replace(/\s+/g, '-');

        currentTip = tipSlug;
        currentBoja = bojaSlug;
        currentView = 'front';

        var isOnSale = variation.display_price !== undefined &&
                       variation.display_regular_price !== undefined &&
                       parseFloat(variation.display_price) < parseFloat(variation.display_regular_price);
        isCurrentVariationOnSale = isOnSale;
        debouncedUpdateSaleBadgeVisibility(isOnSale);

        $('.tvt-btn').removeClass('tvt-active');
        $('.tvt-btn[data-view="front"]').addClass('tvt-active');

        injectViewToggle();
        updateView(tipSlug, bojaSlug, 'front', lightPrint);
        updateGalleryStrip(tipSlug, bojaSlug);
    });

    $(document).on('reset_data hide_variation', function () {
        currentTip = null;
        currentBoja = null;
        currentView = 'front';
        isCurrentVariationOnSale = false;
        debouncedUpdateSaleBadgeVisibility(false);

        if (originalGalleryHTML) {
            getGalleryWrap().html(originalGalleryHTML);
        }
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

    // ── Gallery thumbnail strip ──────────────────────────────────────────────
    function updateGalleryStrip(tipSlug, bojaSlug) {
        var key = tipSlug + '|' + bojaSlug;
        var thumbs = (d.thumbnails || {})[key];
        var $strip = $('.flex-control-nav.flex-control-thumbs, .woocommerce-product-gallery__thumbnail-strip');

        if (!$strip.length || !thumbs) return;

        var items = [];
        if (thumbs.front) items.push({ url: thumbs.front, view: 'front', label: 'Front' });
        if (thumbs.back && d.has_back) items.push({ url: thumbs.back, view: 'back', label: 'Back' });
        if (!items.length) return;

        $strip.empty();

        items.forEach(function (item) {
            var $li = $('<li>');
            var $img = $('<img>')
                .attr({ src: item.url, alt: item.label })
                .css({ cursor: 'pointer', opacity: item.view === 'front' ? 1 : 0.6 });

            $li.append($img).appendTo($strip);

            $img.on('click', function () {
                currentView = item.view;
                $strip.find('img').css('opacity', 0.6);
                $(this).css('opacity', 1);

                $('.tvt-btn').removeClass('tvt-active');
                $('.tvt-btn[data-view="' + item.view + '"]').addClass('tvt-active');

                updateView(tipSlug, bojaSlug, item.view, false);
            });
        });
    }

    // ── Capture original lightbox options ─────────────────────────────────────
    function captureLightboxOptions() {
        var $gallery = $('.gspb-gallery-full, .woocommerce-product-gallery');
        if (!$gallery.length) return;

        var $anchors = $gallery.find('a.imagelink, a.woocommerce-product-gallery__trigger, a[data-lightbox]');
        if (!$anchors.length) return;

        var instance = $anchors.first().data('simpleLightbox');
        if (instance && instance.options) {
            $gallery.data('simpleLightboxOptions', instance.options);
        }
    }

    // ── Tag the real image modal with slbThready (safe method) ────────────────
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
        }, 150);
    }

    // ── Init ─────────────────────────────────────────────────────────────────
    $(function () {
        var $wrap = getGalleryWrap();
        if ($wrap.length) {
            originalGalleryHTML = $wrap.html();
            $wrap.addClass('thready-canvas-loading');
            setTimeout(function () {
                $wrap.removeClass('thready-canvas-loading');
            }, MIN_LOADING_TIME + 500);

            captureLightboxOptions();

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
        }
    });

}(jQuery));