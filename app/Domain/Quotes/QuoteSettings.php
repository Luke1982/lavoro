<?php

namespace App\Domain\Quotes;

use App\Enums\QuoteTermsKinds;
use App\Models\GeneralSetting;
use App\Support\RichText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * What holds for every quote unless the quote says otherwise: the columns the
 * customer sees, the payment terms, the hourly rate a werkbon's hours are
 * offered at, the follow-up, and the company's general terms.
 *
 * The terms come one of three ways: a PDF on the tenant's private disk, a text
 * written here, or a link to the company's own page. A new upload is stored
 * beside the old one rather than over it: a version sent before still names the
 * file the customer was given, and that file has to stay what it was. A text is
 * frozen into each version as it was sent.
 */
final class QuoteSettings
{
    public const DEFAULT_PAYMENT_TERMS = 'Betaling binnen 14 dagen na factuurdatum.';

    public const DEFAULT_MAX_UPFRONT_PERCENT = 25;

    public const DEFAULT_REMINDER_DAYS = 7;

    public const DEFAULT_EXPIRY_NOTICE_DAYS = 3;

    private const TERMS_FOLDER = 'quote-terms';

    private const TERMS_NAME = 'Algemene voorwaarden';

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

    /**
     * The general terms every quote goes out with, in the way chosen, or null
     * when there are none: nothing chosen, or the chosen way has nothing in it.
     * A PDF is named by the file it is stored in, a text carries the text, a
     * link its address; each version freezes this as it was.
     *
     * @return array{kind: string, name: string, path?: string, html?: string, url?: string}|null
     */
    public static function terms(): ?array
    {
        return match (self::termsKind()) {
            QuoteTermsKinds::pdf => self::pdfTerms(),
            QuoteTermsKinds::text => self::textTerms(),
            QuoteTermsKinds::link => self::linkTerms(),
            null => null,
        };
    }

    /** The way chosen; before there was a choice, an uploaded PDF was the only way. */
    public static function termsKind(): ?QuoteTermsKinds
    {
        $kind = GeneralSetting::get('quote_terms_kind');

        return $kind === null
            ? (GeneralSetting::get('quote_terms_path') ? QuoteTermsKinds::pdf : null)
            : QuoteTermsKinds::tryFrom($kind);
    }

    /** A new PDF, which from now on is the way the terms come along. */
    public static function storeTerms(UploadedFile $file): void
    {
        GeneralSetting::set('quote_terms_path', Storage::disk('local')->putFile(self::TERMS_FOLDER, $file));
        GeneralSetting::set('quote_terms_name', $file->getClientOriginalName());
        GeneralSetting::set('quote_terms_kind', QuoteTermsKinds::pdf->value);
    }

    /** The PDF is gone from the settings; the file stays for the versions that named it. */
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
            'terms_kind' => self::termsKind()?->value,
            'terms_name' => (string) GeneralSetting::get('quote_terms_name', '') ?: null,
            'terms_text' => (string) GeneralSetting::get('quote_terms_text', ''),
            'terms_url' => (string) GeneralSetting::get('quote_terms_url', ''),
        ];
    }

    /** @param array<string, mixed> $values validated, each one only when it was sent */
    public static function save(array $values): void
    {
        foreach ($values as $key => $value) {
            GeneralSetting::set('quote_' . $key, match (true) {
                $key === 'layout' => json_encode(QuoteLayout::complete($value, self::layout())),
                $key === 'terms_text' => RichText::clean($value) ?? '',
                $value === null => '',
                default => $value,
            });
        }
    }

    /** @return array{kind: string, name: string, path: string}|null */
    private static function pdfTerms(): ?array
    {
        $path = GeneralSetting::get('quote_terms_path');

        return $path ? [
            'kind' => QuoteTermsKinds::pdf->value,
            'name' => (string) GeneralSetting::get('quote_terms_name', self::TERMS_NAME . '.pdf'),
            'path' => $path,
        ] : null;
    }

    /** @return array{kind: string, name: string, html: string}|null */
    private static function textTerms(): ?array
    {
        $html = GeneralSetting::get('quote_terms_text');

        return $html
            ? ['kind' => QuoteTermsKinds::text->value, 'name' => self::TERMS_NAME . '.pdf', 'html' => $html]
            : null;
    }

    /** @return array{kind: string, name: string, url: string}|null */
    private static function linkTerms(): ?array
    {
        $url = GeneralSetting::get('quote_terms_url');

        return $url ? ['kind' => QuoteTermsKinds::link->value, 'name' => $url, 'url' => $url] : null;
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
