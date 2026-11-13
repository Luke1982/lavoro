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

Op een telefoon verdwijnt het zijmenu. Bovenin staat een balk met logo, zoekknop en meldingen; scrol je naar beneden, dan schuift die weg, en een veeg omhoog haalt hem terug; onderin een zwevende balk met vijf knoppen: Menu, Agenda, een grote **plus-knop (Nieuw)**, Meldingen en Profiel. De plus-knop klapt een rijtje open om snel iets aan te maken: een werkbon, storing, afspraak of klant — met alleen de velden die echt nodig zijn; de rest vul je op de pagina van het record zelf in. De hele onderbalk kun je opzij vegen; hij klapt dan weg tot een klein lipje aan de rand en blijft zo staan tot je hem terughaalt.

**Zoeken** (via de zoekknop of de sneltoets) opent een zoekvenster dat twee dingen tegelijk doorzoekt: pagina's uit het menu en records (klanten, machines, werkbonnen, storingen, enzovoort). Resultaten staan gegroepeerd per soort, met statuslabels erbij. Je ziet alleen records waar je bij mag.

Een paar vaste gewoontes in de hele applicatie:

- **Klikken om te bewerken.** Op detailpagina's zijn de meeste waardes direct aanpasbaar: erop klikken opent een invulveld, het diskette-icoon slaat op.
- **Keuzelijsten wisselen om.** In een keuzelijst is klikken op de al geselecteerde optie hem deselecteren. Er zijn geen aparte kruisjes om iets leeg te maken.
- **Filters blijven staan.** Zoektermen en filters op lijstpagina's worden onthouden (ook na verversen) en staan in de adresbalk, dus een gefilterde lijst is deelbaar als link.
- **Bulk-acties.** In veel lijsten kun je rijen aanvinken; onderin verschijnt dan een balk om ze in één keer te bewerken (bijvoorbeeld werkbonnen naar een andere fase zetten of storingen van status wisselen).
- **Fases als stappenbalk.** Op een werkbon staat de fase als een rij genummerde rondjes; klikken op een stap verzet de werkbon naar die fase.
- **Als opslaan niet lukt.** Lukt bewaren niet, dan verschijnt rechtsboven een rode melding met de reden en blijft staan wat je hebt ingevuld. Namen en codes die maar één keer mogen voorkomen (een merk, een categorie, een serienummer bij een product) leveren "is al in gebruik" op bij het veld zelf. Iets verwijderen dat nog ergens aan hangt, wordt geweigerd met de melding dat het nog in gebruik is: maak eerst die koppeling los.

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

### Spelden en groepen op de kaart

Beide kaarten openen in een eigen venster; de knop **Sluiten** rechtsboven sluit het, ook als je systeem dat venster zonder titelbalk laat zien. Op de kaart van de aankomende keuringen en die van de openstaande storingen staat één speld per adres, met het aantal machines of storingen daar erin; de legenda rechtsonder zegt wat de kleuren betekenen. Op de keuringskaart kiest een beheerder die kleuren zelf, onder **Instellingen → Algemeen** (zie *Instellingen en beheer*); zolang daar niets is opgeslagen is het rood voor verlopen, oranje voor binnen 30 dagen, okergeel voor binnen 60 dagen en groen voor later. Ver uitgezoomd worden spelden die over elkaar zouden vallen samen één rondje. Het cijfer daarin is de som van de cijfers op die spelden, de ring toont hun kleuren (de dringendste bovenaan), en een paars schildje betekent dat er minstens één speld met een schildje bij zit. Klik op het rondje om in te zoomen. Van dichtbij, op straatniveau, staat elke speld weer los, en een adres dat in je route zit staat altijd los.

### Een route plannen

Op de kaart van de aankomende keuringen en op de kaart van de openstaande storingen staat linksboven de knop **Route plannen**. Zet hem aan en klik de adressen aan die je wilt bezoeken; hun speld wordt blauw en ze komen in de lijst. Het kruisje achter een adres in de lijst haalt het er weer uit, net als nog eens klikken op de speld. Een route gaat langs hoogstens 48 adressen.

De planner maakt er een **werkdag** van. Bovenaan staan het begin en het einde van de dag: de begin- en eindtijd die je in de planner voor de dagweergave hebt gekozen (in deze browser), anders 07:00 tot 18:00. Per route zijn ze aan te passen. Elk adres heeft een **tijd op locatie**, standaard de standaardduur uit de planner, een veld **vóór** en een knop **Spoed**. Vul je bij vóór een tijd in, dan begint het werk op dat adres uiterlijk om die tijd; leeg betekent dat het niet uitmaakt wanneer. De tijd moet na het begin van de werkdag liggen. Standaard zijn alle adressen even belangrijk. **Route berekenen** kiest dan, in deze volgorde:

1. alle spoedadressen die in de dag passen;
2. daarna zoveel mogelijk gewone adressen;
3. en voor die adressen de snelste volgorde.

Een spoedadres gaat dus altijd voor, ook voor een handvol gewone adressen samen. Liggen er een spoedklus en een kleine klus vlak bij elkaar, dan komen ze allebei mee zolang de dag het toelaat. Wat niet past, staat grijs onder **Past niet in de werkdag**, en bij elk adres staat waarom. Bij een gewoon adres is dat meestal dat de dag vol is. Bij een spoedadres of een adres met een vóór-tijd zoekt de planner de echte reden uit:

- **Niet vóór 10:00 te halen: op zijn vroegst om 10:25 daar.** Alleen de rit erheen duurt al te lang; spoed verandert daar niets aan.
- **Past niet in de werkdag, ook niet als enige adres.** Heen, het werk en terug duren samen langer dan de dag.
- **Vóór 10:00 lukt niet samen met de andere spoedadressen.** Alleen zou het lukken, maar niet naast de andere spoedadressen met hun tijden.

Past een spoedadres niet, of past er helemaal niets, dan staat dat ook in rood boven de lijst. Maak de dag langer, de tijd op locatie korter of een vóór-tijd later, of haal een ander spoedadres weg, en reken opnieuw. Dat uitzoeken telt als één berekening extra per drie van zulke adressen.

In de route krijgt elk adres een grotere, donker omlijnde speld met zijn volgnummer en een aankomsttijd, de weg staat op de kaart, en onderaan zie je hoe laat je terug bent, hoeveel kilometer het is en hoe lang je rijdt. Files tellen niet mee. Ligt een adres te ver van een weg, dan noemt de planner het bij naam; haal het dan uit de route.

Onder **Begin en einde** kies je waar de route begint en eindigt:

- **Bedrijf**: het bedrijfsadres (onder Bedrijf). Is dat niet ingevuld, dan staat deze keuze grijs.
- **Thuis: een naam**: het thuisadres van een inplanbare medewerker, uit diens profiel (zie *Gebruikers, rollen en rechten*). Wie geen thuisadres heeft, staat grijs in de lijst. Het adres zelf zie je niet; na het berekenen staat er een huisje op de kaart.
- **Ander adres**: typ straat, huisnummer en plaats en kies het adres uit de lijst, of klik op de kaart. Versleep het huisje om het te verplaatsen. Het zoeken kent alleen Nederlandse adressen; voor een adres over de grens klik je op de kaart.

Is een adres niet op de kaart te vinden, dan zegt de planner dat en kies je een ander startpunt.

**Andersom**, naast de tijd van terugkomst, rijdt dezelfde route in omgekeerde volgorde: hetzelfde begin en einde, dezelfde adressen, de laatste eerst. De aankomsttijden worden opnieuw berekend; de eerste keer telt dat als één berekening. Haalt de route zo een vóór-tijd niet, of kom je na het einde van de werkdag terug, dan staat dat in rood boven de lijst, en bij zo'n adres staat de aankomsttijd in rood met **Niet vóór 10:00 te halen**. Er valt geen adres af: kijk zelf of je zo wilt rijden. Nog eens op **Andersom** klikken geeft de eerste volgorde terug. **In planning zetten** neemt de volgorde die je ziet.

**Openen in Google Maps** zet de route klaar voor de navigatie op je computer of, met de Google Maps-app, op je telefoon. Google Maps neemt hoogstens tien adressen per keer, dus een langere route staat in delen klaar: open het volgende deel als je het vorige gereden hebt. Zet je **Route plannen** uit, dan is de route weg, tenzij je hem in de planning hebt gezet.

**In planning zetten** geeft de route aan een monteur voor een dag: kies onder de berekende route de monteur en de dag. In de planner staat dan bovenaan die dag een knop **Route** die de route in Google Maps opent (bij een lange route **Route 1**, **Route 2**, enzovoort); wijs hem aan voor het aantal adressen en hoe laat hij terug is. Op de telefoon staat de knop in de balk van die dag. Heeft de monteur zijn agenda aan Google gekoppeld, dan staat de route ook als item voor de hele dag in zijn Google Agenda, met de adressen, de aankomsttijden en de links; dat item houdt geen tijd bezet. Elke monteur heeft hoogstens één route per dag: een nieuwe route voor dezelfde dag vervangt de vorige. Met het prullenbakje naast de knop haal je de route uit de planning, ook uit Google Agenda. Alleen de monteur zelf en wie de hele planning ziet, zien de route. Een route in de planning zetten mag wie routes mag plannen én afspraken voor die monteur mag maken; de knop zet geen afspraken in de planning, alleen de route.

Je kunt routes plannen als je een van de twee kaarten mag zien. De berekening loopt via een routeserver: de gratis dienst van OpenRouteService of een eigen server. Daar gaan alleen coördinaten en tijden heen, geen namen of adressen. Bij OpenRouteService is het aantal routes per dag beperkt; is dat op, dan zegt de planner het en kan het de volgende dag weer. Iedereen kan hoogstens tien routes per minuut berekenen.

## Klanten

Een klant heeft adresgegevens (bezoek- en postadres), meerdere e-mailadressen (algemeen, factuur, offerte), telefoonnummers, KvK-nummer en optioneel IBAN en btw-nummer (die laatste zijn afgeschermd achter een apart recht voor gevoelige gegevens). Een klant kan een **facturatieklant** hebben: dan gaan facturen naar die andere klant.

Op de klantpagina staan tabbladen: Overzicht (contactgegevens), Machines, Werkbonnen & Projecten en Afspraken. Vanaf de klant maak je direct een nieuw contact, onderhoudscontract of nieuwe machine aan. Onder Machines staat elke machine als kaartje; is de machine een bundel, dan staan de onderdelen die eronder hangen op het kaartje zelf vermeld, met hun serienummer en een link naar de onderdeelmachine. Onder Werkbonnen & Projecten staan de werkbonnen als kaartjes met nummer, klant en locatie, geplande afspraken, fase, monteurs en taakvoortgang (zie het hoofdstuk Projecten voor de details van die weergave).

- **Locaties** zijn de vestigingen of adressen van een klant. Machines, werkbonnen en afspraken kunnen aan een locatie hangen. Waar je een locatie kiest of zoekt, kijkt het zoekveld naar de naam, de locatiecode en het hele adres — straat, postcode, plaats en land. Interpunctie en extra spaties doen daarbij niet mee: typ je "Rustenburg 24 - Wageningen", dan vind je ook de locatie die in het systeem "Rustenburg 24  - Wageningen" heet, met twee spaties. Levert je zoekterm niets op, dan zegt de lijst dat — hij klapt niet zonder uitleg dicht. Op de klantpagina staan de locaties in het blok **Locaties**, standaard tien per pagina. Heeft een klant er meer dan tien, dan staat erboven zo'n zoekveld en kies je eronder hoeveel locaties er op een pagina staan en blader je verder; die keuze wordt onthouden. Het speldje voor een locatie opent hem op de kaart.
- **Contactpersonen** horen bij een klant en kunnen bij meerdere klanten tegelijk horen.
- **Importeren uit Excel**: via de klantenlijst kun je een Excel-bestand uploaden. Je krijgt eerst een voorbeeldweergave ter controle (de kolom "Naam" is verplicht), daarna wordt de import op de achtergrond verwerkt. Er is een voorbeeldbestand om te downloaden. Voor leveranciers bestaat dezelfde import.
- **Uit SnelStart**: is SnelStart gekoppeld, dan staat in hetzelfde menu **Klanten ophalen uit SnelStart**. Die knop haalt alle klanten uit SnelStart op. Nieuwe klanten komen er altijd bij; eerst vraagt Lavoro of klanten die al in Lavoro staan ook bijgewerkt moeten worden met wat SnelStart heeft. Kies je **Ook bestaande bijwerken**, dan wordt overschreven wat in Lavoro anders was ingevuld; kies je **Alleen nieuwe klanten**, dan blijven bestaande klanten zoals ze zijn. Daarna staat er hoeveel klanten nieuw zijn en hoeveel zijn bijgewerkt. Lukt het niet, dan zegt de melding waarom en is er niets veranderd. Daarnaast gebeurt dit elke nacht vanzelf; zie het hoofdstuk over koppelingen. Een klantenexport uit SnelStart kun je ook als Excel-bestand uploaden: Lavoro herkent hem en vraagt of hij omgezet moet worden.

## Machines

Een machine is een exemplaar van een product uit de catalogus, met een serienummer, een klant en meestal een locatie. Verder heeft hij een status (actief of niet actief), een datum ingebruikname en een **volgende keuringsdatum**. Naast het serienummer staan twee scanknoppen: de eerste maakt een foto van het typeplaatje, de tweede gebruikt een foto die je al hebt. Lavoro leest de tekst van die foto en biedt de nummers die het erin herkent aan om op te tikken — zelf typen mag natuurlijk ook.

**Staat het product er nog niet in?** Typ het in het productveld van het machineformulier (op de machinelijst of bij een klant). Onderaan de lijst verschijnt dan "Nieuw product ‘…’"; dat opent het productformulier boven op het machineformulier, met wat je typte al als model ingevuld. Na opslaan staat het nieuwe product meteen gekozen en vul je de machine verder in — wat je al had ingevuld blijft staan. Ontbreekt in dat productformulier het merk of het producttype, dan maak je dat op dezelfde manier aan: typ de naam en kies "Nieuw merk ‘…’" of "Nieuw producttype ‘…’". Die regel zie je alleen met het recht om producten aan te maken; voor een merk of producttype is daarnaast het recht nodig om merken of producttypes aan te maken. Een klant die er nog niet in staat maak je op dezelfde manier vanuit het klantveld: typ de naam en kies "Nieuwe klant ‘…’" (met het recht om klanten aan te maken). Het klantformulier opent met die naam al ingevuld; na opslaan staat de klant gekozen.

Niet elke machine heeft een serienummer. Een bundel is een omhulsel en heeft er nooit een, en een product dat niet **registreerbaar** is wordt per stuk geteld in plaats van stuk voor stuk vastgelegd — denk aan zonnepanelen. Machines van zulke producten worden gewoon aangemaakt, alleen zonder nummer; overal waar anders het serienummer staat, lees je "Bundel" of "Geen serienummer".

Machines kunnen in elkaar zitten: een machine kan onderdelen hebben die zelf ook machines zijn (met eigen serienummer). Een onderdeel hoort bij zijn moedermachine en niet rechtstreeks bij een klant. Als het product zo is ingericht dat er verplichte onderdelen bij horen, worden die bij het aanmaken automatisch meegemaakt. Onderdelen met een **vrij aantal** vul je bij het aanmaken zelf in: je geeft aan hoeveel er in deze machine gaan, en typt daarna alleen de serienummers van de onderdelen die er een hebben. Je kunt ook een losse machine als onderdeel aan een andere koppelen (hij geeft dan zijn eigen klant en locatie op) of een onderdeel loskoppelen (hij erft dan de klant en locatie van de moedermachine).

**Verhuizen naar een andere klant**: wijzig je op een machine, werkbon of contract de klant, dan kan de hele machineboom mee-verhuizen. Je krijgt eerst een voorbeeldscherm dat laat zien wat er gebeurt, inclusief het opnieuw kiezen van een locatie bij de nieuwe klant. Keuringen en storingen reizen met de machine mee — de historie hoort bij het apparaat. Onderhoudscontracten van de oude klant laten de machine automatisch los.

**Een machine kiezen** doe je overal met hetzelfde keuzemenu (op een werkbon, een contract of een storing). Het zoekveld kijkt naar merk, model, serienummer, locatie én naar de onderdelen: het serienummer van een onderdeel brengt je dus bij de machine waar het in zit. Machines met onderdelen tonen die onderdelen eronder, zodat je bij twee gelijkende machines ziet welke je moet hebben.

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

Op Details staan: de stappenbalk, de gegevens (klant, project, locatie, datum opdracht, extern factuurnummer, externe referentie, uitvoeringslocatie), de taken, de keuringen (met een knop om een machine toe te voegen), de gekoppelde storingen (bestaande kiezen of direct een nieuwe aanmaken; de keuzelijst toont alle openstaande storingen van de klant, ook die op onderdelen van een machine), de materialen, en in de zijbalk: een kaartje van de locatie, de tijdlijn, de afspraken, opmerkingen (openbaar én intern), documenten, twee fotoblokken (openbaar en intern) en het blok **Afronding en handtekening**.

**Standaard of monteur.** Bovenaan de werkbon staat een schakelaar tussen **Standaard** en **Monteur**. In de monteursweergave staat daarboven verder niets: geen kruimelpad, nummer of fase. Wie de werkbon uitvoert — op de werkbon zelf of op een van de afspraken — krijgt de monteursweergave, iedereen anders de standaardweergave. Schakel je om, dan onthoudt Lavoro die keuze op dit toestel voor al je werkbonnen.

De monteursweergave toont van boven naar beneden:

- De klant en het adres waar je heen moet, met een knop **Navigeer** die Google Maps opent en een knop **Bel klant** die het mobiele nummer van de klant belt, of het vaste als er geen mobiel nummer is. Onder het adres staat waarom het dit adres is: de locatie van de afspraak, een adres dat bij de afspraak is ingevuld, de locatie of uitvoeringslocatie van de werkbon, de locatie van het project, of het adres van de klant. Daaronder de afspraak met datum en tijd. Heb je er meer, dan blader je met de pijltjes of veeg je de afspraak opzij; het adres verandert mee, want een afspraak kan een eigen locatie hebben. Staan je tijden er nog niet in, dan zie je een oranje label **Geen tijden ingevuld**. Met **Tijden invullen** vul je voor die afspraak je begin- en eindtijd, reistijd, pauze en handtekening in; daarna staan je tijden er, met pauze en reistijd erbij. **Annuleren** daarnaast annuleert de afspraak voor jou, na een bevestiging.
- **Wat moet er gebeuren?**: de keuringen (**Uitvoeren** opent de keuring), de storingen (**Oplossen** opent de storing) en de taken. Een taak zonder machines en zonder checks vink je af met **Afvinken**; nog eens tikken zet het vinkje weer uit. Een taak met machines of checks heeft de knop **Uitvoeren**: de taak klapt dan open op zijn plek, zonder venster erover. Daarin staan per machine het serienummer (typen of scannen) en de checks van die machine, of — als de checks voor de hele taak gelden — eerst alle serienummers en daaronder één keer de checks. Een afgevinkte check toont het tijdstip waarop je hem afvinkte; een sterretje betekent dat de check verplicht is. Onderaan staat **Taak voltooien**; zolang er nog verplichte checks open staan of machines ontbreken, staat eronder wat er nog mist. Bij een afgeronde taak staat daar **Taak heropenen**. Op de dichtgeklapte taak zie je hoeveel machines en checks er al zijn (bijvoorbeeld "4/9 checks"). Wat klaar is blijft grijs en met een vinkje in de lijst staan.
- **Materialen**, alleen naam en aantal. Tik op een regel en eronder klapt open wat je kunt aanpassen: het aantal, onvoorzien, de omschrijving van een vrije regel, of de regel verwijderen. Nog eens tikken klapt hem dicht. **Toevoegen** klapt bovenaan de lijst open: kies een materiaal of vul een vrije regel in. Het blijft open, zodat je meerdere materialen achter elkaar boekt; nog eens op **Toevoegen** tikken klapt het dicht. Prijzen staan hier niet.
- **Opmerkingen** en **Foto's**, elk met een schakelaar tussen **Intern** en **Extern (klant)**, en daaronder de documenten. De knop onder het opmerkingenveld zegt waar je plaatst: **Interne opmerking plaatsen** of **Externe opmerking plaatsen**. Dat staat zo ook in de standaardweergave van de werkbon.
- Onderin, altijd binnen bereik, de knop **Werkbon afronden**. Die opent het overzicht om de klant te laten tekenen; na het tekenen heet hij **Werkbon afsluiten**. Veeg je de menubalk opzij, dan zakt de knop mee naar beneden. Terwijl je typt verdwijnt hij even, zodat hij niet over het veld valt.

Taken toevoegen, annuleren of laten ondertekenen, keuringen en storingen koppelen, de fase wijzigen en de administratie doe je in de standaardweergave.

**Locatie**: de keuzelijst toont alle locaties van de klant van de werkbon. Met het rondje met het kruis naast het veld haal je de locatie weer van de werkbon af; daarna kun je een vrije uitvoeringslocatie intypen. In de tijdlijn staat een gewijzigde locatie met haar naam.

Is er niets te kiezen, dan zegt de lijst waarom. "Deze klant heeft nog geen locaties" betekent dat ze eerst bij de klant aangemaakt moeten worden; dat de locaties niet opgehaald konden worden, betekent dat je het opnieuw moet proberen of de pagina moet herladen; en "je mag de locaties van deze klant niet opvragen" betekent dat jouw rol de lijst niet mag opvragen. Die lijst gaat mee met het veld dat je invult: wie werkbonnen, machines of afspraken mag aanmaken of wijzigen ziet hem. Zie je de melding toch, dan is het recht **Locaties bekijken** of **Klanten bekijken** nodig — vraag een beheerder erom. Het veld blijft in alle drie de gevallen staan, zodat je ziet dat er een locatie *kan* en waarom er nu geen in de lijst staat.

**Afsluiten**: onderaan zit de knop om de werkbon af te sluiten, met aankomst- en vertrektijden, afsluitende opmerkingen en de handtekening van de klant (naam plus getekende handtekening). Er kan een minimumaantal foto's vereist zijn om af te sluiten (instelbaar door de beheerder). Een werkbon kan ook heropend of als onvolledig gemarkeerd worden. Het vinkje "werk volledig afgerond" zet je uit als het werk niet af is.

De knop **Bekijk & onderteken** opent het overzicht dat je aan de klant laat zien: de opmerkingen, de foto's, de materialen, de uitgevoerde werkzaamheden en de handtekening. De taken staan daar als leeslijst — wat er gedaan is, met serienummers en de reden bij een geannuleerde taak — en niet als de werklijst die je zelf afvinkt.

**Openstaande taken houden de bon dicht.** Zolang er taken zijn die niet afgerond en niet geannuleerd zijn, gaat de werkbon niet naar een fase die hem sluit (ook niet naar de gefactureerde fase). Je krijgt dan een venster met de taken die het tegenhouden. Zit daar een taak bij die aan een rol hangt waarin jij deze bon niet uitvoert, dan staat die er ook bij, met de reden waarom hij niet in jouw eigen takenlijst stond. Diezelfde poging levert een melding op voor wie de werkbon mag bijwerken, zodat iemand die de taak wél kan afronden of annuleren het weet.

Op **Administratie** staan de materialen met prijzen, subtotaal, btw (21%) en totaal, financieel commentaar, en — als de SnelStart-koppeling aanstaat — de knop **Verstuur naar SnelStart**.

Op **Exporteren** staan de werkbon-PDF en de keuring-PDF's met een voorbeeldweergave, een genereerknop en een **E-mail PDF**-knop. Op een iPhone met Lavoro als app op het beginscherm opent de genereerknop het deelvenster van iOS, net als bij het downloaden van documenten.

### PDF, e-mail en SnelStart

- De **werkbon-PDF** bevat logo, geplande datum, monteurs met werkelijke uren/reistijd/pauze, materialen (gesplitst in voorzien en onvoorzien "extra"), taken, foto's en de afsluittekst die de beheerder heeft ingesteld. Onder elke taak staan de afgevinkte checks met het tijdstip, per machine ("Machine 1 (DK-2231-A): ✓ Leidingen afgeperst 10:41 · ✓ Rooster schoongemaakt 10:42") of één keer voor de hele taak. Checks die niet zijn afgevinkt staan er niet op.
- **E-mailen kan pas als de werkbon gesloten is** en de klant een e-mailadres heeft. Er is ook een variant die de werkbon-PDF plus alle keuringsrapporten in één mail meestuurt. Na verzending staat op de werkbon dat hij naar de klant is verstuurd.
- **Verstuur naar SnelStart** maakt van de materialen op de werkbon een verkooporder in de boekhouding. Voorwaarden: de werkbon is gesloten, nog niet eerder verstuurd, de klant (of zijn facturatieklant) is aan SnelStart gekoppeld, en de materialen hebben een SnelStart-koppeling — regels zonder koppeling worden overgeslagen en dat wordt teruggemeld.

### Zoeken en filteren

De werkbonnenlijst zoekt op klant, plaats, beschrijving, fase, factuurnummer, inkoopordernummer en uitvoerder. Het adres waar het werk gebeurt telt ook mee: van een gekoppelde locatie tellen naam, locatiecode, adres, postcode, plaats en land, en van een los ingetypt uitvoeringsadres de tekst zelf. Heeft de werkbon zelf geen adres, dan telt de locatie van het project waar hij onder valt. Zoeken op een straat vindt de werkbon dus, of het adres nu gekozen, getypt of van het project is — ook in het zoekvenster bovenin en via de assistent. Onder de klantnaam in de lijst staat dat uitvoeringsadres (of, zonder adres, de plaats van de klant), zodat je ziet waarom een werkbon bij je zoekterm hoort. Er is een fasefilter en een schakelaar **"Alleen te sluiten"**: werkbonnen waarvan alle afspraken voorbij zijn maar die nog niet gesloten zijn. Wie geen recht heeft om alle werkbonnen te zien, ziet alleen de werkbonnen waar hij zelf als uitvoerder op staat.

## Taken op een werkbon

Taken zeggen wat er gedaan moet worden. Onder Werkbonnen → Taken beheer je **taaksjablonen** (titel en omschrijving); op een werkbon zet je daar exemplaren van, eventueel met een aantal en een gekoppeld product.

Per taak op de werkbon kan:

- **Afvinken** (gereed melden). Een geannuleerde taak kan niet gereed; hoort er een product bij, dan moeten eerst alle machines geregistreerd zijn, en heeft de taak verplichte checks, dan moeten die eerst allemaal afgevinkt zijn ("Vink eerst alle verplichte checks af."). Er wordt vastgelegd wie de taak wanneer afrondde.
- **Ondertekenen**: een afgeronde taak kan apart door de klant ondertekend worden. Heropenen wist de handtekening.
- **Annuleren** met een reden (kan niet meer als de taak al afgerond is).
- **Apparatuur registreren**: bij een taak die apparatuur levert, registreer je de machines; daarmee ontstaan ze in het systeem. In de standaardweergave doe je dat in het venster **Taak uitvoeren**, dat opent via het label "0/3 machines" of "0/9 checks" bij de taak; in de monteursweergave in de opengeklapte taak. Levert de taak een bundel, dan krijg je per verkochte bundel een blok met de onderdelen die erin gaan: de bundelmachine zelf komt bij de klant te staan (zonder serienummer) en de onderdelen hangen eronder. Onderdelen van een registreerbaar product vul je stuk voor stuk op serienummer in; onderdelen zonder serienummer registreer je in één keer met de knop **Registreren**. Zodra apparatuur geregistreerd is kan de taak niet meer heropend worden.
- **Vrije aantallen invullen**: kent de bundel onderdelen met een vrij aantal — één omvormer met zoveel panelen als dit dak aankan — dan vul je die aantallen in bij het aanmaken of bewerken van de taak. Het aantal kan later niet lager gezet worden dan wat er al geregistreerd is.
- **Materialen boeken** op de taak zelf (naast materialen op de werkbon); op de bon en PDF is te zien bij welke taak materiaal hoort.

Taken kunnen aan planrollen gebonden zijn, zodat monteurs alleen de taken van hun eigen rol zien; er is een recht om altijd alle taken te zien. Staan er taken op de bon die buiten jouw rollen vallen, dan meldt het takenblok dat er taken zijn die je niet kunt zien — je weet dan dat de lijst niet compleet is, ook al lees je de taken zelf niet.

### Checks op een taak

Een **check** is één ding dat de monteur bij een taak afvinkt, zoals "Leidingen afgeperst" of "Rooster schoongemaakt". Welke checks bij welk taaksjabloon horen stel je in onder Werkbonnen → Checklists (zie hieronder). Zet je een taak op een werkbon, dan krijgt die taak de checks van het sjabloon mee: eerst de checks van de checklists, in hun volgorde, daarna de losse checks. Een check die via twee wegen bij de taak hoort, staat er maar één keer op, en is verplicht als hij via een van die wegen verplicht is.

- **Verplicht of optioneel**: verplichte checks (met een sterretje) moeten afgevinkt zijn voordat de taak voltooid kan worden; optionele niet. Een taak met verplichte checks kan niet meteen als voltooid worden aangemaakt.
- **Per machine of voor de hele taak**: heeft de taak een product met een aantal groter dan 1, dan kies je bij het aanmaken of bewerken met **Checks per machine afvinken** of de checks per machine gelden (drie airco's: drie keer de checks) of één keer voor alle machines samen. Bij een bundel telt elke verkochte bundel als één machine, niet elk onderdeel. Zodra er een check is afgevinkt, kan dit niet meer wijzigen.
- **Extra checks**: bij het aanmaken of bewerken van een taak voeg je onder **Extra checks (optioneel)** losse checks of hele checklists uit de bibliotheek toe, of typ je een eigen check in ("Eigen check"). Extra checks zijn altijd optioneel. Een eigen check geldt alleen voor deze werkbon en komt niet in de bibliotheek. Een extra check haal je weg door op het label te klikken.
- **Checks volgen de machine**: wordt het serienummer van machine 2 ingevuld, dan horen de checks van machine 2 vanaf dat moment bij die machine. Wordt het serienummer later verbeterd, dan blijven de vinkjes bij dezelfde machine.
- **Aantal verlagen**: gaat het aantal omlaag, dan verdwijnen de checks van de machines die wegvallen, en de overige machines worden opnieuw doorgenummerd. Machines zonder vinkjes en zonder geregistreerde machine gaan eerst; staan er bij de machines die weg zouden moeten al vinkjes, dan wordt het nieuwe aantal geweigerd ("Bij machine 3 zijn al checks afgevinkt; kies een hoger aantal."), en is daar al een machine geregistreerd, dan staat er "Machine 3 is al geregistreerd; kies een hoger aantal.". Bij een bundel gaan altijd de laatste bundels weg: van 3 naar 2 haalt bundel 3 weg, ook als bundel 1 nog leeg is, en heeft bundel 3 al vinkjes, dan wordt het geweigerd. Een voltooide taak houdt zijn aantal; heropen de taak om het te wijzigen.
- **Wie en wanneer**: bij elke afgevinkte check wordt vastgelegd wie hem afvinkte en wanneer. In de tijdlijn van de werkbon en van de klant staat bijvoorbeeld: Check "Rooster schoongemaakt" afgevinkt bij taak "Airco installatie", machine 2 (DK-2231-A). Na het voltooien van de taak kunnen de checks niet meer veranderen; heropen de taak daarvoor.
- **Sjablonen veranderen werkbonnen niet**: een taak op een werkbon houdt de checks waarmee hij is aangemaakt. Wijzig of verwijder je later een check of checklist, dan blijven bestaande werkbonnen zoals ze waren.

### Checklists beheren

Onder Werkbonnen → **Checklists** stel je in welke checks bij welke werkbontaak horen. De pagina heeft drie kolommen: links de **checks**, in het midden de **checklists** (een herbruikbaar rijtje checks, zoals "Checklist oplevering"), rechts de **taken** (de taaksjablonen van Werkbonnen → Taken). Elke kolom heeft een eigen zoekveld en scrolt los van de andere. De pagina werkt op een groter scherm; op een telefoon staat er een melding.

- **Aanmaken**: typ bovenaan de kolom een naam bij **+ Nieuwe check** of **+ Nieuwe checklist** en druk op Enter. Taken maak je aan onder Werkbonnen → Taken.
- **Koppelen**: sleep een check op een checklist of op een taak, of sleep een checklist op een taak. Er verschijnt een lijn tussen de twee. Een nieuwe koppeling is verplicht.
- **Lijnen**: een doorgetrokken lijn is verplicht, een gestippelde lijn optioneel. Klik op een lijn om te kiezen tussen **Verplicht** en **Optioneel**, of om te **ontkoppelen**. Een checklist die optioneel aan een taak hangt, maakt al haar checks optioneel voor die taak; dezelfde checklist kan op een andere taak verplicht zijn.
- **Bekijken wat bij elkaar hoort**: klik op een kaart, dan lichten alleen haar lijnen op en vervaagt de rest. Nog eens klikken laat alles weer zien.
- **Volgorde**: klap een checklist of taak open met het pijltje. Je ziet wat erin zit, in de volgorde waarin de monteur het ziet; sleep een regel aan het handvat om hem te verplaatsen, en klik op "verplicht" of "optioneel" om dat om te zetten. Bij een taak staan de checklists en de losse checks apart; de checklists komen op de werkbon eerst.
- **Hernoemen en verwijderen** gaan via het menu (de drie puntjes) op de kaart. Voor het verwijderen zie je in hoeveel taken de check of checklist zit. Werkbonnen die er al mee werken houden hun eigen kopie.

Wie werkbontaken mag bekijken, kan deze pagina zien; wie ze mag wijzigen, kan hier ook aanmaken, koppelen en verwijderen.

## Keuringen

Een keuring is de inspectie van één machine op een werkbon. Voeg je op de werkbon een machine toe, dan ontstaat de keuring en worden automatisch alle **keurpunten** klaargezet die bij het producttype van die machine horen. Onderdelen van de machine krijgen elk hun eigen deelkeuring (een gecombineerde keuring met moeder- en dochterkeuringen).

**Meerdere machines tegelijk.** Naast **+ Keuring toevoegen** staat **Meerdere machines**. Daarin kies je **Alle machines van de klant** of een van de locaties van de klant; achter elke keuze staat hoeveel machines nog geen keuring op deze werkbon hebben. Na een bevestiging krijgt elke actieve machine (status Actief) daar een keuring, met de gecombineerde keuringen voor de onderdelen erbij, en staat elke toegevoegde keuring in de tijdlijn. Machines die al een keuring op deze werkbon hebben worden overgeslagen; heeft geen enkele machine meer een keuring nodig, dan staat de knop er niet. Eén machine die al een keuring op deze werkbon heeft, kun je ook los niet nog eens toevoegen.

**Het serienummer** staat bovenaan de keuring en is daar ook in te vullen, met dezelfde twee scanknoppen als op de machine zelf. Dat scheelt een omweg als de machine zonder nummer of met het verkeerde nummer blijkt te zijn ingevoerd: het plaatje zit op dat moment voor je neus. Invullen kan als je machines mag bijwerken, of als je het recht **Serienummer invullen op machines van eigen open werkbonnen** hebt — dat laatste geeft een monteur alleen het serienummer, en alleen van machines op zijn eigen open werkbonnen. Mag je het niet, dan staat het nummer er gewoon als verwijzing naar de machine. Een bundel of een niet-registreerbaar product heeft geen serienummer: daar leest het veld "Bundel" of "Geen serienummer" en is er niets in te vullen.

Keurpunten zijn er in soorten: één keuze uit meerdere, meerdere keuzes, ja/nee, getal en tekst; per punt kunnen ook een opmerking en foto's worden vastgelegd. Keurpunten en hun groepen (de indeling op het rapport) beheer je in de catalogus onder Keurpunten.

**Afronden**: kies een uitkomst — Goedkeur, Afkeur, Goedkeur na reparatie, Tijdelijke goedkeur (met aantal dagen) — en een afrondingsdatum. Afronden kan pas als alle keurpunten zijn ingevuld; "Nog geen uitkomst" telt niet als uitkomst. Een gecombineerde keuring kan in één keer met alle deelkeuringen worden afgerond. Bij goedkeuring schuift de volgende keuringsdatum van de machine automatisch op; het opnieuw openen van de keuring draait dat weer terug.

Mist een keuring keurpunten die later aan het producttype zijn toegevoegd, dan is er een knop om de ontbrekende punten alsnog toe te voegen.

Elke keuring heeft een eigen PDF-rapport dat je kunt genereren en naar de klant kunt mailen (los, of gebundeld met de werkbon-PDF).

### Aankomende keuringen

De pagina **Aankomende keuringen** (onder Lijsten) toont de machines waarvan de keuring verlopen is of binnen de gekozen periode valt (60, 90, 180 of 365 dagen), met hun storingen, zodat je er werkbonnen voor maakt. Rechtsboven de lijst wissel je tussen twee weergaven; de pagina opent de volgende keer in de weergave die je het laatst koos.

- **Per klant**: een kaart per klant met daarin de machines, gegroepeerd per locatie, en de storingen die je los kunt aanvinken.
- **Tabel**: één rij per machine, met foto, serienummer, merk en model, klant, locatie, keuringsdatum, status en het aantal storingen dat nog op geen werkbon staat (het sleuteltje). Heeft de klant nog meer machines in de lijst, ook op een andere pagina, dan staat achter de klantnaam hoeveel: *+3*. Een onderdeel staat er als eigen rij, met de klant en locatie van de machine waar het in zit en een link naar die machine. Staat de machine al op een werkbon, dan staat die werkbon onder de naam, met de datum van de afspraak als die er is.

In de tabel:

- **Status** heeft dezelfde groepen en kleuren als de spelden op de kaart (zie *Spelden en groepen op de kaart*): Verlopen, Binnen 30 dagen, enzovoort.
- **Sorteren**: klik op een kolomkop. De eerste klik sorteert oplopend, de tweede aflopend en de derde zet de standaardvolgorde terug: wat het eerst verloopt bovenaan. Binnen dezelfde klant, locatie of machine blijft de vroegste datum bovenaan.
- **Filteren** op klant, type, status, storingen (met of zonder) en planning. Een filter biedt alleen wat er in de gekozen periode voorkomt. Staat een filter aan, dan begint de lijst met de weg terug (*Alle klanten*, *Alle types*, *Alle statussen*, *Met en zonder storingen*, *Gepland en niet gepland*); dat, of nog eens klikken op de gekozen waarde, maakt het vak weer leeg. Een leeg vak filtert niets. **Planning** staat bij het openen op *Niet gepland*: een machine geldt als gepland als hij op een werkbon staat met een afspraak die nog moet komen. Kies *Al gepland* om alleen die te zien, of maak het vak leeg voor allebei.
- **Zoeken** kijkt naar serienummer, merk, model, type, klant en locatie; een onderdeel vind je ook op de naam van de klant van zijn machine.
- **Bladeren**: onderaan kies je hoeveel rijen er op een pagina staan en blader je verder.
- Klik je op de kaart (knop **Kaart**) op een speld, dan toont de tabel alleen de machines van die klant. In de weergave per klant springt de pagina naar die klant.

**Een werkbon maken** gaat in beide weergaven hetzelfde: vink machines aan en kies onderaan rechts **Maak een werkbon aan en blijf hier**, **… en open die** of **Maak werkbon en plan deze in**. In de tabel blijven je vinkjes staan als je sorteert, filtert of bladert, ook als de aangevinkte machine even niet in beeld is. Vink je in de tabel een machine aan, dan biedt de pagina aan wat je op dezelfde rit kunt meenemen: de storingen van die machine die nog op geen werkbon staan, en de andere machines van dezelfde klant in de lijst, ook als die verderop of op een andere pagina staan. Alles staat aangevinkt; vink per storing of per machine uit wat niet mee moet, of in één keer met het vinkje boven de lijst. **Alleen deze machine** neemt niets mee. Haal je het vinkje van een machine weer weg, dan gaan ook haar storingen eraf. Een werkbon is voor één klant: vink je machines of storingen van twee klanten aan, dan wordt de knop rood en zegt hij *Selecteer storingen en keuringen van één klant*, en ook Lavoro zelf weigert zo'n werkbon. Een werkbon aanmaken kan alleen met het recht om werkbonnen aan te maken; zonder dat recht staan de knoppen er niet, ook niet bij een project of als vinkje *Maak een nieuwe werkbon aan* bij een afspraak in de planner.

## Macro's

Een macro is een vaste reeks stappen die je met één klik op een werkbon uitvoert, zodat je niet bij elke airco-installatie opnieuw dezelfde taken, opmerking en keuringen aanklikt. Waar het per werkbon verschilt — hoeveel airco's, is er een offerte — stelt de macro bij het uitvoeren een vraag.

### Een macro bouwen

Onder Werkbonnen → **Macro's** staan de macro's, elk met zijn stappen als genummerde zinnen. **Nieuwe macro** opent de editor: bovenaan de naam en een omschrijving, links de **blokken** en rechts de macro zelf, van **Start** naar **Klaar**. Sleep een blok op een plus tussen twee stappen, of klik op een plus om een blok te kiezen. Een blok verplaats je door het aan de kop te slepen. Klik op een blok om het in te stellen; nog eens klikken klapt het dicht. **Blok verwijderen** staat onderaan de instellingen.

De blokken:

- **Taken toevoegen**: een taak uit de taaksjablonen, eventueel met een product en een aantal. Bij een product met een aantal boven 1 kies je **Checks per machine afvinken**; extra checks en checklists kunnen erbij, net als bij een taak op de werkbon.
- **Vraag ja/nee**: splitst de macro in een **Ja**- en een **Nee**-baan. Bij het uitvoeren wordt de vraag gesteld en loopt alleen de baan van het antwoord; daarna gaat de macro verder met wat onder de twee banen staat. In een baan kan weer een vraag staan.
- **Document uploaden**: vraagt bij het uitvoeren om een bestand en zet het bij de werkbon, in de gekozen categorie en eventueel intern.
- **Opmerking plaatsen**: een interne of externe opmerking op de werkbon.
- **Keuringen toevoegen**: een keuring voor elke actieve machine van de klant, of van één locatie (zie *Keuringen*, Meerdere machines).

**Vragen bij uitvoeren.** Bij het aantal van een taak, de tekst van een opmerking en de locatie van de keuringen staat de schakelaar **Vragen bij uitvoeren**. Staat hij aan, dan typ je in plaats van een vaste waarde de vraag die gesteld wordt, bijvoorbeeld "Hoeveel airco's?". Bij Document uploaden is het bestand altijd een vraag.

**Opslaan** controleert de hele macro. Een blok dat niet goed is ingevuld krijgt een rood uitroepteken, en in het blok staat wat er mist. Een macro wijzigen of verwijderen verandert niets aan werkbonnen waarop hij al is uitgevoerd. Macro's bouwen kan met het recht **Macro's beheren**.

### Een macro uitvoeren

Op de werkbon staat bovenaan **Macro uitvoeren** (niet bij een afgesloten werkbon). Je ziet alleen de macro's waarvan je elke stap zelf mag doen. Kies een macro. Stelt hij vragen, dan komen die één voor één in beeld, met **Vorige** en **Volgende**; een ja/nee-vraag beantwoord je met **Ja** of **Nee** en je gaat meteen door. Welke vragen er daarna nog komen hangt van dat antwoord af. **Uitvoeren** doet dan alle stappen in één keer.

Een macro gaat helemaal of helemaal niet: lukt één stap niet, dan blijft er niets van de macro achter en staat er waarom. Keuringen toevoegen lukt bijvoorbeeld niet als elke machine al een keuring op de werkbon heeft ("Er zijn geen machines meer zonder keuring op deze werkbon."). Is er sinds het bouwen een taak, product, check of documentcategorie uit de macro verwijderd, dan weigert de macro meteen en vraagt hij om aanpassing; open hem en klik op **Opslaan**: de blokken met een uitroepteken laten zien wat er mist. Uitvoeren kan als je de werkbon mag wijzigen én elke stap die aan de beurt komt zelf mag doen. In de tijdlijn van de werkbon en van de klant staat bijvoorbeeld *Macro "Airco installatie met offerte" uitgevoerd: Hoeveel airco's? → 2; Is er een offerte? → nee*.

## Storingen

Een storing is een gemelde fout aan een machine, met een onderwerp, omschrijving, **status** (Open, In behandeling, Wacht op terugkoppeling klant, Gesloten), **prioriteit** (Laag, Normaal, Hoog) en optioneel een storingscode.

- Sluit je een storing, dan wordt automatisch vastgelegd wie dat wanneer deed; heropenen wist dat weer.
- Een storing hoort bij één machine en kan aan één werkbon gekoppeld worden. Alleen een losse storing (nog aan geen werkbon gekoppeld) kan aan een werkbon worden gehangen.
- Wie een storing mag zien volgt uit de machine of de werkbon erachter: kun je die zien, dan zie je de storing.

De storingenlijst heeft teltegels (Open, In behandeling, Wacht op klant, Gesloten), filters op status, prioriteit, storingscode en wie hem sloot, en bulk-bewerken van de status. Er is ook een **kaartweergave** die openstaande storingen per klant/locatie op de kaart zet: één speld per adres, in de kleur van de hoogste prioriteit daar, met het aantal storingen erin. Een paars schildje op de speld betekent dat er een storing bij is aan een machine onder een lopend onderhoudscontract, ook als de storing aan een onderdeel van die machine hangt; in de ballon staat het bij die storing zelf. Het schildje zie je alleen als je onderhoudscontracten mag inzien. Op deze kaart plan je ook een route langs de adressen (zie *Een route plannen*).

Storingen aanmaken kan vanaf de storingenlijst, de plus-knop, de machinepagina en direct vanaf een werkbon.

### Aanvullende informatie opvragen bij de klant

Ontbreekt er informatie om de storing goed in te schatten, dan vraag je die met de knop **Informatie opvragen** boven aan de storingspagina. Dat recht (`ticket.request_customer_info`) staat los van het bijwerken van storingen, want er gaat post de deur uit op naam van het bedrijf.

In het venster staat het adres van de klant al ingevuld, met het onderwerp en een brief waarin de machine en het serienummer zijn ingevuld. Met de knoppen erboven kies je wat je wilt ontvangen — foto's, video's of andere aanvullende informatie; nog eens klikken zet een keuze weer uit. Wat je kiest verschijnt als lijstje in de brief, die je verder vrij mag herschrijven. De knop naar de aanleverpagina wordt onder je tekst gezet en is daarom niet stuk te maken.

Na versturen:

- De storing komt op **Wacht op terugkoppeling klant** te staan.
- Er komt een regel op de tijdlijn met wat er gevraagd is en aan welk adres het ging.
- De mail wordt bewaard in de map Verzonden van de bedrijfspostbus, net als alle andere post uit Lavoro.

**Wat de klant ziet.** De link in de mail opent een eigen pagina met het bedrijfslogo, de machine, het serienummer en een lijstje van wat er gevraagd is. De klant sleept er bestanden op — foto's, video's, pdf's en documenten — en kan een toelichting typen. Er is geen inlog: de link ís de sleutel. Hij werkt **veertien dagen** en mag meerdere keren gebruikt worden, zodat een vergeten foto niet om een nieuwe mail vraagt. De pagina toont wat er via die link al verstuurd is en verder niets uit het dossier.

**Wat ermee gebeurt.** Foto's komen bij de afbeeldingen van de storing, video's en documenten bij de documenten onder de categorie *Klantinformatie*, en de toelichting als opmerking met het label *Klant*. Stond de storing op **Wacht op terugkoppeling klant**, dan gaat hij terug naar **In behandeling**: er wordt immers niet meer gewacht. Staat hij intussen ergens anders, dan blijft dat zo — wie hem daar heeft neergezet weet meer dan de aanleverpagina. Foto's en video's worden automatisch verkleind tot een formaat dat prettig te bekijken is (foto's tot 1920 pixels, video's tot 720p); lukt dat niet, dan blijft het origineel staan. Elke inzending levert één regel op de tijdlijn op, op naam van de klant en met het adres waar de link naartoe ging. Is de storing intussen gesloten, dan neemt de pagina niets meer aan.

## Planning en afspraken

De planningspagina is een tijdbalk: monteurs als rijen, de tijd van links naar rechts, per week of per dag. Bovenin: Vandaag, vorige/volgende en een kalenderknop met een maandkalender: klik een dag en de planner springt erheen (in de weekweergave naar de week van die dag), de week/dag-schakelaar, een zoekveld voor afspraken, de monteurkaart (live locatie van monteurs) en een instellingenmenu (exporteren, standaardduur, slotgrootte, leidende kleur — afspraaktype of monteurrol — en de begintijd en eindtijd van de dagweergave). Wie niet verder dan een week vooruit mag kijken, kan in die kalender ook geen latere dag kiezen.

- **Slepen om te plannen**: rechts staat een lijst met **ongeplande werkbonnen** (werkbonnen in een inplanbare fase). Werkbonnen van een project staan daar niet in maar hangen onder hun projectbalk, zolang het project nog loopt of nog moet beginnen; is de looptijd voorbij of heeft het project er geen, dan staan ze gewoon in de lijst, met een mapje achter het nummer (wijs het aan voor de projectnaam). Het zoekveld boven de lijst zoekt op werkbonnummer (10, #10 of WB-0010), klant, plaats en omschrijving. Sleep er één naar een monteur en tijdstip en er ontstaat een afspraak. Met de **rechtermuisknop** op een werkbon in die lijst open je een menu om hem te **openen** of te **verwijderen**; verwijderen vraagt eerst om een bevestiging. Wat je niet mag, staat niet in het menu. Afspraken zelf zijn ook te verslepen en op te rekken. Klikken opent het bewerkscherm.
- **Contractwerkbonnen** staan standaard *niet* in die lijst: werkbonnen die uit een onderhoudscontract zijn gegenereerd zouden het werk dat om een plek in de agenda vraagt anders overstemmen. Rechts naast de kop van de lijst zit een schakelaar die ze erbij zet — wijs hem aan en hij zegt wat hij doet en om hoeveel werkbonnen het gaat. Ze zijn dan te herkennen aan het paarse label **Contract**. De stand van de schakelaar wordt onthouden. Spring je met de knop "Inplannen" naar een contractwerkbon, dan gaat de schakelaar vanzelf aan.
- **Open plekken**: bovenin zit een schakelaar die de vrije ruimte in de planning groen laat oplichten. Het invoerveld ernaast bepaalt hoeveel minuten een open plek minstens moet zijn; leeg gelaten geldt de standaardduur voor nieuwe afspraken. Werkbonnen in een inplanbare fase hebben bovendien een knop **Inplannen** (op de werkbonlijsten en de werkbonpagina) die je naar de planner brengt: de werkbon licht op in de lijst met ongeplande werkbonnen — of, als hij onder een projectbalk hangt, daar — en de open plekken waar hij past lichten mee op.
- **De afspraak** heeft een soort (afspraaktype, met kleur), begin- en eindtijd, een klant, een gekoppelde werkbon, een locatie, een omschrijving en één of meer monteurs — per monteur met eigen pauze, eventueel **afwijkende tijden** en een planrol. Een afspraak kan ook **voorlopig** zijn.
- Het veld **Werkbon** in het afspraakscherm toont de werkbonnen van de gekozen klant die nog geen afspraak hebben, en werkt pas als er een klant gekozen is. Elke keer dat je erin klikt wordt de lijst opnieuw opgehaald; tijdens het typen zoekt het op werkbonnummer (272, #272 of WB-0272), datum, omschrijving en extern inkoopordernummer. Een werkbon die je net in een ander tabblad hebt aangemaakt staat er dus meteen in, zonder de planner te verversen.
- Bij het aanmaken van een afspraak kan automatisch een werkbon worden meegemaakt (of juist uitdrukkelijk zonder werkbon). Koppelen van een afspraak zet de werkbon in de fase "ingepland"; de afspraak verwijderen zet hem terug naar "planning vervallen".
- **Onbeschikbaarheid** (vrije dagen en vaste vrije momenten uit het rooster) ligt als blok over de planning. Plannen over een blok heen kan alleen als de beheerder dat toestaat, en dan met een uitdrukkelijke bevestiging.
- **Plangroepen** ordenen de monteursrijen: gekleurde groepen die je in de zijbalk aanmaakt, hernoemt, sorteert en van monteurs voorziet; ook of iemand überhaupt **inplanbaar** is stel je daar in.

**Uren en handtekening (uitvoering)**: elke monteur op een afspraak registreert zijn eigen werkelijkheid — starttijd, eindtijd, reistijd, pauze en een handtekening. Op de afspraak staan daarvoor, alleen in je eigen rij, knopjes: de klok om tijden en handtekening in te vullen, het kruisje om je eigen deel van de afspraak te annuleren. In de rij van een collega staan ze nooit, ook niet als je zelf op dezelfde afspraak staat. Wie het recht heeft kan ook tijden voor een ander invullen. Monteurs die hun tijden van gisteren nog niet hebben ingevuld krijgen daar 's ochtends automatisch een melding van.

**Bij het invullen** staan begin, eind en pauze al op wat er voor jou gepland was, met je afwijkende tijden als je die hebt; pas aan wat anders ging. Voor de gewerkte uren op de werkbon-PDF en in de planner-export telt de pauze die je invult, niet de geplande; is er geen pauze ingevuld, dan telt de geplande. De export toont beide, als **Geplande pauze** en **Werkelijke pauze**, en wie afwijkende tijden heeft, ziet die daar als geplande start en eind.

**Annuleren** geldt alleen voor jou: de afspraak en de andere monteurs erop blijven gewoon staan. In de planner is een geannuleerd deel te herkennen aan de doorgestreepte titel in de rij van die monteur en aan een verbodsteken dat iedereen ziet. Daarna kan niemand meer tijden voor dat deel invullen, ook niet namens jou, totdat de annulering wordt ingetrokken.

**Vrijgeven**: wie het recht heeft om tijden vrij te geven ziet op een afgerond of geannuleerd deel een rond pijltje. Dat geldt alleen voor de monteur in wiens rij het staat: afgeronde tijden worden gewist, een annulering wordt ingetrokken, en daarna kunnen de tijden opnieuw ingevuld worden. Wat de anderen op dezelfde afspraak hebben geregistreerd blijft staan.

Starten, tijden registreren, annuleren en vrijgeven komen allemaal op de tijdlijn van de gekoppelde werkbon. Je ziet daar wie het deed, voor welke monteur, en welke tijden er stonden voordat ze werden vrijgegeven. De assistent kan er ook naar gevraagd worden, per werkbon of per afspraak.

Afspraken kunnen gekopieerd worden naar andere dagen (inclusief werkbonkoppeling en monteurs): klik met de rechtermuisknop op de afspraak en kies onder **Kopiëren** naar morgen, de rest van de week, volgende week of **Naar datum…**, waar je zelf een dag kiest tot een jaar voor of na de afspraak. Een kopie krijgt dezelfde tijd, ook over de overgang naar zomer- of wintertijd heen. Vanaf een afspraak kan een **afspraakbevestiging** naar de klant worden gemaild, en de beheerder kan **standaard e-mails** aan het aanmaken, wijzigen of verwijderen van afspraken hangen (zie Instellingen en beheer).

Op een telefoon toont de planner één monteur tegelijk, per week, als lijst of als baanweergave, met veeggebaren om van week te wisselen. Mag je de hele planning zien, dan kies je bovenin een monteur of **Alle monteurs**; de telefoon onthoudt die keuze, zodat je bij terugkomst weer dezelfde ziet. Een afspraak aantikken opent hetzelfde bewerkscherm; onderaan dat scherm staat een knop om de afspraak te **verwijderen**, die eerst om een bevestiging vraagt. Die knop verschijnt alleen als je de afspraak mag verwijderen. Wijzigingen in de planning verschijnen — voor wie zijn agenda gekoppeld heeft — ook in Google Agenda; zie het hoofdstuk over koppelingen.

**Route van de dag**: is er voor een monteur een route in de planning gezet (zie *Een route plannen*), dan staat bovenaan die dag in zijn rij een knop **Route** die de route in Google Maps opent; op de telefoon staat hij in de balk van die dag. Een dag met alleen een route en geen afspraken staat dan ook in de lijst.

## Onderhoudscontracten

Een onderhoudscontract hoort bij een klant en dekt een set machines. Het heeft een looptijd (start- en einddatum), een prijs met een prijsinterval, en een **onderhoudsfrequentie**: maandelijks, halfjaarlijks, jaarlijks of een eigen aantal dagen. De frequentie geldt voor het hele contract, of — als "frequentie per machine" aanstaat — per machine apart.

De **einddatum mag leeg blijven**: dat is een contract dat doorloopt tot je het annuleert. Een ingevulde einddatum haal je weg met het kruisje dat rechts naast het veld verschijnt zodra je er met de muis overheen gaat; klap je het veld open om de datum te wijzigen, dan staat het kruisje naast de opslaanknop.

De **status** volgt uit de datums: toekomstig (nog niet begonnen), actief, verlopen (einddatum voorbij) of geannuleerd. Annuleren en weer activeren zijn knoppen op het contract.

De lijst met gekoppelde machines toont per machine het **producttype** en het serienummer, zodat je zonder doorklikken ziet wat er onder het contract valt.

**Werkbonnen genereren**: staat automatische generatie aan, dan kijkt het systeem elk uur welke machines aan de beurt zijn (op basis van de frequentie en wanneer er voor het laatst gegenereerd is) en maakt werkbonnen aan — **één werkbon per locatie**, met alle machines van die locatie als keuringen erop. Zit er een bundel bij, dan krijgen de onderdelen daarvan hun eigen deelkeuring, net als wanneer je de machine met de hand aan een werkbon toevoegt. Er kan een afwijkend generatie-interval ingesteld worden. Met de knop "werkbonnen genereren" doe je hetzelfde direct, voor alle machines van het contract, ongeacht of ze al aan de beurt waren. Een werkbon uit een contract is gewoon een werkbon, dus daarvoor is ook het recht om werkbonnen aan te maken nodig: zonder dat recht staat de knop er niet, en kun je automatische generatie niet aanzetten, ook niet via een sjabloon dat het aan heeft: een contract uit zo'n sjabloon komt er dan zonder automatische generatie uit.

Verhuist een machine naar een andere klant, dan wordt hij automatisch van contracten van de oude klant losgekoppeld.

De **prijs en het prijsinterval** zijn alleen te zien en te wijzigen met het recht op financiële contractgegevens; dat geldt voor contracten en voor sjablonen. Omdat je bij het aanmaken de prijs invult, is voor een nieuw contract naast het recht om contracten aan te maken ook dit recht nodig; zonder staat er geen knop om een contract toe te voegen.

### Sjablonen

Onder Onderhoudscontracten staan de **sjablonen**: contracten die je vaker afsluit, één keer vastgelegd. Een sjabloon bevat alles wat een contract heeft behalve de klant en de machines — een contracttitel, een looptijd in maanden, prijs en prijsinterval, de servicefrequentie (of "per machine"), en of er automatisch werkbonnen gegenereerd worden en met welk interval. Elk veld mag leeg blijven; wat het sjabloon niet invult, vul je op het contract zelf in.

In de **contracttitel** mag je `{klant}` en `{jaar}` gebruiken. Die worden ingevuld zodra het sjabloon toegepast wordt: `{klant}` met de naam van de klant, `{jaar}` met het jaar van de startdatum. "Onderhoud {jaar} — {klant}" wordt zo "Onderhoud 2026 — Jansen BV". Iets anders tussen accolades blijft staan zoals je het typt.

**Toepassen** doe je bij het aanmaken van een contract: bovenin het scherm "nieuw onderhoudscontract" — zowel vanaf de contractenlijst als vanaf de klant — staat een keuzelijst met sjablonen. Kies je er een, dan worden de velden ingevuld: de startdatum wordt vandaag als je er nog geen gekozen had, de einddatum volgt uit de looptijd (een looptijd van 12 maanden vanaf 1 januari loopt tot en met 31 december; geen looptijd betekent geen einddatum). Alles blijft daarna gewoon aan te passen, en zodra je zelf in de titel of de einddatum typt laat het sjabloon dat veld met rust. Een contract onthoudt niet uit welk sjabloon het komt: een sjabloon later wijzigen verandert niets aan bestaande contracten.

Sjablonen bekijken, aanmaken, wijzigen en verwijderen zijn aparte rechten, los van de rechten op contracten zelf.

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

- **Producten**: merk plus model, behorend bij een producttype. Een product kan een **bundel** zijn: een samenstelling van andere producten. Die samenstelling bepaalt welke onderdelen automatisch meekomen wanneer een machine of geleverde apparatuur wordt aangemaakt. Per onderdeel in een bundel leg je vast hoeveel er in gaan, of je zet het aantal op **vrij**: dan wordt het pas ingevuld op het moment dat de bundel verkocht wordt. Een product dat geen bundel is, is standaard **registreerbaar** — machines ervan worden stuk voor stuk op serienummer vastgelegd. Zet je dat uit, dan wordt het product per stuk geteld en vraagt Lavoro nergens meer om een serienummer. Producten hebben ook een aantal "certificaatdagen": hoe lang een goedkeuring geldig is, en dus hoe ver de volgende keuringsdatum opschuift.
- **Producttypes**: het soort apparaat. Aan het producttype hangen de **keurpunten** die elke keuring van zo'n apparaat moet aflopen. Types kunnen een hiërarchie vormen.
- **Merken**, **productkenmerken** (instelbare eigenschappen met waardes per product) en **relatietypes** (hoe producten in elkaar zitten) completeren de catalogus.
- **Leveranciers** kunnen aan producten en materialen gekoppeld worden, met inkoopprijs. Leveranciers zijn ook uit Excel te importeren.
- Producten kennen bulk-bewerken voor het in één keer aanpassen van velden.
- Een merk of producttype dat nog niet bestaat, maak je bij het aanmaken van een product direct in het keuzeveld aan: typ de naam en kies "Nieuw merk ‘…’" of "Nieuw producttype ‘…’". Producten, merken en producttypes aanmaken vraagt elk een eigen recht (product aanmaken, merk aanmaken, producttype aanmaken); zonder dat recht zie je die keuze niet, en ook de knop om toe te voegen op de pagina's Merken en Producttypes niet.

## Projecten

Een project bundelt werk voor een klant dat groter is dan één werkbon. Het heeft een projectleider, een status (Niet gestart, Gestart, Afgerond, Geannuleerd), een looptijd en een locatie. Werkbonnen kunnen aan een project hangen; heeft de werkbon zelf geen locatie, dan geldt die van het project.

Op de projectpagina staan de details, een **tijdlijn** (mijlpalen, werkbonnen met hun fases, afspraken, taken en storingen in de tijd), de werkbonnen, de klant en de **mijlpalen** — elk met een geplande en een werkelijke datum en een verantwoordelijke. Projecten verschijnen ook als balk boven in de planner.

De werkbonnen staan er als kaartjes: nummer en aanmaakdatum, klant met de locatie eronder, de geplande afspraken (de eerste twee; een +teller klapt de rest uit als je eroverheen beweegt), de fase (met bijvoorbeeld de afsluitdatum of de werkelijke starttijd eronder), de monteurs en hoeveel taken er af zijn. Het kleurbalkje links volgt de fase. Op een smal scherm klapt de rij samen tot een compact kaartje met dezelfde informatie. Dezelfde weergave wordt gebruikt op de klantpagina.

Wie het recht op projectfinanciën heeft ziet ook het tabblad Administratie met financiële notities (met vastgelegd wie ze het laatst bijwerkte). Wijzigingen in die notities komen niet in de tijdlijn: alleen de laatste bewerker en het tijdstip worden bewaard. Zonder dat recht zijn de notities nergens te zien, ook niet via de assistent.

## Documenten, foto's en opmerkingen

Aan vrijwel elk record (werkbon, storing, project, klant, machine, product…) kunnen bijlagen hangen:

- **Documenten**: bestanden uploaden (meerdere tegelijk), een titel en een **categorie** geven, en per document markeren of het intern is. Interne documenten komen nooit bij de klant. Documenten zijn te bekijken in een voorbeeldweergave en te downloaden; beide gaan langs de rechtencontrole. Op een iPhone met Lavoro als app op het beginscherm opent downloaden het deelvenster van iOS — kies daar bijvoorbeeld "Bewaar in Bestanden". Categorieën beheert de beheerder; er is bulk-hercategoriseren en bulk-verwijderen.
- **Foto's**: afbeeldingen uploaden, een hoofdfoto aanwijzen, en op werkbonnen een apart blok voor interne foto's. De werkbon-PDF bundelt de (openbare) foto's van het werk. Wordt een record verwijderd, dan gaan zijn foto's mee, en ook die van wat er automatisch mee verdwijnt, zoals de keuringen van een werkbon of de onderdelen van een machine. Foto's worden bij het uploaden verkleind, en HEIC-foto's (het formaat waarin Samsung- en iPhone-camera's opslaan als "hoge efficiëntie" aanstaat) worden daarbij omgezet naar JPEG. Dat omzetten kost een paar seconden per foto, en de eerste keer haalt de app daarvoor eerst de omzetter op. Mislukt een foto, dan staat onder **Opnieuw proberen** per foto waarom: geen verbinding, een bestand dat niet als foto te lezen is, of de reden die de server gaf.
- **Op een foto tekenen**: het potloodje op een foto (op de telefoon: tik op de foto en kies **Annoteren**) opent de foto schermvullend. Onderin staan **Markeer** (vrij tekenen), **Pijl**, **Tekst** (tik waar het label moet komen en typ) en **Bijsnijden** (sleep de hoeken; het rondje met de pijl zet de hele foto terug), plus een kleur en **Ongedaan maken**. Nog eens op het actieve gereedschap tikken zet het uit; dan kun je getekende dingen aantikken om ze te verschuiven, van kleur te veranderen, de tekst aan te passen of met het prullenbakje te verwijderen. Met **Bewaar** zie je de bewerkte foto overal: in de lijst, in de werkbon-PDF en in mails. Lukt bewaren niet, bijvoorbeeld zonder verbinding, dan blijft de foto open met de reden erbij en raak je niets kwijt. De foto zoals hij gemaakt is blijft bewaard, dus als je de foto later weer opent staan de pijlen en teksten er los op en kun je ze nog verschuiven of weghalen. Bewerken komt op de tijdlijn van het record als "Afbeelding … bewerkt".
- **Opmerkingen**: notities op een record. Werkbonnen kennen openbare én **interne opmerkingen**; interne opmerkingen zijn de plek voor diagnoses en aantekeningen die niet op de bon voor de klant horen.

Documentatie (handleidingen, datasheets) die als document met een documentatie-achtige categorie bij een **product** hangt, kan de AI-assistent meelezen bij technische vragen over een apparaat.

## Extra velden

De beheerder kan eigen velden definiëren op klanten, machines, producten, werkbonnen en storingen: tekst, nummer, datum, ja/nee, keuzelijst of tekstvak, eventueel verplicht en in een eigen volgorde. Deze extra velden verschijnen op de betreffende pagina's en zijn daar net als de gewone velden in te vullen. Beheer gebeurt onder Instellingen → Extra velden.

## Tijdlijn en meldingen

**Tijdlijn.** Vrijwel alles wat er in de applicatie gebeurt wordt vastgelegd: wie welk veld wanneer veranderde, met de waarde ervoor en erna, en ook samengestelde gebeurtenissen ("afspraak ingepland", "materiaal geboekt", "contract geannuleerd"). Op detailpagina's staat die geschiedenis als tijdlijn. Eén handeling die meerdere records raakt verschijnt op al die tijdlijnen. Gevoelige velden (prijzen, IBAN) zijn in de tijdlijn alleen zichtbaar voor wie het bijbehorende recht heeft. Handelingen die de AI-assistent namens iemand deed staan gewoon op naam van die gebruiker, maar zijn herkenbaar als door de assistent uitgevoerd.

**Meldingen.** Het belletje toont meldingen in de applicatie; wie dat toestaat krijgt ze ook als pushbericht op telefoon of computer. Twee soorten:

- **Abonnementen** (zelf aan te zetten onder Instellingen → Meldingen): nieuwe storing, storing van status gewijzigd, storing van prioriteit gewijzigd, klant leverde informatie aan, nieuwe planning, planning gewijzigd, werkbon afgerond, materiaal toegevoegd, keuring ondertekend, nieuwe klant, en "werkbon niet te sluiten" — die laatste komt als iemand een werkbon probeert te sluiten en wordt tegengehouden door taken die diegene zelf niet mag zien, en is alleen aan te zetten door wie werkbonnen mag bijwerken. Je krijgt alleen meldingen over dingen die je mag zien.
- **Afspraakherinnering** (staat vanzelf aan, zelf uit te zetten onder Instellingen → Meldingen): vlak voor elke afspraak waarop je staat krijg je een herinnering, standaard een uur van tevoren — de beheerder stelt dat in onder Instellingen → Algemeen. De herinnering zegt in één zin hoe laat je waar moet zijn, bijvoorbeeld "Let op, je moet om 09:30 bij Bakkerij Jansen zijn, op Dorpsstraat 1, 3511 AB Utrecht". Tik je erop, dan opent de werkbon (of de planner, als de afspraak geen werkbon heeft). In de Android-app en in Chrome staan er twee knoppen onder: **Route** opent Google Maps met het adres, **Bellen** zet het nummer van de klant klaar (eerst het mobiele nummer, anders het vaste): in de app meteen in de telefoon-app, in Chrome via een pagina met één grote belknop. Op een iPhone verschijnen geen knoppen; tikken werkt wel. Is de afspraak begonnen, dan telt de herinnering niet meer mee op het belletje; hij blijft wel in de lijst staan. Heb je eigen tijden op een afspraak, dan telt jouw begintijd. Je krijgt hem alleen als je inplanbaar bent, alleen voor afspraken die in Lavoro gepland zijn (niet voor wat uit je Google Agenda komt: daar herinnert Google je zelf aan), niet voor voorlopige, afgezegde of afgeronde afspraken, en niet als je je uitvoering al gestart bent. Wordt de afspraak verzet, dan krijg je een nieuwe herinnering voor de nieuwe tijd; een herinnering die niet meer klopt (andere tijd, afgezegd, of je staat er niet meer op) verdwijnt uit de lijst zolang je hem nog niet gelezen had.
- **Verplichte meldingen** (niet uit te zetten, over je eigen werk): je bent op een werkbon gezet, je afspraak is verplaatst, je bent van een afspraak afgehaald, en je hebt nog tijden van een afgelopen afspraak niet ingevuld (dagelijkse herinnering).

**Eén storing volgen.** Naast de abonnementen op een soort nieuws kun je één storing volgen met het belletje boven aan de storingspagina. Je krijgt dan alles wat over díe storing te melden valt — een gewijzigde status of prioriteit, en een klant die informatie aanlevert — zonder je op al het nieuws van dat soort in te tekenen. Nog eens klikken zet het volgen weer uit. Volgen kan van elke storing die je mag inzien: een monteur die op de werkbon staat hoeft daarvoor geen recht op álle storingen te hebben.

Over je eigen handelingen krijg je nooit een melding. Een nieuwere melding over hetzelfde record vervangt de oudere.

## Aankondigingen

Een **aankondiging** is een bericht aan de eigen mensen dat gelezen moet worden. Het verschijnt onder in beeld, op elke pagina, en gaat pas weg als de ontvanger op **Begrepen** drukt. Wie dat wanneer deed wordt vastgelegd. Dat is het verschil met een melding: een melding vertelt iets, een aankondiging vraagt om een bevestiging.

Beheer staat onder Communicatie → Aankondigingen.

**Een aankondiging maken.** Je geeft een titel en een bericht, en kiest de doelgroep: aan iedereen, of aan bepaalde gebruikers. Opslaan is versturen — er is geen conceptstand. Optioneel vul je **zichtbaar tot en met** in: op de dag erna verdwijnt de balk, ook bij wie niet bevestigde. Laat je dat leeg, dan blijft de aankondiging staan tot iedereen hem bevestigd heeft.

**De doelgroep is een momentopname.** "Aan iedereen" betekent iedereen die er op het moment van opslaan is. Wie later in dienst komt krijgt oude aankondigingen dus niet; wil je dat wel, dan open je de aankondiging en voeg je die persoon toe aan de doelgroep. Iemand uit de doelgroep halen kan ook, maar wie al bevestigd heeft blijft staan: die bevestiging is een vastgelegd feit.

**Eén tegelijk.** Heeft iemand meerdere aankondigingen openstaan, dan ziet die de oudste. Na Begrepen schuift de volgende in beeld.

**Terugkijken.** De lijst toont per aankondiging de doelgroep, hoeveel ontvangers al bevestigd hebben en of hij nog loopt. Op de aankondiging zelf staat wie hem kreeg, met per persoon de datum en tijd van bevestiging of "nog niet bevestigd", plus de tijdlijn met wat er aan de aankondiging veranderd is.

**Rechten.** Aankondigingen bekijken, aanmaken, wijzigen en verwijderen zijn losse rechten. Bevestigen vraagt geen recht: wie de aankondiging krijgt mag hem bevestigen, en niemand anders. Wijzigen omvat het bepalen van de doelgroep.

## Gebruikers, rollen en rechten

**Rechten** bepalen wat iemand mag. Ze zijn gebundeld in **rollen** (beheer onder Gebruikers → Rollen): een rol krijgt een set rechten en een set gebruikers. Eén rol is bijzonder: **admin** — een admin mag alles, ongeacht rechten, en is de enige die het admin-gedeelte (Bedrijf, Rollen, Instellingen) kan openen.

**Rollen toekennen** is een eigen recht (`user.assign_roles`), los van het bewerken van een gebruiker. Wie het heeft ziet op de gebruikerspagina het veld **Rollen** en kan daar rollen aan- en uitzetten; wie het niet heeft ziet dat veld niet en kan de rollen van een gebruiker ook niet langs een omweg wijzigen. Het staat bewust apart, want wie rollen uitdeelt deelt indirect alle rechten uit die in die rollen zitten. Het geeft geen toegang tot het samenstellen van rollen zelf: welke rechten in een rol zitten blijft aan de admin.

Rechten heten steeds "onderdeel punt actie" — bijvoorbeeld: werkbonnen lezen, alleen eigen werkbonnen lezen, werkbon sluiten, financiële gegevens zien, afspraken voor anderen aanmaken, alle afspraken zien, tijden van anderen invullen, gevoelige klantgegevens zien, de AI-assistent gebruiken. Lijsten en zoekresultaten volgen dezelfde regels: wie alleen eigen werkbonnen mag zien, ziet overal — ook in de zoekfunctie en de assistent — alleen die werkbonnen. Eigen betekent: je staat als uitvoerder op de werkbon zelf, of je voert een afspraak op die werkbon uit. Machines volgen dezelfde regel: met het recht **Machines zien die relevant zijn voor open werkbonnen** open je de machines van je eigen open werkbonnen, en verder geen.

Eén recht werkt alleen samen met een ander: **contracten aanmaken** vraagt ook **financiële contractgegevens zien**, omdat je bij het aanmaken de prijs invult.

**Per gebruiker** beheert de beheerder: naam, e-mail, wachtwoord, avatar, rollen (met het recht hierboven) en of iemand **inplanbaar** is (als rij in de planner verschijnt; alleen de admin). Gebruikers worden nooit echt weggegooid: verwijderen deactiveert ze, en ze zijn terug te halen.

**Thuisadres**: op het profiel staat een eigen blok **Thuisadres**. Typ straat, huisnummer en plaats in het zoekveld en kies het adres uit de lijst; de velden vullen zich dan vanzelf. Je kunt ze ook zelf invullen. Een route op de kaart kan bij een inplanbare medewerker thuis beginnen en eindigen (zie *Een route plannen*). Het adres zien alleen de persoon zelf en wie gebruikers mag bewerken; wie de route plant ziet alleen het huisje op de kaart.

**Rooster**: per gebruiker zijn vaste vrije momenten in te stellen (een weekdag, wekelijks of om de week, hele dag of een dagdeel, met een label zoals "papadag") en losse vrije dagen (een datum of periode). Dit rooster blokkeert de planner en telt mee wanneer beschikbaarheid wordt berekend. Wie het recht heeft beheert alleen zijn eigen rooster, of dat van iedereen.

**Planrollen** (Gebruikers → Planrollen) zijn iets anders dan rechten-rollen: gekleurde functierollen (bijvoorbeeld een vakgebied) die je aan monteurs op een afspraak geeft en waarmee taken op een werkbon aan een rol gebonden kunnen worden. Ze geven geen rechten.

Iedereen kan op zijn eigen profielpagina zijn naam, wachtwoord en avatar wijzigen en daar zijn Google Agenda koppelen.

### Grenzen aan uw abonnement

Uw abonnement bepaalt hoeveel u kunt opslaan en hoeveel mensen er kunnen werken.

**Opslag.** Foto's, documenten en de gegevens zelf tellen samen mee. Bereikt u de
limiet, dan wordt een upload geweigerd met een melding; bestaande bestanden
blijven altijd staan. Neem contact op om uit te breiden.

**Gebruikers.** Bij het aanmaken kiest u per gebruiker buitendienst of binnendienst;
die keuze bepaalt welke plaats hij bezet. Er is een aantal plaatsen voor buitendienst en voor binnendienst.
Zijn ze bezet, dan kan er geen nieuwe gebruiker van die soort bij. Een gebruiker
die u op non-actief zet geeft zijn plaats meteen weer vrij.

**Onderdelen.** Wat niet in uw abonnement zit, staat ook niet in het menu. Mist u
iets dat u wel verwacht, dan is het waarschijnlijk niet afgenomen.

## Instellingen en beheer

Het beheergedeelte (alleen voor admins, tenzij anders vermeld):

- **Bedrijf**: bedrijfsgegevens en logo's (normaal en diapositief); het hoofdbedrijf staat op de PDF's.
- **Rollen**: rollen samenstellen uit rechten en gebruikers.
- **Instellingen → Algemeen**: het tijdvenster en de weekdagen waarop de locatie van monteurs gevolgd wordt; de standaard afsluittekst onderaan de werkbon-PDF; of plannen over onbeschikbaarheid heen mag; het minimumaantal foto's om een werkbon te mogen sluiten; hoeveel minuten voor een afspraak de monteurs die erop staan een **afspraakherinnering** krijgen (5 tot 240, standaard 60); de kleuren op de kaart van de aankomende keuringen — een kleur voor verlopen, zelf toe te voegen, te wijzigen en te verwijderen groepen "binnen zoveel dagen" met elk een eigen kleur, en een kleur voor alles daarna (Later); **Standaardkleuren terugzetten** vult de oorspronkelijke kleuren weer in, en met **Opslaan** gelden ze; en — als SnelStart gekoppeld is — of de nachtelijke import uit SnelStart klanten bijwerkt die al in Lavoro staan.
- **Instellingen → Agenda-toegang**: een gebruiker inzage geven in de (Google-)agenda van een ander.
- **Instellingen → Extra velden** en **Instellingen → Meldingen**: zie de eigen hoofdstukken.
- **Communicatie → Standaard e-mails**: e-mailsjablonen met onderwerp en tekst (met invulvelden), gekoppeld aan momenten rond afspraken — bij aanmaken, wijzigen of verwijderen — en per moment in te stellen of de mail eerst ter bevestiging wordt voorgelegd, nog te bewerken is, of stil op de achtergrond wordt verstuurd. **Standaard bijlagen** zijn herbruikbare bestanden die met die sjablonen meegaan. Vanaf een afspraak zijn sjablonen ook met de hand te versturen, met een voorbeeldweergave en verzendhistorie.
- **Technisch beheer** (alleen voor MajorLabel): de koppelingen van uw bedrijf instellen — de mailserver waarmee Lavoro uw e-mail verstuurt (Microsoft 365 of een eigen mailserver, met daaronder de IMAP-gegevens voor de kopie in de map Verzonden) en de SnelStart-sleutels — en een testmail sturen om te controleren of het werkt. Sleutels en wachtwoorden worden versleuteld bewaard en nooit meer getoond; een leeg veld laat de opgeslagen waarde staan.
- In de planner zelf: standaardduur van een afspraak en de leidende kleur (afspraaktype of monteurrol).

**Onderhoud.** Bij werk aan de applicatie kan Lavoro tijdelijk op onderhoud worden gezet. Iedereen krijgt dan een onderhoudspagina te zien in plaats van de applicatie, met — als die zijn ingevuld — een korte toelichting en het tijdstip waarop Lavoro weer verwacht wordt. Die pagina ververst zichzelf, dus zodra het onderhoud klaar is kom je vanzelf weer in de applicatie; je hoeft niets te doen en niets opnieuw te laden. Er gaat geen werk verloren: wat al opgeslagen was staat er na het onderhoud gewoon weer. Onderhoud wordt door de beheerder van buiten de applicatie aan- en uitgezet, dus je vindt er geen knop voor in het menu.

Vaste automatische taken op de achtergrond: elk uur werkbonnen genereren uit onderhoudscontracten, elke nacht om 3 uur klanten en artikelen ophalen uit SnelStart (alleen als SnelStart gekoppeld is), elke ochtend monteurs herinneren aan ontbrekende tijden, en het dagelijks opruimen van oude assistent-gesprekken en oude locatiepings.

## Koppelingen

**SnelStart** (boekhouding). De sleutels stelt een beheerder in onder Technisch beheer; ze gelden alleen voor uw bedrijf. Als de koppeling is ingericht, worden klanten en artikelen elke nacht om 3 uur opgehaald. Nieuwe klanten en artikelen komen erbij en bestaande artikelen worden bijgewerkt. Bestaande klanten worden ook bijgewerkt met wat SnelStart heeft, tenzij een beheerder dat onder **Instellingen → Algemeen** heeft uitgezet; dan blijven klanten die al in Lavoro staan zoals ze zijn en komen er alleen nieuwe bij. Met de hand kan het ook: **Klanten ophalen uit SnelStart** op de klantenlijst (die vraagt elke keer of bestaande klanten bijgewerkt moeten worden, los van de instelling) en **SnelStart materialen importeren** op de materialenlijst; vanaf een gesloten werkbon maak je met één knop een verkooporder in SnelStart (zie het hoofdstuk Werkbonnen). Is er geen SnelStart-sleutel ingesteld, dan zijn alle SnelStart-knoppen onzichtbaar.

**Google Agenda.** Iedere gebruiker koppelt op zijn profielpagina zijn eigen Google-account. Er wordt dan een aparte agenda "Lavoro" in Google aangemaakt en gevuld met de bestaande afspraken. Daarna gaat het twee kanten op: afspraken uit de planner verschijnen in Google, en wijzigingen in Google komen terug in Lavoro. Valt de koppeling stil, dan verschijnt bovenin de applicatie een balk om opnieuw te koppelen. Een beheerder kan via Agenda-toegang iemands agenda met een collega delen.

**E-mail.** Uitgaande mail (werkbonnen, keuringsrapporten, afspraakbevestigingen, standaard e-mails) loopt via de mailkoppeling die een beheerder onder Technisch beheer instelt: Microsoft 365 of een eigen mailserver. Zolang er niets is ingesteld verstuurt Lavoro niets — er is geen gedeelde afzender waarop wordt teruggevallen. Verstuurde mail komt in de map Verzonden van de eigen mailbox. Bij Microsoft 365 doet het postvak dat zelf. Bij een eigen mailserver zet Lavoro er via IMAP een kopie neer, met dezelfde server, gebruikersnaam en wachtwoord als de SMTP-server tenzij daar onder Technisch beheer iets anders is ingevuld; de map (Sent, Verzonden of hoe uw server hem noemt) zoekt Lavoro zelf op. Doet uw mailserver dat zelf al, zoals Gmail, dan kan MajorLabel de kopie uitzetten; anders staat alles er twee keer in. Lukt de kopie niet, dan komt de mail gewoon aan; alleen de kopie ontbreekt. Onder Technisch beheer is de koppeling te testen: de testmail zegt ook in welke map de kopie terechtkwam, of waarom dat niet lukte.

**Mobiel.** Lavoro werkt in de browser op de telefoon en er is een Android-app, Lavoro in Google Play. Je hoeft in de app geen adres in te vullen: je logt in met je gewone e-mailadres en wachtwoord, en daarmee kom je vanzelf in je eigen bedrijf. Had je de oude app, die vanuit de applicatie te downloaden was? Die werkt niet meer: verwijder hem en installeer Lavoro uit Google Play. De app scant serienummers met de camera en deelt — alleen binnen het tijdvenster dat een beheerder onder Algemene instellingen instelt — de locatie van monteurs voor de monteurkaart in de planner. Voordat de app om je locatie vraagt, legt hij uit wat er gedeeld wordt en met wie; kies je "Niet nu", dan vraagt hij het de volgende keer dat je de app opent opnieuw. Zolang je locatie gedeeld wordt staat er een melding "Locatie actief" in je meldingenbalk, en buiten werktijd staat daar "Locatie gepauzeerd buiten werktijd". Ook de app toont meldingen, met de knoppen Route en Bellen onder een afspraakherinnering. Ben je inplanbaar, dan legt de app na het inloggen uit waarom herinneringen op tijd moeten komen en vraagt hij Android om Lavoro niet te beperken bij het sparen van de batterij; kies daar **Toestaan**. Op telefoons van Xiaomi, Huawei en Samsung zit daar nog een eigen batterijbesparing bovenop: zet Lavoro in de batterij-instellingen van de telefoon op **Niet beperkt** (Samsung: **Niet geoptimaliseerd**, en niet bij de slapende apps), anders kan een herinnering nog steeds te laat komen. Log je uit, dan krijgt die telefoon geen meldingen meer van jouw account. Is de server even niet bereikbaar, dan toont de app een pagina met "Opnieuw proberen", en zodra er weer verbinding is gaat hij vanzelf verder. De privacyverklaring staat op de website van Lavoro en is te openen via de link onder het inlogscherm; daar staat ook wat er met de locatie gebeurt en hoe je je account laat verwijderen.

## De AI-assistent

Wie het recht heeft ziet op belangrijke pagina's (werkbon, storing, klant, planner) een assistent-knop (sterretjes-icoon); ook via een sneltoets te openen. Je stelt in gewoon Nederlands een vraag; de assistent zoekt het antwoord op in het systeem.

Het venster heeft drie tabbladen:

- **Nieuwe chat** — het gesprek zelf. Zolang je nog niets gevraagd hebt staan hier voorgestelde vragen die bij deze pagina horen; klik er een aan om hem meteen te stellen. Daaronder staan je laatste gesprekken. Onderin typ je je vraag, met knoppen om een **foto** of een **bestand** mee te sturen, het gesprek te **melden** en met **Nieuw** een schoon gesprek te beginnen.
- **Gesprekken** — al je eerdere gesprekken, met het aantal berichten en wanneer het laatste was. Klik er een aan om hem te hervatten: het hele gesprek komt terug en je volgende vraag gaat er gewoon op verder.
- **Veelgestelde vragen** — de voorgestelde vragen beheren. De standaardvragen staan er al; je kunt eigen vragen toevoegen, wijzigen en verwijderen, en per vraag instellen op welke soort pagina hij verschijnt (of overal). Alleen je eigen vragen zijn te wijzigen.

Wat je ervan mag verwachten:

- **Hij ziet alleen wat jij mag zien.** Elke opzoekactie gebruikt jouw rechten. Een leeg resultaat betekent "niets gevonden of niets zichtbaar".
- **Hij hoort niets te verzinnen — maar controleer hem.** Records waar hij naar verwijst maakt hij klikbaar; noemt hij toch iets dat niet uit een zoekactie kwam, dan waarschuwt het scherm daarvoor. Voor technische uitspraken over apparatuur bestaat zo'n vangnet niet: hij hoort alleen te herhalen wat in de productomschrijving of documentatie staat, maar een taalmodel kan een gat stellig opvullen. Onderin het venster staat daarom altijd: de AI-assistent kan fouten maken, controleer de gegevens altijd. Wil je dat een verschil tussen twee uitvoeringen wél betrouwbaar beantwoord wordt, zet het dan in de productomschrijving — dan leest hij het voortaan gewoon op.
- **Wijzigen gaat altijd met een bevestigingsknop.** De assistent kan voorstellen een afspraak te plannen, een storing vast te leggen, een klant, locatie, product of machine aan te maken, een werkbon aan te maken, taken toe te voegen, een onderhoudscontract af te sluiten, een macro op een werkbon uit te voeren of een meegestuurd document bij een werkbon te bewaren — maar er gebeurt niets tot jij op **Bevestigen** klikt. Wat er op de knop staat is precies wat er uitgevoerd wordt. Een voorstel verloopt na een kwartier.
- **Meerdere kandidaten? Dan krijg je de keuze** voorgelegd in plaats van een gok.
- **Hij weet op welke pagina je bent**, dus "deze werkbon" werkt. Bij het wisselen van pagina begint een nieuw gesprek; eerdere gesprekken staan onder **Gesprekken** en zijn te hervatten. Alleen jijzelf kan je eigen gesprekken teruglezen.
- **Foto's kan hij lezen.** Stuur er een mee met de knop **Foto**, of sleep hem het venster in (plakken mag ook). Van een typeplaatje haalt hij merk, model en serienummer; per apparaat krijg je een blokje met balkjes die aangeven hoe zeker hij van elk gegeven is, met de foto's erbij waar hij het vanaf las. Onder de 70 procent gaat hij niets aanmaken zonder dat jij het bevestigt. Hij kan ook kijken naar foto's die al bij een werkbon, machine of storing staan — inclusief de interne foto's — bijvoorbeeld om machines die als "onbekend" zijn ingevoerd alsnog aan te vullen. **Let op:** een vraag met foto's kost al gauw tientallen keren zoveel als een vraag zonder; het venster waarschuwt daarvoor zolang er foto's meegaan. Meegestuurde foto's worden tijdelijk bewaard; bij het sluiten van het gesprek wordt gevraagd of je ze in je opslag wilt bewaren (dat telt mee voor je opslaglimiet) of weggooien.
- **Een bestand kan mee.** Klik op **Bestand** of sleep het het venster in: pdf's en tekstbestanden (.txt, .csv), maximaal twee tegelijk. Handig om een datasheet of offerte te laten lezen die nog niet in het systeem staat — "wat is het opgenomen vermogen volgens dit datablad?" of "vergelijk deze twee". Een Word- of Excel-bestand kun je eerst als pdf opslaan. Het bestand blijft de rest van het gesprek beschikbaar, dus je kunt er meerdere vragen over stellen. De eerste keer dat de assistent het leest, schrijft hij op wat hij eruit nodig heeft; daarna gaat die samenvatting mee in plaats van het hele bestand, wat elke volgende vraag goedkoper maakt. Staat het antwoord niet in de samenvatting, dan leest hij het bestand zelf opnieuw. Het wordt **niet** vanzelf in je opslag bewaard: het gaat mee zolang het gesprek loopt en verdwijnt daarna. Alleen een offerte waar de assistent een werkbon van maakt biedt hij aan bij die werkbon te bewaren (zie hieronder). Wil je een ander document blijvend bewaren, upload het dan gewoon bij het product of de werkbon — daar leest de assistent het ook.
- Bij technische vragen over een apparaat leest hij de documentatie mee die bij het product is opgeslagen, en bij vragen over de werking van Lavoro zelf raadpleegt hij deze handleiding.
- Vragen over de geschiedenis ("wie heeft dit gewijzigd?") beantwoordt hij uit de tijdlijn.
- **Macro's** kan hij lezen en uitvoeren. Vraag bijvoorbeeld "voer de airco-macro uit op deze werkbon, het zijn er drie en er is nog geen offerte": hij zoekt de macro op, vult de antwoorden in en vraagt wat hij niet weet. Op de bevestigingsknop staan de werkbon, de klant en elk antwoord. Vraagt de macro om een document, stuur het dan mee in het gesprek; anders voer je de macro uit met de knop op de werkbon. Hij gebruikt dezelfde controles als die knop: dezelfde rechten, dezelfde vragen, alles of niets.
- **Ging een gesprek mis?** Klik onder het invoerveld op **Melden** en zeg erbij wat er misging of wat je verwacht had (mag ook leeg blijven). Het hele gesprek wordt dan — met wat er achter de schermen is opgezocht — als bestand bewaard, zodat de fout onderzocht kan worden. Alleen je eigen gesprekken zijn te melden.

Wat de assistent doet wordt vastgelegd: de gestelde vragen, de gebruikte opzoekacties en de kosten per vraag. Handelingen die je via de assistent bevestigt staan op de tijdlijn op jouw naam, herkenbaar als via de assistent gedaan.

### Een getekende offerte inlezen

Sleep een getekende offerte (pdf) in het venster en vraag de assistent de klus op te zetten. Hij loopt dan stap voor stap door wat er nodig is; elke stap die iets aanmaakt krijgt een eigen **Bevestigen**-knop, en na de klik gaat hij vanzelf door met de volgende.

1. **Overzicht** — eerst een tabel met de klant en de regels van de offerte. Opties met aantal 0 (niet gekozen) laat hij weg. Wat de volgende stappen uit de offerte nodig hebben schrijft hij daarbij op; na de eerste klik werkt hij met die samenvatting, niet meer met de hele pdf.
2. **Klant** — hij zoekt de klant op naam en plaats. Vindt hij hem niet, dan kies je: een nieuwe klant aanmaken met de gegevens van de offerte, of zoeken op een andere naam.
3. **Locatie** — hij vraagt of het werk op het adres van de klant is of ergens anders; zo nodig maakt hij een locatie aan.
4. **Producten** — elk apparaat op de offerte zoekt hij op in het assortiment. Staat het er niet in, dan maakt hij het aan, met merk en soort erbij als die ook nog niet bestaan. Montagemateriaal dat in de prijs zit, voorrijkosten en voorwaarden worden geen product.
5. **Werkbon** — met het juiste type (installatie, service of gemengd), het offertenummer en de regels en voorwaarden die de monteur moet weten in de omschrijving.
6. **Taken en machines** — per product een taak "Leveren en monteren" met het aantal, allemaal met één klik. De machines die de taak oplevert komen meteen bij de klant én op de taak te staan, met een tijdelijk serienummer (onbekend-1, onbekend-2, …). De monteur ziet ze bij **Machines registreren** op de taak en vult daar het echte serienummer in; zolang er nog een tijdelijk nummer staat telt die machine als niet geregistreerd en kan de taak niet voltooid worden. Bij het voltooien van de taak gaan de machines in dienst: vanaf die dag loopt hun onderhoudstermijn.
7. **Planning** — hij vraagt hoeveel uur werk het is (dat staat niet op een offerte). Daarna zoekt hij monteurs uit de plangroep die bij het product past, en alleen daaruit. Wie het meeste ervaring heeft met dit soort apparaat en dit merk gaat voor: bij een installatie tellen afgeronde taken met dat product het zwaarst, bij servicewerk gesloten storingen en afgetekende machines; afgesloten werkbonnen tellen bij allebei mee. Noemt de offerte een levertijd, dan zoekt hij pas vanaf dan. Je kiest een bezetting met een knop, en de afspraak op de werkbon wijst de monteurs toe. Kan niemand uit de groep, dan vraagt hij of hij ook buiten de groep mag zoeken of binnen de groep verder vooruit.
8. **Onderhoudscontract** — staat er een op de offerte, dan vraagt hij of het aangemaakt moet worden, met de machines uit stap 6.
9. **De offerte zelf** — tot slot vraagt hij of de pdf als document bij de werkbon bewaard moet worden, en in welke categorie.

Ook buiten een offerte geldt bij het plannen: vraag je om een monteur uit een bepaalde groep, dan kijkt de assistent alleen in die groep, en vraagt hij het eerst voordat hij iemand van buiten de groep voorstelt.

### Wat de assistent per maand mag kosten

De assistent kost geld bij de leverancier, dus er zit een tegoed per maand op.
U ziet in het venster hoeveel daarvan op is, en bij tachtig procent verschijnt
een waarschuwing.

Is het tegoed op, dan beantwoordt de assistent geen vragen meer tot de volgende
maand. De rest van Lavoro werkt gewoon door.

Meer nodig? Twee smaken. Structureel meer: het maandtegoed kan verhoogd worden.
Eenmalig iets erbij: er is los tegoed bij te kopen dat niet aan een maand
gebonden is -- het blijft staan tot het op is, en wordt pas aangesproken als het
maandtegoed van dat moment op is. Neem voor beide contact op.
