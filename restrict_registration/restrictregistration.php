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

		$loginRestrictPermission = $this->params->get('login_restrict');

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

		return true;
	}
}
