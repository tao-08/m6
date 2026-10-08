<?php
declare(strict_types=1);

/**
 * =====================================================================
 *  pdf_words.php — PHP だけで PDF から「単語と座標」を取り出す
 * =====================================================================
 *  本番サーバーは exec() が使えないので pdftotext（poppler）を呼べない。
 *  そこで pdftotext -bbox と同じ形（1単語ごとに xMin/yMin/xMax/yMax）を PHP だけで作る。
 *
 *  対応しているもの（Excel / Word / 「Microsoft Print to PDF」が書き出す PDF で十分な範囲）
 *    - オブジェクトストリーム（ObjStm）・FlateDecode 圧縮
 *    - Type0（Identity-H）フォント + ToUnicode、TrueType / Type1 の1バイトフォント
 *    - Form XObject の中の文字
 *  対応していないもの：暗号化された PDF・スキャン画像の PDF（文字が無い）
 *
 *  座標は pdftotext と同じく「ページ左上が原点、下に行くほど y が大きい」
 *  単語の区切りも pdftotext と同じ考え方：空白の文字・文字と文字の隙間が広いところで区切る
 * =====================================================================
 */

final class PdfName
{
    public function __construct(public string $v) {}
}

final class PdfRef
{
    public function __construct(public int $num) {}
}

final class PdfDict
{
    /** @param array<string, mixed> $e */
    public function __construct(public array $e = []) {}

    public function get(string $key): mixed
    {
        return $this->e[$key] ?? null;
    }
}

final class PdfStream
{
    public function __construct(public PdfDict $dict, public string $raw) {}
}

/** PDF の字句・構文解析（ファイル本体にも、ページの描画命令にも使う） */
final class PdfLexer
{
    public int $pos = 0;
    private int $len;

    public function __construct(public string $s, int $pos = 0)
    {
        $this->pos = $pos;
        $this->len = strlen($s);
    }

    public function skipSpace(): void
    {
        while ($this->pos < $this->len) {
            $c = $this->s[$this->pos];
            if ($c === '%') {
                while ($this->pos < $this->len && $this->s[$this->pos] !== "\n" && $this->s[$this->pos] !== "\r") {
                    $this->pos++;
                }
            } elseif (strpos(" \t\r\n\f\0", $c) !== false) {
                $this->pos++;
            } else {
                return;
            }
        }
    }

    /**
     * 次の値を1つ読む。命令（Tj や obj など）は ['op' => 'Tj'] で返す。終わりなら null
     */
    public function next(): mixed
    {
        $this->skipSpace();
        if ($this->pos >= $this->len) {
            return null;
        }
        $s = $this->s;
        $c = $s[$this->pos];
        if ($c === '/') {
            $this->pos++;
            $start = $this->pos;
            while ($this->pos < $this->len && strpos(" \t\r\n\f\0/[]<>(){}%", $s[$this->pos]) === false) {
                $this->pos++;
            }
            $name = substr($s, $start, $this->pos - $start);
            return new PdfName(preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn($m) => chr(hexdec($m[1])), $name) ?? $name);
        }
        if ($c === '(') {
            return $this->literalString();
        }
        if ($c === '<') {
            if (($s[$this->pos + 1] ?? '') === '<') {
                $this->pos += 2;
                return $this->dict();
            }
            $end = strpos($s, '>', $this->pos);
            $end = $end === false ? $this->len : $end;
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $this->pos + 1, $end - $this->pos - 1)) ?? '';
            $this->pos = $end + 1;
            if (strlen($hex) % 2) {
                $hex .= '0';
            }
            return (string)hex2bin($hex);
        }
        if ($c === '[') {
            $this->pos++;
            $arr = [];
            while (true) {
                $v = $this->next();
                if ($v === null || (is_array($v) && isset($v['op']) && $v['op'] === ']')) {
                    break;
                }
                $arr[] = $v;
            }
            return $this->foldRefs($arr);
        }
        if ($c === ']' || $c === '>' || $c === '{' || $c === '}' || $c === ')') {
            $this->pos += ($c === '>' && ($s[$this->pos + 1] ?? '') === '>') ? 2 : 1;
            return ['op' => $c === '>' ? '>>' : $c];
        }
        if (preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)/', $s, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            return str_contains($m[0], '.') ? (float)$m[0] : (int)$m[0];
        }
        $start = $this->pos;
        while ($this->pos < $this->len && strpos(" \t\r\n\f\0/[]<>(){}%", $s[$this->pos]) === false) {
            $this->pos++;
        }
        if ($this->pos === $start) {
            $this->pos++;
        }
        $word = substr($s, $start, $this->pos - $start);
        return match ($word) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => ['op' => $word],
        };
    }

    private function literalString(): string
    {
        $s = $this->s;
        $this->pos++;
        $out = '';
        $depth = 1;
        while ($this->pos < $this->len) {
            $c = $s[$this->pos++];
            if ($c === '\\') {
                $n = $s[$this->pos++] ?? '';
                $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f"];
                if (isset($map[$n])) {
                    $out .= $map[$n];
                } elseif ($n >= '0' && $n <= '7') {
                    $oct = $n;
                    for ($i = 0; $i < 2 && ($s[$this->pos] ?? '') >= '0' && ($s[$this->pos] ?? '') <= '7'; $i++) {
                        $oct .= $s[$this->pos++];
                    }
                    $out .= chr(octdec($oct) & 0xFF);
                } elseif ($n === "\r") {
                    if (($s[$this->pos] ?? '') === "\n") {
                        $this->pos++;
                    }
                } elseif ($n !== "\n") {
                    $out .= $n;
                }
            } elseif ($c === '(') {
                $depth++;
                $out .= $c;
            } elseif ($c === ')') {
                if (--$depth === 0) {
                    break;
                }
                $out .= $c;
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    private function dict(): PdfDict
    {
        $items = [];
        while (true) {
            $v = $this->next();
            if ($v === null && $this->pos >= $this->len) {
                break;
            }
            if (is_array($v) && isset($v['op']) && $v['op'] === '>>') {
                break;
            }
            $items[] = $v;
        }
        $items = $this->foldRefs($items);
        $d = new PdfDict();
        for ($i = 0; $i + 1 < count($items); $i += 2) {
            if ($items[$i] instanceof PdfName) {
                $d->e[$items[$i]->v] = $items[$i + 1];
            }
        }
        return $d;
    }

    /** 「12 0 R」の3つを PdfRef 1つにまとめる */
    private function foldRefs(array $items): array
    {
        $out = [];
        foreach ($items as $v) {
            $n = count($out);
            if (is_array($v) && isset($v['op']) && $v['op'] === 'R' && $n >= 2 && is_int($out[$n - 1]) && is_int($out[$n - 2])) {
                array_pop($out);
                $out[] = new PdfRef((int)array_pop($out));
                continue;
            }
            $out[] = $v;
        }
        return $out;
    }
}

final class PdfDocument
{
    /** @var array<int, int> オブジェクト番号 => ファイル内の位置 */
    private array $offsets = [];
    /** @var array<int, array{int, int}> オブジェクト番号 => [ObjStm の番号, その中の何番目か] */
    private array $inStream = [];
    private array $cache = [];
    private array $objStmCache = [];

    public function __construct(private string $data)
    {
        if (!str_starts_with(ltrim(substr($data, 0, 1024)), '%PDF') && !str_contains(substr($data, 0, 1024), '%PDF')) {
            throw new RuntimeException('PDF ファイルではありません');
        }
        $this->scanObjects();
    }

    /**
     * xref 表は壊れていることがあるので使わず、ファイルを頭から「n 0 obj」を探して位置を覚える。
     * ストリームの中身は読み飛ばすので、圧縮データの中の偶然の「n 0 obj」は拾わない。
     * 同じ番号が2回出てきたら後ろ（追記された新しい方）を使う。
     */
    private function scanObjects(): void
    {
        $pos = 0;
        $objStms = [];
        while (preg_match('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $this->data, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $m[0][1];
            $num = (int)$m[1][0];
            $lx = new PdfLexer($this->data, $start + strlen($m[0][0]));
            try {
                $obj = $this->readObjectBody($lx);
            } catch (Throwable) {
                $pos = $start + 1;
                continue;
            }
            $this->offsets[$num] = $start;
            unset($this->cache[$num]);
            if ($obj instanceof PdfStream && ($obj->dict->get('Type') instanceof PdfName) && $obj->dict->get('Type')->v === 'ObjStm') {
                $objStms[] = $num;
            }
            $pos = max($lx->pos, $start + 1);
        }
        foreach ($objStms as $stmNum) {
            foreach ($this->objStmIndex($stmNum) as $i => [$num]) {
                if (!isset($this->offsets[$num]) && !isset($this->inStream[$num])) {
                    $this->inStream[$num] = [$stmNum, $i];
                }
            }
        }
    }

    /** 「n 0 obj」の直後から値（とストリームの中身）を読む */
    private function readObjectBody(PdfLexer $lx): mixed
    {
        $obj = $lx->next();
        $save = $lx->pos;
        $lx->skipSpace();
        if ($obj instanceof PdfDict && substr($this->data, $lx->pos, 6) === 'stream') {
            $p = $lx->pos + 6;
            if (($this->data[$p] ?? '') === "\r") {
                $p++;
            }
            if (($this->data[$p] ?? '') === "\n") {
                $p++;
            }
            $len = $obj->get('Length');
            if ($len instanceof PdfRef) {
                $len = isset($this->offsets[$len->num]) || isset($this->inStream[$len->num]) ? $this->resolve($len) : null;
            }
            if (is_int($len) && $len >= 0 && preg_match('/\G\s*endstream/', $this->data, $mm, 0, $p + $len)) {
                $raw = substr($this->data, $p, $len);
                $lx->pos = $p + $len + strlen($mm[0]);
            } else {
                $end = strpos($this->data, 'endstream', $p);
                if ($end === false) {
                    throw new RuntimeException('endstream が無い');
                }
                $raw = rtrim(substr($this->data, $p, $end - $p), "\r\n");
                $lx->pos = $end + 9;
            }
            return new PdfStream($obj, $raw);
        }
        $lx->pos = $save;
        return $obj;
    }

    /** @return list<array{int, int}> ObjStm の中の [オブジェクト番号, 中身の開始位置] */
    private function objStmIndex(int $stmNum): array
    {
        if (isset($this->objStmCache[$stmNum])) {
            return $this->objStmCache[$stmNum]['index'];
        }
        $stm = $this->get($stmNum);
        $index = [];
        $body = '';
        if ($stm instanceof PdfStream) {
            $body = $this->decode($stm);
            $n = (int)$stm->dict->get('N');
            $first = (int)$stm->dict->get('First');
            $lx = new PdfLexer($body);
            for ($i = 0; $i < $n; $i++) {
                $num = $lx->next();
                $off = $lx->next();
                if (!is_int($num) || !is_int($off)) {
                    break;
                }
                $index[] = [$num, $first + $off];
            }
        }
        $this->objStmCache[$stmNum] = ['index' => $index, 'body' => $body];
        return $index;
    }

    public function get(int $num): mixed
    {
        if (array_key_exists($num, $this->cache)) {
            return $this->cache[$num];
        }
        $this->cache[$num] = null; // 循環参照よけ
        $obj = null;
        if (isset($this->offsets[$num])) {
            $lx = new PdfLexer($this->data, $this->offsets[$num]);
            $lx->next();
            $lx->next();
            $lx->next(); // n 0 obj
            $obj = $this->readObjectBody($lx);
        } elseif (isset($this->inStream[$num])) {
            [$stmNum, $i] = $this->inStream[$num];
            $index = $this->objStmIndex($stmNum);
            $lx = new PdfLexer($this->objStmCache[$stmNum]['body'], $index[$i][1]);
            $obj = $lx->next();
        }
        return $this->cache[$num] = $obj;
    }

    public function resolve(mixed $v): mixed
    {
        for ($i = 0; $i < 32 && $v instanceof PdfRef; $i++) {
            $v = $this->get($v->num);
        }
        return $v;
    }

    /** ストリームの中身を展開する（文字を読むのに必要な FlateDecode と ASCIIHex だけ） */
    public function decode(PdfStream $stm): string
    {
        $filters = $this->resolve($stm->dict->get('Filter'));
        $filters = $filters === null ? [] : (is_array($filters) ? $filters : [$filters]);
        $data = $stm->raw;
        foreach ($filters as $f) {
            $f = $this->resolve($f);
            $name = $f instanceof PdfName ? $f->v : '';
            if ($name === 'FlateDecode' || $name === 'Fl') {
                $out = @gzuncompress($data);
                if ($out === false) {
                    $out = @gzinflate(substr($data, 2));
                }
                if ($out === false) {
                    $out = @gzinflate($data);
                }
                if ($out === false) {
                    return '';
                }
                $data = $out;
            } elseif ($name === 'ASCIIHexDecode' || $name === 'AHx') {
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', explode('>', $data)[0]) ?? '';
                $data = (string)hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex);
            } else {
                return ''; // 画像用の圧縮など。文字には関係ない
            }
        }
        return $data;
    }

    public function dict(mixed $v): ?PdfDict
    {
        $v = $this->resolve($v);
        if ($v instanceof PdfStream) {
            return $v->dict;
        }
        return $v instanceof PdfDict ? $v : null;
    }

    public function catalog(): ?PdfDict
    {
        // trailer / xref ストリームの /Root を探す。無ければ /Type /Catalog のオブジェクト
        if (preg_match_all('/\/Root\s+(\d+)\s+\d+\s+R/', $this->data, $m)) {
            $cat = $this->dict(new PdfRef((int)end($m[1])));
            if ($cat !== null) {
                return $cat;
            }
        }
        foreach (array_keys($this->offsets + $this->inStream) as $num) {
            $d = $this->dict(new PdfRef($num));
            if ($d !== null && ($d->get('Type') instanceof PdfName) && $d->get('Type')->v === 'Catalog') {
                return $d;
            }
        }
        return null;
    }

    /** @return list<PdfDict> ページ（Resources / MediaBox / CropBox は親から引き継いだものを入れておく） */
    public function pages(): array
    {
        $cat = $this->catalog();
        if ($cat === null) {
            throw new RuntimeException('PDF の構造を読めませんでした');
        }
        if ($cat->get('Encrypt') !== null || preg_match('/\/Encrypt\s/', substr($this->data, -4096))) {
            throw new RuntimeException('パスワード（暗号化）付きの PDF は読めません');
        }
        $out = [];
        $this->collectPages($this->dict($cat->get('Pages')), [], $out, 0);
        return $out;
    }

    private function collectPages(?PdfDict $node, array $inherit, array &$out, int $depth): void
    {
        if ($node === null || $depth > 64) {
            return;
        }
        foreach (['Resources', 'MediaBox', 'CropBox', 'Rotate'] as $k) {
            if ($node->get($k) !== null) {
                $inherit[$k] = $node->get($k);
            }
        }
        $kids = $this->resolve($node->get('Kids'));
        if (is_array($kids)) {
            foreach ($kids as $kid) {
                $this->collectPages($this->dict($kid), $inherit, $out, $depth + 1);
            }
            return;
        }
        $page = new PdfDict($node->e);
        foreach ($inherit as $k => $v) {
            $page->e[$k] = $v;
        }
        $out[] = $page;
    }
}

/** フォント1つ分：文字コード → Unicode と、文字の幅 */
final class PdfFont
{
    public bool $twoByte = false;
    /** @var array<int, string> 文字コード => UTF-8 */
    public array $toUnicode = [];
    /** @var array<int, float> 文字コード => 幅（1000分率） */
    public array $widths = [];
    public float $defaultWidth = 0.0;
    public float $ascent = 0.95;
    public float $descent = -0.35;

    public function __construct(PdfDocument $doc, ?PdfDict $d)
    {
        if ($d === null) {
            return;
        }
        $subtype = $d->get('Subtype') instanceof PdfName ? $d->get('Subtype')->v : '';
        $desc = null;
        if ($subtype === 'Type0') {
            $this->twoByte = true;
            $descendants = $doc->resolve($d->get('DescendantFonts'));
            $cid = is_array($descendants) ? $doc->dict($descendants[0] ?? null) : null;
            if ($cid !== null) {
                $this->defaultWidth = (float)($doc->resolve($cid->get('DW')) ?? 1000);
                $w = $doc->resolve($cid->get('W'));
                if (is_array($w)) {
                    $this->readCidWidths($doc, $w);
                }
                $desc = $doc->dict($cid->get('FontDescriptor'));
            }
        } else {
            $first = (int)($doc->resolve($d->get('FirstChar')) ?? 0);
            $ws = $doc->resolve($d->get('Widths'));
            if (is_array($ws)) {
                foreach ($ws as $i => $w) {
                    $this->widths[$first + $i] = (float)$doc->resolve($w);
                }
            }
            $desc = $doc->dict($d->get('FontDescriptor'));
            $this->defaultWidth = (float)($desc !== null ? ($doc->resolve($desc->get('MissingWidth')) ?? 0) : 0);
            if (!is_array($ws)) {
                $this->defaultWidth = 500.0; // 標準14フォントなど幅が書いていないもの（だいたいの値）
            }
            $this->simpleEncoding($doc, $d);
        }
        if ($desc !== null) {
            $a = $doc->resolve($desc->get('Ascent'));
            $dd = $doc->resolve($desc->get('Descent'));
            if (is_numeric($a) && $a != 0 && $a / 1000 < 3) {
                $this->ascent = $a / 1000;
            }
            if (is_numeric($dd) && $dd != 0 && $dd / 1000 > -3) {
                $this->descent = -abs($dd / 1000);
            }
        }
        $tu = $doc->resolve($d->get('ToUnicode'));
        if ($tu instanceof PdfStream) {
            $this->readToUnicode($doc->decode($tu));
        }
    }

    private function readCidWidths(PdfDocument $doc, array $w): void
    {
        $i = 0;
        $n = count($w);
        while ($i < $n) {
            $c = (int)$doc->resolve($w[$i]);
            $next = $doc->resolve($w[$i + 1] ?? null);
            if (is_array($next)) {
                foreach ($next as $k => $v) {
                    $this->widths[$c + $k] = (float)$doc->resolve($v);
                }
                $i += 2;
            } else {
                $last = (int)$next;
                $v = (float)$doc->resolve($w[$i + 2] ?? 0);
                for ($k = $c; $k <= $last && $k - $c < 65536; $k++) {
                    $this->widths[$k] = $v;
                }
                $i += 3;
            }
        }
    }

    /** 1バイトフォントの Encoding（WinAnsi + Differences）。ToUnicode があればそちらで上書きされる */
    private function simpleEncoding(PdfDocument $doc, PdfDict $d): void
    {
        for ($c = 32; $c < 256; $c++) {
            $u = $c < 128 ? chr($c) : (string)mb_convert_encoding(chr($c), 'UTF-8', 'Windows-1252');
            if ($u !== '' && $u !== '?') {
                $this->toUnicode[$c] = $u;
            }
        }
        $enc = $doc->dict($d->get('Encoding'));
        $diff = $enc !== null ? $doc->resolve($enc->get('Differences')) : null;
        if (!is_array($diff)) {
            return;
        }
        $code = 0;
        foreach ($diff as $v) {
            $v = $doc->resolve($v);
            if (is_int($v)) {
                $code = $v;
            } elseif ($v instanceof PdfName) {
                $u = pdf_glyph_name_to_utf8($v->v);
                if ($u !== null) {
                    $this->toUnicode[$code] = $u;
                }
                $code++;
            }
        }
    }

    private function readToUnicode(string $cmap): void
    {
        $toUtf8 = static function (string $bytes): string {
            $u = mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
            return is_string($u) ? $u : '';
        };
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $block, $m, PREG_SET_ORDER);
                foreach ($m as [, $src, $dst]) {
                    $this->toUnicode[hexdec($src)] = $toUtf8((string)hex2bin(strlen($dst) % 2 ? $dst . '0' : $dst));
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(?:<([0-9A-Fa-f]*)>|\[([^\]]*)\])/', $block, $m, PREG_SET_ORDER);
                foreach ($m as $r) {
                    $lo = hexdec($r[1]);
                    $hi = min(hexdec($r[2]), $lo + 65535);
                    if (isset($r[4]) && $r[4] !== '') {
                        preg_match_all('/<([0-9A-Fa-f]*)>/', $r[4], $dsts);
                        foreach ($dsts[1] as $k => $dst) {
                            if ($lo + $k <= $hi) {
                                $this->toUnicode[$lo + $k] = $toUtf8((string)hex2bin($dst));
                            }
                        }
                        continue;
                    }
                    $dst = (string)hex2bin(strlen($r[3]) % 2 ? $r[3] . '0' : $r[3]);
                    for ($c = $lo; $c <= $hi; $c++) {
                        // 最後の1バイト（2バイト）を1ずつ増やしていく
                        $bytes = $dst;
                        $add = $c - $lo;
                        for ($i = strlen($bytes) - 1; $i >= 0 && $add > 0; $i--) {
                            $sum = ord($bytes[$i]) + $add;
                            $bytes[$i] = chr($sum & 0xFF);
                            $add = $sum >> 8;
                        }
                        $this->toUnicode[$c] = $toUtf8($bytes);
                    }
                }
            }
        }
    }

    /** @return list<int> 文字列を文字コードの列に分ける */
    public function codes(string $s): array
    {
        if ($this->twoByte) {
            $n = intdiv(strlen($s), 2);
            return $n > 0 ? array_values(unpack('n' . $n, $s)) : [];
        }
        return $s === '' ? [] : array_values(unpack('C*', $s));
    }

    public function width(int $code): float
    {
        return $this->widths[$code] ?? $this->defaultWidth;
    }
}

function pdf_glyph_name_to_utf8(string $name): ?string
{
    if (preg_match('/^uni([0-9A-Fa-f]{4})/', $name, $m) || preg_match('/^u([0-9A-Fa-f]{4,6})$/', $name, $m)) {
        return mb_chr((int)hexdec($m[1]), 'UTF-8') ?: null;
    }
    if (strlen($name) === 1) {
        return $name;
    }
    static $names = [
        'space' => ' ', 'exclam' => '!', 'quotedbl' => '"', 'numbersign' => '#', 'dollar' => '$', 'percent' => '%',
        'ampersand' => '&', 'quotesingle' => "'", 'quoteright' => '’', 'parenleft' => '(', 'parenright' => ')',
        'asterisk' => '*', 'plus' => '+', 'comma' => ',', 'hyphen' => '-', 'minus' => '-', 'period' => '.',
        'slash' => '/', 'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5',
        'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'colon' => ':', 'semicolon' => ';',
        'less' => '<', 'equal' => '=', 'greater' => '>', 'question' => '?', 'at' => '@', 'bracketleft' => '[',
        'backslash' => '\\', 'bracketright' => ']', 'asciicircum' => '^', 'underscore' => '_', 'grave' => '`',
        'quoteleft' => '‘', 'braceleft' => '{', 'bar' => '|', 'braceright' => '}', 'asciitilde' => '~',
        'endash' => '–', 'emdash' => '—', 'bullet' => '•', 'quotedblleft' => '“', 'quotedblright' => '”',
        'ellipsis' => '…', 'nbspace' => ' ',
    ];
    return $names[$name] ?? null;
}

/**
 * ページの描画命令を順に実行して、文字1つ1つの位置を集め、単語にまとめる
 */
final class PdfTextCollector
{
    /** @var list<array{x0: float, y0: float, x1: float, y1: float, text: string}> */
    public array $words = [];
    private ?array $cur = null;
    /** @var array<string, PdfFont> */
    private array $fontCache = [];

    public function __construct(
        private PdfDocument $doc,
        private float $pageX0,
        private float $pageY1,
        private float $pageW,
        private float $pageH,
    ) {}

    public function run(string $content, ?PdfDict $resources, array $ctm, int $depth = 0): void
    {
        if ($depth > 8) {
            return;
        }
        $gsStack = [];
        $ctmNow = $ctm;
        $ts = ['Tc' => 0.0, 'Tw' => 0.0, 'Th' => 1.0, 'TL' => 0.0, 'Ts' => 0.0, 'font' => null, 'size' => 0.0];
        $tm = [1, 0, 0, 1, 0, 0];
        $tlm = $tm;
        $ops = [];
        $lx = new PdfLexer($content);
        $len = strlen($content);
        while ($lx->pos < $len) {
            $v = $lx->next();
            if (!(is_array($v) && isset($v['op']))) {
                if ($v === null && $lx->pos >= $len) {
                    break;
                }
                $ops[] = $v;
                continue;
            }
            $op = $v['op'];
            $a = $ops;
            $ops = [];
            switch ($op) {
                case 'q':
                    $gsStack[] = [$ctmNow, $ts];
                    break;
                case 'Q':
                    if ($gsStack !== []) {
                        [$ctmNow, $ts] = array_pop($gsStack);
                    }
                    break;
                case 'cm':
                    if (count($a) >= 6) {
                        $ctmNow = pdf_mat_mul(array_map('floatval', array_slice($a, -6)), $ctmNow);
                    }
                    break;
                case 'BT':
                    $tm = $tlm = [1, 0, 0, 1, 0, 0];
                    break;
                case 'Tf':
                    if (count($a) >= 2 && $a[0] instanceof PdfName) {
                        $ts['font'] = $this->font($resources, $a[0]->v);
                        $ts['size'] = (float)$a[1];
                    }
                    break;
                case 'Tc': $ts['Tc'] = (float)($a[0] ?? 0); break;
                case 'Tw': $ts['Tw'] = (float)($a[0] ?? 0); break;
                case 'Tz': $ts['Th'] = (float)($a[0] ?? 100) / 100; break;
                case 'TL': $ts['TL'] = (float)($a[0] ?? 0); break;
                case 'Ts': $ts['Ts'] = (float)($a[0] ?? 0); break;
                case 'Td':
                case 'TD':
                    if (count($a) >= 2) {
                        if ($op === 'TD') {
                            $ts['TL'] = -(float)$a[1];
                        }
                        $tlm = pdf_mat_mul([1, 0, 0, 1, (float)$a[0], (float)$a[1]], $tlm);
                        $tm = $tlm;
                    }
                    break;
                case 'Tm':
                    if (count($a) >= 6) {
                        $tm = $tlm = array_map('floatval', array_slice($a, -6));
                    }
                    break;
                case 'T*':
                    $tlm = pdf_mat_mul([1, 0, 0, 1, 0, -$ts['TL']], $tlm);
                    $tm = $tlm;
                    break;
                case "'":
                case '"':
                    if ($op === '"' && count($a) >= 3) {
                        $ts['Tw'] = (float)$a[0];
                        $ts['Tc'] = (float)$a[1];
                    }
                    $tlm = pdf_mat_mul([1, 0, 0, 1, 0, -$ts['TL']], $tlm);
                    $tm = $tlm;
                    if (is_string(end($a))) {
                        $this->show(end($a), $ts, $tm, $ctmNow);
                    }
                    break;
                case 'Tj':
                    if (is_string(end($a))) {
                        $this->show(end($a), $ts, $tm, $ctmNow);
                    }
                    break;
                case 'TJ':
                    foreach ((is_array(end($a)) ? end($a) : []) as $item) {
                        if (is_string($item)) {
                            $this->show($item, $ts, $tm, $ctmNow);
                        } elseif (is_int($item) || is_float($item)) {
                            $tm = pdf_mat_mul([1, 0, 0, 1, -$item / 1000 * $ts['size'] * $ts['Th'], 0], $tm);
                        }
                    }
                    break;
                case 'Do':
                    if (($a[0] ?? null) instanceof PdfName && $resources !== null) {
                        $xobjs = $this->doc->dict($resources->get('XObject'));
                        $x = $xobjs !== null ? $this->doc->resolve($xobjs->get($a[0]->v)) : null;
                        if ($x instanceof PdfStream && ($x->dict->get('Subtype') instanceof PdfName) && $x->dict->get('Subtype')->v === 'Form') {
                            $m = $this->doc->resolve($x->dict->get('Matrix'));
                            $m = is_array($m) && count($m) === 6 ? array_map(fn($v) => (float)$this->doc->resolve($v), $m) : [1, 0, 0, 1, 0, 0];
                            $res = $this->doc->dict($x->dict->get('Resources')) ?? $resources;
                            $this->run($this->doc->decode($x), $res, pdf_mat_mul($m, $ctmNow), $depth + 1);
                        }
                    }
                    break;
                case 'BI':
                    // インライン画像は ID の後ろが生のデータなので EI まで飛ばす
                    $id = strpos($content, 'ID', $lx->pos);
                    $ei = $id === false ? false : (preg_match('/\sEI(?=[\s]|$)/', $content, $mm, PREG_OFFSET_CAPTURE, $id + 3) ? $mm[0][1] : false);
                    $lx->pos = $ei === false ? $len : $ei + 3;
                    break;
            }
        }
        $this->endWord();
    }

    private function font(?PdfDict $resources, string $name): ?PdfFont
    {
        $fonts = $resources !== null ? $this->doc->dict($resources->get('Font')) : null;
        $ref = $fonts?->get($name);
        $key = $ref instanceof PdfRef ? 'r' . $ref->num : 'n' . spl_object_id($fonts ?? new PdfDict()) . $name;
        return $this->fontCache[$key] ??= new PdfFont($this->doc, $this->doc->dict($ref));
    }

    /** 文字列1つを描く：文字ごとに位置を出して addChar し、テキスト行列を進める */
    private function show(string $s, array $ts, array &$tm, array $ctm): void
    {
        $font = $ts['font'];
        if (!$font instanceof PdfFont) {
            return;
        }
        $size = $ts['size'];
        $th = $ts['Th'];
        foreach ($font->codes($s) as $code) {
            $w0 = $font->width($code) / 1000;
            $isSpace = !$font->twoByte && $code === 32;
            $trm = pdf_mat_mul(pdf_mat_mul([$size * $th, 0, 0, $size, 0, $ts['Ts']], $tm), $ctm);
            // 文字の左下（基準線上）と、文字幅ぶん右の点をページの座標に
            [$ox, $oy] = [$trm[4], $trm[5]];
            $dx = $w0 * $trm[0];
            $dy = $w0 * $trm[1];
            $fontSize = sqrt($trm[2] ** 2 + $trm[3] ** 2);
            $u = $font->toUnicode[$code] ?? null;
            $this->addChar($u, $ox, $oy, $dx, $dy, $fontSize, $font, $isSpace);
            $tx = ($w0 * $size + $ts['Tc'] + ($isSpace ? $ts['Tw'] : 0)) * $th;
            $tm = pdf_mat_mul([1, 0, 0, 1, $tx, 0], $tm);
        }
    }

    private function addChar(?string $u, float $x, float $y, float $dx, float $dy, float $fontSize, PdfFont $font, bool $isSpaceCode): void
    {
        // pdftotext と同じく左上原点に
        $x = $x - $this->pageX0;
        $y = $this->pageY1 - $y;
        if ($x + $dx < 0 || $x > $this->pageW || $y < 0 || $y > $this->pageH) {
            return;
        }
        if ($u === null || $u === '') {
            return;
        }
        // 改行などの制御文字に対応付けられた文字は、pdftotext と同じく空白（単語の区切り）として扱う
        $isSpace = $isSpaceCode || preg_match('/^[\p{Cc}\p{Zs}]+$/u', $u) === 1;
        if ($this->cur !== null) {
            $c = $this->cur;
            $sp = $x - $c['x1'];
            if (abs($y - $c['base']) > 0.5 * $c['size']
                || $sp > 0.1 * $c['size']
                || $sp < -0.2 * $c['size']
                || abs($fontSize - $c['size']) > 0.01) {
                $this->endWord();
            }
        }
        if ($isSpace) {
            $this->endWord();
            return;
        }
        if ($this->cur === null) {
            $this->cur = [
                'x0' => $x, 'x1' => $x, 'base' => $y, 'size' => $fontSize, 'text' => '',
                'y0' => $y - $font->ascent * $fontSize, 'y1' => $y - $font->descent * $fontSize,
            ];
        }
        $this->cur['text'] .= $u;
        $this->cur['x1'] = $x + $dx;
    }

    private function endWord(): void
    {
        if ($this->cur !== null && $this->cur['text'] !== '') {
            $c = $this->cur;
            $this->words[] = ['x0' => $c['x0'], 'y0' => $c['y0'], 'x1' => $c['x1'], 'y1' => $c['y1'], 'text' => $c['text']];
        }
        $this->cur = null;
    }
}

/** 行列の掛け算（PDF の [a b c d e f] 形式） */
function pdf_mat_mul(array $m1, array $m2): array
{
    return [
        $m1[0] * $m2[0] + $m1[1] * $m2[2],
        $m1[0] * $m2[1] + $m1[1] * $m2[3],
        $m1[2] * $m2[0] + $m1[3] * $m2[2],
        $m1[2] * $m2[1] + $m1[3] * $m2[3],
        $m1[4] * $m2[0] + $m1[5] * $m2[2] + $m2[4],
        $m1[4] * $m2[1] + $m1[5] * $m2[3] + $m2[5],
    ];
}

/**
 * @return list<list<array{x0: float, y0: float, x1: float, y1: float, text: string}>> ページごとの単語
 */
function pdf_extract_words(string $data): array
{
    $doc = new PdfDocument($data);
    $out = [];
    foreach ($doc->pages() as $page) {
        $box = $doc->resolve($page->get('CropBox')) ?? $doc->resolve($page->get('MediaBox'));
        $box = is_array($box) && count($box) === 4 ? array_map(fn($v) => (float)$doc->resolve($v), $box) : [0, 0, 612, 792];
        [$bx0, $by0, $bx1, $by1] = [min($box[0], $box[2]), min($box[1], $box[3]), max($box[0], $box[2]), max($box[1], $box[3])];
        $contents = $doc->resolve($page->get('Contents'));
        $parts = is_array($contents) ? $contents : [$contents];
        $content = '';
        foreach ($parts as $p) {
            $p = $doc->resolve($p);
            if ($p instanceof PdfStream) {
                $content .= $doc->decode($p) . "\n";
            }
        }
        $collector = new PdfTextCollector($doc, $bx0, $by1, $bx1 - $bx0, $by1 - $by0);
        $collector->run($content, $doc->dict($page->get('Resources')), [1, 0, 0, 1, 0, 0]);
        $out[] = $collector->words;
    }
    return $out;
}
