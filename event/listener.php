<?php
/**
*
* Activity 24 hours extension for the phpBB Forum Software package.
*
* @copyright (c) 2015 Rich McGirr (RMcGirr83)
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace rmcgirr83\activity24hours\event;

/**
* @ignore
*/
use phpbb\auth\auth;
use phpbb\cache\service as cache;
use phpbb\config\config;
use phpbb\db\driver\driver_interface as db;
use phpbb\event\dispatcher_interface as dispatcher;
use phpbb\language\language;
use phpbb\template\template;
use phpbb\user;
use phpbb\extension\manager as ext_manager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
* Event listener
*/
class listener implements EventSubscriberInterface
{
	/** @var auth $auth */
	protected $auth;

	/** @var cache $cache */
	protected $cache;

	/** @var config $config */
	protected $config;

	/** @var db $db */
	protected $db;

	/** @var dispatcher $dispatcher */
	protected $dispatcher;

	/** @var language $language */
	protected $language;

	/** @var template $template */
	protected $template;

	/** @var user $user */
	protected $user;

	/** @var hidebots $hidebots */
	private $hidebots;

	/** @var relativedates $relativedates */
	private $relativedates;

	/** @var ext_manager $ext_manager */
	protected $ext_manager;

	public function __construct(
		auth $auth,
		cache $cache,
		config $config,
		db $db,
		dispatcher $dispatcher,
		language $language,
		template $template,
		user $user,
		hidebots $hidebots = null,
		relativedates $relativedates = null,
		ext_manager $ext_manager)
	{
		$this->auth = $auth;
		$this->cache = $cache;
		$this->config = $config;
		$this->db = $db;
		$this->dispatcher = $dispatcher;
		$this->language = $language;
		$this->template = $template;
		$this->user = $user;
		$this->hidebots = $hidebots;
		$this->relativedates = $relativedates;
		$this->ext_manager	 = $ext_manager;

		// the amount of time to lookback for activity
		// look back time can be set at the bottom of this file in the look_back function
		$this->lookback = $this->define_interval($this->look_back());
	}

	/**
	* Assign functions defined in this class to event listeners in the core
	*
	* @return array
	* @static
	* @access public
	*/
	static public function getSubscribedEvents()
	{
		return [
			'core.acp_extensions_run_action_after'	=>	'acp_extensions_run_action_after',
			'core.permissions'						=>	'activity24hours_stats_permissions',
			'core.index_modify_page_title'			=>	'display_24_hour_stats',
		];
	}

	/* Display additional metdate in extension details
	*
	* @param $event			event object
	* @param return null
	* @access public
	*/
	public function acp_extensions_run_action_after($event)
	{
		if ($event['ext_name'] == 'rmcgirr83/activity24hours' && $event['action'] == 'details')
		{
			$this->language->add_lang('common', $event['ext_name']);
			$this->template->assign_vars([
				'L_BUY_ME_A_BEER_EXPLAIN'		=> $this->language->lang('BUY ME A BEER_EXPLAIN', '<a href="' . $this->language->lang('BUY_ME_A_BEER_URL') . '" target="_blank" rel="noreferrer noopener">', '</a>'),
				'S_BUY_ME_A_BEER_A24H' => true,
			]);
		}
	}

	/**
	 * Permission's language file is automatically loaded
	 *
	 * @event core.permissions
	 */
	public function activity24hours_stats_permissions($event)
	{
		$permissions = $event['permissions'];
		$permissions['u_a24hrs_view'] = ['lang'	=> 'ACL_U_A24HRS_VIEW',	'cat'	=> 'misc'];
		$event['permissions'] = $permissions;
	}

	/**
	* Display stats on index page
	*
	* @param object $event The event object
	* @return null
	* @access public
	*/
	public function display_24_hour_stats($event)
	{
		// if the user is not allowed to view
		if (!$this->auth->acl_get('u_a24hrs_view'))
		{
			return;
		}

		$this->language->add_lang('common', 'rmcgirr83/activity24hours');

		// This sets our header for the extension to be dynamic based on the seconds input in the look_back function that is defined at the bottom of this file
		$lookback_seconds = $this->look_back();

		$seconds_error = '';
		if ($lookback_seconds < 60)
		{
			$seconds_error = $this->auth->acl_get('a_') ? true : false;
		}

		$seconds_in_a_minute = 60;
		$seconds_in_an_hour = 3600;
		$seconds_in_a_day = 24 * $seconds_in_an_hour;

		// Extract days
		$days = floor($lookback_seconds / $seconds_in_a_day);

		// Extract hours
		$hour_seconds = $lookback_seconds % $seconds_in_a_day;
		$hours = floor($hour_seconds / $seconds_in_an_hour);

		// Extract minutes
		$minute_seconds = $hour_seconds % $seconds_in_an_hour;
		$minutes = floor($minute_seconds / $seconds_in_a_minute);

		// Format
		$timeparts = [];
		$sections = [
			'DAY'	=> (int) $days,
			'HOUR'	=> (int) $hours,
			'MIN'	=> (int) $minutes,
		];

		foreach ($sections as $name => $value)
		{
			if ($value > 0)
			{
				$timeparts[] = $this->language->lang('24HOUR_' . $name, $value);
			}
		}

		$timeparts_count = count($timeparts);

		$lookback_string = '';

		if ($timeparts_count == 3)
		{
			$lookback_string = $timeparts[0] . $this->language->lang('COMMA_SEPARATOR') . $timeparts[1] . $this->language->lang('24HOUR_AND') . $timeparts[2];
		}
		else if ($timeparts_count == 2)
		{
			$lookback_string = implode($this->language->lang('24HOUR_AND'), $timeparts);
		}
		else
		{
			$lookback_string = implode('', $timeparts);
		}

		// obtain posts/topics/new users activity
		$activity = $this->obtain_activity_data();

		// obtain user activity data
		$active_users = $this->obtain_active_user_data();

		// Obtain guests data
		$total_guests_online_24 = $this->obtain_guest_count_24();

		$user_count = $bot_count = $hidden_count = 0;

		// we hide bots according to the hide bots extension
		$hide_bots = (!$this->auth->acl_get('a_') && $this->hidebots !== null) ? true : false;

		if (!empty($active_users))
		{
			// parse the activity
			foreach ($active_users as $row)
			{
				// the users stuff...this is changed below depending
				$username_string = $this->auth->acl_get('u_viewprofile') ? get_username_string('full', $row['user_id'], $row['username'], $row['user_colour']) : get_username_string('no_profile', $row['user_id'], $row['username'], $row['user_colour']);
				$max_last_visit = max($row['user_lastvisit'], $row['session_time']);

				// relativedates installed?
				if ($this->relativedates !== null)
				{
					$hover_info = $this->user->format_date($max_last_visit, false, false, false);
				}
				else
				{
					$hover_info = $this->user->format_date($max_last_visit);
				}

				$hover_info = ' title="' . $hover_info . '"';

				if (($hide_bots && $row['user_type'] == USER_IGNORE) || ($row['user_lastvisit'] < $this->lookback && $row['session_time'] < $this->lookback))
				{
					continue;
				}

				if (((!$row['session_viewonline'] && !empty($row['session_time'])) || !$row['user_allow_viewonline']) && $row['user_type'] != USER_IGNORE )
				{
					++$hidden_count;
					if ($this->auth->acl_get('u_viewonline') || $row['user_id'] == $this->user->data['user_id'])
					{
						$row['username'] = '<em>' . $row['username'] . '</em>';
						$username_string = get_username_string('full', $row['user_id'], $row['username'], $row['user_colour']);
					}
					else
					{
						++$user_count;
						continue;
					}
				}
				// to seperate bots from normal users
				else if ($row['user_type'] == USER_IGNORE)
				{
					++$bot_count;
					$this->template->assign_block_vars('bot_lastvisit', [
						'BOTNAME_FULL'	=> '<span' . $hover_info . '>' . get_username_string('no_profile', $row['user_id'], $row['username'], $row['user_colour']) . '</span>',
					]);
					continue;
				}

				++$user_count;
				$this->template->assign_block_vars('lastvisit', [
					'USERNAME_FULL'	=> '<span' . $hover_info . '>' . $username_string . '</span>',
				]);
			}
		}

		$display_link = false;

		if ($user_count || $hidden_count || $bot_count)
		{
			$display_link = true;
		}

		// assign the forum stats to the template.
		$template_data = [
			'DISPLAY_LINK'			=> $display_link,
			'BOTS_ACTIVE'			=> (!$hide_bots) ? $bot_count : '',
			'USERS_ACTIVE'			=> $user_count + $hidden_count,
			'TOTAL_24HOUR_USERS'	=> $this->language->lang('TOTAL_24HOUR_USERS', $user_count + $total_guests_online_24 + $bot_count),
			'USERS_24HOUR_TOTAL'	=> $this->language->lang('USERS_24HOUR_TOTAL', $user_count - $hidden_count),
			'BOTS_24HOUR_TOTAL'		=> $this->language->lang('BOTS_24HOUR_TOTAL', $bot_count),
			'HIDDEN_24HOUR_TOTAL'	=> $this->language->lang('HIDDEN_24HOUR_TOTAL', $hidden_count),
			'GUEST_ONLINE_24'		=> $total_guests_online_24 ? $this->language->lang('GUEST_ONLINE_24', $total_guests_online_24) : '',
			'HOUR_TOPICS'			=> $this->language->lang('24HOUR_TOPICS', $activity['topics']),
			'HOUR_POSTS'			=> $this->language->lang('24HOUR_POSTS', $activity['posts']),
			'HOUR_USERS'			=> $this->language->lang('24HOUR_USERS', $activity['users']),
			'S_CAN_VIEW_24_HOURS'	=> $this->auth->acl_get('u_a24hrs_view') ? true : false,
			'S_TABBEDSTATBLOCK'		=> $this->ext_manager->is_enabled('zyleta/tabbedstatblock'),

			'HOUR_ERROR'			=> $seconds_error,

			'L_TWENTYFOURHOUR_STATS'	=> $this->language->lang('TWENTYFOURHOUR_STATS') . ' ' . $lookback_string,
		];
		/**
		* Modify activity display
		*
		* @event rmcgirr83.activity24hours.modify_activity_display
		* @var array	activity				An array of the activity posts, topics etc.
		* @var array	active_users			An array of users active for past x time
		* @var bool		total_guests_online_24 Count of guests for past x time
		* @var array	template_data			An array of the template items
		* @since 1.0.6
		*/
		$vars = ['activity', 'active_users', 'total_guests_online_24', 'template_data'];
		extract($this->dispatcher->trigger_event('rmcgirr83.activity24hours.modify_activity_display', compact($vars)));

		// Assign template date to template engine
		$this->template->assign_vars($template_data);
	}

	/**
	 * Obtain an array of active users over the last 24 hours.
	 *
	 * @return array
	 */
	private function obtain_active_user_data()
	{
		if (($active_users = $this->cache->get('_24hour_users')) === false)
		{
			// grab a list of users who are currently online
			// and users who have visited in the last XX hours
			$sql_ary = [
				'SELECT'	=> 'u.user_id, u.user_colour, u.username, u.user_type, u.user_lastvisit, u.user_allow_viewonline, MAX(s.session_time) as session_time, s.session_viewonline',
				'FROM'		=> [USERS_TABLE => 'u'],
				'LEFT_JOIN'	=> [
					[
						'FROM'	=> [SESSIONS_TABLE => 's'],
						'ON'	=> 's.session_user_id = u.user_id',
					],
				],
				'WHERE'		=> 'u.user_lastvisit > ' . (int) $this->lookback . ' OR s.session_user_id <> ' . ANONYMOUS,
				'GROUP_BY'	=> 'u.user_id, s.session_viewonline',
				'ORDER_BY'	=> 'u.username_clean',
			];

			/**
			* Modify sql_ary
			*
			* @event rmcgirr83.activity24hours.modify_sql_ary
			* @var array	sql_ary			An array of the sql query
			* @since 1.0.4
			*/
			$vars = ['sql_ary'];
			extract($this->dispatcher->trigger_event('rmcgirr83.activity24hours.modify_sql_ary', compact($vars)));

			$result = $this->db->sql_query($this->db->sql_build_query('SELECT', $sql_ary));

			while ($row = $this->db->sql_fetchrow($result))
			{
				$active_users[$row['user_id']] = $row;
			}
			$this->db->sql_freeresult($result);

			// cache this data for 5 minutes, this improves performance
			$this->cache->put('_24hour_users', $active_users, 300);
		}

		/**
		* Modify active_users
		*
		* @event rmcgirr83.activity24hours.modify_active_users
		* @var array	active_users	An array of active user data
		* @since 1.1.1
		*/
		$vars = ['active_users'];
		extract($this->dispatcher->trigger_event('rmcgirr83.activity24hours.modify_active_users', compact($vars)));

		return $active_users;
	}

	/**
	 * obtained cached 24 hour activity data
	 *
	 * @return array
	 */
	private function obtain_activity_data()
	{
		if (($activity = $this->cache->get('_24hour_activity')) === false)
		{
			// total new posts in the last 24 hours
			$sql = 'SELECT COUNT(post_id) AS new_posts
					FROM ' . POSTS_TABLE . '
					WHERE post_time > ' . (int) $this->lookback;
			$result = $this->db->sql_query($sql);
			$activity['posts'] = $this->db->sql_fetchfield('new_posts');
			$this->db->sql_freeresult($result);

			// total new topics in the last 24 hours
			$sql = 'SELECT COUNT(topic_id) AS new_topics
					FROM ' . TOPICS_TABLE . '
					WHERE topic_time > ' . (int) $this->lookback;
			$result = $this->db->sql_query($sql);
			$activity['topics'] = $this->db->sql_fetchfield('new_topics');
			$this->db->sql_freeresult($result);

			// total new users in the last 24 hours, counts inactive users as well
			$sql = 'SELECT COUNT(user_id) AS new_users
					FROM ' . USERS_TABLE . '
					WHERE user_regdate > ' . (int) $this->lookback;
			$result = $this->db->sql_query($sql);
			$activity['users'] = $this->db->sql_fetchfield('new_users');
			$this->db->sql_freeresult($result);

			// cache this data for 5 minutes, this improves performance
			$this->cache->put('_24hour_activity', $activity, 300);
		}
		return $activity;
	}

	private function obtain_guest_count_24()
	{
		$total_guests_online_24 = 0;

		if ($this->config['load_online_guests'])
		{
			// Get number of online guests for the past 24 hours
			// caching and main sql if none yet
			if (($total_guests_online_24 = $this->cache->get('_total_guests_online_24')) === false)
			{
				if ($this->db->get_sql_layer() === 'sqlite' || $this->db->get_sql_layer() === 'sqlite3')
				{
					$sql = 'SELECT COUNT(session_ip) as num_guests_24
						FROM (
							SELECT DISTINCT session_ip
							FROM ' . SESSIONS_TABLE . '
							WHERE session_user_id = ' . ANONYMOUS . '
								AND session_time >= ' . ($this->lookback - ((int) ($this->lookback % 60))) . ')';
				}
				else
				{
					$sql = 'SELECT COUNT(DISTINCT session_ip) as num_guests_24
						FROM ' . SESSIONS_TABLE . '
						WHERE session_user_id = ' . ANONYMOUS . '
							AND session_time >= ' . ($this->lookback - ((int) ($this->lookback % 60)));
				}
				$result = $this->db->sql_query($sql);
				$total_guests_online_24 = (int) $this->db->sql_fetchfield('num_guests_24');

				$this->db->sql_freeresult($result);

				// cache this data for 5 minutes, this improves performance
				$this->cache->put('_total_guests_online_24', $total_guests_online_24, 300);
			}
		}
		return $total_guests_online_24;
	}

	public function look_back()
	{
		/* you can define the amount to look back
		 * be careful with this, it may cause performance issues on your forum
		 * (60 * 60) * hours * days
		 * 86400 = 24 hours
		 * 604800 = past 7 days
		 * 2628000 = past month
		 * DO NOT SET THE NUMBER OF SECONDS TO LESS THAN 60!!
		*/
		$look_back = 86400;

		/**
		* Modify activity look back
		*
		* @event rmcgirr83.activity24hours.modify_activity_look_back
		* @var	int		look_back	The number of seconds used in the extension
		* @return		the amount of time in seconds
		* @since 1.0.7
		*/
		$vars = ['look_back'];
		extract($this->dispatcher->trigger_event('rmcgirr83.activity24hours.modify_activity_look_back', compact($vars)));

		return $look_back;
	}

	private function define_interval($look_back = 0)
	{
		return (time() - $look_back);
	}
}
