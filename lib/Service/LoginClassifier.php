<?php

declare(strict_types=1);

/**
 * @copyright 2018 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @author 2018 Christoph Wurst <christoph@winzerhof-wurst.at>
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
 */

namespace OCA\SuspiciousLogin\Service;

use function base64_decode;
use function explode;
use OCA\SuspiciousLogin\Exception\ServiceException;
use OCA\SuspiciousLogin\Util\AddressClassifier;
use function preg_match;
use function strlen;
use function substr;
use OCA\SuspiciousLogin\Db\SuspiciousLogin;
use OCA\SuspiciousLogin\Db\SuspiciousLoginMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ILogger;
use OCP\IRequest;
use Throwable;

class LoginClassifier {

	/** @var EstimatorService */
	private $estimator;

	/** @var IRequest */
	private $request;

	/** @var ILogger */
	private $logger;

	/** @var SuspiciousLoginMapper */
	private $mapper;

	/** @var LoginNotifier */
	private $loginNotifier;

	/** @var ITimeFactory */
	private $timeFactory;

	public function __construct(EstimatorService $estimator,
								IRequest $request,
								ILogger $logger,
								SuspiciousLoginMapper $mapper,
								LoginNotifier $loginNotifier,
								ITimeFactory $timeFactory) {
		$this->estimator = $estimator;
		$this->request = $request;
		$this->logger = $logger;
		$this->mapper = $mapper;
		$this->loginNotifier = $loginNotifier;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * @todo find a more reliable way of checking this
	 */
	private function isAuthenticatedWithAppPassword(IRequest $request): bool {
		$authHeader = $request->getHeader('Authorization');
		if (is_null($authHeader)) {
			return false;
		}
		if (substr($authHeader, 0, strlen('Basic ')) !== 'Basic ') {
			return false;
		}
		$pwd = explode(
			':',
			base64_decode(substr($authHeader, strlen('Basic ')))
		);
		if (!isset($pwd[1])) {
			return false;
		}

		return preg_match(
				"/^([0-9A-Za-z]{5})-([0-9A-Za-z]{5})-([0-9A-Za-z]{5})-([0-9A-Za-z]{5})-([0-9A-Za-z]{5})$/",
				$pwd[1]
			) === 1;
	}

	public function process(string $uid, string $ip) {
		if ($this->isAuthenticatedWithAppPassword($this->request)) {
			// We don't care about those logins
			$this->logger->debug("App password detected. No address classification is performed");
			return;
		}
		try {
			if (!AddressClassifier::isIpV4($ip)) {
				$this->logger->warning("Login is not from IPv4, hence it's considered suspicious");
				// TODO: add IPv6 classifier https://github.com/nextcloud-gmbh/suspicious_login/issues/44
			} else if ($this->estimator->predict($uid, $ip)) {
				// All good, carry on!
				return;
			}
		} catch (ServiceException $ex) {
			$this->logger->warning("Could not predict suspiciousness: " . $ex->getMessage());
			// This most likely means there is no trained model yet, so we return early here
			return;
		}

		$this->logger->warning("detected a login from a suspicious login. user=$uid ip=$ip");

		$this->persistSuspiciousLogin($uid, $ip);
		$this->notifyUser($uid, $ip);
	}

	/**
	 * @param string $uid
	 * @param string $ip
	 */
	protected function persistSuspiciousLogin(string $uid, string $ip) {
		try {
			$entity = new SuspiciousLogin();
			$entity->setUid($uid);
			$entity->setIp($ip);
			$entity->setRequestId($this->request->getId());
			$entity->setUrl($this->request->getRequestUri());
			$entity->setCreatedAt($this->timeFactory->getTime());

			$this->mapper->insert($entity);
		} catch (Throwable $ex) {
			$this->logger->critical("could not save the details of a suspicious login");
			$this->logger->logException($ex);
		}
	}

	/**
	 * @param string $uid
	 * @param string $ip
	 */
	protected function notifyUser(string $uid, string $ip): void {
		$now = $this->timeFactory->getTime();
		$twoHoursAgo = $now - 60*60*2;
		// We just inserted one alert – are there more with these params?
		if (count($this->mapper->findRecent($uid, $ip, $twoHoursAgo)) > 1) {
			$this->logger->debug("Notification for $uid:$ip already sent");
			return;
		}

		try {
			$this->loginNotifier->notify($uid, $ip);
		} catch (Throwable $ex) {
			$this->logger->critical("could not send notification about a suspicious login");
			$this->logger->logException($ex);
		}
	}

}
