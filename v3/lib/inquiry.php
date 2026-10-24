<?php
/**
 * 問い合わせのメール通知（contact.php・contact_guest.php で共通）
 */

/**
 * 通知先を登録している管理者全員にメールを送る。1人以上に送れたら true
 *   件名・差出人は固定の文字だけで作る（ユーザーの入力をヘッダーに入れると、改行を混ぜて
 *   宛先を勝手に足される「メールヘッダーインジェクション」ができてしまう）。入力は本文にだけ入れる
 *   $sender は本文に書く送信元の表記（匿名なら '匿名'）
 */
function send_inquiry_mail(PDO $pdo, string $sender, string $category, string $body): bool
{
    $from = (string)(config('mail_from') ?? '');
    if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    $to = $pdo->query('SELECT email FROM user_account WHERE is_admin = 1 AND email IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    if (!$to) {
        return false;
    }

    $subject = '【AbbeyRoad.online】問い合わせ・要望が届きました';
    $text = "種類: {$category}\n"
        . "送信元: {$sender}\n"
        . '日時: ' . date('Y-m-d H:i') . "\n\n"
        . $body . "\n";
    mb_language('uni'); // UTF-8 で送る
    $sent = false;
    foreach ($to as $addr) {
        // 1人ずつ送る（To に全員並べると、管理者同士のアドレスが見えてしまう）
        // From の「Abbey Road」はメールソフトに出る送信者名。
        // -f で封筒の差出人（Return-Path）も $from にする。付けないとサーバーの内部アドレスになり、SPF が合わずに捨てられやすい
        $sent = @mb_send_mail($addr, $subject, $text, 'From: Abbey Road <' . $from . '>', '-f' . $from) || $sent;
    }
    return $sent;
}
