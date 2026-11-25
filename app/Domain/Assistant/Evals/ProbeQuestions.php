<?php

namespace App\Domain\Assistant\Evals;

/**
 * Questions people at an installation company would plausibly ask, grouped by
 * who asks them — not by what the assistant can do. Asked once each, they show
 * where the assistant has no answer, which is where it could grow next.
 */
final class ProbeQuestions
{
    /** Who asks each group's questions, as one of EvalUsers::ROLES. */
    private const ROLES = ['planner' => 'planner', 'monteur' => 'monteur', 'kantoor' => 'admin', 'beheer' => 'admin'];

    public static function roleOf(string $group): string
    {
        return self::ROLES[$group];
    }

    /** @return array<string, array<int, string>> */
    public static function all(): array
    {
        return [
            'planner' => [
                'Welke werkbonnen staan er nog ongepland?',
                'Wie heeft er volgende week nog ruimte voor een installatie van een dag?',
                'Verzet alle afspraken van Jeroen Mulder van morgen naar overmorgen, hij is ziek.',
                'Welke monteurs zijn er vrijdag vrij?',
                'Hoeveel uur staat Niels Peters deze week ingepland?',
                'Plan de onderhoudsbeurten van deze maand in voor Team Airco.',
                'Welke onderhoudscontracten moeten deze maand nog een beurt krijgen?',
                'Kopieer de afspraak van Bas Visser van vandaag naar volgende week dinsdag.',
                'Stuur de klant van werkbon 300 een afspraakbevestiging.',
                'Maak een route voor Daan Willems voor morgen.',
                'Welke afspraken overlappen er deze week?',
                'Zet de afspraak van morgen bij Hotel Rivierzicht op voorlopig.',
                'Welke werkbonnen zijn al ingepland maar nog niet uitgevoerd?',
                'Wie heeft het meeste ervaring met Daikin warmtepompen?',
                'Verwijder de afspraak van Tom de Groot van donderdag.',
            ],
            'monteur' => [
                'Wat moet ik vandaag doen?',
                'Wat is het adres van mijn volgende klus?',
                'Welke machines staan er bij de klant van mijn eerste afspraak van morgen?',
                'Wanneer is de airco bij Bakkerij Van Ommeren voor het laatst onderhouden?',
                'Wat was er de vorige keer aan de hand bij de storing van Hotel Rivierzicht?',
                'Wat is de foutcode E7 op een Daikin binnendeel?',
                'Hoeveel koudemiddel zit er in de Mitsubishi MSZ-LN25VG?',
                'Zet een taak op mijn werkbon: filter vervangen.',
                'Ik heb 2 meter koelleiding en 3 bochten gebruikt op werkbon 290, boek dat erop.',
                'Registreer mijn uren van vandaag: 8 tot 16:30 met een half uur pauze.',
                'De klant wil een extra binnendeel, kun je daar een storing of notitie van maken?',
                'Welke checks moet ik doen bij een periodieke controle van een warmtepomp?',
                'Is er nog voorraad van de Daikin Perfera FTXM25R in de bus?',
                'Maak een storing aan bij Restaurant De Gouden Karper: airco lekt water.',
                'Wat is het serienummer van de buitenunit bij Fysiotherapie Waalkade?',
            ],
            'kantoor' => [
                'Welke werkbonnen kunnen gefactureerd worden?',
                'Zet werkbon 280 op gefactureerd.',
                'Maak een offerte voor Hotel Rivierzicht voor twee extra binnendelen.',
                'Welke offertes staan nog open en zijn ouder dan twee weken?',
                'Stuur werkbon 270 als pdf naar de klant.',
                'Welke klanten hebben geen onderhoudscontract maar wel een airco?',
                'Wat is de omzet van onderhoudscontracten per jaar?',
                'Welke contracten lopen dit jaar af?',
                'Verleng het onderhoudscontract van Hotel Rivierzicht met een jaar.',
                'Wat kost het contract van Tandartspraktijk De Linde per jaar en waarom?',
                'Verhoog alle contractprijzen met 4 procent indexatie.',
                'Welke keuringen verlopen de komende maand?',
                'Maak een nieuwe klant aan: Garage Jansen, Stationsweg 4, 4101 AB Culemborg.',
                'Voeg een contactpersoon toe aan Autobedrijf Van Leeuwen: Piet Jansen, 06-12345678.',
                'Welke materialen moeten we bijbestellen?',
                'Wat hebben we vorige maand aan materialen verbruikt?',
                'Welke werkbonnen staan er al langer dan een maand open?',
                'Exporteer de uren van vorige week.',
                'Welke storingen zijn er deze week binnengekomen?',
                'Hoeveel storingen had Hotel Rivierzicht dit jaar?',
            ],
            'beheer' => [
                'Hoe stel ik in dat monteurs alleen hun eigen afspraken zien?',
                'Geef Daan Willems het recht om offertes te maken.',
                'Maak een nieuwe plangroep Team Zonnepanelen met Tom de Groot erin.',
                'Wat kost de assistent ons deze maand?',
                'Maak een macro die bij een airco-installatie de standaardtaken zet.',
                'Wie heeft gisteren de prijs van het contract van Hotel Rivierzicht gewijzigd?',
                'Koppel onze Exact Online.',
                'Hoeveel gebruikers hebben we en hoeveel mogen er nog bij?',
                'Welke werkbonnen zijn er deze maand afgesloten, per monteur?',
                'Wat is de gemiddelde doorlooptijd van een storing?',
            ],
        ];
    }
}
