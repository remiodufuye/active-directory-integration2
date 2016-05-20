<?php
if (!defined('ABSPATH')) {
	die('Access denied.');
}

if (class_exists('Adi_Synchronization_WordPress')) {
	return;
}

/**
 * Adi_Synchronization_WordPress sync the Active Directory users and their attribute values with WordPress.
 *
 * Adi_Synchronization_WordPress get all users from the Active Directory and WordPress. Then each user will be updated
 * or created with the attribute values supplied by the Active Directory
 *
 * @author Tobias Hellmann <the@neos-it.de>
 * @author Sebastian Weinert <swe@neos-it.de>
 * @author Danny Meißner <dme@neos-it.de>
 * @access public
 */
class Adi_Synchronization_WordPress extends Adi_Synchronization_Abstract
{
	// userAccountControl Flags
	const UF_ACCOUNT_DISABLE = 2;
	const UF_NORMAL_ACCOUNT = 512;
	const UF_INTERDOMAIN_TRUST_ACCOUNT = 2048;
	const UF_WORKSTATION_TRUST_ACCOUNT = 4096;
	const UF_SERVER_TRUST_ACCOUNT = 8192;
	const UF_MNS_LOGON_ACCOUNT = 131072;
	const UF_SMARTCARD_REQUIRED = 262144;
	const UF_PARTIAL_SECRETS_ACCOUNT = 67108864;

	// = UF_INTERDOMAIN_TRUST_ACCOUNT + UF_WORKSTATION_TRUST_ACCOUNT + UF_SERVER_TRUST_ACCOUNT + UF_MNS_LOGON_ACCOUNT + UF_PARTIAL_SECRETS_ACCOUNT
	const NO_UF_NORMAL_ACCOUNT = 67254272;

	/* @var Adi_User_Manager */
	private $userManager;

	/* @var Adi_Role_Manager */
	private $roleManager;

	/* @var Adi_User_Helper */
	private $userHelper;

	/* @var Logger $logger */
	private $logger;

	/* @var int */
	private $ldapRequestTimeCounter;

	/* @var int */
	private $wordpressDbTimeCounter;

	/**
	 * @param Adi_User_Manager                $userManager
	 * @param Adi_User_Helper                 $userHelper
	 * @param Multisite_Configuration_Service $configuration
	 * @param Ldap_Connection                 $connection
	 * @param Ldap_Attribute_Service          $attributeService
	 * @param Adi_Role_Manager                $roleManager
	 */
	public function __construct(Adi_User_Manager $userManager,
		Adi_User_Helper $userHelper,
		Multisite_Configuration_Service $configuration,
		Ldap_Connection $connection,
		Ldap_Attribute_Service $attributeService,
		Adi_Role_Manager $roleManager
	) {
		parent::__construct($configuration, $connection, $attributeService);

		$this->userManager = $userManager;
		$this->userHelper = $userHelper;
		$this->roleManager = $roleManager;

		$this->logger = Logger::getLogger(__CLASS__);
	}

	/**
	 * Get all users from certain Active Directory groups and import them as WordPress user into the WordPress database.
	 *
	 * @return bool
	 * @throws Exception
	 */
	public function synchronize()
	{
		if (!$this->prepareForSync()) {
			return false;
		}

		$startTime = time();
		$this->logger->debug('START: findSynchronizableUsers(): ' . $startTime);
		$users = $this->findSynchronizableUsers();
		$this->logger->debug('END: findSynchronizableUsers(): Duration:  ' .  time() - $startTime . ' seconds');

		if (is_array($users) && !empty($users)) {
			$this->logNumberOfUsers($users);

			$addedUsers = 0;
			$updatedUsers = 0;
			$failedSync = 0;

			foreach ($users as $guid => $sAMAccountName) {
				$status = $this->synchronizeUser(new Adi_Authentication_Credentials($sAMAccountName), $guid);

				switch ($status) {
					case 0:
						$addedUsers++;
						break;
					case 1:
						$updatedUsers++;
						break;
					default:
						$failedSync++;
				}
			}

			$this->finishSynchronization($addedUsers, $updatedUsers, $failedSync);

			return true;
		}

		$this->logger->error("No possible users for Sync to Wordpress were found.");

		return false;
	}

	/**
	 *
	 * @return bool
	 */
	protected function prepareForSync()
	{
		$enabled = $this->configuration->getOptionValue(Adi_Configuration_Options::SYNC_TO_WORDPRESS_ENABLED);
		if (!$enabled) {
			$this->logger->info('Sync to WordPress is disabled.');

			return false;
		}

		$this->logger->info('Start of Sync to WordPress');
		$this->startTimer();

		$username = $this->configuration->getOptionValue(Adi_Configuration_Options::SYNC_TO_WORDPRESS_USER);
		$password = $this->configuration->getOptionValue(Adi_Configuration_Options::SYNC_TO_WORDPRESS_PASSWORD);
		if (empty($username) && empty($password)) {
			$this->logger->error('Sync to WordPress global user or password not set.');

			return false;
		}
		if (!$this->connectToAdLdap($username, $password)) {
			return false;
		}

		$this->increaseExecutionTime();

		Logger::getRootLogger()->setLevel(LoggerLevel::getLevelInfo());

		return true;
	}

	/**
	 * Combines all usernames from WordPress and from Active Directory and returns their user data.
	 * The sAMAccountName is used as identifier.
	 *
	 * @return array
	 */
	protected function findSynchronizableUsers()
	{

		$groups = trim(
			$this->configuration->getOptionValue(Adi_Configuration_Options::SYNC_TO_WORDPRESS_SECURITY_GROUPS)
		);
		$activeDirectoryUsers = $this->connection->findAllMembersOfGroups($groups);
		$convertedActiveDirectoryUsers = $this->convertActiveDirectoryUsers($activeDirectoryUsers);

		$wordPressUsers = $this->findActiveDirectoryUsernames();

		return array_merge($wordPressUsers, $convertedActiveDirectoryUsers);
	}

	/**
	 * Convert the given array into our necessary format.
	 *
	 * @param $adUsers
	 *
	 * @return array|hashmap key is Active Directory objectGUID, value is username
	 */
	protected function convertActiveDirectoryUsers($adUsers)
	{
		$result = array();

		foreach ($adUsers AS $adUser) {
			$attributes = $this->attributeService->findLdapAttributesOfUsername($adUser);
			$guid = $attributes->getFilteredValue(Adi_User_Persistence_Repository::META_KEY_OBJECT_GUID);

			$result[strtolower($guid)] = $adUser;
		}

		return $result;
	}

	/**
	 * Log number of users.
	 *
	 * @param int $users
	 */
	protected function logNumberOfUsers($users)
	{
		$elapsedTime = $this->getElapsedTime();
		$numberOfUsers = count($users);
		$this->logger->info("Number of users to import/update: $numberOfUsers ($elapsedTime seconds)");
	}

	/**
	 * Returns the value of the key "useraccountcontrol"
	 *
	 * @param array $attributes
	 *
	 * @return int 0 if parameter is empty, null or anything else
	 */
	public function userAccountControl($attributes)
	{
		$key = "useraccountcontrol";

		if (!$attributes || !isset($attributes[$key]) || !is_array($attributes[$key])) {
			return 0;
		}

		$uac = $attributes[$key][0];

		return $uac;
	}

	/**
	 * Is the account a normal account
	 *
	 * @param int $uac
	 *
	 * @return bool
	 */
	public function isNormalAccount($uac)
	{
		if (($uac & (self::UF_NORMAL_ACCOUNT | self::NO_UF_NORMAL_ACCOUNT)) === self::UF_NORMAL_ACCOUNT) {
			return true;
		}

		return false;
	}

	/**
	 * Is a smart card required for the account?
	 *
	 * @param int $uac
	 *
	 * @return bool
	 */
	public function isSmartCardRequired($uac)
	{
		if (($uac & self::UF_SMARTCARD_REQUIRED) === 0) {
			return false;
		}

		return true;
	}

	/**
	 * Has the account been disabled?
	 *
	 * @param int $uac
	 *
	 * @return bool
	 */
	public function isAccountDisabled($uac)
	{
		if (($uac & self::UF_ACCOUNT_DISABLE) === self::UF_ACCOUNT_DISABLE) {
			return true;
		}

		return false;
	}

	/**
	 * Convert an Active Directory user to a WordPress user
	 *
	 * @param Adi_Authentication_Credentials $credentials
	 *
	 * @return bool|string
	 * @throws Exception
	 */
	public function synchronizeUser(Adi_Authentication_Credentials $credentials, $guid)
	{
		Core_Assert::notNull($credentials);

		$synchronizeDisabledAccounts = $this->configuration->getOptionValue(
			Adi_Configuration_Options::SYNC_TO_WORDPRESS_DISABLE_USERS
		);

		$startTimerLdap = time();

		// ADI-204: in contrast to the Login process we use the sAMAccountName in synchronization have the sAMAccountName
		$ldapAttributes = $this->attributeService->findLdapAttributesOfUser($credentials, $guid);

		$elapsedTimeLdap = time() - $startTimerLdap;
		$this->ldapRequestTimeCounter = $this->ldapRequestTimeCounter + $elapsedTimeLdap;

			$credentials->setUserPrincipalName($ldapAttributes->getFilteredValue('userprincipalname'));


		$adiUser = $this->userManager->createAdiUser($credentials, $ldapAttributes);

		// check account restrictions
		if ($synchronizeDisabledAccounts) {
			if (!$this->checkAccountRestrictions($adiUser)) {
				return false;
			}
		}

		$startTimerWordPress = time();
		$status = $this->createOrUpdateUser($adiUser);
		$elapsedTimeWordPress = time() - $startTimerWordPress;
		$this->wordpressDbTimeCounter = $this->wordpressDbTimeCounter + $elapsedTimeWordPress;

		if (-1 === $status) {
			return -1;
		}

		// if option is enabled and user is disabled in AD, disable him in WordPress
		$this->synchronizeAccountStatus($adiUser, $synchronizeDisabledAccounts);

		return $status;
	}

	/**
	 * Create or update an user.
	 * Due to the different requirements for login and synchronization we cannot use a common base.
	 *
	 * @param Adi_User $adiUser
	 *
	 * @return int 0=created,1=updated,-1=error
	 */
	protected function createOrUpdateUser(Adi_User $adiUser)
	{
		Core_Assert::notNull($adiUser);

		if (!$adiUser->getId()) {
			$startTimer = time();
			$user = $this->userManager->create($adiUser, true);
			$this->logger->info("Creating user took: " . (time() - $startTimer) . " s");
			$status = 0;
		} else {
			$user = $this->userManager->update($adiUser, true);
			$status = 1;
		}

		if (is_wp_error($user)) {
			return -1;
		}

		return $status;
	}

	/**
	 * Check account restrictions:
	 * <ul>
	 * <li>Is the user still present in Active Directory?</li>
	 * <li>Is his account a normal account?</li>
	 * <li>Is a smart card required?</li>
	 * </ul>
	 * If one of those checks matches, the account is disabled.
	 *
	 * @param Adi_User $adiUser
	 *
	 * @return bool
	 */
	public function checkAccountRestrictions(Adi_User $adiUser)
	{
		$rawLdapAttributes = $adiUser->getLdapAttributes()->getRaw();
		$username = $adiUser->getCredentials()->getSAMAccountName();

		$isInActiveDirectory = isset($rawLdapAttributes) && (sizeof($rawLdapAttributes) > 0);
		$isInWordPress = ($adiUser->getId() > 0);
		$uac = $this->userAccountControl($rawLdapAttributes);

		if (!$isInWordPress) {
			return true;
		}

		try {
			if (!$isInActiveDirectory) {
				throw new Exception(sprintf(__('User "%s" no longer found in Active Directory.', ADI_I18N), $username));
			}

			if (!$this->isNormalAccount($uac)) {
				throw new Exception(
					sprintf(
						__(
							'User "%s" has no normal Active Directory user account. Only user accounts can be synchronized.',
							ADI_I18N
						), $username
					)
				);
			}

			if ($this->isSmartCardRequired($uac)) {
				throw new Exception(
					sprintf(
						__('The account of user "%s" requires a smart card for login.', ADI_I18N),
						$username
					)
				);
			}
		} catch (Exception $e) {
			$this->logger->warn("Disable user '{$username}': " . $e->getMessage());
			$this->userManager->disable($adiUser->getId(), $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Synchronize the user's account status (locked/enabled).
	 * If the AD account has the status "Enabled", this status will be always synchronized to WordPress.
	 * If the AD account has the status "Locked/Disabled" this status will be only synchronized with "Sync to WordPress > Automatich deactivate users".
	 *
	 * @param Adi_User $adiUser
	 * @param bool     $synchronizeDisabledAccounts
	 *
	 * @return bool
	 */
	public function synchronizeAccountStatus(Adi_User $adiUser, $synchronizeDisabledAccounts)
	{
		$uac = $this->userAccountControl($adiUser->getLdapAttributes()->getRaw());

		if (!$this->isAccountDisabled($uac)) {
			$this->logger->info("Enabling user '{$adiUser->getUserLogin()}'.");
			$this->userManager->enable($adiUser->getId());

			return true;
		}

		$this->logger->info("The user '{$adiUser->getUserLogin()}' is disabled in Active Directory.");

		if (!$synchronizeDisabledAccounts) {
			return false;
		}

		$this->logger->warn("Disabling user '{$adiUser->getUserLogin()}'.");
		$message = sprintf(__('User "%s" is disabled in Active Directory.', ADI_I18N), $adiUser->getUserLogin());
		$this->userManager->disable($adiUser->getId(), $message);

		return false;
	}

	/**
	 * Finish synchronization with some log messages.
	 *
	 * @param int $addedUsers   amount of added users
	 * @param int $updatedUsers amount of updated users
	 * @param int $failedSync   amount of failed syncs
	 */
	protected function finishSynchronization($addedUsers, $updatedUsers, $failedSync)
	{
		Logger::getRootLogger()->setLevel(LoggerLevel::getLevelDebug());
		$elapsedTime = $this->getElapsedTime();

		$this->logger->info("$addedUsers users have been added to the WordPress database.");
		$this->logger->info("$updatedUsers users from the WordPress database have been updated.");
		$this->logger->info("$failedSync users could not be synchronized.");
		$this->logger->info("Ldap searches took: $this->ldapRequestTimeCounter seconds");
		$this->logger->info("WordPress DB actions took: $this->wordpressDbTimeCounter seconds");
		$this->logger->info("Duration for sync: $elapsedTime seconds");
		$this->logger->info("End of Sync to WordPress");
	}
}
