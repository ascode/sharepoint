<?php
/**
 * @copyright Copyright (c) 2016 Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
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

namespace OCA\Files_External\Tests\Storage;

use OC\Cache\CappedMemoryCache;
use OCA\SharePoint\NotFoundException;
use OCA\SharePoint\Storage\Storage;
use OCP\Files\FileInfo;
use OCA\SharePoint\ContextsFactory;
use OCA\SharePoint\Client;
use OCA\SharePoint\ClientFactory;
use Office365\PHP\Client\SharePoint\ClientContext;
use Office365\PHP\Client\SharePoint\File;
use Office365\PHP\Client\SharePoint\Folder;
use Office365\PHP\Client\SharePoint\SPList;
use Test\TestCase;

class SharePointTest extends TestCase {

	/** @var  Storage */
	protected $storage;

	/** @var  ContextsFactory|\PHPUnit_Framework_MockObject_MockObject */
	protected $factory;

	/** @var  ClientContext|\PHPUnit_Framework_MockObject_MockObject */
	protected $clientContextMock;

	/** @var  string */
	protected $documentLibraryTitle = 'Fancy Documents';

	/** @var  SPList|\PHPUnit_Framework_MockObject_MockObject */
	protected $sharePointList;

	/** @var string */
	protected $exampleHost = 'example.foo';

	/** @var string */
	protected $exampleUser = 'alice';

	/** @var string */
	protected $examplePwd = 'a123456';

	/** @var  ClientFactory|\PHPUnit_Framework_MockObject_MockObject */
	protected $clientFactory;

	/** @var  Client|\PHPUnit_Framework_MockObject_MockObject */
	protected $client;

	/** @var  CappedMemoryCache|\PHPUnit_Framework_MockObject_MockObject */
	protected $fileCache;

	public function setUp() {
		parent::setUp();

		$this->factory = $this->createMock(ContextsFactory::class);
		$this->clientFactory = $this->createMock(ClientFactory::class);
		$this->client = $this->createMock(Client::class);

		$this->clientFactory->expects($this->any())
			->method('getClient')
			->willReturn($this->client);

		$this->fileCache =  $this->createMock(CappedMemoryCache::class);

		$parameters = [
			'host'                    => $this->exampleHost,
			'documentLibrary'         => $this->documentLibraryTitle,
			'user'                    => $this->exampleUser,
			'password'                => $this->examplePwd,
			'contextFactory'          => $this->factory,
			'sharePointClientFactory' => $this->clientFactory,
			'cappedMemoryCache'       => $this->fileCache,
		];

		$this->storage = new Storage($parameters);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testBadDocumentLibraryName() {
		$parameters = [
			'host'            => 'example.foo',
			'documentLibrary' => 'foo" or bar eq 42',
			'user'            => 'alicce',
			'password'        => 'asdf',
		];

		new Storage($parameters);
	}

	public function pathProvider() {
		return [
			['/', null],
			['', null],
			['Paperwork', null],
			['Paperwork/', null],
			['/Paperwork/', null],
			['My Documents', null],
			['Paperwork/This and That/Bills/', null],
			['Textfile.txt', 26624],
			['Paperwork/Letter Template.ott', 26624],
			['Paperwork/This and That/Foobar.ora', 26624],
		];
	}

	/**
	 * @dataProvider pathProvider
	 */
	public function testStatExisting($path, $returnSize) {
		$mtime = new \DateTime(null, new \DateTimeZone('Z'));
		$mtime->sub(new \DateInterval('P2D'));
		// a SP time string looks like: 2017-03-22T16:17:23Z
		$returnMTime = $mtime->format('o-m-d\TH:i:se');
		$size = $returnSize ?: FileInfo::SPACE_UNKNOWN;

		$folderMock = $this->createMock(Folder::class);
		$folderMock->expects($this->exactly(2))
			->method('getProperty')
			->withConsecutive(['Length'], ['TimeLastModified'])
			->willReturnOnConsecutiveCalls($returnSize, $returnMTime);

		$serverPath = '/' . $this->documentLibraryTitle;
		if(trim($path, '/') !== '') {
			$serverPath .= '/' . trim($path, '/');
		}

		$this->client->expects($this->once())
			->method('fetchFileOrFolder')
			->with($serverPath, [Storage::SP_PROPERTY_SIZE, Storage::SP_PROPERTY_MTIME])
			->willReturn($folderMock);

		$data = $this->storage->stat($path);

		$this->assertSame($mtime->getTimestamp(), $data['mtime']);
		$this->assertSame($size, $data['size']);
		$this->assertTrue($mtime->getTimestamp() < $data['atime']);
	}

	public function testStatNotExisting() {
		$path = '/foobar/bar.foo';
		$serverPath = '/' . $this->documentLibraryTitle . '/' . trim($path, '/');

		$this->client->expects($this->once())
			->method('fetchFileOrFolder')
			->with($serverPath, [Storage::SP_PROPERTY_SIZE, Storage::SP_PROPERTY_MTIME])
			->willThrowException(new NotFoundException());

		$this->assertFalse($this->storage->stat($path));
	}

	/**
	 * @dataProvider pathProvider
	 */
	public function testFileType($path, $returnSize) {
		if($returnSize === null) {
			$return = $this->createMock(Folder::class);
			$expectedType = 'dir';
		} else {
			$return = $this->createMock(File::class);
			$expectedType = 'file';
		}

		$serverPath = '/' . $this->documentLibraryTitle;
		if(trim($path, '/') !== '') {
			$serverPath .= '/' . trim($path, '/');
		}

		$this->client->expects($this->once())
			->method('fetchFileOrFolder')
			->with($serverPath)
			->willReturn($return);

		$this->assertSame($expectedType, $this->storage->filetype($path));
	}

	public function testFileTypeNotExisting() {
		$path = '/dingdong/nothing.sh';

		$serverPath = '/' . $this->documentLibraryTitle;
		if(trim($path, '/') !== '') {
			$serverPath .= '/' . trim($path, '/');
		}

		$this->client->expects($this->once())
			->method('fetchFileOrFolder')
			->with($serverPath)
			->willThrowException(new NotFoundException());

		$this->assertFalse($this->storage->filetype($path));
	}

	public function  boolProvider() {
		return [
			[ true ],
			[ false ]
		];
	}

	/**
	 * @dataProvider boolProvider
	 */
	public function testFileExists($exists) {
		$path = '/dingdong/nothing.sh';

		$serverPath = '/' . $this->documentLibraryTitle;
		if(trim($path, '/') !== '') {
			$serverPath .= '/' . trim($path, '/');
		}

		$invocationMocker = $this->client->expects($this->once())
			->method('fetchFileOrFolder')
			->with($serverPath);
		if($exists) {
			$invocationMocker->willReturn($this->createMock(File::class));
		} else {
			$invocationMocker->willThrowException(new NotFoundException());
		}

		$this->assertSame($exists, $this->storage->file_exists($path));
	}

	/**
	 * @dataProvider boolProvider
	 */
	public function testMkDir($successful) {
		$dirName = '/Parentfolder/NewDirectory';
		$serverPath = '/' . $this->documentLibraryTitle . $dirName;

		$folderMock = $this->createMock(Folder::class);

		$invocationMocker = $this->client->expects($this->once())
			->method('createFolder')
			->with($serverPath)
			->willReturn($folderMock);

		if(!$successful) {
			$this->fileCache->expects($this->once())
				->method('remove')
				->with($serverPath);

			$invocationMocker->willThrowException(new \Exception('Whatever'));
		} else {
			$this->fileCache->expects($this->once())
				->method('set')
				->with($serverPath);

			$folderMock->expects($this->once())
				->method('getFolders');
			$folderMock->expects($this->once())
				->method('getFiles');
		}

		$this->assertSame($successful, $this->storage->mkdir($dirName));
	}

	/**
	 * @dataProvider boolProvider
	 */
	public function testRmDir($successful) {
		$dirName = '/Parentfolder/TargetDirectory';
		$serverPath = '/' . $this->documentLibraryTitle . $dirName;

		$folderMock = $this->createMock(Folder::class);

		$this->client->expects($this->once())
			->method('fetchFileOrFolder')
			->with($serverPath)
			->willReturn($folderMock);
		$invocationMocker = $this->client->expects($this->once())
			->method('delete')
			->with($folderMock);

		if(!$successful) {
			$invocationMocker->willThrowException(new \Exception('nope'));
		}

		$this->assertSame($successful, $this->storage->rmdir($dirName));
	}

	public function testUnlink() {
		$path = '/dingdong/nothing.sh';

		$serverPath = '/' . $this->documentLibraryTitle;
		if(trim($path, '/') !== '') {
			$serverPath .= '/' . trim($path, '/');
		}

		$fileMock = $this->createMock(File::class);

		$this->client->expects($this->exactly(2))
			->method('fetchFileOrFolder')
			->with($serverPath)
			->willReturn($fileMock);

		$this->client->expects($this->once())
			->method('delete')
			->with($fileMock);

		$this->storage->unlink($path);
	}



}
