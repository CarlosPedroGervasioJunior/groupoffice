<?php

namespace go\modules\community\calendar\model;

use go\core\fs\Blob;
use go\core\model\Acl;
use go\core\model\User;
use go\core\orm\Query;
use go\core\util\DateTime;
use go\modules\community\tasks\model\TaskList;
use Sabre\CalDAV\Backend\AbstractBackend;
use Sabre\CalDAV;
use Sabre\DAV\PropPatch;
use Sabre\VObject;
use Sabre\DAV;

class CalDAVBackend extends AbstractBackend implements
//		Sabre\CalDAV\Backend\SyncSupport
	CalDAV\Backend\SchedulingSupport
{
	// Only increase this number if all CalDAV client need a full resync after the upgrade
	const VERSION = 1;

	public $propertyMap = [
		'{DAV:}displayname' => 'name',
		'{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
		//'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => 'timezone',
		'{http://apple.com/ns/ical/}calendar-order' => 'sortOrder',
		'{http://apple.com/ns/ical/}calendar-color' => 'color',
	];

	public function getCalendarsForUser($principalUri)
	{
		$result = [];
		$tz = new \GO\Base\VObject\VTimezone(); // same for each?
		// using logged in user, but should use PrincipalUri
		$calendars = Calendar::find()->where(['isSubscribed'=>1, 'groupId'=>null]);
		$username = basename($principalUri);
		$u = User::find(['id'])->where(['username'=>$username])->single();
		foreach($calendars as $calendar) {

			$uri = 'cal-'.$calendar->id;
			$result[] = [
				'id' => $calendar->id,
				'uri' => $uri,
				'principaluri' => $principalUri, // echo back
				'{DAV:}displayname' => $calendar->name,
				//'{http://apple.com/ns/ical/}refreshrate' => '0',
				'{http://apple.com/ns/ical/}calendar-order' => $calendar->sortOrder,
				'{http://apple.com/ns/ical/}calendar-color' => '#'.$calendar->color,
				'{urn:ietf:params:xml:ns:caldav}calendar-description' => $calendar->description,
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => "BEGIN:VCALENDAR\r\n" . $tz->serialize() . "END:VCALENDAR",
				'{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new CalDAV\Xml\Property\SupportedCalendarComponentSet(['VEVENT']),
				// free when calendar does not belong to the user
				'{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp' => new CalDAV\Xml\Property\ScheduleCalendarTransp($calendar->ownerId == $u->id ? 'opaque' : 'transparent'),

				'{http://calendarserver.org/ns/}getctag' => 'GroupOffice/calendar/'.self::VERSION.'/'.$calendar->highestItemModSeq(),
				//'{http://calendarserver.org/ns/}subscribed-strip-todos' => '0',
				//'{http://calendarserver.org/ns/}subscribed-strip-alarms' => '0',
				//'{http://calendarserver.org/ns/}subscribed-strip-attachments' => '0',
				'{http://sabredav.org/ns}sync-token' => self::VERSION.'-'.$calendar->highestItemModSeq(),
				'share-resource-uri' => '/ns/share/'.$calendar->id,
				// 1 = owner, 2 = readonly, 3 = readwrite
				'share-access' => $calendar->getPermissionLevel() == Acl::LEVEL_MANAGE ? 1 : ($calendar->getPermissionLevel() >= Acl::LEVEL_WRITE ? 3 : 2),
			];

		}

		return $result;
	}

	public function createCalendar($principalUri, $calendarUri, array $properties)
	{
		$sccs = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
		$type = 'VEVENT';
		if (isset($properties[$sccs])) {
			if (!($properties[$sccs] instanceof CalDAV\Xml\Property\SupportedCalendarComponentSet)) {
				throw new DAV\Exception('The '.$sccs.' property must be of type: \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet');
			}
			$type = $properties[$sccs]->getValue();
		}

		switch($type) {
			case 'VEVENT': //
				$cal = new Calendar();
				break;
			case 'VTODO': // task
				$cal = new TaskList();
				break;
			default: // combined?
				$cal = new Calendar(); // and attach tasklist?
		}

		$transp = '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp';
		if (isset($properties[$transp])) {
			$ownerId = $properties[$transp]->getValue() === 'transparent' ? null : go()->getUserId();
		}

		$values = ['ownerId' => $ownerId];
		foreach ($this->propertyMap as $xmlName => $dbName) {
			if (isset($properties[$xmlName])) {
				$values[$dbName] = $properties[$xmlName];
			}
		}
		if($values['color']) {
			$values['color'] = substr($values['color'], 1); // remove #
		}
		$cal->setValues($values);
		$cal->save();

		return $cal->id;
	}

	public function updateCalendar($calendarId, PropPatch $propPatch)
	{
		$supportedProperties = array_keys($this->propertyMap);
		$supportedProperties[] = '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp';

		$propPatch->handle($supportedProperties, function ($mutations) use ($calendarId) {
			$newValues = [];
			foreach ($mutations as $propertyName => $propertyValue) {
				switch ($propertyName) {
					case '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp':
						$newValues['includeInAvailability'] = 'transparent' === $propertyValue->getValue() ? 'none' : 'all';
						break;
					case '{http://apple.com/ns/ical/}calendar-color':
						$newValues['color'] = substr($propertyValue, 1);
						break;
					default:
						$newValues[$this->propertyMap[$propertyName]] = $propertyValue;
						break;
				}
			}

			$cal = Calendar::findById($calendarId);
			$cal->setValues($newValues);
			$cal->save();

			return true;
		});
	}

	public function deleteCalendar($calendarId)
	{
		list($type, $id) = explode('-', $calendarId);
		switch($type) {
			case 'c': Calendar::delete(['id' => $id]); break;
			case 't': TaskList::delete(['id' => $id]); break;
		}
	}

	public function getCalendarObjects($calendarId)
	{
		//id, uri, lastmodified, etag, calendarid, size, componenttype

		$maxMonthsOld = isset(\GO::config()->caldav_max_months_old) ? abs(\GO::config()->caldav_max_months_old) : 6;
		$pStart = date('Y-m-d', strtotime('-'.$maxMonthsOld.' months'));
		$pEnd = date('Y-m-d', strtotime('+3 years'));

		$etId = CalendarEvent::entityType()->getId();
		$events = CalendarEvent::find(['id', 'modifiedAt', 'uid'])
			->select(['cce.id as id','uid','eventdata.modifiedAt as modified','CONCAT(c.modSeq, "-", cu.modseq) as modseq'])
			->join('core_change', 'c', 'c.entityId = cce.id AND c.entityTypeId = '.$etId, 'LEFT')
			->join('core_change_user', 'cu', 'cu.entityId = cce.id AND cu.userId = 1 AND cu.entityTypeId = '.$etId, 'LEFT')
			//->join('core_blob', 'b','b.id = veventBlobId', 'LEFT')
			->where(['calendarId' => $calendarId])
			->filter([
				'before'=> $pEnd,
				'after' => $pStart
			])
			->fetchMode(\PDO::FETCH_OBJ);

		$result = [];
		foreach($events as $event) {
			//$blob = $event->icsBlob();
			$result[] = [
				'id' => $event->id,
				'calendarid' => $calendarId, // needed for bug in local delivery scheduler
				'uri' => str_replace('/', '+', $event->uid) . '.ics',
				'lastmodified' => strtotime($event->modified),
				'etag' => '"' . $event->modseq . '"',
				//'size' => $blob->size,
				'component' => 'vevent'
			];
		}
		return $result;
	}

	// TODO: pre-generate blobs for speedup
//	public function getMultipleCalendarObjects($calendarId, array $uris)
//	{
//		$uids = [];
//		foreach($uris as $uri) {
//			$uid = pathinfo($uri,PATHINFO_FILENAME);
//			$uids[$uid] = $uri;
//		}
//
//		$events = CalendarEvent::find()
//			->where(['cce.calendarId'=> $calendarId, 'eventdata.uid'=>array_keys($uids)])->all();
//
//		return array_map(function($event) use($uids) {
//			$blob = $event->icsBlob();
//			return [
//				'id' => $event->id,
//				'uri' => $uids[$event->uid],
//				'lastmodified' => strtotime($event->modifiedAt),
//				'etag' => '"' . $event->modseq . '"',
//				//'size' => $blob->size,
//				'calendardata' => $blob->getFile()->getContents(),
//				'component' => 'vevent',
//			];
//		}, $events);
//
////		return array_map(function ($uri) use ($calendarId) {
////			return $this->getCalendarObject($calendarId, $uri);
////		}, $uris);
//	}

	/**
	 * Check if this is only called when the getCalendarObjects does not provide the calendardata
	 */
	public function getCalendarObject($calendarId, $objectUri)
	{
		$uid = pathinfo($objectUri, PATHINFO_FILENAME);

		/** @var CalendarEvent $event */
		$event = CalendarEvent::find()
			//->join('core_blob', 'b','b.id = veventBlobId', 'LEFT')
			//->join('calendar_event_user', 'u', 'u.eventId = eventdata.id')
			->where(['cce.calendarId'=> $calendarId, 'eventdata.uid'=>$uid])->single();

		if (!$event) {
			go()->log("Event $objectUri not found in calendar $calendarId!");
			return false;
		}

		$blob = $event->icsBlob();

		$calendarData = $blob->getFile()->getContents();

		go()->debug("CalDAVBackend::getCalendarObject($calendarId, $objectUri, ");
		go()->debug($calendarData);
		go()->debug(")");

		return [
			'id' => $event->id,
			'uri' => $objectUri,
			'lastmodified' => strtotime($event->modifiedAt),
			'etag' => '"' . $blob->id . '"',
			'size' => $blob->size,
			'calendardata' => $calendarData,
			'component' => 'vevent',
		];
	}

	public function createCalendarObject($calendarId, $objectUri, $calendarData)
	{

		go()->debug("CalDAVBackend::createCalendarObject($calendarId, $objectUri, ");
		go()->debug($calendarData);
		go()->debug(")");

		//$calendar = Calendar::findById($calendarId);
		$uid = pathinfo($objectUri, PATHINFO_FILENAME);
		$event = new CalendarEvent();
		$event->uid = $uid;

		$event = ICalendarHelper::parseVObject($calendarData, $event);

		// The attached blob must be identical to the data used to create the event
		$event->attachBlob(ICalendarHelper::makeBlob($event, $calendarData)->id);

		$savedEvent = Calendar::addEvent($event, $calendarId);
		if($savedEvent === null) {
			throw new \Exception('Could not create calendar event');
		}

		return '"' . $event->icsBlobId() . '"';
	}

	public function updateCalendarObject($calendarId, $objectUri, $calendarData)
	{
		go()->debug("CalDAVBackend::updateCalendarObject($calendarId, $objectUri, ");
		go()->debug($calendarData);
		go()->debug(")");

		//$extraData = $this->getDenormalizedData($calendarData);
		$uid = pathinfo($objectUri, PATHINFO_FILENAME);
		/** @var CalendarEvent $event */
		$event = CalendarEvent::find()->where(['uid'=>$uid, 'calendarId'=>$calendarId])->single();
		if(!$event){
			go()->log("Event $objectUri not found in calendar $calendarId!");
			return false;
		}
		$event = ICalendarHelper::parseVObject($calendarData, $event);

		// The attached blob must be identical to the data used to create the event
		$event->attachBlob(ICalendarHelper::makeBlob($event, $calendarData)->id);

		if(!$event->save()) {
			go()->log("Failed to update event at ".$objectUri);
			return false;
		}

		return '"'.$event->icsBlobId().'"';
	}

	public function deleteCalendarObject($calendarId, $objectUri)
	{
		// objectUri = uid + '.ics' ?
		$uid = pathinfo($objectUri, PATHINFO_FILENAME);
		$query = (new Query())->select('id')->from('calendar_calendar_event','cce')
			->join('calendar_event', 'ev', 'ev.eventId = cce.eventId')
			->where(['calendarId' => $calendarId, 'ev.uid'=> $uid]);
		CalendarEvent::delete($query);
	}

	public function getSchedulingObject($principalUri, $objectUri)
	{
		return null;
	}

	public function getSchedulingObjects($principalUri)
	{
		return [];
	}

	public function deleteSchedulingObject($principalUri, $objectUri)
	{
		return null;
	}

	public function createSchedulingObject($principalUri, $objectUri, $objectData)
	{
		return null;
	}
}