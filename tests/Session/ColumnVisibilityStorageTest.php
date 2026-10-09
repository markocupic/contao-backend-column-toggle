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

namespace Markocupic\ContaoBackendColumnToggle\Tests\Session;

use Markocupic\ContaoBackendColumnToggle\Session\ColumnVisibilityStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class ColumnVisibilityStorageTest extends TestCase
{
    public function testReturnsAnEmptyListWithoutSession(): void
    {
        $storage = new ColumnVisibilityStorage(new RequestStack());

        $this->assertSame([], $storage->getHiddenColumns('tl_test'));

        // Must not throw
        $storage->setHiddenColumns('tl_test', ['title']);
    }

    public function testStoresTheHiddenColumnsPerTable(): void
    {
        [$storage, $bag] = $this->createStorage();

        $storage->setHiddenColumns('tl_a', ['title', 'title', '', 'alias']);
        $storage->setHiddenColumns('tl_b', ['date']);

        $this->assertSame(['title', 'alias'], $storage->getHiddenColumns('tl_a'));
        $this->assertSame(['date'], $storage->getHiddenColumns('tl_b'));
        $this->assertSame([], $storage->getHiddenColumns('tl_c'));
        $this->assertSame(['tl_a' => ['title', 'alias'], 'tl_b' => ['date']], $bag->get(ColumnVisibilityStorage::SESSION_KEY));
    }

    public function testRemovesTheSessionKeyIfNothingIsHidden(): void
    {
        [$storage, $bag] = $this->createStorage();

        $storage->setHiddenColumns('tl_a', ['title']);
        $storage->resetTable('tl_a');

        $this->assertSame([], $storage->getHiddenColumns('tl_a'));
        $this->assertFalse($bag->has(ColumnVisibilityStorage::SESSION_KEY));
    }

    /**
     * @return array{0: ColumnVisibilityStorage, 1: AttributeBag}
     */
    private function createStorage(): array
    {
        $bag = new AttributeBag('_contao_be_attributes');
        $bag->setName('contao_backend');

        $session = new Session(new MockArraySessionStorage());
        $session->registerBag($bag);

        $request = new Request();
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return [new ColumnVisibilityStorage($requestStack), $bag];
    }
}
