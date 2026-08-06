# Handleiding Lavoro

Lavoro is een systeem voor installatie- en servicebedrijven. Het houdt klanten, machines, werkbonnen, storingen, keuringen, planning, onderhoudscontracten, materialen en projecten bij, en het praat met de boekhouding (SnelStart), Google Agenda en e-mail.

Deze handleiding beschrijft hoe de applicatie werkt: wat de schermen en begrippen betekenen en hoe je iets voor elkaar krijgt. Elke paragraaf is een hoofdstuk dat op zichzelf leesbaar is.

De belangrijkste begrippen in één zin elk:

- **Werkbon** — de opdracht: wat er bij een klant gedaan moet worden, met taken, materialen, keuringen en afspraken eraan.
- **Storing** — een gemelde fout aan een machine, met status en prioriteit.
- **Machine** — een apparaat bij een klant; een exemplaar van een product uit de catalogus, met serienummer.
- **Keuring** — een inspectie van een machine, met keurpunten en een uitkomst.
- **Afspraak** — een blok in de planning, met monteurs erop, meestal gekoppeld aan een werkbon.
- **Onderhoudscontract** — een afspraak met een klant om machines periodiek te onderhouden; kan zelf werkbonnen genereren.

## Navigatie en zoeken

Op een groot scherm staat links een donker menu. Bovenin zit een **Zoeken**-knop en het belletje met meldingen. Het menu is ingedeeld in secties: Operatie (Planning, Werkbonnen, Storingen, Projecten), Relaties (Klanten, Machines, Catalogus), Organisatie (Bedrijf, Gebruikers), Lijsten en Beheer & instellingen. Je ziet alleen de onderdelen waar je rechten voor hebt. Het menu kan ingeklapt worden tot een smalle strook met alleen iconen (pijltje onder het logo); die stand wordt onthouden.

Onderin het menu staan een licentiekaart, een **Support**-kaart met het telefoonnummer (tikken belt direct), je eigen profielkaart (naar je profielpagina) en de **Uitloggen**-knop.

Op een telefoon verdwijnt het zijmenu. Bovenin staat een balk met logo, zoekknop en meldingen; onderin een zwevende balk met vijf knoppen: Menu, Agenda, een grote **plus-knop (Nieuw)**, Meldingen en Profiel. De plus-knop klapt een rijtje open om snel iets aan te maken: een werkbon, storing, afspraak of klant — met alleen de velden die echt nodig zijn; de rest vul je op de pagina van het record zelf in. De hele onderbalk kun je opzij vegen; hij klapt dan weg tot een klein lipje aan de rand en blijft zo staan tot je hem terughaalt.

**Zoeken** (via de zoekknop of de sneltoets) opent een zoekvenster dat twee dingen tegelijk doorzoekt: pagina's uit het menu en records (klanten, machines, werkbonnen, storingen, enzovoort). Resultaten staan gegroepeerd per soort, met statuslabels erbij. Je ziet alleen records waar je bij mag.

Een paar vaste gewoontes in de hele applicatie:

- **Klikken om te bewerken.** Op detailpagina's zijn de meeste waardes direct aanpasbaar: erop klikken opent een invulveld, het diskette-icoon slaat op.
- **Keuzelijsten wisselen om.** In een keuzelijst is klikken op de al geselecteerde optie hem deselecteren. Er zijn geen aparte kruisjes om iets leeg te maken.
- **Filters blijven staan.** Zoektermen en filters op lijstpagina's worden onthouden (ook na verversen) en staan in de adresbalk, dus een gefilterde lijst is deelbaar als link.
- **Bulk-acties.** In veel lijsten kun je rijen aanvinken; onderin verschijnt dan een balk om ze in één keer te bewerken (bijvoorbeeld werkbonnen naar een andere fase zetten of storingen van status wisselen).
- **Fases als stappenbalk.** Op een werkbon staat de fase als een rij genummerde rondjes; klikken op een stap verzet de werkbon naar die fase.

## Dashboard

De startpagina vat samen wat er in een gekozen periode gebeurt. Rechtsboven staat de **periodekiezer** (deze week, vorige week, deze maand, laatste 30 dagen); de knop toont het bereik waar je naar kijkt. Standaard staat hij op de laatste 30 dagen. Daarnaast staat de blauwe knop **Nieuw**: die opent dezelfde vier soorten records als de plusknop op de telefoon (werkbon, storing, afspraak, klant), in dezelfde la. Je ziet alleen de soorten die je mag aanmaken; mag je er geen enkele, dan staat de knop er niet. De teltegels en de kaart volgen die keuze; de ring met openstaande werkbonnen en de blokken onderin doen dat bewust niet.

Bovenaan staan vijf teltegels met een staafje per dag: **nieuwe werkbonnen**, **geplande uren** (de duur van alle afspraken samen), **storingen open**, **werkbonnen afgerond** en **storingen binnen een week opgelost**. Onder elk getal staat het verschil met de vorige, even lange periode — groen als dat goede en rood als dat slechte ontwikkeling is, grijs bij gelijk gebleven. Staat er "Geen vergelijking", dan viel er in de vorige periode niets te vergelijken. Boven de staafjes staat de hoogste dag van de periode als schaal, eronder de nullijn en de begin- en einddatum; wijs een staafje aan voor de dag en de waarde erbij.

"Storingen binnen een week opgelost" kijkt naar de storingen die in de periode zijn **gesloten**, en telt welk deel daarvan binnen zeven dagen na aanmelden opgelost was. Bewust de gesloten storingen en niet de aangemelde: een storing van gisteren kan nog geen week oud zijn, en die hoort het percentage niet omlaag te trekken. Is er in de periode niets gesloten, dan valt er niets te rekenen en blijft de tegel leeg.

Daaronder staan drie blokken naast elkaar:

- **Werkbonnen overzicht** — een ring met **alle openstaande werkbonnen** verdeeld over de fases waar ze nu op staan, met aantal en percentage per fase. Dit blok loopt niet met de periodekiezer mee: het antwoordt op "waar staat het werk dat nog moet", en dat verandert niet als je naar een andere week kijkt. Een werkbon zonder fase telt als openstaand. Klik op een schijf of op een regel in de legenda om de werkbonnenlijst te openen, gefilterd op die fase.

  Onderin het blok staat **Te sluiten**: de werkbonnen waarvan alle afspraken geweest zijn maar die nog niet op een gesloten of gefactureerde fase staan. Die liggen over alle fases verspreid, dus in de ring zelf zie je ze nooit als groep. De regel brengt je naar dezelfde lijst als de knop "Alleen te sluiten" op de werkbonnenpagina. Fases houden hun eigen kleur; voorbij zes fases vallen de rest en de werkbonnen zonder fase samen onder "Overige fases".
- **Werkbonnen op locatie** — een kaart met de werkbonnen die in deze periode zijn **ingepland**, dus op grond van hun afspraken. Eén speld per adres: het cijfer is het aantal werkbonnen daar, en een groene speld met vinkje betekent dat alle afspraken op dat adres afgerond zijn. De knop met het richtkruis brengt alle spelden weer in beeld.

  Het adres komt van de afspraak zelf, anders van de werkbon en anders van de klant — dezelfde volgorde die de planner aanhoudt. Een adres kan alleen op de kaart als er coördinaten bij bekend zijn; is dat er niet, dan zegt een balkje onderaan de kaart hoeveel afspraken er niet op staan. Zie *Adressen op de kaart krijgen* hieronder.
- **Agenda (vandaag)** — de afspraken van vandaag op tijd gesorteerd, met werkbonnummer, klant en status.

Onderin staan **aankomende keuringen** (de machines met de eerstvolgende keuringsdatum, met een label hoeveel dagen dat nog is), **recente werkbonnen** en **actieve storingen**.

Elk blok is aan een eigen recht gebonden: heb je het recht niet, dan wordt het blok niet getoond én niet opgehaald. Wie geen enkel recht heeft, krijgt daar een melding over.

### Adressen op de kaart krijgen

Coördinaten worden alleen bewaard als iemand ze opzoekt, dus van veel klanten zijn ze er niet — en zonder coördinaten kan een adres niet op de kaart. Een beheerder vult ze in bulk aan met `php artisan geocode:addresses`. Dat commando zoekt achter elkaar de ontbrekende coördinaten op van locaties, van klanten met afspraken in de buurt van vandaag, en van de losse adressen die alleen als tekst op een afspraak staan.

De dienst erachter staat één vraag per seconde toe, dus het commando pauzeert tussen de adressen en stopt na `--limit` stuks (standaard 100). Draai het gerust nog eens: wat al gevonden is wordt overgeslagen. Met `--all` gaat het langs alle klanten zonder coördinaten in plaats van alleen die met afspraken.

Meestal hoeft dat commando niet. Ziet het dashboard adressen die het niet kan plaatsen, dan zet het zelf een opdracht in de wachtrij om ze op te zoeken — hoogstens één per kwartier, en het opzoeken gebeurt op de achtergrond zodat de pagina er niet op wacht. De kaart vult zichzelf dus aan; het commando is er voor wie niet wil wachten. Een adres dat niets oplevert wordt een week onthouden als "niet gevonden", zodat postbussen en adressen over de grens niet elke ronde opnieuw geprobeerd worden.

## Klanten

Een klant heeft adresgegevens (bezoek- en postadres), meerdere e-mailadressen (algemeen, factuur, offerte), telefoonnummers, KvK-nummer en optioneel IBAN en btw-nummer (die laatste zijn afgeschermd achter een apart recht voor gevoelige gegevens). Een klant kan een **facturatieklant** hebben: dan gaan facturen naar die andere klant.

Op de klantpagina staan tabbladen: Overzicht (contactgegevens), Machines, Werkbonnen & Projecten en Afspraken. Vanaf de klant maak je direct een nieuw contact, onderhoudscontract of nieuwe machine aan. Onder Werkbonnen & Projecten staan de werkbonnen als kaartjes met nummer, klant en locatie, geplande afspraken, fase, monteurs en taakvoortgang (zie het hoofdstuk Projecten voor de details van die weergave).

- **Locaties** zijn de vestigingen of adressen van een klant. Machines, werkbonnen en afspraken kunnen aan een locatie hangen.
- **Contactpersonen** horen bij een klant en kunnen bij meerdere klanten tegelijk horen.
- **Importeren uit Excel**: via de klantenlijst kun je een Excel-bestand uploaden. Je krijgt eerst een voorbeeldweergave ter controle (de kolom "Naam" is verplicht), daarna wordt de import op de achtergrond verwerkt. Er is een voorbeeldbestand om te downloaden. Voor leveranciers bestaat dezelfde import.
- Klanten kunnen ook automatisch uit **SnelStart** komen; zie het hoofdstuk over koppelingen.

## Machines

Een machine is een exemplaar van een product uit de catalogus, met een serienummer, een klant en meestal een locatie. Verder heeft hij een status (actief of niet actief), een datum ingebruikname en een **volgende keuringsdatum**.

Machines kunnen in elkaar zitten: een machine kan onderdelen hebben die zelf ook machines zijn (met eigen serienummer). Een onderdeel hoort bij zijn moedermachine en niet rechtstreeks bij een klant. Als het product zo is ingericht dat er verplichte onderdelen bij horen, worden die bij het aanmaken automatisch meegemaakt. Je kunt ook een losse machine als onderdeel aan een andere koppelen (hij geeft dan zijn eigen klant en locatie op) of een onderdeel loskoppelen (hij erft dan de klant en locatie van de moedermachine).

**Verhuizen naar een andere klant**: wijzig je op een machine, werkbon of contract de klant, dan kan de hele machineboom mee-verhuizen. Je krijgt eerst een voorbeeldscherm dat laat zien wat er gebeurt, inclusief het opnieuw kiezen van een locatie bij de nieuwe klant. Keuringen en storingen reizen met de machine mee — de historie hoort bij het apparaat. Onderhoudscontracten van de oude klant laten de machine automatisch los.

De **volgende keuringsdatum** schuift automatisch op wanneer een keuring wordt afgerond met een goedkeuring (met het aantal certificaatdagen van het product) of een tijdelijke goedkeuring (met het ingevulde aantal dagen). De lijst "Aankomende keuringen" en het dashboard gebruiken deze datum.

## Werkbonnen

De werkbon is het middelpunt van de applicatie: de opdracht bij een klant, met alles eraan vast. Een werkbon heeft een nummer (WB-….), een klant, optioneel een project en locatie, een omschrijving, een soort (installatie, service of gemengd) en een **fase**.

### Fases

De fases vormen een instelbare pijplijn (te beheren onder Werkbonnen → Fases). Elke fase heeft vlaggen die betekenen wat de fase inhoudt: *inplanbaar* (klaar om in te plannen), *ingepland*, *gesloten*, *gefactureerd*, *planning vervallen* en *onvolledig*. Op de werkbon staat de fase als stappenbalk; klikken verzet de fase, en er wordt bijgehouden wie een fase wanneer bereikte. In de werkbonlijsten bij een klant of project kan de fase ook met de rechtermuisknop worden gewijzigd; wie alleen mag afsluiten of onvolledig markeren ziet daar alleen die fases.

Belangrijke automatiek rond fases:

- Een nieuwe werkbon start in de eerste fase.
- Wordt er een afspraak aan de werkbon gekoppeld, dan schuift hij automatisch door naar de fase "ingepland" (nooit terug).
- Wordt de afspraak verwijderd, dan valt hij terug naar de fase "planning vervallen".
- Vul je een **extern factuurnummer** in, dan gaat de werkbon automatisch naar de gefactureerde fase.
- **Gefactureerd telt overal als gesloten.** Filters, lijsten en telwerk beschouwen een gefactureerde werkbon als afgesloten.

### De werkbonpagina

De pagina is opgedeeld in tabbladen: **Details**, **Administratie** (alleen met recht op financiële gegevens) en **Exporteren** (met export-/mailrechten).

Op Details staan: de stappenbalk, de gegevens (klant, project, locatie, datum opdracht, extern factuurnummer, externe referentie, uitvoeringslocatie), de taken, de keuringen (met een knop om een machine toe te voegen), de gekoppelde storingen (bestaande kiezen of direct een nieuwe aanmaken), de materialen, en in de zijbalk: een kaartje van de locatie, de tijdlijn, de afspraken, opmerkingen (openbaar én intern), documenten, twee fotoblokken (openbaar en intern) en het blok **Afronding en handtekening**.

**Afsluiten**: onderaan zit de knop om de werkbon af te sluiten, met aankomst- en vertrektijden, afsluitende opmerkingen en de handtekening van de klant (naam plus getekende handtekening). Er kan een minimumaantal foto's vereist zijn om af te sluiten (instelbaar door de beheerder). Een werkbon kan ook heropend of als onvolledig gemarkeerd worden. Het vinkje "werk volledig afgerond" zet je uit als het werk niet af is.

Op **Administratie** staan de materialen met prijzen, subtotaal, btw (21%) en totaal, financieel commentaar, en — als de SnelStart-koppeling aanstaat — de knop **Verstuur naar SnelStart**.

Op **Exporteren** staan de werkbon-PDF en de keuring-PDF's met een voorbeeldweergave, een genereerknop en een **E-mail PDF**-knop.

### PDF, e-mail en SnelStart

- De **werkbon-PDF** bevat logo, geplande datum, monteurs met werkelijke uren/reistijd/pauze, materialen (gesplitst in voorzien en onvoorzien "extra"), taken, foto's en de afsluittekst die de beheerder heeft ingesteld.
- **E-mailen kan pas als de werkbon gesloten is** en de klant een e-mailadres heeft. Er is ook een variant die de werkbon-PDF plus alle keuringsrapporten in één mail meestuurt. Na verzending staat op de werkbon dat hij naar de klant is verstuurd.
- **Verstuur naar SnelStart** maakt van de materialen op de werkbon een verkooporder in de boekhouding. Voorwaarden: de werkbon is gesloten, nog niet eerder verstuurd, de klant (of zijn facturatieklant) is aan SnelStart gekoppeld, en de materialen hebben een SnelStart-koppeling — regels zonder koppeling worden overgeslagen en dat wordt teruggemeld.

### Zoeken en filteren

De werkbonnenlijst zoekt op klant, plaats, beschrijving, fase, factuurnummer, inkoopordernummer en uitvoerder. Er is een fasefilter en een schakelaar **"Alleen te sluiten"**: werkbonnen waarvan alle afspraken voorbij zijn maar die nog niet gesloten zijn. Wie geen recht heeft om alle werkbonnen te zien, ziet alleen de werkbonnen waar hij zelf als uitvoerder op staat.

## Taken op een werkbon

Taken zeggen wat er gedaan moet worden. Onder Werkbonnen → Taken beheer je **taaksjablonen** (titel en omschrijving); op een werkbon zet je daar exemplaren van, eventueel met een aantal en een gekoppeld product.

Per taak op de werkbon kan:

- **Afvinken** (gereed melden). Een geannuleerde taak kan niet gereed; hoort er een product met serienummers bij, dan moeten eerst alle serienummervakken gevuld zijn. Er wordt vastgelegd wie de taak wanneer afrondde.
- **Ondertekenen**: een afgeronde taak kan apart door de klant ondertekend worden. Heropenen wist de handtekening.
- **Annuleren** met een reden (kan niet meer als de taak al afgerond is).
- **Apparatuur registreren**: bij een taak die apparatuur levert, vul je de serienummers in; daarmee ontstaan de machines in het systeem, inclusief onderdelen volgens de productsamenstelling. Zodra apparatuur geregistreerd is kan de taak niet meer heropend worden.
- **Materialen boeken** op de taak zelf (naast materialen op de werkbon); op de bon en PDF is te zien bij welke taak materiaal hoort.

Taken kunnen aan planrollen gebonden zijn, zodat monteurs alleen de taken van hun eigen rol zien; er is een recht om altijd alle taken te zien.

## Keuringen

Een keuring is de inspectie van één machine op een werkbon. Voeg je op de werkbon een machine toe, dan ontstaat de keuring en worden automatisch alle **keurpunten** klaargezet die bij het producttype van die machine horen. Onderdelen van de machine krijgen elk hun eigen deelkeuring (een gecombineerde keuring met moeder- en dochterkeuringen).

Keurpunten zijn er in soorten: één keuze uit meerdere, meerdere keuzes, ja/nee, getal en tekst; per punt kunnen ook een opmerking en foto's worden vastgelegd. Keurpunten en hun groepen (de indeling op het rapport) beheer je in de catalogus onder Keurpunten.

**Afronden**: kies een uitkomst — Goedkeur, Afkeur, Goedkeur na reparatie, Tijdelijke goedkeur (met aantal dagen) — en een afrondingsdatum. Afronden kan pas als alle keurpunten zijn ingevuld; "Nog geen uitkomst" telt niet als uitkomst. Een gecombineerde keuring kan in één keer met alle deelkeuringen worden afgerond. Bij goedkeuring schuift de volgende keuringsdatum van de machine automatisch op; het opnieuw openen van de keuring draait dat weer terug.

Mist een keuring keurpunten die later aan het producttype zijn toegevoegd, dan is er een knop om de ontbrekende punten alsnog toe te voegen.

Elke keuring heeft een eigen PDF-rapport dat je kunt genereren en naar de klant kunt mailen (los, of gebundeld met de werkbon-PDF).

## Storingen

Een storing is een gemelde fout aan een machine, met een onderwerp, omschrijving, **status** (Open, In behandeling, Gesloten), **prioriteit** (Laag, Normaal, Hoog) en optioneel een storingscode.

- Sluit je een storing, dan wordt automatisch vastgelegd wie dat wanneer deed; heropenen wist dat weer.
- Een storing hoort bij één machine en kan aan één werkbon gekoppeld worden. Alleen een losse storing (nog aan geen werkbon gekoppeld) kan aan een werkbon worden gehangen.
- Wie een storing mag zien volgt uit de machine of de werkbon erachter: kun je die zien, dan zie je de storing.

De storingenlijst heeft teltegels (Open, In behandeling, Gesloten), filters op status, prioriteit, storingscode en wie hem sloot, en bulk-bewerken van de status. Er is ook een **kaartweergave** die openstaande storingen per klant/locatie op de kaart zet.

Storingen aanmaken kan vanaf de storingenlijst, de plus-knop, de machinepagina en direct vanaf een werkbon.

## Planning en afspraken

De planningspagina is een tijdbalk: monteurs als rijen, de tijd van links naar rechts, per week of per dag. Bovenin: Vandaag, vorige/volgende, de week/dag-schakelaar, een zoekveld voor afspraken, de monteurkaart (live locatie van monteurs) en een instellingenmenu (exporteren, standaardduur, slotgrootte, leidende kleur — afspraaktype of monteurrol — en de begintijd en eindtijd van de dagweergave).

- **Slepen om te plannen**: rechts staat een lijst met **ongeplande werkbonnen** (werkbonnen in een inplanbare fase). Sleep er één naar een monteur en tijdstip en er ontstaat een afspraak. Afspraken zelf zijn ook te verslepen en op te rekken. Klikken opent het bewerkscherm.
- **Open plekken**: bovenin zit een schakelaar die de vrije ruimte in de planning groen laat oplichten. Het invoerveld ernaast bepaalt hoeveel minuten een open plek minstens moet zijn; leeg gelaten geldt de standaardduur voor nieuwe afspraken. Werkbonnen in een inplanbare fase hebben bovendien een knop **Inplannen** (op de werkbonlijsten en de werkbonpagina) die je naar de planner brengt: de werkbon licht op in de lijst met ongeplande werkbonnen — of, bij een projectwerkbon, onder zijn projectbalk — en de open plekken waar hij past lichten mee op.
- **De afspraak** heeft een soort (afspraaktype, met kleur), begin- en eindtijd, een klant, een gekoppelde werkbon, een locatie, een omschrijving en één of meer monteurs — per monteur met eigen pauze, eventueel **afwijkende tijden** en een planrol. Een afspraak kan ook **voorlopig** zijn.
- Bij het aanmaken van een afspraak kan automatisch een werkbon worden meegemaakt (of juist uitdrukkelijk zonder werkbon). Koppelen van een afspraak zet de werkbon in de fase "ingepland"; de afspraak verwijderen zet hem terug naar "planning vervallen".
- **Onbeschikbaarheid** (vrije dagen en vaste vrije momenten uit het rooster) ligt als blok over de planning. Plannen over een blok heen kan alleen als de beheerder dat toestaat, en dan met een uitdrukkelijke bevestiging.
- **Plangroepen** ordenen de monteursrijen: gekleurde groepen die je in de zijbalk aanmaakt, hernoemt, sorteert en van monteurs voorziet; ook of iemand überhaupt **inplanbaar** is stel je daar in.

**Uren en handtekening (uitvoering)**: elke monteur op een afspraak registreert zijn eigen werkelijkheid — starttijd, eindtijd, reistijd en een handtekening. Op de afspraak staan daarvoor knopjes; wie het recht heeft kan ook tijden voor een ander invullen of geregistreerde tijden weer vrijgeven. Monteurs die hun tijden van gisteren nog niet hebben ingevuld krijgen daar 's ochtends automatisch een melding van.

Afspraken kunnen gekopieerd worden naar andere dagen (inclusief werkbonkoppeling en monteurs). Vanaf een afspraak kan een **afspraakbevestiging** naar de klant worden gemaild, en de beheerder kan **standaard e-mails** aan het aanmaken, wijzigen of verwijderen van afspraken hangen (zie Instellingen en beheer).

Op een telefoon toont de planner één monteur tegelijk, per week, als lijst of als baanweergave, met veeggebaren om van week te wisselen. Wijzigingen in de planning verschijnen — voor wie zijn agenda gekoppeld heeft — ook in Google Agenda; zie het hoofdstuk over koppelingen.

## Onderhoudscontracten

Een onderhoudscontract hoort bij een klant en dekt een set machines. Het heeft een looptijd (start- en einddatum), een prijs met een prijsinterval, en een **onderhoudsfrequentie**: maandelijks, halfjaarlijks, jaarlijks of een eigen aantal dagen. De frequentie geldt voor het hele contract, of — als "frequentie per machine" aanstaat — per machine apart.

De **status** volgt uit de datums: toekomstig (nog niet begonnen), actief, verlopen (einddatum voorbij) of geannuleerd. Annuleren en weer activeren zijn knoppen op het contract.

**Werkbonnen genereren**: staat automatische generatie aan, dan kijkt het systeem elk uur welke machines aan de beurt zijn (op basis van de frequentie en wanneer er voor het laatst gegenereerd is) en maakt werkbonnen aan — **één werkbon per locatie**, met alle machines van die locatie als keuringen erop. Er kan een afwijkend generatie-interval ingesteld worden. Met de knop "werkbonnen genereren" doe je hetzelfde direct, voor alle machines van het contract, ongeacht of ze al aan de beurt waren.

Verhuist een machine naar een andere klant, dan wordt hij automatisch van contracten van de oude klant losgekoppeld. De contractprijzen zijn alleen zichtbaar met het recht op financiële contractgegevens.

## Materialen en voorraad

**Producten en materialen zijn verschillende dingen.** Een product is een model in de catalogus (merk plus type) — het *soort* apparaat dat een machine is. Een materiaal is een voorraadartikel dat je op een werkbon boekt: onderdelen, verbruiksartikelen of diensten, met een prijs, kostprijs, code, voorraad en minimum-/maximumvoorraad.

- Materiaal boek je op een werkbon of op een taak van die werkbon, met een aantal. **De voorraad beweegt automatisch mee**: boeken haalt af, verwijderen of het aantal verlagen zet terug. Ook bij het verwijderen van een hele werkbon komt de voorraad terug.
- Elke regel heeft een vlag **onvoorzien**: materiaal dat niet was voorzien in de opdracht. Op de PDF staan onvoorziene materialen apart als "extra".
- **Vrije materiaalregels** zijn regels in vrije tekst voor iets dat niet in de catalogus staat, ook met aantal en onvoorzien-vlag; die raken de voorraad niet.
- Materialen hebben categorieën en gebruikseenheden (beide te beheren in de catalogus) en kunnen aan leveranciers gekoppeld zijn met inkoopprijzen.
- Materialen kunnen uit **SnelStart** komen (artikelenimport); een materiaal met SnelStart-koppeling kan mee in de verkooporder vanaf de werkbon.
- Prijzen en financiële kolommen zijn afgeschermd achter aparte rechten.

## Producten en catalogus

De catalogus beschrijft wat het bedrijf levert en onderhoudt:

- **Producten**: merk plus model, behorend bij een producttype. Een product kan een **bundel** zijn: een samenstelling van andere producten. Die samenstelling bepaalt welke onderdelen automatisch meekomen wanneer een machine of geleverde apparatuur wordt aangemaakt. Producten hebben ook een aantal "certificaatdagen": hoe lang een goedkeuring geldig is, en dus hoe ver de volgende keuringsdatum opschuift.
- **Producttypes**: het soort apparaat. Aan het producttype hangen de **keurpunten** die elke keuring van zo'n apparaat moet aflopen. Types kunnen een hiërarchie vormen.
- **Merken**, **productkenmerken** (instelbare eigenschappen met waardes per product) en **relatietypes** (hoe producten in elkaar zitten) completeren de catalogus.
- **Leveranciers** kunnen aan producten en materialen gekoppeld worden, met inkoopprijs. Leveranciers zijn ook uit Excel te importeren.
- Producten kennen bulk-bewerken voor het in één keer aanpassen van velden.

## Projecten

Een project bundelt werk voor een klant dat groter is dan één werkbon. Het heeft een projectleider, een status (Niet gestart, Gestart, Afgerond, Geannuleerd), een looptijd en een locatie. Werkbonnen kunnen aan een project hangen; heeft de werkbon zelf geen locatie, dan geldt die van het project.

Op de projectpagina staan de details, een **tijdlijn** (mijlpalen, werkbonnen met hun fases, afspraken, taken en storingen in de tijd), de werkbonnen, de klant en de **mijlpalen** — elk met een geplande en een werkelijke datum en een verantwoordelijke. Projecten verschijnen ook als balk boven in de planner.

De werkbonnen staan er als kaartjes: nummer en aanmaakdatum, klant met de locatie eronder, de geplande afspraken (de eerste twee; een +teller klapt de rest uit als je eroverheen beweegt), de fase (met bijvoorbeeld de afsluitdatum of de werkelijke starttijd eronder), de monteurs en hoeveel taken er af zijn. Het kleurbalkje links volgt de fase. Op een smal scherm klapt de rij samen tot een compact kaartje met dezelfde informatie. Dezelfde weergave wordt gebruikt op de klantpagina.

Wie het recht op projectfinanciën heeft ziet ook het tabblad Administratie met financiële notities (met vastgelegd wie ze het laatst bijwerkte).

## Documenten, foto's en opmerkingen

Aan vrijwel elk record (werkbon, storing, project, klant, machine, product…) kunnen bijlagen hangen:

- **Documenten**: bestanden uploaden (meerdere tegelijk), een titel en een **categorie** geven, en per document markeren of het intern is. Interne documenten komen nooit bij de klant. Documenten zijn te bekijken in een voorbeeldweergave en te downloaden; beide gaan langs de rechtencontrole. Categorieën beheert de beheerder; er is bulk-hercategoriseren en bulk-verwijderen.
- **Foto's**: afbeeldingen uploaden, een hoofdfoto aanwijzen, en op werkbonnen een apart blok voor interne foto's. De werkbon-PDF bundelt de (openbare) foto's van het werk.
- **Opmerkingen**: notities op een record. Werkbonnen kennen openbare én **interne opmerkingen**; interne opmerkingen zijn de plek voor diagnoses en aantekeningen die niet op de bon voor de klant horen.

Documentatie (handleidingen, datasheets) die als document met een documentatie-achtige categorie bij een **product** hangt, kan de AI-assistent meelezen bij technische vragen over een apparaat.

## Extra velden

De beheerder kan eigen velden definiëren op klanten, machines, producten, werkbonnen en storingen: tekst, nummer, datum, ja/nee, keuzelijst of tekstvak, eventueel verplicht en in een eigen volgorde. Deze extra velden verschijnen op de betreffende pagina's en zijn daar net als de gewone velden in te vullen. Beheer gebeurt onder Instellingen → Extra velden.

## Tijdlijn en meldingen

**Tijdlijn.** Vrijwel alles wat er in de applicatie gebeurt wordt vastgelegd: wie welk veld wanneer veranderde, met de waarde ervoor en erna, en ook samengestelde gebeurtenissen ("afspraak ingepland", "materiaal geboekt", "contract geannuleerd"). Op detailpagina's staat die geschiedenis als tijdlijn. Eén handeling die meerdere records raakt verschijnt op al die tijdlijnen. Gevoelige velden (prijzen, IBAN) zijn in de tijdlijn alleen zichtbaar voor wie het bijbehorende recht heeft. Handelingen die de AI-assistent namens iemand deed staan gewoon op naam van die gebruiker, maar zijn herkenbaar als door de assistent uitgevoerd.

**Meldingen.** Het belletje toont meldingen in de applicatie; wie dat toestaat krijgt ze ook als pushbericht op telefoon of computer. Twee soorten:

- **Abonnementen** (zelf aan te zetten onder Instellingen → Meldingen): nieuwe storing, nieuwe planning, planning gewijzigd, werkbon afgerond, materiaal toegevoegd, keuring ondertekend, nieuwe klant. Je krijgt alleen meldingen over dingen die je mag zien.
- **Verplichte meldingen** (niet uit te zetten, over je eigen werk): je afspraak is verplaatst, je bent van een afspraak afgehaald, en je hebt nog tijden van een afgelopen afspraak niet ingevuld (dagelijkse herinnering).

Over je eigen handelingen krijg je nooit een melding. Een nieuwere melding over hetzelfde record vervangt de oudere.

## Gebruikers, rollen en rechten

**Rechten** bepalen wat iemand mag. Ze zijn gebundeld in **rollen** (beheer onder Gebruikers → Rollen): een rol krijgt een set rechten en een set gebruikers. Eén rol is bijzonder: **admin** — een admin mag alles, ongeacht rechten, en is de enige die rollen kan toekennen en het admin-gedeelte (Bedrijf, Rollen, Instellingen) kan openen.

Rechten heten steeds "onderdeel punt actie" — bijvoorbeeld: werkbonnen lezen, alleen eigen werkbonnen lezen, werkbon sluiten, financiële gegevens zien, afspraken voor anderen aanmaken, alle afspraken zien, tijden van anderen invullen, gevoelige klantgegevens zien, de AI-assistent gebruiken. Lijsten en zoekresultaten volgen dezelfde regels: wie alleen eigen werkbonnen mag zien, ziet overal — ook in de zoekfunctie en de assistent — alleen die werkbonnen.

**Per gebruiker** beheert de beheerder: naam, e-mail, wachtwoord, avatar, rollen, en of iemand **inplanbaar** is (als rij in de planner verschijnt). Gebruikers worden nooit echt weggegooid: verwijderen deactiveert ze, en ze zijn terug te halen.

**Rooster**: per gebruiker zijn vaste vrije momenten in te stellen (een weekdag, wekelijks of om de week, hele dag of een dagdeel, met een label zoals "papadag") en losse vrije dagen (een datum of periode). Dit rooster blokkeert de planner en telt mee wanneer beschikbaarheid wordt berekend. Wie het recht heeft beheert alleen zijn eigen rooster, of dat van iedereen.

**Planrollen** (Gebruikers → Planrollen) zijn iets anders dan rechten-rollen: gekleurde functierollen (bijvoorbeeld een vakgebied) die je aan monteurs op een afspraak geeft en waarmee taken op een werkbon aan een rol gebonden kunnen worden. Ze geven geen rechten.

Iedereen kan op zijn eigen profielpagina zijn naam, wachtwoord en avatar wijzigen en daar zijn Google Agenda koppelen.

## Instellingen en beheer

Het beheergedeelte (alleen voor admins, tenzij anders vermeld):

- **Bedrijf**: bedrijfsgegevens en logo's (normaal en diapositief); het hoofdbedrijf staat op de PDF's.
- **Rollen**: rollen samenstellen uit rechten en gebruikers.
- **Instellingen → Algemeen**: het tijdvenster en de weekdagen waarop de locatie van monteurs gevolgd wordt; de standaard afsluittekst onderaan de werkbon-PDF; of plannen over onbeschikbaarheid heen mag; en het minimumaantal foto's om een werkbon te mogen sluiten.
- **Instellingen → Agenda-toegang**: een gebruiker inzage geven in de (Google-)agenda van een ander.
- **Instellingen → Extra velden** en **Instellingen → Meldingen**: zie de eigen hoofdstukken.
- **Communicatie → Standaard e-mails**: e-mailsjablonen met onderwerp en tekst (met invulvelden), gekoppeld aan momenten rond afspraken — bij aanmaken, wijzigen of verwijderen — en per moment in te stellen of de mail eerst ter bevestiging wordt voorgelegd, nog te bewerken is, of stil op de achtergrond wordt verstuurd. **Standaard bijlagen** zijn herbruikbare bestanden die met die sjablonen meegaan. Vanaf een afspraak zijn sjablonen ook met de hand te versturen, met een voorbeeldweergave en verzendhistorie.
- **Technisch beheer** (eigen recht): de status van de mailkoppeling bekijken en een testmail sturen.
- In de planner zelf: standaardduur van een afspraak en de leidende kleur (afspraaktype of monteurrol).

Vaste automatische taken op de achtergrond: elk uur werkbonnen genereren uit onderhoudscontracten, dagelijks klanten en artikelen ophalen uit SnelStart ('s nachts), elke ochtend monteurs herinneren aan ontbrekende tijden, en het dagelijks opruimen van oude assistent-gesprekken en oude locatiepings.

## Koppelingen

**SnelStart** (boekhouding). Als de koppeling is ingericht: klanten en artikelen worden dagelijks ('s nachts) opgehaald en zijn ook met de hand te importeren; vanaf een gesloten werkbon maak je met één knop een verkooporder in SnelStart (zie het hoofdstuk Werkbonnen). Is er geen SnelStart-sleutel ingesteld, dan zijn alle SnelStart-knoppen onzichtbaar.

**Google Agenda.** Iedere gebruiker koppelt op zijn profielpagina zijn eigen Google-account. Er wordt dan een aparte agenda "Lavoro" in Google aangemaakt en gevuld met de bestaande afspraken. Daarna gaat het twee kanten op: afspraken uit de planner verschijnen in Google, en wijzigingen in Google komen terug in Lavoro. Valt de koppeling stil, dan verschijnt bovenin de applicatie een balk om opnieuw te koppelen. Een beheerder kan via Agenda-toegang iemands agenda met een collega delen.

**E-mail.** Uitgaande mail (werkbonnen, keuringsrapporten, afspraakbevestigingen, standaard e-mails) loopt via de ingestelde mailkoppeling — onder andere Microsoft 365 wordt ondersteund. Verstuurde mail kan automatisch in de map Verzonden van de eigen mailbox worden bijgezet. Onder Technisch beheer is de koppeling te testen.

**Mobiel.** Lavoro werkt in de browser op de telefoon en er is een Android-app (te downloaden vanuit de applicatie). De app kan pushmeldingen tonen, serienummers scannen met de camera en — binnen het ingestelde tijdvenster — de locatie van monteurs delen voor de monteurkaart in de planner.

## De AI-assistent

Wie het recht heeft ziet op belangrijke pagina's (werkbon, storing, klant, planner) een assistent-knop (sterretjes-icoon); ook via een sneltoets te openen. Je stelt in gewoon Nederlands een vraag; de assistent zoekt het antwoord op in het systeem.

Het venster heeft drie tabbladen:

- **Nieuwe chat** — het gesprek zelf. Zolang je nog niets gevraagd hebt staan hier voorgestelde vragen die bij deze pagina horen; klik er een aan om hem meteen te stellen. Daaronder staan je laatste gesprekken. Onderin typ je je vraag, met knoppen om een **foto** of een **bestand** mee te sturen, het gesprek te **melden** en met **Nieuw** een schoon gesprek te beginnen.
- **Gesprekken** — al je eerdere gesprekken, met het aantal berichten en wanneer het laatste was. Klik er een aan om hem te hervatten: het hele gesprek komt terug en je volgende vraag gaat er gewoon op verder.
- **Veelgestelde vragen** — de voorgestelde vragen beheren. De standaardvragen staan er al; je kunt eigen vragen toevoegen, wijzigen en verwijderen, en per vraag instellen op welke soort pagina hij verschijnt (of overal). Alleen je eigen vragen zijn te wijzigen.

Wat je ervan mag verwachten:

- **Hij ziet alleen wat jij mag zien.** Elke opzoekactie gebruikt jouw rechten. Een leeg resultaat betekent "niets gevonden of niets zichtbaar".
- **Hij hoort niets te verzinnen — maar controleer hem.** Records waar hij naar verwijst maakt hij klikbaar; noemt hij toch iets dat niet uit een zoekactie kwam, dan waarschuwt het scherm daarvoor. Voor technische uitspraken over apparatuur bestaat zo'n vangnet niet: hij hoort alleen te herhalen wat in de productomschrijving of documentatie staat, maar een taalmodel kan een gat stellig opvullen. Onderin het venster staat daarom altijd: de AI-assistent kan fouten maken, controleer de gegevens altijd. Wil je dat een verschil tussen twee uitvoeringen wél betrouwbaar beantwoord wordt, zet het dan in de productomschrijving — dan leest hij het voortaan gewoon op.
- **Wijzigen gaat altijd met een bevestigingsknop.** De assistent kan voorstellen een afspraak te plannen, een storing vast te leggen, een werkbon aan te maken of een taak toe te voegen — maar er gebeurt niets tot jij op **Bevestigen** klikt. Wat er op de knop staat is precies wat er uitgevoerd wordt. Een voorstel verloopt na een kwartier.
- **Meerdere kandidaten? Dan krijg je de keuze** voorgelegd in plaats van een gok.
- **Hij weet op welke pagina je bent**, dus "deze werkbon" werkt. Bij het wisselen van pagina begint een nieuw gesprek; eerdere gesprekken staan onder **Gesprekken** en zijn te hervatten. Alleen jijzelf kan je eigen gesprekken teruglezen.
- **Foto's kan hij lezen.** Stuur er een mee met de knop **Foto**, of sleep hem het venster in (plakken mag ook). Van een typeplaatje haalt hij merk, model en serienummer; per apparaat krijg je een blokje met balkjes die aangeven hoe zeker hij van elk gegeven is, met de foto's erbij waar hij het vanaf las. Onder de 70 procent gaat hij niets aanmaken zonder dat jij het bevestigt. Hij kan ook kijken naar foto's die al bij een werkbon, machine of storing staan — inclusief de interne foto's — bijvoorbeeld om machines die als "onbekend" zijn ingevoerd alsnog aan te vullen. **Let op:** een vraag met foto's kost al gauw tientallen keren zoveel als een vraag zonder; het venster waarschuwt daarvoor zolang er foto's meegaan. Meegestuurde foto's worden tijdelijk bewaard; bij het sluiten van het gesprek wordt gevraagd of je ze in je opslag wilt bewaren (dat telt mee voor je opslaglimiet) of weggooien.
- **Een bestand kan mee.** Klik op **Bestand** of sleep het het venster in: pdf's en tekstbestanden (.txt, .csv), maximaal twee tegelijk. Handig om een datasheet of offerte te laten lezen die nog niet in het systeem staat — "wat is het opgenomen vermogen volgens dit datablad?" of "vergelijk deze twee". Een Word- of Excel-bestand kun je eerst als pdf opslaan. Het bestand blijft de rest van het gesprek beschikbaar, dus je kunt er meerdere vragen over stellen. Het wordt **niet** in je opslag bewaard: het gaat mee zolang het gesprek loopt en verdwijnt daarna vanzelf. Wil je een document blijvend bewaren, upload het dan gewoon bij de klant, het product of de werkbon — daar leest de assistent het ook.
- Bij technische vragen over een apparaat leest hij de documentatie mee die bij het product is opgeslagen, en bij vragen over de werking van Lavoro zelf raadpleegt hij deze handleiding.
- Vragen over de geschiedenis ("wie heeft dit gewijzigd?") beantwoordt hij uit de tijdlijn.
- **Ging een gesprek mis?** Klik onder het invoerveld op **Melden** en zeg erbij wat er misging of wat je verwacht had (mag ook leeg blijven). Het hele gesprek wordt dan — met wat er achter de schermen is opgezocht — als bestand bewaard, zodat de fout onderzocht kan worden. Alleen je eigen gesprekken zijn te melden.

Wat de assistent doet wordt vastgelegd: de gestelde vragen, de gebruikte opzoekacties en de kosten per vraag. Handelingen die je via de assistent bevestigt staan op de tijdlijn op jouw naam, herkenbaar als via de assistent gedaan.
