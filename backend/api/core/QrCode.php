<?php
declare(strict_types=1);

namespace MailArchive\Core;

/**
 * Generatore autonomo di Codici QR in puro PHP (SVG Data URL).
 * Zero dipendenze esterne, funziona su qualsiasi server PHP 8.1+.
 */
class QrCode
{
    /**
     * Genera un Data URL SVG per il testo specificato (es. otpauth://...)
     */
    public static function svgDataUrl(string $text, int $size = 240): string
    {
        $matrix = self::generateMatrix($text);
        $count = count($matrix);
        $pixelSize = max(1, (int) floor($size / $count));
        $actualSize = $count * $pixelSize;

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="' . $actualSize . '" height="' . $actualSize . '" viewBox="0 0 ' . $actualSize . ' ' . $actualSize . '">';
        $svg .= '<rect width="100%" height="100%" fill="#ffffff"/>';

        for ($r = 0; $r < $count; $r++) {
            for ($c = 0; $c < $count; $c++) {
                if ($matrix[$r][$c]) {
                    $x = $c * $pixelSize;
                    $y = $r * $pixelSize;
                    $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $pixelSize . '" height="' . $pixelSize . '" fill="#0f172a"/>';
                }
            }
        }

        $svg .= '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Genera la matrice QR (Standard QR Code Model 2)
     */
    private static function generateMatrix(string $data): array
    {
        // Seleziona la versione minima adatta
        $len = strlen($data);
        $version = 3; // 29x29
        if ($len > 50) $version = 4; // 33x33
        if ($len > 80) $version = 5; // 37x37
        if ($len > 110) $version = 6; // 41x41

        $size = 17 + ($version * 4);
        $modules = array_fill(0, $size, array_fill(0, $size, null));

        // 1. Finder patterns (angolo top-left, top-right, bottom-left)
        self::addFinderPattern($modules, 0, 0);
        self::addFinderPattern($modules, $size - 7, 0);
        self::addFinderPattern($modules, 0, $size - 7);

        // 2. Separators
        self::addSeparators($modules, $size);

        // 3. Timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            $val = ($i % 2 === 0);
            if ($modules[6][$i] === null) $modules[6][$i] = $val;
            if ($modules[$i][6] === null) $modules[$i][6] = $val;
        }

        // 4. Alignment pattern per versioni >= 2
        if ($version >= 2) {
            $alignPos = [
                2 => [6, 18],
                3 => [6, 22],
                4 => [6, 26],
                5 => [6, 30],
                6 => [6, 34]
            ][$version] ?? [6, 26];

            foreach ($alignPos as $r) {
                foreach ($alignPos as $c) {
                    if ($modules[$r][$c] === null) {
                        self::addAlignmentPattern($modules, $r, $c);
                    }
                }
            }
        }

        // 5. Dark module e Format info riservata
        $modules[$size - 8][8] = true;
        for ($i = 0; $i < 9; $i++) {
            if ($modules[8][$i] === null) $modules[8][$i] = false;
            if ($modules[$i][8] === null) $modules[$i][8] = false;
        }
        for ($i = $size - 8; $i < $size; $i++) {
            if ($modules[8][$i] === null) $modules[8][$i] = false;
            if ($modules[$i][8] === null) $modules[$i][8] = false;
        }

        // 6. Codifica dati (Byte Mode)
        $bits = self::encodeData($data, $version);

        // 7. Riempimento dati nella matrice a zigzag
        $bitIdx = 0;
        $numBits = strlen($bits);
        $dir = -1; // verso l'alto
        $c = $size - 1;
        $r = $size - 1;

        while ($c > 0) {
            if ($c === 6) $c--; // Salta la colonna del timing pattern verticale

            for ($step = 0; $step < $size; $step++) {
                $currR = $dir === -1 ? ($size - 1 - $step) : $step;

                for ($colOff = 0; $colOff < 2; $colOff++) {
                    $currC = $c - $colOff;
                    if ($modules[$currR][$currC] === null) {
                        $bit = ($bitIdx < $numBits) ? ($bits[$bitIdx] === '1') : false;
                        $bitIdx++;

                        // Maschera pattern 0: (row + col) % 2 == 0
                        $mask = (($currR + $currC) % 2 === 0);
                        $modules[$currR][$currC] = $bit ^ $mask;
                    }
                }
            }
            $dir = -$dir;
            $c -= 2;
        }

        // Applica Format Info per Mask Pattern 0 (L: 01, Mask 0: 000 -> 01000 con BCH: 111011111000100)
        self::applyFormatInfo($modules, $size, '111011111000100');

        // Sostituisci eventuali null rimanenti con false
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($modules[$r][$c] === null) $modules[$r][$c] = false;
            }
        }

        return $modules;
    }

    private static function addFinderPattern(array &$m, int $startX, int $startY): void
    {
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                $isBorder = ($r === 0 || $r === 6 || $c === 0 || $c === 6);
                $isCenter = ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $m[$startY + $r][$startX + $c] = ($isBorder || $isCenter);
            }
        }
    }

    private static function addSeparators(array &$m, int $size): void
    {
        for ($i = 0; $i < 8; $i++) {
            if ($i < $size) {
                $m[7][$i] = false;
                $m[$i][7] = false;
                $m[$size - 8][$i] = false;
                $m[$size - 1 - $i][7] = false;
                $m[7][$size - 1 - $i] = false;
                $m[$i][$size - 8] = false;
            }
        }
    }

    private static function addAlignmentPattern(array &$m, int $centerR, int $centerC): void
    {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $isBorder = (abs($r) === 2 || abs($c) === 2);
                $isCenter = ($r === 0 && $c === 0);
                $m[$centerR + $r][$centerC + $c] = ($isBorder || $isCenter);
            }
        }
    }

    private static function applyFormatInfo(array &$m, int $size, string $formatBits): void
    {
        // Top-left attorno al finder
        $coords1 = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]
        ];

        // Bottom-left e Top-right
        $coords2 = [
            [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8],
            [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
            [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5],
            [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1]
        ];

        for ($i = 0; $i < 15; $i++) {
            $bit = ($formatBits[$i] === '1');
            $m[$coords1[$i][0]][$coords1[$i][1]] = $bit;
            $m[$coords2[$i][0]][$coords2[$i][1]] = $bit;
        }
    }

    private static function encodeData(string $data, int $version): string
    {
        // Mode indicator 0100 (Byte mode)
        $bits = '0100';
        // Character count indicator (8 bits per version 1-9)
        $bits .= str_pad(decbin(strlen($data)), 8, '0', STR_PAD_LEFT);

        // Data bytes
        for ($i = 0; $i < strlen($data); $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Terminator (fino a 4 bit 0)
        $capacity = [
            3 => 55 * 8,
            4 => 80 * 8,
            5 => 108 * 8,
            6 => 136 * 8
        ][$version] ?? (80 * 8);

        $termLen = min(4, $capacity - strlen($bits));
        if ($termLen > 0) {
            $bits .= str_repeat('0', $termLen);
        }

        // Padding to byte boundary
        while (strlen($bits) % 8 !== 0) {
            $bits .= '0';
        }

        // Pad codewords
        $padBytes = ['11101100', '00010001'];
        $padIdx = 0;
        while (strlen($bits) < $capacity) {
            $bits .= $padBytes[$padIdx % 2];
            $padIdx++;
        }

        return $bits;
    }
}
