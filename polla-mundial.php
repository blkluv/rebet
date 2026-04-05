<?php
/**
 * Plugin Name: Real Estate Prediction Market
 * Description: Predict property sale prices, compete with investors, and earn points for accuracy.
 * Version: 1.0.0
 * Author: Hahz Terry (Wizard of Hahz)
 */

if (!defined('ABSPATH')) exit;

class RealEstatePredictionMarket {
    const CAPABILITY = 'manage_options';

    public function __construct() {
        // Admin menus
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_admin_actions']);
        
        // Database creation on activation
        register_activation_hook(__FILE__, [$this, 'create_tables']);
        
        // Frontend shortcodes
        add_action('init', [$this, 'register_shortcodes']);
        add_action('init', [$this, 'handle_prediction_submit']);
        add_action('init', [$this, 'handle_group_create']);
        add_action('init', [$this, 'handle_group_join']);
    }
    
    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        // Properties table
        $properties = $wpdb->prefix . 'rep_properties';
        $sql_properties = "CREATE TABLE $properties (
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
        
        // Listings table
        $listings = $wpdb->prefix . 'rep_listings';
        $sql_listings = "CREATE TABLE $listings (
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
            FOREIGN KEY (property_id) REFERENCES $properties(id) ON DELETE CASCADE
        ) $charset;";
        
        // Predictions table
        $predictions = $wpdb->prefix . 'rep_predictions';
        $sql_predictions = "CREATE TABLE $predictions (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            listing_id INT NOT NULL,
            predicted_price DECIMAL(12,2) NOT NULL,
            confidence_level ENUM('low','medium','high') DEFAULT 'medium',
            notes TEXT,
            created_at DATETIME,
            updated_at DATETIME,
            PRIMARY KEY (id),
            FOREIGN KEY (listing_id) REFERENCES $listings(id) ON DELETE CASCADE
        ) $charset;";
        
        // Scores table
        $scores = $wpdb->prefix . 'rep_scores';
        $sql_scores = "CREATE TABLE $scores (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            prediction_id INT NOT NULL,
            points_accuracy INT DEFAULT 0,
            points_bonus INT DEFAULT 0,
            points_total INT DEFAULT 0,
            percent_off DECIMAL(5,2),
            calculated_at DATETIME,
            PRIMARY KEY (id),
            FOREIGN KEY (prediction_id) REFERENCES $predictions(id) ON DELETE CASCADE
        ) $charset;";
        
        // Groups (Neighborhood Leagues)
        $groups = $wpdb->prefix . 'rep_groups';
        $sql_groups = "CREATE TABLE $groups (
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
        
        // Group members
        $members = $wpdb->prefix . 'rep_group_members';
        $sql_members = "CREATE TABLE $members (
            id INT NOT NULL AUTO_INCREMENT,
            group_id INT NOT NULL,
            user_id INT NOT NULL,
            role ENUM('owner','admin','member') DEFAULT 'member',
            joined_at DATETIME,
            PRIMARY KEY (id),
            FOREIGN KEY (group_id) REFERENCES $groups(id) ON DELETE CASCADE
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
        
        add_submenu_page('rep-dashboard', 'Properties', 'Properties', self::CAPABILITY, 'rep-properties', [$this, 'render_properties']);
        add_submenu_page('rep-dashboard', 'Listings', 'Listings', self::CAPABILITY, 'rep-listings', [$this, 'render_listings']);
        add_submenu_page('rep-dashboard', 'Enter Results', 'Enter Results', self::CAPABILITY, 'rep-results', [$this, 'render_results']);
        add_submenu_page('rep-dashboard', 'Leaderboard', 'Leaderboard', self::CAPABILITY, 'rep-leaderboard', [$this, 'render_leaderboard_admin']);
    }
    
    /**
     * Register shortcodes
     */
    public function register_shortcodes() {
        add_shortcode('rep_markets', [$this, 'shortcode_markets']);
        add_shortcode('rep_leaderboard', [$this, 'shortcode_leaderboard']);
        add_shortcode('rep_groups', [$this, 'shortcode_groups']);
        add_shortcode('rep_my_predictions', [$this, 'shortcode_my_predictions']);
        add_shortcode('rep_dashboard', [$this, 'shortcode_dashboard']);
        add_shortcode('rep_property', [$this, 'shortcode_property']);
    }
    
    /**
     * Render dashboard
     */
    public function render_dashboard() {
        echo '<div class="wrap"><h1>🏠 Real Estate Prediction Market</h1>';
        echo '<p>Track properties, listings, and user predictions.</p>';
        echo '</div>';
    }
    
    /**
     * Render properties admin
     */
    public function render_properties() {
        global $wpdb;
        $table = $wpdb->prefix . 'rep_properties';
        $properties = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");
        
        echo '<div class="wrap"><h1>Properties</h1>';
        
        // Add property form
        echo '<h2>Add Property</h2>';
        echo '<form method="post">';
        wp_nonce_field('rep_add_property_action');
        echo '<table class="form-table">';
        echo '<tr><th>Address</th><td><input type="text" name="address" required style="width:300px"></td></tr>';
        echo '<tr><th>Neighborhood</th><td><input type="text" name="neighborhood"></td></tr>';
        echo '<tr><th>City</th><td><input type="text" name="city"></td></tr>';
        echo '<tr><th>Property Type</th><td>
            <select name="property_type">
                <option value="house">House</option>
                <option value="condo">Condo</option>
                <option value="townhouse">Townhouse</option>
                <option value="land">Land</option>
                <option value="commercial">Commercial</option>
            </select>
        </td></tr>';
        echo '<tr><th>Bedrooms</th><td><input type="number" name="bedrooms"></td></tr>';
        echo '<tr><th>Bathrooms</th><td><input type="number" step="0.5" name="bathrooms"></td></tr>';
        echo '<tr><th>Square Feet</th><td><input type="number" name="square_feet"></td></tr>';
        echo '<tr><th>Image URL</th><td><input type="url" name="image_url" style="width:300px"></td></tr>';
        echo '</table>';
        echo '<p><button class="button button-primary" name="rep_add_property" value="1">Add Property</button></p>';
        echo '</form>';
        
        // Properties list
        echo '<hr><h2>Property List</h2>';
        if ($properties) {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>ID</th><th>Address</th><th>Neighborhood</th><th>Type</th><th>Beds/Baths</th><th>Sq Ft</th></tr></thead><tbody>';
            foreach ($properties as $p) {
                echo '<tr>';
                echo '<td>' . intval($p->id) . '</td>';
                echo '<td>' . esc_html($p->address) . '</td>';
                echo '<td>' . esc_html($p->neighborhood) . '</td>';
                echo '<td>' . esc_html($p->property_type) . '</td>';
                echo '<td>' . intval($p->bedrooms) . '/' . floatval($p->bathrooms) . '</td>';
                echo '<td>' . number_format($p->square_feet) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
    
    /**
     * Render listings admin
     */
    public function render_listings() {
        global $wpdb;
        $properties_table = $wpdb->prefix . 'rep_properties';
        $listings_table = $wpdb->prefix . 'rep_listings';
        
        $properties = $wpdb->get_results("SELECT id, address FROM $properties_table ORDER BY address ASC");
        $listings = $wpdb->get_results("
            SELECT l.*, p.address, p.neighborhood 
            FROM $listings_table l
            JOIN $properties_table p ON p.id = l.property_id
            ORDER BY l.listing_date DESC
        ");
        
        echo '<div class="wrap"><h1>Listings / Markets</h1>';
        
        // Add listing form
        echo '<h2>Create Market</h2>';
        echo '<form method="post">';
        wp_nonce_field('rep_add_listing_action');
        echo '<table class="form-table">';
        echo '<tr><th>Property</th><td><select name="property_id" required>';
        echo '<option value="">Select Property</option>';
        foreach ($properties as $p) {
            echo '<option value="' . intval($p->id) . '">' . esc_html($p->address) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th>List Price</th><td>$<input type="number" name="list_price" step="1000" required></td></tr>';
        echo '<tr><th>Listing Date</th><td><input type="datetime-local" name="listing_date" required></td></tr>';
        echo '<tr><th>Expected Close Date</th><td><input type="datetime-local" name="close_date" required></td></tr>';
        echo '<tr><th>Prediction Cutoff (when market closes)</th><td><input type="datetime-local" name="predict_close_date" required></td></tr>';
        echo '</table>';
        echo '<p><button class="button button-primary" name="rep_add_listing" value="1">Create Market</button></p>';
        echo '</form>';
        
        // Listings list
        echo '<hr><h2>Active Markets</h2>';
        if ($listings) {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>ID</th><th>Property</th><th>List Price</th><th>Status</th><th>Listing Date</th><th>Predictions Close</th></tr></thead><tbody>';
            foreach ($listings as $l) {
                $status_badge = '';
                if ($l->status == 'active') $status_badge = '<span style="color:green;">● Active</span>';
                elseif ($l->status == 'closed') $status_badge = '<span style="color:blue;">● Closed</span>';
                else $status_badge = '<span style="color:gray;">● ' . esc_html($l->status) . '</span>';
                
                echo '<tr>';
                echo '<td>' . intval($l->id) . '</td>';
                echo '<td>' . esc_html($l->address) . '</td>';
                echo '<td>$' . number_format($l->list_price, 2) . '</td>';
                echo '<td>' . $status_badge . '</td>';
                echo '<td>' . esc_html($l->listing_date) . '</td>';
                echo '<td>' . esc_html($l->predict_close_date) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
    
    /**
     * Render results entry
     */
    public function render_results() {
        global $wpdb;
        $listings_table = $wpdb->prefix . 'rep_listings';
        $properties_table = $wpdb->prefix . 'rep_properties';
        
        // Handle result submission
        if (isset($_POST['rep_save_result'])) {
            check_admin_referer('rep_save_result_action');
            $listing_id = intval($_POST['listing_id']);
            $actual_price = floatval($_POST['actual_price']);
            
            if ($listing_id && $actual_price) {
                $wpdb->update(
                    $listings_table,
                    [
                        'actual_sale_price' => $actual_price,
                        'status' => 'closed',
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $listing_id],
                    ['%f','%s','%s'],
                    ['%d']
                );
                $this->calculate_points_for_listing($listing_id);
                echo '<div class="notice notice-success"><p>Result saved and points calculated!</p></div>';
            }
        }
        
        // Get active listings past prediction close date
        $listings = $wpdb->get_results("
            SELECT l.*, p.address, p.neighborhood 
            FROM $listings_table l
            JOIN $properties_table p ON p.id = l.property_id
            WHERE l.status = 'active' 
              AND l.predict_close_date <= NOW()
            ORDER BY l.close_date ASC
        ");
        
        echo '<div class="wrap"><h1>Enter Sale Results</h1>';
        
        if (!$listings) {
            echo '<p>No pending results to enter.</p></div>';
            return;
        }
        
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Property</th><th>List Price</th><th>Market Closed</th><th>Actual Sale Price</th><th>Action</th></tr></thead><tbody>';
        
        foreach ($listings as $l) {
            echo '<tr><form method="post">';
            wp_nonce_field('rep_save_result_action');
            echo '<td>' . esc_html($l->address) . '</td>';
            echo '<td>$' . number_format($l->list_price, 2) . '</td>';
            echo '<td>' . esc_html($l->predict_close_date) . '</td>';
            echo '<td><input type="number" name="actual_price" step="1000" required> $</td>';
            echo '<td>
                <input type="hidden" name="listing_id" value="' . intval($l->id) . '">
                <button class="button button-primary" name="rep_save_result" value="1">Save & Calculate</button>
            </td>';
            echo '</form></tr>';
        }
        
        echo '</tbody></table></div>';
    }
    
    /**
     * Calculate points for a listing
     */
    private function calculate_points_for_listing($listing_id) {
        global $wpdb;
        
        $predictions_table = $wpdb->prefix . 'rep_predictions';
        $scores_table = $wpdb->prefix . 'rep_scores';
        $listings_table = $wpdb->prefix . 'rep_listings';
        
        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, actual_sale_price FROM $listings_table WHERE id = %d",
            $listing_id
        ));
        
        if (!$listing || !$listing->actual_sale_price) return;
        
        $actual = floatval($listing->actual_sale_price);
        $predictions = $wpdb->get_results($wpdb->prepare(
            "SELECT id, predicted_price, user_id FROM $predictions_table WHERE listing_id = %d",
            $listing_id
        ));
        
        foreach ($predictions as $pred) {
            $predicted = floatval($pred->predicted_price);
            $diff_percent = abs($predicted - $actual) / $actual * 100;
            
            // Scoring logic
            if ($predicted == $actual) {
                $points = 50;
            } elseif ($diff_percent <= 1) {
                $points = 30;
            } elseif ($diff_percent <= 5) {
                $points = 20;
            } elseif ($diff_percent <= 10) {
                $points = 10;
            } elseif ($diff_percent <= 20) {
                $points = 5;
            } else {
                $points = 0;
            }
            
            // Bonus for being early (simplified)
            $bonus = ($pred->id % 10 < 3) ? 5 : 0;
            
            $wpdb->insert($scores_table, [
                'user_id' => $pred->user_id,
                'prediction_id' => $pred->id,
                'points_accuracy' => $points,
                'points_bonus' => $bonus,
                'points_total' => $points + $bonus,
                'percent_off' => $diff_percent,
                'calculated_at' => current_time('mysql')
            ]);
        }
    }
    
    /**
     * Handle prediction submission
     */
    public function handle_prediction_submit() {
        if (!isset($_POST['rep_submit_prediction'])) return;
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(wp_get_referer()));
            exit;
        }
        if (!wp_verify_nonce($_POST['_wpnonce'], 'rep_prediction_action')) {
            wp_die('Invalid nonce.');
        }
        
        global $wpdb;
        $listings_table = $wpdb->prefix . 'rep_listings';
        $predictions_table = $wpdb->prefix . 'rep_predictions';
        
        $user_id = get_current_user_id();
        $listing_id = intval($_POST['listing_id']);
        $predicted_price = floatval($_POST['predicted_price']);
        
        // Check if market is still open
        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, predict_close_date, status FROM $listings_table WHERE id = %d",
            $listing_id
        ));
        
        if (!$listing || $listing->status != 'active') {
            wp_safe_redirect(add_query_arg('rep_error', 'closed', wp_get_referer()));
            exit;
        }
        
        $now = current_time('timestamp');
        $close_ts = strtotime($listing->predict_close_date);
        
        if ($now >= $close_ts) {
            wp_safe_redirect(add_query_arg('rep_error', 'closed', wp_get_referer()));
            exit;
        }
        
        // Check if user already predicted
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $predictions_table WHERE user_id = %d AND listing_id = %d",
            $user_id, $listing_id
        ));
        
        if ($existing) {
            $wpdb->update(
                $predictions_table,
                ['predicted_price' => $predicted_price, 'updated_at' => current_time('mysql')],
                ['id' => $existing],
                ['%f','%s'],
                ['%d']
            );
        } else {
            $wpdb->insert($predictions_table, [
                'user_id' => $user_id,
                'listing_id' => $listing_id,
                'predicted_price' => $predicted_price,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);
        }
        
        wp_safe_redirect(add_query_arg('rep_saved', '1', wp_get_referer()));
        exit;
    }
    
    /**
     * Shortcode: Active markets
     */
    public function shortcode_markets() {
        if (!is_user_logged_in()) {
            $login_url = wp_login_url(get_permalink());
            return '<p>🔐 Please <a href="' . esc_url($login_url) . '">login</a> to view prediction markets.</p>';
        }
        
        global $wpdb;
        $listings_table = $wpdb->prefix . 'rep_listings';
        $properties_table = $wpdb->prefix . 'rep_properties';
        $predictions_table = $wpdb->prefix . 'rep_predictions';
        
        $user_id = get_current_user_id();
        $now = current_time('timestamp');
        
        $listings = $wpdb->get_results("
            SELECT l.*, p.address, p.neighborhood, p.property_type, p.bedrooms, p.bathrooms, p.square_feet, p.image_url
            FROM $listings_table l
            JOIN $properties_table p ON p.id = l.property_id
            WHERE l.status = 'active'
            ORDER BY l.predict_close_date ASC
        ");
        
        if (!$listings) {
            return '<div class="rep-card"><p>📭 No active prediction markets at this time.</p></div>';
        }
        
        // Get user's existing predictions
        $user_preds = $wpdb->get_results($wpdb->prepare(
            "SELECT listing_id, predicted_price FROM $predictions_table WHERE user_id = %d",
            $user_id
        ));
        $pred_map = [];
        foreach ($user_preds as $p) {
            $pred_map[$p->listing_id] = floatval($p->predicted_price);
        }
        
        ob_start();
        ?>
        <div class="rep-markets-grid">
            <?php foreach ($listings as $l): 
                $close_ts = strtotime($l->predict_close_date);
                $is_open = ($now < $close_ts);
                $user_pred = isset($pred_map[$l->id]) ? $pred_map[$l->id] : null;
            ?>
            <div class="rep-market-card">
                <?php if ($l->image_url): ?>
                    <img src="<?php echo esc_url($l->image_url); ?>" alt="<?php echo esc_attr($l->address); ?>" class="rep-market-image">
                <?php endif; ?>
                <div class="rep-market-body">
                    <h3><?php echo esc_html($l->address); ?></h3>
                    <div class="rep-market-details">
                        <span class="rep-badge"><?php echo esc_html($l->property_type); ?></span>
                        <span><?php echo intval($l->bedrooms); ?> bed / <?php echo floatval($l->bathrooms); ?> bath</span>
                        <span><?php echo number_format($l->square_feet); ?> sq ft</span>
                    </div>
                    <div class="rep-market-price">
                        <strong>List Price:</strong> $<?php echo number_format($l->list_price, 2); ?>
                    </div>
                    <div class="rep-market-deadline">
                        ⏳ Predictions close: <?php echo date_i18n('M j, Y g:i A', strtotime($l->predict_close_date)); ?>
                    </div>
                    
                    <?php if ($is_open): ?>
                        <form method="post" class="rep-prediction-form">
                            <?php wp_nonce_field('rep_prediction_action'); ?>
                            <input type="hidden" name="listing_id" value="<?php echo intval($l->id); ?>">
                            <div class="rep-prediction-input">
                                <label>💰 Your predicted sale price:</label>
                                <div class="rep-price-input-group">
                                    <span class="rep-currency">$</span>
                                    <input type="number" name="predicted_price" step="1000" 
                                           value="<?php echo $user_pred ? esc_attr($user_pred) : ''; ?>" 
                                           placeholder="Enter amount" required>
                                </div>
                            </div>
                            <button type="submit" name="rep_submit_prediction" class="rep-btn rep-btn-primary">
                                <?php echo $user_pred ? 'Update Prediction' : 'Submit Prediction'; ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="rep-market-closed">
                            🔒 Market closed
                            <?php if ($user_pred): ?>
                                <div class="rep-your-prediction">Your prediction: $<?php echo number_format($user_pred, 2); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <style>
            .rep-markets-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
                gap: 25px;
                margin: 30px 0;
            }
            .rep-market-card {
                background: #1A1A1A;
                border-radius: 16px;
                overflow: hidden;
                border: 1px solid #2A2A2A;
                transition: transform 0.2s;
            }
            .rep-market-card:hover {
                transform: translateY(-4px);
            }
            .rep-market-image {
                width: 100%;
                height: 200px;
                object-fit: cover;
            }
            .rep-market-body {
                padding: 20px;
            }
            .rep-market-body h3 {
                margin: 0 0 12px 0;
                color: #FF5A00;
            }
            .rep-market-details {
                display: flex;
                gap: 12px;
                flex-wrap: wrap;
                margin-bottom: 15px;
                font-size: 13px;
                color: #CCC;
            }
            .rep-badge {
                background: #2A2A2A;
                padding: 4px 10px;
                border-radius: 20px;
                font-size: 11px;
                text-transform: uppercase;
            }
            .rep-market-price {
                margin-bottom: 10px;
                color: #FFF;
            }
            .rep-market-deadline {
                font-size: 12px;
                color: #888;
                margin-bottom: 20px;
            }
            .rep-prediction-form {
                margin-top: 15px;
            }
            .rep-prediction-input {
                margin-bottom: 15px;
            }
            .rep-prediction-input label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #FFF;
            }
            .rep-price-input-group {
                display: flex;
                align-items: center;
                background: #0A0A0A;
                border: 1px solid #333;
                border-radius: 8px;
                padding: 0 12px;
            }
            .rep-currency {
                font-weight: 600;
                margin-right: 8px;
                color: #FF5A00;
            }
            .rep-price-input-group input {
                background: transparent;
                border: none;
                padding: 12px 0;
                width: 100%;
                color: #FFF;
                font-size: 16px;
                outline: none;
            }
            .rep-btn {
                display: inline-block;
                padding: 12px 24px;
                border-radius: 50px;
                font-weight: 600;
                text-decoration: none;
                cursor: pointer;
                border: none;
                transition: all 0.2s;
            }
            .rep-btn-primary {
                background: #FF5A00;
                color: #FFF;
                width: 100%;
            }
            .rep-btn-primary:hover {
                background: #6B2EFF;
                transform: translateY(-2px);
            }
            .rep-market-closed {
                text-align: center;
                padding: 20px;
                background: #0A0A0A;
                border-radius: 8px;
                color: #888;
            }
            .rep-your-prediction {
                margin-top: 10px;
                color: #FF5A00;
                font-weight: 600;
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Shortcode: Leaderboard
     */
    public function shortcode_leaderboard() {
        global $wpdb;
        
        $scores_table = $wpdb->prefix . 'rep_scores';
        $users_table = $wpdb->users;
        
        $scores = $wpdb->get_results("
            SELECT 
                u.ID,
                u.display_name,
                COALESCE(SUM(s.points_total), 0) AS total_points,
                COUNT(s.id) AS predictions_made
            FROM $users_table u
            LEFT JOIN $scores_table s ON s.user_id = u.ID
            GROUP BY u.ID
            ORDER BY total_points DESC
            LIMIT 50
        ");
        
        ob_start();
        ?>
        <div class="rep-leaderboard">
            <h2>🏆 Top Predictors Leaderboard</h2>
            <div class="rep-leaderboard-table-wrap">
                <table class="rep-leaderboard-table">
                    <thead>
                        <tr><th>Rank</th><th>Investor</th><th>Points</th><th>Predictions</th></tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach ($scores as $s): ?>
                        <tr class="<?php echo $rank <= 3 ? 'rep-top-' . $rank : ''; ?>">
                            <td class="rep-rank">
                                <?php if ($rank == 1): ?>🥇<?php elseif ($rank == 2): ?>🥈<?php elseif ($rank == 3): ?>🥉<?php else: echo $rank; endif; ?>
                            </td>
                            <td><?php echo esc_html($s->display_name); ?></td>
                            <td class="rep-points"><?php echo number_format($s->total_points); ?></td>
                            <td><?php echo intval($s->predictions_made); ?></td>
                        </tr>
                        <?php $rank++; endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <style>
            .rep-leaderboard-table {
                width: 100%;
                border-collapse: collapse;
            }
            .rep-leaderboard-table th,
            .rep-leaderboard-table td {
                padding: 12px;
                text-align: left;
                border-bottom: 1px solid #2A2A2A;
            }
            .rep-leaderboard-table th {
                color: #FF5A00;
            }
            .rep-rank {
                font-size: 20px;
                width: 60px;
            }
            .rep-points {
                font-weight: 700;
                color: #00F5D4;
            }
            .rep-top-1 td:first-child { font-size: 28px; }
            .rep-top-2 td:first-child { font-size: 24px; }
            .rep-top-3 td:first-child { font-size: 22px; }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Shortcode: Groups (Neighborhood Leagues)
     */
    public function shortcode_groups() {
        if (!is_user_logged_in()) {
            $login_url = wp_login_url(get_permalink());
            return '<p>🔐 Please <a href="' . esc_url($login_url) . '">login</a> to join prediction leagues.</p>';
        }
        
        global $wpdb;
        $groups_table = $wpdb->prefix . 'rep_groups';
        $members_table = $wpdb->prefix . 'rep_group_members';
        
        $user_id = get_current_user_id();
        $invite_code = isset($_GET['join']) ? strtoupper(sanitize_text_field($_GET['join'])) : '';
        
        ob_start();
        ?>
        <div class="rep-groups-container">
            <h2>🏘️ Neighborhood Prediction Leagues</h2>
            <p>Compete with investors in your area! Create a league or join with a code.</p>
            
            <?php if ($invite_code): ?>
                <div class="rep-card rep-invite-card">
                    <h3>🎟️ Join League</h3>
                    <p>Code: <strong><?php echo esc_html($invite_code); ?></strong></p>
                    <form method="post">
                        <?php wp_nonce_field('rep_join_group_action'); ?>
                        <input type="hidden" name="join_code" value="<?php echo esc_attr($invite_code); ?>">
                        <button type="submit" name="rep_join_group" class="rep-btn rep-btn-primary">Join This League →</button>
                    </form>
                </div>
            <?php endif; ?>
            
            <div class="rep-groups-grid">
                <!-- Create League -->
                <div class="rep-group-card">
                    <div class="rep-group-icon">🏗️</div>
                    <h3>Create League</h3>
                    <p>Start your own neighborhood prediction league</p>
                    <form method="post">
                        <?php wp_nonce_field('rep_create_group_action'); ?>
                        <input type="text" name="group_name" placeholder="League name" required style="width:100%; margin-bottom:10px; padding:8px; background:#0A0A0A; border:1px solid #333; color:#FFF; border-radius:8px;">
                        <input type="text" name="neighborhood" placeholder="Neighborhood (optional)" style="width:100%; margin-bottom:10px; padding:8px; background:#0A0A0A; border:1px solid #333; color:#FFF; border-radius:8px;">
                        <button type="submit" name="rep_create_group" class="rep-btn rep-btn-primary">Create League →</button>
                    </form>
                </div>
                
                <!-- Join League -->
                <div class="rep-group-card">
                    <div class="rep-group-icon">🔑</div>
                    <h3>Join League</h3>
                    <p>Enter a code to join an existing league</p>
                    <form method="post">
                        <?php wp_nonce_field('rep_join_group_action'); ?>
                        <input type="text" name="join_code" placeholder="Enter code (e.g., A7K9Q2)" required style="width:100%; margin-bottom:10px; padding:8px; background:#0A0A0A; border:1px solid #333; color:#FFF; border-radius:8px; text-transform:uppercase;">
                        <button type="submit" name="rep_join_group" class="rep-btn rep-btn-primary">Join League →</button>
                    </form>
                </div>
            </div>
        </div>
        
        <style>
            .rep-groups-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 25px;
                margin-top: 30px;
            }
            .rep-group-card {
                background: #1A1A1A;
                border-radius: 20px;
                padding: 30px;
                text-align: center;
                border: 1px solid #2A2A2A;
            }
            .rep-group-icon {
                font-size: 48px;
                margin-bottom: 15px;
            }
            .rep-group-card h3 {
                color: #FF5A00;
                margin-bottom: 10px;
            }
            .rep-invite-card {
                background: linear-gradient(135deg, #1A1A1A, #2A1A3A);
                border-radius: 16px;
                padding: 20px;
                margin-bottom: 20px;
                text-align: center;
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Handle group creation
     */
    public function handle_group_create() {
        if (!isset($_POST['rep_create_group'])) return;
        if (!is_user_logged_in()) return;
        if (!wp_verify_nonce($_POST['_wpnonce'], 'rep_create_group_action')) return;
        
        global $wpdb;
        $groups_table = $wpdb->prefix . 'rep_groups';
        $members_table = $wpdb->prefix . 'rep_group_members';
        
        $name = trim(sanitize_text_field($_POST['group_name'] ?? ''));
        $neighborhood = sanitize_text_field($_POST['neighborhood'] ?? '');
        
        if (!$name) {
            wp_safe_redirect(add_query_arg('rep_error', 'Name required', wp_get_referer()));
            exit;
        }
        
        $user_id = get_current_user_id();
        
        // Generate unique join code
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $join_code = '';
        for ($i = 0; $i < 10; $i++) {
            $code = '';
            for ($j = 0; $j < 6; $j++) {
                $code .= $chars[random_int(0, strlen($chars)-1)];
            }
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $groups_table WHERE join_code = %s", $code));
            if (!$exists) { $join_code = $code; break; }
        }
        
        $wpdb->insert($groups_table, [
            'name' => $name,
            'neighborhood' => $neighborhood ?: null,
            'join_code' => $join_code,
            'owner_user_id' => $user_id,
            'created_at' => current_time('mysql')
        ]);
        
        $group_id = $wpdb->insert_id;
        
        $wpdb->insert($members_table, [
            'group_id' => $group_id,
            'user_id' => $user_id,
            'role' => 'owner',
            'joined_at' => current_time('mysql')
        ]);
        
        wp_safe_redirect(add_query_arg('rep_created', '1', wp_get_referer()));
        exit;
    }
    
    /**
     * Handle group join
     */
    public function handle_group_join() {
        if (!isset($_POST['rep_join_group'])) return;
        if (!is_user_logged_in()) return;
        if (!wp_verify_nonce($_POST['_wpnonce'], 'rep_join_group_action')) return;
        
        global $wpdb;
        $groups_table = $wpdb->prefix . 'rep_groups';
        $members_table = $wpdb->prefix . 'rep_group_members';
        
        $code = strtoupper(trim(sanitize_text_field($_POST['join_code'] ?? '')));
        
        if (!$code) {
            wp_safe_redirect(add_query_arg('rep_error', 'Code required', wp_get_referer()));
            exit;
        }
        
        $group = $wpdb->get_row($wpdb->prepare("SELECT id FROM $groups_table WHERE join_code = %s", $code));
        
        if (!$group) {
            wp_safe_redirect(add_query_arg('rep_error', 'Invalid code', wp_get_referer()));
            exit;
        }
        
        $user_id = get_current_user_id();
        
        $already = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $members_table WHERE group_id = %d AND user_id = %d",
            $group->id, $user_id
        ));
        
        if ($already) {
            wp_safe_redirect(add_query_arg('rep_error', 'Already a member', wp_get_referer()));
            exit;
        }
        
        $wpdb->insert($members_table, [
            'group_id' => $group->id,
            'user_id' => $user_id,
            'role' => 'member',
            'joined_at' => current_time('mysql')
        ]);
        
        wp_safe_redirect(add_query_arg('rep_joined', '1', wp_get_referer()));
        exit;
    }
    
    /**
     * Shortcode: My predictions
     */
    public function shortcode_my_predictions() {
        if (!is_user_logged_in()) {
            return '<p>🔐 Please login to view your predictions.</p>';
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        
        $predictions = $wpdb->get_results($wpdb->prepare("
            SELECT p.*, l.list_price, l.actual_sale_price, l.status, 
                   pr.address, pr.neighborhood, pr.property_type,
                   s.points_total, s.percent_off
            FROM {$wpdb->prefix}rep_predictions p
            JOIN {$wpdb->prefix}rep_listings l ON l.id = p.listing_id
            JOIN {$wpdb->prefix}rep_properties pr ON pr.id = l.property_id
            LEFT JOIN {$wpdb->prefix}rep_scores s ON s.prediction_id = p.id
            WHERE p.user_id = %d
            ORDER BY p.created_at DESC
        ", $user_id));
        
        if (!$predictions) {
            return '<div class="rep-card"><p>📊 You haven\'t made any predictions yet. Start predicting!</p></div>';
        }
        
        ob_start();
        ?>
        <div class="rep-predictions-list">
            <h2>📋 My Predictions</h2>
            <div class="rep-predictions-table-wrap">
                <table class="rep-predictions-table">
                    <thead>
                        <tr><th>Property</th><th>Your Prediction</th><th>List Price</th><th>Actual Sale</th><th>Status</th><th>Points</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($predictions as $pred): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($pred->address); ?></strong><br>
                                <small><?php echo esc_html($pred->neighborhood); ?></small>
                            </td>
                            <td>$<?php echo number_format($pred->predicted_price, 2); ?></td>
                            <td>$<?php echo number_format($pred->list_price, 2); ?></td>
                            <td>
                                <?php if ($pred->actual_sale_price): ?>
                                    $<?php echo number_format($pred->actual_sale_price, 2); ?>
                                    <?php if ($pred->percent_off): ?>
                                        <br><small class="<?php echo $pred->percent_off <= 5 ? 'rep-success' : 'rep-error'; ?>">
                                            <?php echo $pred->percent_off <= 5 ? '✓' : '⚠'; ?> 
                                            <?php echo number_format($pred->percent_off, 1); ?>% off
                                        </small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="rep-pending">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($pred->status == 'closed'): ?>
                                    <span class="rep-badge rep-badge-closed">Closed</span>
                                <?php else: ?>
                                    <span class="rep-badge rep-badge-active">Active</span>
                                <?php endif; ?>
                            </td>
                            <td class="rep-points-cell">
                                <?php echo $pred->points_total ? number_format($pred->points_total) : '-'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <style>
            .rep-predictions-table {
                width: 100%;
                border-collapse: collapse;
            }
            .rep-predictions-table th,
            .rep-predictions-table td {
                padding: 12px;
                text-align: left;
                border-bottom: 1px solid #2A2A2A;
            }
            .rep-predictions-table th {
                color: #FF5A00;
            }
            .rep-badge-active {
                background: #00F5D4;
                color: #000;
                padding: 4px 10px;
                border-radius: 20px;
                font-size: 11px;
            }
            .rep-badge-closed {
                background: #6B2EFF;
                color: #FFF;
                padding: 4px 10px;
                border-radius: 20px;
                font-size: 11px;
            }
            .rep-success { color: #00F5D4; }
            .rep-error { color: #FF006E; }
            .rep-pending { color: #888; }
            .rep-points-cell { font-weight: 700; color: #FF5A00; }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Shortcode: Dashboard
     */
    public function shortcode_dashboard() {
        if (!is_user_logged_in()) {
            $login_url = wp_login_url(get_permalink());
            return '<p>🔐 Please <a href="' . esc_url($login_url) . '">login</a> to view your dashboard.</p>';
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        $user = wp_get_current_user();
        
        // Get user stats
        $total_points = $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(points_total), 0) FROM {$wpdb->prefix}rep_scores WHERE user_id = %d
        ", $user_id));
        
        $predictions_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}rep_predictions WHERE user_id = %d
        ", $user_id));
        
        $accuracy = $wpdb->get_var($wpdb->prepare("
            SELECT AVG(100 - percent_off) FROM {$wpdb->prefix}rep_scores WHERE user_id = %d AND percent_off IS NOT NULL
        ", $user_id));
        
        $rank = $wpdb->get_var("
            SELECT COUNT(*) + 1 FROM (
                SELECT SUM(points_total) as pts FROM {$wpdb->prefix}rep_scores GROUP BY user_id
            ) as scores WHERE pts > (SELECT COALESCE(SUM(points_total), 0) FROM {$wpdb->prefix}rep_scores WHERE user_id = $user_id)
        ");
        
        ob_start();
        ?>
        <div class="rep-dashboard">
            <div class="rep-dashboard-header">
                <h2>🏠 Welcome back, <?php echo esc_html($user->display_name); ?>!</h2>
                <p>Track your prediction performance and compete with others.</p>
            </div>
            
            <div class="rep-stats-grid">
                <div class="rep-stat-card">
                    <div class="rep-stat-icon">🏆</div>
                    <div class="rep-stat-value"><?php echo number_format($total_points); ?></div>
                    <div class="rep-stat-label">Total Points</div>
                </div>
                <div class="rep-stat-card">
                    <div class="rep-stat-icon">📊</div>
                    <div class="rep-stat-value"><?php echo intval($predictions_count); ?></div>
                    <div class="rep-stat-label">Predictions Made</div>
                </div>
                <div class="rep-stat-card">
                    <div class="rep-stat-icon">🎯</div>
                    <div class="rep-stat-value"><?php echo $accuracy ? number_format($accuracy, 1) : '—'; ?>%</div>
                    <div class="rep-stat-label">Avg. Accuracy</div>
                </div>
                <div class="rep-stat-card">
                    <div class="rep-stat-icon">🥇</div>
                    <div class="rep-stat-value">#<?php echo intval($rank); ?></div>
                    <div class="rep-stat-label">Global Rank</div>
                </div>
            </div>
            
            <div class="rep-dashboard-links">
                <a href="<?php echo esc_url(get_permalink(get_page_by_path('markets'))); ?>" class="rep-btn rep-btn-primary">💰 View Active Markets</a>
                <a href="<?php echo esc_url(get_permalink(get_page_by_path('leaderboard'))); ?>" class="rep-btn rep-btn-secondary">🏆 View Leaderboard</a>
                <a href="<?php echo esc_url(get_permalink(get_page_by_path('my-predictions'))); ?>" class="rep-btn rep-btn-secondary">📋 My Predictions</a>
            </div>
        </div>
        
        <style>
            .rep-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin: 30px 0;
            }
            .rep-stat-card {
                background: #1A1A1A;
                border-radius: 16px;
                padding: 24px;
                text-align: center;
                border: 1px solid #2A2A2A;
            }
            .rep-stat-icon {
                font-size: 32px;
                margin-bottom: 12px;
            }
            .rep-stat-value {
                font-size: 36px;
                font-weight: 800;
                color: #FF5A00;
            }
            .rep-stat-label {
                color: #888;
                margin-top: 8px;
            }
            .rep-dashboard-links {
                display: flex;
                gap: 15px;
                flex-wrap: wrap;
                margin-top: 30px;
            }
            .rep-btn-secondary {
                background: transparent;
                border: 1px solid #FF5A00;
                color: #FF5A00;
            }
            .rep-btn-secondary:hover {
                background: #FF5A00;
                color: #000;
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Shortcode: Single property
     */
    public function shortcode_property($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $property_id = intval($atts['id']);
        
        global $wpdb;
        $property = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}rep_properties WHERE id = %d
        ", $property_id));
        
        if (!$property) {
            return '<p>Property not found.</p>';
        }
        
        $listings = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}rep_listings WHERE property_id = %d ORDER BY listing_date DESC
        ", $property_id));
        
        ob_start();
        ?>
        <div class="rep-property-detail">
            <?php if ($property->image_url): ?>
                <img src="<?php echo esc_url($property->image_url); ?>" alt="<?php echo esc_attr($property->address); ?>" class="rep-property-image">
            <?php endif; ?>
            <h2><?php echo esc_html($property->address); ?></h2>
            <div class="rep-property-info">
                <p><strong>📍 Neighborhood:</strong> <?php echo esc_html($property->neighborhood); ?></p>
                <p><strong>🏠 Type:</strong> <?php echo esc_html($property->property_type); ?></p>
                <p><strong>🛏️ Beds/Baths:</strong> <?php echo intval($property->bedrooms); ?> / <?php echo floatval($property->bathrooms); ?></p>
                <p><strong>📐 Sq Ft:</strong> <?php echo number_format($property->square_feet); ?></p>
            </div>
            
            <h3>Past Markets</h3>
            <?php if ($listings): ?>
                <table class="rep-listings-table">
                    <thead><tr><th>List Price</th><th>Actual Sale</th><th>Status</th><th>Listing Date</th></tr></thead>
                    <tbody>
                        <?php foreach ($listings as $l): ?>
                        <tr>
                            <td>$<?php echo number_format($l->list_price, 2); ?></td>
                            <td><?php echo $l->actual_sale_price ? '$' . number_format($l->actual_sale_price, 2) : '—'; ?></td>
                            <td><?php echo esc_html($l->status); ?></td>
                            <td><?php echo esc_html($l->listing_date); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No past markets for this property.</p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render leaderboard admin
     */
    public function render_leaderboard_admin() {
        global $wpdb;
        
        $scores = $wpdb->get_results("
            SELECT u.display_name, COALESCE(SUM(s.points_total), 0) as total_points
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->prefix}rep_scores s ON s.user_id = u.ID
            GROUP BY u.ID
            ORDER BY total_points DESC
            LIMIT 50
        ");
        
        echo '<div class="wrap"><h1>🏆 Leaderboard</h1>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Rank</th><th>User</th><th>Points</th></tr></thead><tbody>';
        $rank = 1;
        foreach ($scores as $s) {
            echo '<tr><td>' . $rank++ . '</td><td>' . esc_html($s->display_name) . '</td><td>' . number_format($s->total_points) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

new RealEstatePredictionMarket();
