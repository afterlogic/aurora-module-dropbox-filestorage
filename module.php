<?php
/**
 * @copyright Copyright (c) 2016, Afterlogic Corp.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 * 
 * @package Modules
 */

class DropboxFilestorageModule extends AApiModule
{
	protected static $sService = 'dropbox';
	protected $oClient = null;
	protected $aRequireModules = array(
		'OAuthIntegratorWebclient', 
		'DropboxAuthWebclient'
	);
	
	protected $aSettingsMap = array(
		'Scopes' => array('storage', 'string')
	);
	
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
		$this->subscribeEvent('Files::GetFiles::after', array($this, 'onAfterGetFiles'));
		$this->subscribeEvent('Files::CreateFolder::after', array($this, 'onAfterCreateFolder'));
		$this->subscribeEvent('Files::CreateFile', array($this, 'onCreateFile'));
		$this->subscribeEvent('Files::Delete::after', array($this, 'onAfterDelete'));
		$this->subscribeEvent('Files::Rename::after', array($this, 'onAfterRename'));
		$this->subscribeEvent('Files::Move::after', array($this, 'onAfterMove'));
		$this->subscribeEvent('Files::Copy::after', array($this, 'onAfterCopy')); 
		$this->subscribeEvent('Files::GetFileInfo::after', array($this, 'onAfterGetFileInfo'));
		$this->subscribeEvent('Files::PopulateFileItem', array($this, 'onPopulateFileItem'));
		$this->subscribeEvent('Dropbox::GetSettings', array($this, 'onGetSettings'));
		$this->subscribeEvent('Dropbox::UpdateSettings::after', array($this, 'onAfterUpdateSettings'));
		
		$this->subscribeEvent('Files::GetFiles::before', array($this, 'CheckUrlFile'));
		$this->subscribeEvent('Files::UploadFile::before', array($this, 'CheckUrlFile'));
		$this->subscribeEvent('Files::CreateFolder::before', array($this, 'CheckUrlFile'));
	}
	
	/**
	 * Obtains DropBox client if passed $sType is DropBox account type.
	 * 
	 * @param string $sType Service type.
	 * @return \Dropbox\Client
	 */
	protected function getClient()
	{
		
		$oDropboxModule = \CApi::GetModule('Dropbox');
		if ($oDropboxModule instanceof \AApiModule)
		{
			if (!$oDropboxModule->getConfig('EnableModule', false) || !$this->issetScope('storage'))
			{
				return false;
			}
		}
		else
		{
			return false;
		}		
		
		if ($this->oClient === null)
		{
			\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);

			$oOAuthIntegratorWebclientModule = \CApi::GetModuleDecorator('OAuthIntegratorWebclient');
			$oOAuthAccount = $oOAuthIntegratorWebclientModule->GetAccount(self::$sService);
			if ($oOAuthAccount)
			{
				$this->oClient = new \Dropbox\Client($oOAuthAccount->AccessToken, "Aurora App");
			}
		}
		
		return $this->oClient;
	}	
	
	/**
	 * Write to the $aResult variable information about DropBox storage.
	 * 
	 * @ignore
	 * @param array $aData Is passed by reference.
	 */
	public function onAfterGetStorages($aArgs, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);
		
		$bEnableDropboxModule = false;
		$oDropboxModule = \CApi::GetModule('Dropbox');
		if ($oDropboxModule instanceof \AApiModule)
		{
			$bEnableDropboxModule = $oDropboxModule->getConfig('EnableModule', false);
		}
		else
		{
			$bEnableDropboxModule = false;
		}
		
		
		$oOAuthIntegratorWebclientModule = \CApi::GetModuleDecorator('OAuthIntegratorWebclient');
		$oOAuthAccount = $oOAuthIntegratorWebclientModule->GetAccount(self::$sService);

		if ($oOAuthAccount instanceof COAuthAccount && 
				$oOAuthAccount->Type === self::$sService &&
					$this->issetScope('storage') && $oOAuthAccount->issetScope('storage'))
		{		
			$mResult[] = [
				'Type' => self::$sService, 
				'IsExternal' => true,
				'DisplayName' => 'Dropbox'
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
	 * Populates file info.
	 * 
	 * @param string $sType Service type.
	 * @param \Dropbox\Client $oClient DropBox client.
	 * @param array $aData Array contains information about file.
	 * @return \CFileStorageItem|false
	 */
	protected function populateFileInfo($aData)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);
		
		$mResult = false;
		if ($aData && is_array($aData))
		{
			$sPath = ltrim($this->getDirName($aData['path']), '/');
			$oClient = $this->getClient();
			$mResult /*@var $mResult \CFileStorageItem */ = new  \CFileStorageItem();
//			$mResult->IsExternal = true;
			$mResult->TypeStr = self::$sService;
			$mResult->IsFolder = $aData['is_dir'];
			$mResult->Id = $this->getBaseName($aData['path']);
			$mResult->Name = $mResult->Id;
			$mResult->Path = !empty($sPath) ? '/'.$sPath : $sPath;
			$mResult->Size = $aData['bytes'];
//			$bResult->Owner = $oSocial->Name;
			$mResult->LastModified = date_timestamp_get($oClient->parseDateTime($aData['modified']));
			$mResult->Shared = isset($aData['shared']) ? $aData['shared'] : false;
			$mResult->FullPath = $mResult->Name !== '' ? $mResult->Path . '/' . $mResult->Name : $mResult->Path ;

			if (!$mResult->IsFolder && $aData['thumb_exists'])
			{
				$mResult->Thumb = true;
			}
			
		}
		return $mResult;
	}	
	
	/**
	 * Writes to the $mResult variable open file source if $sType is DropBox account type.
	 * 
	 * @ignore
	 * @param int $iUserId Identifier of the authenticated user.
	 * @param string $sType Service type.
	 * @param string $sPath File path.
	 * @param string $sName File name.
	 * @param boolean $bThumb **true** if thumbnail is expected.
	 * @param mixed $mResult
	 */
	public function onGetFile($aArgs, &$mResult)
	{
		if ($aArgs['Type'] === self::$sService)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = fopen('php://memory','wb+');
				if (!isset($aArgs['Thumb']))
				{
					$oClient->getFile('/'.ltrim($aArgs['Path'], '/').'/'.$aArgs['Name'], $mResult);
				}
				else
				{
					$aThumb = $oClient->getThumbnail('/'.ltrim($aArgs['Path'], '/').'/'.$aArgs['Name'], "png", "m");
					if ($aThumb && isset($aThumb[1]))
					{
						fwrite($mResult, $aThumb[1]);
					}
				}
				rewind($mResult);
			}
		}
	}	
	
	/**
	 * Writes to $aData variable list of DropBox files if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData Is passed by reference.
	 */
	public function onAfterGetFiles($aArgs, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);
		
		if ($aArgs['Type'] === self::$sService)
		{
			$mResult['Items'] = array();
			$oClient = $this->getClient();
			if ($oClient)
			{
				$aItems = array();
				$Path = '/'.ltrim($aArgs['Path'], '/');
				if (empty($aArgs['Pattern']))
				{
					$aItem = $oClient->getMetadataWithChildren($Path);
					$aItems = $aItem['contents'];
				}
				else
				{
					$aItems = $oClient->searchFileNames($Path, $aArgs['Pattern']);
				}

				foreach($aItems as $aChild) 
				{
					$oItem /*@var $oItem \CFileStorageItem */ = $this->populateFileInfo($aChild);
					if ($oItem)
					{
						$mResult['Items'][] = $oItem;
					}
				}				
			}
		}
	}	

	/**
	 * Creates folder if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData Is passed by reference.
	 */
	public function onAfterCreateFolder($aArgs, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aArgs['Type'] === self::$sService)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = false;

				if ($oClient->createFolder('/'.ltrim($aArgs['Path'], '/').'/'.$aArgs['FolderName']) !== null)
				{
					$mResult = true;
				}
			}
		}
	}	

	/**
	 * Creates file if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData
	 */
	public function onCreateFile($aArgs, &$Result)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aArgs['Type'] === self::$sService)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = false;

				$Path = '/'.ltrim($aArgs['Path'], '/').'/'.$aArgs['Name'];
				if (is_resource($aArgs['Data']))
				{
					if ($oClient->uploadFile(
						$Path, 
						\Dropbox\WriteMode::add(), 
						$aArgs['Data']))
					{
						$mResult = true;
					}
				}
				else
				{
					if ($oClient->uploadFileFromString(
						$Path, 
						\Dropbox\WriteMode::add(), 
						$aArgs['Data']))
					{
						$mResult = true;
					}
				}
			}
		}
	}	

	/**
	 * Deletes file if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData
	 */
	public function onAfterDelete($aArgs, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aArgs['Type'] === self::$sService)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = false;

				foreach ($aArgs['Items'] as $aItem)
				{
					$oClient->delete('/'.ltrim($aItem['Path'], '/').'/'.$aItem['Name']);
					$mResult = true;
				}
			}
		}
	}	

	/**
	 * Renames file if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData
	 */
	public function onAfterRename($aArgs, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aArgs['Type'] === self::$sService)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = false;

				$sPath = ltrim($aArgs['Path'], '/');
				if ($oClient->move('/'.$sPath.'/'.$aArgs['Name'], '/'.$sPath.'/'.$aArgs['NewName']))
				{
					$mResult = true;
				}
			}
		}
	}	

	/**
	 * Moves file if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData
	 */
	public function onAfterMove($aArgs, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aArgs['FromType'] === self::$sService)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = false;

				if ($aArgs['ToType'] === $aArgs['FromType'])
				{
					foreach ($aArgs['Files'] as $aFile)
					{
						$oClient->move('/'.ltrim($aArgs['FromPath'], '/').'/'.$aFile['Name'], '/'.ltrim($aArgs['ToPath'], '/').'/'.$aFile['Name']);
					}
					$mResult = true;
				}
			}
		}
	}	

	/**
	 * Copies file if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData
	 */
	public function onAfterCopy($aArgs, &$mResult)
	{
		\CApi::checkUserRoleIsAtLeast(\EUserRole::NormalUser);
		
		if ($aArgs['FromType'] === self::$sService)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = false;

				if ($aArgs['ToType'] === $aArgs['FromType'])
				{
					foreach ($aArgs['Files'] as $aFile)
					{
						$oClient->copy('/'.ltrim($aArgs['FromPath'], '/').'/'.$aFile['Name'], '/'.ltrim($aArgs['ToPath'], '/').'/'.$aFile['Name']);
					}
					$mResult = true;
				}
			}
		}
	}		
	
	protected function _getFileInfo($sPath, $sName)
	{
		$mResult = false;
		$oClient = $this->GetClient();
		if ($oClient)
		{
			$mResult = $oClient->getMetadata('/'.ltrim($sPath, '/').'/'.$sName);
		}
		
		return $mResult;
	}


	/**
	 * @ignore
	 * @todo not used
	 * @param object $oAccount
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sName
	 * @param boolean $mResult
	 * @param boolean $bBreak
	 */
	public function onAfterGetFileInfo($aArgs)
	{
		$mResult = false;
		
		\CApi::checkUserRoleIsAtLeast(\EUserRole::Anonymous);
		
		$mFileInfo = $this->_getFileInfo($aArgs['Path'], $aArgs['Name']);
		if ($mFileInfo)
		{
			$mResult = $this->PopulateFileInfo($mFileInfo);
		}
		
		return $mResult;
	}	
	
	/**
	 * @ignore
	 * @todo not used
	 * @param object $oItem
	 * @return boolean
	 */
	public function onPopulateFileItem($aArgs, &$oItem)
	{
		if ($oItem->IsLink)
		{
			if (false !== strpos($oItem->LinkUrl, 'dl.dropboxusercontent.com') || 
					false !== strpos($oItem->LinkUrl, 'dropbox.com'))
			{
				$aMetadata = $this->getMetadataLink($oItem->LinkUrl);
				if (isset($aMetadata['path']) && $aMetadata['is_dir'])
				{
					$oItem->MainAction = 'list';
					$oItem->Thumb = true;
					$oItem->ThumbnailLink = \MailSo\Base\Http::SingletonInstance()->GetFullUrl() . 'modules/' . $this->GetName() . '/images/dropbox.png';
				}
				$oItem->LinkType = 'dropbox';
				return true;
			}
		}
	}	
	
	public function getMetadataLink($sLink)
	{
		$oClient = $this->getClient();
        $response = $oClient->doGet(
			\Dropbox\Host::getDefault()->getApi(),
            '1/metadata/link', 
			array(
				'link' => $sLink
			)
		);

        if ($response->statusCode === 404) return null;
        if ($response->statusCode !== 200) return null;

        $metadata = \Dropbox\RequestUtil::parseResponseJson($response->body);
        if (array_key_exists("is_deleted", $metadata) && $metadata["is_deleted"]) return null;
        return $metadata;
	}
	
	public function CheckUrlFile(&$aArgs, &$mResult)
	{
		if (strpos($aArgs['Path'], '.url') !== false)
		{
			list($sUrl, $sPath) = explode('.url', $aArgs['Path']);
			$sUrl .= '.url';
			$aArgs['Path'] = $sUrl;
			$this->prepareArgs($aArgs);
			if ($sPath)
			{
				$aArgs['Path'] .= $sPath;
			}
		}
	}

	protected function prepareArgs(&$aData)
	{
		$aPathInfo = pathinfo($aData['Path']);
		$sExtension = isset($aPathInfo['extension']) ? $aPathInfo['extension'] : '';
		if ($sExtension === 'url')
		{
			$aArgs = array(
				'UserId' => $aData['UserId'],
				'Type' => $aData['Type'],
				'Path' => $aPathInfo['dirname'],
				'Name' => $aPathInfo['basename'],
				'IsThumb' => false
			);
			$mResult = false;
			\CApi::GetModuleManager()->broadcastEvent(
				'Files',
				'GetFile', 
				$aArgs,
				$mResult
			);	
			if (is_resource($mResult))
			{
				$aUrlFileInfo = \api_Utils::parseIniString(stream_get_contents($mResult));
				if ($aUrlFileInfo && isset($aUrlFileInfo['URL']))
				{
					if (false !== strpos($aUrlFileInfo['URL'], 'dl.dropboxusercontent.com') || 
						false !== strpos($aUrlFileInfo['URL'], 'dropbox.com'))
					{
						$aData['Type'] = 'dropbox';
						$aMetadata = $this->getMetadataLink($aUrlFileInfo['URL']);
						if (isset($aMetadata['path']))
						{
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
		$oUser = \CApi::getAuthenticatedUser();
		
		if (!empty($oUser))
		{
			$aScope = array(
				'Name' => 'storage',
				'Description' => $this->i18N('SCOPE_FILESTORAGE', $oUser->iId),
				'Value' => false
			);
			if ($oUser->Role === \EUserRole::SuperAdmin)
			{
				$aScope['Value'] = $this->issetScope('storage');
				$mResult['Scopes'][] = $aScope;
			}
			if ($oUser->Role === \EUserRole::NormalUser)
			{
				if ($aArgs['OAuthAccount'] instanceof \COAuthAccount)
				{
					$aScope['Value'] = $aArgs['OAuthAccount']->issetScope('storage');
				}
				if ($this->issetScope('storage'))
				{
					$mResult['Scopes'][] = $aScope;
				}
			}
		}	
	}
	
	public function onAfterUpdateSettings($aArgs, &$mResult)
	{
		$sScope = '';
		if (isset($aArgs['Scopes']) && is_array($aArgs['Scopes']))
		{
			foreach($aArgs['Scopes'] as $aScope)
			{
				if ($aScope['Name'] === 'storage')
				{
					if ($aScope['Value'])
					{
						$sScope = 'storage';
						break;
					}
				}
			}
		}
		$this->setConfig('Scopes', $sScope);
		$this->saveModuleConfig();
	}
}
