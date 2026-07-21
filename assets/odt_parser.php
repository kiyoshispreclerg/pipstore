<?php
declare(strict_types=1);

/**
 * Parses an ODT file and returns an array of elements.
 * Each element: ['type'=>'heading'|'paragraph', 'level'=>int, 'text'=>string]
 * Italic runs are wrapped in <em>...</em> in the text.
 */
function odt_parse(string $filepath): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('A extensão ZipArchive do PHP não está disponível.');
    }
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        throw new RuntimeException('Não foi possível abrir o arquivo ODT.');
    }
    $content_xml = $zip->getFromName('content.xml');
    $zip->close();
    if ($content_xml === false) {
        throw new RuntimeException('content.xml não encontrado no arquivo ODT.');
    }

    $dom = new DOMDocument();
    $dom->loadXML($content_xml, LIBXML_NOERROR | LIBXML_NOWARNING);

    $xp = new DOMXPath($dom);
    foreach ([
        'text'   => 'urn:oasis:names:tc:opendocument:xmlns:text:1.0',
        'style'  => 'urn:oasis:names:tc:opendocument:xmlns:style:1.0',
        'fo'     => 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0',
        'office' => 'urn:oasis:names:tc:opendocument:xmlns:office:1.0',
    ] as $prefix => $uri) {
        $xp->registerNamespace($prefix, $uri);
    }

    $italic_styles = _odt_italic_styles($xp);

    $elements = [];
    $body_text = $xp->query('//office:body/office:text');
    if ($body_text->length === 0) return $elements;

    foreach ($body_text->item(0)->childNodes as $node) {
        if (!($node instanceof DOMElement)) continue;
        $el = _odt_node($node, $xp, $italic_styles);
        if ($el !== null) $elements[] = $el;
    }

    return $elements;
}

function _odt_italic_styles(DOMXPath $xp): array {
    $has_italic = [];
    $parents    = [];

    $styles = $xp->query('//style:style');
    foreach ($styles as $s) {
        $name   = $s->getAttribute('style:name');
        $parent = $s->getAttribute('style:parent-style-name');
        if ($parent !== '') $parents[$name] = $parent;

        $props = $xp->query('.//style:text-properties', $s);
        foreach ($props as $p) {
            if ($p->getAttribute('fo:font-style') === 'italic') {
                $has_italic[$name] = true;
            }
        }
    }

    // Resolve one level of parent inheritance
    foreach ($parents as $name => $parent) {
        if (isset($has_italic[$parent])) $has_italic[$name] = true;
    }
    // Second pass for deeper chains
    foreach ($parents as $name => $parent) {
        if (isset($has_italic[$parent])) $has_italic[$name] = true;
    }

    return $has_italic;
}

function _odt_node(DOMElement $node, DOMXPath $xp, array $italic): ?array {
    $local = $node->localName;

    if ($local === 'h') {
        $level = (int)($node->getAttribute('text:outline-level') ?: 1);
        $text  = trim(_odt_text($node, $italic));
        return $text === '' ? null : ['type' => 'heading', 'level' => $level, 'text' => $text];
    }

    if ($local === 'p') {
        $style       = $node->getAttribute('text:style-name');
        $clean_style = str_replace('_20_', ' ', $style);
        // Detect heading by style name pattern (e.g. "Heading 1", "Título 1")
        if (preg_match('/^(?:Heading|T[ií]tulo|Cabeçalho)\s+(\d+)$/i', $clean_style, $m)) {
            $text = trim(_odt_text($node, $italic));
            return $text === '' ? null : ['type' => 'heading', 'level' => (int)$m[1], 'text' => $text];
        }
        $text = trim(_odt_text($node, $italic));
        return $text === '' ? null : ['type' => 'paragraph', 'level' => 0, 'text' => $text];
    }

    return null;
}

function _odt_text(DOMNode $node, array $italic): string {
    $out = '';
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            $out .= $child->nodeValue;
        } elseif ($child instanceof DOMElement) {
            $local = $child->localName;
            if ($local === 'span') {
                $style = $child->getAttribute('text:style-name');
                $inner = _odt_text($child, $italic);
                $out  .= (isset($italic[$style]) && $inner !== '')
                       ? '<em>' . $inner . '</em>'
                       : $inner;
            } elseif ($local === 's') {
                $out .= str_repeat(' ', max(1, (int)($child->getAttribute('text:c') ?: 1)));
            } elseif ($local === 'line-break') {
                $out .= "\n";
            } elseif (in_array($local, ['tab', 'footnote', 'note', 'annotation'], true)) {
                // skip
            } else {
                $out .= _odt_text($child, $italic);
            }
        }
    }
    return $out;
}

/**
 * Returns sorted unique heading levels found in parsed elements.
 */
function odt_heading_levels(array $elements): array {
    $levels = [];
    foreach ($elements as $el) {
        if ($el['type'] === 'heading') $levels[$el['level']] = true;
    }
    ksort($levels);
    return array_keys($levels);
}

/**
 * Splits elements into chapters.
 *
 * @param int    $chapter_level  Which heading level creates chapter breaks (ignored when $all_levels=true)
 * @param string $sub_sep        String inserted in content when a non-chapter heading appears
 * @param bool   $all_levels     If true, every heading level creates a new chapter
 */
function odt_split_chapters(array $elements, int $chapter_level, string $sub_sep, bool $all_levels): array {
    $chapters = [];
    $current  = null;

    foreach ($elements as $el) {
        $is_break = $el['type'] === 'heading' && ($all_levels || $el['level'] === $chapter_level);
        $is_sub   = $el['type'] === 'heading' && !$all_levels && $el['level'] !== $chapter_level;

        if ($is_break) {
            if ($current !== null) $chapters[] = $current;
            $current = ['title' => $el['text'], 'content' => ''];
        } elseif ($is_sub) {
            if ($current === null) $current = ['title' => '', 'content' => ''];
            if ($sub_sep !== '') {
                if ($current['content'] !== '') $current['content'] .= "\n";
                $current['content'] .= $sub_sep;
            }
        } else {
            // paragraph
            if ($current === null) $current = ['title' => '', 'content' => ''];
            if ($current['content'] !== '') $current['content'] .= "\n";
            $current['content'] .= $el['text'];
        }
    }

    if ($current !== null && ($current['title'] !== '' || trim($current['content']) !== '')) {
        $chapters[] = $current;
    }

    return $chapters;
}
