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

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\AlreadyExistsException;
use OCP\IDBConnection;

class FolderConfigManager {
	private IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	/**
	 * @param string $userId
	 * @return FolderConfig[]
	 */
	public function getFoldersForUser(string $userId): array {
		$query = $this->connection->getQueryBuilder();
		$query->select('folder.folder_id', 'user', 'mount_point', 'file_id')
			->from('virtual_folders', 'folder')
			->leftJoin('folder', 'virtual_folder_files', 'files', $query->expr()->eq('folder.folder_id', 'files.folder_id'))
			->where($query->expr()->eq('user', $query->createNamedParameter($userId)));
		$rows = $query->execute()->fetchAll();

		return $this->fromRows($rows);
	}

	public function getById(int $id): ?FolderConfig {
		$query = $this->connection->getQueryBuilder();
		$query->select('folder.folder_id', 'user', 'mount_point', 'file_id')
			->from('virtual_folders', 'folder')
			->leftJoin('folder', 'virtual_folder_files', 'files', $query->expr()->eq('folder.folder_id', 'files.folder_id'))
			->where($query->expr()->eq('folder.folder_id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$rows = $query->execute()->fetchAll();

		if ($rows) {
			return $this->fromRows($rows)[0];
		} else {
			return null;
		}
	}

	/**
	 * @return FolderConfig[]
	 */
	public function getAllFolders(): array {
		$query = $this->connection->getQueryBuilder();
		$query->select('folder.folder_id', 'user', 'mount_point', 'file_id')
			->from('virtual_folders', 'folder')
			->leftJoin('folder', 'virtual_folder_files', 'files', $query->expr()->eq('folder.folder_id', 'files.folder_id'));
		$rows = $query->execute()->fetchAll();

		return $this->fromRows($rows);
	}

	public function deleteFolder(int $id) {
		$query = $this->connection->getQueryBuilder();
		$query->delete('virtual_folder_files')
			->where($query->expr()->eq('folder_id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$query->execute();

		$query = $this->connection->getQueryBuilder();
		$query->delete('virtual_folders')
			->where($query->expr()->eq('folder_id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$query->execute();
	}

	/**
	 * @param string $userId
	 * @param int[] $fileIds
	 * @return FolderConfig
	 */
	public function newFolder(string $userId, string $mountPoint, array $fileIds): FolderConfig {
		$fileIds = array_unique($fileIds);
		$existingFolders = $this->getFoldersForUser($userId);
		foreach ($existingFolders as $existingFolder) {
			if ($existingFolder->getMountPoint() === $mountPoint) {
				throw new AlreadyExistsException("Virtual folder with given mountpoint already exists for user");
			}
		}

		$query = $this->connection->getQueryBuilder();
		$query->insert('virtual_folders')
			->values([
				'user' => $query->createNamedParameter($userId),
				'mount_point' => $query->createNamedParameter($mountPoint),
			]);
		$query->execute();
		$folderId = $query->getLastInsertId();

		foreach ($fileIds as $fileId) {
			$this->addSourceFile($folderId, $fileId);
		}

		return new FolderConfig($folderId, $userId, $mountPoint, $fileIds);
	}

	/**
	 * Get a list of all configured folders, indexed by the fileid of the root of the virtual folder
	 *
	 * @return array<int, FolderConfig>
	 */
	public function getAllByRootIds(): array {
		$query = $this->connection->getQueryBuilder();
		$query->select('folder.folder_id', 'user', 'mount_point', 'file_id', 'f.fileid')
			->from('virtual_folders', 'folder')
			->innerJoin('folder', 'storages', 's', $query->expr()->eq('s.id', $query->func()->concat(
				$query->expr()->literal("virtual_"),
				$query->expr()->castColumn('folder.folder_id', Types::STRING)
			)))
			->innerJoin('s', 'filecache', 'f', $query->expr()->andX(
				$query->expr()->eq('path_hash', $query->expr()->literal(md5(''))),
				$query->expr()->eq('s.numeric_id', 'f.storage')
			))
			->leftJoin('folder', 'virtual_folder_files', 'files', $query->expr()->eq('folder.folder_id', 'files.folder_id'));
		$rows = $query->execute()->fetchAll();

		return $this->fromRows($rows, 'fileid');
	}

	/**
	 * @param array $rows
	 * @param string|null $key
	 * @return FolderConfig[]
	 */
	public function fromRows(array $rows, string $key = 'folder_id'): array {
		$folders = [];

		foreach ($rows as $row) {
			$folderKey = $row[$key];
			if (!isset($folders[$folderKey])) {
				$folders[$folderKey] = [
					'id' => (int)$row['folder_id'],
					'user' => $row['user'],
					'mount_point' => $row['mount_point'],
					'files' => [],
				];
			}
			if ($row['file_id']) {
				$folders[$folderKey]['files'][] = (int)$row['file_id'];
			}
		}

		ksort($folders);

		$folders = array_map(function (array $folder) {
			return new FolderConfig($folder['id'], $folder['user'], $folder['mount_point'], $folder['files']);
		}, $folders);
		if ($key === 'folder_id') {
			return array_values($folders);
		} else {
			return $folders;
		}
	}

	public function setMountPoint(int $id, string $mountPoint) {
		$query = $this->connection->getQueryBuilder();
		$query->update('virtual_folders')
			->set('mount_point', $query->createNamedParameter($mountPoint))
			->where($query->expr()->eq('folder_id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$query->execute();
	}

	public function removeSourceFile(int $folderId, int $fileId) {
		$query = $this->connection->getQueryBuilder();
		$query->delete('virtual_folder_files')
			->where($query->expr()->eq('file_id', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('folder_id', $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)));
		$query->execute();
	}

	public function removeSourceFileAll(int $fileId) {
		$query = $this->connection->getQueryBuilder();
		$query->delete('virtual_folder_files')
			->where($query->expr()->eq('file_id', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$query->execute();
	}

	public function addSourceFile(int $folderId, int $fileId) {
		$query = $this->connection->getQueryBuilder();
		$query->insert('virtual_folder_files')
			->values([
				'folder_id' => $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT),
				'file_id' => $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
			]);
		try {
			$query->execute();
		} catch (UniqueConstraintViolationException $e) {
			// ignore duplicate
		}
	}
}
