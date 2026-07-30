<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

use IMS\Module\User\Auth\AdminAuthSettingsPage;
use IMS\Support\Crypto;
use IMS\Support\Google\ChatNotifier;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 「ポータル設定 > 打刻システム設定」画面（02_time_tracking.md §2.1）。
 *
 * 権限：ims_manage_system（administrator のみ）。要件の「システム管理者のみが変更できる」に対応。
 *
 * 画面構成はワイヤーフレーム 画面5/5 に準拠し、左メニュー＋右パネルの3セクション：
 *   ① 深夜勤務の日付設定       → §2.1①
 *   ② スタッフ自身による打刻の修正 → §2.1②
 *   ③ 残業アラートの通知先      → §2.1③
 *
 * 3セクションは1つの <form> にまとめ、どの「保存する」を押しても全体を保存する。
 * セクションを跨いだ部分保存による設定の取りこぼしを防ぐため。
 *
 * 注：要件定義書の記載は「設定 > 打刻システム設定」だが、既存実装で
 *     プラグイン設定は「ポータル設定」メニューに集約されているため（認証設定・
 *     外部ツール設定）、一貫性を優先してその配下に置く。
 */
final class AdminSettingsPage
{
    private const MENU_SLUG   = 'ims-timecard-settings';
    private const CAP         = 'ims_manage_system';
    private const NONCE_SAVE  = 'ims_save_timecard_settings';
    private const NONCE_TEST  = 'ims_test_timecard_webhook';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 11);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_post_ims_save_timecard_settings', [self::class, 'handle_save']);
        add_action('admin_post_ims_test_timecard_webhook', [self::class, 'handle_test_send']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            AdminAuthSettingsPage::PARENT_SLUG, // 「ポータル設定」
            '打刻システム設定',
            '打刻システム設定',
            self::CAP,
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueue(string $hook): void
    {
        if (!str_contains($hook, self::MENU_SLUG)) {
            return;
        }
        wp_enqueue_style('ims-portal-tokens', IMS_PORTAL_URL . 'assets/css/ims-tokens.css', [], IMS_PORTAL_VERSION);
        wp_enqueue_style('ims-admin', IMS_PORTAL_URL . 'assets/css/admin.css', ['ims-portal-tokens'], IMS_PORTAL_VERSION);
        // この画面専用CSS。共通の admin.css は他画面に影響するため触らない。
        wp_enqueue_style('ims-timecard-admin', IMS_PORTAL_URL . 'assets/css/timecard-admin.css', ['ims-admin'], IMS_PORTAL_VERSION);
        wp_enqueue_script('ims-timecard-admin', IMS_PORTAL_URL . 'assets/js/timecard-admin.js', [], IMS_PORTAL_VERSION, true);
    }

    public static function page_url(): string
    {
        return admin_url('admin.php?page=' . self::MENU_SLUG);
    }

    // ── ハンドラ ───────────────────────────────────────────

    public static function handle_save(): void
    {
        self::guard();
        check_admin_referer(self::NONCE_SAVE);

        $webhook_raw = trim((string) wp_unslash($_POST['chat_webhook_url'] ?? ''));

        // URL が入力された場合のみ形式を検証する（空欄＝既存維持）
        if ($webhook_raw !== '' && !Settings::is_valid_webhook_url($webhook_raw)) {
            self::redirect_back('invalid_webhook', 's3');
        }

        Settings::update([
            'date_boundary_hour'     => (int) ($_POST['date_boundary_hour'] ?? 0),
            'staff_correction_level' => (string) wp_unslash($_POST['staff_correction_level'] ?? 'pre_closing'),
            'alert_threshold_min'    => (int) ($_POST['alert_threshold_min'] ?? 30),
            'chat_webhook_url'       => $webhook_raw,
            'chat_webhook_clear'     => !empty($_POST['chat_webhook_clear']),
        ]);

        self::redirect_back('saved', (string) ($_POST['active_section'] ?? 's1'));
    }

    /**
     * Webhook のテスト送信（§2.1③）。
     * 未保存の入力欄の値があればそれを優先して試せるようにする
     *  （「貼り付けてすぐ疎通確認したい」という要件の意図に沿う）。
     */
    public static function handle_test_send(): void
    {
        self::guard();
        check_admin_referer(self::NONCE_TEST);

        $typed = trim((string) wp_unslash($_POST['chat_webhook_url'] ?? ''));
        $url   = $typed !== '' ? $typed : Settings::chat_webhook_url();

        if ($url === '') {
            self::redirect_back('test_no_url', 's3');
        }
        if (!Settings::is_valid_webhook_url($url)) {
            self::redirect_back('invalid_webhook', 's3');
        }

        $result = ChatNotifier::send_now($url, self::test_message());

        if ($result === true) {
            self::redirect_back('test_ok', 's3');
        }

        // 失敗理由を画面に出す（一時的な transient に置き、URL には載せない）
        set_transient(self::error_transient_key(), $result->get_error_message(), 60);
        self::redirect_back('test_ng', 's3');
    }

    private static function test_message(): string
    {
        $user = wp_get_current_user();
        return "【IMS ポータル】打刻システムのテスト送信です。\n"
            . 'この通知が見えていれば、Webhook の設定は正しく動作しています。' . "\n"
            . '送信者: ' . $user->display_name . ' / 送信日時: ' . current_time('Y年n月j日 H:i');
    }

    private static function error_transient_key(): string
    {
        return 'ims_tc_webhook_err_' . get_current_user_id();
    }

    private static function redirect_back(string $notice, string $section): void
    {
        wp_safe_redirect(add_query_arg(
            ['ims_notice' => $notice, 'sec' => $section],
            self::page_url()
        ));
        exit;
    }

    private static function guard(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'), '', ['response' => 403]);
        }
    }

    // ── 画面描画 ───────────────────────────────────────────

    public static function render(): void
    {
        self::guard();

        $opt          = Settings::all();
        $has_webhook  = Settings::has_chat_webhook();
        $notice       = (string) ($_GET['ims_notice'] ?? '');
        $active       = (string) ($_GET['sec'] ?? 's1');
        $active       = in_array($active, ['s1', 's2', 's3'], true) ? $active : 's1';
        $err_detail   = get_transient(self::error_transient_key());
        if ($err_detail !== false) {
            delete_transient(self::error_transient_key());
        }
        ?>
        <div class="wrap ims-admin ims-tc-settings">
            <h1 class="wp-heading-inline">打刻システム設定</h1>
            <hr class="wp-header-end">

            <?php self::render_notice($notice, (string) $err_detail); ?>

            <?php if (!Crypto::available()) : ?>
                <div class="notice notice-error">
                    <p>サーバーで OpenSSL が利用できないため、Webhook URL を暗号化して保存できません。ホスティング環境をご確認ください。</p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ims-tc-form">
                <?php wp_nonce_field(self::NONCE_SAVE); ?>
                <input type="hidden" name="action" value="ims_save_timecard_settings">
                <input type="hidden" name="active_section" id="ims-tc-active-section" value="<?php echo esc_attr($active); ?>">

                <div class="ims-tc-grid">
                    <nav class="ims-tc-menu" aria-label="設定セクション">
                        <button type="button" class="ims-tc-menu-item<?php echo $active === 's1' ? ' is-active' : ''; ?>" data-target="s1">① 深夜勤務の日付設定</button>
                        <button type="button" class="ims-tc-menu-item<?php echo $active === 's2' ? ' is-active' : ''; ?>" data-target="s2">② スタッフの打刻修正</button>
                        <button type="button" class="ims-tc-menu-item<?php echo $active === 's3' ? ' is-active' : ''; ?>" data-target="s3">③ 残業アラート通知</button>
                    </nav>

                    <div class="ims-tc-panel">
                        <?php
                        self::render_section_boundary($opt, $active);
                        self::render_section_correction($opt, $active);
                        self::render_section_alert($opt, $has_webhook, $active);
                        ?>
                    </div>
                </div>
            </form>

            <?php self::render_test_form(); ?>
        </div>
        <?php
    }

    /** ① 深夜勤務の日付設定（§2.1①） */
    private static function render_section_boundary(array $opt, string $active): void
    {
        ?>
        <section class="ims-tc-section<?php echo $active === 's1' ? ' is-active' : ''; ?>" id="ims-tc-s1">
            <h2>① 深夜勤務の日付設定</h2>
            <p class="ims-tc-desc">
                日をまたいで勤務するスタッフがいる場合に設定します。深夜の退勤打刻を「前日の勤務」として記録する時刻を指定できます。<br>
                <strong>通常の勤務形態のみの場合は「0時」のままで問題ありません。</strong>
            </p>

            <div class="ims-tc-field">
                <label for="date_boundary_hour">この時刻より前の退勤は「前日の勤務」として記録する</label>
                <div class="ims-tc-inline">
                    <input type="number" id="date_boundary_hour" name="date_boundary_hour"
                           min="0" max="23" step="1"
                           value="<?php echo esc_attr((string) $opt['date_boundary_hour']); ?>">
                    <span>時（0〜23時）</span>
                </div>
                <p class="ims-tc-hint">
                    例：「4」に設定した場合 → 深夜 0 時〜3 時 59 分の退勤は前日の勤務として集計されます。<br>
                    「0」のまま（初期値）→ 日またぎを考慮しません。
                </p>
                <p class="ims-tc-hint">
                    この設定が影響するのは<strong>退勤打刻のみ</strong>です。出勤打刻は常に打刻した日が勤務日になります。
                </p>
            </div>

            <?php self::render_save_button(); ?>
        </section>
        <?php
    }

    /** ② スタッフ自身による打刻の修正（§2.1②） */
    private static function render_section_correction(array $opt, string $active): void
    {
        $levels = [
            'disabled'    => ['修正させない', 'スタッフ本人は打刻を修正できません。修正が必要な場合は管理者へ依頼が必要です。', false],
            'today_only'  => ['当日分のみ修正できる', '当日の打刻に限り、スタッフ自身が修正できます。翌日以降は管理者のみ修正可能です。', false],
            'pre_closing' => ['月次締めまでの分を修正できる', '当月の締め処理が完了するまでの間、スタッフ自身が過去の打刻を修正できます。', true],
        ];
        $current = $opt['staff_correction_level'];
        ?>
        <section class="ims-tc-section<?php echo $active === 's2' ? ' is-active' : ''; ?>" id="ims-tc-s2">
            <h2>② スタッフ自身による打刻の修正</h2>
            <p class="ims-tc-desc">
                スタッフが自分の打刻を後から修正できる範囲を設定します。<br>
                人事担当者・システム管理者は、この設定に関わらず常に修正できます。
            </p>

            <div class="ims-tc-radios">
                <?php foreach ($levels as $value => [$label, $desc, $is_default]) : ?>
                    <label class="ims-tc-radio<?php echo $current === $value ? ' is-selected' : ''; ?>">
                        <input type="radio" name="staff_correction_level" value="<?php echo esc_attr($value); ?>"
                               <?php checked($current, $value); ?>>
                        <span class="ims-tc-radio-body">
                            <span class="ims-tc-radio-label">
                                <?php echo esc_html($label); ?>
                                <?php if ($is_default) : ?><span class="ims-tc-chip">初期値</span><?php endif; ?>
                            </span>
                            <span class="ims-tc-radio-desc"><?php echo esc_html($desc); ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <p class="ims-tc-hint">
                月次の締め処理が完了した月の打刻は、この設定に関わらず全員が修正できなくなります（読み取り専用）。
            </p>

            <?php self::render_save_button(); ?>
        </section>
        <?php
    }

    /** ③ 残業アラートの通知先（§2.1③） */
    private static function render_section_alert(array $opt, bool $has_webhook, string $active): void
    {
        ?>
        <section class="ims-tc-section<?php echo $active === 's3' ? ' is-active' : ''; ?>" id="ims-tc-s3">
            <h2>③ 残業アラートの通知先（Google Chat）</h2>
            <p class="ims-tc-desc">
                残業申請の予定終了時刻と実際の退勤時刻が大きくずれていた場合に、Google Chat へ自動で通知します。<br>
                申請承認の通知（稟議モジュール側）とは別のチャットルームに送ることもできます。
            </p>

            <div class="ims-tc-field">
                <label for="chat_webhook_url">通知先のチャットルーム URL</label>
                <div class="ims-tc-webhook-row">
                    <input type="url" id="chat_webhook_url" name="chat_webhook_url"
                           autocomplete="off"
                           placeholder="<?php echo $has_webhook ? '設定済み（変更する場合のみ入力）' : 'https://chat.googleapis.com/v1/spaces/...'; ?>">
                    <button type="button" class="button ims-tc-test-btn" id="ims-tc-test-btn">テスト送信</button>
                </div>
                <p class="ims-tc-hint">
                    Google Chat のルームを開き、「Webhook を管理」から取得した URL を貼り付けてください。<br>
                    保存時に暗号化されます。空欄のまま保存すると既存の設定を維持します。
                </p>
                <?php if ($has_webhook) : ?>
                    <p class="ims-tc-hint">
                        <label>
                            <input type="checkbox" name="chat_webhook_clear" value="1">
                            設定済みの URL を削除する（通知を停止します）
                        </label>
                    </p>
                <?php endif; ?>
            </div>

            <div class="ims-tc-field">
                <label for="alert_threshold_min">ずれが何分以上でアラートを送るか</label>
                <div class="ims-tc-inline">
                    <input type="number" id="alert_threshold_min" name="alert_threshold_min"
                           min="1" max="1440" step="1"
                           value="<?php echo esc_attr((string) $opt['alert_threshold_min']); ?>">
                    <span>分以上のずれで通知（初期値：30分）</span>
                </div>
                <p class="ims-tc-hint">残業申請の予定終了時刻と実際の退勤打刻の差がこの時間を超えたときに通知します。</p>
            </div>

            <div class="ims-tc-sample">
                <strong>通知メッセージのサンプル</strong>
                <pre>【残業時間のずれを検知しました】
申請者　: 山田 太郎
対象日　: 2025年6月15日
申請の予定終了　: 18:00
実際の退勤打刻　: 19:32（＋1時間32分）
勤怠集計は実際の打刻時刻を正として反映します。</pre>
            </div>

            <div class="ims-tc-tip">
                通知の送信に失敗した場合も、打刻の記録自体には影響しません。最大3回まで自動で再送します。
            </div>

            <div class="ims-tc-tip is-pending">
                <strong>この通知はまだ動作しません。</strong>
                乖離の判定には残業申請のデータ（稟議モジュール）が必要なため、実際の通知は稟議モジュールの実装時に有効になります。
                現時点では上記の設定の保存と、テスト送信による疎通確認のみ行えます。
            </div>

            <?php self::render_save_button(); ?>
        </section>
        <?php
    }

    private static function render_save_button(): void
    {
        ?>
        <p class="ims-tc-actions">
            <button type="submit" class="button button-primary">保存する</button>
        </p>
        <?php
    }

    /**
     * テスト送信用の別フォーム。
     * HTML の入れ子フォームは不正なため、本体フォームの外に置き、
     * 入力中の URL は JS が送信直前にコピーする。
     */
    private static function render_test_form(): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ims-tc-test-form" class="ims-tc-hidden-form">
            <?php wp_nonce_field(self::NONCE_TEST); ?>
            <input type="hidden" name="action" value="ims_test_timecard_webhook">
            <input type="hidden" name="chat_webhook_url" id="ims-tc-test-url" value="">
        </form>
        <?php
    }

    private static function render_notice(string $notice, string $err_detail): void
    {
        if ($notice === '') {
            return;
        }

        $map = [
            'saved'           => ['success', '設定を保存しました。'],
            'test_ok'         => ['success', 'テストメッセージを送信しました。Google Chat のルームをご確認ください。'],
            'test_no_url'     => ['warning', '通知先のチャットルーム URL を入力してから、テスト送信を実行してください。'],
            'invalid_webhook' => ['error',   'Webhook URL の形式が正しくありません。https://chat.googleapis.com/ で始まる URL を入力してください。'],
            'test_ng'         => ['error',   'テスト送信に失敗しました。'],
        ];

        if (!isset($map[$notice])) {
            return;
        }
        [$type, $message] = $map[$notice];
        ?>
        <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
            <p>
                <?php echo esc_html($message); ?>
                <?php if ($notice === 'test_ng' && $err_detail !== '') : ?>
                    <br><span class="ims-tc-err-detail"><?php echo esc_html($err_detail); ?></span>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }
}
