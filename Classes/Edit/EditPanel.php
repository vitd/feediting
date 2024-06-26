<?php
declare(strict_types=1);

namespace GeorgRinger\Feediting\Edit;

use GeorgRinger\Feediting\Event\EditPanelActionEvent;
use GeorgRinger\Feediting\Service\AccessService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\FrontendBackendUserAuthentication;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

class EditPanel
{

    protected Permissions $permissions;
    protected bool $enabled = false;
    protected int $permissionsOfPage;
    protected EventDispatcherInterface $eventDispatcher;

    public function __construct(
        protected ServerRequestInterface $request,
        protected string $tableName,
        protected int $recordId,
        protected array $row
    )
    {
        if (!$this->getBackendUser()) {
            return;
        }
        $accessService = GeneralUtility::makeInstance(AccessService::class);
        if (!$accessService->enabled()) {
            return;
        }

        $this->enabled = true;
        $this->eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        $this->permissions = GeneralUtility::makeInstance(Permissions::class);
        $pageRow = $this->request->getAttribute('frontend.controller')->page;
//        $moduleName = BackendUtility::getPagesTSconfig($row['pid'])['mod.']['newContentElementWizard.']['override'] ?? 'new_content_element_wizard';
//        $perms = $this->getBackendUser()->calcPerms($tsfe->page);
        $this->permissionsOfPage = $this->getBackendUser()->calcPerms($pageRow);
    }

    public function render(string $content): string
    {
        if (!$this->enabled) {
            return '';
        }
        $isPageTable = $this->tableName === 'pages';
        $allowed = $isPageTable ? $this->permissions->editPage($this->row['pid']) : $this->permissions->editElement($this->tableName, $this->row);
        if (!$allowed) {
            return '';
        }

        return $this->renderPanel($content);
    }

    protected function collectActions(): array
    {
        $data = [];

        $event = $this->eventDispatcher->dispatch(
            new EditPanelActionEvent(
                $this->request,
                $this->permissionsOfPage,
                $this->tableName,
                $this->recordId,
                $this->row, $data),

        );
        return $event->getActions();
    }

    protected function renderPanel(string $content): string
    {
        $data = $this->collectActions();
        if (empty($data)) {
            return '';
        }

        $assetCollector = GeneralUtility::makeInstance(AssetCollector::class);
        $assetCollector->addStylesheet('feediting', 'EXT:feediting/Resources/Public/Styles/basic.css');

        array_walk($data, static function (string &$value) {
            $value = '<span class="tx-feediting-element">' . $value . '</span>';
        });

        $infos = [
            BackendUtility::getRecordTitle($this->tableName, $this->row),
        ];
        if ($this->tableName === 'tt_content') {
            $infos[] = BackendUtility::getProcessedValue($this->tableName, 'CType', $this->row['CType']);
        }

        $identifier = 'trigger' . md5(json_encode($data));
        $panel = '
<div class="popover-container ' . $this->tableName . '">
  <button class="feediting-popover-trigger" title="' . htmlspecialchars(implode(LF, $infos)) . ' [#' . $this->recordId  . ']">
  <img src="' . htmlspecialchars(PathUtility::getPublicResourceWebPath('EXT:feediting/Resources/Public/Icons/pen-solid.svg')) . '">
</button>

<div class="tx-feediting-panel ' . $this->tableName . '"><div class="tx-feediting-actions">' . implode(LF, $data) . '</div></div>
</div>';
        return '<div id="tx-feediting' . $this->row['uid'] . '" class="tx-feediting-fluidtemplate tx-feediting-fluidtemplate-' . $this->tableName . '">' . $content . $panel . '</div>';
    }

    protected function getBackendUser(): ?FrontendBackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

}
