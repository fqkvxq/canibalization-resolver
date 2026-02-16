<?php
/**
 * カニバリゼーション検出クラス
 * 
 * @package SEO_Cannibalization_Resolver
 */

if (!defined('ABSPATH')) {
    exit;
}

class SCR_Cannibalization_Detector {
    
    /**
     * 検出しきい値設定
     */
    private $thresholds = array(
        'min_impressions' => 10,        // 最小インプレッション数
        'min_pages' => 2,               // カニバリと判定する最小ページ数
        'position_variance' => 5,       // 順位の分散しきい値
        'click_distribution' => 0.3,    // クリック分散しきい値
    );
    
    /**
     * 深刻度判定基準
     */
    private $severity_criteria = array(
        'critical' => array(
            'min_impressions' => 1000,
            'min_pages' => 3,
            'position_range' => 10,
        ),
        'high' => array(
            'min_impressions' => 500,
            'min_pages' => 2,
            'position_range' => 15,
        ),
        'medium' => array(
            'min_impressions' => 100,
            'min_pages' => 2,
            'position_range' => 20,
        ),
        'low' => array(
            'min_impressions' => 10,
            'min_pages' => 2,
            'position_range' => 30,
        ),
    );
    
    /**
     * カニバリゼーション分析実行
     */
    public function analyze($days = 28) {
        // キーワード別ページデータ取得
        $keyword_pages = SCR_Database::get_pages_by_keyword($days);
        
        if (empty($keyword_pages)) {
            return new WP_Error('no_data', 'データがありません。まずGSCからデータをインポートしてください。');
        }
        
        // キーワードごとにグループ化
        $grouped = $this->group_by_keyword($keyword_pages);
        
        // カニバリゼーション検出
        $cannibalization_issues = array();
        
        foreach ($grouped as $keyword => $pages) {
            // 最小ページ数チェック
            if (count($pages) < $this->thresholds['min_pages']) {
                continue;
            }
            
            // 総インプレッションチェック
            $total_impressions = array_sum(array_column($pages, 'total_impressions'));
            if ($total_impressions < $this->thresholds['min_impressions']) {
                continue;
            }
            
            // カニバリゼーション判定
            $issue = $this->detect_cannibalization($keyword, $pages);
            
            if ($issue !== null) {
                $cannibalization_issues[] = $issue;
            }
        }
        
        // 結果をデータベースに保存
        foreach ($cannibalization_issues as $issue) {
            SCR_Database::save_cannibalization($issue);
        }
        
        // 分析履歴保存
        $this->save_analysis_history($keyword_pages, $cannibalization_issues);
        
        return array(
            'total_keywords_analyzed' => count($grouped),
            'cannibalization_found' => count($cannibalization_issues),
            'issues' => $cannibalization_issues,
        );
    }
    
    /**
     * キーワードでグループ化
     */
    private function group_by_keyword($data) {
        $grouped = array();
        
        foreach ($data as $row) {
            $keyword = $row['keyword'];
            if (!isset($grouped[$keyword])) {
                $grouped[$keyword] = array();
            }
            $grouped[$keyword][] = $row;
        }
        
        return $grouped;
    }
    
    /**
     * カニバリゼーション検出
     */
    private function detect_cannibalization($keyword, $pages) {
        // 基本統計計算
        $total_clicks = array_sum(array_column($pages, 'total_clicks'));
        $total_impressions = array_sum(array_column($pages, 'total_impressions'));
        $positions = array_column($pages, 'avg_position');
        
        $avg_position = array_sum($positions) / count($positions);
        $min_position = min($positions);
        $max_position = max($positions);
        $position_range = $max_position - $min_position;
        
        // クリック分散計算
        $click_distribution = $this->calculate_click_distribution($pages);
        
        // カニバリゼーション判定ロジック
        $is_cannibalization = false;
        $reasons = array();
        
        // 条件1: 複数ページが近い順位で競合
        if ($position_range <= 20 && count($pages) >= 2) {
            $is_cannibalization = true;
            $reasons[] = '複数ページが近い検索順位で競合しています';
        }
        
        // 条件2: クリックが分散している
        if ($click_distribution < $this->thresholds['click_distribution'] && $total_clicks > 0) {
            $is_cannibalization = true;
            $reasons[] = 'クリックが複数ページに分散しています';
        }
        
        // 条件3: 高インプレッションだが低クリック率
        $overall_ctr = $total_impressions > 0 ? $total_clicks / $total_impressions : 0;
        if ($total_impressions > 100 && $overall_ctr < 0.02 && count($pages) >= 2) {
            $is_cannibalization = true;
            $reasons[] = 'インプレッションに対してCTRが低く、ページ間で競合している可能性があります';
        }
        
        if (!$is_cannibalization) {
            return null;
        }
        
        // 深刻度判定
        $severity = $this->determine_severity($total_impressions, count($pages), $position_range);
        
        // 推奨アクション生成
        $recommendation = $this->generate_recommendation($pages, $reasons, $severity);
        
        return array(
            'keyword' => $keyword,
            'page_urls' => json_encode(array_column($pages, 'page_url')),
            'severity' => $severity,
            'total_clicks' => $total_clicks,
            'total_impressions' => $total_impressions,
            'avg_position' => round($avg_position, 2),
            'recommendation' => $recommendation,
            'details' => array(
                'pages' => $pages,
                'reasons' => $reasons,
                'position_range' => $position_range,
                'click_distribution' => $click_distribution,
            ),
        );
    }
    
    /**
     * クリック分散度計算
     * 1に近いほど1ページに集中、0に近いほど分散
     */
    private function calculate_click_distribution($pages) {
        $clicks = array_column($pages, 'total_clicks');
        $total = array_sum($clicks);
        
        if ($total === 0) {
            return 0;
        }
        
        $max_clicks = max($clicks);
        return $max_clicks / $total;
    }
    
    /**
     * 深刻度判定
     */
    private function determine_severity($impressions, $page_count, $position_range) {
        foreach ($this->severity_criteria as $level => $criteria) {
            if ($impressions >= $criteria['min_impressions'] &&
                $page_count >= $criteria['min_pages'] &&
                $position_range <= $criteria['position_range']) {
                return $level;
            }
        }
        
        return 'low';
    }
    
    /**
     * 推奨アクション生成
     */
    private function generate_recommendation($pages, $reasons, $severity) {
        $recommendations = array();
        
        // ベストページ特定
        usort($pages, function($a, $b) {
            // クリック数 > インプレッション数 > 順位 の優先度で比較
            if ($a['total_clicks'] !== $b['total_clicks']) {
                return $b['total_clicks'] - $a['total_clicks'];
            }
            if ($a['total_impressions'] !== $b['total_impressions']) {
                return $b['total_impressions'] - $a['total_impressions'];
            }
            return $a['avg_position'] - $b['avg_position'];
        });
        
        $best_page = $pages[0];
        $other_pages = array_slice($pages, 1);
        
        $recommendations[] = sprintf(
            '【メインページ候補】%s（クリック: %d, インプレッション: %d, 平均順位: %.1f）',
            $best_page['page_url'],
            $best_page['total_clicks'],
            $best_page['total_impressions'],
            $best_page['avg_position']
        );
        
        // 深刻度別の推奨アクション
        switch ($severity) {
            case 'critical':
                $recommendations[] = '⚠️ 緊急対応推奨: このキーワードは重大なカニバリゼーションが発生しています。';
                $recommendations[] = '→ 競合ページの統合またはリダイレクトを検討してください。';
                $recommendations[] = '→ メインページ以外のページからこのキーワードを削除または別キーワードに変更してください。';
                break;
                
            case 'high':
                $recommendations[] = '⚡ 早期対応推奨: 明確なカニバリゼーションが検出されました。';
                $recommendations[] = '→ 各ページのターゲットキーワードを明確に差別化してください。';
                $recommendations[] = '→ 内部リンク構造を見直し、メインページへの権威集中を検討してください。';
                break;
                
            case 'medium':
                $recommendations[] = '📊 対応検討: カニバリゼーションの兆候があります。';
                $recommendations[] = '→ 各ページのコンテンツの差別化を確認してください。';
                $recommendations[] = '→ canonicalタグの設定を確認してください。';
                break;
                
            case 'low':
                $recommendations[] = '📝 監視推奨: 軽度のカニバリゼーションの可能性があります。';
                $recommendations[] = '→ 定期的にモニタリングし、悪化する場合は対応を検討してください。';
                break;
        }
        
        // 競合ページ情報
        if (!empty($other_pages)) {
            $recommendations[] = '';
            $recommendations[] = '【競合ページ】';
            foreach ($other_pages as $page) {
                $recommendations[] = sprintf(
                    '- %s（クリック: %d, 順位: %.1f）',
                    $page['page_url'],
                    $page['total_clicks'],
                    $page['avg_position']
                );
            }
        }
        
        return implode("\n", $recommendations);
    }
    
    /**
     * 分析履歴保存
     */
    private function save_analysis_history($keyword_pages, $issues) {
        global $wpdb;
        $table = $wpdb->prefix . 'scr_analysis_history';
        
        $unique_keywords = count(array_unique(array_column($keyword_pages, 'keyword')));
        $unique_pages = count(array_unique(array_column($keyword_pages, 'page_url')));
        
        $affected_pages = array();
        foreach ($issues as $issue) {
            $pages = json_decode($issue['page_urls'], true);
            $affected_pages = array_merge($affected_pages, $pages);
        }
        $affected_pages = array_unique($affected_pages);
        
        $wpdb->insert($table, array(
            'analysis_type' => 'full_scan',
            'total_keywords' => $unique_keywords,
            'cannibalized_keywords' => count($issues),
            'affected_pages' => count($affected_pages),
            'analysis_data' => json_encode(array(
                'severity_breakdown' => array_count_values(array_column($issues, 'severity')),
                'top_issues' => array_slice($issues, 0, 10),
            )),
        ), array('%s', '%d', '%d', '%d', '%s'));
    }
    
    /**
     * セマンティック類似度分析（拡張機能）
     * 将来的にコンテンツの意味的重複も検出
     */
    public function analyze_semantic_similarity($page_urls) {
        // この機能は将来的な拡張用
        // 外部APIやローカルのNLPモデルを使用して
        // ページコンテンツの意味的類似度を分析
        
        return array(
            'status' => 'not_implemented',
            'message' => 'セマンティック分析機能は今後のアップデートで追加予定です。',
        );
    }
}
