<?php

declare(strict_types=1);

namespace App\Forms;

use Nette\Application\UI\Form;

final class SettingsFormFactory
{
    public function __construct(
        private readonly FormFactory $factory,
    ) {
    }

    public function create(): Form
    {
        $form = $this->factory->create();

        $form->addText('company_name', 'Název společnosti')->setRequired()->setMaxLength(200);
        $form->addText('company_street', 'Ulice')->setMaxLength(200);
        $form->addText('company_city', 'Město')->setMaxLength(100);
        $form->addText('company_zip', 'PSČ')->setMaxLength(10);
        $form->addText('company_ico', 'IČO')->setMaxLength(10);
        $form->addText('company_dic', 'DIČ')->setMaxLength(14);
        $form->addText('company_phone', 'Telefon')->setMaxLength(32);
        $form->addEmail('company_email', 'E-mail')->setMaxLength(180);
        $form->addText('company_web', 'Web')->setMaxLength(200);
        $form->addText('company_bank_account', 'Bankovní účet')->setMaxLength(50);

        FieldHelper::addMoney($form, 'default_hourly_rate', 'Výchozí hodinová sazba (bez DPH)', required: true);
        FieldHelper::addPercent($form, 'vat_rate', 'Sazba DPH %')->setRequired('Zadejte sazbu DPH.');
        $form->addInteger('default_due_days', 'Výchozí lhůta opravy (pracovní dny)')
            ->setRequired()
            ->addRule(Form::Range, 'Zadejte %d–%d dní.', [0, 60]);
        $form->addTextArea('document_footer', 'Patička dokumentů', null, 3)->setMaxLength(1000);

        $form->addSubmit('send', 'Uložit nastavení');

        return $form;
    }
}
