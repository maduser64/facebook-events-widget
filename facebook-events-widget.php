<?php
/*
Plugin Name: Facebook Events Widget
Plugin URI: http://roidayan.com
Description: Widget to display events from Facebook page or group
Version: 1.9.4
Author: Roi Dayan
Author URI: http://roidayan.com
License: GPLv2

Based on code by Mike Dalisay
  http://www.codeofaninja.com/2011/07/display-facebook-events-to-your-website.html


Copyright (C) 2011, 2012-2015  Roi Dayan  (email : roi.dayan@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/* TODO
 * setting if to display more info or not
 * link to all events
 * setting for date format for one day event and event that span multiple days
 * force height for widget container
*/

//error_reporting(E_ALL);

// requiring FB PHP SDK
if ( ! class_exists( 'Facebook' ) ) {
	session_start();
    require_once( 'facebook-php-sdk-v4-4.0-dev/autoload.php' );
}

use Facebook\FacebookSession;
use Facebook\FacebookRequest;
use Facebook\GraphUser;
use Facebook\FacebookRequestException;
use Facebook\FacebookRedirectLoginHelper;
use Facebook\FacebookSDKException;


define( 'FBEVENTS_TD', 'fbevents' );


class Facebook_Events_Widget extends WP_Widget {

    var $default_settings = array(
        'title' => '',
        'pageId' => '',
        'appId' => '',
        'appSecret' => '',
        'accessToken' => '',
        'maxEvents' => 10,
        'futureEvents' => false,
        'timeOffset' => 7,
        'newWindow' => false,
        'calSeparate' => false
        );

    function Facebook_Events_Widget() {
        // constructor
        $widget_ops = array(
            'classname' => 'widget_Facebook_Events_Widget',
            'description' => __('Display facebook events.', FBEVENTS_TD)
            );

        $control_ops = array(
            'width' => '',
            'height' => ''
            );

        $this->WP_Widget('facebook_events_widget',
            __('Facebook Events Widget', FBEVENTS_TD), $widget_ops, $control_ops);

        //$this->admin_url = admin_url('admin.php?page=' . urlencode(plugin_basename(__FILE__)));
        $this->admin_url = admin_url('widgets.php');

        add_action('init', array($this, 'add_style'));
    }

    function add_style() {
        if (!is_admin()) {
            wp_enqueue_style('facebook-events',
                            plugin_dir_url(__FILE__).'style.css',
                            false, '1.0', 'all');
        }
    }

    function widget($args, $instance) {
        // print the widget
        extract($args, EXTR_SKIP);
        $instance = wp_parse_args(
            (array) $instance,
            $this->default_settings
        );
        extract($instance, EXTR_SKIP);

        $title = apply_filters( 'widget_title', empty($title) ? __('Facebook Events', FBEVENTS_TD) : $title );

		FacebookSession::setDefaultApplication($appId, $appSecret);

        echo $before_widget;

        if ( $title ) {
            echo $before_title . $title . $after_title;
		}

		$data = $this->query_fb_events( $pageId, $accessToken, $maxEvents, $futureEvents );

        echo '<div class="fb-events-container">';

        /* loop through retrieved data */
        if ( ! empty( $data ) ) {
            $last_sep = '';

            foreach ( $data as $idx => $event ) {
				$event = (array) $event;
                $event['start_time'] = $this->fix_time($event['start_time'], $timeOffset);
                $event['end_time'] = $this->fix_time($event['end_time'], $timeOffset);
				$event['eid'] = $event['id'];
				$event['pic'] = $event['picture']->data->url;

                if ( $calSeparate ) {
                    $last_sep = $this->cal_event($event, $last_sep);
				}

                $this->create_event_div_block($event, $instance);
            }
        } else
            $this->create_noevents_div_block();

        echo '</div>';

        echo $after_widget;
    }

    function fix_time($tm, $offset) {
        // Facebook old reply is unixtime and new reply is "2012-07-21" or "2012-07-21T12:00:00-0400"
        // on new replys end_time could be empty
        if (!$tm)
            return $tm;
        if (ctype_digit($tm)) {
            $n = $tm;
            if ($offset != 0)
                $n -= $offset * 3600;
        } else {
            $r = new DateTime($tm);
            $n = $r->format('U') + $r->getOffset();
        }
        return $n;
    }

    function update($new_instance, $old_instance) {
        // save the widget
        $instance = $old_instance;
        foreach ($this->default_settings as $key => $val)
            $instance[$key] = strip_tags(stripslashes($new_instance[$key]));

        return $instance;
    }

    function form( $instance ) {
        // widget form in backend
        $instance = wp_parse_args(
            (array) $instance,
            $this->default_settings
        );
        extract($instance, EXTR_SKIP);

		if ( !empty($appId) && !empty($appSecret) ) {

			FacebookSession::setDefaultApplication( $appId, $appSecret );

			if ( empty( $accessToken ) ) {
				$accessToken = $this->get_facebook_access_token();
			}
		}

        $title = htmlspecialchars($instance['title']);
        $this->create_input('title', $title, __( 'Title:', FBEVENTS_TD ) );
        $this->create_input('pageId', $pageId, __( 'Facebook Page ID:', FBEVENTS_TD ) );
        $this->create_input('appId', $appId, __( 'Facebook App ID:', FBEVENTS_TD ) );
        $this->create_input('appSecret', $appSecret, __( 'Facebook App secret:', FBEVENTS_TD ) );
		$this->create_input('accessToken', $accessToken, __( 'Access token:', FBEVENTS_TD ) );

		if ( empty($accessToken) && !empty($appId) && !empty($appSecret) ) {

			$loginUrl = $this->_fb_login_url;

			if ( isset($loginUrl) ) {
				echo '<p><a class="button-secondary" href="' . $loginUrl . '">' .
					__('Get Facebook access token', FBEVENTS_TD) . '</a></p>';
			} else {
				_e( "<strong>ERROR:</strong> failed to get Facebook login url.", FBEVENTS_TD );
			}
        }

        $this->create_input('maxEvents', $maxEvents, __( 'Maximum Events:', FBEVENTS_TD ), 'number' );
        $this->create_input('futureEvents', $futureEvents, __( 'Show future events only:', FBEVENTS_TD ), 'checkbox');
        $this->create_input('timeOffset', $timeOffset, __( 'Adjust events times in hours:', FBEVENTS_TD ), 'number');
        $this->create_input('newWindow', $newWindow, __( 'Open events in new window:', FBEVENTS_TD ), 'checkbox');
        $this->create_input('calSeparate', $calSeparate, __( 'Show calendar separators:', FBEVENTS_TD ), 'checkbox');

        _e( '*To edit the style you need to edit the style.css file.', FBEVENTS_TD );
		echo '<br/><br/>';
    }

    function get_facebook_access_token() {
		$token = '';
		$redir_url = $this->admin_url . '?wid=' . $this->id;
		$helper = new FacebookRedirectLoginHelper( $redir_url );

		try {

			$session = $helper->getSessionFromRedirect();

			if ( isset( $session ) ) {
				$token = $session->getToken();
			} else {
				$scope = array( 'offline_access', 'user_events' );
				$this->_fb_login_url = $helper->getLoginUrl( $scope );
			}

		} catch(FacebookRequestException $e) {
			// When Facebook returns an error
			echo "<strong>ERROR:</strong> " . $e->getMessage();
		} catch(\Exception $e) {
			// When validation fails or other local issues
			echo "<strong>ERROR:</strong> " . $e->getMessage();
		}

		return $token;
    }

    function create_input($key, $value, $title, $type='text') {
        $name = $this->get_field_name($key);
        $id = $this->get_field_id($key);
        echo '<p><label for="' . $id . '">' . __($title);
        echo '&nbsp;<input id="' . $id . '" name="' . $name . '" type="' . $type . '"';

        if ($type == 'number') {
            echo ' style="width: 80px;"';
		}

        if ($type == 'checkbox') {
            checked( (bool) $value, true);
        } else {
            echo ' value="' . $value . '"';
		}
        echo ' /></label></p>';
    }

	function get_next_events( $response, &$data ) {
		$request = $response->getRequestForNextPage();

		if ( $request ) {
			$response = $request->execute();
			$g = $response->getGraphObject();
			$data2 = $g->getProperty('data');

			if ( $data2 ) {
				$data2 = $data2->asArray();
				$data = array_merge( $data, $data2 );
			}
		} else {
			$response = NULL;
		}

		return $response;
	}

    function query_fb_events( $pageId, $accessToken, $maxEvents, $futureOnly ) {
		$session = new FacebookSession( $accessToken );
		$g = false;
		$now = time();

		if ( $futureOnly ) {
			$since = $now;
		} else {
			$since = 1;
		}
		/* adding since=1 will get more results without fetching the next page */
		$url = "/{$pageId}/events?since={$since}";
        $p = array(
            "fields" => "id,name,picture,start_time,end_time,location"
        );

		try {
			$request = new FacebookRequest( $session, 'GET', $url, $p, 'v2.2' );
			$response = $request->execute();
			$g = $response->getGraphObject();
		} catch (FacebookRequestException $e) {
			// The Graph API returned an error
			echo "<strong>ERROR:</strong> " . $e->getMessage();
		} catch (\Exception $e) {
			// Some other error occurred
			echo "<strong>ERROR:</strong> " . $e->getMessage();
		}

		if ( ! $g ) {
			return false;
		}

		$data = $g->getProperty('data');
		if ( $data ) {
			$data = $data->asArray();
		}

		if ( ! $futureOnly && count( $data ) < $maxEvents ) {
			$request = $response->getRequestForNextPage();
			$response = $this->get_next_events( $response, $data );
		}

		/**
		 * graph api shows future first and we might need more pages to get current events
		 */
		if ( $futureOnly ) {
			$oldest = end( $data );
			$start_time = strtotime( $oldest->start_time );

			while ( $response && $start_time > $now ) {
				$response = $this->get_next_events( $response, $data );
			}
		}

		if ( $data ) {
			if ( $futureOnly ) {
				$data = array_reverse( $data );
			}

			$data = array_slice( $data, 0, $maxEvents );
		}

        return $data;
    }

    function cal_event($values, $last_sep = '') {
        $today = false;
        $tomorrow = false;
        $this_week = false;
        $this_month = false;

        if (date('Ymd') == date('Ymd', $values['start_time']))
            $today = true;

        if (date('Ymd') == date('Ymd', $values['start_time'] - 86400))
            $tomorrow = true;

        if (date('Ym') == date('Ym', $values['start_time'])) {
            $this_month = true;

            if (( date('j', $values['start_time']) - date('j') ) < 7) {
                if (date('w', $values['start_time']) >= date('w') ||
                    date('w', $values['start_time']) == 0)
                {
                    // comparing to 0 because seems its 0-6 where sunday is 1 and saturday is 0
                    // docs says otherwise.. need to check,
                    $this_week = true;
                }
            }
        }

        $month = date('F', $values['start_time']);

        if ($today) {
            $t = __( 'Today', FBEVENTS_TD );
            $r = 'today';
        } else if ($tomorrow) {
            $t = __( 'Tomorrow', FBEVENTS_TD );
            $r = 'tomorrow';
        } else if ($this_week) {
            $t = __( 'This Week', FBEVENTS_TD );
            $r = 'thisweek';
        } else if ($this_month) {
            $t = __( 'This Month', FBEVENTS_TD );
            $r = 'thismonth';
        } else {
            $t = $month;
            $r = $month;
        }

        if ($r != $last_sep) {
            echo '<div class="fb-event-cal-head">';
            echo $t;
            echo '</div>';
        }

        return $r;
    }

    function create_event_div_block($values, $instance) {
        extract($instance, EXTR_SKIP);

        $start_date = date_i18n(get_option('date_format'), $values['start_time']);
        if (date("His", $values['start_time']) != "000000")
            $start_time = date_i18n(get_option('time_format'), $values['start_time']);
        else
            $start_time = "";

        if (!empty($values['end_time'])) {
            $end_date = date_i18n(get_option('date_format'), $values['end_time']);
            if (date("His", $values['end_time']) != "000000")
                $end_time = date_i18n(get_option('time_format'), $values['end_time']);
            else
                $end_time = "";
        } else {
            $end_date = "";
            $end_time = "";
        }

        if ($start_date == $end_date)
            $end_date = "";

        $on = "$start_date";
        if (!empty($start_time))
            $on .= " &#183; $start_time";
        if (($start_date != $end_date) && !empty($end_date))
            $on .= " -<br>$end_date";
        if (!empty($end_time))
            $on .= " &#183; $end_time";

        $event_url = 'http://www.facebook.com/event.php?eid=' . $values['eid'];

        //printing the data
        echo "<div class='fb-event'>";
        echo '<a class="fb-event-anchor" href="' . $event_url . '"';

        if ( $newWindow )
            echo ' target="_blank"';

        echo '><div>';
        echo "<img src={$values['pic']} />";
        echo "<div class='fb-event-title'>{$values['name']}</div>";
        echo "<div class='fb-event-time'>{$on}</div>";

        if ( ! empty( $values['location'] ) )
            echo "<div class='fb-event-location'>" . $values['location'] . "</div>";

        if ( ! empty( $values['description'] ) )
            echo "<div class='fb-event-description'>" . nl2br($values['description']) . "</div>";

        //echo "<div style='clear: both'></div>";
        echo "</div></a>";
        echo "</div>";
    }

    function create_noevents_div_block() {
        echo "<div class='fb-event'>";
        echo "<div class='fb-event-description'>" . __( 'There are no events', FBEVENTS_TD ) . "</div>";
        echo "</div>";
    }
}

// register the widget
add_action( 'widgets_init', function(){
	register_widget( 'Facebook_Events_Widget' );
});

function fbevents_load_text_domain() {
	load_plugin_textdomain( FBEVENTS_TD, false, dirname(  __FILE__ ) . '/languages/' );
}

add_action( 'plugins_loaded', 'fbevents_load_text_domain' );