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

namespace Markocupic\ContaoBackendColumnToggle\Tests\Controller;

use Contao\BackendUser;
use Contao\Controller;
use Contao\TestCase\ContaoTestCase;
use Markocupic\ContaoBackendColumnToggle\Controller\BackendController\ColumnToggleController;
use Markocupic\ContaoBackendColumnToggle\EventListener\DataContainer\ColumnToggleListener;
use Markocupic\ContaoBackendColumnToggle\Session\ColumnVisibilityStorage;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class ColumnToggleControllerTest extends ContaoTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);

        parent::tearDown();
    }

    public function testDeniesAccessWithoutBackendUser(): void
    {
        $request = $this->createRequest(['table' => 'tl_test']);
        $response = $this->createController($request, false)($request);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testRejectsInvalidTableNames(): void
    {
        $request = $this->createRequest(['table' => 'tl_test;DROP']);
        $response = $this->createController($request)($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testRejectsTablesWithoutToggleableColumns(): void
    {
        $request = $this->createRequest(['table' => 'tl_other']);
        $response = $this->createController($request)($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testStoresOnlyKnownColumnsAndNeverHidesAllColumns(): void
    {
        $request = $this->createRequest(['table' => 'tl_test', 'hidden' => ['title', 'unknown', 'alias', 'date']]);
        $response = $this->createController($request)($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // "unknown" is ignored and one of the three columns stays visible
        $this->assertSame(['alias', 'date'], $data['hidden']);
        $this->assertSame(['alias', 'date'], $this->createStorage($request)->getHiddenColumns('tl_test'));
    }

    private function createController(Request $request, bool $authenticated = true): ColumnToggleController
    {
        $controllerAdapter = $this->mockAdapter(['loadDataContainer']);
        $controllerAdapter
            ->method('loadDataContainer')
            ->willReturnCallback(
                static function (string $table): void {
                    if ('tl_test' === $table) {
                        $GLOBALS['TL_DCA']['tl_test']['list']['label'][ColumnToggleListener::DCA_KEY_ALL_FIELDS] = [
                            'title' => 'Title',
                            'alias' => 'Alias',
                            'date' => 'Date',
                        ];
                    }
                },
            )
        ;

        $framework = $this->mockContaoFramework([Controller::class => $controllerAdapter]);

        $security = $this->createMock(Security::class);
        $security
            ->method('getUser')
            ->willReturn($authenticated ? $this->createMock(BackendUser::class) : null)
        ;

        return new ColumnToggleController($framework, $security, $this->createStorage($request));
    }

    private function createStorage(Request $request): ColumnVisibilityStorage
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new ColumnVisibilityStorage($requestStack);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function createRequest(array $post): Request
    {
        $bag = new AttributeBag('_contao_be_attributes');
        $bag->setName('contao_backend');

        $session = new Session(new MockArraySessionStorage());
        $session->registerBag($bag);

        $request = new Request([], $post);
        $request->setSession($session);

        return $request;
    }
}
