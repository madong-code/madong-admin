<?php
declare(strict_types=1);

/**
 *+------------------
 * madong
 *+------------------
 * 纯 PHP 的 Markdown -> DOCX 转换器（自包含，无第三方 docx 依赖）
 *
 * 思路：erusev/parsedown（已为项目依赖）将 Markdown 转为 HTML，
 * 再用 PHP 内置 DOMDocument 解析 HTML，遍历节点手写 OOXML（zip+xml）。
 * 支持：标题、段落、有序/无序列表、表格、代码块、引用、分隔线、加粗/斜体/行内代码。
 *
 * @author Mr.April
 * @since 1.0.0
 */

namespace app\command\plugin;

class MarkdownDocxConverter
{
    /**
     * 将多个 Markdown 文档与封面元数据转换为 docx 二进制内容
     *
     * @param string[] $markdownDocs 按顺序排列的 markdown 文本
     * @param array    $meta         ['title'=>,'subtitle'=>,'meta'=>]
     */
    public function convert(array $markdownDocs, array $meta): string
    {
        if (!class_exists(\ZipArchive::class) || !class_exists(\DOMDocument::class)) {
            throw new \RuntimeException('需要 ZipArchive 与 DOMDocument 扩展');
        }

        $parsedown = new \Parsedown();
        $body = $this->coverXml($meta);
        foreach ($markdownDocs as $i => $md) {
            $html = $parsedown->text($md);
            // 每篇文档从新页开始，避免连续堆叠
            if ($i > 0) {
                $body .= '<w:p><w:pPr><w:pageBreakBefore/></w:pPr></w:p>';
            }
            $body .= $this->htmlToBodyXml($html);
        }

        return $this->buildPackage($body, $meta);
    }

    // ───────────────────────── 封面 ─────────────────────────

    private function coverXml(array $meta): string
    {
        $title = $meta['title'] ?? '插件文档';
        $subtitle = $meta['subtitle'] ?? '';
        $metaLine = $meta['meta'] ?? '';

        $xml = '';
        $xml .= $this->paragraph(
            $this->run($title, ['<w:sz w:val="52"/>', '<w:b/>']),
            '',
            '<w:jc w:val="center"/><w:spacing w:before="2400"/>'
        );
        if ($subtitle !== '') {
            $xml .= $this->paragraph(
                $this->run($subtitle, ['<w:sz w:val="28"/>', '<w:color w:val="555555"/>']),
                '',
                '<w:jc w:val="center"/><w:spacing w:before="200"/>'
            );
        }
        if ($metaLine !== '') {
            $xml .= $this->paragraph(
                $this->run($metaLine, ['<w:sz w:val="22"/>', '<w:color w:val="888888"/>']),
                '',
                '<w:jc w:val="center"/><w:spacing w:before="400"/>'
            );
        }
        // 分页符：封面后另起一页
        $xml .= '<w:p><w:pPr><w:pageBreakBefore/></w:pPr></w:p>';
        return $xml;
    }

    // ───────────────────────── HTML -> Body XML ─────────────────────────

    private function htmlToBodyXml(string $html): string
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // 前置 XML 声明强制以 UTF-8 解析，避免中文乱码
        $dom->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            $htmlEl = $dom->getElementsByTagName('html')->item(0);
            $body = $htmlEl ? $htmlEl->getElementsByTagName('body')->item(0) : $dom->documentElement;
        }
        if (!$body) {
            return '';
        }
        return $this->renderBlocks($body->childNodes);
    }

    private function renderBlocks(\DOMNodeList $nodes, string $indentPPr = ''): string
    {
        $xml = '';
        foreach ($nodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $t = trim($node->nodeValue ?? '');
                if ($t !== '') {
                    $xml .= $this->paragraph($this->run($t), '', $indentPPr);
                }
                continue;
            }
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $tag = strtolower($node->nodeName);
            switch ($tag) {
                case 'h1':
                    $xml .= $this->paragraph($this->runsFromNode($node), 'Heading1', $indentPPr . '<w:spacing w:before="280" w:after="140"/>');
                    break;
                case 'h2':
                    $xml .= $this->paragraph($this->runsFromNode($node), 'Heading2', $indentPPr . '<w:spacing w:before="220" w:after="110"/>');
                    break;
                case 'h3':
                    $xml .= $this->paragraph($this->runsFromNode($node), 'Heading3', $indentPPr . '<w:spacing w:before="160" w:after="80"/>');
                    break;
                case 'p':
                    $xml .= $this->paragraph($this->runsFromNode($node), '', $indentPPr . '<w:spacing w:after="140"/>');
                    break;
                case 'ul':
                    $xml .= $this->renderList($node, false, $this->indentFromPPr($indentPPr));
                    break;
                case 'ol':
                    $xml .= $this->renderList($node, true, $this->indentFromPPr($indentPPr));
                    break;
                case 'table':
                    $xml .= $this->renderTable($node);
                    break;
                case 'pre':
                    $xml .= $this->renderPre($node);
                    break;
                case 'blockquote':
                    $xml .= $this->renderBlocks($node->childNodes, '<w:ind w:left="360"/>');
                    break;
                case 'hr':
                    $xml .= $this->paragraph('', '', '<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="2E75B6"/></w:pBdr>');
                    break;
                case 'div':
                default:
                    $xml .= $this->renderBlocks($node->childNodes, $indentPPr);
                    break;
            }
        }
        return $xml;
    }

    private function renderList(\DOMNode $node, bool $ordered, int $baseIndent = 0): string
    {
        $xml = '';
        $idx = 1;
        $left = $baseIndent + 720;
        $liPPr = '<w:spacing w:before="20" w:after="80"/><w:ind w:left="' . $left . '" w:hanging="360"/>';

        foreach ($node->childNodes as $li) {
            if ($li->nodeType !== XML_ELEMENT_NODE || strtolower($li->nodeName) !== 'li') {
                continue;
            }
            $prefix = $ordered ? $idx . '. ' : '• ';
            $first = true;

            foreach ($this->listItemBlocks($li) as $block) {
                if ($block['type'] === 'inline') {
                    $runs = ($first ? $this->run($prefix) : '') . $block['runs'];
                    // 首块带项目符号（悬挂缩进）；同项的续段仅普通左缩进，避免首行外突
                    $pPr = $first ? $liPPr : '<w:spacing w:before="20" w:after="80"/><w:ind w:left="' . $left . '"/>';
                    $xml .= $this->paragraph($runs, '', $pPr);
                } elseif ($block['type'] === 'pre') {
                    $xml .= $this->renderPre($block['node'], $left);
                } else { // 子列表
                    $xml .= $this->renderList($block['node'], $block['ordered'], $left);
                }
                $first = false;
            }

            if ($first) {
                // 空列表项，仅输出符号
                $xml .= $this->paragraph($this->run($prefix), '', $liPPr);
            }
            $idx++;
        }
        return $xml;
    }

    /**
     * 把 <li> 拆成块级序列（段落 / 代码块 / 子列表），避免嵌套块被压平成同一段。
     */
    private function listItemBlocks(\DOMNode $li): array
    {
        $blocks = [];
        $inline = '';

        $flush = function () use (&$blocks, &$inline) {
            if ($inline !== '') {
                $blocks[] = ['type' => 'inline', 'runs' => $inline];
                $inline = '';
            }
        };

        foreach ($li->childNodes as $c) {
            if ($c->nodeType === XML_TEXT_NODE) {
                $t = $c->nodeValue ?? '';
                if (trim($t) !== '') {
                    $inline .= $this->run($t);
                }
                continue;
            }
            if ($c->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $tag = strtolower($c->nodeName);
            if ($tag === 'p') {
                $inline .= $this->runsFromNode($c);
                $flush();
            } elseif ($tag === 'pre') {
                $flush();
                $blocks[] = ['type' => 'pre', 'node' => $c];
            } elseif ($tag === 'ul' || $tag === 'ol') {
                $flush();
                $blocks[] = ['type' => 'list', 'node' => $c, 'ordered' => $tag === 'ol'];
            } else {
                $inline .= $this->runsFromNode($c);
            }
        }
        $flush();

        return $blocks;
    }

    private function indentFromPPr(string $indentPPr): int
    {
        if (preg_match('/w:left="(\d+)"/', $indentPPr, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    private function renderPre(\DOMNode $node, int $indent = 120): string
    {
        $code = $node->textContent ?? '';
        $code = preg_replace('/^\n+/', '', $code);   // 去掉前置空行
        $code = rtrim($code);
        $lines = explode("\n", $code);
        $runs = '';
        $first = true;
        foreach ($lines as $line) {
            if (!$first) {
                $runs .= '<w:r><w:br/></w:r>';
            }
            $first = false;
            $runs .= $this->run($line);
        }
        // pPr 子元素顺序：shd → spacing → ind
        return $this->paragraph($runs, 'Code', '<w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/><w:spacing w:after="120"/><w:ind w:left="' . $indent . '"/>');
    }

    private function renderTable(\DOMNode $node): string
    {
        $rows = [];
        foreach ($node->childNodes as $section) {
            if ($section->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $st = strtolower($section->nodeName);
            if ($st === 'tr') {
                $rows[] = ['cells' => $this->cellsOf($section), 'header' => false];
            } elseif (in_array($st, ['thead', 'tbody', 'tfoot'], true)) {
                foreach ($section->childNodes as $tr) {
                    if ($tr->nodeType === XML_ELEMENT_NODE && strtolower($tr->nodeName) === 'tr') {
                        $rows[] = ['cells' => $this->cellsOf($tr), 'header' => $st === 'thead'];
                    }
                }
            }
        }
        if ($rows === []) {
            return '';
        }

        $colCount = 0;
        foreach ($rows as $r) {
            $colCount = max($colCount, count($r['cells']));
        }
        if ($colCount === 0) {
            return '';
        }
        $cellPct = intval(5000 / $colCount);

        $border = '<w:tcBorders>'
            . '<w:top w:val="single" w:sz="4" w:color="CCCCCC"/>'
            . '<w:left w:val="single" w:sz="4" w:color="CCCCCC"/>'
            . '<w:bottom w:val="single" w:sz="4" w:color="CCCCCC"/>'
            . '<w:right w:val="single" w:sz="4" w:color="CCCCCC"/>'
            . '</w:tcBorders>';

        $grid = '';
        for ($i = 0; $i < $colCount; $i++) {
            $grid .= '<w:gridCol w:w="' . intval(9026 / $colCount) . '"/>';
        }

        $xml = '<w:tbl><w:tblPr>'
            . '<w:tblpPr w:topFromText="140" w:bottomFromText="140" w:leftFromText="0" w:rightFromText="0"/>'
            . '<w:tblW w:w="5000" w:type="pct"/>'
            . '<w:tblLayout w:type="fixed"/>'
            . '<w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:color="BBBBBB"/>'
            . '<w:left w:val="single" w:sz="4" w:color="BBBBBB"/>'
            . '<w:bottom w:val="single" w:sz="4" w:color="BBBBBB"/>'
            . '<w:right w:val="single" w:sz="4" w:color="BBBBBB"/>'
            . '<w:insideH w:val="single" w:sz="4" w:color="DDDDDD"/>'
            . '<w:insideV w:val="single" w:sz="4" w:color="DDDDDD"/>'
            . '</w:tblBorders>'
            . '<w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="0" w:firstColumn="1" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/>'
            . '</w:tblPr>'
            . '<w:tblGrid>' . $grid . '</w:tblGrid>';

        foreach ($rows as $r) {
            $xml .= '<w:tr>';
            for ($i = 0; $i < $colCount; $i++) {
                $cell = $r['cells'][$i] ?? ['runs' => '', 'header' => false];
                $shd = $cell['header'] ? '<w:shd w:val="clear" w:fill="D5E8F0"/>' : '';
                $xml .= '<w:tc><w:tcPr>'
                    . '<w:tcW w:w="' . $cellPct . '" w:type="pct"/>'
                    . '<w:vAlign w:val="center"/>'
                    . '<w:tcMar><w:top w:w="60" w:type="dxa"/><w:left w:w="90" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/><w:right w:w="90" w:type="dxa"/></w:tcMar>'
                    . $shd . $border
                    . '</w:tcPr><w:p><w:pPr><w:spacing w:before="20" w:after="20"/></w:pPr>' . $cell['runs'] . '</w:p></w:tc>';
            }
            $xml .= '</w:tr>';
        }
        $xml .= '</w:tbl>';
        return $xml;
    }

    private function cellsOf(\DOMNode $tr): array
    {
        $cells = [];
        foreach ($tr->childNodes as $c) {
            if ($c->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $ct = strtolower($c->nodeName);
            if ($ct === 'td' || $ct === 'th') {
                $cells[] = [
                    'runs' => $this->runsFromNode($c),
                    'header' => $ct === 'th',
                ];
            }
        }
        return $cells;
    }

    // ───────────────────────── 行内渲染 ─────────────────────────

    private function runsFromNode(\DOMNode $node, array $rPr = []): string
    {
        $xml = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                // HTML 语义：连续空白（含换行）折叠为单个空格，避免 w:t 内出现换行被渲染成换行
                $text = preg_replace('/[ \t\r\n]+/u', ' ', $child->nodeValue ?? '');
                if ($text !== '') {
                    $xml .= $this->run($text, $rPr);
                }
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($child->nodeName);
                switch ($tag) {
                    case 'br':
                        $xml .= '<w:r><w:br/></w:r>';
                        break;
                    case 'strong':
                    case 'b':
                        $xml .= $this->runsFromNode($child, array_merge($rPr, ['<w:b/>']));
                        break;
                    case 'em':
                    case 'i':
                        $xml .= $this->runsFromNode($child, array_merge($rPr, ['<w:i/>']));
                        break;
                    case 'code':
                        $xml .= $this->runsFromNode($child, array_merge($rPr, [
                            '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas" w:eastAsia="Microsoft YaHei" w:cs="Consolas"/>',
                            '<w:sz w:val="18"/>',
                        ]));
                        break;
                    case 'a':
                    case 'sub':
                    case 'sup':
                    case 'span':
                    default:
                        // 链接仅保留文本，其余未知元素递归取文本
                        $xml .= $this->runsFromNode($child, $rPr);
                        break;
                }
            }
        }
        return $xml;
    }

    private function run(string $text, array $rPr = []): string
    {
        if ($text === '') {
            return '';
        }
        // w:t 内不允许裸换行；CR/LF 一律转空格（代码行的换行由 <w:br/> 表达）
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
        $rPrXml = $rPr !== [] ? '<w:rPr>' . implode('', $rPr) . '</w:rPr>' : '';
        return '<w:r>' . $rPrXml . '<w:t xml:space="preserve">' . $this->esc($text) . '</w:t></w:r>';
    }

    private function paragraph(string $runsXml, string $pStyle = '', string $pPrExtra = ''): string
    {
        $pPr = '';
        if ($pStyle !== '' || $pPrExtra !== '') {
            $inner = '';
            if ($pStyle !== '') {
                $inner .= '<w:pStyle w:val="' . $pStyle . '"/>';
            }
            $inner .= $pPrExtra;
            $pPr = '<w:pPr>' . $inner . '</w:pPr>';
        }
        return '<w:p>' . $pPr . $runsXml . '</w:p>';
    }

    private function esc(string $s): string
    {
        return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $s);
    }

    // ───────────────────────── 打包 OOXML ─────────────────────────

    private function buildPackage(string $bodyXml, array $meta): string
    {
        $title = $this->esc($meta['title'] ?? '插件文档');
        $creator = $this->esc($meta['meta'] ?? 'madong');
        $now = date('Y-m-d\TH:i:s\Z');

        $sectPr = '<w:sectPr>'
            . '<w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>'
            . '</w:sectPr>';

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<w:body>' . $bodyXml . $sectPr . '</w:body></w:document>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';

        $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $styles = $this->buildStyles();

        $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $title . '</dc:title>'
            . '<dc:creator>' . $creator . '</dc:creator>'
            . '<cp:lastModifiedBy>' . $creator . '</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';

        $app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>madong</Application>'
            . '</Properties>';

        $tmp = tempnam(sys_get_temp_dir(), 'docx') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('无法创建临时 zip');
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $document);
        $zip->addFromString('word/_rels/document.xml.rels', $docRels);
        $zip->addFromString('word/styles.xml', $styles);
        $zip->addFromString('docProps/core.xml', $core);
        $zip->addFromString('docProps/app.xml', $app);
        $zip->close();

        $data = file_get_contents($tmp);
        unlink($tmp);
        return $data;
    }

    private function buildStyles(): string
    {
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:eastAsia="Microsoft YaHei" w:cs="Arial"/>'
            . '<w:sz w:val="22"/>'
            . '</w:rPr></w:rPrDefault></w:docDefaults>';

        $styles .= $this->style('Normal', 'Normal', '', '', null);
        $styles .= $this->style('Heading1', 'Heading 1', '', '<w:sz w:val="34"/><w:b/><w:color w:val="1F4E79"/>', 0);
        $styles .= $this->style('Heading2', 'Heading 2', '', '<w:sz w:val="28"/><w:b/><w:color w:val="2E75B6"/>', 1);
        $styles .= $this->style('Heading3', 'Heading 3', '', '<w:sz w:val="24"/><w:b/><w:color w:val="2E75B6"/>', 2);
        $styles .= $this->style(
            'Code',
            'Code',
            '',
            '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas" w:eastAsia="Microsoft YaHei" w:cs="Consolas"/>'
            . '<w:sz w:val="18"/><w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>',
            null
        );

        $styles .= '</w:styles>';
        return $styles;
    }

    private function style(string $id, string $name, string $pPrInner, string $rPrInner, ?int $outlineLvl): string
    {
        $pPr = '';
        if ($pPrInner !== '' || $outlineLvl !== null) {
            $inner = $pPrInner;
            if ($outlineLvl !== null) {
                $inner .= '<w:outlineLvl w:val="' . $outlineLvl . '"/>';
            }
            $pPr = '<w:pPr>' . $inner . '</w:pPr>';
        }
        $rPr = $rPrInner !== '' ? '<w:rPr>' . $rPrInner . '</w:rPr>' : '';
        return '<w:style w:type="paragraph" w:styleId="' . $id . '">'
            . '<w:name w:val="' . $name . '"/>' . $pPr . $rPr . '</w:style>';
    }
}
