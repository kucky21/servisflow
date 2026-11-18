<?php

declare(strict_types=1);

namespace App\Forms;

use App\Model\Enum\CustomerType;
use Nette\Application\UI\Form;

final class CustomerFormFactory
{
    public function __construct(
        private readonly FormFactory $factory,
    ) {
    }

    public function create(): Form
    {
        $form = $this->factory->create();

        $type = $form->addRadioList('type', 'Typ zákazníka', CustomerType::options())
            ->setDefaultValue(CustomerType::Person->value)
            ->setRequired();
        $form->addText('name', 'Jméno / název firmy')
            ->setRequired('Vyplňte jméno nebo název.')
            ->setMaxLength(200);
        $form->addText('contact_person', 'Kontaktní osoba')
            ->setMaxLength(150)
            ->setNullable();
        $email = $form->addEmail('email', 'E-mail')
            ->setMaxLength(180)
            ->setNullable();
        $phone = $form->addText('phone', 'Telefon')
            ->setHtmlType('tel')
            ->setMaxLength(32)
            ->setNullable();
        $form->addText('street', 'Ulice a č. p.')->setMaxLength(200)->setNullable();
        $form->addText('city', 'Město')->setMaxLength(100)->setNullable();
        $form->addText('zip', 'PSČ')->setMaxLength(10)->setNullable()
            ->addCondition(Form::Filled)
            ->addRule(Form::Pattern, 'PSČ má tvar 123 45.', '\s*\d{3}\s?\d{2}\s*');
        $ico = $form->addText('ico', 'IČO')->setMaxLength(10)->setNullable();
        $ico->addCondition(Form::Filled)
            ->addRule(Form::Pattern, 'IČO má 8 číslic.', '\s*\d{8}\s*');
        $form->addText('dic', 'DIČ')->setMaxLength(14)->setNullable();
        $form->addTextArea('note', 'Poznámka', null, 3)->setMaxLength(5000)->setNullable();

        // Klientská i serverová validace: alespoň jeden kontakt, u firmy IČO
        $email->addConditionOn($phone, Form::Blank)
            ->setRequired('Zadejte alespoň e-mail nebo telefon.');
        $ico->addConditionOn($type, Form::Equal, CustomerType::Company->value)
            ->setRequired('U firmy vyplňte IČO.');

        $form->addSubmit('send', 'Uložit zákazníka');

        return $form;
    }
}
