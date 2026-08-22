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

namespace Markocupic\ContaoBackendColumnToggle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class MarkocupicContaoBackendColumnToggle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
