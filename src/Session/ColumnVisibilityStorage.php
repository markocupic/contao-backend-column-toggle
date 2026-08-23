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

namespace Markocupic\ContaoBackendColumnToggle\Session;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;

/**
 * Reads and writes the per-user column visibility settings.
 *
 * The data is stored in Contao's "contao_backend" session bag. Contao's
 * UserSessionListener loads that bag from the serialized "tl_user.session"
 * blob on 'kernel.request' and writes the complete bag back to that column on
 * kernel.response. So writing to the bag is what persists the setting in
 * tl_user.session — writing to the column directly would be overwritten by
 * that very listener at the end of the same request.
 *
 * Structure inside the bag:
 *
 *   'BE_COLUMN_TOGGLE' => [
 *       'tl_news' => ['date', 'author'],
 *       'tl_member' => ['dateAdded'],
 *   ]
 */
class ColumnVisibilityStorage
{
    public const SESSION_KEY = 'BE_COLUMN_TOGGLE';

    private const BAG_NAME = 'contao_backend';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * @return array<int, string> the hidden field names of the given table
     */
    public function getHiddenColumns(string $table): array
    {
        $data = $this->all();

        if (!\is_array($data[$table] ?? null)) {
            return [];
        }

        return $this->normalize($data[$table]);
    }

    /**
     * @param array<int, string> $columns the field names that should be hidden
     */
    public function setHiddenColumns(string $table, array $columns): void
    {
        if (null === ($bag = $this->getSessionBag())) {
            return;
        }

        $columns = $this->normalize($columns);
        $data = $this->all();

        if ([] === $columns) {
            unset($data[$table]);
        } else {
            $data[$table] = $columns;
        }

        if ([] === $data) {
            $bag->remove(self::SESSION_KEY);
        } else {
            $bag->set(self::SESSION_KEY, $data);
        }
    }

    public function resetTable(string $table): void
    {
        $this->setHiddenColumns($table, []);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function all(): array
    {
        $data = $this->getSessionBag()?->get(self::SESSION_KEY);

        return \is_array($data) ? $data : [];
    }

    private function getSessionBag(): AttributeBagInterface|null
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || !$request->hasSession()) {
            return null;
        }

        $bag = $request->getSession()->getBag(self::BAG_NAME);

        return $bag instanceof AttributeBagInterface ? $bag : null;
    }

    /**
     * @param array<int|string, mixed> $columns
     *
     * @return array<int, string>
     */
    private function normalize(array $columns): array
    {
        $columns = array_map(static fn ($v) => \is_scalar($v) ? (string) $v : '', $columns);
        $columns = array_filter($columns, static fn (string $v) => '' !== $v);

        return array_values(array_unique($columns));
    }
}
