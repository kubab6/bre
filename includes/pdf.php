<?php
declare(strict_types=1);

/**
 * Minimaler PDF-Generator ohne externe Abhängigkeiten: A4, Standardschriften
 * Helvetica/Helvetica-Bold (WinAnsi, also inkl. Umlaute und €), Text, Linien
 * und Rechtecke. Koordinaten in Millimetern, Ursprung oben links.
 */
final class SimplePdf {
    public const W = 210.0;
    public const H = 297.0;
    private const K = 72 / 25.4; // pt pro mm

    private const WIDTHS = [
        'R' => '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,761,556,556,222,556,333,1000,556,556,333,1000,667,333,1000,556,611,556,556,222,222,333,333,350,556,1000,333,1000,500,333,944,556,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500',
        'B' => '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,761,556,556,278,556,500,1000,556,556,333,1000,667,333,1000,556,611,556,556,278,278,500,500,350,556,1000,333,1000,556,333,944,556,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556',
    ];

    /** @var string[] */
    private array $pages = [];
    private string $font = 'R';
    private float $size = 10.0;
    private array $widthCache = [];
    private int $cur = 0;

    public function addPage(): void {
        $this->pages[] = '';
        $this->cur = array_key_last($this->pages);
    }

    public function pageCount(): int {
        return count($this->pages);
    }

    /** Wechselt die Seite, auf die geschrieben wird (z.B. für "Seite x von y" am Schluss). */
    public function selectPage(int $index): void {
        if (isset($this->pages[$index])) $this->cur = $index;
    }

    public function setFont(string $style, float $size): void {
        $this->font = $style === 'B' ? 'B' : 'R';
        $this->size = $size;
    }

    public function setFillGray(float $g): void {
        $this->out(sprintf('%.3F g', $g));
    }

    public function setStrokeGray(float $g): void {
        $this->out(sprintf('%.3F G', $g));
    }

    public static function encode(string $s): string {
        $s = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $s);
        $enc = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        return is_string($enc) ? $enc : '';
    }

    /** Breite eines UTF-8-Texts in mm bei aktueller Schrift. */
    public function textWidth(string $s): float {
        $w = $this->widths($this->font);
        $sum = 0;
        $enc = self::encode($s);
        for ($i = 0, $n = strlen($enc); $i < $n; $i++) {
            $c = ord($enc[$i]);
            $sum += $c >= 32 ? $w[$c - 32] : 0;
        }
        return $sum * $this->size / 1000 / self::K;
    }

    /** Text an Position (x, y = Grundlinie). $align: L, R oder C relativ zu x. */
    public function text(float $x, float $y, string $s, string $align = 'L'): void {
        if ($s === '') return;
        if ($align === 'R') $x -= $this->textWidth($s);
        elseif ($align === 'C') $x -= $this->textWidth($s) / 2;
        $esc = strtr(self::encode($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
        $this->out(sprintf('BT /F%s %.2F Tf %.2F %.2F Td (%s) Tj ET',
            $this->font, $this->size, $x * self::K, (self::H - $y) * self::K, $esc));
    }

    /** Bricht Text auf die Breite $maxW (mm) um. */
    public function wrap(string $s, float $maxW): array {
        $lines = [];
        foreach (preg_split('/\R/', $s) as $para) {
            $line = '';
            foreach (preg_split('/\s+/', trim($para)) as $word) {
                if ($word === '') continue;
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($this->textWidth($try) <= $maxW) { $line = $try; continue; }
                if ($line !== '') $lines[] = $line;
                // Überlange Wörter hart umbrechen.
                while ($this->textWidth($word) > $maxW && mb_strlen($word) > 1) {
                    $cut = mb_strlen($word);
                    while ($cut > 1 && $this->textWidth(mb_substr($word, 0, $cut)) > $maxW) $cut--;
                    $lines[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $line = $word;
            }
            if ($line !== '') $lines[] = $line;
        }
        return $lines ?: [''];
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.2): void {
        $this->out(sprintf('%.2F w %.2F %.2F m %.2F %.2F l S', $width * self::K,
            $x1 * self::K, (self::H - $y1) * self::K, $x2 * self::K, (self::H - $y2) * self::K));
    }

    public function fillRect(float $x, float $y, float $w, float $h): void {
        $this->out(sprintf('%.2F %.2F %.2F %.2F re f', $x * self::K, (self::H - $y - $h) * self::K, $w * self::K, $h * self::K));
    }

    public function output(string $title = ''): string {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($this->pages as $content) {
            $pageId = $n++;
            $contentId = $n++;
            $kids[] = "$pageId 0 R";
            $objs[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /FR 3 0 R /FB 4 0 R >> >> /Contents %d 0 R >>',
                self::W * self::K, self::H * self::K, $contentId);
            $data = gzcompress($content);
            $objs[$contentId] = '<< /Length ' . strlen($data) . " /Filter /FlateDecode >>\nstream\n" . $data . "\nendstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $infoId = $n;
        $esc = strtr(self::encode($title), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
        $objs[$infoId] = '<< /Title (' . $esc . ') /Producer (Zeiterfassung) /CreationDate (D:' . date('YmdHis') . ') >>';
        ksort($objs);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($infoId + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $infoId; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($infoId + 1) . " /Root 1 0 R /Info $infoId 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }

    private function out(string $s): void {
        if (!$this->pages) $this->addPage();
        $this->pages[$this->cur] .= $s . "\n";
    }

    private function widths(string $font): array {
        return $this->widthCache[$font] ??= array_map('intval', explode(',', self::WIDTHS[$font]));
    }
}

/**
 * Seitenlayout für Berichte: Tabellen mit Kopfzeile, Zeilenumbruch je Zelle,
 * automatischem Seitenwechsel (Kopf wird wiederholt) und Fußzeile mit Seitenzahlen.
 * Spalten: [Titel, x, Breite, Ausrichtung L/R].
 */
final class PdfReport {
    public SimplePdf $pdf;
    public float $y = 0.0;
    public float $left = 15.0;
    public float $right = SimplePdf::W - 15.0;
    public float $bottom = SimplePdf::H - 20.0;
    public float $lh = 4.4;

    public function __construct() {
        $this->pdf = new SimplePdf();
        $this->newPage();
    }

    public function newPage(): void {
        $this->pdf->addPage();
        $this->y = 20.0;
    }

    public function head(array $cols): void {
        $p = $this->pdf;
        $p->setFillGray(0.92);
        $p->fillRect($this->left, $this->y - 4.2, $this->right - $this->left, 6.2);
        $p->setFillGray(0);
        $p->setFont('B', 8.5);
        foreach ($cols as [$title, $x, $w, $align]) {
            $p->text($align === 'R' ? $x + $w : $x, $this->y, $title, $align);
        }
        $this->y += 6.0;
    }

    /** Platz prüfen; bei Seitenumbruch ggf. Tabellenkopf wiederholen. */
    public function ensure(float $needed, ?array $cols = null): void {
        if ($this->y + $needed <= $this->bottom) return;
        $this->newPage();
        if ($cols) $this->head($cols);
    }

    /** Tabellenzeile; $bold: Zeile fett (z.B. Summen), $shade: grauer Hintergrund. */
    public function row(array $cols, array $cells, bool $bold = false, bool $shade = false): void {
        $p = $this->pdf;
        $style = $bold ? 'B' : 'R';
        $p->setFont($style, 8.5);
        $wrapped = [];
        $lines = 1;
        foreach ($cols as $i => [, , $w]) {
            $wrapped[$i] = $p->wrap((string)($cells[$i] ?? ''), $w - 1.5);
            $lines = max($lines, count($wrapped[$i]));
        }
        $this->ensure($lines * $this->lh + 1.5, $cols);
        if ($shade) {
            $p->setFillGray(0.96);
            $p->fillRect($this->left, $this->y - 3.4, $this->right - $this->left, ($lines - 1) * $this->lh + 5.6);
            $p->setFillGray(0);
        }
        $p->setFont($style, 8.5);
        foreach ($cols as $i => [, $x, $w, $align]) {
            foreach ($wrapped[$i] as $n => $line) {
                $p->text($align === 'R' ? $x + $w : $x, $this->y + $n * $this->lh, $line, $align);
            }
        }
        $this->y += ($lines - 1) * $this->lh + 2.2;
        $p->setStrokeGray(0.8);
        $p->line($this->left, $this->y, $this->right, $this->y, 0.15);
        $p->setStrokeGray(0);
        $this->y += $this->lh;
    }

    /** Abschnittsüberschrift. */
    public function section(string $title): void {
        $this->ensure(20);
        $this->pdf->setFont('B', 11);
        $this->pdf->text($this->left, $this->y, $title);
        $this->y += 6;
    }

    public function note(string $text): void {
        $this->pdf->setFont('R', 9);
        $this->pdf->text($this->left, $this->y, $text);
        $this->y += 8;
    }

    /** Rechtsbündige Infozeilen (Schlüssel/Wert) ab x=130; gibt die Endposition zurück. */
    public function meta(array $meta, float $y): float {
        foreach ($meta as $k => $v) {
            $this->pdf->setFont('B', 9);
            $this->pdf->text(130, $y, (string)$k);
            $this->pdf->setFont('R', 9);
            $this->pdf->text($this->right, $y, (string)$v, 'R');
            $y += 4.8;
        }
        return $y;
    }

    /** Fußzeile auf allen Seiten und PDF als Download/Inline senden. */
    public function send(string $footer, string $title, string $filename): void {
        $p = $this->pdf;
        $pages = $p->pageCount();
        for ($i = 0; $i < $pages; $i++) {
            $p->selectPage($i);
            $p->setStrokeGray(0.6);
            $p->line($this->left, SimplePdf::H - 13, $this->right, SimplePdf::H - 13, 0.2);
            $p->setFont('R', 7.5);
            $p->text($this->left, SimplePdf::H - 9, $footer);
            $p->text($this->right, SimplePdf::H - 9, 'Seite ' . ($i + 1) . ' von ' . $pages, 'R');
        }
        $data = $p->output($title);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        echo $data;
    }
}

/** Dauer als Stunden:Minuten, z.B. "1:30 h". */
function fmtHours(int $secs): string {
    $neg = $secs < 0;
    $min = (int)round(abs($secs) / 60);
    return ($neg ? '-' : '') . sprintf('%d:%02d h', intdiv($min, 60), $min % 60);
}

/** Dateiname-tauglicher Teil aus einem Namen (Umlaute ersetzt). */
function fileSlug(string $name, string $fallback): string {
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']));
    $slug = trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', $ascii ?: ''), '_');
    return $slug !== '' ? $slug : $fallback;
}
