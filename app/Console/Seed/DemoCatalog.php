<?php

declare(strict_types=1);

namespace App\Console\Seed;

/**
 * Statický katalog realistických demo dat (jména, zařízení, díly, závady).
 */
final class DemoCatalog
{
    public const array FIRST_NAMES_M = ['Jan', 'Petr', 'Tomáš', 'Jakub', 'Lukáš', 'Martin', 'Ondřej', 'David', 'Michal', 'Filip', 'Vojtěch', 'Adam', 'Marek', 'Roman', 'Jiří'];
    public const array FIRST_NAMES_F = ['Eva', 'Lucie', 'Kateřina', 'Tereza', 'Veronika', 'Martina', 'Barbora', 'Klára', 'Markéta', 'Jana', 'Lenka', 'Petra', 'Simona', 'Alena', 'Hana'];
    public const array LAST_NAMES_M = ['Novák', 'Svoboda', 'Novotný', 'Dvořák', 'Černý', 'Procházka', 'Kučera', 'Veselý', 'Horák', 'Němec', 'Pokorný', 'Marek', 'Pospíšil', 'Hájek', 'Jelínek', 'Růžička', 'Beneš', 'Fiala', 'Sedláček', 'Doležal'];
    public const array LAST_NAMES_F = ['Nováková', 'Svobodová', 'Novotná', 'Dvořáková', 'Černá', 'Procházková', 'Kučerová', 'Veselá', 'Horáková', 'Němcová', 'Pokorná', 'Marková', 'Pospíšilová', 'Hájková', 'Jelínková', 'Růžičková', 'Benešová', 'Fialová', 'Sedláčková', 'Doležalová'];

    public const array COMPANIES = [
        'Advokátní kancelář Brázda & partneři', 'Účetnictví Krátký s.r.o.', 'Stavební firma Kámen a.s.', 'Kavárna U Tří lip',
        'Projekce Dvořák s.r.o.', 'Zubní klinika Úsměv s.r.o.', 'Realitní kancelář Domov', 'Autoservis Rychlý s.r.o.',
        'Grafické studio Pixel', 'Základní škola Komenského',
    ];

    /** @var list<array{0: string, 1: string, 2: string}> ulice, město, PSČ */
    public const array ADDRESSES = [
        ['Kounicova 12', 'Brno', '602 00'], ['Lidická 45', 'Brno', '602 00'], ['Údolní 8', 'Brno', '602 00'],
        ['Masarykova 103', 'Brno', '602 00'], ['Palackého třída 22', 'Brno', '612 00'], ['Křenová 61', 'Brno', '602 00'],
        ['Vinohradská 30', 'Praha', '120 00'], ['Na Poříčí 17', 'Praha', '110 00'], ['Bělehradská 88', 'Praha', '120 00'],
        ['Hlavní 14', 'Blansko', '678 01'], ['Komenského 5', 'Vyškov', '682 01'], ['Nádražní 230', 'Kuřim', '664 34'],
        ['Tišnovská 77', 'Tišnov', '666 01'], ['Masarykovo nám. 3', 'Jihlava', '586 01'], ['Pražská 19', 'Olomouc', '779 00'],
        ['Zámecká 6', 'Boskovice', '680 01'], ['Brněnská 41', 'Šlapanice', '664 51'], ['Sportovní 9', 'Modřice', '664 42'],
    ];

    /** @var array<string, list<array{0: string, 1: string}>> typ => [výrobce, model] */
    public const array DEVICES = [
        'laptop' => [
            ['Lenovo', 'ThinkPad T14 Gen 3'], ['Lenovo', 'IdeaPad 5 14ALC05'], ['Dell', 'Latitude 5440'], ['Dell', 'XPS 13 9315'],
            ['HP', 'EliteBook 840 G9'], ['HP', 'ProBook 450 G8'], ['Apple', 'MacBook Air 13" M2'], ['Apple', 'MacBook Pro 14" M1 Pro'],
            ['Asus', 'ZenBook 14 UX3402'], ['Acer', 'Aspire 5 A515-57'], ['MSI', 'Katana GF66'],
        ],
        'phone' => [
            ['Apple', 'iPhone 13'], ['Apple', 'iPhone 12 mini'], ['Apple', 'iPhone 14 Pro'], ['Samsung', 'Galaxy S23'],
            ['Samsung', 'Galaxy A54 5G'], ['Xiaomi', 'Redmi Note 12 Pro'], ['Google', 'Pixel 7'], ['Motorola', 'Moto G84'],
        ],
        'tablet' => [['Apple', 'iPad Air 5. gen.'], ['Samsung', 'Galaxy Tab S8'], ['Lenovo', 'Tab P11 Pro']],
        'desktop' => [['Dell', 'OptiPlex 7010 SFF'], ['HP', 'ProDesk 400 G7'], ['Lenovo', 'ThinkCentre M70q'], ['Vlastní sestava', 'Ryzen 7 / RTX 3070']],
        'monitor' => [['Dell', 'P2422H'], ['LG', '27UL850-W']],
        'printer' => [['Brother', 'HL-L2350DW'], ['HP', 'LaserJet Pro M404dn']],
    ];

    /**
     * Skladové položky: SKU, název, popis, nákup, prodej, kategorie (pro výběr k zařízení).
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    public const array INVENTORY = [
        ['SSD-SAM-500', 'SSD Samsung 980 500 GB NVMe', 'M.2 2280, PCIe 3.0', '1190.00', '1690.00', 'laptop'],
        ['SSD-SAM-1TB', 'SSD Samsung 980 PRO 1 TB NVMe', 'M.2 2280, PCIe 4.0', '2290.00', '3190.00', 'laptop'],
        ['SSD-KIN-480', 'SSD Kingston A400 480 GB SATA', '2,5", SATA III', '790.00', '1190.00', 'desktop'],
        ['RAM-DDR4-8', 'RAM SO-DIMM DDR4 8 GB 3200 MHz', 'Kingston FURY Impact', '549.00', '849.00', 'laptop'],
        ['RAM-DDR4-16', 'RAM SO-DIMM DDR4 16 GB 3200 MHz', 'Crucial', '990.00', '1490.00', 'laptop'],
        ['RAM-DDR4-D16', 'RAM DIMM DDR4 16 GB 3200 MHz', 'Pro stolní PC', '890.00', '1290.00', 'desktop'],
        ['BAT-LEN-T14', 'Baterie Lenovo ThinkPad T14', 'Originální, 57 Wh', '1650.00', '2490.00', 'laptop'],
        ['BAT-DELL-54', 'Baterie Dell Latitude 54xx', '4článková, 54 Wh', '1390.00', '2190.00', 'laptop'],
        ['BAT-HP-840', 'Baterie HP EliteBook 840', 'HT03XL kompatibilní', '1190.00', '1890.00', 'laptop'],
        ['BAT-MBA-M2', 'Baterie MacBook Air M2', 'A2681, servisní díl', '2890.00', '4290.00', 'laptop'],
        ['LCD-14-FHD', 'Displej 14" FHD IPS 30pin', 'Matný, 1920×1080', '2190.00', '3490.00', 'laptop'],
        ['LCD-156-FHD', 'Displej 15,6" FHD IPS 30pin', 'Matný, 1920×1080', '1990.00', '3190.00', 'laptop'],
        ['KBD-LEN-T14', 'Klávesnice Lenovo T14 CZ podsvícená', 'CZ/SK layout', '1290.00', '1990.00', 'laptop'],
        ['KBD-HP-450', 'Klávesnice HP ProBook 450 CZ', 'CZ/SK layout', '890.00', '1490.00', 'laptop'],
        ['FAN-LAP-UNI', 'Ventilátor chlazení notebooku', 'Univerzální 5 V', '390.00', '690.00', 'laptop'],
        ['PASTE-MX4', 'Teplovodivá pasta Arctic MX-4 4 g', 'Spotřební materiál', '149.00', '290.00', 'any'],
        ['PAD-THERM', 'Teplovodivé podložky 1 mm', 'Sada 4 ks', '120.00', '250.00', 'laptop'],
        ['CHG-USBC-65', 'Nabíječka USB-C 65 W PD', 'Univerzální', '690.00', '1190.00', 'laptop'],
        ['CHG-LEN-90', 'Adaptér Lenovo 90 W slim tip', 'Originální', '890.00', '1390.00', 'laptop'],
        ['DCJ-UNI', 'Napájecí konektor DC-IN', 'Pro notebooky, s kabelem', '190.00', '390.00', 'laptop'],
        ['SCR-IP13', 'Displej iPhone 13 OLED', 'Servisní díl, True Tone', '3290.00', '4990.00', 'phone'],
        ['SCR-IP12M', 'Displej iPhone 12 mini OLED', 'Servisní díl', '2690.00', '3990.00', 'phone'],
        ['SCR-IP14P', 'Displej iPhone 14 Pro OLED', 'Servisní díl', '5490.00', '7990.00', 'phone'],
        ['SCR-S23', 'Displej Samsung Galaxy S23 AMOLED', 'Service Pack s rámečkem', '3890.00', '5690.00', 'phone'],
        ['SCR-A54', 'Displej Samsung Galaxy A54 AMOLED', 'Service Pack', '2190.00', '3290.00', 'phone'],
        ['SCR-RN12', 'Displej Xiaomi Redmi Note 12 Pro', 'AMOLED s rámečkem', '1690.00', '2590.00', 'phone'],
        ['BAT-IP13', 'Baterie iPhone 13', '3227 mAh', '690.00', '1290.00', 'phone'],
        ['BAT-IP12M', 'Baterie iPhone 12 mini', '2227 mAh', '590.00', '1190.00', 'phone'],
        ['BAT-S23', 'Baterie Samsung Galaxy S23', 'EB-BS912ABY', '690.00', '1290.00', 'phone'],
        ['BAT-PIX7', 'Baterie Google Pixel 7', 'GLU7G', '790.00', '1390.00', 'phone'],
        ['USB-C-PORT', 'Nabíjecí konektor USB-C (flex)', 'Pro telefony Samsung/Xiaomi', '290.00', '590.00', 'phone'],
        ['LTN-PORT-IP', 'Nabíjecí konektor Lightning (flex)', 'iPhone 12/13', '390.00', '790.00', 'phone'],
        ['GLASS-REAR', 'Zadní sklo telefonu', 'Univerzální výřez', '290.00', '690.00', 'phone'],
        ['ADH-SET', 'Lepicí páska pro displeje', 'Sada předřezaných pásek', '59.00', '150.00', 'phone'],
        ['SCR-IPAD-A5', 'Digitizér iPad Air 5', 'Dotyková vrstva', '2490.00', '3790.00', 'tablet'],
        ['BAT-TABS8', 'Baterie Samsung Galaxy Tab S8', '8000 mAh', '990.00', '1690.00', 'tablet'],
        ['PSU-500W', 'Zdroj ATX 500 W 80+ Bronze', 'Seasonic', '1390.00', '1990.00', 'desktop'],
        ['FAN-120', 'Ventilátor 120 mm PWM', 'Arctic P12', '149.00', '290.00', 'desktop'],
        ['TONER-TN2420', 'Toner Brother TN-2420', 'Kompatibilní, 3000 stran', '490.00', '890.00', 'printer'],
        ['FUSER-M404', 'Fixační jednotka HP M404', 'RM2-5399', '2490.00', '3690.00', 'printer'],
    ];

    /**
     * Typické závady podle typu zařízení: [popis závady, diagnostika, práce, kategorie dílu|null]
     *
     * @var array<string, list<array{0: string, 1: string, 2: list<array{0: string, 1: int}>, 3: list<string>}>>
     */
    public const array PROBLEMS = [
        'laptop' => [
            ['Notebook se výrazně přehřívá a při zátěži se vypíná.', 'Zanesený chladič prachem, vyschlá teplovodivá pasta. Ventilátor hlučný, ložisko opotřebené.', [['Rozebrání a vyčištění chlazení', 60], ['Výměna teplovodivé pasty a podložek', 30], ['Zátěžový test 30 min', 30]], ['PASTE-MX4', 'PAD-THERM', 'FAN-LAP-UNI']],
            ['Pomalý start systému, časté zamrzání, disk hlásí chyby.', 'SMART disku hlásí realokované sektory – disk na konci životnosti. Doporučena výměna za SSD a přenos dat.', [['Diagnostika disku (SMART, povrchový test)', 30], ['Výměna disku za SSD', 30], ['Klonování systému a dat', 90]], ['SSD-SAM-500', 'SSD-SAM-1TB']],
            ['Baterie vydrží jen 20 minut, notebook se vypíná na 30 %.', 'Kapacita baterie 41 % původní hodnoty, 900+ cyklů. Výměna baterie.', [['Test kapacity baterie', 20], ['Výměna baterie a kalibrace', 40]], ['BAT-LEN-T14', 'BAT-DELL-54', 'BAT-HP-840', 'BAT-MBA-M2']],
            ['Prasklý displej po pádu, obraz zobrazuje pruhy.', 'Mechanicky poškozený LCD panel, kabel displeje v pořádku. Nutná výměna panelu.', [['Demontáž rámečku a výměna LCD panelu', 60], ['Test obrazu a podsvícení', 15]], ['LCD-14-FHD', 'LCD-156-FHD']],
            ['Polité klávesnice kávou, některé klávesy nefungují.', 'Zatečení pod klávesnici, oxidace kontaktů. Základní deska nepoškozena. Výměna klávesnice, vyčištění.', [['Rozebrání a ultrazvukové čištění', 60], ['Výměna klávesnice', 45]], ['KBD-LEN-T14', 'KBD-HP-450']],
            ['Notebook nenabíjí, konektor napájení se viklá.', 'Vylomený napájecí konektor DC-IN, poškozený kabel. Výměna konektoru.', [['Demontáž a výměna DC konektoru', 75]], ['DCJ-UNI', 'CHG-USBC-65', 'CHG-LEN-90']],
            ['Požadavek na rozšíření paměti a zrychlení počítače.', 'Osazeno 8 GB RAM, volný slot. Doporučeno doplnit na 16/24 GB a SSD.', [['Instalace paměti a test stability', 30], ['Optimalizace systému', 45]], ['RAM-DDR4-8', 'RAM-DDR4-16']],
            ['Systém nenabootuje, modrá obrazovka po aktualizaci.', 'Poškozený zavaděč systému po přerušené aktualizaci. Hardware v pořádku.', [['Diagnostika a záloha dat', 45], ['Oprava zavaděče a reinstalace ovladačů', 60]], []],
        ],
        'phone' => [
            ['Rozbitý displej, dotyk nereaguje ve spodní části.', 'Prasklý OLED panel včetně dotykové vrstvy. Rám nepoškozen. Výměna displeje.', [['Výměna displeje', 45], ['Test dotyku, Face ID / senzorů', 15]], ['SCR-IP13', 'SCR-IP12M', 'SCR-IP14P', 'SCR-S23', 'SCR-A54', 'SCR-RN12', 'ADH-SET']],
            ['Telefon se rychle vybíjí a sám se vypíná.', 'Stav baterie 72 %, baterie nafouklá. Nutná výměna z bezpečnostních důvodů.', [['Výměna baterie', 30], ['Kalibrace a test nabíjení', 15]], ['BAT-IP13', 'BAT-IP12M', 'BAT-S23', 'BAT-PIX7', 'ADH-SET']],
            ['Telefon nenabíjí, kabel je nutné držet v určité poloze.', 'Opotřebený nabíjecí konektor, zanesený nečistotami. Výměna flexu konektoru.', [['Výměna nabíjecího konektoru', 45]], ['USB-C-PORT', 'LTN-PORT-IP']],
            ['Prasklé zadní sklo po pádu.', 'Poškozené zadní sklo, kamera a cívka bezdrátového nabíjení v pořádku.', [['Odstranění skla laserem a výměna', 60]], ['GLASS-REAR', 'ADH-SET']],
            ['Telefon spadl do vody, nejde zapnout.', 'Oxidace na základní desce v oblasti napájení. Provedeno čištění, deska oživena, baterie poškozena.', [['Rozebrání a ultrazvukové čištění desky', 60], ['Diagnostika napájení', 30]], ['BAT-IP13', 'BAT-S23']],
        ],
        'tablet' => [
            ['Prasklé dotykové sklo tabletu.', 'Poškozený digitizér, LCD funkční. Výměna dotykové vrstvy.', [['Výměna digitizéru', 90]], ['SCR-IPAD-A5', 'ADH-SET']],
            ['Tablet se nenabíjí nad 60 % a hřeje se.', 'Degradovaná baterie, nabíjecí obvod v pořádku.', [['Výměna baterie', 60]], ['BAT-TABS8']],
        ],
        'desktop' => [
            ['Počítač se náhodně restartuje při hraní her.', 'Nestabilní zdroj – zvlnění napětí 12 V mimo toleranci. Výměna zdroje.', [['Měření zdroje a zátěžový test', 45], ['Výměna zdroje a kabeláže', 30]], ['PSU-500W', 'FAN-120']],
            ['Pomalý počítač, potřeba rozšířit paměť a disk.', 'HDD 5400 ot./min, 8 GB RAM. Doporučeno SSD + 16 GB RAM.', [['Instalace SSD a RAM', 30], ['Migrace systému', 60]], ['SSD-KIN-480', 'RAM-DDR4-D16']],
            ['Hlučný počítač, ventilátory jedou na plné otáčky.', 'Zanesené filtry a chladič CPU, vadný ložiskový ventilátor skříně.', [['Čištění a výměna ventilátoru', 45]], ['FAN-120', 'PASTE-MX4']],
        ],
        'monitor' => [
            ['Monitor bliká a po chvíli zhasne.', 'Vadné kondenzátory ve zdroji monitoru. Oprava osazením nových součástek.', [['Diagnostika a oprava zdroje', 90]], []],
        ],
        'printer' => [
            ['Tiskárna tiskne šmouhy a mačká papír.', 'Opotřebená fixační jednotka, znečištěná dráha papíru.', [['Výměna fixační jednotky a čištění', 60]], ['FUSER-M404', 'TONER-TN2420']],
            ['Tiskárna hlásí chybu toneru i po výměně.', 'Znečištěné kontakty čipu, vadná kazeta. Výměna toneru, reset čítače.', [['Čištění kontaktů a reset', 30]], ['TONER-TN2420']],
        ],
    ];

    public const array COMMENTS = [
        'Zákazník volal, ptal se na stav opravy – informován o průběhu.',
        'Díl objednán u dodavatele, předpokládané dodání do 3 pracovních dnů.',
        'Zákazník souhlasí s výměnou, prosí o co nejrychlejší vyřízení.',
        'Pozor: zákazník má na disku důležitá data, před zásahem provést zálohu.',
        'Zákazníkovi odeslána SMS s cenovou nabídkou.',
        'Zařízení má kromě nahlášené závady také poškrábaný kryt – zákazník informován.',
        'Domluveno vyzvednutí na pátek odpoledne.',
        'Firemní zákazník – fakturovat na IČO, kontaktní osoba je uvedena v kartě.',
        'Po výměně proveden 24h zátěžový test, bez chyb.',
        'Zákazník požaduje vrácení původních dílů.',
    ];

    public const array CANCEL_REASONS = [
        'Zákazník odmítl cenovou nabídku – oprava je neekonomická.',
        'Zákazník si zařízení vyzvedl bez opravy, pořídí nové.',
        'Náhradní díl již není dostupný, zákazník souhlasil se zrušením.',
    ];
}
