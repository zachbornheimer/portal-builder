<?php

/**
 * Applicant-facing open and close wording.
 *
 * Actual instants gate the form. Display instants are what people read.
 * A blank display falls back to the actual instant.
 *
 * @package DragonGate
 */

if (!class_exists('Portal_When')) {
    /**
     * Format a portal open or close for people.
     */
    class Portal_When
    {
        const WHICH_OPEN = 'open';
        const WHICH_CLOSE = 'close';
        const PART_PHRASE = 'phrase';
        const PART_DATE = 'date';
        const ZONE_EASTERN = 'America/New_York';

        /**
         * Instant applicants should read. Blank display uses the gate instant.
         *
         * @param array  $publish Publish block.
         * @param string $which   open|close.
         * @return string|null
         */
        public static function shown_instant(array $publish, $which)
        {
            $display_key = self::WHICH_OPEN === $which ? 'launchDisplay' : 'deadlineDisplay';
            $actual_key = self::WHICH_OPEN === $which ? 'launchAt' : 'deadline';
            $display = self::instant($publish, $display_key);
            if (null !== $display) {
                return $display;
            }
            return self::instant($publish, $actual_key);
        }

        /**
         * Instant that opens or closes the form. Display is ignored.
         *
         * @param array  $publish Publish block.
         * @param string $which   open|close.
         * @return string|null
         */
        public static function actual_instant(array $publish, $which)
        {
            $key = self::WHICH_OPEN === $which ? 'launchAt' : 'deadline';
            return self::instant($publish, $key);
        }

        /**
         * @param array $publish Publish block.
         * @return string
         */
        public static function timezone(array $publish)
        {
            $timezone = isset($publish['timezone']) ? trim((string) $publish['timezone']) : '';
            return '' !== $timezone ? $timezone : 'America/New_York';
        }

        /**
         * Date, and the time when it is not midnight.
         *
         * @param mixed  $instant  Datetime string.
         * @param string $timezone IANA zone.
         * @return string
         */
        public static function phrase($instant, $timezone)
        {
            $dt = self::parse($instant, $timezone);
            if (!$dt) {
                return '';
            }
            $date = $dt->format('l, F j, Y');
            if (0 === (int) $dt->format('H') && 0 === (int) $dt->format('i')) {
                return $date;
            }
            return $date . ', ' . self::clock($dt) . ' ' . self::zone_label($timezone);
        }

        /**
         * Calendar date in the portal zone. Hosts use this for their own listings.
         *
         * @param mixed  $instant  Datetime string.
         * @param string $timezone IANA zone.
         * @return string
         */
        public static function ymd($instant, $timezone)
        {
            $dt = self::parse($instant, $timezone);
            return $dt ? $dt->format('Y-m-d') : '';
        }

        /**
         * Month day, year. No weekday or time.
         *
         * @param mixed  $instant  Datetime string.
         * @param string $timezone IANA zone.
         * @return string
         */
        public static function date_label($instant, $timezone)
        {
            $dt = self::parse($instant, $timezone);
            return $dt ? $dt->format('F j, Y') : '';
        }

        /**
         * @param array  $publish Publish block.
         * @param string $key     Field name.
         * @return string|null
         */
        private static function instant(array $publish, $key)
        {
            if (!array_key_exists($key, $publish) || !is_string($publish[$key])) {
                return null;
            }
            $value = trim($publish[$key]);
            return '' === $value ? null : $value;
        }

        /**
         * @param mixed  $instant  Datetime string.
         * @param string $timezone IANA zone.
         * @return DateTime|null
         */
        private static function parse($instant, $timezone)
        {
            if (null === $instant || '' === $instant) {
                return null;
            }
            try {
                $tz = new DateTimeZone($timezone);
            } catch (Exception $e) {
                $tz = new DateTimeZone('America/New_York');
            }
            try {
                return new DateTime((string) $instant, $tz);
            } catch (Exception $e) {
                return null;
            }
        }

        /**
         * @param DateTime $dt Parsed instant.
         * @return string
         */
        private static function clock(DateTime $dt)
        {
            return str_replace(array('am', 'pm'), array('a.m.', 'p.m.'), $dt->format('g:i a'));
        }

        /**
         * @param string $timezone IANA zone.
         * @return string
         */
        private static function zone_label($timezone)
        {
            return self::ZONE_EASTERN === $timezone ? 'Eastern' : $timezone;
        }
    }
}
