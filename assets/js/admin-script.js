/* assets/js/admin-script.js */

(function($) {
    'use strict';

    // DOM Ready
    $(document).ready(function() {
        SCRAdmin.init();
    });

    var SCRAdmin = {
        
        /**
         * 初期化
         */
        init: function() {
            this.bindEvents();
            this.initCharts();
        },

        /**
         * イベントバインド
         */
        bindEvents: function() {
            // GSCデータ取得
            $('.scr-fetch-data').on('click', this.fetchGSCData.bind(this));
            
            // カニバリ分析実行
            $('.scr-analyze').on('click', this.runAnalysis.bind(this));
            
            // ステータス更新
            $(document).on('click', '.scr-mark-resolved', this.markResolved.bind(this));
            $(document).on('click', '.scr-mark-ignored', this.markIgnored.bind(this));
            
            // GSC接続解除
            $('.scr-disconnect-gsc').on('click', this.disconnectGSC.bind(this));
            
            // 設定フォーム送信
            $('#scr-gsc-settings-form').on('submit', this.saveSettings.bind(this));
        },

        /**
         * GSCデータ取得
         */
        fetchGSCData: function(e) {
            e.preventDefault();
            
            var $button = $(e.currentTarget);
            var $status = $('.scr-action-status');
            
            $button.prop('disabled', true);
            $status.removeClass('success error').addClass('loading').text(scrAdmin.strings.loading).show();
            
            $.ajax({
                url: scrAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'scr_fetch_gsc_data',
                    nonce: scrAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.removeClass('loading').addClass('success');
                        $status.html(
                            '<strong>' + scrAdmin.strings.success + '</strong><br>' +
                            '取得行数: ' + response.data.total_rows + '<br>' +
                            '保存行数: ' + response.data.saved_rows + '<br>' +
                            '期間: ' + response.data.date_range.start + ' 〜 ' + response.data.date_range.end
                        );
                    } else {
                        $status.removeClass('loading').addClass('error');
                        $status.text(response.data.message || scrAdmin.strings.error);
                    }
                },
                error: function() {
                    $status.removeClass('loading').addClass('error').text(scrAdmin.strings.error);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * カニバリ分析実行
         */
        runAnalysis: function(e) {
            e.preventDefault();
            
            var $button = $(e.currentTarget);
            var $status = $('.scr-action-status');
            
            $button.prop('disabled', true);
            $status.removeClass('success error').addClass('loading').text(scrAdmin.strings.loading).show();
            
            $.ajax({
                url: scrAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'scr_analyze_cannibalization',
                    nonce: scrAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.removeClass('loading').addClass('success');
                        $status.html(
                            '<strong>' + scrAdmin.strings.success + '</strong><br>' +
                            '分析キーワード数: ' + response.data.total_keywords_analyzed + '<br>' +
                            '検出されたカニバリ: ' + response.data.cannibalization_found + '件'
                        );
                        
                        // 3秒後にページリロード
                        setTimeout(function() {
                            location.reload();
                        }, 3000);
                    } else {
                        $status.removeClass('loading').addClass('error');
                        $status.text(response.data.message || scrAdmin.strings.error);
                    }
                },
                error: function() {
                    $status.removeClass('loading').addClass('error').text(scrAdmin.strings.error);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * 解決済みにする
         */
        markResolved: function(e) {
            e.preventDefault();
            this.updateStatus($(e.currentTarget), 'resolved');
        },

        /**
         * 無視する
         */
        markIgnored: function(e) {
            e.preventDefault();
            this.updateStatus($(e.currentTarget), 'ignored');
        },

        /**
         * ステータス更新
         */
        updateStatus: function($button, status) {
            var id = $button.data('id');
            
            if (!confirm('このアクションを実行しますか？')) {
                return;
            }
            
            $button.prop('disabled', true);
            
            $.ajax({
                url: scrAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'scr_update_status',
                    nonce: scrAdmin.nonce,
                    id: id,
                    status: status
                },
                success: function(response) {
                    if (response.success) {
                        // カードまたは行を非表示
                        $button.closest('.scr-issue-card, tr').fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        alert(response.data.message || scrAdmin.strings.error);
                    }
                },
                error: function() {
                    alert(scrAdmin.strings.error);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * GSC接続解除
         */
        disconnectGSC: function(e) {
            e.preventDefault();
            
            if (!confirm('Google Search Consoleとの接続を解除しますか？')) {
                return;
            }
            
            $.ajax({
                url: scrAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'scr_disconnect_gsc',
                    nonce: scrAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || scrAdmin.strings.error);
                    }
                },
                error: function() {
                    alert(scrAdmin.strings.error);
                }
            });
        },

        /**
         * 設定保存
         */
        saveSettings: function(e) {
            e.preventDefault();
            
            var $form = $(e.currentTarget);
            var $button = $form.find('#scr-save-credentials');
            
            $button.prop('disabled', true);
            
            $.ajax({
                url: scrAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'scr_save_gsc_settings',
                    nonce: scrAdmin.nonce,
                    client_id: $('#scr_client_id').val(),
                    client_secret: $('#scr_client_secret').val()
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || scrAdmin.strings.error);
                    }
                },
                error: function() {
                    alert(scrAdmin.strings.error);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * チャート初期化
         */
        initCharts: function() {
            var $canvas = $('#scr-severity-chart');
            
            if ($canvas.length === 0 || typeof Chart === 'undefined') {
                return;
            }
            
            // 深刻度データを整形
            var severityData = window.scrSeverityData || [];
            var labels = [];
            var data = [];
            var colors = {
                'critical': '#d63638',
                'high': '#dba617',
                'medium': '#72aee6',
                'low': '#8c8f94'
            };
            var backgroundColors = [];
            
            var severityOrder = ['critical', 'high', 'medium', 'low'];
            
            severityOrder.forEach(function(severity) {
                var found = severityData.find(function(item) {
                    return item.severity === severity;
                });
                
                if (found && found.count > 0) {
                    labels.push(severity.charAt(0).toUpperCase() + severity.slice(1));
                    data.push(found.count);
                    backgroundColors.push(colors[severity]);
                }
            });
            
            if (data.length === 0) {
                $canvas.parent().html('<p style="text-align: center; color: #646970;">データがありません</p>');
                return;
            }
            
            new Chart($canvas, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: backgroundColors,
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true
                            }
                        }
                    }
                }
            });
        }
    };

})(jQuery);
