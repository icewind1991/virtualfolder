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

namespace OCA\VirtualFolder\Command;

use OCA\VirtualFolder\Folder\FolderConfigManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Create extends Command {
	/** @var FolderConfigManager */
	protected $configManager;

	public function __construct(FolderConfigManager $configManager) {
		parent::__construct();

		$this->configManager = $configManager;
	}

	protected function configure() {
		$this
			->setName('virtualfolder:create')
			->setDescription('Create a new virtual folder')
			->addArgument(
				'source_user',
				InputArgument::REQUIRED,
				'User id of the source user for the folder'
			)
			->addArgument(
				'target_user',
				InputArgument::REQUIRED,
				'User id of the target user for the folder'
			)
			->addArgument(
				'mount_point',
				InputArgument::REQUIRED,
				'Mount point for the virtual folder'
			)
			->addArgument(
				'file_ids',
				InputArgument::IS_ARRAY,
				'File ids to add to the folder'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$sourceUserId = $input->getArgument('source_user');
		$targetUserId = $input->getArgument('target_user');
		$mountPoint = $input->getArgument('mount_point');
		$fileIds = $input->getArgument('file_ids');

		$this->configManager->newFolder($sourceUserId, $targetUserId, $mountPoint, $fileIds);

		return 0;
	}
}
