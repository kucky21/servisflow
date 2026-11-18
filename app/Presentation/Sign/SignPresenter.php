<?php

declare(strict_types=1);

namespace App\Presentation\Sign;

use App\Forms\SignFormFactory;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;
use Nette\Security\AuthenticationException;

/**
 * Přihlášení (veřejná část aplikace).
 */
final class SignPresenter extends Presenter
{
    #[Persistent]
    public string $backlink = '';

    public function __construct(
        private readonly SignFormFactory $formFactory,
    ) {
        parent::__construct();
    }

    public function actionIn(): void
    {
        if ($this->getUser()->isLoggedIn()) {
            $this->redirect('Dashboard:default');
        }
    }

    public function renderIn(): void
    {
        $this->template->showDemo = \App\Bootstrap::getEnvironment() !== 'production';
    }

    protected function createComponentSignInForm(): Form
    {
        $form = $this->formFactory->createSignIn();
        $form->onSuccess[] = function (Form $form, \stdClass $values): void {
            $user = $this->getUser();
            try {
                $user->setExpiration($values->remember ? '14 days' : '8 hours');
                $user->login((string) $values->email, (string) $values->password);
            } catch (AuthenticationException $e) {
                $form->addError($e->getMessage());

                return;
            }

            $this->getSession()->regenerateId();
            $this->restoreRequest($this->backlink);
            $this->redirect('Dashboard:default');
        };

        return $form;
    }
}
