<?php

namespace App\AI6\Tickets;

/** Locates structural level-two ticket headings while ignoring fenced code. */
final readonly class TicketSectionLocator
{
    /** @return list<array{title: string, offset: int, content_offset: int}> */
    public function levelTwoHeadings(string $content): array
    {
        $headings = [];
        $offset = 0;
        $length = strlen($content);
        $fenceCharacter = null;
        $fenceLength = 0;

        while ($offset < $length) {
            $lineFeed = strpos($content, "\n", $offset);
            $nextOffset = $lineFeed === false ? $length : $lineFeed + 1;
            $lineLength = ($lineFeed === false ? $length : $lineFeed) - $offset;
            $line = substr($content, $offset, $lineLength);
            if (str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }

            if ($fenceCharacter !== null) {
                $closing = '/^[ \t]{0,3}'.preg_quote($fenceCharacter, '/').'{'.$fenceLength.',}[ \t]*$/D';
                if (preg_match($closing, $line) === 1) {
                    $fenceCharacter = null;
                    $fenceLength = 0;
                }
            } elseif (preg_match('/^[ \t]{0,3}(`{3,}|~{3,})/', $line, $fence) === 1) {
                $fenceCharacter = $fence[1][0];
                $fenceLength = strlen($fence[1]);
            } elseif (preg_match('/^##(?!#)[ \t]+(.+?)[ \t]*$/D', $line, $heading) === 1) {
                $headings[] = [
                    'title' => $heading[1],
                    'offset' => $offset,
                    'content_offset' => $nextOffset,
                ];
            }

            $offset = $nextOffset;
        }

        return $headings;
    }
}
