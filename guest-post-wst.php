<?php
/**
 * Plugin Name: Guest Post WST
 * Description: Front-end guest post submission form for logged-in users with admin approval workflow.
 * Version: 1.0.0
 * Author: Guest Post WST
 * Text Domain: guest-post-wst
 * Domain Path: /languages
 */

if (! defined('ABSPATH')) {
    exit;
}

final class Guest_Post_WST_Plugin
{
    private const NONCE_ACTION = 'gpwst_submit_post_action';
    private const NONCE_NAME   = 'gpwst_submit_post_nonce';
    private const SHORTCODE    = 'guest_post_submission_form';
    private const RATE_LIMIT_SECONDS = 30;

    public function __construct()
    {
        add_action('init', [$this, 'register_shortcode']);
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_post_gpwst_submit_post', [$this, 'handle_post_submission']);
        add_action('admin_post_nopriv_gpwst_submit_post', [$this, 'handle_guest_submission']);
        add_action('admin_menu', [$this, 'register_admin_page']);
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain('guest-post-wst', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function register_shortcode(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'render_form_shortcode']);
    }

    public function register_admin_page(): void
    {
        add_submenu_page(
            'options-general.php',
            __('Guest Post Form', 'guest-post-wst'),
            __('Guest Post Form', 'guest-post-wst'),
            'manage_options',
            'guest-post-wst',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'guest-post-wst'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Guest Post Form Shortcode', 'guest-post-wst') . '</h1>';
        echo '<p>' . esc_html__('Copy and paste this shortcode into any page:', 'guest-post-wst') . '</p>';
        echo '<code>[' . esc_html(self::SHORTCODE) . ']</code>';
        echo '</div>';
    }

    public function render_form_shortcode(): string
    {
        $message = $this->get_message_from_request();

        ob_start();

        if ($message !== '') {
            echo '<div class="gpwst-message">' . esc_html($message) . '</div>';
        }

        if (! is_user_logged_in()) {
            $login_url    = wp_login_url(get_permalink() ?: home_url('/'));
            $register_url = wp_registration_url();

            echo '<p>' . esc_html__('Please log in to submit a post.', 'guest-post-wst') . '</p>';
            echo '<p><a href="' . esc_url($login_url) . '">' . esc_html__('Log in', 'guest-post-wst') . '</a>';
            echo ' | <a href="' . esc_url($register_url) . '">' . esc_html__('Register', 'guest-post-wst') . '</a></p>';

            return (string) ob_get_clean();
        }

        $categories = get_categories([
            'hide_empty' => false,
        ]);

        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <p>
                <label for="gpwst_title"><?php echo esc_html__('Title', 'guest-post-wst'); ?></label><br>
                <input type="text" id="gpwst_title" name="gpwst_title" required maxlength="200">
            </p>

            <p>
                <label for="gpwst_description"><?php echo esc_html__('Description', 'guest-post-wst'); ?></label><br>
                <textarea id="gpwst_description" name="gpwst_description" rows="8" required></textarea>
            </p>

            <p>
                <label for="gpwst_category"><?php echo esc_html__('Category', 'guest-post-wst'); ?></label><br>
                <select id="gpwst_category" name="gpwst_category" required>
                    <option value=""><?php echo esc_html__('Select a category', 'guest-post-wst'); ?></option>
                    <?php foreach ($categories as $category) : ?>
                        <option value="<?php echo esc_attr((string) $category->term_id); ?>">
                            <?php echo esc_html($category->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label for="gpwst_tags"><?php echo esc_html__('Tags (comma separated)', 'guest-post-wst'); ?></label><br>
                <input type="text" id="gpwst_tags" name="gpwst_tags" maxlength="300">
            </p>

            <p>
                <label for="gpwst_thumbnail"><?php echo esc_html__('Thumbnail', 'guest-post-wst'); ?></label><br>
                <input type="file" id="gpwst_thumbnail" name="gpwst_thumbnail" accept="image/*">
            </p>

            <p><?php echo esc_html__('Your post will be published after admin approval.', 'guest-post-wst'); ?></p>

            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
            <input type="hidden" name="action" value="gpwst_submit_post">

            <p>
                <button type="submit"><?php echo esc_html__('Submit Post', 'guest-post-wst'); ?></button>
            </p>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    public function handle_guest_submission(): void
    {
        $this->safe_redirect_with_status('login_required');
    }

    public function handle_post_submission(): void
    {
        if (! is_user_logged_in()) {
            $this->safe_redirect_with_status('login_required');
        }

        if (! isset($_POST[self::NONCE_NAME]) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
            $this->safe_redirect_with_status('invalid_nonce');
        }

        $user_id = get_current_user_id();
        $rate_limit_key = 'gpwst_submit_' . $user_id;

        if ((int) get_transient($rate_limit_key) === 1) {
            $this->safe_redirect_with_status('rate_limited');
        }

        $title       = isset($_POST['gpwst_title']) ? sanitize_text_field(wp_unslash($_POST['gpwst_title'])) : '';
        $description = isset($_POST['gpwst_description']) ? wp_kses_post(wp_unslash($_POST['gpwst_description'])) : '';
        $category_id = isset($_POST['gpwst_category']) ? absint($_POST['gpwst_category']) : 0;
        $tags_raw    = isset($_POST['gpwst_tags']) ? sanitize_text_field(wp_unslash($_POST['gpwst_tags'])) : '';

        if ($title === '' || $description === '' || $category_id <= 0 || ! term_exists($category_id, 'category')) {
            $this->safe_redirect_with_status('invalid_input');
        }

        $post_data = [
            'post_title'   => $title,
            'post_content' => $description,
            'post_status'  => 'pending',
            'post_author'  => $user_id,
            'post_category'=> [$category_id],
            'tags_input'   => $this->prepare_tags($tags_raw),
            'post_type'    => 'post',
        ];

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            $this->safe_redirect_with_status('submit_failed');
        }

        if (
            isset($_FILES['gpwst_thumbnail'])
            && isset($_FILES['gpwst_thumbnail']['name'])
            && $_FILES['gpwst_thumbnail']['name'] !== ''
            && is_array($_FILES['gpwst_thumbnail'])
        ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $attachment_id = media_handle_upload('gpwst_thumbnail', $post_id);
            if (! is_wp_error($attachment_id)) {
                set_post_thumbnail($post_id, $attachment_id);
            }
        }

        set_transient($rate_limit_key, 1, self::RATE_LIMIT_SECONDS);

        $this->safe_redirect_with_status('success');
    }

    private function prepare_tags(string $tags_raw): array
    {
        if ($tags_raw === '') {
            return [];
        }

        $tags = array_map('trim', explode(',', $tags_raw));
        $tags = array_filter($tags, static fn ($tag): bool => $tag !== '');

        return array_values(array_unique(array_map('sanitize_text_field', $tags)));
    }

    private function safe_redirect_with_status(string $status): void
    {
        $redirect_url = wp_get_referer();

        if (! $redirect_url) {
            $redirect_url = home_url('/');
        }

        $redirect_url = add_query_arg('gpwst_status', rawurlencode($status), $redirect_url);

        wp_safe_redirect($redirect_url);
        exit;
    }

    private function get_message_from_request(): string
    {
        $status = isset($_GET['gpwst_status']) ? sanitize_key(wp_unslash($_GET['gpwst_status'])) : '';

        $messages = [
            'success'        => __('Your post has been submitted successfully and is waiting for admin approval.', 'guest-post-wst'),
            'login_required' => __('You need to log in first.', 'guest-post-wst'),
            'invalid_nonce'  => __('Security check failed. Please try again.', 'guest-post-wst'),
            'invalid_input'  => __('Please fill in all required fields correctly.', 'guest-post-wst'),
            'submit_failed'  => __('Post submission failed. Please try again later.', 'guest-post-wst'),
            'rate_limited'   => __('Please wait a moment before submitting again.', 'guest-post-wst'),
        ];

        return $messages[$status] ?? '';
    }
}

new Guest_Post_WST_Plugin();
