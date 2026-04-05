<?php
/**
 * Plugin Name: Real Estate Prediction Market
 * Description: Predict property sale prices, compete with investors, and earn points for accuracy.
 * Version: 1.0.1
 * Author: Hahz Terry (Wizard of Hahz)
 */

if (!defined('ABSPATH')) exit;

class RealEstatePredictionMarket {
    const CAPABILITY = 'manage_options';

    public function __construct() {
        // Admin menus
        add_action('admin_menu', [$this, 'register_admin_menu']);
        
        // Database creation on activation
        register_activation_hook(__FILE__, [$this, 'create_tables']);
        
        // Frontend shortcodes
        add_action('init', [$this, 'register_shortcodes']);
        add_action('init', [$this, 'handle_prediction_submit']);
        add_action('init', [$this, 'handle_group_create']);
        add_action('init', [$this, 'handle_group_join']);
        
        // Admin POST handlers
        add_action('admin_init', [$this, 'handle_admin_actions']);
    }
    
    /**
     * Handle admin POST actions
     */
    public function handle_admin_actions() {
        if (!is_admin()) return;
        
        // Add property
        if (isset($_POST['rep_add_property']) && current_user_can(self::CAPABILITY)) {
            check_admin_referer('rep_add_property_action');
            
            global $wpdb;
            $table = $wpdb->prefix . 'rep_properties';
            
            $wpdb->insert($table, [
                'address' => sanitize_text_field($_POST['address']),
                'neighborhood' => sanitize_text_field($_POST['neighborhood']),
                'city' => sanitize_text_field($_POST['city']),
                'property_type' => sanitize_text_field($_POST['property_type']),
                'bedrooms' => intval($_POST['bedrooms']),
                'bathrooms' => floatval($_POST['bathrooms']),
                'square_feet' => intval($_POST['square_feet']),
                'image_url' => esc_url_raw($_POST['image_url']),
                'created_at' => current_time('mysql'),
            ]);
            
            wp_safe_redirect(admin_url('admin.php?page=rep-properties'));
            exit;
        }
        
        // Add listing
        if (isset($_POST['rep_add_listing']) && current_user_can(self::CAPABILITY)) {
            check_admin_referer('rep_add_listing_action');
            
            global $wpdb;
            $table = $wpdb->prefix . 'rep_listings';
            
            $listing_date = sanitize_text_field($_POST['listing_date']);
            $close_date = sanitize_text_field($_POST['close_date']);
            $predict_close_date = sanitize_text_field($_POST['predict_close_date']);
            
            $wpdb->insert($table, [
                'property_id' => intval($_POST['property_id']),
                'list_price' => floatval($_POST['list_price']),
                'listing_date' => $listing_date ?: null,
                'close_date' => $close_date ?: null,
                'predict_close_date' => $predict_close_date ?: null,
                'status' => 'active',
                'created_at' => current_time('mysql'),
            ]);
            
            wp_safe_redirect(admin_url('admin.php?page=rep-listings'));
            exit;
        }
    }
    
    /**
     * Create database tables (no foreign keys to avoid crashes)
     */
    public function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        $properties = $wpdb->prefix . 'rep_properties';
        $sql_properties = "CREATE TABLE IF NOT EXISTS $properties (
            id INT NOT NULL AUTO_INCREMENT,
            address VARCHAR(255) NOT NULL,
            neighborhood VARCHAR(100),
            city VARCHAR(100),
            state VARCHAR(50),
            zip VARCHAR(20),
            property_type ENUM('house','condo','townhouse','land','commercial','multi-family') DEFAULT 'house',
            bedrooms INT,
            bathrooms DECIMAL(3,1),
            square_feet INT,
            lot_size DECIMAL(10,2),
            year_built INT,
            image_url VARCHAR(255),
            created_at DATETIME,
            PRIMARY KEY (id)
        ) $charset;";
        
        $listings = $wpdb->prefix . 'rep_listings';
        $sql_listings = "CREATE TABLE IF NOT EXISTS $listings (
            id INT NOT NULL AUTO_INCREMENT,
            property_id INT NOT NULL,
            list_price DECIMAL(12,2) NOT NULL,
            listing_date DATETIME,
            close_date DATETIME,
            predict_close_date DATETIME,
            actual_sale_price DECIMAL(12,2),
            status ENUM('active','closed','cancelled','pending') DEFAULT 'active',
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY (id),
            KEY property_id (property_id)
        ) $charset;";
        
        $predictions = $wpdb->prefix . 'rep_predictions';
        $sql_predictions = "CREATE TABLE IF NOT EXISTS $predictions (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            listing_id INT NOT NULL,
            predicted_price DECIMAL(12,2) NOT NULL,
            confidence_level ENUM('low','medium','high') DEFAULT 'medium',
            notes TEXT,
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY (id),
            KEY listing_id (listing_id),
            KEY user_id (user_id)
        ) $charset;";
        
        $scores = $wpdb->prefix . 'rep_scores';
        $sql_scores = "CREATE TABLE IF NOT EXISTS $scores (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            prediction_id INT NOT NULL,
            points_accuracy INT DEFAULT 0,
            points_bonus INT DEFAULT 0,
            points_total INT DEFAULT 0,
            percent_off DECIMAL(5,2),
            calculated_at DATETIME,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY prediction_id (prediction_id)
        ) $charset;";
        
        $groups = $wpdb->prefix . 'rep_groups';
        $sql_groups = "CREATE TABLE IF NOT EXISTS $groups (
            id INT NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            neighborhood VARCHAR(100),
            city VARCHAR(100),
            join_code VARCHAR(10) NOT NULL UNIQUE,
            owner_user_id INT NOT NULL,
            is_private TINYINT DEFAULT 0,
            created_at DATETIME,
            PRIMARY KEY (id)
        ) $charset;";
        
        $members = $wpdb->prefix . 'rep_group_members';
        $sql_members = "CREATE TABLE IF NOT EXISTS $members (
            id INT NOT NULL AUTO_INCREMENT,
            group_id INT NOT NULL,
            user_id INT NOT NULL,
            role ENUM('owner','admin','member') DEFAULT 'member',
            joined_at DATETIME,
            PRIMARY KEY (id),
            KEY group_id (group_id),
            KEY user_id (user_id)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_properties);
        dbDelta($sql_listings);
        dbDelta($sql_predictions);
        dbDelta($sql_scores);
        dbDelta($sql_groups);
        dbDelta($sql_members);
    }
    
    /**
     * Register admin menus
     */
    public function register_admin_menu() {
        add_menu_page(
            'RE Prediction',
            '🏠 RE Predict',
            self::CAPABILITY,
            'rep-dashboard',
            [$this, 'render_dashboard'],
            'dashicons-chart-line',
            26
        );
        
        add_submenu_page('rep-dashboard', 'Properties', 'Properties', self::CAPABILITY, 'rep-properties', [$this, 'render_properties_admin']);
        add_submenu_page('rep-dashboard', 'Listings', 'Listings', self::CAPABILITY, 'rep-listings', [$this, 'render_listings_admin']);
        add_submenu_page('rep-dashboard', 'Enter Results', 'Enter Results', self::CAPABILITY, 'rep-results', [$this, 'render_results_admin']);
    }
    
    /**
     * Register shortcodes
     */
    public function register_shortcodes() {
        add_shortcode('rep_markets', [$this, 'shortcode_markets']);
        add_shortcode('rep_leaderboard', [$this, 'shortcode_leaderboard']);
        add_shortcode('rep_dashboard', [$this, 'shortcode_dashboard']);
    }
    
    /**
     * Simple dashboard render
     */
    public function render_dashboard() {
        echo '<div class="wrap"><h1>🏠 Real Estate Prediction Market</h1>';
        echo '<p>Plugin activated successfully! Use shortcodes:</p>';
        echo '<ul><li>[rep_markets] - Show active prediction markets</li>';
        echo '<li>[rep_leaderboard] - Show top predictors</li>';
        echo '<li>[rep_dashboard] - User dashboard</li></ul>';
        echo '</div>';
    }
    
    /**
     * Properties admin
     */
    public function render_properties_admin() {
        global $wpdb;
        $table = $wpdb->prefix . 'rep_properties';
        $properties = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 100");
        
        echo '<div class="wrap"><h1>Properties</h1>';
        
        // Add property form
        echo '<h2>Add Property</h2>';
        echo '<form method="post">';
        wp_nonce_field('rep_add_property_action');
        echo '<table class="form-table">';
        echo '<tr><th>Address</th><td><input type="text" name="address" required></td></tr>';
        echo '<tr><th>Neighborhood</th><td><input type="text" name="neighborhood"></td></tr>';
        echo '<tr><th>City</th><td><input type="text" name="city"></td></tr>';
        echo '<tr><th>Property Type</th><td><select name="property_type"><option value="house">House</option><option value="condo">Condo</option><option value="commercial">Commercial</option></select></td></tr>';
        echo '<tr><th>Bedrooms</th><td><input type="number" name="bedrooms"></td></tr>';
        echo '<tr><th>Bathrooms</th><td><input type="number" step="0.5" name="bathrooms"></td></tr>';
        echo '<tr><th>Square Feet</th><td><input type="number" name="square_feet"></td></tr>';
        echo '<tr><th>Image URL</th><td><input type="url" name="image_url" style="width:300px"></td></tr>';
        echo '</table>';
        echo '<p><button class="button button-primary" name="rep_add_property" value="1">Add Property</button></p>';
        echo '</form>';
        
        // List properties
        echo '<hr><h2>Property List</h2>';
        if ($properties) {
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Address</th><th>Type</th><th>Beds/Baths</th></tr></thead><tbody>';
            foreach ($properties as $p) {
                echo '<tr><td>' . $p->id . '</td><td>' . esc_html($p->address) . '</td><td>' . esc_html($p->property_type) . '</td><td>' . intval($p->bedrooms) . '/' . floatval($p->bathrooms) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
    
    /**
     * Listings admin
     */
    public function render_listings_admin() {
        global $wpdb;
        $properties_table = $wpdb->prefix . 'rep_properties';
        $listings_table = $wpdb->prefix . 'rep_listings';
        
        $properties = $wpdb->get_results("SELECT id, address FROM $properties_table");
        
        echo '<div class="wrap"><h1>Listings / Markets</h1>';
        
        echo '<h2>Create Market</h2>';
        echo '<form method="post">';
        wp_nonce_field('rep_add_listing_action');
        echo '<table class="form-table">';
        echo '<tr><th>Property</th><td><select name="property_id" required><option value="">Select</option>';
        foreach ($properties as $p) {
            echo '<option value="' . $p->id . '">' . esc_html($p->address) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th>List Price ($)</th><td><input type="number" name="list_price" step="1000" required></td></tr>';
        echo '<tr><th>Prediction Cutoff</th><td><input type="datetime-local" name="predict_close_date"></td></tr>';
        echo '</table>';
        echo '<p><button class="button button-primary" name="rep_add_listing" value="1">Create Market</button></p>';
        echo '</form>';
        echo '</div>';
    }
    
    /**
     * Results admin
     */
    public function render_results_admin() {
        global $wpdb;
        $listings_table = $wpdb->prefix . 'rep_listings';
        $properties_table = $wpdb->prefix . 'rep_properties';
        
        // Handle result submission
        if (isset($_POST['rep_save_result'])) {
            check_admin_referer('rep_save_result_action');
            $listing_id = intval($_POST['listing_id']);
            $actual_price = floatval($_POST['actual_price']);
            
            if ($listing_id && $actual_price) {
                $wpdb->update($listings_table, ['actual_sale_price' => $actual_price, 'status' => 'closed'], ['id' => $listing_id]);
                echo '<div class="notice notice-success"><p>Result saved!</p></div>';
            }
        }
        
        $listings = $wpdb->get_results("
            SELECT l.*, p.address 
            FROM $listings_table l
            JOIN $properties_table p ON p.id = l.property_id
            WHERE l.status = 'active' AND l.predict_close_date <= NOW()
        ");
        
        echo '<div class="wrap"><h1>Enter Sale Results</h1>';
        
        if (!$listings) {
            echo '<p>No pending results.</p></div>';
            return;
        }
        
        echo '<table class="widefat striped">';
        foreach ($listings as $l) {
            echo '<tr><form method="post">';
            wp_nonce_field('rep_save_result_action');
            echo '<td>' . esc_html($l->address) . '</td>';
            echo '<td>$' . number_format($l->list_price, 2) . '</td>';
            echo '<td><input type="number" name="actual_price" step="1000" required> $</td>';
            echo '<td><input type="hidden" name="listing_id" value="' . $l->id . '"><button class="button button-primary" name="rep_save_result" value="1">Save</button></td>';
            echo '</form></tr>';
        }
        echo '</table></div>';
    }
    
    /**
     * Shortcode: Markets
     */
    public function shortcode_markets() {
        if (!is_user_logged_in()) {
            return '<p>🔐 Please <a href="' . wp_login_url(get_permalink()) . '">login</a> to view markets.</p>';
        }
        
        global $wpdb;
        $listings_table = $wpdb->prefix . 'rep_listings';
        $properties_table = $wpdb->prefix . 'rep_properties';
        
        $listings = $wpdb->get_results("
            SELECT l.*, p.address, p.property_type, p.image_url
            FROM $listings_table l
            JOIN $properties_table p ON p.id = l.property_id
            WHERE l.status = 'active'
            ORDER BY l.predict_close_date ASC
        ");
        
        if (!$listings) {
            return '<div><p>📭 No active prediction markets.</p></div>';
        }
        
        ob_start();
        echo '<div class="rep-markets-grid">';
        foreach ($listings as $l) {
            echo '<div class="rep-market-card">';
            echo '<div class="rep-market-body">';
            echo '<h3>' . esc_html($l->address) . '</h3>';
            echo '<p>List Price: $' . number_format($l->list_price, 2) . '</p>';
            echo '<p>⏳ Closes: ' . date_i18n('M j, Y', strtotime($l->predict_close_date)) . '</p>';
            
            echo '<form method="post">';
            wp_nonce_field('rep_prediction_action');
            echo '<input type="hidden" name="listing_id" value="' . $l->id . '">';
            echo '<input type="number" name="predicted_price" step="1000" placeholder="Your prediction $" required>';
            echo '<button type="submit" name="rep_submit_prediction" class="button">Submit</button>';
            echo '</form>';
            echo '</div></div>';
        }
        echo '</div>';
        
        echo '<style>
            .rep-markets-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin: 30px 0; }
            .rep-market-card { background: #f5f5f5; border-radius: 12px; padding: 20px; border: 1px solid #ddd; }
            .rep-market-card h3 { margin-top: 0; color: #333; }
            .rep-market-card input { width: 100%; padding: 8px; margin: 10px 0; border: 1px solid #ccc; border-radius: 4px; }
            .rep-market-card button { background: #007cba; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; }
        </style>';
        
        return ob_get_clean();
    }
    
    /**
     * Shortcode: Leaderboard
     */
    public function shortcode_leaderboard() {
        global $wpdb;
        
        $scores = $wpdb->get_results("
            SELECT u.display_name, COALESCE(SUM(s.points_total), 0) as total_points
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->prefix}rep_scores s ON s.user_id = u.ID
            GROUP BY u.ID
            ORDER BY total_points DESC
            LIMIT 20
        ");
        
        ob_start();
        echo '<div class="rep-leaderboard">';
        echo '<h2>🏆 Top Predictors</h2>';
        echo '<table style="width:100%; border-collapse: collapse;">';
        echo '<thead><tr><th>Rank</th><th>User</th><th>Points</th></tr></thead><tbody>';
        $rank = 1;
        foreach ($scores as $s) {
            echo '<tr><td>' . $rank++ . '</td><td>' . esc_html($s->display_name) . '</td><td>' . number_format($s->total_points) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        
        return ob_get_clean();
    }
    
    /**
     * Shortcode: Dashboard
     */
    public function shortcode_dashboard() {
        if (!is_user_logged_in()) {
            return '<p>🔐 Please <a href="' . wp_login_url(get_permalink()) . '">login</a> to view dashboard.</p>';
        }
        
        $user = wp_get_current_user();
        
        return '<div class="rep-dashboard">
            <h2>🏠 Welcome, ' . esc_html($user->display_name) . '!</h2>
            <p>Use [rep_markets] to view and predict property sale prices.</p>
            <p>Use [rep_leaderboard] to see top predictors.</p>
        </div>';
    }
    
    /**
     * Handle prediction submission
     */
    public function handle_prediction_submit() {
        if (!isset($_POST['rep_submit_prediction'])) return;
        if (!is_user_logged_in()) return;
        if (!wp_verify_nonce($_POST['_wpnonce'], 'rep_prediction_action')) return;
        
        global $wpdb;
        $predictions_table = $wpdb->prefix . 'rep_predictions';
        
        $user_id = get_current_user_id();
        $listing_id = intval($_POST['listing_id']);
        $predicted_price = floatval($_POST['predicted_price']);
        
        $wpdb->insert($predictions_table, [
            'user_id' => $user_id,
            'listing_id' => $listing_id,
            'predicted_price' => $predicted_price,
            'created_at' => current_time('mysql'),
        ]);
        
        wp_safe_redirect(add_query_arg('rep_saved', '1', wp_get_referer()));
        exit;
    }
    
    /**
     * Handle group creation (placeholder)
     */
    public function handle_group_create() {
        // Simplified - no groups in this version
    }
    
    /**
     * Handle group join (placeholder)
     */
    public function handle_group_join() {
        // Simplified - no groups in this version
    }
}

new RealEstatePredictionMarket();
