<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  User.restrict_registration
 *
 * @copyright   Copyright (C) 2009 - 2021 Techjoomla. All rights reserved.
 * @license     http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\UserHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Utilities\ArrayHelper;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

// Load language file for plugin.
$lang = JFactory::getLanguage();
$lang->load('plg_user_restrictregistration', JPATH_ADMINISTRATOR);

/**
 * An example custom user restrict plugin.
 *
 * @since  __DEPLOY_VERSION__
 */
class PlgUserRestrictRegistration extends CMSPlugin
{
	/**
    * Load the language file on instantiation.
    *
    * @var    boolean
    * @since  __DEPLOY_VERSION__
    */
    protected $autoloadLanguage = true;

	/**
	 * Application object
	 *
	 * @var    JApplicationCms
	 * @since  __DEPLOY_VERSION__
	 */
	protected $app;

	/**
	 * Method is called before user data is stored in the database
	 *
	 * @param   array    $user   Holds the old user data.
	 * @param   boolean  $isNew  True if a new user is stored.
	 * @param   array    $data   Holds the new user data.
	 *
	 * @return  boolean
	 *
	 * @since   __DEPLOY_VERSION__
	 * @throws  InvalidArgumentException on missing required data.
	 */
	public function onUserBeforeSave($user, $isNew, $data)
	{
		$userId = ArrayHelper::getValue($user, 'id', 0, 'int');

		// User already registered, no need to check it further
		if ($userId > 0)
		{
			return true;
		}

		JLoader::import('components.com_users.models.users', JPATH_ADMINISTRATOR);
		$usersModel = BaseDatabaseModel::getInstance('Users', 'UsersModel', array('ignore_request' => true));

		$userCount = $usersModel->getTotal();

		$restrictCount = $this->params->get('userCount');

		if ($isNew && !empty($restrictCount))
		{
			if ($userCount >= $restrictCount)
			{
				throw new InvalidArgumentException(Text::_('PLG_USER_RESTRICT_REGISTRATION_MSG'));

				return false;
			}
		}

		return true;
	}

	/**
	 * This method should handle any login logic and report back to the subject
	 *
	 * @param   array  $user     Holds the user data
	 * @param   array  $options  Array holding options (remember, autoregister, group)
	 *
	 * @return  boolean  True on success
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onUserLogin($user, $options = array())
	{
		$app = Factory::getApplication();
		$now = new \DateTime();

		$loginRestrictPermission       = $this->params->get('login_restrict');
		$activeUniqueLoginRestrictPermission = $this->params->get('active_unique_login_restrict');
		$activeLoginRestrictPermission = $this->params->get('active_login_restrict');

		if ($loginRestrictPermission)
		{
			$loginUserCount = $this->params->get('loginUserCount');
			$restrictPeriod = $this->params->get('restrictPeriod');

			$userId = UserHelper::getUserId($user['username']);
			$check = array();

			if ($this->app->isClient('site'))
			{
				// Get a db connection.
				$db = JFactory::getDbo();

				// Create a new query object.
				$query = $db->getQuery(true);

				$query->select('a.*');
				$query->from($db->quoteName('#__plg_user_restrict_activities', 'a'));
				$query->where('MONTH('.$db->quoteName('a.created').')  = '. $db->quote($now->format('m')));
				$db->setQuery($query);
				$users = $db->loadAssocList();
				$userCount = count($users);

				$check = array_column($users, 'user_id');

				if (!(in_array($userId, $check)))
				{
					if ($loginUserCount <= $userCount)
					{
						// Add a message to the message queue
						$this->app->enqueueMessage(Text::_('PLG_USER_RESTRICT_LOGIN_MSG'), 'warning');

						Factory::getApplication()->setUserState('com_users.action.uid', (int) $userId);
						$redirect_to_url = URI::root().'index.php?option=com_users&view=login&'.Session::getFormToken().'=1';
						Factory::getApplication()->redirect($redirect_to_url);

						return false;
					}
					else
					{
						// Insert columns.
						$columns = array('user_id', 'action', 'created');

						// Insert values.
						$values = array($db->quote($userId), $db->quote('LOGIN'), $db->quote(date('Y-m-d H:i:s')));

						// Prepare the insert query.
						$query
						    ->insert($db->quoteName('#__plg_user_restrict_activities'))
						    ->columns($db->quoteName($columns))
						    ->values(implode(',', $values));

						// Set the query using our newly populated query object and execute it.
						$db->setQuery($query);
						$db->execute();
					}
				}
			}
		}

		if ($activeUniqueLoginRestrictPermission)
		{
			$activeUniqueLoginUserCount = $this->params->get('max_active_unique_logins');

			if ($this->app->isClient('site'))
			{
				$instance = $this->_getUser($user, $options);

				// If _getUser returned an error, then pass it back.
				if ($instance instanceof Exception)
				{
					return false;
				}

				// If the user is blocked, redirect with an error
				if ($instance->block == 1)
				{
					$this->app->enqueueMessage(Text::_('JERROR_NOLOGIN_BLOCKED'), 'warning');

					return false;
				}

				// Check the user can login.
				$result = $instance->authorise($options['action']);

				if (!$result)
				{
					$this->app->enqueueMessage(Text::_('JERROR_LOGIN_DENIED'), 'warning');

					return false;
				}

				// Get the user sessions
				$this->db = JFactory::getDbo();

				$query = $this->db->getQuery(true);
				$query->select('b.*');
				$query->from($this->db->quoteName('#__session', 'b'));
				$query->where($this->db->quoteName('b.guest') . ' = 0');
				$query->where('(' . $this->db->quoteName('b.client_id') . ' = 0' . ' OR ' . $this->db->quoteName('b.client_id') . ' IS NULL' . ')'); // 0 is for users, 1 is for admins
				$query->where($this->db->quoteName('b.userid') . ' = '. (int) $instance->id);

				try
				{
					$this->db->setQuery($query);
					$count_of_user_sessions = count($this->db->loadObjectlist());
				}
				catch (RuntimeException $e)
				{
					JError::raiseError(500, $e->getMessage());
					return false;
				}

				if ($count_of_user_sessions >= $activeUniqueLoginUserCount)
				{
					$this->app->enqueueMessage(Text::sprintf('PLG_USER_RESTRICT_ACTIVE_UNIQUE_LOGIN_ERROR_MESSAGE', $activeUniqueLoginUserCount), 'warning');
					Factory::getApplication()->setUserState('com_users.action.uid', (int) $userId);
					$redirect_to_url = URI::root().'index.php?option=com_users&view=login&'.Session::getFormToken().'=1';
					Factory::getApplication()->redirect($redirect_to_url);

					return false;
				}
			}
		}

		if ($activeLoginRestrictPermission)
		{
			$activeLoginUserCount = $this->params->get('max_active_logins');

			if ($this->app->isClient('site'))
			{
				$instance = $this->_getUser($user, $options);

				// If _getUser returned an error, then pass it back.
				if ($instance instanceof Exception)
				{
					return false;
				}

				// If the user is blocked, redirect with an error
				if ($instance->block == 1)
				{
					$this->app->enqueueMessage(Text::_('JERROR_NOLOGIN_BLOCKED'), 'warning');

					return false;
				}

				// Check the user can login.
				$result = $instance->authorise($options['action']);

				if (!$result)
				{
					$this->app->enqueueMessage(Text::_('JERROR_LOGIN_DENIED'), 'warning');

					return false;
				}

				// Get the user sessions
				$this->db = JFactory::getDbo();

				$query = $this->db->getQuery(true);
				$query->select('b.*');
				$query->from($this->db->quoteName('#__session', 'b'));
				$query->where($this->db->quoteName('b.guest') . ' = 0');
				$query->where('(' . $this->db->quoteName('b.client_id') . ' = 0' . ' OR ' . $this->db->quoteName('b.client_id') . ' IS NULL' . ')'); // 0 is for users, 1 is for admins

				try
				{
					$this->db->setQuery($query);
					$count_of_active_user_sessions = count($this->db->loadObjectlist());
				}
				catch (RuntimeException $e)
				{
					JError::raiseError(500, $e->getMessage());
					return false;
				}

				if ($count_of_active_user_sessions >= $activeLoginUserCount)
				{
					$this->app->enqueueMessage(Text::_('PLG_USER_RESTRICT_ACTIVE_LOGIN_MSG'), 'warning');
					Factory::getApplication()->setUserState('com_users.action.uid', (int) $userId);
					$redirect_to_url = URI::root().'index.php?option=com_users&view=login&'.Session::getFormToken().'=1';
					Factory::getApplication()->redirect($redirect_to_url);

					return false;
				}
			}
		}

		return true;
	}

	/**
	 * This method will return a user object
	 *
	 * If options['autoregister'] is true, if the user doesn't exist yet they will be created
	 *
	 * @param   array  $user     Holds the user data.
	 * @param   array  $options  Array holding options (remember, autoregister, group).
	 *
	 * @return  User
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	protected function _getUser($user, $options = array())
	{
		$instance = User::getInstance();
		$id = (int) UserHelper::getUserId($user['username']);

		if ($id)
		{
			$instance->load($id);

			return $instance;
		}

		// TODO : move this out of the plugin
		$params = ComponentHelper::getComponent('com_users')->getParams();

		// Read the default user group option from com_users
		$defaultUserGroup = $params->get('new_usertype', $params->get('guest_usergroup', 1));

		$instance->id = 0;
		$instance->name = $user['fullname'];
		$instance->username = $user['username'];
		$instance->password_clear = $user['password_clear'];

		// Result should contain an email (check).
		$instance->email = $user['email'];
		$instance->groups = array($defaultUserGroup);

		// If autoregister is set let's register the user
		$autoregister = isset($options['autoregister']) ? $options['autoregister'] : $this->params->get('autoregister', 1);

		if ($autoregister)
		{
			if (!$instance->save())
			{
				$this->app->enqueueMessage(Text::_('Error in autoregistration for user ' . $user['username'] . '.'), 'error');
				return false;
			}
		}
		else
		{
			// No existing user and autoregister off, this is a temporary user.
			$instance->set('tmp_user', true);
		}

		return $instance;
	}
}
