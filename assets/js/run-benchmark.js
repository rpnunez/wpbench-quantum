jQuery(document).ready(function($) {
    // Profile selector logic
    $('#profile-selector').on('change', function() {
        var profileId = $(this).val();
        var $spinner = $('#profile-spinner');
        
        $('#profile_id_hidden').val(profileId);

        if (!profileId) {
            // Reset to manual if they choose the default option
            // Potentially reset checkboxes to current active plugins or clear them.
            // For now, leave as is, or clear them:
            // $('#the-list input[name="benchmark_plugins[]"]').prop('checked', false);
            // $('#tests-to-run-fieldset input[name="benchmark_tests[]"]').prop('checked', true); // Or false
            return;
        }
        
        $spinner.addClass('is-active');

        $.ajax({
            url: wpBenchmarkRunParams.ajax_url,
            type: 'POST',
            data: {
                action: 'wpb_get_profile_data',
                profile_id: profileId,
                _ajax_nonce: wpBenchmarkRunParams.get_profile_nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var plugins = response.data.plugins || [];
                    var tests = response.data.tests || [];
                    
                    // Update plugin checkboxes
                    $('#the-list input[name="benchmark_plugins[]"]').each(function() {
                        $(this).prop('checked', plugins.includes($(this).val()));
                    });
                    
                    // Update test checkboxes
                    $('#tests-to-run-fieldset input[name="benchmark_tests[]"]').each(function() {
                        $(this).prop('checked', tests.includes($(this).val()));
                    });
                } else {
                    alert(wpBenchmarkRunParams.i18n.profileErrorPrefix + (response.data.message || wpBenchmarkRunParams.i18n.unknownError));
                }
            },
            error: function() {
                alert(wpBenchmarkRunParams.i18n.profileAjaxError);
            },
            complete: function() {
                $spinner.removeClass('is-active');
            }
        });
    });

    // Toggle all plugins
    $('#select-all-plugins-toggle').on('change', function() {
        $('#the-list input[type="checkbox"][name="benchmark_plugins[]"]').prop('checked', $(this).prop('checked'));
    });

    // Run benchmark button
    $('#run-benchmark-button').on('click', function() {
        var $button = $(this);
        var $spinner = $button.siblings('.spinner');
        var $noticeArea = $('#benchmark-notice-area');
        var $progressArea = $('#benchmark-progress-area');
        var $logArea = $('#benchmark-log');

        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $noticeArea.html(''); 
        $logArea.html('');    
        $progressArea.show();

        var formData = $('#run-benchmark-form').serialize();
        $logArea.append(wpBenchmarkRunParams.i18n.initializing + "\n");
        $logArea.append(wpBenchmarkRunParams.i18n.waitMessage + "\n\n");

        $.ajax({
            url: wpBenchmarkRunParams.ajax_url, 
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $logArea.append(wpBenchmarkRunParams.i18n.completedSuccess + "\n");
                    if(response.data.log) { response.data.log.forEach(function(entry) { $logArea.append(entry + "\n"); }); }
                    if(typeof response.data.score !== 'undefined') { $logArea.append("\n" + wpBenchmarkRunParams.i18n.calculatedScore + " " + response.data.score + "\n"); }
                    $logArea.append("\n" + wpBenchmarkRunParams.i18n.resultsId + " " + response.data.post_id + "\n");
                    $logArea.append(wpBenchmarkRunParams.i18n.viewResults + " <a href='" + response.data.post_link + "' target='_blank'>" + response.data.post_link + "</a>\n");
                    $noticeArea.html('<div class="notice notice-success is-dismissible"><p>' + wpBenchmarkRunParams.i18n.completedNotice + ' ' + wpBenchmarkRunParams.i18n.scoreLabel + ' ' + response.data.score + '. <a href="' + response.data.post_link + '" target="_blank">' + wpBenchmarkRunParams.i18n.viewResultsLink + '</a>.</p></div>');
                } else {
                    var errorMessage = response.data && response.data.message ? response.data.message : wpBenchmarkRunParams.i18n.unknownError;
                    if(response.data.log) { response.data.log.forEach(function(entry) { $logArea.append(entry + "\n"); });}
                    $logArea.append("\n" + wpBenchmarkRunParams.i18n.errorPrefix + " " + errorMessage + "\n");
                    $noticeArea.html('<div class="notice notice-error is-dismissible"><p>' + wpBenchmarkRunParams.i18n.failedNotice + ' ' + errorMessage + '</p></div>');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                $logArea.append(wpBenchmarkRunParams.i18n.ajaxErrorPrefix + " " + textStatus + " - " + errorThrown + "\n");
                if (jqXHR.responseText) { $logArea.append(wpBenchmarkRunParams.i18n.serverResponse + "\n" + jqXHR.responseText.substring(0, 500) + "...\n"); }
                $noticeArea.html('<div class="notice notice-error is-dismissible"><p>' + wpBenchmarkRunParams.i18n.ajaxErrorDetails + '</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
                $logArea.scrollTop($logArea[0].scrollHeight);
            }
        });
    });
});