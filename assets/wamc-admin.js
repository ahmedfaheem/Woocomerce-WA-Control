/**
 * WA Meta Control — Admin JS
 * Loaded on Settings and Dashboard pages via wp_enqueue_script.
 * wamcAjax.ajaxurl and wamcAjax.nonce are localized by PHP.
 */
(function ($) {
    'use strict';

    var ajax  = (typeof wamcAjax !== 'undefined') ? wamcAjax.ajaxurl : ajaxurl;
    var nonce = (typeof wamcAjax !== 'undefined') ? wamcAjax.nonce   : '';

    /* ── Tabs ────────────────────────────────────────────────────────── */
    $(document).on('click', '.wamc-tab-btn', function () {
        var tab = $(this).data('tab');
        $('.wamc-tab-btn').removeClass('active');
        $('.wamc-tab-panel').hide();
        $(this).addClass('active');
        $('#wamc-tab-' + tab).show();
    });

    /* ── Toggle API Key Visibility ───────────────────────────────────── */
    $(document).on('click', '#wamc-toggle-key', function () {
        var $f = $('#wamc_api_key');
        $f.attr('type', $f.attr('type') === 'password' ? 'text' : 'password');
    });

    /* ── Test Connection ─────────────────────────────────────────────── */
    $(document).on('click', '#wamc-test-connection', function () {
        var $btn = $(this);
        var $res = $('#wamc-connection-result');
        $btn.prop('disabled', true);
        $res.text('جاري الاتصال...').css('color', '#888');
        $.post(ajax, { action: 'wamc_test_connection', nonce: $btn.data('nonce') || nonce }, function (r) {
            if (r.success) {
                $res.text('✓ متصل').css('color', '#25D366');
            } else {
                $res.text('✗ ' + (r.data ? r.data.message : 'فشل')).css('color', '#e00');
            }
        }).always(function () { $btn.prop('disabled', false); });
    });

    /* ── Copy Webhook URL ────────────────────────────────────────────── */
    $(document).on('click', '#wamc-copy-webhook', function () {
        var url = $(this).data('url') || $('#wamc-webhook-url').val();
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
                alert('تم النسخ ✓');
            });
        } else {
            $('#wamc-webhook-url').select();
            document.execCommand('copy');
            alert('تم النسخ ✓');
        }
    });

    /* ── Register Webhook ────────────────────────────────────────────── */
    $(document).on('click', '#wamc-register-webhook', function () {
        var $btn = $(this);
        var $res = $('#wamc-webhook-result');
        $btn.prop('disabled', true);
        $res.text('جاري التسجيل...').css('color', '#888');
        $.post(ajax, { action: 'wamc_register_webhook', nonce: $btn.data('nonce') || nonce }, function (r) {
            if (r.success) {
                $res.text('✓ تم التسجيل').css('color', '#25D366');
            } else {
                $res.text('✗ ' + (r.data ? r.data.message : 'فشل')).css('color', '#e00');
            }
        }).always(function () { $btn.prop('disabled', false); });
    });

    /* ── Notify mode radio toggle ─────────────────────────────────────── */
    function bindNotifyMode(prefix) {
        $(document).on('change', 'input[name$="[' + prefix + '_notify_mode]"]', function () {
            var mode = $(this).val();
            $('.wamc-mode-panel-' + prefix).hide();
            $('#wamc-' + prefix + '-panel-' + mode).show();
        });
        $('input[name$="[' + prefix + '_notify_mode]"]:checked').trigger('change');
    }
    bindNotifyMode('admin');
    bindNotifyMode('qp');

    /* ── Dashboard handlers live in WAMC_Admin_Page::render_page() ───── */

    /* ── Blacklist: remove number ────────────────────────────────────── */
    $(document).on('click', '.wamc-remove-blacklist', function () {
        var $btn  = $(this);
        var phone = $btn.data('phone');
        var n     = $btn.data('nonce') || nonce;
        if ( ! confirm('إزالة ' + phone + ' من القائمة السوداء؟') ) return;
        $btn.prop('disabled', true).text('جاري...');
        $.post(ajax, { action: 'wamc_remove_blacklist', nonce: n, phone: phone }, function (r) {
            if (r.success) {
                $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
            } else {
                $btn.prop('disabled', false).text('✕ إزالة');
                alert('فشل الحذف');
            }
        });
    });

    function esc(s) { return $('<div>').text(String(s)).html(); }

})(jQuery);
