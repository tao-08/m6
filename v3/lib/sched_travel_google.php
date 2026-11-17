<?php
declare(strict_types=1);

/**
 * =====================================================================
 *  sched_travel_google.php — Google Maps（Routes API）で、2点間の移動時間（分）を調べる
 * =====================================================================
 *  キーは YouTube / Drive と同じ Google の API キー（config.php の youtube.api_key。drive.api_key があればそちら）
 *    ※ Google Cloud Console で同じプロジェクトの「Routes API」を有効にし、キーの「APIの制限」に Routes API を足す
 *  電車（TRANSIT）で調べ、経路が無ければ徒歩（WALK）。出発は「次の平日の昼」（深夜は電車が無く、結果がぶれるため）
 *  使うのは設定画面のボタンを押したときだけ（日程調整の画面では呼ばない = 料金・待ち時間が増えない）
 * =====================================================================
 */

function sched_google_key(): string
{
    return (string)((config('drive')['api_key'] ?? '') ?: (config('youtube')['api_key'] ?? ''));
}

/** 次の平日の 12:00（日本時間）を RFC3339（UTC）で。電車の時刻表がある時間帯で聞くため */
function sched_google_departure(?DateTimeImmutable $now = null): string
{
    $d = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->setTimezone(new DateTimeZone('Asia/Tokyo'))->setTime(12, 0);
    do {
        $d = $d->modify('+1 day');
    } while ((int)$d->format('N') >= 6);
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

/** Routes API の返事の duration（"1234s"）→ 秒。読めなければ null */
function sched_google_seconds(array $json): ?int
{
    $dur = $json['routes'][0]['duration'] ?? null;
    return is_string($dur) && preg_match('/^(\d+)(?:\.\d+)?s$/', $dur, $m) ? (int)$m[1] : null;
}

/** 秒 → 分（1分単位に切り上げ。5分でも「10分」にしない代わりに、1秒でも超えたら次の分） */
function sched_google_minutes(int $sec): int
{
    return (int)ceil($sec / 60);
}

/**
 * 2点（[緯度, 経度]）の移動時間（分。1分単位）。経路が見つからなければ null
 * @throws RuntimeException キー未設定・通信やAPIのエラー（画面に出してよい日本語）
 */
function sched_google_travel(array $from, array $to): ?int
{
    $key = sched_google_key();
    if ($key === '') {
        throw new RuntimeException('config.php に Google の API キー（youtube.api_key）が設定されていません');
    }
    foreach (['TRANSIT', 'WALK'] as $mode) {
        $body = [
            'origin'      => ['location' => ['latLng' => ['latitude' => $from[0], 'longitude' => $from[1]]]],
            'destination' => ['location' => ['latLng' => ['latitude' => $to[0], 'longitude' => $to[1]]]],
            'travelMode'  => $mode,
            'languageCode' => 'ja-JP',
        ];
        if ($mode === 'TRANSIT') {
            $body['departureTime'] = sched_google_departure();
        }
        $ch = curl_init('https://routes.googleapis.com/directions/v2:computeRoutes');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['content-type: application/json', 'X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: routes.duration'],
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false) {
            throw new RuntimeException('Google に接続できませんでした。時間をおいて試してください');
        }
        $json = json_decode((string)$raw, true);
        if ($status === 403 || $status === 401) {
            throw new RuntimeException('Google の API キーで Routes API が使えません（Routes API の有効化と、キーの「APIの制限」を確認してください）');
        }
        if ($status >= 400) {
            throw new RuntimeException('Google の API エラー（' . $status . '）');
        }
        $sec = is_array($json) ? sched_google_seconds($json) : null;
        if ($sec !== null) {
            return sched_google_minutes($sec);
        }
    }
    return null;
}
