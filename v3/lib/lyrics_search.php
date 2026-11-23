<?php
/**
 * 歌詞フレーズ検索の正規化・照合（歌詞本文は扱わない）。
 */
declare(strict_types=1);

/** 表記比較用の正規化。歌詞の意味や漢字の読みを推測する処理ではない。 */
function lyrics_search_normalize_text(string $value): string
{
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
        if (is_string($normalized)) {
            $value = $normalized;
        }
    }
    $value = mb_convert_kana($value, 'asKV', 'UTF-8');
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/[\p{P}\p{S}\p{Z}\s]+/u', '', $value) ?? $value;
    return $value;
}

/** 全角カタカナをひらがなに変換する。漢字の読みは推測しない。 */
function lyrics_search_to_hiragana(string $value): string
{
    return preg_replace_callback(
        '/[\x{30A1}-\x{30F6}\x{30FD}\x{30FE}]/u',
        static function (array $match): string {
            $codepoint = mb_ord($match[0], 'UTF-8');
            return mb_chr($codepoint - 0x60, 'UTF-8');
        },
        $value
    ) ?? $value;
}

/**
 * APIに投げる検索語候補。原文を優先し、正規化・句読点除去・かな変換は必要時の予備にする。
 * 同一検索語はまとめ、最大3パターンに制限する。
 *
 * @return string[]
 */
function lyrics_search_query_variants(string $query): array
{
    $variants = [];
    $add = static function (string $value) use (&$variants): void {
        $value = trim($value);
        if ($value !== '' && !in_array($value, $variants, true)) {
            $variants[] = $value;
        }
    };

    $add($query);

    $normalized = $query;
    if (class_exists('Normalizer')) {
        $nfkc = Normalizer::normalize($normalized, Normalizer::FORM_KC);
        if (is_string($nfkc)) {
            $normalized = $nfkc;
        }
    }
    $normalized = mb_convert_kana($normalized, 'asKV', 'UTF-8');
    $withoutPunctuation = preg_replace('/[\p{P}\p{S}]+/u', ' ', $normalized) ?? $normalized;
    $withoutPunctuation = preg_replace('/[\p{Z}\s]+/u', ' ', trim($withoutPunctuation)) ?? trim($withoutPunctuation);
    $add($withoutPunctuation);

    // 句読点を整理した後にかな変換する。原文・正規化・かな変換の最大3段階。
    $hiragana = lyrics_search_to_hiragana($withoutPunctuation);
    $add($hiragana);

    return array_slice($variants, 0, 3);
}

/** 曲名・アーティスト名の照合。短い名前の部分一致は誤検出しやすいため避ける。 */
function lyrics_search_field_matches(string $actual, string $expected): bool
{
    $a = lyrics_search_normalize_text($actual);
    $e = lyrics_search_normalize_text($expected);
    if ($a === '' || $e === '') {
        return false;
    }
    if ($a === $e) {
        return true;
    }
    if (mb_strlen($e, 'UTF-8') < 3) {
        return false;
    }
    return str_contains($a, $e) || str_contains($e, $a);
}

/** 歌詞検索結果が期待する曲かを判定する。 */
function lyrics_search_hit_matches(array $hit, string $expectedTitle, string $expectedArtist): bool
{
    return $expectedTitle !== '' && $expectedArtist !== ''
        && lyrics_search_field_matches((string)($hit['title'] ?? ''), $expectedTitle)
        && lyrics_search_field_matches((string)($hit['artist'] ?? ''), $expectedArtist);
}

/** ISRCがあればそれを識別子にし、無ければ曲名・アーティストで重複を除く。 */
function lyrics_search_hit_key(array $hit): string
{
    $isrc = strtoupper(trim((string)($hit['isrc'] ?? '')));
    if ($isrc !== '') {
        return 'isrc:' . $isrc;
    }
    return 'meta:' . lyrics_search_normalize_text((string)($hit['title'] ?? ''))
        . ':' . lyrics_search_normalize_text((string)($hit['artist'] ?? ''));
}
