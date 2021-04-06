<?php
declare(strict_types=1);
/**
 * @copyright Copyright (C) 2018, struktur AG
 *
 * @author Joachim Bauch <mail@joachim-bauch.de>
 *
 * @license AGPL-3.0
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
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace OCA\Pdfdraw\Controller;

use \Firebase\JWT\JWT;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;

class ApiController extends Controller {

	/** @var IDBConnection */
	private $db;

	/**
	 * Root folder
	 *
	 * @var IRootFolder
	 */
	private $root;

	/** @var IConfig */
	private $config;

	/**
	 * @param string $AppName
	 * @param IRequest $request
	 */
	public function __construct(
			string $AppName,
			IRequest $request,
			IDBConnection $db,
			IRootFolder $root,
			IConfig	$config) {
		parent::__construct($AppName, $request);
		$this->db = $db;
		$this->root = $root;
		$this->config = $config;
	}

	/**
	 * Decode the JWT token from the request.
	 *
	 * @param string $fileId
	 * @return array|null
	 */
	private function decodeToken(string $fileId) {
		$authHeader = $this->request->getHeader('Authorization');
		if (empty($authHeader) || strpos($authHeader, 'Bearer') !== 0) {
			return null;
		}
		$token = substr($authHeader, 7);
		$backend = $this->config->getAppValue('pdfdraw', 'backend');
		$secret = null;
		if (!empty($backend)) {
			$backend = json_decode($backend);
			$secret = $backend->secret;
		}

		try {
			$decoded = JWT::decode($token, $secret, array('HS256'));
		} catch (\Exception $e) {
			return null;
		}

		if ($decoded->file !== $fileId) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Return list of items on a given file.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $fileId
	 * @return DataResponse
	 */
	public function getItems(string $fileId) {
		$decoded = $this->decodeToken($fileId);
		if (empty($decoded)) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($decoded->iss !== 'backend') {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('pdfdraw_items')
			->where($query->expr()->eq('file_id', $query->createNamedParameter($fileId)));
		$result = $query->execute();
		$items = [];
		while ($row = $result->fetch()) {
			$items[] = [
				'page' => (int) $row['page'],
				'name' => $row['name'],
				'data' => $row['data'],
			];
		}
		$result->closeCursor();
		return new DataResponse($items);
	}

	/**
	 * Create / update item on a page of a given file.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $fileId
	 * @param int $page
	 * @param string $name
	 * @param string $data
	 * @return DataResponse
	 */
	public function storeItem(string $fileId, int $page, string $name, string $data) {
		$decoded = $this->decodeToken($fileId);
		if (empty($decoded)) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($decoded->iss !== 'backend') {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$query = $this->db->getQueryBuilder();
		// Try modifying the item first...
		$query->update('pdfdraw_items')
			->set('data', $query->createNamedParameter($data))
			->set('page', $query->createNamedParameter($page, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('file_id', $query->createNamedParameter($fileId)))
			->andWhere($query->expr()->eq('name', $query->createNamedParameter($name)));
		$result = $query->execute();
		if ($result === 0) {
			// ...and create it if it didn't exist before.
			$query->insert('pdfdraw_items')
				->values(
					[
						'file_id' => $query->createNamedParameter($fileId),
						'page' => $query->createNamedParameter($page, IQueryBuilder::PARAM_INT),
						'name' => $query->createNamedParameter($name),
						'data' => $query->createNamedParameter($data),
					]
				);
			$query->execute();
		}
		return new DataResponse([]);
	}

	/**
	 * Remove item from page of a file.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $fileId
	 * @param int $page
	 * @param string $name
	 * @return DataResponse
	 */
	public function deleteItem(string $fileId, int $page, string $name) {
		$decoded = $this->decodeToken($fileId);
		if (empty($decoded)) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if ($decoded->iss !== 'backend') {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$query = $this->db->getQueryBuilder();
		$query->delete('pdfdraw_items')
			->where($query->expr()->eq('file_id', $query->createNamedParameter($fileId)))
			->andWhere($query->expr()->eq('page', $query->createNamedParameter($page)))
			->andWhere($query->expr()->eq('name', $query->createNamedParameter($name)));
		$query->execute();
		return new DataResponse([]);
	}

        /**
         * Download file by id.
         *
         * @PublicPage
         * @NoCSRFRequired
         *
	 * @param string $fileId
         * @return DataResponse
         */
	public function getFilePath(string $fileId) {

                $decoded = $this->decodeToken($fileId);
                if (empty($decoded)) {
                        return new DataResponse([], Http::STATUS_UNAUTHORIZED);
                }

                $query = $this->db->getQueryBuilder();
                $query->select('path')
                        ->from('filecache')
                        ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)));
                $result = $query->execute();
                $path = null;
                while ($row = $result->fetch()) {
                        $path = $row['path'];
                        break;
                }
                if (empty($path)) {
                        return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if (strpos($path, "files") === 0){
			// This is a home owned file, the real path starts after "files".
			$path = substr($path, 6);
		} else if (strpos($path, "__groupfolders") === 0){
			// This is a group folder, we need to calculate the real path
			// from __groupfolders/#/Real Path/...
			$groupid = explode("/", $path)[1];
			$query = $this->db->getQueryBuilder();
			$query->select('mount_point')
				->from('group_folders')
				->where($query->expr()->eq('folder_id', $query->createNamedParameter($groupid)));
			$result = $query->execute();
			while ($row = $result->fetch()){
				$mountpoint = $row['mount_point'];
				break;
			}
			if (empty($mountpoint)){
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}
			$path = $mountpoint . substr($path, strpos($path, "/", strpos($path, "/") + 1));
		}


		return new DataDownloadResponse($path, "blob", "text/plain");
//		return new DataResponse(["path" => $path], Http::STATUS_OK);
	}

	/**
	 * Download file by id.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $fileId
	 * @return DataResponse
	 */
	public function downloadFile(string $fileId) {
		$decoded = $this->decodeToken($fileId);
		if (empty($decoded)) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$query = $this->db->getQueryBuilder();
		$query->select('storage')
			->from('filecache')
			->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId)));
		$result = $query->execute();
		$storage = null;
		while ($row = $result->fetch()) {
			$storage = $row['storage'];
			break;
		}
		if (empty($storage)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$query = $this->db->getQueryBuilder();
		$query->select('id')
			->from('storages')
			->where($query->expr()->eq('numeric_id', $query->createNamedParameter($storage)));
		$result = $query->execute();
		$homeId = null;
		while ($row = $result->fetch()) {
			$homeId = $row['id'];
			break;
		}

		// This is stupidly limited to user home directories.
                $ownerId = "testuser";
                if (empty($homeId) || strpos($homeId, 'home::') !== 0) {
                        //return new DataResponse([], Http::STATUS_NOT_FOUND);
                } else {
                        $ownerId = substr($homeId, 6);
                }
		$files = $this->root->getUserFolder($ownerId)->getById($fileId);

//		if (empty($homeId) || strpos($homeId, 'home::') !== 0) {
//			return new DataResponse([], Http::STATUS_NOT_FOUND);
//		}
//		$ownerId = substr($homeId, 6);
//		$files = $this->root->getUserFolder($ownerId)->getById($fileId);

		if (empty($files)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$file = $files[0];
		if (!$file instanceof File) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataDownloadResponse($file->getContent(), $file->getName(), $file->getMimeType());
	}

}
