<?php

declare(strict_types=1);

namespace App\Presentation\Profile;

use App\Forms\SignFormFactory;
use App\Presentation\BasePresenter;
use App\Repositories\UserRepository;
use App\Services\UserService;
use Nette\Application\UI\Form;

/**
 * Vlastní profil přihlášeného uživatele (dostupný všem rolím).
 */
final class ProfilePresenter extends BasePresenter
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserService $userService,
        private readonly SignFormFactory $formFactory,
    ) {
        parent::__construct();
    }

    public function renderDefault(): void
    {
        $this->template->profile = $this->users->getById($this->getActor()->id);
    }

    protected function createComponentPasswordForm(): Form
    {
        $form = $this->formFactory->createPasswordChange();
        $form->onSuccess[] = function (Form $form, array $values): void {
            $ok = $this->runAction(
                fn () => $this->userService->changeOwnPassword(
                    $this->getActor(),
                    (string) $values['current_password'],
                    (string) $values['new_password'],
                ),
                $form,
                'Heslo bylo změněno.',
            );
            if ($ok) {
                $this->redirect('this');
            }
        };

        return $form;
    }
}
