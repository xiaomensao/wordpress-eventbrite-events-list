<?php
/**
 * Plugin Name: Eventbrite Events List
 * Description: Display Eventbrite events using a shortcode. Supports organizer-based and collection-based lists. Example: [eventbrite_events limit="5"]
 * Version: 1.2.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) exit;

/* -----------------------------------------------------------
   Helper: Load plugin settings
----------------------------------------------------------- */
function eb_events_get_settings() {
    $defaults = [
        'source_type'   => 'organizer', // 'organizer' or 'collection'
        'organizer_id'  => '',
        'collection_id' => '',
        'token'         => '',
        'default_limit' => 5,
        'upcoming_only' => 0,          // 0 = all events, 1 = only live/upcoming
    ];
    return wp_parse_args(get_option('eb_events_settings', []), $defaults);
}

/* -----------------------------------------------------------
   Build API URL based on source type (organizer / collection)
----------------------------------------------------------- */
function eb_events_build_api_url($source_type, $source_id, $token, $upcoming_only = false) {
    if (!$source_type || !$source_id || !$token) {
        return '';
    }

    // Base path by source type
    if ($source_type === 'collection') {
        $base = "https://www.eventbriteapi.com/v3/collections/{$source_id}/events/";
    } else {
        $base = "https://www.eventbriteapi.com/v3/organizers/{$source_id}/events/";
    }

    $args = [
        'order_by' => 'start_asc',
        'page'     => 1,
        'token'    => $token,
    ];

    // 只要正在进行/未来活动：用 status=live
    if ($upcoming_only) {
        $args['status'] = 'live';
    }

    $url = add_query_arg($args, $base);

    return $url;
}

/* -----------------------------------------------------------
   Fetch events from Eventbrite API
----------------------------------------------------------- */
function eb_events_fetch_data($source_type, $source_id, $token, $limit = 5, $upcoming_only = false) {

    if (!$source_type || !$source_id || !$token) return [];

    // cache key 包含是否 upcoming_only
    $cache_key = 'eb_events_' . md5($source_type . '|' . $source_id . '|' . $limit . '|' . ($upcoming_only ? '1' : '0'));
    if ($cached = get_transient($cache_key)) {
        return $cached;
    }

    $url = eb_events_build_api_url($source_type, $source_id, $token, $upcoming_only);
    if (!$url) return [];

    $response = wp_remote_get($url, ['timeout' => 10]);
    if (is_wp_error($response)) return [];

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['events']) || !is_array($body['events'])) return [];

    $events = array_slice($body['events'], 0, (int) $limit);

    set_transient($cache_key, $events, 10 * MINUTE_IN_SECONDS);
    return $events;
}

/* -----------------------------------------------------------
   Shortcode: [eventbrite_events]
   支持参数：
   - [eventbrite_events]                               使用默认设置
   - [eventbrite_events organizer="123"]               指定 organizer
   - [eventbrite_events collection="abc"]              指定 collection
   - [eventbrite_events upcoming_only="1"]             只显示正在进行/未来活动
   - [eventbrite_events upcoming_only="0"]             显示所有活动
----------------------------------------------------------- */
function eb_events_shortcode($atts) {
    $settings = eb_events_get_settings();

    $atts = shortcode_atts([
        'organizer'     => '',
        'collection'    => '',
        'source'        => '',   // 可选覆盖：organizer / collection
        'limit'         => $settings['default_limit'],
        'upcoming_only' => $settings['upcoming_only'] ? '1' : '0',
    ], $atts);

    $limit = (int) $atts['limit'];
    $token = $settings['token'];

    // 解析 upcoming_only 参数（支持 1/0 / true/false / yes/no）
    $uo_raw        = strtolower(trim((string) $atts['upcoming_only']));
    $upcoming_only = in_array($uo_raw, ['1', 'true', 'yes'], true);

    // 1. 确定 source_type
    $source_type = $settings['source_type']; // 默认来自设置页
    if (!empty($atts['source'])) {
        $src = strtolower(trim($atts['source']));
        if (in_array($src, ['organizer', 'collection'], true)) {
            $source_type = $src;
        }
    }

    // 2. 根据 source_type 决定 ID
    $source_id = '';

    if ($source_type === 'collection') {
        if (!empty($atts['collection'])) {
            $source_id = $atts['collection'];
        } else {
            $source_id = $settings['collection_id'];
        }
    } else {
        if (!empty($atts['organizer'])) {
            $source_id = $atts['organizer'];
        } else {
            $source_id = $settings['organizer_id'];
        }
        $source_type = 'organizer';
    }

    if (!$source_id || !$token) {
        return '<p>Eventbrite settings missing. Please configure an API token and at least one Organizer ID or Collection ID in WP Admin.</p>';
    }

    $events = eb_events_fetch_data($source_type, $source_id, $token, $limit, $upcoming_only);

    if (empty($events)) {
        return '<p>No events found.</p>';
    }

    ob_start(); ?>
    <div class="eb-events-list">
        <?php foreach ($events as $event):
            $title  = $event['name']['text'] ?? '';
            $url    = $event['url'] ?? '#';
            $start  = $event['start']['local'] ?? '';
            $logo   = $event['logo']['url'] ?? '';
            $venue  = $event['venue']['name'] ?? '';

            $date_display = $start ? date_i18n('M j, Y g:ia', strtotime($start)) : '';
        ?>
            <div class="eb-event-item" style="border:1px solid #eee;padding:16px;margin:12px 0;border-radius:8px;">
                <?php if ($logo): ?>
                    <img src="<?php echo esc_url($logo); ?>"
                         alt="<?php echo esc_attr($title); ?>"
                         style="max-width:100%;border-radius:6px;margin-bottom:10px;">
                <?php endif; ?>

                <h3 style="margin:0 0 8px;">
                    <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">
                        <?php echo esc_html($title); ?>
                    </a>
                </h3>

                <?php if ($date_display): ?>
                    <p style="margin:0 0 4px;color:#555;">
                        <strong>Date:</strong> <?php echo esc_html($date_display); ?>
                    </p>
                <?php endif; ?>

                <?php if ($venue): ?>
                    <p style="margin:0 0 4px;color:#555;">
                        <strong>Location:</strong> <?php echo esc_html($venue); ?>
                    </p>
                <?php endif; ?>

                <p style="margin:4px 0 0;">
                    <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">
                        View on Eventbrite
                    </a>
                </p>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('eventbrite_events', 'eb_events_shortcode');

/* -----------------------------------------------------------
   Admin Settings Page
----------------------------------------------------------- */
function eb_events_add_admin_menu() {
    add_options_page(
        'Eventbrite Events Settings',
        'Eventbrite Events',
        'manage_options',
        'eb-events-settings',
        'eb_events_render_settings_page'
    );
}
add_action('admin_menu', 'eb_events_add_admin_menu');

function eb_events_render_settings_page() {

    if (isset($_POST['eb_events_save']) && check_admin_referer('eb_events_nonce')) {
        $source_type   = isset($_POST['source_type']) ? sanitize_text_field($_POST['source_type']) : 'organizer';
        if (!in_array($source_type, ['organizer', 'collection'], true)) {
            $source_type = 'organizer';
        }

        $organizer_id  = sanitize_text_field($_POST['organizer_id']  ?? '');
        $collection_id = sanitize_text_field($_POST['collection_id'] ?? '');
        $token         = sanitize_text_field($_POST['token']         ?? '');
        $default_limit = intval($_POST['default_limit'] ?? 5);
        $upcoming_only = isset($_POST['upcoming_only']) ? 1 : 0;

        update_option('eb_events_settings', [
            'source_type'   => $source_type,
            'organizer_id'  => $organizer_id,
            'collection_id' => $collection_id,
            'token'         => $token,
            'default_limit' => $default_limit,
            'upcoming_only' => $upcoming_only,
        ]);

        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $s = eb_events_get_settings();
    ?>

    <div class="wrap">
        <h1>Eventbrite Events Settings</h1>

        <form method="post">
            <?php wp_nonce_field('eb_events_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th>Default Source Type</th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="source_type" value="organizer" <?php checked($s['source_type'], 'organizer'); ?>>
                                Organizer
                            </label><br>
                            <label>
                                <input type="radio" name="source_type" value="collection" <?php checked($s['source_type'], 'collection'); ?>>
                                Collection
                            </label>
                            <p class="description">Default source when shortcode does not specify organizer/collection.</p>
                        </fieldset>
                    </td>
                </tr>

                <tr>
                    <th><label for="organizer_id">Default Organizer ID</label></th>
                    <td>
                        <input type="text" name="organizer_id" id="organizer_id"
                               value="<?php echo esc_attr($s['organizer_id']); ?>" class="regular-text">
                        <p class="description">Optional. Used when source type is "organizer" and no organizer is specified in the shortcode.</p>
                    </td>
                </tr>

                <tr>
                    <th><label for="collection_id">Default Collection ID</label></th>
                    <td>
                        <input type="text" name="collection_id" id="collection_id"
                               value="<?php echo esc_attr($s['collection_id']); ?>" class="regular-text">
                        <p class="description">
                            Optional. Used when source type is "collection" and no collection is specified in the shortcode.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th><label for="token">API Token</label></th>
                    <td>
                        <input type="text" name="token" id="token"
                               value="<?php echo esc_attr($s['token']); ?>" class="regular-text">
                        <p class="description">Your private Eventbrite API token (keep this secret).</p>
                    </td>
                </tr>

                <tr>
                    <th><label for="default_limit">Default Limit</label></th>
                    <td>
                        <input type="number" name="default_limit" id="default_limit"
                               value="<?php echo esc_attr($s['default_limit']); ?>" min="1" max="50">
                        <p class="description">Default number of events when no limit is specified in the shortcode.</p>
                    </td>
                </tr>

                <tr>
                    <th><label for="upcoming_only">Only show live/upcoming events</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="upcoming_only" id="upcoming_only" value="1"
                                <?php checked($s['upcoming_only'], 1); ?>>
                            Yes, hide past events (只显示正在进行/未来的活动，隐藏已结束活动)
                        </label>
                    </td>
                </tr>
            </table>

            <p><button type="submit" name="eb_events_save" class="button button-primary">Save Settings</button></p>
        </form>

        <hr>
        <h2>Usage</h2>
        <p><strong>Use defaults：</strong><br>
            <code>[eventbrite_events]</code>
        </p>

        <p><strong>Specify organizer：</strong><br>
            <code>[eventbrite_events organizer="1234567890" limit="5"]</code>
        </p>

        <p><strong>Specify collection：</strong><br>
            <code>[eventbrite_events collection="COLLECTION_ID" limit="5"]</code>
        </p>

        <p><strong>Force upcoming only in shortcode：</strong><br>
            <code>[eventbrite_events upcoming_only="1"]</code>
        </p>

        <p><strong>Force include all events (override setting)：</strong><br>
            <code>[eventbrite_events upcoming_only="0"]</code>
        </p>
    </div>

    <?php
}
