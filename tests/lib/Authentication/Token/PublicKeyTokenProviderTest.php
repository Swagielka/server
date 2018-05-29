<?php
/**
 * @author Christoph Wurst <christoph@owncloud.com>
 *
 * @copyright Copyright (c) 2016, Lukas Reschke <lukas@statuscode.ch>
 * @copyright Copyright (c) 2016, ownCloud, Inc.
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
 */

namespace Test\Authentication\Token;

use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Exceptions\PasswordlessTokenException;
use OC\Authentication\Token\PublicKeyToken;
use OC\Authentication\Token\PublicKeyTokenMapper;
use OC\Authentication\Token\PublicKeyTokenProvider;
use OC\Authentication\Token\ExpiredTokenException;
use OC\Authentication\Token\IToken;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IUser;
use OCP\Security\ICrypto;
use Test\TestCase;

class PublicKeyTokenProviderTest extends TestCase {

	/** @var PublicKeyTokenProvider|\PHPUnit_Framework_MockObject_MockObject */
	private $tokenProvider;
	/** @var PublicKeyTokenMapper|\PHPUnit_Framework_MockObject_MockObject */
	private $mapper;
	/** @var ICrypto */
	private $crypto;
	/** @var IConfig|\PHPUnit_Framework_MockObject_MockObject */
	private $config;
	/** @var ILogger|\PHPUnit_Framework_MockObject_MockObject */
	private $logger;
	/** @var ITimeFactory|\PHPUnit_Framework_MockObject_MockObject */
	private $timeFactory;
	/** @var int */
	private $time;

	protected function setUp() {
		parent::setUp();

		$this->mapper = $this->createMock(PublicKeyTokenMapper::class);
		$this->crypto = \OC::$server->getCrypto();
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValue')
			->will($this->returnValueMap([
				['session_lifetime', 60 * 60 * 24, 150],
				['remember_login_cookie_lifetime', 60 * 60 * 24 * 15, 300],
				['secret', '', '1f4h9s'],
			]));
		$this->logger = $this->createMock(ILogger::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->time = 1313131;
		$this->timeFactory->method('getTime')
			->willReturn($this->time);

		$this->tokenProvider = new PublicKeyTokenProvider($this->mapper, $this->crypto, $this->config, $this->logger,
			$this->timeFactory);
	}

	public function testGenerateToken() {
		$token = 'token';
		$uid = 'user';
		$user = 'User';
		$password = 'passme';
		$name = 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12';
		$type = IToken::PERMANENT_TOKEN;

		$actual = $this->tokenProvider->generateToken($token, $uid, $user, $password, $name, $type, IToken::DO_NOT_REMEMBER);

		$this->assertInstanceOf(PublicKeyToken::class, $actual);
		$this->assertSame($uid, $actual->getUID());
		$this->assertSame($user, $actual->getLoginName());
		$this->assertSame($name, $actual->getName());
		$this->assertSame(IToken::DO_NOT_REMEMBER, $actual->getRemember());
		$this->assertSame($password, $this->tokenProvider->getPassword($actual, $token));
	}

	public function testUpdateToken() {
		$tk = new PublicKeyToken();
		$tk->setLastActivity($this->time - 200);
		$this->mapper->expects($this->once())
			->method('update')
			->with($tk);

		$this->tokenProvider->updateTokenActivity($tk);

		$this->assertEquals($this->time, $tk->getLastActivity());
	}

	public function testUpdateTokenDebounce() {
		$tk = new PublicKeyToken();
		$tk->setLastActivity($this->time - 30);
		$this->mapper->expects($this->never())
			->method('update')
			->with($tk);

		$this->tokenProvider->updateTokenActivity($tk);
	}

	public function testGetTokenByUser() {
		$user = $this->createMock(IUser::class);
		$this->mapper->expects($this->once())
			->method('getTokenByUser')
			->with($user)
			->will($this->returnValue(['token']));

		$this->assertEquals(['token'], $this->tokenProvider->getTokenByUser($user));
	}

	public function testGetPassword() {
		$token = 'token';
		$uid = 'user';
		$user = 'User';
		$password = 'passme';
		$name = 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12';
		$type = IToken::PERMANENT_TOKEN;

		$actual = $this->tokenProvider->generateToken($token, $uid, $user, $password, $name, $type, IToken::DO_NOT_REMEMBER);

		$this->assertSame($password, $this->tokenProvider->getPassword($actual, $token));
	}

	/**
	 * @expectedException \OC\Authentication\Exceptions\PasswordlessTokenException
	 */
	public function testGetPasswordPasswordLessToken() {
		$token = 'token1234';
		$tk = new PublicKeyToken();
		$tk->setPassword(null);

		$this->tokenProvider->getPassword($tk, $token);
	}

	/**
	 * @expectedException \OC\Authentication\Exceptions\InvalidTokenException
	 */
	public function testGetPasswordInvalidToken() {
		$token = 'token';
		$uid = 'user';
		$user = 'User';
		$password = 'passme';
		$name = 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12';
		$type = IToken::PERMANENT_TOKEN;

		$actual = $this->tokenProvider->generateToken($token, $uid, $user, $password, $name, $type, IToken::DO_NOT_REMEMBER);

		$this->tokenProvider->getPassword($actual, 'wrongtoken');
	}

	public function testSetPassword() {
		$token = 'token';
		$uid = 'user';
		$user = 'User';
		$password = 'passme';
		$name = 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12';
		$type = IToken::PERMANENT_TOKEN;

		$actual = $this->tokenProvider->generateToken($token, $uid, $user, $password, $name, $type, IToken::DO_NOT_REMEMBER);

		$newpass = 'newpass';
		$this->tokenProvider->setPassword($actual, $token, $newpass);

		$this->assertSame($newpass, $this->tokenProvider->getPassword($actual, 'token'));
	}

	/**
	 * @expectedException \OC\Authentication\Exceptions\InvalidTokenException
	 */
	public function testSetPasswordInvalidToken() {
		$token = $this->createMock(IToken::class);
		$tokenId = 'token123';
		$password = '123456';

		$this->tokenProvider->setPassword($token, $tokenId, $password);
	}

	public function testInvalidateToken() {
		$this->mapper->expects($this->once())
			->method('invalidate')
			->with(hash('sha512', 'token7'.'1f4h9s'));

		$this->tokenProvider->invalidateToken('token7');
	}

	public function testInvaildateTokenById() {
		$id = 123;
		$user = $this->createMock(IUser::class);

		$this->mapper->expects($this->once())
			->method('deleteById')
			->with($user, $id);

		$this->tokenProvider->invalidateTokenById($user, $id);
	}

	public function testInvalidateOldTokens() {
		$defaultSessionLifetime = 60 * 60 * 24;
		$defaultRememberMeLifetime = 60 * 60 * 24 * 15;
		$this->config->expects($this->exactly(2))
			->method('getSystemValue')
			->will($this->returnValueMap([
				['session_lifetime', $defaultSessionLifetime, 150],
				['remember_login_cookie_lifetime', $defaultRememberMeLifetime, 300],
			]));
		$this->mapper->expects($this->at(0))
			->method('invalidateOld')
			->with($this->time - 150);
		$this->mapper->expects($this->at(1))
			->method('invalidateOld')
			->with($this->time - 300);

		$this->tokenProvider->invalidateOldTokens();
	}

	public function testRenewSessionTokenWithoutPassword() {
		$token = 'oldId';
		$uid = 'user';
		$user = 'User';
		$password = null;
		$name = 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12';
		$type = IToken::PERMANENT_TOKEN;

		$oldToken = $this->tokenProvider->generateToken($token, $uid, $user, $password, $name, $type, IToken::DO_NOT_REMEMBER);

		$this->mapper
			->expects($this->at(0))
			->method('getToken')
			->with(hash('sha512', 'oldId' . '1f4h9s'))
			->willReturn($oldToken);
		$this->mapper
			->expects($this->at(1))
			->method('insert')
			->with($this->callback(function (PublicKeyToken $token) use ($user, $uid, $name) {
				return $token->getUID() === $uid &&
					$token->getLoginName() === $user &&
					$token->getName() === $name &&
					$token->getType() === IToken::DO_NOT_REMEMBER &&
					$token->getLastActivity() === $this->time &&
					$token->getPassword() === null;
			}));
		$this->mapper
			->expects($this->at(2))
			->method('delete')
			->with($this->callback(function($token) use ($oldToken) {
				return $token === $oldToken;
			}));

		$this->tokenProvider->renewSessionToken('oldId', 'newId');
	}

	public function testRenewSessionTokenWithPassword() {
		$token = 'oldId';
		$uid = 'user';
		$user = 'User';
		$password = 'password';
		$name = 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12';
		$type = IToken::PERMANENT_TOKEN;

		$oldToken = $this->tokenProvider->generateToken($token, $uid, $user, $password, $name, $type, IToken::DO_NOT_REMEMBER);

		$this->mapper
			->expects($this->at(0))
			->method('getToken')
			->with(hash('sha512', 'oldId' . '1f4h9s'))
			->willReturn($oldToken);
		$this->mapper
			->expects($this->at(1))
			->method('insert')
			->with($this->callback(function (PublicKeyToken $token) use ($user, $uid, $name) {
				return $token->getUID() === $uid &&
					$token->getLoginName() === $user &&
					$token->getName() === $name &&
					$token->getType() === IToken::DO_NOT_REMEMBER &&
					$token->getLastActivity() === $this->time &&
					$token->getPassword() !== null &&
					$this->tokenProvider->getPassword($token, 'newId') === 'password';
			}));
		$this->mapper
			->expects($this->at(2))
			->method('delete')
			->with($this->callback(function($token) use ($oldToken) {
				return $token === $oldToken;
			}));

		$this->tokenProvider->renewSessionToken('oldId', 'newId');
	}

	public function testGetToken() {
		$token = new PublicKeyToken();

		$this->config->method('getSystemValue')
			->with('secret')
			->willReturn('mysecret');

		$this->mapper->method('getToken')
			->with(
				$this->callback(function (string $token) {
					return hash('sha512', 'unhashedToken'.'1f4h9s') === $token;
				})
			)->willReturn($token);

		$this->assertSame($token, $this->tokenProvider->getToken('unhashedToken'));
	}

	public function testGetInvalidToken() {
		$this->expectException(InvalidTokenException::class);

		$this->mapper->method('getToken')
			->with(
				$this->callback(function (string $token) {
					return hash('sha512', 'unhashedToken'.'1f4h9s') === $token;
				})
			)->willThrowException(new InvalidTokenException());

		$this->tokenProvider->getToken('unhashedToken');
	}

	public function testGetExpiredToken() {
		$token = 'token';
		$uid = 'user';
		$user = 'User';
		$password = 'passme';
		$name = 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12';
		$type = IToken::PERMANENT_TOKEN;

		$actual = $this->tokenProvider->generateToken($token, $uid, $user, $password, $name, $type, IToken::DO_NOT_REMEMBER);
		$actual->setExpires(42);

		$this->mapper->method('getToken')
			->with(
				$this->callback(function (string $token) {
					return hash('sha512', 'token'.'1f4h9s') === $token;
				})
			)->willReturn($actual);

		try {
			$this->tokenProvider->getToken('token');
			$this->fail();
		} catch (ExpiredTokenException $e) {
			$this->assertSame($actual, $e->getToken());
		}

	}

	public function testGetTokenById() {
		$token = $this->createMock(PublicKeyToken::class);

		$this->mapper->expects($this->once())
			->method('getTokenById')
			->with($this->equalTo(42))
			->willReturn($token);

		$this->assertSame($token, $this->tokenProvider->getTokenById(42));
	}

	public function testGetInvalidTokenById() {
		$this->expectException(InvalidTokenException::class);

		$this->mapper->expects($this->once())
			->method('getTokenById')
			->with($this->equalTo(42))
			->willThrowException(new DoesNotExistException('nope'));

		$this->tokenProvider->getTokenById(42);
	}

	public function testGetExpiredTokenById() {
		$token = new PublicKeyToken();
		$token->setExpires(42);

		$this->mapper->expects($this->once())
			->method('getTokenById')
			->with($this->equalTo(42))
			->willReturn($token);

		try {
			$this->tokenProvider->getTokenById(42);
			$this->fail();
		} catch (ExpiredTokenException $e) {
			$this->assertSame($token, $e->getToken());
		}
	}

	public function testRotate() {
		$token = 'oldtoken';
		$uid = 'user';
		$user = 'User';
		$password = 'password';
		$name = 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12';
		$type = IToken::PERMANENT_TOKEN;

		$actual = $this->tokenProvider->generateToken($token, $uid, $user, $password, $name, $type, IToken::DO_NOT_REMEMBER);

		$new = $this->tokenProvider->rotate($actual, 'oldtoken', 'newtoken');

		$this->assertSame('password', $this->tokenProvider->getPassword($new, 'newtoken'));
	}

	public function testRotateNoPassword() {
		$token = 'oldtoken';
		$uid = 'user';
		$user = 'User';
		$password = null;
		$name = 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12'
			. 'User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12';
		$type = IToken::PERMANENT_TOKEN;

		$actual = $this->tokenProvider->generateToken($token, $uid, $user, $password, $name, $type, IToken::DO_NOT_REMEMBER);

		$oldPrivate = $actual->getPrivateKey();

		$new = $this->tokenProvider->rotate($actual, 'oldtoken', 'newtoken');

		$newPrivate = $new->getPrivateKey();

		$this->assertNotSame($newPrivate, $oldPrivate);
		$this->assertNull($new->getPassword());
	}
}
