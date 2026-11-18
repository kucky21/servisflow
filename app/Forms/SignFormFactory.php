<?php

declare(strict_types=1);

namespace App\Forms;

use App\Security\PasswordPolicy;
use Nette\Application\UI\Form;

final class SignFormFactory
{
    public function __construct(
        private readonly FormFactory $factory,
    ) {
    }

    public function createSignIn(): Form
    {
        $form = $this->factory->create();
        $form->addEmail('email', 'E-mail')
            ->setRequired('Zadejte e-mail.')
            ->setHtmlAttribute('autocomplete', 'username')
            ->setHtmlAttribute('autofocus');
        $form->addPassword('password', 'Heslo')
            ->setRequired('Zadejte heslo.')
            ->setHtmlAttribute('autocomplete', 'current-password');
        $form->addCheckbox('remember', 'Zůstat přihlášen 14 dní');
        $form->addSubmit('send', 'Přihlásit se');

        return $form;
    }

    public function createPasswordChange(): Form
    {
        $form = $this->factory->create();
        $form->addPassword('current_password', 'Současné heslo')
            ->setRequired('Zadejte současné heslo.')
            ->setHtmlAttribute('autocomplete', 'current-password');
        $form->addPassword('new_password', 'Nové heslo')
            ->setRequired('Zadejte nové heslo.')
            ->addRule(Form::MinLength, null, PasswordPolicy::MIN_LENGTH)
            ->setHtmlAttribute('autocomplete', 'new-password')
            ->setOption('description', 'Alespoň 8 znaků, malé i velké písmeno a číslice.');
        $form->addPassword('new_password_confirm', 'Nové heslo znovu')
            ->setRequired('Zopakujte nové heslo.')
            ->addRule(Form::Equal, 'Hesla se neshodují.', $form['new_password'])
            ->setOmitted();
        $form->addSubmit('send', 'Změnit heslo');

        return $form;
    }
}
