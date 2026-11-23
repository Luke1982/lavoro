<?php

namespace App\Actions\Customers;

use App\Models\Contact;
use App\Models\Customer;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns the free-text contact field a bookkeeping system or a spreadsheet hands
 * over into contact persons of the customer, so a werkbon can pick them and a
 * standard e-mail can greet them.
 *
 * The text is whatever someone typed there: "T.a.v. dhr. J. de Vries",
 * "Jan de Vries (06-12345678), inkoop", "Jan en Petra Jansen, jan@bakker.nl;
 * Piet Hein". Each entry of a list is read on its own: t.a.v. and a salutation
 * go, so does whatever follows the names, and the addresses and numbers written
 * beside them are handed to the people they belong to.
 *
 * @phpstan-type Person array{first_name: string, last_name: string, email: ?string, phone: ?string, mobile: ?string}
 */
class AddContactsFromText
{
    /** "Jan de Vries; Piet Hein". A slash before a number joins two numbers of one person. */
    private const ENTRY_SEPARATOR = '/\s*(?:;|\n|\/(?!\s*[+\d(]))\s*/u';

    /** "Jan en Petra Jansen". */
    private const CONJUNCTION = '/\s*(?:&|\+|\ben\b|\band\b)\s*/iu';

    private const LEAD = '/^(?:t\.?\s*a\.?\s*v\.?|ter\s+attentie\s+van|attn\.?|attention)\s*:?\s*/iu';

    private const SALUTATION = '/^(?:de\s+heer|mevrouw|dhr|mevr|mw|mr|mrs|ms)\.?\s+/iu';

    private const EMAIL = '/[^\s<>()\[\],;:]+@[^\s<>()\[\],;:]+\.\p{L}{2,}/u';

    private const PHONE = '/\+?\d[\d\s\-().]{7,}\d/';

    /** Everything that is not a name: "(inkoop)", "tel.", and what follows ", " or " - ". */
    private const NOT_A_NAME = [
        '/[(\[<][^)\]>]*[)\]>]/u',
        '/\b(?:tel|telefoon|mob|mobiel|gsm|e-?mail|mail)\b\.?:?/iu',
        '/(?:,|\s[-–—|]\s).*$/u',
    ];

    /** Punctuation left at either end of a name once the rest is gone. */
    private const LOOSE_ENDS = '/^[\s\-–—,:|*]+|[\s\-–—,:|*]+$/u';

    /** "Verbeek & Zn": the part after the ampersand belongs to the company name. */
    private const COMPANY_SUFFIX = '/^(?:zn|zonen|co|partners|dochters)\.?$/iu';

    /** "Van den Bosch" is a surname, not a first name "Van". */
    private const SURNAME_PREFIXES = [
        'van', 'de', 'den', 'der', 'het', 'ter', 'ten', 'te', "'t", 'op', 'in', 'la', 'le', 'du', 'di',
    ];

    /**
     * Called right after the customer is saved. Only a new customer or a changed
     * field makes anyone, so a contact removed by hand is not brought back by the
     * next pull of the same value.
     *
     * Someone the customer already has under that name, or with that e-mail
     * address, is not made twice; what they were missing is filled in instead.
     */
    public function execute(Customer $customer): void
    {
        if (!$customer->wasRecentlyCreated && !$customer->wasChanged('contactname')) {
            return;
        }

        $contacts = $customer->contacts()->get();

        foreach (self::people($customer->contactname) as $person) {
            $existing = $contacts->first(fn (Contact $contact) => self::samePerson($contact, $person));

            $existing
                ? self::fillIn($existing, $person)
                : $contacts->push(Contact::createFor($customer, $person));
        }
    }

    /** @return array<int, Person> */
    public static function people(?string $text): array
    {
        return collect(preg_split(self::ENTRY_SEPARATOR, trim((string) $text)))
            ->flatMap(fn (string $entry) => self::entry($entry))
            ->values()
            ->all();
    }

    /** @return Collection<int, Person> */
    private static function entry(string $entry): Collection
    {
        $entry = preg_replace(self::LEAD, '', $entry);

        preg_match_all(self::EMAIL, $entry, $emails);
        $entry = preg_replace(self::EMAIL, ' ', $entry);

        preg_match_all(self::PHONE, $entry, $numbers);
        $numbers = array_filter($numbers[0], fn (string $number) => strlen(preg_replace('/\D+/', '', $number)) >= 10);
        $entry = str_replace($numbers, ' ', $entry);

        $names = Str::squish(preg_replace(self::NOT_A_NAME, ' ', $entry));

        $people = collect(preg_split(self::CONJUNCTION, $names))
            ->map(fn (string $name) => self::person($name))
            ->filter()
            ->values();

        return self::handOut(self::shareSurname($people), $emails[0], array_map('trim', $numbers));
    }

    /**
     * A salutation goes when a name follows it, and stays when only a surname
     * does: "Mevrouw Bakker" is greeted as that, not as "Bakker".
     *
     * @return Person|null
     */
    private static function person(string $name): ?array
    {
        $name = preg_replace(self::LOOSE_ENDS, '', $name);
        $without_salutation = preg_replace(self::SALUTATION, '', $name);

        if (str_contains($without_salutation, ' ')) {
            $name = $without_salutation;
        }

        if (!preg_match('/\p{L}/u', $name) || preg_match(self::COMPANY_SUFFIX, $name)) {
            return null;
        }

        [$first_name, $last_name] = in_array(mb_strtolower(Str::before($name, ' ')), self::SURNAME_PREFIXES, true)
            ? ['', $name]
            : array_pad(explode(' ', $name, 2), 2, '');

        return [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => null,
            'phone' => null,
            'mobile' => null,
        ];
    }

    /** "Jan en Petra Jansen": a first name on its own takes the surname the group ends with. */
    private static function shareSurname(Collection $people): Collection
    {
        $surname = $people->last()['last_name'] ?? '';

        return $people->map(
            fn (array $person) => $person['last_name'] === '' ? [...$person, 'last_name' => $surname] : $person
        );
    }

    /**
     * An address goes to the person it names (jan@… to Jan), otherwise to the
     * first without one. Numbers go out in the order they were written, a 06
     * number as mobile.
     *
     * @param  array<int, string>  $emails
     * @param  array<int, string>  $numbers
     */
    private static function handOut(Collection $people, array $emails, array $numbers): Collection
    {
        foreach ($emails as $email) {
            $email = mb_strtolower($email);
            $people = self::give($people, 'email', $email, fn (array $person) => self::addressNames($email, $person));
        }

        foreach ($numbers as $number) {
            $is_mobile = preg_match('/^(?:0031|31|0)6/', preg_replace('/\D+/', '', $number));
            $people = self::give($people, $is_mobile ? 'mobile' : 'phone', $number);
        }

        return $people;
    }

    /** Gives the value to the first person whose field is still empty, the one $prefer picks if there is one. */
    private static function give(Collection $people, string $field, string $value, ?Closure $prefer = null): Collection
    {
        $free = $people->filter(fn (array $person) => $person[$field] === null);
        $index = $free->filter($prefer ?? fn () => false)->keys()->first() ?? $free->keys()->first();

        return $index === null ? $people : $people->put($index, [...$people[$index], $field => $value]);
    }

    /** @param  Person  $person */
    private static function addressNames(string $email, array $person): bool
    {
        $first_name = mb_strtolower(rtrim($person['first_name'], '.'));

        return mb_strlen($first_name) >= 3 && str_contains(Str::before($email, '@'), $first_name);
    }

    /** @param  Person  $person */
    private static function samePerson(Contact $contact, array $person): bool
    {
        $name = fn (string $full_name) => mb_strtolower(Str::squish($full_name));

        return $name($contact->full_name) === $name($person['first_name'] . ' ' . $person['last_name'])
            || ($person['email'] !== null && mb_strtolower((string) $contact->email) === $person['email']);
    }

    /** @param  Person  $person */
    private static function fillIn(Contact $contact, array $person): void
    {
        $missing = collect($person)
            ->only(['email', 'phone', 'mobile'])
            ->filter(fn (?string $value, string $field) => $value !== null && blank($contact->{$field}));

        if ($missing->isNotEmpty()) {
            $contact->update($missing->all());
        }
    }
}
