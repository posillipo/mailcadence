/* global farmadatiAjax, jQuery */
(function ($) {
    'use strict';

    // -------------------------------------------------------------------------
    // Connection test
    // -------------------------------------------------------------------------
    $(document).on('click', '#farmadati-test-connection', function () {
        var $btn    = $(this);
        var $result = $('#farmadati-connection-result');

        $btn.prop('disabled', true).text(farmadatiAjax.i18n.testing);
        $result.html('').removeClass('notice-success notice-error').hide();

        $.post(farmadatiAjax.ajaxurl, {
            action:  'farmadati_test_connection',
            nonce:   farmadatiAjax.nonce
        })
        .done(function (res) {
            if (res.success) {
                $result.addClass('notice notice-success').html('<p>' + res.data + '</p>').show();
            } else {
                $result.addClass('notice notice-error').html('<p>' + res.data + '</p>').show();
            }
        })
        .fail(function () {
            $result.addClass('notice notice-error').html('<p>' + farmadatiAjax.i18n.ajaxError + '</p>').show();
        })
        .always(function () {
            $btn.prop('disabled', false).text(farmadatiAjax.i18n.testConnection);
        });
    });

    // -------------------------------------------------------------------------
    // Product search
    // -------------------------------------------------------------------------
    var currentPage = 1;
    var lastSearch  = {};

    function buildResultsTable(records) {
        if (!records || records.length === 0) {
            return '<p>' + farmadatiAjax.i18n.noResults + '</p>';
        }

        var html = '<table class="wp-list-table widefat fixed striped farmadati-results">';
        html += '<thead><tr>';
        html += '<th>' + farmadatiAjax.i18n.colCode   + '</th>';
        html += '<th>' + farmadatiAjax.i18n.colName   + '</th>';
        html += '<th>' + farmadatiAjax.i18n.colPrice  + '</th>';
        html += '<th>' + farmadatiAjax.i18n.colStatus + '</th>';
        html += '<th>' + farmadatiAjax.i18n.colAction + '</th>';
        html += '</tr></thead><tbody>';

        $.each(records, function (i, r) {
            var statusLabel = r.FDI_0245 === 'S'
                ? '<span class="farmadati-badge farmadati-badge--on">'  + farmadatiAjax.i18n.inCommerce  + '</span>'
                : '<span class="farmadati-badge farmadati-badge--off">' + farmadatiAjax.i18n.outCommerce + '</span>';

            var editLink = r._wc_id
                ? ' <a href="' + r._wc_edit + '" target="_blank" class="button button-small">' + farmadatiAjax.i18n.edit + '</a>'
                : '';

            var importLabel = r._wc_id ? farmadatiAjax.i18n.update : farmadatiAjax.i18n.importProduct;

            html += '<tr>';
            html += '<td>' + escHtml(r.FDI_0001 || '') + '</td>';
            html += '<td>' + escHtml(r.FDI_0004 || '') + '</td>';
            html += '<td>' + escHtml(r.FDI_9007 || '') + '</td>';
            html += '<td>' + statusLabel + '</td>';
            html += '<td>';
            html += '<button class="button button-primary farmadati-import-btn" data-record=\'' + JSON.stringify(r) + '\'>' + importLabel + '</button>';
            html += editLink;
            html += '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        return html;
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function doSearch(page) {
        var $form    = $('#farmadati-search-form');
        var $results = $('#farmadati-search-results');
        var $pages   = $('#farmadati-pagination');
        var $spinner = $form.find('.spinner');

        var params = {
            action:     'farmadati_search',
            nonce:      farmadatiAjax.nonce,
            table:      $form.find('[name="table"]').val(),
            field_key:  $form.find('[name="field_key"]').val(),
            query:      $form.find('[name="query"]').val(),
            page:       page || 1
        };

        lastSearch = params;
        currentPage = parseInt(params.page, 10);

        $spinner.addClass('is-active');
        $results.html('');
        $pages.html('');

        $.post(farmadatiAjax.ajaxurl, params)
        .done(function (res) {
            if (!res.success) {
                $results.html('<div class="notice notice-error"><p>' + res.data + '</p></div>');
                return;
            }
            var data = res.data;
            $results.html(buildResultsTable(data.records));

            // Pagination
            if (data.pages > 1) {
                var pagHtml = '<div class="farmadati-pagination">';
                if (currentPage > 1) {
                    pagHtml += '<button class="button farmadati-page-btn" data-page="' + (currentPage - 1) + '">&laquo; ' + farmadatiAjax.i18n.prev + '</button> ';
                }
                pagHtml += '<span>' + farmadatiAjax.i18n.page + ' ' + currentPage + ' / ' + data.pages + '</span>';
                if (currentPage < data.pages) {
                    pagHtml += ' <button class="button farmadati-page-btn" data-page="' + (currentPage + 1) + '">' + farmadatiAjax.i18n.next + ' &raquo;</button>';
                }
                pagHtml += '</div>';
                $pages.html(pagHtml);
            }
        })
        .fail(function () {
            $results.html('<div class="notice notice-error"><p>' + farmadatiAjax.i18n.ajaxError + '</p></div>');
        })
        .always(function () {
            $spinner.removeClass('is-active');
        });
    }

    $(document).on('submit', '#farmadati-search-form', function (e) {
        e.preventDefault();
        doSearch(1);
    });

    $(document).on('click', '.farmadati-page-btn', function () {
        doSearch($(this).data('page'));
    });

    // -------------------------------------------------------------------------
    // Single-product import
    // -------------------------------------------------------------------------
    $(document).on('click', '.farmadati-import-btn', function () {
        var $btn    = $(this);
        var record  = $btn.data('record');
        var table   = $('#farmadati-search-form [name="table"]').val() || 'TE001';

        $btn.prop('disabled', true).text(farmadatiAjax.i18n.importing);

        $.post(farmadatiAjax.ajaxurl, {
            action:  'farmadati_import_product',
            nonce:   farmadatiAjax.nonce,
            record:  JSON.stringify(record),
            table:   table
        })
        .done(function (res) {
            if (res.success) {
                $btn.text(farmadatiAjax.i18n.imported).addClass('button-disabled');
                var editUrl = res.data.edit_url;
                if (editUrl && !$btn.next('.farmadati-edit-link').length) {
                    $btn.after(' <a href="' + editUrl + '" class="button button-small farmadati-edit-link" target="_blank">' + farmadatiAjax.i18n.edit + '</a>');
                }
            } else {
                alert(res.data);
                $btn.prop('disabled', false).text(farmadatiAjax.i18n.importProduct);
            }
        })
        .fail(function () {
            alert(farmadatiAjax.i18n.ajaxError);
            $btn.prop('disabled', false).text(farmadatiAjax.i18n.importProduct);
        });
    });

    // -------------------------------------------------------------------------
    // Manual sync
    // -------------------------------------------------------------------------
    $(document).on('click', '#farmadati-run-sync', function () {
        var $btn     = $(this);
        var $log     = $('#farmadati-sync-log');
        var syncType = $('input[name="sync_type"]:checked').val() || 'incremental';

        $btn.prop('disabled', true).text(farmadatiAjax.i18n.syncing);
        $log.html('<p>' + farmadatiAjax.i18n.syncRunning + '</p>');

        $.post(farmadatiAjax.ajaxurl, {
            action:     'farmadati_run_sync',
            nonce:      farmadatiAjax.nonce,
            sync_type:  syncType
        })
        .done(function (res) {
            if (res.success) {
                var html = '<table class="widefat fixed"><thead><tr><th>' + farmadatiAjax.i18n.colTable + '</th><th>' + farmadatiAjax.i18n.colResult + '</th></tr></thead><tbody>';
                $.each(res.data, function (table, result) {
                    var cell = (typeof result === 'object')
                        ? JSON.stringify(result)
                        : escHtml(String(result));
                    html += '<tr><td><strong>' + escHtml(table) + '</strong></td><td>' + cell + '</td></tr>';
                });
                html += '</tbody></table>';
                $log.html(html);
            } else {
                $log.html('<div class="notice notice-error"><p>' + res.data + '</p></div>');
            }
        })
        .fail(function () {
            $log.html('<div class="notice notice-error"><p>' + farmadatiAjax.i18n.ajaxError + '</p></div>');
        })
        .always(function () {
            $btn.prop('disabled', false).text(farmadatiAjax.i18n.runSync);
        });
    });

}(jQuery));
