<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Converts between the store's local time (what admins type) and UTC (what Hyvä stores in time_scheduled).
 */
class ScheduleTime
{
    /**
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * Get store timezone.
     *
     * @return string
     */
    public function getStoreTimezone(): string
    {
        return $this->timezone->getConfigTimezone();
    }

    /**
     * To utc.
     *
     * @param string $localTime e.g. "2026-11-27 08:00"
     * @param string|null $timezone IANA name; defaults to the store timezone.
     * @return string UTC 'Y-m-d H:i:s'
     * @throws LocalizedException
     */
    public function toUtc(string $localTime, ?string $timezone = null): string
    {
        try {
            $date = new DateTimeImmutable($localTime, new DateTimeZone($timezone ?? $this->getStoreTimezone()));
        } catch (Exception) {
            throw new LocalizedException(
                __(
                    '"%1" is not a valid date/time (use YYYY-MM-DD HH:MM) or "%2" is not a valid timezone.',
                    $localTime,
                    (string) $timezone
                )
            );
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * To local.
     *
     * @param string $utc
     * @return string Store-local 'Y-m-d H:i T'
     */
    public function toLocal(string $utc): string
    {
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($this->getStoreTimezone()))
                ->format('Y-m-d H:i T');
        } catch (Exception) {
            return $utc;
        }
    }
}
