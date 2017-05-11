<?php

/*
 * This file is part of the Carbon package.
 *
 * (c) Brian Nesbitt <brian@nesbot.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Carbon;

use Carbon\Exceptions\InvalidDateException;
use Closure;
use DatePeriod;
use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * A simple API extension for DateTime
 *
 * @property      int $year
 * @property      int $yearIso
 * @property      int $month
 * @property      int $day
 * @property      int $hour
 * @property      int $minute
 * @property      int $second
 * @property      int $timestamp seconds since the Unix Epoch
 * @property      \DateTimeZone $timezone the current timezone
 * @property      \DateTimeZone $tz alias of timezone
 * @property-read int $micro
 * @property-read int $dayOfWeek 0 (for Sunday) through 6 (for Saturday)
 * @property-read int $dayOfYear 0 through 365
 * @property-read int $weekOfMonth 1 through 5
 * @property-read int $weekOfYear ISO-8601 week number of year, weeks starting on Monday
 * @property-read int $daysInMonth number of days in the given month
 * @property-read int $age does a diffInYears() with default parameters
 * @property-read int $quarter the quarter of this instance, 1 - 4
 * @property-read int $offset the timezone offset in seconds from UTC
 * @property-read int $offsetHours the timezone offset in hours from UTC
 * @property-read bool $dst daylight savings time indicator, true if DST, false otherwise
 * @property-read bool $local checks if the timezone is local, true if local, false otherwise
 * @property-read bool $utc checks if the timezone is UTC, true if UTC, false otherwise
 * @property-read string $timezoneName
 * @property-read string $tzName
 */
class Carbon extends DateTime
{
    /*
     * The day constants.
     */
    const SUNDAY = 0;
    const MONDAY = 1;
    const TUESDAY = 2;
    const WEDNESDAY = 3;
    const THURSDAY = 4;
    const FRIDAY = 5;
    const SATURDAY = 6;

    /*
     * Names of days of the week.
     *
     * @var array
     */
    protected static $days = array(
        self::SUNDAY => 'Sunday',
        self::MONDAY => 'Monday',
        self::TUESDAY => 'Tuesday',
        self::WEDNESDAY => 'Wednesday',
        self::THURSDAY => 'Thursday',
        self::FRIDAY => 'Friday',
        self::SATURDAY => 'Saturday',
    );

    /**
     * Terms used to detect if a time passed is a relative date.
     *
     * This is here for testing purposes.
     *
     * @var array
     */
    protected static $relativeKeywords = array(
        '+',
        '-',
        'ago',
        'first',
        'last',
        'next',
        'this',
        'today',
        'tomorrow',
        'yesterday',
    );

    /*
     * Number of X in Y.
     */
    const YEARS_PER_CENTURY = 100;
    const YEARS_PER_DECADE = 10;
    const MONTHS_PER_YEAR = 12;
    const MONTHS_PER_QUARTER = 3;
    const WEEKS_PER_YEAR = 52;
    const DAYS_PER_WEEK = 7;
    const HOURS_PER_DAY = 24;
    const MINUTES_PER_HOUR = 60;
    const SECONDS_PER_MINUTE = 60;

    /*
     * 默认时间格式 常量
     *
     * @var string
     */
    const DEFAULT_TO_STRING_FORMAT = 'Y-m-d H:i:s';

    /*
     * __toString 方法格式化输出时间的格式
     *
     * @var string
     */
    protected static $toStringFormat = self::DEFAULT_TO_STRING_FORMAT;

    /*
     * 一周的第一天
     *
     * @var int
     */
    protected static $weekStartsAt = self::MONDAY;

    /*
     * 一周的最后一天
     *
     * @var int
     */
    protected static $weekEndsAt = self::SUNDAY;

    /*
     * 周末
     *
     * @var array
     */
    protected static $weekendDays = array(
        self::SATURDAY,
        self::SUNDAY,
    );

    /**
     * A test Carbon instance to be returned when now instances are created.
     *
     * @var \Carbon\Carbon
     */
    protected static $testNow;

    /**
     * A translator to ... er ... translate stuff.
     *
     * @var \Symfony\Component\Translation\TranslatorInterface
     */
    protected static $translator;

    /**
     * The errors that can occur.
     *
     * @var array
     */
    protected static $lastErrors;

    /**
     * Will UTF8 encoding be used to print localized date/time ?
     *
     * @var bool
     */
    protected static $utf8 = false;

    /*
     * Indicates if months should be calculated with overflow.
     *
     * @var bool
     */
    protected static $monthsOverflow = true;

    /**
     * Indicates if months should be calculated with overflow.
     *
     * @param bool $monthsOverflow
     *
     * @return void
     */
    public static function useMonthsOverflow($monthsOverflow = true)
    {
        static::$monthsOverflow = $monthsOverflow;
    }

    /**
     * Reset the month overflow behavior.
     *
     * @return void
     */
    public static function resetMonthsOverflow()
    {
        static::$monthsOverflow = true;
    }

    /**
     * Get the month overflow behavior.
     *
     * @return bool
     */
    public static function shouldOverflowMonths()
    {
        return static::$monthsOverflow;
    }

    /**
     * Creates a DateTimeZone from a string, DateTimeZone or integer offset.
     *
     * @param \DateTimeZone|string|int|null $object
     *
     * @throws \InvalidArgumentException
     *
     * @return \DateTimeZone
     */
    protected static function safeCreateDateTimeZone($object)
    {
        if ($object === null) {
            // Don't return null... avoid Bug #52063 in PHP <5.3.6
            return new DateTimeZone(date_default_timezone_get());
        }

        if ($object instanceof DateTimeZone) {
            return $object;
        }

        if (is_numeric($object)) {
            $tzName = timezone_name_from_abbr(null, $object * 3600, true);

            if ($tzName === false) {
                throw new InvalidArgumentException('Unknown or bad timezone ('.$object.')');
            }

            $object = $tzName;
        }

        $tz = @timezone_open((string) $object);

        if ($tz === false) {
            throw new InvalidArgumentException('Unknown or bad timezone ('.$object.')');
        }

        return $tz;
    }

    ///////////////////////////////////////////////////////////////////
    //////////////////////////// CONSTRUCTORS /////////////////////////
    ///////////////////////////////////////////////////////////////////

    /**
     * Create a new Carbon instance.
     *
     * Please see the testing aids section (specifically static::setTestNow())
     * for more on the possibility of this constructor returning a test instance.
     *
     * @param string|null               $time
     * @param \DateTimeZone|string|null $tz
     */
    public function __construct($time = null, $tz = null)
    {
        // If the class has a test now set and we are trying to create a now()
        // instance then override as required
        if (static::hasTestNow() && (empty($time) || $time === 'now' || static::hasRelativeKeywords($time))) {
            $testInstance = clone static::getTestNow();
            if (static::hasRelativeKeywords($time)) {
                $testInstance->modify($time);
            }

            //shift the time according to the given time zone
            if ($tz !== null && $tz !== static::getTestNow()->getTimezone()) {
                $testInstance->setTimezone($tz);
            } else {
                $tz = $testInstance->getTimezone();
            }

            $time = $testInstance->toDateTimeString();
        }

        parent::__construct($time, static::safeCreateDateTimeZone($tz));
    }

    /**
     * Create a Carbon instance from a DateTime one.
     *
     * @param \DateTime $dt
     *
     * @return static
     */
    public static function instance(DateTime $dt)
    {
        if ($dt instanceof static) {
            return clone $dt;
        }

        return new static($dt->format('Y-m-d H:i:s.u'), $dt->getTimezone());
    }

    /**
     * Create a carbon instance from a string.
     *
     * This is an alias for the constructor that allows better fluent syntax
     * as it allows you to do Carbon::parse('Monday next week')->fn() rather
     * than (new Carbon('Monday next week'))->fn().
     *
     * @param string|null               $time
     * @param \DateTimeZone|string|null $tz
     *
     * @return static
     */
    public static function parse($time = null, $tz = null)
    {
        return new static($time, $tz);
    }

    /**
     * Get a Carbon instance for the current date and time.
     *
     * @param \DateTimeZone|string|null $tz
     *
     * @return static
     */
    public static function now($tz = null)
    {
        return new static(null, $tz);
    }

    /**
     * Create a Carbon instance for today.
     *
     * @param \DateTimeZone|string|null $tz
     *
     * @return static
     */
    public static function today($tz = null)
    {
        return static::now($tz)->startOfDay();
    }

    /**
     * Create a Carbon instance for tomorrow.
     *
     * @param \DateTimeZone|string|null $tz
     *
     * @return static
     */
    public static function tomorrow($tz = null)
    {
        return static::today($tz)->addDay();
    }

    /**
     * Create a Carbon instance for yesterday.
     *
     * @param \DateTimeZone|string|null $tz
     *
     * @return static
     */
    public static function yesterday($tz = null)
    {
        return static::today($tz)->subDay();
    }

    /**
     * Create a Carbon instance for the greatest supported date.
     *
     * @return static
     */
    public static function maxValue()
    {
        if (PHP_INT_SIZE === 4) {
            // 32 bit (and additionally Windows 64 bit)
            return static::createFromTimestamp(PHP_INT_MAX);
        }

        // 64 bit
        return static::create(9999, 12, 31, 23, 59, 59);
    }

    /**
     * Create a Carbon instance for the lowest supported date.
     *
     * @return static
     */
    public static function minValue()
    {
        if (PHP_INT_SIZE === 4) {
            // 32 bit (and additionally Windows 64 bit)
            return static::createFromTimestamp(~PHP_INT_MAX);
        }

        // 64 bit
        return static::create(1, 1, 1, 0, 0, 0);
    }

    /**
     * Create a new Carbon instance from a specific date and time.
     *
     * If any of $year, $month or $day are set to null their now() values will
     * be used.
     *
     * If $hour is null it will be set to its now() value and the default
     * values for $minute and $second will be their now() values.
     *
     * If $hour is not null then the default values for $minute and $second
     * will be 0.
     *
     * @param int|null                  $year
     * @param int|null                  $month
     * @param int|null                  $day
     * @param int|null                  $hour
     * @param int|null                  $minute
     * @param int|null                  $second
     * @param \DateTimeZone|string|null $tz
     *
     * @return static
     */
    public static function create($year = null, $month = null, $day = null, $hour = null, $minute = null, $second = null, $tz = null)
    {
        $now = static::hasTestNow() ? static::getTestNow()->getTimestamp() : time();

        $defaults = array_combine(array(
            'year',
            'month',
            'day',
            'hour',
            'minute',
            'second',
        ), explode('-', date('Y-n-j-G-i-s', $now)));

        $year = $year === null ? $defaults['year'] : $year;
        $month = $month === null ? $defaults['month'] : $month;
        $day = $day === null ? $defaults['day'] : $day;

        if ($hour === null) {
            $hour = $defaults['hour'];
            $minute = $minute === null ? $defaults['minute'] : $minute;
            $second = $second === null ? $defaults['second'] : $second;
        } else {
            $minute = $minute === null ? 0 : $minute;
            $second = $second === null ? 0 : $second;
        }

        $fixYear = null;

        if ($year < 0) {
            $fixYear = $year;
            $year = 0;
        } elseif ($year > 9999) {
            $fixYear = $year - 9999;
            $year = 9999;
        }

        $instance = static::createFromFormat('Y-n-j G:i:s', sprintf('%s-%s-%s %s:%02s:%02s', $year, $month, $day, $hour, $minute, $second), $tz);

        if ($fixYear !== null) {
            $instance->addYears($fixYear);
        }

        return $instance;
    }

    /**
     * Create a new safe Carbon instance from a specific date and time.
     *
     * If any of $year, $month or $day are set to null their now() values will
     * be used.
     *
     * If $hour is null it will be set to its now() value and the default
     * values for $minute and $second will be their now() values.
     *
     * If $hour is not null then the default values for $minute and $second
     * will be 0.
     *
     * If one of the set values is not valid, an \InvalidArgumentException
     * will be thrown.
     *
     * @param int|null                  $year
     * @param int|null                  $month
     * @param int|null                  $day
     * @param int|null                  $hour
     * @param int|null                  $minute
     * @param int|null                  $second
     * @param \DateTimeZone|string|null $tz
     *
     * @throws \Carbon\Exceptions\InvalidDateException
     *
     * @return static
     */
    public static function createSafe($year = null, $month = null, $day = null, $hour = null, $minute = null, $second = null, $tz = null)
    {
        $fields = array(
            'year' => array(0, 9999),
            'month' => array(0, 12),
            'day' => array(0, 31),
            'hour' => array(0, 24),
            'minute' => array(0, 59),
            'second' => array(0, 59),
        );

        foreach ($fields as $field => $range) {
            if ($$field !== null && (!is_int($$field) || $$field < $range[0] || $$field > $range[1])) {
                throw new InvalidDateException($field, $$field);
            }
        }

        $instance = static::create($year, $month, 1, $hour, $minute, $second, $tz);

        if ($day !== null && $day > $instance->daysInMonth) {
            throw new InvalidDateException('day', $day);
        }

        return $instance->day($day);
    }

    /**
     * Create a Carbon instance from just a date. The time portion is set to now.
     *
     * @param int|null                  $year
     * @param int|null                  $month
     * @param int|null                  $day
     * @param \DateTimeZone|string|null $tz
     *
     * @return static
     */
    public static function createFromDate($year = null, $month = null, $day = null, $tz = null)
    {
        return static::create($year, $month, $day, null, null, null, $tz);
    }

    /**
     * Create a Carbon instance from just a time. The date portion is set to today.
     *
     * @param int|null                  $hour
     * @param int|null                  $minute
     * @param int|null                  $second
     * @param \DateTimeZone|string|null $tz
     *
     * @return static
     */
    public static function createFromTime($hour = null, $minute = null, $second = null, $tz = null)
    {
        return static::create(null, null, null, $hour, $minute, $second, $tz);
    }

    /**
     * Create a Carbon instance from a specific format.
     *
     * @param string                    $format
     * @param string                    $time
     * @param \DateTimeZone|string|null $tz
     *
     * @throws \InvalidArgumentException
     *
     * @return static
     */
    public static function createFromFormat($format, $time, $tz = null)
    {
        if ($tz !== null) {
            $dt = parent::createFromFormat($format, $time, static::safeCreateDateTimeZone($tz));
        } else {
            $dt = parent::createFromFormat($format, $time);
        }

        static::setLastErrors($lastErrors = parent::getLastErrors());

        if ($dt instanceof DateTime) {
            return static::instance($dt);
        }

        throw new InvalidArgumentException(implode(PHP_EOL, $lastErrors['errors']));
    }

    /**
     * Set last errors.
     *
     * @param array $lastErrors
     *
     * @return void
     */
    private static function setLastErrors(array $lastErrors)
    {
        static::$lastErrors = $lastErrors;
    }

    /**
     * {@inheritdoc}
     */
    public static function getLastErrors()
    {
        return static::$lastErrors;
    }

    /**
     * Create a Carbon instance from a timestamp.
     *
     * @param int                       $timestamp
     * @param \DateTimeZone|string|null $tz
     *
     * @return static
     */
    public static function createFromTimestamp($timestamp, $tz = null)
    {
        return static::now($tz)->setTimestamp($timestamp);
    }

    /**
     * Create a Carbon instance from an UTC timestamp.
     *
     * @param int $timestamp
     *
     * @return static
     */
    public static function createFromTimestampUTC($timestamp)
    {
        return new static('@'.$timestamp);
    }

    /**
     * Get a copy of the instance.
     *
     * @return static
     */
    public function copy()
    {
        return clone $this;
    }

    ///////////////////////////////////////////////////////////////////
    ///////////////////////// GETTERS AND SETTERS /////////////////////
    ///////////////////////////////////////////////////////////////////

    /**
     * Get a part of the Carbon object
     *
     * @param string $name
     *
     * @throws \InvalidArgumentException
     *
     * @return string|int|\DateTimeZone
     */
    public function __get($name)
    {
        switch (true) {
            case array_key_exists($name, $formats = array(
                'year' => 'Y',
                'yearIso' => 'o',
                'month' => 'n',
                'day' => 'j',
                'hour' => 'G',
                'minute' => 'i',
                'second' => 's',
                'micro' => 'u',
                'dayOfWeek' => 'w',
                'dayOfYear' => 'z',
                'weekOfYear' => 'W',
                'daysInMonth' => 't',
                'timestamp' => 'U',
            )):
                return (int) $this->format($formats[$name]);

            case $name === 'weekOfMonth':
                return (int) ceil($this->day / static::DAYS_PER_WEEK);

            case $name === 'age':
                return $this->diffInYears();

            case $name === 'quarter':
                return (int) ceil($this->month / static::MONTHS_PER_QUARTER);

            case $name === 'offset':
                return $this->getOffset();

            case $name === 'offsetHours':
                return $this->getOffset() / static::SECONDS_PER_MINUTE / static::MINUTES_PER_HOUR;

            case $name === 'dst':
                return $this->format('I') === '1';

            case $name === 'local':
                return $this->getOffset() === $this->copy()->setTimezone(date_default_timezone_get())->getOffset();

            case $name === 'utc':
                return $this->getOffset() === 0;

            case $name === 'timezone' || $name === 'tz':
                return $this->getTimezone();

            case $name === 'timezoneName' || $name === 'tzName':
                return $this->getTimezone()->getName();

            default:
                throw new InvalidArgumentException(sprintf("Unknown getter '%s'", $name));
        }
    }

    /**
     * Check if an attribute exists on the object
     *
     * @param string $name
     *
     * @return bool
     */
    public function __isset($name)
    {
        try {
            $this->__get($name);
        } catch (InvalidArgumentException $e) {
            return false;
        }

        return true;
    }

    /**
     * Set a part of the Carbon object
     *
     * @param string                   $name
     * @param string|int|\DateTimeZone $value
     *
     * @throws \InvalidArgumentException
     */
    public function __set($name, $value)
    {
        switch ($name) {
            case 'year':
            case 'month':
            case 'day':
            case 'hour':
            case 'minute':
            case 'second':
                list($year, $month, $day, $hour, $minute, $second) = explode('-', $this->format('Y-n-j-G-i-s'));
                $$name = $value;
                $this->setDateTime($year, $month, $day, $hour, $minute, $second);
                break;

            case 'timestamp':
                parent::setTimestamp($value);
                break;

            case 'timezone':
            case 'tz':
                $this->setTimezone($value);
                break;

            default:
                throw new InvalidArgumentException(sprintf("Unknown setter '%s'", $name));
        }
    }

    /**
     * Set the instance's year
     *
     * @param int $value
     *
     * @return static
     */
    public function year($value)
    {
        $this->year = $value;

        return $this;
    }

    /**
     * Set the instance's month
     *
     * @param int $value
     *
     * @return static
     */
    public function month($value)
    {
        $this->month = $value;

        return $this;
    }

    /**
     * Set the instance's day
     *
     * @param int $value
     *
     * @return static
     */
    public function day($value)
    {
        $this->day = $value;

        return $this;
    }

    /**
     * Set the instance's hour
     *
     * @param int $value
     *
     * @return static
     */
    public function hour($value)
    {
        $this->hour = $value;

        return $this;
    }

    /**
     * Set the instance's minute
     *
     * @param int $value
     *
     * @return static
     */
    public function minute($value)
    {
        $this->minute = $value;

        return $this;
    }

    /**
     * Set the instance's second
     *
     * @param int $value
     *
     * @return static
     */
    public function second($value)
    {
        $this->second = $value;

        return $this;
    }

    /**
     * Sets the current date of the DateTime object to a different date.
     * Calls modify as a workaround for a php bug
     *
     * @param int $year
     * @param int $month
     * @param int $day
     *
     * @return static
     *
     * @see https://github.com/briannesbitt/Carbon/issues/539
     * @see https://bugs.php.net/bug.php?id=63863
     */
    public function setDate($year, $month, $day)
    {
        $this->modify('+0 day');

        return parent::setDate($year, $month, $day);
    }

    /**
     * Set the date and time all together
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $hour
     * @param int $minute
     * @param int $second
     *
     * @return static
     */
    public function setDateTime($year, $month, $day, $hour, $minute, $second = 0)
    {
        return $this->setDate($year, $month, $day)->setTime($hour, $minute, $second);
    }

    /*
     * 根据 H:i:s 字符串设置当前实例时间
     *
     * @param string $time
     *
     * @return static
     */
    public function setTimeFromTimeString($time)
    {
        $time = explode(':', $time);

        $hour = $time[0];
        $minute = isset($time[1]) ? $time[1] : 0;
        $second = isset($time[2]) ? $time[2] : 0;

        return $this->setTime($hour, $minute, $second);
    }

    /**
     * Set the instance's timestamp
     *
     * @param int $value
     *
     * @return static
     */
    public function timestamp($value)
    {
        return $this->setTimestamp($value);
    }

    /**
     * Alias for setTimezone()
     *
     * @param \DateTimeZone|string $value
     *
     * @return static
     */
    public function timezone($value)
    {
        return $this->setTimezone($value);
    }

    /**
     * Alias for setTimezone()
     *
     * @param \DateTimeZone|string $value
     *
     * @return static
     */
    public function tz($value)
    {
        return $this->setTimezone($value);
    }

    /**
     * Set the instance's timezone from a string or object
     *
     * @param \DateTimeZone|string $value
     *
     * @return static
     */
    public function setTimezone($value)
    {
        return parent::setTimezone(static::safeCreateDateTimeZone($value));
    }

    /**
     * Get the days of the week
     *
     * @return array
     */
    public static function getDays()
    {
        return static::$days;
    }

    ///////////////////////////////////////////////////////////////////
    /////////////////////// WEEK SPECIAL DAYS /////////////////////////
    ///////////////////////////////////////////////////////////////////

    /**
     * Get the first day of week
     *
     * @return int
     */
    public static function getWeekStartsAt()
    {
        return static::$weekStartsAt;
    }

    /**
     * Set the first day of week
     *
     * @param int
     */
    public static function setWeekStartsAt($day)
    {
        static::$weekStartsAt = $day;
    }

    /**
     * Get the last day of week
     *
     * @return int
     */
    public static function getWeekEndsAt()
    {
        return static::$weekEndsAt;
    }

    /**
     * Set the last day of week
     *
     * @param int
     */
    public static function setWeekEndsAt($day)
    {
        static::$weekEndsAt = $day;
    }

    /**
     * Get weekend days
     *
     * @return array
     */
    public static function getWeekendDays()
    {
        return static::$weekendDays;
    }

    /**
     * Set weekend days
     *
     * @param array
     */
    public static function setWeekendDays($days)
    {
        static::$weekendDays = $days;
    }

    ///////////////////////////////////////////////////////////////////
    /////////////////////////// 测试专用 //////////////////////////////
    ///////////////////////////////////////////////////////////////////

    /*
     * Set a Carbon instance (real or mock) to be returned when a "now"
     * instance is created.  The provided instance will be returned
     * specifically under the following conditions:
     *   - A call to the static now() method, ex. Carbon::now()
     *   - When a null (or blank string) is passed to the constructor or parse(), ex. new Carbon(null)
     *   - When the string "now" is passed to the constructor or parse(), ex. new Carbon('now')
     *   - When a string containing the desired time is passed to Carbon::parse().
     *
     * Note the timezone parameter was left out of the examples above and
     * has no affect as the mock value will be returned regardless of its value.
     *
     * To clear the test instance call this method using the default
     * parameter of null.
     *
     * @param \Carbon\Carbon|string|null $testNow
     */
    public static function setTestNow($testNow = null)
    {
        static::$testNow = is_string($testNow) ? static::parse($testNow) : $testNow;
    }

    /*
     * Get the Carbon instance (real or mock) to be returned when a "now"
     * instance is created.
     *
     * @return static the current instance used for testing
     */
    public static function getTestNow()
    {
        return static::$testNow;
    }

    /*
     * Determine if there is a valid test instance set. A valid test instance
     * is anything that is not null.
     *
     * @return bool true if there is a test instance, otherwise false
     */
    public static function hasTestNow()
    {
        return static::getTestNow() !== null;
    }

    /*
     * Determine if there is a relative keyword in the time string, this is to
     * create dates relative to now for test instances. e.g.: next tuesday
     *
     * @param string $time
     *
     * @return bool true if there is a keyword, otherwise false
     */
    public static function hasRelativeKeywords($time)
    {
        // skip common format with a '-' in it
        if (preg_match('/\d{4}-\d{1,2}-\d{1,2}/', $time) !== 1) {
            foreach (static::$relativeKeywords as $keyword) {
                if (stripos($time, $keyword) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    ///////////////////////////////////////////////////////////////////
    ///////////////////////////// 本地化 //////////////////////////////
    ///////////////////////////////////////////////////////////////////

    /*
     * Initialize the translator instance if necessary.
     *
     * @return \Symfony\Component\Translation\TranslatorInterface
     */
    protected static function translator()
    {
        if (static::$translator === null) {
            $translator = new Translator('en');
            $translator->addLoader('array', new ArrayLoader());
            static::$translator = $translator;
            static::setLocale('en');
        }

        return static::$translator;
    }

    /*
     * Get the translator instance in use
     *
     * @return \Symfony\Component\Translation\TranslatorInterface
     */
    public static function getTranslator()
    {
        return static::translator();
    }

    /*
     * Set the translator instance to use
     *
     * @param \Symfony\Component\Translation\TranslatorInterface $translator
     */
    public static function setTranslator(TranslatorInterface $translator)
    {
        static::$translator = $translator;
    }

    /*
     * Get the current translator locale
     *
     * @return string
     */
    public static function getLocale()
    {
        return static::translator()->getLocale();
    }

    /*
     * Set the current translator locale and indicate if the source locale file exists
     *
     * @param string $locale
     *
     * @return bool
     */
    public static function setLocale($locale)
    {
        $locale = preg_replace_callback('/\b([a-z]{2})[-_](?:([a-z]{4})[-_])?([a-z]{2})\b/', function ($matches) {
            return $matches[1].'_'.(!empty($matches[2]) ? ucfirst($matches[2]).'_' : '').strtoupper($matches[3]);
        }, strtolower($locale));

        if (file_exists($filename = __DIR__.'/Lang/'.$locale.'.php')) {
            $translator = static::translator();
            $translator->setLocale($locale);

            if ($translator instanceof Translator) {
                // Ensure the locale has been loaded.
                $translator->addResource('array', require $filename, $locale);
            }

            return true;
        }

        return false;
    }

    ///////////////////////////////////////////////////////////////////
    ////////////////////////// 字符串格式化 ///////////////////////////
    ///////////////////////////////////////////////////////////////////

    /*
     * 设置是否采用 utf8 字符串编码
     *
     * @param bool $utf8
     */
    public static function setUtf8($utf8)
    {
        static::$utf8 = $utf8;
    }

    /*
     * 本地化格式输出
     * You can set the current locale using setlocale() http://php.net/setlocale.
     *
     * @param string $format
     *
     * @return string
     */
    public function formatLocalized($format)
    {
        // Check for Windows to find and replace the %e
        // modifier correctly
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $format = preg_replace('#(?<!%)((?:%%)*)%e#', '\1%#d', $format);
        }

        $formatted = strftime($format, strtotime($this));

        return static::$utf8 ? utf8_encode($formatted) : $formatted;
    }

    /*
     * 恢复默认 __toString  方法格式化输出时间的格式
     */
    public static function resetToStringFormat()
    {
        static::setToStringFormat(static::DEFAULT_TO_STRING_FORMAT);
    }

    /*
     * 设置 __toString  方法格式化输出时间的格式
     *
     * @param string $format
     */
    public static function setToStringFormat($format)
    {
        static::$toStringFormat = $format;
    }

    /*
     * 字符串格式输出
     *
     * @return string
     */
    public function __toString()
    {
        return $this->format(static::$toStringFormat);
    }

    /*
     * 日期格式化输出
     *
     * @return string
     */
    public function toDateString()
    {
        return $this->format('Y-m-d');
    }

    /*
     * 可读格式化输出
     * 月份 几号， 年   例： Jan 11, 1999
     *
     * @return string
     */
    public function toFormattedDateString()
    {
        return $this->format('M j, Y');
    }

    /*
     * 时间格式化输出
     *
     * @return string
     */
    public function toTimeString()
    {
        return $this->format('H:i:s');
    }

    /*
     * 日期、时间格式化输出
     *
     * @return string
     */
    public function toDateTimeString()
    {
        return $this->format('Y-m-d H:i:s');
    }

    /*
     * 格式化输出如 "Fri, Jan 3, 2013 10:50 PM"
     *
     * @return string
     */
    public function toDayDateTimeString()
    {
        return $this->format('D, M j, Y g:i A');
    }

    /*
     * static::ATOM的值为："Y-m-d\TH:i:sP", 例"2012-10-20T14:12:26+00:00"
     *
     * @return string
     */
    public function toAtomString()
    {
        return $this->format(static::ATOM);
    }

    /*
     * static::COOKIE 的值为："l, d-M-Y H:i:s T", 例"Friday, 02-Jan-2012 14:20:39 UTC"
     *
     * @return string
     */
    public function toCookieString()
    {
        return $this->format(static::COOKIE);
    }

    /*
     * 格式化时间，同 toAtomString
     *
     * @return string
     */
    public function toIso8601String()
    {
        return $this->toAtomString();
    }

    /*
     * static::RFC822 的值为 "D, d M y H:i:s O", 例“Mon, 15 Aug 05 15:52:01 +0000”
     *
     * @return string
     */
    public function toRfc822String()
    {
        return $this->format(static::RFC822);
    }

    /*
     * static::RFC850 的值为 "l, d-M-y H:i:s T", 例 “Monday, 15-Aug-05 15:52:01 UTC”
     *
     * @return string
     */
    public function toRfc850String()
    {
        return $this->format(static::RFC850);
    }

    /*
     * static::RFC1036 的值为 “D, d M y H:i:s O”, 例 “2005-08-15T15:52:01+0000”
     *
     * @return string
     */
    public function toRfc1036String()
    {
        return $this->format(static::RFC1036);
    }

    /*
     * static::RFC1123 的值为 "D, d M Y H:i:s O", 例如 “Mon, 15 Aug 2005 15:52:01 +0000”
     *
     * @return string
     */
    public function toRfc1123String()
    {
        return $this->format(static::RFC1123);
    }

    /*
     * static::RFC2822 的值为：“D, d M Y H:i:s O”, 例如 “Mon, 15 Aug 05 15:52:01 +0000”
     *
     * @return string
     */
    public function toRfc2822String()
    {
        return $this->format(static::RFC2822);
    }

    /*
     * 格式化时间，同 toAtomString
     *
     * @return string
     */
    public function toRfc3339String()
    {
        return $this->format(static::RFC3339);
    }

    /*
     * static::RSS 的值为 "D, d M Y H:i:s O", 例如 “Mon, 15 Aug 2005 15:52:01 +0000”
     *
     * @return string
     */
    public function toRssString()
    {
        return $this->format(static::RSS);
    }

    /*
     * static::W3C 的值为 "Y-m-d\TH:i:sP", 例如 “2005-08-15T15:52:01+00:00”
     *
     * @return string
     */
    public function toW3cString()
    {
        return $this->format(static::W3C);
    }

    ///////////////////////////////////////////////////////////////////
    ////////////////////////////// 比较 ///////////////////////////////
    ///////////////////////////////////////////////////////////////////

    /*
     * 判断时间是否相等
     *
     * @param Carbon $dt
     *
     * @return bool
     */
    public function eq(Carbon $dt)
    {
        return $this == $dt;
    }

    /*
     * 判断时间是否相等
     *
     * @param Carbon $dt
     *
     * @see eq()
     *
     * @return bool
     */
    public function equalTo(Carbon $dt)
    {
        return $this->eq($dt);
    }

    /*
     * 判断时间是否不等
     *
     * @param Carbon $dt
     *
     * @return bool
     */
    public function ne(Carbon $dt)
    {
        return !$this->eq($dt);
    }

    /*
     * 判断时间是否不等
     *
     * @param Carbon $dt
     *
     * @see ne()
     *
     * @return bool
     */
    public function notEqualTo(Carbon $dt)
    {
        return $this->ne($dt);
    }

    /*
     * 判断当前实例时间是否大于指定时间
     *
     * @param Carbon $dt
     *
     * @return bool
     */
    public function gt(Carbon $dt)
    {
        return $this > $dt;
    }

    /*
     * 判断当前实例时间是否大于指定时间
     *
     * @param Carbon $dt
     *
     * @see gt()
     *
     * @return bool
     */
    public function greaterThan(Carbon $dt)
    {
        return $this->gt($dt);
    }

    /*
     * 判断当前实例时间是否大于等于指定时间
     *
     * @param Carbon $dt
     *
     * @return bool
     */
    public function gte(Carbon $dt)
    {
        return $this >= $dt;
    }

    /*
     * 判断当前实例时间是否大于等于指定时间
     *
     * @param Carbon $dt
     *
     * @see gte()
     *
     * @return bool
     */
    public function greaterThanOrEqualTo(Carbon $dt)
    {
        return $this->gte($dt);
    }

    /*
     * 判断当前实例时间是否小于指定时间
     *
     * @param Carbon $dt
     *
     * @return bool
     */
    public function lt(Carbon $dt)
    {
        return $this < $dt;
    }

    /*
     * 判断当前实例时间是否小于指定时间
     *
     * @param Carbon $dt
     *
     * @see lt()
     *
     * @return bool
     */
    public function lessThan(Carbon $dt)
    {
        return $this->lt($dt);
    }

    /*
     * 判断当前实例时间是否小于等于指定时间
     *
     * @param Carbon $dt
     *
     * @return bool
     */
    public function lte(Carbon $dt)
    {
        return $this <= $dt;
    }

    /*
     * 判断当前实例时间是否小于等于指定时间
     *
     * @param Carbon $dt
     *
     * @see lte()
     *
     * @return bool
     */
    public function lessThanOrEqualTo(Carbon $dt)
    {
        return $this->lte($dt);
    }

    /*
     * 判断当前实例时间是否在指定时间范围内
     *
     * @param Carbon $dt1
     * @param Carbon $dt2
     * @param bool   $equal Indicates if a > and < comparison should be used or <= or >=
     *
     * @return bool
     */
    public function between(Carbon $dt1, Carbon $dt2, $equal = true)
    {
        if ($dt1->gt($dt2)) {
            $temp = $dt1;
            $dt1 = $dt2;
            $dt2 = $temp;
        }

        if ($equal) {
            return $this->gte($dt1) && $this->lte($dt2);
        }

        return $this->gt($dt1) && $this->lt($dt2);
    }

    /*
     * 获取指定时间中更接近当前实例时间的对象
     *
     * @param Carbon $dt1
     * @param Carbon $dt2
     *
     * @return static
     */
    public function closest(Carbon $dt1, Carbon $dt2)
    {
        return $this->diffInSeconds($dt1) < $this->diffInSeconds($dt2) ? $dt1 : $dt2;
    }

    /*
     * 获取指定时间中更远于当前实例时间的对象
     *
     * @param Carbon $dt1
     * @param Carbon $dt2
     *
     * @return static
     */
    public function farthest(Carbon $dt1, Carbon $dt2)
    {
        return $this->diffInSeconds($dt1) > $this->diffInSeconds($dt2) ? $dt1 : $dt2;
    }

    /*
     * 获取当前实例与指定时间中更小的时间
     *
     * @param \Carbon\Carbon|null $dt
     *
     * @return static
     */
    public function min(Carbon $dt = null)
    {
        $dt = $dt ?: static::now($this->getTimezone());

        return $this->lt($dt) ? $this : $dt;
    }

    /*
     * 获取当前实例与指定时间中更小的时间
     *
     * @param \Carbon\Carbon|null $dt
     *
     * @see min()
     *
     * @return static
     */
    public function minimum(Carbon $dt = null)
    {
        return $this->min($dt);
    }

    /*
     * 获取当前实例与指定时间中更大的那个时间
     *
     * @param \Carbon\Carbon|null $dt
     *
     * @return static
     */
    public function max(Carbon $dt = null)
    {
        $dt = $dt ?: static::now($this->getTimezone());

        return $this->gt($dt) ? $this : $dt;
    }

    /*
     * 获取当前实例与指定时间中更大的那个时间
     *
     * @param \Carbon\Carbon|null $dt
     *
     * @see max()
     *
     * @return static
     */
    public function maximum(Carbon $dt = null)
    {
        return $this->max($dt);
    }

    /*
     * 判断当前实例时间是否是工作日
     *
     * @return bool
     */
    public function isWeekday()
    {
        return !$this->isWeekend();
    }

    /*
     * 判断当前实例时间是否是周末
     *
     * @return bool
     */
    public function isWeekend()
    {
        return in_array($this->dayOfWeek, static::$weekendDays);
    }

    /*
     * 判断当前实例时间是否是昨天
     *
     * @return bool
     */
    public function isYesterday()
    {
        return $this->toDateString() === static::yesterday($this->getTimezone())->toDateString();
    }

    /*
     * 判断当前实例时间是否是今天
     *
     * @return bool
     */
    public function isToday()
    {
        return $this->toDateString() === static::now($this->getTimezone())->toDateString();
    }

    /*
     * 判断当前实例时间是否是明天
     *
     * @return bool
     */
    public function isTomorrow()
    {
        return $this->toDateString() === static::tomorrow($this->getTimezone())->toDateString();
    }

    /*
     * 判断当前实例时间是否是在下个星期
     *
     * @return bool
     */
    public function isNextWeek()
    {
        return $this->weekOfYear === static::now($this->getTimezone())->addWeek()->weekOfYear;
    }

    /*
     * 判断当前实例时间是否是在上个星期
     *
     * @return bool
     */
    public function isLastWeek()
    {
        return $this->weekOfYear === static::now($this->getTimezone())->subWeek()->weekOfYear;
    }

    /*
     * 判断当前实例时间是否在下个月
     *
     * @return bool
     */
    public function isNextMonth()
    {
        return $this->month === static::now($this->getTimezone())->addMonthNoOverflow()->month;
    }

    /*
     * 判断当前实例时间是否在上个月
     *
     * @return bool
     */
    public function isLastMonth()
    {
        return $this->month === static::now($this->getTimezone())->subMonthNoOverflow()->month;
    }

    /*
     * 判断当前实例时间是否在明年
     *
     * @return bool
     */
    public function isNextYear()
    {
        return $this->year === static::now($this->getTimezone())->addYear()->year;
    }

    /*
     * 判断当前实例时间是否在去年
     *
     * @return bool
     */
    public function isLastYear()
    {
        return $this->year === static::now($this->getTimezone())->subYear()->year;
    }

    /*
     * 判断当前实例时间是否在未来
     *
     * @return bool
     */
    public function isFuture()
    {
        return $this->gt(static::now($this->getTimezone()));
    }

    /*
     * 判断当前实例时间是否在过去
     *
     * @return bool
     */
    public function isPast()
    {
        return $this->lt(static::now($this->getTimezone()));
    }

    /*
     * 判断当前实例是否在闰年
     *
     * @return bool
     */
    public function isLeapYear()
    {
        return $this->format('L') === '1';
    }

    /*
     * 判断当前实例时间是否是长年, 即一年有 53 个星期
     *
     * @see https://en.wikipedia.org/wiki/ISO_8601#Week_dates
     *
     * @return bool
     */
    public function isLongYear()
    {
        return static::create($this->year, 12, 28, 0, 0, 0, $this->tz)->weekOfYear === 53;
    }

    /*
     * 比较两个时间的格式化结果
     *
     * @param string              $format The date formats to compare.
     * @param \Carbon\Carbon|null $dt     The instance to compare with or null to use current day.
     *
     * @return bool
     */
    public function isSameAs($format, Carbon $dt = null)
    {
        $dt = $dt ?: static::now($this->tz);

        return $this->format($format) === $dt->format($format);
    }

    /*
     * 判断当前实例时间是否在今年
     *
     * @return bool
     */
    public function isCurrentYear()
    {
        return $this->isSameYear();
    }

    /*
     * 判断指定时间与当前实例时间是否是同一年
     *
     * @param \Carbon\Carbon|null $dt The instance to compare with or null to use current day.
     *
     * @return bool
     */
    public function isSameYear(Carbon $dt = null)
    {
        return $this->isSameAs('Y', $dt);
    }

    /*
     * 判断当前实例时间是否在本月
     *
     * @return bool
     */
    public function isCurrentMonth()
    {
        return $this->isSameMonth();
    }

    /*
     * 判断当前实例时间与指定时间是否是同一月份（年可选）
     *
     * @param \Carbon\Carbon|null $dt         The instance to compare with or null to use current day.
     * @param bool                $ofSameYear Check if it is the same month in the same year.
     *
     * @return bool
     */
    public function isSameMonth(Carbon $dt = null, $ofSameYear = false)
    {
        $format = $ofSameYear ? 'Y-m' : 'm';

        return $this->isSameAs($format, $dt);
    }

    /*
     * 判断指定时间与当前实例时间是否是同一天
     *
     * @param \Carbon\Carbon $dt
     *
     * @return bool
     */
    public function isSameDay(Carbon $dt)
    {
        return $this->toDateString() === $dt->toDateString();
    }

    /*
     * 判断当前实例时间是否是周日
     *
     * @return bool
     */
    public function isSunday()
    {
        return $this->dayOfWeek === static::SUNDAY;
    }

    /*
     * 判断当前实例时间是否是周一
     *
     * @return bool
     */
    public function isMonday()
    {
        return $this->dayOfWeek === static::MONDAY;
    }

    /*
     * 判断当前实例时间是否是周二
     *
     * @return bool
     */
    public function isTuesday()
    {
        return $this->dayOfWeek === static::TUESDAY;
    }

    /*
     * 判断当前实例时间是否是周三
     *
     * @return bool
     */
    public function isWednesday()
    {
        return $this->dayOfWeek === static::WEDNESDAY;
    }

    /*
     * 判断当前实例时间是否是周四
     *
     * @return bool
     */
    public function isThursday()
    {
        return $this->dayOfWeek === static::THURSDAY;
    }

    /*
     * 判断当前实例时间是否是周五
     *
     * @return bool
     */
    public function isFriday()
    {
        return $this->dayOfWeek === static::FRIDAY;
    }

    /*
     * 判断当前实例时间是否是周六
     *
     * @return bool
     */
    public function isSaturday()
    {
        return $this->dayOfWeek === static::SATURDAY;
    }

    ///////////////////////////////////////////////////////////////////
    //////////////////////////// 加与减 ///////////////////////////////
    ///////////////////////////////////////////////////////////////////

    /*
     * 当前实例增加年份，正数为增，负数为减
     *
     * @param int $value
     *
     * @return static
     */
    public function addYears($value)
    {
        return $this->modify((int) $value.' year');
    }

    /*
     * 当前实例年份默认增加一年
     *
     * @param int $value
     *
     * @return static
     */
    public function addYear($value = 1)
    {
        return $this->addYears($value);
    }

    /*
     * 当前实例年份默认减一年
     *
     * @param int $value
     *
     * @return static
     */
    public function subYear($value = 1)
    {
        return $this->subYears($value);
    }

    /*
     * 当前实例减少指定年份
     *
     * @param int $value
     *
     * @return static
     */
    public function subYears($value)
    {
        return $this->addYears(-1 * $value);
    }

    /*
     * 当前实例时间增加指定数量的季度, 可正负
     *
     * @param int $value
     *
     * @return static
     */
    public function addQuarters($value)
    {
        return $this->addMonths(static::MONTHS_PER_QUARTER * $value);
    }

    /*
     * 当前实例时间默认增加一个季度
     *
     * @param int $value
     *
     * @return static
     */
    public function addQuarter($value = 1)
    {
        return $this->addQuarters($value);
    }

    /*
     * 当前实例时间默认减少一个季度
     *
     * @param int $value
     *
     * @return static
     */
    public function subQuarter($value = 1)
    {
        return $this->subQuarters($value);
    }

    /*
     * 当前实例时间减少指定数量的季度
     *
     * @param int $value
     *
     * @return static
     */
    public function subQuarters($value)
    {
        return $this->addQuarters(-1 * $value);
    }

    /*
     * 当前实例添加指定数量的世纪，可正负
     * negative $value travels into the past.
     *
     * @param int $value
     *
     * @return static
     */
    public function addCenturies($value)
    {
        return $this->addYears(static::YEARS_PER_CENTURY * $value);
    }

    /*
     * 当前实例默认添加一个数量的世纪
     *
     * @param int $value
     *
     * @return static
     */
    public function addCentury($value = 1)
    {
        return $this->addCenturies($value);
    }

    /*
     * 当前实例默认减去一个数量的世纪
     *
     * @param int $value
     *
     * @return static
     */
    public function subCentury($value = 1)
    {
        return $this->subCenturies($value);
    }

    /*
     * 当前实例默认减去指定数量的世纪
     *
     * @param int $value
     *
     * @return static
     */
    public function subCenturies($value)
    {
        return $this->addCenturies(-1 * $value);
    }

    /*
     * 当前实例添加指定数量的月份；比如当前实例天是 5月31号，增加一个月后，不存在6月31号，
     * 如果 monthsOverflow 为 true，则实例时间为 7月1号，否则为 6月30号
     *
     * @param int $value
     *
     * @return static
     */
    public function addMonths($value)
    {
        if (static::shouldOverflowMonths()) {
            return $this->addMonthsWithOverflow($value);
        }

        return $this->addMonthsNoOverflow($value);
    }

    /*
     * 当初实例时间默认增加1个月
     *
     * @param int $value
     *
     * @return static
     */
    public function addMonth($value = 1)
    {
        return $this->addMonths($value);
    }

    /*
     * 当初实例时间默认减去1个月
     *
     * @param int $value
     *
     * @return static
     */
    public function subMonth($value = 1)
    {
        return $this->subMonths($value);
    }

    /*
     * 当前实例时间月份减去指定数量月
     *
     * @param int $value
     *
     * @return static
     */
    public function subMonths($value)
    {
        return $this->addMonths(-1 * $value);
    }

    /*
     * 当前实例增加指定数量的月份
     *
     * @param int $value
     *
     * @return static
     */
    public function addMonthsWithOverflow($value)
    {
        return $this->modify((int) $value.' month');
    }

    /*
     * 当前实例添加指定数量的月份
     *
     * @param int $value
     *
     * @return static
     */
    public function addMonthWithOverflow($value = 1)
    {
        return $this->addMonthsWithOverflow($value);
    }

    /*
     * 当前实例默认减去指定1个月
     *
     * @param int $value
     *
     * @return static
     */
    public function subMonthWithOverflow($value = 1)
    {
        return $this->subMonthsWithOverflow($value);
    }

    /*
     * 当前实例减去指定数量的月份
     *
     * @param int $value
     *
     * @return static
     */
    public function subMonthsWithOverflow($value)
    {
        return $this->addMonthsWithOverflow(-1 * $value);
    }

    /*
     * 当前实例添加指定数量的月份（非溢出）
     *
     * @param int $value
     *
     * @return static
     */
    public function addMonthsNoOverflow($value)
    {
        $day = $this->day;

        $this->modify((int) $value.' month');

        if ($day !== $this->day) {
            $this->modify('last day of previous month');
        }

        return $this;
    }

    /*
     * 当前实例默认添加1个月（非溢出）
     *
     * @param int $value
     *
     * @return static
     */
    public function addMonthNoOverflow($value = 1)
    {
        return $this->addMonthsNoOverflow($value);
    }

    /*
     * 当前实例默认减去1个月（非溢出）
     *
     * @param int $value
     *
     * @return static
     */
    public function subMonthNoOverflow($value = 1)
    {
        return $this->subMonthsNoOverflow($value);
    }

    /*
     * 当前实例默认减去指定数量的月份（非溢出）
     *
     * @param int $value
     *
     * @return static
     */
    public function subMonthsNoOverflow($value)
    {
        return $this->addMonthsNoOverflow(-1 * $value);
    }

    /*
     * 添加指定天数
     *
     * @param int $value
     *
     * @return static
     */
    public function addDays($value)
    {
        return $this->modify((int) $value.' day');
    }

    /*
     * 默认添加一天
     *
     * @param int $value
     *
     * @return static
     */
    public function addDay($value = 1)
    {
        return $this->addDays($value);
    }

    /*
     * 默认减去一天
     *
     * @param int $value
     *
     * @return static
     */
    public function subDay($value = 1)
    {
        return $this->subDays($value);
    }

    /*
     * 减去指定天数
     *
     * @param int $value
     *
     * @return static
     */
    public function subDays($value)
    {
        return $this->addDays(-1 * $value);
    }

    /*
     * 添加指定数量的工作日数
     *
     * @param int $value
     *
     * @return static
     */
    public function addWeekdays($value)
    {
        // fix for https://bugs.php.net/bug.php?id=54909
        $t = $this->toTimeString();
        $this->modify((int) $value.' weekday');

        return $this->setTimeFromTimeString($t);
    }

    /*
     * 当前实例默认添加一个工作日
     *
     * @param int $value
     *
     * @return static
     */
    public function addWeekday($value = 1)
    {
        return $this->addWeekdays($value);
    }

    /*
     * 当前实例默认减去一个工作日
     *
     * @param int $value
     *
     * @return static
     */
    public function subWeekday($value = 1)
    {
        return $this->subWeekdays($value);
    }

    /*
     * 当前实例时间默认减去指定数量的工作日
     *
     * @param int $value
     *
     * @return static
     */
    public function subWeekdays($value)
    {
        return $this->addWeekdays(-1 * $value);
    }

    /*
     * 当前实例时间添加指定数量的星期
     *
     * @param int $value
     *
     * @return static
     */
    public function addWeeks($value)
    {
        return $this->modify((int) $value.' week');
    }

    /*
     * 当前实例时间默认添加1个星期
     *
     * @param int $value
     *
     * @return static
     */
    public function addWeek($value = 1)
    {
        return $this->addWeeks($value);
    }

    /*
     * 当前实例时间默认减去1个星期
     *
     * @param int $value
     *
     * @return static
     */
    public function subWeek($value = 1)
    {
        return $this->subWeeks($value);
    }

    /*
     * 当前实例时间减去指定数量的星期
     *
     * @param int $value
     *
     * @return static
     */
    public function subWeeks($value)
    {
        return $this->addWeeks(-1 * $value);
    }

    /*
     * 添加指定数量的小时
     *
     * @param int $value
     *
     * @return static
     */
    public function addHours($value)
    {
        return $this->modify((int) $value.' hour');
    }

    /*
     * 当前实例时间默认添加 1 个小时
     *
     * @param int $value
     *
     * @return static
     */
    public function addHour($value = 1)
    {
        return $this->addHours($value);
    }

    /*
     * 当前实例时间默认减少 1 个小时
     *
     * @param int $value
     *
     * @return static
     */
    public function subHour($value = 1)
    {
        return $this->subHours($value);
    }

    /*
     * 当前实例时间减去指定数量的小时
     *
     * @param int $value
     *
     * @return static
     */
    public function subHours($value)
    {
        return $this->addHours(-1 * $value);
    }

    /*
     * 当前实例时间添加指定数量的分钟
     *
     * @param int $value
     *
     * @return static
     */
    public function addMinutes($value)
    {
        return $this->modify((int) $value.' minute');
    }

    /*
     * 当前实例时间默认添加 1 分钟
     *
     * @param int $value
     *
     * @return static
     */
    public function addMinute($value = 1)
    {
        return $this->addMinutes($value);
    }

    /*
     * 当前实例时间默认减少 1 分钟
     *
     * @param int $value
     *
     * @return static
     */
    public function subMinute($value = 1)
    {
        return $this->subMinutes($value);
    }

    /*
     * 当前实例时间减少指定数量的分钟
     *
     * @param int $value
     *
     * @return static
     */
    public function subMinutes($value)
    {
        return $this->addMinutes(-1 * $value);
    }

    /*
     * 当前实例时间添加指定数量的秒数
     *
     * @param int $value
     *
     * @return static
     */
    public function addSeconds($value)
    {
        return $this->modify((int) $value.' second');
    }

    /*
     * 当前实例时间默认添加 1 秒
     *
     * @param int $value
     *
     * @return static
     */
    public function addSecond($value = 1)
    {
        return $this->addSeconds($value);
    }

    /*
     * 当前实例时间默认减少 1 分钟
     *
     * @param int $value
     *
     * @return static
     */
    public function subSecond($value = 1)
    {
        return $this->subSeconds($value);
    }

    /*
     * 当前实例时间默认减少指定数量的分钟
     *
     * @param int $value
     *
     * @return static
     */
    public function subSeconds($value)
    {
        return $this->addSeconds(-1 * $value);
    }

    ///////////////////////////////////////////////////////////////////
    ////////////////////////////// 差值比较 ///////////////////////////
    ///////////////////////////////////////////////////////////////////

    /*
     * 获取年差
     *
     * @param \Carbon\Carbon|null $dt
     * @param bool                $abs Get the absolute of the difference
     *
     * @return int
     */
    public function diffInYears(Carbon $dt = null, $abs = true)
    {
        $dt = $dt ?: static::now($this->getTimezone());

        return (int) $this->diff($dt, $abs)->format('%r%y');
    }

    /*
     * 获取月差
     *
     * @param \Carbon\Carbon|null $dt
     * @param bool                $abs Get the absolute of the difference
     *
     * @return int
     */
    public function diffInMonths(Carbon $dt = null, $abs = true)
    {
        $dt = $dt ?: static::now($this->getTimezone());

        return $this->diffInYears($dt, $abs) * static::MONTHS_PER_YEAR + (int) $this->diff($dt, $abs)->format('%r%m');
    }

    /*
     * 获取星期差(小数点后省略)
     *
     * @param \Carbon\Carbon|null $dt
     * @param bool                $abs Get the absolute of the difference
     *
     * @return int
     */
    public function diffInWeeks(Carbon $dt = null, $abs = true)
    {
        return (int) ($this->diffInDays($dt, $abs) / static::DAYS_PER_WEEK);
    }

    /*
     * 获取天数差
     *
     * @param \Carbon\Carbon|null $dt
     * @param bool                $abs Get the absolute of the difference
     *
     * @return int
     */
    public function diffInDays(Carbon $dt = null, $abs = true)
    {
        $dt = $dt ?: static::now($this->getTimezone());

        return (int) $this->diff($dt, $abs)->format('%r%a');
    }

    /*
     * 获取时间差的数目, 回调函数用于过滤出有效时间
     *
     * @param Closure             $callback
     * @param \Carbon\Carbon|null $dt
     * @param bool                $abs      Get the absolute of the difference
     *
     * @return int
     */
    public function diffInDaysFiltered(Closure $callback, Carbon $dt = null, $abs = true)
    {
        return $this->diffFiltered(CarbonInterval::day(), $callback, $dt, $abs);
    }

    /*
     * 获取小时差的数目
     *
     * @param Closure             $callback
     * @param \Carbon\Carbon|null $dt
     * @param bool                $abs      Get the absolute of the difference
     *
     * @return int
     */
    public function diffInHoursFiltered(Closure $callback, Carbon $dt = null, $abs = true)
    {
        return $this->diffFiltered(CarbonInterval::hour(), $callback, $dt, $abs);
    }

    /*
     * 指定时间间隔单位，获取差值；
     *
     * @param CarbonInterval $ci       An interval to traverse by
     * @param Closure        $callback
     * @param Carbon|null    $dt
     * @param bool           $abs      Get the absolute of the difference
     *
     * @return int
     */
    public function diffFiltered(CarbonInterval $ci, Closure $callback, Carbon $dt = null, $abs = true)
    {
        $start = $this;
        $end = $dt ?: static::now($this->getTimezone());
        $inverse = false;

        if ($end < $start) {
            $start = $end;
            $end = $this;
            $inverse = true;
        }

        $period = new DatePeriod($start, $ci, $end);
        $vals = array_filter(iterator_to_array($period), function (DateTime $date) use ($callback) {
            return call_user_func($callback, Carbon::instance($date));
        });

        $diff = count($vals);

        return $inverse && !$abs ? -$diff : $diff;
    }

    /*
     * 获取两个时间之间工作日的数目
     *
     * @param \Carbon\Carbon|null $dt
     * @param bool                $abs Get the absolute of the difference
     *
     * @return int
     */
    public function diffInWeekdays(Carbon $dt = null, $abs = true)
    {
        return $this->diffInDaysFiltered(function (Carbon $date) {
            return $date->isWeekday();
        }, $dt, $abs);
    }

    /*
     * 获取两个时间之间周末的数目
     *
     * @param \Carbon\Carbon|null $dt
     * @param bool                $abs Get the absolute of the difference
     *
     * @return int
     */
    public function diffInWeekendDays(Carbon $dt = null, $abs = true)
    {
        return $this->diffInDaysFiltered(function (Carbon $date) {
            return $date->isWeekend();
        }, $dt, $abs);
    }

    /*
     * 获取两个时间的小时差
     *
     * @param \Carbon\Carbon|null $dt
     * @param bool                $abs Get the absolute of the difference
     *
     * @return int
     */
    public function diffInHours(Carbon $dt = null, $abs = true)
    {
        return (int) ($this->diffInSeconds($dt, $abs) / static::SECONDS_PER_MINUTE / static::MINUTES_PER_HOUR);
    }

    /*
     * 获取两个时间的分钟差
     *
     * @param \Carbon\Carbon|null $dt
     * @param bool                $abs Get the absolute of the difference
     *
     * @return int
     */
    public function diffInMinutes(Carbon $dt = null, $abs = true)
    {
        return (int) ($this->diffInSeconds($dt, $abs) / static::SECONDS_PER_MINUTE);
    }

    /*
     * 获取指定时间与当前时间的差，前者 - 后者
     *
     * @param \Carbon\Carbon|null $dt
     * @param bool                $abs Get the absolute of the difference
     *
     * @return int
     */
    public function diffInSeconds(Carbon $dt = null, $abs = true)
    {
        $dt = $dt ?: static::now($this->getTimezone());
        $value = $dt->getTimestamp() - $this->getTimestamp();

        return $abs ? abs($value) : $value;
    }

    /*
     * 从当前实例时间的 0时0分0秒 与当前实例时间的秒差
     *
     * @return int
     */
    public function secondsSinceMidnight()
    {
        return $this->diffInSeconds($this->copy()->startOfDay());
    }

    /*
     * The number of seconds until 23:59:59.
     * 从当前实例时间的 23时59分59秒 与当前实例时间的秒差
     *
     * @return int
     */
    public function secondsUntilEndOfDay()
    {
        return $this->diffInSeconds($this->copy()->endOfDay());
    }

    /*
     * Get the difference in a human readable format in the current locale.
     *
     * When comparing a value in the past to default now:
     * 1 hour ago
     * 5 months ago
     *
     * When comparing a value in the future to default now:
     * 1 hour from now
     * 5 months from now
     *
     * When comparing a value in the past to another value:
     * 1 hour before
     * 5 months before
     *
     * When comparing a value in the future to another value:
     * 1 hour after
     * 5 months after
     *
     * @param Carbon|null $other
     * @param bool        $absolute removes time difference modifiers ago, after, etc
     * @param bool        $short    displays short format of time units
     *
     * @return string
     */
    public function diffForHumans(Carbon $other = null, $absolute = false, $short = false)
    {
        $isNow = $other === null;

        if ($isNow) {
            $other = static::now($this->getTimezone());
        }

        $diffInterval = $this->diff($other);

        switch (true) {
            case $diffInterval->y > 0:
                $unit = $short ? 'y' : 'year';
                $count = $diffInterval->y;
                break;

            case $diffInterval->m > 0:
                $unit = $short ? 'm' : 'month';
                $count = $diffInterval->m;
                break;

            case $diffInterval->d > 0:
                $unit = $short ? 'd' : 'day';
                $count = $diffInterval->d;

                if ($count >= static::DAYS_PER_WEEK) {
                    $unit = $short ? 'w' : 'week';
                    $count = (int) ($count / static::DAYS_PER_WEEK);
                }
                break;

            case $diffInterval->h > 0:
                $unit = $short ? 'h' : 'hour';
                $count = $diffInterval->h;
                break;

            case $diffInterval->i > 0:
                $unit = $short ? 'min' : 'minute';
                $count = $diffInterval->i;
                break;

            default:
                $count = $diffInterval->s;
                $unit = $short ? 's' : 'second';
                break;
        }

        if ($count === 0) {
            $count = 1;
        }

        $time = static::translator()->transChoice($unit, $count, array(':count' => $count));

        if ($absolute) {
            return $time;
        }

        $isFuture = $diffInterval->invert === 1;

        $transId = $isNow ? ($isFuture ? 'from_now' : 'ago') : ($isFuture ? 'after' : 'before');

        // Some langs have special pluralization for past and future tense.
        $tryKeyExists = $unit.'_'.$transId;
        if ($tryKeyExists !== static::translator()->transChoice($tryKeyExists, $count)) {
            $time = static::translator()->transChoice($tryKeyExists, $count, array(':count' => $count));
        }

        return static::translator()->trans($transId, array(':time' => $time));
    }

    ///////////////////////////////////////////////////////////////////
    //////////////////////////// 设置时间 /////////////////////////////
    ///////////////////////////////////////////////////////////////////

    /*
     * 重置当前实例时间为 0时0分0秒
     *
     * @return static
     */
    public function startOfDay()
    {
        return $this->setTime(0, 0, 0);
    }

    /*
     * 重置当前实例时间为 23时59分59秒
     *
     * @return static
     */
    public function endOfDay()
    {
        return $this->setTime(23, 59, 59);
    }

    /*
     * 重置当前实例时间为 本月第一天，同时设置 0时0分0秒
     *
     * @return static
     */
    public function startOfMonth()
    {
        return $this->setDateTime($this->year, $this->month, 1, 0, 0, 0);
    }

    /*
     * 重置当前实例时间为 本月最后一天，同时设置 23时59分59秒
     *
     * @return static
     */
    public function endOfMonth()
    {
        return $this->setDateTime($this->year, $this->month, $this->daysInMonth, 23, 59, 59);
    }

    /*
     * 重置当前实例时间为 本季度第一天，同时设置 0时0分0秒
     *
     * @return static
     */
    public function startOfQuarter()
    {
        $month = ($this->quarter - 1) * static::MONTHS_PER_QUARTER + 1;

        return $this->setDateTime($this->year, $month, 1, 0, 0, 0);
    }

    /*
     * 重置当前实例时间为 本季度最后一天，同时设置 23时59分59秒
     *
     * @return static
     */
    public function endOfQuarter()
    {
        return $this->startOfQuarter()->addMonths(static::MONTHS_PER_QUARTER - 1)->endOfMonth();
    }

    /*
     * 重置当前实例时间为 本年第一天，同时设置 0时0分0秒
     *
     * @return static
     */
    public function startOfYear()
    {
        return $this->setDateTime($this->year, 1, 1, 0, 0, 0);
    }

    /*
     * 重置当前实例时间为 本年最后一天，同时设置 23时59分59秒
     *
     * @return static
     */
    public function endOfYear()
    {
        return $this->setDateTime($this->year, 12, 31, 23, 59, 59);
    }

    /*
     * 重置当前实例时间为 所在十年的第一天，同时设置 0时0分0秒
     *
     * @return static
     */
    public function startOfDecade()
    {
        $year = $this->year - $this->year % static::YEARS_PER_DECADE;

        return $this->setDateTime($year, 1, 1, 0, 0, 0);
    }

    /*
     * 重置当前实例时间为 所在十年的最后一天，同时设置 23时59分59秒
     *
     * @return static
     */
    public function endOfDecade()
    {
        $year = $this->year - $this->year % static::YEARS_PER_DECADE + static::YEARS_PER_DECADE - 1;

        return $this->setDateTime($year, 12, 31, 23, 59, 59);
    }

    /*
     * 重置当前实例时间为 本世纪的第一天，同时设置 0时0分0秒
     *
     * @return static
     */
    public function startOfCentury()
    {
        $year = $this->year - ($this->year - 1) % static::YEARS_PER_CENTURY;

        return $this->setDateTime($year, 1, 1, 0, 0, 0);
    }

    /*
     * 重置当前实例时间为 本世纪的最后一天，同时设置 23时59分59秒
     *
     * @return static
     */
    public function endOfCentury()
    {
        $year = $this->year - 1 - ($this->year - 1) % static::YEARS_PER_CENTURY + static::YEARS_PER_CENTURY;

        return $this->setDateTime($year, 12, 31, 23, 59, 59);
    }

    /*
     * 重置当前实例时间为 本周的第一天，同时设置 0时0分0秒
     *
     * @return static
     */
    public function startOfWeek()
    {
        while ($this->dayOfWeek !== static::$weekStartsAt) {
            $this->subDay();
        }

        return $this->startOfDay();
    }

    /*
     * 重置当前实例时间为 本周的最后一天，同时设置 23时59分59秒
     *
     * @return static
     */
    public function endOfWeek()
    {
        while ($this->dayOfWeek !== static::$weekEndsAt) {
            $this->addDay();
        }

        return $this->endOfDay();
    }

    /*
     * Modify to the next occurrence of a given day of the week.
     * If no dayOfWeek is provided, modify to the next occurrence
     * of the current day of the week.  Use the supplied constants
     * to indicate the desired dayOfWeek, ex. static::MONDAY.
     *
     * @param int|null $dayOfWeek
     *
     * @return static
     */
    public function next($dayOfWeek = null)
    {
        if ($dayOfWeek === null) {
            $dayOfWeek = $this->dayOfWeek;
        }

        return $this->startOfDay()->modify('next '.static::$days[$dayOfWeek]);
    }

    /*
     * 设置本实例时间为下一个工作日或周末时间
     *
     * @param bool $weekday
     * @param bool $forward
     *
     * @return $this
     */
    private function nextOrPreviousDay($weekday = true, $forward = true)
    {
        $step = $forward ? 1 : -1;

        do {
            $this->addDay($step);
        } while ($weekday ? $this->isWeekend() : $this->isWeekday());

        return $this;
    }

    /*
     * 设置本实例时间为下一个工作日
     *
     * @return $this
     */
    public function nextWeekday()
    {
        return $this->nextOrPreviousDay();
    }

    /*
     * 设置本实例时间为上一个工作日
     *
     *
     * @return $this
     */
    public function previousWeekday()
    {
        return $this->nextOrPreviousDay(true, false);
    }

    /*
     * 设置本实例时间为下一个周末时间
     *
     * @return $this
     */
    public function nextWeekendDay()
    {
        return $this->nextOrPreviousDay(false);
    }

    /*
     * 设置本实例时间为上一个周末时间
     *
     * @return $this
     */
    public function previousWeekendDay()
    {
        return $this->nextOrPreviousDay(false, false);
    }

    /*
     * Modify to the previous occurrence of a given day of the week.
     * If no dayOfWeek is provided, modify to the previous occurrence
     * of the current day of the week.  Use the supplied constants
     * to indicate the desired dayOfWeek, ex. static::MONDAY.
     *
     * @param int|null $dayOfWeek
     *
     * @return static
     */
    public function previous($dayOfWeek = null)
    {
        if ($dayOfWeek === null) {
            $dayOfWeek = $this->dayOfWeek;
        }

        return $this->startOfDay()->modify('last '.static::$days[$dayOfWeek]);
    }

    /*
     * Modify to the first occurrence of a given day of the week
     * in the current month. If no dayOfWeek is provided, modify to the
     * first day of the current month.  Use the supplied constants
     * to indicate the desired dayOfWeek, ex. static::MONDAY.
     *
     * @param int|null $dayOfWeek
     *
     * @return static
     */
    public function firstOfMonth($dayOfWeek = null)
    {
        $this->startOfDay();

        if ($dayOfWeek === null) {
            return $this->day(1);
        }

        return $this->modify('first '.static::$days[$dayOfWeek].' of '.$this->format('F').' '.$this->year);
    }

    /*
     * Modify to the last occurrence of a given day of the week
     * in the current month. If no dayOfWeek is provided, modify to the
     * last day of the current month.  Use the supplied constants
     * to indicate the desired dayOfWeek, ex. static::MONDAY.
     *
     * @param int|null $dayOfWeek
     *
     * @return static
     */
    public function lastOfMonth($dayOfWeek = null)
    {
        $this->startOfDay();

        if ($dayOfWeek === null) {
            return $this->day($this->daysInMonth);
        }

        return $this->modify('last '.static::$days[$dayOfWeek].' of '.$this->format('F').' '.$this->year);
    }

    /*
     * Modify to the given occurrence of a given day of the week
     * in the current month. If the calculated occurrence is outside the scope
     * of the current month, then return false and no modifications are made.
     * Use the supplied constants to indicate the desired dayOfWeek, ex. static::MONDAY.
     *
     * @param int $nth
     * @param int $dayOfWeek
     *
     * @return mixed
     */
    public function nthOfMonth($nth, $dayOfWeek)
    {
        $dt = $this->copy()->firstOfMonth();
        $check = $dt->format('Y-m');
        $dt->modify('+'.$nth.' '.static::$days[$dayOfWeek]);

        return $dt->format('Y-m') === $check ? $this->modify($dt) : false;
    }

    /*
     * Modify to the first occurrence of a given day of the week
     * in the current quarter. If no dayOfWeek is provided, modify to the
     * first day of the current quarter.  Use the supplied constants
     * to indicate the desired dayOfWeek, ex. static::MONDAY.
     *
     * @param int|null $dayOfWeek
     *
     * @return static
     */
    public function firstOfQuarter($dayOfWeek = null)
    {
        return $this->setDate($this->year, $this->quarter * static::MONTHS_PER_QUARTER - 2, 1)->firstOfMonth($dayOfWeek);
    }

    /*
     * Modify to the last occurrence of a given day of the week
     * in the current quarter. If no dayOfWeek is provided, modify to the
     * last day of the current quarter.  Use the supplied constants
     * to indicate the desired dayOfWeek, ex. static::MONDAY.
     *
     * @param int|null $dayOfWeek
     *
     * @return static
     */
    public function lastOfQuarter($dayOfWeek = null)
    {
        return $this->setDate($this->year, $this->quarter * static::MONTHS_PER_QUARTER, 1)->lastOfMonth($dayOfWeek);
    }

    /*
     * Modify to the given occurrence of a given day of the week
     * in the current quarter. If the calculated occurrence is outside the scope
     * of the current quarter, then return false and no modifications are made.
     * Use the supplied constants to indicate the desired dayOfWeek, ex. static::MONDAY.
     *
     * @param int $nth
     * @param int $dayOfWeek
     *
     * @return mixed
     */
    public function nthOfQuarter($nth, $dayOfWeek)
    {
        $dt = $this->copy()->day(1)->month($this->quarter * static::MONTHS_PER_QUARTER);
        $lastMonth = $dt->month;
        $year = $dt->year;
        $dt->firstOfQuarter()->modify('+'.$nth.' '.static::$days[$dayOfWeek]);

        return ($lastMonth < $dt->month || $year !== $dt->year) ? false : $this->modify($dt);
    }

    /*
     * Modify to the first occurrence of a given day of the week
     * in the current year. If no dayOfWeek is provided, modify to the
     * first day of the current year.  Use the supplied constants
     * to indicate the desired dayOfWeek, ex. static::MONDAY.
     *
     * @param int|null $dayOfWeek
     *
     * @return static
     */
    public function firstOfYear($dayOfWeek = null)
    {
        return $this->month(1)->firstOfMonth($dayOfWeek);
    }

    /*
     * Modify to the last occurrence of a given day of the week
     * in the current year. If no dayOfWeek is provided, modify to the
     * last day of the current year.  Use the supplied constants
     * to indicate the desired dayOfWeek, ex. static::MONDAY.
     *
     * @param int|null $dayOfWeek
     *
     * @return static
     */
    public function lastOfYear($dayOfWeek = null)
    {
        return $this->month(static::MONTHS_PER_YEAR)->lastOfMonth($dayOfWeek);
    }

    /*
     * Modify to the given occurrence of a given day of the week
     * in the current year. If the calculated occurrence is outside the scope
     * of the current year, then return false and no modifications are made.
     * Use the supplied constants to indicate the desired dayOfWeek, ex. static::MONDAY.
     *
     * @param int $nth
     * @param int $dayOfWeek
     *
     * @return mixed
     */
    public function nthOfYear($nth, $dayOfWeek)
    {
        $dt = $this->copy()->firstOfYear()->modify('+'.$nth.' '.static::$days[$dayOfWeek]);

        return $this->year === $dt->year ? $this->modify($dt) : false;
    }

    /*
     * Modify the current instance to the average of a given instance (default now) and the current instance.
     *
     * @param \Carbon\Carbon|null $dt
     *
     * @return static
     */
    public function average(Carbon $dt = null)
    {
        $dt = $dt ?: static::now($this->getTimezone());

        return $this->addSeconds((int) ($this->diffInSeconds($dt, false) / 2));
    }

    /*
     * Check if its the birthday. Compares the date/month values of the two dates.
     *
     * @param \Carbon\Carbon|null $dt The instance to compare with or null to use current day.
     *
     * @return bool
     */
    public function isBirthday(Carbon $dt = null)
    {
        return $this->isSameAs('md', $dt);
    }

    /*
     * Consider the timezone when modifying the instance.
     *
     * @param string $modify
     *
     * @return static
     */
    public function modify($modify)
    {
        if ($this->local) {
            return parent::modify($modify);
        }

        $timezone = $this->getTimezone();
        $this->setTimezone('UTC');
        $instance = parent::modify($modify);
        $this->setTimezone($timezone);

        return $instance;
    }

    /*
     * Return a serialized string of the instance.
     *
     * @return string
     */
    public function serialize()
    {
        return serialize($this);
    }

    /*
     * Create an instance form a serialized string.
     *
     * @param string $value
     *
     * @throws \InvalidArgumentException
     *
     * @return static
     */
    public static function fromSerialized($value)
    {
        $instance = @unserialize($value);

        if (!$instance instanceof static) {
            throw new InvalidArgumentException('Invalid serialized value.');
        }

        return $instance;
    }
}
