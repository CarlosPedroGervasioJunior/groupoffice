<?php
/*
 * @copyright (c) 2016, Intermesh BV http://www.intermesh.nl
 * @author Michael de Hart <mdhart@intermesh.nl>
 * @license http://www.gnu.org/licenses/agpl-3.0.html AGPLv3
 */
namespace go\modules\community\calendar\model;

use go\core\orm\Mapping;
use go\core\orm\UserProperty;

/**
 * An alarms will ring a bell on a set datum / time
 * The time depends on the object it is attached to
 *
 * @property string $individualEmail emai lof the attendee that the alarm is for
 */
class Alert extends UserProperty {

	/* relatedTo */
	const Start = 'start';
	const End = 'end';

	/* actions */
	const Email = 'email';
	const Display = 'display';

	/** @var int auto increment primary key */
	public $id;

	/** @var string ISO 8061 signed duration */
	protected $offset;

	/** @var \DateTime Time to trigger the alarm. */
	protected $when;

	/** @var string 'start' | 'end' of the startdate of the event */
	protected $relatedTo = self::Start;

	/** @var \DateTime when to user has dismissed the alert or when the server has carried out sending the email */
	public $acknowledged;

	/** @var string 'email' | 'display'  */
	public $action;

	/** @var int PK of the evetn this alarm is set on */
	protected $eventId;

	/** @var int */
	protected $userId;

	protected static function defineMapping(): Mapping
	{
		return parent::defineMapping()->addTable("calendar_event_alert", "alert");
	}

	public function getTrigger() {
		return isset($this->offset) ? [
			'offset' => $this->offset,
			'relatedTo' => $this->relatedTo
		] : [
			'when' => $this->when
		];
	}

	public function at()
	{
		$event = $this->owner;
		if (isset($this->offset)) {
			$offset = $this->offset;
			$date = clone ($this->relatedTo == self::End ? $event->end() : $event->start());
			if ($offset[0] == '-') {
				$date->sub(new \DateInterval(substr($offset, 1)));
				return $date;
			}
			if ($offset[0] == '+') {
				$offset = substr($offset, 1);
			}
			$date->add(new \DateInterval($offset));
			return $date;
		}
		if (isset($this->when)) {
			return $this->when;
		}
		return null;
	}

	public function setTrigger($v) {
		if(isset($v['offset'])) {
			$this->offset = $v['offset'];
			$this->relatedTo = isset($v['relatedTo']) ? $v['relatedTo'] : self::Start;
		} elseif(isset($v['when'])) {
			$this->when = $v['when'];
		}
	}
}
