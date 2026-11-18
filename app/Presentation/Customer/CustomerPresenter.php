<?php

declare(strict_types=1);

namespace App\Presentation\Customer;

use App\DTO\Filter\CustomerFilter;
use App\DTO\Input\CustomerData;
use App\Forms\CustomerFormFactory;
use App\Model\Enum\CustomerType;
use App\Presentation\BasePresenter;
use App\Repositories\CustomerRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\ServiceOrderRepository;
use App\Security\RequirePermission;
use App\Services\CustomerService;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;

#[RequirePermission('customer', 'view')]
final class CustomerPresenter extends BasePresenter
{
    private ?ActiveRow $customer = null;

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly DeviceRepository $devices,
        private readonly ServiceOrderRepository $orders,
        private readonly CustomerService $customerService,
        private readonly CustomerFormFactory $formFactory,
    ) {
        parent::__construct();
    }

    public function renderDefault(?string $q = null, ?string $type = null, string $sort = 'name', int $page = 1): void
    {
        $filter = new CustomerFilter($q, CustomerType::tryFrom((string) $type), $sort);
        $selection = $this->customers->search($filter);
        $paginator = $this->createPaginator($selection->count('*'), $page);

        $this->template->customers = $selection
            ->limit($paginator->getLength(), $paginator->getOffset())
            ->fetchAll();
        $this->template->paginator = $paginator;
        $this->template->filter = $filter;
        $this->template->typeOptions = CustomerType::options();

        // Počty zařízení a zakázek pro zobrazené zákazníky (jeden dotaz místo N+1)
        $ids = array_keys($this->template->customers);
        $this->template->deviceCounts = $ids === [] ? [] : $this->devices->table()
            ->select('customer_id, COUNT(*) AS cnt')->where('customer_id', $ids)->group('customer_id')
            ->fetchPairs('customer_id', 'cnt');
        $this->template->orderCounts = $ids === [] ? [] : $this->orders->table()
            ->select('customer_id, COUNT(*) AS cnt')->where('customer_id', $ids)->group('customer_id')
            ->fetchPairs('customer_id', 'cnt');

        if ($this->isAjax()) {
            $this->redrawControl('list');
        }
    }

    public function actionDetail(int $id): void
    {
        $this->customer = $this->customers->findById($id) ?? $this->error('Zákazník nebyl nalezen.');
    }

    public function renderDetail(int $id): void
    {
        assert($this->customer !== null);
        $this->template->customer = $this->customer;
        $this->template->devices = $this->devices->findByCustomer($id)->fetchAll();
        $this->template->orders = $this->orders->findByCustomer($id)->fetchAll();
        $this->template->hasOrders = $this->template->orders !== [];

        $totals = $this->orders->table()
            ->select('COUNT(*) AS cnt, COALESCE(SUM(final_price), 0) AS total')
            ->where('customer_id', $id)
            ->where('status', ['completed', 'handed_over'])
            ->fetch();
        $this->template->completedCount = (int) ($totals->cnt ?? 0);
        $this->template->completedTotal = (string) ($totals->total ?? '0');
    }

    #[RequirePermission('customer', 'create')]
    public function actionAdd(): void
    {
        $this->setView('form');
        $this->template->customer = null;
    }

    #[RequirePermission('customer', 'edit')]
    public function actionEdit(int $id): void
    {
        $this->customer = $this->customers->findById($id) ?? $this->error('Zákazník nebyl nalezen.');
        $this->setView('form');
        $this->template->customer = $this->customer;

        $form = $this->getComponent('customerForm');
        assert($form instanceof Form);
        $form->setDefaults($this->customer->toArray());
    }

    #[RequirePermission('customer', 'delete')]
    #[Requires(methods: 'POST')]
    public function handleDelete(int $id): void
    {
        $ok = $this->runAction(
            fn () => $this->customerService->delete($id, $this->getActor()),
            successMessage: 'Zákazník byl smazán.',
        );
        $ok ? $this->redirect('default') : $this->redirect('detail', $id);
    }

    protected function createComponentCustomerForm(): Form
    {
        $form = $this->formFactory->create();
        $form->onSuccess[] = function (Form $form, array $values): void {
            $data = CustomerData::fromArray($values);
            $id = $this->customer?->id;

            $ok = $this->runAction(function () use ($data, &$id): void {
                if ($this->customer === null) {
                    $id = $this->customerService->create($data, $this->getActor());
                } else {
                    $this->customerService->update((int) $this->customer->id, $data, $this->getActor());
                }
            }, $form, $this->customer === null ? 'Zákazník byl založen.' : 'Změny byly uloženy.');

            if ($ok) {
                $this->redirect('detail', (int) $id);
            }
        };

        return $form;
    }
}
