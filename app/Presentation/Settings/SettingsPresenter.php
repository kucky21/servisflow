<?php

declare(strict_types=1);

namespace App\Presentation\Settings;

use App\Forms\SettingsFormFactory;
use App\Presentation\BasePresenter;
use App\Security\RequirePermission;
use App\Services\SettingsService;
use Nette\Application\UI\Form;

#[RequirePermission('settings', 'edit')]
final class SettingsPresenter extends BasePresenter
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SettingsFormFactory $formFactory,
    ) {
        parent::__construct();
    }

    protected function createComponentSettingsForm(): Form
    {
        $form = $this->formFactory->create();
        $defaults = $this->settings->getAll();
        foreach (['default_hourly_rate', 'vat_rate'] as $key) {
            if (isset($defaults[$key])) {
                $defaults[$key] = str_replace('.', ',', $defaults[$key]);
            }
        }
        $form->setDefaults($defaults);

        $form->onSuccess[] = function (Form $form, array $values): void {
            $values['default_hourly_rate'] = str_replace([' ', ','], ['', '.'], (string) $values['default_hourly_rate']);
            $values['vat_rate'] = str_replace([' ', ','], ['', '.'], (string) $values['vat_rate']);
            $stringValues = array_map(static fn (mixed $v): string => (string) $v, $values);

            $ok = $this->runAction(
                fn () => $this->settings->update($stringValues, $this->getActor()),
                $form,
                'Nastavení bylo uloženo. Nové sazby se použijí pro nově přijaté zakázky.',
            );
            if ($ok) {
                $this->redirect('this');
            }
        };

        return $form;
    }
}
