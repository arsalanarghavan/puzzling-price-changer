jQuery(document).ready(function($) {

    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    function unformatNumber(str) {
        return str.toString().replace(/[^0-9]/g, '');
    }

    $('.variation-price-input').on('input', function() {
        var cursorPosition = this.selectionStart;
        var originalLength = this.value.length;
        
        var value = $(this).val();
        var unformattedValue = unformatNumber(value);
        
        if (unformattedValue.length > 0) {
            var formattedValue = formatNumber(unformattedValue);
            $(this).val(formattedValue);
            
            var newLength = this.value.length;
            cursorPosition = cursorPosition + (newLength - originalLength);
            this.setSelectionRange(cursorPosition, cursorPosition);
        } else {
            $(this).val('');
        }
    });

    $('.variation-price-input').on('change', function() {
        
        var inputField = $(this);
        var variationId = inputField.data('variation-id');
        var formattedPrice = inputField.val();
        var newPrice = unformatNumber(formattedPrice);
        
        var saveStatus = inputField.closest('.price-wrapper').find('.save-status');

        saveStatus.removeClass('success error').addClass('saving');

        var data = {
            action: 'update_variation_price',
            nonce: psp_ajax_object.nonce,
            variation_id: variationId,
            new_price: newPrice 
        };

        $.post(psp_ajax_object.ajax_url, data, function(response) {
            saveStatus.removeClass('saving');
            if (response.success) {
                saveStatus.addClass('success');
            } else {
                saveStatus.addClass('error');
                alert('خطا: ' + response.data.message);
            }
            
            setTimeout(function() {
                saveStatus.removeClass('success error');
            }, 2000);

        }).fail(function() {
            saveStatus.removeClass('saving').addClass('error');
            alert('خطای ارتباط با سرور. لطفا اتصال اینترنت خود را بررسی کنید.');
            setTimeout(function() {
                saveStatus.removeClass('error');
            }, 2000);
        });
    });
});