<?php

namespace App\Domain\Tickets;

/**
 * Wat één inzending van een klant heeft opgeleverd, geteld per soort.
 *
 * Bestaat omdat er precies één zin en één melding uit voortkomen: wie tien foto's
 * stuurt heeft één ding gedaan, en dan moet er ook één ding geteld worden.
 */
final class CustomerUploadResult
{
    /** @param  array<int, array<string, string>>  $entries */
    public function __construct(
        public int $photos = 0,
        public int $videos = 0,
        public int $documents = 0,
        public bool $has_note = false,
        public array $entries = [],

        /**
         * Het eerste opgestuurde beeld, voor het voorbeeldplaatje in de tijdlijn.
         * Pad én id: het pad toont het vandaag, het id blijft werken zodra
         * afbeeldingen per huurder achter een eigen route staan.
         */
        public ?string $first_photo_path = null,
        public ?int $first_photo_id = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->photos === 0
            && $this->videos === 0
            && $this->documents === 0
            && !$this->has_note;
    }
}
