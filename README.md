# ServisHub

Informační systém pro servis notebooků, počítačů a mobilních zařízení. Pokrývá celý životní cyklus servisní zakázky – od příjmu zařízení přes diagnostiku, schválení zákazníkem, opravu a spotřebu materiálu až po předání a PDF protokoly.

Projekt je referenční ukázkou klasické server-rendered aplikace v **PHP 8.4 a Nette Frameworku** nad **PostgreSQL** – bez SPA a bez Node.js.

---

## Obsah

1. [Funkcionalita](#funkcionalita)
2. [Technologie](#technologie)
3. [Architektura](#architektura)
4. [Instalace a spuštění v Dockeru](#instalace-a-spuštění-v-dockeru)
5. [Migrace a demo data](#migrace-a-demo-data)
6. [Přihlášení a role](#přihlášení-a-role)
7. [Workflow servisní zakázky](#workflow-servisní-zakázky)
8. [Sklad a výpočet cen](#sklad-a-výpočet-cen)
9. [Bezpečnost](#bezpečnost)
10. [Testování a kvalita kódu](#testování-a-kvalita-kódu)
11. [Příprava na deployment](#příprava-na-deployment)

---

## Funkcionalita

| Modul | Co umí |
|---|---|
| **Dashboard** | Aktivní zakázky, dokončené tento měsíc, zakázky po termínu, čekající na díly, hodnota dokončených zakázek, noví zákazníci, průměrná doba opravy, poslední zakázky a aktivity, položky s nízkou zásobou. Grafy: zakázky podle stavu, přijaté/dokončené po měsících, hodnota zakázek za 6 měsíců. Technik vidí statistiky jen svých zakázek. |
| **Zákazníci** | CRUD, fyzické osoby i firmy (IČO s kontrolou kontrolní číslice, DIČ), živé vyhledávání, filtrování, řazení, stránkování, detail s historií zakázek, zařízeními a obratem. |
| **Zařízení** | Evidence zařízení zákazníka (typ, výrobce, model, sériové číslo / IMEI, popis), detail s aktuálními zakázkami a historií oprav a předchozích závad. |
| **Servisní zakázky** | Automatické číslo `SRV-RRRR-NNNN`, příjem, úprava, přiřazení technika, priorita, termín, stavový automat, diagnostika a odhad ceny, výkazy práce, výdej materiálu ze skladu, interní komentáře, časová osa změn, filtry (stav, priorita, technik, po termínu, nepřiřazené) a fulltext. Detail zakázky je pracovní prostor s AJAX akcemi. |
| **Sklad** | Skladové položky (SKU, ceny, minimální zásoba), příjem, výdej k zakázce, vrácení, inventurní úprava, historie pohybů, upozornění na nízký stav, ochrana proti zápornému stavu i při souběhu. |
| **Ceny** | Práce (čas × historická sazba), materiál (historická cena), sleva, základ daně, DPH dle nastavení, celkem – přesná desetinná aritmetika. |
| **PDF** | Příjmový protokol, servisní protokol (po dokončení), cenový rozpis – hlavička firmy, údaje zákazníka a zařízení, práce, materiál, ceny, prostor pro podpis, plná podpora češtiny. |
| **Administrace** | Uživatelé a role, nastavení firmy a sazeb, auditní log se změnami hodnot. |

UI je v češtině, responzivní (sidebar se na mobilu skrývá), s toast notifikacemi, potvrzením destruktivních akcí, empty states, validací formulářů na klientu i serveru a konzistentní ikonografií (Lucide).

## Technologie

| Vrstva | Použito |
|---|---|
| Backend | PHP 8.4, Nette Application 3.3, Nette DI, Nette Database Explorer, Nette Security, Nette Forms, Latte 3, Tracy |
| Databáze | PostgreSQL 17 (cizí klíče, CHECK omezení, částečné indexy, `pg_trgm` pro fulltext) |
| Migrace | [nextras/migrations](https://github.com/nextras/migrations) (SQL migrace, Nette DI + Symfony Console) |
| Finance | `brick/math` – BigDecimal, žádný float |
| PDF | mPDF 8 s fontem DejaVu Sans |
| Konzole | `contributte/console` (Symfony Console) |
| Frontend | Latte šablony, Bootstrap 5.3, vlastní CSS design systém, vanilla JS (fetch API), Chart.js, Lucide Icons |
| Infrastruktura | Docker Compose: Nginx, PHP-FPM, PostgreSQL – s healthchecky |
| Kvalita | PHPUnit 11 (unit + integrační testy nad PostgreSQL), PHPStan level 8 + phpstan-nette, PHP_CodeSniffer (PSR-12), latte-lint |

## Architektura

```
app/
├── Bootstrap.php              sestavení DI kontejneru (web / konzole / testy)
├── Core/                      router, Latte rozšíření (filtry money, hours…), normalizace řádků DB
├── Model/
│   ├── Enum/                  OrderStatus, OrderPriority, UserRole, DeviceType, StockMovementType…
│   ├── Money/Money.php        neměnná peněžní částka nad BigDecimal
│   ├── Validation/            české validátory (IČO, DIČ, telefon)
│   └── Workflow/              OrderStateMachine – graf přechodů a oprávnění rolí
├── Repositories/              veškeré SQL (parametrizované), jedna třída na agregát
├── Services/                  business logika a transakce
│   ├── ServiceOrderService    příjem, úpravy, přiřazení, diagnostika, výkazy, komentáře
│   ├── OrderWorkflowService   změny stavů: zámek → oprávnění → graf → podmínky → efekty → historie → audit
│   ├── InventoryService       příjem, výdej, vrácení, inventura – zamykání řádků
│   ├── Pricing/               PriceCalculator (čistý výpočet) + OrderPricingService (data z DB)
│   ├── Document/              PDF dokumenty (Latte → mPDF)
│   └── …                      CustomerService, DeviceService, UserService, SettingsService, AuditLogger, DashboardService
├── Security/                  Authenticator, ACL (AuthorizatorFactory), OrderAccessPolicy, Actor, PasswordPolicy
├── DTO/                       vstupní data formulářů, filtry, výsledky výpočtů
├── Forms/                     továrny formulářů (CSRF, české hlášky)
├── Components/                OrderTimeline – časová osa zakázky
├── Exceptions/                doménové výjimky s hláškami pro uživatele
├── Console/                   app:seed, app:user:create
└── Presentation/              presentery a Latte šablony (tenké – jen orchestrace)
migrations/                    SQL migrace (structures, basic-data)
tests/                         Unit/ a Integration/
docker/                        Dockerfile, Nginx, PHP a PostgreSQL konfigurace
www/                           veřejný adresář (index.php, CSS, JS)
```

**Principy**

- Presentery neobsahují business logiku ani SQL – volají služby a mapují výjimky na hlášky.
- Služby přijímají `Actor` (kdo operaci provádí) explicitně, takže fungují stejně z webu, konzole i testů a **oprávnění kontrolují samy** – nespoléhají na to, že UI tlačítko skryje.
- Všechny zápisy s více kroky běží v transakci (`Explorer::transaction`, s podporou vnoření).
- Čtení pro šablony používá `ActiveRow` (idiomatické Nette), zápisy jdou přes typovaná DTO.
- Nette Database standardně převádí `NUMERIC` na `float` – vlastní `RowNormalizer` je ponechává jako řetězce pro přesné výpočty.

## Instalace a spuštění v Dockeru

Požadavky: Docker s Docker Compose v2.

```bash
cp .env.example .env        # volitelné – bez .env se použijí lokální výchozí hodnoty
docker compose up --build
```

Aplikace poběží na **http://localhost:8080** (port lze změnit proměnnou `APP_PORT`, např. `APP_PORT=8091 docker compose up --build`).

Při startu PHP kontejner automaticky:
1. nainstaluje Composer závislosti (dev),
2. počká na PostgreSQL,
3. spustí migrace,
4. v dev prostředí naplní demo data, pokud je databáze prázdná (`SEED_DEMO_DATA=1`).

| Služba | Popis |
|---|---|
| `nginx` | webový server, healthcheck `/nginx-health` |
| `php` | PHP 8.4-FPM (pdo_pgsql, intl, gd, bcmath, opcache), healthcheck přes FPM ping |
| `database` | PostgreSQL 17, při první inicializaci vytvoří i testovací databázi `<DB_NAME>_test`; z hostitele dostupná na portu `54320` |

`vendor/` a `temp/` jsou v pojmenovaných volumes (na Docker Desktopu je to řádově rychlejší než bind mount). Užitečné příkazy:

```bash
docker compose exec php php bin/console list          # všechny příkazy
docker compose exec php composer qa                   # coding standard + PHPStan + testy
docker compose down -v                                # smazat i databázi
```

## Migrace a demo data

```bash
docker compose exec php php bin/console migrations:continue   # spustí nové migrace
docker compose exec php php bin/console migrations:create structures popis   # nová migrace
docker compose exec php php bin/console app:seed --force      # přegeneruje demo data (smaže stávající!)
```

Demo data jsou deterministická (pevný seed generátoru) a konzistentní:

- 3 uživatelé (administrátor, vedoucí servisu, technik), 30 zákazníků (osoby i firmy s platným IČO), 45 zařízení, 40 skladových položek, 70 zakázek za posledních ~7 měsíců,
- historie stavů prochází skutečným grafem workflow, výkazy práce a materiál odpovídají stavu zakázky,
- finální ceny počítá stejný `PriceCalculator` jako aplikace; starší zakázky mají nižší historickou sazbu (790 Kč/h) i ceny dílů – ukázka, že změna ceníku neovlivní uzavřené zakázky,
- stav skladu přesně odpovídá součtu skladových pohybů, část položek je pod minimální zásobou.

Příkaz `app:seed` **odmítne běžet v produkci** (`APP_ENV=production`) a demo účty se nikdy nevytvářejí automaticky mimo dev prostředí.

## Přihlášení a role

Demo přístupy (pouze lokálně):

| Role | E-mail | Heslo |
|---|---|---|
| Administrátor | `admin@servishub.local` | `Demo1234!` |
| Vedoucí servisu | `vedouci@servishub.local` | `Demo1234!` |
| Technik | `technik@servishub.local` | `Demo1234!` |

Heslo demo účtů se nastavuje proměnnou `DEMO_PASSWORD`.

| | Technik | Vedoucí servisu | Administrátor |
|---|:---:|:---:|:---:|
| Dashboard | jen své zakázky | ✓ | ✓ |
| Zakázky | jen přiřazené | všechny | všechny |
| Diagnostika, výkazy práce, výdej materiálu, komentáře | ✓ (přiřazené) | ✓ | ✓ |
| Přechody stavů | technické (diagnostika, oprava, dokončení…) | všechny | všechny |
| Příjem a úprava zakázek, přiřazení technika, slevy | – | ✓ | ✓ |
| Zákazníci a zařízení | – | ✓ | ✓ |
| Sklad | prohlížení | správa, příjem, inventura | ✓ |
| PDF dokumenty | přiřazené zakázky | ✓ | ✓ |
| Uživatelé, nastavení, audit log | – | – | ✓ |

Oprávnění jsou vynucena na třech úrovních: ACL na presenterech a akcích (atribut `#[RequirePermission]`), oprávnění na konkrétní záznam (`OrderAccessPolicy` – technik a cizí zakázka = 403) a kontroly ve službách.

## Workflow servisní zakázky

```
Přijato ─► Diagnostika ─► Čeká na schválení ─► V opravě ◄──► Čeká na díly
                │                                   │  ▲
                └──────── (vedoucí: rovnou) ───────►│  │ (vedoucí: reklamace, s důvodem)
                                                    ▼  │
                              Předáno zákazníkovi ◄── Dokončeno
Zrušit lze každou rozpracovanou zakázku (pouze vedoucí, s povinným důvodem).
Předáno a Zrušeno jsou konečné stavy.
```

| Přechod | Kdo | Podmínky / efekty |
|---|---|---|
| → Diagnostika | technik, vedoucí | zakázka musí mít přiřazeného technika |
| → Čeká na schválení | technik, vedoucí | vyplněná diagnostika a odhad ceny |
| Čeká na schválení → V opravě | vedoucí | záznam schválení zákazníkem (poznámka) |
| Diagnostika → V opravě | vedoucí | vyplněná diagnostika (předem schválený limit, záruka) |
| V opravě ⇄ Čeká na díly | technik, vedoucí | – |
| → Dokončeno | technik, vedoucí | diagnostika + alespoň jeden výkaz práce; **zafixuje finální cenu** |
| Dokončeno → V opravě | vedoucí | povinný důvod; finální cena se zruší |
| → Předáno zákazníkovi | vedoucí | jen z Dokončeno; uloží čas předání |
| → Zrušeno | vedoucí | povinný důvod; **vydaný materiál se automaticky vrátí na sklad** |

Graf a role definuje `OrderStateMachine`, podmínky a vedlejší efekty `OrderWorkflowService`. Každá změna proběhne v jedné transakci se zámkem řádku zakázky a zapíše se do historie stavů i auditního logu. UI zobrazuje jen přechody dostupné dané roli a u blokovaných uvádí nesplněné podmínky – backend je ale vždy kontroluje znovu.

## Sklad a výpočet cen

**Ochrana skladu** (`InventoryService`):
- zamykání řádku `SELECT … FOR UPDATE` v transakci, pevné pořadí zámků (zakázka → položka) proti deadlockům,
- odpis podmíněným `UPDATE … SET quantity = quantity - ? WHERE quantity >= ? RETURNING quantity`,
- databázové `CHECK (quantity >= 0)` jako poslední pojistka,
- každý pohyb ukládá i stav po pohybu; součet pohybů vždy odpovídá skladu (ověřeno testem).

**Výpočet ceny** (`PriceCalculator`):

```
práce      = Σ (minuty × hodinová sazba výkazu / 60)     zaokrouhleno na haléře
materiál   = Σ (množství × cena při výdeji)
mezisoučet = práce + materiál
sleva      = mezisoučet × sleva %
základ     = mezisoučet − sleva
DPH        = základ × sazba DPH (z nastavení, uložená u zakázky)
celkem     = základ + DPH
```

Výpočty používají `BigDecimal` se zaokrouhlením HALF_UP. Výkaz práce si ukládá hodinovou sazbu a výdej materiálu prodejní i nákupní cenu – pozdější změna ceníku neovlivní existující zakázky. Ceny v databázi jsou `NUMERIC(12,2)` bez DPH, odhad a finální cena zakázky jsou vč. DPH.

## Bezpečnost

- **CSRF:** všechny formuláře mají token (`addProtection`); signály i formuláře navíc kontrolují same-origin (`Sec-Fetch-Site`), destruktivní signály vyžadují POST.
- **XSS:** kontextové escapování Latte, přísná **Content Security Policy** s nonce pro skripty.
- **SQL injection:** výhradně parametrizované dotazy; SQL řetězce typované jako `literal-string` (PHPStan).
- **Hesla:** bcrypt (cost 12) s automatickým přehashováním, politika síly hesla, zablokování účtu na 15 min po 5 neúspěšných pokusech, hlášky neprozrazují existenci účtu, vyrovnání doby odezvy.
- **Session:** HttpOnly, SameSite=Lax, strict mode, regenerace ID po přihlášení a odhlášení, expirace 8 h; identita se při každém požadavku načítá z DB (deaktivace účtu nebo změna role se projeví okamžitě).
- **Error handling:** v produkci obecné chybové stránky, detaily jen v logu (Tracy).
- **Audit:** přihlášení, změny zakázek (s původními a novými hodnotami), stavy, sklad, uživatelé, nastavení, generované dokumenty – včetně uživatele a IP.
- **Konfigurace:** žádná tajemství v kódu – vše přes proměnné prostředí (`.env`, není v gitu).

## Testování a kvalita kódu

```bash
docker compose exec php composer test               # všechny testy
docker compose exec php composer test:unit          # jen unit testy
docker compose exec php composer test:integration   # integrační testy (PostgreSQL)
docker compose exec php composer phpstan            # PHPStan level 8
docker compose exec php composer cs                 # PSR-12 (cs:fix opraví automaticky)
docker compose exec php composer qa                 # vše dohromady
```

Stejné kontroly spouští **GitHub Actions** ([.github/workflows/ci.yml](.github/workflows/ci.yml)) při každém pushi a pull requestu – coding standard a PHPStan v jednom jobu, PHPUnit proti PostgreSQL 17 ve druhém.

Integrační testy běží proti samostatné databázi `servishub_test`, jejíž schéma se před během vytvoří z migrací (stejná cesta jako produkce); každý test začíná s prázdnými tabulkami.

Pokryto mimo jiné:

| Oblast | Testy |
|---|---|
| Výpočet ceny | rozpis, zaokrouhlení, sleva, DPH, ochrana proti float chybám, neplatné sazby |
| Workflow | povolené a zakázané přechody pro každou roli, konečné stavy, podmínky přechodů, transakční rollback, historie, finální cena, reklamace |
| Oprávnění | ACL všech rolí, technik a cizí zakázka (stav, materiál, výkazy), technik nesmí zakládat zakázky ani spravovat sklad |
| Sklad | příjem, výdej, vrácení, inventura, **nelze vydat více, než je skladem**, DB constraint proti zápornému stavu, součet pohybů = stav, historické ceny |
| Zakázky | validace vstupu, zařízení musí patřit zákazníkovi, **unikátní a postupná čísla zakázek** (i přes rok), DB unikátní omezení |
| Přihlášení | zablokování po opakovaných pokusech, role z databáze |

Aktuální stav: **107 testů / 296 asercí – OK, PHPStan level 8 – 0 chyb, PHP_CodeSniffer PSR-12 – 0 chyb.**

## Příprava na deployment

Dockerfile obsahuje produkční stage `prod`, která zabalí kód a závislosti bez dev balíčků do image a vypne kontrolu změn souborů v OPcache:

```bash
docker build -f docker/php/Dockerfile --target prod -t servishub-php:prod .
```

Kontrolní seznam pro produkci:

1. `APP_ENV=production`, `APP_DEBUG=0`, silné `DB_PASSWORD`, `SEED_DEMO_DATA=0`.
2. HTTPS na reverzní proxy / load balanceru (session cookie je v produkci `Secure`; při proxy nastavte `http.proxy` v `config/local.neon`).
3. Nginx musí obsluhovat obsah `www/` z produkčního image (např. sdíleným volume nebo samostatným image s assety).
4. Migrace se spouští automaticky při startu kontejneru (`migrations:continue`), případně ručně v deploy pipeline.
5. První administrátor: `php bin/console app:user:create admin@firma.cz` (heslo se zadá interaktivně, nikde se neukládá).
6. Zálohování PostgreSQL (`pg_dump`) a rotace logů v `log/`.
7. Lokální přepisy konfigurace patří do `config/local.neon` (není v gitu).
