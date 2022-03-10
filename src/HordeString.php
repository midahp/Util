<?php
namespace Horde\Util;

use Horde_Util;
use Error;
use Exception;
use Horde_Imap_Client_Utf7imap;
use Horde_Imap_Client_Exception;
use InvalidArgumentException;
/**
 * Provides static methods for charset and locale safe string manipulation.
 *
 * Copyright 2003-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @todo Split up in Horde_String_Multibyte for multibyte-safe methods and
 *       Horde_String_Locale for locale-safe methods.
 *
 * @author   Jan Schneider <jan@horde.org>
 * @category Horde
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package  Util
 */
class HordeString
{
    /**
     * lower() cache.
     *
     * @var array
     */
    protected static array $lowers = [];

    /**
     * upper() cache.
     *
     * @var array
     */
    protected static array $uppers = [];

    /**
     * Converts a string from one charset to another.
     *
     * Uses the iconv or the mbstring extensions.
     * The original string is returned if conversion failed or none
     * of the extensions were available.
     *
     * @param mixed $input    The data to be converted. If $input is an an
     *                        array, the array's values get converted
     *                        recursively.
     * @param string $from    The string's current charset.
     * @param string $to      The charset to convert the string to.
     * @param bool   $force  Force conversion?
     *
     * @return mixed  The converted input data.
     */
    public static function convertCharset($input, string $from, string $to, bool $force = false)
    {
        /* Don't bother converting numbers. */
        if (is_numeric($input)) {
            return $input;
        }

        /* If the from and to character sets are identical, return now. */
        if (!$force && $from == $to) {
            return $input;
        }
        $from = self::lower($from);
        $to = self::lower($to);
        if (!$force && $from == $to) {
            return $input;
        }

        if (is_array($input)) {
            $tmp = [];
            foreach ($input as $key => $val) {
                $tmp[self::_convertCharset($key, $from, $to)] = self::convertCharset($val, $from, $to, $force);
            }
            return $tmp;
        }

        if (is_object($input)) {
            // PEAR_Error/Exception objects are almost guaranteed to contain
            // recursion, which will cause a segfault in PHP. We should never
            // reach this line, but add a check.
            if (($input instanceof Exception) ||
                ($input instanceof Error)) {
                return '';
            }

            $input = clone $input;
            $vars = get_object_vars($input);
            foreach ($vars as $key => $val) {
                $input->$key = self::convertCharset($val, $from, $to, $force);
            }
            return $input;
        }

        if (!is_string($input)) {
            return $input;
        }

        return self::_convertCharset($input, $from, $to);
    }

    /**
     * Internal function used to do charset conversion.
     *
     * @param string $input  See self::convertCharset().
     * @param string $from   See self::convertCharset().
     * @param string $to     See self::convertCharset().
     *
     * @return string  The converted string.
     */
    protected static function _convertCharset(string $input, string $from, string $to): string
    {
        // Initialize, mostly to un-worry static analyzers
        $php_errormsg = '';
        /* Use utf8_[en|de]code() if possible and if the string isn't too
         * large (less than 16 MB = 16 * 1024 * 1024 = 16777216 bytes) - these
         * functions use more memory. */
        if (Util::extensionExists('xml') &&
            ((strlen($input) < 16777216) ||
             !Util::extensionExists('iconv') ||
             !Util::extensionExists('mbstring'))) {
            if (($to == 'utf-8') &&
                in_array($from, ['iso-8859-1', 'us-ascii', 'utf-8'])) {
                return utf8_encode($input);
            }

            if (($from == 'utf-8') &&
                in_array($to, ['iso-8859-1', 'us-ascii', 'utf-8'])) {
                return utf8_decode($input);
            }
        }

        /* Try UTF7-IMAP conversions. */
        if (($from == 'utf7-imap') || ($to == 'utf7-imap')) {
            try {
                if ($from == 'utf7-imap') {
                    return self::convertCharset(Horde_Imap_Client_Utf7imap::Utf7ImapToUtf8($input), 'UTF-8', $to);
                } else {
                    if ($from == 'utf-8') {
                        $conv = $input;
                    } else {
                        $conv = self::convertCharset($input, $from, 'UTF-8');
                    }
                    return Horde_Imap_Client_Utf7imap::Utf8ToUtf7Imap($conv);
                }
            } catch (Horde_Imap_Client_Exception $e) {
                return $input;
            }
        }

        /* Try iconv with transliteration. */
        if (Util::extensionExists('iconv')) {
            unset($php_errormsg);
            ini_set('track_errors', 1);
            $out = @iconv($from, $to . '//TRANSLIT', $input);
            $errmsg = isset($php_errormsg);
            ini_restore('track_errors');
            if (!$errmsg && $out !== false) {
                return $out;
            }
        }

        /* Try mbstring. */
        if (Util::extensionExists('mbstring')) {
            $out = @mb_convert_encoding($input, $to, self::_mbstringCharset($from));
            if (!empty($out)) {
                return $out;
            }
        }

        return $input;
    }

    /**
     * Makes a string lowercase.
     *
     * @param string $string   The string to be converted.
     * @param bool $locale  If true the string will be converted based on
     *                         a given charset, locale independent else.
     * @param string $charset  If $locale is true, the charset to use when
     *                         converting.
     *
     * @return string  The string with lowercase characters.
     */
    public static function lower(string $string, bool $locale = false, ?string $charset = null): string
    {
        if ($locale) {
            if (Util::extensionExists('mbstring')) {
                if (is_null($charset)) {
                    throw new InvalidArgumentException('$charset argument must not be null');
                }
                $ret = @mb_strtolower($string, self::_mbstringCharset($charset));
                if (!empty($ret)) {
                    return $ret;
                }
            }
            return strtolower($string);
        }

        if (!isset(self::$lowers[$string])) {
            $language = setlocale(LC_CTYPE, 0);
            setlocale(LC_CTYPE, 'C');
            self::$lowers[$string] = strtolower($string);
            setlocale(LC_CTYPE, $language);
        }

        return self::$lowers[$string];
    }

    /**
     * Makes a string uppercase.
     *
     * @param string $string   The string to be converted.
     * @param bool   $locale   If true the string will be converted based on a
     *                         given charset, locale independent else.
     * @param string $charset  If $locale is true, the charset to use when
     *                         converting. If not provided the current charset.
     *
     * @return string  The string with uppercase characters.
     */
    public static function upper(string $string, bool $locale = false, ?string $charset = null): string
    {
        if ($locale) {
            if (Util::extensionExists('mbstring')) {
                if (is_null($charset)) {
                    throw new InvalidArgumentException('$charset argument must not be null');
                }
                $ret = @mb_strtoupper($string, self::_mbstringCharset($charset));
                if (!empty($ret)) {
                    return $ret;
                }
            }
            return strtoupper($string);
        }

        if (!isset(self::$uppers[$string])) {
            $language = setlocale(LC_CTYPE, 0);
            setlocale(LC_CTYPE, 'C');
            self::$uppers[$string] = strtoupper($string);
            setlocale(LC_CTYPE, $language);
        }

        return self::$uppers[$string];
    }

    /**
     * Returns a string with the first letter capitalized if it is
     * alphabetic.
     *
     * @param string $string   The string to be capitalized.
     * @param bool   $locale   If true the string will be converted based on a
     *                         given charset, locale independent else.
     * @param string $charset  The charset to use, defaults to current charset.
     *
     * @return string  The capitalized string.
     */
    public static function ucfirst(string $string, bool $locale = false, ?string $charset = null): string
    {
        if ($locale) {
            if (is_null($charset)) {
                throw new InvalidArgumentException('$charset argument must not be null');
            }
            $first = self::substr($string, 0, 1, $charset);
            if (self::isAlpha($first, $charset)) {
                $string = self::upper($first, true, $charset) . self::substr($string, 1, null, $charset);
            }
        } else {
            $string = self::upper(substr($string, 0, 1), false) . substr($string, 1);
        }

        return $string;
    }

    /**
     * Returns a string with the first letter of each word capitalized if it is
     * alphabetic.
     *
     * Sentences are splitted into words at whitestrings.
     *
     * @param string $string   The string to be capitalized.
     * @param bool   $locale   If true the string will be converted based on a
     *                         given charset, locale independent else.
     * @param string|null $charset  The charset to use, defaults to current charset.
     *
     * @return string  The capitalized string.
     */
    public static function ucwords(string $string, bool $locale = false, ?string $charset = null): string
    {
        $words = preg_split('/(\s+)/', $string, -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 0, $c = count($words); $i < $c; $i += 2) {
            $words[$i] = self::ucfirst($words[$i], $locale, $charset);
        }
        return implode('', $words);
    }

    /**
     * Returns part of a string.
     *
     * @param string $string   The string to be converted.
     * @param integer $start   The part's start position, zero based.
     * @param integer $length  The part's length.
     * @param string $charset  The charset to use when calculating the part's
     *                         position and length, defaults to current
     *                         charset.
     *
     * @return string  The string's part.
     */
    public static function substr(
        string $string,
        int $start,
        ?int $length = null,
        string $charset = 'UTF-8'
    ): string
    {
        if (is_null($length)) {
            $length = self::length($string, $charset) - $start;
        }

        if ($length === 0) {
            return '';
        }

        $error = false;

        /* Try mbstring. */
        if (Util::extensionExists('mbstring')) {
            $ret = @mb_substr($string, $start, $length, self::_mbstringCharset($charset));

            /* mb_substr() returns empty string on failure. */
            if (strlen($ret)) {
                return $ret;
            }
            $error = true;
        }

        /* Try iconv. */
        if (Util::extensionExists('iconv')) {
            $ret = @iconv_substr($string, $start, $length, $charset);

            /* iconv_substr() returns false on failure. */
            if ($ret !== false) {
                return $ret;
            }
            $error = true;
        }

        /* Try intl. */
        if (Util::extensionExists('intl')) {
            $ret = self::convertCharset(
                @grapheme_substr(
                    self::convertCharset($string, $charset, 'UTF-8'),
                    $start,
                    $length
                ),
                'UTF-8',
                $charset
            );

            /* grapheme_substr() returns false on failure. */
            if ($ret !== false) {
                return $ret;
            }
            $error = true;
        }

        return $error
            ? ''
            : substr($string, $start, $length);
    }

    /**
     * Returns the character (not byte) length of a string.
     *
     * @param string $string  The string to return the length of.
     * @param string $charset The charset to use when calculating the string's
     *                        length.
     *
     * @return int  The string's length.
     */
    public static function length(string $string, string $charset = 'UTF-8'): int
    {
        $charset = self::lower($charset);

        if ($charset == 'utf-8' || $charset == 'utf8') {
            return strlen(utf8_decode($string));
        }

        if (Util::extensionExists('mbstring')) {
            $ret = @mb_strlen($string, self::_mbstringCharset($charset));
            if (!empty($ret)) {
                return $ret;
            }
        }
        if (Util::extensionExists('intl')) {
            return grapheme_strlen(
                self::convertCharset($string, $charset, 'UTF-8')
            );
        }

        return strlen($string);
    }

    /**
     * Returns the numeric position of the first occurrence of $needle
     * in the $haystack string.
     *
     * @param string $haystack  The string to search through.
     * @param string $needle    The string to search for.
     * @param int    $offset    Character in $haystack to start searching at.
     * @param string $charset   Charset of $needle.
     *
     * @return int  The position of first occurrence.
     */
    public static function pos(
        string $haystack,
        string $needle,
        int $offset = 0,
        string $charset = 'UTF-8'
    ): int  {
        return self::_pos($haystack, $needle, $offset, $charset, 'strpos');
    }

    /**
     * Returns the numeric position of the first case-insensitive occurrence
     * of $needle in the $haystack string.
     *
     * @since 2.5.0
     *
     * @param string $haystack  The string to search through.
     * @param string $needle    The string to search for.
     * @param int $offset   Character in $haystack to start searching at.
     * @param string $charset   Charset of $needle.
     *
     * @return integer  The position of first case-insensitive occurrence.
     */
    public static function ipos(
        string $haystack,
        string $needle,
        int $offset = 0,
        string $charset = 'UTF-8'
    ): int {
        return self::_pos($haystack, $needle, $offset, $charset, 'stripos');
    }

    /**
     * Returns the numeric position of the last occurrence of $needle
     * in the $haystack string.
     *
     * @param string $haystack  The string to search through.
     * @param string $needle    The string to search for.
     * @param int    $offset    Character in $haystack to start searching at.
     * @param string $charset   Charset of $needle.
     *
     * @return int  The position of last occurrence.
     */
    public static function rpos(
        string $haystack,
        string $needle,
        int $offset = 0,
        string $charset = 'UTF-8'
    ): int {
        return self::_pos($haystack, $needle, $offset, $charset, 'strrpos');
    }

    /**
     * Returns the numeric position of the last case-insensitive occurrence of
     * $needle in the $haystack string.
     *
     * @since 2.5.0
     *
     * @param string $haystack  The string to search through.
     * @param string $needle    The string to search for.
     * @param int    $offset    Character in $haystack to start searching at.
     * @param string $charset   Charset of $needle.
     *
     * @return int  The position of last case-insensitive occurrence.
     */
    public static function ripos(
        string $haystack,
        string $needle,
        int $offset = 0,
        string $charset = 'UTF-8'
    ): int {
        return self::_pos($haystack, $needle, $offset, $charset, 'strripos');
    }

    /**
     * Perform string position searches.
     *
     * @param string $haystack  The string to search through.
     * @param string $needle    The string to search for.
     * @param int    $offset    Character in $haystack to start searching at.
     * @param string $charset   Charset of $needle.
     * @param string $func      Function to use.
     *
     * @return int  The position of occurrence.
     *
     */
    protected static function _pos(
        string $haystack,
        string $needle,
        int $offset,
        string $charset,
        string $func
    ): int {
        // Initialize, mostly to un-worry static analyzers
        $php_errormsg = '';
        if (Util::extensionExists('mbstring')) {
            unset($php_errormsg);
            $track_errors = ini_set('track_errors', 1);
            $ret = @call_user_func('mb_' . $func, $haystack, $needle, $offset, self::_mbstringCharset($charset));
            ini_set('track_errors', $track_errors);
            if (!isset($php_errormsg)) {
                return $ret;
            }
        }

        if (Util::extensionExists('intl')) {
            unset($php_errormsg);
            $track_errors = ini_set('track_errors', 1);
            $ret = self::convertCharset(
                @call_user_func(
                    'grapheme_' . $func,
                    self::convertCharset($haystack, $charset, 'UTF-8'),
                    self::convertCharset($needle, $charset, 'UTF-8'),
                    $offset
                ),
                'UTF-8',
                $charset
            );
            ini_set('track_errors', $track_errors);
            if (!isset($php_errormsg)) {
                return $ret;
            }
        }

        return $func($haystack, $needle, $offset);
    }

    /**
     * Returns a string padded to a certain length with another string.
     * This method behaves exactly like str_pad() but is multibyte safe.
     *
     * @param string $input    The string to be padded.
     * @param int $length  The length of the resulting string.
     * @param string $pad      The string to pad the input string with. Must
     *                         be in the same charset like the input string.
     * @param int $type        The padding type. One of STR_PAD_LEFT,
     *                         STR_PAD_RIGHT, or STR_PAD_BOTH.
     * @param string $charset  The charset of the input and the padding
     *                         strings.
     *
     * @return string  The padded string.
     */
    public static function pad(
        string $input,
        int $length,
        string $pad = ' ',
        int $type = STR_PAD_RIGHT,
        string $charset = 'UTF-8'
    ): string
    {
        $mb_length = self::length($input, $charset);
        $sb_length = strlen($input);
        $pad_length = self::length($pad, $charset);

        /* Return if we already have the length. */
        if ($mb_length >= $length) {
            return $input;
        }

        /* Shortcut for single byte strings. */
        if ($mb_length == $sb_length && $pad_length == strlen($pad)) {
            return str_pad($input, $length, $pad, $type);
        }

        switch ($type) {
        case STR_PAD_LEFT:
            $left = $length - $mb_length;
            $output = self::substr(str_repeat($pad, ceil($left / $pad_length)), 0, $left, $charset) . $input;
            break;

        case STR_PAD_BOTH:
            $left = floor(($length - $mb_length) / 2);
            $right = ceil(($length - $mb_length) / 2);
            $output = self::substr(str_repeat($pad, ceil($left / $pad_length)), 0, $left, $charset) .
                $input .
                self::substr(str_repeat($pad, ceil($right / $pad_length)), 0, $right, $charset);
            break;

        case STR_PAD_RIGHT:
            $right = $length - $mb_length;
            $output = $input . self::substr(str_repeat($pad, ceil($right / $pad_length)), 0, $right, $charset);
            break;
        }

        return $output;
    }

    /**
     * Wraps the text of a message.
     *
     * @param string $string         String containing the text to wrap.
     * @param int    $width         Wrap the string at this number of
     *                               characters.
     * @param string $break          Character(s) to use when breaking lines.
     * @param bool   $cut           Whether to cut inside words if a line
     *                               can't be wrapped.
     * @param bool   $line_folding  Whether to apply line folding rules per
     *                               RFC 822 or similar. The correct break
     *                               characters including leading whitespace
     *                               have to be specified too.
     *
     * @return string  String containing the wrapped text.
     */
    public static function wordwrap(
        string $string,
        int $width = 75,
        string $break = "\n",
        bool $cut = false,
        bool $line_folding = false
    ): string
    {
        $breakRegex = '(?:' . preg_quote($break) . ')';
        $rpos = self::rpos($break, "\n");
        if ($rpos === false) {
            $rpos = 0;
        } else {
            $rpos++;
        }
        $wrapped = '';
        $hasWrapped = false;

        while (self::length($string, 'UTF-8') > $width) {
            $line = self::substr($string, 0, $width + ($hasWrapped ? $rpos : 0), 'UTF-8');
            $string = self::substr($string, self::length($line, 'UTF-8'), null, 'UTF-8');

            // Make sure we didn't cut a word, unless we want hard breaks
            // anyway.
            if (!$cut && preg_match('/^(.+?)((\s|\r?\n).*)/us', $string, $match)) {
                $line .= $match[1];
                $string = $match[2];
            }

            // Wrap at existing line breaks.
            $regex = '/^(' . ($hasWrapped ? $breakRegex : '') . '.*?)(\r?\n)(.*)$/us';
            if (preg_match($regex, $line, $match)) {
                $wrapped .= $match[1] . $match[2];
                $string = $match[3] . $string;
                $hasWrapped = false;
                continue;
            }

            // Wrap at the last colon or semicolon followed by a whitespace if
            // doing line folding.
            if ($line_folding &&
                preg_match('/^(.*?)(;|:)(\s+.*)$/us', $line, $match)) {
                $wrapped .= $match[1] . $match[2];
                $string = $break . $match[3] . $string;
                $hasWrapped = true;
                continue;
            }

            // Wrap at the last whitespace of $line.
            $sub = $line_folding
                ? '(' . ($hasWrapped ? $breakRegex : '') . '.+[^\s])'
                : '(' . ($hasWrapped ? $breakRegex : '') . '.*)';

            if (preg_match('/^' . $sub . '(\s+)(.*)$/u', $line, $match)) {
                $wrapped .= $match[1];
                $string = $break . ($line_folding ? $match[2] : '')
                    . $match[3] . $string;
                $hasWrapped = true;
                continue;
            }

            // Hard wrap if necessary.
            if ($cut) {
                $wrapped .= $line;
                $string = $break . $string;
                $hasWrapped = true;
                continue;
            }

            $wrapped .= $line;
            $hasWrapped = false;
        }

        return $wrapped . $string;
    }

    /**
     * Wraps the text of a message.
     *
     * @param string $text        String containing the text to wrap.
     * @param int    $length     Wrap $text at this number of characters.
     * @param string $break_char  Character(s) to use when breaking lines.
     * @param bool   $quote      Ignore lines that are wrapped with the '>'
     *                            character (RFC 2646)? If true, we don't
     *                            remove any padding whitespace at the end of
     *                            the string.
     *
     * @return string  String containing the wrapped text.
     */
    public static function wrap(
        string $text,
        int $length = 80,
        string $break_char = "\n",
        bool $quote = false
    ): string
    {
        $paragraphs = [];

        foreach (preg_split('/\r?\n/', $text) as $input) {
            if ($quote && (strpos($input, '>') === 0)) {
                $line = $input;
            } else {
                /* We need to handle the Usenet-style signature line
                 * separately; since the space after the two dashes is
                 * REQUIRED, we don't want to trim the line. */
                if ($input != '-- ') {
                    $input = rtrim($input);
                }
                $line = self::wordwrap($input, $length, $break_char);
            }

            $paragraphs[] = $line;
        }

        return implode($break_char, $paragraphs);
    }

    /**
     * Return a truncated string, suitable for notifications.
     *
     * @param string $text     The original string.
     * @param int    $length  The maximum length.
     *
     * @return string  The truncated string, if longer than $length.
     */
    public static function truncate(string $text, int $length = 100): string
    {
        return (self::length($text) > $length)
            ? rtrim(self::substr($text, 0, $length - 3)) . '...'
            : $text;
    }

    /**
     * Return an abbreviated string, with characters in the middle of the
     * excessively long string replaced by '...'.
     *
     * @param string $text     The original string.
     * @param int    $length  The length at which to abbreviate.
     *
     * @return string  The abbreviated string, if longer than $length.
     */
    public static function abbreviate(string $text, int $length = 20): string
    {
        return (self::length($text) > $length)
            ? rtrim(self::substr($text, 0, round(($length - 3) / 2))) . '...' . ltrim(self::substr($text, (($length - 3) / 2) * -1))
            : $text;
    }

    /**
     * Returns the common leading part of two strings.
     *
     * @param string $str1  A string.
     * @param string $str2  Another string.
     *
     * @return string  The start of $str1 and $str2 that is identical in both.
     */
    public static function common(string $str1, string $str2): string
    {
        for ($result = '', $i = 0;
             isset($str1[$i]) && isset($str2[$i]) && $str1[$i] == $str2[$i];
             $i++) {
            $result .= $str1[$i];
        }
        return $result;
    }

    /**
     * Returns true if the every character in the parameter is an alphabetic
     * character.
     *
     * @param string $string   The string to test.
     * @param string $charset  The charset to use when testing the string.
     *
     * @return bool  True if the parameter was alphabetic only.
     */
    public static function isAlpha(string $string, string $charset):bool
    {
        if (!Util::extensionExists('mbstring')) {
            return ctype_alpha($string);
        }

        $charset = self::_mbstringCharset($charset);
        $old_charset = mb_regex_encoding();

        if ($charset != $old_charset) {
            @mb_regex_encoding($charset);
        }
        $alpha = !@mb_ereg_match('[^[:alpha:]]', $string);
        if ($charset != $old_charset) {
            @mb_regex_encoding($old_charset);
        }

        return $alpha;
    }

    /**
     * Returns true if ever character in the parameter is a lowercase letter in
     * the current locale.
     *
     * @param string $string   The string to test.
     * @param string $charset  The charset to use when testing the string.
     *
     * @return bool  True if the parameter was lowercase.
     */
    public static function isLower(string $string, string $charset): bool
    {
        return ((self::lower($string, true, $charset) === $string) &&
                self::isAlpha($string, $charset));
    }

    /**
     * Returns true if every character in the parameter is an uppercase letter
     * in the current locale.
     *
     * @param string $string   The string to test.
     * @param string $charset  The charset to use when testing the string.
     *
     * @return bool  True if the parameter was uppercase.
     */
    public static function isUpper(string $string, string $charset): bool
    {
        return ((self::upper($string, true, $charset) === $string) &&
                self::isAlpha($string, $charset));
    }

    /**
     * Performs a multibyte safe regex match search on the text provided.
     *
     * @param string $text     The text to search.
     * @param array $regex     The regular expressions to use, without perl
     *                         regex delimiters (e.g. '/' or '|').
     * @param string $charset  The character set of the text.
     *
     * @return array  The matches array from the first regex that matches.
     */
    public static function regexMatch($text, $regex, $charset = null)
    {
        if (!empty($charset)) {
            $regex = self::convertCharset($regex, $charset, 'utf-8');
            $text = self::convertCharset($text, $charset, 'utf-8');
        }

        $matches = [];
        foreach ($regex as $val) {
            if (preg_match('/' . $val . '/u', $text, $matches)) {
                break;
            }
        }

        if (!empty($charset)) {
            $matches = self::convertCharset($matches, 'utf-8', $charset);
        }

        return $matches;
    }

    /**
     * Check to see if a string is valid UTF-8.
     *
     * @param string $text  The text to check.
     *
     * @return boolean  True if valid UTF-8.
     */
    public static function validUtf8($text)
    {
        $text = strval($text);

        // First check for illegal surrogate pair sequences. See RFC 3629.
        if (preg_match('/\xE0[\x80-\x9F][\x80-\xBF]|\xED[\xA0-\xBF][\x80-\xBF]/S', $text)) {
            return false;
        }

        for ($i = 0, $len = strlen($text); $i < $len; ++$i) {
            $c = ord($text[$i]);
            if ($c > 128) {
                if ($c > 247) {
                    // STD 63 (RFC 3629) eliminates 5 & 6-byte characters.
                    return false;
                } elseif ($c > 239) {
                    $j = 3;
                } elseif ($c > 223) {
                    $j = 2;
                } elseif ($c > 191) {
                    $j = 1;
                } else {
                    return false;
                }

                if (($i + $j) > $len) {
                    return false;
                }

                do {
                    $c = ord($text[++$i]);
                    if (($c < 128) || ($c > 191)) {
                        return false;
                    }
                } while (--$j);
            }
        }

        return true;
    }

    /**
     * Workaround charsets that don't work with mbstring functions.
     *
     * @param string $charset  The original charset.
     *
     * @return string  The charset to use with mbstring functions.
     */
    protected static function _mbstringCharset($charset)
    {
        /* mbstring functions do not handle the 'ks_c_5601-1987' &
         * 'ks_c_5601-1989' charsets. However, these charsets are used, for
         * example, by various versions of Outlook to send Korean characters.
         * Use UHC (CP949) encoding instead. See, e.g.,
         * http://lists.w3.org/Archives/Public/ietf-charsets/2001AprJun/0030.html */
        return in_array(self::lower($charset), ['ks_c_5601-1987', 'ks_c_5601-1989'])
            ? 'UHC'
            : $charset;
    }

    /**
     * Strip UTF-8 byte order mark (BOM) from string data.
     *
     * @param string $str  Input string (UTF-8).
     *
     * @return string  Stripped string (UTF-8).
     */
    public static function trimUtf8Bom($str)
    {
        return (substr($str, 0, 3) == pack('CCC', 239, 187, 191))
            ? substr($str, 3)
            : $str;
    }

    /**
     * Try to convert a string to UTF-8.
     * Returns null if source charset could not be detected or conversion failed
     *
     * @param string $string The string to convert
     * @return string The string in UTF-8
     * 
     * @throws Exception If the source encoding can not be detected
     * @throws Exception If the iconv extension is not installed
     * @throws Exception If the conversion via iconv fails
     */
    public static function convertToUtf8(string $string): ?string
    {
        $encodings = [
            'UTF-8',
            'ISO-8859-1',
            'UTF-16',
            'ISO-8859-2',
            'ISO-8859-3',
            'ISO-8859-4',
            'ISO-8859-5',
            'ISO-8859-6',
            'ISO-8859-7',
            'ISO-8859-8',
            'ISO-8859-9',
            'ISO-8859-10',
            'ISO-8859-13',
            'ISO-8859-14',
            'ISO-8859-15',
            'ISO-8859-16',
        ];
        $enc = mb_detect_encoding($string, $encodings);
        if (!$enc) {
            throw new Exception('Can not convert string to UTF-8. Could not detect source encoding.');
        } elseif ($enc === 'UTF-8') {
            return $string;
        }
        if (!Horde_Util::extensionExists('iconv')) {
            throw new Exception('Can not convert string to UTF-8. iconv extension not found.');
        }
        $converted = iconv($enc, "UTF-8", $string);
        if ($converted === false) {
            throw new Exception("iconv conversion from $enc to UTF-8 failed.");
        }
        return $converted;
    }
}
