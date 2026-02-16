<?php
/**
 * Plugin Name: Guest Post WST
 * Description: Front-end guest post submission form for logged-in users with admin approval workflow.
 * Version: 1.1.0
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
    private const NONCE_NAME = 'gpwst_submit_post_nonce';
    private const SHORTCODE = 'guest_post_submission_form';
    private const RATE_LIMIT_SECONDS = 30;

    public function __construct()
    {
        add_action('init', [$this, 'register_shortcode']);
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_post_gpwst_submit_post', [$this, 'handle_post_submission']);
        add_action('admin_post_nopriv_gpwst_submit_post', [$this, 'handle_guest_submission']);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('wp_enqueue_scripts', [$this, 'register_frontend_assets']);
    }

    public function register_frontend_assets(): void
    {
        wp_register_style(
            'gpwst-tailwind-local',
            plugins_url('assets/css/tailwind-local.css', __FILE__),
            [],
            '1.0.0'
        );
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
        $lang = $this->get_current_lang();

        wp_enqueue_style('gpwst-tailwind-local');
        wp_enqueue_editor();

        ob_start();
        ?>
        <div dir="<?php echo esc_attr($lang === 'fa' ? 'rtl' : 'ltr'); ?>" class="gpwst-ui-wrapper max-w-3xl mx-auto my-10 rounded-3xl border border-slate-200 bg-white/95 p-6 sm:p-10 shadow-2xl text-slate-800">
            <div class="mb-6 flex items-center justify-between gap-3 flex-wrap">
                <h2 class="text-2xl sm:text-3xl font-semibold tracking-tight text-slate-900"><?php echo esc_html($this->t('guest_post_submission', $lang)); ?></h2>
                <div class="inline-flex rounded-xl border border-slate-200 bg-white p-1 text-sm">
                    <a class="rounded-lg px-3 py-1 <?php echo $lang === 'en' ? 'bg-slate-900 text-white' : 'text-slate-700'; ?>" href="<?php echo esc_url($this->build_lang_url('en')); ?>">English</a>
                    <a class="rounded-lg px-3 py-1 <?php echo $lang === 'fa' ? 'bg-slate-900 text-white' : 'text-slate-700'; ?>" href="<?php echo esc_url($this->build_lang_url('fa')); ?>">فارسی</a>
                </div>
            </div>

            <p class="mt-2 mb-8 text-sm sm:text-base text-slate-500"><?php echo esc_html($this->t('workflow_text', $lang)); ?></p>
        <?php

        if ($message !== '') {
            echo '<div class="mb-6 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">' . esc_html($message) . '</div>';
        }

        if (! is_user_logged_in()) {
            $login_url = wp_login_url(get_permalink() ?: home_url('/'));
            $register_url = wp_registration_url();

            echo '<p class="text-slate-600">' . esc_html($this->t('login_required', $lang)) . '</p>';
            echo '<p class="mt-4 flex flex-wrap items-center gap-3">';
            echo '<a class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700" href="' . esc_url($login_url) . '">' . esc_html($this->t('log_in', $lang)) . '</a>';
            echo '<a class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:text-slate-900" href="' . esc_url($register_url) . '">' . esc_html($this->t('register', $lang)) . '</a>';
            echo '</p>';
            echo '</div>';

            return (string) ob_get_clean();
        }

        $categories = get_categories([
            'hide_empty' => false,
        ]);

        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="space-y-6">
            <div class="space-y-2">
                <label class="block text-sm font-medium text-slate-700" for="gpwst_title"><?php echo esc_html($this->t('title', $lang)); ?></label>
                <input class="w-full rounded-xl border border-slate-200 px-4 py-3 text-slate-900 shadow-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200" type="text" id="gpwst_title" name="gpwst_title" required maxlength="200" placeholder="<?php echo esc_attr($this->t('title_placeholder', $lang)); ?>">
            </div>

            <div class="space-y-2">
                <label class="block text-sm font-medium text-slate-700" for="gpwst_description"><?php echo esc_html($this->t('description', $lang)); ?></label>
                <?php
                wp_editor('', 'gpwst_description_editor', [
                    'textarea_name' => 'gpwst_description',
                    'textarea_rows' => 12,
                    'media_buttons' => false,
                    'teeny' => false,
                    'quicktags' => true,
                    'tinymce' => [
                        'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo',
                        'toolbar2' => 'removeformat,charmap,hr,pastetext,fullscreen',
                    ],
                ]);
                ?>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-slate-700" for="gpwst_category"><?php echo esc_html($this->t('category', $lang)); ?></label>
                    <select class="w-full rounded-xl border border-slate-200 px-4 py-3 text-slate-900 shadow-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200" id="gpwst_category" name="gpwst_category" required>
                        <option value=""><?php echo esc_html($this->t('select_category', $lang)); ?></option>
                        <?php foreach ($categories as $category) : ?>
                            <option value="<?php echo esc_attr((string) $category->term_id); ?>"><?php echo esc_html($category->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="space-y-2">
                    <label class="block text-sm font-medium text-slate-700" for="gpwst_tags"><?php echo esc_html($this->t('tags', $lang)); ?></label>
                    <input class="w-full rounded-xl border border-slate-200 px-4 py-3 text-slate-900 shadow-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200" type="text" id="gpwst_tags" name="gpwst_tags" maxlength="300" placeholder="<?php echo esc_attr($this->t('tags_placeholder', $lang)); ?>">
                </div>
            </div>

            <div class="space-y-2">
                <label class="block text-sm font-medium text-slate-700" for="gpwst_thumbnail"><?php echo esc_html($this->t('thumbnail', $lang)); ?></label>
                <input class="block w-full rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-600 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-slate-700" type="file" id="gpwst_thumbnail" name="gpwst_thumbnail" accept="image/*">
            </div>

            <p class="rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-600"><?php echo esc_html($this->t('approval_text', $lang)); ?></p>

            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
            <input type="hidden" name="action" value="gpwst_submit_post">
            <input type="hidden" name="gpwst_lang" value="<?php echo esc_attr($lang); ?>">

            <div class="pt-2">
                <button class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-5 py-3 text-sm font-medium text-white shadow-sm transition hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-300" type="submit"><?php echo esc_html($this->t('submit_post', $lang)); ?></button>
            </div>
        </form>
        </div>
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

        $title = isset($_POST['gpwst_title']) ? sanitize_text_field(wp_unslash($_POST['gpwst_title'])) : '';
        $description = isset($_POST['gpwst_description']) ? wp_kses_post(wp_unslash($_POST['gpwst_description'])) : '';
        $category_id = isset($_POST['gpwst_category']) ? absint($_POST['gpwst_category']) : 0;
        $tags_raw = isset($_POST['gpwst_tags']) ? sanitize_text_field(wp_unslash($_POST['gpwst_tags'])) : '';

        if ($title === '' || $description === '' || $category_id <= 0 || ! term_exists($category_id, 'category')) {
            $this->safe_redirect_with_status('invalid_input');
        }

        $post_data = [
            'post_title' => $title,
            'post_content' => $description,
            'post_status' => 'pending',
            'post_author' => $user_id,
            'post_category' => [$category_id],
            'tags_input' => $this->prepare_tags($tags_raw),
            'post_type' => 'post',
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

        $redirect_args = ['gpwst_status' => rawurlencode($status)];
        $redirect_args['gpwst_lang'] = $this->get_current_lang();

        $redirect_url = add_query_arg($redirect_args, $redirect_url);

        wp_safe_redirect($redirect_url);
        exit;
    }

    private function get_message_from_request(): string
    {
        $status = isset($_GET['gpwst_status']) ? sanitize_key(wp_unslash($_GET['gpwst_status'])) : '';
        $lang = $this->get_current_lang();

        $messages = [
            'success' => $this->t('msg_success', $lang),
            'login_required' => $this->t('msg_login_required', $lang),
            'invalid_nonce' => $this->t('msg_invalid_nonce', $lang),
            'invalid_input' => $this->t('msg_invalid_input', $lang),
            'submit_failed' => $this->t('msg_submit_failed', $lang),
            'rate_limited' => $this->t('msg_rate_limited', $lang),
        ];

        return $messages[$status] ?? '';
    }

    private function get_current_lang(): string
    {
        $lang = isset($_REQUEST['gpwst_lang']) ? sanitize_key(wp_unslash($_REQUEST['gpwst_lang'])) : '';

        if (! in_array($lang, ['fa', 'en'], true)) {
            $locale = determine_locale();
            $lang = strpos($locale, 'fa') === 0 ? 'fa' : 'en';
        }

        return $lang;
    }

    private function build_lang_url(string $lang): string
    {
        $base = get_permalink() ?: home_url('/');

        return add_query_arg('gpwst_lang', $lang, $base);
    }

    private function t(string $key, string $lang): string
    {
        $translations = [
            'en' => [
                'guest_post_submission' => 'Guest Post Submission',
                'workflow_text' => 'A clean and simple workflow for sharing your article. Your content stays pending until approved by the admin.',
                'login_required' => 'Please log in to submit a post.',
                'log_in' => 'Log in',
                'register' => 'Register',
                'title' => 'Title',
                'title_placeholder' => 'Write a clear and engaging title',
                'description' => 'Description',
                'category' => 'Category',
                'select_category' => 'Select category',
                'tags' => 'Tags (comma separated)',
                'tags_placeholder' => 'tech, tutorial, wordpress',
                'thumbnail' => 'Thumbnail',
                'approval_text' => 'Your post will be published after admin approval.',
                'submit_post' => 'Submit Post',
                'msg_success' => 'Your post has been submitted successfully and is waiting for admin approval.',
                'msg_login_required' => 'You need to log in first.',
                'msg_invalid_nonce' => 'Security check failed. Please try again.',
                'msg_invalid_input' => 'Please fill in all required fields correctly.',
                'msg_submit_failed' => 'Post submission failed. Please try again later.',
                'msg_rate_limited' => 'Please wait a moment before submitting again.',
            ],
            'fa' => [
                'guest_post_submission' => 'ارسال پست مهمان',
                'workflow_text' => 'فرایند ارسال مقاله ساده است و محتوای شما تا زمان تأیید مدیر در حالت انتظار می‌ماند.',
                'login_required' => 'برای ارسال پست لطفاً وارد حساب کاربری شوید.',
                'log_in' => 'ورود',
                'register' => 'ثبت‌نام',
                'title' => 'عنوان',
                'title_placeholder' => 'یک عنوان واضح و جذاب بنویسید',
                'description' => 'توضیحات',
                'category' => 'دسته‌بندی',
                'select_category' => 'انتخاب دسته‌بندی',
                'tags' => 'برچسب‌ها (با کاما جدا کنید)',
                'tags_placeholder' => 'فناوری، آموزش، وردپرس',
                'thumbnail' => 'تصویر شاخص',
                'approval_text' => 'پست شما پس از تأیید مدیر منتشر می‌شود.',
                'submit_post' => 'ارسال پست',
                'msg_success' => 'پست شما با موفقیت ثبت شد و در انتظار تأیید مدیر است.',
                'msg_login_required' => 'ابتدا باید وارد حساب کاربری شوید.',
                'msg_invalid_nonce' => 'بررسی امنیتی ناموفق بود. لطفاً دوباره تلاش کنید.',
                'msg_invalid_input' => 'لطفاً همه فیلدهای ضروری را صحیح تکمیل کنید.',
                'msg_submit_failed' => 'ارسال پست ناموفق بود. لطفاً بعداً دوباره تلاش کنید.',
                'msg_rate_limited' => 'لطفاً کمی صبر کنید و دوباره ارسال کنید.',
            ],
        ];

        return $translations[$lang][$key] ?? $translations['en'][$key] ?? $key;
    }
}

new Guest_Post_WST_Plugin();
