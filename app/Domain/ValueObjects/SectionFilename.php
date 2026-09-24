<?php

namespace App\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Parses a section spreadsheet's filename into its parts. Every section
 * file is named:
 *
 *   COURSE[-MAJOR]-YEARLETTER[-TAG]
 *
 * e.g.:
 *   "BEED-1A.xlsx"                 -> course BEED, no major, section "1A"
 *   "BEED-1E-Working Students.xlsx"-> course BEED, no major, section "1E-Working Students"
 *   "BSBA-FM-1B(ABM).xlsx"         -> course BSBA, major FM,  section "1B(ABM)"
 *   "BSBA-FM-1J-SAT.xlsx"          -> course BSBA, major FM,  section "1J-SAT"
 *   "BSBA-FM (OS)-4A-OS.xlsx"      -> course BSBA, major "FM (OS)", section "4A-OS"
 *   "BSED-ENG-2C.xlsx"             -> course BSED, major ENG,  section "2C"
 *
 * Only BEED/BSIT sections skip the major segment — BSBA and BSED always
 * have one. The rule that actually distinguishes "this token is the
 * major" from "this token is the year+letter" isn't position, it's
 * shape: the first hyphen segment (after the course) that starts with a
 * digit followed by a letter is the year+letter token. Everything
 * between the course and that token is the major; everything from that
 * token onward (including trailing tags like "-SAT", "-OS", or a strand
 * suffix glued on like "(ABM)") becomes the section name, since that's
 * the part that actually distinguishes one roster from every other
 * roster in the same course/major/year.
 */
final class SectionFilename
{
    private function __construct(
        public readonly string $courseCode,
        public readonly ?string $major,
        public readonly string $yearLevel,
        public readonly string $section,
    ) {}

    public static function parse(string $filename): self
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);

        $tokens = array_values(array_filter(
            array_map('trim', explode('-', $stem)),
            fn (string $token) => $token !== '',
        ));

        if ($tokens === []) {
            throw new InvalidArgumentException(
                "Could not read a section name from \"{$filename}\".",
            );
        }

        $courseCode = strtoupper($tokens[0]);

        $yearIndex = null;
        for ($i = 1; $i < count($tokens); $i++) {
            if (preg_match('/^\d+[A-Za-z]/', $tokens[$i]) === 1) {
                $yearIndex = $i;
                break;
            }
        }

        if ($yearIndex === null) {
            throw new InvalidArgumentException(
                "Could not find a year + section letter (e.g. \"1A\") in \"{$filename}\". ".
                'Expected the file to be named COURSE-[MAJOR-]YEARLETTER[-TAG], e.g. "BSIT-1A.xlsx" or "BSBA-FM-1H.xlsx".',
            );
        }

        $major = $yearIndex > 1
            ? implode('-', array_slice($tokens, 1, $yearIndex - 1))
            : null;

        $section = implode('-', array_slice($tokens, $yearIndex));

        preg_match('/^\d+/', $tokens[$yearIndex], $matches);
        $yearLevel = $matches[0];

        return new self($courseCode, $major, $yearLevel, $section);
    }
}
