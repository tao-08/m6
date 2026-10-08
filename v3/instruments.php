<?php
/**
 * instruments.php — 以前の「楽器の管理」のページ。
 * 会場・日程名・楽器の管理は masters.php の1ページにまとめたので、そのタブへ移動するだけ（古いリンク・ブックマーク用）
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_admin();
redirect('masters?tab=instrument');
