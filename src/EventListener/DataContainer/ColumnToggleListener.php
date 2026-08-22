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

namespace Markocupic\ContaoBackendColumnToggle\EventListener\DataContainer;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\CoreBundle\String\HtmlAttributes;
use Markocupic\ContaoBackendColumnToggle\Controller\BackendController\ColumnToggleController;
use Markocupic\ContaoBackendColumnToggle\Session\ColumnVisibilityStorage;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Removes the columns the user has hidden from the list view and injects the
 * data element the Stimulus controller (cbct--column-toggle) is bound to.
 *
 * The element is added as a global operation, because this is the only
 * extension point that allows adding markup to a list view without overriding
 * a core template.
 */
/*
 * Runs late on purpose: Contao's DefaultLabelsListener (priority -255) adds
 * the missing field labels, and DefaultGlobalOperationsListener (priority 200)
 * rebuilds the global operations array.
 */
#[AsHook('loadDataContainer', priority: -300)]
class ColumnToggleListener
{
    /**
     * Name of the DCA key the untouched list of label fields is stored in, so
     * the AJAX controller is able to validate the submitted field names.
     */
    public const DCA_KEY_ALL_FIELDS = 'columnToggleFields';

    public const OPERATION_NAME = 'columnToggle';

    private const ASSET_PATH = 'bundles/markocupiccontaobackendcolumntoggle';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ScopeMatcher $scopeMatcher,
        private readonly Security $security,
        private readonly ColumnVisibilityStorage $storage,
        private readonly TranslatorInterface $translator,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(string $table): void
    {
        if (!$this->isBackendUserRequest()) {
            return;
        }

        $fields = $GLOBALS['TL_DCA'][$table]['list']['label']['fields'] ?? null;

        // The header row only exists if the "showColumns" option is enabled
        if (!($GLOBALS['TL_DCA'][$table]['list']['label']['showColumns'] ?? false) || !\is_array($fields) || [] === $fields) {
            return;
        }

        // Field names may be defined as "field:relatedTable.field"
        $allFields = [];

        foreach ($fields as $field) {
            if (!\is_string($field) || '' === $field) {
                continue;
            }

            $name = explode(':', $field, 2)[0];
            $allFields[$name] = $this->getFieldLabel($table, $name);
        }

        if ([] === $allFields) {
            return;
        }

        // Remember the untouched list for the AJAX controller
        $GLOBALS['TL_DCA'][$table]['list']['label'][self::DCA_KEY_ALL_FIELDS] = $allFields;

        $hidden = array_values(array_intersect($this->storage->getHiddenColumns($table), array_keys($allFields)));

        // Never hide all columns
        while (\count($hidden) >= \count($allFields)) {
            array_shift($hidden);
        }

        if ([] !== $hidden) {
            $GLOBALS['TL_DCA'][$table]['list']['label']['fields'] = array_values(
                array_filter(
                    $fields,
                    static fn ($field) => !\in_array(explode(':', (string) $field, 2)[0], $hidden, true),
                ),
            );

            // Otherwise Contao would silently re-add the current sorting field
            // as the last column (see DC_Table::listView())
            $GLOBALS['TL_DCA'][$table]['list']['label']['showFirstOrderBy'] = false;
        }

        $this->addGlobalOperation($table, $allFields, $hidden);
        $this->addAssets();
    }

    /**
     * @param array<string, string> $allFields
     * @param array<int, string>    $hidden
     */
    private function addGlobalOperation(string $table, array $allFields, array $hidden): void
    {
        $columns = [];

        foreach ($allFields as $name => $label) {
            $columns[] = ['name' => $name, 'label' => $label];
        }

        $attributes = (new HtmlAttributes())
            ->addClass('cbct-column-toggle-data')
            ->set('style', 'display:none')
            ->set('data-controller', 'cbct--column-toggle')
            ->set('data-cbct--column-toggle-table-value', $table)
            ->set('data-cbct--column-toggle-url-value', $this->urlGenerator->generate(ColumnToggleController::class))
            ->set('data-cbct--column-toggle-token-value', $this->csrfTokenManager->getDefaultTokenValue())
            ->set('data-cbct--column-toggle-columns-value', json_encode($columns, JSON_THROW_ON_ERROR))
            ->set('data-cbct--column-toggle-hidden-value', json_encode($hidden, JSON_THROW_ON_ERROR))
            ->set('data-cbct--column-toggle-i18n-value', json_encode($this->getTranslations(), JSON_THROW_ON_ERROR))
        ;

        $html = \sprintf('<div%s></div>', $attributes);

        $GLOBALS['TL_DCA'][$table]['list']['global_operations'][self::OPERATION_NAME] = [
            'showOnSelect' => false,
            'button_callback' => static function (DataContainerOperation $operation) use ($html): void {
                $operation->setHtml($html);
                $operation['primary'] = true;
                $operation['listAttributes'] = (new HtmlAttributes())->set('style', 'display:none');
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getTranslations(): array
    {
        $keys = ['columns', 'columnsTitle', 'hideColumn', 'showAll', 'lastColumn', 'pendingReload', 'error'];
        $translations = [];

        foreach ($keys as $key) {
            $translations[$key] = $this->translator->trans('MSC.columnToggle.'.$key, [], 'contao_default');
        }

        return $translations;
    }

    private function getFieldLabel(string $table, string $field): string
    {
        $label = $GLOBALS['TL_DCA'][$table]['fields'][$field]['label'] ?? null;

        if (\is_array($label)) {
            $label = $label[0] ?? null;
        }

        if (\is_string($label) && '' !== $label) {
            return $label;
        }

        $key = $table.'.'.$field.'.0';
        $translated = $this->translator->trans($key, [], 'contao_'.$table);

        return $translated !== $key ? $translated : $field;
    }

    private function addAssets(): void
    {
        $GLOBALS['TL_JAVASCRIPT']['markocupic_contao_backend_column_toggle'] = self::ASSET_PATH.'/backend.js|static';
        $GLOBALS['TL_CSS']['markocupic_contao_backend_column_toggle'] = self::ASSET_PATH.'/backend.css|static';
    }

    private function isBackendUserRequest(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || !$this->scopeMatcher->isBackendRequest($request)) {
            return false;
        }

        return $this->security->getUser() instanceof BackendUser;
    }
}
