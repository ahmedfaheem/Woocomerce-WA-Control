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

    /* ── Dashboard: Message Log ──────────────────────────────────────── */
    var logPage = 1;
    var logPageCount = 1;

    function loadLog(page) {
        logPage = page;
        var type = $('input[name="log_type"]:checked').val() || 'sent';
        $('#wamc-log-table-wrap').html('<p>جاري التحميل...</p>');
        $.post(ajax, { action: 'wamc_fetch_log', nonce: nonce, log_type: type, page: logPage }, function (r) {
            if (!r.success) {
                $('#wamc-log-table-wrap').html('<p style="color:red">فشل التحميل</p>');
                return;
            }
            var result     = r.data.result || {};
            var rows       = result.data || [];
            logPageCount   = result.pageCount || 1;

            if (!rows.length) {
                $('#wamc-log-table-wrap').html('<p>لا توجد رسائل</p>');
                return;
            }

            var html = '<table class="widefat fixed wamc-log-table"><thead><tr>'
                + '<th>Phone / Group</th><th>Message</th><th>Status</th><th>Delivery</th><th>Created</th>'
                + '</tr></thead><tbody>';

            $.each(rows, function (i, row) {
                var statusClass = 'wamc-status-' + (row.status || '').toLowerCase().replace(/\s+/g, '-');
                html += '<tr>'
                    + '<td>' + esc(row.phonenumber || row.groupId || '') + '</td>'
                    + '<td>' + esc((row.text || '').substr(0, 80)) + '</td>'
                    + '<td><span class="wamc-status ' + statusClass + '">' + esc(row.status || '') + '</span></td>'
                    + '<td>' + esc(row.delivery || '') + '</td>'
                    + '<td>' + esc(row.createdAt || '') + '</td>'
                    + '</tr>';
            });

            html += '</tbody></table>';
            $('#wamc-log-table-wrap').html(html);
            $('#wamc-log-pager').text('صفحة ' + logPage + ' من ' + logPageCount);
            $('#wamc-log-prev').prop('disabled', logPage <= 1);
            $('#wamc-log-next').prop('disabled', logPage >= logPageCount);
        });
    }

    $(document).on('click', '#wamc-btn-load-log', function () { loadLog(1); });
    $(document).on('click', '#wamc-log-prev', function () { if (logPage > 1) loadLog(logPage - 1); });
    $(document).on('click', '#wamc-log-next', function () { if (logPage < logPageCount) loadLog(logPage + 1); });

    /* ── Dashboard: Service Info ─────────────────────────────────────── */
    $(document).on('click', '#wamc-btn-load-service', function () {
        $('#wamc-service-output').html('<p>جاري التحميل...</p>');
        $.post(ajax, { action: 'wamc_fetch_service', nonce: nonce }, function (r) {
            if (!r.success) { $('#wamc-service-output').html('<p style="color:red">فشل</p>'); return; }
            var state    = r.data.state    || {};
            var settings = r.data.settings || {};
            var stateVal = state.state || state.status || 'Unknown';
            var html = '<div class="wamc-service-grid">';
            html += '<div class="wamc-service-card"><strong>Connection State</strong><br>'
                  + '<span class="wamc-status wamc-status-' + stateVal.toLowerCase() + '">' + esc(stateVal) + '</span></div>';
            var result = settings.result || settings.data || {};
            $.each(result, function (k, v) {
                html += '<div class="wamc-service-card"><strong>' + esc(k) + '</strong><br>' + esc(String(v)) + '</div>';
            });
            html += '</div>';
            $('#wamc-service-output').html(html);
        });
    });

    /* ── Dashboard: Group List ───────────────────────────────────────── */
    $(document).on('click', '#wamc-btn-load-groups', function () {
        $('#wamc-groups-output').html('<p>جاري التحميل...</p>');
        $.post(ajax, { action: 'wamc_get_groups', nonce: nonce }, function (r) {
            if (!r.success) { $('#wamc-groups-output').html('<p style="color:red">فشل</p>'); return; }
            var groups = r.data.result || r.data.data || r.data || [];
            if (!Array.isArray(groups) || !groups.length) {
                $('#wamc-groups-output').html('<p>لا توجد مجموعات</p>');
                return;
            }
            var html = '<table class="widefat fixed wamc-log-table"><thead><tr><th>اسم المجموعة</th><th>Group ID</th><th></th></tr></thead><tbody>';
            $.each(groups, function (i, g) {
                var gid   = esc(g.id || g.groupId || g._serialized || '');
                var gname = esc(g.name || g.subject || '');
                html += '<tr><td>' + gname + '</td><td><code>' + gid + '</code></td>'
                      + '<td><button type="button" class="button wamc-copy-gid" data-id="' + gid + '">📋 نسخ ID</button></td></tr>';
            });
            html += '</tbody></table>';
            $('#wamc-groups-output').html(html);
        });
    });

    $(document).on('click', '.wamc-copy-gid', function () {
        var id = $(this).data('id');
        if (navigator.clipboard) {
            navigator.clipboard.writeText(id);
        }
        $(this).text('✓ تم النسخ').prop('disabled', true);
    });

    /* ── Utility ─────────────────────────────────────────────────────── */
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
