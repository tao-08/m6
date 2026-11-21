<?php
declare(strict_types=1);

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
