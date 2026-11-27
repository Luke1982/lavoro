<?php

namespace App\Domain\Quotes;

/**
 * Which columns the customer sees on a quote, whether the PDF shows the photos
 * of what is offered, and whether its prices include VAT. A fixed-price quote
 * to a private customer often shows no unit prices at all, only the total. The
 * settings hold the usual choice; a quote that differs keeps its own. Whether
 * prices include VAT follows the customer instead: with VAT for a private one,
 * without for a business, so the settings do not offer it.
 */
final class QuoteLayout
{
    /** @var array<string, string> every choice, as the settings name it */
    public const CHOICES = [
        'quantity' => 'Aantal en eenheid',
        'unit_price' => 'Prijs per eenheid',
        'discount' => 'Korting',
        'vat' => 'Btw-tarief',
        'line_total' => 'Regeltotaal',
        'images' => "Foto's van producten",
        'incl_vat' => 'Prijzen inclusief btw',
    ];

    /** The choices a quote takes from whether its customer is private, not from the settings. */
    public const PER_CUSTOMER_KIND = ['incl_vat'];

    public const DEFAULT = [
        'quantity' => true,
        'unit_price' => true,
        'discount' => true,
        'vat' => false,
        'line_total' => true,
        'images' => true,
        'incl_vat' => false,
    ];

    /**
     * Every choice made, the ones missing filled in from the given defaults.
     *
     * @param  array<string, mixed>|null  $layout
     * @param  array<string, bool>  $defaults
     * @return array<string, bool>
     */
    public static function complete(?array $layout, array $defaults = self::DEFAULT): array
    {
        return array_map(
            fn (string $choice) => (bool) ($layout[$choice] ?? $defaults[$choice] ?? self::DEFAULT[$choice]),
            array_combine(array_keys(self::CHOICES), array_keys(self::CHOICES)),
        );
    }

    /** @return array<string, array<int, string>> the rules for a layout sent along under this name */
    public static function rules(string $name): array
    {
        return [
            $name => ['sometimes', 'nullable', 'array:' . implode(',', array_keys(self::CHOICES))],
            $name . '.*' => ['boolean'],
        ];
    }

    /** How the trail says it: "aantal en eenheid, regeltotaal; prijzen inclusief btw". */
    public static function describe(array $layout): string
    {
        $layout = self::complete($layout);
        $shown = array_filter(
            self::CHOICES,
            fn (string $choice) => $choice !== 'incl_vat' && $layout[$choice],
            ARRAY_FILTER_USE_KEY,
        );

        return mb_strtolower(implode(', ', $shown) ?: 'alleen het totaal')
            . '; prijzen ' . ($layout['incl_vat'] ? 'inclusief' : 'exclusief') . ' btw';
    }
}
