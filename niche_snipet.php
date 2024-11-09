if (!defined('ABSPATH')) {
    exit;
}

class NicheDiscoveryTool {
    private $api_key = 'KEY';
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'niche_discovery_results';
        
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_analyze_niche', array($this, 'handle_analysis'));
        add_action('wp_ajax_nopriv_analyze_niche', array($this, 'handle_analysis'));
        register_activation_hook(__FILE__, array($this, 'create_table'));
    }

    public function init() {
        // Initialize plugin
    }

    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            industry varchar(100) NOT NULL,
            demographics text NOT NULL,
            content_focus varchar(50) NOT NULL,
            campaign_goals varchar(100) NOT NULL,
            trends text NOT NULL,
            analysis_results longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function enqueue_scripts() {
        wp_enqueue_script(
            'niche-discovery-script',
            plugins_url('js/script.js', __FILE__),
            array('jquery'),
            '1.0.0',
            true
        );

        // Changed to match the JavaScript variable name
        wp_localize_script('niche-discovery-script', 'nicheAnalysisAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('niche_analysis_nonce')
        ));
    }

    private function call_gemini_api($prompt) {
        $api_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=" . $this->api_key;

        $body = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 2048
            ]
        ];

        $args = [
            'body' => json_encode($body),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 30
        ];

        $response = wp_remote_post($api_url, $args);

        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            throw new Exception('Invalid API response structure');
        }

        return trim($data['candidates'][0]['content']['parts'][0]['text']);
    }

    public function handle_analysis() {
        check_ajax_referer('niche_analysis_nonce', 'nonce');

        try {
            $required_fields = ['industry', 'demographics', 'contentFocus', 'campaignGoals', 'trends'];
            foreach ($required_fields as $field) {
                if (!isset($_POST[$field]) || empty($_POST[$field])) {
                    throw new Exception("Missing or empty required field: $field");
                }
            }

            $industry = sanitize_text_field($_POST['industry']);
            $demographics = sanitize_textarea_field($_POST['demographics']);
            $content_focus = sanitize_text_field($_POST['contentFocus']);
            $campaign_goals = sanitize_text_field($_POST['campaignGoals']);
            $trends = sanitize_textarea_field($_POST['trends']);

            $prompt = $this->generate_prompt($industry, $demographics, $content_focus, $campaign_goals, $trends);
            $analysis_results = $this->call_gemini_api($prompt);

            $this->save_results(array(
                'user_id' => get_current_user_id(),
                'industry' => $industry,
                'demographics' => $demographics,
                'content_focus' => $content_focus,
                'campaign_goals' => $campaign_goals,
                'trends' => $trends,
                'analysis_results' => $analysis_results
            ));

            wp_send_json_success(array('analysis' => $analysis_results));

        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    private function generate_prompt($industry, $demographics, $content_focus, $campaign_goals, $trends) {
        return "Based on the following user profile:
            - Industry: {$industry}
            - Audience Demographics: {$demographics}
            - Content Focus: {$content_focus}
            - Campaign Goals: {$campaign_goals}
            - Trends/Topics of Interest: {$trends}

            Recommend 3-5 trending, high-potential email marketing niches. For each niche, provide:
            1. A brief description and popularity metrics
            2. Recommended audience segmentation
            3. Specific content ideas, email subject lines, and messaging styles
            4. Analysis of competitors and differentiation strategies

            Format the response in a clear, structured way with sections for each niche.";
    }

    private function save_results($data) {
        global $wpdb;
        
        $wpdb->insert(
            $this->table_name,
            $data,
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }
}

// Initialize the plugin
new NicheDiscoveryTool();