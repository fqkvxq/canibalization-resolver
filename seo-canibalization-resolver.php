<?php
/**
 * Plugin Name: SEO Cannibalization Resolver
 * Plugin URI: https://example.com/seo-cannibalization-resolver
 * Description: Google Search Consoleのデータを活用してSEOカニバリゼーションを検出・解消するプラグイン
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * Text Domain: seo-cannibalization-resolver
 * Domain Path: /languages
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

// プラグイン定数
define('SCR_VERSION', '1.0.0');
define('SCR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SCR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SCR_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * メインプラグインクラス
 */
class SEO_Cannibalization_Resolver {
    
    private static $instance = null;
    
    /**
     * シングルトンインスタンス取得
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * コンストラクタ
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * 依存ファイル読み込み
     */
    private function load_dependencies() {
        require_once SCR_PLUGIN_DIR . 'includes/class-database.php';
        require_once SCR_PLUGIN_DIR . 'includes/class-gsc-api.php';
        require_once SCR_PLUGIN_DIR . 'includes/class-canibalization-detector.php';
        require_once SCR_PLUGIN_DIR . 'includes/class-admin-page.php';
    }
    
    /**
     * フック初期化
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_scr_fetch_gsc_data', array($this, 'ajax_fetch_gsc_data'));
        add_action('wp_ajax_scr_analyze_cannibalization', array($this, 'ajax_analyze_cannibalization'));
        add_action('wp_ajax_scr_connect_gsc', array($this, 'ajax_connect_gsc'));

        // ↓↓↓ 以下の3行を追加 ↓↓↓
        add_action('wp_ajax_scr_save_gsc_settings', array($this, 'ajax_save_gsc_settings'));
        add_action('wp_ajax_scr_disconnect_gsc', array($this, 'ajax_disconnect_gsc'));
        add_action('wp_ajax_scr_update_status', array($this, 'ajax_update_status'));
}
    }
    
    /**
     * プラグイン有効化
     */
    public function activate() {
        SCR_Database::create_tables();
        flush_rewrite_rules();
    }
    
    /**
     * プラグイン無効化
     */
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    /**
     * 管理メニュー追加
     */
    public function add_admin_menu() {
        add_menu_page(
            __('SEOカニバリ解消', 'seo-cannibalization-resolver'),
            __('SEOカニバリ解消', 'seo-cannibalization-resolver'),
            'manage_options',
            'seo-cannibalization-resolver',
            array($this, 'render_admin_page'),
            'dashicons-chart-area',
            30
        );
        
        add_submenu_page(
            'seo-cannibalization-resolver',
            __('ダッシュボード', 'seo-cannibalization-resolver'),
            __('ダッシュボード', 'seo-cannibalization-resolver'),
            'manage_options',
            'seo-cannibalization-resolver',
            array($this, 'render_admin_page')
        );
        
        add_submenu_page(
            'seo-cannibalization-resolver',
            __('GSC設定', 'seo-cannibalization-resolver'),
            __('GSC設定', 'seo-cannibalization-resolver'),
            'manage_options',
            'scr-gsc-settings',
            array($this, 'render_gsc_settings_page')
        );
        
        add_submenu_page(
            'seo-cannibalization-resolver',
            __('カニバリ分析', 'seo-cannibalization-resolver'),
            __('カニバリ分析', 'seo-cannibalization-resolver'),
            'manage_options',
            'scr-analysis',
            array($this, 'render_analysis_page')
        );
    }
    
    /**
     * 管理画面アセット読み込み
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'seo-cannibalization-resolver') === false && 
            strpos($hook, 'scr-') === false) {
            return;
        }
        
        wp_enqueue_style(
            'scr-admin-style',
            SCR_PLUGIN_URL . 'assets/css/admin-style.css',
            array(),
            SCR_VERSION
        );
        
        wp_enqueue_script(
            'scr-admin-script',
            SCR_PLUGIN_URL . 'assets/js/admin-script.js',
            array('jquery', 'chart-js'),
            SCR_VERSION,
            true
        );
        
        // Chart.js
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js',
            array(),
            '4.4.0',
            true
        );
        
        wp_localize_script('scr-admin-script', 'scrAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('scr_admin_nonce'),
            'strings' => array(
                'loading' => __('読み込み中...', 'seo-cannibalization-resolver'),
                'error' => __('エラーが発生しました', 'seo-cannibalization-resolver'),
                'success' => __('完了しました', 'seo-cannibalization-resolver'),
            )
        ));
    }
    
    /**
     * メイン管理ページ表示
     */
    public function render_admin_page() {
        $admin_page = new SCR_Admin_Page();
        $admin_page->render_dashboard();
    }
    
    /**
     * GSC設定ページ表示
     */
    public function render_gsc_settings_page() {
        $admin_page = new SCR_Admin_Page();
        $admin_page->render_gsc_settings();
    }
    
    /**
     * 分析ページ表示
     */
    public function render_analysis_page() {
        $admin_page = new SCR_Admin_Page();
        $admin_page->render_analysis();
    }
    
    /**
     * AJAX: GSCデータ取得
     */
    public function ajax_fetch_gsc_data() {
        check_ajax_referer('scr_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '権限がありません'));
        }
        
        $gsc_api = new SCR_GSC_API();
        $result = $gsc_api->fetch_search_analytics();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: カニバリゼーション分析
     */
    public function ajax_analyze_cannibalization() {
        check_ajax_referer('scr_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '権限がありません'));
        }
        
        $detector = new SCR_Cannibalization_Detector();
        $result = $detector->analyze();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: GSC接続
     */
    public function ajax_connect_gsc() {
        check_ajax_referer('scr_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '権限がありません'));
        }
        
        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $client_secret = sanitize_text_field($_POST['client_secret'] ?? '');
        
        if (empty($client_id) || empty($client_secret)) {
            wp_send_json_error(array('message' => 'Client IDとClient Secretを入力してください'));
        }
        
        update_option('scr_gsc_client_id', $client_id);
        update_option('scr_gsc_client_secret', $client_secret);
        
        $gsc_api = new SCR_GSC_API();
        $auth_url = $gsc_api->get_auth_url();
        
        wp_send_json_success(array('auth_url' => $auth_url));
    }

    /**
 * AJAX: GSC設定保存
 */
public function ajax_save_gsc_settings() {
    check_ajax_referer('scr_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '権限がありません'));
    }
    
    $client_id = sanitize_text_field($_POST['client_id'] ?? '');
    $client_secret = sanitize_text_field($_POST['client_secret'] ?? '');
    
    if (empty($client_id) || empty($client_secret)) {
        wp_send_json_error(array('message' => 'Client IDとClient Secretを入力してください'));
    }
    
    update_option('scr_gsc_client_id', $client_id);
    update_option('scr_gsc_client_secret', $client_secret);
    
    wp_send_json_success(array('message' => '設定を保存しました'));
}

/**
 * AJAX: GSC接続解除
 */
public function ajax_disconnect_gsc() {
    check_ajax_referer('scr_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '権限がありません'));
    }
    
    $gsc_api = new SCR_GSC_API();
    $gsc_api->disconnect();
    
    wp_send_json_success(array('message' => '接続を解除しました'));
}

/**
 * AJAX: ステータス更新
 */
public function ajax_update_status() {
    check_ajax_referer('scr_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '権限がありません'));
    }
    
    $id = intval($_POST['id'] ?? 0);
    $status = sanitize_text_field($_POST['status'] ?? '');
    
    if (empty($id) || !in_array($status, array('pending', 'resolved', 'ignored'))) {
        wp_send_json_error(array('message' => '無効なパラメータです'));
    }
    
    $result = SCR_Database::update_status($id, $status);
    
    if ($result === false) {
        wp_send_json_error(array('message' => '更新に失敗しました'));
    }
    
    wp_send_json_success(array('message' => 'ステータスを更新しました'));
}

    
}

// プラグイン初期化
function scr_init() {
    return SEO_Cannibalization_Resolver::get_instance();
}
add_action('plugins_loaded', 'scr_init');
