<?php

declare(strict_types=1);

namespace App\Presentation\Order;

use App\Components\OrderTimeline\OrderTimeline;
use App\DTO\Filter\OrderFilter;
use App\DTO\Input\OrderData;
use App\DTO\Input\WorkLogData;
use App\Forms\OrderFormFactory;
use App\Model\Enum\DocumentType;
use App\Model\Enum\OrderPriority;
use App\Model\Enum\OrderStatus;
use App\Presentation\BasePresenter;
use App\Repositories\AuditLogRepository;
use App\Repositories\CommentRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\InventoryItemRepository;
use App\Repositories\OrderMaterialRepository;
use App\Repositories\ServiceOrderRepository;
use App\Repositories\StatusHistoryRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkLogRepository;
use App\Security\OrderAccessPolicy;
use App\Security\RequirePermission;
use App\Services\InventoryService;
use App\Services\OrderWorkflowService;
use App\Services\Pricing\OrderPricingService;
use App\Services\ServiceOrderService;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;

#[RequirePermission('order', 'view')]
final class OrderPresenter extends BasePresenter
{
    /** Snippety pracovního prostoru zakázky překreslované po každé změně. */
    private const array WORKSPACE_SNIPPETS = [
        'header', 'sidebar', 'diagnosis', 'work', 'material', 'pricing', 'history', 'comments', 'statusForm', 'workCount', 'materialCount',
    ];

    private ?ActiveRow $order = null;

    public function __construct(
        private readonly ServiceOrderRepository $orders,
        private readonly CustomerRepository $customers,
        private readonly DeviceRepository $devices,
        private readonly UserRepository $users,
        private readonly WorkLogRepository $workLogs,
        private readonly OrderMaterialRepository $materials,
        private readonly CommentRepository $comments,
        private readonly StatusHistoryRepository $statusHistory,
        private readonly AuditLogRepository $auditLogs,
        private readonly InventoryItemRepository $inventoryItems,
        private readonly ServiceOrderService $orderService,
        private readonly OrderWorkflowService $workflow,
        private readonly OrderPricingService $pricing,
        private readonly InventoryService $inventoryService,
        private readonly OrderAccessPolicy $accessPolicy,
        private readonly OrderFormFactory $formFactory,
    ) {
        parent::__construct();
    }

    // ------------------------------------------------------------------
    // Seznam
    // ------------------------------------------------------------------

    public function renderDefault(
        ?string $q = null,
        ?string $status = null,
        string $scope = OrderFilter::SCOPE_ACTIVE,
        ?int $technician = null,
        ?string $priority = null,
        bool $overdue = false,
        bool $unassigned = false,
        int $page = 1,
    ): void {
        $actor = $this->getActor();
        // Technik vidí výhradně své zakázky – filtr nelze obejít parametrem v URL
        $technicianId = $actor->isTechnician() ? $actor->id : $technician;

        $filter = new OrderFilter(
            query: $q,
            status: OrderStatus::tryFrom((string) $status),
            scope: in_array($scope, [OrderFilter::SCOPE_ACTIVE, OrderFilter::SCOPE_DONE, OrderFilter::SCOPE_ALL], true) ? $scope : OrderFilter::SCOPE_ACTIVE,
            technicianId: $technicianId,
            priority: OrderPriority::tryFrom((string) $priority),
            overdueOnly: $overdue,
            unassignedOnly: $unassigned && !$actor->isTechnician(),
        );

        // Fulltext hledá napříč všemi stavy
        if ($q !== null && $q !== '' && $status === null && $scope === OrderFilter::SCOPE_ACTIVE) {
            $filter = new OrderFilter($q, null, OrderFilter::SCOPE_ALL, $technicianId, $filter->priority, $overdue, $filter->unassignedOnly);
        }

        $selection = $this->orders->search($filter);
        $paginator = $this->createPaginator($selection->count('service_orders.id'), $page);

        $this->template->orders = $selection->limit($paginator->getLength(), $paginator->getOffset())->fetchAll();
        $this->template->paginator = $paginator;
        $this->template->filter = $filter;
        $this->template->statusOptions = OrderStatus::options();
        $this->template->priorityOptions = OrderPriority::options();
        $this->template->technicianOptions = $this->users->getTechnicianOptions();
        $this->template->hasFilters = $q || $status || $priority || $overdue || $unassigned || ($technician && !$actor->isTechnician());

        if ($this->isAjax()) {
            $this->redrawControl('list');
        }
    }

    // ------------------------------------------------------------------
    // Nová zakázka / úprava
    // ------------------------------------------------------------------

    #[RequirePermission('order', 'create')]
    public function actionAdd(?int $customerId = null, ?int $deviceId = null): void
    {
        $this->setView('form');
        $this->template->order = null;

        $form = $this->getComponent('orderForm');
        assert($form instanceof Form);
        $form->setDefaults(array_filter([
            'customer_id' => $customerId,
            'device_id' => $deviceId,
        ]));
    }

    #[RequirePermission('order', 'edit')]
    public function actionEdit(int $id): void
    {
        $this->order = $this->loadOrder($id);
        if (OrderStatus::from((string) $this->order->status)->isFinal()) {
            $this->toast('Uzavřenou zakázku nelze upravovat.', 'warning');
            $this->redirect('detail', $id);
        }
        $this->setView('form');
        $this->template->order = $this->order;

        $form = $this->getComponent('orderForm');
        assert($form instanceof Form);
        $defaults = $this->order->toArray();
        foreach (['estimated_price', 'hourly_rate', 'discount_percent'] as $key) {
            $defaults[$key] = $defaults[$key] === null ? null : str_replace('.', ',', (string) $defaults[$key]);
        }
        $form->setDefaults($defaults);
    }

    /**
     * AJAX: zařízení vybraného zákazníka pro závislý select ve formuláři.
     */
    #[RequirePermission('order', 'create')]
    public function handleDevices(?int $customerId = null): void
    {
        $options = [];
        foreach ($customerId === null ? [] : $this->devices->getOptionsForCustomer($customerId) as $id => $label) {
            $options[] = ['value' => $id, 'label' => $label];
        }
        $this->sendJson(['devices' => $options]);
    }

    protected function createComponentOrderForm(): Form
    {
        $isNew = $this->getAction() === 'add';

        $customerId = (int) ($this->getHttpRequest()->getPost('customer_id') ?? $this->getParameter('customerId') ?? 0);
        $deviceOptions = $isNew && $customerId > 0 ? $this->devices->getOptionsForCustomer($customerId) : [];

        $form = $this->formFactory->createOrderForm(
            $isNew,
            $isNew ? $this->customers->getOptions() : [],
            $deviceOptions,
            $this->users->getTechnicianOptions(),
        );

        $form->onSuccess[] = function (Form $form, array $values): void {
            if ($this->order !== null) {
                $values['customer_id'] = $this->order->customer_id;
                $values['device_id'] = $this->order->device_id;
            }
            $data = OrderData::fromArray($values);
            $id = $this->order?->id;

            $ok = $this->runAction(function () use ($data, &$id): void {
                if ($this->order === null) {
                    $id = $this->orderService->create($data, $this->getActor());
                } else {
                    $this->orderService->update((int) $this->order->id, $data, $this->getActor());
                }
            }, $form, $this->order === null ? 'Zakázka byla přijata.' : 'Zakázka byla upravena.');

            if ($ok) {
                $this->redirect('detail', (int) $id);
            }
        };

        return $form;
    }

    // ------------------------------------------------------------------
    // Pracovní prostor zakázky
    // ------------------------------------------------------------------

    public function actionDetail(int $id): void
    {
        $this->order = $this->loadOrder($id);
    }

    public function renderDetail(int $id): void
    {
        $order = $this->orders->getById($id); // čerstvá data po AJAX změnách
        $this->order = $order;
        $actor = $this->getActor();
        $status = OrderStatus::from((string) $order->status);

        $transitions = [];
        $statusOptions = [];
        foreach ($this->workflow->getAvailableTransitions($order, $actor) as $transition) {
            $transitions[] = [
                'transition' => $transition,
                'problems' => $this->workflow->getUnmetConditions($order, $transition),
            ];
            $statusOptions[$transition->to->value] = $transition->label;
        }

        // Formuláře mohly vzniknout před změnou stavu/skladu – obnovit jejich volby
        $statusSelect = $this->getComponent('statusForm')->getComponent('status');
        assert($statusSelect instanceof \Nette\Forms\Controls\SelectBox);
        $statusSelect->setItems($statusOptions);
        $itemSelect = $this->getComponent('materialForm')->getComponent('item_id');
        assert($itemSelect instanceof \Nette\Forms\Controls\SelectBox);
        $itemSelect->setItems($this->inventoryItems->getIssueOptions());

        $this->template->order = $order;
        $this->template->status = $status;
        $this->template->customer = $order->customer;
        $this->template->device = $order->device;
        $this->template->technician = $order->ref('users', 'assigned_technician_id');
        $this->template->createdBy = $order->ref('users', 'created_by_id');
        $this->template->transitions = $transitions;
        $this->template->workLogs = $this->workLogs->findByOrder($id)->fetchAll();
        $this->template->materials = $this->materials->findByOrder($id)->fetchAll();
        $this->template->comments = $this->comments->findByOrder($id)->fetchAll();
        $this->template->breakdown = $this->pricing->getBreakdownForOrder($order);
        $this->template->canWork = $this->accessPolicy->canWorkOnRow($actor, $order);
        $this->template->canManage = $this->accessPolicy->canManage($actor) && !$status->isFinal();
        $this->template->documents = array_filter(
            DocumentType::cases(),
            static fn (DocumentType $type): bool => $type->isAvailableFor($status),
        );
        $this->template->isOverdue = $order->due_date !== null && $status->isActive()
            && $order->due_date < new \DateTimeImmutable('today');
    }

    #[Requires(methods: 'POST')]
    #[RequirePermission('order', 'logWork')]
    public function handleDeleteWorkLog(int $workLogId): void
    {
        $this->runAction(
            fn () => $this->orderService->deleteWorkLog($workLogId, $this->getActor()),
            successMessage: 'Výkaz práce byl odstraněn.',
        );
        $this->finishAction(self::WORKSPACE_SNIPPETS);
    }

    #[Requires(methods: 'POST')]
    #[RequirePermission('order', 'useMaterial')]
    public function handleRemoveMaterial(int $materialId): void
    {
        $this->runAction(
            fn () => $this->inventoryService->removeMaterialFromOrder($materialId, $this->getActor()),
            successMessage: 'Materiál byl vrácen na sklad.',
        );
        $this->finishAction(self::WORKSPACE_SNIPPETS);
    }

    protected function createComponentStatusForm(): Form
    {
        $transitions = $this->order !== null
            ? $this->workflow->getAvailableTransitions($this->order, $this->getActor())
            : [];
        $form = $this->formFactory->createStatusForm($transitions);

        $form->onSuccess[] = function (Form $form, array $values): void {
            $this->requirePrivilege('changeStatus');
            $target = OrderStatus::from((string) $values['status']);
            $ok = $this->runAction(
                fn () => $this->workflow->changeStatus($this->getOrderId(), $target, $this->getActor(), $values['note']),
                $form,
                sprintf('Stav zakázky změněn na „%s“.', $target->label()),
            );
            $this->afterWorkspaceForm($form, $ok, 'statusForm');
        };

        return $this->redrawOnError($form, 'statusForm');
    }

    protected function createComponentAssignForm(): Form
    {
        $form = $this->formFactory->createAssignForm($this->users->getTechnicianOptions());
        if ($this->order !== null && $this->order->assigned_technician_id !== null) {
            $form->setDefaults(['technician_id' => $this->order->assigned_technician_id]);
        }

        $form->onSuccess[] = function (Form $form, array $values): void {
            $this->requirePrivilege('assign');
            $technicianId = $values['technician_id'] === null ? null : (int) $values['technician_id'];
            $ok = $this->runAction(
                fn () => $this->orderService->assignTechnician($this->getOrderId(), $technicianId, $this->getActor()),
                $form,
                $technicianId === null ? 'Technik byl odebrán.' : 'Technik byl přiřazen.',
            );
            $this->afterWorkspaceForm($form, $ok, 'sidebar');
        };

        return $this->redrawOnError($form, 'sidebar');
    }

    protected function createComponentDiagnosisForm(): Form
    {
        $form = $this->formFactory->createDiagnosisForm();
        if ($this->order !== null) {
            $form->setDefaults([
                'diagnosis' => $this->order->diagnosis,
                'estimated_price' => $this->order->estimated_price === null ? null : str_replace('.', ',', (string) $this->order->estimated_price),
            ]);
        }

        $form->onSuccess[] = function (Form $form, array $values): void {
            $this->requirePrivilege('diagnose');
            $ok = $this->runAction(
                fn () => $this->orderService->updateDiagnosis(
                    $this->getOrderId(),
                    $values['diagnosis'],
                    \App\DTO\Input\Normalizer::nullableDecimal($values['estimated_price']),
                    $this->getActor(),
                ),
                $form,
                'Diagnostika byla uložena.',
            );
            $this->afterWorkspaceForm($form, $ok, 'diagnosis', reset: false);
        };

        return $this->redrawOnError($form, 'diagnosis');
    }

    protected function createComponentWorkLogForm(): Form
    {
        $form = $this->formFactory->createWorkLogForm();
        $form->onSuccess[] = function (Form $form, array $values): void {
            $this->requirePrivilege('logWork');
            $ok = $this->runAction(
                fn () => $this->orderService->addWorkLog($this->getOrderId(), WorkLogData::fromArray($values), $this->getActor()),
                $form,
                'Práce byla vykázána.',
            );
            $this->afterWorkspaceForm($form, $ok, 'work');
        };

        return $this->redrawOnError($form, 'work');
    }

    protected function createComponentMaterialForm(): Form
    {
        $form = $this->formFactory->createMaterialForm($this->inventoryItems->getIssueOptions());
        $form->onSuccess[] = function (Form $form, array $values): void {
            $this->requirePrivilege('useMaterial');
            $ok = $this->runAction(
                fn () => $this->inventoryService->issueToOrder($this->getOrderId(), (int) $values['item_id'], (int) $values['quantity'], $this->getActor()),
                $form,
                'Materiál byl vydán ze skladu.',
            );
            $this->afterWorkspaceForm($form, $ok, 'material');
        };

        return $this->redrawOnError($form, 'material');
    }

    protected function createComponentCommentForm(): Form
    {
        $form = $this->formFactory->createCommentForm();
        $form->onSuccess[] = function (Form $form, array $values): void {
            $this->requirePrivilege('comment');
            $ok = $this->runAction(
                fn () => $this->orderService->addComment($this->getOrderId(), (string) $values['body'], $this->getActor()),
                $form,
                'Komentář byl přidán.',
            );
            $this->afterWorkspaceForm($form, $ok, 'comments');
        };

        return $this->redrawOnError($form, 'comments');
    }

    protected function createComponentOrderTimeline(): OrderTimeline
    {
        $orderId = $this->getOrderId();
        $userNames = [];
        foreach ($this->users->table() as $user) {
            $userNames[(int) $user->id] = $user->first_name . ' ' . $user->last_name;
        }

        return new OrderTimeline(
            array_values($this->statusHistory->findByOrder($orderId)->fetchAll()),
            $this->getActor()->isManagerial()
                ? array_values($this->auditLogs->findByEntity('service_order', $orderId, 60)->fetchAll())
                : [],
            $userNames,
        );
    }

    // ------------------------------------------------------------------

    private function loadOrder(int $id): ActiveRow
    {
        $order = $this->orders->findById($id) ?? $this->error('Zakázka nebyla nalezena.');
        if (!$this->accessPolicy->canViewRow($this->getActor(), $order)) {
            $this->error('K této zakázce nemáte přístup.', 403);
        }

        return $order;
    }

    private function getOrderId(): int
    {
        if ($this->order === null) {
            $this->error('Zakázka nebyla nalezena.');
        }

        return (int) $this->order->id;
    }

    private function requirePrivilege(string $privilege): void
    {
        if (!$this->isAllowed('order', $privilege)) {
            $this->error('K této akci nemáte oprávnění.', 403);
        }
    }

    /**
     * Úspěch: překreslit celý pracovní prostor (a vyčistit formulář).
     * Chyba: překreslit jen snippet s formulářem, aby se zobrazily chyby.
     */
    private function afterWorkspaceForm(Form $form, bool $ok, string $formSnippet, bool $reset = true): void
    {
        if (!$ok) {
            if ($this->isAjax()) {
                $this->redrawControl($formSnippet);
            }

            return;
        }

        if ($reset) {
            $form->reset();
        }
        $this->finishAction(self::WORKSPACE_SNIPPETS);
    }
}
