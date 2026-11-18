<?php

declare(strict_types=1);

namespace App\Presentation\User;

use App\DTO\Input\UserData;
use App\Forms\UserFormFactory;
use App\Presentation\BasePresenter;
use App\Repositories\UserRepository;
use App\Security\RequirePermission;
use App\Services\UserService;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;

#[RequirePermission('user', 'view')]
final class UserPresenter extends BasePresenter
{
    private ?ActiveRow $editedUser = null;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserService $userService,
        private readonly UserFormFactory $formFactory,
    ) {
        parent::__construct();
    }

    public function renderDefault(): void
    {
        $this->template->users = $this->users->findAllOrdered()->fetchAll();
        $this->template->activeOrderCounts = $this->users->table()->getExplorer()
            ->table('service_orders')
            ->select('assigned_technician_id, COUNT(*) AS cnt')
            ->where('status', \App\Model\Enum\OrderStatus::activeValues())
            ->where('assigned_technician_id IS NOT NULL')
            ->group('assigned_technician_id')
            ->fetchPairs('assigned_technician_id', 'cnt');
    }

    #[RequirePermission('user', 'create')]
    public function actionAdd(): void
    {
        $this->setView('form');
        $this->template->editedUser = null;
    }

    #[RequirePermission('user', 'edit')]
    public function actionEdit(int $id): void
    {
        $this->editedUser = $this->users->findById($id) ?? $this->error('Uživatel nebyl nalezen.');
        $this->setView('form');
        $this->template->editedUser = $this->editedUser;

        $form = $this->getComponent('userForm');
        assert($form instanceof Form);
        $defaults = $this->editedUser->toArray();
        unset($defaults['password_hash']);
        $form->setDefaults($defaults);
    }

    protected function createComponentUserForm(): Form
    {
        $form = $this->formFactory->create($this->getAction() === 'add');
        $form->onSuccess[] = function (Form $form, array $values): void {
            $data = UserData::fromArray($values);
            $ok = $this->runAction(function () use ($data): void {
                if ($this->editedUser === null) {
                    $this->userService->create($data, $this->getActor());
                } else {
                    $this->userService->update((int) $this->editedUser->id, $data, $this->getActor());
                }
            }, $form, $this->editedUser === null ? 'Uživatel byl vytvořen.' : 'Uživatel byl upraven.');

            if ($ok) {
                $this->redirect('default');
            }
        };

        return $form;
    }
}
