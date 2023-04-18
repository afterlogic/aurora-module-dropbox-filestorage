<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\DropboxFilestorage;

/**
 * Adds ability to work with Dropbox file storage inside Aurora Files module.
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
    protected static $sStorageType = 'dropbox';
    protected static $iStorageOrder = 100;
    protected $oClient = null;
    protected $aRequireModules = array(
        'OAuthIntegratorWebclient',
        'DropboxAuthWebclient'
    );

    /**
     * @return Module
     */
    public static function Decorator()
    {
        return parent::Decorator();
    }

    /**
     *
     * @return Settings
     */
    protected function GetModuleSettings()
    {
        return $this->oModuleSettings;
    }

    protected function issetScope($sScope)
    {
        return in_array($sScope, explode(' ', $this->getConfig('Scopes')));
    }

    /***** private functions *****/
    /**
     * Initializes DropBox Module.
     *
     * @ignore
     */
    public function init()
    {
        $this->subscribeEvent('Files::GetStorages::after', array($this, 'onAfterGetStorages'));
        $this->subscribeEvent('Files::GetFile', array($this, 'onGetFile'));
        $this->subscribeEvent('Files::GetItems', array($this, 'onGetItems'));
        $this->subscribeEvent('Files::CreateFolder::after', array($this, 'onAfterCreateFolder'));
        $this->subscribeEvent('Files::CreateFile', array($this, 'onCreateFile'));
        $this->subscribeEvent('Files::Delete::after', array($this, 'onAfterDelete'));
        $this->subscribeEvent('Files::Rename::after', array($this, 'onAfterRename'));
        $this->subscribeEvent('Files::Move::after', array($this, 'onAfterMove'));
        $this->subscribeEvent('Files::Copy::after', array($this, 'onAfterCopy'));
        $this->subscribeEvent('Files::GetFileInfo::after', array($this, 'onAfterGetFileInfo'));
        $this->subscribeEvent('Files::PopulateFileItem::after', array($this, 'onAfterPopulateFileItem'));

        $this->subscribeEvent('Dropbox::GetSettings', array($this, 'onGetSettings'));
        $this->subscribeEvent('Dropbox::UpdateSettings::after', array($this, 'onAfterUpdateSettings'));

        $this->subscribeEvent('Files::GetItems::before', array($this, 'CheckUrlFile'));
        $this->subscribeEvent('Files::UploadFile::before', array($this, 'CheckUrlFile'));
        $this->subscribeEvent('Files::CreateFolder::before', array($this, 'CheckUrlFile'));
        $this->subscribeEvent('Files::CheckQuota::after', array($this, 'onAfterCheckQuota'));
    }

    /**
     * Obtains DropBox client if passed $sType is DropBox account type.
     *
     * @return \Kunnu\Dropbox\Dropbox
     */
    protected function getClient()
    {
        $oDropboxModule = \Aurora\System\Api::GetModule('Dropbox');
        if ($oDropboxModule instanceof \Aurora\System\Module\AbstractModule) {
            if (!$oDropboxModule->getConfig('EnableModule', false) || !$this->issetScope('storage')) {
                return false;
            }
        } else {
            return false;
        }

        if ($this->oClient === null) {
            \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);

            $oOAuthIntegratorWebclientModule = \Aurora\Modules\OAuthIntegratorWebclient\Module::Decorator();
            $oOAuthAccount = $oOAuthIntegratorWebclientModule->GetAccount(self::$sStorageType);
            if ($oOAuthAccount) {
                $oDropboxApp = new \Kunnu\Dropbox\DropboxApp(
                    \Aurora\System\Api::GetModule('Dropbox')->getConfig('Id'),
                    \Aurora\System\Api::GetModule('Dropbox')->getConfig('Secret'),
                    $oOAuthAccount->AccessToken
                );
                $this->oClient = new \Kunnu\Dropbox\Dropbox($oDropboxApp);
            }
        }

        return $this->oClient;
    }

    /**
     * Write to the $mResult variable information about DropBox storage.
     *
     * @ignore
     * @param array $aArgs
     * @param array $mResult
     */
    public function onAfterGetStorages($aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);

        $bEnableDropboxModule = false;
        $oDropboxModule = \Aurora\System\Api::GetModule('Dropbox');
        if ($oDropboxModule instanceof \Aurora\System\Module\AbstractModule) {
            $bEnableDropboxModule = $oDropboxModule->getConfig('EnableModule', false);
        } else {
            $bEnableDropboxModule = false;
        }


        $oOAuthIntegratorWebclientModule = \Aurora\Modules\OAuthIntegratorWebclient\Module::Decorator();
        $oOAuthAccount = $oOAuthIntegratorWebclientModule->GetAccount(self::$sStorageType);

        if ($oOAuthAccount instanceof \Aurora\Modules\OAuthIntegratorWebclient\Models\OauthAccount &&
                $oOAuthAccount->Type === self::$sStorageType &&
                    $this->issetScope('storage') && $oOAuthAccount->issetScope('storage')) {
            $mResult[] = [
                'Type' => self::$sStorageType,
                'IsExternal' => true,
                'DisplayName' => 'Dropbox',
                'Order' => self::$iStorageOrder,
                'IsDroppable' => true
            ];
        }
    }

    /**
     * Returns directory name for the specified path.
     *
     * @param string $sPath Path to the file.
     * @return string
     */
    protected function getDirName($sPath)
    {
        $sPath = dirname($sPath);
        return str_replace(DIRECTORY_SEPARATOR, '/', $sPath);
    }

    /**
     * Returns base name for the specified path.
     *
     * @param string $sPath Path to the file.
     * @return string
     */
    protected function getBaseName($sPath)
    {
        $aPath = explode('/', $sPath);
        return end($aPath);
    }

    /**
     *
     * @param \Aurora\Modules\Files\Classes\FileItem $oItem
     * @return string
     */
    protected function getItemHash($oItem)
    {
        return \Aurora\System\Api::EncodeKeyValues(array(
            'UserId' => \Aurora\System\Api::getAuthenticatedUserId(),
            'Type' => $oItem->TypeStr,
            'Path' => '',
            'Name' => $oItem->FullPath,
            'FileName' => $oItem->Name
        ));
    }

    protected function hasThumb($sName)
    {
        return in_array(
            pathinfo($sName, PATHINFO_EXTENSION),
            ['jpg', 'jpeg', 'png', 'tiff', 'tif', 'gif', 'bmp']
        );
    }

    /**
     * Populates file info.
     *
     * @param \Kunnu\Dropbox\Models\FileMetadata|\Kunnu\Dropbox\Models\FolderMetadata $aData contains information about file.
     * @return \Aurora\Modules\Files\Classes\FileItem|false
     */
    protected function populateFileInfo($aData)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);

        $mResult = false;
        if ($aData) {
            $sPath = ltrim($this->getDirName($aData->getPathDisplay()), '/');

            $mResult /*@var $mResult \Aurora\Modules\Files\Classes\FileItem */ = new  \Aurora\Modules\Files\Classes\FileItem();
            $mResult->IsExternal = true;
            $mResult->TypeStr = self::$sStorageType;
            $mResult->IsFolder = ($aData instanceof \Kunnu\Dropbox\Models\FolderMetadata);
            $mResult->Id = $aData->getName();
            $mResult->Name = $mResult->Id;
            $mResult->Path = !empty($sPath) ? '/'.$sPath : $sPath;
            $mResult->Size = !$mResult->IsFolder ? $aData->getSize() : 0;
            //			$bResult->Owner = $oSocial->Name;
            if (!$mResult->IsFolder) {
                $mResult->LastModified =  date("U", strtotime($aData->getServerModified()));
            }
            //			$mResult->Shared = isset($aData['shared']) ? $aData['shared'] : false;
            $mResult->FullPath = $mResult->Name !== '' ? $mResult->Path . '/' . $mResult->Name : $mResult->Path ;
            $mResult->ContentType = \Aurora\System\Utils::MimeContentType($mResult->Name);

            $mResult->Thumb = $this->hasThumb($mResult->Name);

            if ($mResult->IsFolder) {
                $mResult->AddAction([
                    'list' => []
                ]);
            } else {
                $mResult->AddAction([
                    'view' => [
                        'url' => '?download-file/' . $this->getItemHash($mResult) .'/view'
                    ]
                ]);
                $mResult->AddAction([
                    'download' => [
                        'url' => '?download-file/' . $this->getItemHash($mResult)
                    ]
                ]);
            }
        }
        return $mResult;
    }

    /**
     * Writes to the $mResult variable open file source if $sType is DropBox account type.
     *
     * @ignore
     * @param array $aArgs
     * @param mixed $mResult

     * @return bool|void
     */
    public function onGetFile($aArgs, &$mResult)
    {
        if ($aArgs['Type'] === self::$sStorageType) {
            $oClient = $this->getClient();
            if ($oClient) {
                $sFullPath = $aArgs['Path'] . '/'  .  ltrim($aArgs['Name'], '/');

                if (isset($aArgs['IsThumb']) && (bool)$aArgs['IsThumb'] === true) {
                    $oThumbnail = $oClient->getThumbnail($sFullPath, 'medium', 'png');
                    if ($oThumbnail) {
                        $mResult = \fopen('php://memory', 'r+');
                        \fwrite($mResult, $oThumbnail->getContents());
                        \rewind($mResult);
                    }
                } else {
                    $mDownloadResult = $oClient->download($sFullPath);
                    if ($mDownloadResult) {
                        $mResult = \fopen('php://memory', 'r+');
                        \fwrite($mResult, $mDownloadResult->getContents());
                        \rewind($mResult);
                    }
                }
            }

            return true;
        }
    }

    /**
     * Writes to $aArgs variable list of DropBox files if $aArgs['Type'] is DropBox account type.
     *
     * @ignore
     * @param array $aArgs Is passed by reference.
     * @param mixed $mResult
     *
     * @return bool|void
     */
    public function onGetItems($aArgs, &$mResult)
    {
        if ($aArgs['Type'] === self::$sStorageType) {
            $mResult = array();
            $oClient = $this->getClient();
            if ($oClient) {
                $aItems = array();
                $Path = '/'.ltrim($aArgs['Path'], '/');
                if (empty($aArgs['Pattern'])) {
                    $oListFolderContents = $oClient->listFolder($Path);
                    $oItems = $oListFolderContents->getItems();
                    $aItems = $oItems->all();
                } else {
                    $oListFolderContents = $oClient->search($Path, $aArgs['Pattern']);
                    $oItems = $oListFolderContents->getItems();
                    $aItems = $oItems->all();
                }

                foreach ($aItems as $oChild) {
                    if ($oChild instanceof \Kunnu\Dropbox\Models\SearchResult) {
                        $oChild = $oChild->getMetadata();
                    }
                    $oItem /*@var $oItem \Aurora\Modules\Files\Classes\FileItem */ = $this->populateFileInfo($oChild);
                    if ($oItem) {
                        $mResult[] = $oItem;
                    }
                }
            }

            return true;
        }
    }

    /**
     * Creates folder if $aArgs['Type'] is DropBox account type.
     *
     * @ignore
     * @param array $aArgs
     * @param mixed $mResult
     *
     * @return bool|void
     */
    public function onAfterCreateFolder($aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aArgs['Type'] === self::$sStorageType) {
            $oClient = $this->getClient();
            if ($oClient) {
                $mResult = false;
                $sPath = $aArgs['Path'];

                if ($oClient->createFolder($sPath.'/'.$aArgs['FolderName']) !== null) {
                    $mResult = true;
                }
            }
            return true;
        }
    }

    /**
     * Creates file if $aArgs['Type'] is DropBox account type.
     *
     * @ignore
     * @param array $aArgs
     * @param mixed $mResult
     *
     * @return bool|void
     */
    public function onCreateFile($aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aArgs['Type'] === self::$sStorageType) {
            $oClient = $this->getClient();
            if ($oClient) {
                $mResult = false;

                $Path = $aArgs['Path'].'/'.$aArgs['Name'];
                $rData = $aArgs['Data'];
                if (!is_resource($aArgs['Data'])) {
                    $rData = fopen('php://memory', 'r+');
                    fwrite($rData, $aArgs['Data']);
                    rewind($rData);
                }
                $oDropboxFile = \Kunnu\Dropbox\DropboxFile::createByStream($aArgs['Name'], $rData);
                if ($oClient->upload($oDropboxFile, $Path)) {
                    $mResult = true;
                }

                return true;
            }
        }
    }

    /**
     * Deletes file if $aArgs['Type'] is DropBox account type.
     *
     * @ignore
     * @param array $aArgs
     * @param mixed $mResult
     *
     * @return bool|void
     */
    public function onAfterDelete($aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aArgs['Type'] === self::$sStorageType) {
            $oClient = $this->getClient();
            if ($oClient) {
                $mResult = false;

                foreach ($aArgs['Items'] as $aItem) {
                    $oClient->delete($aItem['Path'].'/'.$aItem['Name']);
                    $mResult = true;
                }
            }
            return true;
        }
    }

    /**
     * Renames file if $aArgs['Type'] is DropBox account type.
     *
     * @ignore
     * @param array $aArgs
     * @param mixed $mResult
     *
     * @return bool|void
     */
    public function onAfterRename($aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aArgs['Type'] === self::$sStorageType) {
            $oClient = $this->getClient();
            if ($oClient) {
                $mResult = false;

                $sPath = $aArgs['Path'];
                if ($oClient->move($sPath.'/'.$aArgs['Name'], $sPath.'/'.$aArgs['NewName'])) {
                    $mResult = true;
                }
            }
        }
    }

    /**
     * Moves file if $aArgs['Type'] is DropBox account type.
     *
     * @ignore
     * @param array $aArgs
     * @param mixed $mResult
     *
     * @return bool|void
     */
    public function onAfterMove($aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aArgs['FromType'] === self::$sStorageType) {
            $oClient = $this->getClient();
            if ($oClient) {
                $mResult = false;

                if ($aArgs['ToType'] === $aArgs['FromType']) {
                    foreach ($aArgs['Files'] as $aFile) {
                        $oClient->move($aArgs['FromPath'].'/'.$aFile['Name'], $aArgs['ToPath'].'/'.$aFile['Name']);
                    }
                    $mResult = true;
                }
            }
            return true;
        }
    }

    /**
     * Copies file if $aArgs['Type'] is DropBox account type.
     *
     * @ignore
     * @param array $aArgs
     * @param mixed $mResult
     *
     * @return bool|void
     */
    public function onAfterCopy($aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($aArgs['FromType'] === self::$sStorageType) {
            $oClient = $this->getClient();
            if ($oClient) {
                $mResult = false;

                if ($aArgs['ToType'] === $aArgs['FromType']) {
                    foreach ($aArgs['Files'] as $aFile) {
                        $oClient->copy($aArgs['FromPath'].'/'.$aFile['Name'], $aArgs['ToPath'].'/'.$aFile['Name']);
                    }
                    $mResult = true;
                }
            }
            return true;
        }
    }

    protected function _getFileInfo($sPath, $sName)
    {
        $mResult = false;
        $oClient = $this->GetClient();
        if ($oClient) {
            $mResult = $oClient->getMetadata($sPath.'/'.$sName);
        }

        return $mResult;
    }


    /**
     * @ignore
     * @todo not used
     * @param array $aArgs
     * @param mixed $mResult
     *
     * @return bool|void
     */
    public function onAfterGetFileInfo($aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);

        if (self::$sStorageType === $aArgs['Type']) {
            $mFileInfo = $this->_getFileInfo($aArgs['Path'], $aArgs['Id']);
            if ($mFileInfo) {
                $mResult = $this->PopulateFileInfo($mFileInfo);
            }
            return true;
        }
    }

    /**
     * @ignore
     * @todo not used
     * @param array $aArgs
     * @param mixed $oItem
     *
     * @return bool|void
     */
    public function onAfterPopulateFileItem($aArgs, &$oItem)
    {
        if ($oItem->IsLink) {
            if (false !== strpos($oItem->LinkUrl, 'dl.dropboxusercontent.com') ||
                    false !== strpos($oItem->LinkUrl, 'dropbox.com')) {
                $aMetadata = $this->getMetadataLink($oItem->LinkUrl);
                if (isset($aMetadata['path']) && $aMetadata['is_dir']) {
                    $oItem->UnshiftAction(array(
                        'list' => array()
                    ));

                    $oItem->Thumb = true;
                    $oItem->ThumbnailUrl = \MailSo\Base\Http::SingletonInstance()->GetFullUrl() . 'modules/' . self::GetName() . '/images/dropbox.png';
                }
                $oItem->LinkType = 'dropbox';
                return true;
            }
        }

        return false;
    }

    protected function getMetadataLink($sLink)
    {
        $oClient = $this->getClient();
        $response = $oClient->postToAPI(
            '/2/sharing/get_shared_link_metadata',
            array(
                'url' => $sLink
            )
        );

        if ($response->getHttpStatusCode() === 404) {
            return null;
        }
        if ($response->getHttpStatusCode() !== 200) {
            return null;
        }

        $metadata = $response->getDecodedBody();
        if (array_key_exists("is_deleted", $metadata) && $metadata["is_deleted"]) {
            return null;
        }
        return $metadata;
    }

    public function CheckUrlFile(&$aArgs, &$mResult)
    {
        if (strpos($aArgs['Path'], '.url') !== false) {
            list($sUrl, $sPath) = explode('.url', $aArgs['Path']);
            $sUrl .= '.url';
            $aArgs['Path'] = $sUrl;
            $this->prepareArgs($aArgs);
            if ($sPath) {
                $aArgs['Path'] .= $sPath;
            }
        }
    }

    protected function prepareArgs(&$aData)
    {
        $aPathInfo = pathinfo($aData['Path']);
        $sExtension = isset($aPathInfo['extension']) ? $aPathInfo['extension'] : '';
        if ($sExtension === 'url') {
            $aArgs = array(
                'UserId' => $aData['UserId'],
                'Type' => $aData['Type'],
                'Path' => $aPathInfo['dirname'],
                'Name' => $aPathInfo['basename'],
                'IsThumb' => false
            );
            $mResult = false;
            \Aurora\System\Api::GetModuleManager()->broadcastEvent(
                'Files',
                'GetFile',
                $aArgs,
                $mResult
            );
            if (is_resource($mResult)) {
                $aUrlFileInfo = \Aurora\System\Utils::parseIniString(stream_get_contents($mResult));
                if ($aUrlFileInfo && isset($aUrlFileInfo['URL'])) {
                    if (false !== strpos($aUrlFileInfo['URL'], 'dl.dropboxusercontent.com') ||
                        false !== strpos($aUrlFileInfo['URL'], 'dropbox.com')) {
                        $aData['Type'] = 'dropbox';
                        $aMetadata = $this->getMetadataLink($aUrlFileInfo['URL']);
                        if (isset($aMetadata['path'])) {
                            $aData['Path'] = $aMetadata['path'];
                        }
                    }
                }
            }
        }
    }
    /***** private functions *****/

    /**
     * Passes data to connect to service.
     *
     * @ignore
     * @param string $aArgs Service type to verify if data should be passed.
     * @param boolean|array $mResult variable passed by reference to take the result.
     */
    public function onGetSettings($aArgs, &$mResult)
    {
        $oUser = \Aurora\System\Api::getAuthenticatedUser();

        if ($oUser) {
            $aScope = array(
                'Name' => 'storage',
                'Description' => $this->i18N('SCOPE_FILESTORAGE'),
                'Value' => false
            );
            if ($oUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin) {
                $aScope['Value'] = $this->issetScope('storage');
                $mResult['Scopes'][] = $aScope;
            }
            if ($oUser->isNormalOrTenant()) {
                if ($aArgs['OAuthAccount'] instanceof \Aurora\Modules\OAuthIntegratorWebclient\Models\OauthAccount) {
                    $aScope['Value'] = $aArgs['OAuthAccount']->issetScope('storage');
                }
                if ($this->issetScope('storage')) {
                    $mResult['Scopes'][] = $aScope;
                }
            }
        }
    }

    public function onAfterUpdateSettings($aArgs, &$mResult)
    {
        $sScope = '';
        if (isset($aArgs['Scopes']) && is_array($aArgs['Scopes'])) {
            foreach ($aArgs['Scopes'] as $aScope) {
                if ($aScope['Name'] === 'storage') {
                    if ($aScope['Value']) {
                        $sScope = 'storage';
                        break;
                    }
                }
            }
        }
        $this->setConfig('Scopes', $sScope);
        $this->saveModuleConfig();
    }

    /**
     * Checks if storage type is personal.
     *
     * @param string $Type Storage type.
     * @return bool
     */
    protected function checkStorageType($Type)
    {
        return $Type === static::$sStorageType;
    }

    /**
     * @ignore
     * @param array $aArgs Arguments of event.
     * @param mixed $mResult Is passed by reference.
     */
    public function onAfterCheckQuota($aArgs, &$mResult)
    {
        $Type = $aArgs['Type'];
        if ($this->checkStorageType($Type)) {
            $mResult = true;
            return true;
        }
    }
}
