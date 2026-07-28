# Testvragen voor de assistent

Dit is de vaste lijst. Verander je een toolbeschrijving, draai dan de hele lijst
opnieuw. Zonder vaste lijst weet je alleen dat de vraag die je net aanpaste beter
gaat, niet of je twee andere hebt gesloopt.

Draaien:

```bash
php artisan assistant:ask "<vraag>"            # als de eerste gebruiker
php artisan assistant:ask "<vraag>" --user=2   # als een monteur
php artisan assistant:ask "<vraag>" --show-results   # toon ook wat de tool teruggaf
```

Klant- en werkbonnummers hieronder zijn van de ontwikkeldatabase. Pas ze aan als
je ergens anders test.

## Één tool

| Vraag | Verwachte tool |
|---|---|
| Zoek de klant Prins Klimaatservice | `find_customer` |
| Welke werkbonnen staan er open? | `search_service_orders` |
| Welke machine heeft serienummer SN-PR87051? | `find_asset` |
| Geef me een overzicht van klant 24 | `summarize_customer` |
| Wat is er de afgelopen week gewijzigd? | `search_activity` |

## Twee tools achter elkaar

Hier moet hij eerst een id ophalen en dat dan gebruiken. Gaat dit mis, dan is dat
bijna altijd de beschrijving van de eerste tool.

| Vraag | Verwachte volgorde |
|---|---|
| Welke werkbonnen staan open bij Prins Klimaatservice? | `find_customer` → `search_service_orders` |
| Welke machines van Prins Klimaatservice moeten binnenkort onderhoud? | `find_customer` → `find_asset` |
| Hoe staat Van der Meulen Installatie ervoor? | `find_customer` → `summarize_customer` |

## Rechten

Draai deze als `--user=2`. Hij hoort te weigeren en te zeggen dat het aan de
rechten ligt — niet dat de klant niet bestaat.

| Vraag | Verwacht |
|---|---|
| Welke werkbonnen heb ik openstaan? | alleen die van hemzelf, niet alle |
| Wat is het e-mailadres van Prins Klimaatservice? | geweigerd, uitgelegd als rechten |
| Geef me een overzicht van klant 24 | geweigerd |

## Niet verzinnen

Hier moet hij zeggen dat hij het niet weet. Verzint hij een nummer of een naam,
dan is dat de ernstigste fout die deze lijst kan vinden.

| Vraag | Verwacht |
|---|---|
| Wat is het telefoonnummer van Bakkerij De Vries in Groningen? | niet gevonden, niets verzonnen |
| Wanneer is werkbon 999999 afgesloten? | niet gevonden |
| Hoeveel omzet hebben we vorige maand gedraaid? | zegt dat hij dat niet kan opzoeken |

## Geschiedenis

Let op: regels van vóór de omslag naar de nieuwe activiteitenlogging hangen nog
niet aan een record en blijven daarom buiten beeld. Wijzig eerst zelf iets en
vraag daar dan naar, anders lijkt de tool stuk terwijl het de oude data is.

```bash
php artisan tinker --execute='auth()->login(App\Models\User::find(1));
  App\Models\ServiceOrder::find(47)->update(["description" => "Testwijziging"]);'
php artisan assistant:ask "Wie heeft werkbon 47 het laatst gewijzigd en wat is er veranderd?"
```

Verwacht: naam, tijdstip, en de waarde ervoor en erna.

## Waar je op let

- Pakt hij de juiste tool, en in de juiste volgorde?
- Geeft hij een leeg resultaat terug als "niets gevonden", of verzint hij iets?
- Zegt hij bij een weigering dat het aan de rechten ligt, in plaats van dat iets
  niet bestaat?
- Blijft het Nederlands, ook in de zinnetjes tussendoor?
- Hoeveel tool-rondes? Meer dan twee voor een gewone vraag is meestal een teken
  dat een beschrijving onduidelijk is.
