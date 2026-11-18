<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\DomainException;
use App\Exceptions\EntityNotFoundException;
use App\Exceptions\ValidationException;
use App\Forms\FormFactory;
use App\Model\Enum\UserRole;
use App\Repositories\InventoryItemRepository;
use App\Security\Actor;
use App\Security\RequirePermission;
use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;
use Nette\DI\Attributes\Inject;
use Nette\Utils\Paginator;

/**
 * Základ všech chráněných presenterů.
 *
 *  - nepřihlášený uživatel je přesměrován na přihlášení (s návratem zpět),
 *  - oprávnění se deklarují atributem #[RequirePermission] na třídě nebo metodě,
 *  - doménové výjimky se převádějí na toast / chyby formuláře,
 *  - u AJAX požadavků se notifikace posílají v payloadu.
 */
abstract class BasePresenter extends Presenter
{
    protected const int PER_PAGE = 20;

    #[Inject]
    public InventoryItemRepository $inventoryRepository;

    private ?Actor $actor = null;

    public function checkRequirements(\ReflectionClass|\ReflectionMethod $element): void
    {
        parent::checkRequirements($element);

        if ($element instanceof \ReflectionClass && !$this->getUser()->isLoggedIn()) {
            if ($this->getUser()->getLogoutReason() === \Nette\Security\User::LogoutInactivity) {
                $this->flashMessage('Byli jste odhlášeni z důvodu nečinnosti.', 'warning');
            }
            $this->redirect('Sign:in', ['backlink' => $this->storeRequest()]);
        }

        foreach ($element->getAttributes(RequirePermission::class) as $attribute) {
            $permission = $attribute->newInstance();
            if (!$this->getUser()->isAllowed($permission->resource, $permission->privilege)) {
                $this->error('K této části systému nemáte oprávnění.', 403);
            }
        }
    }

    protected function beforeRender(): void
    {
        parent::beforeRender();

        $actor = $this->getActor();
        $this->template->actor = $actor;
        $this->template->roleLabel = $actor->role->label();
        $this->template->lowStockCount = $actor->isManagerial() ? $this->inventoryRepository->countLowStock() : 0;
    }

    protected function getActor(): Actor
    {
        if ($this->actor !== null) {
            return $this->actor;
        }

        $identity = $this->getUser()->getIdentity();
        if ($identity === null) {
            $this->redirect('Sign:in');
        }

        $data = $identity->getData();

        return $this->actor = new Actor(
            id: (int) $identity->getId(),
            role: UserRole::from((string) ($data['role'] ?? UserRole::Technician->value)),
            name: trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? '')),
        );
    }

    protected function isAllowed(string $resource, string $privilege): bool
    {
        return $this->getUser()->isAllowed($resource, $privilege);
    }

    /**
     * Notifikace: u klasického požadavku flash zpráva, u AJAX požadavku položka v payloadu.
     */
    public function toast(string $message, string $type = 'success'): void
    {
        if ($this->isAjax()) {
            $toasts = $this->payload->toasts ?? [];
            $toasts[] = ['message' => $message, 'type' => $type];
            $this->payload->toasts = $toasts;
        } else {
            $this->flashMessage($message, $type);
        }
    }

    /**
     * Spustí doménovou operaci a převede výjimky na uživatelské hlášky.
     * Vrací true při úspěchu.
     */
    protected function runAction(callable $action, ?Form $form = null, ?string $successMessage = null): bool
    {
        try {
            $action();
            if ($successMessage !== null) {
                $this->toast($successMessage);
            }

            return true;
        } catch (ValidationException $e) {
            if ($form !== null) {
                FormFactory::applyErrors($form, $e);
            } else {
                $this->toast($e->getMessage(), 'danger');
            }
        } catch (EntityNotFoundException) {
            $this->toast('Záznam nebyl nalezen. Možná jej mezitím někdo smazal.', 'danger');
        } catch (AccessDeniedException $e) {
            $this->toast($e->getMessage(), 'danger');
        } catch (DomainException $e) {
            // U formuláře se chyba zobrazí v něm (a u AJAX formulářů i jako toast přes onError)
            if ($form !== null) {
                $form->addError($e->getMessage());
            } else {
                $this->toast($e->getMessage(), 'danger');
            }
        }

        return false;
    }

    /**
     * Po úspěšné akci: AJAX → překreslení snippetů, jinak redirect (PRG).
     *
     * @param list<string> $snippets
     */
    protected function finishAction(array $snippets, string $destination = 'this', mixed ...$args): void
    {
        if ($this->isAjax()) {
            foreach ($snippets as $snippet) {
                $this->redrawControl($snippet);
            }
            $this->payload->success = true;

            return;
        }

        $this->redirect($destination, ...$args);
    }

    /**
     * AJAX formulář: při chybě validace překreslit jeho snippet, aby se zobrazily chyby u polí.
     */
    protected function redrawOnError(Form $form, string $snippet): Form
    {
        $form->onError[] = function (Form $form) use ($snippet): void {
            if ($this->isAjax()) {
                $this->redrawControl($snippet);
                $this->payload->formErrors = true;
                foreach (array_unique(array_map(strval(...), $form->getErrors())) as $error) {
                    $this->toast($error, 'danger');
                }
            }
        };

        return $form;
    }

    protected function createPaginator(int $itemCount, int $page, int $perPage = self::PER_PAGE): Paginator
    {
        $paginator = new Paginator();
        $paginator->setItemsPerPage($perPage);
        $paginator->setItemCount($itemCount);
        $paginator->setPage(max(1, $page));

        return $paginator;
    }

    #[Requires(methods: 'POST')]
    public function handleLogout(): void
    {
        $this->getUser()->logout(true);
        $this->getSession()->regenerateId();
        $this->flashMessage('Byli jste bezpečně odhlášeni.', 'info');
        $this->redirect('Sign:in');
    }
}
