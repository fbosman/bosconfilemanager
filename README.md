# BosConfilemanager

Dolibarr-module met een **bestandsverkenner voor de Dolibarr-installatie**. De root is altijd de Dolibarr-root
(`DOL_DOCUMENT_ROOT`, de map `htdocs`). Je kunt bestanden bekijken, bewerken, uploaden en downloaden.

- Versie: 1.0.0
- Dolibarr 18 t/m 22, PHP 7.4 of hoger
- Modulenummer 450170, technische naam `bosconfilemanager`, klasse `modBosConfilemanager`

## Functies

- **Verkenner** met een mappenboom links (submappen laden pas als je ze openklapt) en de bestandslijst rechts:
  naam, grootte, wijzigingsdatum en rechten, sorteerbaar, met een filter op naam en een kruimelpad.
- **Bekijken** van tekstbestanden met syntax highlighting (ACE-editor, wordt met Dolibarr meegeleverd),
  voorbeeld van afbeeldingen en PDF's.
- **Bewerken** van tekstbestanden (Ctrl+S om op te slaan). Regeleinden (LF/CRLF) en ISO-8859-1-bestanden
  blijven zoals ze waren.
  - **PHP-syntaxcontrole**: een PHP-bestand met een syntaxfout wordt niet opgeslagen tenzij je dat bevestigt,
    zodat een tikfout Dolibarr niet onbereikbaar maakt.
  - **Conflictcontrole**: is het bestand gewijzigd sinds je het opende, dan wordt niet zomaar overschreven.
  - De PHP-opcache van het bestand wordt na opslaan geleegd.
- **Uploaden** van meerdere bestanden tegelijk, ook door ze naar de lijst te slepen; optioneel overschrijven.
- **Downloaden** van bestanden, en van mappen als ZIP.
- **Map of bestand aanmaken, hernoemen en verwijderen** (mappen met de volledige inhoud, na bevestiging).
- **Backups**: voordat een bestand wordt opgeslagen, overschreven door een upload, teruggezet of verwijderd,
  komt er een kopie in `documents/bosconfilemanager/backup/<pad>.<JJJJMMDDUUMMSS>.bak`. In de editor zie je de
  backups van het bestand en kun je ze downloaden, terugzetten of verwijderen. Van verwijderde mappen wordt geen
  backup gemaakt.

## Toegang

Alleen **Dolibarr-beheerders** (admin) die ook de rechten van de module hebben:

| Recht | Id | Wat |
| --- | --- | --- |
| `read` | 45017001 | Bladeren, bekijken en downloaden |
| `write` | 45017002 | Uploaden, bewerken, aanmaken en hernoemen |
| `delete` | 45017003 | Verwijderen (ook backups) |

Wie de module activeert krijgt de rechten automatisch. Het menu staat onder **Home > Beheertools >
Bestandsbeheer** (`/custom/bosconfilemanager/explorer.php`).

> Let op: wie bestanden in de Dolibarr-root kan bewerken of uploaden, kan willekeurige PHP-code op de server
> uitvoeren. Geef de rechten `write` en `delete` alleen aan beheerders die je daarmee vertrouwt.

## Beveiliging

- Elk pad wordt met `realpath()` opgelost en moet binnen de Dolibarr-root liggen; `../` en symbolische
  links naar buiten de root worden geweigerd. Symbolische links in de root worden bij verwijderen en hernoemen
  als link behandeld (het doel blijft staan) en worden niet in ZIP-bestanden opgenomen.
- Alle acties die iets wijzigen gaan via een formulier met CSRF-token (ook bevestigd door de module zelf).
- Afbeeldingen (ook SVG) worden met `Content-Security-Policy: sandbox` geserveerd, zodat ze geen scripts in
  Dolibarr kunnen uitvoeren; andere bestanden worden altijd als download aangeboden.
- Alleen het veld met de bestandsinhoud wordt uitgezonderd van de SQL/script-injectiecontrole van Dolibarr
  (anders kun je geen HTML/JS/SQL bewerken).
- Elke wijziging wordt gelogd in de Dolibarr-syslog (niveau NOTICE) met de login van de gebruiker.

## Instellingen

Home > Instellingen > Modules > BosConfilemanager:

- Backup maken bij opslaan, overschrijven en verwijderen (standaard aan)
- Verborgen bestanden tonen (standaard aan)
- PHP-syntax controleren bij opslaan (standaard aan)
- Maximale grootte om te bewerken (standaard 2048 KB)
- Thema van de editor
- Aantal en grootte van de backups, met een knop om alle backups te verwijderen

## Installatie

Kopieer de map naar `htdocs/custom/bosconfilemanager` (of upload de ZIP `module_bosconfilemanager-1.0.0.zip`
via Home > Instellingen > Modules > Externe module installeren) en activeer de module. De webserver moet
schrijfrechten hebben op de bestanden die je wilt bewerken; de verkenner toont welke bestanden en mappen
alleen-lezen zijn.
