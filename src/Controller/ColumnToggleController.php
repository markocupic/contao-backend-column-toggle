<?php

declare(strict_types=1);

/*
 * This file is part of Contao Backend Column Toggle.
 *
 * (c) Marko Cupic <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/contao-backend-column-toggle
 */

namespace Markocupic\ContaoBackendColumnToggle\Controller;

use Contao\BackendUser;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Markocupic\ContaoBackendColumnToggle\EventListener\DataContainer\ColumnToggleListener;
use Markocupic\ContaoBackendColumnToggle\Session\ColumnVisibilityStorage;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stores the hidden columns of a back end list view in tl_user.session.
 *
 * Expects an "application/x-www-form-urlencoded" POST request, so Contao's
 * RequestTokenListener is able to validate the REQUEST_TOKEN parameter:
 *
 *   REQUEST_TOKEN=...&table=tl_news&hidden[]=date&hidden[]=author
 */
#[Route(
    '%contao.backend.route_prefix%/column-toggle',
    name: ColumnToggleController::class,
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['POST'],
)]
class ColumnToggleController
{
    public function __construct(
        private readonly ColumnVisibilityStorage $storage,
        private readonly ContaoFramework $framework,
        private readonly ScopeMatcher $scopeMatcher,
        private readonly Security $security,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->scopeMatcher->isBackendRequest($request)) {
            throw new \RuntimeException('This controller can only be called from the backend.');
        }

        if (!$this->security->getUser() instanceof BackendUser) {
            throw new \RuntimeException('This controller can only be called by logged in backend users.');
        }

        $table = (string) $request->request->get('table', '');

        if ('' === $table || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return new JsonResponse(['error' => 'Invalid table name.'], Response::HTTP_BAD_REQUEST);
        }

        $this->framework->initialize();

        $allFields = $this->getToggleableFields($table);

        if ([] === $allFields) {
            return new JsonResponse(['error' => \sprintf('Table "%s" has no toggleable columns.', $table)], Response::HTTP_BAD_REQUEST);
        }

        $submitted = $request->request->all('hidden');
        $submitted = array_map(static fn ($v) => \is_scalar($v) ? (string) $v : '', $submitted);

        // Only accept field names that really are part of the list view
        $hidden = array_values(array_unique(array_intersect($submitted, $allFields)));

        // Never hide all columns
        while (\count($hidden) >= \count($allFields)) {
            array_shift($hidden);
        }

        $this->storage->setHiddenColumns($table, $hidden);

        return new JsonResponse([
            'success' => true,
            'table' => $table,
            'hidden' => $hidden,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function getToggleableFields(string $table): array
    {
        $dca = $this->getDca($table);

        $fields = $dca['list']['label'][ColumnToggleListener::DCA_KEY_ALL_FIELDS] ?? null;

        return \is_array($fields) ? array_keys($fields) : [];
    }

    private function getDca(string $table): array
    {
        $controller = $this->framework->getAdapter(Controller::class);
        $controller->loadDataContainer($table);

        return $GLOBALS['TL_DCA'][$table];
    }
}
