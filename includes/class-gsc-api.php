<?php
/**
 * Google Search Console API連携クラス
 * 
 * @package SEO_Cannibalization_Resolver
 */

if (!defined('ABSPATH')) {
    exit;
}

class SCR_GSC_API {
    
    private $client_id;
    private $client_secret;
    private $redirect_uri;
    private $access_token;
    private $refresh_token;
    private $api_base_url = 'https://www.googleapis.com/webmasters/v3';
    private $oauth_url = 'https://accounts.google.com/o/oauth2';
    private $token_url = 'https://oauth2.googleapis.com/token';
    
    /**
     * コンストラクタ
     */
    public function __construct() {
        $this->client_id = get_option('scr_gsc_client_id', '');
        $this->client_secret = get_option('scr_gsc_client_secret', '');
        $this->redirect_uri = admin_url('admin.php?page=scr-gsc-settings&scr_oauth_callback=1');
        $this->access_token = get_option('scr_gsc_access_token', '');
        $this->refresh_token = get_option('scr_gsc_refresh_token', '');
    }
    
    /**
     * 認証URL取得
     */
    public function get_auth_url() {
        $params = array(
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
        );
        
        return $this->oauth_url . '/auth?' . http_build_query($params);
    }
    
    /**
     * 認証コードからトークン取得
     */
    public function exchange_code_for_token($code) {
        $response = wp_remote_post($this->token_url, array(
            'body' => array(
                'code' => $code,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'redirect_uri' => $this->redirect_uri,
                'grant_type' => 'authorization_code',
            ),
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('oauth_error', $body['error_description'] ?? $body['error']);
        }
        
        // トークン保存
        update_option('scr_gsc_access_token', $body['access_token']);
        if (isset($body['refresh_token'])) {
            update_option('scr_gsc_refresh_token', $body['refresh_token']);
        }
        update_option('scr_gsc_token_expires', time() + $body['expires_in']);
        
        $this->access_token = $body['access_token'];
        
        return true;
    }
    
    /**
     * トークンリフレッシュ
     */
    public function refresh_access_token() {
        if (empty($this->refresh_token)) {
            return new WP_Error('no_refresh_token', 'リフレッシュトークンがありません。再認証してください。');
        }
        
        $response = wp_remote_post($this->token_url, array(
            'body' => array(
                'refresh_token' => $this->refresh_token,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'refresh_token',
            ),
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('refresh_error', $body['error_description'] ?? $body['error']);
        }
        
        update_option('scr_gsc_access_token', $body['access_token']);
        update_option('scr_gsc_token_expires', time() + $body['expires_in']);
        
        $this->access_token = $body['access_token'];
        
        return true;
    }
    
    /**
     * トークン有効性確認・更新
     */
    private function ensure_valid_token() {
        $expires = get_option('scr_gsc_token_expires', 0);
        
        if (time() >= $expires - 60) {
            return $this->refresh_access_token();
        }
        
        return true;
    }
    
    /**
     * API リクエスト実行
     */
    private function api_request($endpoint, $method = 'GET', $body = null) {
        $token_check = $this->ensure_valid_token();
        if (is_wp_error($token_check)) {
            return $token_check;
        }
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30,
        );
        
        if ($body !== null) {
            $args['body'] = json_encode($body);
        }
        
        $response = wp_remote_request($this->api_base_url . $endpoint, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code >= 400) {
            $error_message = $body['error']['message'] ?? 'APIエラーが発生しました';
            return new WP_Error('api_error', $error_message);
        }
        
        return $body;
    }
    
    /**
     * サイト一覧取得
     */
    public function get_sites() {
        return $this->api_request('/sites');
    }
    
    /**
     * 検索アナリティクスデータ取得
     */
    public function fetch_search_analytics($days = 28, $row_limit = 5000) {
        $site_url = get_option('scr_gsc_site_url', '');
        
        if (empty($site_url)) {
            // サイトURLが設定されていない場合、自動検出を試みる
            $site_url = $this->detect_site_url();
            if (is_wp_error($site_url)) {
                return $site_url;
            }
        }
        
        $encoded_site_url = urlencode($site_url);
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = date('Y-m-d', strtotime('-1 day'));
        
        $request_body = array(
            'startDate' => $start_date,
            'endDate' => $end_date,
            'dimensions' => array('query', 'page'),
            'rowLimit' => $row_limit,
            'startRow' => 0,
        );
        
        $all_rows = array();
        $start_row = 0;
        
        do {
            $request_body['startRow'] = $start_row;
            
            $response = $this->api_request(
                "/sites/{$encoded_site_url}/searchAnalytics/query",
                'POST',
                $request_body
            );
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            $rows = $response['rows'] ?? array();
            $all_rows = array_merge($all_rows, $rows);
            
            $start_row += $row_limit;
            
        } while (count($rows) === $row_limit && $start_row < 25000);
        
        // データを整形して保存
        $formatted_data = array();
        foreach ($all_rows as $row) {
            $formatted_data[] = array(
                'keyword' => $row['keys'][0],
                'page_url' => $row['keys'][1],
                'clicks' => $row['clicks'] ?? 0,
                'impressions' => $row['impressions'] ?? 0,
                'ctr' => $row['ctr'] ?? 0,
                'position' => $row['position'] ?? 0,
                'date_recorded' => $end_date,
            );
        }
        
        // データベースに保存
        $saved = SCR_Database::bulk_save_keyword_data($formatted_data);
        
        return array(
            'total_rows' => count($formatted_data),
            'saved_rows' => $saved,
            'date_range' => array(
                'start' => $start_date,
                'end' => $end_date,
            ),
        );
    }
    
    /**
     * サイトURL自動検出
     */
    private function detect_site_url() {
        $sites = $this->get_sites();
        
        if (is_wp_error($sites)) {
            return $sites;
        }
        
        $current_site = trailingslashit(home_url());
        $current_site_sc = 'sc-domain:' . parse_url($current_site, PHP_URL_HOST);
        
        foreach ($sites['siteEntry'] ?? array() as $site) {
            $site_url = $site['siteUrl'];
            
            // 完全一致またはドメインプロパティ
            if ($site_url === $current_site || 
                $site_url === rtrim($current_site, '/') ||
                $site_url === $current_site_sc) {
                update_option('scr_gsc_site_url', $site_url);
                return $site_url;
            }
        }
        
        return new WP_Error('site_not_found', 'Search Consoleにこのサイトが登録されていません。');
    }
    
    /**
     * 接続状態確認
     */
    public function is_connected() {
        return !empty($this->access_token) && !empty($this->refresh_token);
    }
    
    /**
     * 接続解除
     */
    public function disconnect() {
        delete_option('scr_gsc_access_token');
        delete_option('scr_gsc_refresh_token');
        delete_option('scr_gsc_token_expires');
        delete_option('scr_gsc_site_url');
    }
}
