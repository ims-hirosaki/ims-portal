<?php

if (!defined('ABSPATH')) {
    exit;
}

use IMS\Core\Layout;

Layout::render_header('ページが見つかりません');
?>
<div class="card" style="max-width:480px;margin:40px auto;">
    <div class="card-body">
        <p><?php esc_html_e('お探しのページは見つかりませんでした。', 'ims-portal'); ?></p>
        <p><a class="btn-ghost-u" href="<?php echo esc_url(home_url('/portal/')); ?>"><?php esc_html_e('ホームへ戻る', 'ims-portal'); ?></a></p>
    </div>
</div>
<?php
Layout::render_footer();
