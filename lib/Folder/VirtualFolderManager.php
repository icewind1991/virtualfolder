<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2021 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\VirtualFolder\Folder;

use OC\Files\Cache\CacheEntry;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

class VirtualFolderManager {
	/** @var IDBConnection */
	private $connection;
	/** @var ContainerInterface */
	private $rootFolderContainer;
	/** @var IUserManager */
	private $userManager;
	/** @var FolderConfigManager */
	private $configManager;

	public function __construct(IDBConnection $connection, ContainerInterface $rootFolderContainer, IUserManager $userManager, FolderConfigManager $configManager) {
		$this->connection = $connection;
		$this->rootFolderContainer = $rootFolderContainer;
		$this->userManager = $userManager;
		$this->configManager = $configManager;
	}

	/**
	 * @param IUser $user
	 * @return VirtualFolder[]
	 */
	public function getFoldersForUser(IUser $user): array {
		$folders = $this->configManager->getFoldersForUser($user->getUID());
		$rootFolderFactory = function () {
			return $this->rootFolderContainer->get(IRootFolder::class);
		};
		return array_map(function (FolderConfig $folder) use ($rootFolderFactory) {
			$sourceUser = $this->userManager->get($folder->getSourceUserId());
			if ($sourceUser === null) {
				throw new NotFoundException("Source user not found for virtual folder");
			}
			$sourceFiles = $this->getSourceFilesFromFileIds($sourceUser, $rootFolderFactory, $folder->getSourceFileIds());
			return new VirtualFolder($sourceFiles, $folder->getMountPoint());
		}, $folders);
	}

	private function getSourceFilesFromFileIds(IUser $sourceUser, callable $rootFolderFactory, array $sourceFileIds): array {
		$query = $this->connection->getQueryBuilder();
		$query->select('fileid', 'storage', 'path', 'parent', 'name', 'mimetype', 'mimepart', 'size', 'mtime', 'storage_mtime', 'encrypted', 'unencrypted_size', 'etag', 'permissions', 'checksum', 'id')
			->from('filecache', 'f')
			->innerJoin('f', 'storages', 's', $query->expr()->eq('storage', 'numeric_id'))
			->where($query->expr()->in('fileid', $query->createNamedParameter($sourceUser, IQueryBuilder::PARAM_INT_ARRAY)));
		$results = $query->executeQuery()->fetchAll();
		return array_map(function (array $row) use ($rootFolderFactory, $sourceUser) {
			$cacheEntry = new CacheEntry($row);
			return new SourceFile($cacheEntry, $row['id'], $rootFolderFactory, $sourceUser);
		}, $results);
	}
}
