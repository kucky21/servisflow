<?php

declare(strict_types=1);

namespace App\Forms;

use App\Model\Enum\UserRole;
use App\Security\PasswordPolicy;
use Nette\Application\UI\Form;

final class UserFormFactory
{
    public function __construct(
        private readonly FormFactory $factory,
    ) {
    }

    public function create(bool $isNew): Form
    {
        $form = $this->factory->create();
        $form->addText('first_name', 'Jméno')->setRequired('Vyplňte jméno.')->setMaxLength(100);
        $form->addText('last_name', 'Příjmení')->setRequired('Vyplňte příjmení.')->setMaxLength(100);
        $form->addEmail('email', 'E-mail (přihlašovací jméno)')->setRequired('Vyplňte e-mail.')->setMaxLength(180);
        $form->addText('phone', 'Telefon')->setHtmlType('tel')->setMaxLength(32)->setNullable();
        $form->addSelect('role', 'Role', UserRole::options())
            ->setDefaultValue(UserRole::Technician->value)
            ->setRequired();
        $password = $form->addPassword('password', $isNew ? 'Heslo' : 'Nové heslo')
            ->setNullable()
            ->setHtmlAttribute('autocomplete', 'new-password')
            ->setOption('description', $isNew
                ? 'Alespoň 8 znaků, malé i velké písmeno a číslice.'
                : 'Vyplňte pouze při změně hesla.');
        if ($isNew) {
            $password->setRequired('Zadejte heslo.');
        }
        $password->addCondition(Form::Filled)->addRule(Form::MinLength, null, PasswordPolicy::MIN_LENGTH);
        $form->addCheckbox('is_active', 'Aktivní účet')->setDefaultValue(true);
        $form->addSubmit('send', $isNew ? 'Vytvořit uživatele' : 'Uložit změny');

        return $form;
    }
}
