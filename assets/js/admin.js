jQuery(document).ready(function($) {

    /* ---------------------------------------------------------
     * Couple Mode: hide product-level print settings
     * --------------------------------------------------------- */

    function togglePrintSettingsForCoupleMode() {
        var $coupleModeCheckbox = $('#_thready_couple_mode');

        if (!$coupleModeCheckbox.length) {
            return;
        }

        var isCoupleMode = $coupleModeCheckbox.is(':checked');

        // Hide ONLY product-level print settings
        $('.thready-print-settings').toggle(!isCoupleMode);
    }

    // Initial run
    togglePrintSettingsForCoupleMode();

    // Toggle on checkbox change
    $(document).on('change', '#_thready_couple_mode', function () {
        togglePrintSettingsForCoupleMode();
    });

    /* ---------------------------------------------------------
     * Print image upload/remove functionality
     * --------------------------------------------------------- */

    $('#upload_print_image').click(function() {
        var frame = wp.media({
            title: thready_admin_params.upload_title,
            multiple: false,
            library: { type: 'image' }
        });
        
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#thready_print_image_container').html('<img src="' + attachment.sizes.thumbnail.url + '" />');
            $('#thready_print_image').val(attachment.id);
            $('#remove_print_image').show();
        });
        
        frame.open();
    });
    
    $('#remove_print_image').click(function() {
        $('#thready_print_image_container').html('');
        $('#thready_print_image').val('');
        $(this).hide();
    });
    
    // Light print image upload/remove functionality
    $('#upload_light_print_image').click(function() {
        var frame = wp.media({
            title: 'Select Light Print Image',
            multiple: false,
            library: { type: 'image' }
        });
        
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#thready_light_print_image_container').html('<img src="' + attachment.sizes.thumbnail.url + '" />');
            $('#thready_light_print_image').val(attachment.id);
            $('#remove_light_print_image').show();
        });
        
        frame.open();
    });
    
    $('#remove_light_print_image').click(function() {
        $('#thready_light_print_image_container').html('');
        $('#thready_light_print_image').val('');
        $(this).hide();
    });
    
    // Back print image upload/remove functionality
    $('#upload_back_print_image').click(function() {
        var frame = wp.media({
            title: 'Select Back Print Image',
            multiple: false,
            library: { type: 'image' }
        });
        
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#thready_back_print_image_container').html('<img src="' + attachment.sizes.thumbnail.url + '" />');
            $('#thready_back_print_image').val(attachment.id);
            $('#remove_back_print_image').show();
        });
        
        frame.open();
    });
    
    $('#remove_back_print_image').click(function() {
        $('#thready_back_print_image_container').html('');
        $('#thready_back_print_image').val('');
        $(this).hide();
    });
    
    // Back base image upload/remove functionality for variations
    $(document).on('click', '.upload_back_base_image', function(e) {
        e.preventDefault();
        var loop = $(this).data('loop');
        var frame = wp.media({
            title: 'Select Back Base Image',
            multiple: false,
            library: { type: 'image' }
        });
        
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#thready_back_base_image_container_' + loop).html('<img src="' + attachment.sizes.thumbnail.url + '" />');
            $('#thready_back_base_image_id_' + loop).val(attachment.id);
            $('.remove_back_base_image[data-loop="' + loop + '"]').show();
        });
        
        frame.open();
    });
    
    $(document).on('click', '.remove_back_base_image', function(e) {
        e.preventDefault();
        var loop = $(this).data('loop');
        $('#thready_back_base_image_container_' + loop).html('');
        $('#thready_back_base_image_id_' + loop).val('');
        $(this).hide();
    });
    
    // Variation available sizes - select/deselect all functionality
    $('.thready-sizes-select-all').click(function(e) {
        e.preventDefault();
        var $container = $(this).closest('.form-row').find('.thready-sizes-container');
        $container.find('input[type="checkbox"]').prop('checked', true);
    });
    
    $('.thready-sizes-deselect-all').click(function(e) {
        e.preventDefault();
        var $container = $(this).closest('.form-row').find('.thready-sizes-container');
        $container.find('input[type="checkbox"]').prop('checked', false);
    });

});
