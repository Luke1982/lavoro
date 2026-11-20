<?php

namespace App\Domain\Quotes;

use App\Models\GeneralSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * What holds for every quote unless the quote says otherwise: the columns the
 * customer sees, the payment terms, the hourly rate a werkbon's hours are
 * offered at, the follow-up, and the company's general terms.
 *
 * The terms are a PDF on the tenant's private disk. A new upload is stored
 * beside the old one rather than over it: a version sent before still names the
 * file the customer was given, and that file has to stay what it was.
 */
final class QuoteSettings
{
    public const DEFAULT_PAYMENT_TERMS = 'Betaling binnen 14 dagen na factuurdatum.';

    public const DEFAULT_MAX_UPFRONT_PERCENT = 25;

    public const DEFAULT_REMINDER_DAYS = 7;

    public const DEFAULT_EXPIRY_NOTICE_DAYS = 3;

    private const TERMS_FOLDER = 'quote-terms';

    /** @return array<string, bool> */
    public static function layout(): array
    {
        return QuoteLayout::complete(json_decode((string) GeneralSetting::get('quote_layout', ''), true) ?: null);
    }

    public static function paymentTerms(): string
    {
        return (string) GeneralSetting::get('quote_payment_terms', self::DEFAULT_PAYMENT_TERMS);
    }

    public static function maxUpfrontPercent(): float
    {
        return (float) GeneralSetting::get('quote_max_upfront_percent', self::DEFAULT_MAX_UPFRONT_PERCENT);
    }

    public static function hourlyRate(): ?float
    {
        $rate = GeneralSetting::get('quote_hourly_rate');

        return $rate === null || $rate === '' ? null : (float) $rate;
    }

    /** Days after sending that the customer is reminded once; null when nobody is. */
    public static function reminderDays(): ?int
    {
        return self::days('quote_reminder_days', self::DEFAULT_REMINDER_DAYS);
    }

    /** Days before the quote runs out that its owner is told; null when nobody is. */
    public static function expiryNoticeDays(): ?int
    {
        return self::days('quote_expiry_notice_days', self::DEFAULT_EXPIRY_NOTICE_DAYS);
    }

    /** @return array{name: string, path: string}|null the general terms every quote goes out with */
    public static function terms(): ?array
    {
        $path = GeneralSetting::get('quote_terms_path');

        return $path
            ? ['name' => (string) GeneralSetting::get('quote_terms_name', 'Algemene voorwaarden.pdf'), 'path' => $path]
            : null;
    }

    public static function storeTerms(UploadedFile $file): void
    {
        GeneralSetting::set('quote_terms_path', Storage::disk('local')->putFile(self::TERMS_FOLDER, $file));
        GeneralSetting::set('quote_terms_name', $file->getClientOriginalName());
    }

    /** Quotes go out without terms from now on; the file stays for the versions that named it. */
    public static function removeTerms(): void
    {
        GeneralSetting::set('quote_terms_path', '');
        GeneralSetting::set('quote_terms_name', '');
    }

    /** @return array<string, mixed> for the settings page */
    public static function toArray(): array
    {
        return [
            'layout' => self::layout(),
            'payment_terms' => self::paymentTerms(),
            'max_upfront_percent' => self::maxUpfrontPercent(),
            'hourly_rate' => self::hourlyRate(),
            'reminder_days' => self::reminderDays(),
            'expiry_notice_days' => self::expiryNoticeDays(),
            'terms_name' => self::terms()['name'] ?? null,
        ];
    }

    /** @param array<string, mixed> $values validated, each one only when it was sent */
    public static function save(array $values): void
    {
        foreach ($values as $key => $value) {
            GeneralSetting::set('quote_' . $key, match (true) {
                $key === 'layout' => json_encode(QuoteLayout::complete($value, self::layout())),
                $value === null => '',
                default => $value,
            });
        }
    }

    /** A blank stored value means "off"; a value never stored means the default. */
    private static function days(string $key, int $default): ?int
    {
        $value = GeneralSetting::get($key);

        return match (true) {
            $value === null => $default,
            $value === '' => null,
            default => (int) $value,
        };
    }
}
