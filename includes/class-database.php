<?php
/**
 * データベース操作クラス
 * 
 * @package SEO_Cannibalization_Resolver
 */

if (!defined('ABSPATH')) {
    exit;
}

class SCR_Database {
    
    /**
     * テーブル作成
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // キーワードデータテーブル
        $table_keywords = $wpdb->prefix . 'scr_keywords';
        $sql_keywords = "CREATE TABLE $table_keywords (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            keyword varchar(500) NOT NULL,
            page_url text NOT NULL,
            clicks int(11) DEFAULT 0,
            impressions int(11) DEFAULT 0,
            ctr decimal(5,4) DEFAULT 0,
            position decimal(5,2) DEFAULT 0,
            date_recorded date NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY keyword_idx (keyword(191)),
            KEY page_url_idx (page_url(191)),
            KEY date_idx (date_recorded)
        ) $charset_collate;";
        
        // カニバリゼーション検出結果テーブル
        $table_cannibalization = $wpdb->prefix . 'scr_cannibalization';
        $sql_cannibalization = "CREATE TABLE $table_cannibalization (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            keyword varchar(500) NOT NULL,
            page_urls text NOT NULL,
            severity enum('low','medium','high','critical') DEFAULT 'low',
            total_clicks int(11) DEFAULT 0,
            total_impressions int(11) DEFAULT 0,
            avg_position decimal(5,2) DEFAULT 0,
            recommendation text,
            status enum('pending','resolved','ignored') DEFAULT 'pending',
            detected_at datetime DEFAULT CURRENT_TIMESTAMP,
            resolved_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY keyword_idx (keyword(191)),
            KEY severity_idx (severity),
            KEY status_idx (status)
        ) $charset_collate;";
        
        // 分析履歴テーブル
        $table_history = $wpdb->prefix . 'scr_analysis_history';
        $sql_history = "CREATE TABLE $table_history (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            analysis_type varchar(50) NOT NULL,
            total_keywords int(11) DEFAULT 0,
            cannibalized_keywords int(11) DEFAULT 0,
            affected_pages int(11) DEFAULT 0,
            analysis_data longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY analysis_type_idx (analysis_type)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_keywords);
        dbDelta($sql_cannibalization);
        dbDelta($sql_history);
    }
    
    /**
     * キーワードデータ保存
     */
    public static function save_keyword_data($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'scr_keywords';
        
        return $wpdb->insert($table, array(
            'keyword' => $data['keyword'],
            'page_url' => $data['page_url'],
            'clicks' => $data['clicks'],
            'impressions' => $data['impressions'],
            'ctr' => $data['ctr'],
            'position' => $data['position'],
            'date_recorded' => $data['date_recorded'],
        ), array('%s', '%s', '%d', '%d', '%f', '%f', '%s'));
    }
    
    /**
     * キーワードデータ一括保存
     */
    public static function bulk_save_keyword_data($data_array) {
        global $wpdb;
        $table = $wpdb->prefix . 'scr_keywords';
        
        // 既存データをクリア（同日のデータ）
        $date = $data_array[0]['date_recorded'] ?? date('Y-m-d');
        $wpdb->delete($table, array('date_recorded' => $date), array('%s'));
        
        $inserted = 0;
        foreach ($data_array as $data) {
            $result = self::save_keyword_data($data);
            if ($result) {
                $inserted++;
            }
        }
        
        return $inserted;
    }
    
    /**
     * カニバリゼーションデータ保存
     */
    public static function save_cannibalization($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'scr_cannibalization';
        
        // 既存の同じキーワードのデータを更新または新規作成
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE keyword = %s AND status = 'pending'",
            $data['keyword']
        ));
        
        if ($existing) {
            return $wpdb->update($table, array(
                'page_urls' => $data['page_urls'],
                'severity' => $data['severity'],
                'total_clicks' => $data['total_clicks'],
                'total_impressions' => $data['total_impressions'],
                'avg_position' => $data['avg_position'],
                'recommendation' => $data['recommendation'],
                'detected_at' => current_time('mysql'),
            ), array('id' => $existing->id), 
            array('%s', '%s', '%d', '%d', '%f', '%s', '%s'),
            array('%d'));
        }
        
        return $wpdb->insert($table, array(
            'keyword' => $data['keyword'],
            'page_urls' => $data['page_urls'],
            'severity' => $data['severity'],
            'total_clicks' => $data['total_clicks'],
            'total_impressions' => $data['total_impressions'],
            'avg_position' => $data['avg_position'],
            'recommendation' => $data['recommendation'],
        ), array('%s', '%s', '%s', '%d', '%d', '%f', '%s'));
    }
    
    /**
     * キーワード別ページ取得
     */
    public static function get_pages_by_keyword($days = 28) {
        global $wpdb;
        $table = $wpdb->prefix . 'scr_keywords';
        
        $date_from = date('Y-m-d', strtotime("-{$days} days"));
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT 
                keyword,
                page_url,
                SUM(clicks) as total_clicks,
                SUM(impressions) as total_impressions,
                AVG(ctr) as avg_ctr,
                AVG(position) as avg_position
            FROM $table
            WHERE date_recorded >= %s
            GROUP BY keyword, page_url
            HAVING total_impressions > 0
            ORDER BY keyword, total_clicks DESC
        ", $date_from), ARRAY_A);
    }
    
    /**
     * カニバリゼーション一覧取得
     */
    public static function get_cannibalization_list($args = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'scr_cannibalization';
        
        $defaults = array(
            'status' => 'pending',
            'severity' => '',
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'total_impressions',
            'order' => 'DESC',
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = array('1=1');
        $values = array();
        
        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }
        
        if (!empty($args['severity'])) {
            $where[] = 'severity = %s';
            $values[] = $args['severity'];
        }
        
        $where_clause = implode(' AND ', $where);
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        
        $sql = "SELECT * FROM $table WHERE $where_clause ORDER BY $orderby LIMIT %d OFFSET %d";
        $values[] = $args['limit'];
        $values[] = $args['offset'];
        
        return $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);
    }
    
    /**
     * 統計情報取得
     */
    public static function get_statistics() {
        global $wpdb;
        $table_keywords = $wpdb->prefix . 'scr_keywords';
        $table_cannibalization = $wpdb->prefix . 'scr_cannibalization';
        
        $stats = array();
        
        // 総キーワード数
        $stats['total_keywords'] = $wpdb->get_var("SELECT COUNT(DISTINCT keyword) FROM $table_keywords");
        
        // 総ページ数
        $stats['total_pages'] = $wpdb->get_var("SELECT COUNT(DISTINCT page_url) FROM $table_keywords");
        
        // カニバリゼーション数（ステータス別）
        $stats['cannibalization'] = $wpdb->get_results("
            SELECT status, severity, COUNT(*) as count
            FROM $table_cannibalization
            GROUP BY status, severity
        ", ARRAY_A);
        
        // 未解決のカニバリゼーション数
        $stats['pending_count'] = $wpdb->get_var("SELECT COUNT(*) FROM $table_cannibalization WHERE status = 'pending'");
        
        // 深刻度別カウント
        $stats['severity_counts'] = $wpdb->get_results("
            SELECT severity, COUNT(*) as count
            FROM $table_cannibalization
            WHERE status = 'pending'
            GROUP BY severity
        ", ARRAY_A);
        
        return $stats;
    }
    
    /**
     * ステータス更新
     */
    public static function update_status($id, $status) {
        global $wpdb;
        $table = $wpdb->prefix . 'scr_cannibalization';
        
        $data = array('status' => $status);
        if ($status === 'resolved') {
            $data['resolved_at'] = current_time('mysql');
        }
        
        return $wpdb->update($table, $data, array('id' => $id), array('%s', '%s'), array('%d'));
    }
}
