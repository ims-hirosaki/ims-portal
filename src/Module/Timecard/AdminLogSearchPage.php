<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

use IMS\Module\User\EmployeeRepository;
use IMS\Support\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 社員管理＞打刻ログ照会（02_time_tracking.md §4.2）。
 *
 * 監査用の横断検索画面。日常的な打刻修正には使わない想定（労基署対応・疑義確認・
 * 自動補完/修正履歴の証跡確認）。画面を開いた直後は検索フォームのみを表示し、
 * 「検索する」ボタン押下後にのみ結果テーブルを表示する（条件なしなら全件扱い）。
 *
 * 親メニュー（社員管理）は User モジュールの AdminUserListPage が登録済みのため、
 * ここではサブメニューとして追加するだけで User モジュール側は無改修。
 *
 * 検索条件のうち社員名・社員番号は WordPress のユーザーデータ（wp_users/wp_usermeta）
 * にしかないため、ここで先に user_id へ解決してから Repository::search_logs() に渡す
 * （Repository は打刻ログ自身のテーブルのSQLだけを担当する方針を維持するため）。
 */
final class AdminLogSearchPage
{
    private const SLUG = 'ims-timecard-logs';
    private const CAP  = 'ims_manage_users';

    /** 打刻種別 → 日本語ラベル（打刻コンソールのボタン文言と合わせる）。 */
    private const PUNCH_TYPE_LABELS = [
        StatusCalculator::CLOCK_IN  => '出勤',
        StatusCalculator::BREAK_IN  => '休憩開始',
        StatusCalculator::BREAK_OUT => '休憩終了',
        StatusCalculator::CLOCK_OUT => '退勤',
    ];

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 20); // 親（9）より後
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            \IMS\Module\User\AdminUserListPage::PARENT_SLUG,
            '打刻ログ照会',
            '打刻ログ照会',
            self::CAP,
            self::SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueue(string $hook): void
    {
        if (!str_contains($hook, self::SLUG)) {
            return;
        }
        wp_enqueue_style('ims-portal-tokens', IMS_PORTAL_URL . 'assets/css/ims-tokens.css', [], IMS_PORTAL_VERSION);
        wp_enqueue_style('ims-admin', IMS_PORTAL_URL . 'assets/css/admin.css', ['ims-portal-tokens'], IMS_PORTAL_VERSION);
        // このページ専用の見た目（修正モーダル・展開行）。共有 admin.css も、
        // 打刻システム設定画面（2a）が既に使っている timecard-admin.css も変更しない
        // （ハンドル名・ファイル名の衝突を避けるため別名にしてある）。
        wp_enqueue_style(
            'ims-timecard-admin-logs',
            IMS_PORTAL_URL . 'assets/css/timecard-admin-logs.css',
            ['ims-admin'],
            IMS_PORTAL_VERSION
        );

        wp_enqueue_script(
            'ims-timecard-admin-logs',
            IMS_PORTAL_URL . 'assets/js/admin-timecard-logs.js',
            [],
            IMS_PORTAL_VERSION,
            true
        );
        wp_localize_script('ims-timecard-admin-logs', 'imsTimecardAdmin', [
            'restUrl' => esc_url_raw(rest_url('ims/v1/')),
            'nonce'   => wp_create_nonce('wp_rest'),
            // {log_id} はJS側でログIDに置換する（2eのREST修正エンドポイントを流用）
            'correctRoute' => 'timecard/logs/{log_id}/correct',
        ]);
    }

    public static function render(): void
    {
        if (!Capabilities::can_manage_users()) {
            wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'));
        }

        $searched = isset($_GET['ims_tc_search']);
        $filters  = self::read_filters();
        ?>
        <div class="wrap ims-admin">
            <h1 class="wp-heading-inline">打刻ログ照会</h1>
            <hr class="wp-header-end">
            <p class="ims-sub">労働基準監督署の調査対応・打刻の疑義確認・自動補完や修正履歴の証跡確認のための監査用画面です。日常的な打刻修正は各自の打刻コンソールをご利用ください。</p>

            <?php self::render_search_form($filters); ?>

            <?php if ($searched) : ?>
                <?php self::render_results($filters); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @return array{name:string, employee_code:string, date_from:string, date_to:string,
     *               punch_type:string, is_auto_filled:string, has_correction:string}
     */
    private static function read_filters(): array
    {
        return [
            'name'           => sanitize_text_field(wp_unslash($_GET['name'] ?? '')),
            'employee_code'  => sanitize_text_field(wp_unslash($_GET['employee_code'] ?? '')),
            'date_from'      => self::sanitize_date($_GET['date_from'] ?? ''),
            'date_to'        => self::sanitize_date($_GET['date_to'] ?? ''),
            'punch_type'     => sanitize_text_field(wp_unslash($_GET['punch_type'] ?? '')),
            'is_auto_filled' => sanitize_text_field(wp_unslash($_GET['is_auto_filled'] ?? '')),
            'has_correction' => sanitize_text_field(wp_unslash($_GET['has_correction'] ?? '')),
        ];
    }

    private static function sanitize_date(mixed $value): string
    {
        $value = sanitize_text_field(wp_unslash((string) $value));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private static function render_search_form(array $filters): void
    {
        ?>
        <form method="get" class="ims-list-filter ims-tc-search-form">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
            <input type="hidden" name="ims_tc_search" value="1">

            <label>社員名
                <input type="text" name="name" value="<?php echo esc_attr($filters['name']); ?>" placeholder="例：弘前 太郎">
            </label>
            <label>社員番号
                <input type="text" name="employee_code" value="<?php echo esc_attr($filters['employee_code']); ?>">
            </label>
            <label>対象期間（開始）
                <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>">
            </label>
            <label>対象期間（終了）
                <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>">
            </label>
            <label>打刻の種類
                <select name="punch_type">
                    <option value="">指定なし</option>
                    <?php foreach (self::PUNCH_TYPE_LABELS as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['punch_type'], $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>自動補完
                <select name="is_auto_filled">
                    <option value="">指定なし</option>
                    <option value="1" <?php selected($filters['is_auto_filled'], '1'); ?>>自動補完のみ</option>
                    <option value="0" <?php selected($filters['is_auto_filled'], '0'); ?>>通常打刻のみ</option>
                </select>
            </label>
            <label>修正履歴
                <select name="has_correction">
                    <option value="">指定なし</option>
                    <option value="1" <?php selected($filters['has_correction'], '1'); ?>>あり</option>
                    <option value="0" <?php selected($filters['has_correction'], '0'); ?>>なし</option>
                </select>
            </label>

            <button type="submit" class="button button-primary">検索する</button>
        </form>
        <?php
    }

    private static function render_results(array $filters): void
    {
        $name_ids = null;
        if ($filters['name'] !== '') {
            $found = get_users([
                'search'         => '*' . $filters['name'] . '*',
                'search_columns' => ['display_name'],
                'fields'         => 'ID',
            ]);
            $name_ids = array_map('intval', $found);
        }

        $code_ids = null;
        if ($filters['employee_code'] !== '') {
            $uid = EmployeeRepository::user_id_by_employee_code($filters['employee_code']);
            $code_ids = $uid !== null ? [$uid] : [];
        }

        $user_ids = null; // null = 絞り込みなし（全社員）
        if ($name_ids !== null && $code_ids !== null) {
            $user_ids = array_values(array_intersect($name_ids, $code_ids));
        } elseif ($name_ids !== null) {
            $user_ids = $name_ids;
        } elseif ($code_ids !== null) {
            $user_ids = $code_ids;
        }

        // 社員名・社員番号を指定したのに該当者が0人なら、検索するまでもなく0件。
        if ($user_ids !== null && $user_ids === []) {
            self::render_results_table([], 0, 500);
            return;
        }

        $search_filters = [
            'user_ids'       => $user_ids,
            'date_from'      => $filters['date_from'] ?: null,
            'date_to'        => $filters['date_to'] ?: null,
            'punch_type'     => $filters['punch_type'] ?: null,
            'is_auto_filled' => $filters['is_auto_filled'] === '' ? null : $filters['is_auto_filled'] === '1',
            'has_correction' => $filters['has_correction'] === '' ? null : $filters['has_correction'] === '1',
        ];

        $result = Repository::search_logs($search_filters);
        self::render_results_table($result['rows'], $result['total'], 500);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function render_results_table(array $rows, int $total, int $limit): void
    {
        // 表示に使う社員名をまとめて解決（行ごとに get_userdata を呼ばない）。
        $user_ids = array_values(array_unique(array_map(static fn(array $r): int => $r['user_id'], $rows)));
        $names = [];
        if ($user_ids !== []) {
            foreach (get_users(['include' => $user_ids, 'fields' => ['ID', 'display_name']]) as $u) {
                $names[(int) $u->ID] = $u->display_name;
            }
        }
        ?>
        <p class="ims-count">
            <?php echo esc_html(sprintf('%d件', $total)); ?>
            <?php if ($total > $limit) : ?>
                <span class="ims-tc-limit-note">（最新<?php echo esc_html((string) $limit); ?>件のみ表示しています。絞り込み条件を追加してください）</span>
            <?php endif; ?>
        </p>

        <table class="wp-list-table widefat fixed striped ims-tc-log-table">
            <thead>
                <tr>
                    <th>社員名</th>
                    <th>対象日</th>
                    <th>打刻の種類</th>
                    <th>打刻時刻</th>
                    <th>自動補完</th>
                    <th>GPS</th>
                    <th>接続元IP</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []) : ?>
                    <tr><td colspan="8" class="ims-empty">該当する打刻はありません。</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row) : ?>
                    <?php self::render_row($row, $names[$row['user_id']] ?? '（不明なユーザー）'); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_row(array $row, string $employee_name): void
    {
        $time = date('H:i', strtotime($row['punched_at']));
        $type_label = self::PUNCH_TYPE_LABELS[$row['punch_type']] ?? $row['punch_type'];
        ?>
        <tr>
            <td><?php echo esc_html($employee_name); ?></td>
            <td><?php echo esc_html($row['work_date']); ?></td>
            <td><?php echo esc_html($type_label); ?></td>
            <td>
                <?php echo esc_html($time); ?>
                <?php if ($row['has_correction']) : ?>
                    <span class="ims-chip ims-chip-off">修正あり</span>
                <?php endif; ?>
            </td>
            <td><?php echo $row['is_auto_filled'] ? '○' : '—'; ?></td>
            <td><?php echo $row['has_gps'] ? '有' : '無'; ?></td>
            <td><?php echo esc_html($row['ip_address'] ?? '—'); ?></td>
            <td>
                <button type="button" class="button button-small ims-tc-history-toggle"
                        data-log-id="<?php echo esc_attr((string) $row['log_id']); ?>">履歴</button>
                <button type="button" class="button button-small ims-tc-correct-btn"
                        data-log-id="<?php echo esc_attr((string) $row['log_id']); ?>"
                        data-punch-type="<?php echo esc_attr($type_label); ?>"
                        data-punched-at="<?php echo esc_attr($row['punched_at']); ?>"
                        data-employee="<?php echo esc_attr($employee_name); ?>"
                        data-work-date="<?php echo esc_attr($row['work_date']); ?>">修正</button>
            </td>
        </tr>
        <tr class="ims-tc-history-row" data-log-id="<?php echo esc_attr((string) $row['log_id']); ?>" hidden>
            <td colspan="8">
                <?php self::render_history_detail($row); ?>
            </td>
        </tr>
        <?php
    }

    private static function render_history_detail(array $row): void
    {
        if (!$row['has_correction']) {
            echo '<p class="ims-sub">修正履歴はありません。</p>';
            return;
        }

        $corrections = Repository::corrections_for_log($row['log_id']);
        if ($corrections === []) {
            echo '<p class="ims-sub">修正履歴はありません。</p>';
            return;
        }
        ?>
        <table class="ims-tc-correction-table">
            <thead>
                <tr>
                    <th>修正日時</th>
                    <th>修正前</th>
                    <th>修正後</th>
                    <th>修正者</th>
                    <th>理由</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($corrections as $c) :
                    $corrector = get_userdata($c['corrected_by']);
                    ?>
                    <tr>
                        <td><?php echo esc_html($c['corrected_at']); ?></td>
                        <td><?php echo $c['original_datetime'] !== null ? esc_html($c['original_datetime']) : '（新規追加）'; ?></td>
                        <td><?php echo esc_html($c['corrected_datetime']); ?></td>
                        <td><?php echo esc_html($corrector ? $corrector->display_name : '（不明）'); ?></td>
                        <td><?php echo esc_html($c['reason']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
