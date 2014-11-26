<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik\Plugins\LoginLdap\Auth;

use Exception;
use Piwik\Auth;
use Piwik\AuthResult;
use Piwik\Log;
use Piwik\Plugins\LoginLdap\Config;
use Piwik\Plugins\LoginLdap\Ldap\Exceptions\ConnectionException;
use Piwik\Plugins\LoginLdap\LdapInterop\UserSynchronizer;
use Piwik\Plugins\LoginLdap\Model\LdapUsers;
use Piwik\Plugins\UsersManager\API as UsersManagerAPI;
use Piwik\Plugins\UsersManager\Model as UserModel;

/**
 * Auth implementation that assumes the web server that hosts Piwik has authenticated
 * users.
 *
 * Supports every type of authentication since authentication is delegated to the web server.
 *
 * ## Implementation Details
 *
 * Checks for the $_SERVER['REMOTE_USER'] variable, if present assumes the user was authenticated
 * by the web server.
 *
 * This auth implementation will still connect to LDAP in order to synchronize user details.
 *
 * If the `[LoginLdap] synchronize_users_after_login` option is set to 0, synchronization
 * will not occur after login.
 */
class WebServerAuth extends Base
{
    /**
     * Whether a user's LDAP information should be synchronized with Piwik's DB after each
     * successful login or not.
     *
     * @var bool
     */
    private $synchronizeUsersAfterSuccessfulLogin = true;

    /**
     * Fallback LDAP Auth implementation to use if REMOTE_USER is not found.
     *
     * @var Auth
     */
    private $fallbackAuth;

    /**
     * Attempts to authenticate with the information set on this instance.
     *
     * @return AuthResult
     */
    public function authenticate()
    {
        try {
            $webServerAuthUser = $this->getAlreadyAuthenticatedLogin();

            if (empty($webServerAuthUser)) {
                Log::debug("using web server authentication, but REMOTE_USER server variable not found.");

                return $this->useFallbackAuth();
            } else {
                $this->login = preg_replace('/@.*/', '', $webServerAuthUser);
                $this->password = '';

                Log::info("User '%s' authenticated by webserver.", $this->login);

                if ($this->synchronizeUsersAfterSuccessfulLogin) {
                    $this->synchronizeLoggedInUser();
                } else {
                    Log::debug("WebServerAuth::%s: not synchronizing user '%s'.", __FUNCTION__, $this->login);
                }

                return $this->makeSuccessLogin($this->getUserForLogin());
            }
        } catch (ConnectionException $ex) {
            throw $ex;
        } catch (Exception $ex) {
            Log::debug($ex);
        }

        return $this->makeAuthFailure();
    }

    /**
     * Gets the {@link $synchronizeUsersAfterSuccessfulLogin} property.
     *
     * @return boolean
     */
    public function isSynchronizeUsersAfterSuccessfulLogin()
    {
        return $this->synchronizeUsersAfterSuccessfulLogin;
    }

    /**
     * Sets the {@link $synchronizeUsersAfterSuccessfulLogin} property.
     *
     * @param boolean $synchronizeUsersAfterSuccessfulLogin
     */
    public function setSynchronizeUsersAfterSuccessfulLogin($synchronizeUsersAfterSuccessfulLogin)
    {
        $this->synchronizeUsersAfterSuccessfulLogin = $synchronizeUsersAfterSuccessfulLogin;
    }

    /**
     * Gets the {@link $fallbackAuth} property.
     *
     * @return Auth
     */
    public function getFallbackAuth()
    {
        return $this->fallbackAuth;
    }

    /**
     * Sets the {@link $fallbackAuth} property.
     *
     * @param Auth $fallbackAuth
     */
    public function setFallbackAuth($fallbackAuth)
    {
        $this->fallbackAuth = $fallbackAuth;
    }

    private function getAlreadyAuthenticatedLogin()
    {
        return @$_SERVER['REMOTE_USER'];
    }

    private function synchronizeLoggedInUser()
    {
        $ldapUser = $this->ldapUsers->getUser($this->login);

        if (empty($ldapUser)) {
            Log::warning("Cannot find web server authenticated user %s in LDAP!", $this->login);
            return;
        }

        $this->synchronizeLdapUser($ldapUser);
    }

    /**
     * Returns a WebServerAuth instance configured with INI config.
     *
     * @return WebServerAuth
     */
    public static function makeConfigured()
    {
        $result = new WebServerAuth();
        $result->setLdapUsers(LdapUsers::makeConfigured());
        $result->setUsersManagerAPI(UsersManagerAPI::getInstance());
        $result->setUsersModel(new UserModel());
        $result->setUserSynchronizer(UserSynchronizer::makeConfigured());

        $synchronizeUsersAfterSuccessfulLogin = Config::getShouldSynchronizeUsersAfterLogin();
        $result->setSynchronizeUsersAfterSuccessfulLogin($synchronizeUsersAfterSuccessfulLogin);

        if (Config::getUseLdapForAuthentication()) {
            $fallbackAuth = LdapAuth::makeConfigured();
        } else {
            $fallbackAuth = SynchronizedAuth::makeConfigured();
        }

        $result->setFallbackAuth($fallbackAuth);

        Log::debug("WebServerAuth::%s: configuring with synchronizeUsersAfterSuccessfulLogin = %s, fallbackAuth = %s",
            __FUNCTION__, $synchronizeUsersAfterSuccessfulLogin, get_class($fallbackAuth));

        return $result;
    }

    private function useFallbackAuth()
    {
        Log::debug("WebServerAuth::useFallbackAuth(): attempting fallback auth with '%s'", get_class($this->fallbackAuth));

        $this->fallbackAuth->setLogin($this->login);
        $this->fallbackAuth->setPassword($this->password);
        return $this->fallbackAuth->authenticate();
    }
}