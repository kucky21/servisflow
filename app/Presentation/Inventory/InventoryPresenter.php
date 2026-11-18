<?php

declare(strict_types=1);

namespace App\Presentation\Inventory;

use App\DTO\Filter\InventoryFilter;
use App\DTO\Input\InventoryItemData;
use App\DTO\Input\Normalizer;
use App\Forms\InventoryFormFactory;
use App\Presentation\BasePresenter;
use App\Repositories\InventoryItemRepository;
use App\Repositories\OrderMaterialRepository;
use App\Repositories\StockMovementRepository;
use App\Security\RequirePermission;
use App\Services\InventoryService;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;

#[RequirePermission('inventory', 'view')]
final class InventoryPresenter extends BasePresenter
{
    private ?ActiveRow $item = null;

    public function __construct(
        private readonly InventoryItemRepository $items,
        private readonly StockMovementRepository $movements,
        private readonly OrderMaterialRepository $orderMaterials,
        private readonly InventoryService $inventoryService,
        private readonly InventoryFormFactory $formFactory,
    ) {
        parent::__construct();
    }

    public function renderDefault(?string $q = null, bool $low = false, bool $inactive = false, int $page = 1): void
    {
        $filter = new InventoryFilter($q, $low, $inactive && $this->isAllowed('inventory', 'edit'));
        $selection = $this->items->search($filter);
        $paginator = $this->createPaginator($selection->count('*'), $page, 25);

        $this->template->items = $selection->limit($paginator->getLength(), $paginator->getOffset())->fetchAll();
        $this->template->paginator = $paginator;
        $this->template->filter = $filter;
        $this->template->lowStockTotal = $this->items->countLowStock();
        $this->template->stockValue = $this->items->getStockValue();
        $this->template->canManage = $this->isAllowed('inventory', 'edit');

        if ($this->isAjax()) {
            $this->redrawControl('list');
        }
    }

    public function actionDetail(int $id): void
    {
        $this->item = $this->items->findById($id) ?? $this->error('Skladová položka nebyla nalezena.');
    }

    public function renderDetail(int $id): void
    {
        $item = $this->items->getById($id);
        $this->template->item = $item;
        $this->template->movements = $this->movements->findByItem($id)->limit(100)->fetchAll();
        $this->template->usage = $this->orderMaterials->table()
            ->select('SUM(quantity) AS qty, COUNT(DISTINCT service_order_id) AS orders')
            ->where('inventory_item_id', $id)
            ->fetch();
        $this->template->canManage = $this->isAllowed('inventory', 'edit');
        $this->template->hasMovements = $this->items->hasMovements($id);
        $this->template->margin = (float) $item->sale_price > 0
            ? round(((float) $item->sale_price - (float) $item->purchase_price) / (float) $item->sale_price * 100, 1)
            : null;
    }

    #[RequirePermission('inventory', 'edit')]
    public function renderMovements(int $page = 1): void
    {
        $selection = $this->movements->table()->order('created_at DESC, id DESC');
        $paginator = $this->createPaginator($selection->count('*'), $page, 40);
        $this->template->movements = $selection->limit($paginator->getLength(), $paginator->getOffset())->fetchAll();
        $this->template->paginator = $paginator;
    }

    #[RequirePermission('inventory', 'create')]
    public function actionAdd(): void
    {
        $this->setView('form');
        $this->template->item = null;
    }

    #[RequirePermission('inventory', 'edit')]
    public function actionEdit(int $id): void
    {
        $this->item = $this->items->findById($id) ?? $this->error('Skladová položka nebyla nalezena.');
        $this->setView('form');
        $this->template->item = $this->item;

        $form = $this->getComponent('itemForm');
        assert($form instanceof Form);
        $defaults = $this->item->toArray();
        $defaults['purchase_price'] = str_replace('.', ',', (string) $defaults['purchase_price']);
        $defaults['sale_price'] = str_replace('.', ',', (string) $defaults['sale_price']);
        $form->setDefaults($defaults);
    }

    #[RequirePermission('inventory', 'delete')]
    #[Requires(methods: 'POST')]
    public function handleDelete(int $id): void
    {
        $ok = $this->runAction(
            fn () => $this->inventoryService->deleteItem($id, $this->getActor()),
            successMessage: 'Skladová položka byla smazána.',
        );
        $ok ? $this->redirect('default') : $this->redirect('detail', $id);
    }

    protected function createComponentItemForm(): Form
    {
        $form = $this->formFactory->createItemForm($this->getAction() === 'add');
        $form->onSuccess[] = function (Form $form, array $values): void {
            $data = InventoryItemData::fromArray($values);
            $id = $this->item?->id;

            $ok = $this->runAction(function () use ($data, &$id): void {
                if ($this->item === null) {
                    $id = $this->inventoryService->createItem($data, $this->getActor());
                } else {
                    $this->inventoryService->updateItem((int) $this->item->id, $data, $this->getActor());
                }
            }, $form, $this->item === null ? 'Skladová položka byla založena.' : 'Změny byly uloženy.');

            if ($ok) {
                $this->redirect('detail', (int) $id);
            }
        };

        return $form;
    }

    protected function createComponentReceiptForm(): Form
    {
        $form = $this->formFactory->createReceiptForm();
        $form->onSuccess[] = function (Form $form, array $values): void {
            if (!$this->isAllowed('inventory', 'receive') || $this->item === null) {
                $this->error('K této akci nemáte oprávnění.', 403);
            }
            $ok = $this->runAction(
                fn () => $this->inventoryService->receive(
                    (int) $this->item->id,
                    (int) $values['quantity'],
                    Normalizer::nullableDecimal($values['unit_price']),
                    $values['note'],
                    $this->getActor(),
                ),
                $form,
                sprintf('Naskladněno %d %s.', (int) $values['quantity'], $this->item->unit),
            );
            $this->afterStockForm($form, $ok, 'receiptForm');
        };

        return $this->redrawOnError($form, 'receiptForm');
    }

    protected function createComponentAdjustmentForm(): Form
    {
        $form = $this->formFactory->createAdjustmentForm();
        $form->onSuccess[] = function (Form $form, array $values): void {
            if (!$this->isAllowed('inventory', 'adjust') || $this->item === null) {
                $this->error('K této akci nemáte oprávnění.', 403);
            }
            $ok = $this->runAction(
                fn () => $this->inventoryService->adjust((int) $this->item->id, (int) $values['quantity'], (string) $values['note'], $this->getActor()),
                $form,
                'Inventurní úprava byla provedena.',
            );
            $this->afterStockForm($form, $ok, 'adjustmentForm');
        };

        return $this->redrawOnError($form, 'adjustmentForm');
    }

    private function afterStockForm(Form $form, bool $ok, string $snippet): void
    {
        if (!$ok) {
            if ($this->isAjax()) {
                $this->redrawControl($snippet);
            }

            return;
        }
        $form->reset();
        $this->finishAction(['itemSummary', 'movements', 'receiptForm', 'adjustmentForm']);
    }
}
