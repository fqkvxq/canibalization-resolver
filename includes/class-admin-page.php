<?php
/**
 * 管理画面クラス
 * 
 * @package SEO_Cannibalization_Resolver
 */

if (!defined('ABSPATH')) {
    exit;
}

class SCR_Admin_Page {
    
    /**
     * ダッシュボード表示
     */
    public function render_dashboard() {
        $stats = SCR_Database::get_statistics();
        $recent_issues = SCR_Database::get_cannibalization_list(array(
            'limit' => 10,
            'orderby' => 'detected_at',
            'order' => 'DESC',
        ));
        
        ?>
        <div class="wrap scr-admin-wrap">
            <h1><?php _e('SEOカニバリゼーション解消 - ダッシュボード', 'seo-cannibalization-resolver'); ?></h1>
            
            <!-- 統計カード -->
            <div class="scr-stats-grid">
                <div class="scr-stat-card">
                    <div class="scr-stat-icon dashicons dashicons-search"></div>
                    <div class="scr-stat-content">
                        <span class="scr-stat-number"><?php echo number_format($stats['total_keywords'] ?? 0); ?></span>
                        <span class="scr-stat-label"><?php _e('総キーワード数', 'seo-cannibalization-resolver'); ?></span>
                    </div>
                </div>
                
                <div class="scr-stat-card">
                    <div class="scr-stat-icon dashicons dashicons-admin-page"></div>
                    <div class="scr-stat-content">
                        <span class="scr-stat-number"><?php echo number_format($stats['total_pages'] ?? 0); ?></span>
                        <span class="scr-stat-label"><?php _e('総ページ数', 'seo-cannibalization-resolver'); ?></span>
                    </div>
                </div>
                
                <div class="scr-stat-card scr-stat-warning">
                    <div class="scr-stat-icon dashicons dashicons-warning"></div>
                    <div class="scr-stat-content">
                        <span class="scr-stat-number"><?php echo number_format($stats['pending_count'] ?? 0); ?></span>
                        <span class="scr-stat-label"><?php _e('未解決の問題', 'seo-cannibalization-resolver'); ?></span>
                    </div>
                </div>
                
                <div class="scr-stat-card">
                    <div class="scr-stat-icon dashicons dashicons-chart-pie"></div>
                    <div class="scr-stat-content">
                        <span class="scr-stat-number">
                            <?php 
                            $critical = 0;
                            foreach ($stats['severity_counts'] ?? array() as $s) {
                                if ($s['severity'] === 'critical') {
                                    $critical = $s['count'];
                                    break;
                                }
                            }
                            echo number_format($critical);
                            ?>
                        </span>
                        <span class="scr-stat-label"><?php _e('重大な問題', 'seo-cannibalization-resolver'); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- クイックアクション -->
            <div class="scr-quick-actions">
                <h2><?php _e('クイックアクション', 'seo-cannibalization-resolver'); ?></h2>
                <div class="scr-action-buttons">
                    <button type="button" class="button button-primary scr-fetch-data">
                        <span class="dashicons dashicons-download"></span>
                        <?php _e('GSCデータを取得', 'seo-cannibalization-resolver'); ?>
                    </button>
                    <button type="button" class="button button-secondary scr-analyze">
                        <span class="dashicons dashicons-chart-area"></span>
                        <?php _e('カニバリ分析を実行', 'seo-cannibalization-resolver'); ?>
                    </button>
                </div>
                <div class="scr-action-status"></div>
            </div>
            
            <!-- 深刻度別チャート -->
            <div class="scr-charts-section">
                <h2><?php _e('問題の深刻度分布', 'seo-cannibalization-resolver'); ?></h2>
                <div class="scr-chart-container">
                    <canvas id="scr-severity-chart"></canvas>
                </div>
            </div>
            
            <!-- 最近の問題 -->
            <div class="scr-recent-issues">
                <h2><?php _e('最近検出された問題', 'seo-cannibalization-resolver'); ?></h2>
                <?php if (!empty($recent_issues)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('キーワード', 'seo-cannibalization-resolver'); ?></th>
                                <th><?php _e('深刻度', 'seo-cannibalization-resolver'); ?></th>
                                <th><?php _e('インプレッション', 'seo-cannibalization-resolver'); ?></th>
                                <th><?php _e('平均順位', 'seo-cannibalization-resolver'); ?></th>
                                <th><?php _e('検出日', 'seo-cannibalization-resolver'); ?></th>
                                <th><?php _e('アクション', 'seo-cannibalization-resolver'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_issues as $issue): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($issue['keyword']); ?></strong>
                                        <div class="row-actions">
                                            <span class="view">
                                                <a href="<?php echo admin_url('admin.php?page=scr-analysis&keyword=' . urlencode($issue['keyword'])); ?>">
                                                    <?php _e('詳細を見る', 'seo-cannibalization-resolver'); ?>
                                                </a>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="scr-severity scr-severity-<?php echo esc_attr($issue['severity']); ?>">
                                            <?php echo esc_html(ucfirst($issue['severity'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($issue['total_impressions']); ?></td>
                                    <td><?php echo number_format($issue['avg_position'], 1); ?></td>
                                    <td><?php echo date_i18n(get_option('date_format'), strtotime($issue['detected_at'])); ?></td>
                                    <td>
                                        <button type="button" class="button button-small scr-mark-resolved" data-id="<?php echo esc_attr($issue['id']); ?>">
                                            <?php _e('解決済み', 'seo-cannibalization-resolver'); ?>
                                        </button>
                                        <button type="button" class="button button-small scr-mark-ignored" data-id="<?php echo esc_attr($issue['id']); ?>">
                                            <?php _e('無視', 'seo-cannibalization-resolver'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="scr-no-data">
                        <p><?php _e('まだデータがありません。GSCからデータを取得して分析を実行してください。', 'seo-cannibalization-resolver'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- 深刻度データをJSに渡す -->
            <script>
                var scrSeverityData = <?php echo json_encode($stats['severity_counts'] ?? array()); ?>;
            </script>
        </div>
        <?php
    }
    
    /**
     * GSC設定ページ表示
     */
    public function render_gsc_settings() {
        $gsc_api = new SCR_GSC_API();
        $is_connected = $gsc_api->is_connected();
        
        // OAuth コールバック処理
        if (isset($_GET['scr_oauth_callback']) && isset($_GET['code'])) {
            $result = $gsc_api->exchange_code_for_token(sanitize_text_field($_GET['code']));
            if (is_wp_error($result)) {
                $error_message = $result->get_error_message();
            } else {
                $success_message = __('Google Search Consoleに正常に接続されました。', 'seo-cannibalization-resolver');
                $is_connected = true;
            }
        }
        
        $client_id = get_option('scr_gsc_client_id', '');
        $client_secret = get_option('scr_gsc_client_secret', '');
        $site_url = get_option('scr_gsc_site_url', '');
        
        ?>
        <div class="wrap scr-admin-wrap">
            <h1><?php _e('Google Search Console 設定', 'seo-cannibalization-resolver'); ?></h1>
            
            <?php if (isset($error_message)): ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html($error_message); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($success_message)): ?>
                <div class="notice notice-success">
                    <p><?php echo esc_html($success_message); ?></p>
                </div>
            <?php endif; ?>
            
            <!-- 接続状態 -->
            <div class="scr-connection-status">
                <h2><?php _e('接続状態', 'seo-cannibalization-resolver'); ?></h2>
                <?php if ($is_connected): ?>
                    <div class="scr-status-connected">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php _e('Google Search Consoleに接続されています', 'seo-cannibalization-resolver'); ?>
                        <?php if ($site_url): ?>
                            <br><small><?php printf(__('サイト: %s', 'seo-cannibalization-resolver'), esc_html($site_url)); ?></small>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="button scr-disconnect-gsc">
                        <?php _e('接続を解除', 'seo-cannibalization-resolver'); ?>
                    </button>
                <?php else: ?>
                    <div class="scr-status-disconnected">
                        <span class="dashicons dashicons-no-alt"></span>
                        <?php _e('Google Search Consoleに接続されていません', 'seo-cannibalization-resolver'); ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- 設定フォーム -->
            <div class="scr-settings-form">
                <h2><?php _e('API認証情報', 'seo-cannibalization-resolver'); ?></h2>
                
                <div class="scr-setup-instructions">
                    <h3><?php _e('設定手順', 'seo-cannibalization-resolver'); ?></h3>
                    <ol>
                        <li><?php _e('Google Cloud Consoleでプロジェクトを作成または選択します', 'seo-cannibalization-resolver'); ?></li>
                        <li><?php _e('「APIとサービス」→「ライブラリ」から「Search Console API」を有効化します', 'seo-cannibalization-resolver'); ?></li>
                        <li><?php _e('「APIとサービス」→「認証情報」でOAuth 2.0クライアントIDを作成します', 'seo-cannibalization-resolver'); ?></li>
                        <li><?php _e('アプリケーションの種類は「ウェブアプリケーション」を選択します', 'seo-cannibalization-resolver'); ?></li>
                        <li><?php printf(__('承認済みのリダイレクトURIに以下を追加します: %s', 'seo-cannibalization-resolver'), '<code>' . admin_url('admin.php?page=scr-gsc-settings&scr_oauth_callback=1') . '</code>'); ?></li>
                        <li><?php _e('作成されたClient IDとClient Secretを下記に入力します', 'seo-cannibalization-resolver'); ?></li>
                    </ol>
                </div>
                
                <form method="post" action="" id="scr-gsc-settings-form">
                    <?php wp_nonce_field('scr_gsc_settings', 'scr_gsc_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="scr_client_id"><?php _e('Client ID', 'seo-cannibalization-resolver'); ?></label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="scr_client_id" 
                                       name="scr_client_id" 
                                       value="<?php echo esc_attr($client_id); ?>" 
                                       class="regular-text"
                                       placeholder="xxxxx.apps.googleusercontent.com">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="scr_client_secret"><?php _e('Client Secret', 'seo-cannibalization-resolver'); ?></label>
                            </th>
                            <td>
                                <input type="password" 
                                       id="scr_client_secret" 
                                       name="scr_client_secret" 
                                       value="<?php echo esc_attr($client_secret); ?>" 
                                       class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label><?php _e('リダイレクトURI', 'seo-cannibalization-resolver'); ?></label>
                            </th>
                            <td>
                                <code><?php echo admin_url('admin.php?page=scr-gsc-settings&scr_oauth_callback=1'); ?></code>
                                <p class="description"><?php _e('このURIをGoogle Cloud Consoleの「承認済みのリダイレクトURI」に追加してください', 'seo-cannibalization-resolver'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary" id="scr-save-credentials">
                            <?php _e('認証情報を保存', 'seo-cannibalization-resolver'); ?>
                        </button>
                        
                        <?php if (!empty($client_id) && !empty($client_secret) && !$is_connected): ?>
                            <a href="<?php echo esc_url($gsc_api->get_auth_url()); ?>" class="button button-secondary">
                                <?php _e('Googleアカウントで認証', 'seo-cannibalization-resolver'); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * 分析ページ表示
     */
    public function render_analysis() {
        $current_filter = array(
            'status' => sanitize_text_field($_GET['status'] ?? 'pending'),
            'severity' => sanitize_text_field($_GET['severity'] ?? ''),
        );
        
        $issues = SCR_Database::get_cannibalization_list(array(
            'status' => $current_filter['status'],
            'severity' => $current_filter['severity'],
            'limit' => 50,
        ));
        
        ?>
        <div class="wrap scr-admin-wrap">
            <h1><?php _e('カニバリゼーション分析', 'seo-cannibalization-resolver'); ?></h1>
            
            <!-- フィルター -->
            <div class="scr-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="scr-analysis">
                    
                    <select name="status">
                        <option value="pending" <?php selected($current_filter['status'], 'pending'); ?>><?php _e('未解決', 'seo-cannibalization-resolver'); ?></option>
                        <option value="resolved" <?php selected($current_filter['status'], 'resolved'); ?>><?php _e('解決済み', 'seo-cannibalization-resolver'); ?></option>
                        <option value="ignored" <?php selected($current_filter['status'], 'ignored'); ?>><?php _e('無視', 'seo-cannibalization-resolver'); ?></option>
                        <option value="" <?php selected($current_filter['status'], ''); ?>><?php _e('すべて', 'seo-cannibalization-resolver'); ?></option>
                    </select>
                    
                    <select name="severity">
                        <option value=""><?php _e('すべての深刻度', 'seo-cannibalization-resolver'); ?></option>
                        <option value="critical" <?php selected($current_filter['severity'], 'critical'); ?>><?php _e('Critical', 'seo-cannibalization-resolver'); ?></option>
                        <option value="high" <?php selected($current_filter['severity'], 'high'); ?>><?php _e('High', 'seo-cannibalization-resolver'); ?></option>
                        <option value="medium" <?php selected($current_filter['severity'], 'medium'); ?>><?php _e('Medium', 'seo-cannibalization-resolver'); ?></option>
                        <option value="low" <?php selected($current_filter['severity'], 'low'); ?>><?php _e('Low', 'seo-cannibalization-resolver'); ?></option>
                    </select>
                    
                    <button type="submit" class="button"><?php _e('フィルター', 'seo-cannibalization-resolver'); ?></button>
                </form>
            </div>
            
            <!-- 問題一覧 -->
            <?php if (!empty($issues)): ?>
                <div class="scr-issues-list">
                    <?php foreach ($issues as $issue): ?>
                        <div class="scr-issue-card scr-severity-<?php echo esc_attr($issue['severity']); ?>">
                            <div class="scr-issue-header">
                                <h3><?php echo esc_html($issue['keyword']); ?></h3>
                                <span class="scr-severity-badge"><?php echo esc_html(ucfirst($issue['severity'])); ?></span>
                            </div>
                            
                            <div class="scr-issue-stats">
                                <div class="scr-stat">
                                    <span class="scr-stat-value"><?php echo number_format($issue['total_impressions']); ?></span>
                                    <span class="scr-stat-label"><?php _e('インプレッション', 'seo-cannibalization-resolver'); ?></span>
                                </div>
                                <div class="scr-stat">
                                    <span class="scr-stat-value"><?php echo number_format($issue['total_clicks']); ?></span>
                                    <span class="scr-stat-label"><?php _e('クリック', 'seo-cannibalization-resolver'); ?></span>
                                </div>
                                <div class="scr-stat">
                                    <span class="scr-stat-value"><?php echo number_format($issue['avg_position'], 1); ?></span>
                                    <span class="scr-stat-label"><?php _e('平均順位', 'seo-cannibalization-resolver'); ?></span>
                                </div>
                            </div>
                            
                            <div class="scr-issue-pages">
                                <h4><?php _e('競合ページ', 'seo-cannibalization-resolver'); ?></h4>
                                <ul>
                                    <?php 
                                    $pages = json_decode($issue['page_urls'], true);
                                    foreach ($pages as $page_url): 
                                    ?>
                                        <li>
                                            <a href="<?php echo esc_url($page_url); ?>" target="_blank">
                                                <?php echo esc_html($page_url); ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            
                            <div class="scr-issue-recommendation">
                                <h4><?php _e('推奨アクション', 'seo-cannibalization-resolver'); ?></h4>
                                <pre><?php echo esc_html($issue['recommendation']); ?></pre>
                            </div>
                            
                            <div class="scr-issue-actions">
                                <button type="button" class="button button-primary scr-mark-resolved" data-id="<?php echo esc_attr($issue['id']); ?>">
                                    <?php _e('解決済みにする', 'seo-cannibalization-resolver'); ?>
                                </button>
                                <button type="button" class="button scr-mark-ignored" data-id="<?php echo esc_attr($issue['id']); ?>">
                                    <?php _e('無視する', 'seo-cannibalization-resolver'); ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="scr-no-data">
                    <p><?php _e('該当する問題はありません。', 'seo-cannibalization-resolver'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
