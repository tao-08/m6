<?php
declare(strict_types=1);

/** 裏掲示板の共通認証。合言葉のハッシュは本番 config.php または環境変数に置く。 */
function secret_board_entry_hash(): string
{
    $env = getenv('ABBEY_SECRET_BOARD_HASH');
    $hash = is_string($env) && $env !== '' ? $env : (string)(config('secret_board_hash') ?? '');
    return $hash;
}

function secret_board_entry_unlocked(array $user): bool
{
    return !empty($_SESSION['secret_board_entry'])
        && (int)($_SESSION['secret_board_entry_user'] ?? 0) === (int)$user['user_id'];
}

function secret_board_require_entry(array $user): void
{
    if (!secret_board_entry_unlocked($user)) {
        redirect('secret_board');
    }
}

function secret_board_rate_limited(string $key): bool
{
    $now = time();
    $data = $_SESSION['secret_board_attempts'][$key] ?? ['count' => 0, 'until' => 0];
    if ((int)$data['until'] < $now) {
        unset($_SESSION['secret_board_attempts'][$key]);
        return false;
    }
    return (int)$data['count'] >= 5;
}

function secret_board_record_failure(string $key): void
{
    $now = time();
    $data = $_SESSION['secret_board_attempts'][$key] ?? ['count' => 0, 'until' => $now + 900];
    if ((int)$data['until'] < $now) {
        $data = ['count' => 0, 'until' => $now + 900];
    }
    $data['count']++;
    $_SESSION['secret_board_attempts'][$key] = $data;
}

function secret_board_clear_failures(string $key): void
{
    unset($_SESSION['secret_board_attempts'][$key]);
}

function secret_board_thread_unlocked(array $user, int $threadId): bool
{
    return !empty($_SESSION['secret_board_threads'][$threadId])
        && (int)($_SESSION['secret_board_threads'][$threadId]) === (int)$user['user_id'];
}

function secret_board_unlock_thread(array $user, int $threadId): void
{
    $_SESSION['secret_board_threads'][$threadId] = (int)$user['user_id'];
}
