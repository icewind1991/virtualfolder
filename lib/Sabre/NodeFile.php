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
namespace OCA\VirtualFolder\Sabre;

use OCA\VirtualFolder\Folder\FolderConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use Sabre\DAV\IFile;

class NodeFile extends AbstractNode implements IFile {
	/** @var File */
	protected \OCP\Files\Node $node;

	public function __construct(File $node, Folder $userFolder, FolderConfig $folder) {
		$this->node = $node;
		$this->userFolder = $userFolder;
		$this->folder = $folder;
	}

	public function put($data) {
		$this->node->putContent($data);
	}

	public function get() {
		return $this->node->fopen('r');
	}

	public function getContentType() {
		return $this->node->getMimeType();
	}

	public function getETag() {
		return $this->node->getEtag();
	}

	public function getSize() {
		return $this->node->getSize();
	}
}
