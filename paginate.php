<?php
/**
 * paginate.php
 *
 * Reformats a plain text file into a "virtual paged" text file, where each
 * page of wrapped text is framed with box-drawing characters.
 *
 * Input assumptions:
 *   - Each line (terminated by a line-feed) in the input file is one
 *     complete paragraph. The text is NOT already word-wrapped.
 *   - UTF-8 encoding. Each character is assumed to occupy exactly one
 *     character cell (no wide/combining/RTL handling).
 *
 * Output:
 *   - Paragraphs are word-wrapped to a max of 66 characters per line,
 *     with the first line of each paragraph indented 5 spaces.
 *   - Wrapped lines are grouped into pages of 33 lines, with basic
 *     widow/orphan control.
 *   - Each page is rendered as a 72-column x 38-row box:
 *       row 1        : top border (corners + horizontal)
 *       row 2        : padding row (verticals only)
 *       rows 3-35    : the 33 text lines
 *       row 36       : padding row (verticals only)
 *       row 37       : centered page number, e.g. "-10-"
 *       row 38       : bottom border
 *   - Two completely blank lines separate consecutive rendered pages.
 *
 * Usage (CLI):
 *   php paginate.php input.txt output.txt
 *
 * Usage (as a module):
 *   require_once 'paginate.php';
 *   convert_to_paged_document('input.txt', 'output.txt');
 */

declare(strict_types=1);

const LINE_WIDTH        = 66; // max characters of wrapped text per line
const FIRST_LINE_INDENT = 5;  // spaces indenting the first line of a paragraph
const LINES_PER_PAGE    = 33; // text lines per virtual page
const PAGE_WIDTH        = 72; // total rendered width, including borders
const PAGE_HEIGHT       = 38; // total rendered height, including borders

// ---------------------------------------------------------------------
// Multibyte-safe helpers (kept simple; each character = 1 cell, per spec)
// ---------------------------------------------------------------------

if (!function_exists('mb_strlen')) {
    // Minimal polyfill for environments without the mbstring extension.
    // Counts UTF-8 codepoints (not bytes) so ASCII/Latin text still lines
    // up correctly, per the stated one-cell-per-character assumption.
    function mb_strlen(string $str, string $encoding = 'UTF-8'): int
    {
        return count(preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}

function mb_pad_right(string $str, int $len, string $pad = ' '): string
{
    $strLen = mb_strlen($str);
    if ($strLen >= $len) {
        return $str;
    }
    return $str . str_repeat($pad, $len - $strLen);
}

// ---------------------------------------------------------------------
// Step 1: Load paragraphs from the input file
// ---------------------------------------------------------------------

/**
 * @return string[] Array of non-empty paragraph strings, in order.
 */
function load_paragraphs(string $inputFile): array
{
    $raw = file($inputFile, FILE_IGNORE_NEW_LINES);
    if ($raw === false) {
        throw new RuntimeException("Unable to read input file: {$inputFile}");
    }

    $paragraphs = [];
    foreach ($raw as $line) {
        $line = trim($line);
        if ($line !== '') {
            $paragraphs[] = $line;
        }
    }
    return $paragraphs;
}

// ---------------------------------------------------------------------
// Step 2: Word-wrap a paragraph into fixed-width lines
// ---------------------------------------------------------------------

/**
 * Word-wraps a single paragraph.
 *
 * @return array<int, array{text:string, firstOfPara:bool, lastOfPara:bool, blank:bool}>
 */
function wrap_paragraph(string $paragraph): array
{
    $words = preg_split('/\s+/u', trim($paragraph)) ?: [];
    $words = array_filter($words, fn($w) => $w !== '');
    $words = array_values($words);

    $rawLines = [];
    $curLine  = '';
    $curWidth = LINE_WIDTH - FIRST_LINE_INDENT; // target width for line being built
    $isFirst  = true;

    $flush = function () use (&$curLine, &$rawLines, &$isFirst, &$curWidth) {
        if ($curLine === '') {
            return;
        }
        $rawLines[] = $isFirst ? str_repeat(' ', FIRST_LINE_INDENT) . $curLine : $curLine;
        $isFirst  = false;
        $curWidth = LINE_WIDTH; // subsequent lines use the full width
        $curLine  = '';
    };

    foreach ($words as $word) {
        // A single word longer than the target width is placed on its own
        // line and allowed to overflow (simplifying assumption).
        if ($curLine === '') {
            $curLine = $word;
            continue;
        }
        if (mb_strlen($curLine) + 1 + mb_strlen($word) <= $curWidth) {
            $curLine .= ' ' . $word;
        } else {
            $flush();
            $curLine = $word;
        }
    }
    $flush();

    if (empty($rawLines)) {
        // Paragraph had no words (shouldn't normally happen since blank
        // paragraphs are filtered out earlier), guard anyway.
        $rawLines = [str_repeat(' ', FIRST_LINE_INDENT)];
    }

    $lines = [];
    $count = count($rawLines);
    foreach ($rawLines as $i => $text) {
        $lines[] = [
            'text'        => $text,
            'firstOfPara' => ($i === 0),
            'lastOfPara'  => ($i === $count - 1),
            'blank'       => false,
        ];
    }
    return $lines;
}

/**
 * Word-wraps every paragraph and concatenates the resulting lines.
 *
 * @param string[] $paragraphs
 * @return array<int, array{text:string, firstOfPara:bool, lastOfPara:bool, blank:bool}>
 */
function wrap_all_paragraphs(array $paragraphs): array
{
    $lines = [];
    foreach ($paragraphs as $paragraph) {
        foreach (wrap_paragraph($paragraph) as $line) {
            $lines[] = $line;
        }
    }
    return $lines;
}

function blank_line(): array
{
    return ['text' => '', 'firstOfPara' => false, 'lastOfPara' => false, 'blank' => true];
}

// ---------------------------------------------------------------------
// Step 3: Paginate the wrapped lines, with widow/orphan control
// ---------------------------------------------------------------------

/**
 * Orphan control: the first line of a paragraph should not be left alone
 * as the very last line of a page (with the rest of the paragraph
 * spilling onto the next page). If that would happen, the whole
 * paragraph is deferred to the next page and this page is cut short,
 * padded with blank line(s) at the bottom.
 *
 * Widow control: the last line of a paragraph should not be left alone
 * as the first line of a page (with the rest of the paragraph having
 * been on the previous page). If that would happen, the immediately
 * preceding line (the paragraph's second-to-last line, which by
 * construction is the previous page's last real line) is pulled forward
 * to join it, so the new page begins with two lines of that paragraph
 * instead of one. The previous page is padded with a blank line in its
 * place.
 *
 * Both checks are applied as a single forward pass at each page
 * boundary, which handles the common cases well; pathological inputs
 * (e.g. a paragraph whose second-to-last line is itself the sole
 * survivor of an earlier orphan/widow adjustment) may still show an
 * imperfect break, consistent with this being an approximate algorithm.
 *
 * @param array $lines Output of wrap_all_paragraphs()
 * @return array<int, array> Array of pages, each an array of exactly
 *                            LINES_PER_PAGE line-records.
 */
function paginate(array $lines): array
{
    $pages   = [];
    $current = [];
    $i       = 0;
    $n       = count($lines);

    while ($i < $n) {
        $current[] = $lines[$i];
        $i++;

        if (count($current) === LINES_PER_PAGE) {
            // Orphan check: does the last line on this page start a
            // paragraph that continues beyond this page?
            $last = end($current);
            if ($last['firstOfPara'] && !$last['lastOfPara'] && $i < $n) {
                array_pop($current);
                $i--; // re-process this line as the start of the next page
            }

            // Widow check: would the line about to start the next page be
            // a paragraph's lone last line, orphaned from the rest of its
            // paragraph on this page? If so, pull this page's current
            // last real line (the paragraph's second-to-last line)
            // forward so the next page starts with both lines together.
            if ($i < $n) {
                $next = $lines[$i];
                if ($next['lastOfPara'] && !$next['firstOfPara']) {
                    $prevReal = end($current);
                    if ($prevReal !== false && !$prevReal['blank']) {
                        array_pop($current);
                        $i--; // re-process this line at the start of the next page
                    }
                }
            }

            while (count($current) < LINES_PER_PAGE) {
                $current[] = blank_line();
            }

            $pages[]  = $current;
            $current  = [];
        }
    }

    if (!empty($current)) {
        while (count($current) < LINES_PER_PAGE) {
            $current[] = blank_line();
        }
        $pages[] = $current;
    }

    return $pages;
}

// ---------------------------------------------------------------------
// Step 4: Render a page using box-drawing characters
// ---------------------------------------------------------------------

function render_page(array $pageLines, int $pageNumber): string
{
    $innerWidth = PAGE_WIDTH - 2; // width between the two vertical borders

    $out = '';
    $out .= "\u{2554}" . str_repeat("\u{2550}", $innerWidth) . "\u{2557}\n"; // ╔══...══╗
    $out .= "\u{2551}" . str_repeat(' ', $innerWidth) . "\u{2551}\n";         // ║ padding ║

    foreach ($pageLines as $line) {
        $text = mb_pad_right($line['text'], LINE_WIDTH);
        $out .= "\u{2551}  " . $text . "  \u{2551}\n";
    }

    $out .= "\u{2551}" . str_repeat(' ', $innerWidth) . "\u{2551}\n";         // ║ padding ║

    $pageLabel = "-{$pageNumber}-";
    $labelLen  = mb_strlen($pageLabel);
    $padTotal  = $innerWidth - $labelLen;
    $padLeft   = intdiv($padTotal, 2);
    $padRight  = $padTotal - $padLeft;
    $out .= "\u{2551}" . str_repeat(' ', $padLeft) . $pageLabel . str_repeat(' ', $padRight) . "\u{2551}\n";

    $out .= "\u{255A}" . str_repeat("\u{2550}", $innerWidth) . "\u{255D}\n"; // ╚══...══╝

    return $out;
}

// ---------------------------------------------------------------------
// Step 5: Orchestrate the full conversion
// ---------------------------------------------------------------------

function convert_to_paged_document(string $inputFile, string $outputFile): void
{
    $paragraphs = load_paragraphs($inputFile);
    $lines      = wrap_all_paragraphs($paragraphs);
    $pages      = paginate($lines);

    $output = '';
    foreach ($pages as $index => $pageLines) {
        $output .= render_page($pageLines, $index + 1);
        if ($index < count($pages) - 1) {
            $output .= "\n\n"; // two completely blank lines between pages
        }
    }

    if (file_put_contents($outputFile, $output) === false) {
        throw new RuntimeException("Unable to write output file: {$outputFile}");
    }
}

// ---------------------------------------------------------------------
// CLI entry point
// ---------------------------------------------------------------------

if (PHP_SAPI === 'cli' && isset($argv) && basename(__FILE__) === basename($argv[0] ?? '')) {
    if (count($argv) < 3) {
        fwrite(STDERR, "Usage: php {$argv[0]} <input.txt> <output.txt>\n");
        exit(1);
    }

    [, $inputFile, $outputFile] = $argv;

    try {
        convert_to_paged_document($inputFile, $outputFile);
        echo "Wrote paginated document to {$outputFile}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: {$e->getMessage()}\n");
        exit(1);
    }
}
