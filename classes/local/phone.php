<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_alborhansignup\local;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * Phone number validation and normalisation to E.164 (+9665XXXXXXXX), using the libphonenumber copy shipped with core.
 *
 * None of the methods throw on bad input: they return null/false, so they are safe to call from hooks.
 *
 * @package    local_alborhansignup
 * @copyright  2026 Alborhan Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class phone {
    /** @var string Region used for numbers typed without an international prefix when no usable country is known. */
    public const DEFAULT_REGION = 'SA';

    /**
     * Replace Arabic-Indic, Persian and full-width digits, and the full-width plus sign, with ASCII.
     *
     * @param string $raw
     * @return string
     */
    public static function normalise_digits(string $raw): string {
        $map = [\core_text::code2utf8(0xFF0B) => '+'];
        for ($i = 0; $i <= 9; $i++) {
            $map[\core_text::code2utf8(0x0660 + $i)] = (string) $i;
            $map[\core_text::code2utf8(0x06F0 + $i)] = (string) $i;
            $map[\core_text::code2utf8(0xFF10 + $i)] = (string) $i;
        }
        return strtr($raw, $map);
    }

    /**
     * Region libphonenumber should assume for a number without an international prefix.
     *
     * @param string|null $country Moodle country code (ISO 3166-1 alpha-2), e.g. from the user's profile
     * @return string
     */
    public static function region(?string $country): string {
        $country = strtoupper(trim((string) $country));
        if ($country !== '' && in_array($country, self::util()->getSupportedRegions(), true)) {
            return $country;
        }
        return self::DEFAULT_REGION;
    }

    /**
     * Parse a typed number into a valid phone number.
     *
     * A number without an international prefix is read in the region of $country. When that region is not the
     * default one and the same digits are also a valid number in the default region (e.g. "0501234567" is a mobile
     * number both in Saudi Arabia and in the United Arab Emirates), the number is ambiguous and rejected: the user
     * must type the country code.
     *
     * @param string $raw number as typed, e.g. "+966 50 123 4567", "0501234567" or "٠٥٠١٢٣٤٥٦٧"
     * @param string|null $country Moodle country code used for numbers without an international prefix
     * @return PhoneNumber|null null when the number cannot be parsed, is not valid, is ambiguous or has an extension
     */
    public static function parse(string $raw, ?string $country = null): ?PhoneNumber {
        $raw = trim(self::normalise_digits($raw));
        if ($raw === '') {
            return null;
        }
        $region = self::region($country);
        $number = self::parse_in_region($raw, $region);
        if ($number === null || $number->hasExtension()) {
            return null;
        }
        $international = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        if (!$international && $region !== self::DEFAULT_REGION && self::parse_in_region($raw, self::DEFAULT_REGION)) {
            return null;
        }
        return $number;
    }

    /**
     * Parse in the given region; null unless the result is a valid number.
     *
     * @param string $raw number with ASCII digits
     * @param string $region libphonenumber region code
     * @return PhoneNumber|null
     */
    private static function parse_in_region(string $raw, string $region): ?PhoneNumber {
        $util = self::util();
        try {
            $number = $util->parse($raw, $region);
        } catch (NumberParseException | \RuntimeException $e) {
            // The library also throws RuntimeException on some unusual Unicode input.
            return null;
        }
        return $util->isValidNumber($number) ? $number : null;
    }

    /**
     * Normalise a typed number to E.164.
     *
     * @param string $raw number as typed
     * @param string|null $country Moodle country code used for numbers without an international prefix
     * @return string|null E.164 number (at most 16 characters), or null when the number is not valid
     */
    public static function to_e164(string $raw, ?string $country = null): ?string {
        $number = self::parse($raw, $country);
        return $number === null ? null : self::util()->format($number, PhoneNumberFormat::E164);
    }

    /**
     * Whether the typed number is a valid mobile number.
     *
     * Numbers whose type cannot be told apart from a landline (FIXED_LINE_OR_MOBILE, e.g. North America) are accepted.
     *
     * @param string $raw number as typed
     * @param string|null $country Moodle country code used for numbers without an international prefix
     * @return bool
     */
    public static function is_valid_mobile(string $raw, ?string $country = null): bool {
        $number = self::parse($raw, $country);
        if ($number === null) {
            return false;
        }
        $type = self::util()->getNumberType($number);
        return $type === PhoneNumberType::MOBILE || $type === PhoneNumberType::FIXED_LINE_OR_MOBILE;
    }

    /**
     * The shared libphonenumber instance.
     *
     * @return PhoneNumberUtil
     */
    private static function util(): PhoneNumberUtil {
        return \core\di::get(PhoneNumberUtil::class);
    }
}
