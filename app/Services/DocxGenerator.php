<?php
declare(strict_types=1);

namespace CvTailor\Services;

use RuntimeException;

final class DocxGenerator
{
    /**
     * Escape text for use inside an OOXML text node.
     */
    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Convert a small, safe subset of inline Markdown to WordprocessingML runs.
     * Supported formatting: **bold**, __bold__, and *italic*.
     */
    private static function inlineMarkdownToRuns(string $text): string
    {
        $parts = preg_split(
            '/(\*\*.+?\*\*|__.+?__|(?<!\*)\*[^*\r\n]+\*(?!\*))/u',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        if ($parts === false || $parts === []) {
            $parts = [$text];
        }

        $runs = '';

        foreach ($parts as $part) {
            $bold = false;
            $italic = false;
            $content = $part;

            if (
                (str_starts_with($part, '**') && str_ends_with($part, '**')) ||
                (str_starts_with($part, '__') && str_ends_with($part, '__'))
            ) {
                $bold = true;
                $content = substr($part, 2, -2);
            } elseif (str_starts_with($part, '*') && str_ends_with($part, '*')) {
                $italic = true;
                $content = substr($part, 1, -1);
            }

            if ($content === '') {
                continue;
            }

            $runProperties = '';
            if ($bold || $italic) {
                $runProperties = '<w:rPr>' .
                    ($bold ? '<w:b/>' : '') .
                    ($italic ? '<w:i/>' : '') .
                    '</w:rPr>';
            }

            $runs .= '<w:r>' . $runProperties .
                '<w:t xml:space="preserve">' . self::xmlEscape($content) . '</w:t>' .
                '</w:r>';
        }

        return $runs;
    }

    /**
     * Build a Word paragraph.
     */
    private static function makeParagraph(
        string $text,
        ?string $style = null,
        ?int $numberingId = null
    ): string {
        $properties = '';

        if ($style !== null || $numberingId !== null) {
            $properties = '<w:pPr>';

            if ($style !== null) {
                $properties .= '<w:pStyle w:val="' . self::xmlEscape($style) . '"/>';
            }

            if ($numberingId !== null) {
                $properties .= '<w:numPr>' .
                    '<w:ilvl w:val="0"/>' .
                    '<w:numId w:val="' . $numberingId . '"/>' .
                    '</w:numPr>';
            }

            $properties .= '</w:pPr>';
        }

        return '<w:p>' . $properties . self::inlineMarkdownToRuns($text) . '</w:p>';
    }

    /**
     * Convert restricted Markdown into WordprocessingML paragraphs.
     */
    private static function markdownToDocumentXml(string $markdown): string
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $body = '';
        $previousWasBlank = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                if (!$previousWasBlank) {
                    $body .= '<w:p/>';
                    $previousWasBlank = true;
                }
                continue;
            }

            $previousWasBlank = false;

            if (preg_match('/^(#{1,4})\s+(.+)$/u', $trimmed, $matches)) {
                $level = strlen($matches[1]);
                $style = match ($level) {
                    1 => 'Title',
                    2 => 'Heading1',
                    3 => 'Heading2',
                    default => 'Heading3',
                };

                $body .= self::makeParagraph($matches[2], $style);
                continue;
            }

            if (preg_match('/^(?:[-*•])\s+(.+)$/u', $trimmed, $matches)) {
                $body .= self::makeParagraph($matches[1], 'ListParagraph', 1);
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/u', $trimmed, $matches)) {
                $body .= self::makeParagraph($matches[1], 'ListParagraph', 2);
                continue;
            }

            if ($trimmed === '---' || $trimmed === '***') {
                $body .= '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="94A3B8"/></w:pBdr><w:spacing w:before="80" w:after="80"/></w:pPr></w:p>';
                continue;
            }

            $body .= self::makeParagraph($trimmed);
        }

        // No w:headerReference or w:footerReference is included, so the DOCX
        // contains no page header, footer, title, date, or time.
        $body .= '<w:sectPr>' .
            '<w:pgSz w:w="11906" w:h="16838"/>' .
            '<w:pgMar w:top="936" w:right="936" w:bottom="936" w:left="936" w:header="0" w:footer="0" w:gutter="0"/>' .
            '</w:sectPr>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' .
            '<w:body>' . $body . '</w:body>' .
            '</w:document>';
    }

    /**
     * Write a basic standards-compliant ZIP archive using only core PHP.
     * Entries are stored without compression, avoiding any dependency on
     * ZipArchive, Composer, or third-party packages.
     *
     * @param array<string,string> $files
     */
    private static function writeStoredZip(array $files, string $targetPath): void
    {
        $localData = '';
        $centralDirectory = '';
        $offset = 0;
        $entryCount = 0;

        // Fixed ZIP entry date: 1 January 1980, 00:00.
        // This avoids embedding the generation date/time in the DOCX package.
        $dosTime = 0;
        $dosDate = 33;

        foreach ($files as $name => $content) {
            $name = str_replace('\\', '/', trim($name));

            if ($name === '' || str_contains($name, '../') || str_starts_with($name, '/')) {
                throw new RuntimeException('Invalid DOCX archive entry name.');
            }

            $nameLength = strlen($name);
            $size = strlen($content);
            $crc = (int) sprintf('%u', crc32($content));

            $localHeader = pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $crc,
                $size,
                $size,
                $nameLength,
                0
            );

            $localData .= $localHeader . $name . $content;

            $centralHeader = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $crc,
                $size,
                $size,
                $nameLength,
                0,
                0,
                0,
                0,
                0,
                $offset
            );

            $centralDirectory .= $centralHeader . $name;
            $offset += strlen($localHeader) + $nameLength + $size;
            $entryCount++;
        }

        $endOfCentralDirectory = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $entryCount,
            $entryCount,
            strlen($centralDirectory),
            strlen($localData),
            0
        );

        $bytesWritten = file_put_contents(
            $targetPath,
            $localData . $centralDirectory . $endOfCentralDirectory,
            LOCK_EX
        );

        if ($bytesWritten === false || $bytesWritten <= 0) {
            throw new RuntimeException('Could not write the DOCX file.');
        }
    }

    /**
     * Create a standards-compliant OOXML DOCX using only core PHP.
     */
    public function create(string $markdown, string $targetPath): void
    {

        $contentTypes = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
      <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
      <Default Extension="xml" ContentType="application/xml"/>
      <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
      <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
      <Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>
    </Types>
    XML;

        $rootRelationships = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
      <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
    </Relationships>
    XML;

        $documentRelationships = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
      <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
      <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>
    </Relationships>
    XML;

        $styles = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
      <w:docDefaults>
        <w:rPrDefault>
          <w:rPr>
            <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:eastAsia="Arial" w:cs="Arial"/>
            <w:sz w:val="21"/>
            <w:szCs w:val="21"/>
            <w:color w:val="111827"/>
            <w:lang w:val="en-US"/>
          </w:rPr>
        </w:rPrDefault>
        <w:pPrDefault>
          <w:pPr>
            <w:spacing w:after="40" w:line="276" w:lineRule="auto"/>
          </w:pPr>
        </w:pPrDefault>
      </w:docDefaults>

      <w:style w:type="paragraph" w:default="1" w:styleId="Normal">
        <w:name w:val="Normal"/>
        <w:qFormat/>
      </w:style>

      <w:style w:type="paragraph" w:styleId="Title">
        <w:name w:val="Title"/>
        <w:basedOn w:val="Normal"/>
        <w:next w:val="Normal"/>
        <w:qFormat/>
        <w:pPr><w:spacing w:before="0" w:after="100"/></w:pPr>
        <w:rPr><w:b/><w:sz w:val="40"/><w:szCs w:val="40"/><w:color w:val="0F172A"/></w:rPr>
      </w:style>

      <w:style w:type="paragraph" w:styleId="Heading1">
        <w:name w:val="Heading 1"/>
        <w:basedOn w:val="Normal"/>
        <w:next w:val="Normal"/>
        <w:qFormat/>
        <w:pPr>
          <w:keepNext/><w:keepLines/>
          <w:spacing w:before="240" w:after="80"/>
          <w:pBdr><w:bottom w:val="single" w:sz="6" w:space="3" w:color="475569"/></w:pBdr>
          <w:outlineLvl w:val="0"/>
        </w:pPr>
        <w:rPr><w:b/><w:sz w:val="25"/><w:szCs w:val="25"/><w:color w:val="0F172A"/></w:rPr>
      </w:style>

      <w:style w:type="paragraph" w:styleId="Heading2">
        <w:name w:val="Heading 2"/>
        <w:basedOn w:val="Normal"/>
        <w:next w:val="Normal"/>
        <w:qFormat/>
        <w:pPr><w:keepNext/><w:keepLines/><w:spacing w:before="160" w:after="40"/><w:outlineLvl w:val="1"/></w:pPr>
        <w:rPr><w:b/><w:sz w:val="22"/><w:szCs w:val="22"/><w:color w:val="1E293B"/></w:rPr>
      </w:style>

      <w:style w:type="paragraph" w:styleId="Heading3">
        <w:name w:val="Heading 3"/>
        <w:basedOn w:val="Normal"/>
        <w:next w:val="Normal"/>
        <w:qFormat/>
        <w:pPr><w:keepNext/><w:keepLines/><w:spacing w:before="120" w:after="30"/><w:outlineLvl w:val="2"/></w:pPr>
        <w:rPr><w:b/><w:sz w:val="21"/><w:szCs w:val="21"/><w:color w:val="334155"/></w:rPr>
      </w:style>

      <w:style w:type="paragraph" w:styleId="ListParagraph">
        <w:name w:val="List Paragraph"/>
        <w:basedOn w:val="Normal"/>
        <w:qFormat/>
        <w:pPr><w:ind w:left="420" w:hanging="240"/><w:spacing w:after="30"/></w:pPr>
      </w:style>
    </w:styles>
    XML;

        $numbering = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
      <w:abstractNum w:abstractNumId="0">
        <w:multiLevelType w:val="singleLevel"/>
        <w:lvl w:ilvl="0">
          <w:start w:val="1"/>
          <w:numFmt w:val="bullet"/>
          <w:lvlText w:val="•"/>
          <w:lvlJc w:val="left"/>
          <w:pPr><w:tabs><w:tab w:val="num" w:pos="420"/></w:tabs><w:ind w:left="420" w:hanging="240"/></w:pPr>
          <w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/></w:rPr>
        </w:lvl>
      </w:abstractNum>
      <w:abstractNum w:abstractNumId="1">
        <w:multiLevelType w:val="singleLevel"/>
        <w:lvl w:ilvl="0">
          <w:start w:val="1"/>
          <w:numFmt w:val="decimal"/>
          <w:lvlText w:val="%1."/>
          <w:lvlJc w:val="left"/>
          <w:pPr><w:tabs><w:tab w:val="num" w:pos="420"/></w:tabs><w:ind w:left="420" w:hanging="240"/></w:pPr>
        </w:lvl>
      </w:abstractNum>
      <w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>
      <w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num>
    </w:numbering>
    XML;

        self::writeStoredZip([
            '[Content_Types].xml' => $contentTypes,
            '_rels/.rels' => $rootRelationships,
            'word/document.xml' => self::markdownToDocumentXml($markdown),
            'word/styles.xml' => $styles,
            'word/numbering.xml' => $numbering,
            'word/_rels/document.xml.rels' => $documentRelationships,
        ], $targetPath);
    }
}
