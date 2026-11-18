<?php

declare(strict_types=1);

namespace App\Presentation\Audit;

use App\DTO\Filter\AuditFilter;
use App\Presentation\BasePresenter;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use App\Security\RequirePermission;

#[RequirePermission('audit', 'view')]
final class AuditPresenter extends BasePresenter
{
    public function __construct(
        private readonly AuditLogRepository $auditLogs,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    public function renderDefault(?string $entity = null, ?int $userId = null, ?string $action = null, int $page = 1): void
    {
        $filter = new AuditFilter($entity, $userId, $action);
        $selection = $this->auditLogs->search($filter);
        $paginator = $this->createPaginator($selection->count('*'), $page, 50);

        $userOptions = [];
        foreach ($this->users->findAllOrdered() as $row) {
            $userOptions[(int) $row->id] = $row->first_name . ' ' . $row->last_name;
        }

        $this->template->entries = $selection->limit($paginator->getLength(), $paginator->getOffset())->fetchAll();
        $this->template->paginator = $paginator;
        $this->template->filter = $filter;
        $this->template->entityTypes = $this->auditLogs->getEntityTypes();
        $this->template->userOptions = $userOptions;

        if ($this->isAjax()) {
            $this->redrawControl('list');
        }
    }
}
