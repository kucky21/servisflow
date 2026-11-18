<?php

declare(strict_types=1);

namespace App\Console\Seed;

use App\DTO\Pricing\LaborLine;
use App\DTO\Pricing\MaterialLine;
use App\Model\Enum\OrderPriority;
use App\Model\Enum\OrderStatus;
use App\Model\Enum\UserRole;
use App\Model\Money\Money;
use App\Services\OrderNumberGenerator;
use App\Services\Pricing\PriceCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Nette\Database\Explorer;
use Nette\Security\Passwords;
use Nette\Utils\Json;
use Nette\Utils\Strings;

/**
 * Deterministická demo data pro lokální ukázku.
 *
 * Historie zakázek prochází skutečným grafem workflow, ceny se počítají stejným
 * kalkulátorem jako v aplikaci a skladové množství odpovídá součtu pohybů.
 * Spouští se výhradně mimo produkci (kontroluje SeedCommand).
 */
final class DemoDataSeeder
{
    private const int CUSTOMERS = 30;
    private const int DEVICES = 45;
    private const int ORDERS = 70;
    private const int RANDOM_SEED = 20_261_118;
    private const string RATE_CHANGE_DATE = '2026-07-01';

    private \DateTimeImmutable $now;

    /** @var array<string, int> role => user id */
    private array $users = [];

    /** @var array<string, array{id: int, sku: string, name: string, unit: string, purchase: string, sale: string, category: string}> */
    private array $items = [];

    /** @var array<int, int> item id => aktuální stav */
    private array $stock = [];

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $demoPassword,
        private readonly Explorer $db,
        private readonly Passwords $passwords,
        private readonly PriceCalculator $calculator,
    ) {
    }

    public function isDatabaseEmpty(): bool
    {
        return $this->db->table('users')->count('*') === 0;
    }

    /**
     * @return array<string, int> Počty vytvořených záznamů
     */
    public function seed(): array
    {
        if ($this->demoPassword === '') {
            throw new \RuntimeException('Proměnná DEMO_PASSWORD není nastavena.');
        }

        mt_srand(self::RANDOM_SEED);
        $this->now = new \DateTimeImmutable('now');

        return $this->db->transaction(function (): array {
            $this->truncate();
            $this->seedUsers();
            $devices = $this->seedCustomersAndDevices();
            $this->seedInventory();
            $orders = $this->seedOrders($devices);
            $this->finalizeStock();

            $this->db->table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'system.demo_seeded',
                'entity_type' => 'system',
                'changes' => Json::encode(['orders' => $orders]),
            ]);

            return [
                'users' => $this->db->table('users')->count('*'),
                'customers' => $this->db->table('customers')->count('*'),
                'devices' => $this->db->table('devices')->count('*'),
                'service_orders' => $this->db->table('service_orders')->count('*'),
                'inventory_items' => $this->db->table('inventory_items')->count('*'),
                'stock_movements' => $this->db->table('stock_movements')->count('*'),
                'work_logs' => $this->db->table('work_logs')->count('*'),
                'service_order_materials' => $this->db->table('service_order_materials')->count('*'),
                'service_order_status_history' => $this->db->table('service_order_status_history')->count('*'),
                'service_order_comments' => $this->db->table('service_order_comments')->count('*'),
            ];
        });
    }

    private function truncate(): void
    {
        $this->db->query(
            'TRUNCATE audit_logs, service_order_materials, stock_movements, work_logs, service_order_comments,
                service_order_status_history, service_orders, order_number_sequences, inventory_items,
                devices, customers, users RESTART IDENTITY CASCADE',
        );
    }

    private function seedUsers(): void
    {
        $definitions = [
            UserRole::Admin->value => ['admin@servishub.local', 'Petr', 'Svoboda', '+420 777 100 200'],
            UserRole::Manager->value => ['vedouci@servishub.local', 'Lucie', 'Dvořáková', '+420 777 100 201'],
            UserRole::Technician->value => ['technik@servishub.local', 'Martin', 'Král', '+420 777 100 202'],
        ];

        $hash = $this->passwords->hash($this->demoPassword);
        foreach ($definitions as $role => [$email, $first, $last, $phone]) {
            $row = $this->insertRow('users', [
                'email' => $email,
                'password_hash' => $hash,
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $phone,
                'role' => $role,
                'is_active' => true,
                'created_at' => $this->now->modify('-320 days'),
                'last_login_at' => $this->now->modify('-' . mt_rand(1, 48) . ' hours'),
            ]);
            $this->users[$role] = $row;
        }
    }

    /**
     * @return list<array{id: int, customer_id: int, type: string, manufacturer: string, model: string, registered_at: \DateTimeImmutable}>
     */
    private function seedCustomersAndDevices(): array
    {
        $customers = [];
        $usedEmails = [];

        for ($i = 0; $i < self::CUSTOMERS; $i++) {
            $isCompany = $i % 3 === 2;
            $address = self::pick(DemoCatalog::ADDRESSES);
            // 5 zákazníků je nových (posledních 30 dní) – kvůli metrice na dashboardu
            $daysAgo = $i >= self::CUSTOMERS - 5 ? mt_rand(2, 28) : mt_rand(60, 300);
            $createdAt = $this->now->modify("-{$daysAgo} days")->setTime(mt_rand(8, 17), mt_rand(0, 59));

            if ($isCompany) {
                $name = DemoCatalog::COMPANIES[intdiv($i, 3) % count(DemoCatalog::COMPANIES)];
                $female = mt_rand(0, 1) === 1;
                $contact = $female
                    ? self::pick(DemoCatalog::FIRST_NAMES_F) . ' ' . self::pick(DemoCatalog::LAST_NAMES_F)
                    : self::pick(DemoCatalog::FIRST_NAMES_M) . ' ' . self::pick(DemoCatalog::LAST_NAMES_M);
                $ico = self::generateIco();
                $emailBase = 'info@' . Strings::webalize(explode(' ', $name)[0] . '-' . (explode(' ', $name)[1] ?? 'firma')) . '.cz';
            } else {
                $female = $i % 2 === 0;
                $first = $female ? self::pick(DemoCatalog::FIRST_NAMES_F) : self::pick(DemoCatalog::FIRST_NAMES_M);
                $last = $female ? self::pick(DemoCatalog::LAST_NAMES_F) : self::pick(DemoCatalog::LAST_NAMES_M);
                $name = $first . ' ' . $last;
                $contact = null;
                $ico = null;
                $emailBase = Strings::webalize($first) . '.' . Strings::webalize($last) . '@' . self::pick(['seznam.cz', 'gmail.com', 'email.cz', 'centrum.cz']);
            }

            $email = $emailBase;
            $suffix = 2;
            while (isset($usedEmails[$email])) {
                $email = preg_replace('/@/', $suffix++ . '@', $emailBase, 1) ?? $emailBase;
            }
            $usedEmails[$email] = true;

            $row = $this->insertRow('customers', [
                'type' => $isCompany ? 'company' : 'person',
                'name' => $name,
                'contact_person' => $contact,
                'email' => $email,
                'phone' => sprintf('+420 %d%02d %03d %03d', mt_rand(6, 7), mt_rand(0, 99), mt_rand(0, 999), mt_rand(0, 999)),
                'street' => $address[0],
                'city' => $address[1],
                'zip' => $address[2],
                'ico' => $ico,
                'dic' => $ico !== null ? 'CZ' . $ico : null,
                'note' => $isCompany && mt_rand(0, 1) === 1 ? 'Firemní zákazník – faktura se splatností 14 dní.' : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $customers[] = ['id' => $row, 'created_at' => $createdAt, 'company' => $isCompany];
        }

        // Každý zákazník má alespoň jedno zařízení, zbytek se rozdělí náhodně (firmy více)
        $owners = array_column($customers, 'id');
        while (count($owners) < self::DEVICES) {
            $candidate = self::pick($customers);
            $owners[] = $candidate['id'];
            if ($candidate['company'] && count($owners) < self::DEVICES) {
                $owners[] = $candidate['id'];
            }
        }
        $owners = array_slice($owners, 0, self::DEVICES);
        $customerDates = array_column($customers, 'created_at', 'id');

        $typeWeights = ['laptop' => 45, 'phone' => 30, 'tablet' => 7, 'desktop' => 10, 'monitor' => 3, 'printer' => 5];
        $devices = [];
        $usedSerials = [];
        foreach ($owners as $customerId) {
            $type = self::weighted($typeWeights);
            [$manufacturer, $model] = self::pick(DemoCatalog::DEVICES[$type]);
            do {
                $serial = $type === 'phone'
                    ? (string) mt_rand(35000000, 35999999) . str_pad((string) mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT)
                    : strtoupper(substr(md5((string) mt_rand()), 0, 4) . mt_rand(100000, 999999));
            } while (isset($usedSerials[$manufacturer . $serial]));
            $usedSerials[$manufacturer . $serial] = true;

            $registeredAt = $customerDates[$customerId]->modify('+' . mt_rand(0, 90) . ' minutes');
            $row = $this->insertRow('devices', [
                'customer_id' => $customerId,
                'type' => $type,
                'manufacturer' => $manufacturer,
                'model' => $model,
                'serial_number' => $serial,
                'description' => mt_rand(0, 2) === 0 ? self::pick(['Stříbrná barva, drobné škrábance na víku.', 'S ochranným pouzdrem.', 'Firemní inventární štítek na spodní straně.', 'Černá barva, bez viditelného poškození.']) : null,
                'registered_at' => $registeredAt,
                'created_at' => $registeredAt,
                'updated_at' => $registeredAt,
            ]);
            $devices[] = [
                'id' => $row,
                'customer_id' => $customerId,
                'type' => $type,
                'manufacturer' => $manufacturer,
                'model' => $model,
                'registered_at' => $registeredAt,
            ];
        }

        return $devices;
    }

    private function seedInventory(): void
    {
        $receivedAt = $this->now->modify('-310 days')->setTime(9, 0);

        foreach (DemoCatalog::INVENTORY as [$sku, $name, $description, $purchase, $sale, $category]) {
            $row = $this->insertRow('inventory_items', [
                'sku' => $sku,
                'name' => $name,
                'description' => $description,
                'unit' => 'ks',
                'purchase_price' => $purchase,
                'sale_price' => $sale,
                'quantity' => 0,
                'min_quantity' => 2,
                'is_active' => true,
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ]);
            $id = $row;
            $this->items[$sku] = [
                'id' => $id, 'sku' => $sku, 'name' => $name, 'unit' => 'ks',
                'purchase' => $purchase, 'sale' => $sale, 'category' => $category,
            ];
            $this->stock[$id] = 0;
            $this->receipt($id, mt_rand(3, 14), $receivedAt, 'Počáteční naskladnění');
        }
    }

    /**
     * @param list<array{id: int, customer_id: int, type: string, manufacturer: string, model: string, registered_at: \DateTimeImmutable}> $devices
     */
    private function seedOrders(array $devices): int
    {
        // Datumy přijetí za posledních ~7 měsíců: 44 uzavřených, 12 nedávných, 14 čerstvých
        $dates = [];
        for ($i = 0; $i < self::ORDERS; $i++) {
            $daysAgo = match (true) {
                $i < 44 => mt_rand(26, 215),
                $i < 56 => mt_rand(11, 25),
                default => mt_rand(0, 10),
            };
            $dates[] = $this->now->modify("-{$daysAgo} days")->setTime(mt_rand(8, 17), mt_rand(0, 59));
        }
        sort($dates);
        // Nejnovější zakázky přijaté v posledních hodinách
        $dates[self::ORDERS - 1] = $this->now->modify('-2 hours');
        $dates[self::ORDERS - 2] = $this->now->modify('-5 hours');
        sort($dates);

        $sequence = [];
        foreach ($dates as $index => $receivedAt) {
            $device = self::pick($devices);
            if ($device['registered_at'] > $receivedAt) {
                // zařízení zaregistrované později – posunout registraci před přijetí
                $registered = $receivedAt->modify('-30 minutes');
                $this->db->table('devices')->where('id', $device['id'])->update(['registered_at' => $registered, 'created_at' => $registered]);
                $this->db->query('UPDATE customers SET created_at = LEAST(created_at, ?) WHERE id = ?', $registered->modify('-10 minutes'), $device['customer_id']);
                foreach ($devices as &$d) {
                    if ($d['id'] === $device['id']) {
                        $d['registered_at'] = $registered;
                    }
                }
                unset($d);
            }

            $year = (int) $receivedAt->format('Y');
            $sequence[$year] = ($sequence[$year] ?? 0) + 1;
            $this->createOrder($device, $receivedAt, OrderNumberGenerator::format($year, $sequence[$year]), $index);
        }

        foreach ($sequence as $year => $last) {
            $this->db->query('INSERT INTO order_number_sequences (year, last_value) VALUES (?, ?)', $year, $last);
        }

        return self::ORDERS;
    }

    /**
     * @param array{id: int, customer_id: int, type: string, manufacturer: string, model: string, registered_at: \DateTimeImmutable} $device
     */
    private function createOrder(array $device, \DateTimeImmutable $receivedAt, string $number, int $index): void
    {
        $ageDays = (int) $receivedAt->diff($this->now)->days;
        $finalStatus = $this->chooseFinalStatus($ageDays, $index);
        [$path, $offsets] = $this->buildPath($finalStatus);

        // Časová razítka kroků; u čerstvých zakázek se rozpětí stlačí, aby nepřesáhlo „teď“
        $totalHours = array_sum($offsets);
        $available = max(1, ($this->now->getTimestamp() - $receivedAt->getTimestamp()) / 3600 - 0.5);
        $scale = $totalHours > $available ? $available / $totalHours : 1.0;
        $times = [$receivedAt];
        $cursor = $receivedAt;
        foreach ($offsets as $hours) {
            $cursor = $cursor->modify('+' . max(1, (int) round($hours * $scale * 60)) . ' minutes');
            $times[] = $cursor;
        }

        $problem = self::pick(DemoCatalog::PROBLEMS[$device['type']]);
        [$problemText, $diagnosisText, $laborTemplate, $partCandidates] = $problem;

        $rate = $receivedAt < new \DateTimeImmutable(self::RATE_CHANGE_DATE) ? '790.00' : '890.00';
        $priceFactor = $receivedAt < new \DateTimeImmutable(self::RATE_CHANGE_DATE) ? '0.95' : '1';
        $discount = match (true) {
            mt_rand(1, 10) === 1 => '10.00',
            mt_rand(1, 10) === 1 => '5.00',
            default => '0.00',
        };

        $reached = static fn (OrderStatus $s): bool => in_array($s, $path, true);
        $technicianId = $finalStatus === OrderStatus::Received && mt_rand(0, 2) === 0 ? null : $this->users[UserRole::Technician->value];
        $creatorId = mt_rand(0, 3) === 0 ? $this->users[UserRole::Admin->value] : $this->users[UserRole::Manager->value];

        // Plánované materiály a práce (pro odhad ceny i skutečný výdej)
        $parts = $this->chooseParts($device, $partCandidates);
        $plannedLabor = [];
        foreach ($laborTemplate as [$desc, $minutes]) {
            $plannedLabor[] = new LaborLine($desc, $minutes, Money::of($rate));
        }
        $plannedMaterials = [];
        foreach ($parts as $part) {
            $plannedMaterials[] = new MaterialLine($part['item']['name'], $part['qty'], $this->historicPrice($part['item']['sale'], $priceFactor));
        }
        $plannedLabor[] = new LaborLine('Diagnostika závady', 30, Money::of($rate));
        $estimate = $this->calculator->calculate($plannedLabor, $plannedMaterials, '0', '21')->total;
        $estimateRounded = Money::of($estimate->getAmount()->dividedBy(100, 0, RoundingMode::UP)->multipliedBy(100));

        $hasDiagnosis = $reached(OrderStatus::AwaitingApproval) || $reached(OrderStatus::InRepair) || ($finalStatus === OrderStatus::Diagnostics && mt_rand(0, 1) === 1);

        $orderRow = $this->insertRow('service_orders', [
            'order_number' => $number,
            'customer_id' => $device['customer_id'],
            'device_id' => $device['id'],
            'assigned_technician_id' => $technicianId,
            'created_by_id' => $creatorId,
            'status' => OrderStatus::Received->value,
            'priority' => self::weighted([OrderPriority::Low->value => 15, OrderPriority::Normal->value => 60, OrderPriority::High->value => 18, OrderPriority::Urgent->value => 7]),
            'problem_description' => $problemText,
            'accessories' => self::pick([null, 'Nabíječka', 'Bez příslušenství', 'Nabíječka a brašna', 'Pouzdro', 'Krabice, nabíjecí kabel']),
            'diagnosis' => $hasDiagnosis ? $diagnosisText : null,
            'estimated_price' => $hasDiagnosis ? $estimateRounded->toDecimalString() : null,
            'hourly_rate' => $rate,
            'discount_percent' => $discount,
            'vat_rate' => '21.00',
            'received_at' => $receivedAt,
            'due_date' => $receivedAt->modify('+' . mt_rand(3, 7) . ' days')->setTime(0, 0),
            'internal_note' => mt_rand(0, 4) === 0 ? 'Stálý zákazník – při vyzvednutí nabídnout prodlouženou záruku.' : null,
            'created_at' => $receivedAt,
            'updated_at' => end($times),
        ]);
        $orderId = $orderRow;

        $this->audit('order.created', $orderId, $creatorId, $receivedAt, ['order_number' => $number]);

        // Historie stavů
        $previous = null;
        foreach ($path as $step => $status) {
            $actor = match ($status) {
                OrderStatus::Received, OrderStatus::HandedOver, OrderStatus::Cancelled => $creatorId,
                OrderStatus::InRepair => $previous === OrderStatus::AwaitingApproval || $previous === OrderStatus::Diagnostics
                    ? $this->users[UserRole::Manager->value]
                    : $this->users[UserRole::Technician->value],
                default => $this->users[UserRole::Technician->value],
            };
            $note = match (true) {
                $status === OrderStatus::Received => 'Zakázka přijata do servisu',
                $status === OrderStatus::InRepair && $previous === OrderStatus::AwaitingApproval => 'Zákazník schválil opravu telefonicky.',
                $status === OrderStatus::InRepair && $previous === OrderStatus::Diagnostics => 'Oprava v rámci předem schváleného limitu.',
                $status === OrderStatus::AwaitingParts => 'Díl objednán u dodavatele.',
                $status === OrderStatus::HandedOver => 'Zařízení převzal zákazník osobně.',
                $status === OrderStatus::Cancelled => self::pick(DemoCatalog::CANCEL_REASONS),
                default => null,
            };
            $this->db->table('service_order_status_history')->insert([
                'service_order_id' => $orderId,
                'from_status' => $previous?->value,
                'to_status' => $status->value,
                'changed_by_id' => $actor,
                'note' => $note,
                'created_at' => $times[$step],
            ]);
            $previous = $status;
        }
        $statusTime = static function (OrderStatus $status) use ($path, $times): ?\DateTimeImmutable {
            $index = array_search($status, $path, true);

            return $index === false ? null : $times[$index];
        };

        // Výkazy práce
        $laborLines = [];
        $technician = $this->users[UserRole::Technician->value];
        if ($reached(OrderStatus::Diagnostics) && $finalStatus !== OrderStatus::Diagnostics) {
            $laborLines[] = $this->workLog($orderId, $technician, 'Diagnostika závady', 30, $rate, $statusTime(OrderStatus::Diagnostics));
        }
        if ($reached(OrderStatus::InRepair)) {
            $repairStart = $statusTime(OrderStatus::InRepair);
            $done = $finalStatus->isDone();
            foreach ($laborTemplate as $k => [$desc, $minutes]) {
                if (!$done && $k > 0) {
                    break; // rozpracovaná oprava – zatím jen první úkon
                }
                $minutes = max(15, $minutes + 15 * mt_rand(-1, 2));
                $laborLines[] = $this->workLog($orderId, $technician, $desc, $minutes, $rate, $repairStart?->modify('+' . (30 + 40 * $k) . ' minutes'));
            }
        }

        // Materiál (vydán po zahájení opravy)
        $materialLines = [];
        if ($reached(OrderStatus::InRepair) && $finalStatus !== OrderStatus::Cancelled) {
            $issueTime = $statusTime(OrderStatus::InRepair)?->modify('+20 minutes') ?? $receivedAt;
            foreach ($parts as $part) {
                $materialLines[] = $this->issue($orderId, $part['item'], $part['qty'], $issueTime, $number, $priceFactor);
            }
        }

        // Komentáře
        $lastTime = end($times);
        for ($c = 0, $count = mt_rand(0, 2); $c < $count; $c++) {
            $at = $receivedAt->modify('+' . mt_rand(10, max(11, (int) (($lastTime->getTimestamp() - $receivedAt->getTimestamp()) / 60))) . ' minutes');
            $this->db->table('service_order_comments')->insert([
                'service_order_id' => $orderId,
                'user_id' => self::pick(array_values($this->users)),
                'body' => self::pick(DemoCatalog::COMMENTS),
                'created_at' => min($at, $this->now),
            ]);
        }

        // Finální stav a časová razítka
        $update = ['status' => $finalStatus->value];
        if ($finalStatus->isDone()) {
            $update['completed_at'] = $statusTime(OrderStatus::Completed);
            $update['final_price'] = $this->calculator
                ->calculate($laborLines, $materialLines, $discount, '21')
                ->total->toDecimalString();
        }
        if ($finalStatus === OrderStatus::HandedOver) {
            $update['handed_over_at'] = $statusTime(OrderStatus::HandedOver);
        }
        if ($finalStatus === OrderStatus::Cancelled) {
            $update['cancelled_at'] = $statusTime(OrderStatus::Cancelled);
            $update['cancellation_reason'] = $this->db->table('service_order_status_history')
                ->where('service_order_id', $orderId)->where('to_status', 'cancelled')->fetch()?->note;
        }
        $this->db->table('service_orders')->where('id', $orderId)->update($update);
    }

    private function chooseFinalStatus(int $ageDays, int $index): OrderStatus
    {
        if ($index >= self::ORDERS - 2) {
            return OrderStatus::Received;
        }

        $weights = match (true) {
            $ageDays > 25 => ['handed_over' => 80, 'completed' => 6, 'cancelled' => 14],
            $ageDays > 10 => ['handed_over' => 30, 'completed' => 15, 'awaiting_parts' => 15, 'in_repair' => 15, 'cancelled' => 8, 'awaiting_approval' => 10, 'diagnostics' => 7],
            default => ['received' => 12, 'diagnostics' => 20, 'awaiting_approval' => 18, 'in_repair' => 22, 'awaiting_parts' => 12, 'completed' => 16],
        };

        return OrderStatus::from(self::weighted($weights));
    }

    /**
     * Cesta grafem workflow k cílovému stavu a odstupy kroků v hodinách.
     *
     * @return array{0: list<OrderStatus>, 1: list<float>}
     */
    private function buildPath(OrderStatus $final): array
    {
        $path = [OrderStatus::Received];
        $offsets = [];
        $go = static function (OrderStatus $status, float $minH, float $maxH) use (&$path, &$offsets): void {
            $path[] = $status;
            $offsets[] = $minH + (mt_rand(0, 1000) / 1000) * ($maxH - $minH);
        };

        if ($final === OrderStatus::Received) {
            return [$path, $offsets];
        }
        if ($final === OrderStatus::Cancelled && mt_rand(0, 2) === 0) {
            $go(OrderStatus::Cancelled, 2, 30);

            return [$path, $offsets];
        }

        $go(OrderStatus::Diagnostics, 1, 20);
        if ($final === OrderStatus::Diagnostics) {
            return [$path, $offsets];
        }

        $needsApproval = in_array($final, [OrderStatus::AwaitingApproval, OrderStatus::Cancelled], true) || mt_rand(1, 10) <= 6;
        if ($needsApproval) {
            $go(OrderStatus::AwaitingApproval, 2, 24);
            if ($final === OrderStatus::AwaitingApproval) {
                return [$path, $offsets];
            }
            if ($final === OrderStatus::Cancelled) {
                $go(OrderStatus::Cancelled, 6, 72);

                return [$path, $offsets];
            }
        }

        $go(OrderStatus::InRepair, 2, 40);
        if ($final === OrderStatus::InRepair) {
            return [$path, $offsets];
        }

        if ($final === OrderStatus::AwaitingParts || mt_rand(1, 4) === 1) {
            $go(OrderStatus::AwaitingParts, 1, 12);
            if ($final === OrderStatus::AwaitingParts) {
                return [$path, $offsets];
            }
            $go(OrderStatus::InRepair, 36, 120);
        }

        $go(OrderStatus::Completed, 2, 30);
        if ($final === OrderStatus::HandedOver) {
            $go(OrderStatus::HandedOver, 4, 96);
        }

        return [$path, $offsets];
    }

    /**
     * Výběr dílů podle shody názvu dílu s modelem zařízení.
     *
     * @param array{type: string, manufacturer: string, model: string} $device
     * @param list<string> $candidates
     * @return list<array{item: array{id: int, sku: string, name: string, unit: string, purchase: string, sale: string, category: string}, qty: int}>
     */
    private function chooseParts(array $device, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $consumables = ['PASTE-MX4', 'PAD-THERM', 'ADH-SET'];
        $main = array_values(array_diff($candidates, $consumables));
        $tokens = array_filter(
            preg_split('/[\s"\/]+/', mb_strtolower($device['manufacturer'] . ' ' . $device['model'])) ?: [],
            static fn (string $t): bool => mb_strlen($t) >= 2,
        );

        $parts = [];
        if ($main !== []) {
            $best = [];
            $bestScore = -1;
            foreach ($main as $sku) {
                $name = mb_strtolower($this->items[$sku]['name']);
                $score = 0;
                foreach ($tokens as $token) {
                    if (str_contains($name, $token)) {
                        $score++;
                    }
                }
                if ($score > $bestScore) {
                    [$best, $bestScore] = [[$sku], $score];
                } elseif ($score === $bestScore) {
                    $best[] = $sku;
                }
            }
            $sku = self::pick($best);
            $parts[] = ['item' => $this->items[$sku], 'qty' => str_starts_with($sku, 'RAM') ? mt_rand(1, 2) : 1];
        }

        foreach (array_intersect($candidates, $consumables) as $sku) {
            if (mt_rand(0, 9) < 6) {
                $parts[] = ['item' => $this->items[$sku], 'qty' => 1];
            }
        }

        return $parts;
    }

    private function workLog(int $orderId, int $userId, string $description, int $minutes, string $rate, ?\DateTimeImmutable $at): LaborLine
    {
        $at = min($at ?? $this->now, $this->now);
        $this->db->table('work_logs')->insert([
            'service_order_id' => $orderId,
            'user_id' => $userId,
            'description' => $description,
            'work_date' => $at->setTime(0, 0),
            'minutes' => $minutes,
            'hourly_rate' => $rate,
            'created_at' => $at,
        ]);
        $this->audit('order.work_logged', $orderId, $userId, $at, ['minutes' => $minutes, 'description' => $description]);

        return new LaborLine($description, $minutes, Money::of($rate));
    }

    /**
     * @param array{id: int, sku: string, name: string, unit: string, purchase: string, sale: string, category: string} $item
     */
    private function issue(int $orderId, array $item, int $quantity, \DateTimeImmutable $at, string $orderNumber, string $priceFactor): MaterialLine
    {
        $at = min($at, $this->now);
        $id = $item['id'];
        if ($this->stock[$id] < $quantity) {
            $this->receipt($id, mt_rand(4, 10), $at->modify('-1 day'), 'Doobjednáno od dodavatele');
        }

        $price = $this->historicPrice($item['sale'], $priceFactor);
        $this->stock[$id] -= $quantity;
        $movement = $this->insertRow('stock_movements', [
            'inventory_item_id' => $id,
            'type' => 'issue',
            'quantity' => -$quantity,
            'quantity_after' => $this->stock[$id],
            'unit_price' => $price->toDecimalString(),
            'service_order_id' => $orderId,
            'user_id' => $this->users[UserRole::Technician->value],
            'note' => 'Výdej na zakázku ' . $orderNumber,
            'created_at' => $at,
        ]);
        $this->db->table('service_order_materials')->insert([
            'service_order_id' => $orderId,
            'inventory_item_id' => $id,
            'stock_movement_id' => $movement,
            'sku' => $item['sku'],
            'name' => $item['name'],
            'unit' => $item['unit'],
            'quantity' => $quantity,
            'unit_price' => $price->toDecimalString(),
            'unit_purchase_price' => $item['purchase'],
            'created_by_id' => $this->users[UserRole::Technician->value],
            'created_at' => $at,
        ]);
        $this->audit('order.material_added', $orderId, $this->users[UserRole::Technician->value], $at, [
            'sku' => $item['sku'], 'quantity' => $quantity, 'unit_price' => $price->toDecimalString(),
        ]);

        return new MaterialLine($item['name'], $quantity, $price, $item['sku']);
    }

    private function receipt(int $itemId, int $quantity, \DateTimeImmutable $at, string $note): void
    {
        $this->stock[$itemId] += $quantity;
        $purchase = $this->db->table('inventory_items')->get($itemId)?->purchase_price;
        $this->db->table('stock_movements')->insert([
            'inventory_item_id' => $itemId,
            'type' => 'receipt',
            'quantity' => $quantity,
            'quantity_after' => $this->stock[$itemId],
            'unit_price' => $purchase,
            'user_id' => $this->users[UserRole::Manager->value],
            'note' => $note,
            'created_at' => $at,
        ]);
    }

    /**
     * Uloží výsledné stavy skladu a nastaví minimální zásoby tak, aby část položek
     * byla pod minimem (ukázka upozornění na nízký stav).
     */
    private function finalizeStock(): void
    {
        $ids = array_keys($this->stock);
        $low = [];
        foreach ((array) array_rand($ids, 6) as $key) {
            $low[$ids[$key]] = true;
        }

        foreach ($this->stock as $id => $quantity) {
            $min = isset($low[$id]) ? $quantity + mt_rand(1, 3) : max(0, min(mt_rand(2, 4), $quantity - 1));
            $this->db->table('inventory_items')->where('id', $id)->update([
                'quantity' => $quantity,
                'min_quantity' => $min,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function audit(string $action, int $orderId, int $userId, \DateTimeImmutable $at, array $changes): void
    {
        $this->db->table('audit_logs')->insert([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => 'service_order',
            'entity_id' => $orderId,
            'changes' => Json::encode($changes),
            'ip_address' => '127.0.0.1',
            'created_at' => min($at, $this->now),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return int ID vloženého řádku
     */
    private function insertRow(string $table, array $data): int
    {
        $row = $this->db->table($table)->insert($data);
        if (!$row instanceof \Nette\Database\Table\ActiveRow) {
            throw new \LogicException("Insert into {$table} did not return a row.");
        }

        return (int) $row->id;
    }

    private function historicPrice(string $salePrice, string $factor): Money
    {
        $price = BigDecimal::of($salePrice)->multipliedBy($factor)->toScale(0, RoundingMode::HALF_UP);

        return Money::of($price);
    }

    private static function generateIco(): string
    {
        $digits = '';
        for ($i = 0; $i < 7; $i++) {
            $digits .= (string) mt_rand($i === 0 ? 1 : 0, 9);
        }
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += (int) $digits[$i] * (8 - $i);
        }
        $remainder = $sum % 11;
        $check = match ($remainder) {
            0 => 1,
            1 => 0,
            default => 11 - $remainder,
        };

        return $digits . $check;
    }

    /**
     * @template T
     * @param array<T> $items
     * @return T
     */
    private static function pick(array $items): mixed
    {
        $values = array_values($items);

        return $values[mt_rand(0, count($values) - 1)];
    }

    /**
     * @param array<string, int> $weights
     */
    private static function weighted(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_first($weights);
    }
}
