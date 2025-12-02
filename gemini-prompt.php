<?php
/**
 * Gemini Prompt Interface
 * Processes natural language commands to update episode access
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_submenu_page(
        'gemini-patreon-control',
        'AI Commands',
        'AI Commands',
        'manage_options',
        'gemini-prompt',
        'gemini_prompt_page_html'
    );
    
    add_submenu_page(
        'gemini-patreon-control',
        'Sync Episode Numbers',
        'Sync Episodes',
        'manage_options',
        'gemini-sync-episodes',
        'gemini_sync_episodes_page_html'
    );
    
    add_submenu_page(
        'gemini-patreon-control',
        'Auto Unlock Schedule',
        'Auto Unlock',
        'manage_options',
        'gemini-auto-unlock',
        'gemini_auto_unlock_page_html'
    );
});

function gemini_prompt_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>🤖 AI Episode Control</h1>
        <p>Use natural language to control episode access. Examples:</p>
        <ul>
            <li>"Make episode 5 free for everyone"</li>
            <li>"Change episode 12 to advance access only"</li>
            <li>"Unlock episode 8"</li>
        </ul>
        
        <form method="post">
            <?php wp_nonce_field('gemini_command_nonce'); ?>
            <textarea name="gemini_prompt" rows="4" cols="80" placeholder="Enter your command..." required></textarea><br><br>
            <input type="submit" name="gemini_send" class="button button-primary" value="🚀 Execute Command">
        </form>
        
        <?php
        if (isset($_POST['gemini_send']) && check_admin_referer('gemini_command_nonce')) {
            process_gemini_command();
        }
        ?>
    </div>
    <?php
}

function process_gemini_command() {
    $prompt = sanitize_textarea_field($_POST['gemini_prompt']);
    
    $api_key = get_option('gpc_gemini_key');
    if (!$api_key) {
        echo '<div class="notice notice-error"><p>❌ Please set your Gemini API key in Settings first.</p></div>';
        return;
    }
    
    echo '<div style="background:#f0f0f1;padding:15px;margin:20px 0;border-left:4px solid #2271b1;">';
    echo '<h2>🔄 Processing Command...</h2>';
    echo '<p><strong>Your request:</strong> ' . esc_html($prompt) . '</p>';
    
    $structured_data = ask_gemini_for_structure($prompt, $api_key);
    
    if (!$structured_data) {
        echo '<p style="color:red;">❌ Failed to get response from Gemini.</p></div>';
        return;
    }
    
    $result = execute_episode_action($structured_data);
    
    echo '</div>';
    
    if ($result['success']) {
        echo '<div class="notice notice-success"><p>✅ ' . esc_html($result['message']) . '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>❌ ' . esc_html($result['message']) . '</p></div>';
    }
}

function ask_gemini_for_structure($user_prompt, $api_key) {
    $system_instruction = "You are an assistant that extracts structured data from user commands about podcast episodes.

The user will give commands like:
- 'Make episode 5 free for everyone'
- 'Change episode 12 to advance access'
- 'Unlock episode 8'

You must respond with ONLY valid JSON in this exact format:
{
  \"episode_number\": 5,
  \"access_type\": \"free\",
  \"confidence\": \"high\"
}

Rules:
- access_type must be either \"free\" or \"advance\"
- If user says 'free', 'unlock', 'public', 'everyone' → use \"free\"
- If user says 'advance', 'paid', 'lock', 'patron only' → use \"advance\"
- episode_number must be an integer
- confidence can be \"high\", \"medium\", or \"low\"
- Respond ONLY with the JSON object, no other text";

    $full_prompt = $system_instruction . "\n\nUser command: " . $user_prompt;
    
    $model = 'gemini-2.5-flash';
    $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$api_key";
    
    $body = json_encode([
        "contents" => [
            [
                "parts" => [
                    ["text" => $full_prompt]
                ]
            ]
        ],
        "generationConfig" => [
            "temperature" => 0.1,
            "topP" => 0.95,
            "topK" => 40
        ]
    ]);
    
    $response = wp_remote_post($endpoint, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => $body,
        'timeout' => 30,
    ]);
    
    if (is_wp_error($response)) {
        echo '<p style="color:red;">API Error: ' . esc_html($response->get_error_message()) . '</p>';
        return false;
    }
    
    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);
    
    if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        echo '<p style="color:red;">Unexpected API response format.</p>';
        echo '<pre>' . esc_html($response_body) . '</pre>';
        return false;
    }
    
    $gemini_text = $data['candidates'][0]['content']['parts'][0]['text'];
    echo '<p><strong>🤖 Gemini interpretation:</strong></p>';
    echo '<pre style="background:#fff;padding:10px;border:1px solid #ccc;">' . esc_html($gemini_text) . '</pre>';
    
    preg_match('/\{[^}]+\}/', $gemini_text, $matches);
    if (!empty($matches[0])) {
        $structured = json_decode($matches[0], true);
        if ($structured) {
            return $structured;
        }
    }
    
    $structured = json_decode($gemini_text, true);
    return $structured ?: false;
}

function execute_episode_action($data) {
    if (!isset($data['episode_number']) || !isset($data['access_type'])) {
        return [
            'success' => false,
            'message' => 'Invalid data structure from Gemini. Missing episode_number or access_type.'
        ];
    }
    
    $episode_number = intval($data['episode_number']);
    $access_type = sanitize_text_field($data['access_type']);
    
    if (!in_array($access_type, ['free', 'advance'])) {
        return [
            'success' => false,
            'message' => 'Invalid access type. Must be "free" or "advance".'
        ];
    }
    
    $episode_meta_key = get_option('gpc_episode_number_meta', 'episode_number');
    $patreon_post_meta_key = get_option('gpc_patreon_post_meta', 'patreon_post_id');
    $acf_field_type = get_option('gpc_acf_field_type', 'section');
    
    echo '<p><strong>🔍 Searching for:</strong> Episode number ' . $episode_number . ' using meta key "' . esc_html($episode_meta_key) . '"</p>';
    
    $args = [
        'post_type' => 'episodes',
        'meta_query' => [
            [
                'key' => $episode_meta_key,
                'value' => $episode_number,
                'compare' => '='
            ]
        ],
        'posts_per_page' => 1
    ];
    
    $query = new WP_Query($args);
    
    echo '<p><strong>📊 Search results:</strong> Found ' . $query->found_posts . ' episode(s)</p>';
    
    $title_search = new WP_Query([
        'post_type' => 'episodes',
        's' => $episode_number,
        'posts_per_page' => 5
    ]);
    
    if ($title_search->have_posts()) {
        echo '<p><strong>🔍 Episodes with "' . $episode_number . '" in title/content:</strong></p><ul>';
        while ($title_search->have_posts()) {
            $title_search->the_post();
            $ep_num = get_post_meta(get_the_ID(), $episode_meta_key, true);
            echo '<li>' . get_the_title() . ' (ID: ' . get_the_ID() . ', episode_number meta: ' . esc_html($ep_num) . ')</li>';
        }
        echo '</ul>';
        wp_reset_postdata();
    }
    
    if (!$query->have_posts()) {
        return [
            'success' => false,
            'message' => "Episode $episode_number not found in WordPress. Make sure the 'Episode Number' field is set to $episode_number in the episode's sidebar."
        ];
    }
    
    $post = $query->posts[0];
    $post_id = $post->ID;
    
    echo '<p><strong>📝 Found WordPress post:</strong> ' . esc_html($post->post_title) . ' (ID: ' . $post_id . ')</p>';
    
    $current_terms = wp_get_post_terms($post_id, 'chapter-categories', ['fields' => 'all']);
    $current_access = 'unknown';
    
    if (!empty($current_terms) && !is_wp_error($current_terms)) {
        echo '<p><strong>🏷️ Current terms:</strong> ';
        foreach ($current_terms as $term) {
            echo esc_html($term->name) . ' (ID: ' . $term->term_id . '), ';
            if (strtolower($term->name) === 'advance') $current_access = 'advance';
            if (strtolower($term->name) === 'free') $current_access = 'free';
        }
        echo '</p>';
    } else {
        echo '<p><strong>🏷️ Current terms:</strong> None</p>';
    }
    
    echo '<p><strong>📊 Current access:</strong> ' . esc_html($current_access) . '</p>';
    
    if ($access_type === 'advance') {
        $term = get_term_by('name', 'Advance', 'chapter-categories');
        if (!$term) {
            $term = get_term_by('slug', 'advance', 'chapter-categories');
        }
        
        if (!$term) {
            echo '<p style="color:orange;">⚠️ "Advance" term not found. Creating it...</p>';
            $new_term = wp_insert_term('Advance', 'chapter-categories');
            if (!is_wp_error($new_term)) {
                $term_id = $new_term['term_id'];
            } else {
                echo '<p style="color:red;">❌ Error creating term: ' . $new_term->get_error_message() . '</p>';
                return ['success' => false, 'message' => 'Failed to create Advance term'];
            }
        } else {
            $term_id = $term->term_id;
        }
        
        wp_set_object_terms($post_id, [$term_id], 'chapter-categories', false);
        echo '<p>✅ Set to "Advance" category (patron-only access)</p>';
        
    } else {
        $term = get_term_by('name', 'Free', 'chapter-categories');
        if (!$term) {
            $term = get_term_by('slug', 'free', 'chapter-categories');
        }
        
        if (!$term) {
            echo '<p style="color:orange;">⚠️ "Free" term not found. Creating it...</p>';
            $new_term = wp_insert_term('Free', 'chapter-categories');
            if (!is_wp_error($new_term)) {
                $term_id = $new_term['term_id'];
            } else {
                echo '<p style="color:red;">❌ Error creating term: ' . $new_term->get_error_message() . '</p>';
                return ['success' => false, 'message' => 'Failed to create Free term'];
            }
        } else {
            $term_id = $term->term_id;
        }
        
        wp_set_object_terms($post_id, [$term_id], 'chapter-categories', false);
        echo '<p>✅ Removed "Advance" and set to "Free" category (public access)</p>';
    }
    
    $verify_terms = wp_get_post_terms($post_id, 'chapter-categories', ['fields' => 'names']);
    if (!empty($verify_terms) && !is_wp_error($verify_terms)) {
        echo '<p>✅ Verified: Episode now has category: <strong>' . implode(', ', $verify_terms) . '</strong></p>';
    }
    
    update_patreon_plugin_access($post_id, $access_type);
    
    if (function_exists('update_field')) {
        update_field($acf_field_type, $access_type, $post_id);
        echo '<p>✅ Updated ACF field "' . esc_html($acf_field_type) . '" to "' . esc_html($access_type) . '" (if exists)</p>';
    }
    
    $patreon_post_id = get_post_meta($post_id, $patreon_post_meta_key, true);
    
    if ($patreon_post_id) {
        echo '<p><strong>🔗 Found Patreon post ID:</strong> ' . esc_html($patreon_post_id) . '</p>';
        $patreon_result = update_patreon_post_access($patreon_post_id, $access_type);
        
        if ($patreon_result['success']) {
            echo '<p>✅ ' . esc_html($patreon_result['message']) . '</p>';
        } else {
            echo '<p style="color:orange;">⚠️ ' . esc_html($patreon_result['message']) . '</p>';
        }
    } else {
        echo '<p style="color:orange;">⚠️ No Patreon post ID found for this episode.</p>';
    }
    
    return [
        'success' => true,
        'message' => "Episode $episode_number successfully updated to '$access_type' access!"
    ];
}

function update_patreon_plugin_access($post_id, $access_type) {
    echo '<h3>🔐 Updating Patreon WordPress Plugin Settings</h3>';
    
    if ($access_type === 'free') {
        delete_post_meta($post_id, '_ppwp_patreon_level');
        delete_post_meta($post_id, 'patreon-level');
        update_post_meta($post_id, 'patreon-level', '0');
        echo '<p>✅ Removed Patreon tier restriction (available to everyone)</p>';
    } else {
        $silver_tier_id = get_option('gpc_patreon_silver_tier_id', '');
        
        if (empty($silver_tier_id)) {
            echo '<p style="color:orange;">⚠️ Silver tier ID not configured. Please add it in Settings.</p>';
            echo '<p>To find your tier ID: Go to Patreon.com → Your Page → Settings → Tiers, and note the tier ID from the URL.</p>';
        } else {
            update_post_meta($post_id, '_ppwp_patreon_level', $silver_tier_id);
            update_post_meta($post_id, 'patreon-level', $silver_tier_id);
            echo '<p>✅ Set Patreon tier requirement to Silver tier (ID: ' . esc_html($silver_tier_id) . ')</p>';
        }
    }
    
    $current_level = get_post_meta($post_id, 'patreon-level', true);
    if ($current_level === '0' || empty($current_level)) {
        echo '<p>✅ Verified: Patreon level = <strong>Everyone (Free)</strong></p>';
    } else {
        echo '<p>✅ Verified: Patreon level = <strong>Tier ID: ' . esc_html($current_level) . '</strong></p>';
    }
}

function update_patreon_post_access($patreon_post_id, $access_type) {
    $access_token = get_option('gpc_patreon_access_token');
    
    if (!$access_token) {
        return [
            'success' => false,
            'message' => 'Patreon access token not configured.'
        ];
    }
    
    $endpoint = "https://www.patreon.com/api/oauth2/v2/posts/$patreon_post_id";
    
    $tier_data = [];
    if ($access_type === 'free') {
        $tier_data = [
            'data' => [
                'attributes' => [
                    'is_public' => true
                ],
                'type' => 'post',
                'id' => $patreon_post_id
            ]
        ];
    } else {
        $tier_data = [
            'data' => [
                'attributes' => [
                    'is_public' => false
                ],
                'type' => 'post',
                'id' => $patreon_post_id
            ]
        ];
    }
    
    $response = wp_remote_request($endpoint, [
        'method' => 'PATCH',
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($tier_data),
        'timeout' => 30
    ]);
    
    if (is_wp_error($response)) {
        return [
            'success' => false,
            'message' => 'Patreon API error: ' . $response->get_error_message()
        ];
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    
    if ($status_code === 200) {
        return [
            'success' => true,
            'message' => "Patreon post updated to '$access_type' access."
        ];
    } else {
        return [
            'success' => false,
            'message' => "Patreon API returned status $status_code. Response: " . substr($response_body, 0, 200)
        ];
    }
}

/**
 * Sync Episode Numbers Page
 */
function gemini_sync_episodes_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>🔄 Sync Episode Numbers</h1>
        <p>This tool will automatically extract episode numbers from your episode titles and fill in the <code>episode_number</code> field.</p>
        
        <div style="background:#fff3cd;border-left:4px solid #ffc107;padding:15px;margin:20px 0;">
            <strong>⚠️ Important:</strong> This will scan all episodes and extract numbers from titles like:<br>
            <code>"Surviving The Game As A Barbarian Episode 669"</code> → Will set episode_number to <strong>669</strong>
        </div>
        
        <form method="post">
            <?php wp_nonce_field('sync_episodes_nonce'); ?>
            <p>
                <label>
                    <input type="checkbox" name="confirm_sync" value="1" required>
                    I understand this will update episode numbers for all episodes
                </label>
            </p>
            <input type="submit" name="sync_episodes" class="button button-primary" value="🚀 Sync All Episodes">
            <input type="submit" name="preview_sync" class="button" value="👁️ Preview (Don't Save)">
        </form>
        
        <?php
        if (isset($_POST['preview_sync']) && check_admin_referer('sync_episodes_nonce')) {
            preview_episode_sync();
        }
        
        if (isset($_POST['sync_episodes']) && check_admin_referer('sync_episodes_nonce')) {
            perform_episode_sync(true);
        }
        ?>
    </div>
    <?php
}

function preview_episode_sync() {
    echo '<h2>👁️ Preview - No Changes Made</h2>';
    perform_episode_sync(false);
}

function perform_episode_sync($save = false) {
    $args = [
        'post_type' => 'episodes',
        'posts_per_page' => -1,
        'post_status' => 'any',
        'orderby' => 'date',
        'order' => 'DESC'
    ];
    
    $query = new WP_Query($args);
    
    if (!$query->have_posts()) {
        echo '<p style="color:red;">❌ No episodes found.</p>';
        return;
    }
    
    echo '<div style="background:#f0f0f1;padding:15px;margin:20px 0;">';
    echo '<h3>📊 Found ' . $query->found_posts . ' episodes</h3>';
    
    $updated = 0;
    $skipped = 0;
    $failed = 0;
    
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th style="width:50px;">ID</th>';
    echo '<th>Title</th>';
    echo '<th style="width:100px;">Current #</th>';
    echo '<th style="width:100px;">Detected #</th>';
    echo '<th style="width:120px;">Status</th>';
    echo '</tr></thead><tbody>';
    
    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        $title = get_the_title();
        $current_episode_num = get_post_meta($post_id, 'episode_number', true);
        
        $detected_number = extract_episode_number($title);
        
        $status = '';
        $status_class = '';
        
        if ($detected_number) {
            if ($current_episode_num == $detected_number) {
                $status = '✓ Already set';
                $status_class = 'color:#666;';
                $skipped++;
            } else {
                if ($save) {
                    update_post_meta($post_id, 'episode_number', $detected_number);
                    $status = $save ? '✅ Updated' : '→ Will update';
                    $status_class = 'color:green;font-weight:bold;';
                    $updated++;
                } else {
                    $status = '→ Will update';
                    $status_class = 'color:blue;';
                    $updated++;
                }
            }
        } else {
            $status = '⚠️ No number found';
            $status_class = 'color:orange;';
            $failed++;
        }
        
        echo '<tr>';
        echo '<td>' . $post_id . '</td>';
        echo '<td>' . esc_html($title) . '</td>';
        echo '<td>' . ($current_episode_num ? $current_episode_num : '<em>empty</em>') . '</td>';
        echo '<td><strong>' . ($detected_number ? $detected_number : '-') . '</strong></td>';
        echo '<td style="' . $status_class . '">' . $status . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
    
    wp_reset_postdata();
    
    echo '<div style="background:#d1ecf1;border-left:4px solid #0c5460;padding:15px;margin:20px 0;">';
    echo '<h3>📈 Summary</h3>';
    echo '<ul>';
    echo '<li><strong>' . $updated . '</strong> episodes ' . ($save ? 'updated' : 'will be updated') . '</li>';
    echo '<li><strong>' . $skipped . '</strong> episodes already had correct numbers</li>';
    echo '<li><strong>' . $failed . '</strong> episodes couldn\'t detect number from title</li>';
    echo '</ul>';
    
    if ($save) {
        echo '<p style="color:green;font-weight:bold;">✅ All episode numbers have been synced!</p>';
    } else {
        echo '<p><strong>This is a preview only.</strong> Click "Sync All Episodes" to save changes.</p>';
    }
    echo '</div>';
}

function extract_episode_number($title) {
    $patterns = [
        '/Episode\s+(\d+)/i',
        '/Ep\s+(\d+)/i',
        '/Chapter\s+(\d+)/i',
        '/\b(\d+)\s*$/',
        '/#\s*(\d+)/',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $title, $matches)) {
            return intval($matches[1]);
        }
    }
    
    return null;
}

/**
 * Auto Unlock Schedule Page - Per Novel
 */
function gemini_auto_unlock_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    if (isset($_POST['save_schedules']) && check_admin_referer('auto_unlock_nonce')) {
        $schedules = [];
        
        if (isset($_POST['novel_id']) && is_array($_POST['novel_id'])) {
            foreach ($_POST['novel_id'] as $index => $novel_id) {
                if (!empty($novel_id)) {
                    $schedules[] = [
                        'novel_id' => intval($novel_id),
                        'novel_name' => sanitize_text_field($_POST['novel_name'][$index]),
                        'search_term' => sanitize_text_field($_POST['search_term'][$index]),
                        'days' => intval($_POST['unlock_days'][$index]),
                        'time' => sanitize_text_field($_POST['unlock_time'][$index]),
                        'enabled' => isset($_POST['enabled'][$index]) ? 1 : 0
                    ];
                }
            }
        }
        
        update_option('gpc_unlock_schedules', $schedules);
        
        wp_clear_scheduled_hook('gpc_auto_unlock_novels');
        if (!empty($schedules)) {
            $earliest_time = '23:59';
            foreach ($schedules as $schedule) {
                if ($schedule['enabled'] && $schedule['time'] < $earliest_time) {
                    $earliest_time = $schedule['time'];
                }
            }
            $timestamp = strtotime('today ' . $earliest_time);
            if ($timestamp < time()) {
                $timestamp = strtotime('tomorrow ' . $earliest_time);
            }
            wp_schedule_event($timestamp, 'daily', 'gpc_auto_unlock_novels');
        }
        
        echo '<div class="updated"><p>✅ Unlock schedules saved!</p></div>';
    }
    
    if (isset($_POST['reset_dates']) && check_admin_referer('auto_unlock_nonce')) {
        $schedules = get_option('gpc_unlock_schedules', []);
        foreach ($schedules as $schedule) {
            delete_post_meta($schedule['novel_id'], 'gpc_next_unlock_date');
        }
        echo '<div class="updated"><p>✅ All unlock dates have been reset!</p></div>';
    }
    
    if (isset($_POST['test_unlock']) && check_admin_referer('auto_unlock_nonce')) {
        echo '<div style="background:#f0f0f1;padding:15px;margin:20px 0;border-left:4px solid #2271b1;">';
        echo '<h3>🧪 Testing Auto-Unlock (Preview Mode)</h3>';
        gpc_run_novel_unlock(true);
        echo '</div>';
    }
    
    if (isset($_POST['run_now']) && check_admin_referer('auto_unlock_nonce')) {
        echo '<div style="background:#f0f0f1;padding:15px;margin:20px 0;border-left:4px solid #2271b1;">';
        echo '<h3>🚀 Running Auto-Unlock NOW</h3>';
        gpc_run_novel_unlock(false, true); // Pass true for force run
        echo '</div>';
    }
    
    $schedules = get_option('gpc_unlock_schedules', []);
    $next_run = wp_next_scheduled('gpc_auto_unlock_novels');
    
    $novels = get_posts([
        'post_type' => 'novels',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC'
    ]);
    
    ?>
    <div class="wrap">
        <h1>⏰ Auto Unlock Schedule (Per Novel)</h1>
        <p>Set up different unlock schedules for each novel. One advance episode will be unlocked per schedule.</p>
        
        <form method="post">
            <?php wp_nonce_field('auto_unlock_nonce'); ?>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:40px;">Enable</th>
                        <th>Novel</th>
                        <th style="width:200px;">Search Term in Episode Title</th>
                        <th style="width:120px;">Unlock Every</th>
                        <th style="width:100px;">Run Time</th>
                        <th style="width:120px;">Skip Weekends?</th>
                        <th style="width:150px;">Next Episode Due</th>
                        <th style="width:80px;">Action</th>
                    </tr>
                </thead>
                <tbody id="schedule-rows">
                    <?php
                    if (empty($schedules)) {
                        echo '<tr>';
                        echo '<td><input type="checkbox" name="enabled[0]" value="1" checked></td>';
                        echo '<td><select name="novel_id[0]" required><option value="">Select Novel...</option>';
                        foreach ($novels as $novel) {
                            echo '<option value="' . $novel->ID . '">' . esc_html($novel->post_title) . '</option>';
                        }
                        echo '</select><input type="hidden" name="novel_name[0]" value=""></td>';
                        echo '<td><input type="text" name="search_term[0]" placeholder="e.g., Surviving Game Barbarian" style="width:100%;" /></td>';
                        echo '<td><input type="number" name="unlock_days[0]" min="1" max="365" value="1" style="width:60px;"> days</td>';
                        echo '<td><input type="time" name="unlock_time[0]" value="02:00" style="width:90px;"></td>';
                        echo '<td><label><input type="checkbox" name="skip_weekends[0]" value="1" checked> Skip Sat/Sun</label></td>';
                        echo '<td><em>Not scheduled yet</em></td>';
                        echo '<td><button type="button" class="button remove-row">Remove</button></td>';
                        echo '</tr>';
                    } else {
                        foreach ($schedules as $index => $schedule) {
                            $next_due = get_post_meta($schedule['novel_id'], 'gpc_next_unlock_date', true);
                            $search_term = isset($schedule['search_term']) ? $schedule['search_term'] : '';
                            echo '<tr>';
                            echo '<td><input type="checkbox" name="enabled[' . $index . ']" value="1" ' . checked($schedule['enabled'], 1, false) . '></td>';
                            echo '<td><select name="novel_id[' . $index . ']" required><option value="">Select Novel...</option>';
                            foreach ($novels as $novel) {
                                echo '<option value="' . $novel->ID . '" ' . selected($schedule['novel_id'], $novel->ID, false) . '>' . esc_html($novel->post_title) . '</option>';
                            }
                            echo '</select><input type="hidden" name="novel_name[' . $index . ']" value="' . esc_attr($schedule['novel_name']) . '"></td>';
                            echo '<td><input type="text" name="search_term[' . $index . ']" value="' . esc_attr($search_term) . '" placeholder="Leave empty to use novel title" style="width:100%;" /></td>';
                            echo '<td><input type="number" name="unlock_days[' . $index . ']" min="1" max="365" value="' . $schedule['days'] . '" style="width:60px;"> days</td>';
                            echo '<td><input type="time" name="unlock_time[' . $index . ']" value="' . esc_attr($schedule['time']) . '" style="width:90px;"></td>';
                            $skip_weekends = isset($schedule['skip_weekends']) ? $schedule['skip_weekends'] : 1;
                            echo '<td><label><input type="checkbox" name="skip_weekends[' . $index . ']" value="1" ' . checked($skip_weekends, 1, false) . '> Skip Sat/Sun</label></td>';
                            echo '<td>' . ($next_due ? date('M j, Y H:i', strtotime($next_due)) : '<em>Not set</em>') . '</td>';
                            echo '<td><button type="button" class="button remove-row">Remove</button></td>';
                            echo '</tr>';
                        }
                    }
                    ?>
                </tbody>
            </table>
            
            <p style="margin-top:10px;">
                <button type="button" class="button" id="add-schedule">➕ Add Novel Schedule</button>
            </p>
            
            <p class="submit">
                <input type="submit" name="save_schedules" class="button button-primary" value="💾 Save Schedules">
                <input type="submit" name="reset_dates" class="button" value="🔄 Reset All Dates" style="margin-left:10px;">
                <input type="submit" name="test_unlock" class="button" value="🧪 Test (Preview)" style="margin-left:10px;">
                <input type="submit" name="run_now" class="button" value="▶️ Run Now" style="margin-left:10px;">
            </p>
        </form>
        
        <?php if ($next_run): ?>
            <div style="background:#d1ecf1;border-left:4px solid #0c5460;padding:15px;margin:20px 0;">
                <h3>📊 Status</h3>
                <p><strong>Next scheduled check:</strong> <?php echo date('Y-m-d H:i:s', $next_run); ?></p>
            </div>
        <?php endif; ?>
        
        <div style="background:#f8f9fa;padding:15px;margin:20px 0;border:1px solid #ddd;">
            <h3>📖 How it works:</h3>
            <ol>
                <li>Each novel can have its own unlock schedule (e.g., daily, every 2 days, weekly)</li>
                <li>Every day at the specified time, the system checks if it's time to unlock for each novel</li>
                <li>It will unlock <strong>ONE</strong> advance episode (the oldest one) per novel</li>
                <li>The next unlock date is automatically calculated and tracked</li>
            </ol>
            <p><strong>Example:</strong></p>
            <ul>
                <li><strong>Surviving the Game as Barbarian:</strong> Unlock 1 episode every 1 day at 2:00 AM</li>
                <li><strong>Lee Gwak:</strong> Unlock 1 episode every 2 days at 2:00 AM</li>
            </ul>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        let rowIndex = <?php echo count($schedules); ?>;
        const novels = <?php echo json_encode(array_map(function($n) { return ['id' => $n->ID, 'title' => $n->post_title]; }, $novels)); ?>;
        
        $('#add-schedule').click(function() {
            let options = '<option value="">Select Novel...</option>';
            novels.forEach(novel => {
                options += '<option value="' + novel.id + '">' + novel.title + '</option>';
            });
            
            let row = '<tr>' +
                '<td><input type="checkbox" name="enabled[' + rowIndex + ']" value="1" checked></td>' +
                '<td><select name="novel_id[' + rowIndex + ']" required>' + options + '</select>' +
                '<input type="hidden" name="novel_name[' + rowIndex + ']" value=""></td>' +
                '<td><input type="text" name="search_term[' + rowIndex + ']" placeholder="e.g., Surviving Game Barbarian" style="width:100%;" /></td>' +
                '<td><input type="number" name="unlock_days[' + rowIndex + ']" min="1" max="365" value="1" style="width:60px;"> days</td>' +
                '<td><input type="time" name="unlock_time[' + rowIndex + ']" value="02:00" style="width:90px;"></td>' +
                '<td><label><input type="checkbox" name="skip_weekends[' + rowIndex + ']" value="1" checked> Skip Sat/Sun</label></td>' +
                '<td><em>Not scheduled yet</em></td>' +
                '<td><button type="button" class="button remove-row">Remove</button></td>' +
                '</tr>';
            
            $('#schedule-rows').append(row);
            rowIndex++;
        });
        
        $(document).on('click', '.remove-row', function() {
            $(this).closest('tr').remove();
        });
        
        $(document).on('change', 'select[name^="novel_id"]', function() {
            let selectedText = $(this).find('option:selected').text();
            $(this).siblings('input[name^="novel_name"]').val(selectedText);
        });
    });
    </script>
    <?php
}

function gpc_run_novel_unlock($preview = false, $force_run = false) {
    $schedules = get_option('gpc_unlock_schedules', []);
    
    if (empty($schedules)) {
        echo '<p>⚠️ No unlock schedules configured.</p>';
        return;
    }
    
    $current_time = current_time('mysql');
    $unlocked_count = 0;
    
    if ($force_run) {
        echo '<p style="background:#fff3cd;padding:10px;border-left:4px solid #ffc107;"><strong>⚡ Force Mode:</strong> Ignoring schedule times and unlocking all due episodes now.</p>';
    }
    
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>Novel</th><th>Schedule</th><th>Next Due</th><th>Episode to Unlock</th><th>Status</th></tr></thead><tbody>';
    
    foreach ($schedules as $schedule) {
        if (!$schedule['enabled']) {
            continue;
        }
        
        $novel_id = $schedule['novel_id'];
        $novel = get_post($novel_id);
        
        if (!$novel) {
            continue;
        }
        
        $next_unlock = get_post_meta($novel_id, 'gpc_next_unlock_date', true);
        
        if (empty($next_unlock)) {
            // Get current timestamp
            $current_timestamp = current_time('timestamp');
            
            // Parse scheduled time - handle both 12h and 24h formats
            $time_parts = explode(':', $schedule['time']);
            $scheduled_hour = (int)$time_parts[0];
            $scheduled_minute = isset($time_parts[1]) ? (int)$time_parts[1] : 0;
            
            // Create today's date at scheduled time
            $today_date = date('Y-m-d', $current_timestamp);
            $today_at_scheduled_time = $today_date . ' ' . sprintf('%02d:%02d:00', $scheduled_hour, $scheduled_minute);
            $today_timestamp = strtotime($today_at_scheduled_time);
            
            // If scheduled time today has already passed, use tomorrow
            if ($today_timestamp <= $current_timestamp) {
                $next_unlock = date('Y-m-d H:i:s', $today_timestamp + 86400); // Add 1 day (86400 seconds)
            } else {
                $next_unlock = $today_at_scheduled_time;
            }
            
            // If skip_weekends is enabled and next date falls on weekend, move to Monday
            if (!empty($schedule['skip_weekends'])) {
                $day_of_week = date('w', strtotime($next_unlock));
                if ($day_of_week == 0) { // Sunday -> Monday
                    $next_unlock = date('Y-m-d H:i:s', strtotime($next_unlock . ' +1 day'));
                } elseif ($day_of_week == 6) { // Saturday -> Monday  
                    $next_unlock = date('Y-m-d H:i:s', strtotime($next_unlock . ' +2 days'));
                }
            }
            
            if (!$preview) {
                update_post_meta($novel_id, 'gpc_next_unlock_date', $next_unlock);
            }
        }
        
        echo '<tr>';
        echo '<td><strong>' . esc_html($novel->post_title) . '</strong></td>';
        echo '<td>Every ' . $schedule['days'] . ' day(s) at ' . $schedule['time'] . '</td>';
        echo '<td>' . date('M j, Y H:i', strtotime($next_unlock)) . '</td>';
        
        // Check if it's time to unlock OR force run is enabled
        if ($force_run || strtotime($next_unlock) <= strtotime($current_time)) {
            $episode = get_oldest_advance_episode_for_novel($novel_id);
            
            if ($episode) {
                echo '<td>' . esc_html($episode->post_title) . ' (ID: ' . $episode->ID . ')</td>';
                
                if (!$preview) {
                    unlock_episode($episode->ID);
                    
                    // Calculate next unlock date, respecting weekend skipping
                    $new_next_unlock = calculate_next_unlock_date($next_unlock, $schedule['days'], $schedule['skip_weekends']);
                    update_post_meta($novel_id, 'gpc_next_unlock_date', $new_next_unlock);
                    
                    echo '<td style="color:green;font-weight:bold;">✅ Unlocked! Next: ' . date('M j, Y H:i', strtotime($new_next_unlock)) . '</td>';
                    $unlocked_count++;
                } else {
                    echo '<td style="color:blue;">→ Will unlock</td>';
                    $unlocked_count++;
                }
            } else {
                echo '<td><em>No advance episodes found</em></td>';
                echo '<td style="color:orange;">⚠️ No episodes to unlock</td>';
            }
        } else {
            echo '<td><em>Not due yet</em></td>';
            echo '<td>⏳ Next unlock in ' . human_time_diff(strtotime($current_time), strtotime($next_unlock)) . '</td>';
        }
        
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    
    if ($unlocked_count > 0) {
        echo '<div style="background:#d4edda;padding:10px;margin-top:15px;border-left:4px solid #28a745;">';
        if ($preview) {
            echo '<p><strong>📋 Preview:</strong> ' . $unlocked_count . ' episode(s) will be unlocked.</p>';
        } else {
            echo '<p><strong>✅ Success:</strong> ' . $unlocked_count . ' episode(s) have been unlocked!</p>';
        }
        echo '</div>';
    }
}

function get_oldest_advance_episode_for_novel($novel_id) {
    $novel = get_post($novel_id);
    if (!$novel) {
        return null;
    }
    
    // Check if there's a custom search term for this novel
    $schedules = get_option('gpc_unlock_schedules', []);
    $search_term = '';
    
    foreach ($schedules as $schedule) {
        if ($schedule['novel_id'] == $novel_id && !empty($schedule['search_term'])) {
            $search_term = $schedule['search_term'];
            break;
        }
    }
    
    // If no custom search term, use novel title
    if (empty($search_term)) {
        $search_term = $novel->post_title;
    }
    
    // Get all advance episodes
    $args = [
        'post_type' => 'episodes',
        'posts_per_page' => -1,
        'tax_query' => [
            [
                'taxonomy' => 'chapter-categories',
                'field' => 'slug',
                'terms' => 'advance'
            ]
        ]
    ];
    
    $query = new WP_Query($args);
    
    if (!$query->have_posts()) {
        return null;
    }
    
    // Collect all matching episodes with their episode numbers
    $all_episodes = [];
    while ($query->have_posts()) {
        $query->the_post();
        $episode_title = get_the_title();
        $episode_num = get_post_meta(get_the_ID(), 'episode_number', true);
        
        // Check if search term is in episode title
        if (stripos($episode_title, $search_term) !== false && !empty($episode_num)) {
            $all_episodes[] = [
                'id' => get_the_ID(),
                'title' => $episode_title,
                'number' => intval($episode_num)
            ];
        }
    }
    wp_reset_postdata();
    
    if (empty($all_episodes)) {
        return null;
    }
    
    // Sort by episode number (lowest first)
    usort($all_episodes, function($a, $b) {
        return $a['number'] - $b['number'];
    });
    
    // Return the first one (lowest episode number)
    return get_post($all_episodes[0]['id']);
}

function unlock_episode($post_id) {
    $free_term = get_term_by('name', 'Free', 'chapter-categories');
    if (!$free_term) {
        $new_term = wp_insert_term('Free', 'chapter-categories');
        if (!is_wp_error($new_term)) {
            $free_term = get_term($new_term['term_id']);
        }
    }
    
    if ($free_term) {
        wp_set_object_terms($post_id, [$free_term->term_id], 'chapter-categories', false);
    }
    
    delete_post_meta($post_id, '_ppwp_patreon_level');
    delete_post_meta($post_id, 'patreon-level');
    update_post_meta($post_id, 'patreon-level', '0');
    
    if (function_exists('update_field')) {
        update_field('section', 'free', $post_id);
    }
}

/**
 * Calculate next unlock date, optionally skipping weekends
 */
function calculate_next_unlock_date($current_date, $days_interval, $skip_weekends) {
    $next_date = $current_date;
    $days_added = 0;
    
    while ($days_added < $days_interval) {
        $next_date = date('Y-m-d H:i:s', strtotime($next_date . ' +1 day'));
        
        if ($skip_weekends) {
            // 0 = Sunday, 6 = Saturday
            $day_of_week = date('w', strtotime($next_date));
            if ($day_of_week == 0 || $day_of_week == 6) {
                // Skip weekends, don't count this day
                continue;
            }
        }
        
        $days_added++;
    }
    
    return $next_date;
}

add_action('gpc_auto_unlock_novels', 'gpc_run_novel_unlock');