<?php

declare(strict_types=1);

namespace IMS\Module\User\Csv;

use IMS\Module\User\EmployeeRepository;
use IMS\Module\User\MasterRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * アカウント一括取り込み（01_user_management.md §3.1「取り込み仕様」）。
 *
 * 方針：
 * ・文字コード UTF-8（BOM 許容）。取込キーは employee_code。既存＝更新／新規＝作成。
 * ・行単位バリデーション。エラー行はスキップし、有効行のみ取り込む。結果をサマリー返却。
 * ・任意列が空欄のときは「変更しない」（既存値を維持）。※ 運用確定事項
 * ・auth_type=google_sso はメールが @ims-hirosaki.com 必須。
 * ・auth_type=password の新規作成時は仮パスワードを自動生成してメール送信する。
 * ・承認者（P/Q）は社員番号参照。取り込み内での前後関係に強くするため 2 パスで解決する
 *   （1パス目で全行の社員を作成/更新し code→id を確定 → 2パス目で承認者を設定）。
 *
 * 検証の一部（CSVパース・列マッピング・形式チェック）は WordPress 非依存に切り出し、
 * 単体テスト可能にしている。
 */
final class CsvImporter
{
    /** @var array<string, array<string, int>> type => (code|name) => id */
    private array $master_index = [];

    /**
     * 取り込みを実行し、結果サマリーを返す。
     *
     * @return array{
     *   total:int, created:int, updated:int, emails_sent:int,
     *   errors:array<int,array{row:int,code:string,messages:array<int,string>}>,
     *   warnings:array<int,array{row:int,code:string,messages:array<int,string>}>,
     *   fatal:?string
     * }
     */
    public static function import(string $file_path, int $operator_id): array
    {
        $self = new self();
        return $self->run($file_path, $operator_id);
    }

    private function run(string $file_path, int $operator_id): array
    {
        $result = [
            'total'       => 0,
            'created'     => 0,
            'updated'     => 0,
            'emails_sent' => 0,
            'errors'      => [],
            'warnings'    => [],
            'fatal'       => null,
        ];

        $raw = @file_get_contents($file_path);
        if ($raw === false || $raw === '') {
            $result['fatal'] = 'ファイルを読み込めませんでした。';
            return $result;
        }

        $rows = self::parse_csv($raw);
        if ($rows === []) {
            $result['fatal'] = 'データ行がありません。';
            return $result;
        }

        // 先頭行がヘッダなら除外（社員番号 / employee_code で判定）
        $first = $rows[0][0] ?? '';
        $header_offset = 0;
        if (in_array(trim($first), ['社員番号', 'employee_code'], true)) {
            $header_offset = 1;
        }

        $can_view_salary = current_user_can('ims_view_salary');
        $this->build_master_index();

        // ── 1パス目：社員の作成/更新（承認者以外） ──
        $keys = CsvSchema::keys();
        $pending_approvers = []; // ['row'=>int,'user_id'=>int,'first'=>string,'final'=>string]
        $code_to_uid = [];       // 取り込み後の code → user_id

        foreach ($rows as $i => $cells) {
            if ($i < $header_offset) {
                continue;
            }
            $line = $i + 1; // 表示用の行番号（1始まり、ヘッダ含む）
            $result['total']++;

            // 17列にそろえる
            $cells = array_pad(array_slice($cells, 0, 17), 17, '');
            $cells = array_map(static fn ($v): string => trim((string) $v), $cells);
            $data  = array_combine($keys, $cells);

            $errors = [];
            $fields = $this->validate_row($data, $can_view_salary, $errors);

            if ($errors !== []) {
                $result['errors'][] = ['row' => $line, 'code' => (string) $data['employee_code'], 'messages' => $errors];
                continue;
            }

            $apply = $this->apply_employee($fields, $operator_id, $can_view_salary, $result);
            if (is_string($apply)) { // 適用時エラー（作成失敗等）
                $result['errors'][] = ['row' => $line, 'code' => (string) $data['employee_code'], 'messages' => [$apply]];
                continue;
            }

            $uid = $apply['user_id'];
            $code_to_uid[$fields['employee_code']] = $uid;

            if ($apply['created']) {
                $result['created']++;
                if ($fields['auth_type'] === 'password' && $apply['temp_password'] !== null) {
                    if ($this->send_temp_password_mail($uid, $apply['temp_password'])) {
                        $result['emails_sent']++;
                    } else {
                        $result['warnings'][] = ['row' => $line, 'code' => $fields['employee_code'], 'messages' => ['仮パスワードのメール送信に失敗しました。手動で通知してください。']];
                    }
                }
            } else {
                $result['updated']++;
            }

            if ($fields['first_approver_code'] !== '' || $fields['final_approver_code'] !== '') {
                $pending_approvers[] = [
                    'row'     => $line,
                    'user_id' => $uid,
                    'code'    => $fields['employee_code'],
                    'first'   => $fields['first_approver_code'],
                    'final'   => $fields['final_approver_code'],
                ];
            }
        }

        // ── 2パス目：承認者の解決・設定 ──
        foreach ($pending_approvers as $pa) {
            $notes = [];
            if ($pa['first'] !== '') {
                $fid = $this->resolve_user_code($pa['first'], $code_to_uid);
                if ($fid === null) {
                    $notes[] = sprintf('確認者の社員番号「%s」が見つからないため未設定にしました。', $pa['first']);
                } elseif ($fid === $pa['user_id']) {
                    $notes[] = '確認者に本人が指定されていたため未設定にしました。';
                } else {
                    update_user_meta($pa['user_id'], 'first_approver_id', $fid);
                }
            }
            if ($pa['final'] !== '') {
                $lid = $this->resolve_user_code($pa['final'], $code_to_uid);
                if ($lid === null) {
                    $notes[] = sprintf('最終承認者の社員番号「%s」が見つからないため未設定にしました。', $pa['final']);
                } elseif ($lid === $pa['user_id']) {
                    $notes[] = '最終承認者に本人が指定されていたため未設定にしました。';
                } else {
                    update_user_meta($pa['user_id'], 'final_approver_id', $lid);
                }
            }
            if ($notes !== []) {
                $result['warnings'][] = ['row' => $pa['row'], 'code' => $pa['code'], 'messages' => $notes];
            }
        }

        return $result;
    }

    // ── バリデーション ─────────────────────────────────────

    /**
     * 1行を検証し、正規化済みフィールドを返す。エラーは $errors に追記する。
     *
     * @param array<string,string> $data 列キー => 値
     * @param array<int,string>    $errors
     * @return array<string,mixed> 正規化済みフィールド
     */
    private function validate_row(array $data, bool $can_view_salary, array &$errors): array
    {
        $f = [];

        // 必須（A〜E）
        foreach (['employee_code' => '社員番号', 'last_name' => '姓', 'first_name' => '名', 'email' => 'メールアドレス', 'auth_type' => '認証方式'] as $k => $label) {
            if (($data[$k] ?? '') === '') {
                $errors[] = sprintf('%s（必須）が空です。', $label);
            }
        }
        $f['employee_code'] = $data['employee_code'];
        $f['last_name']     = $data['last_name'];
        $f['first_name']    = $data['first_name'];
        $f['email']         = strtolower($data['email']);

        // 認証方式
        $auth = $data['auth_type'];
        if ($auth !== '' && !CsvSchema::is_valid_auth_type($auth)) {
            $errors[] = sprintf('認証方式「%s」は不正です（google_sso または password）。', $auth);
        }
        $f['auth_type'] = $auth;

        // メール形式・ドメイン
        if ($f['email'] !== '') {
            if (!is_email($f['email'])) {
                $errors[] = sprintf('メールアドレス「%s」の形式が不正です。', $f['email']);
            } elseif ($auth === 'google_sso' && !str_ends_with($f['email'], CsvSchema::GOOGLE_DOMAIN)) {
                $errors[] = sprintf('google_sso のメールは %s ドメインである必要があります。', CsvSchema::GOOGLE_DOMAIN);
            }
        }

        // マスタ解決（コード：所属・部署 / 名称：役職・職種・雇用形態）※空欄は「変更しない」
        $f['affiliation_id']     = $this->resolve_master('affiliation', 'code', $data['affiliation_code'], '所属コード', $errors);
        $f['department_id']      = $this->resolve_master('department', 'code', $data['department_code'], '部署コード', $errors);
        $f['position_id']        = $this->resolve_master('position', 'name', $data['position_name'], '役職名', $errors);
        $f['job_type_id']        = $this->resolve_master('job_type', 'name', $data['job_type_name'], '職種名', $errors);
        $f['employment_type_id'] = $this->resolve_master('employment_type', 'name', $data['employment_type_name'], '雇用形態名', $errors);

        // 在籍状況（空欄可）
        $status = $data['employment_status'];
        if ($status !== '' && !in_array($status, EmployeeRepository::EMPLOYMENT_STATUSES, true)) {
            $errors[] = sprintf('在籍状況「%s」は不正です。', $status);
        }
        $f['employment_status'] = $status;

        // 数値（空欄可）
        $f['scheduled_work_hours'] = $this->validate_number($data['scheduled_work_hours'], '所定労働時間', $errors);
        $f['travel_unit_price']    = $this->validate_number($data['travel_unit_price'], '交通費単価', $errors);
        $f['commute_one_way_km']   = $this->validate_number($data['commute_one_way_km'], '通勤片道距離', $errors);

        // 基本給（機微・整数）：閲覧権限がない操作者は無視
        $salary_raw = $data['base_salary'];
        if ($salary_raw !== '' && !is_numeric($salary_raw)) {
            $errors[] = '基本給は数値で入力してください。';
        }
        $f['base_salary'] = $salary_raw;
        if ($salary_raw !== '' && !$can_view_salary) {
            // 権限がない場合は取り込まない（エラーにはせず後段で無視＋警告）
            $f['base_salary'] = '';
            $f['_salary_skipped'] = true;
        }

        // 承認者コード（2パス目で解決）
        $f['first_approver_code'] = $data['first_approver_code'];
        $f['final_approver_code'] = $data['final_approver_code'];

        return $f;
    }

    private function validate_number(string $raw, string $label, array &$errors): string
    {
        if ($raw === '') {
            return '';
        }
        if (!is_numeric($raw)) {
            $errors[] = sprintf('%sは数値で入力してください（「%s」）。', $label, $raw);
            return '';
        }
        return $raw;
    }

    /**
     * マスタ解決。空欄は 0（＝変更しない）。値ありで未解決はエラー。
     * @return int 解決した id（0＝空欄で「変更しない」）
     */
    private function resolve_master(string $type, string $by, string $value, string $label, array &$errors): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $key = $by === 'name' ? strtolower($value) : $value;
        $id  = $this->master_index[$type][$key] ?? null;
        if ($id === null) {
            $errors[] = sprintf('%s「%s」に一致するマスタが見つかりません。', $label, $value);
            return 0;
        }
        return $id;
    }

    // ── 適用（作成/更新） ───────────────────────────────────

    /**
     * 社員を作成または更新する（承認者以外）。
     *
     * @param array<string,mixed> $f
     * @return array{user_id:int,created:bool,temp_password:?string}|string 失敗時はエラーメッセージ
     */
    private function apply_employee(array $f, int $operator_id, bool $can_view_salary, array &$result)
    {
        $existing = EmployeeRepository::user_id_by_employee_code($f['employee_code']);
        $created  = false;
        $temp_password = null;

        if ($existing === null) {
            // ── 新規作成 ──
            // メール重複（社員番号未登録なのにメールが既存）はエラー
            if (get_user_by('email', $f['email'])) {
                return sprintf('メールアドレス「%s」は既に別ユーザーで使用されています。', $f['email']);
            }
            $login = $this->unique_login_from_email($f['email']);
            $temp_password = wp_generate_password(16, true, true);

            $uid = wp_insert_user([
                'user_login'   => $login,
                'user_email'   => $f['email'],
                'user_pass'    => $temp_password,
                'first_name'   => $f['first_name'],
                'last_name'    => $f['last_name'],
                'display_name' => trim($f['last_name'] . ' ' . $f['first_name']),
                'role'         => \IMS\Core\Roles::ROLE_GENERAL_STAFF,
            ]);
            if (is_wp_error($uid)) {
                return 'アカウント作成に失敗しました：' . $uid->get_error_message();
            }
            $uid = (int) $uid;
            $created = true;

            update_user_meta($uid, 'employee_code', $f['employee_code']);
            update_user_meta($uid, 'auth_type', $f['auth_type']);
        } else {
            // ── 既存更新 ──
            $uid = $existing;
            wp_update_user([
                'ID'           => $uid,
                'user_email'   => $f['email'],
                'first_name'   => $f['first_name'],
                'last_name'    => $f['last_name'],
                'display_name' => trim($f['last_name'] . ' ' . $f['first_name']),
            ]);
            update_user_meta($uid, 'auth_type', $f['auth_type']);
            // ロール・パスワードは更新しない（CSVに含めない）
        }

        // 任意フィールド：空欄は「変更しない」。値ありのみ反映。
        foreach (['affiliation_id', 'department_id', 'position_id', 'job_type_id', 'employment_type_id'] as $key) {
            if ((int) $f[$key] > 0) {
                update_user_meta($uid, $key, (int) $f[$key]);
            }
        }
        if ($f['employment_status'] !== '') {
            update_user_meta($uid, 'employment_status', $f['employment_status']);
        }
        if ($f['scheduled_work_hours'] !== '') {
            update_user_meta($uid, 'scheduled_work_hours', number_format((float) $f['scheduled_work_hours'], 2, '.', ''));
        }
        if ($f['travel_unit_price'] !== '') {
            update_user_meta($uid, 'travel_unit_price', number_format((float) $f['travel_unit_price'], 2, '.', ''));
        }
        if ($f['commute_one_way_km'] !== '') {
            update_user_meta($uid, 'commute_one_way_km', number_format((float) $f['commute_one_way_km'], 2, '.', ''));
        }

        // 基本給：値あり かつ 権限あり のときのみ、現在値と異なれば履歴追記
        if ($f['base_salary'] !== '' && $can_view_salary) {
            $new = (int) $f['base_salary'];
            if ($new !== EmployeeRepository::current_base_salary($uid)) {
                EmployeeRepository::append_salary_history($uid, $new, current_time('Y-m-d'), 'CSV取り込み', $operator_id);
            }
        }

        return ['user_id' => $uid, 'created' => $created, 'temp_password' => $temp_password];
    }

    // ── ヘルパー ───────────────────────────────────────────

    private function resolve_user_code(string $code, array $code_to_uid): ?int
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        if (isset($code_to_uid[$code])) {
            return $code_to_uid[$code];
        }
        return EmployeeRepository::user_id_by_employee_code($code);
    }

    private function unique_login_from_email(string $email): string
    {
        $base = sanitize_user(substr($email, 0, (int) strpos($email, '@')), true);
        if ($base === '') {
            $base = 'user';
        }
        $login = $base;
        $n = 2;
        while (username_exists($login)) {
            $login = $base . '-' . $n;
            $n++;
        }
        return $login;
    }

    private function send_temp_password_mail(int $user_id, string $temp_password): bool
    {
        $u = get_userdata($user_id);
        if (!$u) {
            return false;
        }
        $login_url = home_url('/portal/login/');
        $subject   = '【IMS Hirosaki】アカウント発行のお知らせ';
        $body  = $u->display_name . " 様\n\n";
        $body .= "IMS Hirosaki 業務ポータルのアカウントを発行しました。\n";
        $body .= "下記の情報でログインし、初回ログイン後にパスワードを変更してください。\n\n";
        $body .= 'ログインURL：' . $login_url . "\n";
        $body .= 'ユーザー名：' . $u->user_login . "\n";
        $body .= '仮パスワード：' . $temp_password . "\n\n";
        $body .= "※このメールに心当たりがない場合は、担当の管理者（人事部）までご連絡ください。\n";

        return wp_mail($u->user_email, $subject, $body);
    }

    /**
     * マスタの逆引き索引を構築する。所属・部署はコード、その他は名称（小文字化）で引く。
     */
    private function build_master_index(): void
    {
        $this->master_index = [
            'affiliation'     => [],
            'department'      => [],
            'position'        => [],
            'job_type'        => [],
            'employment_type' => [],
        ];
        foreach (MasterRepository::all('affiliation') as $r) {
            $code = (string) ($r['affiliation_code'] ?? '');
            if ($code !== '') {
                $this->master_index['affiliation'][$code] = (int) $r['id'];
            }
        }
        foreach (MasterRepository::all('department') as $r) {
            $code = (string) ($r['department_code'] ?? '');
            if ($code !== '') {
                $this->master_index['department'][$code] = (int) $r['id'];
            }
        }
        foreach (['position', 'job_type', 'employment_type'] as $type) {
            foreach (MasterRepository::all($type) as $r) {
                $name = (string) ($r['name'] ?? '');
                if ($name !== '') {
                    $this->master_index[$type][strtolower($name)] = (int) $r['id'];
                }
            }
        }
    }

    // ── CSV パース（WordPress 非依存・テスト可能） ────────────

    /**
     * CSV 文字列を二次元配列に変換する。BOM 除去・CRLF/LF 両対応・引用符対応。
     *
     * @return array<int, array<int, string>>
     */
    public static function parse_csv(string $raw): array
    {
        // BOM 除去
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return [];
        }
        fwrite($fh, $raw);
        rewind($fh);

        $rows = [];
        while (($cells = fgetcsv($fh)) !== false) {
            // 完全な空行はスキップ
            if ($cells === [null] || (count($cells) === 1 && ($cells[0] === null || $cells[0] === ''))) {
                continue;
            }
            $rows[] = array_map(static fn ($v): string => $v === null ? '' : (string) $v, $cells);
        }
        fclose($fh);
        return $rows;
    }
}
