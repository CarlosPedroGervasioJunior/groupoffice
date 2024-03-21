<?php
namespace go\modules\community\calendar\model;

use go\core\acl\model\AclOwnerEntity;
use go\core\db\Criteria;
use go\core\model\Principal;
use go\core\orm\Filters;
use go\core\orm\Mapping;
use go\core\orm\PrincipalTrait;
use go\core\orm\Query;

/**
 * Calendar entity
 *
 */
class Calendar extends AclOwnerEntity {

	use PrincipalTrait {
		queryMissingPrincipals as protected traitMissingPrincipals;
	}

	/* Include In Availability */
	const All = 'all';
	const Attending = 'attending';
	const None = 'none';

	const UserProperties = ['color', 'sortOrder', 'isVisible', 'isSubscribed'];

	public $id;
	/** @var string The user-visible name of the calendar */
	public $name;
	public $description;
	/** @var string Any valid CSS color value. The color to be used when displaying events associated with the calendar */
	public $color;
	/** @var int uint32 Defines the sort order of calendars when presented in the client’s UI, so it is consistent between devices */
	public $sortOrder = 0;
	/** @var bool Has the user indicated they wish to see this Calendar in their client */
	public $isSubscribed;
	/** @var bool Should the calendar’s events be displayed to the user at the moment? */
	public $isVisible = true; // per user
	/**
	 * @var string (default: all) Should the calendar’s events be used as part of availability calculation?
	 * This MUST be one of:
	 *	- all: all events are considered.
	 *	- attending: events the user is a confirmed or tentative participant of are considered.
	 *	- none: all events are ignored (but may be considered if also in another calendar).
	 */
	public $includeInAvailability = self::All;

	/** @var ?string default for event. If NULL client will use the Users default timeZone  */
	public $timeZone;

	protected $defaultColor;

	public $defaultAlertsWithTime;
	public $defaultAlertsWithoutTime;
	public $ownerId;
	public $createdBy;

	public $groupId;
	protected $highestItemModSeq;

	protected static function defineMapping(): Mapping
	{
		return parent::defineMapping()
			->addTable("calendar_calendar")
			->addUserTable('calendar_calendar_user', 'caluser',['id' => 'id'], self::UserProperties)
			->addMap('defaultAlertsWithTime', DefaultAlert::class,  ['id'=>'calendarId'])
			->addMap('defaultAlertsWithoutTime', DefaultAlertWT::class,  ['id'=>'calendarId']);
	}

	/** @return Calendar */
	public static function fetchDefault($scheduleId) {
		return self::find()
			->join('core_user', 'u', 'u.id = calendar_calendar.ownerId')
			->where(['u.email' => $scheduleId])
			->orderBy(['sortOrder'=>'ASC'])
			->single();
	}

	public function getColor() {
		return $this->color ?? $this->defaultColor;
	}
	public function setColor($value) {
		$this->color = $value;
	}

	/**
	 * If the UID of the event already exists in the system. Grab its ID and add the event to the given calendar.
	 * Then select it again and return the selected event.
	 * If it doesn't exists just set the calendarId and save() once to behave
	 * @param $event CalendarEvent
	 * @param $cal Calendar
	 * @return CalendarEvent this one is saved in the provided calendar
	 */
	static function addEvent($event, $calendarId) {
		$eventData = go()->getDbConnection()
			->select(['t.eventId, GROUP_CONCAT(calendarId) as calendarIds'])
			->from('calendar_event', 't')
			->join('calendar_calendar_event', 'c', 'c.eventId = t.eventId', 'LEFT')
			->where(['uid'=>$event->uid])
			->single();

		if(!empty($eventData) && !empty($eventData['eventId'])) {
			$calendarIds = explode(',', $eventData['calendarIds']??'');
			if(in_array($calendarId, $calendarIds)) {
				// found and already in calendar
				return CalendarEvent::find()->where(['calendarId'=>$calendarId, 'uid' => $event->uid])->single();
			}
			// found but not in calendar (insert)
			go()->getDbConnection()->insert('calendar_calendar_event', [
				'calendarId' => $calendarId,
				'eventId' => $eventData['eventId']
			])->execute();
			$id = go()->getDbConnection()->getPDO()->lastInsertId();
			Calendar::updateHighestModSeq($calendarId);
			return CalendarEvent::findById($id);
		}
		//not found, set calendarId save and return
		$event->calendarId = $calendarId;
		return $event->save() ? $event : null;
	}

	protected static function defineFilters(): Filters
	{
		return parent::defineFilters()->add('isSubscribed', function(Criteria $criteria, $value, Query $query) {
			$query->where('isSubscribed','=', $value);
				if($value === false) {
					$query->orWhere('isSubscribed', 'IS', null);
				}
		})->add('isResource', function(Criteria $criteria, $value, Query $query) {
			$query->where('groupId',$value?'IS NOT':'IS', null);
		}, false)->add('groupId', function(Criteria $criteria, $value, Query $query) {
			$query->where('groupId','=', $value);
		});
	}

	/**
	 * @return int highest mod
	 */
	public function highestItemModSeq() {
		return $this->highestItemModSeq;
	}

	static function updateHighestModSeq($calendarId) {
		go()->getDbConnection()
			->update(self::getMapping()->getPrimaryTable()->getName(),
				['highestItemModSeq' => CalendarEvent::getState()],
				['id' => $calendarId]
			)->execute();
	}

	/**
	 *  per-user OR default = true ONLY IF current user is the owner
	 * @return bool
	 */
//	public function getIsSubscribed() {
//		return ($this->isSubscribed === NULL) ? $this->isOwner() :  $this->isSubscribed;
//	}

	public function isOwner() {
		return $this->ownerId === go()->getUserId();
	}

	public function isOwned() {
		return !empty($this->ownerId);
	}

	protected function internalSave(): bool
	{
		if($this->isNew()) {
			$this->isSubscribed = true; // auto subscribe the creator.
			$this->isVisible = true;
			$this->defaultColor = $this->color;
		}
		if(empty($this->color)) {
			$this->color = $this->defaultColor;
		}
		return parent::internalSave(); // TODO: Change the autogenerated stub
	}

	public function getMyRights() {
		$lvl = $this->getPermissionLevel();
		return [
			'mayReadFreeBusy' => $lvl >= 5,
			'mayReadItems' => $lvl >= 10,
			'mayUpdatePrivate' => $lvl >= 20, // per-user properties only
			'mayRSVP' => $lvl >= 25, // only own principal status
			'mayWriteOwn' => $lvl >= 30, // write only owned events
			'mayWriteAll' => $lvl >= 35,
			'mayAdmin' => $lvl >= 50,
			'mayDelete' => $lvl >= 35 && $this->isOwner(), // calendar itself
		];
	}

	public function shareesActAs() {
		return $this->isOwner() ? 'self' : 'secretary';
	}

	protected function principalAttrs(): array {
		$owner = Principal::findById($this->ownerId);
		$email = !empty($owner) ? $owner->email : null;
		return [
			'name'=>$this->name,
			'description' => $this->description ?? $owner->name ?? '',
			'timeZone' => $this->timeZone,
			'email' => $email
		];
	}

	protected function isPrincipal()
	{
		return $this->groupId != null;
	}

	protected static function queryMissingPrincipals(int $offset = 0): Query {
		return self::traitMissingPrincipals($offset)->andWhere('groupId', 'IS NOT', NULL);
	}

	protected function isPrincipalModified() : bool
	{
		return $this->isModified(['name', 'description', 'timeZone', 'ownerId','groupId']);
	}

	protected function principalType(): string {
		return Principal::Resource;
	}
}
