<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 確定（最終承認）の取り消し（03 §3.5「確定の取り消し」。3n-3）の入力チェックと、
 * 取り消し後に wp_monthly_summary へ書き込む値を決める純粋ロジック。DB/WordPressに触れない。
 * 権限（人事管理担当者以上・自己取り消しの禁止）は MonthlySummaryService 側で見る。
 */
final class ConfirmationCancelCalculator
{
    /** 取り消し後の戻し先（本人に差し戻す／最終承認だけやり直す）。 */
    public const RETURN_TARGETS = [
        MonthlySummaryCalculator::REJECTED_BY_ADMIN,
        MonthlySummaryCalculator::CHECKED,
    ];

    /**
     * 入力チェック。問題なければ null、あれば [エラーコード, 画面に出す文言]。
     *
     * @return array{0:string, 1:string}|null
     */
    public static function validate(bool $allowed, string $current_status, string $returned_status, string $reason): ?array
    {
        if (!$allowed) {
            return ['disabled', '確定の取り消しは現在許可されていません（ポータル設定で変更できます）。'];
        }
        if ($current_status !== MonthlySummaryCalculator::CONFIRMED) {
            return ['invalid_status', 'この月度は確定済みではないため、取り消せません。'];
        }
        if (!in_array($returned_status, self::RETURN_TARGETS, true)) {
            return ['validation', '取り消したあとの戻し先を選んでください。'];
        }
        if (trim($reason) === '') {
            return ['validation', '取り消す理由を入力してください。'];
        }
        return null;
    }

    /**
     * wp_monthly_summary に書き込む値。最終承認の記録と給与スナップショットは空に戻す
     * （次の最終承認で記録し直す）。本人に差し戻す場合は、理由を差し戻し理由として本人の画面にも出す。
     *
     * @return array<string, mixed>
     */
    public static function summary_update(string $returned_status, string $reason, int $actor_id, string $now): array
    {
        $data = [
            'status'               => $returned_status,
            'final_approved_by'    => null,
            'final_approved_at'    => null,
            'snapshot_base_salary' => null,
            'snapshot_allowances'  => null,
        ];
        if ($returned_status === MonthlySummaryCalculator::REJECTED_BY_ADMIN) {
            $data['last_rejected_by']  = $actor_id;
            $data['last_rejected_at']  = $now;
            $data['rejection_comment'] = trim($reason);
        }
        return $data;
    }

    /**
     * 取り消し記録（wp_monthly_confirmation_cancellations）の1行。取り消し前の値を写して残す。
     *
     * @param array<string, mixed> $summary 取り消し前の wp_monthly_summary の行
     * @return array<string, mixed>
     */
    public static function log_row(array $summary, string $returned_status, string $reason, int $actor_id, string $now): array
    {
        return [
            'monthly_summary_id'        => (int) $summary['id'],
            'user_id'                   => (int) $summary['user_id'],
            'target_year_month'         => (string) $summary['target_year_month'],
            'returned_status'           => $returned_status,
            'cancel_reason'             => trim($reason),
            'cancelled_by'              => $actor_id,
            'cancelled_at'              => $now,
            'prev_final_approved_by'    => $summary['final_approved_by'] ?? null,
            'prev_final_approved_at'    => $summary['final_approved_at'] ?? null,
            'prev_snapshot_base_salary' => $summary['snapshot_base_salary'] ?? null,
            'prev_snapshot_allowances'  => $summary['snapshot_allowances'] ?? null,
        ];
    }

    /** 戻し先の画面表示名。 */
    public static function return_label(string $returned_status): string
    {
        return match ($returned_status) {
            MonthlySummaryCalculator::REJECTED_BY_ADMIN => '本人に差し戻す（本人が直して再提出する）',
            MonthlySummaryCalculator::CHECKED           => '最終承認だけやり直す（チェック済みに戻す）',
            default                                     => $returned_status,
        };
    }
}
