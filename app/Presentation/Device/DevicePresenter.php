<?php

declare(strict_types=1);

namespace App\Presentation\Device;

use App\DTO\Filter\DeviceFilter;
use App\DTO\Input\DeviceData;
use App\Forms\DeviceFormFactory;
use App\Model\Enum\DeviceType;
use App\Model\Enum\OrderStatus;
use App\Presentation\BasePresenter;
use App\Repositories\CustomerRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\ServiceOrderRepository;
use App\Security\RequirePermission;
use App\Services\DeviceService;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;

#[RequirePermission('device', 'view')]
final class DevicePresenter extends BasePresenter
{
    private ?ActiveRow $device = null;

    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly CustomerRepository $customers,
        private readonly ServiceOrderRepository $orders,
        private readonly DeviceService $deviceService,
        private readonly DeviceFormFactory $formFactory,
    ) {
        parent::__construct();
    }

    public function renderDefault(?string $q = null, ?string $type = null, int $page = 1): void
    {
        $filter = new DeviceFilter($q, DeviceType::tryFrom((string) $type));
        $selection = $this->devices->search($filter);
        $paginator = $this->createPaginator($selection->count('devices.id'), $page);

        $this->template->devices = $selection->limit($paginator->getLength(), $paginator->getOffset())->fetchAll();
        $this->template->paginator = $paginator;
        $this->template->filter = $filter;
        $this->template->typeOptions = DeviceType::options();

        $ids = array_keys($this->template->devices);
        $this->template->orderCounts = $ids === [] ? [] : $this->orders->table()
            ->select('device_id, COUNT(*) AS cnt')->where('device_id', $ids)->group('device_id')
            ->fetchPairs('device_id', 'cnt');

        if ($this->isAjax()) {
            $this->redrawControl('list');
        }
    }

    public function actionDetail(int $id): void
    {
        $this->device = $this->devices->findById($id) ?? $this->error('Zařízení nebylo nalezeno.');
    }

    public function renderDetail(int $id): void
    {
        assert($this->device !== null);
        $orders = $this->orders->findByDevice($id)->fetchAll();

        $this->template->device = $this->device;
        $this->template->customer = $this->device->customer;
        $this->template->activeOrders = array_filter($orders, static fn (ActiveRow $o): bool => OrderStatus::from((string) $o->status)->isActive());
        $this->template->pastOrders = array_filter($orders, static fn (ActiveRow $o): bool => !OrderStatus::from((string) $o->status)->isActive());
        $this->template->hasOrders = $orders !== [];
    }

    #[RequirePermission('device', 'create')]
    public function actionAdd(?int $customerId = null): void
    {
        $this->setView('form');
        $this->template->device = null;
        $this->template->customer = $customerId !== null ? $this->customers->findById($customerId) : null;

        if ($customerId !== null) {
            $form = $this->getComponent('deviceForm');
            assert($form instanceof Form);
            $form->setDefaults(['customer_id' => $customerId]);
        }
    }

    #[RequirePermission('device', 'edit')]
    public function actionEdit(int $id): void
    {
        $this->device = $this->devices->findById($id) ?? $this->error('Zařízení nebylo nalezeno.');
        $this->setView('form');
        $this->template->device = $this->device;
        $this->template->customer = $this->device->customer;

        $form = $this->getComponent('deviceForm');
        assert($form instanceof Form);
        $form->setDefaults($this->device->toArray());
    }

    #[RequirePermission('device', 'delete')]
    #[Requires(methods: 'POST')]
    public function handleDelete(int $id): void
    {
        $customerId = (int) ($this->devices->findById($id)?->customer_id);
        $ok = $this->runAction(
            fn () => $this->deviceService->delete($id, $this->getActor()),
            successMessage: 'Zařízení bylo smazáno.',
        );
        $ok ? $this->redirect('Customer:detail', $customerId) : $this->redirect('detail', $id);
    }

    protected function createComponentDeviceForm(): Form
    {
        $form = $this->formFactory->create($this->customers->getOptions());
        $form->onSuccess[] = function (Form $form, array $values): void {
            $data = DeviceData::fromArray($values);
            $id = $this->device?->id;

            $ok = $this->runAction(function () use ($data, &$id): void {
                if ($this->device === null) {
                    $id = $this->deviceService->create($data, $this->getActor());
                } else {
                    $this->deviceService->update((int) $this->device->id, $data, $this->getActor());
                }
            }, $form, $this->device === null ? 'Zařízení bylo zaevidováno.' : 'Změny byly uloženy.');

            if ($ok) {
                $this->redirect('detail', (int) $id);
            }
        };

        return $form;
    }
}
